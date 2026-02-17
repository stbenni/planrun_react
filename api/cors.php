<?php
/**
 * Общий CORS для api/*.php
 * Универсально: разрешаем same-origin (текущий хост) и локальную разработку.
 * Домен не захардкожен — работает при любом домене/сервере.
 */

if (headers_sent()) {
    return;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$currentHost = $_SERVER['HTTP_HOST'] ?? '';

$ok = false;
if ($origin && $currentHost) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $currentHostClean = preg_replace('/^www\./', '', $currentHost);
    $originHostClean = preg_replace('/^www\./', '', $originHost ?? '');
    // Same domain: origin host совпадает с текущим хостом
    $sameDomain = $originHost && $currentHostClean && (
        $originHostClean === $currentHostClean
        || strpos($originHostClean, '.' . $currentHostClean) !== false
        || strpos($currentHostClean, '.' . $originHostClean) !== false
    );
    $localDev = strpos($origin, 'http://localhost') === 0
        || strpos($origin, 'http://127.0.0.1') === 0
        || strpos($origin, 'http://192.168.') === 0;
    // Мобильное приложение (Capacitor): origin capacitor://localhost или https://localhost
    $capacitorApp = strpos($origin, 'capacitor://') === 0
        || strpos($origin, 'https://localhost') === 0
        || strpos($origin, 'http://localhost') === 0;
    $ok = $sameDomain || $localDev || $capacitorApp;
}

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
