<?php
require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

class TrainingLoadService extends BaseService {
    private ?\UserRepository $userRepo = null;

    private function userRepo(): \UserRepository {
        return $this->userRepo ??= new \UserRepository($this->db);
    }

    /**
     * Banister TRIMP formula.
     * TRIMP = duration_min * deltaHR_ratio * 0.64 * e^(1.92 * deltaHR_ratio)
     * deltaHR_ratio = (avgHR - restHR) / (maxHR - restHR)
     */
    public function computeTrimp(float $durationMin, float $avgHr, float $restHr, float $maxHr): ?float {
        if ($maxHr <= $restHr || $avgHr < $restHr || $durationMin <= 0) {
            return null;
        }
        $deltaRatio = ($avgHr - $restHr) / ($maxHr - $restHr);
        $deltaRatio = max(0.0, min(1.0, $deltaRatio));
        return round($durationMin * $deltaRatio * 0.64 * exp(1.92 * $deltaRatio), 2);
    }

    /**
     * Парсит темп "MM:SS" в секунды/км. Возвращает null если формат невалидный
     * или результат вне реалистичного диапазона (2:00–15:00 мин/км).
     */
    private function parsePaceSec(?string $pace): ?int {
        if (!$pace || trim($pace) === '') return null;
        $parts = explode(':', trim($pace));
        if (count($parts) !== 2) return null;
        $m = (int) $parts[0];
        $s = (int) $parts[1];
        if ($m < 0 || $s < 0 || $s >= 60) return null;
        $sec = $m * 60 + $s;
        return ($sec >= 120 && $sec <= 900) ? $sec : null;
    }

    /**
     * Running TSS (rTSS) — индустриальный стандарт TrainingPeaks для бегунов без HR.
     * Формула: rTSS = (duration_hours) × IF³ × 100, где IF = threshold_pace / actual_pace.
     * Кубическая функция автоматически "наказывает" гоночные усилия (race-effort
     * не требует отдельной надбавки).
     *
     * Приводим к Banister-шкале коэффициентом 1.6 — чтобы числа были согласованы
     * с тренировками, где TRIMP считается по реальному пульсу (1 час threshold ≈ 160 TRIMP
     * для типичного юзера с max_hr≈190, rest_hr≈60).
     *
     * Threshold pace оцениваем как easy_pace × 0.82 (Daniels: marathon-threshold ratio).
     */
    public function estimateTrimpFromPace(
        float $durationMin,
        int $paceSec,
        int $easyPaceSec
    ): ?float {
        if ($durationMin <= 0 || $paceSec <= 0 || $easyPaceSec <= 0) {
            return null;
        }
        $thresholdPaceSec = $easyPaceSec * 0.82;
        $intensityFactor = $thresholdPaceSec / $paceSec;
        // Cap IF: даже race-pace 5к редко превышает ~1.15 IF; защищает от выбросов при ошибках ввода.
        $intensityFactor = min($intensityFactor, 1.30);
        $rtss = ($durationMin / 60.0) * pow($intensityFactor, 3) * 100;
        // Приведение к Banister-шкале для согласованности с HR-based TRIMP.
        return round($rtss * 1.6, 2);
    }

    /**
     * Get user's HR parameters. Uses DB values, falls back to age-based estimation.
     * max_hr: user value or 220 - age
     * rest_hr: user value or 60
     */
    public function getUserHrParams(int $userId): array {
        $row = $this->userRepo()->getForHrCalculation($userId);

        if (!$row) {
            return ['max_hr' => 190, 'rest_hr' => 60, 'source' => 'default'];
        }

        $source = 'estimated';
        $maxHr = null;
        $restHr = null;

        if (!empty($row['max_hr']) && (int)$row['max_hr'] >= 120 && (int)$row['max_hr'] <= 230) {
            $maxHr = (int)$row['max_hr'];
            $source = 'user';
        }
        if (!empty($row['rest_hr']) && (int)$row['rest_hr'] >= 30 && (int)$row['rest_hr'] <= 100) {
            $restHr = (int)$row['rest_hr'];
            if ($source !== 'user') $source = 'partial';
        }

        if ($maxHr === null) {
            $age = !empty($row['birth_year']) ? ((int)date('Y') - (int)$row['birth_year']) : 30;
            $maxHr = max(150, 220 - $age);
        }
        if ($restHr === null) {
            $restHr = 60;
        }

        return ['max_hr' => $maxHr, 'rest_hr' => $restHr, 'source' => $source];
    }

