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
require_once __DIR__ . '/LlmGateway.php';
require_once __DIR__ . '/../user_functions.php';

class ProactiveCoachService {

    private $db;
    private string $llmBaseUrl;
    private string $llmModel;

    private const COOLDOWN_HOURS = 48;
    private const COOLDOWN_MAP = [
        'daily_briefing' => 20,
        'weekly_digest' => 144,
    ];

    private const TYPE_RU_MAP = [
        'easy' => 'Лёгкий бег',
        'long' => 'Длительный бег',
        'long-run' => 'Длительный бег',
        'tempo' => 'Темповый бег',
        'interval' => 'Интервальная тренировка',
        'fartlek' => 'Фартлек',
        'recovery' => 'Восстановительный бег',
        'race' => 'Забег',
        'sbu' => 'СБУ',
        'ofp' => 'ОФП',
        'rest' => 'Отдых',
        'free' => 'Свободный день',
        'running' => 'Бег',
        'walking' => 'Ходьба',
        'hiking' => 'Поход',
        'cycling' => 'Велосипед',
        'swimming' => 'Плавание',
        'other' => 'Другое',
    ];

    public function __construct($db) {
        $this->db = $db;
        $this->llmBaseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
        $this->llmModel = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');
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
                if (!$this->isWithinUserSendWindow($user, 'daily')) { $stats['skipped']++; continue; }

                $events = $this->detectEvents($userId, $user);
                if (empty($events)) { $stats['skipped']++; continue; }

                $priorityEvent = $this->pickNextAvailableEvent($userId, $events);
                if ($priorityEvent === null) { $stats['skipped']++; continue; }

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

    private function orderEventsByPriority(array $events): array {
        usort($events, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));
        return $events;
    }

    private function pickNextAvailableEvent(int $userId, array $events): ?array {
        foreach ($this->orderEventsByPriority($events) as $event) {
            $type = (string) ($event['type'] ?? '');
            if ($type === '' || $this->isOnCooldown($userId, $type)) {
                continue;
            }
            return $event;
        }
        return null;
    }

    /**
     * Контекст главной цели атлета (race goal + days_to_race + phase + best_races).
     * Помогает LLM привязывать совет к конкретному старту.
     */
    private function buildGoalContext(int $userId): string {
        try {
            $stmt = $this->db->prepare(
                "SELECT goal_type, race_date, race_distance, race_target_time
                 FROM users WHERE id = ? LIMIT 1"
            );
            if (!$stmt) return '';
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || ($user['goal_type'] ?? '') !== 'race' || empty($user['race_date'])) {
                return '';
            }
            $raceDate = (string) $user['race_date'];
            $daysToRace = (int) ((strtotime($raceDate) - time()) / 86400);
            if ($daysToRace < 0) return '';

            $distLabel = (string) ($user['race_distance'] ?? '');
            $targetTime = trim((string) ($user['race_target_time'] ?? ''));

            $parts = [];
            $parts[] = "Главная цель: {$distLabel}";
            if ($targetTime !== '') $parts[] = "целевое время {$targetTime}";
            $parts[] = "до старта {$daysToRace} " . ($daysToRace === 1 ? 'день' : ($daysToRace < 5 ? 'дня' : 'дней'));

            return "\nЦЕЛЬ: " . implode(', ', $parts) . '.';
        } catch (Throwable $e) {
            return '';
        }
    }

    private function buildHistoryBlock(int $userId, int $maxLines = 16): string {
        try {
            require_once __DIR__ . '/WorkoutAnalysisRepository.php';
            $repo = new WorkoutAnalysisRepository($this->db);
            $lines = $repo->getSummaryLinesForActivePlan($userId);
            $rollup = $repo->getWeeklyRollupForActivePlan($userId);
            $keyLines = $repo->getKeyWorkoutSummaryForActivePlan($userId);
            if (empty($lines) && empty($rollup) && empty($keyLines)) return '';

            $out = "\nИСТОРИЯ ТРЕНИРОВОК (ИСПОЛЬЗУЙ цифры из блока, не считай сам):";
            if (!empty($rollup)) {
                $out .= "\nОбъёмы по неделям:\n" . implode("\n", $rollup);
            }
            if (!empty($keyLines)) {
                $out .= "\nКлючевые/значимые тренировки:\n  " . implode("\n  ", $keyLines);
            }
            if (!empty($lines)) {
                $tail = array_slice($lines, -$maxLines);
                $out .= "\nПоследние тренировки:\n  " . implode("\n  ", $tail);
            }
            return $out;
        } catch (Throwable $e) {
            return '';
        }
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
            'goal_achievable' => "Прогнозное время пользователя {$name} впервые стало быстрее целевого (прогноз: " . gmdate('H:i:s', $data['predicted_sec']) . ", цель: " . gmdate('H:i:s', $data['target_sec']) . ").",
            default => '',
        };

        if ($eventDescription === '') return '';

        $historyBlock = $this->buildHistoryBlock($userId);
        $goalContext = $this->buildGoalContext($userId);

        $prompt = <<<PROMPT
