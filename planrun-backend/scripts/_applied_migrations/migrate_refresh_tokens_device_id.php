#!/usr/bin/env php
<?php
/**
 * Добавить колонку device_id в таблицу refresh_tokens.
 * Запуск: php scripts/migrate_refresh_tokens_device_id.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$checks = $db->query("SHOW COLUMNS FROM refresh_tokens LIKE 'device_id'");
if ($checks && $checks->num_rows > 0) {
    echo "OK: Column device_id already exists.\n";
    exit(0);
}

$sql = "ALTER TABLE refresh_tokens ADD COLUMN device_id VARCHAR(64) NULL DEFAULT NULL AFTER token_hash";
if ($db->query($sql)) {
    echo "OK: Column device_id added.\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}

$idxCheck = $db->query("SHOW INDEX FROM refresh_tokens WHERE Key_name = 'idx_user_device'");
if (!$idxCheck || $idxCheck->num_rows === 0) {
    $idxSql = "ALTER TABLE refresh_tokens ADD INDEX idx_user_device (user_id, device_id)";
    if ($db->query($idxSql)) {
        echo "OK: Index idx_user_device added.\n";
    } else {
        fwrite(STDERR, "Warning: Index creation failed: " . $db->error . "\n");
    }
} else {
    echo "OK: Index idx_user_device already exists.\n";
}

exit(0);
