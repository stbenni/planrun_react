#!/usr/bin/env php
<?php
/**
 * Проверка/восстановление Polar webhook (cron, например раз в сутки).
 * Запуск: php scripts/polar_webhook_health.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/config/Logger.php';
require_once $baseDir . '/providers/PolarProvider.php';

if (!env('POLAR_WEBHOOK_CALLBACK_URL', '')) {
    exit(0);
}

$db = getDBConnection();
if (!$db) {
    Logger::error('polar_webhook_health: no db');
    exit(1);
}

$provider = new PolarProvider($db);
$result = $provider->ensureWebhookSubscription();

if (empty($result['ok'])) {
    Logger::warning('polar_webhook_health failed', ['error' => $result['error'] ?? 'unknown']);
    exit(1);
}

Logger::info('polar_webhook_health ok', [
    'changed' => !empty($result['changed']),
    'webhook_id' => $result['webhook_id'] ?? null,
]);
exit(0);
