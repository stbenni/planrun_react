#!/usr/bin/env php
<?php
/**
 * Регистрация Polar AccessLink webhook (один на приложение).
 * Запуск: php scripts/polar_register_webhook.php
 *
 * Требуется: POLAR_CLIENT_ID, POLAR_CLIENT_SECRET, POLAR_WEBHOOK_CALLBACK_URL
 * После успеха секрет подписи сохраняется в planrun-backend/storage/polar_webhook_secret.txt
 * (и/или задайте POLAR_WEBHOOK_SIGNATURE_SECRET в .env).
 *
 * Важно: URL должен быть доступен из интернета; при создании Polar шлёт PING на этот адрес.
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/providers/PolarProvider.php';

$missing = [];
if (!trim((string)env('POLAR_CLIENT_ID', ''))) {
    $missing[] = 'POLAR_CLIENT_ID';
}
if (!trim((string)env('POLAR_CLIENT_SECRET', ''))) {
    $missing[] = 'POLAR_CLIENT_SECRET';
}
if (!trim((string)env('POLAR_WEBHOOK_CALLBACK_URL', ''))) {
    $missing[] = 'POLAR_WEBHOOK_CALLBACK_URL';
}
if ($missing !== []) {
    fwrite(STDERR, "В planrun-backend/.env не задано: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Файл .env должен лежать рядом с config/ (путь: " . dirname(__DIR__) . "/.env)\n");
    fwrite(STDERR, "Пример: POLAR_WEBHOOK_CALLBACK_URL=https://planrun.ru/api/polar_webhook.php\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$provider = new PolarProvider($db);
$result = $provider->ensureWebhookSubscription();

if (empty($result['ok'])) {
    fwrite(STDERR, 'Error: ' . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

echo ($result['changed'] ?? false)
    ? "OK: Polar webhook created/updated (id=" . ($result['webhook_id'] ?? '?') . ")\n"
    : "OK: Polar webhook already correct (id=" . ($result['webhook_id'] ?? '?') . ")\n";
echo 'Callback: ' . env('POLAR_WEBHOOK_CALLBACK_URL', '') . "\n";
if (!empty($result['signature_saved'])) {
    echo "Signature saved to planrun-backend/storage/polar_webhook_secret.txt\n";
} elseif (!empty($result['changed'])) {
    echo "Warning: signature_secret_key not saved — check directory permissions on planrun-backend/storage/\n";
}
exit(0);
