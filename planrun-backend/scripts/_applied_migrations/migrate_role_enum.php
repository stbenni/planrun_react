<?php
/**
 * Добавляет роль 'user' в колонку role таблицы users.
 * Исправляет ошибку "Data truncated for column 'role'" при минимальной регистрации.
 * Запуск: php scripts/migrate_role_enum.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

// Проверяем текущий тип колонки
$info = $db->query("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
if (!$info || $info->num_rows === 0) {
    fwrite(STDERR, "Колонка users.role не найдена.\n");
    exit(1);
}
$row = $info->fetch_assoc();
$currentType = $row['COLUMN_TYPE'] ?? '';
$currentDefault = $row['COLUMN_DEFAULT'] ?? null;

// Если уже ENUM с 'user' — ничего не делаем
if (stripos($currentType, 'enum') !== false && stripos($currentType, "'user'") !== false) {
    echo "Колонка users.role уже содержит значение 'user'.\n";
    exit(0);
}

// Приводим к ENUM('admin','coach','user') с DEFAULT 'user' или к VARCHAR(50)
$sql = "ALTER TABLE users MODIFY COLUMN role ENUM('admin','coach','user') NOT NULL DEFAULT 'user'";

if ($db->query($sql)) {
    echo "Колонка users.role обновлена: ENUM('admin','coach','user') DEFAULT 'user'.\n";
} else {
    fwrite(STDERR, "Ошибка: " . $db->error . "\n");
    exit(1);
}
