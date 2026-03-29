<?php
/**
 * Сервис для админских операций с пользователями.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../user_functions.php';

class AdminService extends BaseService {

    /**
     * Список пользователей с пагинацией и поиском.
     */
    public function listUsers(int $page, int $perPage, string $search): array {
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

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM users WHERE $where");
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $stmt = $this->db->prepare(
            "SELECT id, username, email, role, created_at, training_mode, goal_type
             FROM users WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $allParams = array_merge($params, [$perPage, $offset]);
        $allTypes = $types . 'ii';
        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'users' => $list,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $total ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Получить данные одного пользователя.
     */
    public function getUser(int $userId): array {
        $user = getUserData($userId, null, false);
        if (!$user) {
            $this->throwNotFoundException('Пользователь не найден');
        }
        unset($user['password']);
        if (isset($user['preferred_days']) && is_string($user['preferred_days'])) {
            $user['preferred_days'] = json_decode($user['preferred_days'], true) ?? [];
        }
        if (isset($user['preferred_ofp_days']) && is_string($user['preferred_ofp_days'])) {
            $user['preferred_ofp_days'] = json_decode($user['preferred_ofp_days'], true) ?? [];
        }
        return $user;
    }

    /**
     * Обновить пользователя (роль, email).
     */
    public function updateUser(int $userId, int $currentAdminId, array $data): void {
        if ($userId === $currentAdminId) {
            $this->throwValidationException('Нельзя менять роль самому себе через эту форму');
        }

        require_once __DIR__ . '/../config/constants.php';

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
                $this->throwValidationException('Некорректный формат email');
            }
            $updates[] = 'email = ?';
            $values[] = $email;
            $types .= 's';
        }

        if (empty($updates)) {
            $this->throwValidationException('Нет данных для обновления');
        }

        $values[] = $userId;
        $types .= 'i';
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        clearUserCache($userId);
    }
}
