#!/usr/bin/env php
<?php
/**
 * Создать таблицы чата: chat_conversations, chat_messages
 * Запуск: php scripts/migrate_chat_tables.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$sql1 = "CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('ai', 'admin') NOT NULL DEFAULT 'ai',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_type (user_id, type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql2 = "CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_type ENUM('user', 'ai', 'admin') NOT NULL,
    sender_id INT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    metadata JSON NULL,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    INDEX idx_conv_created (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$ok = true;
if (!$db->query($sql1)) {
    fwrite(STDERR, "Error creating chat_conversations: " . $db->error . "\n");
    $ok = false;
} else {
    echo "OK: Table chat_conversations exists or was created.\n";
}

if (!$db->query($sql2)) {
    fwrite(STDERR, "Error creating chat_messages: " . $db->error . "\n");
    $ok = false;
} else {
    echo "OK: Table chat_messages exists or was created.\n";
}

exit($ok ? 0 : 1);
