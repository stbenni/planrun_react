<?php
/**
 * Добавление колонок настроек конфиденциальности профиля
 * Запуск: php scripts/migrate_profile_privacy.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

$columns = [
    'privacy_show_email' => "ADD COLUMN privacy_show_email TINYINT(1) NOT NULL DEFAULT 1",
    'privacy_show_trainer' => "ADD COLUMN privacy_show_trainer TINYINT(1) NOT NULL DEFAULT 1",
    'privacy_show_calendar' => "ADD COLUMN privacy_show_calendar TINYINT(1) NOT NULL DEFAULT 1",
    'privacy_show_metrics' => "ADD COLUMN privacy_show_metrics TINYINT(1) NOT NULL DEFAULT 1",
    'privacy_show_workouts' => "ADD COLUMN privacy_show_workouts TINYINT(1) NOT NULL DEFAULT 1",
];

foreach ($columns as $name => $sql) {
    $check = $db->query("SHOW COLUMNS FROM users LIKE '$name'");
    if ($check && $check->num_rows > 0) {
        echo "Колонка $name уже существует.\n";
        continue;
    }
    if ($db->query("ALTER TABLE users $sql")) {
        echo "Колонка $name добавлена.\n";
    } else {
        fwrite(STDERR, "Ошибка добавления $name: " . $db->error . "\n");
        exit(1);
    }
}

echo "Миграция завершена.\n";
