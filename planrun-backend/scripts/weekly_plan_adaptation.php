#!/usr/bin/env php
<?php
/**
 * Cron-обёртка для WeeklyPlanAdaptationService.
 *
 * Запуск (каждую минуту, скрипт сам фильтрует пользователей по локальной таймзоне):
 *   * * * * php /var/www/planrun/planrun-backend/scripts/weekly_plan_adaptation.php >> /var/log/planrun-weekly-adaptation.log 2>&1
 *
 * Целевой момент срабатывания — воскресенье 21:00 локально.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WeeklyPlanAdaptationService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$start = microtime(true);

try {
    $service = new WeeklyPlanAdaptationService($db);
    $stats = $service->processAllUsers();

    if ($stats['processed'] > 0 || $stats['adapted'] > 0 || $stats['errors'] > 0) {
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        echo "WeeklyAdaptation: processed={$stats['processed']} adapted={$stats['adapted']} skipped={$stats['skipped']} errors={$stats['errors']} elapsed_ms={$elapsedMs}\n";
        if (!empty($stats['details'])) {
            foreach ($stats['details'] as $d) {
                echo "  -> user {$d['userId']}: {$d['changes']} changes — {$d['summary']}\n";
            }
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "WeeklyAdaptation fatal: " . $e->getMessage() . "\n");
    exit(1);
}
