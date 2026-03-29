<?php
/**
 * Polar AccessLink webhook: PING (регистрация), EXERCISE (новая/обновлённая тренировка).
 * Подпись: заголовок Polar-Webhook-Signature = hex(HMAC-SHA256(raw body, signature_secret_key)).
 *
 * URL: POLAR_WEBHOOK_CALLBACK_URL → например https://planrun.ru/api/polar_webhook.php
 *
 * @see https://www.polar.com/accesslink-api/ — раздел Webhooks
 */
require_once __DIR__ . '/../planrun-backend/config/env_loader.php';
require_once __DIR__ . '/../planrun-backend/db_config.php';
require_once __DIR__ . '/../planrun-backend/providers/PolarProvider.php';

$logFile = dirname(__DIR__) . '/planrun-backend/logs/polar_webhook.log';
$log = function (string $msg) use ($logFile): void {
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$eventHeader = $_SERVER['HTTP_POLAR_WEBHOOK_EVENT'] ?? '';
$sigHeader = $_SERVER['HTTP_POLAR_WEBHOOK_SIGNATURE'] ?? null;
if ($sigHeader !== null) {
    $sigHeader = (string)$sigHeader;
}

$payload = json_decode($rawBody, true);
$event = is_array($payload) && isset($payload['event']) ? (string)$payload['event'] : $eventHeader;

// Регистрация webhook: Polar шлёт PING до того, как секрет сохранён у нас — подпись не проверяем.
if ($event === 'PING') {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(200);
    echo '{}';
    exit;
}

if (!is_array($payload)) {
    $log('invalid JSON: ' . substr($rawBody, 0, 200));
    http_response_code(200);
    exit;
}

$db = getDBConnection();
if (!$db) {
    $log('no db');
    http_response_code(200);
    exit;
}

$provider = new PolarProvider($db);
$secret = $provider->loadWebhookSignatureSecret();

if ($event !== 'EXERCISE') {
    http_response_code(200);
    exit;
}

if ($secret !== '') {
    if (!$provider->verifyWebhookSignature($rawBody, $sigHeader)) {
        $log('EXERCISE bad signature');
        http_response_code(403);
        exit;
    }
} else {
    $log('EXERCISE skipped: POLAR_WEBHOOK_SIGNATURE_SECRET / storage file not set');
    http_response_code(200);
    exit;
}

$polarUserId = isset($payload['user_id']) ? trim((string)$payload['user_id']) : '';
$exerciseUrl = isset($payload['url']) ? trim((string)$payload['url']) : '';
if ($polarUserId === '' || $exerciseUrl === '') {
    $log('EXERCISE missing user_id or url');
    http_response_code(200);
    exit;
}

$stmt = $db->prepare('SELECT user_id FROM integration_tokens WHERE provider = ? AND external_athlete_id = ? LIMIT 1');
$prov = 'polar';
$stmt->bind_param('ss', $prov, $polarUserId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $log('polar user_id=' . $polarUserId . ' not linked');
    http_response_code(200);
    exit;
}

$userId = (int)$row['user_id'];

header('Content-Type: application/json; charset=UTF-8');
http_response_code(200);
echo '{}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

require_once __DIR__ . '/../planrun-backend/services/WorkoutService.php';

$workout = $provider->fetchSingleExerciseByUrl($userId, $exerciseUrl);
if ($workout) {
    $service = new WorkoutService($db);
    $service->importWorkouts($userId, [$workout], 'polar');
    $log('imported polar exercise user_id=' . $userId . ' url=' . substr($exerciseUrl, 0, 80));

    try {
        require_once __DIR__ . '/../planrun-backend/services/PushNotificationService.php';
        $pushService = new PushNotificationService($db);
        $pushService->sendDataPush($userId, [
            'type' => 'workout_sync',
            'source' => 'polar',
        ]);
    } catch (Throwable $e) {
        $log('push failed user_id=' . $userId . ' ' . substr($e->getMessage(), 0, 100));
    }
} else {
    $log('fetchSingleExerciseByUrl failed user_id=' . $userId);
}
exit;
