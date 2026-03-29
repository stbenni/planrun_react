#!/usr/bin/env php
<?php
/**
 * Выполнить все базовые auth/notification миграции.
 * Запуск на сервере один раз: php scripts/migrate_all.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$migrations = [
    'email_verification_codes' => trim((string) file_get_contents($baseDir . '/migrations/create_email_verification_codes.sql')),
    'plan_generation_jobs' => trim((string) file_get_contents($baseDir . '/migrations/create_plan_generation_jobs.sql')),
    'workout_share_jobs' => "CREATE TABLE IF NOT EXISTS workout_share_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        workout_kind VARCHAR(16) NOT NULL DEFAULT 'workout',
        workout_id INT UNSIGNED NOT NULL,
        template VARCHAR(24) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 2,
        payload_json MEDIUMTEXT NULL,
        result_json MEDIUMTEXT NULL,
        last_error VARCHAR(255) NULL DEFAULT NULL,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL DEFAULT NULL,
        finished_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_workout_share_jobs_due (status, available_at),
        INDEX idx_workout_share_jobs_lookup (user_id, workout_kind, workout_id, template),
        INDEX idx_workout_share_jobs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'workout_share_cards' => "CREATE TABLE IF NOT EXISTS workout_share_cards (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        workout_kind VARCHAR(16) NOT NULL DEFAULT 'workout',
        workout_id INT UNSIGNED NOT NULL,
        template VARCHAR(24) NOT NULL,
        mime_type VARCHAR(64) NOT NULL DEFAULT 'image/png',
        file_path VARCHAR(512) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        map_provider VARCHAR(24) NULL DEFAULT NULL,
        renderer_version VARCHAR(32) NOT NULL DEFAULT 'v3-playwright',
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_workout_share_card (user_id, workout_kind, workout_id, template),
        INDEX idx_workout_share_cards_lookup (user_id, workout_kind, workout_id),
        INDEX idx_workout_share_cards_generated (generated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'password_reset_tokens' => trim((string) file_get_contents($baseDir . '/migrations/create_password_reset_tokens.sql')),
    'refresh_tokens' => "CREATE TABLE IF NOT EXISTS refresh_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_token_hash (token_hash),
        INDEX idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_dismissals' => "CREATE TABLE IF NOT EXISTS notification_dismissals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        notification_id VARCHAR(128) NOT NULL,
        dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_notification (user_id, notification_id),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'push_tokens' => "CREATE TABLE IF NOT EXISTS push_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        fcm_token VARCHAR(512) NOT NULL,
        platform VARCHAR(16) NOT NULL DEFAULT 'android',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_device (user_id, device_id),
        INDEX idx_user_id (user_id),
        INDEX idx_fcm_token (fcm_token(128))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_channel_settings' => "CREATE TABLE IF NOT EXISTS notification_channel_settings (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        mobile_push_enabled TINYINT(1) NOT NULL DEFAULT 1,
        web_push_enabled TINYINT(1) NOT NULL DEFAULT 1,
        telegram_enabled TINYINT(1) NOT NULL DEFAULT 1,
        email_enabled TINYINT(1) NOT NULL DEFAULT 1,
        quiet_hours_enabled TINYINT(1) NOT NULL DEFAULT 0,
        quiet_hours_start TIME NOT NULL DEFAULT '22:00:00',
        quiet_hours_end TIME NOT NULL DEFAULT '07:00:00',
        workout_today_hour TINYINT NOT NULL DEFAULT 8,
        workout_today_minute TINYINT NOT NULL DEFAULT 0,
        workout_tomorrow_hour TINYINT NOT NULL DEFAULT 20,
        workout_tomorrow_minute TINYINT NOT NULL DEFAULT 0,
        email_digest_mode VARCHAR(16) NOT NULL DEFAULT 'instant',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_notification_channel_settings_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_preferences' => "CREATE TABLE IF NOT EXISTS notification_preferences (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        event_key VARCHAR(96) NOT NULL,
        mobile_push_enabled TINYINT(1) NOT NULL DEFAULT 0,
        web_push_enabled TINYINT(1) NOT NULL DEFAULT 0,
        telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
        email_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_notification_pref_user_event (user_id, event_key),
        INDEX idx_notification_pref_user (user_id),
        INDEX idx_notification_pref_event (event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'web_push_subscriptions' => "CREATE TABLE IF NOT EXISTS web_push_subscriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        endpoint VARCHAR(512) NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        user_agent VARCHAR(255) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_web_push_endpoint (endpoint(191)),
        INDEX idx_web_push_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_deliveries' => "CREATE TABLE IF NOT EXISTS notification_deliveries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        event_key VARCHAR(96) NOT NULL,
        channel VARCHAR(24) NOT NULL,
        status VARCHAR(32) NOT NULL,
        title VARCHAR(255) NULL DEFAULT NULL,
        body TEXT NULL,
        entity_type VARCHAR(64) NULL DEFAULT NULL,
        entity_id VARCHAR(64) NULL DEFAULT NULL,
        error_text VARCHAR(255) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notification_deliveries_user (user_id),
        INDEX idx_notification_deliveries_event (event_key),
        INDEX idx_notification_deliveries_channel (channel),
        INDEX idx_notification_deliveries_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_dispatch_guards' => "CREATE TABLE IF NOT EXISTS notification_dispatch_guards (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        event_key VARCHAR(96) NOT NULL,
        entity_type VARCHAR(64) NOT NULL,
        entity_id VARCHAR(128) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'processing',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        sent_at DATETIME NULL DEFAULT NULL,
        UNIQUE KEY uk_notification_dispatch_guard (user_id, event_key, entity_type, entity_id),
        INDEX idx_notification_dispatch_guard_status (status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_delivery_queue' => "CREATE TABLE IF NOT EXISTS notification_delivery_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        event_key VARCHAR(96) NOT NULL,
        channel VARCHAR(24) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        link VARCHAR(512) NULL DEFAULT NULL,
        push_data_json MEDIUMTEXT NULL,
        email_action_label VARCHAR(128) NULL DEFAULT NULL,
        entity_type VARCHAR(64) NULL DEFAULT NULL,
        entity_id VARCHAR(128) NULL DEFAULT NULL,
        deliver_after DATETIME NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(255) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_notification_delivery_queue_due (status, deliver_after),
        INDEX idx_notification_delivery_queue_user (user_id),
        INDEX idx_notification_delivery_queue_event (event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_email_digest_items' => "CREATE TABLE IF NOT EXISTS notification_email_digest_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        event_key VARCHAR(96) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        link VARCHAR(512) NULL DEFAULT NULL,
        entity_type VARCHAR(64) NULL DEFAULT NULL,
        entity_id VARCHAR(128) NULL DEFAULT NULL,
        digest_after DATETIME NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_notification_email_digest_due (status, digest_after),
        INDEX idx_notification_email_digest_user (user_id),
        INDEX idx_notification_email_digest_event (event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'notification_template_overrides' => "CREATE TABLE IF NOT EXISTS notification_template_overrides (
        event_key VARCHAR(96) NOT NULL PRIMARY KEY,
        title_template VARCHAR(255) NULL DEFAULT NULL,
        body_template TEXT NULL,
        link_template VARCHAR(512) NULL DEFAULT NULL,
        email_action_label_template VARCHAR(128) NULL DEFAULT NULL,
        updated_by INT UNSIGNED NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_notification_template_overrides_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($migrations as $name => $sql) {
    if ($db->query($sql)) {
        echo "OK: $name\n";
    } else {
        fwrite(STDERR, "Error $name: " . $db->error . "\n");
        exit(1);
    }
}

// refresh_tokens: добавить device_id если отсутствует
$colCheck = $db->query("SHOW COLUMNS FROM refresh_tokens LIKE 'device_id'");
if ($colCheck && $colCheck->num_rows === 0) {
    if ($db->query("ALTER TABLE refresh_tokens ADD COLUMN device_id VARCHAR(64) NULL DEFAULT NULL AFTER token_hash")) {
        echo "OK: refresh_tokens device_id\n";
    }
}
$idxCheck = $db->query("SHOW INDEX FROM refresh_tokens WHERE Key_name = 'idx_user_device'");
if (!$idxCheck || $idxCheck->num_rows === 0) {
    if ($db->query("ALTER TABLE refresh_tokens ADD INDEX idx_user_device (user_id, device_id)")) {
        echo "OK: refresh_tokens idx_user_device\n";
    }
}

// users: push_workout_minute (минуты напоминания)
$checkMin = $db->query("SHOW COLUMNS FROM users LIKE 'push_workout_minute'");
if ($checkMin && $checkMin->num_rows === 0) {
    if ($db->query("ALTER TABLE users ADD COLUMN push_workout_minute TINYINT NOT NULL DEFAULT 0 COMMENT 'Минуты напоминания (0-59)' AFTER push_workout_hour")) {
        echo "OK: users push_workout_minute\n";
    }
}

// users: настройки push-уведомлений
foreach (['push_workouts_enabled', 'push_chat_enabled', 'push_workout_hour'] as $col) {
    $check = $db->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        $sql = $col === 'push_workout_hour'
            ? "ALTER TABLE users ADD COLUMN push_workout_hour TINYINT NOT NULL DEFAULT 20 COMMENT 'Час напоминания (0-23)'"
            : "ALTER TABLE users ADD COLUMN $col TINYINT(1) NOT NULL DEFAULT 1";
        if ($db->query($sql)) {
            echo "OK: users $col\n";
        }
    }
}

echo "All migrations done.\n";
