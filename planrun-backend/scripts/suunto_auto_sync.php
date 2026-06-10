#!/usr/bin/env php
<?php
/**
 * Авто-синк Suunto → PlanRun (поллинг). Надёжный фолбэк к webhook'у:
 * Suunto часто НЕ доставляет WORKOUT_CREATED-пуши (circuit breaker / настройка приложения /
 * только на Production), поэтому периодически сами тянем последние тренировки и импортируем.
 * Идемпотентно (дедуп по external_id + кросс-сорс), окно — последние дни.
 *
 * Cron (например каждые 15 минут):
 *   *\/15 * * * * php /var/www/planrun/planrun-backend/scripts/suunto_auto_sync.php >> /var/log/planrun-suunto-sync.log 2>&1
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/providers/SuuntoProvider.php';
require_once $baseDir . '/services/WorkoutService.php';

if ((int) env('SUUNTO_AUTO_SYNC_ENABLED', 1) !== 1) {
    echo "SuuntoAutoSync disabled\n";
    exit(0);
}

$lockPath = sys_get_temp_dir() . '/planrun-suunto-auto-sync.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "SuuntoAutoSync already running\n";
    exit(0);
}

$db = getDBConnection();
if (!$db) {
    echo "DB unavailable\n";
    exit(1);
}

$days = max(1, (int) env('SUUNTO_AUTO_SYNC_DAYS', 3));
$startDate = date('Y-m-d', strtotime("-{$days} days"));
$endDate = date('Y-m-d');

$provider = new SuuntoProvider($db);
$service = new WorkoutService($db);

$res = $db->query("SELECT user_id FROM integration_tokens WHERE provider = 'suunto'");
$users = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $users[] = (int) $r['user_id'];
    }
}

foreach ($users as $userId) {
    try {
        $workouts = $provider->fetchWorkouts($userId, $startDate, $endDate);
        if (empty($workouts)) {
            continue;
        }
        $result = $service->importWorkouts($userId, $workouts, 'suunto');
        $imported = (int) ($result['imported'] ?? 0);
        if ($imported > 0) {
            // Толкаем пуш — чтобы приложение обновило виджеты/календарь (как делает webhook).
            try {
                require_once $baseDir . '/services/PushNotificationService.php';
                (new PushNotificationService($db))->sendDataPush($userId, ['type' => 'suunto_sync', 'source' => 'suunto']);
            } catch (\Throwable $e) {
                // best-effort
            }
            echo date('c') . " user={$userId} imported={$imported} skipped=" . (int) ($result['skipped'] ?? 0) . "\n";
        }
    } catch (\Throwable $e) {
        require_once $baseDir . '/config/Logger.php';
        Logger::warning('Suunto auto-sync failed', ['user_id' => $userId, 'msg' => $e->getMessage()]);
    }
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
