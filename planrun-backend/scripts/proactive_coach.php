#!/usr/bin/env php
<?php
/**
 * Проактивный AI-тренер — крон-задача.
 * Обнаруживает события (пауза, перегрузка, забег, рекорд, низкое выполнение)
 * и отправляет персонализированные сообщения в чат.
 *
 * Запуск: каждые 6 часов (или 2 раза в день)
 * Cron: 0 8,20 * * * php /path/to/planrun-backend/scripts/proactive_coach.php
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
    echo "ProactiveCoach disabled (PROACTIVE_COACH_ENABLED != 1)\n";
    exit(0);
}

echo "ProactiveCoach: starting...\n";
$start = microtime(true);

try {
    $service = new ProactiveCoachService($db);
    $stats = $service->processAllUsers();
    $elapsed = round((microtime(true) - $start) * 1000);

    echo "ProactiveCoach: done in {$elapsed}ms\n";
    echo "  Sent: {$stats['sent']}\n";
    echo "  Skipped: {$stats['skipped']}\n";
    echo "  Errors: {$stats['errors']}\n";

    if (!empty($stats['details'])) {
        foreach ($stats['details'] as $d) {
            echo "  -> user {$d['userId']}: {$d['event']}\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ProactiveCoach fatal: " . $e->getMessage() . "\n");
    exit(1);
}
