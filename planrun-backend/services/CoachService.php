<?php
/**
 * Сервис для работы с тренерами: каталог, запросы, связи, группы, ценообразование, заявки.
 */

require_once __DIR__ . '/BaseService.php';

class CoachService extends BaseService {

    // ==================== КАТАЛОГ ====================

    public function listCoaches(array $filters, int $limit, int $offset): array {
        $where = ["u.role = 'coach'"];
        $params = [];
        $types = '';

        if (!empty($filters['specialization'])) {
            $where[] = "JSON_CONTAINS(u.coach_specialization, ?)";
            $params[] = '"' . $filters['specialization'] . '"';
            $types .= 's';
        }
        if (isset($filters['accepts_new']) && $filters['accepts_new'] !== '') {
            $where[] = "u.coach_accepts = ?";
            $params[] = (int)$filters['accepts_new'];
            $types .= 'i';
        }

        $whereClause = implode(' AND ', $where);

        // Total
        $countSql = "SELECT COUNT(*) as total FROM users u WHERE $whereClause";
        if ($params) {
            $stmt = $this->db->prepare($countSql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $res = $this->db->query($countSql);
            $total = (int)$res->fetch_assoc()['total'];
        }

        // List
        $sql = "SELECT u.id, u.username, u.username_slug, u.avatar_path, u.coach_bio,
                       u.coach_specialization, u.coach_accepts, u.coach_prices_on_request,
                       u.coach_experience_years, u.coach_philosophy, u.last_activity
                FROM users u
                WHERE $whereClause
                ORDER BY u.last_activity DESC
                LIMIT ? OFFSET ?";

        $allParams = array_merge($params, [$limit, $offset]);
        $allTypes = $types . 'ii';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $coaches = [];
        while ($row = $result->fetch_assoc()) {
            $coachId = (int)$row['id'];
            $pricing = $this->loadPricing($coachId);

            $coaches[] = [
                'id' => $coachId,
                'username' => $row['username'],
                'username_slug' => $row['username_slug'],
                'avatar_path' => $row['avatar_path'],
                'coach_bio' => $row['coach_bio'],
                'coach_specialization' => json_decode($row['coach_specialization'] ?? '[]', true) ?: [],
                'coach_accepts' => (bool)$row['coach_accepts'],
                'coach_prices_on_request' => (bool)$row['coach_prices_on_request'],
                'coach_experience_years' => $row['coach_experience_years'] ? (int)$row['coach_experience_years'] : null,
                'coach_philosophy' => $row['coach_philosophy'],
                'pricing' => $pricing,
            ];
        }
        $stmt->close();

        return ['coaches' => $coaches, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    // ==================== ЗАПРОСЫ НА ТРЕНИРОВКУ ====================

    public function createRequest(int $athleteId, int $coachId, string $message = ''): int {
        if ($coachId === $athleteId) {
            $this->throwValidationException('Нельзя запросить себя как тренера');
        }

        // Тренер существует и принимает
        $stmt = $this->db->prepare("SELECT role, coach_accepts FROM users WHERE id = ? AND role IN ('coach', 'admin')");
        $stmt->bind_param("i", $coachId);
        $stmt->execute();
        $coach = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$coach) {
            $this->throwNotFoundException('Тренер не найден');
        }
        if (!(bool)$coach['coach_accepts']) {
            $this->throwValidationException('Тренер сейчас не принимает новых учеников');
        }

        // Дубликат pending
        $stmt = $this->db->prepare("SELECT id FROM coach_requests WHERE user_id = ? AND coach_id = ? AND status = 'pending'");
        $stmt->bind_param("ii", $athleteId, $coachId);
        $stmt->execute();
        $hasPending = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($hasPending) {
            $this->throwValidationException('У вас уже есть активный запрос к этому тренеру');
        }

        // Уже связаны
        $stmt = $this->db->prepare("SELECT id FROM user_coaches WHERE user_id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $athleteId, $coachId);
        $stmt->execute();
        $alreadyConnected = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($alreadyConnected) {
            $this->throwValidationException('Этот тренер уже ваш тренер');
        }

        $msgVal = $message ?: null;
        $stmt = $this->db->prepare("INSERT INTO coach_requests (user_id, coach_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $athleteId, $coachId, $msgVal);
        $stmt->execute();
        $requestId = $stmt->insert_id;
        $stmt->close();

        return $requestId;
    }

    public function getRequests(int $coachId, string $status, int $limit, int $offset): array {
        $stmt = $this->db->prepare("
            SELECT cr.id, cr.user_id, cr.status, cr.message, cr.created_at,
                   u.username, u.username_slug, u.avatar_path
            FROM coach_requests cr
            JOIN users u ON cr.user_id = u.id
            WHERE cr.coach_id = ? AND cr.status = ?
            ORDER BY cr.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("isii", $coachId, $status, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'username' => $row['username'],
                'username_slug' => $row['username_slug'],
                'avatar_path' => $row['avatar_path'],
                'message' => $row['message'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            ];
        }
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM coach_requests WHERE coach_id = ? AND status = ?");
        $stmt->bind_param("is", $coachId, $status);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        return ['requests' => $requests, 'total' => $total];
    }

    public function acceptRequest(int $coachId, int $requestId): int {
        $stmt = $this->db->prepare("SELECT id, user_id, coach_id, status FROM coach_requests WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $requestId, $coachId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            $this->throwNotFoundException('Запрос не найден');
        }
        if ($request['status'] !== 'pending') {
            $this->throwValidationException('Запрос уже обработан');
        }

        $athleteId = (int)$request['user_id'];

        $stmt = $this->db->prepare("UPDATE coach_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare("INSERT IGNORE INTO user_coaches (user_id, coach_id, can_view, can_edit) VALUES (?, ?, 1, 1)");
        $stmt->bind_param("ii", $athleteId, $coachId);
        $stmt->execute();
        $stmt->close();

        return $athleteId;
    }

    public function rejectRequest(int $coachId, int $requestId): void {
        $stmt = $this->db->prepare("SELECT id, status FROM coach_requests WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $requestId, $coachId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request || $request['status'] !== 'pending') {
            $this->throwValidationException('Запрос не найден или уже обработан');
        }

        $stmt = $this->db->prepare("UPDATE coach_requests SET status = 'rejected', responded_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();
    }

    // ==================== СВЯЗИ ====================

    public function getUserCoaches(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.username_slug, u.avatar_path, u.coach_bio, u.coach_specialization
            FROM user_coaches uc
            JOIN users u ON uc.coach_id = u.id
            WHERE uc.user_id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $coaches = [];
        while ($row = $result->fetch_assoc()) {
            $coaches[] = [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'username_slug' => $row['username_slug'],
                'avatar_path' => $row['avatar_path'],
                'coach_bio' => $row['coach_bio'],
                'coach_specialization' => json_decode($row['coach_specialization'] ?? '[]', true) ?: [],
            ];
        }
        $stmt->close();

        return $coaches;
    }

    public function removeCoachRelationship(int $currentUserId, ?int $coachId, ?int $athleteId): void {
        if ($coachId && !$athleteId) {
            $stmt = $this->db->prepare("DELETE FROM user_coaches WHERE user_id = ? AND coach_id = ?");
            $stmt->bind_param("ii", $currentUserId, $coachId);
        } elseif ($athleteId && !$coachId) {
            $stmt = $this->db->prepare("DELETE FROM user_coaches WHERE user_id = ? AND coach_id = ?");
            $stmt->bind_param("ii", $athleteId, $currentUserId);
        } else {
            $this->throwValidationException('Укажите coach_id (для атлета) или athlete_id (для тренера)');
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            $this->throwNotFoundException('Связь не найдена');
        }
    }

    // ==================== ЗАЯВКА «СТАТЬ ТРЕНЕРОМ» ====================

    public function applyAsCoach(int $userId, array $input): int {
        // Уже тренер?
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user['role'] === 'coach') {
            $this->throwValidationException('Вы уже тренер');
        }

        // Есть pending заявка?
        $stmt = $this->db->prepare("SELECT id FROM coach_applications WHERE user_id = ? AND status = 'pending'");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $hasPending = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($hasPending) {
            $this->throwValidationException('У вас уже есть заявка на рассмотрении');
        }

        // Валидация
        $specialization = $input['coach_specialization'] ?? [];
        if (is_array($specialization)) {
            if (count($specialization) === 0) {
                $this->throwValidationException('Выберите хотя бы одну специализацию');
            }
            $specialization = json_encode($specialization, JSON_UNESCAPED_UNICODE);
        }

        $bio = trim($input['coach_bio'] ?? '');
        if (mb_strlen($bio) < 100 || mb_strlen($bio) > 500) {
            $this->throwValidationException('Описание должно быть от 100 до 500 символов');
        }

        $philosophy = trim($input['coach_philosophy'] ?? '') ?: null;
        $experienceYears = isset($input['coach_experience_years']) ? (int)$input['coach_experience_years'] : null;
        if ($experienceYears !== null && ($experienceYears < 1 || $experienceYears > 50)) {
            $this->throwValidationException('Опыт должен быть от 1 до 50 лет');
        }

        $runnerAchievements = trim($input['coach_runner_achievements'] ?? '') ?: null;
        $athleteAchievements = trim($input['coach_athlete_achievements'] ?? '') ?: null;
        $certifications = trim($input['coach_certifications'] ?? '') ?: null;
        $contactsExtra = trim($input['coach_contacts_extra'] ?? '') ?: null;
        $acceptsNew = isset($input['coach_accepts_new']) ? (int)$input['coach_accepts_new'] : 1;
        $pricesOnRequest = isset($input['coach_prices_on_request']) ? (int)$input['coach_prices_on_request'] : 0;
        $pricingJson = isset($input['coach_pricing']) ? json_encode($input['coach_pricing'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->db->prepare("
            INSERT INTO coach_applications
            (user_id, coach_specialization, coach_bio, coach_philosophy, coach_experience_years,
             coach_runner_achievements, coach_athlete_achievements, coach_certifications,
             coach_contacts_extra, coach_accepts_new, coach_prices_on_request, coach_pricing_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssissssiis",
            $userId, $specialization, $bio, $philosophy, $experienceYears,
            $runnerAchievements, $athleteAchievements, $certifications,
            $contactsExtra, $acceptsNew, $pricesOnRequest, $pricingJson
        );
        $stmt->execute();
        $applicationId = $stmt->insert_id;
        $stmt->close();

        return $applicationId;
    }

    // ==================== АТЛЕТЫ ТРЕНЕРА ====================

    public function getCoachAthletes(int $coachId): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.username_slug, u.avatar_path, u.last_activity,
                   u.goal_type, u.race_date, u.race_distance, u.race_target_time,
                   (SELECT COUNT(*) FROM plan_notifications pn
                    WHERE pn.user_id = ? AND pn.type = 'athlete_result_logged'
                      AND pn.read_at IS NULL
                      AND JSON_UNQUOTE(JSON_EXTRACT(pn.metadata, '$.athlete_id')) = CAST(u.id AS CHAR)
                   ) AS unread_results
            FROM user_coaches uc
            JOIN users u ON uc.user_id = u.id
            WHERE uc.coach_id = ?
            ORDER BY u.last_activity DESC
        ");
        $stmt->bind_param("ii", $coachId, $coachId);
        $stmt->execute();
        $result = $stmt->get_result();

        $athletes = [];
        while ($row = $result->fetch_assoc()) {
            $athlete = [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'username_slug' => $row['username_slug'],
                'avatar_path' => $row['avatar_path'],
                'last_activity' => $row['last_activity'],
                'has_new_activity' => (int)$row['unread_results'] > 0,
            ];
            if ($row['goal_type']) $athlete['goal_type'] = $row['goal_type'];
            if ($row['race_date']) $athlete['race_date'] = $row['race_date'];
            if ($row['race_distance']) $athlete['race_distance'] = $row['race_distance'];
            if ($row['race_target_time']) $athlete['race_target_time'] = $row['race_target_time'];
            $athletes[] = $athlete;
        }
        $stmt->close();

        // Группы для каждого атлета
        if (count($athletes) > 0) {
            $athleteIds = array_column($athletes, 'id');
            $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));
            $types = 'i' . str_repeat('i', count($athleteIds));
            $params = array_merge([$coachId], $athleteIds);
            $stmt = $this->db->prepare("
                SELECT gm.user_id, g.id AS group_id, g.name, g.color
                FROM coach_group_members gm
                JOIN coach_athlete_groups g ON gm.group_id = g.id
                WHERE g.coach_id = ? AND gm.user_id IN ($placeholders)
            ");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $groupsByUser = [];
            while ($row = $result->fetch_assoc()) {
                $uid = (int)$row['user_id'];
                $groupsByUser[$uid][] = ['id' => (int)$row['group_id'], 'name' => $row['name'], 'color' => $row['color']];
            }
            $stmt->close();

            foreach ($athletes as &$a) {
                $a['groups'] = $groupsByUser[$a['id']] ?? [];
            }
            unset($a);
        }

        return $athletes;
    }

    // ==================== ЦЕНООБРАЗОВАНИЕ ====================

    public function getPricing(int $coachId): array {
        $stmt = $this->db->prepare("SELECT id, type, label, price, currency, period, sort_order FROM coach_pricing WHERE coach_id = ? ORDER BY sort_order");
        $stmt->bind_param("i", $coachId);
        $stmt->execute();
        $result = $stmt->get_result();

        $pricing = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['price'] = $row['price'] !== null ? (float)$row['price'] : null;
            $row['sort_order'] = (int)$row['sort_order'];
            $pricing[] = $row;
        }
        $stmt->close();

        return $pricing;
    }

    public function updatePricing(int $coachId, array $items, ?int $pricesOnRequest): void {
        if ($pricesOnRequest !== null) {
            $stmt = $this->db->prepare("UPDATE users SET coach_prices_on_request = ? WHERE id = ?");
            $stmt->bind_param("ii", $pricesOnRequest, $coachId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $this->db->prepare("DELETE FROM coach_pricing WHERE coach_id = ?");
        $stmt->bind_param("i", $coachId);
        $stmt->execute();
        $stmt->close();

        if (!empty($items)) {
            $stmt = $this->db->prepare("INSERT INTO coach_pricing (coach_id, type, label, price, currency, period, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $i => $item) {
                $type = $item['type'] ?? 'custom';
                $label = trim($item['label'] ?? '');
                $price = isset($item['price']) ? (float)$item['price'] : null;
                $currency = $item['currency'] ?? 'RUB';
                $period = $item['period'] ?? 'month';
                $sortOrder = $i;

                if (!$label) continue;

                $stmt->bind_param("issdssi", $coachId, $type, $label, $price, $currency, $period, $sortOrder);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    // ==================== ГРУППЫ АТЛЕТОВ ====================

    public function getGroups(int $coachId): array {
        $stmt = $this->db->prepare("
            SELECT g.id, g.name, g.color, g.sort_order,
                   COUNT(gm.user_id) AS member_count
            FROM coach_athlete_groups g
            LEFT JOIN coach_group_members gm ON gm.group_id = g.id
            WHERE g.coach_id = ?
            GROUP BY g.id
            ORDER BY g.sort_order, g.name
        ");
        $stmt->bind_param("i", $coachId);
        $stmt->execute();
        $result = $stmt->get_result();

        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'color' => $row['color'],
                'sort_order' => (int)$row['sort_order'],
                'member_count' => (int)$row['member_count'],
            ];
        }
        $stmt->close();

        return $groups;
    }

    public function saveGroup(int $coachId, string $name, string $color, ?int $groupId): array {
        if ($name === '' || mb_strlen($name) > 100) {
            $this->throwValidationException('Название группы обязательно (макс. 100 символов)');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#6366f1';
        }

        if ($groupId) {
            $stmt = $this->db->prepare("UPDATE coach_athlete_groups SET name = ?, color = ? WHERE id = ? AND coach_id = ?");
            $stmt->bind_param("ssii", $name, $color, $groupId, $coachId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $this->throwNotFoundException('Группа не найдена');
            }
            $stmt->close();
            return ['updated' => true, 'group_id' => $groupId];
        }

        $stmt = $this->db->prepare("INSERT INTO coach_athlete_groups (coach_id, name, color) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $coachId, $name, $color);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        return ['group_id' => (int)$newId];
    }

    public function deleteGroup(int $coachId, int $groupId): void {
        $this->requireGroupOwnership($coachId, $groupId);

        $stmt = $this->db->prepare("DELETE FROM coach_group_members WHERE group_id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare("DELETE FROM coach_athlete_groups WHERE id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();
    }

    public function getGroupMembers(int $coachId, int $groupId): array {
        $this->requireGroupOwnership($coachId, $groupId);

        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.username_slug, u.avatar_path
            FROM coach_group_members gm
            JOIN users u ON gm.user_id = u.id
            WHERE gm.group_id = ?
            ORDER BY u.username
        ");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $result = $stmt->get_result();

        $members = [];
        while ($row = $result->fetch_assoc()) {
            $members[] = [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'username_slug' => $row['username_slug'],
                'avatar_path' => $row['avatar_path'],
            ];
        }
        $stmt->close();

        return $members;
    }

    public function updateGroupMembers(int $coachId, int $groupId, array $userIds): int {
        $this->requireGroupOwnership($coachId, $groupId);

        // Валидация: только атлеты этого тренера
        $validIds = [];
        if (count($userIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $types = str_repeat('i', count($userIds) + 1);
            $params = array_merge([$coachId], array_map('intval', $userIds));
            $stmt = $this->db->prepare("SELECT user_id FROM user_coaches WHERE coach_id = ? AND user_id IN ($placeholders)");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $validIds[] = (int)$row['user_id'];
            }
            $stmt->close();
        }

        // Замена
        $stmt = $this->db->prepare("DELETE FROM coach_group_members WHERE group_id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();

        if (count($validIds) > 0) {
            $stmt = $this->db->prepare("INSERT INTO coach_group_members (group_id, user_id) VALUES (?, ?)");
            foreach ($validIds as $uid) {
                $stmt->bind_param("ii", $groupId, $uid);
                $stmt->execute();
            }
            $stmt->close();
        }

        return count($validIds);
    }

    public function getAthleteGroups(int $coachId, int $userId): array {
        $stmt = $this->db->prepare("
            SELECT g.id, g.name, g.color
            FROM coach_group_members gm
            JOIN coach_athlete_groups g ON gm.group_id = g.id
            WHERE gm.user_id = ? AND g.coach_id = ?
            ORDER BY g.sort_order, g.name
        ");
        $stmt->bind_param("ii", $userId, $coachId);
        $stmt->execute();
        $result = $stmt->get_result();

        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'color' => $row['color'],
            ];
        }
        $stmt->close();

        return $groups;
    }

    // ==================== АДМИН: ЗАЯВКИ НА РОЛЬ ТРЕНЕРА ====================

    /**
     * Получить список заявок на роль тренера (для админки).
     */
    public function getApplications(string $status, int $limit, int $offset): array {
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
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        return ['applications' => $applications, 'total' => $total];
    }

    /**
     * Одобрить заявку: обновить роль пользователя, скопировать pricing, отметить заявку.
     */
    public function approveApplication(int $applicationId, int $reviewerId): array {
        $stmt = $this->db->prepare("SELECT * FROM coach_applications WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $applicationId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$app) {
            $this->throwNotFoundException('Заявка не найдена или уже обработана');
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
        $stmt->bind_param("ii", $reviewerId, $applicationId);
        $stmt->execute();
        $stmt->close();

        return ['approved' => true, 'user_id' => $userId];
    }

    /**
     * Отклонить заявку.
     */
    public function rejectApplication(int $applicationId, int $reviewerId): void {
        $stmt = $this->db->prepare("SELECT id FROM coach_applications WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $applicationId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            $this->throwNotFoundException('Заявка не найдена или уже обработана');
        }
        $stmt->close();

        $stmt = $this->db->prepare("UPDATE coach_applications SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
        $stmt->bind_param("ii", $reviewerId, $applicationId);
        $stmt->execute();
        $stmt->close();
    }

    // ==================== ПРОВЕРКИ ====================

    public function isCoachOrAdmin(int $userId): bool {
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && in_array($row['role'], ['coach', 'admin']);
    }

    // ==================== ПРИВАТНЫЕ ХЕЛПЕРЫ ====================

    private function loadPricing(int $coachId): array {
        $stmt = $this->db->prepare("SELECT type, label, price, currency, period FROM coach_pricing WHERE coach_id = ? ORDER BY sort_order");
        $stmt->bind_param("i", $coachId);
        $stmt->execute();
        $result = $stmt->get_result();
        $pricing = [];
        while ($p = $result->fetch_assoc()) {
            $p['price'] = $p['price'] !== null ? (float)$p['price'] : null;
            $pricing[] = $p;
        }
        $stmt->close();
        return $pricing;
    }

    private function requireGroupOwnership(int $coachId, int $groupId): void {
        $stmt = $this->db->prepare("SELECT id FROM coach_athlete_groups WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $groupId, $coachId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$found) {
            $this->throwNotFoundException('Группа не найдена');
        }
    }
}
