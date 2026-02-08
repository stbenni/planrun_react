<?php
/**
 * API обертка для logout (для React приложения)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/session_init.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

require_once __DIR__ . '/../planrun-backend/auth.php';
header('Content-Type: application/json; charset=utf-8');

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isJsonRequest = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logout();
    if ($isAjax || $isJsonRequest) {
        echo json_encode(['success' => true, 'message' => 'Logout successful']);
        exit;
    }
    header('Location: /');
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
exit;
