#!/usr/bin/env php
<?php
/**
 * Полный запуск WeeklyPlanAdaptationService для одного пользователя
 * (применяет изменения, шлёт уведомление, игнорирует cooldown).
 *
 * Использование:
 *   php scripts/run_weekly_adaptation_for_user.php <user_id>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WeeklyPlanAdaptationService.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_weekly_adaptation_for_user.php <user_id>\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$service = new WeeklyPlanAdaptationService($db);
$result = $service->processUser($userId, null, true);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
