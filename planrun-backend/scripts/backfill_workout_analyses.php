#!/usr/bin/env php
<?php
/**
 * Backfill workout_analyses для пользователя:
 *  - проходит все workouts (и опционально workout_log) с laps/agg-данными;
 *  - запускает WorkoutStructureAnalyzer для классификации;
 *  - формирует summary_line программно (БЕЗ обращения к LLM, чтобы не жечь токены);
 *  - оставляет llm_review_text = NULL для исторических — он наполнится естественно
 *    при следующем createPostWorkoutAnalysisMessage или /admin re-analysis.
 *
 * Usage:
 *   php scripts/backfill_workout_analyses.php <user_id> [--since=YYYY-MM-DD] [--dry-run]
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WorkoutStructureAnalyzer.php';
require_once $baseDir . '/services/WorkoutAnalysisRepository.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/backfill_workout_analyses.php <user_id> [--since=YYYY-MM-DD] [--dry-run]\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$since = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--since=')) {
        $since = substr($arg, 8);
    }
}
if (!$since) {
    // По умолчанию — старт текущего активного плана или 6 месяцев назад
    $since = date('Y-m-d', strtotime('-6 months'));
}

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$analyzer = new WorkoutStructureAnalyzer($db);
$repo = new WorkoutAnalysisRepository($db);

echo "User: {$userId}, since: {$since}, dry-run: " . ($dryRun ? 'YES' : 'no') . "\n\n";

$workouts = fetchWorkouts($db, $userId, $since);
echo "Found " . count($workouts) . " workouts.\n";

$stats = ['processed' => 0, 'saved' => 0, 'skipped' => 0, 'updated' => 0];

foreach ($workouts as $w) {
    $stats['processed']++;
    $sourceId = (int) $w['id'];
    $workoutDate = (string) $w['workout_date'];

    $structure = $analyzer->analyze($sourceId, null, $userId);

    $planned = fetchPlannedForDate($db, $userId, $workoutDate);
    $feedback = fetchFeedback($db, $userId, 'workout', $sourceId);

    $data = [
        'user_id' => $userId,
        'source_kind' => 'workout',
        'source_id' => $sourceId,
        'workout_date' => $workoutDate,
        'planned_type' => $planned['type'] ?? null,
        'planned_description' => $planned['description'] ?? null,
        'planned_is_key' => $planned['is_key_workout'] ?? null,
        'actual_distance_km' => $w['distance_km'] ?? null,
        'actual_duration_min' => $w['duration_minutes'] ?? null,
        'actual_avg_pace' => $w['avg_pace'] ?? null,
        'actual_avg_hr' => $w['avg_heart_rate'] ?? null,
        'actual_max_hr' => $w['max_heart_rate'] ?? null,
        'detected_type' => $structure['type'] ?? null,
        'detected_confidence' => $structure['confidence'] ?? null,
        'intensity' => $structure['avg_hr_pct_max'] ?? null,
        'pace_variance' => $structure['pace_variance'] ?? null,
        'structure' => $structure,
        'llm_review_text' => null,
        'feedback_rpe' => $feedback['session_rpe'] ?? null,
        'feedback_legs' => $feedback['legs_score'] ?? null,
        'feedback_pain_flag' => $feedback['pain_flag'] ?? null,
        'feedback_fatigue_flag' => $feedback['fatigue_flag'] ?? null,
    ];
    $data['summary_line'] = WorkoutAnalysisRepository::formatSummaryLine($data);

    echo sprintf("  %s [w=%d]: %s\n", $workoutDate, $sourceId, $data['summary_line']);

    if (!$dryRun) {
        $existing = $repo->getBySource($userId, 'workout', $sourceId);
        $id = $repo->save($data);
        if ($id > 0) {
            $stats['saved']++;
        } elseif ($existing) {
            $stats['updated']++;
        } else {
            $stats['skipped']++;
        }
    }
}

echo "\nStats: " . json_encode($stats) . "\n";
echo ($dryRun ? "DRY-RUN: nothing saved.\n" : "Done.\n");


// ── helpers ──

function fetchWorkouts($db, int $userId, string $since): array {
    $stmt = $db->prepare(
        "SELECT id,
                DATE(COALESCE(end_time, start_time)) AS workout_date,
                distance_km, duration_minutes, avg_pace,
                avg_heart_rate, max_heart_rate
         FROM workouts
         WHERE user_id = ? AND DATE(COALESCE(end_time, start_time)) >= ?
         ORDER BY start_time ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('is', $userId, $since);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function fetchPlannedForDate($db, int $userId, string $date): ?array {
    $stmt = $db->prepare(
        "SELECT d.type, d.description, d.is_key_workout
         FROM training_plan_days d
         INNER JOIN training_plan_weeks w ON w.id = d.week_id
         WHERE w.user_id = ? AND d.date = ?
         ORDER BY d.id ASC LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function fetchFeedback($db, int $userId, string $sourceKind, int $sourceId): array {
    $stmt = $db->prepare(
        "SELECT session_rpe, legs_score, pain_flag, fatigue_flag
         FROM post_workout_followups
         WHERE user_id = ? AND source_kind = ? AND source_id = ? AND status IN ('responded', 'completed')
         ORDER BY responded_at DESC, id DESC LIMIT 1"
    );
    if (!$stmt) return [];
    $stmt->bind_param('isi', $userId, $sourceKind, $sourceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: [];
}
