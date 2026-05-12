#!/usr/bin/env php
<?php
/**
 * Добавить колонку history_summary в chat_user_memory.
 * Суммаризация старой истории чата хранится здесь и подставляется в контекст вместо полной истории.
 * Запуск: php scripts/migrate_chat_history_summary.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

// MySQL < 8.0 не поддерживает IF NOT EXISTS для ADD COLUMN — проверяем вручную
$res = $db->query("SHOW COLUMNS FROM chat_user_memory LIKE 'history_summary'");
if ($res && $res->num_rows > 0) {
    echo "OK: Column history_summary already exists.\n";
    exit(0);
}

$sql = "ALTER TABLE chat_user_memory ADD COLUMN history_summary TEXT NULL AFTER content";
if (!$db->query($sql)) {
    fwrite(STDERR, "Error adding history_summary: " . $db->error . "\n");
    exit(1);
}

echo "OK: Column history_summary added to chat_user_memory.\n";
exit(0);
