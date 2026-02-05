<?php
/**
 * Загрузчик переменных окружения из .env файла
 * 
 * Безопасная загрузка конфигурации без внешних зависимостей.
 * Можно подключать несколько раз (require_once из разных путей) — функции объявляются один раз.
 */

if (!function_exists('loadEnv')) {
/**
 * Загрузить переменные окружения из .env файла
 * 
 * @param string $envPath Путь к .env файлу
 * @return void
 */
function loadEnv($envPath = null) {
    if ($envPath === null) {
        $envPath = __DIR__ . '/../.env';
    }
    
    if (!file_exists($envPath)) {
        return;
    }
    
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            if (!isset($_ENV[$key]) && getenv($key) === false) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}
}

if (!function_exists('env')) {
/**
 * Получить значение переменной окружения с fallback
 */
function env($key, $default = null) {
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $default;
}
}

if (!defined('ENV_LOADER_LOADED')) {
    define('ENV_LOADER_LOADED', true);
    loadEnv();
}
