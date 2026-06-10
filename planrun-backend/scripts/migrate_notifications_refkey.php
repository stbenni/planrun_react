#!/usr/bin/env php
<?php
/**
 * Миграция: единый store уведомлений — свёртка по диалогу/сущности.
 *  - plan_notifications.ref_key (ключ свёртки, напр. "chat:123")
 *  - UNIQUE (user_id, ref_key) для UPSERT (множественные NULL допустимы)
 * Запуск: php scripts/migrate_notifications_refkey.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

// 1) колонка ref_key
$col = $db->query("SHOW COLUMNS FROM plan_notifications LIKE 'ref_key'");
if ($col && $col->num_rows === 0) {
    if ($db->query("ALTER TABLE plan_notifications ADD COLUMN ref_key VARCHAR(100) NULL DEFAULT NULL")) {
        echo "OK: plan_notifications.ref_key added\n";
    } else { fwrite(STDERR, "Error: " . $db->error . "\n"); exit(1); }
} else {
    echo "plan_notifications.ref_key already exists\n";
}

// 2) уникальный индекс (user_id, ref_key) — для INSERT ... ON DUPLICATE KEY UPDATE
$idx = $db->query("SHOW INDEX FROM plan_notifications WHERE Key_name = 'uniq_user_ref'");
if ($idx && $idx->num_rows === 0) {
    if ($db->query("ALTER TABLE plan_notifications ADD UNIQUE KEY uniq_user_ref (user_id, ref_key)")) {
        echo "OK: uniq_user_ref index added\n";
    } else { fwrite(STDERR, "Error: " . $db->error . "\n"); exit(1); }
} else {
    echo "uniq_user_ref index already exists\n";
}

echo "Done.\n";
