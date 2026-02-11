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
     * Запросить сброс пароля
     * POST /api_v2.php?action=request_password_reset
     */
    public function requestPasswordReset() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            $this->returnError('Метод не поддерживается', 405);
            return;
        }
        try {
            $data = $this->getJsonBody();
            $email = $data['email'] ?? null;
            if (!$email) {
                $this->returnError('Введите email', 400);
                return;
            }
            $result = $this->authService->requestPasswordReset($email);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            if ($this->isPasswordResetTableMissing($e)) {
                $this->returnError('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
                return;
            }
            $this->handleException($e);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'password_reset_tokens') !== false || stripos($msg, "doesn't exist") !== false) {
                $this->returnError('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
                return;
            }
            $this->returnError('Внутренняя ошибка сервера', 500);
        }
    }

    /**
     * Подтвердить сброс пароля по токену
     * POST /api_v2.php?action=confirm_password_reset
     */
    public function confirmPasswordReset() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            $this->returnError('Метод не поддерживается', 405);
            return;
        }
        try {
            $data = $this->getJsonBody();
            $token = $data['token'] ?? null;
            $newPassword = $data['new_password'] ?? null;
            if (!$token || !$newPassword) {
                $this->returnError('Токен и новый пароль обязательны', 400);
                return;
            }
            $result = $this->authService->confirmPasswordReset($token, $newPassword);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            if ($this->isPasswordResetTableMissing($e)) {
                $this->returnError('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
                return;
            }
            $this->handleException($e);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'password_reset_tokens') !== false || stripos($msg, "doesn't exist") !== false) {
                $this->returnError('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
                return;
            }
            $this->returnError('Внутренняя ошибка сервера', 500);
        }
    }

    /**
     * Проверить авторизацию
     * GET /api_v2.php?action=check_auth
     * Возвращает authenticated, user_id, username, auth_method и при успехе — name, avatar_path для шапки.
     */
    public function checkAuth() {
        try {
            $userId = null;
            $username = null;
            $authMethod = null;

            // Проверяем JWT токен
            $jwtUser = $this->authService->validateJwtToken();
            if ($jwtUser) {
                $userId = (int) $jwtUser['user_id'];
                $username = $jwtUser['username'];
                $authMethod = 'jwt';
            } else {
                require_once __DIR__ . '/../auth.php';
                if (isAuthenticated()) {
                    $userId = (int) getCurrentUserId();
                    $username = $_SESSION['username'] ?? null;
                    $authMethod = 'session';
                }
            }

            if ($userId && $username !== null) {
                $avatarPath = null;
                $role = 'user';
                $onboardingCompleted = 1;
                $row = null;
                $stmt = $this->db->prepare('SELECT avatar_path, role, COALESCE(onboarding_completed, 1) AS onboarding_completed, timezone FROM users WHERE id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        if (!empty($row['avatar_path'])) {
                            $avatarPath = $row['avatar_path'];
                        }
                        if (!empty($row['role'])) {
                            $role = $row['role'];
                        }
                        if (isset($row['onboarding_completed'])) {
                            $onboardingCompleted = (int) $row['onboarding_completed'];
                        }
                    }
                }
                $timezone = (isset($row) && !empty($row['timezone'])) ? $row['timezone'] : 'Europe/Moscow';
                $this->returnSuccess([
                    'authenticated' => true,
                    'user_id' => $userId,
                    'username' => $username,
                    'avatar_path' => $avatarPath,
                    'role' => $role,
                    'auth_method' => $authMethod,
                    'onboarding_completed' => $onboardingCompleted,
                    'timezone' => $timezone
                ]);
                return;
            }

            $this->returnSuccess(['authenticated' => false]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Проверка: исключение из-за отсутствия таблицы password_reset_tokens (или refresh_tokens).
     */
    protected function isPasswordResetTableMissing($e) {
        $msg = $e->getMessage();
        return (stripos($msg, 'password_reset_tokens') !== false || stripos($msg, "doesn't exist") !== false);
    }
}
