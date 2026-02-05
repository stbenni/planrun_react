<?php
/**
 * Единый обработчик ошибок для проекта PlanRun
 * 
 * Интегрирован с Logger для структурированного логирования
 */

require_once __DIR__ . '/Logger.php';

class ErrorHandler {
    /**
     * Логирование ошибки
     * 
     * @param string $message Сообщение об ошибке
     * @param array $context Дополнительный контекст
     * @param string $level Уровень ошибки (error, warning, info)
     */
    public static function log($message, $context = [], $level = 'error') {
        // Используем Logger для структурированного логирования
        switch ($level) {
            case 'warning':
                Logger::warning($message, $context);
                break;
            case 'info':
                Logger::info($message, $context);
                break;
            case 'error':
            default:
                Logger::error($message, $context);
                break;
        }
    }
    
    /**
     * Логирование ошибки с полным контекстом
     */
    public static function logError($message, $context = []) {
        self::log($message, $context, 'error');
    }
    
    /**
     * Логирование предупреждения
     */
    public static function logWarning($message, $context = []) {
        self::log($message, $context, 'warning');
    }
    
    /**
     * Логирование информации
     */
    public static function logInfo($message, $context = []) {
        self::log($message, $context, 'info');
    }
    
    /**
     * Вернуть JSON ошибку и завершить выполнение
     * 
     * @param string $message Сообщение об ошибке
     * @param int $code HTTP код ответа
     * @param array $additionalData Дополнительные данные для ответа
     */
    public static function returnJsonError($message, $code = 500, $additionalData = []) {
        http_response_code($code);
        
        $response = array_merge([
            'error' => $message,
            'success' => false
        ], $additionalData);
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Вернуть JSON успех
     * 
     * @param mixed $data Данные для ответа
     * @param int $code HTTP код ответа
     * @param array $additionalData Дополнительные данные
     */
    public static function returnJsonSuccess($data = null, $code = 200, $additionalData = []) {
        http_response_code($code);
        
        $response = array_merge([
            'success' => true
        ], $additionalData);
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Обработка исключений с логированием и возвратом JSON
     * @param \Throwable $e Исключение или ошибка (в т.ч. mysqli_sql_exception, Error)
     */
    public static function handleException($e, $returnJson = true) {
        $message = $e->getMessage();
        $context = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
        
        if ($e instanceof Exception) {
            Logger::exception($e, $context);
        } else {
            Logger::error('Unhandled ' . get_class($e) . ': ' . $message, $context);
        }
        
        if ($returnJson) {
            // Отсутствие таблиц сброса пароля / JWT — возвращаем 503 с понятным текстом
            if (stripos($message, 'password_reset_tokens') !== false
                || stripos($message, 'refresh_tokens') !== false
                || stripos($message, "doesn't exist") !== false) {
                self::returnJsonError('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
                return;
            }
            // В продакшене не показываем детали ошибки
            $showDetails = (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost') ||
                          (isset($_GET['debug']) && $_GET['debug'] === '1');
            
            $errorMessage = $showDetails ? $message : 'Произошла внутренняя ошибка сервера';
            self::returnJsonError($errorMessage, 500);
        }
    }
    
    /**
     * Регистрация глобальных обработчиков ошибок
     */
    public static function register() {
        set_error_handler(function($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            
            // Защита от рекурсии - если Logger падает, не логируем через него
            try {
                $level = 'error';
                if ($severity === E_WARNING || $severity === E_USER_WARNING) {
                    $level = 'warning';
                } elseif ($severity === E_NOTICE || $severity === E_USER_NOTICE) {
                    $level = 'info';
                }
                
                Logger::log($level, $message, [
                    'file' => $file,
                    'line' => $line,
                    'severity' => $severity
                ]);
            } catch (Exception $e) {
                // Если Logger не работает, используем стандартный error_log
                @error_log("ErrorHandler: " . $message . " in " . $file . " on line " . $line);
            }
            
            return true;
        });
        
        set_exception_handler(function($e) {
            try {
                ErrorHandler::handleException($e, true);
            } catch (\Throwable $fallback) {
                // Если обработка исключения сама вызвала исключение, используем стандартный вывод
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Internal Server Error']);
                exit;
            }
        });
        
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                try {
                    Logger::critical($error['message'], [
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'type' => $error['type']
                    ]);
                } catch (Exception $e) {
                    @error_log("Fatal error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
                }
            }
        });
    }
    
    /**
     * Валидация и нормализация ENUM значений
     */
    public static function validateEnum($value, $validValues, $defaultValue, $fieldName = 'field') {
        $value = is_string($value) ? trim($value) : $value;
        
        if (empty($value) || !in_array($value, $validValues, true)) {
            self::logWarning("Invalid $fieldName value: " . var_export($value, true) . ", using default: $defaultValue");
            return $defaultValue;
        }
        
        return $value;
    }
}


