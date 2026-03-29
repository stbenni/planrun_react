#!/usr/bin/env php
<?php
/**
 * Миграция: создаёт таблицу goal_progress_snapshots для еженедельных снимков прогресса.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$sql = "CREATE TABLE IF NOT EXISTS goal_progress_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    snapshot_date DATE NOT NULL,
    vdot DECIMAL(4,1) DEFAULT NULL,
    vdot_source VARCHAR(30) DEFAULT NULL,
    weekly_km DECIMAL(6,1) DEFAULT NULL,
    weekly_sessions INT DEFAULT NULL,
    compliance_pct INT DEFAULT NULL,
    longest_run_km DECIMAL(5,1) DEFAULT NULL,
    acwr DECIMAL(3,2) DEFAULT NULL,
    acwr_zone VARCHAR(10) DEFAULT NULL,
    goal_type VARCHAR(30) DEFAULT NULL,
    race_date DATE DEFAULT NULL,
    race_target_time_sec INT DEFAULT NULL,
    predicted_time_sec INT DEFAULT NULL,
    weeks_to_goal INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_date (user_id, snapshot_date),
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "Table goal_progress_snapshots created/verified.\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}
