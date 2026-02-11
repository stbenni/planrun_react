<?php
/**
 * Обёртка API завершения специализации (для React)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/session_init.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

define('API_CORS_SENT', true);
require_once __DIR__ . '/../planrun-backend/complete_specialization_api.php';
