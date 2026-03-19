<?php
/**
 * Bootstrap для PHPUnit тестов
 */

// Загружаем переменные окружения из тестового .env
$_ENV['APP_ENV'] = 'testing';

// Используем локальное хранилище сессий, чтобы CLI-тесты не зависели
// от системного session.save_path с ограниченными правами.
$sessionDir = sys_get_temp_dir() . '/planrun-phpunit-sessions';
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
    throw new RuntimeException('Не удалось создать директорию для тестовых сессий: ' . $sessionDir);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

// Загружаем env_loader
require_once __DIR__ . '/../config/env_loader.php';

// Загружаем основные файлы
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../auth.php';
