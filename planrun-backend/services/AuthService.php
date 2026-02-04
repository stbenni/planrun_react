<?php
/**
 * Сервис для аутентификации
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/JwtService.php';

class AuthService extends BaseService {
    
    protected $jwtService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->jwtService = new JwtService($db);
    }
    
    /**
     * Авторизация пользователя
     * 
     * @param string $username Имя пользователя
     * @param string $password Пароль
     * @param bool $useJwt Использовать JWT токены
     * @return array Результат авторизации
     * @throws Exception
     */
    public function login($username, $password, $useJwt = false) {
        try {
            // Используем существующую функцию login
            $result = login($username, $password);
            
            if (!$result) {
                $this->throwException('Неверное имя пользователя или пароль', 401);
            }
            
            $userId = $_SESSION['user_id'];
            $username = $_SESSION['username'];
            
            $response = [
                'success' => true,
                'user_id' => $userId,
                'username' => $username
            ];
            
            // Если требуется JWT, создаем токены
            if ($useJwt) {
                $accessToken = $this->jwtService->createAccessToken($userId, $username);
                $refreshToken = $this->jwtService->createRefreshToken($userId);
                
                $response['access_token'] = $accessToken;
                $response['refresh_token'] = $refreshToken;
                $response['expires_in'] = 3600; // 1 час
            }
            
            return $response;
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка авторизации: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Выход из системы
     * 
     * @param string|null $refreshToken Refresh token для отзыва (опционально)
     * @return array
     */
    public function logout($refreshToken = null) {
        // Отзываем refresh token если передан
        if ($refreshToken) {
            $this->jwtService->revokeRefreshToken($refreshToken);
        }
        
        // Выходим из сессии
        logout();
        
        return ['success' => true];
    }
    
    /**
     * Обновить access token
     * 
     * @param string $refreshToken Refresh token
     * @return array Новые токены
     * @throws Exception
     */
    public function refreshToken($refreshToken) {
        try {
            $tokens = $this->jwtService->refreshAccessToken($refreshToken);
            
            if (!$tokens) {
                $this->throwException('Невалидный refresh token', 401);
            }
            
            return $tokens;
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка обновления токена: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Проверить JWT токен из заголовка
     * 
     * @return array|null Данные пользователя или null
     */
    public function validateJwtToken() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        
        if (!$authHeader) {
            return null;
        }
        
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }
        
        $token = $matches[1];
        $payload = $this->jwtService->verifyToken($token);
        
        if (!$payload || $payload['type'] !== 'access') {
            return null;
        }
        
        return [
            'user_id' => $payload['user_id'],
            'username' => $payload['username']
        ];
    }
}
