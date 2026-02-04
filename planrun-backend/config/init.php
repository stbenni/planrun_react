<?php
/**
 * Инициализация всех систем проекта
 * 
 * Подключайте этот файл в начале каждого скрипта для автоматической инициализации:
 * require_once __DIR__ . '/config/init.php';
 */

// Подключаем все необходимые системы
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/../cache_config.php';

// Регистрируем глобальные обработчики ошибок
ErrorHandler::register();

// Инициализируем Logger
Logger::init();

// Настройка логирования ошибок PHP
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

