<?php
/**
 * Контроллер для аутентификации
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/AuthService.php';

class AuthController extends BaseController {
    
    protected $authService;
    
    public function __construct($db) {
        $this->db = $db;
        // Для AuthController не нужна проверка доступа к календарю
        // Инициализируем только базовые настройки
        require_once __DIR__ . '/../auth.php';
        require_once __DIR__ . '/../services/AuthService.php';
        $this->authService = new AuthService($db);
        
        // Вызываем переопределенный initializeAccess() который не проверяет календарь
        $this->initializeAccess();
    }
    
    // Переопределяем initializeAccess чтобы не проверять календарь
    protected function initializeAccess() {
        // Для AuthController не нужна проверка доступа к календарю
        // Просто инициализируем базовые значения
        require_once __DIR__ . '/../auth.php';
        require_once __DIR__ . '/../services/AuthService.php';
        
        // Проверяем JWT токен (если есть)
        $authService = new AuthService($this->db);
        $jwtUser = $authService->validateJwtToken();
        
        if ($jwtUser) {
            $_SESSION['authenticated'] = true;
            $_SESSION['user_id'] = $jwtUser['user_id'];
            $_SESSION['username'] = $jwtUser['username'];
        }
        
        $this->currentUserId = isAuthenticated() ? getCurrentUserId() : null;
        $this->calendarUserId = $this->currentUserId;
        $this->canEdit = false;
        $this->canView = false;
        $this->isOwner = false;
    }
    
    /**
     * Авторизация пользователя
     * POST /api.php?action=login
     */
    public function login() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            $this->returnError('Метод не поддерживается', 405);
            return;
        }
        
        try {
            $data = $this->getJsonBody();
            $username = $data['username'] ?? null;
            $password = $data['password'] ?? null;
            $useJwt = $data['use_jwt'] ?? false; // Для мобильных приложений
            
            if (!$username || !$password) {
                $this->returnError('Имя пользователя и пароль обязательны', 400);
                return;
            }
            
            $result = $this->authService->login($username, $password, $useJwt);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Выход из системы
     * POST /api_v2.php?action=logout
     */
    public function logout() {
        try {
            $data = $this->getJsonBody();
            $refreshToken = $data['refresh_token'] ?? null;
            
            $result = $this->authService->logout($refreshToken);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Обновить access token
     * POST /api_v2.php?action=refresh_token
     */
    public function refreshToken() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            $this->returnError('Метод не поддерживается', 405);
            return;
        }
        
        try {
            $data = $this->getJsonBody();
            $refreshToken = $data['refresh_token'] ?? null;
            
            if (!$refreshToken) {
                $this->returnError('Refresh token обязателен', 400);
                return;
            }
            
            $result = $this->authService->refreshToken($refreshToken);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Проверить авторизацию
     * GET /api_v2.php?action=check_auth
     */
    public function checkAuth() {
        try {
            // Проверяем JWT токен
            $jwtUser = $this->authService->validateJwtToken();
            
            if ($jwtUser) {
                $this->returnSuccess([
                    'authenticated' => true,
                    'user_id' => $jwtUser['user_id'],
                    'username' => $jwtUser['username'],
                    'auth_method' => 'jwt'
                ]);
                return;
            }
            
            // Проверяем сессию
            require_once __DIR__ . '/../auth.php';
            $isAuthenticated = isAuthenticated();
            
            if ($isAuthenticated) {
                $this->returnSuccess([
                    'authenticated' => true,
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'username' => $_SESSION['username'] ?? null,
                    'auth_method' => 'session'
                ]);
            } else {
                $this->returnSuccess([
                    'authenticated' => false
                ]);
            }
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
