<?php
/**
 * Миграция: создание таблицы очереди повторов для Strava-вебхуков.
 * Запуск: php planrun-backend/scripts/migrate_strava_webhook_retry_queue.php
 */
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../db_config.php';

$db = getDBConnection();

$sql = "
CREATE TABLE IF NOT EXISTS strava_webhook_retry_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    strava_activity_id BIGINT UNSIGNED NOT NULL,
    aspect_type VARCHAR(16) NOT NULL DEFAULT 'create',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(512) NULL DEFAULT NULL,
    last_http_code SMALLINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finished_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uk_activity_user (strava_activity_id, user_id),
    INDEX idx_strava_retry_due (status, next_retry_at),
    INDEX idx_strava_retry_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

if ($db->query($sql)) {
    echo "OK: strava_webhook_retry_queue created/exists\n";
} else {
    echo "ERROR: " . $db->error . "\n";
    exit(1);
}
