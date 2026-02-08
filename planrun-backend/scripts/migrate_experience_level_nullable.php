<?php
/**
 * Делает поле experience_level необязательным (NULL) для режима «Самостоятельно».
 * Запуск: php scripts/migrate_experience_level_nullable.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

// Разрешаем NULL: для пользователей в режиме «самостоятельно» поле не заполняется
$sql = "ALTER TABLE users MODIFY COLUMN experience_level VARCHAR(50) NULL DEFAULT NULL";

if ($db->query($sql)) {
    echo "Поле users.experience_level теперь допускает NULL.\n";
} else {
    fwrite(STDERR, "Ошибка: " . $db->error . "\n");
    exit(1);
}
