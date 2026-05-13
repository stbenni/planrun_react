#!/usr/bin/env php
<?php
/**
 * Миграция: добавить birth_month TINYINT NULL в users (1-12, опционально к birth_year).
 * Запуск: php scripts/migrate_users_birth_month.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$tables = $db->query("SHOW TABLES LIKE 'users'");
if (!$tables || $tables->num_rows === 0) {
    fwrite(STDERR, "Table users does not exist.\n");
    exit(1);
}

$columns = $db->query("SHOW COLUMNS FROM users LIKE 'birth_month'");
if ($columns && $columns->num_rows === 0) {
    if ($db->query("ALTER TABLE users ADD COLUMN birth_month TINYINT NULL DEFAULT NULL AFTER birth_year")) {
        echo "OK: added column birth_month\n";
    } else {
        fwrite(STDERR, "Error: " . $db->error . "\n");
        exit(1);
    }
} else {
    echo "Column birth_month already exists.\n";
}
