<?php
/**
 * API обертка для регистрации (для React приложения)
 */

require_once __DIR__ . '/cors.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

require_once __DIR__ . '/../planrun-backend/register_api.php';
