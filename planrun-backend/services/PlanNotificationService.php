<?php
/**
 * Сервис для отправки in-app уведомлений между тренером и атлетом.
 * Типы: coach_plan_updated, athlete_result_logged
 */

class PlanNotificationService {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Отправить уведомление пользователю.
     * @param int $userId - кому
     * @param string $type - тип (coach_plan_updated, athlete_result_logged, coach_note_added, ...)
     * @param string $message - текст
     * @param array|null $metadata - доп. данные (JSON)
     */
    public function notify($userId, $type, $message, $metadata = null) {
        $sql = "INSERT INTO plan_notifications (user_id, type, message, metadata) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return;
        $metaJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
        $stmt->bind_param('isss', $userId, $type, $message, $metaJson);
        $stmt->execute();
        $stmt->close();

        $eventKey = $this->mapTypeToEventKey((string) $type, is_array($metadata) ? $metadata : []);
        if ($eventKey === null) {
            return;
        }

        try {
            require_once __DIR__ . '/NotificationDispatcher.php';
            $dispatcher = new NotificationDispatcher($this->db);
            $metadata = is_array($metadata) ? $metadata : [];
            $link = '/calendar';
            if ($eventKey === 'coach.athlete_result_logged' && !empty($metadata['athlete_slug'])) {
                $params = [
                    'athlete' => (string) $metadata['athlete_slug'],
                ];
                if (!empty($metadata['date'])) {
                    $params['date'] = (string) $metadata['date'];
                }
                $link .= '?' . http_build_query($params);
            } elseif (!empty($metadata['date'])) {
                $link .= '?date=' . rawurlencode((string) $metadata['date']);
            } elseif (!empty($metadata['athlete_slug'])) {
                $link = '/calendar?athlete=' . rawurlencode((string) $metadata['athlete_slug']);
            }
            $dispatcher->dispatchToUser((int) $userId, $eventKey, 'Обновление плана', (string) $message, [
                'link' => $link,
                'plan_action' => (string) ($metadata['action'] ?? ''),
                'plan_date' => (string) ($metadata['date'] ?? ''),
                'athlete_slug' => (string) ($metadata['athlete_slug'] ?? ''),
                'athlete_name' => !empty($metadata['athlete_id']) ? $this->getUsername((int) $metadata['athlete_id']) : '',
                'push_data' => [
                    'type' => 'plan',
                    'link' => $link,
                ],
            ]);
        } catch (\Throwable $e) {
            // Внешняя доставка не должна ломать in-app уведомление.
        }
    }

    /**
     * Уведомить атлета о том, что тренер обновил план.
     * @param int $athleteId - ID атлета
     * @param int $coachId - ID тренера
     * @param string $action - 'add'|'update'|'delete'|'copy'|'note'
     * @param string|null $date - дата тренировки (Y-m-d)
     */
    public function notifyCoachPlanUpdated($athleteId, $coachId, $action = 'update', $date = null) {
        // Получаем имя тренера
        $coachName = $this->getUsername($coachId);

        $actionLabels = [
            'add' => 'добавил тренировку',
            'update' => 'обновил план',
            'delete' => 'удалил тренировку',
            'copy' => 'скопировал тренировку',
            'note' => 'оставил заметку',
        ];
        $actionText = $actionLabels[$action] ?? 'обновил план';
        $dateText = $date ? " на {$date}" : '';
        $message = "Тренер {$coachName} {$actionText}{$dateText}";

        $this->notify($athleteId, 'coach_plan_updated', $message, [
            'coach_id' => $coachId,
            'action' => $action,
            'date' => $date,
        ]);
    }

    /**
     * Уведомить тренеров атлета о внесённом результате.
     * @param int $athleteId - ID атлета
     * @param string|null $date - дата тренировки
     */
    public function notifyAthleteResultLogged($athleteId, $date = null) {
        $athleteName = $this->getUsername($athleteId);
        $athleteSlug = $this->getUsernameSlug($athleteId);
        $dateText = $date ? " за {$date}" : '';
        $message = "Атлет {$athleteName} внёс результат{$dateText}";

        // Находим всех тренеров атлета
        $coaches = $this->getCoachesForAthlete($athleteId);
        foreach ($coaches as $coachId) {
            $this->notify($coachId, 'athlete_result_logged', $message, [
                'athlete_id' => $athleteId,
                'athlete_slug' => $athleteSlug,
                'date' => $date,
            ]);
        }
    }

    /**
     * Получить непрочитанные уведомления пользователя.
     */
    public function getUnread($userId, $limit = 20) {
        $sql = "SELECT id, type, message, metadata, created_at
                FROM plan_notifications
                WHERE user_id = ? AND read_at IS NULL
                ORDER BY created_at DESC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['metadata']) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Отметить уведомление прочитанным.
     */
    public function markRead($notificationId, $userId) {
        $sql = "UPDATE plan_notifications SET read_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $notificationId, $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

    /**
     * Отметить все уведомления прочитанными.
     */
    public function markAllRead($userId) {
        $sql = "UPDATE plan_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function getUsername($userId) {
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['username'] ?? 'Пользователь';
    }

    private function getUsernameSlug($userId) {
        $stmt = $this->db->prepare("SELECT username_slug FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['username_slug'] ?? null;
    }

    private function getCoachesForAthlete($athleteId) {
        $coaches = [];
        $tableCheck = $this->db->query("SHOW TABLES LIKE 'user_coaches'");
        if (!$tableCheck || $tableCheck->num_rows === 0) return $coaches;

        $stmt = $this->db->prepare("SELECT coach_id FROM user_coaches WHERE user_id = ?");
        $stmt->bind_param('i', $athleteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $coaches[] = (int) $row['coach_id'];
        }
        $stmt->close();
        return $coaches;
    }

    private function mapTypeToEventKey(string $type, array $metadata): ?string {
        if ($type === 'athlete_result_logged') {
            return 'coach.athlete_result_logged';
        }
        if ($type === 'coach_plan_updated') {
            return ($metadata['action'] ?? null) === 'note'
                ? 'plan.coach_note_added'
                : 'plan.coach_updated';
        }
        return null;
    }
}
