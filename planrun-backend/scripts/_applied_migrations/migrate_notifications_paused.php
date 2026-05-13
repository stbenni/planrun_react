#!/usr/bin/env php
<?php
/**
 * Миграция: добавить paused TINYINT(1) DEFAULT 0 в notification_channel_settings
 * (фича «Приостановить все уведомления»).
 * Запуск: php scripts/migrate_notifications_paused.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$tables = $db->query("SHOW TABLES LIKE 'notification_channel_settings'");
if (!$tables || $tables->num_rows === 0) {
    echo "Table notification_channel_settings does not exist. Will be created lazily by service.\n";
    exit(0);
}

$columns = $db->query("SHOW COLUMNS FROM notification_channel_settings LIKE 'paused'");
if ($columns && $columns->num_rows === 0) {
    if ($db->query("ALTER TABLE notification_channel_settings ADD COLUMN paused TINYINT(1) NOT NULL DEFAULT 0 AFTER email_digest_mode")) {
        echo "OK: added column paused\n";
    } else {
        fwrite(STDERR, "Error: " . $db->error . "\n");
        exit(1);
    }
} else {
    echo "Column paused already exists.\n";
}
