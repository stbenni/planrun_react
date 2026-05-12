#!/usr/bin/env php
<?php
/**
 * Миграция: добавить source и external_id в workouts для мульти-провайдеров
 * Запуск: php scripts/migrate_workouts_source.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

// Проверяем существование таблицы workouts
$tables = $db->query("SHOW TABLES LIKE 'workouts'");
if (!$tables || $tables->num_rows === 0) {
    echo "Table workouts does not exist. Skipping.\n";
    exit(0);
}

$columns = $db->query("SHOW COLUMNS FROM workouts LIKE 'source'");
if ($columns && $columns->num_rows === 0) {
    if ($db->query("ALTER TABLE workouts ADD COLUMN source VARCHAR(50) NULL DEFAULT NULL")) {
        echo "OK: added column source\n";
    } else {
        fwrite(STDERR, "Error adding source: " . $db->error . "\n");
        exit(1);
    }
} else {
    echo "Column source already exists.\n";
}

$columns = $db->query("SHOW COLUMNS FROM workouts LIKE 'external_id'");
if ($columns && $columns->num_rows === 0) {
    if ($db->query("ALTER TABLE workouts ADD COLUMN external_id VARCHAR(255) NULL AFTER source")) {
        echo "OK: added column external_id\n";
    } else {
        fwrite(STDERR, "Error adding external_id: " . $db->error . "\n");
        exit(1);
    }
} else {
    echo "Column external_id already exists.\n";
}

// Индекс для дедупликации
$indexes = $db->query("SHOW INDEX FROM workouts WHERE Key_name = 'idx_user_external'");
if (!$indexes || $indexes->num_rows === 0) {
    if ($db->query("ALTER TABLE workouts ADD INDEX idx_user_external (user_id, external_id)")) {
        echo "OK: added index idx_user_external\n";
    } else {
        // Игнорируем если external_id ещё не добавлен
        echo "Note: index idx_user_external skipped (may already exist or column missing)\n";
    }
}

echo "Migration done.\n";
