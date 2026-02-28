<?php
/**
 * Базовый контроллер для API
 * 
 * Содержит общую логику для всех контроллеров
 */

class BaseController {
    protected $db;
    protected $currentUserId;
    protected $calendarUserId;
    protected $canEdit;
    protected $canView;
    protected $isOwner;
    
    public function __construct($db) {
        $this->db = $db;
        $this->initializeAccess();
    }
    
    /**
     * Инициализация прав доступа
     */
    protected function initializeAccess() {
        require_once __DIR__ . '/../auth.php';
        require_once __DIR__ . '/../calendar_access.php';
        require_once __DIR__ . '/../services/AuthService.php';
        
        // Проверяем JWT токен сначала
        $authService = new AuthService($this->db);
        $jwtUser = $authService->validateJwtToken();
        
        if ($jwtUser) {
            // Устанавливаем сессию из JWT для обратной совместимости
            $_SESSION['authenticated'] = true;
            $_SESSION['user_id'] = $jwtUser['user_id'];
            $_SESSION['username'] = $jwtUser['username'];
        }
        
        $isAuthenticated = isAuthenticated();
        
        // Определяем права доступа к календарю
        $access = getCalendarAccess();
        
        if (isset($access['error'])) {
            $this->returnError($access['error'], 403);
            return;
        }
        
        $this->calendarUserId = $access['user_id'];
        $this->canEdit = $access['can_edit'];
        $this->canView = $access['can_view'];
        $this->isOwner = $access['is_owner'] ?? false;
        $this->currentUserId = $isAuthenticated ? getCurrentUserId() : $this->calendarUserId;
    }
    
    /**
     * Проверка авторизации для операций записи
     */
    protected function requireAuth() {
        if (!isAuthenticated()) {
            $this->returnError('Требуется авторизация для этого действия', 401);
            return false;
        }
        return true;
    }
    
    /**
     * Проверка прав на редактирование
     */
    protected function requireEdit() {
        if (!$this->canEdit) {
            $this->returnError('Нет прав на редактирование этого календаря', 403);
            return false;
        }
        return true;
    }
    
    /**
     * Проверка CSRF токена.
     * Пропускаем при JWT-авторизации (Capacitor/native): cookies не отправляются, сессия не сохраняется между запросами.
     */
    protected function checkCsrfToken() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+\S+/', $authHeader)) {
            return true;
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;
        
        // Если токен не найден, пытаемся извлечь из JSON body
        if ($csrfToken === null) {
            $rawInput = file_get_contents('php://input');
            $jsonData = json_decode($rawInput, true);
            if ($jsonData !== null && isset($jsonData['csrf_token'])) {
                $csrfToken = $jsonData['csrf_token'];
            }
        }
        
        if ($csrfToken === null || $csrfToken !== $_SESSION['csrf_token']) {
            $this->returnError('Неверный CSRF токен. Обновите страницу и попробуйте снова.', 403);
            return false;
        }
        
        return true;
    }
    
    /**
     * Возврат успешного ответа
     */
    protected function returnSuccess($data = null, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = ['success' => true];
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Возврат ошибки
     */
    protected function returnError($message, $code = 400, $details = null) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = [
            'success' => false,
            'error' => $message
        ];
        
        if ($details !== null) {
            $response['details'] = $details;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Обработка исключений
     */
    protected function handleException(Exception $e) {
        require_once __DIR__ . '/../config/Logger.php';
        require_once __DIR__ . '/../exceptions/AppException.php';
        require_once __DIR__ . '/../exceptions/ValidationException.php';
        
        // Если это наше исключение приложения
        if ($e instanceof AppException) {
            Logger::error($e->getMessage(), $e->getContext());
            
            // Для ValidationException возвращаем детали валидации
            if ($e instanceof ValidationException) {
                $this->returnError($e->getMessage(), $e->getStatusCode(), [
                    'validation_errors' => $e->getValidationErrors()
                ]);
            } else {
                $this->returnError($e->getMessage(), $e->getStatusCode(), $e->getContext());
            }
        } else {
            // Обычное исключение
            $message = $e->getMessage();
            Logger::error('Необработанное исключение', [
                'message' => $message,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            // Отсутствие таблиц сброса пароля / JWT
            if (stripos($message, 'password_reset_tokens') !== false
                || stripos($message, 'refresh_tokens') !== false
                || stripos($message, "doesn't exist") !== false) {
                $this->returnError('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
                return;
            }
            // В продакшене не показываем детали ошибки
            $displayMessage = 'Внутренняя ошибка сервера';
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $displayMessage = $message;
            }
            $this->returnError($displayMessage, 500);
        }
    }
    
    /**
     * Получить параметр из GET или POST
     */
    protected function getParam($key, $default = null) {
        return $_GET[$key] ?? $_POST[$key] ?? $default;
    }
    
    /**
     * Получить JSON body
     */
    protected function getJsonBody() {
        $rawInput = file_get_contents('php://input');
        return json_decode($rawInput, true);
    }
}
