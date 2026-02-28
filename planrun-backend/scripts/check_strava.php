#!/usr/bin/env php
<?php
/**
 * Диагностика Strava: проверка env, прокси, OAuth URL, токенов.
 * Запуск: php scripts/check_strava.php [user_id]
 */
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/env_loader.php';
require_once $projectRoot . '/db_config.php';

$userId = isset($argv[1]) ? (int)$argv[1] : null;

echo "=== Strava Диагностика ===\n\n";

// 1. Env
$clientId = env('STRAVA_CLIENT_ID', '');
$clientSecret = env('STRAVA_CLIENT_SECRET', '');
$redirectUri = env('STRAVA_REDIRECT_URI', '');
$proxy = env('STRAVA_PROXY', '') ?: null;

echo "1. ENV переменные:\n";
echo "   STRAVA_CLIENT_ID: " . ($clientId ? substr($clientId, 0, 4) . '...' : '(пусто)') . "\n";
echo "   STRAVA_CLIENT_SECRET: " . ($clientSecret ? '***' : '(пусто)') . "\n";
echo "   STRAVA_REDIRECT_URI: " . ($redirectUri ?: '(пусто)') . "\n";
echo "   STRAVA_PROXY: " . ($proxy ?: '(нет)') . "\n\n";

if (!$clientId || !$redirectUri) {
    echo "ОШИБКА: STRAVA_CLIENT_ID и STRAVA_REDIRECT_URI обязательны в .env\n";
    exit(1);
}

// 2. Проверка доступа к Strava API (через прокси если есть)
$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_NOBODY => true,
];
if ($proxy) {
    $opts[CURLOPT_PROXY] = $proxy;
    $opts[CURLOPT_HTTPPROXYTUNNEL] = true;
    if (strpos($proxy, 'socks5') === 0) {
        $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
    }
}

$ch = curl_init('https://www.strava.com/api/v3/athlete');
curl_setopt_array($ch, $opts + [CURLOPT_HTTPHEADER => ['Authorization: Bearer invalid']]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

echo "2. Доступ к Strava API:\n";
if ($curlErr) {
    echo "   ОШИБКА: $curlErr\n";
    echo "   Возможные причины: блокировка (нужен STRAVA_PROXY), нет интернета.\n";
} else {
    echo "   HTTP $httpCode (ожидается 401 Unauthorized — без токена это нормально)\n";
    if ($httpCode === 403) {
        echo "   ВНИМАНИЕ: 403 = блокировка Strava в регионе. Настройте STRAVA_PROXY.\n";
    } elseif ($httpCode === 0 || $httpCode >= 500) {
        echo "   ВНИМАНИЕ: До Strava не дошли. Проверьте прокси.\n";
    } else {
        echo "   OK: До Strava дошли.\n";
    }
}
echo "\n";

// 3. OAuth URL
$state = bin2hex(random_bytes(8));
$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'activity:read_all',
    'state' => $state,
];
$authUrl = 'https://www.strava.com/oauth/authorize?' . http_build_query($params);
echo "3. OAuth URL (для redirect_uri в Strava Dashboard):\n";
echo "   $redirectUri\n";
echo "   Должен ТОЧНО совпадать с настройками приложения в https://www.strava.com/settings/api\n\n";

// 4. Токены пользователя
if ($userId) {
    $db = getDBConnection();
    if (!$db) {
        echo "4. Ошибка подключения к БД\n";
        exit(1);
    }
    $stmt = $db->prepare("SELECT access_token, refresh_token, expires_at, external_athlete_id, updated_at FROM integration_tokens WHERE user_id = ? AND provider = 'strava'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo "4. Токены пользователя $userId:\n";
    if (!$row) {
        echo "   Нет записи — Strava не подключен.\n";
    } else {
        $expired = $row['expires_at'] && strtotime($row['expires_at']) < time();
        echo "   access_token: " . (strlen($row['access_token'] ?? '') ? 'есть' : 'нет') . "\n";
        echo "   refresh_token: " . (strlen($row['refresh_token'] ?? '') ? 'есть' : 'нет') . "\n";
        echo "   expires_at: " . ($row['expires_at'] ?? 'null') . ($expired ? ' (ИСТЁК)' : '') . "\n";
        echo "   external_athlete_id: " . ($row['external_athlete_id'] ?? 'null') . "\n";
        echo "   updated_at: " . ($row['updated_at'] ?? 'null') . "\n";
        if ($expired && empty($row['refresh_token'])) {
            echo "   ВНИМАНИЕ: Токен истёк и нет refresh_token.\n";
            echo "   Решение: отвязать Strava и подключить заново.\n";
        }
    }
} else {
    echo "4. Токены: укажите user_id для проверки.\n";
    echo "   Пример: php scripts/check_strava.php 1\n";
}

echo "\n=== Конец ===\n";
