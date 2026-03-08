<?php
/**
 * API обертка для регистрации (для React приложения)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/session_init.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../planrun-backend/register_api.php';
