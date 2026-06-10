#!/usr/bin/env php
<?php
/**
 * Миграция: PlanRun → Suunto зеркалирование тренировок.
 *  - users.suunto_mirror_enabled (тумблер per-user)
 *  - таблица suunto_upload_queue (очередь заливок + дедуп)
 * Запуск: php scripts/migrate_suunto_upload.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

// 1) колонка-тумблер
$col = $db->query("SHOW COLUMNS FROM users LIKE 'suunto_mirror_enabled'");
if ($col && $col->num_rows === 0) {
    if ($db->query("ALTER TABLE users ADD COLUMN suunto_mirror_enabled TINYINT(1) NOT NULL DEFAULT 0")) {
        echo "OK: users.suunto_mirror_enabled added\n";
    } else { fwrite(STDERR, "Error: " . $db->error . "\n"); exit(1); }
} else {
    echo "users.suunto_mirror_enabled already exists\n";
}

// 2) очередь заливок
$sql = "CREATE TABLE IF NOT EXISTS suunto_upload_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    workout_id INT NOT NULL,
    status ENUM('pending','processing','done','skipped','error') NOT NULL DEFAULT 'pending',
    suunto_workout_key VARCHAR(64) NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_workout (user_id, workout_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if ($db->query($sql)) {
    echo "OK: suunto_upload_queue ready\n";
} else { fwrite(STDERR, "Error: " . $db->error . "\n"); exit(1); }

echo "Migration done.\n";
