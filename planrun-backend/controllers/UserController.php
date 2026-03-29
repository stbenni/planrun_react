<?php
/**
 * UserController — API для работы с пользователями
 *
 * Тонкий контроллер: auth/permissions + делегация в UserProfileService и другие сервисы.
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/AvatarService.php';
require_once __DIR__ . '/../services/NotificationSettingsService.php';
require_once __DIR__ . '/../services/WebPushNotificationService.php';
require_once __DIR__ . '/../services/UserProfileService.php';

class UserController extends BaseController {

    private ?UserProfileService $profileService = null;

    private function profile(): UserProfileService {
        if (!$this->profileService) {
            $this->profileService = new UserProfileService($this->db);
        }
        return $this->profileService;
    }

    /**
     * GET get_profile
     */
    public function getProfile() {
        if (!$this->requireAuth()) return;
        try {
            $this->returnSuccess($this->profile()->getProfile($this->currentUserId));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST update_profile
     */
    public function updateProfile() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $updatedUser = $this->profile()->updateProfile($this->currentUserId, $data);
            $this->returnSuccess(['message' => 'Профиль успешно обновлен', 'user' => $updatedUser]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_notification_settings
     */
    public function getNotificationSettings() {
        if (!$this->requireAuth()) return;
        try {
            $service = new NotificationSettingsService($this->db);
            $this->returnSuccess($service->getSettings($this->currentUserId));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_notification_delivery_log
     */
    public function getNotificationDeliveryLog() {
        if (!$this->requireAuth()) return;
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
            $service = new NotificationSettingsService($this->db);
            $this->returnSuccess(['items' => $service->getDeliveryLog($this->currentUserId, $limit)]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST update_notification_settings
     */
    public function updateNotificationSettings() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $payload = $this->getJsonBody();
            if (!is_array($payload)) {
                $this->returnError('Некорректное тело запроса', 400);
                return;
            }
            $service = new NotificationSettingsService($this->db);
            $settings = $service->saveSettings($this->currentUserId, $payload);
            require_once __DIR__ . '/../user_functions.php';
            clearUserCache($this->currentUserId);
            $this->returnSuccess($settings);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST register_web_push_subscription
     */
    public function registerWebPushSubscription() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $payload = $this->getJsonBody();
            $subscription = is_array($payload['subscription'] ?? null) ? $payload['subscription'] : [];
            $userAgent = isset($payload['user_agent']) ? (string)$payload['user_agent'] : null;

            $service = new WebPushNotificationService($this->db);
            if (!$service->isConfigured()) {
                $this->returnError('Web push не настроен на сервере', 503);
                return;
            }
            if (!$service->registerSubscription($this->currentUserId, $subscription, $userAgent)) {
                $this->returnError('Не удалось сохранить web push подписку', 400);
                return;
            }

            $settingsService = new NotificationSettingsService($this->db);
            $this->returnSuccess(['registered' => true, 'settings' => $settingsService->getSettings($this->currentUserId)]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST unregister_web_push_subscription
     */
    public function unregisterWebPushSubscription() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $payload = $this->getJsonBody();
            $endpoint = isset($payload['endpoint']) ? (string)$payload['endpoint'] : null;

            $service = new WebPushNotificationService($this->db);
            $deleted = $service->unregisterSubscription($this->currentUserId, $endpoint);

            $settingsService = new NotificationSettingsService($this->db);
            $this->returnSuccess(['deleted' => $deleted, 'settings' => $settingsService->getSettings($this->currentUserId)]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST send_test_notification
     */
    public function sendTestNotification() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $payload = $this->getJsonBody();
            $channel = trim((string)($payload['channel'] ?? ''));
            $endpoint = trim((string)($payload['endpoint'] ?? ''));

            $result = $this->profile()->sendTestNotification($this->currentUserId, $channel, $endpoint);

            if (!$result['success']) {
                $this->returnError($result['error'] ?: 'Не удалось отправить тестовое уведомление', 400);
                return;
            }

            $this->returnSuccess([
                'channel' => $result['channel'],
                'sent' => true,
                'message' => 'Тестовое уведомление отправлено',
            ]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST delete_user (admin only)
     */
    public function deleteUser() {
        if (!$this->requireAuth() || !$this->requireEdit()) return;
        $this->checkCsrfToken();
        try {
            require_once __DIR__ . '/../user_functions.php';
            $currentUser = getCurrentUser();
            if (!$currentUser || $currentUser['role'] !== 'admin') {
                $this->returnError('Доступ запрещен. Требуется роль администратора.', 403);
                return;
            }

            $data = $this->getJsonBody();
            $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
            if (!$targetUserId || $targetUserId <= 0) {
                $this->returnError('Не указан ID пользователя', 400);
                return;
            }

            $result = $this->profile()->deleteUser($targetUserId, $this->currentUserId);
            $this->returnSuccess([
                'message' => 'Пользователь и все его данные успешно удалены',
                'username' => $result['username'],
            ]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST upload_avatar
     */
    public function uploadAvatar() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $this->returnError('Файл не загружен или произошла ошибка', 400);
                return;
            }

            $result = $this->profile()->uploadAvatar($this->currentUserId, $_FILES['avatar']);
            $this->returnSuccess([
                'message' => 'Аватар успешно загружен',
                'avatar_path' => $result['avatar_path'],
                'user' => $result['user'],
            ]);
        } catch (InvalidArgumentException $e) {
            $this->returnError($e->getMessage(), 400);
        } catch (RuntimeException $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error('Ошибка загрузки аватара', ['user_id' => $this->currentUserId, 'error' => $e->getMessage()]);
            $this->returnError('Ошибка обработки аватара. Проверьте формат изображения и права на uploads/avatars.', 500);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_avatar (без авторизации)
     */
    public function getAvatar() {
        $file = $_GET['file'] ?? '';
        $variant = $_GET['variant'] ?? 'full';
        if (!AvatarService::serveRequestedAvatar($file, $variant)) {
            http_response_code(404);
        }
        exit;
    }

    /**
     * POST remove_avatar
     */
    public function removeAvatar() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $this->profile()->removeAvatar($this->currentUserId);
            $this->returnSuccess(['message' => 'Аватар успешно удален']);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST update_privacy
     */
    public function updatePrivacy() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $result = $this->profile()->updatePrivacy($this->currentUserId, $data['privacy_level'] ?? 'public');
            $this->returnSuccess(array_merge(['message' => 'Настройки приватности обновлены'], $result));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET notifications_dismissed
     */
    public function getNotificationsDismissed() {
        if (!$this->requireAuth()) return;
        try {
            require_once __DIR__ . '/../repositories/NotificationRepository.php';
            $repo = new NotificationRepository($this->db);
            $this->returnSuccess(['dismissed' => $repo->getDismissedIds($this->currentUserId)]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST notifications_dismiss
     */
    public function dismissNotification() {
        if (!$this->requireAuth()) return;
        try {
            $data = $this->getJsonBody();
            $notificationId = trim($data['notification_id'] ?? $data['id'] ?? '');
            if ($notificationId === '' || strlen($notificationId) > 120) {
                $this->returnError('Некорректный ID уведомления', 400);
                return;
            }
            require_once __DIR__ . '/../repositories/NotificationRepository.php';
            $repo = new NotificationRepository($this->db);
            $repo->dismiss($this->currentUserId, $notificationId);
            $this->returnSuccess(['ok' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET telegram_login_url
     */
    public function getTelegramLoginUrl() {
        if (!$this->requireAuth()) return;
        try {
            require_once __DIR__ . '/../services/TelegramLoginService.php';
            $fromApp = (string)($this->getParam('from_app', '0')) === '1';
            $service = new TelegramLoginService($this->db);
            $this->returnSuccess(['auth_url' => $service->createAuthorizationUrl((int)$this->currentUserId, $fromApp)]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST generate_telegram_link_code
     */
    public function generateTelegramLinkCode() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $result = $this->profile()->generateTelegramLinkCode($this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST unlink_telegram
     */
    public function unlinkTelegram() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        try {
            $this->profile()->unlinkTelegram($this->currentUserId);
            $this->returnSuccess(['message' => 'Telegram успешно отвязан']);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
