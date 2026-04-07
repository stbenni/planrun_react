#!/usr/bin/env php
<?php
/**
 * Migration: Create user_health_events table for injury/health memory.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$sql = "CREATE TABLE IF NOT EXISTS user_health_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    issue_type VARCHAR(50) NOT NULL DEFAULT 'other',
    description TEXT,
    severity VARCHAR(20) NOT NULL DEFAULT 'mild',
    affected_area VARCHAR(100) DEFAULT NULL,
    days_off INT DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_active (user_id, resolved_at),
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "Table user_health_events created (or already exists)\n";
} else {
    fwrite(STDERR, "Error creating table: " . $db->error . "\n");
    exit(1);
}

echo "Migration complete.\n";
