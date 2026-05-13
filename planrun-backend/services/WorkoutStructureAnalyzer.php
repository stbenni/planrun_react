<?php
/**
 * Структурный анализатор тренировки на основе workout_laps.
 *
 * Считывает 1km-сплиты (или произвольные лапы), классифицирует тренировку
 * (interval / tempo / fartlek / easy / long / mixed) и выделяет work-set/recovery
 * для дальнейшей передачи в LLM-разбор.
 */

class WorkoutStructureAnalyzer {

    private const ZONE_THRESHOLDS = [
        ['min' => 0.00, 'max' => 0.60, 'name' => 'z1', 'label' => 'recovery'],
        ['min' => 0.60, 'max' => 0.70, 'name' => 'z2', 'label' => 'easy'],
        ['min' => 0.70, 'max' => 0.80, 'name' => 'z3', 'label' => 'aerobic'],
        ['min' => 0.80, 'max' => 0.90, 'name' => 'z4', 'label' => 'threshold'],
        ['min' => 0.90, 'max' => 1.20, 'name' => 'z5', 'label' => 'VO2max'],
    ];

    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Анализирует тренировку по workout_id.
     * @return array|null null если данных недостаточно (нет лапов).
     */
    public function analyze(int $workoutId, ?int $maxHrCap = null, ?int $userId = null): ?array {
        $laps = $this->loadLaps($workoutId);
        if (count($laps) < 3) {
            return null;
        }

        if ($maxHrCap === null) {
            // Workout-локальный max часто занижен — используем максимум за год по пользователю.
            $maxHrCap = $this->fetchWorkoutMaxHr($workoutId);
            if ($userId !== null) {
                $userMax = $this->fetchUserGlobalMaxHr($userId);
                if ($userMax !== null && ($maxHrCap === null || $userMax > $maxHrCap)) {
                    $maxHrCap = $userMax;
                }
            }
        }

        // Принимаем лап как "полноценный", если distance >= 0.5km и есть pace.
        $valid = [];
        foreach ($laps as $lap) {
            $km = (float) ($lap['distance_km'] ?? 0);
            $sec = (int) ($lap['moving_seconds'] ?? 0);
            if ($km < 0.5 || $sec < 60) continue;
            $lap['pace_sec_per_km'] = (int) round($sec / $km);
            $valid[] = $lap;
        }

        if (count($valid) < 3) {
            return null;
        }

        $paces = array_column($valid, 'pace_sec_per_km');
        $median = $this->median($paces);
        $fastThreshold = $median * 0.85;   // на 15%+ быстрее медианы
        $slowThreshold = $median * 1.15;   // на 15%+ медленнее медианы

        $fast = [];
        $slow = [];
        $normal = [];
        foreach ($valid as $lap) {
            if ($lap['pace_sec_per_km'] < $fastThreshold) {
                $fast[] = $lap;
            } elseif ($lap['pace_sec_per_km'] > $slowThreshold) {
                $slow[] = $lap;
            } else {
                $normal[] = $lap;
            }
        }

        $variance = $this->relativeVariance($paces);
        $alternating = $this->hasAlternation($valid, $fastThreshold, $slowThreshold);

        $maxHr = $maxHrCap;

        $avgHr = $this->mean(array_filter(array_column($valid, 'avg_heart_rate')));
        $totalKm = array_sum(array_map(fn($l) => (float) $l['distance_km'], $valid));

        // Классификация
        $type = 'mixed';
        $confidence = 'low';

        $intensity = ($maxHr && $avgHr) ? ($avgHr / $maxHr) : null;

        if ($totalKm >= 21 && $intensity !== null && $intensity >= 0.82) {
            // длинная дистанция (полумарафон+) на высоком пульсе = гонка/соревнование.
            $type = 'race';
            $confidence = $intensity >= 0.85 ? 'high' : 'medium';
        } elseif (count($fast) >= 2 && $alternating) {
            $type = 'interval';
            $confidence = 'high';
        } elseif (count($fast) >= 2 && !$alternating && count($fast) / max(1, count($valid)) >= 0.5 && $variance < 0.08) {
            $type = 'tempo';
            $confidence = 'medium';
        } elseif (count($fast) >= 2 && !$alternating) {
            $type = 'fartlek';
            $confidence = 'medium';
        } elseif ($variance < 0.07 && $intensity !== null && $intensity >= 0.78) {
            // ровный темп на повышенном пульсе — темповая/threshold-работа
            $type = 'tempo';
            $confidence = $intensity >= 0.85 ? 'high' : 'medium';
        } elseif ($intensity !== null && $intensity < 0.70) {
            $type = $totalKm >= 15 ? 'long' : 'easy';
            $confidence = $variance < 0.10 ? 'high' : 'medium';
        } elseif ($intensity !== null && $intensity < 0.78 && $variance < 0.10) {
            $type = $totalKm >= 15 ? 'long' : 'easy';
            $confidence = 'medium';
        }

        return [
            'type' => $type,
            'confidence' => $confidence,
            'lap_count' => count($valid),
            'total_km' => round($totalKm, 2),
            'median_pace' => $this->formatPace((int) $median),
            'median_pace_sec' => (int) $median,
            'pace_variance' => round($variance, 3),
            'alternating' => $alternating,
            'max_hr' => $maxHr,
            'avg_hr' => $avgHr ? (int) round($avgHr) : null,
            'avg_hr_pct_max' => ($maxHr && $avgHr) ? round($avgHr / $maxHr, 2) : null,
            'work_laps' => $this->summariseLaps($fast, $maxHr),
            'recovery_laps' => $this->summariseLaps($slow, $maxHr),
            'normal_laps' => $this->summariseLaps($normal, $maxHr),
            'lap_table' => $this->buildLapTable($valid, $fastThreshold, $slowThreshold, $maxHr),
            'narrative' => $this->buildNarrative($type, $fast, $slow, $normal, $alternating, $maxHr),
        ];
    }

