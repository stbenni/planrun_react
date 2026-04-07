#!/usr/bin/env php
<?php
/**
 * Еженедельный дайджест от AI-тренера.
 * Итоги недели с рекомендациями на следующую.
 *
 * Cron: 0 20 * * 0 php /path/to/planrun-backend/scripts/weekly_digest.php
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

echo "WeeklyDigest: starting...\n";
$start = microtime(true);

try {
    $service = new ProactiveCoachService($db);
    $stats = $service->processWeeklyDigests();
    $elapsed = round((microtime(true) - $start) * 1000);

    echo "WeeklyDigest: done in {$elapsed}ms\n";
    echo "  Sent: {$stats['sent']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "WeeklyDigest fatal: " . $e->getMessage() . "\n");
    exit(1);
}
