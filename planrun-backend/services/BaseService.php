<?php
/**
 * Базовый класс для всех сервисов
 * Содержит общую логику для работы с БД и ошибками
 */

require_once __DIR__ . '/../config/Logger.php';

abstract class BaseService {
    protected $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Логирование ошибок
     */
    protected function logError($message, $context = []) {
        Logger::error($message, $context);
    }
    
    /**
     * Логирование информации
     */
    protected function logInfo($message, $context = []) {
        Logger::info($message, $context);
    }
    
    /**
     * Логирование отладки
     */
    protected function logDebug($message, $context = []) {
        Logger::debug($message, $context);
    }
    
    /**
     * Выбросить исключение с логированием
     */
    protected function throwException($message, $code = 500, $context = []) {
        require_once __DIR__ . '/../exceptions/AppException.php';
        $this->logError($message, $context);
        throw new AppException($message, $code, null, $context);
    }
    
    /**
     * Выбросить исключение валидации
     */
    protected function throwValidationException($message, $validationErrors = []) {
        require_once __DIR__ . '/../exceptions/ValidationException.php';
        $this->logError($message, ['validation_errors' => $validationErrors]);
        throw new ValidationException($message, $validationErrors);
    }
    
    /**
     * Выбросить исключение "не найдено"
     */
    protected function throwNotFoundException($message = "Ресурс не найден", $context = []) {
        require_once __DIR__ . '/../exceptions/NotFoundException.php';
        throw new NotFoundException($message, 404, null, $context);
    }
    
    /**
     * Выбросить исключение "не авторизован"
     */
    protected function throwUnauthorizedException($message = "Требуется авторизация", $context = []) {
        require_once __DIR__ . '/../exceptions/UnauthorizedException.php';
        throw new UnauthorizedException($message, 401, null, $context);
    }
    
    /**
     * Выбросить исключение "доступ запрещен"
     */
    protected function throwForbiddenException($message = "Доступ запрещен", $context = []) {
        require_once __DIR__ . '/../exceptions/ForbiddenException.php';
        throw new ForbiddenException($message, 403, null, $context);
    }
}
