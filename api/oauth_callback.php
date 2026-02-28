<?php
/**
 * OAuth callback для интеграций (Huawei, Garmin, Strava).
 * Huawei перенаправляет сюда после авторизации: ?provider=huawei&code=...&state=...
 */
require_once __DIR__ . '/session_init.php';
session_start();

$providerId = $_GET['provider'] ?? '';
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

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

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: ' . $baseUrl . '/login?redirect=' . urlencode($redirectBase . '&error=not_authenticated'));
    exit;
}

if (($state !== ($_SESSION['csrf_token'] ?? '')) && ($state !== ($_SESSION['integration_state'] ?? ''))) {
    header('Location: ' . $redirectBase . '&error=invalid_state');
    exit;
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/planrun-backend/config/env_loader.php';
require_once $projectRoot . '/planrun-backend/db_config.php';
require_once $projectRoot . '/planrun-backend/auth.php';
require_once $projectRoot . '/planrun-backend/user_functions.php';
require_once $projectRoot . '/planrun-backend/providers/WorkoutImportProvider.php';
require_once $projectRoot . '/planrun-backend/providers/HuaweiHealthProvider.php';
require_once $projectRoot . '/planrun-backend/providers/StravaProvider.php';
require_once $projectRoot . '/planrun-backend/providers/PolarProvider.php';

$providers = ['huawei' => HuaweiHealthProvider::class, 'strava' => StravaProvider::class, 'polar' => PolarProvider::class];
if (!isset($providers[$providerId])) {
    header('Location: ' . $redirectBase . '&error=unknown_provider');
    exit;
}

$db = getDBConnection();
if (!$db) {
    header('Location: ' . $redirectBase . '&error=db_error');
    exit;
}

$_SESSION['user_id'] = $_SESSION['user_id'] ?? null;
if (!$_SESSION['user_id']) {
    header('Location: ' . $redirectBase . '&error=not_authenticated');
    exit;
}

try {
    $provider = new $providers[$providerId]($db);
    $provider->exchangeCodeForTokens($code, $state);
    if ($providerId === 'strava') {
        $provider->ensureIntegrationHealthy((int)$_SESSION['user_id']);
    }
    header('Location: ' . $redirectBase . '&connected=' . urlencode($providerId));
} catch (Exception $e) {
    header('Location: ' . $redirectBase . '&error=' . urlencode($e->getMessage()));
}
exit;
