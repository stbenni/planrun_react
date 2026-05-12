<?php
/**
 * Миграция таблиц раздела «Тренеры»: coach_requests, user_coaches, coach_pricing, coach_applications + поля в users
 * Запуск: php scripts/migrate_coach_tables.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

$tables = [
    'coach_requests' => "CREATE TABLE IF NOT EXISTS coach_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        coach_id INT UNSIGNED NOT NULL,
        status ENUM('pending','accepted','rejected') DEFAULT 'pending',
        message TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at TIMESTAMP NULL,
        KEY idx_coach_status (coach_id, status),
        KEY idx_user_coach (user_id, coach_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'user_coaches' => "CREATE TABLE IF NOT EXISTS user_coaches (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        coach_id INT UNSIGNED NOT NULL,
        can_view TINYINT(1) NOT NULL DEFAULT 1,
        can_edit TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_coach (user_id, coach_id),
        KEY idx_coach (coach_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'coach_pricing' => "CREATE TABLE IF NOT EXISTS coach_pricing (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coach_id INT UNSIGNED NOT NULL,
        type ENUM('individual','group','consultation','custom') NOT NULL DEFAULT 'custom',
        label VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NULL,
        currency VARCHAR(3) DEFAULT 'RUB',
        period ENUM('month','week','one_time','custom') DEFAULT 'month',
        sort_order INT NOT NULL DEFAULT 0,
        KEY idx_coach (coach_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'coach_applications' => "CREATE TABLE IF NOT EXISTS coach_applications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        coach_specialization JSON NULL,
        coach_bio TEXT NULL,
        coach_philosophy VARCHAR(500) NULL,
        coach_experience_years TINYINT UNSIGNED NULL,
        coach_runner_achievements TEXT NULL,
        coach_athlete_achievements TEXT NULL,
        coach_certifications TEXT NULL,
        coach_contacts_extra VARCHAR(255) NULL,
        coach_accepts_new TINYINT(1) DEFAULT 1,
        coach_prices_on_request TINYINT(1) DEFAULT 0,
        coach_pricing_json JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        reviewed_by INT UNSIGNED NULL,
        KEY idx_status (status),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    if ($db->query($sql)) {
        echo "OK: $name\n";
    } else {
        fwrite(STDERR, "Ошибка $name: " . $db->error . "\n");
        exit(1);
    }
}

// Поля users для тренеров и активности
$userCols = [
    'coach_bio' => "ALTER TABLE users ADD COLUMN coach_bio TEXT NULL",
    'coach_specialization' => "ALTER TABLE users ADD COLUMN coach_specialization JSON NULL",
    'coach_accepts' => "ALTER TABLE users ADD COLUMN coach_accepts TINYINT(1) NOT NULL DEFAULT 0",
    'coach_prices_on_request' => "ALTER TABLE users ADD COLUMN coach_prices_on_request TINYINT(1) NOT NULL DEFAULT 0",
    'coach_experience_years' => "ALTER TABLE users ADD COLUMN coach_experience_years TINYINT UNSIGNED NULL",
    'coach_philosophy' => "ALTER TABLE users ADD COLUMN coach_philosophy VARCHAR(500) NULL",
    'last_activity' => "ALTER TABLE users ADD COLUMN last_activity DATETIME NULL DEFAULT NULL",
];

foreach ($userCols as $col => $sql) {
    $check = $db->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        if ($db->query($sql)) {
            echo "OK: users.$col\n";
        } else {
            fwrite(STDERR, "Ошибка users.$col: " . $db->error . "\n");
        }
    }
}

echo "Миграция coach tables завершена.\n";
