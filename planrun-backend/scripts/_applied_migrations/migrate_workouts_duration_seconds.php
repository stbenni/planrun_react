#!/usr/bin/env php
<?php
/**
 * Миграция: добавить duration_seconds в workouts для точного вывода времени
 * Запуск: php scripts/migrate_workouts_duration_seconds.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$tables = $db->query("SHOW TABLES LIKE 'workouts'");
if (!$tables || $tables->num_rows === 0) {
    echo "Table workouts does not exist. Skipping.\n";
    exit(0);
}

$columns = $db->query("SHOW COLUMNS FROM workouts LIKE 'duration_seconds'");
if ($columns && $columns->num_rows === 0) {
    if ($db->query("ALTER TABLE workouts ADD COLUMN duration_seconds INT UNSIGNED NULL DEFAULT NULL AFTER duration_minutes")) {
        echo "OK: added column duration_seconds\n";
    } else {
        fwrite(STDERR, "Error adding duration_seconds: " . $db->error . "\n");
        exit(1);
    }
} else {
    echo "Column duration_seconds already exists.\n";
}

echo "Migration done.\n";
