<?php
/**
 * COROS Workout Summary Data Push + проверка доступности (Service Status).
 *
 * Укажите в заявке COROS:
 * - Workout data receiving Endpoint URL → https://your-domain.com/api/coros_workout_push.php
 * - Service Status Check URL → тот же адрес (GET возвращает JSON ok)
 *
 * POST: при COROS_PUSH_SECRET задайте заголовок X-PlanRun-Coros-Secret: <секрет>.
 * Тело JSON — ищем внешний id пользователя COROS по полям из COROS_PUSH_EXTERNAL_ID_KEYS
 * (по умолчанию openId,open_id,userId,user_id) и делаем импорт за последние 14 дней.
 *
 * После получения официального формата тела из API Reference Guide — при необходимости
 * скорректируйте corosResolveUserId() ниже.
 */
require_once __DIR__ . '/../planrun-backend/config/env_loader.php';
require_once __DIR__ . '/../planrun-backend/db_config.php';
require_once __DIR__ . '/../planrun-backend/providers/CorosProvider.php';
require_once __DIR__ . '/../planrun-backend/services/WorkoutService.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' || $method === 'HEAD') {
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'planrun-coros-push',
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$secret = (function_exists('env') ? env('COROS_PUSH_SECRET', '') : '') ?: '';
if ($secret !== '') {
    $hdr = $_SERVER['HTTP_X_PLANRUN_COROS_SECRET'] ?? '';
    if (!is_string($hdr) || !hash_equals($secret, $hdr)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'invalid_json']);
    exit;
}

$db = getDBConnection();
if (!$db) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}

$externalId = corosResolveExternalUserId($payload);
if ($externalId === null || $externalId === '') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'no_user_key']);
    exit;
}

$stmt = $db->prepare('SELECT user_id FROM integration_tokens WHERE provider = ? AND external_athlete_id = ? LIMIT 1');
$prov = 'coros';
$stmt->bind_param('ss', $prov, $externalId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'user_not_linked']);
    exit;
}

$userId = (int)$row['user_id'];

http_response_code(200);
echo json_encode(['ok' => true, 'accepted' => true]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

try {
    $provider = new CorosProvider($db);
    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime('-14 days'));
    $workouts = $provider->fetchWorkouts($userId, $start, $end);
    $service = new WorkoutService($db);
    $service->importWorkouts($userId, $workouts, 'coros');
} catch (Throwable $e) {
    require_once __DIR__ . '/../planrun-backend/config/Logger.php';
    Logger::warning('COROS push import failed', ['user_id' => $userId, 'msg' => $e->getMessage()]);
    exit;
}

try {
    require_once __DIR__ . '/../planrun-backend/services/PushNotificationService.php';
    $pushService = new PushNotificationService($db);
    $pushService->sendDataPush($userId, [
        'type' => 'coros_sync',
        'source' => 'coros',
    ]);
} catch (Throwable $e) {
    // OK
}
exit;

/**
 * @param array<string,mixed> $payload
 */
function corosResolveExternalUserId(array $payload): ?string {
    $keysStr = (function_exists('env') ? env('COROS_PUSH_EXTERNAL_ID_KEYS', 'openId,open_id,userId,user_id,sub') : '') ?: 'openId,open_id,userId,user_id,sub';
    $keys = array_map('trim', explode(',', $keysStr));
    foreach ($keys as $k) {
        if ($k !== '' && !empty($payload[$k])) {
            return trim((string)$payload[$k]);
        }
    }
    if (!empty($payload['data']) && is_array($payload['data'])) {
        return corosResolveExternalUserId($payload['data']);
    }
    if (!empty($payload['user']) && is_array($payload['user'])) {
        return corosResolveExternalUserId($payload['user']);
    }
    return null;
}
