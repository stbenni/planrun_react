#!/usr/bin/env php
<?php
/**
 * Миграция: daily_wellness — субъективные данные пользователя по дням.
 * Используется в чате для адаптации советов: плохой сон / высокий stress / соленость → не давить интервалами.
 *
 * Шкалы 1-5:
 *  - sleep_quality: 1=ужасно, 5=отлично
 *  - mood: 1=apathy/down, 5=energetic
 *  - soreness: 1=нет, 5=сильная боль
 *  - stress: 1=нет, 5=сильный
 *  - energy: 1=нет сил, 5=полно сил
 * RPE последней тренировки 1-10 (опционально)
 *
 * Запуск: php scripts/migrate_daily_wellness.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$tableExistsStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$table = 'daily_wellness';
$tableExistsStmt->bind_param('s', $table);
$tableExistsStmt->execute();
$exists = (int) ($tableExistsStmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
$tableExistsStmt->close();

if ($exists) {
    echo "SKIP: daily_wellness already exists\n";
    exit(0);
}

$sql = <<<SQL
CREATE TABLE daily_wellness (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    log_date DATE NOT NULL,
    sleep_quality TINYINT NULL DEFAULT NULL COMMENT '1-5: качество сна',
    mood TINYINT NULL DEFAULT NULL COMMENT '1-5: настроение',
    soreness TINYINT NULL DEFAULT NULL COMMENT '1-5: мышечная боль',
    stress TINYINT NULL DEFAULT NULL COMMENT '1-5: уровень стресса',
    energy TINYINT NULL DEFAULT NULL COMMENT '1-5: уровень энергии',
    last_workout_rpe TINYINT NULL DEFAULT NULL COMMENT '1-10: RPE последней тренировки',
    notes TEXT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ux_user_date (user_id, log_date),
    INDEX idx_user_date (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$db->query($sql)) {
    fwrite(STDERR, "FAILED to create daily_wellness: " . $db->error . "\n");
    exit(1);
}

echo "CREATED: daily_wellness\n";
