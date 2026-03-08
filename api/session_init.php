<?php
/**
 * Общая инициализация сессии для api/*.php
 * Использует каталог api/sessions (доступный для записи веб-серверу),
 * чтобы избежать 500 при Permission denied в /var/lib/php/sessions.
 *
 * Время жизни сессии: 30 дней (как «запомнить меня» в Facebook).
 * По умолчанию PHP: gc_maxlifetime=24 мин, cookie_lifetime=0 (до закрытия браузера).
 *
 * Cookie: HTTPS (prod) — SameSite=None, Secure=1. HTTP (localhost dev) — Lax, Secure=0.
 */
if (session_status() === PHP_SESSION_NONE) {
    $sessionDir = __DIR__ . '/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0770, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }
    // 30 дней — пользователь остаётся залогиненным при повторных визитах
    $thirtyDays = 30 * 24 * 60 * 60;
    ini_set('session.gc_maxlifetime', (string) $thirtyDays);
    ini_set('session.cookie_lifetime', (string) $thirtyDays);

    // HTTPS: SameSite=None + Secure. HTTP (localhost): Lax + Secure=0 (иначе cookie не работает)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isHttps) {
        ini_set('session.cookie_samesite', 'None');
        ini_set('session.cookie_secure', '1');
    } else {
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', '0');
    }
    ini_set('session.cookie_httponly', '1');
}
