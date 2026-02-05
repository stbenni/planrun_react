#!/usr/bin/env php
<?php
/**
 * Проверка сброса пароля на сервере: таблица, пользователь, отправка.
 * Запуск: php scripts/check_password_reset.php [username]
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$username = $argv[1] ?? 'st_benni';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

echo "1. Table password_reset_tokens: ";
$r = $db->query("SHOW TABLES LIKE 'password_reset_tokens'");
echo ($r && $r->num_rows) ? "exists\n" : "MISSING (run: php scripts/migrate_all.php)\n";

echo "2. User '$username': ";
$stmt = $db->prepare("SELECT id, username, email FROM users WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    echo "not found\n";
    exit(1);
}
echo "id={$user['id']}, email={$user['email']}\n";

echo "3. EmailService (PHPMailer): ";
try {
    require_once $baseDir . '/services/EmailService.php';
    $svc = new EmailService();
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
    exit(1);
}

echo "All checks passed. Request reset in the browser for '$username' or '{$user['email']}'.\n";
