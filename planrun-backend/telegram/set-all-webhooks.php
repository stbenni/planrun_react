<?php
/**
 * Установка webhook через прокси для всех ботов.
 * Запуск после развёртывания proxy на VPS: php set-all-webhooks.php
 *
 * Требует: curl, доступ к api.telegram.org (через прокси если в РФ)
 */
$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            if ($k === 'TELEGRAM_PROXY') {
                putenv("TELEGRAM_PROXY=" . trim(trim($v), " \t\"'"));
                break;
            }
        }
    }
}
$bots = [
    'planrun'   => '8550141676:AAHoKDDlbItZ6_r16VKrdFvTAYchnRYFoeQ',
    'hday'      => null, // из altervision/bots/hday/config.php
    'gpu-alert' => null, // из altervision/bots/gpu-alert/config.php
    'tsd'       => '7971364864:AAHF3MKMsHIv3ZfTCH1ScMdFXTVFFU8avVo',
];

$baseUrl = 'https://tg.planrun.ru:8443/webhook-proxy';

// Загрузить токены из altervision
$hdayConfig = @include __DIR__ . '/../../../altervision/bots/hday/config.php';
if (is_array($hdayConfig) && !empty($hdayConfig['telegram']['bot_token'])) {
    $bots['hday'] = $hdayConfig['telegram']['bot_token'];
}
$gpuConfig = @include __DIR__ . '/../../../altervision/bots/gpu-alert/config.php';
if (is_array($gpuConfig) && !empty($gpuConfig['telegram']['bot_token'])) {
    $bots['gpu-alert'] = $gpuConfig['telegram']['bot_token'];
}

$proxy = getenv('TELEGRAM_PROXY') ?: '';
$curlOpts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15];
if ($proxy !== '') {
    $curlOpts[CURLOPT_PROXY] = $proxy;
    $curlOpts[CURLOPT_HTTPPROXYTUNNEL] = true;
    if (strpos($proxy, 'socks5') === 0) {
        $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
    }
}

foreach ($bots as $name => $token) {
    if ($token === null) {
        echo "⏭ $name: токен не найден, пропуск\n";
        continue;
    }
    $url = $baseUrl . '/' . $name;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/setWebhook');
    curl_setopt_array($ch, $curlOpts + [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query(['url' => $url, 'drop_pending_updates' => true]),
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $r = json_decode($response, true);
    if ($r && ($r['ok'] ?? false)) {
        echo "✅ $name: webhook установлен → $url\n";
    } else {
        echo "❌ $name: " . ($r['description'] ?? "HTTP $code") . "\n";
    }
}

echo "\nГотово.\n";