    /**
     * Compute TRIMP for a workout and cache it in the workouts table.
     */
    public function computeAndCacheWorkoutTrimp(int $workoutId, ?array $hrParams = null): ?float {
        $stmt = $this->db->prepare("SELECT user_id, avg_heart_rate, duration_seconds, duration_minutes FROM workouts WHERE id = ?");
        $stmt->bind_param('i', $workoutId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['avg_heart_rate'])) {
            return null;
        }

        if (!$hrParams) {
            $hrParams = $this->getUserHrParams((int)$row['user_id']);
        }

        $durationMin = 0;
        if (!empty($row['duration_seconds'])) {
            $durationMin = (int)$row['duration_seconds'] / 60.0;
        } elseif (!empty($row['duration_minutes'])) {
            $durationMin = (int)$row['duration_minutes'];
        }

        $trimp = $this->computeTrimp($durationMin, (float)$row['avg_heart_rate'], (float)$hrParams['rest_hr'], (float)$hrParams['max_hr']);

        if ($trimp !== null) {
            $upd = $this->db->prepare("UPDATE workouts SET trimp = ? WHERE id = ?");
            $upd->bind_param('di', $trimp, $workoutId);
            $upd->execute();
            $upd->close();
        }

        return $trimp;
    }

    /**
     * Recalculate TRIMP for all workouts of a user (e.g. after max_hr/rest_hr change).
     * Returns count of updated workouts.
     */
    public function recalculateAllTrimp(int $userId): int {
        $hrParams = $this->getUserHrParams($userId);

        $stmt = $this->db->prepare("SELECT id, avg_heart_rate, duration_seconds, duration_minutes FROM workouts WHERE user_id = ? AND avg_heart_rate IS NOT NULL AND avg_heart_rate > 0");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $count = 0;
        $upd = $this->db->prepare("UPDATE workouts SET trimp = ? WHERE id = ?");

        while ($row = $result->fetch_assoc()) {
            $durationMin = 0;
            if (!empty($row['duration_seconds'])) {
                $durationMin = (int)$row['duration_seconds'] / 60.0;
            } elseif (!empty($row['duration_minutes'])) {
                $durationMin = (int)$row['duration_minutes'];
            }

            $trimp = $this->computeTrimp($durationMin, (float)$row['avg_heart_rate'], (float)$hrParams['rest_hr'], (float)$hrParams['max_hr']);
            if ($trimp !== null) {
                $upd->bind_param('di', $trimp, $row['id']);
                $upd->execute();
                $count++;
            }
        }

        $stmt->close();
        $upd->close();

        return $count;
    }

    /**
     * Get full training load data: daily TRIMP, ATL, CTL, TSB.
     * Returns data for the chart and current values.
     *
     * @param int $userId
     * @param int $days Number of days to return in the chart (default 90)
     * @return array
     */
    public function getTrainingLoad(int $userId, int $days = 90): array {
        $hrParams = $this->getUserHrParams($userId);
        $easyPaceSec = $this->userRepo()->getEasyPaceSec($userId);

        // Need extra history to seed CTL (42-day window)
        $totalDays = $days + 42;
        $cutoff = date('Y-m-d', strtotime("-{$totalDays} days"));

        // Collect daily TRIMP from workouts table.
        // Для дедупликации: если на одной дате уже есть auto-запись с близкой дистанцией,
        // пропускаем manual (Strava точнее: есть HR + GPS).
        $dailyTrimp = [];
        $autoDayDistances = [];  // [date => [dist1, dist2, ...]]

        // Auto-imported workouts — only running activities (walking inflates TRIMP unrealistically).
        // TRIMP считается по реальному пульсу (Banister) — race-effort уже учтён через высокий HR,
        // надбавки не нужны.
        $stmt = $this->db->prepare("
            SELECT DATE(start_time) as d, avg_heart_rate, duration_seconds, duration_minutes,
                   distance_km, trimp
            FROM workouts
            WHERE user_id = ? AND DATE(start_time) >= ? AND avg_heart_rate IS NOT NULL AND avg_heart_rate > 0
              AND LOWER(TRIM(COALESCE(activity_type, ''))) IN ('running', 'trail running', 'treadmill')
            ORDER BY start_time
        ");
        $stmt->bind_param('is', $userId, $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $date = $row['d'];
            $trimp = $row['trimp'] !== null ? (float)$row['trimp'] : null;

            if ($trimp === null) {
                $durationMin = 0;
                if (!empty($row['duration_seconds'])) {
                    $durationMin = (int)$row['duration_seconds'] / 60.0;
                } elseif (!empty($row['duration_minutes'])) {
                    $durationMin = (int)$row['duration_minutes'];
                }
                $trimp = $this->computeTrimp($durationMin, (float)$row['avg_heart_rate'], (float)$hrParams['rest_hr'], (float)$hrParams['max_hr']);
            }

            if ($trimp !== null) {
                $dailyTrimp[$date] = ($dailyTrimp[$date] ?? 0) + $trimp;
                if (!empty($row['distance_km'])) {
                    $autoDayDistances[$date][] = (float) $row['distance_km'];
                }
            }
        }
        $stmt->close();

        // Manual workout_log entries — учитываем только беговые (activity_type_id=1=Бег).
        // Если пульса нет — оцениваем TRIMP по темпу через easy_pace_sec атлета.
        $stmt2 = $this->db->prepare("
            SELECT wl.training_date as d, wl.avg_heart_rate, wl.duration_minutes,
                   wl.distance_km, wl.pace, wl.result_time, wl.activity_type_id
            FROM workout_log wl
            WHERE wl.user_id = ? AND wl.is_completed = 1 AND wl.training_date >= ?
              AND (wl.activity_type_id IS NULL OR wl.activity_type_id = 1)
            ORDER BY wl.training_date
        ");
        $stmt2->bind_param('is', $userId, $cutoff);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        while ($row = $result2->fetch_assoc()) {
            $date = $row['d'];
            $durationMin = !empty($row['duration_minutes']) ? (int)$row['duration_minutes'] : 0;

            // Если duration не задан, попробуем вывести из distance × pace.
            $paceSec = $this->parsePaceSec($row['pace'] ?? null);
            $distKm = !empty($row['distance_km']) ? (float)$row['distance_km'] : 0;
            if ($durationMin <= 0 && $paceSec !== null && $distKm > 0) {
                $durationMin = (int) round($distKm * $paceSec / 60);
            }
            if ($durationMin <= 0) continue;

            // Дедупликация: если на эту дату уже есть auto-запись с близкой дистанцией
            // (Strava-импорт того же бега) — пропускаем manual во избежание двойного счёта.
            if ($distKm > 0 && !empty($autoDayDistances[$date])) {
                foreach ($autoDayDistances[$date] as $autoDist) {
                    if (abs($autoDist - $distKm) / max($autoDist, $distKm) < 0.15) {
                        continue 2;  // skip this manual entry
                    }
                }
            }

            $avgHr = !empty($row['avg_heart_rate']) ? (float)$row['avg_heart_rate'] : 0;
            $trimp = null;
            if ($avgHr > 0) {
                // Реальный пульс → Banister TRIMP.
                $trimp = $this->computeTrimp($durationMin, $avgHr, (float)$hrParams['rest_hr'], (float)$hrParams['max_hr']);
            } elseif ($paceSec !== null && $easyPaceSec !== null) {
                // Пульса нет → rTSS (running TSS) по темпу. Кубическая курва уже
                // отражает race-effort, отдельная надбавка не нужна.
                $trimp = $this->estimateTrimpFromPace($durationMin, $paceSec, $easyPaceSec);
            }

            if ($trimp !== null) {
                $dailyTrimp[$date] = ($dailyTrimp[$date] ?? 0) + $trimp;
            }
        }
        $stmt2->close();

        // EMA constants — индустриальный стандарт TrainingPeaks Performance Manager:
        // ATL = 7-day, CTL = 42-day. Для "острой" усталости после гонок используется
        // отдельная метрика ACWR (ниже), а не подкрутка ATL.
        $kAtl = 2.0 / (7 + 1);    // 7-day EMA
        $kCtl = 2.0 / (42 + 1);   // 42-day EMA

        $atl = 0.0;
        $ctl = 0.0;
        $series = [];

        $startDate = strtotime($cutoff);
        $endDate = time();

        for ($ts = $startDate; $ts <= $endDate; $ts += 86400) {
            $date = date('Y-m-d', $ts);
            $trimp = $dailyTrimp[$date] ?? 0;

            $atl = $atl + ($trimp - $atl) * $kAtl;
            $ctl = $ctl + ($trimp - $ctl) * $kCtl;
            $tsb = $ctl - $atl;

            $series[$date] = [
                'date' => $date,
                'trimp' => round($trimp, 1),
                'atl' => round($atl, 1),
                'ctl' => round($ctl, 1),
                'tsb' => round($tsb, 1),
            ];
        }

        // Return only the last $days for the chart (trim the seed period)
        $chartStart = date('Y-m-d', strtotime("-{$days} days"));
        $chartData = array_values(array_filter($series, fn($s) => $s['date'] >= $chartStart));

        // Current values (today or last data point)
        $current = end($chartData) ?: ['atl' => 0, 'ctl' => 0, 'tsb' => 0];

        // Recent workouts with TRIMP (last 14 days)
        $recentCutoff = date('Y-m-d', strtotime('-14 days'));
        $recentStmt = $this->db->prepare("
            SELECT id, DATE(start_time) as d, activity_type, distance_km, duration_seconds, duration_minutes, avg_heart_rate, trimp
            FROM workouts
            WHERE user_id = ? AND DATE(start_time) >= ? AND avg_heart_rate IS NOT NULL AND avg_heart_rate > 0
              AND LOWER(TRIM(COALESCE(activity_type, ''))) IN ('running', 'trail running', 'treadmill')
            ORDER BY start_time DESC
            LIMIT 20
        ");
        $recentStmt->bind_param('is', $userId, $recentCutoff);
        $recentStmt->execute();
        $recentResult = $recentStmt->get_result();
        $recentWorkouts = [];
        while ($row = $recentResult->fetch_assoc()) {
            $trimp = $row['trimp'] !== null ? (float)$row['trimp'] : null;
            if ($trimp === null) {
                $dMin = !empty($row['duration_seconds']) ? (int)$row['duration_seconds'] / 60.0 : (int)($row['duration_minutes'] ?? 0);
                $trimp = $this->computeTrimp($dMin, (float)$row['avg_heart_rate'], (float)$hrParams['rest_hr'], (float)$hrParams['max_hr']);
            }
            $recentWorkouts[] = [
                'id' => (int)$row['id'],
                'date' => $row['d'],
                'activity_type' => $row['activity_type'],
                'distance_km' => $row['distance_km'] ? round((float)$row['distance_km'], 1) : null,
                'duration_min' => !empty($row['duration_seconds']) ? round((int)$row['duration_seconds'] / 60.0) : ($row['duration_minutes'] ? (int)$row['duration_minutes'] : null),
                'avg_hr' => (int)$row['avg_heart_rate'],
                'trimp' => $trimp !== null ? round($trimp, 1) : null,
            ];
        }
        $recentStmt->close();

        // Count days with TRIMP data
        $daysWithData = count(array_filter($chartData, fn($d) => $d['trimp'] > 0));

        // ACWR (Acute:Chronic Workload Ratio) — детектор острой усталости/спайков нагрузки.
        // Дополняет TSB: TSB про среднесрочную "форму", ACWR про резкие скачки за последнюю неделю.
        // Сладкое пятно 0.8–1.3, риск 1.3–1.5, опасно >1.5 (мета-анализ Springer 2024).
        $acwr = $this->computeAcwr($dailyTrimp);

        return [
            'available' => $daysWithData >= 7,
            'current' => [
                'atl' => $current['atl'],
                'ctl' => $current['ctl'],
                'tsb' => $current['tsb'],
                'acwr' => $acwr['ratio'],
                'acwr_status' => $acwr['status'],
            ],
            'hr_params' => $hrParams,
            'daily' => $chartData,
            'recent_workouts' => $recentWorkouts,
            'days_with_data' => $daysWithData,
        ];
    }

    /**
     * ACWR = acute (7-day rolling sum) / chronic (28-day rolling avg-per-week).
     * Возвращает ratio и status: 'detrained' (<0.8), 'optimal' (0.8–1.3),
     * 'caution' (1.3–1.5), 'risk' (>1.5), 'insufficient' если мало данных.
     *
     * @param array $dailyTrimp [date => trimp_sum]
     */
    private function computeAcwr(array $dailyTrimp): array {
        $today = time();
        $acuteSum = 0.0;
        $chronicSum = 0.0;

        // Acute: последние 7 дней (включая сегодня).
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', $today - $i * 86400);
            $acuteSum += $dailyTrimp[$d] ?? 0;
        }
        // Chronic: последние 28 дней, делим на 4 для week-equivalent среднего.
        for ($i = 0; $i < 28; $i++) {
            $d = date('Y-m-d', $today - $i * 86400);
            $chronicSum += $dailyTrimp[$d] ?? 0;
        }
        $chronicWeeklyAvg = $chronicSum / 4.0;

        if ($chronicWeeklyAvg < 30) {
            // Нет базы для сравнения — недостаточно данных
            return ['ratio' => null, 'status' => 'insufficient'];
        }

        $ratio = $acuteSum / $chronicWeeklyAvg;
        $status = 'optimal';
        if ($ratio < 0.8) $status = 'detrained';
        elseif ($ratio > 1.5) $status = 'risk';
        elseif ($ratio > 1.3) $status = 'caution';

        return ['ratio' => round($ratio, 2), 'status' => $status];
    }
}
