#!/usr/bin/env php
<?php
/**
 * Добавить push_workout_minute для точного времени напоминания (минуты).
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$check = $db->query("SHOW COLUMNS FROM users LIKE 'push_workout_minute'");
if ($check && $check->num_rows === 0) {
    if ($db->query("ALTER TABLE users ADD COLUMN push_workout_minute TINYINT NOT NULL DEFAULT 0 COMMENT 'Минуты напоминания (0-59)' AFTER push_workout_hour")) {
        echo "OK: users push_workout_minute\n";
    } else {
        fwrite(STDERR, "Error: " . $db->error . "\n");
        exit(1);
    }
} else {
    echo "push_workout_minute already exists\n";
}