    // ── Loading ──────────────────────────────────────────────────────────

    private function loadLaps(int $workoutId): array {
        $stmt = $this->db->prepare(
            "SELECT lap_index, lap_name, distance_km, moving_seconds, avg_heart_rate, max_heart_rate, elevation_gain
             FROM workout_laps
             WHERE workout_id = ?
             ORDER BY lap_index ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $workoutId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function fetchWorkoutMaxHr(int $workoutId): ?int {
        $stmt = $this->db->prepare("SELECT max_heart_rate FROM workouts WHERE id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $workoutId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $hr = (int) ($row['max_heart_rate'] ?? 0);
        return $hr > 0 ? $hr : null;
    }

    private function fetchUserGlobalMaxHr(int $userId): ?int {
        // Берём топ-3 значения max_heart_rate за последние 12 мес и усредняем,
        // чтобы игнорировать сенсорные выбросы (вроде HR=223 у взрослого).
        // Также cap: реалистичный максимум для взрослого = 210.
        $stmt = $this->db->prepare(
            "SELECT max_heart_rate
             FROM workouts
             WHERE user_id = ?
               AND max_heart_rate BETWEEN 100 AND 210
               AND start_time > DATE_SUB(NOW(), INTERVAL 12 MONTH)
             ORDER BY max_heart_rate DESC
             LIMIT 3"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[] = (int) $row['max_heart_rate'];
        }
        $stmt->close();
        if (empty($values)) return null;
        return (int) round(array_sum($values) / count($values));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function median(array $values): float {
        if (empty($values)) return 0.0;
        sort($values);
        $n = count($values);
        $mid = (int) ($n / 2);
        if ($n % 2 === 0) {
            return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
        }
        return (float) $values[$mid];
    }

    private function mean(array $values): ?float {
        $values = array_filter($values, fn($v) => $v !== null && $v !== '' && (float) $v > 0);
        if (empty($values)) return null;
        return array_sum($values) / count($values);
    }

    private function relativeVariance(array $paces): float {
        if (count($paces) < 2) return 0.0;
        $mean = array_sum($paces) / count($paces);
        if ($mean <= 0) return 0.0;
        $sq = 0.0;
        foreach ($paces as $p) {
            $sq += ($p - $mean) ** 2;
        }
        $stddev = sqrt($sq / count($paces));
        return $stddev / $mean;
    }

    private function hasAlternation(array $laps, float $fastT, float $slowT): bool {
        $marks = [];
        foreach ($laps as $l) {
            if ($l['pace_sec_per_km'] < $fastT) $marks[] = 'F';
            elseif ($l['pace_sec_per_km'] > $slowT) $marks[] = 'S';
            else $marks[] = 'N';
        }
        // Ищем паттерн F → (S или N) → F
        for ($i = 0; $i < count($marks) - 2; $i++) {
            if ($marks[$i] === 'F' && $marks[$i + 1] !== 'F' && $marks[$i + 2] === 'F') {
                return true;
            }
        }
        return false;
    }

    private function summariseLaps(array $laps, ?int $maxHr): array {
        return array_map(function ($l) use ($maxHr) {
            return [
                'idx' => (int) $l['lap_index'],
                'km' => round((float) $l['distance_km'], 2),
                'pace' => $this->formatPace($l['pace_sec_per_km']),
                'pace_sec' => $l['pace_sec_per_km'],
                'hr' => $l['avg_heart_rate'] ? (int) $l['avg_heart_rate'] : null,
                'hr_pct' => ($maxHr && $l['avg_heart_rate']) ? round($l['avg_heart_rate'] / $maxHr, 2) : null,
                'zone' => $this->zoneForHr($l['avg_heart_rate'] ?? null, $maxHr),
            ];
        }, $laps);
    }

    private function buildLapTable(array $laps, float $fastT, float $slowT, ?int $maxHr): array {
        $rows = [];
        foreach ($laps as $l) {
            $mark = '·';
            if ($l['pace_sec_per_km'] < $fastT) $mark = '▲';
            elseif ($l['pace_sec_per_km'] > $slowT) $mark = '▽';
            $rows[] = [
                'idx' => (int) $l['lap_index'],
                'km' => round((float) $l['distance_km'], 2),
                'pace' => $this->formatPace($l['pace_sec_per_km']),
                'hr' => $l['avg_heart_rate'] ? (int) $l['avg_heart_rate'] : null,
                'zone' => $this->zoneForHr($l['avg_heart_rate'] ?? null, $maxHr),
                'mark' => $mark,
            ];
        }
        return $rows;
    }

    private function zoneForHr(?int $hr, ?int $maxHr): ?string {
        if (!$hr || !$maxHr) return null;
        $pct = $hr / $maxHr;
        foreach (self::ZONE_THRESHOLDS as $z) {
            if ($pct >= $z['min'] && $pct < $z['max']) {
                return $z['name'];
            }
        }
        return null;
    }

    private function formatPace(int $secPerKm): string {
        if ($secPerKm <= 0) return '—';
        $m = (int) ($secPerKm / 60);
        $s = $secPerKm % 60;
        return sprintf('%d:%02d', $m, $s);
    }

    private function buildNarrative(string $type, array $fast, array $slow, array $normal, bool $alternating, ?int $maxHr): string {
        switch ($type) {
            case 'interval':
                $cnt = count($fast);
                $paces = array_map(fn($l) => $this->formatPace($l['pace_sec_per_km']), $fast);
                $hrs = array_filter(array_map(fn($l) => (int) $l['avg_heart_rate'], $fast));
                $line = "Интервальная тренировка: {$cnt} " . $this->pluralIntervals($cnt) . " в темпе " . implode('/', array_unique($paces));
                if (!empty($hrs)) {
                    $hrMin = min($hrs);
                    $hrMax = max($hrs);
                    $line .= " при пульсе " . ($hrMin === $hrMax ? $hrMin : "{$hrMin}–{$hrMax}");
                }
                if (!empty($slow)) {
                    $rPaces = array_unique(array_map(fn($l) => $this->formatPace($l['pace_sec_per_km']), $slow));
                    $line .= "; восстановление между отрезками в темпе " . implode('/', $rPaces);
                }
                return $line;
            case 'tempo':
                $line = "Темповая работа: " . count($fast) . " " . $this->pluralKm(count($fast)) . " в темпе ";
                $paces = array_unique(array_map(fn($l) => $this->formatPace($l['pace_sec_per_km']), $fast));
                $line .= implode('/', $paces);
                return $line;
            case 'fartlek':
                return "Фартлек: чередование быстрых и спокойных отрезков без чёткой структуры.";
            case 'race':
                return "Гонка/соревновательный темп на длинной дистанции.";
            case 'long':
                return "Длительная тренировка в ровном лёгком темпе.";
            case 'easy':
                return "Лёгкий бег в ровном темпе.";
            default:
                return "Смешанная тренировка: чёткой структуры не выделено.";
        }
    }

    private function pluralIntervals(int $n): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) return 'рабочий отрезок';
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return 'рабочих отрезка';
        return 'рабочих отрезков';
    }

    private function pluralKm(int $n): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) return 'километр';
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return 'километра';
        return 'километров';
    }
}
