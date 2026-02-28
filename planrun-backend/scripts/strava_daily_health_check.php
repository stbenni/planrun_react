#!/usr/bin/env php
<?php
/**
 * Проверка и восстановление интеграции Strava.
 * Strava access token живёт ~6 часов — скрипт должен запускаться чаще, чем раз в день.
 *
 * Cron: каждые 4 часа — 0 0,4,8,12,16,20 * * * php .../scripts/strava_daily_health_check.php
 *
 * Проверяет всех пользователей с привязанной Strava:
 * 1. external_athlete_id — нужен для webhook (owner_id → user_id). Если пуст — получает из /athlete.
 * 2. Токен — если истёк или истекает в течение 4 часов — обновляет через refresh_token.
 *
 * Работает и после отвязки/привязки: при новой привязке OAuth сохраняет athlete_id,
 * но если по какой-то причине он пуст — скрипт восстановит.
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/providers/StravaProvider.php';

$clientId = env('STRAVA_CLIENT_ID', '');
$clientSecret = env('STRAVA_CLIENT_SECRET', '');
if (!$clientId || !$clientSecret) {
    fwrite(STDERR, "STRAVA_CLIENT_ID and STRAVA_CLIENT_SECRET required in .env\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$provider = new StravaProvider($db);

$stmt = $db->query("SELECT user_id FROM integration_tokens WHERE provider = 'strava'");
$rows = $stmt ? $stmt->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

if (empty($rows)) {
    exit(0);
}

$athleteIdFixed = 0;
$tokenRefreshed = 0;
$errors = [];

foreach ($rows as $row) {
    $userId = (int)$row['user_id'];
    $r = $provider->ensureIntegrationHealthy($userId);
    if ($r['athlete_id_fixed']) {
        $athleteIdFixed++;
    }
    if ($r['token_refreshed']) {
        $tokenRefreshed++;
    }
    if ($r['error']) {
        $errors[] = "user_id=$userId: " . $r['error'];
    }
    usleep(300000);
}

if ($athleteIdFixed > 0 || $tokenRefreshed > 0 || !empty($errors)) {
    $msg = sprintf(
        "Strava daily check: athlete_id fixed=%d, tokens refreshed=%d, errors=%d",
        $athleteIdFixed,
        $tokenRefreshed,
        count($errors)
    );
    if (!empty($errors)) {
        $msg .= "\n" . implode("\n", array_slice($errors, 0, 10));
        if (count($errors) > 10) {
            $msg .= "\n... and " . (count($errors) - 10) . " more";
        }
    }
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    }
    if (file_exists($baseDir . '/config/Logger.php')) {
        require_once $baseDir . '/config/Logger.php';
        \Logger::info('Strava daily health check', [
            'athlete_id_fixed' => $athleteIdFixed,
            'tokens_refreshed' => $tokenRefreshed,
            'errors' => $errors,
        ]);
    }
}