Ты — PlanRun, AI-тренер по бегу. Напиши короткую реакцию на событие из жизни атлета.
{$goalContext}

СОБЫТИЕ: {$eventDescription}
{$historyBlock}

ФОРМАТ ОТВЕТА:
- 2 связных предложения (не bullet, не пункты).
- 1-е: констатация события с конкретной цифрой из СОБЫТИЯ.
- 2-е: следующий шаг — что делать или о чём подумать.

ТОН ПО ТИПУ СОБЫТИЯ:
- pause / low_compliance: мягко, без давления. Спроси что мешает, предложи помощь.
- overload / overload_warning: серьёзно и прямо — назови ACWR, рекомендуй отдых или лёгкий бег.
- distance_record / volume_record: отметь рекорд с цифрой, напомни про восстановление.
- vdot_improvement / goal_achievable: констатируй рост формы с цифрой, призови держать темп.
- consistency_streak: отметь стабильность с числом недель.
- race_approaching: спокойно, без паники — фокус на восстановлении.

ОБЯЗАТЕЛЬНО:
- Строго на русском. На «ты».
- Конкретная цифра из СОБЫТИЯ должна быть в тексте.

ЗАПРЕЩЕНО:
- Эмодзи.
- "Молодец", "Так держать", "Отличная работа", "Поздравляю", "Не сдавайся".
- Английские слова.
- Восклицательные знаки больше одного на сообщение.
- Bullet-points, дефисы.
PROMPT;

        $content = $this->callLlmSimple($prompt, 220, $userId);
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

    private function getDailyBriefingFallback(string $typeRu, string $description, bool $isKey, string $acwrZone): string {
        $lines = preg_split('/\R+/', trim($description)) ?: [];
        $summary = trim((string) ($lines[0] ?? ''));
        $summary = preg_replace('/\s+/', ' ', $summary);

        $advice = match (true) {
            $acwrZone === 'danger' => 'Нагрузка сейчас высокая, поэтому держи усилие под контролем и при необходимости упрости сессию.',
            $isKey => 'Это ключевая тренировка: начни спокойно, хорошо разомнись и держи заданный ритм.',
            default => 'Сделай качественную разминку и держи тренировку ровно, без лишнего форсирования с первых минут.',
        };

        $message = "Сегодня по плану {$typeRu}.";
        if ($summary !== '') {
            $message .= " {$summary}.";
        }

        return "{$message} {$advice}";
    }

    // ── Sending and cooldown ──

    private function sendProactiveMessage(int $userId, string $message, array $event): void {
        require_once __DIR__ . '/ChatService.php';
        $chatService = new ChatService($this->db);
        $metadata = [
            'event_key' => 'coach.proactive_' . $event['type'],
            'title' => 'Сообщение от тренера',
            'link' => '/chat',
            'proactive_type' => $event['type'],
        ];
        if (!empty($event['data'])) {
            $metadata['data'] = $event['data'];
        }
        $chatService->addAIMessageToUser($userId, $message, $metadata);
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
        $baseType = preg_replace('/:[^ ]+$/', '', $eventType);
        $cooldownHours = self::COOLDOWN_MAP[$baseType] ?? self::COOLDOWN_HOURS;
        return $hoursSince < $cooldownHours;
    }

    private function recordCooldown(int $userId, string $eventType): void {
        $stmt = $this->db->prepare("INSERT INTO proactive_coach_log (user_id, event_type) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('is', $userId, $eventType);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function processDailyBriefings(): array {
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
        $users = $this->getActiveUsers();

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            try {
                if ($this->isOnCooldown($userId, 'daily_briefing')) {
                    $stats['skipped']++;
                    continue;
                }

                if (!$this->isWithinUserSendWindow($user, 'daily')) {
                    $stats['skipped']++;
                    continue;
                }

                $tz = $this->getUserTz($userId, $user);
                $today = (new DateTime('now', $tz))->format('Y-m-d');
                $stmt = $this->db->prepare(
                    "SELECT d.type, d.description, d.is_key_workout
                     FROM training_plan_days d
                     INNER JOIN training_plan_weeks w ON d.week_id = w.id
                     WHERE w.user_id = ? AND d.date = ?
                     ORDER BY d.id ASC
                     LIMIT 1"
                );
                if (!$stmt) {
                    $stats['skipped']++;
                    continue;
                }
                $stmt->bind_param('is', $userId, $today);
                $stmt->execute();
                $plan = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$plan || in_array((string) ($plan['type'] ?? ''), ['rest', 'free'], true)) {
                    $stats['skipped']++;
                    continue;
                }

                require_once __DIR__ . '/ChatContextBuilder.php';
                $acwr = (new ChatContextBuilder($this->db))->calculateACWR($userId);
                $type = (string) ($plan['type'] ?? 'running');
                $typeRu = self::TYPE_RU_MAP[$type] ?? $type;
                $description = trim((string) ($plan['description'] ?? ''));
                $isKey = !empty($plan['is_key_workout']);
                $acwrVal = round((float) ($acwr['acwr'] ?? 0), 2);
                $acwrZone = (string) ($acwr['zone'] ?? 'unknown');

                $eventDesc = "Сегодня ({$today}): {$typeRu}.";
                if ($description !== '') {
                    $eventDesc .= " Детали плана: {$description}.";
                }
                if ($isKey) {
                    $eventDesc .= " Это ключевая тренировка.";
                }
                $eventDesc .= " ACWR: {$acwrVal} ({$acwrZone}).";

                $historyBlock = $this->buildHistoryBlock($userId, 10);
                $goalContext = $this->buildGoalContext($userId);

                $prompt = <<<PROMPT
Ты — PlanRun, AI-тренер по бегу. Напиши утренний брифинг перед сегодняшней тренировкой.
{$goalContext}

ПЛАН НА СЕГОДНЯ: {$eventDesc}
{$historyBlock}

ФОРМАТ ОТВЕТА:
- 2–3 связных предложения (не bullet-список, не пункты через дефис).
- Первое: кратко напомни ЧТО за тренировка с цифрами из плана (дистанция/темп/интервалы).
- Второе: один конкретный совет — на чём сегодня сфокусироваться (темп, пульсовая зона, ощущение, разминка/заминка).
- Третье (если уместно): связь с целью или контекст нагрузки. Например, "за 14 дней до марафона держим объём" или "ACWR в норме, можно работать".

ОБЯЗАТЕЛЬНО:
- Строго на русском. На «ты».
- Используй ТОЛЬКО цифры из плана и истории, ничего не выдумывай.
- Один конкретный совет, без общих слов.

ЗАПРЕЩЕНО:
- Эмодзи, любые символы кроме букв и пунктуации.
- "Привет!", "Доброе утро!", "Удачи!", "Так держать!", "Молодец", "Сегодня важный день".
- Вопросы пользователю.
- Bullet-points, дефисы в начале строк, нумерованные списки.
- Английские слова (easy, tempo, recovery, base, build — пиши по-русски).
PROMPT;

                $message = $this->callLlmSimple($prompt, 320, $userId);
                if ($message === '') {
                    $message = $this->getDailyBriefingFallback($typeRu, $description, $isKey, $acwrZone);
                }
                if ($message === '') {
                    $stats['skipped']++;
                    continue;
                }

                $this->sendProactiveMessage($userId, $message, [
                    'type' => 'daily_briefing',
                    'priority' => 1,
                    'data' => ['date' => $today, 'type' => $type],
                ]);
                $this->recordCooldown($userId, 'daily_briefing');
                $stats['sent']++;
            } catch (Throwable $e) {
                Logger::warning('ProactiveCoach: daily briefing error', ['userId' => $userId, 'error' => $e->getMessage()]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    public function processWeeklyDigests(): array {
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
        $users = $this->getActiveUsers();

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            try {
                if ($this->isOnCooldown($userId, 'weekly_digest')) {
                    $stats['skipped']++;
                    continue;
                }

                if (!$this->isWithinUserSendWindow($user, 'weekly')) {
                    $stats['skipped']++;
                    continue;
                }

                $tz = $this->getUserTz($userId, $user);
                $end = new DateTime('now', $tz);
                $start = (clone $end)->modify('-6 days');
                $startDate = $start->format('Y-m-d');
                $endDate = $end->format('Y-m-d');

                $planned = $this->countPlannedWorkouts($userId, $startDate, $endDate);
                $actual = $this->getActualWorkoutStats($userId, $startDate, $endDate);
                if ($planned <= 0 && (int) $actual['sessions'] <= 0) {
                    $stats['skipped']++;
                    continue;
                }

                require_once __DIR__ . '/ChatContextBuilder.php';
                $acwr = (new ChatContextBuilder($this->db))->calculateACWR($userId);
                $completion = $planned > 0 ? round(((int) $actual['sessions'] / $planned) * 100) : null;
                $weekStats = "Период {$startDate}–{$endDate}: выполнено {$actual['sessions']} из {$planned} запланированных тренировок";
                if ($completion !== null) {
                    $weekStats .= ", выполнение {$completion}%";
                }
                $weekStats .= ", объём " . round((float) $actual['km'], 1) . " км.";
                if (!empty($acwr['acwr'])) {
                    $weekStats .= " ACWR: " . round((float) $acwr['acwr'], 2) . " ({$acwr['zone']}).";
                }

                $historyBlock = $this->buildHistoryBlock($userId, 20);
                $goalContext = $this->buildGoalContext($userId);

                $prompt = <<<PROMPT
Ты — PlanRun, AI-тренер по бегу. Напиши итог завершившейся недели атлета.
{$goalContext}

ДАННЫЕ НЕДЕЛИ: {$weekStats}
{$historyBlock}

ФОРМАТ ОТВЕТА:
- 3–5 связных предложений (не bullet, не дефисы, не "пункт 1, пункт 2").
- 1-е предложение: оценка недели одним фактом — объём км, число тренировок, выполнение %.
- 2-е: что особенно отметить (ключевая тренировка, рекорд, или наоборот пропуск/слабое место). Конкретно с цифрами.
- 3-е: динамика vs прошлая неделя (объём вырос/упал, темп улучшился), из истории недель.
- 4-е (если уместно): рекомендация на следующую неделю — что держать, что добавить, где осторожнее.

ОБЯЗАТЕЛЬНО:
- Строго на русском. На «ты».
- Используй ТОЛЬКО цифры из блока статистики/истории. Не выдумывай.
- Если выполнение < 70% — без давления, спроси что мешает, предложи скорректировать.
- Если ACWR в зоне overload — обязательно упомяни про восстановление.

ЗАПРЕЩЕНО:
- Эмодзи.
- "Молодец", "Так держать", "Отлично сработал" — общие слова.
- Bullet-points, нумерация.
- Английские слова из плана (easy, tempo, recovery, etc.) — пиши по-русски.
- Приветствия и подписи "Твой тренер".
PROMPT;

                $message = $this->callLlmSimple($prompt, 500, $userId);
                if ($message === '') {
                    $message = "Итог недели: {$weekStats} На следующей неделе держи нагрузку ровно и внимательно следи за восстановлением.";
                }

                $this->sendProactiveMessage($userId, $message, [
                    'type' => 'weekly_digest',
                    'priority' => 1,
                    'data' => ['start_date' => $startDate, 'end_date' => $endDate],
                ]);
                $this->recordCooldown($userId, 'weekly_digest');
                $stats['sent']++;
            } catch (Throwable $e) {
                Logger::warning('ProactiveCoach: weekly digest error', ['userId' => $userId, 'error' => $e->getMessage()]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private function callLlmSimple(string $prompt, int $maxTokens = 300, ?int $userId = null): string {
        $payload = LlmGateway::withThinkingMode([
            'model' => $this->llmModel,
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => 'Напиши сообщение.'],
            ],
            'stream' => false,
            'max_tokens' => $maxTokens,
            'temperature' => 0.7,
        ], $this->llmBaseUrl, false);

        try {
            $response = LlmGateway::requestChatCompletion($this->llmBaseUrl, $payload, [
                'feature' => 'Proactive coach message',
                'purpose' => 'chat',
                'db' => $this->db,
                'surface' => 'proactive_coach',
                'event_type' => 'llm_request',
                'user_id' => $userId,
                'timeout' => 30,
                'connect_timeout' => 5,
                'max_attempts' => max(1, min(5, (int) env('LLM_MAX_RETRIES', 1))),
            ]);
        } catch (Throwable $e) {
            Logger::warning('ProactiveCoach: LLM call failed', ['error' => $e->getMessage(), 'userId' => $userId]);
            return '';
        }

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        return $this->normalizeProse($content);
    }

    /**
     * LLM временами нарушает запрет на bullet-формат — стрипим ведущие маркеры,
     * markdown bold/italic и схлопываем в один связный текст.
     */
    private function normalizeProse(string $text): string {
        if ($text === '') return '';
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        $text = preg_replace('/\*(.+?)\*/u', '$1', $text);
        $text = str_replace('`', '', $text);
        $lines = preg_split('/\r?\n/u', $text);
        $clean = [];
        foreach ($lines as $line) {
            $line = ltrim($line);
            $line = preg_replace('/^(?:[-—–*•·]+|\d+[.)])\s+/u', '', $line);
            $line = trim($line);
            if ($line !== '') $clean[] = $line;
        }
        $joined = implode(' ', $clean);
        $joined = preg_replace('/\s+/u', ' ', $joined);
        $joined = preg_replace('/\s+([,.;:!?])/u', '$1', $joined);
        return trim($joined);
    }

    private function countPlannedWorkouts(int $userId, string $startDate, string $endDate): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt
             FROM training_plan_days d
             INNER JOIN training_plan_weeks w ON d.week_id = w.id
             WHERE w.user_id = ?
               AND d.date BETWEEN ? AND ?
               AND d.type NOT IN ('rest', 'free')"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('iss', $userId, $startDate, $endDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['cnt'] ?? 0);
    }

    private function getActualWorkoutStats(int $userId, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare(
            "(SELECT training_date AS workout_date, distance_km
              FROM workout_log
              WHERE user_id = ? AND is_completed = 1 AND training_date BETWEEN ? AND ?)
             UNION ALL
             (SELECT DATE(start_time) AS workout_date, distance_km
              FROM workouts
              WHERE user_id = ? AND DATE(start_time) BETWEEN ? AND ?
                AND NOT EXISTS (
                    SELECT 1 FROM workout_log wl
                    WHERE wl.user_id = workouts.user_id
                      AND wl.training_date = DATE(workouts.start_time)
                      AND wl.is_completed = 1
                ))"
        );
        if (!$stmt) {
            return ['sessions' => 0, 'km' => 0.0];
        }
        $stmt->bind_param('ississ', $userId, $startDate, $endDate, $userId, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $sessions = 0;
        $km = 0.0;
        while ($row = $result->fetch_assoc()) {
            $sessions++;
            $km += (float) ($row['distance_km'] ?? 0);
        }
        $stmt->close();
        return ['sessions' => $sessions, 'km' => $km];
    }

    // ── Helpers ──

    private function getActiveUsers(): array {
        $result = $this->db->query(
            "SELECT u.* FROM users u
             WHERE u.onboarding_completed = 1
               AND COALESCE(u.banned, 0) = 0
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

    /**
     * TZ-гейт проактива: слать ТОЛЬКО если сейчас в таймзоне юзера — его окно отправки.
     *
     * Окно из training_time_pref: morning→[6,9), day→[11,14), evening→[17,20),
     * нет/неизвестно → дефолт [6,9). Жёсткий override для всех: env
     * PROACTIVE_SEND_WINDOW="H-H" (напр. "7-10").
     *
     * ВАЖНО: требует ежечасного cron (`0 * * * *`). При cron «раз/день в фикс.
     * час» этот гейт почти всегда вернёт false (час не совпадёт с окном юзера),
     * и проактив перестанет слаться — это by design, расписание управляется
     * локальным утром юзера, а не моментом запуска скрипта.
     *
     * Для weekly_digest ($kind='weekly') дополнительно требуется, чтобы в TZ
     * юзера было воскресенье (cron должен быть `0 * * * 0`).
     */
    private function isWithinUserSendWindow(array $user, string $kind = 'daily'): bool {
        $userId = (int) ($user['id'] ?? 0);
        $tz = $this->getUserTz($userId, $user);
        $nowLocal = new DateTime('now', $tz);

        if ($kind === 'weekly' && (int) $nowLocal->format('N') !== 7) {
            return false; // не воскресенье в TZ юзера
        }

        [$startHour, $endHour] = $this->resolveSendWindowHours($user);
        $h = (int) $nowLocal->format('G');
        return $h >= $startHour && $h < $endHour;
    }

    /**
     * @return array{0:int,1:int} [startHour, endHour) в локальном времени юзера.
     */
    private function resolveSendWindowHours(array $user): array {
        $override = trim((string) env('PROACTIVE_SEND_WINDOW', ''));
        if ($override !== '' && preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $override, $m)) {
            $s = max(0, min(23, (int) $m[1]));
            $e = max($s + 1, min(24, (int) $m[2]));
            return [$s, $e];
        }

        $pref = strtolower(trim((string) ($user['training_time_pref'] ?? '')));
        return match ($pref) {
            'day' => [11, 14],
            'evening' => [17, 20],
            default => [6, 9], // morning + неизвестно/пусто
        };
    }
}
