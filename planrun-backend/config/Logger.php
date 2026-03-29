<?php
/**
 * Структурированная система логирования
 *
 * Поддерживает разные уровни логирования и ротацию файлов
 */

require_once __DIR__ . '/env_loader.php';

class Logger {
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';

    private static $logDir = __DIR__ . '/../logs';
    private static $enabledLevels = [];
    private static $isInitialized = false;

    /**
     * Инициализация логгера
     */
    public static function init($logDir = null, $enabledLevels = null) {
        if ($logDir !== null) {
            self::$logDir = $logDir;
        }

        if ($enabledLevels !== null) {
            self::$enabledLevels = self::normalizeLevels($enabledLevels);
        } elseif (empty(self::$enabledLevels)) {
            self::$enabledLevels = self::resolveEnabledLevels();
        }

        self::$isInitialized = true;

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    /**
     * Логирование с уровнем
     */
    public static function log($level, $message, $context = []) {
        self::ensureInitialized();

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
     * Проверяет, что логгер проинициализирован.
     */
    private static function ensureInitialized() {
        if (!self::$isInitialized) {
            self::init();
        }
    }

    /**
     * Возвращает все поддерживаемые уровни логирования.
     */
    private static function allLevels() {
        return [
            self::LEVEL_DEBUG,
            self::LEVEL_INFO,
            self::LEVEL_WARNING,
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL
        ];
    }

    /**
     * Разрешённые уровни с учётом env-конфига.
     */
    private static function resolveEnabledLevels() {
        $configured = trim((string) env('LOG_LEVELS', env('APP_LOG_LEVELS', '')));
        if ($configured !== '') {
            return self::normalizeLevels($configured);
        }

        $appEnv = strtolower((string) env('APP_ENV', 'production'));
        if (in_array($appEnv, ['production', 'prod'], true)) {
            return [
                self::LEVEL_INFO,
                self::LEVEL_WARNING,
                self::LEVEL_ERROR,
                self::LEVEL_CRITICAL
            ];
        }

        return self::allLevels();
    }

    /**
     * Нормализует список уровней логирования.
     */
    private static function normalizeLevels($levels) {
        if (!is_array($levels)) {
            $levels = preg_split('/[\s,]+/', strtolower((string) $levels), -1, PREG_SPLIT_NO_EMPTY);
        }

        if ($levels === false) {
            $levels = [];
        }

        $allowed = array_flip(self::allLevels());
        $normalized = [];

        foreach ($levels as $level) {
            $level = strtolower(trim((string) $level));
            if ($level === '' || !isset($allowed[$level])) {
                continue;
            }
            $normalized[] = $level;
        }

        if (!empty($normalized)) {
            return array_values(array_unique($normalized));
        }

        $appEnv = strtolower((string) env('APP_ENV', 'production'));
        if (in_array($appEnv, ['production', 'prod'], true)) {
            return [
                self::LEVEL_INFO,
                self::LEVEL_WARNING,
                self::LEVEL_ERROR,
                self::LEVEL_CRITICAL
            ];
        }

        return self::allLevels();
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
