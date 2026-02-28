#!/usr/bin/env php
<?php
/**
 * Заполнить external_athlete_id для пользователей Strava, у которых он пуст.
 * Вызывает /athlete для получения Strava athlete ID.
 * Запуск: php scripts/strava_backfill_athlete_ids.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$stmt = $db->query("SELECT user_id FROM integration_tokens WHERE provider = 'strava' AND (external_athlete_id IS NULL OR external_athlete_id = '')");
$rows = $stmt ? $stmt->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

if (empty($rows)) {
    echo "No users to backfill.\n";
    exit(0);
}

$getTokenStmt = $db->prepare("SELECT access_token FROM integration_tokens WHERE user_id = ? AND provider = 'strava'");
$updated = 0;

foreach ($rows as $r) {
    $userId = (int)$r['user_id'];
    $getTokenStmt->bind_param("i", $userId);
    $getTokenStmt->execute();
    $tokenRow = $getTokenStmt->get_result()->fetch_assoc();
    if (!$tokenRow) continue;
    $accessToken = $tokenRow['access_token'];
    $ch = curl_init('https://www.strava.com/api/v3/athlete');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    if (($proxy = env('STRAVA_PROXY', ''))) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
    }
    $response = curl_exec($ch);
    curl_close($ch);
    $athlete = json_decode($response, true);
    if (isset($athlete['id'])) {
        $aid = (string)$athlete['id'];
        $up = $db->prepare("UPDATE integration_tokens SET external_athlete_id = ? WHERE user_id = ? AND provider = 'strava'");
        $up->bind_param("si", $aid, $userId);
        if ($up->execute()) $updated++;
        $up->close();
    }
    usleep(300000);
}

$getTokenStmt->close();
echo "Updated $updated users.\n";
