#!/usr/bin/env php
<?php
/**
 * Миграция: очередь и кэш карточек шаринга тренировок.
 * Запуск: php scripts/migrate_workout_share_cards.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$migrations = [
    'workout_share_jobs' => "CREATE TABLE IF NOT EXISTS workout_share_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        workout_kind VARCHAR(16) NOT NULL DEFAULT 'workout',
        workout_id INT UNSIGNED NOT NULL,
        template VARCHAR(24) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 2,
        payload_json MEDIUMTEXT NULL,
        result_json MEDIUMTEXT NULL,
        last_error VARCHAR(255) NULL DEFAULT NULL,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL DEFAULT NULL,
        finished_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_workout_share_jobs_due (status, available_at),
        INDEX idx_workout_share_jobs_lookup (user_id, workout_kind, workout_id, template),
        INDEX idx_workout_share_jobs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'workout_share_cards' => "CREATE TABLE IF NOT EXISTS workout_share_cards (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        workout_kind VARCHAR(16) NOT NULL DEFAULT 'workout',
        workout_id INT UNSIGNED NOT NULL,
        template VARCHAR(24) NOT NULL,
        mime_type VARCHAR(64) NOT NULL DEFAULT 'image/png',
        file_path VARCHAR(512) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        map_provider VARCHAR(24) NULL DEFAULT NULL,
        renderer_version VARCHAR(32) NOT NULL DEFAULT 'v3-playwright',
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_workout_share_card (user_id, workout_kind, workout_id, template),
        INDEX idx_workout_share_cards_lookup (user_id, workout_kind, workout_id),
        INDEX idx_workout_share_cards_generated (generated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($migrations as $name => $sql) {
    if ($db->query($sql)) {
        echo "OK: {$name}\n";
        continue;
    }

    fwrite(STDERR, "Error {$name}: " . $db->error . "\n");
    exit(1);
}

echo "Workout share cache migrations complete.\n";
