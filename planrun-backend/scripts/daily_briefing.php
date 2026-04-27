#!/usr/bin/env php
<?php
/**
 * Morning briefing from the AI coach.
 *
 * Cron: once a day in the user's morning window.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/ProactiveCoachService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

if ((int) env('PROACTIVE_COACH_ENABLED', 0) !== 1) {
    echo "ProactiveCoach disabled\n";
    exit(0);
}

echo "DailyBriefing: starting...\n";
$start = microtime(true);

try {
    $stats = (new ProactiveCoachService($db))->processDailyBriefings();
    $elapsed = round((microtime(true) - $start) * 1000);

    echo "DailyBriefing: done in {$elapsed}ms\n";
    echo "  Sent: {$stats['sent']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "DailyBriefing fatal: " . $e->getMessage() . "\n");
    exit(1);
}
