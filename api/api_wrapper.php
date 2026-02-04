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

if ($action === 'check_auth') {
    require_once __DIR__ . '/../planrun-backend/auth.php';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'authenticated' => isAuthenticated(),
        'user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null,
        'username' => isset($_SESSION['username']) ? $_SESSION['username'] : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

define('API_WRAPPER_CORS_SENT', true);
require_once __DIR__ . '/../planrun-backend/api_v2.php';
