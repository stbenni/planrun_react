<?php
/**
 * Убирает ограничение "одна тренировка на дату": удаляет UNIQUE (user_id, date).
 * После миграции можно добавлять несколько тренировок на один день.
 * Запуск: php scripts/migrate_training_plan_days_multiple_per_date.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

// Проверяем наличие индекса перед удалением
$table = 'training_plan_days';
$result = $db->query("SHOW INDEX FROM {$table} WHERE Key_name = 'unique_user_date'");
if (!$result || $result->num_rows === 0) {
    echo "Индекс unique_user_date уже отсутствует или таблица не найдена. Ничего не делаем.\n";
    exit(0);
}

if ($db->query("ALTER TABLE {$table} DROP INDEX unique_user_date")) {
    echo "Индекс unique_user_date удалён. Теперь можно добавлять несколько тренировок на одну дату.\n";
} else {
    fwrite(STDERR, "Ошибка: " . $db->error . "\n");
    exit(1);
}
