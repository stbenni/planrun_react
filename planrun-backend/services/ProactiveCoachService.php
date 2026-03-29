<?php
/**
 * Проактивный AI-тренер: обнаруживает события и отправляет персонализированные сообщения.
 *
 * События:
 * 1. Пауза (>3 дней без тренировок) → мотивация + предложение помощи
 * 2. Перегрузка (ACWR >1.5 или TSB < -30) → предупреждение + рекомендация
 * 3. Приближающийся забег (<14 дней) → напоминание + совет
 * 4. Milestone (новый рекорд дистанции/темпа) → поздравление
 * 5. Низкое выполнение (<40% за 2 недели) → мягкое предложение пересчёта
 */

require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class ProactiveCoachService {

    private $db;
    private string $llmBaseUrl;
    private string $llmModel;

    private const COOLDOWN_HOURS = 48;

    public function __construct($db) {
        $this->db = $db;
        $this->llmBaseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $this->llmModel = env('LLM_CHAT_MODEL', 'mistralai/ministral-3-14b-reasoning');
    }

    /**
     * Обработать всех активных пользователей и отправить проактивные сообщения.
     * @return array Статистика: sent, skipped, errors
     */
    public function processAllUsers(): array {
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        $users = $this->getActiveUsers();
        Logger::info('ProactiveCoach: processing users', ['count' => count($users)]);

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            try {
                $events = $this->detectEvents($userId, $user);
                if (empty($events)) { $stats['skipped']++; continue; }

                $priorityEvent = $this->pickPriorityEvent($events);
                if ($this->isOnCooldown($userId, $priorityEvent['type'])) { $stats['skipped']++; continue; }

                $message = $this->generateMessage($userId, $user, $priorityEvent);
                if ($message === '') { $stats['skipped']++; continue; }

                $this->sendProactiveMessage($userId, $message, $priorityEvent);
                $this->recordCooldown($userId, $priorityEvent['type']);
                $stats['sent']++;
                $stats['details'][] = ['userId' => $userId, 'event' => $priorityEvent['type']];
            } catch (Throwable $e) {
                Logger::warning('ProactiveCoach: user error', ['userId' => $userId, 'error' => $e->getMessage()]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Detect events for a specific user.
     * @return array[] Array of event descriptors
     */
    public function detectEvents(int $userId, ?array $user = null): array {
        if ($user === null) {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$user) return [];
        }

        $events = [];
        $tz = $this->getUserTz($userId, $user);

        $pauseEvent = $this->detectPause($userId, $tz);
        if ($pauseEvent) $events[] = $pauseEvent;

        $overloadEvent = $this->detectOverload($userId);
        if ($overloadEvent) $events[] = $overloadEvent;

        $raceEvent = $this->detectUpcomingRace($user, $tz);
        if ($raceEvent) $events[] = $raceEvent;

        $complianceEvent = $this->detectLowCompliance($userId, $tz);
        if ($complianceEvent) $events[] = $complianceEvent;

        $milestoneEvent = $this->detectMilestone($userId, $tz);
        if ($milestoneEvent) $events[] = $milestoneEvent;

        $goalMilestones = $this->detectGoalMilestones($userId);
        foreach ($goalMilestones as $gm) $events[] = $gm;

        return $events;
    }

    // ── Event detectors ──

    private function detectPause(int $userId, DateTimeZone $tz): ?array {
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $stmt = $this->db->prepare(
            "(SELECT training_date AS d FROM workout_log WHERE user_id = ? AND is_completed = 1 ORDER BY training_date DESC LIMIT 1)
             UNION ALL
             (SELECT DATE(start_time) AS d FROM workouts WHERE user_id = ? ORDER BY start_time DESC LIMIT 1)
             ORDER BY d DESC LIMIT 1"
        );
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;

        $lastDate = $row['d'];
        $daysSince = (int) (new DateTime($today, $tz))->diff(new DateTime($lastDate, $tz))->days;

        if ($daysSince >= 4 && $daysSince <= 14) {
            return ['type' => 'pause', 'priority' => 3, 'data' => ['days' => $daysSince, 'last_date' => $lastDate]];
        }
        return null;
    }

    private function detectOverload(int $userId): ?array {
        require_once __DIR__ . '/ChatContextBuilder.php';
        $ctx = new ChatContextBuilder($this->db);
        $acwr = $ctx->calculateACWR($userId);
        if (($acwr['zone'] ?? '') === 'danger') {
            return ['type' => 'overload', 'priority' => 5, 'data' => ['acwr' => $acwr['acwr'], 'zone' => 'danger']];
        }
        if (($acwr['zone'] ?? '') === 'caution' && ($acwr['acwr'] ?? 0) > 1.4) {
            return ['type' => 'overload_warning', 'priority' => 2, 'data' => ['acwr' => $acwr['acwr'], 'zone' => 'caution']];
        }
        return null;
    }

    private function detectUpcomingRace(array $user, DateTimeZone $tz): ?array {
        $raceDate = $user['race_date'] ?? null;
        if (!$raceDate) return null;

        $today = new DateTime('now', $tz);
        $race = DateTime::createFromFormat('Y-m-d', $raceDate);
        if (!$race) return null;

        $daysUntil = (int) $today->diff($race)->days;
        $isFuture = $race > $today;

        if ($isFuture && $daysUntil <= 14 && $daysUntil >= 1) {
            return [
                'type' => 'race_approaching', 'priority' => 4,
                'data' => ['days_until' => $daysUntil, 'race_date' => $raceDate, 'race_distance' => $user['race_distance'] ?? null],
            ];
        }
        return null;
    }

    private function detectLowCompliance(int $userId, DateTimeZone $tz): ?array {
        require_once __DIR__ . '/ChatContextBuilder.php';
        $ctx = new ChatContextBuilder($this->db);
        $compliance = $ctx->getWeeklyCompliance($userId);
        $planned = $compliance['planned'] ?? 0;
        $completed = $compliance['completed'] ?? 0;
        if ($planned < 4) return null;
        $pct = round(($completed / $planned) * 100);
        if ($pct < 40) {
            return ['type' => 'low_compliance', 'priority' => 2, 'data' => ['completed' => $completed, 'planned' => $planned, 'percent' => $pct]];
        }
        return null;
    }

    private function detectMilestone(int $userId, DateTimeZone $tz): ?array {
        $yesterday = (clone new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
        $stmt = $this->db->prepare(
            "(SELECT distance_km FROM workout_log WHERE user_id = ? AND is_completed = 1 AND training_date = ?)
             UNION ALL
             (SELECT distance_km FROM workouts WHERE user_id = ? AND DATE(start_time) = ?)
             ORDER BY distance_km DESC LIMIT 1"
        );
        $stmt->bind_param('isis', $userId, $yesterday, $userId, $yesterday);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (float) ($row['distance_km'] ?? 0) < 5) return null;

        $yesterdayKm = (float) $row['distance_km'];

        $stmt2 = $this->db->prepare(
            "(SELECT MAX(distance_km) as max_km FROM workout_log WHERE user_id = ? AND is_completed = 1 AND training_date < ?)
             UNION ALL
             (SELECT MAX(distance_km) as max_km FROM workouts WHERE user_id = ? AND DATE(start_time) < ?)
             ORDER BY max_km DESC LIMIT 1"
        );
        $stmt2->bind_param('isis', $userId, $yesterday, $userId, $yesterday);
        $stmt2->execute();
        $row2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $prevMax = (float) ($row2['max_km'] ?? 0);

        if ($yesterdayKm > $prevMax && $prevMax > 0) {
            return [
                'type' => 'distance_record', 'priority' => 3,
                'data' => ['distance_km' => $yesterdayKm, 'previous_max' => $prevMax, 'date' => $yesterday],
            ];
        }
        return null;
    }

    private function detectGoalMilestones(int $userId): array {
        try {
            require_once __DIR__ . '/GoalProgressService.php';
            $gps = new GoalProgressService($this->db);
            return $gps->detectMilestones($userId);
        } catch (Throwable $e) {
            Logger::debug('ProactiveCoach: goal milestones unavailable', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ── Message generation ──

    private function pickPriorityEvent(array $events): array {
        usort($events, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));
        return $events[0];
    }

    private function generateMessage(int $userId, array $user, array $event): string {
        $type = $event['type'];
        $data = $event['data'] ?? [];
        $name = $user['username'] ?? 'спортсмен';

        $eventDescription = match ($type) {
            'pause' => "Пользователь {$name} не тренировался {$data['days']} дней. Последняя тренировка: {$data['last_date']}.",
            'overload' => "ACWR пользователя {$name} = {$data['acwr']} (зона: ОПАСНО). Высокий риск травмы.",
            'overload_warning' => "ACWR пользователя {$name} = {$data['acwr']} (зона: предупреждение). Нагрузка растёт.",
            'race_approaching' => "До забега пользователя {$name} осталось {$data['days_until']} дней (дистанция: {$data['race_distance']}).",
            'low_compliance' => "Пользователь {$name} выполнил {$data['completed']} из {$data['planned']} тренировок ({$data['percent']}%) за 2 недели.",
            'distance_record' => "Пользователь {$name} вчера пробежал рекордные {$data['distance_km']} км (предыдущий рекорд: {$data['previous_max']} км).",
            'vdot_improvement' => "VDOT пользователя {$name} вырос на {$data['delta']} (с {$data['previous_vdot']} до {$data['current_vdot']}). Форма растёт!",
            'volume_record' => "Пользователь {$name} пробежал рекордный недельный объём: {$data['weekly_km']} км.",
            'consistency_streak' => "Пользователь {$name} стабильно тренируется уже {$data['weeks']} недель подряд.",
            'goal_achievable' => "Прогнозное время пользователя {$name} впервые стало быстрее целевого (прогноз vs цель: " . gmdate('H:i:s', $data['predicted_sec']) . " vs " . gmdate('H:i:s', $data['target_sec']) . ").",
            default => '',
        };

        if ($eventDescription === '') return '';

        $prompt = <<<PROMPT
Ты — PlanRun, тренер по бегу. Напиши ОДНО короткое (2-3 предложения) проактивное сообщение для спортсмена.

СОБЫТИЕ: {$eventDescription}

ПРАВИЛА:
- СТРОГО на русском.
- Тон: дружелюбный, заботливый тренер.
- При паузе: мягко, без укоров. «Заметил паузу, как ты? Если нужна помощь с планом — напиши».
- При перегрузке: серьёзно. «Нагрузка высокая, рекомендую отдых/лёгкий бег».
- При забеге: поддержка. «Забег близко! Сосредоточься на восстановлении».
- При рекорде: похвала. «Отличный результат!»
- При росте VDOT: воодушевление. «Форма растёт, продолжай!»
- При рекорде объёма: похвала + напоминание про восстановление.
- При серии тренировок: подчеркни стабильность и дисциплину.
- При достижении цели (прогноз быстрее плана): поздравь, настрой на финиш.
- При низком выполнении: без давления. «Заметил, что тренировок стало меньше. Может, пересчитаем план?»
- НЕ используй эмодзи. Без «Привет!» — сразу к делу.
PROMPT;

        $url = $this->llmBaseUrl . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->llmModel,
                'messages' => [['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => 'Напиши проактивное сообщение.']],
                'stream' => false, 'max_tokens' => 200, 'temperature' => 0.7,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            Logger::warning('ProactiveCoach: LLM call failed', ['http' => $httpCode, 'type' => $type]);
            return $this->getFallbackMessage($type, $data);
        }

        $content = trim(json_decode($response, true)['choices'][0]['message']['content'] ?? '');
        return $content !== '' ? $content : $this->getFallbackMessage($type, $data);
    }

    private function getFallbackMessage(string $type, array $data): string {
        return match ($type) {
            'pause' => "Заметил паузу в тренировках ({$data['days']} дней). Как ты? Если нужна помощь с планом — напиши, разберёмся.",
            'overload' => "Нагрузка за последнюю неделю заметно выросла (ACWR {$data['acwr']}). Рекомендую сегодня лёгкий бег или отдых — твоё тело скажет спасибо.",
            'overload_warning' => "Нагрузка растёт (ACWR {$data['acwr']}). Пока всё в пределах нормы, но следи за восстановлением.",
            'race_approaching' => "До забега {$data['days_until']} дней! Сейчас важно: не наращивать объёмы, высыпаться, правильно питаться.",
            'low_compliance' => "Заметил, что выполнение плана снизилось ({$data['percent']}%). Ничего страшного — давай посмотрим, что можно скорректировать.",
            'distance_record' => "Рекорд дистанции — {$data['distance_km']} км! Отличная работа. Не забудь про восстановление после длительной.",
            'vdot_improvement' => "Твоя форма растёт — VDOT увеличился до {$data['current_vdot']}. Тренировки дают результат, продолжай в том же духе.",
            'volume_record' => "Рекордный недельный объём — {$data['weekly_km']} км! Хорошая работа. Следи за восстановлением.",
            'consistency_streak' => "Ты стабильно тренируешься уже {$data['weeks']} недель подряд. Это отличный фундамент для прогресса.",
            'goal_achievable' => "Отличные новости: по текущей форме ты готов выполнить свою цель! Продолжай работать по плану.",
            default => '',
        };
    }

    // ── Sending and cooldown ──

    private function sendProactiveMessage(int $userId, string $message, array $event): void {
        require_once __DIR__ . '/ChatService.php';
        $chatService = new ChatService($this->db);
        $chatService->addAIMessageToUser($userId, $message, [
            'event_key' => 'coach.proactive_' . $event['type'],
            'title' => 'Сообщение от тренера',
            'link' => '/chat',
            'proactive_type' => $event['type'],
        ]);
        Logger::info('ProactiveCoach: message sent', ['userId' => $userId, 'event' => $event['type']]);
    }

    private function isOnCooldown(int $userId, string $eventType): bool {
        $stmt = $this->db->prepare(
            "SELECT created_at FROM proactive_coach_log
             WHERE user_id = ? AND event_type = ?
             ORDER BY created_at DESC LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('is', $userId, $eventType);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;

        $lastSent = new DateTime($row['created_at']);
        $now = new DateTime();
        $hoursSince = ($now->getTimestamp() - $lastSent->getTimestamp()) / 3600;
        return $hoursSince < self::COOLDOWN_HOURS;
    }

    private function recordCooldown(int $userId, string $eventType): void {
        $stmt = $this->db->prepare("INSERT INTO proactive_coach_log (user_id, event_type) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('is', $userId, $eventType);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ── Helpers ──

    private function getActiveUsers(): array {
        $result = $this->db->query(
            "SELECT u.* FROM users u
             WHERE u.onboarding_completed = 1
               AND EXISTS (SELECT 1 FROM training_plan_weeks w WHERE w.user_id = u.id)
             ORDER BY u.id"
        );
        if (!$result) return [];
        $users = [];
        while ($row = $result->fetch_assoc()) $users[] = $row;
        return $users;
    }

    private function getUserTz(int $userId, array $user): DateTimeZone {
        $tz = $user['timezone'] ?? 'Europe/Moscow';
        try { return new DateTimeZone($tz); } catch (Exception $e) { return new DateTimeZone('Europe/Moscow'); }
    }
}
