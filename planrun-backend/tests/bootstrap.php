<?php
/**
 * Bootstrap для PHPUnit тестов
 */

// Загружаем переменные окружения из тестового .env
$_ENV['APP_ENV'] = 'testing';

// Загружаем env_loader
require_once __DIR__ . '/../config/env_loader.php';

// Загружаем основные файлы
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../auth.php';
