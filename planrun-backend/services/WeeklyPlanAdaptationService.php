<?php
/**
 * Еженедельная LLM-адаптация плана.
 *
 * Каждое воскресенье 21:00 в локальной таймзоне пользователя:
 *   1. Собираем данные прошедшей недели (план vs факт, ACWR, compliance, самочувствие).
 *   2. Просим DeepSeek проанализировать и предложить точечные изменения плана на следующую неделю.
 *   3. Валидируем JSON-патч (whitelist типов, нельзя трогать race/control, лимит изменений).
 *   4. Применяем через WeekService::updateTrainingDayById().
 *   5. Шлём уведомление с event_key='plan.weekly_adaptation'.
 */

require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/LlmGateway.php';
require_once __DIR__ . '/../user_functions.php';

class WeeklyPlanAdaptationService {

    private $db;
    private string $llmBaseUrl;
    private string $llmModel;

    private const PROTECTED_TYPES = ['race', 'control'];
    private const ALLOWED_NEW_TYPES = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'sbu', 'rest', 'free'];
    private const MAX_CHANGES = 4;
    private const COOLDOWN_DAYS = 6;
    private const SCHEDULE_HOUR = 21;
    private const SCHEDULE_MINUTE = 0;
    private const SCHEDULE_DOW = 7; // воскресенье (ISO 1=Пн … 7=Вс)

    public function __construct($db) {
        $this->db = $db;
        $this->llmBaseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
        $this->llmModel = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');
        $this->ensureSchema();
    }

    /**
     * Обработать всех подходящих пользователей (cron-режим: фильтр по локальной таймзоне).
     */
    public function processAllUsers(): array {
        $stats = ['processed' => 0, 'adapted' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        $users = $this->getEligibleUsers();
        Logger::info('WeeklyAdaptation: processing users', ['count' => count($users)]);

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $stats['processed']++;
            try {
                $result = $this->processUser($userId, $user);
                if ($result['adapted']) {
                    $stats['adapted']++;
                    $stats['details'][] = [
                        'userId' => $userId,
                        'changes' => $result['changes_count'],
                        'summary' => mb_substr($result['summary'], 0, 120),
                    ];
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                Logger::warning('WeeklyAdaptation: user error', ['userId' => $userId, 'error' => $e->getMessage()]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Запустить адаптацию для одного пользователя (используется тестами и /run админкой).
     */
    public function processUser(int $userId, ?array $user = null, bool $forceIgnoreCooldown = false): array {
        if ($user === null) {
            $user = $this->loadUser($userId);
            if (!$user) return ['adapted' => false, 'reason' => 'user_not_found'];
        }

        if (!$forceIgnoreCooldown && $this->isOnCooldown($userId)) {
            return ['adapted' => false, 'reason' => 'cooldown'];
        }

        $inputs = $this->collectInputs($userId, $user);
        if (empty($inputs['next_week_days'])) {
            return ['adapted' => false, 'reason' => 'no_next_week_plan'];
        }

        $llmResponse = $this->callLlm($userId, $inputs);
        if ($llmResponse === null) {
            return ['adapted' => false, 'reason' => 'llm_failed'];
        }

        $changes = is_array($llmResponse['changes'] ?? null) ? $llmResponse['changes'] : [];
        if (empty($changes)) {
            $this->recordCooldown($userId);
            return [
                'adapted' => false,
                'reason' => 'no_changes_needed',
                'summary' => (string) ($llmResponse['no_changes_reason'] ?? ''),
            ];
        }

        $validation = $this->validatePatch($changes, $inputs);
        if (!$validation['valid']) {
            Logger::info('WeeklyAdaptation: validation rejected', [
                'userId' => $userId,
                'reason' => $validation['reason'],
            ]);
            return ['adapted' => false, 'reason' => 'validation_failed:' . $validation['reason']];
        }

        $applied = $this->applyPatch($userId, $validation['filtered_changes']);
        if ($applied === 0) {
            return ['adapted' => false, 'reason' => 'apply_failed'];
        }

        $summary = trim((string) ($llmResponse['summary'] ?? ''));
        if ($summary === '') {
            $summary = "План на следующую неделю адаптирован ({$applied} " . $this->pluralChanges($applied) . ").";
        }

        $this->notifyUser($userId, $summary);
        $this->recordCooldown($userId);

        return ['adapted' => true, 'changes_count' => $applied, 'summary' => $summary];
    }

    // ── Eligibility / user lookup ─────────────────────────────────────────

    private function getEligibleUsers(): array {
        $result = $this->db->query("
            SELECT u.id, u.username, COALESCE(NULLIF(u.timezone, ''), 'Europe/Moscow') AS timezone,
                   u.race_date, u.race_distance, u.race_target_time, u.goal_type
            FROM users u
            INNER JOIN training_plan_weeks tpw ON tpw.user_id = u.id
            WHERE EXISTS (
                SELECT 1 FROM workout_log wl
                WHERE wl.user_id = u.id AND wl.training_date > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                UNION
                SELECT 1 FROM workouts w
                WHERE w.user_id = u.id AND w.start_time > DATE_SUB(NOW(), INTERVAL 14 DAY)
            )
            GROUP BY u.id, u.username, u.timezone, u.race_date, u.race_distance, u.race_target_time, u.goal_type
            HAVING MAX(DATE_ADD(tpw.start_date, INTERVAL 6 DAY)) >= CURDATE()
        ");
        if (!$result) return [];

        $candidates = [];
        while ($row = $result->fetch_assoc()) {
            $candidates[] = $row;
        }

        $eligible = [];
        foreach ($candidates as $u) {
            $tz = $u['timezone'] ?: 'Europe/Moscow';
            try {
                $userNow = new DateTime('now', new DateTimeZone($tz));
            } catch (Throwable $e) {
                continue;
            }
            if ((int) $userNow->format('N') !== self::SCHEDULE_DOW) continue;
            if ((int) $userNow->format('G') !== self::SCHEDULE_HOUR) continue;
            if ((int) $userNow->format('i') !== self::SCHEDULE_MINUTE) continue;
            $eligible[] = $u;
        }
        return $eligible;
    }

    private function loadUser(int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, username, COALESCE(NULLIF(timezone, ''), 'Europe/Moscow') AS timezone,
                   race_date, race_distance, race_target_time, goal_type
            FROM users
            WHERE id = ?
        ");
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    // ── Data collection ───────────────────────────────────────────────────

    private function collectInputs(int $userId, array $user): array {
        $tz = new DateTimeZone($user['timezone'] ?: 'Europe/Moscow');
        $today = new DateTime('now', $tz);

        $weekStart = (clone $today)->modify('-6 days')->format('Y-m-d');
        $weekEnd = $today->format('Y-m-d');

        $nextStart = (clone $today)->modify('+1 day')->format('Y-m-d');
        $nextEnd = (clone $today)->modify('+7 days')->format('Y-m-d');

        require_once __DIR__ . '/ChatContextBuilder.php';
        require_once __DIR__ . '/WorkoutAnalysisRepository.php';
        $ctx = new ChatContextBuilder($this->db);
        $repo = new WorkoutAnalysisRepository($this->db);

        return [
            'user' => $user,
            'week' => [
                'start' => $weekStart,
                'end' => $weekEnd,
                'planned' => $this->getPlannedDays($userId, $weekStart, $weekEnd),
                'actual' => $this->getActualWorkouts($userId, $weekStart, $weekEnd),
            ],
            'acwr' => $ctx->calculateACWR($userId),
            'compliance' => $ctx->getWeeklyCompliance($userId),
            'feedback' => $this->getRecentFeedback($userId),
            'next_week_days' => $this->getPlannedDays($userId, $nextStart, $nextEnd),
            'plan_history' => $repo->getSummaryLinesForActivePlan($userId),
            'plan_history_rollup' => $repo->getWeeklyRollupForActivePlan($userId),
            'plan_key_workouts' => $repo->getKeyWorkoutSummaryForActivePlan($userId),
        ];
    }

    private function getPlannedDays(int $userId, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare(
            "SELECT id, date, day_of_week, type, description, is_key_workout
             FROM training_plan_days
             WHERE user_id = ? AND date BETWEEN ? AND ?
             ORDER BY date ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('iss', $userId, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function getActualWorkouts(int $userId, string $startDate, string $endDate): array {
        $rows = [];

        $stmt = $this->db->prepare(
            "SELECT training_date AS date, distance_km, duration_minutes, pace, avg_heart_rate, rating, notes
             FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date BETWEEN ? AND ?
             ORDER BY training_date ASC"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $startDate, $endDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['src'] = 'log';
                $rows[] = $r;
            }
            $stmt->close();
        }

        $stmt = $this->db->prepare(
            "SELECT DATE(start_time) AS date, distance_km, duration_minutes, avg_pace AS pace, avg_heart_rate
             FROM workouts
             WHERE user_id = ? AND DATE(start_time) BETWEEN ? AND ?
             ORDER BY start_time ASC"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $startDate, $endDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['rating'] = null;
                $r['notes'] = null;
                $r['src'] = 'workout';
                $rows[] = $r;
            }
            $stmt->close();
        }

        usort($rows, fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $rows;
    }

    private function getRecentFeedback(int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT workout_date, session_rpe, legs_score, breath_score, hr_strain_score, pain_score, recovery_risk_score, pain_flag, fatigue_flag
             FROM post_workout_followups
             WHERE user_id = ? AND workout_date > DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             ORDER BY workout_date DESC
             LIMIT 10"
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    // ── LLM ───────────────────────────────────────────────────────────────

    private function callLlm(int $userId, array $inputs): ?array {
        $prompt = $this->buildPrompt($inputs);

        $payload = LlmGateway::withThinkingMode([
            'model' => $this->llmModel,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Ты — PlanRun, опытный тренер по бегу. Анализируешь неделю атлета и предлагаешь точечные изменения плана. Отвечай СТРОГО валидным JSON в указанном формате, без markdown-блоков и без комментариев.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'stream' => false,
            'max_tokens' => 1200,
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
        ], $this->llmBaseUrl, false);

        try {
            $response = LlmGateway::requestChatCompletion($this->llmBaseUrl, $payload, [
                'feature' => 'Weekly plan adaptation',
                'purpose' => 'chat',
                'db' => $this->db,
                'surface' => 'weekly_adaptation',
                'event_type' => 'llm_request',
                'user_id' => $userId,
                'timeout' => 60,
                'connect_timeout' => 5,
                'max_attempts' => 2,
            ]);
        } catch (Throwable $e) {
            Logger::warning('WeeklyAdaptation: LLM call failed', ['userId' => $userId, 'error' => $e->getMessage()]);
            return null;
        }

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return null;

        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/```\s*$/m', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            Logger::warning('WeeklyAdaptation: LLM returned non-JSON', [
                'userId' => $userId,
                'preview' => mb_substr($content, 0, 200),
            ]);
            return null;
        }

        return $parsed;
    }

    private function buildPrompt(array $inputs): string {
        $user = $inputs['user'];
        $week = $inputs['week'];
        $acwr = $inputs['acwr'];
        $compliance = $inputs['compliance'];

        $userBlock = "АТЛЕТ: " . ($user['username'] ?? 'спортсмен');
        if (!empty($user['race_date'])) {
            $userBlock .= ", забег {$user['race_date']}";
            if (!empty($user['race_distance'])) $userBlock .= " ({$user['race_distance']})";
            if (!empty($user['race_target_time'])) $userBlock .= ", цель {$user['race_target_time']}";
        }
        if (!empty($user['goal_type'])) {
            $userBlock .= ", цель: {$user['goal_type']}";
        }

        $weekBlock = "ПРОШЕДШАЯ НЕДЕЛЯ {$week['start']} – {$week['end']}\nПлан:\n";
        if (empty($week['planned'])) {
            $weekBlock .= "  (плана на эту неделю не было)\n";
        } else {
            foreach ($week['planned'] as $d) {
                $key = !empty($d['is_key_workout']) ? ' [КЛЮЧЕВАЯ]' : '';
                $desc = mb_substr(strip_tags((string) ($d['description'] ?? '')), 0, 120);
                $weekBlock .= "  {$d['date']} ({$d['type']}){$key}: {$desc}\n";
            }
        }

        $weekBlock .= "Факт:\n";
        if (empty($week['actual'])) {
            $weekBlock .= "  (тренировок за неделю не зафиксировано)\n";
        } else {
            foreach ($week['actual'] as $a) {
                $line = "  {$a['date']}: ";
                $line .= round((float) ($a['distance_km'] ?? 0), 1) . " км";
                if (!empty($a['duration_minutes'])) $line .= ", " . round((float) $a['duration_minutes']) . " мин";
                if (!empty($a['pace'])) $line .= ", темп {$a['pace']}";
                if (!empty($a['avg_heart_rate'])) $line .= ", ЧСС ср. {$a['avg_heart_rate']}";
                if (!empty($a['rating'])) $line .= ", оценка {$a['rating']}/5";
                if (!empty($a['notes'])) $line .= ", заметка: " . mb_substr(strip_tags((string) $a['notes']), 0, 80);
                $weekBlock .= $line . "\n";
            }
        }

        $metricsBlock = "МЕТРИКИ:\n";
        $acwrVal = round((float) ($acwr['acwr'] ?? 0), 2);
        $metricsBlock .= "  ACWR: {$acwrVal} (зона: " . ($acwr['zone'] ?? 'неизвестно') . ")\n";
        $planned = (int) ($compliance['planned'] ?? 0);
        $completed = (int) ($compliance['completed'] ?? 0);
        $metricsBlock .= "  Выполнение за неделю: {$completed}/{$planned}";
        if ($planned > 0) {
            $pct = (int) round(($completed / $planned) * 100);
            $metricsBlock .= " ({$pct}%)";
        }
        $metricsBlock .= "\n";

        if (!empty($inputs['feedback'])) {
            $metricsBlock .= "САМОЧУВСТВИЕ (по тренировкам):\n";
            foreach ($inputs['feedback'] as $f) {
                $flags = [];
                if (!empty($f['pain_flag'])) $flags[] = 'БОЛЬ';
                if (!empty($f['fatigue_flag'])) $flags[] = 'УСТАЛОСТЬ';
                $line = "  {$f['workout_date']}: RPE " . ($f['session_rpe'] ?? 'н/д');
                if (!empty($f['legs_score'])) $line .= ", ноги {$f['legs_score']}/10";
                if (!empty($flags)) $line .= " [" . implode(', ', $flags) . "]";
                $metricsBlock .= $line . "\n";
            }
        }

        $historyBlock = '';
        if (!empty($inputs['plan_history']) || !empty($inputs['plan_history_rollup'])) {
            $historyBlock = "ИСТОРИЯ ТРЕНИРОВОК (текущий план + хвост предыдущего цикла; ИСПОЛЬЗУЙ эти цифры, не считай сам):\n";
            if (!empty($inputs['plan_history_rollup'])) {
                $historyBlock .= "\nОбъёмы по неделям:\n";
                foreach ($inputs['plan_history_rollup'] as $line) {
                    $historyBlock .= "{$line}\n";
                }
            }
            if (!empty($inputs['plan_key_workouts'])) {
                $historyBlock .= "\nКлючевые/значимые тренировки (★=план key, ◆=значимая по факту):\n";
                foreach ($inputs['plan_key_workouts'] as $line) {
                    $historyBlock .= "  {$line}\n";
                }
            }
            if (!empty($inputs['plan_history'])) {
                $historyBlock .= "\nДетально (план → факт):\n";
                foreach ($inputs['plan_history'] as $line) {
                    $historyBlock .= "  {$line}\n";
                }
            }
            $historyBlock .= "\n";
        }

        $nextBlock = "ПЛАН НА СЛЕДУЮЩУЮ НЕДЕЛЮ:\n";
        foreach ($inputs['next_week_days'] as $d) {
            $key = !empty($d['is_key_workout']) ? ' [КЛЮЧЕВАЯ]' : '';
            $protect = in_array($d['type'], self::PROTECTED_TYPES, true) ? ' [ЗАЩИЩЁН — НЕ МЕНЯТЬ]' : '';
            $desc = mb_substr(strip_tags((string) ($d['description'] ?? '')), 0, 160);
            $nextBlock .= "  {$d['date']} ({$d['type']}){$key}{$protect}: {$desc}\n";
        }

        $allowedTypes = implode('|', self::ALLOWED_NEW_TYPES);
        $maxChanges = self::MAX_CHANGES;

        $rules = <<<TXT

ПРАВИЛА АДАПТАЦИИ:
1. Адаптация — только при необходимости. Если неделя прошла нормально (выполнение ≥70%, ACWR в норме 0.8–1.3, нет боли/усталости) — оставь план без изменений: верни {"changes": [], "no_changes_reason": "..."}.
2. Изменения только в плане на СЛЕДУЮЩУЮ неделю.
3. Максимум {$maxChanges} изменений за раз.
4. ЗАПРЕЩЕНО менять дни с типами race и control (контрольные и соревнования).
5. ЗАПРЕЩЕНО превращать long-run [КЛЮЧЕВАЯ] в rest — можно только сократить дистанцию через new_description.
6. Допустимые типы (для action=change_type): {$allowedTypes}.
7. Триггеры для адаптации:
   - ACWR > 1.5 → снизить нагрузку (заменить interval/tempo на easy, добавить rest)
   - ACWR < 0.7 → можно мягко повысить нагрузку (например, +1 easy день)
   - Compliance < 60% → упростить план (меньше ключевых, больше easy/rest)
   - pain_flag → ДОБАВИТЬ rest, исключить интенсивные работы на 1-2 дня
   - fatigue_flag → снизить интенсивность

ФОРМАТ ОТВЕТА (СТРОГО JSON, без markdown):
{
  "summary": "1-2 коротких предложения на русском, обращайся на «ты», объясни что изменено и почему",
  "changes": [
    {"date": "YYYY-MM-DD", "action": "change_type|change_description|set_rest", "new_type": "easy|long|tempo|...", "new_description": "детали на русском"}
  ],
  "no_changes_reason": "если адаптация не нужна — короткое объяснение"
}

Поля action:
- change_type — поменять тип тренировки (обязательно new_type и new_description)
- change_description — оставить тип, обновить описание (обязательно new_description)
- set_rest — превратить день в rest (new_type выставится автоматически = "rest")
TXT;

        return "{$userBlock}\n\n{$weekBlock}\n{$metricsBlock}\n{$historyBlock}{$nextBlock}\n{$rules}";
    }

    // ── Validation ────────────────────────────────────────────────────────

    private function validatePatch(array $changes, array $inputs): array {
        if (count($changes) > self::MAX_CHANGES) {
            return ['valid' => false, 'reason' => 'too_many_changes'];
        }

        $nextDaysByDate = [];
        foreach ($inputs['next_week_days'] as $d) {
            $nextDaysByDate[$d['date']] = $d;
        }

        $filtered = [];
        foreach ($changes as $change) {
            if (!is_array($change)) continue;
            $date = (string) ($change['date'] ?? '');
            $action = (string) ($change['action'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if (!isset($nextDaysByDate[$date])) continue;

            $existing = $nextDaysByDate[$date];
            if (in_array($existing['type'], self::PROTECTED_TYPES, true)) continue;

            $newType = $existing['type'];
            $newDescription = isset($change['new_description'])
                ? trim((string) $change['new_description'])
                : (string) $existing['description'];

            if ($action === 'set_rest') {
                if ($existing['type'] === 'long' && !empty($existing['is_key_workout'])) {
                    continue;
                }
                $newType = 'rest';
                if ($newDescription === '' || $newDescription === $existing['description']) {
                    $newDescription = 'Отдых';
                }
            } elseif ($action === 'change_type') {
                $candidate = (string) ($change['new_type'] ?? '');
                if (!in_array($candidate, self::ALLOWED_NEW_TYPES, true)) continue;
                if ($candidate === 'rest' && $existing['type'] === 'long' && !empty($existing['is_key_workout'])) continue;
                $newType = $candidate;
                if ($newDescription === '') $newDescription = $existing['description'];
            } elseif ($action === 'change_description') {
                if ($newDescription === '') continue;
            } else {
                continue;
            }

            $filtered[] = [
                'day_id' => (int) $existing['id'],
                'date' => $date,
                'old_type' => $existing['type'],
                'new_type' => $newType,
                'old_description' => (string) $existing['description'],
                'new_description' => $newDescription,
            ];
        }

        if (empty($filtered)) {
            return ['valid' => false, 'reason' => 'no_valid_changes'];
        }

        return ['valid' => true, 'filtered_changes' => $filtered];
    }

    // ── Apply ─────────────────────────────────────────────────────────────

    private function applyPatch(int $userId, array $changes): int {
        require_once __DIR__ . '/WeekService.php';
        $weekService = new WeekService($this->db);

        $applied = 0;
        foreach ($changes as $c) {
            try {
                $weekService->updateTrainingDayById((int) $c['day_id'], $userId, [
                    'type' => $c['new_type'],
                    'description' => $c['new_description'],
                ]);
                $applied++;
                $this->logChange($userId, $c);
            } catch (Throwable $e) {
                Logger::warning('WeeklyAdaptation: apply failed', [
                    'userId' => $userId,
                    'change' => $c,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $applied;
    }

    // ── Notification ──────────────────────────────────────────────────────

    private function notifyUser(int $userId, string $summary): void {
        require_once __DIR__ . '/ChatService.php';
        $chatService = new ChatService($this->db);
        try {
            $chatService->addAIMessageToUser($userId, $summary, [
                'event_key' => 'plan.weekly_adaptation',
                'title' => 'План на неделю адаптирован',
                'link' => '/calendar',
                'proactive_type' => 'weekly_adaptation',
                'push_data' => ['type' => 'plan', 'link' => '/calendar'],
            ]);
        } catch (Throwable $e) {
            Logger::warning('WeeklyAdaptation: notify failed', ['userId' => $userId, 'error' => $e->getMessage()]);
        }
    }

    // ── Cooldown ──────────────────────────────────────────────────────────

    private function isOnCooldown(int $userId): bool {
        $stmt = $this->db->prepare(
            "SELECT created_at FROM proactive_coach_log
             WHERE user_id = ? AND event_type = 'weekly_adaptation'
             ORDER BY created_at DESC
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;
        $diff = time() - strtotime($row['created_at']);
        return $diff < (self::COOLDOWN_DAYS * 86400);
    }

    private function recordCooldown(int $userId): void {
        $stmt = $this->db->prepare(
            "INSERT INTO proactive_coach_log (user_id, event_type) VALUES (?, 'weekly_adaptation')"
        );
        if (!$stmt) return;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    // ── Schema ────────────────────────────────────────────────────────────

    private function ensureSchema(): void {
        @$this->db->query("CREATE TABLE IF NOT EXISTS weekly_plan_adaptation_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            plan_day_id INT UNSIGNED NOT NULL,
            change_date DATE NOT NULL,
            old_type VARCHAR(32) NOT NULL,
            new_type VARCHAR(32) NOT NULL,
            old_description TEXT NULL,
            new_description TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function logChange(int $userId, array $change): void {
        $stmt = $this->db->prepare(
            "INSERT INTO weekly_plan_adaptation_log
                (user_id, plan_day_id, change_date, old_type, new_type, old_description, new_description)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return;
        $stmt->bind_param(
            'iisssss',
            $userId,
            $change['day_id'],
            $change['date'],
            $change['old_type'],
            $change['new_type'],
            $change['old_description'],
            $change['new_description']
        );
        $stmt->execute();
        $stmt->close();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function pluralChanges(int $n): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) return 'изменение';
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return 'изменения';
        return 'изменений';
    }
}
