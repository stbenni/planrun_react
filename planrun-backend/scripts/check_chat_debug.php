#!/usr/bin/env php
<?php
/**
 * Диагностика чата: проверка сообщений в БД
 * Запуск: php scripts/check_chat_debug.php [user_id]
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$userId = isset($argv[1]) ? (int)$argv[1] : 1;

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

echo "=== Chat debug for user_id=$userId ===\n\n";

$conv = $db->query("SELECT id, user_id, type, created_at FROM chat_conversations WHERE user_id = $userId ORDER BY type");
if (!$conv) {
    echo "Error: " . $db->error . "\n";
    exit(1);
}

while ($row = $conv->fetch_assoc()) {
    echo "Conversation: id={$row['id']} user_id={$row['user_id']} type={$row['type']}\n";
    $cid = $row['id'];
    $msgs = $db->query("SELECT id, sender_type, LEFT(content, 50) as content_preview, created_at FROM chat_messages WHERE conversation_id = $cid ORDER BY created_at DESC LIMIT 10");
    if ($msgs) {
        while ($m = $msgs->fetch_assoc()) {
            echo "  - msg id={$m['id']} sender={$m['sender_type']} content=\"{$m['content_preview']}...\" created={$m['created_at']}\n";
        }
    }
    echo "\n";
}

echo "Done.\n";
