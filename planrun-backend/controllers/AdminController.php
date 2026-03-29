<?php
/**
 * Контроллер админки: пользователи и настройки сайта.
 * Тонкий слой: auth/permissions + делегация в сервисы.
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../services/AdminService.php';
require_once __DIR__ . '/../services/CoachService.php';
require_once __DIR__ . '/../services/NotificationTemplateService.php';
require_once __DIR__ . '/../services/SiteSettingsService.php';

class AdminController extends BaseController {

    private ?AdminService $adminService = null;
    private ?CoachService $coachService = null;

    private function admin(): AdminService {
        if (!$this->adminService) {
            $this->adminService = new AdminService($this->db);
        }
        return $this->adminService;
    }

    private function coach(): CoachService {
        if (!$this->coachService) {
            $this->coachService = new CoachService($this->db);
        }
        return $this->coachService;
    }

    /**
     * Проверка прав администратора.
     */
    protected function requireAdmin(): bool {
        if (!$this->requireAuth()) {
            return false;
        }
        $currentUser = getCurrentUser();
        if (!$currentUser || ($currentUser['role'] ?? '') !== UserRoles::ADMIN) {
            $this->returnError('Доступ запрещён. Требуется роль администратора.', 403);
            return false;
        }
        return true;
    }

    // ==================== ПОЛЬЗОВАТЕЛИ ====================

    /**
     * GET admin_list_users — список пользователей (пагинация, поиск)
     */
    public function listUsers() {
        if (!$this->requireAdmin()) return;
        try {
            $page = max(1, (int)($this->getParam('page') ?? 1));
            $perPage = min(100, max(5, (int)($this->getParam('per_page') ?? 20)));
            $search = trim((string)($this->getParam('search') ?? ''));

            $this->returnSuccess($this->admin()->listUsers($page, $perPage, $search));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET admin_get_user — получить одного пользователя
     */
    public function getUser() {
        if (!$this->requireAdmin()) return;
        try {
            $userId = (int)($this->getParam('user_id') ?? 0);
            if ($userId <= 0) {
                $this->returnError('Не указан ID пользователя', 400);
                return;
            }
            $this->returnSuccess($this->admin()->getUser($userId));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST admin_update_user — обновить пользователя (роль, email)
     */
    public function updateUser() {
        if (!$this->requireAdmin()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
            if ($userId <= 0) {
                $this->returnError('Не указан ID пользователя', 400);
                return;
            }
            $this->admin()->updateUser($userId, $this->currentUserId, $data);
            $this->returnSuccess(['message' => 'Пользователь обновлён']);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    // ==================== НАСТРОЙКИ САЙТА ====================

    /**
     * GET get_site_settings — публичные настройки (без авторизации)
     */
    public function getPublicSettings() {
        try {
            $service = new SiteSettingsService($this->db);
            $this->returnSuccess(['settings' => $service->getAll()]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET admin_get_settings — настройки для админа
     */
    public function getSettings() {
        if (!$this->requireAdmin()) return;
        try {
            $service = new SiteSettingsService($this->db);
            $this->returnSuccess(['settings' => $service->getAll()]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST admin_update_settings — обновить настройки сайта
     */
    public function updateSettings() {
        if (!$this->requireAdmin()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $settings = $data['settings'] ?? [];
            if (!is_array($settings)) {
                $this->returnError('settings должен быть объектом', 400);
                return;
            }
            $service = new SiteSettingsService($this->db);
            $service->update($settings);
            $this->returnSuccess(['message' => 'Настройки сохранены']);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    // ==================== ШАБЛОНЫ УВЕДОМЛЕНИЙ ====================

    /**
     * GET admin_get_notification_templates
     */
    public function getNotificationTemplates() {
        if (!$this->requireAdmin()) return;
        try {
            $service = new NotificationTemplateService($this->db);
            $this->returnSuccess(['groups' => $service->getAdminTemplateCatalog()]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST admin_update_notification_template
     */
    public function updateNotificationTemplate() {
        if (!$this->requireAdmin()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $eventKey = trim((string)($data['event_key'] ?? ''));
            if ($eventKey === '') {
                $this->returnError('Не указан event_key', 400);
                return;
            }
            $service = new NotificationTemplateService($this->db);
            $template = $service->saveOverride($eventKey, [
                'title_template' => $data['title_template'] ?? null,
                'body_template' => $data['body_template'] ?? null,
                'link_template' => $data['link_template'] ?? null,
                'email_action_label_template' => $data['email_action_label_template'] ?? null,
            ], (int)$this->currentUserId);
            $this->returnSuccess(['template' => $template]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST admin_reset_notification_template
     */
    public function resetNotificationTemplate() {
        if (!$this->requireAdmin()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $eventKey = trim((string)($data['event_key'] ?? ''));
            if ($eventKey === '') {
                $this->returnError('Не указан event_key', 400);
                return;
            }
            $service = new NotificationTemplateService($this->db);
            $service->resetOverride($eventKey);
            $this->returnSuccess(['template' => $service->getTemplateConfigByEventKey($eventKey)]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    // ==================== ЗАЯВКИ ТРЕНЕРОВ ====================

    /**
     * GET admin_coach_applications — список заявок
     */
    public function getCoachApplications() {
        if (!$this->requireAdmin()) return;
        try {
            $status = $this->getParam('status', 'pending');
            $offset = max(0, (int)($this->getParam('offset', 0)));
            $limit = min(50, max(1, (int)($this->getParam('limit', 20))));

            $this->returnSuccess($this->coach()->getApplications($status, $limit, $offset));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST admin_approve_coach — одобрить заявку
     */
    public function approveCoachApplication() {
        if (!$this->requireAdmin()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $appId = (int)($input['application_id'] ?? 0);

            $result = $this->coach()->approveApplication($appId, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST admin_reject_coach — отклонить заявку
     */
    public function rejectCoachApplication() {
        if (!$this->requireAdmin()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $appId = (int)($input['application_id'] ?? 0);

            $this->coach()->rejectApplication($appId, $this->currentUserId);
            $this->returnSuccess(['rejected' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
