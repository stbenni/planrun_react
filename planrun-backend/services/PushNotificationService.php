<?php
/**
 * Отправка push-уведомлений через Firebase Cloud Messaging (FCM HTTP v1).
 * Требует: FIREBASE_CREDENTIALS (путь к JSON сервисного аккаунта) или FIREBASE_CREDENTIALS_JSON (содержимое).
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/NotificationSettingsService.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

class PushNotificationService extends BaseService {

    private $messaging = null;

    /**
     * Инициализация Firebase Messaging.
     */
    private function getMessaging() {
        if ($this->messaging !== null) {
            return $this->messaging;
        }
        $credentialsPath = env('FIREBASE_CREDENTIALS', '');
        $credentialsJson = env('FIREBASE_CREDENTIALS_JSON', '');
        if (empty($credentialsPath) && empty($credentialsJson)) {
            if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
                return null;
            }
            error_log('[Push] FIREBASE_CREDENTIALS or FIREBASE_CREDENTIALS_JSON not set');
            return null;
        }
        try {
            $factory = \Kreait\Firebase\Factory::class;
            if (!class_exists($factory)) {
                error_log('[Push] kreait/firebase-php not installed. Run: composer require kreait/firebase-php');
                return null;
            }
            if (!empty($credentialsJson)) {
                $credentials = json_decode($credentialsJson, true);
                if ($credentials) {
                    $this->messaging = (new \Kreait\Firebase\Factory)->withServiceAccount($credentials)->createMessaging();
                } elseif (strpos(trim($credentialsJson), '{') !== 0 && (is_file($credentialsJson) || is_file(__DIR__ . '/../' . $credentialsJson))) {
                    $path = is_file($credentialsJson) ? $credentialsJson : __DIR__ . '/../' . $credentialsJson;
                    $this->messaging = (new \Kreait\Firebase\Factory)->withServiceAccount($path)->createMessaging();
                } else {
                    error_log('[Push] Invalid FIREBASE_CREDENTIALS_JSON, trying FIREBASE_CREDENTIALS');
                }
            }
            if ($this->messaging === null && !empty($credentialsPath)) {
                $path = $credentialsPath;
                if (!is_file($path)) {
                    $path = __DIR__ . '/../' . $credentialsPath;
                }
                if (!is_file($path)) {
                    error_log('[Push] Firebase credentials file not found: ' . $credentialsPath);
                    return null;
                }
                $this->messaging = (new \Kreait\Firebase\Factory)->withServiceAccount($path)->createMessaging();
            }
        } catch (\Throwable $e) {
            error_log('[Push] Firebase init error: ' . $e->getMessage());
            return null;
        }
        return $this->messaging;
    }

    /**
     * Проверить, разрешены ли push указанного типа для пользователя.
     */
    public function isPushAllowed(int $userId, string $type): bool {
        $col = $type === 'workout' ? 'push_workouts_enabled' : ($type === 'chat' ? 'push_chat_enabled' : null);
        if (!$col) return true;
        $stmt = $this->db->prepare("SELECT $col FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? ((int)($row[$col] ?? 1)) === 1 : true;
    }

    /**
     * Отправить push одному пользователю.
     * @param int $userId
     * @param string $title
     * @param string $body
     * @param array $data Доп. данные (type, link, date и т.д.)
     * @return bool
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): bool {
        $type = $data['type'] ?? 'chat';
        $eventKey = trim((string) ($data['event_key'] ?? ''));
        $settings = null;
        if ($eventKey !== '') {
            $settings = new NotificationSettingsService($this->db);
            $guard = $settings->canDeliver($userId, 'mobile_push', $eventKey, !empty($data['ignore_quiet_hours']));
            if (empty($guard['allowed'])) {
                $settings->logDelivery($userId, $eventKey, 'mobile_push', 'skipped', [
                    'title' => $title,
                    'body' => $body,
                    'entity_type' => isset($data['entity_type']) ? (string) $data['entity_type'] : null,
                    'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
                    'error_text' => $guard['reason'] ?? 'guard_rejected',
                ]);
                return false;
            }
        } elseif (!$this->isPushAllowed($userId, $type)) {
            return false;
        }
        $tokens = $this->getUserTokens($userId);
        if (empty($tokens)) {
            if ($settings && $eventKey !== '') {
                $settings->logDelivery($userId, $eventKey, 'mobile_push', 'skipped', [
                    'title' => $title,
                    'body' => $body,
                    'entity_type' => isset($data['entity_type']) ? (string) $data['entity_type'] : null,
                    'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
                    'error_text' => 'no_tokens',
                ]);
            }
            return false;
        }
        $sent = $this->sendToTokens($tokens, $title, $body, $data);
        if ($settings && $eventKey !== '') {
            $settings->logDelivery($userId, $eventKey, 'mobile_push', $sent ? 'sent' : 'failed', [
                'title' => $title,
                'body' => $body,
                'entity_type' => isset($data['entity_type']) ? (string) $data['entity_type'] : null,
                'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
                'error_text' => $sent ? null : 'send_failed',
            ]);
        }
        return $sent;
    }

    /**
     * Получить FCM-токены пользователя.
     */
    public function getUserTokens(int $userId): array {
        $stmt = $this->db->prepare("SELECT fcm_token FROM push_tokens WHERE user_id = ? AND fcm_token != ''");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $tokens = [];
        while ($row = $result->fetch_assoc()) {
            $tokens[] = $row['fcm_token'];
        }
        $stmt->close();
        return $tokens;
    }

    /**
     * Отправить push на список токенов.
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): bool {
        $messaging = $this->getMessaging();
        if (!$messaging) {
            return false;
        }
        $sent = false;
        // Android 15 / Doze: high priority для чата и важных уведомлений — доставка без задержки
        $androidConfig = \Kreait\Firebase\Messaging\AndroidConfig::new()
            ->withHighMessagePriority()
            ->withHighNotificationPriority();
        foreach ($tokens as $token) {
            try {
                $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $token)
                    ->withNotification(\Kreait\Firebase\Messaging\Notification::create($title, $body))
                    ->withData($data)
                    ->withAndroidConfig($androidConfig);
                $messaging->send($message);
                $sent = true;
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'not a valid FCM registration token') !== false ||
                    strpos($e->getMessage(), 'unregistered') !== false) {
                    $this->removeInvalidToken($token);
                }
                error_log('[Push] Send error for token ' . substr($token, 0, 20) . '...: ' . $e->getMessage());
            }
        }
        return $sent;
    }

    /**
     * Отправить data-only push (silent) — без уведомления пользователю.
     * Приложение получит данные и обновит UI в фоне.
     */
    public function sendDataPush(int $userId, array $data): bool {
        $tokens = $this->getUserTokens($userId);
        if (empty($tokens)) return false;

        $messaging = $this->getMessaging();
        if (!$messaging) return false;

        $sent = false;
        $androidConfig = \Kreait\Firebase\Messaging\AndroidConfig::new()
            ->withNormalMessagePriority(); // normal priority для data-only — не будит устройство агрессивно

        foreach ($tokens as $token) {
            try {
                $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $token)
                    ->withData($data)
                    ->withAndroidConfig($androidConfig);
                $messaging->send($message);
                $sent = true;
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'not a valid FCM registration token') !== false ||
                    strpos($e->getMessage(), 'unregistered') !== false) {
                    $this->removeInvalidToken($token);
                }
            }
        }
        return $sent;
    }

    private function removeInvalidToken(string $token): void {
        $stmt = $this->db->prepare("DELETE FROM push_tokens WHERE fcm_token = ?");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    }
}
