<?php
/**
 * API обертка для login (для React приложения)
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

if (isAuthenticated()) {
    echo json_encode(['success' => true, 'message' => 'Already authenticated']);
    exit;
}

$error = '';
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isJsonRequest = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        if ($isAjax || $isJsonRequest) {
            echo json_encode(['success' => true, 'message' => 'Login successful']);
            exit;
        }
        exit;
    }
    $error = 'Неверное имя пользователя или пароль';
    if ($isAjax || $isJsonRequest) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
