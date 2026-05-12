#!/usr/bin/env php
<?php
/**
 * Создать таблицу password_reset_tokens в БД (те же учётные данные, что у приложения).
 * Запуск один раз на сервере: php scripts/migrate_password_reset_table.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$sqlPath = $baseDir . '/migrations/create_password_reset_tokens.sql';
$sql = is_file($sqlPath) ? trim((string) file_get_contents($sqlPath)) : '';

if ($sql === '') {
    fwrite(STDERR, "Migration SQL not found: $sqlPath\n");
    exit(1);
}

if ($db->query($sql)) {
    echo "OK: Table password_reset_tokens exists or was created.\n";
    exit(0);
}

fwrite(STDERR, "Error: " . $db->error . "\n");
exit(1);
