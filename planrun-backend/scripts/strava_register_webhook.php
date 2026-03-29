#!/usr/bin/env php
<?php
/**
 * Регистрация Strava webhook подписки.
 * Запуск: php scripts/strava_register_webhook.php
 *
 * Требуется: STRAVA_CLIENT_ID, STRAVA_CLIENT_SECRET, STRAVA_WEBHOOK_CALLBACK_URL, STRAVA_WEBHOOK_VERIFY_TOKEN
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/providers/StravaProvider.php';

if (!env('STRAVA_CLIENT_ID', '') || !env('STRAVA_CLIENT_SECRET', '') || !env('STRAVA_WEBHOOK_CALLBACK_URL', '')) {
    fwrite(STDERR, "Set STRAVA_CLIENT_ID, STRAVA_CLIENT_SECRET, STRAVA_WEBHOOK_CALLBACK_URL in .env\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$provider = new StravaProvider($db);
$result = $provider->ensureWebhookSubscription();

if (!$result['ok']) {
    $message = $result['error'] ?: 'Unknown error';
    $httpCode = isset($result['http_code']) && $result['http_code'] !== null ? (int)$result['http_code'] : 0;
    fwrite(STDERR, "Error" . ($httpCode > 0 ? " ($httpCode)" : '') . ": $message\n");
    if (!empty($result['response'])) {
        fwrite(STDERR, "Response: " . $result['response'] . "\n");
    }
    exit(1);
}

if (!empty($result['deleted_ids'])) {
    echo "Deleted existing subscriptions: " . implode(', ', $result['deleted_ids']) . "\n";
}

echo $result['changed']
    ? "OK: Webhook subscription created (id={$result['subscription_id']})\n"
    : "OK: Webhook subscription already active (id={$result['subscription_id']})\n";
echo "Callback: " . ($result['callback_url'] ?? '(unknown)') . "\n";
