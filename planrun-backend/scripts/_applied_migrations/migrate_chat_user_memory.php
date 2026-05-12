#!/usr/bin/env php
<?php
/**
 * Таблица «память пользователя» для AI-чата.
 * Хранит текст, который каждый раз подставляется в контекст этого пользователя —
 * модель получает его в каждом запросе и ведёт себя как «помнящая» пользователя.
 * Заполнять: вручную (админ), через API или будущей суммаризацией диалогов.
 * Запуск: php scripts/migrate_chat_user_memory.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$sql = "CREATE TABLE IF NOT EXISTS chat_user_memory (
    user_id INT PRIMARY KEY,
    content TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$db->query($sql)) {
    fwrite(STDERR, "Error creating chat_user_memory: " . $db->error . "\n");
    exit(1);
}

echo "OK: Table chat_user_memory exists or was created.\n";
exit(0);
