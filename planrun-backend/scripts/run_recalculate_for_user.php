#!/usr/bin/env php
<?php
/**
 * Запуск реального пересчёта плана через очередь PlanGenerationQueueService.
 * Worker подберёт и выполнит DeepSeek-генерацию.
 *
 * Usage: php scripts/run_recalculate_for_user.php <user_id> "[reason]"
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PlanGenerationQueueService.php';

$userId = (int) ($argv[1] ?? 0);
$reason = (string) ($argv[2] ?? 'manual recalc');
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run_recalculate_for_user.php <user_id> \"[reason]\"\n");
    exit(1);
}

$db = getDBConnection();
$queue = new PlanGenerationQueueService($db);

$result = $queue->enqueue($userId, 'recalculate', [
    'reason' => $reason,
    'source' => 'manual_test',
]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
