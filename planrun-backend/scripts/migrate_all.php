#!/usr/bin/env php
<?php
/**
 * Выполнить все миграции (таблицы для сброса пароля и JWT).
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
    'password_reset_tokens' => "CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user_id (user_id),
        INDEX idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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
