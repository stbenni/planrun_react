<?php
/**
 * API обертка для api_v2.php (для React приложения)
 * Проксирует запросы к api_v2.php с CORS
 *
 * ВАЖНО: Использует api_v2.php (рефакторинг на контроллеры)
 */

// Ранний обработчик: любой фатал/исключение до api_v2 → JSON (не HTML)
ob_start();
$apiWrapperJsonError = function ($message, $code = 500) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};
set_exception_handler(function ($e) use (&$apiWrapperJsonError) {
    $apiWrapperJsonError($e->getMessage(), 500);
});
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () use (&$apiWrapperJsonError) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $apiWrapperJsonError('Fatal: ' . $err['message'], 500);
    }
});

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/session_init.php';

// Настройки сессии для cross-origin
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    try {
        session_start();
    } catch (Throwable $e) {
        // api/sessions недоступен для записи — fallback на sys_temp_dir
        session_save_path(sys_get_temp_dir());
        try {
            session_start();
        } catch (Throwable $e2) {
            // без сессии продолжаем (get_site_settings и др. работают)
        }
    }
}

$action = $_GET['action'] ?? '';

// Сброс пароля не требует сессию; раннее освобождение блокировки избегает зависания
if (in_array($action, ['request_password_reset', 'confirm_password_reset'], true) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Для сброса пароля (SMTP) заранее грузим Composer autoload, иначе require в EmailService зависает по таймауту
if ($action === 'request_password_reset') {
    set_time_limit(60);
    $autoload = __DIR__ . '/../planrun-backend/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

// check_auth отдаём бэкенду (api_v2), чтобы в ответе были avatar_path и др.
define('API_WRAPPER_CORS_SENT', true);
require_once __DIR__ . '/../planrun-backend/api_v2.php';
