<?php
/**
 * GoalProgressService — VDOT tracking, milestone detection, weekly snapshots.
 *
 * Captures a weekly snapshot of the user's fitness metrics (VDOT, volume,
 * compliance, ACWR) and goal progress (predicted race time vs target).
 * Detects notable milestones (VDOT improvement, volume record, consistency
 * streak, predicted time beating target) for use by ProactiveCoachService
 * and weekly reviews.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

class GoalProgressService extends BaseService {

    private $statsService;
    private $contextBuilder;

    public function __construct($db, $statsService = null, $contextBuilder = null) {
        parent::__construct($db);

        if (!$statsService) {
            require_once __DIR__ . '/StatsService.php';
            $statsService = new StatsService($db);
        }
        $this->statsService = $statsService;

        if (!$contextBuilder) {
            require_once __DIR__ . '/ChatContextBuilder.php';
            $contextBuilder = new ChatContextBuilder($db);
        }
        $this->contextBuilder = $contextBuilder;
    }

    // ── Public API ──

    /**
     * Take a weekly snapshot for a single user. Idempotent per (user, date).
     * @return array The snapshot row
     */
    public function takeSnapshot(int $userId, ?string $date = null): array {
        $date = $date ?: date('Y-m-d');

        $user = $this->getUser($userId);
        if (!$user) return [];

        $goalType = $user['goal_type'] ?? 'health';
        $raceDate = $user['race_date'] ?? $user['target_marathon_date'] ?? null;
        $raceTargetTimeSec = $this->parseTargetTimeSec($user);

        $targetDistKm = $this->parseTargetDistKm($user);
        $vdotData = $this->statsService->getBestResultForVdot($userId, 6, $targetDistKm);
        $vdot = $vdotData ? round((float) $vdotData['vdot'], 1) : null;
        $vdotSource = $vdotData ? ($vdotData['vdot_source_detail'] ?? 'best_result') : null;

        $weekStats = $this->getWeekStats($userId, $date);
        $acwr = $this->contextBuilder->calculateACWR($userId);
        $compliance = $this->contextBuilder->getWeeklyCompliance($userId);

        $compliancePct = null;
        if (($compliance['planned'] ?? 0) > 0) {
            $compliancePct = (int) round(($compliance['completed'] / $compliance['planned']) * 100);
        }

        $predictedTimeSec = null;
        if ($vdot && $targetDistKm > 0) {
            $predictedTimeSec = predictRaceTime($vdot, $targetDistKm);
        }

        $weeksToGoal = null;
        if ($raceDate) {
            $diff = (strtotime($raceDate) - strtotime($date));
            if ($diff > 0) {
                $weeksToGoal = (int) ceil($diff / (7 * 86400));
            }
        }

        $snapshot = [
            'user_id' => $userId,
            'snapshot_date' => $date,
            'vdot' => $vdot,
            'vdot_source' => $vdotSource ? mb_substr($vdotSource, 0, 30) : null,
            'weekly_km' => $weekStats['km'],
            'weekly_sessions' => $weekStats['sessions'],
            'compliance_pct' => $compliancePct,
            'longest_run_km' => $weekStats['longest_km'],
            'acwr' => $acwr['acwr'],
            'acwr_zone' => $acwr['zone'] ?? null,
            'goal_type' => $goalType,
            'race_date' => $raceDate,
            'race_target_time_sec' => $raceTargetTimeSec,
            'predicted_time_sec' => $predictedTimeSec,
            'weeks_to_goal' => $weeksToGoal,
        ];

        $this->upsertSnapshot($snapshot);

        return $snapshot;
    }

    /**
     * Process all active users — intended to be called from a weekly cron job.
     */
    public function processAllUsers(?string $date = null): int {
        $date = $date ?: date('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT id FROM users WHERE onboarding_completed = 1 AND banned = 0"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $count = 0;

        while ($row = $result->fetch_assoc()) {
            try {
                $this->takeSnapshot((int) $row['id'], $date);
                $count++;
            } catch (\Throwable $e) {
                $this->logError("GoalProgress snapshot failed for user {$row['id']}: " . $e->getMessage());
            }
        }
        $stmt->close();

        $this->logInfo("GoalProgressService: snapshots taken for {$count} users on {$date}");
        return $count;
    }

    /**
     * Detect milestones by comparing the latest snapshot with history.
     * Returns an array of milestone events (may be empty).
     */
    public function detectMilestones(int $userId): array {
        $snapshots = $this->getRecentSnapshots($userId, 8);
        if (count($snapshots) < 2) return [];

        $current = $snapshots[0];
        $previous = $snapshots[1];
        $milestones = [];

        if ($current['vdot'] && $previous['vdot']) {
            $delta = round($current['vdot'] - $previous['vdot'], 1);
            if ($delta >= 0.5) {
                $milestones[] = [
                    'type' => 'vdot_improvement',
                    'priority' => 3,
                    'data' => [
                        'current_vdot' => $current['vdot'],
                        'previous_vdot' => $previous['vdot'],
                        'delta' => $delta,
                    ],
                ];
            }
        }

        if ($current['weekly_km'] && $this->isVolumeRecord($current, $snapshots)) {
            $milestones[] = [
                'type' => 'volume_record',
                'priority' => 2,
                'data' => [
                    'weekly_km' => $current['weekly_km'],
                ],
            ];
        }

        $streak = $this->getConsistencyStreak($snapshots);
        if ($streak >= 4 && $streak % 4 === 0) {
            $milestones[] = [
                'type' => 'consistency_streak',
                'priority' => 2,
                'data' => ['weeks' => $streak],
            ];
        }

        if ($current['predicted_time_sec'] && $current['race_target_time_sec']) {
            $beating = $current['predicted_time_sec'] <= $current['race_target_time_sec'];
            $wasBehind = !$previous['predicted_time_sec']
                || $previous['predicted_time_sec'] > ($previous['race_target_time_sec'] ?? PHP_INT_MAX);

            if ($beating && $wasBehind) {
                $milestones[] = [
                    'type' => 'goal_achievable',
                    'priority' => 4,
                    'data' => [
                        'predicted_sec' => $current['predicted_time_sec'],
                        'target_sec' => $current['race_target_time_sec'],
                        'margin_sec' => $current['race_target_time_sec'] - $current['predicted_time_sec'],
                    ],
                ];
            }
        }

        return $milestones;
    }

    /**
     * Get a formatted summary of goal progress for AI prompts / reviews.
     */
    public function getProgressSummary(int $userId): ?array {
        $snapshots = $this->getRecentSnapshots($userId, 8);
        if (empty($snapshots)) return null;

        $current = $snapshots[0];
        $oldest = end($snapshots);

        $summary = [
            'current_vdot' => $current['vdot'],
            'vdot_trend' => count($snapshots) >= 2 && $current['vdot'] && $snapshots[1]['vdot']
                ? round($current['vdot'] - $snapshots[1]['vdot'], 1)
                : null,
            'vdot_trend_8w' => $current['vdot'] && $oldest['vdot']
                ? round($current['vdot'] - $oldest['vdot'], 1)
                : null,
            'weeks_tracked' => count($snapshots),
            'avg_weekly_km' => $this->avgField($snapshots, 'weekly_km'),
            'avg_compliance' => $this->avgField($snapshots, 'compliance_pct'),
            'consistency_streak' => $this->getConsistencyStreak($snapshots),
            'predicted_time_sec' => $current['predicted_time_sec'],
            'race_target_time_sec' => $current['race_target_time_sec'],
            'weeks_to_goal' => $current['weeks_to_goal'],
            'goal_type' => $current['goal_type'],
        ];

        if ($summary['predicted_time_sec'] && $summary['race_target_time_sec']) {
            $summary['gap_sec'] = $summary['predicted_time_sec'] - $summary['race_target_time_sec'];
            $summary['on_track'] = $summary['gap_sec'] <= 0;
        }

        return $summary;
    }

    // ── Data access ──

    public function getRecentSnapshots(int $userId, int $limit = 8): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM goal_progress_snapshots WHERE user_id = ? ORDER BY snapshot_date DESC LIMIT ?"
        );
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$r) {
            $r['vdot'] = $r['vdot'] !== null ? (float) $r['vdot'] : null;
            $r['weekly_km'] = $r['weekly_km'] !== null ? (float) $r['weekly_km'] : null;
            $r['longest_run_km'] = $r['longest_run_km'] !== null ? (float) $r['longest_run_km'] : null;
            $r['acwr'] = $r['acwr'] !== null ? (float) $r['acwr'] : null;
            $r['compliance_pct'] = $r['compliance_pct'] !== null ? (int) $r['compliance_pct'] : null;
            $r['predicted_time_sec'] = $r['predicted_time_sec'] !== null ? (int) $r['predicted_time_sec'] : null;
            $r['race_target_time_sec'] = $r['race_target_time_sec'] !== null ? (int) $r['race_target_time_sec'] : null;
            $r['weeks_to_goal'] = $r['weeks_to_goal'] !== null ? (int) $r['weeks_to_goal'] : null;
        }

        return $rows;
    }

    // ── Private helpers ──

    private function getUser(int $userId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, goal_type, race_distance, race_date, race_target_time,
                    target_marathon_date, target_marathon_time
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function parseTargetTimeSec(array $user): ?int {
        $time = $user['race_target_time'] ?? $user['target_marathon_time'] ?? null;
        if (!$time) return null;
        $parts = explode(':', $time);
        if (count($parts) === 3) return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
        if (count($parts) === 2) return (int) $parts[0] * 60 + (int) $parts[1];
        return null;
    }

    private function parseTargetDistKm(array $user): float {
        $dist = $user['race_distance'] ?? null;
        if (!$dist) return 0;

        $map = [
            '5k' => 5.0, '5' => 5.0,
            '10k' => 10.0, '10' => 10.0,
            'half' => 21.0975, 'half_marathon' => 21.0975, '21' => 21.0975, '21k' => 21.0975,
            'marathon' => 42.195, 'full_marathon' => 42.195, '42' => 42.195, '42k' => 42.195,
        ];

        $key = strtolower(trim($dist));
        if (isset($map[$key])) return $map[$key];
        $num = (float) preg_replace('/[^0-9.]/', '', $dist);
        return $num > 0 ? $num : 0;
    }

    private function getWeekStats(int $userId, string $date): array {
        $monday = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $sunday = date('Y-m-d', strtotime('sunday this week', strtotime($date)));

        $km = 0;
        $sessions = 0;
        $longestKm = 0;

        $stmt = $this->db->prepare(
            "SELECT distance_km FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date BETWEEN ? AND ?"
        );
        $stmt->bind_param('iss', $userId, $monday, $sunday);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $d = (float) ($row['distance_km'] ?? 0);
            if ($d > 0) {
                $km += $d;
                $sessions++;
                $longestKm = max($longestKm, $d);
            }
        }
        $stmt->close();

        $stmt2 = $this->db->prepare(
            "SELECT distance_km FROM workouts
             WHERE user_id = ? AND DATE(start_time) BETWEEN ? AND ?"
        );
        $stmt2->bind_param('iss', $userId, $monday, $sunday);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) {
            $d = (float) ($row['distance_km'] ?? 0);
            if ($d > 0) {
                $km += $d;
                $sessions++;
                $longestKm = max($longestKm, $d);
            }
        }
        $stmt2->close();

        return [
            'km' => round($km, 1),
            'sessions' => $sessions,
            'longest_km' => round($longestKm, 1),
        ];
    }

    private function upsertSnapshot(array $data): void {
        $sql = "INSERT INTO goal_progress_snapshots
                (user_id, snapshot_date, vdot, vdot_source, weekly_km, weekly_sessions,
                 compliance_pct, longest_run_km, acwr, acwr_zone, goal_type,
                 race_date, race_target_time_sec, predicted_time_sec, weeks_to_goal)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    vdot = VALUES(vdot),
                    vdot_source = VALUES(vdot_source),
                    weekly_km = VALUES(weekly_km),
                    weekly_sessions = VALUES(weekly_sessions),
                    compliance_pct = VALUES(compliance_pct),
                    longest_run_km = VALUES(longest_run_km),
                    acwr = VALUES(acwr),
                    acwr_zone = VALUES(acwr_zone),
                    goal_type = VALUES(goal_type),
                    race_date = VALUES(race_date),
                    race_target_time_sec = VALUES(race_target_time_sec),
                    predicted_time_sec = VALUES(predicted_time_sec),
                    weeks_to_goal = VALUES(weeks_to_goal)";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param(
            'issdsdiddsssiii',
            $data['user_id'],
            $data['snapshot_date'],
            $data['vdot'],
            $data['vdot_source'],
            $data['weekly_km'],
            $data['weekly_sessions'],
            $data['compliance_pct'],
            $data['longest_run_km'],
            $data['acwr'],
            $data['acwr_zone'],
            $data['goal_type'],
            $data['race_date'],
            $data['race_target_time_sec'],
            $data['predicted_time_sec'],
            $data['weeks_to_goal']
        );
        $stmt->execute();
        $stmt->close();
    }

    private function isVolumeRecord(array $current, array $allSnapshots): bool {
        foreach ($allSnapshots as $i => $s) {
            if ($i === 0) continue;
            if (($s['weekly_km'] ?? 0) >= $current['weekly_km']) return false;
        }
        return true;
    }

    private function getConsistencyStreak(array $snapshots): int {
        $streak = 0;
        foreach ($snapshots as $s) {
            if (($s['compliance_pct'] ?? 0) >= 60 && ($s['weekly_sessions'] ?? 0) >= 2) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }

    private function avgField(array $snapshots, string $field): ?float {
        $values = array_filter(array_column($snapshots, $field), fn($v) => $v !== null);
        if (empty($values)) return null;
        return round(array_sum($values) / count($values), 1);
    }
}
