<?php
/**
 * Сервис для работы с JWT токенами
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../config/env_loader.php';

class JwtService extends BaseService {
    
    private $secretKey;
    private $algorithm = 'HS256';
    private $expirationTime = 3600; // 1 час
    private $refreshExpirationTime = 604800; // 7 дней
    
    public function __construct($db) {
        parent::__construct($db);
        // Получаем секретный ключ из .env или используем дефолтный
        $this->secretKey = env('JWT_SECRET_KEY', 'your-secret-key-change-in-production-' . md5(__DIR__));
    }
    
    /**
     * Создать JWT токен
     * 
     * @param array $payload Данные для токена
     * @param int $expiration Время жизни в секундах
     * @return string JWT токен
     */
    public function createToken($payload, $expiration = null) {
        if ($expiration === null) {
            $expiration = $this->expirationTime;
        }
        
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ];
        
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiration;
        
        $base64UrlHeader = $this->base64UrlEncode(json_encode($header));
        $base64UrlPayload = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->secretKey, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    /**
     * Верифицировать JWT токен
     * 
     * @param string $token JWT токен
     * @return array|null Декодированные данные или null если невалидный
     */
    public function verifyToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
        
        // Проверяем подпись
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->secretKey, true);
        $base64UrlSignatureCheck = $this->base64UrlEncode($signature);
        
        if ($base64UrlSignature !== $base64UrlSignatureCheck) {
            return null;
        }
        
        // Декодируем payload
        $payload = json_decode($this->base64UrlDecode($base64UrlPayload), true);
        
        // Проверяем срок действия
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Создать access token
     * 
     * @param int $userId ID пользователя
     * @param string $username Имя пользователя
     * @return string Access token
     */
    public function createAccessToken($userId, $username) {
        return $this->createToken([
            'user_id' => $userId,
            'username' => $username,
            'type' => 'access'
        ], $this->expirationTime);
    }
    
    /**
     * Создать refresh token
     * 
     * @param int $userId ID пользователя
     * @return string Refresh token
     */
    public function createRefreshToken($userId) {
        $token = $this->createToken([
            'user_id' => $userId,
            'type' => 'refresh'
        ], $this->refreshExpirationTime);
        
        // Сохраняем refresh token в БД
        $this->saveRefreshToken($userId, $token);
        
        return $token;
    }
    
    /**
     * Сохранить refresh token в БД
     */
    private function saveRefreshToken($userId, $token) {
        $hashedToken = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + $this->refreshExpirationTime);
        
        // Удаляем старые токены пользователя
        $deleteStmt = $this->db->prepare("DELETE FROM refresh_tokens WHERE user_id = ?");
        $deleteStmt->bind_param("i", $userId);
        $deleteStmt->execute();
        $deleteStmt->close();
        
        // Сохраняем новый токен
        $insertStmt = $this->db->prepare("INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
        $insertStmt->bind_param("iss", $userId, $hashedToken, $expiresAt);
        $insertStmt->execute();
        $insertStmt->close();
    }
    
    /**
     * Проверить refresh token
     * 
     * @param string $token Refresh token
     * @return array|null Данные пользователя или null
     */
    public function verifyRefreshToken($token) {
        $payload = $this->verifyToken($token);
        if (!$payload || $payload['type'] !== 'refresh') {
            return null;
        }
        
        $hashedToken = hash('sha256', $token);
        $stmt = $this->db->prepare("SELECT user_id FROM refresh_tokens WHERE token_hash = ? AND expires_at > NOW()");
        $stmt->bind_param("s", $hashedToken);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if (!$row) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Обновить access token используя refresh token
     * 
     * @param string $refreshToken Refresh token
     * @return array|null Новые токены или null
     */
    public function refreshAccessToken($refreshToken) {
        $payload = $this->verifyRefreshToken($refreshToken);
        if (!$payload) {
            return null;
        }
        
        $userId = $payload['user_id'];
        
        // Получаем username
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            return null;
        }
        
        // Создаем новые токены
        $accessToken = $this->createAccessToken($userId, $user['username']);
        $newRefreshToken = $this->createRefreshToken($userId);
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => $this->expirationTime
        ];
    }
    
    /**
     * Удалить refresh token
     * 
     * @param string $token Refresh token
     * @return bool
     */
    public function revokeRefreshToken($token) {
        $hashedToken = hash('sha256', $token);
        $stmt = $this->db->prepare("DELETE FROM refresh_tokens WHERE token_hash = ?");
        $stmt->bind_param("s", $hashedToken);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    
    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
