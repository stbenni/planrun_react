<?php
/**
 * Общая инициализация сессии для api/*.php
 * Использует каталог api/sessions (доступный для записи веб-серверу),
 * чтобы избежать 500 при Permission denied в /var/lib/php/sessions.
 */
if (session_status() === PHP_SESSION_NONE) {
    $sessionDir = __DIR__ . '/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0770, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }
}
