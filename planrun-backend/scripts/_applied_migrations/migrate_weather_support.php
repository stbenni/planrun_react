#!/usr/bin/env php
<?php
/**
 * Миграция: weather support
 *  1. users.location_city / latitude / longitude — для геолокации пользователя
 *  2. weather_forecast_cache — кэш ответов OpenWeatherMap (TTL ~3 часа), чтобы не дёргать API
 *
 * Запуск: php scripts/migrate_weather_support.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

function colExists(mysqli $db, string $table, string $col): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $cnt > 0;
}
function tableExists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $cnt > 0;
}

// 1. users columns
$userCols = [
    'location_city' => 'VARCHAR(120) NULL DEFAULT NULL',
    'latitude' => 'DECIMAL(8,4) NULL DEFAULT NULL',
    'longitude' => 'DECIMAL(8,4) NULL DEFAULT NULL',
];
foreach ($userCols as $col => $def) {
    if (colExists($db, 'users', $col)) {
        echo "SKIP users.{$col}\n";
        continue;
    }
    if ($db->query("ALTER TABLE users ADD COLUMN {$col} {$def}")) {
        echo "ADDED users.{$col}\n";
    } else {
        fwrite(STDERR, "FAILED users.{$col}: " . $db->error . "\n");
        exit(1);
    }
}

// 2. weather_forecast_cache table
if (tableExists($db, 'weather_forecast_cache')) {
    echo "SKIP weather_forecast_cache\n";
    exit(0);
}

$sql = <<<SQL
CREATE TABLE weather_forecast_cache (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_key VARCHAR(64) NOT NULL COMMENT 'sprintf("%.2f_%.2f", lat, lon)',
    fetched_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    payload_json JSON NOT NULL,
    UNIQUE KEY ux_location (location_key),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$db->query($sql)) {
    fwrite(STDERR, "FAILED weather_forecast_cache: " . $db->error . "\n");
    exit(1);
}
echo "CREATED weather_forecast_cache\n";
