#!/usr/bin/env php
<?php
/**
 * Таблица кодов подтверждения email при регистрации.
 * Запуск: php scripts/migrate_email_verification_codes.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$sqlPath = $baseDir . '/migrations/create_email_verification_codes.sql';
$sql = is_file($sqlPath) ? trim((string) file_get_contents($sqlPath)) : '';

if ($sql === '') {
    fwrite(STDERR, "Migration SQL not found: $sqlPath\n");
    exit(1);
}

if ($db->query($sql)) {
    echo "OK: Table email_verification_codes exists or was created.\n";
    exit(0);
}

fwrite(STDERR, "Error: " . $db->error . "\n");
exit(1);
