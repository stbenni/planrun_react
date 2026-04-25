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

        // Need extra history to seed CTL (42-day window)
        $totalDays = $days + 42;
        $cutoff = date('Y-m-d', strtotime("-{$totalDays} days"));

        // Collect daily TRIMP from workouts table
        $dailyTrimp = [];

        // Auto-imported workouts — only running activities (walking inflates TRIMP unrealistically)
        $stmt = $this->db->prepare("
            SELECT DATE(start_time) as d, avg_heart_rate, duration_seconds, duration_minutes, trimp
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
            }
        }
        $stmt->close();

        // Manual workout_log entries
        $stmt2 = $this->db->prepare("
            SELECT wl.training_date as d, wl.avg_heart_rate, wl.duration_minutes
            FROM workout_log wl
            WHERE wl.user_id = ? AND wl.is_completed = 1 AND wl.training_date >= ?
              AND wl.avg_heart_rate IS NOT NULL AND wl.avg_heart_rate > 0
            ORDER BY wl.training_date
        ");
        $stmt2->bind_param('is', $userId, $cutoff);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        while ($row = $result2->fetch_assoc()) {
            $date = $row['d'];
            $durationMin = !empty($row['duration_minutes']) ? (int)$row['duration_minutes'] : 0;
            if ($durationMin <= 0) continue;

            $trimp = $this->computeTrimp($durationMin, (float)$row['avg_heart_rate'], (float)$hrParams['rest_hr'], (float)$hrParams['max_hr']);
            if ($trimp !== null) {
                $dailyTrimp[$date] = ($dailyTrimp[$date] ?? 0) + $trimp;
            }
        }
        $stmt2->close();

        // Build continuous date range and compute ATL/CTL/TSB using EMA
        $kAtl = 2.0 / (7 + 1);    // 7-day EMA constant
        $kCtl = 2.0 / (42 + 1);   // 42-day EMA constant

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

        return [
            'available' => $daysWithData >= 7,
            'current' => [
                'atl' => $current['atl'],
                'ctl' => $current['ctl'],
                'tsb' => $current['tsb'],
            ],
            'hr_params' => $hrParams,
            'daily' => $chartData,
            'recent_workouts' => $recentWorkouts,
            'days_with_data' => $daysWithData,
        ];
    }
}
