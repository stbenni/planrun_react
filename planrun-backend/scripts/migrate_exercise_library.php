#!/usr/bin/env php
<?php
/**
 * Создание/обновление таблицы exercise_library для ОФП/СБУ.
 * Ожидаемые поля: id, name, category, default_sets, default_reps, default_distance_m, default_duration_sec.
 * Запуск: php scripts/migrate_exercise_library.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$createTable = "CREATE TABLE IF NOT EXISTS exercise_library (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'other',
    default_sets INT UNSIGNED NULL,
    default_reps INT UNSIGNED NULL,
    default_distance_m INT UNSIGNED NULL,
    default_duration_sec INT UNSIGNED NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$db->query($createTable)) {
    fwrite(STDERR, "Error creating exercise_library: " . $db->error . "\n");
    exit(1);
}
echo "OK: exercise_library table exists or created\n";

// Добавить колонки, если таблица уже была без них
$columns = [
    'default_sets' => 'ADD COLUMN default_sets INT UNSIGNED NULL',
    'default_reps' => 'ADD COLUMN default_reps INT UNSIGNED NULL',
    'default_distance_m' => 'ADD COLUMN default_distance_m INT UNSIGNED NULL',
    'default_duration_sec' => 'ADD COLUMN default_duration_sec INT UNSIGNED NULL',
];
foreach ($columns as $col => $alter) {
    $check = $db->query("SHOW COLUMNS FROM exercise_library LIKE '" . $db->real_escape_string($col) . "'");
    if ($check && $check->num_rows === 0) {
        if ($db->query("ALTER TABLE exercise_library " . $alter)) {
            echo "OK: added column $col\n";
        } else {
            fwrite(STDERR, "Warning: could not add $col: " . $db->error . "\n");
        }
    }
}

echo "Done.\n";
