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

    /** Кулдауны по типам событий (часы). Если тип не указан — используется COOLDOWN_HOURS. */
    private const COOLDOWN_MAP = [
        'daily_briefing'         => 20,  // ежедневный — 20ч (чтобы утренний брифинг работал каждый день)
        'post_workout_analysis'  => 1,   // 1ч — кулдаун минимальный, дубли блокируются по workout_id
        'weekly_digest'          => 144, // 6 дней
    ];

    public function __construct($db) {
        $this->db = $db;
        $this->llmBaseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $this->llmModel = env('LLM_CHAT_MODEL', 'qwen3-14b');
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
            if ($type === '') continue;
            if ($this->isOnCooldown($userId, $type)) continue;
            return $event;
        }
        return null;
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

        $content = $this->callLlmSimple($prompt);
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
            $isKey => 'Это ключевая тренировка, так что начни спокойно, хорошо разомнись и держи заданный ритм.',
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
        // Пробрасываем data из события (date, distance_km, workout_id и т.д.)
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

        // Определяем кулдаун: по базовому типу (без суффикса :wXXX или :YYYY-MM-DD)
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

    // ── Automated AI reactions ──

    /**
     * Post-workout analysis: called after a workout is imported/synced.
     * Generates AI analysis and saves as proactive message.
     */
    public function postWorkoutAnalysis(int $userId, string $date, int $workoutIndex = 0, ?int $workoutId = null): bool {
        // Кулдаун привязан к конкретной тренировке (workout_id) или дате —
        // каждая тренировка анализируется ровно 1 раз, даже если за день несколько
        $cooldownKey = $workoutId !== null
            ? 'post_workout_analysis:w' . $workoutId
            : 'post_workout_analysis:' . $date;
        if ($this->isOnCooldown($userId, $cooldownKey)) {
            Logger::debug('ProactiveCoach: post-workout cooldown active', ['userId' => $userId, 'date' => $date, 'workoutId' => $workoutId]);
            return false;
        }

        try {
            require_once __DIR__ . '/ChatToolRegistry.php';
            require_once __DIR__ . '/ChatContextBuilder.php';
            $ctx = new ChatContextBuilder($this->db);
            $registry = new ChatToolRegistry($this->db, $ctx);

            // Если передан конкретный workout_id — используем его напрямую, иначе по дате + индексу
            $toolArgs = ['date' => $date, 'workout_index' => $workoutIndex];
            if ($workoutId !== null) {
                $toolArgs['workout_id'] = $workoutId;
            }
            $analyzeResult = $registry->executeTool('analyze_workout', json_encode($toolArgs), $userId);

            $data = json_decode($analyzeResult, true);
            if (isset($data['error'])) return false;

            $summary = $data['summary'] ?? [];
            $km = $summary['distance_km'] ?? 0;
            if ($km < 1) return false;

            // Пропускаем короткие разминки/заминки (< 3 км), если за этот день есть другие тренировки
            $totalOnDay = $data['total_workouts_on_day'] ?? 1;
            if ($totalOnDay > 1 && $km < 3) {
                Logger::debug('ProactiveCoach: skipping short warmup/cooldown', [
                    'userId' => $userId, 'date' => $date, 'km' => $km, 'workoutId' => $workoutId,
                ]);
                return false;
            }

            $prompt = $this->buildWorkoutAnalysisPrompt($data);

            $message = $this->callLlmSimple($prompt);
            if ($message === '') return false;

            $this->sendProactiveMessage($userId, $message, [
                'type' => 'post_workout_analysis',
                'priority' => 1,
                'data' => ['date' => $date, 'distance_km' => $km, 'workout_id' => $workoutId],
            ]);
            $this->recordCooldown($userId, $cooldownKey);
            return true;
        } catch (Throwable $e) {
            Logger::warning('ProactiveCoach: post-workout analysis failed', ['userId' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Построить структурированный промпт для анализа тренировки.
     * Предрассчитывает выводы в PHP, чтобы LLM не галлюцинировала причинно-следственные связи.
     */
    public function buildWorkoutAnalysisPrompt(array $data): string {
        $summary = $data['summary'] ?? [];
        $km = $summary['distance_km'] ?? 0;
        $pace = $summary['avg_pace'] ?? null;
        $hr = $summary['avg_hr'] ?? null;
        $maxHr = $summary['max_hr'] ?? null;
        $durationMin = $summary['duration_min'] ?? null;
        $activityType = self::TYPE_RU_MAP[$summary['activity_type'] ?? 'running'] ?? 'Бег';
        $date = $data['date'] ?? '';

        // --- Факт ---
        $facts = [];
        $facts[] = "Дата: {$date}";
        $facts[] = "Тип: {$activityType}";
        $facts[] = "Дистанция: {$km} км";
        if ($pace) $facts[] = "Средний темп: {$pace} мин/км";
        if ($durationMin) $facts[] = "Длительность: {$durationMin} мин";
        if ($hr) $facts[] = "Средний ЧСС: {$hr}";
        if ($maxHr) $facts[] = "Макс ЧСС: {$maxHr}";

        // --- План и сравнение ---
        $planFacts = [];
        $planConclusions = [];
        $plan = $data['plan_comparison'] ?? null;
        if ($plan) {
            $plannedType = $plan['planned_type'] ?? '';
            $plannedDesc = $plan['planned_description'] ?? '';
            $planFacts[] = "Тип по плану: {$plannedType}";
            if ($plannedDesc) $planFacts[] = "Описание плана: {$plannedDesc}";
            $planFacts[] = "Ключевая тренировка: " . ($plan['is_key_workout'] ? 'да' : 'нет');

            // Специальные случаи: день отдыха и СБУ
            if (in_array($plannedType, ['Отдых', 'День отдыха'])) {
                $planConclusions[] = "ВАЖНО: по плану сегодня день отдыха, но пользователь провёл тренировку. Бег в день отдыха может помешать восстановлению.";
            }
            if ($plannedType === 'СБУ') {
                $planConclusions[] = "Это тренировка СБУ (специальные беговые упражнения) — НЕ оценивай темп и дистанцию как обычный бег. Важны: выполнение упражнений и техника.";
            }

            // Извлечь плановую дистанцию и темп из описания
            $plannedKm = null;
            $plannedPace = null;
            if (preg_match('/([\d,.]+)\s*км/', $plannedDesc, $m)) {
                $plannedKm = (float) str_replace(',', '.', $m[1]);
            }
            if (preg_match('/[Тт]емп[:\s]*([\d]+:[\d]{2})\s*мин\/км/', $plannedDesc, $m)) {
                $plannedPace = $m[1];
            }

            // Сравнение дистанции
            if ($plannedKm && $plannedKm > 0) {
                $distPct = round(($km / $plannedKm) * 100);
                $distDiff = round($km - $plannedKm, 1);
                $planFacts[] = "Плановая дистанция: {$plannedKm} км";
                if ($distPct < 70) {
                    $planConclusions[] = "НЕДОБЕГ: выполнено {$distPct}% плановой дистанции ({$km} из {$plannedKm} км). Это существенное отклонение.";
                } elseif ($distPct < 90) {
                    $planConclusions[] = "Дистанция немного ниже плана: {$km} из {$plannedKm} км ({$distPct}%).";
                } elseif ($distPct <= 110) {
                    $planConclusions[] = "Дистанция соответствует плану: {$km} из {$plannedKm} км ({$distPct}%).";
                } else {
                    $planConclusions[] = "ПЕРЕВЫПОЛНЕНИЕ: {$km} из {$plannedKm} км ({$distPct}%). Больше плана на {$distDiff} км.";
                }
            }

            // Сравнение темпа
            if ($plannedPace && $pace) {
                $plannedPaceSec = $this->paceToSeconds($plannedPace);
                $actualPaceSec = $this->paceToSeconds($pace);
                if ($plannedPaceSec > 0 && $actualPaceSec > 0) {
                    $paceDiff = $actualPaceSec - $plannedPaceSec;
                    $planFacts[] = "Плановый темп: {$plannedPace} мин/км";
                    if (abs($paceDiff) <= 10) {
                        $planConclusions[] = "Темп соответствует плану (разница {$paceDiff} сек).";
                    } elseif ($paceDiff > 0) {
                        $planConclusions[] = "Темп медленнее плана на {$paceDiff} сек/км ({$pace} vs {$plannedPace}).";
                    } else {
                        $absDiff = abs($paceDiff);
                        $planConclusions[] = "Темп быстрее плана на {$absDiff} сек/км ({$pace} vs {$plannedPace}).";
                    }
                }
            }
        }

        // --- Раскладка по дистанции ---
        $splitFacts = [];
        $splits = $data['pace_analysis'] ?? [];
        if (!empty($splits['split_type'])) {
            $splitTypeRu = match ($splits['split_type']) {
                'negative_split' => 'ускорение к финишу (вторая половина быстрее первой — хорошая раскладка)',
                'positive_split' => 'затухание к финишу (вторая половина медленнее первой)',
                'even_split' => 'равномерная раскладка (стабильный темп на всей дистанции)',
                default => $splits['split_type'],
            };
            $splitFacts[] = "Раскладка по дистанции: {$splitTypeRu}";
            if (isset($splits['split_diff_sec'])) {
                $absDiff = abs($splits['split_diff_sec']);
                $splitFacts[] = "Разница темпа между первой и второй половиной: {$absDiff} сек/км";
            }
            if (isset($splits['fastest'])) {
                $splitFacts[] = "Самый быстрый километр: {$splits['fastest']['km']}-й ({$splits['fastest']['pace']} мин/км)";
            }
            if (isset($splits['slowest'])) {
                $splitFacts[] = "Самый медленный километр: {$splits['slowest']['km']}-й ({$splits['slowest']['pace']} мин/км)";
            }
        }

        // --- ЧСС-зоны ---
        $hrFacts = [];
        $hrConclusions = [];
        $hrZones = $data['hr_zones'] ?? [];
        if (!empty($hrZones)) {
            $zoneParts = [];
            $z4z5total = 0;
            $z1z2total = 0;
            foreach ($hrZones as $z) {
                $pct = $z['percent'] ?? 0;
                $zoneName = $z['zone'] ?? '';
                if ($pct > 0) $zoneParts[] = "{$zoneName}: {$pct}%";
                if (str_contains($zoneName, 'пороговая') || str_contains($zoneName, 'максимальная')) $z4z5total += $pct;
                if (str_contains($zoneName, 'восстановительная') || str_contains($zoneName, 'аэробная')) $z1z2total += $pct;
            }
            if (!empty($zoneParts)) {
                $hrFacts[] = "Распределение по зонам ЧСС: " . implode(', ', $zoneParts);
            }

            // Считаем Z3+Z4+Z5 (темповая + пороговая + максимальная) для интенсивных тренировок
            $z3total = 0;
            foreach ($hrZones as $z) {
                if (str_contains($z['zone'] ?? '', 'темповая')) $z3total += $z['percent'] ?? 0;
            }
            $z3z4z5total = $z3total + $z4z5total;

            // Оценка ЧСС для типа тренировки
            $plannedType = $plan['planned_type'] ?? '';
            $isEasy = in_array($plannedType, ['Лёгкий бег', 'Восстановительный бег']);
            if ($isEasy && $z4z5total > 50) {
                $hrConclusions[] = "КРИТИЧНО: для лёгкого бега {$z4z5total}% времени в пороговой и максимальной зонах — это недопустимо. Тренировка фактически была темповой/пороговой, а не лёгкой. Оценка: «Слабо.».";
            } elseif ($isEasy && $z4z5total > 20) {
                $hrConclusions[] = "ВНИМАНИЕ: для лёгкого бега слишком высокая доля пороговой и максимальной зон ({$z4z5total}%). Нужно снижать интенсивность.";
            } elseif ($isEasy && $z1z2total > 70) {
                $hrConclusions[] = "Пульс соответствует лёгкому бегу: {$z1z2total}% времени в восстановительной и аэробной зонах.";
            }

            $isTempo = in_array($plannedType, ['Темповый бег', 'Интервальная', 'Фартлек']);
            $isRace = in_array($plannedType, ['Забег']);
            if ($isTempo || $isRace) {
                if ($z3z4z5total > 70) {
                    $hrConclusions[] = "Пульс соответствует интенсивной тренировке: {$z3z4z5total}% в темповой, пороговой и максимальной зонах — это нормально для данного типа.";
                } elseif ($z4z5total < 20 && $z3z4z5total < 40) {
                    $hrConclusions[] = "Для темповой/интервальной тренировки мало времени в интенсивных зонах ({$z3z4z5total}%). Интенсивность ниже целевой.";
                }
            }
        }

        // --- Сборка промпта ---
        $sections = [];
        $sections[] = "ФАКТ ТРЕНИРОВКИ:\n" . implode("\n", $facts);
        if (!empty($planFacts)) {
            $sections[] = "ПЛАН:\n" . implode("\n", $planFacts);
        }
        if (!empty($planConclusions)) {
            $sections[] = "ВЫВОДЫ ПО ПЛАНУ (используй как основу, НЕ пересчитывай):\n" . implode("\n", $planConclusions);
        }
        if (!empty($splitFacts)) {
            $sections[] = "РАСКЛАДКА ПО ДИСТАНЦИИ (данные с датчика):\n" . implode("\n", $splitFacts);
        }
        if (!empty($hrFacts)) {
            $sections[] = "ЗОНЫ ПУЛЬСА:\n" . implode("\n", $hrFacts);
        }
        if (!empty($hrConclusions)) {
            $sections[] = "ВЫВОДЫ ПО ПУЛЬСУ (используй как основу):\n" . implode("\n", $hrConclusions);
        }

        $dataBlock = implode("\n\n", $sections);

        return <<<PROMPT
Ты — PlanRun, тренер по бегу. Напиши КРАТКИЙ (3-5 предложений) анализ завершённой тренировки.

{$dataBlock}

ПРАВИЛА:
- СТРОГО на русском языке. Никаких англицизмов: не используй слова «сплит», «негативный сплит», «позитивный сплит», «зона Z1-Z5» и т.п. Вместо них: «раскладка», «ускорение к финишу», «затухание к финишу», «равномерный темп», «пороговая зона», «аэробная зона» и т.д.
- Начни с общей оценки: «Хорошо.», «Нормально.» или «Слабо.» — одно слово с точкой.
- Используй ТОЛЬКО предоставленные выводы и факты. НЕ выдумывай данных, которых нет.
- «Затухание к финишу» = вторая половина дистанции медленнее первой. «Ускорение к финишу» = вторая половина быстрее. НЕ путай с разницей между фактическим и плановым темпом — это разные вещи.
- Если дистанция выполнена менее чем на 70% от плана — это главный вывод, объясни влияние на тренировочный процесс.
- Если в выводах указано «КРИТИЧНО» по пульсу — оценка обязательно «Слабо.».
- Если по плану день отдыха, а пользователь тренировался — оценка «Нормально.» и предупреди о восстановлении.
- Для СБУ (специальных беговых упражнений) — НЕ анализируй темп и дистанцию как обычный бег.
- Рекомендация должна быть конкретной и логичной: НЕ советуй замедлиться, если темп уже медленнее плана.
- Если плана нет — НЕ сравнивай с планом и НЕ оценивай соответствие целям. Анализируй только факты: темп, пульс, раскладку.
- НЕ используй эмодзи. Без приветствий — сразу к делу.
- Тон: профессиональный тренер, кратко и по делу.
PROMPT;
    }

    /**
     * Конвертировать темп "M:SS" в секунды.
     */
    private function paceToSeconds(string $pace): int {
        $parts = explode(':', $pace);
        if (count($parts) !== 2) return 0;
        return (int) $parts[0] * 60 + (int) $parts[1];
    }

    /**
     * Daily briefing for all users with a planned workout today.
     * Called from cron each morning.
     */
    public function processDailyBriefings(): array {
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
        $users = $this->getActiveUsers();

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            try {
                if ($this->isOnCooldown($userId, 'daily_briefing')) { $stats['skipped']++; continue; }

                $tz = $this->getUserTz($userId, $user);
                $today = (new DateTime('now', $tz))->format('Y-m-d');

                $stmt = $this->db->prepare(
                    "SELECT d.type, d.description, d.is_key_workout
                     FROM training_plan_days d
                     JOIN training_plan_weeks w ON d.week_id = w.id
                     WHERE w.user_id = ? AND d.date = ? LIMIT 1"
                );
                if (!$stmt) { $stats['skipped']++; continue; }
                $stmt->bind_param('is', $userId, $today);
                $stmt->execute();
                $plan = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$plan || $plan['type'] === 'rest') { $stats['skipped']++; continue; }

                require_once __DIR__ . '/ChatContextBuilder.php';
                $ctx = new ChatContextBuilder($this->db);
                $acwr = $ctx->calculateACWR($userId);

                $typeRu = self::TYPE_RU_MAP[$plan['type']] ?? $plan['type'];
                $desc = trim((string) ($plan['description'] ?? ''));
                $isKey = !empty($plan['is_key_workout']);
                $acwrVal = round($acwr['acwr'] ?? 0, 2);
                $acwrZone = $acwr['zone'] ?? 'unknown';

                $eventDesc = "Сегодня ({$today}): {$typeRu}.";
                if ($desc !== '') $eventDesc .= " Детали плана: {$desc}.";
                if ($isKey) $eventDesc .= " Это ключевая тренировка.";
                $eventDesc .= ". ACWR: {$acwrVal} ({$acwrZone}).";

                $prompt = <<<PROMPT
Ты — PlanRun, тренер по бегу. Напиши КРАТКИЙ утренний брифинг (2-3 предложения) перед тренировкой.

ПЛАН НА СЕГОДНЯ: {$eventDesc}

ПРАВИЛА:
- СТРОГО на русском.
- Тон: мотивирующий, конкретный.
- Назови тип тренировки и используй детали из описания плана, если они есть.
- Дай один ключевой совет (темп, разминка, фокус).
- Если ACWR высокий — предупреди о нагрузке.
- Если ключевая — подчеркни важность.
- НЕ используй эмодзи.
PROMPT;

                $message = $this->callLlmSimple($prompt);
                if ($message === '') {
                    $message = $this->getDailyBriefingFallback($typeRu, $desc, $isKey, $acwrZone);
                }
                if ($message === '') { $stats['skipped']++; continue; }

                $this->sendProactiveMessage($userId, $message, [
                    'type' => 'daily_briefing', 'priority' => 1,
                    'data' => ['date' => $today, 'type' => $plan['type']],
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

    /**
     * Weekly digest for all active users.
     * Called from cron once a week (Sunday evening or Monday morning).
     */
    public function processWeeklyDigests(): array {
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
        $users = $this->getActiveUsers();

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            try {
                if ($this->isOnCooldown($userId, 'weekly_digest')) { $stats['skipped']++; continue; }

                require_once __DIR__ . '/ChatToolRegistry.php';
                require_once __DIR__ . '/ChatContextBuilder.php';
                $ctx = new ChatContextBuilder($this->db);
                $registry = new ChatToolRegistry($this->db, $ctx);

                $reviewJson = $registry->executeTool('get_weekly_review', json_encode(['week_offset' => 0]), $userId);
                $review = json_decode($reviewJson, true);
                if (isset($review['error'])) { $stats['skipped']++; continue; }

                $summary = $review['summary'] ?? [];
                $quality = $review['quality'] ?? [];
                $load = $review['load'] ?? [];
                $week = $review['week'] ?? '';

                $goalJson = $registry->executeTool('get_goal_progress', '{}', $userId);
                $goal = json_decode($goalJson, true);
                $goalInfo = '';
                if (!isset($goal['error']) && !empty($goal['race_date'])) {
                    $goalInfo = "Цель: {$goal['race_distance']} {$goal['race_date']}, целевое время {$goal['target_time']}.";
                    if (!empty($goal['predicted_time'])) $goalInfo .= " Текущий прогноз: {$goal['predicted_time']}.";
                    if (!empty($goal['days_until_race'])) $goalInfo .= " Осталось дней: {$goal['days_until_race']}.";
                }

                $weekStats = "Неделя {$week}: {$summary['actual_sessions']}/{$summary['planned_sessions']} тренировок, {$summary['actual_km']} км, выполнение {$summary['completion_pct']}%.";
                if (!empty($quality['week_trimp'])) $weekStats .= " TRIMP: {$quality['week_trimp']}.";
                if (!empty($quality['avg_hr'])) $weekStats .= " Средний ЧСС: {$quality['avg_hr']}.";
                if (!empty($quality['best_pace'])) $weekStats .= " Лучший темп: {$quality['best_pace']}.";
                if (!empty($quality['hr_drift_bpm'])) $weekStats .= " HR drift (длительная): {$quality['hr_drift_bpm']} уд.";
                if (!empty($load['atl'])) $weekStats .= " ATL: {$load['atl']}, CTL: {$load['ctl']}, TSB: {$load['tsb']}.";
                if ($goalInfo) $weekStats .= " {$goalInfo}";

                $prompt = <<<PROMPT
Ты — PlanRun, тренер по бегу. Напиши еженедельный итог (4-6 предложений).

ДАННЫЕ НЕДЕЛИ: {$weekStats}

ПРАВИЛА:
- СТРОГО на русском.
- Тон: тренер даёт разбор недели.
- Начни с общей оценки: хорошая/средняя/слабая неделя.
- Отметь ключевые достижения (лучший темп, объём, ключевые тренировки).
- Если HR drift низкий (<5 уд) — отметь хорошую аэробную базу.
- Если выполнение <70% — мягко спроси о причинах.
- Если есть цель — оцени прогресс к ней.
- Дай 1-2 рекомендации на следующую неделю.
- НЕ используй эмодзи.
PROMPT;

                $message = $this->callLlmSimple($prompt);
                if ($message === '') { $stats['skipped']++; continue; }

                $this->sendProactiveMessage($userId, $message, [
                    'type' => 'weekly_digest', 'priority' => 1,
                    'data' => ['week' => $week],
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

    /**
     * Simple non-streaming LLM call for generating short messages.
     */
    private function callLlmSimple(string $prompt): string {
        $url = $this->llmBaseUrl . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->llmModel,
                'messages' => [['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => 'Напиши сообщение.']],
                'stream' => false, 'max_tokens' => 300, 'temperature' => 0.7,
                'chat_template_kwargs' => ['enable_thinking' => false],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) return '';
        return trim(json_decode($response, true)['choices'][0]['message']['content'] ?? '');
    }

    private const TYPE_RU_MAP = [
        'easy' => 'Лёгкий бег', 'long' => 'Длительный бег', 'tempo' => 'Темповый бег',
        'interval' => 'Интервальная', 'fartlek' => 'Фартлек', 'recovery' => 'Восстановительный бег',
        'race' => 'Забег', 'sbu' => 'СБУ', 'ofp' => 'ОФП', 'rest' => 'Отдых',
        'running' => 'Бег', 'walking' => 'Ходьба', 'hiking' => 'Поход',
        'cycling' => 'Велосипед', 'swimming' => 'Плавание', 'other' => 'Другое',
    ];

    // ── Helpers ──

    private function getActiveUsers(): array {
        $result = $this->db->query(
            "SELECT u.* FROM users u
             WHERE u.onboarding_completed = 1 AND u.banned = 0
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
