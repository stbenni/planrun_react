#!/usr/bin/env php
<?php
/**
 * Миграция: сделать session_id в workouts nullable для импорта (Strava, Huawei)
 * Запуск: php scripts/migrate_workouts_session_id_nullable.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$columns = $db->query("SHOW COLUMNS FROM workouts LIKE 'session_id'");
if (!$columns || $columns->num_rows === 0) {
    echo "Column session_id does not exist. Skipping.\n";
    exit(0);
}

// session_id хранит строки (tg_..., и т.д.), не integer — сохраняем VARCHAR
if ($db->query("ALTER TABLE workouts MODIFY COLUMN session_id VARCHAR(255) NULL DEFAULT NULL")) {
    echo "OK: session_id is now nullable\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}

echo "Migration done.\n";
