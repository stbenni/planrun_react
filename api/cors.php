<?php
/**
 * Общий CORS для api/*.php
 * Вызывать в начале скрипта, до любого вывода.
 */

if (headers_sent()) {
    return;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
// Только s-vladimirov.ru и локальная разработка. Изоляция от planrun.
$allowed = [
    'https://s-vladimirov.ru',
    'https://www.s-vladimirov.ru',
    'http://s-vladimirov.ru',
    'http://www.s-vladimirov.ru',
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:3200',
    'http://127.0.0.1:3200',
];
$ok = $origin && (
    in_array($origin, $allowed, true)
    || strpos($origin, 'http://localhost') === 0
    || strpos($origin, 'http://127.0.0.1') === 0
    || strpos($origin, 'http://192.168.') === 0
);

// Preflight OPTIONS
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($ok) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header('Access-Control-Allow-Headers: ' . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
    }
    http_response_code(204);
    exit(0);
}

// Обычные запросы — CORS заголовки
if ($ok) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}
if (!defined('API_CORS_SENT')) {
    define('API_CORS_SENT', true);
}
