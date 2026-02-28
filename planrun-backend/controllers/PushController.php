<?php
/**
 * Контроллер для регистрации push-токенов.
 */

require_once __DIR__ . '/BaseController.php';

class PushController extends BaseController {

    /**
     * Зарегистрировать FCM токен устройства.
     * POST /api_v2.php?action=register_push_token
     * Body: { fcm_token, device_id, platform }
     */
    public function registerToken() {
        require_once __DIR__ . '/../auth.php';
        if (!isAuthenticated()) {
            $this->returnError('Требуется авторизация для этого действия', 401);
            return;
        }
        try {
            $data = $this->getJsonBody();
            $fcmToken = trim($data['fcm_token'] ?? '');
            $deviceId = trim($data['device_id'] ?? '');
            $platform = trim($data['platform'] ?? 'android');
            if (strlen($fcmToken) < 50 || strlen($deviceId) < 1) {
                $this->returnError('fcm_token и device_id обязательны', 400);
                return;
            }
            if (!in_array($platform, ['android', 'ios'], true)) {
                $platform = 'android';
            }
            $stmt = $this->db->prepare(
                "INSERT INTO push_tokens (user_id, device_id, fcm_token, platform) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), platform = VALUES(platform), updated_at = NOW()"
            );
            $stmt->bind_param('isss', $this->currentUserId, $deviceId, $fcmToken, $platform);
            $stmt->execute();
            $stmt->close();
            $this->returnSuccess(['registered' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Удалить push-токен (при logout).
     * POST /api_v2.php?action=unregister_push_token
     * Body: { device_id }
     */
    public function unregisterToken() {
        if (!$this->requireAuth()) {
            return;
        }
        try {
            $data = $this->getJsonBody();
            $deviceId = trim($data['device_id'] ?? '');
            if ($deviceId === '') {
                $this->returnSuccess(['unregistered' => true]);
                return;
            }
            $stmt = $this->db->prepare("DELETE FROM push_tokens WHERE user_id = ? AND device_id = ?");
            $stmt->bind_param('is', $this->currentUserId, $deviceId);
            $stmt->execute();
            $stmt->close();
            $this->returnSuccess(['unregistered' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
