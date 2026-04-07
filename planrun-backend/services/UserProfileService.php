<?php
/**
 * Сервис для управления профилем пользователя.
 *
 * Валидация полей, обновление профиля, удаление пользователя,
 * приватность, аватар (БД-часть), Telegram.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/AvatarService.php';

class UserProfileService extends BaseService {

    /**
     * Получить профиль пользователя.
     */
    public function getProfile(int $userId): array {
        require_once __DIR__ . '/../user_functions.php';
        $userData = getUserData($userId, null, false);

        if (!$userData) {
            $this->throwNotFoundException('Профиль не найден');
        }

        if (isset($userData['preferred_days']) && is_string($userData['preferred_days'])) {
            $userData['preferred_days'] = json_decode($userData['preferred_days'], true) ?? [];
        }
        if (isset($userData['preferred_ofp_days']) && is_string($userData['preferred_ofp_days'])) {
            $userData['preferred_ofp_days'] = json_decode($userData['preferred_ofp_days'], true) ?? [];
        }
        unset($userData['password']);

        // Добавляем автоопределённый max_hr и зоны ЧСС
        $hrData = $this->getHrZonesData($userId, $userData);
        $userData['hr_zones_data'] = $hrData;

        return $userData;
    }

    /**
     * Расчёт пульсовых зон и автоопределение max_hr из тренировок.
     */
    public function getHrZonesData(int $userId, ?array $userData = null): array {
        if ($userData === null) {
            require_once __DIR__ . '/../user_functions.php';
            $userData = getUserData($userId, null, false) ?: [];
        }

        $profileMaxHr = !empty($userData['max_hr']) ? (int) $userData['max_hr'] : null;
        $restHr = !empty($userData['rest_hr']) ? (int) $userData['rest_hr'] : null;

        // Автоопределение из тренировок (IQR-фильтр артефактов, затем макс из реальных)
        $detectedMaxHr = null;
        $detectedFrom = null;
        $stmt = $this->db->prepare(
            "SELECT max_heart_rate, DATE(start_time) as dt, ROUND(distance_km, 1) as km,
                    avg_heart_rate
             FROM workouts
             WHERE user_id = ? AND max_heart_rate > 100 AND max_heart_rate < 230
               AND LOWER(COALESCE(activity_type, '')) IN ('running', 'trail running', 'treadmill')
             ORDER BY max_heart_rate DESC
             LIMIT 30"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $allWorkouts = [];
            while ($r = $res->fetch_assoc()) {
                $allWorkouts[] = $r;
            }
            $stmt->close();

            if (count($allWorkouts) >= 5) {
                // Шаг 1: собрать все max_hr, отфильтровать артефакты датчика
                // Артефакт: max_hr > avg_hr * 1.35 (нереальный скачок) или max_hr > 215
                $cleaned = [];
                foreach ($allWorkouts as $w) {
                    $maxHr = (int) $w['max_heart_rate'];
                    $avgHr = (int) ($w['avg_heart_rate'] ?? 0);
                    // Если avg_hr известен и max > avg*1.20 — скорее всего артефакт датчика
                    // (реальный max на тренировке обычно в пределах +15-20% от среднего)
                    if ($avgHr > 100 && $maxHr > $avgHr * 1.20) {
                        continue;
                    }
                    // Жёсткий потолок — значения > 210 почти всегда артефакт для взрослых
                    if ($maxHr > 210) {
                        continue;
                    }
                    $cleaned[] = $w;
                }

                // Шаг 2: из очищенных берём максимум (это реальный max_hr)
                if (!empty($cleaned)) {
                    $detectedMaxHr = (int) $cleaned[0]['max_heart_rate'];
                    $detectedFrom = $cleaned[0]['dt'] ?? null;
                }
            }
        }

        // Формула 220 - возраст
        $formulaMaxHr = null;
        $age = null;
        if (!empty($userData['birth_year'])) {
            $age = (int) date('Y') - (int) $userData['birth_year'];
            $formulaMaxHr = 220 - $age;
        }

        // Эффективный max_hr: тренировки > профиль > формула
        // Автоопределение из тренировок — самое точное, ручной ввод — переопределение
        if ($detectedMaxHr) {
            $effectiveMaxHr = $profileMaxHr ?? $detectedMaxHr;
            $source = $profileMaxHr ? 'override' : 'detected';
        } elseif ($profileMaxHr) {
            $effectiveMaxHr = $profileMaxHr;
            $source = 'profile';
        } elseif ($formulaMaxHr) {
            $effectiveMaxHr = $formulaMaxHr;
            $source = 'formula';
        } else {
            $effectiveMaxHr = 0;
            $source = 'unknown';
        }

        // Автоопределение ЧСС покоя из тренировок (если не задан вручную)
        $detectedRestHr = null;
        if (!$restHr) {
            $restStmt = $this->db->prepare(
                "SELECT avg_heart_rate
                 FROM workouts
                 WHERE user_id = ? AND avg_heart_rate > 40 AND avg_heart_rate < 120
                   AND LOWER(COALESCE(activity_type, '')) IN ('running', 'trail running', 'treadmill')
                 ORDER BY avg_heart_rate ASC
                 LIMIT 10"
            );
            if ($restStmt) {
                $restStmt->bind_param('i', $userId);
                $restStmt->execute();
                $restRes = $restStmt->get_result();
                $lowHrs = [];
                while ($rr = $restRes->fetch_assoc()) {
                    $lowHrs[] = (int) $rr['avg_heart_rate'];
                }
                $restStmt->close();
                // Берём минимальный средний пульс из лёгких тренировок и вычитаем ~30% как оценку покоя
                if (count($lowHrs) >= 3) {
                    $detectedRestHr = (int) round($lowHrs[0] * 0.55); // ~55% от самого низкого среднего пульса на бегу
                    if ($detectedRestHr < 40) $detectedRestHr = null;
                }
            }
        }
        $effectiveRestHr = $restHr ?? $detectedRestHr;
        $useKarvonen = $effectiveRestHr !== null && $effectiveRestHr >= 35 && $effectiveRestHr < $effectiveMaxHr;
        $zoneMethod = $useKarvonen ? 'karvonen' : 'percent_max';

        // Зоны ЧСС: Карвонен (с ЧСС покоя) или простой % от max_hr
        $zones = [];
        if ($effectiveMaxHr > 0) {
            $zoneDefinitions = [
                ['name' => 'Восстановительная', 'min_pct' => 0.50, 'max_pct' => 0.60],
                ['name' => 'Аэробная',          'min_pct' => 0.60, 'max_pct' => 0.70],
                ['name' => 'Темповая',           'min_pct' => 0.70, 'max_pct' => 0.80],
                ['name' => 'Пороговая',          'min_pct' => 0.80, 'max_pct' => 0.90],
                ['name' => 'Максимальная',       'min_pct' => 0.90, 'max_pct' => 1.00],
            ];
            foreach ($zoneDefinitions as $i => $zd) {
                if ($useKarvonen) {
                    // Формула Карвонена: RestHR + (MaxHR - RestHR) × Intensity%
                    $hrr = $effectiveMaxHr - $effectiveRestHr;
                    $minHr = (int) round($effectiveRestHr + $hrr * $zd['min_pct']);
                    $maxHr = (int) round($effectiveRestHr + $hrr * $zd['max_pct']);
                } else {
                    $minHr = (int) round($effectiveMaxHr * $zd['min_pct']);
                    $maxHr = (int) round($effectiveMaxHr * $zd['max_pct']);
                }
                $zones[] = [
                    'zone' => $i + 1,
                    'name' => $zd['name'],
                    'min_hr' => $minHr,
                    'max_hr' => $maxHr,
                ];
            }
        }

        // Реальные пульсовые диапазоны по интенсивности (из тренировок)
        $realHrByIntensity = $this->detectRealHrRanges($userId);

        return [
            'max_hr' => $profileMaxHr,
            'rest_hr' => $restHr,
            'effective_rest_hr' => $effectiveRestHr,
            'detected_max_hr' => $detectedMaxHr,
            'detected_from_date' => $detectedFrom,
            'formula_max_hr' => $formulaMaxHr,
            'effective_max_hr' => $effectiveMaxHr,
            'source' => $source,
            'zone_method' => $zoneMethod,
            'age' => $age,
            'zones' => $zones,
            'real_hr_ranges' => $realHrByIntensity,
        ];
    }

    /**
     * Определить реальные пульсовые диапазоны по интенсивности из тренировок.
     * Скользящее окно: последние 6 недель (актуальная форма).
     * Тренд: сравнение последних 3 недель vs предыдущих 3 недель.
     */
    private function detectRealHrRanges(int $userId): array
    {
        $ranges = [];
        try {
            $sixWeeksAgo = date('Y-m-d', strtotime('-42 days'));
            $threeWeeksAgo = date('Y-m-d', strtotime('-21 days'));

            $stmt = $this->db->prepare("
                SELECT avg_heart_rate, avg_pace, distance_km, DATE(start_time) as dt
                FROM workouts
                WHERE user_id = ? AND avg_heart_rate > 80 AND avg_heart_rate < 220
                  AND distance_km >= 3 AND avg_pace IS NOT NULL AND avg_pace != ''
                  AND LOWER(COALESCE(activity_type, '')) IN ('running', 'trail running', 'treadmill')
                  AND start_time >= ?
                ORDER BY start_time DESC
            ");
            if (!$stmt) return [];

            $stmt->bind_param('is', $userId, $sixWeeksAgo);
            $stmt->execute();
            $res = $stmt->get_result();

            // Разделяем по интенсивности И по периоду (recent vs prev)
            $buckets = [
                'easy'     => ['recent' => [], 'prev' => []],
                'moderate' => ['recent' => [], 'prev' => []],
                'intense'  => ['recent' => [], 'prev' => []],
            ];

            while ($r = $res->fetch_assoc()) {
                $paceSec = $this->paceToSeconds($r['avg_pace']);
                if ($paceSec === null) continue;
                $hr = (int) $r['avg_heart_rate'];
                $period = ($r['dt'] >= $threeWeeksAgo) ? 'recent' : 'prev';

                if ($paceSec >= 330) {
                    $buckets['easy'][$period][] = $hr;
                } elseif ($paceSec >= 300) {
                    $buckets['moderate'][$period][] = $hr;
                } else {
                    $buckets['intense'][$period][] = $hr;
                }
            }
            $stmt->close();

            foreach ($buckets as $type => $periods) {
                $all = array_merge($periods['recent'], $periods['prev']);
                if (count($all) < 3) continue;

                sort($all);
                $entry = [
                    'avg' => (int) round(array_sum($all) / count($all)),
                    'min' => $all[0],
                    'max' => end($all),
                    'p25' => $all[(int) floor(count($all) * 0.25)],
                    'p75' => $all[(int) floor(count($all) * 0.75)],
                    'count' => count($all),
                ];

                // Тренд: сравниваем средний ЧСС за последние 3 недели vs предыдущие 3 недели
                if (count($periods['recent']) >= 2 && count($periods['prev']) >= 2) {
                    $recentAvg = array_sum($periods['recent']) / count($periods['recent']);
                    $prevAvg = array_sum($periods['prev']) / count($periods['prev']);
                    $diff = round($recentAvg - $prevAvg);
                    if (abs($diff) >= 3) {
                        // Отрицательный diff = пульс снизился = улучшение
                        $entry['trend'] = $diff < 0 ? 'improving' : 'worsening';
                        $entry['trend_diff'] = $diff;
                        $entry['recent_avg'] = (int) round($recentAvg);
                        $entry['prev_avg'] = (int) round($prevAvg);
                    } else {
                        $entry['trend'] = 'stable';
                    }
                }

                $ranges[$type] = $entry;
            }
        } catch (Throwable $e) {
            // не критично
        }
        return $ranges;
    }

    /**
     * Конвертировать строку темпа "M:SS" в секунды.
     */
    private function paceToSeconds(?string $pace): ?int
    {
        if (!$pace || !preg_match('/^(\d+):(\d{2})$/', trim($pace), $m)) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * Целевой пульс для типа тренировки.
     * Приоритет: реальные данные (p25-p75) > зоны Карвонена.
     *
     * @return array{min: int, max: int}|null
     */
    public function getTargetHrForWorkoutType(int $userId, string $workoutType): ?array
    {
        $hrData = $this->getHrZonesData($userId);
        $real = $hrData['real_hr_ranges'] ?? [];
        $zones = $hrData['zones'] ?? [];

        // Маппинг тип тренировки → bucket реальных данных / зоны
        return match ($workoutType) {
            'easy', 'long' => $this->resolveHr($real['easy'] ?? null, $zones, [1, 2]),
            'tempo'        => $this->resolveHr($real['moderate'] ?? null, $zones, [2, 3]),
            'interval', 'fartlek', 'control' => $this->resolveHr($real['intense'] ?? null, $zones, [3, 4]),
            'race'         => $this->resolveHr(null, $zones, [3, 5]),
            default        => null, // rest, other, sbu, free, marathon
        };
    }

    /**
     * Разрешить HR: реальные данные (p25-p75) > зоны.
     * @param array|null $realBucket  Реальные данные из detectRealHrRanges
     * @param array      $zones      5-зонная модель
     * @param int[]      $zoneRange  [fromZone, toZone] (1-based)
     */
    private function resolveHr(?array $realBucket, array $zones, array $zoneRange): ?array
    {
        // Приоритет 1: реальные данные
        if ($realBucket && !empty($realBucket['p25']) && !empty($realBucket['p75'])) {
            return ['min' => (int) $realBucket['p25'], 'max' => (int) $realBucket['p75']];
        }
        // Приоритет 2: зоны
        if (empty($zones)) return null;
        $fromZone = $zoneRange[0];
        $toZone = $zoneRange[1];
        $minHr = null;
        $maxHr = null;
        foreach ($zones as $z) {
            $num = $z['zone'] ?? 0;
            if ($num >= $fromZone && $num <= $toZone) {
                if ($minHr === null || $z['min_hr'] < $minHr) $minHr = $z['min_hr'];
                if ($maxHr === null || $z['max_hr'] > $maxHr) $maxHr = $z['max_hr'];
            }
        }
        return ($minHr && $maxHr) ? ['min' => $minHr, 'max' => $maxHr] : null;
    }

    /**
     * Пересчитать target_hr для будущих дней плана.
     * Вызывается после импорта тренировок (Strava и т.д.).
     * @return int Количество обновлённых строк.
     */
    public function recalculateHrTargetsForFutureDays(int $userId): int
    {
        $hrData = $this->getHrZonesData($userId);
        $real = $hrData['real_hr_ranges'] ?? [];
        $zones = $hrData['zones'] ?? [];

        $today = date('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT id, type FROM training_plan_days WHERE user_id = ? AND date >= ?"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('is', $userId, $today);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) return 0;

        $update = $this->db->prepare(
            "UPDATE training_plan_days SET target_hr_min = ?, target_hr_max = ? WHERE id = ?"
        );
        if (!$update) return 0;

        $updated = 0;
        foreach ($rows as $row) {
            $hr = $this->getTargetHrForWorkoutType($userId, $row['type']);
            $hrMin = $hr ? $hr['min'] : null;
            $hrMax = $hr ? $hr['max'] : null;
            $id = (int) $row['id'];
            $update->bind_param('iii', $hrMin, $hrMax, $id);
            $update->execute();
            if ($update->affected_rows > 0) $updated++;
        }
        $update->close();

        // Сбросить кэш плана
        if ($updated > 0) {
            require_once __DIR__ . '/../user_functions.php';
            clearUserCache($userId);
        }

        return $updated;
    }

    /**
     * Обновить профиль пользователя.
     *
     * @return array Обновлённые данные пользователя
     */
    public function updateProfile(int $userId, array $data): array {
        $normalizeNull = function ($value) {
            if ($value === null || $value === 'null' || $value === '') {
                return null;
            }
            return $value;
        };

        $updateFields = [];
        $updateValues = [];
        $types = '';

        // --- Базовые ---
        if (isset($data['username'])) {
            $username = trim($data['username']);
            if (strlen($username) < 3 || strlen($username) > 50) {
                $this->throwValidationException('Имя пользователя должно быть от 3 до 50 символов');
            }
            $updateFields[] = 'username = ?';
            $updateValues[] = $username;
            $types .= 's';
        }

        if (isset($data['email'])) {
            $email = $normalizeNull($data['email']);
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->throwValidationException('Некорректный формат email');
            }
            $updateFields[] = 'email = ?';
            $updateValues[] = $email;
            $types .= 's';
        }

        if (isset($data['timezone'])) {
            $updateFields[] = 'timezone = ?';
            $updateValues[] = $normalizeNull($data['timezone']);
            $types .= 's';
        }

        // --- Профиль ---
        if (isset($data['gender'])) {
            $gender = $normalizeNull($data['gender']);
            if ($gender !== null && !in_array($gender, ['male', 'female'], true)) {
                $gender = null;
            }
            $updateFields[] = 'gender = ?';
            $updateValues[] = $gender;
            $types .= 's';
        }

        if (isset($data['birth_year'])) {
            $birthYear = $normalizeNull($data['birth_year']);
            if ($birthYear !== null) {
                $birthYear = (int)$birthYear;
                if ($birthYear < 1900 || $birthYear > (int)date('Y')) {
                    $this->throwValidationException('Некорректный год рождения');
                }
            }
            $updateFields[] = 'birth_year = ?';
            $updateValues[] = $birthYear;
            $types .= 'i';
        }

        if (array_key_exists('max_hr', $data)) {
            $maxHr = $normalizeNull($data['max_hr']);
            if ($maxHr !== null) {
                $maxHr = (int)$maxHr;
                if ($maxHr < 120 || $maxHr > 230) {
                    $this->throwValidationException('Макс ЧСС должен быть от 120 до 230');
                }
            }
            $updateFields[] = 'max_hr = ?';
            $updateValues[] = $maxHr;
            $types .= 'i';
        }

        if (array_key_exists('rest_hr', $data)) {
            $restHr = $normalizeNull($data['rest_hr']);
            if ($restHr !== null) {
                $restHr = (int)$restHr;
                if ($restHr < 30 || $restHr > 120) {
                    $this->throwValidationException('ЧСС покоя должен быть от 30 до 120');
                }
            }
            $updateFields[] = 'rest_hr = ?';
            $updateValues[] = $restHr;
            $types .= 'i';
        }

        if (isset($data['height_cm'])) {
            $heightCm = $normalizeNull($data['height_cm']);
            if ($heightCm !== null) {
                $heightCm = (int)$heightCm;
                if ($heightCm < 50 || $heightCm > 250) {
                    $this->throwValidationException('Некорректный рост (50-250 см)');
                }
            }
            $updateFields[] = 'height_cm = ?';
            $updateValues[] = $heightCm;
            $types .= 'i';
        }

        if (isset($data['weight_kg'])) {
            $weightKg = $normalizeNull($data['weight_kg']);
            if ($weightKg !== null) {
                $weightKg = (float)$weightKg;
                if ($weightKg < 20 || $weightKg > 300) {
                    $this->throwValidationException('Некорректный вес (20-300 кг)');
                }
            }
            $updateFields[] = 'weight_kg = ?';
            $updateValues[] = $weightKg;
            $types .= 'd';
        }

        // --- Цель и забег ---
        if (isset($data['goal_type'])) {
            $goalType = $data['goal_type'];
            if (!in_array($goalType, ['health', 'race', 'weight_loss', 'time_improvement'], true)) {
                $goalType = 'health';
            }
            $updateFields[] = 'goal_type = ?';
            $updateValues[] = $goalType;
            $types .= 's';
        }

        $this->addNullableStringField($data, 'race_distance', $updateFields, $updateValues, $types, $normalizeNull);
        $this->addNullableStringField($data, 'race_date', $updateFields, $updateValues, $types, $normalizeNull);
        $this->addNullableStringField($data, 'race_target_time', $updateFields, $updateValues, $types, $normalizeNull);
        $this->addNullableStringField($data, 'target_marathon_date', $updateFields, $updateValues, $types, $normalizeNull);
        $this->addNullableStringField($data, 'target_marathon_time', $updateFields, $updateValues, $types, $normalizeNull);

        // --- Опыт и тренировки ---
        if (isset($data['experience_level'])) {
            $expLevel = $data['experience_level'];
            if (!in_array($expLevel, ['novice', 'beginner', 'intermediate', 'advanced', 'expert'], true)) {
                $expLevel = ($expLevel === 'zero' || $expLevel === '') ? 'novice' : 'beginner';
            }
            $updateFields[] = 'experience_level = ?';
            $updateValues[] = $expLevel;
            $types .= 's';
        }

        if (isset($data['weekly_base_km'])) {
            $val = $normalizeNull($data['weekly_base_km']);
            $updateFields[] = 'weekly_base_km = ?';
            $updateValues[] = $val !== null ? (float)$val : null;
            $types .= 'd';
        }

        if (isset($data['sessions_per_week'])) {
            $val = $normalizeNull($data['sessions_per_week']);
            $updateFields[] = 'sessions_per_week = ?';
            $updateValues[] = $val !== null ? (int)$val : null;
            $types .= 'i';
        }

        if (isset($data['preferred_days'])) {
            $preferredDays = is_array($data['preferred_days']) ? $data['preferred_days'] : [];
            $updateFields[] = 'preferred_days = ?';
            $updateValues[] = !empty($preferredDays) ? json_encode(array_values($preferredDays), JSON_UNESCAPED_UNICODE) : null;
            $types .= 's';
        }

        if (isset($data['preferred_ofp_days'])) {
            $preferredOfpDays = is_array($data['preferred_ofp_days']) ? $data['preferred_ofp_days'] : [];
            $updateFields[] = 'preferred_ofp_days = ?';
            $updateValues[] = !empty($preferredOfpDays) ? json_encode(array_values($preferredOfpDays), JSON_UNESCAPED_UNICODE) : null;
            $types .= 's';
        }

        if (isset($data['has_treadmill'])) {
            $updateFields[] = 'has_treadmill = ?';
            $updateValues[] = ($data['has_treadmill'] === true || $data['has_treadmill'] === 1 || $data['has_treadmill'] === '1') ? 1 : 0;
            $types .= 'i';
        }

        if (isset($data['training_time_pref'])) {
            $val = $normalizeNull($data['training_time_pref']);
            if ($val !== null && !in_array($val, ['morning', 'day', 'evening'], true)) {
                $val = null;
            }
            $updateFields[] = 'training_time_pref = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        if (isset($data['ofp_preference'])) {
            $val = $normalizeNull($data['ofp_preference']);
            if ($val !== null && !in_array($val, ['gym', 'home', 'both', 'group_classes', 'online'], true)) {
                $val = null;
            }
            $updateFields[] = 'ofp_preference = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        if (isset($data['training_mode'])) {
            $trainingMode = $data['training_mode'];
            if (!in_array($trainingMode, ['ai', 'coach', 'both', 'self'], true)) {
                $trainingMode = 'ai';
            }
            $updateFields[] = 'training_mode = ?';
            $updateValues[] = $trainingMode;
            $types .= 's';
        }

        $this->addNullableStringField($data, 'training_start_date', $updateFields, $updateValues, $types, $normalizeNull);

        // --- Здоровье ---
        $this->addNullableStringField($data, 'health_notes', $updateFields, $updateValues, $types, $normalizeNull);
        $this->addNullableStringField($data, 'device_type', $updateFields, $updateValues, $types, $normalizeNull);

        if (isset($data['coach_style'])) {
            $style = $data['coach_style'];
            if (in_array($style, ['motivational', 'analytical', 'minimal'], true)) {
                $updateFields[] = 'coach_style = ?';
                $updateValues[] = $style;
                $types .= 's';
            }
        }

        if (isset($data['weight_goal_kg'])) {
            $val = $normalizeNull($data['weight_goal_kg']);
            $updateFields[] = 'weight_goal_kg = ?';
            $updateValues[] = $val !== null ? (float)$val : null;
            $types .= 'd';
        }

        $this->addNullableStringField($data, 'weight_goal_date', $updateFields, $updateValues, $types, $normalizeNull);

        if (isset($data['health_program'])) {
            $val = $normalizeNull($data['health_program']);
            if ($val !== null && !in_array($val, ['start_running', 'couch_to_5k', 'regular_running', 'custom'], true)) {
                $val = null;
            }
            $updateFields[] = 'health_program = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        if (isset($data['health_plan_weeks'])) {
            $val = $normalizeNull($data['health_plan_weeks']);
            $updateFields[] = 'health_plan_weeks = ?';
            $updateValues[] = $val !== null ? (int)$val : null;
            $types .= 'i';
        }

        if (isset($data['current_running_level'])) {
            $val = $normalizeNull($data['current_running_level']);
            if ($val !== null && !in_array($val, ['zero', 'basic', 'comfortable'], true)) {
                $val = null;
            }
            $updateFields[] = 'current_running_level = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        // --- Расширенный профиль бегуна ---
        if (isset($data['running_experience'])) {
            $val = $normalizeNull($data['running_experience']);
            if ($val !== null && !in_array($val, ['less_3m', '3_6m', '6_12m', '1_2y', 'more_2y'], true)) {
                $val = null;
            }
            $updateFields[] = 'running_experience = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        if (isset($data['easy_pace_sec'])) {
            $val = $normalizeNull($data['easy_pace_sec']);
            $updateFields[] = 'easy_pace_sec = ?';
            $updateValues[] = $val !== null ? (int)$val : null;
            $types .= 'i';
        }

        if (isset($data['is_first_race_at_distance'])) {
            $val = $normalizeNull($data['is_first_race_at_distance']);
            if ($val !== null) {
                $val = ($val === true || $val === 1 || $val === '1') ? 1 : 0;
            }
            $updateFields[] = 'is_first_race_at_distance = ?';
            $updateValues[] = $val;
            $types .= 'i';
        }

        if (isset($data['last_race_distance'])) {
            $val = $normalizeNull($data['last_race_distance']);
            if ($val !== null && !in_array($val, ['5k', '10k', 'half', 'marathon', 'other'], true)) {
                $val = null;
            }
            $updateFields[] = 'last_race_distance = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        if (isset($data['last_race_distance_km'])) {
            $val = $normalizeNull($data['last_race_distance_km']);
            $updateFields[] = 'last_race_distance_km = ?';
            $updateValues[] = $val !== null ? (float)$val : null;
            $types .= 'd';
        }

        $this->addNullableStringField($data, 'last_race_time', $updateFields, $updateValues, $types, $normalizeNull);
        $this->addNullableStringField($data, 'last_race_date', $updateFields, $updateValues, $types, $normalizeNull);

        if (isset($data['planning_benchmark_distance'])) {
            $val = $normalizeNull($data['planning_benchmark_distance']);
            if ($val !== null && !in_array($val, ['5k', '10k', 'half', 'marathon', 'other'], true)) {
                $val = null;
            }
            $updateFields[] = 'planning_benchmark_distance = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        if (isset($data['planning_benchmark_distance_km'])) {
            $val = $normalizeNull($data['planning_benchmark_distance_km']);
            $updateFields[] = 'planning_benchmark_distance_km = ?';
            $updateValues[] = $val !== null ? (float)$val : null;
            $types .= 'd';
        }

        $this->addNullableStringField($data, 'planning_benchmark_time', $updateFields, $updateValues, $types, $normalizeNull);
        $this->addNullableStringField($data, 'planning_benchmark_date', $updateFields, $updateValues, $types, $normalizeNull);

        if (isset($data['planning_benchmark_type'])) {
            $val = $normalizeNull($data['planning_benchmark_type']);
            if ($val !== null && !in_array($val, ['race', 'control', 'hard_workout', 'easy_workout'], true)) {
                $val = null;
            }
            $updateFields[] = 'planning_benchmark_type = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        if (isset($data['planning_benchmark_effort'])) {
            $val = $normalizeNull($data['planning_benchmark_effort']);
            if ($val !== null && !in_array($val, ['max', 'hard', 'steady', 'easy'], true)) {
                $val = null;
            }
            $updateFields[] = 'planning_benchmark_effort = ?';
            $updateValues[] = $val;
            $types .= 's';
        }

        // --- Аватар ---
        if (isset($data['avatar_path'])) {
            $normalizedAvatar = AvatarService::normalizeStoredAvatarPath($data['avatar_path']);
            if (!$normalizedAvatar['valid']) {
                $this->throwValidationException('Некорректный avatar_path');
            }
            $updateFields[] = 'avatar_path = ?';
            $updateValues[] = $normalizedAvatar['value'];
            $types .= 's';
        }

        // --- Приватность и push ---
        $boolFields = [
            'privacy_show_email', 'privacy_show_trainer', 'privacy_show_calendar',
            'privacy_show_metrics', 'privacy_show_workouts',
            'push_workouts_enabled', 'push_chat_enabled',
        ];
        foreach ($boolFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = ?";
                $updateValues[] = (int)$data[$field] ? 1 : 0;
                $types .= 'i';
            }
        }

        if (isset($data['push_workout_hour'])) {
            $h = (int)$data['push_workout_hour'];
            if ($h >= 0 && $h <= 23) {
                $updateFields[] = 'push_workout_hour = ?';
                $updateValues[] = $h;
                $types .= 'i';
            }
        }
        if (isset($data['push_workout_minute'])) {
            $m = (int)$data['push_workout_minute'];
            if ($m >= 0 && $m <= 59) {
                $updateFields[] = 'push_workout_minute = ?';
                $updateValues[] = $m;
                $types .= 'i';
            }
        }

        if (isset($data['privacy_level'])) {
            $privacyLevel = $data['privacy_level'];
            if (!in_array($privacyLevel, ['public', 'private', 'link'], true)) {
                $privacyLevel = 'public';
            }
            $updateFields[] = 'privacy_level = ?';
            $updateValues[] = $privacyLevel;
            $types .= 's';
            if ($privacyLevel === 'link') {
                $tokenStmt = $this->db->prepare("SELECT public_token FROM users WHERE id = ?");
                $tokenStmt->bind_param("i", $userId);
                $tokenStmt->execute();
                $tokenRow = $tokenStmt->get_result()->fetch_assoc();
                $tokenStmt->close();
                if (empty($tokenRow['public_token'])) {
                    $newToken = bin2hex(random_bytes(16));
                    $updateFields[] = 'public_token = ?';
                    $updateValues[] = $newToken;
                    $types .= 's';
                }
            }
        }

        // --- username_slug ---
        if (isset($data['username'])) {
            require_once __DIR__ . '/../user_functions.php';
            $usernameSlug = generateUsernameSlug($data['username'], $userId);
            $updateFields[] = 'username_slug = ?';
            $updateValues[] = $usernameSlug;
            $types .= 's';
        }

        if (empty($updateFields)) {
            $this->throwValidationException('Нет данных для обновления');
        }

        $updateFields[] = 'updated_at = NOW()';

        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateValues[] = $userId;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            $this->throwException('Ошибка подготовки запроса: ' . $this->db->error, 500);
        }

        $stmt->bind_param($types, ...$updateValues);
        $stmt->execute();

        if ($stmt->error) {
            $stmt->close();
            $this->throwException('Ошибка обновления профиля: ' . $stmt->error, 500);
        }
        $stmt->close();

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($userId);

        // Пересчёт целевого HR для будущих дней при смене пульсовых параметров
        if (array_key_exists('max_hr', $data) || array_key_exists('rest_hr', $data)) {
            try {
                require_once __DIR__ . '/../cache_config.php';
                Cache::delete("training_plan_{$userId}");
                $this->recalculateHrTargetsForFutureDays($userId);
            } catch (Throwable $e) {
                error_log("updateProfile HR recalc error user={$userId}: " . $e->getMessage());
            }
        }

        return $this->getProfile($userId);
    }

    /**
     * Удалить пользователя и все связанные данные.
     */
    public function deleteUser(int $targetUserId, int $adminUserId): array {
        if ($targetUserId === $adminUserId) {
            $this->throwValidationException('Нельзя удалить самого себя');
        }

        $stmt = $this->db->prepare("SELECT id, username, avatar_path FROM users WHERE id = ?");
        $stmt->bind_param("i", $targetUserId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $this->throwNotFoundException('Пользователь не найден');
        }

        $this->db->begin_transaction();

        try {
            $cascadeTables = [
                // [sql, params, types]
                ["DELETE tde FROM training_day_exercises tde INNER JOIN training_plan_days tpd ON tde.plan_day_id = tpd.id WHERE tpd.user_id = ?", [$targetUserId], 'i'],
                ["DELETE FROM training_plan_days WHERE user_id = ?", [$targetUserId], 'i'],
                ["DELETE FROM training_plan_weeks WHERE user_id = ?", [$targetUserId], 'i'],
                ["DELETE FROM training_plan_phases WHERE user_id = ?", [$targetUserId], 'i'],
                ["DELETE FROM user_training_plans WHERE user_id = ?", [$targetUserId], 'i'],
                ["DELETE FROM training_progress WHERE user_id = ?", [$targetUserId], 'i'],
                ["DELETE FROM workout_log WHERE user_id = ?", [$targetUserId], 'i'],
                ["DELETE FROM integration_tokens WHERE user_id = ?", [$targetUserId], 'i'],
            ];

            foreach ($cascadeTables as [$sql, $params, $paramTypes]) {
                $s = $this->db->prepare($sql);
                if ($s) {
                    $s->bind_param($paramTypes, ...$params);
                    $s->execute();
                    $s->close();
                }
            }

            // Таблицы, которые могут отсутствовать
            $optionalTables = [
                'workout_timeline' => "DELETE wt FROM workout_timeline wt INNER JOIN workouts w ON wt.workout_id = w.id WHERE w.user_id = ?",
                'workout_laps' => "DELETE wl FROM workout_laps wl INNER JOIN workouts w ON wl.workout_id = w.id WHERE w.user_id = ?",
                'password_reset_tokens' => "DELETE FROM password_reset_tokens WHERE user_id = ?",
                'refresh_tokens' => "DELETE FROM refresh_tokens WHERE user_id = ?",
                'notification_dismissals' => "DELETE FROM notification_dismissals WHERE user_id = ?",
                'push_tokens' => "DELETE FROM push_tokens WHERE user_id = ?",
            ];

            foreach ($optionalTables as $tableName => $sql) {
                $tableExists = $this->db->query("SHOW TABLES LIKE '$tableName'");
                if ($tableExists && $tableExists->num_rows > 0) {
                    $s = $this->db->prepare($sql);
                    if ($s) {
                        $s->bind_param('i', $targetUserId);
                        $s->execute();
                        $s->close();
                    }
                }
            }

            // workouts (после timeline/laps)
            $s = $this->db->prepare("DELETE FROM workouts WHERE user_id = ?");
            $s->bind_param('i', $targetUserId);
            $s->execute();
            $s->close();

            // user_coaches (both directions)
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'user_coaches'");
            if ($tableCheck && $tableCheck->num_rows > 0) {
                $s = $this->db->prepare("DELETE FROM user_coaches WHERE user_id = ? OR coach_id = ?");
                if ($s) {
                    $s->bind_param("ii", $targetUserId, $targetUserId);
                    $s->execute();
                    $s->close();
                }
            }

            // Удаляем пользователя
            $s = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $s->bind_param("i", $targetUserId);
            $s->execute();
            if ($s->error) {
                throw new \Exception('Ошибка удаления пользователя: ' . $s->error);
            }
            $s->close();

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        // Удаляем аватар с диска (вне транзакции)
        if (!empty($user['avatar_path'])) {
            AvatarService::deleteAvatarByPath($user['avatar_path']);
        }

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($targetUserId);

        $this->logInfo("Пользователь удален", [
            'username' => $user['username'],
            'target_user_id' => $targetUserId,
            'admin_user_id' => $adminUserId,
        ]);

        return ['username' => $user['username']];
    }

    /**
     * Обновить настройки приватности.
     */
    public function updatePrivacy(int $userId, string $privacyLevel): array {
        if (!in_array($privacyLevel, ['public', 'private', 'link'], true)) {
            $privacyLevel = 'public';
        }

        $publicToken = null;
        if ($privacyLevel === 'link') {
            $stmt = $this->db->prepare("SELECT public_token FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (empty($row['public_token'])) {
                $publicToken = bin2hex(random_bytes(16));
                $s = $this->db->prepare("UPDATE users SET public_token = ? WHERE id = ?");
                $s->bind_param("si", $publicToken, $userId);
                $s->execute();
                $s->close();
            } else {
                $publicToken = $row['public_token'];
            }
        }

        $stmt = $this->db->prepare("UPDATE users SET privacy_level = ? WHERE id = ?");
        $stmt->bind_param("si", $privacyLevel, $userId);
        $stmt->execute();
        $stmt->close();

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($userId);

        $result = ['privacy_level' => $privacyLevel];
        if ($privacyLevel === 'link' && $publicToken) {
            $result['public_token'] = $publicToken;
        }
        return $result;
    }

    /**
     * Загрузить аватар (сохранение файла + обновление БД).
     */
    public function uploadAvatar(int $userId, array $file): array {
        $stmt = $this->db->prepare("SELECT avatar_path FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $oldAvatar = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $storedAvatar = AvatarService::storeUploadedAvatar($file, $userId);
        $relativePath = $storedAvatar['avatar_path'];

        try {
            $s = $this->db->prepare("UPDATE users SET avatar_path = ? WHERE id = ?");
            $s->bind_param("si", $relativePath, $userId);
            if (!$s->execute()) {
                throw new \RuntimeException('Не удалось сохранить путь аватара в БД');
            }
            $s->close();
        } catch (\Throwable $e) {
            AvatarService::deleteAvatarByPath($relativePath);
            throw $e;
        }

        if ($oldAvatar && !empty($oldAvatar['avatar_path']) && $oldAvatar['avatar_path'] !== $relativePath) {
            AvatarService::deleteAvatarByPath($oldAvatar['avatar_path']);
        }

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($userId);

        return ['avatar_path' => $relativePath, 'user' => $this->getProfile($userId)];
    }

    /**
     * Удалить аватар.
     */
    public function removeAvatar(int $userId): void {
        $stmt = $this->db->prepare("SELECT avatar_path FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $s = $this->db->prepare("UPDATE users SET avatar_path = NULL WHERE id = ?");
        $s->bind_param("i", $userId);
        if (!$s->execute()) {
            throw new \RuntimeException('Не удалось обновить профиль пользователя');
        }
        $s->close();

        if ($result && !empty($result['avatar_path'])) {
            AvatarService::deleteAvatarByPath($result['avatar_path']);
        }

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($userId);
    }

    /**
     * Сгенерировать код привязки Telegram.
     */
    public function generateTelegramLinkCode(int $userId): array {
        $linkCode = bin2hex(random_bytes(8));
        $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60);

        $stmt = $this->db->prepare("UPDATE users SET telegram_link_code = ?, telegram_link_code_expires = ? WHERE id = ?");
        $stmt->bind_param('ssi', $linkCode, $expiresAt, $userId);
        $stmt->execute();
        $stmt->close();

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($userId);

        return ['code' => $linkCode, 'expires_at' => $expiresAt];
    }

    /**
     * Отвязать Telegram.
     */
    public function unlinkTelegram(int $userId): void {
        $stmt = $this->db->prepare("UPDATE users SET telegram_id = NULL, telegram_link_code = NULL, telegram_link_code_expires = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($userId);
    }

    /**
     * Отправить тестовое уведомление.
     */
    public function sendTestNotification(int $userId, string $channel, string $endpoint = ''): array {
        if (!in_array($channel, ['mobile_push', 'web_push', 'telegram', 'email'], true)) {
            $this->throwValidationException('Неизвестный канал уведомлений');
        }

        $title = 'Тест уведомления PlanRun';
        $body = match ($channel) {
            'mobile_push' => 'Push на устройство работает.',
            'web_push' => 'Браузерный push работает.',
            'telegram' => 'Telegram-уведомления работают.',
            'email' => 'Email-уведомления работают.',
            default => 'Уведомления работают.',
        };

        $success = false;
        $errorText = null;

        if ($channel === 'mobile_push') {
            require_once __DIR__ . '/PushNotificationService.php';
            $push = new \PushNotificationService($this->db);
            $success = $push->sendToUser($userId, $title, $body, ['type' => 'chat', 'link' => '/settings?tab=profile']);
            if (!$success) $errorText = 'Нет активных push-токенов или push не настроен';
        } elseif ($channel === 'web_push') {
            require_once __DIR__ . '/WebPushNotificationService.php';
            $webPush = new \WebPushNotificationService($this->db);
            if (!$webPush->isConfigured()) {
                $this->throwException('Web push не настроен на сервере', 503);
            }
            if ($endpoint === '') {
                $this->throwValidationException('Сначала подключите этот браузер к web push');
            }
            $success = $webPush->sendToEndpoint($userId, $endpoint, $title, $body, ['link' => '/settings?tab=profile']);
            if (!$success) $errorText = 'Нет активной подписки для этого браузера';
        } elseif ($channel === 'telegram') {
            require_once __DIR__ . '/TelegramLoginService.php';
            $telegram = new \TelegramLoginService($this->db);
            $stmt = $this->db->prepare("SELECT telegram_id FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $telegramId = (int)($row['telegram_id'] ?? 0);
            $success = $telegram->sendMessageIfConfigured($telegramId, $title, $body, ['link' => '/settings?tab=profile']);
            if (!$success) $errorText = 'Telegram не подключён или бот недоступен';
        } elseif ($channel === 'email') {
            require_once __DIR__ . '/EmailNotificationService.php';
            $email = new \EmailNotificationService($this->db);
            $success = $email->sendToUser($userId, $title, $body, ['link' => '/settings?tab=profile', 'action_label' => 'Открыть настройки']);
            if (!$success) $errorText = 'Email не настроен или адрес не указан';
        }

        // Log delivery
        require_once __DIR__ . '/NotificationSettingsService.php';
        $settingsService = new \NotificationSettingsService($this->db);
        $settingsService->logDelivery($userId, 'system.test_notification', $channel, $success ? 'sent' : 'failed', [
            'title' => $title, 'body' => $body, 'error_text' => $errorText,
        ]);

        return ['success' => $success, 'channel' => $channel, 'error' => $errorText];
    }

    // ==================== ПРИВАТНЫЕ ХЕЛПЕРЫ ====================

    private function addNullableStringField(array $data, string $key, array &$fields, array &$values, string &$types, callable $normalizeNull): void {
        if (isset($data[$key])) {
            $fields[] = "$key = ?";
            $values[] = $normalizeNull($data[$key]);
            $types .= 's';
        }
    }

}
