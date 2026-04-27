#!/usr/bin/env php
<?php
/**
 * Sends due post-workout AI coach follow-ups.
 *
 * Cron: every 5 minutes:
 *   php /path/to/planrun-backend/scripts/post_workout_followups.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PostWorkoutFollowupService.php';

if ((int) env('POST_WORKOUT_FOLLOWUPS_ENABLED', 1) !== 1) {
    echo "PostWorkoutFollowups disabled (POST_WORKOUT_FOLLOWUPS_ENABLED != 1)\n";
    exit(0);
}

$lockPath = sys_get_temp_dir() . '/planrun-post-workout-followups.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "PostWorkoutFollowups already running\n";
    exit(0);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$limitArg = isset($argv[1]) ? (int) $argv[1] : 0;
$limit = $limitArg > 0 ? $limitArg : (int) env('POST_WORKOUT_FOLLOWUPS_LIMIT', 50);
$limit = max(1, min(500, $limit));

try {
    $service = new PostWorkoutFollowupService($db);
    $stats = $service->processDueFollowups($limit);

    $total = array_sum(array_map('intval', $stats));
    if ($total > 0 || (int) env('POST_WORKOUT_FOLLOWUPS_VERBOSE', 0) === 1) {
        echo sprintf(
            "PostWorkoutFollowups: sent=%d skipped=%d expired=%d errors=%d\n",
            (int) ($stats['sent'] ?? 0),
            (int) ($stats['skipped'] ?? 0),
            (int) ($stats['expired'] ?? 0),
            (int) ($stats['errors'] ?? 0)
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, "PostWorkoutFollowups fatal: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
