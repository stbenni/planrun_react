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
     * @param string|null $deviceId Идентификатор устройства (опционально)
     * @return array Результат авторизации
     * @throws Exception
     */
    public function login($username, $password, $useJwt = false, $deviceId = null) {
        try {
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

            if ($useJwt) {
                $accessToken = $this->jwtService->createAccessToken($userId, $username);
                $refreshToken = $this->jwtService->createRefreshToken($userId, $deviceId);

                $response['access_token'] = $accessToken;
                $response['refresh_token'] = $refreshToken;
                $response['expires_in'] = 3600;
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
     * @param string|null $deviceId Идентификатор устройства (опционально)
     * @return array Новые токены
     * @throws Exception
     */
    public function refreshToken($refreshToken, $deviceId = null) {
        try {
            $tokens = $this->jwtService->refreshAccessToken($refreshToken, $deviceId);
            
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
     * Запросить сброс пароля (создать токен и отправить письмо)
     * POST /api_v2.php?action=request_password_reset
     * 
     * @param string $emailOrUsername Email или логин пользователя
     * @return array { sent, message? } — sent=true если письмо отправлено, message — подсказка для фронта
     */
    public function requestPasswordReset($emailOrUsername) {
        $input = trim($emailOrUsername);
        if (empty($input)) {
            $this->throwException('Введите email или логин', 400);
        }

        $user = null;
        $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);
        if ($isEmail) {
            $stmt = $this->db->prepare('SELECT id, username, email FROM users WHERE email = ? AND email IS NOT NULL AND email != ""');
            $stmt->bind_param('s', $input);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $stmt = $this->db->prepare('SELECT id, username, email FROM users WHERE username = ?');
            $stmt->bind_param('s', $input);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$user) {
            $this->logInfo('Запрос сброса пароля: пользователь не найден в БД', []);
            return ['sent' => false, 'message' => 'Пользователь с таким email или логином не найден. Укажите данные, указанные при регистрации.'];
        }
        if (empty($user['email']) || trim($user['email']) === '') {
            $this->logInfo('Запрос сброса пароля: у пользователя не указан email', ['user_id' => $user['id']]);
            return ['sent' => false, 'message' => 'У этого аккаунта не указан email. Обратитесь в поддержку для восстановления доступа.'];
        }

        $this->ensurePasswordResetTable();

        try {
            $stmt = $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
            if (!$stmt) {
                $this->logError('password_reset_tokens table missing or inaccessible', ['error' => $this->db->error]);
                $this->throwException('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
            }
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $stmt->close();

            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            $stmt = $this->db->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
            if (!$stmt) {
                $this->logError('password_reset_tokens INSERT prepare failed', ['error' => $this->db->error]);
                $this->throwException('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
            }
            $stmt->bind_param('iss', $user['id'], $token, $expiresAt);
            if (!$stmt->execute()) {
                $stmt->close();
                $this->throwException('Не удалось создать токен сброса', 500);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'password_reset_tokens') !== false || stripos($msg, "doesn't exist") !== false) {
                $this->logError('password_reset_tokens error', ['error' => $msg]);
                $this->throwException('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
            }
            throw $e;
        }

        $expiresMin = 60;
        $resetUrl = rtrim(function_exists('env') ? env('APP_URL', '') : '', '/');
        if (empty($resetUrl) && isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $resetUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        $resetUrl .= '/reset-password?token=' . urlencode($token);

        $mailHost = function_exists('env') ? env('MAIL_HOST', '') : '';
        $mailUser = function_exists('env') ? env('MAIL_USERNAME', '') : '';
        $useSmtp = !empty(trim($mailHost)) && !empty(trim($mailUser));

        if (!$useSmtp) {
            $sent = $this->sendPasswordResetViaMail($user['email'], $user['username'], $resetUrl, $expiresMin);
            if ($sent) {
                return ['sent' => true, 'email' => $user['email']];
            }
            return ['sent' => false, 'message' => 'Не удалось отправить письмо. Обратитесь к администратору сайта или попробуйте позже.'];
        }

        try {
            require_once __DIR__ . '/EmailService.php';
            $emailService = new EmailService();
            $emailService->sendPasswordResetLink($user['email'], $user['username'], $token, $expiresMin);
            return ['sent' => true, 'email' => $user['email']];
        } catch (\Throwable $e) {
            $this->logError('Failed to send password reset email', ['error' => $e->getMessage()]);
            return ['sent' => false, 'message' => 'Не удалось отправить письмо. Обратитесь к администратору сайта или попробуйте позже.'];
        }
    }

    /**
     * Отправить письмо сброса пароля через PHP mail() (без PHPMailer/autoload).
     * Вызывается, когда SMTP не настроен.
     */
    private function sendPasswordResetViaMail($toEmail, $username, $resetUrl, $expiresMin) {
        if (!function_exists('env')) {
            require_once __DIR__ . '/../config/env_loader.php';
        }
        $fromEmail = env('MAIL_FROM_ADDRESS', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName = env('MAIL_FROM_NAME', 'PlanRun');
        $subject = 'Сброс пароля PlanRun';
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $bodyHtml = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: sans-serif; line-height: 1.6; color: #333;">
  <p>Здравствуйте, ' . htmlspecialchars($username) . '!</p>
  <p>Вы запросили сброс пароля для аккаунта PlanRun.</p>
  <p>Перейдите по ссылке для установки нового пароля:</p>
  <p><a href="' . htmlspecialchars($resetUrl) . '" style="color: #2563eb; text-decoration: underline;">Сбросить пароль</a></p>
  <p>Ссылка действительна ' . (int)$expiresMin . ' минут.</p>
  <p>Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.</p>
  <p>— PlanRun</p>
</body>
</html>';
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'X-Mailer: PHP/' . PHP_VERSION
        ];
        return @mail($toEmail, $subjectEnc, $bodyHtml, implode("\r\n", $headers));
    }

    /**
     * Подтвердить сброс пароля по токену
     * POST /api_v2.php?action=confirm_password_reset
     * 
     * @return array
     */
    public function confirmPasswordReset($token, $newPassword) {
        $token = trim($token);
        $newPassword = trim($newPassword);

        if (empty($token) || empty($newPassword)) {
            $this->throwException('Токен и новый пароль обязательны', 400);
        }
        if (strlen($newPassword) < 6) {
            $this->throwException('Пароль должен быть не менее 6 символов', 400);
        }

        $this->ensurePasswordResetTable();

        $stmt = $this->db->prepare('
            SELECT prt.user_id, prt.token 
            FROM password_reset_tokens prt 
            WHERE prt.token = ? AND prt.expires_at > NOW()
        ');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $this->throwException('Ссылка для сброса пароля недействительна или истекла. Запросите новую.', 400);
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hashedPassword, $row['user_id']);
        if (!$stmt->execute()) {
            $stmt->close();
            $this->throwException('Не удалось обновить пароль', 500);
        }
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM password_reset_tokens WHERE token = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();

        return ['success' => true, 'message' => 'Пароль успешно изменён'];
    }

    /**
     * Создать таблицу password_reset_tokens при отсутствии (миграция при первом использовании).
     */
    private function ensurePasswordResetTable() {
        $sql = "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_user_id (user_id),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$this->db->query($sql)) {
            $this->logError('ensurePasswordResetTable failed', ['error' => $this->db->error]);
            $this->throwException('Сервис сброса пароля временно недоступен. Обратитесь к администратору сайта.', 503);
        }
    }

    /**
     * Проверить JWT токен из заголовка
     * 
     * @return array|null Данные пользователя или null
     */
    public function validateJwtToken() {
        // Nginx+PHP-FPM: HTTP_AUTHORIZATION не передаётся по умолчанию — нужен fastcgi_param HTTP_AUTHORIZATION $http_authorization
        // Apache mod_rewrite: может быть в REDIRECT_HTTP_AUTHORIZATION
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;
        
        if (!$authHeader || $authHeader === '') {
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
