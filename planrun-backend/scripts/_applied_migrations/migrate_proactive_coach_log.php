#!/usr/bin/env php
<?php
/**
 * Миграция: создаёт таблицу proactive_coach_log для cooldown-логирования.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$sql = "CREATE TABLE IF NOT EXISTS proactive_coach_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_event (user_id, event_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "Table proactive_coach_log created/verified.\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}
