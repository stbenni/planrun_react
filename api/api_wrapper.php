<?php
/**
 * API обертка для api_v2.php (для React приложения)
 * Проксирует запросы к api_v2.php с CORS
 *
 * ВАЖНО: Использует api_v2.php (рефакторинг на контроллеры)
 */

require_once __DIR__ . '/cors.php';

// Настройки сессии для cross-origin
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
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
