#!/usr/bin/env php
<?php
/**
 * Cron: weekly goal progress snapshots for all active users.
 * Recommended schedule: Sunday evening (after last workouts of the week).
 *
 * Usage: php goal_progress_snapshot.php [--date=YYYY-MM-DD]
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/GoalProgressService.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$date = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $date = substr($arg, 7);
    }
}

$service = new GoalProgressService($db);
$count = $service->processAllUsers($date);

echo "Goal progress snapshots: {$count} users processed" . ($date ? " for {$date}" : "") . ".\n";
