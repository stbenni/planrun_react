<?php
/**
 * Migration: executed_exercises — фактическое выполнение упражнений.
 * Используется для:
 *  - Progressive overload (бо́льшие веса со временем)
 *  - AI знание реальных рабочих весов атлета
 *  - UI «отметить выполнение» на ОФП/СБУ днях
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB failed\n"); exit(1); }

$sql = "CREATE TABLE IF NOT EXISTS executed_exercises (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    plan_day_id INT UNSIGNED NULL,
    exercise_id INT UNSIGNED NULL,
    exercise_name VARCHAR(255) NOT NULL,
    category ENUM('run', 'ofp', 'sbu') NOT NULL,

    planned_sets SMALLINT NULL DEFAULT NULL,
    planned_reps SMALLINT NULL DEFAULT NULL,
    planned_weight_kg DECIMAL(6,2) NULL DEFAULT NULL,
    planned_duration_sec INT NULL DEFAULT NULL,
    planned_distance_m INT NULL DEFAULT NULL,

    executed_sets SMALLINT NULL DEFAULT NULL,
    executed_reps SMALLINT NULL DEFAULT NULL,
    executed_weight_kg DECIMAL(6,2) NULL DEFAULT NULL,
    executed_duration_sec INT NULL DEFAULT NULL,
    executed_distance_m INT NULL DEFAULT NULL,

    rpe TINYINT NULL DEFAULT NULL,
    notes TEXT NULL DEFAULT NULL,

    executed_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_date (user_id, executed_date),
    INDEX idx_user_exercise_id (user_id, exercise_id, executed_date),
    INDEX idx_user_exercise_name (user_id, exercise_name, executed_date),
    INDEX idx_plan_day (plan_day_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "OK: executed_exercises table created/exists\n";
} else {
    fwrite(STDERR, "Failed: " . $db->error . "\n");
    exit(1);
}
