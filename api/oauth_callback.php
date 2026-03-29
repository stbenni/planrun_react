<?php
/**
 * OAuth callback для интеграций (Huawei, Strava, Polar, Garmin, COROS).
 * Поддерживает два режима:
 *  1. Web (сессия) — классический redirect flow
 *  2. Mobile app (подписанный state) — In-App Browser без общей сессии
 */
require_once __DIR__ . '/session_init.php';
session_start();

$providerId = $_GET['provider'] ?? '';
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/planrun-backend/config/env_loader.php';

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$redirectBase = $baseUrl . '/settings?tab=integrations';

if ($error) {
    header('Location: ' . $redirectBase . '&error=' . urlencode($error));
    exit;
}

if (!$providerId || !$code || !$state) {
    header('Location: ' . $redirectBase . '&error=missing_params');
    exit;
}

// --- Аутентификация: подписанный state (mobile) ИЛИ сессия (web) ---
$userId = null;
$isFromApp = false;

// Попытка декодировать подписанный state (payload.hmac)
$parts = explode('.', $state, 2);
if (count($parts) === 2) {
    $payload = $parts[0];
    $hmac = $parts[1];
    $secret = env('JWT_SECRET_KEY', 'oauth-state-fallback-' . md5($projectRoot . '/planrun-backend/controllers'));
    $expectedHmac = hash_hmac('sha256', $payload, $secret);
    if (hash_equals($expectedHmac, $hmac)) {
        $data = json_decode(base64_decode($payload), true);
        if ($data && !empty($data['uid']) && !empty($data['ts'])) {
            // Проверяем, что state не старше 10 минут
            if (time() - (int)$data['ts'] < 600) {
                $userId = (int)$data['uid'];
                $isFromApp = !empty($data['app']);
            }
        }
    }
}

// Fallback: сессионная аутентификация (web flow)
if (!$userId) {
    if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || empty($_SESSION['user_id'])) {
        header('Location: ' . $baseUrl . '/login?redirect=' . urlencode($redirectBase . '&error=not_authenticated'));
        exit;
    }
    // Проверка CSRF для сессионного flow (старый формат state)
    if (($state !== ($_SESSION['csrf_token'] ?? '')) && ($state !== ($_SESSION['integration_state'] ?? ''))) {
        header('Location: ' . $redirectBase . '&error=invalid_state');
        exit;
    }
    // Очищаем CSRF-токены после использования (защита от replay-атак)
    unset($_SESSION['csrf_token'], $_SESSION['integration_state']);
    $userId = (int)$_SESSION['user_id'];
}

// --- Обмен кода на токены ---
require_once $projectRoot . '/planrun-backend/db_config.php';
require_once $projectRoot . '/planrun-backend/auth.php';
require_once $projectRoot . '/planrun-backend/user_functions.php';
require_once $projectRoot . '/planrun-backend/providers/WorkoutImportProvider.php';
require_once $projectRoot . '/planrun-backend/providers/HuaweiHealthProvider.php';
require_once $projectRoot . '/planrun-backend/providers/StravaProvider.php';
require_once $projectRoot . '/planrun-backend/providers/PolarProvider.php';
require_once $projectRoot . '/planrun-backend/providers/GarminProvider.php';
require_once $projectRoot . '/planrun-backend/providers/CorosProvider.php';

$providers = [
    'huawei' => HuaweiHealthProvider::class,
    'strava' => StravaProvider::class,
    'polar' => PolarProvider::class,
    'garmin' => GarminProvider::class,
    'coros' => CorosProvider::class,
];
if (!isset($providers[$providerId])) {
    header('Location: ' . $redirectBase . '&error=unknown_provider');
    exit;
}

$db = getDBConnection();
if (!$db) {
    header('Location: ' . $redirectBase . '&error=db_error');
    exit;
}

// Устанавливаем user_id в сессию (может отсутствовать при mobile flow)
$_SESSION['user_id'] = $userId;

try {
    $provider = new $providers[$providerId]($db);
    $provider->exchangeCodeForTokens($code, $state);
    if ($providerId === 'strava') {
        $provider->ensureIntegrationHealthy($userId);
    }
    if ($providerId === 'polar' && $provider instanceof PolarProvider) {
        $wh = $provider->ensureWebhookSubscription();
        if (empty($wh['ok'])) {
            require_once $projectRoot . '/planrun-backend/config/Logger.php';
            Logger::warning('Polar webhook ensure failed after OAuth', [
                'user_id' => $userId,
                'error' => $wh['error'] ?? 'unknown',
            ]);
        }
    }

    if ($isFromApp) {
        // Mobile: deep link redirect — Chrome Custom Tab закроется автоматически
        header('Location: planrun://oauth-callback?connected=' . urlencode($providerId));
    } else {
        // Web: обычный redirect
        header('Location: ' . $redirectBase . '&connected=' . urlencode($providerId));
    }
} catch (Exception $e) {
    if ($isFromApp) {
        header('Location: planrun://oauth-callback?error=' . urlencode($e->getMessage()));
    } else {
        header('Location: ' . $redirectBase . '&error=' . urlencode($e->getMessage()));
    }
}
exit;
