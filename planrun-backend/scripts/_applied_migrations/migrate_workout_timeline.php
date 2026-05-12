#!/usr/bin/env php
<?php
/**
 * Миграция: создать таблицу workout_timeline для графиков пульса/высоты
 * Запуск: php scripts/migrate_workout_timeline.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$sql = "CREATE TABLE IF NOT EXISTS workout_timeline (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workout_id INT UNSIGNED NOT NULL,
    timestamp DATETIME NOT NULL,
    heart_rate SMALLINT UNSIGNED NULL,
    pace VARCHAR(20) NULL,
    altitude DECIMAL(8,2) NULL,
    distance DECIMAL(10,3) NULL,
    cadence SMALLINT UNSIGNED NULL,
    INDEX idx_timeline_workout (workout_id),
    INDEX idx_timeline_workout_timestamp (workout_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "OK: workout_timeline table created or already exists\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}

echo "Migration done.\n";
