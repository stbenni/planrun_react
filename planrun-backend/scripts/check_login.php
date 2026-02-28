#!/usr/bin/env php
<?php
/**
 * Диагностика входа: проверка пользователя и пароля в БД.
 * Запуск: php scripts/check_login.php <username|email> [тестовый_пароль]
 * 
 * Если пароль не передан — только проверяем наличие пользователя и хеша.
 * Если передан — проверяем password_verify.
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$login = trim($argv[1] ?? '');
$testPassword = isset($argv[2]) ? $argv[2] : null;

if ($login === '') {
    echo "Использование: php scripts/check_login.php <username|email> [пароль]\n";
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

$stmt = $db->prepare('SELECT id, username, email, password FROM users WHERE username = ? OR (email IS NOT NULL AND email != "" AND email = ?)');
$stmt->bind_param('ss', $login, $login);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "Пользователь не найден (логин или email: '$login')\n";
    exit(1);
}

echo "Пользователь найден: id={$user['id']}, username={$user['username']}, email=" . ($user['email'] ?? '(пусто)') . "\n";

$hasPassword = !empty($user['password']) && strlen($user['password']) > 10;
echo "Пароль в БД: " . ($hasPassword ? "есть (хеш " . strlen($user['password']) . " символов)" : "ПУСТО или некорректный хеш") . "\n";

if ($testPassword !== null) {
    if (!$hasPassword) {
        echo "Проверка password_verify невозможна — хеш пустой.\n";
        echo "Решение: используйте «Забыли пароль?» для установки нового пароля.\n";
        exit(1);
    }
    $ok = password_verify($testPassword, $user['password']);
    echo "password_verify('$testPassword'): " . ($ok ? "OK — пароль верный" : "FAIL — пароль не совпадает") . "\n";
    if (!$ok) {
        echo "Попробуйте сбросить пароль через «Забыли пароль?» на странице входа.\n";
        exit(1);
    }
} elseif (!$hasPassword) {
    echo "Пароль не задан. Используйте «Забыли пароль?» для установки пароля.\n";
    exit(1);
}

echo "OK\n";
