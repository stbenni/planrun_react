<?php

require_once __DIR__ . '/session_init.php';

if (session_status() === PHP_SESSION_NONE) {
    try {
        session_start();
    } catch (Throwable $e) {
        session_save_path(sys_get_temp_dir());
        session_start();
    }
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/planrun-backend/config/env_loader.php';
require_once $projectRoot . '/planrun-backend/db_config.php';
require_once $projectRoot . '/planrun-backend/services/TelegramLoginService.php';

$scheme = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$webOrigin = $scheme . '://' . $host;
$redirectBase = $webOrigin . '/settings?tab=integrations';

$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$errorDescription = trim((string) ($_GET['error_description'] ?? ''));

function telegramLoginRenderWebResult(string $origin, string $redirectUrl, array $payload): void {
    header('Content-Type: text/html; charset=utf-8');

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $originJson = json_encode($origin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $redirectJson = json_encode($redirectUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Telegram Login</title></head><body>';
    echo '<script>';
    echo '(function () {';
    echo 'const payload = ' . $payloadJson . ';';
    echo 'const targetOrigin = ' . $originJson . ';';
    echo 'const redirectUrl = ' . $redirectJson . ';';
    echo 'try {';
    echo '  if (window.opener && !window.opener.closed) {';
    echo '    window.opener.postMessage(payload, targetOrigin);';
    echo '    window.close();';
    echo '    setTimeout(function () { window.location.replace(redirectUrl); }, 300);';
    echo '    return;';
    echo '  }';
    echo '} catch (error) {}';
    echo 'window.location.replace(redirectUrl);';
    echo '})();';
    echo '</script>';
    echo '<noscript><meta http-equiv="refresh" content="0; url=' . htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></noscript>';
    echo '</body></html>';
    exit;
}

function telegramLoginFinishApp(string $status, string $message = ''): void {
    $query = $status === 'connected'
        ? 'connected=telegram'
        : 'error=' . rawurlencode($message !== '' ? $message : 'Ошибка Telegram Login');
    header('Location: planrun://oauth-callback?' . $query);
    exit;
}

function telegramLoginFinishWeb(string $origin, string $redirectBase, string $status, string $message = ''): void {
    if ($status === 'connected') {
        telegramLoginRenderWebResult($origin, $redirectBase . '&connected=telegram', [
            'type' => 'planrun:telegram-login',
            'status' => 'connected',
        ]);
    }

    telegramLoginRenderWebResult($origin, $redirectBase . '&error=' . rawurlencode($message !== '' ? $message : 'Ошибка Telegram Login'), [
        'type' => 'planrun:telegram-login',
        'status' => 'error',
        'message' => $message !== '' ? $message : 'Ошибка Telegram Login',
    ]);
}

$db = getDBConnection();
$service = $db ? new TelegramLoginService($db) : null;
$flowContext = null;

try {
    if (!$db || !$service) {
        throw new RuntimeException('Не удалось подключиться к базе данных для Telegram Login');
    }

    if ($error !== '') {
        throw new Exception($errorDescription !== '' ? $errorDescription : $error);
    }

    if ($code === '' || $state === '') {
        throw new Exception('Telegram Login вернул неполные параметры');
    }

    $flowContext = $service->getFlowFromState($state);
    $tokens = $service->exchangeCodeForTokens($code, $flowContext['code_verifier']);
    $claims = $service->validateIdToken((string) ($tokens['id_token'] ?? ''));
    $linkResult = $service->linkTelegramAccount((int) $flowContext['uid'], $claims);
    $service->deleteFlow((string) $flowContext['flow_id']);
    $service->sendWelcomeMessageIfConfigured((int) ($linkResult['telegram_id'] ?? 0), $claims['name'] ?? null);

    if (!empty($flowContext['app'])) {
        telegramLoginFinishApp('connected');
    }

    telegramLoginFinishWeb($webOrigin, $redirectBase, 'connected');
} catch (Throwable $e) {
    if ($flowContext && !empty($flowContext['flow_id'])) {
        $service->deleteFlow((string) $flowContext['flow_id']);
    }

    $loggerPath = $projectRoot . '/planrun-backend/config/Logger.php';
    if (is_file($loggerPath)) {
        require_once $loggerPath;
        if (class_exists('Logger')) {
            Logger::warning('Telegram Login callback failed', [
                'message' => $e->getMessage(),
                'code_present' => $code !== '',
                'state_present' => $state !== '',
            ]);
        }
    }

    if ($flowContext && !empty($flowContext['app'])) {
        telegramLoginFinishApp('error', $e->getMessage());
    }

    telegramLoginFinishWeb($webOrigin, $redirectBase, 'error', $e->getMessage());
}
