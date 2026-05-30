<?php
/**
 * Настройка кнопки меню бота на Telegram Mini App.
 * Запуск: php set-miniapp-menu-button.php
 *
 * Ставит кнопку слева от поля ввода (setChatMenuButton, type=web_app),
 * открывающую PlanRun внутри Telegram.
 *
 * Требует: curl, доступ к api.telegram.org (через TELEGRAM_PROXY если в РФ).
 * Токен бота: TELEGRAM_BOT_TOKEN в .env или planrun-bot/bot/config.php.
 */

require_once __DIR__ . '/../config/env_loader.php';

$miniAppUrl = trim((string) env('TELEGRAM_MINIAPP_URL', 'https://planrun.ru/'));
$buttonText = trim((string) env('TELEGRAM_MINIAPP_BUTTON_TEXT', 'Открыть PlanRun'));

$token = trim((string) env('TELEGRAM_BOT_TOKEN', ''));
if ($token === '') {
    $configPath = trim((string) env('TELEGRAM_BOT_CONFIG_PATH', '')) ?: (dirname(__DIR__, 3) . '/planrun-bot/bot/config.php');
    if (is_file($configPath)) {
        require_once $configPath;
        if (defined('TELEGRAM_BOT_TOKEN')) {
            $token = trim((string) constant('TELEGRAM_BOT_TOKEN'));
        }
    }
}

if ($token === '') {
    fwrite(STDERR, "❌ Токен бота не найден (TELEGRAM_BOT_TOKEN в .env или planrun-bot/bot/config.php)\n");
    exit(1);
}

if (!preg_match('#^https://#i', $miniAppUrl)) {
    fwrite(STDERR, "❌ TELEGRAM_MINIAPP_URL должен начинаться с https:// (получено: {$miniAppUrl})\n");
    exit(1);
}

$proxy = trim((string) env('TELEGRAM_PROXY', ''));

$payload = json_encode([
    'menu_button' => [
        'type' => 'web_app',
        'text' => $buttonText,
        'web_app' => ['url' => $miniAppUrl],
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.telegram.org/bot' . $token . '/setChatMenuButton');
$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
];
if ($proxy !== '') {
    $opts[CURLOPT_PROXY] = $proxy;
    $opts[CURLOPT_HTTPPROXYTUNNEL] = true;
    if (strpos($proxy, 'socks5') === 0) {
        $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
    }
}
curl_setopt_array($ch, $opts);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$result = json_decode((string) $response, true);
if ($result && ($result['ok'] ?? false)) {
    echo "✅ Кнопка меню установлена: «{$buttonText}» → {$miniAppUrl}\n";
    exit(0);
}

fwrite(STDERR, "❌ Не удалось установить кнопку: " . ($result['description'] ?? ($curlError ?: "HTTP {$httpCode}")) . "\n");
exit(1);
