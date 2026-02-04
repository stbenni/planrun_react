<?php
/**
 * Структурированная система логирования
 * 
 * Поддерживает разные уровни логирования и ротацию файлов
 */

class Logger {
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    private static $logDir = __DIR__ . '/../logs';
    private static $enabledLevels = [
        self::LEVEL_DEBUG,
        self::LEVEL_INFO,
        self::LEVEL_WARNING,
        self::LEVEL_ERROR,
        self::LEVEL_CRITICAL
    ];
    
    /**
     * Инициализация логгера
     */
    public static function init($logDir = null, $enabledLevels = null) {
        if ($logDir !== null) {
            self::$logDir = $logDir;
        }
        if ($enabledLevels !== null) {
            self::$enabledLevels = $enabledLevels;
        }
        
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }
    
    /**
     * Логирование с уровнем
     */
    public static function log($level, $message, $context = []) {
        if (!in_array($level, self::$enabledLevels)) {
            return;
        }
        
        $logFile = self::$logDir . '/' . $level . '_' . date('Y-m-d') . '.log';
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => self::formatContext($context),
            'user_id' => self::getCurrentUserId(),
            'ip' => self::getClientIp(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        // Пытаемся записать в файл, но не падаем если нет прав
        @file_put_contents($logFile, $logLine, FILE_APPEND);
        
        // Также логируем в error_log для критичных ошибок
        if ($level === self::LEVEL_CRITICAL || $level === self::LEVEL_ERROR) {
            error_log("[$level] $message | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * Логирование отладочной информации
     */
    public static function debug($message, $context = []) {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Логирование информационных сообщений
     */
    public static function info($message, $context = []) {
        self::log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Логирование предупреждений
     */
    public static function warning($message, $context = []) {
        self::log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Логирование ошибок
     */
    public static function error($message, $context = []) {
        self::log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Логирование критичных ошибок
     */
    public static function critical($message, $context = []) {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    /**
     * Логирование исключений
     */
    public static function exception(Exception $e, $context = []) {
        self::error($e->getMessage(), array_merge($context, [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]));
    }
    
    /**
     * Форматирование контекста для логирования
     */
    private static function formatContext($context) {
        if (empty($context)) {
            return null;
        }
        
        // Убираем большие объекты из контекста
        $formatted = [];
        foreach ($context as $key => $value) {
            if (is_object($value) && !($value instanceof Exception)) {
                $formatted[$key] = get_class($value);
            } elseif (is_array($value) && count($value) > 100) {
                $formatted[$key] = '[Array with ' . count($value) . ' items]';
            } else {
                $formatted[$key] = $value;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Получить ID текущего пользователя
     */
    private static function getCurrentUserId() {
        if (function_exists('getCurrentUserId')) {
            return getCurrentUserId();
        }
        if (isset($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        return null;
    }
    
    /**
     * Получить IP клиента
     */
    private static function getClientIp() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return 'unknown';
    }
    
    /**
     * Очистка старых логов (ротация)
     */
    public static function rotate($daysToKeep = 30) {
        $files = glob(self::$logDir . '/*.log');
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
            }
        }
    }
}

// Инициализация при загрузке
Logger::init();

