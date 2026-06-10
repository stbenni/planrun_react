<?php
/**
 * Suunto Webhook — уведомления о новых тренировках (type=WORKOUT_CREATED).
 *
 * Укажите в OAuth-приложении Suunto (apizone.suunto.com → ваше приложение → Webhook):
 *   Webhook URL → https://your-domain.com/api/suunto_webhook.php
 *   Notification secret → значение из .env SUUNTO_WEBHOOK_SECRET
 *
 * Подпись: заголовок X-HMAC-SHA256-Signature = HMAC-SHA256(тело запроса, notification secret).
 * Кодировка в доке не зафиксирована — принимаем и base64, и hex (constant-time сравнение).
 *
 * Тело WORKOUT_CREATED самодостаточно (workout{} содержит сводку), поэтому импортируем
 * прямо из payload; если ключевых полей нет — фолбэк на GET /v2/workout/{workoutKey}.
 * Отвечаем 200 за <2 сек, тяжёлую работу делаем после fastcgi_finish_request.
 */
require_once __DIR__ . '/../planrun-backend/config/env_loader.php';
require_once __DIR__ . '/../planrun-backend/db_config.php';
require_once __DIR__ . '/../planrun-backend/providers/SuuntoProvider.php';
require_once __DIR__ . '/../planrun-backend/services/WorkoutService.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' || $method === 'HEAD') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'service' => 'planrun-suunto-webhook', 'time' => gmdate('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$raw = is_string($raw) ? $raw : '';

// --- Проверка подписи HMAC-SHA256 ---
$secret = (function_exists('env') ? env('SUUNTO_WEBHOOK_SECRET', '') : '') ?: '';
$strict = ((function_exists('env') ? env('SUUNTO_WEBHOOK_STRICT', '0') : '0') ?: '0') === '1';
if ($secret !== '') {
    $sig = $_SERVER['HTTP_X_HMAC_SHA256_SIGNATURE'] ?? '';
    $sigOk = is_string($sig) && suuntoVerifySignature($raw, $secret, $sig);
    if (!$sigOk) {
        // Диагностика схемы подписи: логируем полученную подпись и наши кандидаты,
        // чтобы сверить кодировку (base64/hex) на первом реальном вебхуке.
        require_once __DIR__ . '/../planrun-backend/config/Logger.php';
        $rawHmac = hash_hmac('sha256', $raw, $secret, true);
        Logger::warning('Suunto webhook signature mismatch', [
            'strict' => $strict,
            'received' => is_string($sig) ? $sig : null,
            'cand_base64' => base64_encode($rawHmac),
            'cand_hex' => bin2hex($rawHmac),
        ]);
        if ($strict) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
            exit;
        }
        // нестрогий режим (SUUNTO_WEBHOOK_STRICT!=1): не отклоняем, продолжаем импорт.
    }
}

$payload = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'invalid_json']);
    exit;
}

$type = (string)($payload['type'] ?? '');
$username = isset($payload['username']) ? trim((string)$payload['username']) : '';
$workout = (isset($payload['workout']) && is_array($payload['workout'])) ? $payload['workout'] : [];
$workoutKey = (string)($workout['workoutKey'] ?? $payload['workoutKey'] ?? '');

// Лог факта доставки (для отладки — видно, дошёл ли пуш и с каким username/типом)
require_once __DIR__ . '/../planrun-backend/config/Logger.php';
Logger::warning('Suunto webhook received', [
    'type' => $type,
    'username' => $username,
    'key' => $workoutKey,
    'gear' => $payload['gear']['name'] ?? ($payload['gear']['manufacturer'] ?? null),
    'manually_added' => $workout['isManuallyAdded'] ?? null,
]);

if ($username === '') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'no_username']);
    exit;
}

$db = getDBConnection();
if (!$db) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}

$stmt = $db->prepare('SELECT user_id FROM integration_tokens WHERE provider = ? AND external_athlete_id = ? LIMIT 1');
$prov = 'suunto';
$stmt->bind_param('ss', $prov, $username);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'user_not_linked']);
    exit;
}
$userId = (int)$row['user_id'];

// Отвечаем сразу (Suunto требует 2XX в течение 2 секунд)
http_response_code(200);
echo json_encode(['ok' => true, 'accepted' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

// Импортируем только WORKOUT_CREATED (другие типы просто подтверждаем)
if ($type !== '' && $type !== 'WORKOUT_CREATED') {
    exit;
}

try {
    $provider = new SuuntoProvider($db);
    // Полные данные (GPS + пульс + таймлайн) из FIT; фолбэк — сводка из тела, затем JSON по ключу.
    $mapped = null;
    if ($workoutKey !== '') {
        $mapped = $provider->fetchWorkoutFit($userId, $workoutKey, $workout);
    }
    if ($mapped === null && !empty($workout)) {
        $mapped = $provider->mapSuuntoWorkout($workout);
    }
    if ($mapped === null && $workoutKey !== '') {
        $mapped = $provider->fetchWorkoutByKey($userId, $workoutKey);
    }
    if ($mapped === null) {
        exit;
    }
    $service = new WorkoutService($db);
    $service->importWorkouts($userId, [$mapped], 'suunto');
} catch (Throwable $e) {
    require_once __DIR__ . '/../planrun-backend/config/Logger.php';
    Logger::warning('Suunto webhook import failed', ['user_id' => $userId, 'msg' => $e->getMessage()]);
    exit;
}

try {
    require_once __DIR__ . '/../planrun-backend/services/PushNotificationService.php';
    $pushService = new PushNotificationService($db);
    $pushService->sendDataPush($userId, ['type' => 'suunto_sync', 'source' => 'suunto']);
} catch (Throwable $e) {
    // best-effort
}
exit;

/**
 * Сравнение подписи в constant-time. Принимаем base64 или hex представление HMAC.
 */
function suuntoVerifySignature(string $body, string $secret, string $provided): bool {
    $provided = trim($provided);
    if ($provided === '') {
        return false;
    }
    $rawHmac = hash_hmac('sha256', $body, $secret, true);
    $candidates = [
        base64_encode($rawHmac),
        bin2hex($rawHmac),
    ];
    foreach ($candidates as $c) {
        if (hash_equals($c, $provided)) {
            return true;
        }
    }
    return false;
}
