<?php
/**
 * Контроллер админки: пользователи и настройки сайта
 * Доступ только для role = admin
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../user_functions.php';

class AdminController extends BaseController {

    /**
     * Проверка прав администратора
     */
    protected function requireAdmin() {
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

    /**
     * Список пользователей (пагинация, поиск)
     * GET /api_v2.php?action=admin_list_users&page=1&per_page=20&search=
     */
    public function listUsers() {
        if (!$this->requireAdmin()) {
            return;
        }
        try {
            $page = max(1, (int)($this->getParam('page') ?? 1));
            $perPage = min(100, max(5, (int)($this->getParam('per_page') ?? 20)));
            $search = trim((string)($this->getParam('search') ?? ''));
            $offset = ($page - 1) * $perPage;

            $where = '1=1';
            $params = [];
            $types = '';
            if ($search !== '') {
                $where .= ' AND (username LIKE ? OR email LIKE ?)';
                $term = '%' . $search . '%';
                $params = [$term, $term];
                $types = 'ss';
            }

            $countSql = "SELECT COUNT(*) AS total FROM users WHERE $where";
            $stmt = $this->db->prepare($countSql);
            if ($types && $params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            $listSql = "SELECT id, username, email, role, created_at, training_mode, goal_type 
                        FROM users WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?";
            $stmt = $this->db->prepare($listSql);
            $params[] = $perPage;
            $params[] = $offset;
            $types .= 'ii';
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $this->returnSuccess([
                'users' => $list,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $total ? (int)ceil($total / $perPage) : 0,
            ]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Получить одного пользователя (для редактирования)
     * GET /api_v2.php?action=admin_get_user&user_id=123
     */
    public function getUser() {
        if (!$this->requireAdmin()) {
            return;
        }
        try {
            $userId = (int)($this->getParam('user_id') ?? 0);
            if ($userId <= 0) {
                $this->returnError('Не указан ID пользователя', 400);
                return;
            }
            $user = getUserData($userId, null, false);
            if (!$user) {
                $this->returnError('Пользователь не найден', 404);
                return;
            }
            unset($user['password']);
            if (isset($user['preferred_days']) && is_string($user['preferred_days'])) {
                $user['preferred_days'] = json_decode($user['preferred_days'], true) ?? [];
            }
            if (isset($user['preferred_ofp_days']) && is_string($user['preferred_ofp_days'])) {
                $user['preferred_ofp_days'] = json_decode($user['preferred_ofp_days'], true) ?? [];
            }
            $this->returnSuccess($user);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Обновить пользователя (роль, email, блокировка и т.д.)
     * POST /api_v2.php?action=admin_update_user
     * Body: { user_id, role?, email? }
     */
    public function updateUser() {
        if (!$this->requireAdmin()) {
            return;
        }
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
            if ($userId <= 0) {
                $this->returnError('Не указан ID пользователя', 400);
                return;
            }
            if ($userId === $this->currentUserId) {
                $this->returnError('Нельзя менять роль самому себе через эту форму', 400);
                return;
            }

            $updates = [];
            $values = [];
            $types = '';

            if (isset($data['role']) && in_array($data['role'], UserRoles::getAll(), true)) {
                $updates[] = 'role = ?';
                $values[] = $data['role'];
                $types .= 's';
            }
            if (array_key_exists('email', $data)) {
                $email = $data['email'] === null || $data['email'] === '' ? null : trim($data['email']);
                if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->returnError('Некорректный формат email', 400);
                    return;
                }
                $updates[] = 'email = ?';
                $values[] = $email;
                $types .= 's';
            }

            if (empty($updates)) {
                $this->returnError('Нет данных для обновления', 400);
                return;
            }

            $values[] = $userId;
            $types .= 'i';
            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            if ($stmt->affected_rows >= 0) {
                clearUserCache($userId);
                $this->returnSuccess(['message' => 'Пользователь обновлён']);
            } else {
                $this->returnError('Не удалось обновить пользователя', 500);
            }
            $stmt->close();
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Публичные настройки сайта (без авторизации)
     * GET /api_v2.php?action=get_site_settings
     * Используется для режима обслуживания, названия сайта, доступности регистрации.
     */
    public function getPublicSettings() {
        try {
            $settings = $this->loadSettingsFromDb();
            $this->returnSuccess(['settings' => $settings]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Получить настройки сайта (только для админа)
     * GET /api_v2.php?action=admin_get_settings
     */
    public function getSettings() {
        if (!$this->requireAdmin()) {
            return;
        }
        try {
            $settings = $this->loadSettingsFromDb();
            $this->returnSuccess(['settings' => $settings]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Загрузить настройки из БД или вернуть значения по умолчанию
     */
    protected function loadSettingsFromDb() {
        $settings = $this->getDefaultSettingsMap();
        $tableExists = $this->db->query("SHOW TABLES LIKE 'site_settings'");
        if ($tableExists && $tableExists->num_rows > 0) {
            $res = $this->db->query("SELECT `key`, value FROM site_settings");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $settings[$row['key']] = $row['value'];
                }
            }
        }
        // Всегда возвращаем '0' или '1' для флагов, чтобы снятие галочек сохранялось
        foreach (['maintenance_mode', 'registration_enabled'] as $k) {
            if (isset($settings[$k])) {
                $v = $settings[$k];
                $settings[$k] = ($v === true || $v === '1' || $v === 1) ? '1' : '0';
            }
        }
        return $settings;
    }

    /**
     * Обновить настройки сайта
     * POST /api_v2.php?action=admin_update_settings
     * Body: { settings: { site_name: "...", maintenance_mode: "0", ... } }
     */
    public function updateSettings() {
        if (!$this->requireAdmin()) {
            return;
        }
        $this->checkCsrfToken();
        try {
            $tableExists = $this->db->query("SHOW TABLES LIKE 'site_settings'");
            if (!$tableExists || $tableExists->num_rows === 0) {
                $createSql = file_get_contents(__DIR__ . '/../migrations/create_site_settings.sql');
                if ($createSql && preg_match('/CREATE TABLE[^;]+;/s', $createSql, $m)) {
                    $this->db->query($m[0]);
                }
            }

            $data = $this->getJsonBody();
            $settings = $data['settings'] ?? [];
            if (!is_array($settings)) {
                $this->returnError('settings должен быть объектом', 400);
                return;
            }

            $allowed = array_keys($this->getDefaultSettingsMap());
            $onOffKeys = ['maintenance_mode', 'registration_enabled'];
            foreach ($settings as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    continue;
                }
                if (in_array($key, $onOffKeys, true)) {
                    $value = ($value === true || $value === '1' || $value === 1) ? '1' : '0';
                } else {
                    $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
                }
                $stmt = $this->db->prepare("INSERT INTO site_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
                $stmt->bind_param('sss', $key, $value, $value);
                $stmt->execute();
                $stmt->close();
            }
            $this->returnSuccess(['message' => 'Настройки сохранены']);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET admin_coach_applications — список заявок на роль тренера
     */
    public function getCoachApplications() {
        if (!$this->requireAdmin()) return;
        try {
            $status = $this->getParam('status', 'pending');
            $offset = max(0, (int)($this->getParam('offset', 0)));
            $limit = min(50, max(1, (int)($this->getParam('limit', 20))));

            $stmt = $this->db->prepare("
                SELECT ca.*, u.username, u.username_slug, u.avatar_path, u.email
                FROM coach_applications ca
                JOIN users u ON ca.user_id = u.id
                WHERE ca.status = ?
                ORDER BY ca.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param("sii", $status, $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();

            $applications = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['user_id'] = (int)$row['user_id'];
                $row['coach_specialization'] = json_decode($row['coach_specialization'] ?? '[]', true) ?: [];
                $row['coach_pricing_json'] = json_decode($row['coach_pricing_json'] ?? '[]', true) ?: [];
                $row['coach_accepts_new'] = (bool)$row['coach_accepts_new'];
                $row['coach_prices_on_request'] = (bool)$row['coach_prices_on_request'];
                $row['coach_experience_years'] = $row['coach_experience_years'] ? (int)$row['coach_experience_years'] : null;
                $applications[] = $row;
            }
            $stmt->close();

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM coach_applications WHERE status = ?");
            $stmt->bind_param("s", $status);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();

            $this->returnSuccess(['applications' => $applications, 'total' => (int)$total]);
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

            $stmt = $this->db->prepare("SELECT * FROM coach_applications WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $appId);
            $stmt->execute();
            $app = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$app) {
                $this->returnError('Заявка не найдена или уже обработана', 404);
                return;
            }

            $userId = (int)$app['user_id'];

            // Обновляем роль и coach-поля
            $stmt = $this->db->prepare("
                UPDATE users SET
                    role = 'coach',
                    coach_bio = ?,
                    coach_specialization = ?,
                    coach_accepts = ?,
                    coach_prices_on_request = ?,
                    coach_experience_years = ?,
                    coach_philosophy = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssiissi",
                $app['coach_bio'],
                $app['coach_specialization'],
                $app['coach_accepts_new'],
                $app['coach_prices_on_request'],
                $app['coach_experience_years'],
                $app['coach_philosophy'],
                $userId
            );
            $stmt->execute();
            $stmt->close();

            // Копируем pricing
            $pricingItems = json_decode($app['coach_pricing_json'] ?? '[]', true) ?: [];
            if (!empty($pricingItems)) {
                $stmt = $this->db->prepare("INSERT INTO coach_pricing (coach_id, type, label, price, currency, period, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($pricingItems as $i => $item) {
                    $type = $item['type'] ?? 'custom';
                    $label = $item['label'] ?? '';
                    $price = isset($item['price']) ? (float)$item['price'] : null;
                    $currency = $item['currency'] ?? 'RUB';
                    $period = $item['period'] ?? 'month';
                    $sortOrder = $i;
                    if ($label) {
                        $stmt->bind_param("issdssi", $userId, $type, $label, $price, $currency, $period, $sortOrder);
                        $stmt->execute();
                    }
                }
                $stmt->close();
            }

            // Обновляем статус заявки
            $stmt = $this->db->prepare("UPDATE coach_applications SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->bind_param("ii", $this->currentUserId, $appId);
            $stmt->execute();
            $stmt->close();

            $this->returnSuccess(['approved' => true, 'user_id' => $userId]);
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

            $stmt = $this->db->prepare("SELECT id FROM coach_applications WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $appId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt->close();
                $this->returnError('Заявка не найдена или уже обработана', 404);
                return;
            }
            $stmt->close();

            $stmt = $this->db->prepare("UPDATE coach_applications SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->bind_param("ii", $this->currentUserId, $appId);
            $stmt->execute();
            $stmt->close();

            $this->returnSuccess(['rejected' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Ключи настроек по умолчанию и их значения
     */
    protected function getDefaultSettingsMap() {
        return [
            'site_name' => 'PlanRun',
            'site_description' => 'Персональный план беговых тренировок',
            'maintenance_mode' => '0',
            'registration_enabled' => '1',
            'contact_email' => '',
        ];
    }
}
