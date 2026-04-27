#!/usr/bin/env php
<?php
/**
 * Processor for delayed Strava webhook import retries.
 *
 * The script is intentionally quiet when the retry table is not installed:
 * older crontabs may contain this job even on deployments that never enabled
 * the retry queue.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    echo "DB connection failed\n";
    exit(1);
}

$tableCheck = $db->query("SHOW TABLES LIKE 'strava_webhook_retry_queue'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    exit(0);
}

$logFile = $baseDir . '/logs/strava_retry.log';
$log = function (string $msg) use ($logFile): void {
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
};

$db->query("
    UPDATE strava_webhook_retry_queue
    SET status = 'pending', updated_at = NOW()
    WHERE status = 'processing'
      AND updated_at < (NOW() - INTERVAL 30 MINUTE)
");

$stmt = $db->prepare("
    SELECT id, user_id, strava_activity_id, aspect_type, attempts, max_attempts
    FROM strava_webhook_retry_queue
    WHERE status = 'pending' AND next_retry_at <= NOW()
    ORDER BY next_retry_at ASC
    LIMIT 10
");
if (!$stmt) {
    exit(0);
}
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($jobs)) {
    cleanupOldRetries($db);
    exit(0);
}

$log('processing ' . count($jobs) . ' retry jobs');

$ids = array_map('intval', array_column($jobs, 'id'));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$markStmt = $db->prepare("UPDATE strava_webhook_retry_queue SET status='processing', updated_at=NOW() WHERE id IN ($placeholders)");
if ($markStmt) {
    $markStmt->bind_param($types, ...$ids);
    $markStmt->execute();
    $markStmt->close();
}

require_once $baseDir . '/providers/StravaProvider.php';
require_once $baseDir . '/services/WorkoutService.php';

foreach ($jobs as $job) {
    $jobId = (int) $job['id'];
    $userId = (int) $job['user_id'];
    $activityId = (int) $job['strava_activity_id'];
    $attempts = (int) $job['attempts'] + 1;
    $maxAttempts = (int) $job['max_attempts'];

    try {
        $extId = 'strava_' . $activityId;
        $checkStmt = $db->prepare("SELECT id FROM workouts WHERE user_id = ? AND source = 'strava' AND external_id = ? LIMIT 1");
        $checkStmt->bind_param('is', $userId, $extId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            markRetryCompleted($db, $jobId, $attempts);
            $log("activity_id=$activityId user_id=$userId already imported, marked completed");
            continue;
        }

        $provider = new StravaProvider($db);
        $provider->ensureIntegrationHealthy($userId);

        $lastHttpCode = 0;
        $lastError = '';
        $onError = static function ($httpCode, $response, $msg) use (&$lastHttpCode, &$lastError): void {
            $lastHttpCode = (int) $httpCode;
            $lastError = substr((string) $msg, 0, 500);
        };

        $workout = $provider->fetchSingleActivity($activityId, $userId, $onError);
        if ($workout) {
            (new WorkoutService($db))->importWorkouts($userId, [$workout], 'strava');

            try {
                require_once $baseDir . '/services/PushNotificationService.php';
                (new PushNotificationService($db))->sendDataPush($userId, ['type' => 'workout_sync', 'source' => 'strava']);
            } catch (Throwable $e) {
                // Silent push is best-effort.
            }

            markRetryCompleted($db, $jobId, $attempts);
            $log("SUCCESS activity_id=$activityId user_id=$userId attempt=$attempts");
            continue;
        }

        rescheduleOrFailRetry($db, $jobId, $activityId, $userId, $attempts, $maxAttempts, $lastError, $lastHttpCode, $log);
    } catch (Throwable $e) {
        rescheduleOrFailRetry($db, $jobId, $activityId, $userId, $attempts, $maxAttempts, substr($e->getMessage(), 0, 500), 0, $log);
    }
}

cleanupOldRetries($db);

function markRetryCompleted(mysqli $db, int $jobId, int $attempts): void {
    $upd = $db->prepare("UPDATE strava_webhook_retry_queue SET status='completed', attempts=?, finished_at=NOW() WHERE id=?");
    if (!$upd) {
        return;
    }
    $upd->bind_param('ii', $attempts, $jobId);
    $upd->execute();
    $upd->close();
}

function rescheduleOrFailRetry(mysqli $db, int $jobId, int $activityId, int $userId, int $attempts, int $maxAttempts, string $lastError, int $lastHttpCode, callable $log): void {
    if ($attempts >= $maxAttempts) {
        $upd = $db->prepare("UPDATE strava_webhook_retry_queue SET status='failed', attempts=?, last_error=?, last_http_code=?, finished_at=NOW() WHERE id=?");
        if ($upd) {
            $upd->bind_param('isii', $attempts, $lastError, $lastHttpCode, $jobId);
            $upd->execute();
            $upd->close();
        }
        $log("GAVE UP activity_id=$activityId user_id=$userId after $attempts attempts, last_error=$lastError");
        return;
    }

    $delay = getBackoffSeconds($attempts);
    $upd = $db->prepare("UPDATE strava_webhook_retry_queue SET status='pending', attempts=?, next_retry_at=DATE_ADD(NOW(), INTERVAL ? SECOND), last_error=?, last_http_code=? WHERE id=?");
    if ($upd) {
        $upd->bind_param('iisii', $attempts, $delay, $lastError, $lastHttpCode, $jobId);
        $upd->execute();
        $upd->close();
    }
    $log("RETRY activity_id=$activityId user_id=$userId attempt=$attempts next_in={$delay}s error=$lastError");
}

function cleanupOldRetries(mysqli $db): void {
    $db->query("
        DELETE FROM strava_webhook_retry_queue
        WHERE status IN ('completed', 'failed')
          AND finished_at < (NOW() - INTERVAL 7 DAY)
    ");
}

function getBackoffSeconds(int $attempt): int {
    $schedule = [60, 300, 900, 3600, 14400, 43200];
    $idx = min($attempt - 1, count($schedule) - 1);
    return $schedule[max(0, $idx)];
}
