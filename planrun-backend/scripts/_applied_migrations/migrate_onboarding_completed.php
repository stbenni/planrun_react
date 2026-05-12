<?php
/**
 * Добавляет поле onboarding_completed в users для двухэтапной регистрации.
 * 0 = только быстрая регистрация (логин/email/пароль), специализация не пройдена.
 * 1 = пользователь прошёл специализацию (режим, цель, профиль) или зарегистрирован по старой схеме.
 * Запуск: php scripts/migrate_onboarding_completed.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

// Проверяем, есть ли уже колонка
$check = $db->query("SHOW COLUMNS FROM users LIKE 'onboarding_completed'");
if ($check && $check->num_rows > 0) {
    echo "Колонка users.onboarding_completed уже существует.\n";
    exit(0);
}

$sql = "ALTER TABLE users ADD COLUMN onboarding_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER training_mode";

if ($db->query($sql)) {
    echo "Колонка users.onboarding_completed добавлена.\n";
} else {
    fwrite(STDERR, "Ошибка: " . $db->error . "\n");
    exit(1);
}

// Все существующие пользователи считаем прошедшими онбординг (полная регистрация или уже настроены)
$db->query("UPDATE users SET onboarding_completed = 1 WHERE onboarding_completed = 0");
echo "Существующие пользователи помечены как onboarding_completed = 1.\n";
