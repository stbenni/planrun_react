<?php
/**
 * Процессор очереди ретраев Strava-вебхуков.
 * Забирает failed-импорты и пытается повторно загрузить тренировки.
 *
 * Бэкофф: 1мин → 5мин → 15мин → 1ч → 4ч → 12ч (попытки 7-10 = 12ч).
 * Макс попыток: 10 (~2.5 дня суммарно).
 *
 * Крон: * * * * * php /var/www/planrun/planrun-backend/scripts/process_strava_webhook_retries.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    echo "DB connection failed\n";
    exit(1);
}

$logFile = $baseDir . '/logs/strava_retry.log';
$log = function (string $msg) use ($logFile) {
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
};

// --- 0. Сброс зависших задач (processing > 30 мин) ---
$db->query("
    UPDATE strava_webhook_retry_queue
    SET status = 'pending', updated_at = NOW()
    WHERE status = 'processing'
      AND updated_at < (NOW() - INTERVAL 30 MINUTE)
");

// --- 1. Резервируем до 10 задач ---
$stmt = $db->prepare("
    SELECT id, user_id, strava_activity_id, aspect_type, attempts, max_attempts
    FROM strava_webhook_retry_queue
    WHERE status = 'pending' AND next_retry_at <= NOW()
    ORDER BY next_retry_at ASC
    LIMIT 10
");
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($jobs)) {
    // Ничего — тихо выходим
    goto cleanup;
}

$log('processing ' . count($jobs) . ' retry jobs');

// Помечаем как processing
$ids = array_column($jobs, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$markStmt = $db->prepare("UPDATE strava_webhook_retry_queue SET status='processing', updated_at=NOW() WHERE id IN ($placeholders)");
$markStmt->bind_param($types, ...$ids);
$markStmt->execute();
$markStmt->close();

// --- 2. Загружаем зависимости ---
require_once $baseDir . '/providers/StravaProvider.php';
require_once $baseDir . '/services/WorkoutService.php';

// --- 3. Обрабатываем каждую задачу ---
foreach ($jobs as $job) {
    $jobId = (int) $job['id'];
    $userId = (int) $job['user_id'];
    $activityId = (int) $job['strava_activity_id'];
    $attempts = (int) $job['attempts'] + 1;
    $maxAttempts = (int) $job['max_attempts'];

    // Дедупликация: проверяем, не импортирована ли уже
    $extId = 'strava_' . $activityId;
    $checkStmt = $db->prepare("SELECT id FROM workouts WHERE user_id = ? AND source = 'strava' AND external_id = ? LIMIT 1");
    $checkStmt->bind_param('is', $userId, $extId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($existing) {
        // Уже импортирована (ручной sync или другой механизм) — помечаем completed
        $upd = $db->prepare("UPDATE strava_webhook_retry_queue SET status='completed', finished_at=NOW(), attempts=? WHERE id=?");
        $upd->bind_param('ii', $attempts, $jobId);
        $upd->execute();
        $upd->close();
        $log("activity_id=$activityId user_id=$userId already imported, marked completed");
        continue;
    }

    // Пытаемся загрузить
    $provider = new StravaProvider($db);
    $provider->ensureIntegrationHealthy($userId);

    $lastHttpCode = 0;
    $lastError = '';
    $onError = function ($httpCode, $response, $msg) use (&$lastHttpCode, &$lastError) {
        $lastHttpCode = (int) $httpCode;
        $lastError = substr((string) $msg, 0, 500);
    };

    $workout = $provider->fetchSingleActivity($activityId, $userId, $onError);

    if ($workout) {
        // Успех — импортируем
        $service = new WorkoutService($db);
        $service->importWorkouts($userId, [$workout], 'strava');

        // Silent push
        try {
            require_once $baseDir . '/services/PushNotificationService.php';
            $pushService = new PushNotificationService($db);
            $pushService->sendDataPush($userId, ['type' => 'workout_sync', 'source' => 'strava']);
        } catch (Throwable $e) {
            // не критично
        }

        $upd = $db->prepare("UPDATE strava_webhook_retry_queue SET status='completed', attempts=?, finished_at=NOW() WHERE id=?");
        $upd->bind_param('ii', $attempts, $jobId);
        $upd->execute();
        $upd->close();
        $log("SUCCESS activity_id=$activityId user_id=$userId attempt=$attempts");
    } else {
        // Неудача — ретрай или сдаёмся
        if ($attempts >= $maxAttempts) {
            $upd = $db->prepare("UPDATE strava_webhook_retry_queue SET status='failed', attempts=?, last_error=?, last_http_code=?, finished_at=NOW() WHERE id=?");
            $upd->bind_param('isii', $attempts, $lastError, $lastHttpCode, $jobId);
            $upd->execute();
            $upd->close();
            $log("GAVE UP activity_id=$activityId user_id=$userId after $attempts attempts, last_error=$lastError");
        } else {
            $delay = getBackoffSeconds($attempts);
            $upd = $db->prepare("UPDATE strava_webhook_retry_queue SET status='pending', attempts=?, next_retry_at=DATE_ADD(NOW(), INTERVAL ? SECOND), last_error=?, last_http_code=? WHERE id=?");
            $upd->bind_param('iisii', $attempts, $delay, $lastError, $lastHttpCode, $jobId);
            $upd->execute();
            $upd->close();
            $log("RETRY activity_id=$activityId user_id=$userId attempt=$attempts next_in={$delay}s error=$lastError");
        }
    }
}

// --- 4. Очистка старых записей (>7 дней) ---
cleanup:
$db->query("
    DELETE FROM strava_webhook_retry_queue
    WHERE status IN ('completed', 'failed')
      AND finished_at < (NOW() - INTERVAL 7 DAY)
");

/**
 * Экспоненциальный бэкофф: 1мин, 5мин, 15мин, 1ч, 4ч, 12ч.
 */
function getBackoffSeconds(int $attempt): int {
    $schedule = [60, 300, 900, 3600, 14400, 43200];
    $idx = min($attempt - 1, count($schedule) - 1);
    return $schedule[max(0, $idx)];
}
