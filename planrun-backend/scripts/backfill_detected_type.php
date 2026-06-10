#!/usr/bin/env php
<?php

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/planrun_ai/prompt_builder.php';
require_once $baseDir . '/services/TrainingStateBuilder.php';
require_once $baseDir . '/services/WorkoutClassifier.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$onlyUser = null;
$onlyNull = false;
foreach ($argv as $arg) {
    if (strpos($arg, '--user=') === 0) $onlyUser = (int)substr($arg, 7);
    if ($arg === '--only-null') $onlyNull = true;
}

$userSql = "SELECT DISTINCT user_id FROM workouts";
if ($onlyUser) $userSql .= " WHERE user_id = " . (int)$onlyUser;
$userIds = [];
$res = $db->query($userSql);
while ($row = $res->fetch_assoc()) $userIds[] = (int)$row['user_id'];

$builder = new TrainingStateBuilder($db);
$lapStmt = $db->prepare("SELECT distance_km, moving_seconds, elapsed_seconds FROM workout_laps WHERE workout_id = ? ORDER BY lap_index ASC");
$updStmt = $db->prepare("UPDATE workouts SET detected_type = ? WHERE id = ?");

$totalUsers = 0;
$totalUpdated = 0;

foreach ($userIds as $userId) {
    $totalUsers++;
    $paces = null;
    try {
        $state = $builder->buildForUserId($userId);
        $vdot = !empty($state['vdot']) ? (float)$state['vdot'] : null;
        if ($vdot) $paces = getTrainingPaces($vdot);
    } catch (\Throwable $e) {
        $paces = null;
    }

    $maxHr = 0;
    $byRow = $db->prepare("SELECT birth_year FROM users WHERE id = ? LIMIT 1");
    $byRow->bind_param("i", $userId);
    $byRow->execute();
    $byUser = $byRow->get_result()->fetch_assoc();
    $byRow->close();
    $maxHr = WorkoutClassifier::maxHrFromBirthYear(isset($byUser['birth_year']) ? (int)$byUser['birth_year'] : null);

    $wSql = "SELECT id, activity_type, distance_km, duration_seconds, duration_minutes, avg_heart_rate
             FROM workouts WHERE user_id = " . (int)$userId;
    if ($onlyNull) $wSql .= " AND detected_type IS NULL";
    $wRes = $db->query($wSql);

    while ($w = $wRes->fetch_assoc()) {
        $wid = (int)$w['id'];
        $laps = [];
        $lapStmt->bind_param("i", $wid);
        $lapStmt->execute();
        $lr = $lapStmt->get_result();
        while ($lap = $lr->fetch_assoc()) {
            $laps[] = [
                'distance_km' => $lap['distance_km'],
                'moving_seconds' => $lap['moving_seconds'],
                'elapsed_seconds' => $lap['elapsed_seconds'],
            ];
        }

        $type = WorkoutClassifier::classify([
            'activity_type' => $w['activity_type'],
            'distance_km' => $w['distance_km'],
            'duration_seconds' => $w['duration_seconds'],
            'duration_minutes' => $w['duration_minutes'],
            'avg_heart_rate' => $w['avg_heart_rate'],
            'max_hr' => $maxHr,
            'paces' => $paces,
            'laps' => $laps,
        ]);

        $updStmt->bind_param("si", $type, $wid);
        $updStmt->execute();
        if ($updStmt->affected_rows !== 0) $totalUpdated++;
    }
}

$lapStmt->close();
$updStmt->close();

echo "Backfill done. Users: {$totalUsers}, workouts updated: {$totalUpdated}\n";
