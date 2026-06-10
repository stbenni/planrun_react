<?php
/**
 * Выделенный OAuth-callback для Suunto — БЕЗ query-параметра в URL.
 *
 * Suunto не регистрирует redirect_uri, содержащий query string (?provider=suunto),
 * поэтому для него отдельный endpoint, а провайдер проставляется здесь. Вся логика
 * (подписанный state, сессия, обмен кода на токены, redirect) переиспользуется
 * из общего oauth_callback.php.
 *
 * Зарегистрируйте в OAuth-приложении Suunto Redirect URI:
 *   https://planrun.ru/api/suunto_oauth_callback.php
 * и укажите ТОТ ЖЕ URL в .env как SUUNTO_REDIRECT_URI (должны совпадать байт-в-байт).
 */
$_GET['provider'] = 'suunto';
require __DIR__ . '/oauth_callback.php';
