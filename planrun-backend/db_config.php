<?php
/**
 * Конфигурация подключения к базе данных
 * 
 * Используется для подключения к MySQL базе данных sv
 * 
 * ВАЖНО: Конфигурация загружается из .env файла для безопасности.
 * Создайте .env файл на основе .env.example
 */

// Загружаем переменные окружения
require_once __DIR__ . '/config/env_loader.php';

// Параметры подключения к БД из .env или значения по умолчанию
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'sv'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASSWORD', '')); // Используем DB_PASSWORD из .env
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

/**
 * Получить подключение к БД
 */
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($conn->connect_error) {
                error_log("Ошибка подключения к БД: " . $conn->connect_error);
                return null;
            }
            
            $conn->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            error_log("Ошибка подключения к БД: " . $e->getMessage());
            return null;
        }
    }
    
    return $conn;
}

