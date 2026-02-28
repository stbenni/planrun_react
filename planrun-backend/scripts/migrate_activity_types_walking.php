#!/usr/bin/env php
<?php
/**
 * Миграция: добавить walking, hiking в activity_types для ходьбы и походов
 * Запуск: php scripts/migrate_activity_types_walking.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$tables = $db->query("SHOW TABLES LIKE 'activity_types'");
if (!$tables || $tables->num_rows === 0) {
    echo "Table activity_types does not exist. Create it first with id, name, icon, color, is_active, sort_order.\n";
    exit(0);
}

$toAdd = [
    ['name' => 'walking', 'icon' => '🚶', 'color' => '#22C55E', 'sort_order' => 20],
    ['name' => 'hiking', 'icon' => '🥾', 'color' => '#16A34A', 'sort_order' => 21],
];

foreach ($toAdd as $row) {
    $check = $db->prepare("SELECT id FROM activity_types WHERE name = ?");
    $check->bind_param("s", $row['name']);
    $check->execute();
    $res = $check->get_result();
    $check->close();
    if ($res && $res->num_rows > 0) {
        echo "Activity type '{$row['name']}' already exists.\n";
        continue;
    }
    $stmt = $db->prepare("INSERT INTO activity_types (name, icon, color, is_active, sort_order) VALUES (?, ?, ?, 1, ?)");
    $stmt->bind_param("sssi", $row['name'], $row['icon'], $row['color'], $row['sort_order']);
    if ($stmt->execute()) {
        echo "OK: added activity type '{$row['name']}'\n";
    } else {
        fwrite(STDERR, "Error adding {$row['name']}: " . $db->error . "\n");
    }
    $stmt->close();
}

// Сброс кеша activity_types
if (file_exists($baseDir . '/cache_config.php')) {
    require_once $baseDir . '/cache_config.php';
    if (class_exists('Cache')) {
        Cache::delete('activity_types');
        echo "OK: cleared activity_types cache\n";
    }
}

echo "Migration done.\n";
