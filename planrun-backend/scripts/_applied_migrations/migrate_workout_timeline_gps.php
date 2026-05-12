#!/usr/bin/env php
<?php
/**
 * Миграция: добавить latitude/longitude в workout_timeline для карт маршрутов
 * Запуск: php scripts/migrate_workout_timeline_gps.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

// Проверяем, есть ли уже колонка latitude
$result = $db->query("SHOW COLUMNS FROM workout_timeline LIKE 'latitude'");
if ($result && $result->num_rows > 0) {
    echo "OK: latitude/longitude columns already exist\n";
    echo "Migration done.\n";
    exit(0);
}

$sql = "ALTER TABLE workout_timeline
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER distance,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude";

if ($db->query($sql)) {
    echo "OK: added latitude, longitude columns to workout_timeline\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}

echo "Migration done.\n";
