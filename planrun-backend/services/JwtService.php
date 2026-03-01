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
    private $refreshExpirationTime;
    private $refreshSlidingSeconds;
    
    public function __construct($db) {
        parent::__construct($db);
        // Получаем секретный ключ из .env или используем дефолтный
        $this->secretKey = env('JWT_SECRET_KEY', 'your-secret-key-change-in-production-' . md5(__DIR__));
        // Базовый срок жизни refresh при создании (логин): по умолчанию 10 лет
        $initialDays = (int) env('JWT_REFRESH_INITIAL_DAYS', 3650);
        $this->refreshExpirationTime = $initialDays * 86400;
        // Sliding: продление при каждом refresh (по умолчанию 365 дней)
        $slidingDays = (int) env('JWT_REFRESH_SLIDING_DAYS', 365);
        $maxAgeDays = (int) env('JWT_REFRESH_MAX_AGE_DAYS', 3650);
        $this->refreshSlidingSeconds = min($slidingDays, $maxAgeDays) * 86400;
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
    
    private const MAX_REFRESH_TOKENS_PER_USER = 5;
    private const ROTATION_GRACE_SECONDS = 300; // 5 min — old token stays valid for client to persist new one

    /**
     * Создать refresh token
     *
     * @param int $userId ID пользователя
     * @param string|null $deviceId Идентификатор устройства (опционально)
     * @param int|null $expirationSeconds Срок жизни в секундах (null = базовый refreshExpirationTime)
     * @return string Refresh token
     */
    public function createRefreshToken($userId, $deviceId = null, $expirationSeconds = null) {
        $expiration = $expirationSeconds ?? $this->refreshExpirationTime;
        $token = $this->createToken([
            'user_id' => $userId,
            'type' => 'refresh',
            'device_id' => $deviceId
        ], $expiration);

        $this->saveRefreshToken($userId, $token, $deviceId, $expiration);
        return $token;
    }

    /**
     * Сохранить refresh token в БД.
     * Поддержка нескольких токенов на пользователя (по device_id).
     * При наличии device_id — сокращает TTL старого токена до ROTATION_GRACE_SECONDS (grace period),
     * чтобы клиент успел сохранить новый. Иначе добавляет новый.
     * Ограничение: до MAX_REFRESH_TOKENS_PER_USER на пользователя.
     *
     * @param int|null $expirationSeconds Срок жизни в секундах (null = refreshExpirationTime)
     */
    private function saveRefreshToken($userId, $token, $deviceId = null, $expirationSeconds = null) {
        $hashedToken = hash('sha256', $token);
        $expiration = $expirationSeconds ?? $this->refreshExpirationTime;
        $expiresAt = date('Y-m-d H:i:s', time() + $expiration);
        $deviceIdVal = $deviceId !== null && $deviceId !== '' ? $deviceId : null;

        $graceExpiresAt = date('Y-m-d H:i:s', time() + self::ROTATION_GRACE_SECONDS);
        if ($deviceIdVal !== null) {
            $stmt = $this->db->prepare("UPDATE refresh_tokens SET expires_at = LEAST(expires_at, ?) WHERE user_id = ? AND device_id = ?");
            $stmt->bind_param("sis", $graceExpiresAt, $userId, $deviceIdVal);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->db->prepare("UPDATE refresh_tokens SET expires_at = LEAST(expires_at, ?) WHERE user_id = ? AND device_id IS NULL");
            $stmt->bind_param("si", $graceExpiresAt, $userId);
            $stmt->execute();
            $stmt->close();
        }

        $cols = "user_id, token_hash, expires_at";
        $placeholders = "?, ?, ?";
        $params = [$userId, $hashedToken, $expiresAt];
        $types = "iss";

        $hasDeviceId = $this->db->query("SHOW COLUMNS FROM refresh_tokens LIKE 'device_id'");
        if ($hasDeviceId && $hasDeviceId->num_rows > 0) {
            $cols .= ", device_id";
            $placeholders .= ", ?";
            $params[] = $deviceIdVal;
            $types .= "s";
        }

        $insertStmt = $this->db->prepare("INSERT INTO refresh_tokens ($cols) VALUES ($placeholders)");
        $insertStmt->bind_param($types, ...$params);
        $insertStmt->execute();
        $insertStmt->close();

        $countStmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM refresh_tokens WHERE user_id = ?");
        $countStmt->bind_param("i", $userId);
        $countStmt->execute();
        $row = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();
        $cnt = (int) ($row['cnt'] ?? 0);

        if ($cnt > self::MAX_REFRESH_TOKENS_PER_USER) {
            $limit = self::MAX_REFRESH_TOKENS_PER_USER;
            $idsStmt = $this->db->prepare("SELECT id FROM refresh_tokens WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
            $idsStmt->bind_param("ii", $userId, $limit);
            $idsStmt->execute();
            $ids = [];
            foreach ($idsStmt->get_result() as $r) {
                $ids[] = (int) $r['id'];
            }
            $idsStmt->close();
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $deleteOldStmt = $this->db->prepare("DELETE FROM refresh_tokens WHERE user_id = ? AND id NOT IN ($placeholders)");
                $types = 'i' . str_repeat('i', count($ids));
                $deleteOldStmt->bind_param($types, $userId, ...$ids);
                $deleteOldStmt->execute();
                $deleteOldStmt->close();
            }
        }

        $cleanupStmt = $this->db->prepare("DELETE FROM refresh_tokens WHERE user_id = ? AND expires_at < NOW()");
        $cleanupStmt->bind_param("i", $userId);
        $cleanupStmt->execute();
        $cleanupStmt->close();
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
     * @param string|null $deviceId Идентификатор устройства (из тела запроса или payload)
     * @return array|null Новые токены или null
     */
    public function refreshAccessToken($refreshToken, $deviceId = null) {
        $payload = $this->verifyRefreshToken($refreshToken);
        if (!$payload) {
            return null;
        }

        $userId = $payload['user_id'];
        $deviceIdToUse = $deviceId ?? ($payload['device_id'] ?? null);

        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return null;
        }

        $accessToken = $this->createAccessToken($userId, $user['username']);
        // Sliding expiration: новый refresh получает продление на sliding_days при каждом использовании
        $newRefreshToken = $this->createRefreshToken($userId, $deviceIdToUse, $this->refreshSlidingSeconds);

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
