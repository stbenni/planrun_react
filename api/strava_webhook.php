<?php
/**
 * Strava Webhook: приём push-уведомлений о новых/изменённых/удалённых тренировках.
 * GET — верификация при регистрации подписки (hub.challenge)
 * POST — события create/update/delete
 *
 * URL: https://your-domain.com/api/strava_webhook.php
 */
require_once __DIR__ . '/../planrun-backend/config/env_loader.php';
require_once __DIR__ . '/../planrun-backend/db_config.php';

$clientId = (function_exists('env') ? env('STRAVA_CLIENT_ID', '') : '') ?: '';
$clientSecret = (function_exists('env') ? env('STRAVA_CLIENT_SECRET', '') : '') ?: '';
$verifyToken = (function_exists('env') ? env('STRAVA_WEBHOOK_VERIFY_TOKEN', '') : '') ?: 'planrun_verify';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    if ($mode === 'subscribe' && $challenge !== '' && $token === $verifyToken) {
        header('Content-Type: application/json');
        echo json_encode(['hub.challenge' => $challenge]);
        exit;
    }
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$input = file_get_contents('php://input');
$event = json_decode($input, true);
$logFile = dirname(__DIR__) . '/planrun-backend/logs/strava_webhook.log';
$log = function ($msg) use ($logFile) {
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
};
if (!$event || !isset($event['object_type'], $event['object_id'], $event['owner_id'])) {
    $log('POST invalid: ' . substr($input ?: '', 0, 200));
    http_response_code(200);
    exit;
}
$log('POST ' . ($event['aspect_type'] ?? '') . ' object_id=' . ($event['object_id'] ?? '') . ' owner_id=' . ($event['owner_id'] ?? ''));

if ($event['object_type'] !== 'activity') {
    http_response_code(200);
    exit;
}

$db = getDBConnection();
if (!$db) {
    http_response_code(200);
    exit;
}

$ownerId = (string)$event['owner_id'];
$objectId = (int)$event['object_id'];
$aspectType = $event['aspect_type'] ?? '';

$stmt = $db->prepare("
    SELECT it.user_id
    FROM integration_tokens it
    INNER JOIN users u ON u.id = it.user_id
    WHERE it.provider = 'strava' AND it.external_athlete_id = ?
    LIMIT 1
");
$stmt->bind_param("s", $ownerId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $log('owner_id=' . $ownerId . ' not found (or user deleted)');
    $cleanupStmt = $db->prepare("
        DELETE it FROM integration_tokens it
        LEFT JOIN users u ON u.id = it.user_id
        WHERE it.provider = 'strava' AND it.external_athlete_id = ? AND u.id IS NULL
    ");
    $cleanupStmt->bind_param("s", $ownerId);
    $cleanupStmt->execute();
    $cleaned = $cleanupStmt->affected_rows;
    $cleanupStmt->close();
    if ($cleaned > 0) {
        $log('cleaned ' . $cleaned . ' orphaned integration_tokens for athlete_id=' . $ownerId);
    }
    http_response_code(200);
    exit;
}

$userId = (int)$row['user_id'];

if ($aspectType === 'delete') {
    $extId = 'strava_' . $objectId;
    $findStmt = $db->prepare("SELECT id FROM workouts WHERE user_id = ? AND source = 'strava' AND external_id = ? LIMIT 1");
    $findStmt->bind_param("is", $userId, $extId);
    $findStmt->execute();
    $w = $findStmt->get_result()->fetch_assoc();
    $findStmt->close();
    if ($w) {
        $wid = (int)$w['id'];
        $tlStmt = $db->prepare("DELETE FROM workout_timeline WHERE workout_id = ?");
        $tlStmt->bind_param("i", $wid);
        $tlStmt->execute();
        $tlStmt->close();
        $delStmt = $db->prepare("DELETE FROM workouts WHERE id = ?");
        $delStmt->bind_param("i", $wid);
        $delStmt->execute();
        $delStmt->close();
    }
    http_response_code(200);
    exit;
}

if ($aspectType === 'create' || $aspectType === 'update') {
    // Strava требует 200 в течение 2 сек. Отправляем ответ сразу, обработку — после.
    header('Content-Type: application/json');
    header('HTTP/1.1 200 OK');
    echo '{}';
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }

    require_once __DIR__ . '/../planrun-backend/providers/StravaProvider.php';
    require_once __DIR__ . '/../planrun-backend/services/WorkoutService.php';
    $provider = new StravaProvider($db);
    $provider->ensureIntegrationHealthy($userId);
    $onError = function ($httpCode, $response, $msg) use ($log, $objectId, $userId) {
        $log('fetch failed activity_id=' . $objectId . ' user_id=' . $userId . ' http=' . $httpCode . ' msg=' . substr((string)$msg, 0, 150));
    };
    $workout = $provider->fetchSingleActivity($objectId, $userId, $onError);
    if ($workout) {
        $service = new WorkoutService($db);
        $service->importWorkouts($userId, [$workout], 'strava');
        $log('imported activity_id=' . $objectId . ' user_id=' . $userId);
    } else {
        $log('fetchSingleActivity failed activity_id=' . $objectId . ' user_id=' . $userId);
    }
    exit;
}

http_response_code(200);
