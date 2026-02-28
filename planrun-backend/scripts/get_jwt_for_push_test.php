#!/usr/bin/env php
<?php
/**
 * Получить JWT для теста register_push_token.
 * Запуск: php scripts/get_jwt_for_push_test.php <username> <password>
 *
 * Выведет curl-команду с реальным токеном.
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/auth.php';
require_once $baseDir . '/services/AuthService.php';
require_once $baseDir . '/services/JwtService.php';

$username = trim($argv[1] ?? '');
$password = trim($argv[2] ?? '');

if ($username === '' || $password === '') {
    echo "Использование: php scripts/get_jwt_for_push_test.php <username> <password>\n";
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

$auth = new AuthService($db);
try {
    $result = $auth->login($username, $password, true, 'test-device-001');
} catch (Exception $e) {
    fwrite(STDERR, "Ошибка входа: " . $e->getMessage() . "\n");
    exit(1);
}

$token = $result['access_token'] ?? null;
if (!$token) {
    fwrite(STDERR, "JWT не получен\n");
    exit(1);
}

$host = env('APP_URL', 'https://s-vladimirov.ru');
$host = rtrim($host, '/');
$api = $host . '/api';

echo "JWT получен. Тест register_push_token:\n\n";
echo "curl -X POST \"{$api}/api_wrapper.php?action=register_push_token\" \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -H \"Authorization: Bearer {$token}\" \\\n";
echo "  -d '{\"fcm_token\":\"test_token_12345678901234567890123456789012345678901234567890\",\"device_id\":\"test-device-001\",\"platform\":\"android\"}'\n";
