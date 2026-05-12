#!/usr/bin/env php
<?php
/**
 * Миграция: создать таблицу workout_laps для кругов/интервалов импортированных тренировок.
 * Запуск: php scripts/migrate_workout_laps.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$sql = "CREATE TABLE IF NOT EXISTS workout_laps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workout_id INT UNSIGNED NOT NULL,
    lap_index SMALLINT UNSIGNED NOT NULL,
    lap_name VARCHAR(255) NULL,
    start_time DATETIME NULL,
    elapsed_seconds INT UNSIGNED NULL,
    moving_seconds INT UNSIGNED NULL,
    distance_km DECIMAL(10,3) NULL,
    average_speed DECIMAL(8,3) NULL,
    max_speed DECIMAL(8,3) NULL,
    avg_heart_rate SMALLINT UNSIGNED NULL,
    max_heart_rate SMALLINT UNSIGNED NULL,
    elevation_gain DECIMAL(8,2) NULL,
    cadence SMALLINT UNSIGNED NULL,
    start_index INT UNSIGNED NULL,
    end_index INT UNSIGNED NULL,
    payload_json MEDIUMTEXT NULL,
    UNIQUE KEY uk_workout_lap (workout_id, lap_index),
    INDEX idx_workout_laps_workout (workout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "OK: workout_laps table created or already exists\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}

echo "Migration done.\n";
