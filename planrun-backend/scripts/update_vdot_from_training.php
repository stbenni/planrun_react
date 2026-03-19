#!/usr/bin/env php
<?php
/**
 * Обновить last_race_* у пользователя из лучшей тренировки.
 * Запуск: php scripts/update_vdot_from_training.php st_benni
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/StatsService.php';

$usernameSlug = $argv[1] ?? 'st_benni';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

$stmt = $db->prepare("SELECT id, username FROM users WHERE username_slug = ?");
$stmt->bind_param("s", $usernameSlug);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    fwrite(STDERR, "Пользователь не найден: {$usernameSlug}\n");
    exit(1);
}

$userId = (int)$row['id'];
$statsService = new StatsService($db);
$best = $statsService->getBestResultForVdot($userId);

if (!$best) {
    echo "Нет подходящих тренировок (2–25 км за 12 нед.) для пользователя {$usernameSlug}\n";
    exit(0);
}

$distKm = $best['distance_km'];
$timeSec = $best['time_sec'];
$vdot = $best['vdot'];

// Формат времени: ВСЕГДА HH:MM:SS для совместимости с БД
$h = (int)floor($timeSec / 3600);
$m = (int)floor(($timeSec % 3600) / 60);
$s = (int)($timeSec % 60);
$timeStr = sprintf('%02d:%02d:%02d', $h, $m, $s);

// last_race_distance по дистанции
$distMap = [
    5 => '5k', 10 => '10k', 21.0975 => 'half', 42.195 => 'marathon',
];
$lastRaceDist = 'other';
$lastRaceDistKm = $distKm;
foreach ($distMap as $km => $label) {
    if (abs($distKm - $km) < 0.5) {
        $lastRaceDist = $label;
        $lastRaceDistKm = null;
        break;
    }
}

$dateStr = date('Y-m-d');

$updateStmt = $db->prepare("
    UPDATE users SET last_race_distance = ?, last_race_distance_km = ?, last_race_time = ?, last_race_date = ?
    WHERE id = ?
");
$distKmVal = $lastRaceDist === 'other' ? $distKm : null;
$updateStmt->bind_param("sdssi", $lastRaceDist, $distKmVal, $timeStr, $dateStr, $userId);
$updateStmt->execute();

if ($db->error) {
    fwrite(STDERR, "Ошибка UPDATE: " . $db->error . "\n");
    exit(1);
}

echo "OK: {$usernameSlug} — last_race обновлён: {$distKm} км за {$timeStr}, VDOT {$vdot}\n";
