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

$clientId = env('STRAVA_CLIENT_ID', '');
$clientSecret = env('STRAVA_CLIENT_SECRET', '');
$callbackUrl = env('STRAVA_WEBHOOK_CALLBACK_URL', '');
$verifyToken = env('STRAVA_WEBHOOK_VERIFY_TOKEN', 'planrun_verify');

if (!$clientId || !$clientSecret || !$callbackUrl) {
    fwrite(STDERR, "Set STRAVA_CLIENT_ID, STRAVA_CLIENT_SECRET, STRAVA_WEBHOOK_CALLBACK_URL in .env\n");
    exit(1);
}

$proxy = env('STRAVA_PROXY', '') ?: null;
$opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15];
if ($proxy) {
    $opts[CURLOPT_PROXY] = $proxy;
    $opts[CURLOPT_HTTPPROXYTUNNEL] = true;
    if (strpos($proxy, 'socks5') === 0) {
        $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
    }
}

$getUrl = 'https://www.strava.com/api/v3/push_subscriptions?' . http_build_query(['client_id' => $clientId, 'client_secret' => $clientSecret]);
$ch = curl_init($getUrl);
curl_setopt_array($ch, $opts);
$listResp = curl_exec($ch);
$listCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$listData = json_decode($listResp, true);
if ($listCode === 200 && !empty($listData) && isset($listData[0]['id'])) {
    $subId = $listData[0]['id'];
    echo "Existing subscription id=$subId, deleting...\n";
    $ch = curl_init("https://www.strava.com/api/v3/push_subscriptions/$subId?" . http_build_query(['client_id' => $clientId, 'client_secret' => $clientSecret]));
    curl_setopt_array($ch, $opts + [CURLOPT_CUSTOMREQUEST => 'DELETE']);
    curl_exec($ch);
    curl_close($ch);
}

$ch = curl_init('https://www.strava.com/api/v3/push_subscriptions');
curl_setopt_array($ch, $opts + [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'callback_url' => $callbackUrl,
        'verify_token' => $verifyToken,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);
if (($httpCode === 200 || $httpCode === 201) && isset($data['id'])) {
    echo "OK: Webhook subscription created (id={$data['id']})\n";
    echo "Callback: $callbackUrl\n";
} else {
    $msg = $data['message'] ?? ($data['errors'][0]['message'] ?? null) ?? $response;
    fwrite(STDERR, "Error ($httpCode): $msg\n");
    if (!empty($data['errors'])) {
        fwrite(STDERR, "Details: " . json_encode($data['errors'], JSON_UNESCAPED_UNICODE) . "\n");
    }
    exit(1);
}
