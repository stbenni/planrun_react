#!/usr/bin/env php
<?php
/**
 * Миграция: добавить поля для расчёта тренировочной нагрузки (TRIMP/ATL/CTL/TSB).
 * - max_hr, rest_hr в users
 * - trimp в workouts
 * - индекс idx_workouts_user_trimp
 * Запуск: php scripts/migrate_training_load.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

/**
 * Helper: check if a column exists in a table.
 */
function columnExists(mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['cnt'] > 0;
}

/**
 * Helper: check if an index exists on a table.
 */
function indexExists(mysqli $db, string $table, string $indexName): bool {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->bind_param('ss', $table, $indexName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['cnt'] > 0;
}

$errors = 0;

// 1. Add max_hr to users
if (!columnExists($db, 'users', 'max_hr')) {
    $sql = "ALTER TABLE users ADD COLUMN max_hr SMALLINT UNSIGNED NULL";
    if ($db->query($sql)) {
        echo "OK: added max_hr to users\n";
    } else {
        fwrite(STDERR, "Error adding max_hr: " . $db->error . "\n");
        $errors++;
    }
} else {
    echo "SKIP: users.max_hr already exists\n";
}

// 2. Add rest_hr to users
if (!columnExists($db, 'users', 'rest_hr')) {
    $sql = "ALTER TABLE users ADD COLUMN rest_hr SMALLINT UNSIGNED NULL";
    if ($db->query($sql)) {
        echo "OK: added rest_hr to users\n";
    } else {
        fwrite(STDERR, "Error adding rest_hr: " . $db->error . "\n");
        $errors++;
    }
} else {
    echo "SKIP: users.rest_hr already exists\n";
}

// 3. Add trimp to workouts
if (!columnExists($db, 'workouts', 'trimp')) {
    $sql = "ALTER TABLE workouts ADD COLUMN trimp DECIMAL(8,2) NULL";
    if ($db->query($sql)) {
        echo "OK: added trimp to workouts\n";
    } else {
        fwrite(STDERR, "Error adding trimp: " . $db->error . "\n");
        $errors++;
    }
} else {
    echo "SKIP: workouts.trimp already exists\n";
}

// 4. Add index idx_workouts_user_trimp
if (!indexExists($db, 'workouts', 'idx_workouts_user_trimp')) {
    $sql = "ALTER TABLE workouts ADD INDEX idx_workouts_user_trimp (user_id, start_time, trimp)";
    if ($db->query($sql)) {
        echo "OK: added index idx_workouts_user_trimp\n";
    } else {
        fwrite(STDERR, "Error adding index: " . $db->error . "\n");
        $errors++;
    }
} else {
    echo "SKIP: index idx_workouts_user_trimp already exists\n";
}

if ($errors > 0) {
    fwrite(STDERR, "Migration completed with {$errors} error(s)\n");
    exit(1);
}

echo "Training load migration done.\n";
