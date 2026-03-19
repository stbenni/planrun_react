<?php
/**
 * CoachController — API для раздела «Тренеры»
 *
 * Каталог тренеров, запросы, управление связями, заявки, ценообразование
 */

class CoachController extends BaseController {

    /**
     * Проверка что текущий пользователь — тренер или админ
     */
    private function isCoachOrAdmin() {
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param("i", $this->currentUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && in_array($row['role'], ['coach', 'admin']);
    }

    /**
     * GET list_coaches — каталог тренеров с пагинацией и фильтрами
     */
    public function listCoaches() {
        $offset = max(0, (int)($this->getParam('offset', 0)));
        $limit = min(50, max(1, (int)($this->getParam('limit', 20))));
        $specialization = $this->getParam('specialization');
        $acceptsNew = $this->getParam('accepts_new');

        $where = ["u.role = 'coach'"];
        $params = [];
        $types = '';

        if ($specialization) {
            $where[] = "JSON_CONTAINS(u.coach_specialization, ?)";
            $params[] = '"' . $specialization . '"';
            $types .= 's';
        }
        if ($acceptsNew !== null && $acceptsNew !== '') {
            $where[] = "u.coach_accepts = ?";
            $params[] = (int)$acceptsNew;
            $types .= 'i';
        }

        $whereClause = implode(' AND ', $where);

        // Считаем total
        $countSql = "SELECT COUNT(*) as total FROM users u WHERE $whereClause";
        if ($params) {
            $stmt = $this->db->prepare($countSql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $res = $this->db->query($countSql);
            $total = $res->fetch_assoc()['total'];
        }

        // Получаем список
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

            // Подгружаем pricing
            $pricingStmt = $this->db->prepare("SELECT type, label, price, currency, period FROM coach_pricing WHERE coach_id = ? ORDER BY sort_order");
            $pricingStmt->bind_param("i", $coachId);
            $pricingStmt->execute();
            $pricingResult = $pricingStmt->get_result();
            $pricing = [];
            while ($p = $pricingResult->fetch_assoc()) {
                $p['price'] = $p['price'] !== null ? (float)$p['price'] : null;
                $pricing[] = $p;
            }
            $pricingStmt->close();

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

        $this->returnSuccess([
            'coaches' => $coaches,
            'total' => (int)$total,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * POST request_coach — запрос на тренировку
     */
    public function requestCoach() {
        if (!$this->requireAuth()) return;

        $input = $this->getJsonBody() ?: $_POST;
        $coachId = (int)($input['coach_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if (!$coachId) {
            $this->returnError('coach_id обязателен', 400);
        }

        // Self-request
        if ($coachId === $this->currentUserId) {
            $this->returnError('Нельзя запросить себя как тренера', 400);
        }

        // Проверяем что тренер существует и принимает
        $stmt = $this->db->prepare("SELECT role, coach_accepts FROM users WHERE id = ? AND role IN ('coach', 'admin')");
        $stmt->bind_param("i", $coachId);
        $stmt->execute();
        $coach = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$coach) {
            $this->returnError('Тренер не найден', 404);
        }
        if (!(bool)$coach['coach_accepts']) {
            $this->returnError('Тренер сейчас не принимает новых учеников', 400);
        }

        // Дубликат pending
        $stmt = $this->db->prepare("SELECT id FROM coach_requests WHERE user_id = ? AND coach_id = ? AND status = 'pending'");
        $stmt->bind_param("ii", $this->currentUserId, $coachId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            $this->returnError('У вас уже есть активный запрос к этому тренеру', 400);
        }
        $stmt->close();

        // Уже связаны
        $stmt = $this->db->prepare("SELECT id FROM user_coaches WHERE user_id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $this->currentUserId, $coachId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            $this->returnError('Этот тренер уже ваш тренер', 400);
        }
        $stmt->close();

        $stmt = $this->db->prepare("INSERT INTO coach_requests (user_id, coach_id, message) VALUES (?, ?, ?)");
        $msgVal = $message ?: null;
        $stmt->bind_param("iis", $this->currentUserId, $coachId, $msgVal);
        $stmt->execute();
        $requestId = $stmt->insert_id;
        $stmt->close();

        $this->returnSuccess(['request_id' => $requestId]);
    }

    /**
     * GET coach_requests — pending-запросы для текущего тренера
     */
    public function getCoachRequests() {
        if (!$this->requireAuth()) return;
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $offset = max(0, (int)($this->getParam('offset', 0)));
        $limit = min(50, max(1, (int)($this->getParam('limit', 20))));
        $status = $this->getParam('status', 'pending');

        $stmt = $this->db->prepare("
            SELECT cr.id, cr.user_id, cr.status, cr.message, cr.created_at,
                   u.username, u.username_slug, u.avatar_path
            FROM coach_requests cr
            JOIN users u ON cr.user_id = u.id
            WHERE cr.coach_id = ? AND cr.status = ?
            ORDER BY cr.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("isii", $this->currentUserId, $status, $limit, $offset);
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

        // Total
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM coach_requests WHERE coach_id = ? AND status = ?");
        $stmt->bind_param("is", $this->currentUserId, $status);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $this->returnSuccess(['requests' => $requests, 'total' => (int)$total]);
    }

    /**
     * POST accept_coach_request
     */
    public function acceptCoachRequest() {
        if (!$this->requireAuth()) return;

        $input = $this->getJsonBody() ?: $_POST;
        $requestId = (int)($input['request_id'] ?? 0);

        $stmt = $this->db->prepare("SELECT id, user_id, coach_id, status FROM coach_requests WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $requestId, $this->currentUserId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            $this->returnError('Запрос не найден', 404);
        }
        if ($request['status'] !== 'pending') {
            $this->returnError('Запрос уже обработан', 400);
        }

        $athleteId = (int)$request['user_id'];

        // Обновляем статус
        $stmt = $this->db->prepare("UPDATE coach_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();

        // Создаём связь
        $stmt = $this->db->prepare("INSERT IGNORE INTO user_coaches (user_id, coach_id, can_view, can_edit) VALUES (?, ?, 1, 1)");
        $stmt->bind_param("ii", $athleteId, $this->currentUserId);
        $stmt->execute();
        $stmt->close();

        $this->returnSuccess(['accepted' => true]);
    }

    /**
     * POST reject_coach_request
     */
    public function rejectCoachRequest() {
        if (!$this->requireAuth()) return;

        $input = $this->getJsonBody() ?: $_POST;
        $requestId = (int)($input['request_id'] ?? 0);

        $stmt = $this->db->prepare("SELECT id, user_id, status FROM coach_requests WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $requestId, $this->currentUserId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request || $request['status'] !== 'pending') {
            $this->returnError('Запрос не найден или уже обработан', 400);
        }

        $stmt = $this->db->prepare("UPDATE coach_requests SET status = 'rejected', responded_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();

        $this->returnSuccess(['rejected' => true]);
    }

    /**
     * GET get_my_coaches — тренеры текущего пользователя
     */
    public function getMyCoaches() {
        if (!$this->requireAuth()) return;

        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.username_slug, u.avatar_path, u.coach_bio, u.coach_specialization
            FROM user_coaches uc
            JOIN users u ON uc.coach_id = u.id
            WHERE uc.user_id = ?
        ");
        $stmt->bind_param("i", $this->currentUserId);
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

        $this->returnSuccess(['coaches' => $coaches]);
    }

    /**
     * POST remove_coach — отвязать тренера (может вызвать и атлет, и тренер)
     */
    public function removeCoach() {
        if (!$this->requireAuth()) return;

        $input = $this->getJsonBody() ?: $_POST;
        $coachId = (int)($input['coach_id'] ?? 0);
        $athleteId = (int)($input['athlete_id'] ?? 0);

        // Определяем direction
        if ($coachId && !$athleteId) {
            // Атлет отвязывает тренера
            $stmt = $this->db->prepare("DELETE FROM user_coaches WHERE user_id = ? AND coach_id = ?");
            $stmt->bind_param("ii", $this->currentUserId, $coachId);
        } elseif ($athleteId && !$coachId) {
            // Тренер отвязывает атлета
            $stmt = $this->db->prepare("DELETE FROM user_coaches WHERE user_id = ? AND coach_id = ?");
            $stmt->bind_param("ii", $athleteId, $this->currentUserId);
        } else {
            $this->returnError('Укажите coach_id (для атлета) или athlete_id (для тренера)', 400);
            return;
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            $this->returnError('Связь не найдена', 404);
        }

        $this->returnSuccess(['removed' => true]);
    }

    /**
     * POST apply_coach — заявка «Стать тренером»
     */
    public function applyCoach() {
        if (!$this->requireAuth()) return;

        // Уже тренер?
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param("i", $this->currentUserId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user['role'] === 'coach') {
            $this->returnError('Вы уже тренер', 400);
        }

        // Есть pending заявка?
        $stmt = $this->db->prepare("SELECT id FROM coach_applications WHERE user_id = ? AND status = 'pending'");
        $stmt->bind_param("i", $this->currentUserId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            $this->returnError('У вас уже есть заявка на рассмотрении', 400);
        }
        $stmt->close();

        $input = $this->getJsonBody() ?: $_POST;

        $specialization = $input['coach_specialization'] ?? [];
        if (is_array($specialization)) {
            if (count($specialization) === 0) {
                $this->returnError('Выберите хотя бы одну специализацию', 400);
            }
            $specialization = json_encode($specialization, JSON_UNESCAPED_UNICODE);
        }

        $bio = trim($input['coach_bio'] ?? '');
        if (mb_strlen($bio) < 100 || mb_strlen($bio) > 500) {
            $this->returnError('Описание должно быть от 100 до 500 символов', 400);
        }

        $philosophy = trim($input['coach_philosophy'] ?? '') ?: null;
        $experienceYears = isset($input['coach_experience_years']) ? (int)$input['coach_experience_years'] : null;
        if ($experienceYears !== null && ($experienceYears < 1 || $experienceYears > 50)) {
            $this->returnError('Опыт должен быть от 1 до 50 лет', 400);
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
            $this->currentUserId, $specialization, $bio, $philosophy, $experienceYears,
            $runnerAchievements, $athleteAchievements, $certifications,
            $contactsExtra, $acceptsNew, $pricesOnRequest, $pricingJson
        );
        $stmt->execute();
        $applicationId = $stmt->insert_id;
        $stmt->close();

        $this->returnSuccess(['application_id' => $applicationId]);
    }

    /**
     * GET coach_athletes — список атлетов тренера
     */
    public function getCoachAthletes() {
        if (!$this->requireAuth()) return;
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

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
        $stmt->bind_param("ii", $this->currentUserId, $this->currentUserId);
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

        // Загружаем группы для каждого атлета одним запросом
        if (count($athletes) > 0) {
            $athleteIds = array_column($athletes, 'id');
            $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));
            $types = 'i' . str_repeat('i', count($athleteIds));
            $params = array_merge([$this->currentUserId], $athleteIds);
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

        $this->returnSuccess(['athletes' => $athletes]);
    }

    /**
     * GET get_coach_pricing — цены текущего тренера
     */
    public function getCoachPricing() {
        if (!$this->requireAuth()) return;

        $coachId = (int)($this->getParam('coach_id', $this->currentUserId));

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

        $this->returnSuccess(['pricing' => $pricing]);
    }

    /**
     * POST update_coach_pricing — обновить цены тренера
     */
    public function updateCoachPricing() {
        if (!$this->requireAuth()) return;
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $input = $this->getJsonBody() ?: $_POST;
        $items = $input['pricing'] ?? [];
        $pricesOnRequest = isset($input['prices_on_request']) ? (int)$input['prices_on_request'] : null;

        // Обновляем флаг prices_on_request
        if ($pricesOnRequest !== null) {
            $stmt = $this->db->prepare("UPDATE users SET coach_prices_on_request = ? WHERE id = ?");
            $stmt->bind_param("ii", $pricesOnRequest, $this->currentUserId);
            $stmt->execute();
            $stmt->close();
        }

        // Удаляем старые и вставляем новые
        $stmt = $this->db->prepare("DELETE FROM coach_pricing WHERE coach_id = ?");
        $stmt->bind_param("i", $this->currentUserId);
        $stmt->execute();
        $stmt->close();

        if (is_array($items)) {
            $stmt = $this->db->prepare("INSERT INTO coach_pricing (coach_id, type, label, price, currency, period, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $i => $item) {
                $type = $item['type'] ?? 'custom';
                $label = trim($item['label'] ?? '');
                $price = isset($item['price']) ? (float)$item['price'] : null;
                $currency = $item['currency'] ?? 'RUB';
                $period = $item['period'] ?? 'month';
                $sortOrder = $i;

                if (!$label) continue;

                $stmt->bind_param("issdssi", $this->currentUserId, $type, $label, $price, $currency, $period, $sortOrder);
                $stmt->execute();
            }
            $stmt->close();
        }

        $this->returnSuccess(['updated' => true]);
    }

    // ==================== ГРУППЫ АТЛЕТОВ ====================

    /**
     * GET get_coach_groups — список групп тренера с количеством участников
     */
    public function getCoachGroups() {
        if (!$this->requireAuth()) return;
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $stmt = $this->db->prepare("
            SELECT g.id, g.name, g.color, g.sort_order,
                   COUNT(gm.user_id) AS member_count
            FROM coach_athlete_groups g
            LEFT JOIN coach_group_members gm ON gm.group_id = g.id
            WHERE g.coach_id = ?
            GROUP BY g.id
            ORDER BY g.sort_order, g.name
        ");
        $stmt->bind_param("i", $this->currentUserId);
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

        $this->returnSuccess(['groups' => $groups]);
    }

    /**
     * POST save_coach_group — создать или обновить группу
     * Body: { "name": "...", "color": "#hex", "group_id": null|int }
     */
    public function saveCoachGroup() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $data = $this->getJsonBody();
        $name = trim($data['name'] ?? '');
        $color = $data['color'] ?? '#6366f1';
        $groupId = $data['group_id'] ?? null;

        if ($name === '' || mb_strlen($name) > 100) {
            $this->returnError('Название группы обязательно (макс. 100 символов)', 400);
            return;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#6366f1';
        }

        if ($groupId) {
            // Обновление — только своя группа
            $stmt = $this->db->prepare("UPDATE coach_athlete_groups SET name = ?, color = ? WHERE id = ? AND coach_id = ?");
            $stmt->bind_param("ssii", $name, $color, $groupId, $this->currentUserId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $this->returnError('Группа не найдена', 404);
                return;
            }
            $stmt->close();
            $this->returnSuccess(['updated' => true, 'group_id' => (int)$groupId]);
        } else {
            // Создание
            $stmt = $this->db->prepare("INSERT INTO coach_athlete_groups (coach_id, name, color) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $this->currentUserId, $name, $color);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            $this->returnSuccess(['group_id' => (int)$newId]);
        }
    }

    /**
     * POST delete_coach_group — удалить группу (и связи)
     * Body: { "group_id": 123 }
     */
    public function deleteCoachGroup() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $data = $this->getJsonBody();
        $groupId = (int)($data['group_id'] ?? 0);
        if (!$groupId) {
            $this->returnError('group_id обязателен', 400);
            return;
        }

        // Проверяем владение
        $stmt = $this->db->prepare("SELECT id FROM coach_athlete_groups WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $groupId, $this->currentUserId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            $this->returnError('Группа не найдена', 404);
            return;
        }
        $stmt->close();

        // Удаляем участников и группу
        $stmt = $this->db->prepare("DELETE FROM coach_group_members WHERE group_id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare("DELETE FROM coach_athlete_groups WHERE id = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();

        $this->returnSuccess(['deleted' => true]);
    }

    /**
     * GET get_group_members — участники группы
     * ?group_id=123
     */
    public function getGroupMembers() {
        if (!$this->requireAuth()) return;
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $groupId = (int)$this->getParam('group_id', 0);
        if (!$groupId) {
            $this->returnError('group_id обязателен', 400);
            return;
        }

        // Проверяем владение группой
        $stmt = $this->db->prepare("SELECT id FROM coach_athlete_groups WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $groupId, $this->currentUserId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            $this->returnError('Группа не найдена', 404);
            return;
        }
        $stmt->close();

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

        $this->returnSuccess(['members' => $members]);
    }

    /**
     * POST update_group_members — установить список участников группы
     * Body: { "group_id": 123, "user_ids": [1, 2, 3] }
     */
    public function updateGroupMembers() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $data = $this->getJsonBody();
        $groupId = (int)($data['group_id'] ?? 0);
        $userIds = $data['user_ids'] ?? [];

        if (!$groupId) {
            $this->returnError('group_id обязателен', 400);
            return;
        }
        if (!is_array($userIds)) {
            $this->returnError('user_ids должен быть массивом', 400);
            return;
        }

        // Проверяем владение группой
        $stmt = $this->db->prepare("SELECT id FROM coach_athlete_groups WHERE id = ? AND coach_id = ?");
        $stmt->bind_param("ii", $groupId, $this->currentUserId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            $this->returnError('Группа не найдена', 404);
            return;
        }
        $stmt->close();

        // Проверяем что все user_ids — атлеты этого тренера
        $validIds = [];
        if (count($userIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $types = str_repeat('i', count($userIds) + 1);
            $params = array_merge([$this->currentUserId], array_map('intval', $userIds));
            $stmt = $this->db->prepare("SELECT user_id FROM user_coaches WHERE coach_id = ? AND user_id IN ($placeholders)");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $validIds[] = (int)$row['user_id'];
            }
            $stmt->close();
        }

        // Удаляем старых и добавляем новых
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

        $this->returnSuccess(['member_count' => count($validIds)]);
    }

    /**
     * GET get_athlete_groups — группы конкретного атлета (для карточки)
     * ?user_id=123
     */
    public function getAthleteGroups() {
        if (!$this->requireAuth()) return;
        if (!$this->isCoachOrAdmin()) {
            $this->returnError('Доступно только тренерам', 403);
        }

        $userId = (int)$this->getParam('user_id', 0);
        if (!$userId) {
            $this->returnError('user_id обязателен', 400);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT g.id, g.name, g.color
            FROM coach_group_members gm
            JOIN coach_athlete_groups g ON gm.group_id = g.id
            WHERE gm.user_id = ? AND g.coach_id = ?
            ORDER BY g.sort_order, g.name
        ");
        $stmt->bind_param("ii", $userId, $this->currentUserId);
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

        $this->returnSuccess(['groups' => $groups]);
    }
}
