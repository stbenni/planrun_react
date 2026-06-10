<?php
/**
 * Сервис для работы с тренировками и результатами
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../workout_types.php';
require_once __DIR__ . '/../calendar_access.php';
require_once __DIR__ . '/../query_helpers.php';
require_once __DIR__ . '/../repositories/WorkoutRepository.php';
require_once __DIR__ . '/../validators/WorkoutValidator.php';
require_once __DIR__ . '/PostWorkoutFollowupService.php';
require_once __DIR__ . '/WorkoutShareCardCacheService.php';
require_once __DIR__ . '/LlmGateway.php';

class WorkoutService extends BaseService {
    
    protected $repository;
    protected $validator;
    private $tableExistsCache = [];
    private $shareCardCacheService = null;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new WorkoutRepository($db);
        $this->validator = new WorkoutValidator();
    }

    private function workoutShareCardCache(): WorkoutShareCardCacheService {
        if (!$this->shareCardCacheService) {
            $this->shareCardCacheService = new WorkoutShareCardCacheService($this->db);
        }
        return $this->shareCardCacheService;
    }

    private function queueWorkoutShareCards(int $userId, int $workoutId, string $workoutKind): int {
        if ($userId <= 0 || $workoutId <= 0) {
            return 0;
        }

        try {
            $cache = $this->workoutShareCardCache();
            if (!$cache->isInfrastructureAvailable()) {
                return 0;
            }

            $jobs = $cache->refreshQueuedCardsForWorkout($userId, $workoutId, $workoutKind);
            return count($jobs);
        } catch (Throwable $e) {
            $this->logInfo('Workout share card queue skipped', [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'workout_kind' => $workoutKind,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function schedulePostWorkoutFollowup(int $userId, string $workoutDate, string $sourceKind, int $sourceId, ?int $analysisMessageId = null): bool {
        if ($userId <= 0 || $sourceId <= 0) {
            return false;
        }

        try {
            $scheduled = (new PostWorkoutFollowupService($this->db))->scheduleForWorkout(
                $userId,
                $workoutDate,
                $sourceKind,
                $sourceId,
                $analysisMessageId
            );
            $this->logDebug('Post-workout followup scheduling checked', [
                'user_id' => $userId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'scheduled' => $scheduled,
            ]);
            return $scheduled;
        } catch (Throwable $e) {
            $this->logError('Post-workout followup scheduling failed', [
                'user_id' => $userId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function handlePostWorkoutCoachFlow(int $userId, string $workoutDate, string $sourceKind, int $sourceId): void {
        $this->schedulePostWorkoutFollowup($userId, $workoutDate, $sourceKind, $sourceId);

        if (!$this->isPostWorkoutAnalysisEnabled()) {
            return;
        }

        $followup = $this->getPostWorkoutFollowupRow($userId, $sourceKind, $sourceId);
        if ($followup === null) {
            return;
        }

        $status = (string) ($followup['status'] ?? '');
        if (!in_array($status, ['pending', 'sent', 'completed'], true)) {
            return;
        }

        if (!empty($followup['analysis_message_id'])) {
            return;
        }

        try {
            $messageId = $this->createPostWorkoutAnalysisMessage($userId, $workoutDate, $sourceKind, $sourceId);
            if ($messageId > 0) {
                $this->attachPostWorkoutAnalysisMessage($userId, $sourceKind, $sourceId, $messageId);
            }
        } catch (Throwable $e) {
            $this->logError('Post-workout analysis generation failed', [
                'user_id' => $userId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Публичная точка входа в post-workout coach flow для внешних импортёров,
     * которые сохраняют тренировку в БД напрямую, минуя importWorkouts()
     * (например, Telegram-бот при загрузке FIT/GPX). Планирует follow-up и
     * генерирует разбор тренера так же, как импорт через приложение.
     */
    public function triggerPostWorkoutCoachFlow(int $userId, string $workoutDate, int $workoutId, string $sourceKind = 'workout'): void {
        if ($userId <= 0 || $workoutId <= 0) {
            return;
        }
        $this->handlePostWorkoutCoachFlow($userId, $workoutDate, $sourceKind, $workoutId);
    }

    private function isPostWorkoutAnalysisEnabled(): bool {
        $explicit = env('POST_WORKOUT_ANALYSIS_ENABLED', null);
        if ((string) ($_ENV['APP_ENV'] ?? '') === 'testing' && $explicit === null) {
            return false;
        }

        return (int) env('POST_WORKOUT_ANALYSIS_ENABLED', 1) === 1;
    }

    private function getPostWorkoutFollowupRow(int $userId, string $sourceKind, int $sourceId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, status, analysis_message_id
             FROM post_workout_followups
             WHERE user_id = ? AND source_kind = ? AND source_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('isi', $userId, $sourceKind, $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function attachPostWorkoutAnalysisMessage(int $userId, string $sourceKind, int $sourceId, int $messageId): void {
        $stmt = $this->db->prepare(
            "UPDATE post_workout_followups
             SET analysis_message_id = ?
             WHERE user_id = ? AND source_kind = ? AND source_id = ?"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iisi', $messageId, $userId, $sourceKind, $sourceId);
        $stmt->execute();
        $stmt->close();
    }

    private function createPostWorkoutAnalysisMessage(int $userId, string $workoutDate, string $sourceKind, int $sourceId): int {
        $summary = $this->fetchWorkoutAnalysisSummary($userId, $sourceKind, $sourceId);
        if ($summary === null) {
            return 0;
        }

        $planned = $this->fetchPlannedWorkoutForDate($userId, (string) ($summary['workout_date'] ?? $workoutDate));
        $structure = null;
        if ($sourceKind === 'workout') {
            require_once __DIR__ . '/WorkoutStructureAnalyzer.php';
            $structure = (new WorkoutStructureAnalyzer($this->db))->analyze($sourceId, null, $userId);
        }
        $analysis = $this->generatePostWorkoutAnalysisText($userId, $summary, $planned, $structure);
        if ($analysis === '') {
            return 0;
        }

        $this->persistWorkoutAnalysis($userId, $sourceKind, $sourceId, $summary, $planned, $structure, $analysis);

        require_once __DIR__ . '/ChatService.php';
        $chatService = new ChatService($this->db);
        $result = $chatService->addAIMessageToUser($userId, $analysis, [
            'event_key' => 'coach.proactive_post_workout_analysis',
            'title' => 'Разбор тренировки',
            'link' => '/chat',
            'proactive_type' => 'post_workout_analysis',
            'push_data' => ['post_workout_analysis' => true],
        ]);

        return (int) ($result['message_id'] ?? 0);
    }

    private function fetchWorkoutAnalysisSummary(int $userId, string $sourceKind, int $sourceId): ?array {
        if ($sourceKind === 'workout') {
            $stmt = $this->db->prepare(
                "SELECT id,
                        DATE(COALESCE(end_time, start_time)) AS workout_date,
                        LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type,
                        distance_km,
                        duration_minutes,
                        duration_seconds,
                        avg_pace AS pace,
                        avg_heart_rate,
                        max_heart_rate,
                        elevation_gain,
                        source
                 FROM workouts
                 WHERE id = ? AND user_id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('ii', $sourceId, $userId);
        } else {
            $stmt = $this->db->prepare(
                "SELECT wl.id,
                        wl.training_date AS workout_date,
                        LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type,
                        wl.distance_km,
                        wl.duration_minutes,
                        NULL AS duration_seconds,
                        COALESCE(NULLIF(TRIM(wl.pace), ''), NULLIF(TRIM(wl.result_time), '')) AS pace,
                        wl.avg_heart_rate,
                        wl.max_heart_rate,
                        wl.elevation_gain,
                        'manual' AS source,
                        wl.rating,
                        wl.notes
                 FROM workout_log wl
                 LEFT JOIN activity_types at ON at.id = wl.activity_type_id
                 WHERE wl.id = ? AND wl.user_id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('ii', $sourceId, $userId);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchPlannedWorkoutForDate(int $userId, string $date): ?array {
        if ($date === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT d.type, d.description, d.is_key_workout
             FROM training_plan_days d
             INNER JOIN training_plan_weeks w ON w.id = d.week_id
             WHERE w.user_id = ? AND d.date = ?
             ORDER BY d.id ASC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Если импортированный workout соответствует плановому race/control дню,
     * обновляет users.last_race_* и запускает auto-recalc через VDOT pipeline.
     *
     * Это критично: иначе marathon из Strava не учитывается в VDOT,
     * и план продолжает строиться под устаревший last_race.
     */
    private function maybeUpdateLastRaceFromImport(
        int $userId,
        string $workoutDate,
        ?float $distanceKm,
        ?int $durationSeconds,
        ?int $durationMinutes,
        ?string $avgPace
    ): void {
        if ($distanceKm === null || $distanceKm < 4) return;

        // Соответствует ли этот день плановому race/control?
        $stmt = $this->db->prepare(
            "SELECT d.type FROM training_plan_days d
             INNER JOIN training_plan_weeks w ON w.id = d.week_id
             WHERE w.user_id = ? AND d.date = ?
             LIMIT 1"
        );
        if (!$stmt) return;
        $stmt->bind_param('is', $userId, $workoutDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $plannedType = (string) ($row['type'] ?? '');
        if (!in_array($plannedType, ['race', 'control'], true)) {
            return;
        }

        $timeSec = $durationSeconds && $durationSeconds > 0
            ? (int) $durationSeconds
            : ($durationMinutes && $durationMinutes > 0 ? (int) $durationMinutes * 60 : 0);
        if ($timeSec <= 0) return;

        // Уже более свежий результат?
        $checkStmt = $this->db->prepare("SELECT last_race_date FROM users WHERE id = ?");
        if (!$checkStmt) return;
        $checkStmt->bind_param('i', $userId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        if ($existing && !empty($existing['last_race_date']) && $existing['last_race_date'] >= $workoutDate) {
            return;
        }

        require_once __DIR__ . '/TrainingStateBuilder.php';
        require_once __DIR__ . '/WorkoutPlanRecalculationService.php';
        require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

        $newVdot = estimateVDOT($distanceKm, $timeSec);
        if ($newVdot < 20 || $newVdot > 85) return;

        $builder = new TrainingStateBuilder($this->db);
        $oldState = $builder->buildForUserId($userId);
        $oldVdot = isset($oldState['vdot']) ? (float) $oldState['vdot'] : null;

        $resultTime = $this->formatSecToTime($timeSec);
        $distMap = [5 => '5k', 10 => '10k', 21 => 'half', 42 => 'marathon'];
        $lastRaceDist = 'other';
        $lastRaceDistKm = $distanceKm;
        foreach ($distMap as $km => $label) {
            if (abs($distanceKm - $km) < 0.6) {
                $lastRaceDist = $label;
                $lastRaceDistKm = null;
                break;
            }
        }

        $updateStmt = $this->db->prepare(
            "UPDATE users SET last_race_distance = ?, last_race_distance_km = ?,
                              last_race_time = ?, last_race_date = ?
             WHERE id = ?"
        );
        if (!$updateStmt) return;
        $updateStmt->bind_param('sdssi', $lastRaceDist, $lastRaceDistKm, $resultTime, $workoutDate, $userId);
        $updateStmt->execute();
        $updateStmt->close();

        $this->logInfo("Last race auto-synced from import", [
            'user_id' => $userId,
            'date' => $workoutDate,
            'distance_km' => $distanceKm,
            'time' => $resultTime,
            'new_vdot' => round($newVdot, 1),
            'old_vdot' => $oldVdot,
            'planned_type' => $plannedType,
        ]);

        try {
            (new WorkoutPlanRecalculationService($this->db))->maybeQueueAfterPerformanceUpdate(
                $userId,
                $plannedType,
                $workoutDate,
                $oldVdot,
                $newVdot
            );
        } catch (Throwable $e) {
            $this->logError('auto-recalc after import failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatSecToTime(int $sec): string {
        $h = (int) ($sec / 3600);
        $m = (int) (($sec % 3600) / 60);
        $s = $sec % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    private function persistWorkoutAnalysis(
        int $userId,
        string $sourceKind,
        int $sourceId,
        array $summary,
        ?array $planned,
        ?array $structure,
        string $llmText
    ): void {
        try {
            require_once __DIR__ . '/WorkoutAnalysisRepository.php';
            $repo = new WorkoutAnalysisRepository($this->db);

            $feedback = $this->fetchLatestFeedbackForWorkout($userId, $sourceKind, $sourceId);

            $duration = null;
            if (!empty($summary['duration_minutes'])) {
                $duration = (int) $summary['duration_minutes'];
            } elseif (!empty($summary['duration_seconds'])) {
                $duration = (int) round((int) $summary['duration_seconds'] / 60);
            }

            $data = [
                'user_id' => $userId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'workout_date' => (string) ($summary['workout_date'] ?? date('Y-m-d')),
                'planned_type' => $planned['type'] ?? null,
                'planned_description' => $planned['description'] ?? null,
                'planned_is_key' => $planned['is_key_workout'] ?? null,
                'actual_distance_km' => $summary['distance_km'] ?? null,
                'actual_duration_min' => $duration,
                'actual_avg_pace' => $summary['pace'] ?? null,
                'actual_avg_hr' => $summary['avg_heart_rate'] ?? null,
                'actual_max_hr' => $summary['max_heart_rate'] ?? null,
                'detected_type' => $structure['type'] ?? null,
                'detected_confidence' => $structure['confidence'] ?? null,
                'intensity' => $structure['avg_hr_pct_max'] ?? null,
                'pace_variance' => $structure['pace_variance'] ?? null,
                'structure' => $structure,
                'llm_review_text' => $llmText,
                'feedback_rpe' => $feedback['session_rpe'] ?? null,
                'feedback_legs' => $feedback['legs_score'] ?? null,
                'feedback_pain_flag' => $feedback['pain_flag'] ?? null,
                'feedback_fatigue_flag' => $feedback['fatigue_flag'] ?? null,
            ];
            $data['summary_line'] = WorkoutAnalysisRepository::formatSummaryLine($data);

            $repo->save($data);
        } catch (Throwable $e) {
            $this->logError('persistWorkoutAnalysis failed', [
                'user_id' => $userId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fetchLatestFeedbackForWorkout(int $userId, string $sourceKind, int $sourceId): array {
        $stmt = $this->db->prepare(
            "SELECT session_rpe, legs_score, pain_flag, fatigue_flag
             FROM post_workout_followups
             WHERE user_id = ? AND source_kind = ? AND source_id = ? AND status IN ('responded', 'completed')
             ORDER BY responded_at DESC, id DESC
             LIMIT 1"
        );
        if (!$stmt) return [];
        $stmt->bind_param('isi', $userId, $sourceKind, $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: [];
    }

    private function generatePostWorkoutAnalysisText(int $userId, array $summary, ?array $planned, ?array $structure = null): string {
        $fake = trim((string) env('POST_WORKOUT_ANALYSIS_FAKE_RESPONSE', ''));
        if ((string) ($_ENV['APP_ENV'] ?? '') === 'testing' && $fake !== '') {
            return $fake;
        }

        $facts = $this->buildPostWorkoutAnalysisFacts($summary, $planned, $structure);
        if ($facts === '') {
            return '';
        }

        $llmText = $this->callPostWorkoutAnalysisLlm($facts, $userId);
        if ($llmText !== '') {
            return $llmText;
        }

        return $this->buildPostWorkoutAnalysisFallback($summary, $planned);
    }

    private function buildPostWorkoutAnalysisFacts(array $summary, ?array $planned, ?array $structure = null): string {
        $lines = [];
        $lines[] = 'Фактическая тренировка:';
        $lines[] = '- дата: ' . (string) ($summary['workout_date'] ?? '');
        $lines[] = '- тип активности: ' . (string) ($summary['activity_type'] ?? 'running');

        if (isset($summary['distance_km']) && (float) $summary['distance_km'] > 0) {
            $lines[] = '- дистанция: ' . round((float) $summary['distance_km'], 2) . ' км';
        }
        if (isset($summary['duration_minutes']) && (int) $summary['duration_minutes'] > 0) {
            $lines[] = '- длительность: ' . (int) $summary['duration_minutes'] . ' мин';
        } elseif (isset($summary['duration_seconds']) && (int) $summary['duration_seconds'] > 0) {
            $lines[] = '- длительность: ' . round((int) $summary['duration_seconds'] / 60) . ' мин';
        }
        if (!empty($summary['pace'])) {
            $lines[] = '- темп/результат: ' . trim((string) $summary['pace']);
        }
        if (!empty($summary['avg_heart_rate'])) {
            $lines[] = '- средний пульс: ' . (int) $summary['avg_heart_rate'];
        }
        if (!empty($summary['max_heart_rate'])) {
            $lines[] = '- максимальный пульс: ' . (int) $summary['max_heart_rate'];
        }
        if (!empty($summary['rating'])) {
            $lines[] = '- оценка пользователя: ' . (int) $summary['rating'] . '/5';
        }
        if (!empty($summary['notes'])) {
            $lines[] = '- заметки пользователя: ' . mb_substr(trim((string) $summary['notes']), 0, 500, 'UTF-8');
        }

        if ($planned !== null) {
            $lines[] = '';
            $lines[] = 'План на этот день:';
            $lines[] = '- тип: ' . (string) ($planned['type'] ?? '');
            $description = trim((string) ($planned['description'] ?? ''));
            if ($description !== '') {
                $lines[] = '- описание: ' . mb_substr($description, 0, 700, 'UTF-8');
            }
        }

        if ($structure !== null) {
            $lines[] = '';
            $lines[] = 'Структура тренировки (из lap-данных, классификация автоматическая):';
            $lines[] = '- распознанный тип: ' . (string) ($structure['type'] ?? 'mixed')
                . ' (уверенность: ' . (string) ($structure['confidence'] ?? 'low') . ')';
            $lines[] = '- ' . (string) ($structure['narrative'] ?? '');
            if (!empty($structure['median_pace'])) {
                $lines[] = '- медианный темп: ' . $structure['median_pace'] . ' мин/км';
            }
            if (!empty($structure['lap_table'])) {
                $lines[] = '- лапы (▲=быстрее медианы на 15%+, ▽=медленнее, ·=обычный):';
                foreach ($structure['lap_table'] as $lap) {
                    $line = sprintf(
                        '  %s lap %d: %s км · %s мин/км',
                        $lap['mark'] ?? '·',
                        (int) ($lap['idx'] ?? 0),
                        (string) ($lap['km'] ?? ''),
                        (string) ($lap['pace'] ?? '—')
                    );
                    if (!empty($lap['hr'])) {
                        $line .= ' · HR ' . (int) $lap['hr'];
                        if (!empty($lap['zone'])) $line .= ' (' . $lap['zone'] . ')';
                    }
                    $lines[] = $line;
                }
            }
            if (!empty($structure['detected']['reps'])) {
                $d = $structure['detected'];
                $lines[] = '- автодетект интервалов по треку (точнее лап-данных): ' . (string) ($d['pattern'] ?? '');
                foreach ($d['reps'] as $i => $rep) {
                    $rl = sprintf('  отрезок %d: %d м · %s мин/км', $i + 1, (int) ($rep['distance_m'] ?? 0), (string) ($rep['pace'] ?? '—'));
                    if (!empty($rep['avg_hr'])) $rl .= ' · HR ' . (int) $rep['avg_hr'];
                    $lines[] = $rl;
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    private function callPostWorkoutAnalysisLlm(string $facts, int $userId): string {
        $baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
        $model = trim((string) env('LLM_CHAT_MODEL', 'deepseek-v4-flash'));
        if ($baseUrl === '' || $model === '') {
            return '';
        }

        $systemPrompt = <<<'SYSTEM'
Ты — AI-тренер PlanRun. Напиши разбор только что завершённой тренировки атлета.

ФОРМАТ ОТВЕТА:
- 3–4 связных предложения (НЕ bullet-список, НЕ пункты через дефис, НЕ нумерация).
- 1-е предложение: что было — тип тренировки и ключевая цифра. Если есть «Структура тренировки», используй распознанный тип (например, «Темповый бег 8 км отработан на 4:09/км — пульс в зоне T»).
- 2-е: что особенно отметить — конкретный факт о структуре, темпе, пульсе или зонах. Используй цифры из лап-таблицы или среднего пульса.
- 3-е: связь с планом или историей — совпадает ли с задачей дня, что это значит в общей картине подготовки.
- 4-е (если уместно): один actionable вывод — на что обратить внимание в следующей похожей тренировке.

ОБЯЗАТЕЛЬНО:
- Строго на русском. На «ты».
- Используй ТОЛЬКО факты из входных данных. Ничего не выдумывай.
- Если темп/пульс/распознанный тип отличается от плана — отметь нейтрально, без укоризны, объясни возможную причину.
- Конкретика важнее общих слов: «темп 4:09/км ровно, пульс 153 уд (94% от max) — в зоне T» лучше чем «хорошая тренировка».

ЗАПРЕЩЕНО:
- Эмодзи и любые символы кроме букв и пунктуации.
- "Молодец", "Так держать", "Отлично", "Хорошая работа", "Поздравляю".
- Английские слова: easy, tempo, interval, recovery, base, build, plan, fact, check-in — пиши по-русски.
- Bullet-points, дефисы в начале строк, нумерация.
- Приветствия, подписи, "Привет".
- Вопросы атлету о самочувствии — отдельный чек-ин придёт позже.
SYSTEM;

        $payload = LlmGateway::withThinkingMode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $facts],
            ],
            'stream' => false,
            'max_tokens' => max(200, min(1200, (int) env('POST_WORKOUT_ANALYSIS_MAX_TOKENS', 650))),
            'temperature' => 0.25,
        ], $baseUrl, false);

        try {
            $response = LlmGateway::requestChatCompletion($baseUrl, $payload, [
                'feature' => 'Post-workout analysis',
                'purpose' => 'chat',
                'db' => $this->db,
                'surface' => 'post_workout_analysis',
                'event_type' => 'llm_request',
                'user_id' => $userId,
                'timeout' => max(5, min(90, (int) env('POST_WORKOUT_ANALYSIS_TIMEOUT_SECONDS', 25))),
                'connect_timeout' => max(1, min(20, (int) env('POST_WORKOUT_ANALYSIS_CONNECT_TIMEOUT_SECONDS', 5))),
                'max_attempts' => max(1, min(5, (int) env('LLM_MAX_RETRIES', 1))),
            ]);
        } catch (Throwable $e) {
            $this->logError('Post-workout analysis LLM call failed', [
                'error' => $e->getMessage(),
            ]);
            return '';
        }

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        $content = $this->normalizeLlmProse($content);
        return mb_substr($content, 0, 2500, 'UTF-8');
    }

    /**
     * Превращает LLM-ответ в связный prose-текст:
     * убирает bullet-маркеры в начале строк, схлопывает в один абзац.
     * LLM периодически нарушает запрет на дефисы — это безопасный фолбэк.
     */
    private function normalizeLlmProse(string $text): string {
        if ($text === '') return '';
        // Убираем markdown bold/italic и backticks
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        $text = preg_replace('/\*(.+?)\*/u', '$1', $text);
        $text = str_replace('`', '', $text);
        // Построчно убираем ведущие маркеры: «-», «—», «–», «*», «•», «1.», «1)»
        $lines = preg_split('/\r?\n/u', $text);
        $clean = [];
        foreach ($lines as $line) {
            $line = ltrim($line);
            $line = preg_replace('/^(?:[-—–*•·]+|\d+[.)])\s+/u', '', $line);
            $line = trim($line);
            if ($line !== '') $clean[] = $line;
        }
        // Соединяем в один связный текст; пустых строк не оставляем
        $joined = implode(' ', $clean);
        // Лишние пробелы и точки
        $joined = preg_replace('/\s+/u', ' ', $joined);
        $joined = preg_replace('/\s+([,.;:!?])/u', '$1', $joined);
        return trim($joined);
    }

    private function buildPostWorkoutAnalysisFallback(array $summary, ?array $planned): string {
        $distance = isset($summary['distance_km']) && (float) $summary['distance_km'] > 0
            ? round((float) $summary['distance_km'], 1) . ' км'
            : 'тренировка';
        $duration = isset($summary['duration_minutes']) && (int) $summary['duration_minutes'] > 0
            ? ', ' . (int) $summary['duration_minutes'] . ' мин'
            : '';

        $text = "Разбор тренировки: {$distance}{$duration} сохранена. ";
        if ($planned !== null && trim((string) ($planned['type'] ?? '')) !== '') {
            $text .= 'Я сопоставил её с планом на день и оставил как выполненную работу. ';
        }
        $text .= 'Отдельно через некоторое время спрошу про самочувствие, чтобы понять восстановление после нагрузки.';

        return $text;
    }

    private function deleteWorkoutShareCards(int $userId, int $workoutId, string $workoutKind): void {
        if ($userId <= 0 || $workoutId <= 0) {
            return;
        }

        try {
            $cache = $this->workoutShareCardCache();
            if (!$cache->isInfrastructureAvailable()) {
                return;
            }

            $cache->deleteWorkoutAssets($userId, $workoutId, $workoutKind);
        } catch (Throwable $e) {
            $this->logInfo('Workout share card cleanup skipped', [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'workout_kind' => $workoutKind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function launchWorkoutShareWorkerAsync(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $scriptPath = dirname(__DIR__) . '/scripts/workout_share_worker.php';
        if (!is_file($scriptPath)) {
            return;
        }

        $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' --drain > /dev/null 2>&1 &';

        try {
            if (function_exists('popen') && function_exists('pclose')) {
                $process = @popen($command, 'r');
                if (is_resource($process)) {
                    @pclose($process);
                    return;
                }
            }

            if (function_exists('exec')) {
                @exec($command);
            }
        } catch (Throwable $e) {
            $this->logInfo('Workout share worker launch skipped', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Получить все результаты тренировок
     * 
     * @param int $userId ID пользователя
     * @return array Массив результатов
     * @throws Exception
     */
    public function getAllResults($userId) {
        try {
            // Используем репозиторий
            $results = $this->repository->getAllResults($userId);
            
            return ['results' => $results];
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки всех результатов: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить результат тренировки за конкретный день
     * 
     * @param string $date Дата тренировки (Y-m-d)
     * @param int $weekNumber Номер недели
     * @param string $dayName Название дня (mon, tue, etc.)
     * @param int $userId ID пользователя
     * @return array|null Результат тренировки или null
     * @throws Exception
     */
    public function getResult($date, $weekNumber, $dayName, $userId) {
        try {
            // Используем репозиторий
            return $this->repository->getResultByDate($userId, $date, $weekNumber, $dayName);
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки результата: ' . $e->getMessage(), 500, [
                'date' => $date,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить день тренировки со всеми данными
     * 
     * @param string $date Дата (Y-m-d)
     * @param int $userId ID пользователя
     * @return array Данные дня тренировки
     * @throws Exception
     */
    public function getDay($date, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateGetDay(['date' => $date])) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            require_once __DIR__ . '/../load_training_plan.php';
            require_once __DIR__ . '/../user_functions.php';
            
            // Получаем все записи плана на этот день (несколько тренировок в день разрешены)
            $plan = null;
            $planHtml = null;
            $planType = null;
            $planDayId = null;
            $planDays = [];
            $weekNumber = null;
            
            $dateObj = new DateTime($date);
            $dayOfWeek = (int)$dateObj->format('N');
            $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
            $dayName = $dayNames[$dayOfWeek];
            
            $planStmt = $this->db->prepare("SELECT id, type, description, date, is_key_workout FROM training_plan_days WHERE user_id = ? AND date = ? ORDER BY id");
            $planStmt->bind_param("is", $userId, $date);
            $planStmt->execute();
            $planResult = $planStmt->get_result();
            $planBlocks = [];
            while ($planRow = $planResult->fetch_assoc()) {
                $pid = (int)$planRow['id'];
                $planDays[] = [
                    'id' => $pid,
                    'type' => $planRow['type'],
                    'description' => $planRow['description'],
                    'is_key_workout' => (bool)($planRow['is_key_workout'] ?? 0),
                ];
                if ($planDayId === null) {
                    $planDayId = $pid;
                    $planType = $planRow['type'];
                    $plan = strip_tags(str_replace('<br>', "\n", $planRow['description'] ?? ''));
                }
                $desc = $planRow['description'] ?? '';
                $planBlocks[] = '<div class="plan-day-block" data-plan-day-id="' . (int)$pid . '">'
                    . '<div class="plan-day-text">' . $desc . '</div>'
                    . '<div class="plan-day-actions">'
                    . '<button type="button" class="btn-edit-plan-day" data-plan-day-id="' . (int)$pid . '" title="Редактировать тренировку">Изменить</button>'
                    . '<button type="button" class="btn-delete-plan-day" data-plan-day-id="' . (int)$pid . '" title="Удалить тренировку">Удалить</button>'
                    . '</div></div>';
                if ($weekNumber === null && $planRow['date'] && $planRow['type'] !== 'rest') {
                    $weekStmt = $this->db->prepare("
                        SELECT week_number FROM training_plan_weeks
                        WHERE user_id = ? AND start_date <= ? AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ?
                        ORDER BY start_date DESC LIMIT 1
                    ");
                    $weekStmt->bind_param("iss", $userId, $date, $date);
                    $weekStmt->execute();
                    $weekResultInner = $weekStmt->get_result();
                    if ($wr = $weekResultInner->fetch_assoc()) {
                        $weekNumber = (int)$wr['week_number'];
                    }
                    $weekStmt->close();
                }
            }
            $planStmt->close();
            if (count($planBlocks) > 0) {
                $planHtml = '<div class="plan-day-blocks">' . implode('', $planBlocks) . '</div>';
            }
            $firstPlan = $planDays[0] ?? null;
            
            // Если week_number не найден, пытаемся найти его по дате
            if (!$weekNumber) {
                $weekStmt = $this->db->prepare("
                    SELECT week_number 
                    FROM training_plan_weeks 
                    WHERE user_id = ? 
                      AND start_date <= ? 
                      AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ?
                    ORDER BY start_date DESC
                    LIMIT 1
                ");
                $weekStmt->bind_param("iss", $userId, $date, $date);
                $weekStmt->execute();
                $weekResult = $weekStmt->get_result();
                if ($weekRow = $weekResult->fetch_assoc()) {
                    $weekNumber = (int)$weekRow['week_number'];
                }
                $weekStmt->close();
            }
            
            // Получаем тренировки за этот день
            $dateStart = $date . ' 00:00:00';
            $dateEnd = $date . ' 23:59:59';
            $workoutsRaw = $this->repository->getWorkoutsByDate($userId, $dateStart, $dateEnd);
            
            // Формируем массив тренировок с URL
            $workouts = [];
            foreach ($workoutsRaw as $workout) {
                $workout['workout_url'] = getWorkoutDetailsUrl($workout['id'], $workout['user_id']);
                $workouts[] = $workout;
            }
            
            if (!is_array($workouts)) {
                $workouts = [];
            }
            
            // Получаем ручные тренировки из workout_log
            $logStmt = null;
            $logRow = null;
            
            if ($weekNumber) {
                $logStmt = $this->db->prepare("
                    SELECT wl.id, wl.distance_km, wl.result_time, wl.duration_minutes, wl.pace, 
                           wl.avg_heart_rate, wl.max_heart_rate, wl.elevation_gain, wl.notes,
                           at.name as activity_type_name
                    FROM workout_log wl
                    LEFT JOIN activity_types at ON wl.activity_type_id = at.id
                    WHERE wl.user_id = ? AND wl.training_date = ? AND wl.week_number = ? AND wl.day_name = ? 
                    AND wl.is_completed = 1
                    LIMIT 1
                ");
                $logStmt->bind_param("isis", $userId, $date, $weekNumber, $dayName);
            } else {
                $logStmt = $this->db->prepare("
                    SELECT wl.id, wl.distance_km, wl.result_time, wl.duration_minutes, wl.pace, 
                           wl.avg_heart_rate, wl.max_heart_rate, wl.elevation_gain, wl.notes,
                           at.name as activity_type_name
                    FROM workout_log wl
                    LEFT JOIN activity_types at ON wl.activity_type_id = at.id
                    WHERE wl.user_id = ? AND wl.training_date = ? AND wl.day_name = ? 
                    AND wl.is_completed = 1
                    LIMIT 1
                ");
                $logStmt->bind_param("iss", $userId, $date, $dayName);
            }
            
            $logStmt->execute();
            $logResult = $logStmt->get_result();
            $logRow = $logResult->fetch_assoc();
            $logStmt->close();
            
            if ($logRow) {
                // Проверяем, есть ли соответствующая автоматическая тренировка
                $hasAutomaticWorkout = false;
                if ($logRow['distance_km'] && $logRow['distance_km'] > 0) {
                    $dupTol = max(1.5, (float)$logRow['distance_km'] * 0.1);
                    foreach ($workouts as $workout) {
                        $workoutDate = date('Y-m-d', strtotime($workout['start_time']));
                        if ($workoutDate === $date &&
                            abs($workout['distance_km'] - $logRow['distance_km']) <= $dupTol) {
                            $hasAutomaticWorkout = true;
                            break;
                        }
                    }
                }
                
                if (!$hasAutomaticWorkout) {
                    $manualTraining = [
                        'id' => (int)$logRow['id'],
                        'user_id' => $userId,
                        'activity_type' => $logRow['activity_type_name'] ?? 'running',
                        'start_time' => $date . ' 12:00:00',
                        'duration_minutes' => $logRow['duration_minutes'],
                        'result_time' => $logRow['result_time'],
                        'distance_km' => $logRow['distance_km'],
                        'avg_pace' => $logRow['pace'],
                        'avg_heart_rate' => $logRow['avg_heart_rate'],
                        'max_heart_rate' => $logRow['max_heart_rate'],
                        'elevation_gain' => $logRow['elevation_gain'],
                        'is_manual' => true,
                        'type' => $firstPlan['type'] ?? null,
                        'description' => $firstPlan['description'] ?? null,
                        'is_key_workout' => $firstPlan['is_key_workout'] ?? false,
                        'notes' => $logRow['notes']
                    ];
                    array_unshift($workouts, $manualTraining);
                }
            } else if ($firstPlan && ($firstPlan['type'] === 'other' || $firstPlan['type'] === 'free')) {
                $manualTraining = [
                    'id' => null,
                    'user_id' => $userId,
                    'activity_type' => 'other',
                    'start_time' => $date . ' 12:00:00',
                    'duration_minutes' => null,
                    'distance_km' => null,
                    'avg_pace' => null,
                    'avg_heart_rate' => null,
                    'max_heart_rate' => null,
                    'elevation_gain' => null,
                    'is_manual' => true,
                    'type' => $firstPlan['type'],
                    'description' => $firstPlan['description'],
                    'is_key_workout' => $firstPlan['is_key_workout']
                ];
                array_unshift($workouts, $manualTraining);
            }
            
            // Получаем структурированные упражнения дня — по всем плановым дням (новый формат)
            $dayExercises = [];
            $planDayIdsWithExercises = [];
            if (count($planDays) > 0) {
                try {
                    require_once __DIR__ . '/../repositories/ExerciseRepository.php';
                    $exerciseRepo = new ExerciseRepository($this->db);
                    foreach ($planDays as $pd) {
                        $pid = (int)$pd['id'];
                        $exercisesRaw = $exerciseRepo->getExercisesByDayId($pid, $userId);
                        foreach ($exercisesRaw as $exerciseRow) {
                            $planDayIdsWithExercises[$pid] = true;
                            $dayExercises[] = [
                                'id' => (int)$exerciseRow['id'],
                                'exercise_id' => $exerciseRow['exercise_id'] ? (int)$exerciseRow['exercise_id'] : null,
                                'plan_day_id' => $pid,
                                'category' => $exerciseRow['category'],
                                'name' => $exerciseRow['name'],
                                'sets' => $exerciseRow['sets'],
                                'reps' => $exerciseRow['reps'],
                                'distance_m' => $exerciseRow['distance_m'],
                                'duration_sec' => $exerciseRow['duration_sec'],
                                'weight_kg' => $exerciseRow['weight_kg'] ? (float)$exerciseRow['weight_kg'] : null,
                                'pace' => $exerciseRow['pace'],
                                'notes' => $exerciseRow['notes'],
                                'order_index' => (int)$exerciseRow['order_index']
                            ];
                        }
                    }
                    // Поддержка старого формата: плановые дни без записей в training_day_exercises
                    // ОФП/СБУ: парсим description построчно (формат «как в чате») — в попапе отдельные упражнения
                    $typeLabels = [
                        'easy' => 'Легкий бег', 'long' => 'Длительный бег', 'long-run' => 'Длительный бег',
                        'tempo' => 'Темповый бег', 'interval' => 'Интервалы', 'other' => 'ОФП',
                        'sbu' => 'СБУ', 'fartlek' => 'Фартлек', 'race' => 'Соревнование',
                        'rest' => 'День отдыха', 'free' => 'Пустой день'
                    ];
                    $typeCategory = ['easy' => 'run', 'long' => 'run', 'long-run' => 'run', 'tempo' => 'run', 'interval' => 'run', 'fartlek' => 'run', 'race' => 'run', 'other' => 'ofp', 'sbu' => 'sbu', 'rest' => 'run', 'free' => 'run'];
                    foreach ($planDays as $pd) {
                        $pid = (int)$pd['id'];
                        if (!empty($planDayIdsWithExercises[$pid])) {
                            continue;
                        }
                        $type = $pd['type'] ?? '';
                        $desc = strip_tags(str_replace('<br>', "\n", $pd['description'] ?? ''));
                        $category = $typeCategory[$type] ?? 'run';
                        if (($type === 'other' || $type === 'sbu') && $desc !== '') {
                            require_once __DIR__ . '/../planrun_ai/description_parser.php';
                            $parsed = parseOfpSbuDescription($desc, $type);
                            if (count($parsed) > 0) {
                                foreach ($parsed as $idx => $ex) {
                                    $notes = $ex['notes'];
                                    if ($ex['sets'] !== null || $ex['reps'] !== null || $ex['weight_kg'] !== null || $ex['duration_sec'] !== null || $ex['distance_m'] !== null) {
                                        $parts = [];
                                        if ($ex['sets'] !== null && $ex['reps'] !== null) {
                                            $parts[] = $ex['sets'] . '×' . $ex['reps'];
                                        }
                                        if ($ex['weight_kg'] !== null) {
                                            $parts[] = $ex['weight_kg'] . ' кг';
                                        }
                                        if ($ex['duration_sec'] !== null) {
                                            $parts[] = (int)($ex['duration_sec'] / 60) . ' мин';
                                        }
                                        if ($ex['distance_m'] !== null) {
                                            $parts[] = $ex['distance_m'] . ' м';
                                        }
                                        $notes = implode(', ', $parts) . ($notes ? ' — ' . $notes : '');
                                    }
                                    $dayExercises[] = [
                                        'id' => 0,
                                        'exercise_id' => null,
                                        'plan_day_id' => $pid,
                                        'category' => $category,
                                        'name' => $ex['name'],
                                        'sets' => $ex['sets'] !== null ? (string)$ex['sets'] : null,
                                        'reps' => $ex['reps'] !== null ? (string)$ex['reps'] : null,
                                        'distance_m' => $ex['distance_m'],
                                        'duration_sec' => $ex['duration_sec'],
                                        'weight_kg' => $ex['weight_kg'],
                                        'pace' => null,
                                        'notes' => $notes,
                                        'order_index' => $idx
                                    ];
                                }
                                continue;
                            }
                        }
                        $name = $typeLabels[$type] ?? $type ?: 'Тренировка';
                        $dayExercises[] = [
                            'id' => 0,
                            'exercise_id' => null,
                            'plan_day_id' => $pid,
                            'category' => $category,
                            'name' => $name,
                            'sets' => null,
                            'reps' => null,
                            'distance_m' => null,
                            'duration_sec' => null,
                            'weight_kg' => null,
                            'pace' => null,
                            'notes' => $desc !== '' ? $desc : null,
                            'order_index' => 0
                        ];
                    }
                } catch (Exception $e) {
                    require_once __DIR__ . '/../config/Logger.php';
                    Logger::error("Error loading day exercises", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            if ($weekNumber === null) {
                $weekNumber = 1;
            }

            return [
                'success' => true,
                'date' => $date,
                'week_number' => $weekNumber,
                'day_name' => $dayName,
                'plan' => $plan,
                'planHtml' => $planHtml,
                'planType' => $planType,
                'planDayId' => $planDayId,
                'planDays' => $planDays,
                'dayExercises' => $dayExercises,
                'workouts' => $workouts
            ];
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки дня: ' . $e->getMessage(), 500, [
                'date' => $date,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Batch-загрузка деталей нескольких дней за один вызов (для префетча недели).
     * Переиспользует getDay; возвращает карту date => day. Проблемный день пропускаем.
     *
     * @param string[] $dates  список 'YYYY-MM-DD' (макс. 42)
     * @return array{days: array<string, array>}
     */
    public function getDays($dates, $userId) {
        $out = [];
        if (!is_array($dates)) {
            return ['days' => $out];
        }
        $count = 0;
        foreach ($dates as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if (isset($out[$date])) continue;
            if (++$count > 42) break;
            try {
                $out[$date] = $this->getDay($date, $userId);
            } catch (Exception $e) {
                // пропускаем проблемный день, остальные отдаём
            }
        }
        return ['days' => $out];
    }

    /**
     * Сохранить результат тренировки
     * 
     * @param array $data Данные результата
     * @param int $userId ID пользователя
     * @return array Результат сохранения
     * @throws Exception
     */
    public function saveResult($data, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateSaveResult($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            $date = $data['date'];
            $week = (int)$data['week'];
            $day = $data['day'];
            $activityTypeId = (int)$data['activity_type_id'];
            
            // Проверяем, что дата не в будущем
            $dateObj = new DateTime($date);
            $today = new DateTime();
            $today->setTime(0, 0, 0, 0);
            $dateObj->setTime(0, 0, 0, 0);
            
            if ($dateObj > $today) {
                $this->throwException('Нельзя отметить тренировку как выполненную для будущих дат', 400);
            }
            
            $isSuccessful = isset($data['is_successful']) ? ($data['is_successful'] === true ? 1 : ($data['is_successful'] === false ? 0 : null)) : null;
            $resultTime = isset($data['result_time']) && !empty($data['result_time']) ? $data['result_time'] : null;
            $distanceKm = isset($data['result_distance']) && $data['result_distance'] !== null ? (float)$data['result_distance'] : null;
            $resultPace = isset($data['result_pace']) && !empty($data['result_pace']) ? $data['result_pace'] : null;
            $durationMinutes = isset($data['duration_minutes']) && $data['duration_minutes'] !== null ? (int)$data['duration_minutes'] : null;
            $rating = isset($data['rating']) && $data['rating'] !== null ? (int)$data['rating'] : null;
            $notes = isset($data['notes']) && !empty($data['notes']) ? $data['notes'] : null;
            $avgHeartRate = isset($data['avg_heart_rate']) && $data['avg_heart_rate'] !== null ? (int)$data['avg_heart_rate'] : null;
            $maxHeartRate = isset($data['max_heart_rate']) && $data['max_heart_rate'] !== null ? (int)$data['max_heart_rate'] : null;
            $avgCadence = isset($data['avg_cadence']) && $data['avg_cadence'] !== null ? (int)$data['avg_cadence'] : null;
            $elevationGain = isset($data['elevation_gain']) && $data['elevation_gain'] !== null ? (int)$data['elevation_gain'] : null;
            $calories = isset($data['calories']) && $data['calories'] !== null ? (int)$data['calories'] : null;
            
            // Рассчитываем темп, если не передан
            if (!$resultPace && $distanceKm && $distanceKm > 0 && $resultTime) {
                $timeParts = explode(':', $resultTime);
                $totalMinutes = 0;
                if (count($timeParts) === 2) {
                    $totalMinutes = (int)$timeParts[0] + ((int)$timeParts[1] / 60);
                } elseif (count($timeParts) === 3) {
                    $totalMinutes = ((int)$timeParts[0] * 60) + (int)$timeParts[1] + ((int)$timeParts[2] / 60);
                }
                
                if ($totalMinutes > 0) {
                    $paceMinutesPerKm = $totalMinutes / $distanceKm;
                    $paceMinutes = floor($paceMinutesPerKm);
                    $paceSeconds = round(($paceMinutesPerKm - $paceMinutes) * 60);
                    
                    if ($paceSeconds >= 60) {
                        $paceSeconds = $paceSeconds - 60;
                        $paceMinutes = $paceMinutes + 1;
                    }
                    
                    $resultPace = sprintf("%d:%02d", $paceMinutes, $paceSeconds);
                }
            }
            
            // Рассчитываем duration_minutes, если не передан
            if (!$durationMinutes && $resultTime) {
                $timeParts = explode(':', $resultTime);
                if (count($timeParts) === 2) {
                    $durationMinutes = (int)$timeParts[0] + round((int)$timeParts[1] / 60);
                } elseif (count($timeParts) === 3) {
                    $durationMinutes = ((int)$timeParts[0] * 60) + (int)$timeParts[1] + round((int)$timeParts[2] / 60);
                }
            }
            
            // Проверяем существование записи
            $checkStmt = $this->db->prepare("SELECT id FROM workout_log WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ? LIMIT 1");
            $checkStmt->bind_param("isis", $userId, $date, $week, $day);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $existing = $result->fetch_assoc();
            $checkStmt->close();
            $workoutLogId = 0;
            $shareQueueJobs = 0;
            
            if ($existing) {
                // Обновляем существующую запись
                $updateStmt = $this->db->prepare("UPDATE workout_log SET activity_type_id = ?, is_successful = ?, result_time = ?, distance_km = ?, pace = ?, duration_minutes = ?, rating = ?, notes = ?, avg_heart_rate = ?, max_heart_rate = ?, avg_cadence = ?, elevation_gain = ?, calories = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->bind_param("iisdssiisiiiii", $activityTypeId, $isSuccessful, $resultTime, $distanceKm, $resultPace, $durationMinutes, $rating, $notes, $avgHeartRate, $maxHeartRate, $avgCadence, $elevationGain, $calories, $existing['id']);
                $updateStmt->execute();
                if ($updateStmt->error) {
                    $updateStmt->close();
                    $this->throwException('Ошибка БД: ' . $updateStmt->error, 500);
                }
                $updateStmt->close();
                $workoutLogId = (int) $existing['id'];
            } else {
                // Создаем новую запись
                $insertStmt = $this->db->prepare("INSERT INTO workout_log (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, result_time, distance_km, pace, duration_minutes, rating, notes, avg_heart_rate, max_heart_rate, avg_cadence, elevation_gain, calories) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->bind_param("isisisdssiisiiiii", $userId, $date, $week, $day, $activityTypeId, $isSuccessful, $resultTime, $distanceKm, $resultPace, $durationMinutes, $rating, $notes, $avgHeartRate, $maxHeartRate, $avgCadence, $elevationGain, $calories);
                $insertStmt->execute();
                if ($insertStmt->error) {
                    $insertStmt->close();
                    $this->throwException('Ошибка БД: ' . $insertStmt->error, 500);
                }
                $workoutLogId = (int) $this->db->insert_id;
                $insertStmt->close();
            }
            
            // Инвалидируем кеш
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            require_once __DIR__ . '/../config/Logger.php';
            Logger::debug("Training plan cache invalidated after saving result", ['user_id' => $userId]);

            $shareQueueJobs += $this->queueWorkoutShareCards((int) $userId, $workoutLogId, WorkoutShareCardCacheService::KIND_MANUAL);
            if ($shareQueueJobs > 0) {
                $this->launchWorkoutShareWorkerAsync();
            }

            $this->handlePostWorkoutCoachFlow((int) $userId, $date, 'workout_log', $workoutLogId);
            
            return ['success' => true, 'workout_log_id' => $workoutLogId];
        } catch (Exception $e) {
            $this->throwException('Ошибка сохранения результата: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Импорт тренировок из внешних источников (Huawei, Garmin, Strava, GPX и др.)
     * Дедупликация:
     * 1) По (user_id, external_id, source) — обновление при повторной синхронизации того же источника
     * 2) По (user_id, start_time, source) — для GPX без external_id
     * 3) Кросс-источник: (user_id, start_time ±2 мин) — если тренировка уже есть из другого источника, пропускаем
     *
     * @param int $userId ID пользователя
     * @param array $workouts Массив тренировок в нормализованном формате
     * @param string $source Источник: 'huawei', 'garmin', 'strava', 'polar', 'coros', 'gpx'
     * @return array ['imported' => int, 'skipped' => int]
     */
    public function importWorkouts($userId, array $workouts, $source) {
        $imported = 0;
        $skipped = 0;
        $shareQueueJobs = 0;
        $mirrorQueued = false;
        $importedDetails = [];
        $insertStmt = $this->db->prepare("
            INSERT INTO workouts (user_id, session_id, source, external_id, activity_type, start_time, end_time, duration_minutes, duration_seconds, distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain, detected_type)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $checkByExternalStmt = $this->db->prepare("SELECT id, avg_heart_rate, elevation_gain FROM workouts WHERE user_id = ? AND external_id = ? AND source = ? LIMIT 1");
        $checkByTimeStmt = $this->db->prepare("SELECT id, avg_heart_rate, elevation_gain FROM workouts WHERE user_id = ? AND start_time = ? AND source = ? AND (external_id IS NULL OR external_id = '') LIMIT 1");
        // Cross-source: ±14 часов покрывает timezone mismatches (FIT=UTC, Strava=local)
        $checkCrossSourceStmt = $this->db->prepare("
            SELECT id, distance_km, duration_seconds, duration_minutes, start_time, avg_heart_rate FROM workouts
            WHERE user_id = ? AND start_time BETWEEN DATE_SUB(?, INTERVAL 14 HOUR) AND DATE_ADD(?, INTERVAL 14 HOUR) LIMIT 10
        ");
        $updateStmt = $this->db->prepare("
            UPDATE workouts SET activity_type = ?, end_time = ?, duration_minutes = ?, duration_seconds = ?, distance_km = ?, avg_pace = ?, avg_heart_rate = ?, max_heart_rate = ?, elevation_gain = ?, detected_type = ?
            WHERE id = ?
        ");

        $classifyPaces = null;
        $classifyMaxHr = 0;
        try {
            require_once __DIR__ . '/TrainingStateBuilder.php';
            require_once __DIR__ . '/../planrun_ai/prompt_builder.php';
            require_once __DIR__ . '/WorkoutClassifier.php';
            $state = (new TrainingStateBuilder($this->db))->buildForUserId($userId);
            $vdot = !empty($state['vdot']) ? (float)$state['vdot'] : null;
            if ($vdot) {
                $classifyPaces = getTrainingPaces($vdot);
            }
            $byRow = $this->db->prepare("SELECT birth_year FROM users WHERE id = ? LIMIT 1");
            if ($byRow) {
                $byRow->bind_param("i", $userId);
                $byRow->execute();
                $byUser = $byRow->get_result()->fetch_assoc();
                $byRow->close();
                $classifyMaxHr = WorkoutClassifier::maxHrFromBirthYear(isset($byUser['birth_year']) ? (int)$byUser['birth_year'] : null);
            }
        } catch (\Throwable $e) {
            $classifyPaces = null;
        }

        foreach ($workouts as $w) {
            $activityType = $w['activity_type'] ?? 'running';
            $startTime = $w['start_time'] ?? null;
            $endTime = $w['end_time'] ?? $startTime;
            $durationMinutes = isset($w['duration_minutes']) ? (int)$w['duration_minutes'] : null;
            $durationSeconds = isset($w['duration_seconds']) ? (int)$w['duration_seconds'] : null;
            $distanceKm = isset($w['distance_km']) ? (float)$w['distance_km'] : null;
            $avgPace = $w['avg_pace'] ?? null;
            $avgHeartRate = isset($w['avg_heart_rate']) ? (int)$w['avg_heart_rate'] : null;
            $maxHeartRate = isset($w['max_heart_rate']) ? (int)$w['max_heart_rate'] : null;
            $elevationGain = isset($w['elevation_gain']) ? (int)$w['elevation_gain'] : null;
            $externalId = !empty($w['external_id']) ? (string)$w['external_id'] : null;
            if (!$startTime) {
                $skipped++;
                continue;
            }
            $detectedType = WorkoutClassifier::classify([
                'activity_type' => $activityType,
                'distance_km' => $distanceKm,
                'duration_seconds' => $durationSeconds,
                'duration_minutes' => $durationMinutes,
                'avg_heart_rate' => $avgHeartRate,
                'max_hr' => $classifyMaxHr,
                'paces' => $classifyPaces,
                'laps' => $w['laps'] ?? null,
            ]);
            $existing = null;
            if ($externalId) {
                $checkByExternalStmt->bind_param("iss", $userId, $externalId, $source);
                $checkByExternalStmt->execute();
                $existing = $checkByExternalStmt->get_result()->fetch_assoc();
            } else {
                $checkByTimeStmt->bind_param("iss", $userId, $startTime, $source);
                $checkByTimeStmt->execute();
                $existing = $checkByTimeStmt->get_result()->fetch_assoc();
            }
            if ($existing) {
                $existingId = (int)$existing['id'];
                $updateStmt->bind_param("ssiidsiiisi",
                    $activityType, $endTime, $durationMinutes, $durationSeconds, $distanceKm, $avgPace,
                    $avgHeartRate, $maxHeartRate, $elevationGain, $detectedType,
                    $existingId
                );
                if ($updateStmt->execute()) {
                    $imported++;
                    $this->saveWorkoutTimeline((int)$existing['id'], $w['timeline'] ?? null);
                    $this->saveWorkoutLaps((int)$existing['id'], $w['laps'] ?? null);
                    $shareQueueJobs += $this->queueWorkoutShareCards((int) $userId, (int) $existing['id'], WorkoutShareCardCacheService::KIND_WORKOUT);
                    $workoutDateStr = (string) date('Y-m-d', strtotime($endTime ?: $startTime));
                    $this->handlePostWorkoutCoachFlow((int) $userId, $workoutDateStr, 'workout', (int) $existing['id']);
                    $this->maybeUpdateLastRaceFromImport((int) $userId, $workoutDateStr, $distanceKm, $durationSeconds, $durationMinutes, $avgPace);
                    $importedDetails[] = ['type' => $activityType, 'distance' => $distanceKm, 'duration' => $durationSeconds, 'minutes' => $durationMinutes, 'pace' => $avgPace, 'date' => $workoutDateStr, 'new' => false];
                } else {
                    $skipped++;
                }
                continue;
            }
            $checkCrossSourceStmt->bind_param("iss", $userId, $startTime, $startTime);
            $checkCrossSourceStmt->execute();
            $crossResult = $checkCrossSourceStmt->get_result();
            $crossExisting = false;
            $newStartTs = strtotime($startTime);
            while ($crossRow = $crossResult->fetch_assoc()) {
                $existingTs = strtotime($crossRow['start_time']);
                $timeDiffSec = abs($newStartTs - $existingTs);
                $isCloseTime = ($timeDiffSec <= 120); // ±2 минуты
                $mod = $timeDiffSec % 3600;
                    $isTimezoneOffset = !$isCloseTime && ($mod < 300 || $mod > 3300); // кратно часу ±5 мин

                if ($isCloseTime) {
                    // Близкое время — однозначный дубликат
                    $crossExisting = true;
                    break;
                } elseif ($isTimezoneOffset) {
                    // Timezone mismatch — проверяем дистанцию И длительность
                    $existDist = isset($crossRow['distance_km']) ? (float)$crossRow['distance_km'] : 0;
                    $existDurSec = isset($crossRow['duration_seconds']) ? (int)$crossRow['duration_seconds']
                        : (isset($crossRow['duration_minutes']) ? (int)$crossRow['duration_minutes'] * 60 : 0);

                    $distMatch = ($distanceKm > 0 && $existDist > 0)
                        ? abs($distanceKm - $existDist) <= max(0.15, $distanceKm * 0.03)
                        : false;
                    $durMatch = ($durationSeconds > 0 && $existDurSec > 0)
                        ? abs($durationSeconds - $existDurSec) <= 180
                        : false;

                    // Пульс как доп. подтверждение (если оба имеют данные)
                    $existHr = isset($crossRow['avg_heart_rate']) ? (int)$crossRow['avg_heart_rate'] : 0;
                    $hrMatch = true; // по умолчанию не блокирует (если нет данных)
                    if ($avgHeartRate > 0 && $existHr > 0) {
                        $hrMatch = abs($avgHeartRate - $existHr) <= 10; // ±10 bpm
                    }

                    // Требуем: (дистанция + длительность) + пульс если доступен
                    if ($distMatch && $durMatch && $hrMatch) {
                        $crossExisting = true;
                        break;
                    }
                }
            }
            if ($crossExisting) {
                $skipped++;
                continue;
            }
            $insertStmt->bind_param("isssssiidsiiis",
                $userId, $source, $externalId,
                $activityType, $startTime, $endTime, $durationMinutes, $durationSeconds, $distanceKm, $avgPace,
                $avgHeartRate, $maxHeartRate, $elevationGain, $detectedType
            );
            if ($insertStmt->execute()) {
                $workoutId = (int)$this->db->insert_id;
                $imported++;
                $this->saveWorkoutTimeline($workoutId, $w['timeline'] ?? null);
                $this->saveWorkoutLaps($workoutId, $w['laps'] ?? null);
                if ($this->maybeEnqueueSuuntoMirror((int)$userId, $workoutId, (string)$source)) { $mirrorQueued = true; }
                $shareQueueJobs += $this->queueWorkoutShareCards((int) $userId, $workoutId, WorkoutShareCardCacheService::KIND_WORKOUT);
                $workoutDateStr = (string) date('Y-m-d', strtotime($endTime ?: $startTime));
                $this->handlePostWorkoutCoachFlow((int) $userId, $workoutDateStr, 'workout', $workoutId);
                $this->maybeUpdateLastRaceFromImport((int) $userId, $workoutDateStr, $distanceKm, $durationSeconds, $durationMinutes, $avgPace);
                $importedDetails[] = ['type' => $activityType, 'distance' => $distanceKm, 'duration' => $durationSeconds, 'minutes' => $durationMinutes, 'pace' => $avgPace, 'date' => $workoutDateStr, 'new' => true];
            } else {
                $skipped++;
            }
        }
        $insertStmt->close();
        $checkByExternalStmt->close();
        $checkByTimeStmt->close();
        $checkCrossSourceStmt->close();
        $updateStmt->close();
        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");

        // VDOT recompute уже идёт через TrainingStateBuilder.best_result —
        // отдельный вызов maybeUpdateVdotFromWorkouts удалён в v3.14 как no-op.

        if ($shareQueueJobs > 0) {
            $this->launchWorkoutShareWorkerAsync();
        }

        // Зеркалирование в Suunto: сразу пинаем фоновый воркер (без ожидания cron)
        if ($mirrorQueued) {
            $this->launchSuuntoUploadWorkerAsync();
        }

        // In-app уведомления: загрузка из Strava + личные рекорды. Никогда не ломаем импорт.
        try {
            $this->emitImportNotifications((int) $userId, (string) $source, $importedDetails);
        } catch (\Throwable $e) {
            // ignore
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** Бакеты дистанций для детекта личных рекордов (синхронно со StatsService). */
    private const PR_BUCKETS = [
        ['min' => 4.5,  'max' => 5.5,  'label' => '5 км'],
        ['min' => 8.5,  'max' => 11.5, 'label' => '10 км'],
        ['min' => 19.5, 'max' => 22.5, 'label' => 'полумарафон'],
        ['min' => 40.0, 'max' => 44.0, 'label' => 'марафон'],
    ];

    private function formatSeconds(int $sec): string {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Создаёт in-app уведомления по итогам импорта:
     *  - workout_uploaded (если source=strava и что-то залилось)
     *  - personal_record (если новый забег побил исторический PR в своём бакете)
     */
    private function emitImportNotifications(int $userId, string $source, array $details): void {
        if (empty($details)) return;
        require_once __DIR__ . '/PlanNotificationService.php';
        $svc = new PlanNotificationService($this->db);

        $runs = array_values(array_filter($details, function ($d) {
            $t = strtolower((string) ($d['type'] ?? ''));
            return ($t === 'running' || $t === 'run' || $t === '') && (float) ($d['distance'] ?? 0) > 0;
        }));

        // 1) Strava upload — одно сводное уведомление про последнюю тренировку.
        if (strtolower($source) === 'strava' && !empty($runs)) {
            $last = $runs[count($runs) - 1];
            $dist = round((float) $last['distance'], 1);
            $pace = !empty($last['pace']) ? " · {$last['pace']} /км" : '';
            $svc->notify($userId, 'workout_uploaded', 'Загружена тренировка', [
                'title' => 'Загружена тренировка',
                'body' => "Strava · {$dist} км{$pace}",
                'date' => $last['date'] ?? null,
                'link' => !empty($last['date']) ? '/calendar?date=' . rawurlencode($last['date']) : '/calendar',
                'action_label' => 'Открыть →',
            ]);
        }

        // 2) Личные рекорды — по лучшему новому забегу в каждом бакете.
        $bestByBucket = [];
        foreach ($runs as $d) {
            if (empty($d['new'])) continue; // только новые записи
            $dur = (int) ($d['duration'] ?? 0);
            if ($dur <= 0 && !empty($d['minutes'])) $dur = (int) $d['minutes'] * 60;
            if ($dur <= 0) continue;
            $km = (float) $d['distance'];
            foreach (self::PR_BUCKETS as $i => $b) {
                if ($km >= $b['min'] && $km <= $b['max']) {
                    if (!isset($bestByBucket[$i]) || $dur < $bestByBucket[$i]['dur']) {
                        $bestByBucket[$i] = ['dur' => $dur, 'date' => $d['date'] ?? null];
                    }
                    break;
                }
            }
        }
        foreach ($bestByBucket as $i => $cand) {
            $b = self::PR_BUCKETS[$i];
            // Прошлый лучший результат в этом бакете (без учёта сегодняшнего дня).
            $stmt = $this->db->prepare(
                "SELECT MIN(duration_seconds) AS best FROM workouts
                 WHERE user_id = ? AND duration_seconds > 0
                   AND distance_km BETWEEN ? AND ?
                   AND DATE(start_time) < ?"
            );
            $stmt->bind_param('iddss', $userId, $b['min'], $b['max'], $cand['date']);
            $stmt->execute();
            $prev = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $prevBest = isset($prev['best']) ? (int) $prev['best'] : 0;
            if ($prevBest > 0 && $cand['dur'] < $prevBest) {
                $newStr = $this->formatSeconds($cand['dur']);
                $oldStr = $this->formatSeconds($prevBest);
                $svc->notify($userId, 'personal_record', "Личный рекорд на {$b['label']}", [
                    'title' => "Личный рекорд на {$b['label']}",
                    'body' => "Новое время: {$newStr}. Прошлое — {$oldStr}.",
                    'date' => $cand['date'],
                    'link' => !empty($cand['date']) ? '/calendar?date=' . rawurlencode($cand['date']) : '/calendar',
                    'action_label' => 'Посмотреть →',
                ]);
            }
        }
    }
    
    /**
     * Сохранить прогресс тренировки (старая функция save)
     * 
     * @param array $data Данные прогресса
     * @param int $userId ID пользователя
     * @return array Результат сохранения
     * @throws Exception
     */
    public function saveProgress($data, $userId) {
        try {
            $date = $data['date'];
            $week = (int)$data['week'];
            $day = $data['day'];
            $completed = $data['completed'] ? 1 : 0;
            $completedAt = $completed ? date('Y-m-d H:i:s') : null;
            $resultTime = isset($data['result_time']) ? $data['result_time'] : null;
            $resultDistance = isset($data['result_distance']) ? (float)$data['result_distance'] : null;
            $resultPace = isset($data['result_pace']) ? $data['result_pace'] : null;
            $notes = isset($data['notes']) ? $data['notes'] : null;
            
            // Проверяем существование записи
            $checkStmt = $this->db->prepare("SELECT id FROM training_progress WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?");
            $checkStmt->bind_param("isis", $userId, $date, $week, $day);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $checkStmt->close();
            
            if ($result && $result->num_rows > 0) {
                // Обновляем существующую запись
                if ($completedAt) {
                    $updateStmt = $this->db->prepare("UPDATE training_progress SET completed = ?, completed_at = ?, result_time = ?, result_distance = ?, result_pace = ?, notes = ? WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?");
                    $updateStmt->bind_param("issdssisiss", $completed, $completedAt, $resultTime, $resultDistance, $resultPace, $notes, $userId, $date, $week, $day);
                } else {
                    $updateStmt = $this->db->prepare("UPDATE training_progress SET completed = ?, completed_at = NULL, result_time = ?, result_distance = ?, result_pace = ?, notes = ? WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?");
                    $updateStmt->bind_param("isdssissis", $completed, $resultTime, $resultDistance, $resultPace, $notes, $userId, $date, $week, $day);
                }
                $updateStmt->execute();
                if ($updateStmt->error) {
                    $updateStmt->close();
                    $this->throwException('Ошибка БД: ' . $updateStmt->error, 500);
                }
                $updateStmt->close();
            } else {
                // Создаем новую запись
                if ($completedAt) {
                    $insertStmt = $this->db->prepare("INSERT INTO training_progress (user_id, training_date, week_number, day_name, completed, completed_at, result_time, result_distance, result_pace, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("isisisdss", $userId, $date, $week, $day, $completed, $completedAt, $resultTime, $resultDistance, $resultPace, $notes);
                } else {
                    $insertStmt = $this->db->prepare("INSERT INTO training_progress (user_id, training_date, week_number, day_name, completed, result_time, result_distance, result_pace, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("isisdsdss", $userId, $date, $week, $day, $completed, $resultTime, $resultDistance, $resultPace, $notes);
                }
                $insertStmt->execute();
                if ($insertStmt->error) {
                    $insertStmt->close();
                    $this->throwException('Ошибка БД: ' . $insertStmt->error, 500);
                }
                $insertStmt->close();
            }
            
            // Инвалидируем кеш
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            require_once __DIR__ . '/../config/Logger.php';
            Logger::debug("Training plan cache invalidated after saving progress", ['user_id' => $userId]);

            // VDOT recompute идёт через TrainingStateBuilder.best_result.
            // Вызов maybeUpdateVdotFromWorkouts удалён в v3.14 как no-op.

            return ['success' => true];
        } catch (Exception $e) {
            $this->throwException('Ошибка сохранения прогресса: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сбросить прогресс
     * 
     * @param int $userId ID пользователя
     * @return array Результат сброса
     * @throws Exception
     */
    public function resetProgress($userId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM workout_log WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            if ($this->db->error) {
                $stmt->close();
                $this->throwException('Ошибка БД: ' . $this->db->error, 500);
            }
            $stmt->close();
            
            // Инвалидируем кеш
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            require_once __DIR__ . '/../config/Logger.php';
            Logger::debug("Training plan cache invalidated after reset", ['user_id' => $userId]);
            
            return ['success' => true];
        } catch (Exception $e) {
            $this->throwException('Ошибка сброса прогресса: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Удалить тренировку
     * 
     * @param int $workoutId ID тренировки
     * @param bool $isManual Ручная тренировка или автоматическая
     * @param int $userId ID пользователя
     * @return array Результат удаления
     * @throws Exception
     */
    public function deleteWorkout($workoutId, $isManual, $userId) {
        try {
            if (!$workoutId || $workoutId <= 0) {
                $this->throwException('Не указан ID тренировки', 400);
            }
            
            if ($isManual) {
                // Удаление вручную добавленной тренировки из workout_log
                $checkStmt = $this->db->prepare("SELECT id FROM workout_log WHERE id = ? AND user_id = ?");
                $checkStmt->bind_param("ii", $workoutId, $userId);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $checkStmt->close();
                
                if ($checkResult->num_rows === 0) {
                    $this->throwException('Тренировка не найдена или нет доступа', 404);
                }
                
                $deleteStmt = $this->db->prepare("DELETE FROM workout_log WHERE id = ? AND user_id = ?");
                $deleteStmt->bind_param("ii", $workoutId, $userId);
                $deleteStmt->execute();
                $deleteStmt->close();

                $this->deleteWorkoutShareCards((int) $userId, (int) $workoutId, WorkoutShareCardCacheService::KIND_MANUAL);
                
                return ['success' => true, 'message' => 'Запись о тренировке удалена'];
            } else {
                // Удаление автоматически загруженной тренировки из workouts
                $checkStmt = $this->db->prepare("SELECT id FROM workouts WHERE id = ? AND user_id = ?");
                $checkStmt->bind_param("ii", $workoutId, $userId);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult->num_rows === 0) {
                    $checkStmt->close();
                    $this->throwException('Тренировка не найдена или нет доступа', 404);
                }
                $checkStmt->close();
                
                // Начинаем транзакцию
                $this->db->begin_transaction();
                
                try {
                    // Удаляем все trackpoints
                    $deleteTimelineStmt = $this->db->prepare("DELETE FROM workout_timeline WHERE workout_id = ?");
                    if ($deleteTimelineStmt) {
                        $deleteTimelineStmt->bind_param("i", $workoutId);
                        $deleteTimelineStmt->execute();
                        $deleteTimelineStmt->close();
                    }

                    if ($this->tableExists('workout_laps')) {
                        $deleteLapsStmt = $this->db->prepare("DELETE FROM workout_laps WHERE workout_id = ?");
                        if ($deleteLapsStmt) {
                            $deleteLapsStmt->bind_param("i", $workoutId);
                            $deleteLapsStmt->execute();
                            $deleteLapsStmt->close();
                        }
                    }
                    
                    // Удаляем основную запись
                    $deleteWorkoutStmt = $this->db->prepare("DELETE FROM workouts WHERE id = ? AND user_id = ?");
                    $deleteWorkoutStmt->bind_param("ii", $workoutId, $userId);
                    $deleteWorkoutStmt->execute();
                    $deleteWorkoutStmt->close();
                    
                    $this->db->commit();

                    $this->deleteWorkoutShareCards((int) $userId, (int) $workoutId, WorkoutShareCardCacheService::KIND_WORKOUT);
                    
                    return ['success' => true, 'message' => 'Тренировка и все связанные данные удалены'];
                } catch (Exception $e) {
                    $this->db->rollback();
                    throw $e;
                }
            }
        } catch (Exception $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error("Error deleting workout", [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);
            $this->throwException('Ошибка удаления тренировки: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранить timeline точки в workout_timeline
     *
     * @param int $workoutId ID тренировки
     * @param array|null $timeline Массив точек [['timestamp','heart_rate','pace','altitude','distance','cadence'], ...]
     */
    private function saveWorkoutTimeline(int $workoutId, ?array $timeline): void {
        if (!$workoutId || empty($timeline)) {
            return;
        }
        $deleteStmt = $this->db->prepare("DELETE FROM workout_timeline WHERE workout_id = ?");
        $deleteStmt->bind_param("i", $workoutId);
        $deleteStmt->execute();
        $deleteStmt->close();
        $count = count($timeline);
        if ($count > self::TIMELINE_MAX_POINTS) {
            $step = max(1, (int)floor($count / self::TIMELINE_MAX_POINTS));
            $sampled = [];
            for ($i = 0; $i < $count; $i += $step) {
                $sampled[] = $timeline[$i];
            }
            if (end($sampled) !== end($timeline)) {
                $sampled[] = end($timeline);
            }
            $timeline = $sampled;
        }
        $batchSize = 500;
        $batches = array_chunk($timeline, $batchSize);
        foreach ($batches as $batch) {
            $values = [];
            foreach ($batch as $p) {
                $ts = isset($p['timestamp']) ? "'" . $this->db->real_escape_string($p['timestamp']) . "'" : 'NULL';
                $hr = isset($p['heart_rate']) ? (int)$p['heart_rate'] : 'NULL';
                $pace = isset($p['pace']) && $p['pace'] !== null ? "'" . $this->db->real_escape_string($p['pace']) . "'" : 'NULL';
                $alt = isset($p['altitude']) && $p['altitude'] !== null ? (float)$p['altitude'] : 'NULL';
                $dist = isset($p['distance']) && $p['distance'] !== null ? (float)$p['distance'] : 'NULL';
                $cad = isset($p['cadence']) ? (int)$p['cadence'] : 'NULL';
                $lat = isset($p['latitude']) && $p['latitude'] !== null ? (float)$p['latitude'] : 'NULL';
                $lng = isset($p['longitude']) && $p['longitude'] !== null ? (float)$p['longitude'] : 'NULL';
                $values[] = "($workoutId, $ts, $hr, $pace, $alt, $dist, $cad, $lat, $lng)";
            }
            $sql = "INSERT INTO workout_timeline (workout_id, timestamp, heart_rate, pace, altitude, distance, cadence, latitude, longitude) VALUES " . implode(',', $values);
            $this->db->query($sql);
        }
    }

    /**
     * Сохранить круги/отрезки тренировки в workout_laps.
     *
     * @param int $workoutId ID тренировки
     * @param array|null $laps Массив кругов [['lap_index', 'name', 'distance_km', ...], ...]
     */
    /**
     * Ставит тренировку в очередь на заливку в Suunto, если включено зеркалирование.
     * Гейт: юзер в SUUNTO_MIRROR_USERS + users.suunto_mirror_enabled=1 + Suunto подключён.
     * Источник 'suunto' не зеркалим (иначе петля). Идемпотентно (UNIQUE user_id+workout_id).
     */
    private function maybeEnqueueSuuntoMirror(int $userId, int $workoutId, string $source): bool {
        if ($source === 'suunto' || $userId <= 0 || $workoutId <= 0) {
            return false;
        }
        $allow = trim((string)(function_exists('env') ? env('SUUNTO_MIRROR_USERS', '') : ''));
        if ($allow === '') {
            return false; // фича выключена глобально
        }
        $users = array_values(array_filter(array_map('trim', explode(',', $allow)), fn($u) => $u !== ''));
        if (empty($users) || !$this->tableExists('suunto_upload_queue')) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT u.username, u.suunto_mirror_enabled,
                    (SELECT 1 FROM integration_tokens it WHERE it.user_id = u.id AND it.provider = 'suunto' LIMIT 1) AS has_suunto
             FROM users u WHERE u.id = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int)($row['suunto_mirror_enabled'] ?? 0) !== 1 || empty($row['has_suunto'])) {
            return false;
        }
        if (!in_array((string)$row['username'], $users, true)) {
            return false;
        }
        $enqueued = false;
        $q = $this->db->prepare("INSERT IGNORE INTO suunto_upload_queue (user_id, workout_id, status) VALUES (?, ?, 'pending')");
        if ($q) {
            $q->bind_param('ii', $userId, $workoutId);
            $q->execute();
            $enqueued = $q->affected_rows > 0;
            $q->close();
        }
        return $enqueued;
    }

    /**
     * Запускает фоновый (detached) воркер заливки в Suunto — чтобы тренировка уезжала
     * сразу после импорта, без ожидания cron. Не блокирует запрос. Из CLI не запускаем.
     */
    private function launchSuuntoUploadWorkerAsync(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $scriptPath = dirname(__DIR__) . '/scripts/suunto_upload_worker.php';
        if (!is_file($scriptPath)) {
            return;
        }
        $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        if (stripos($phpBinary, 'fpm') !== false) {
            $phpBinary = 'php'; // под FPM PHP_BINARY = php-fpm, нужен CLI
        }
        $override = (string)(function_exists('env') ? env('SUUNTO_PHP_BIN', '') : '');
        if ($override !== '') {
            $phpBinary = $override;
        }
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1 &';
        try {
            if (function_exists('popen') && function_exists('pclose')) {
                $process = @popen($command, 'r');
                if (is_resource($process)) {
                    @pclose($process);
                    return;
                }
            }
            if (function_exists('exec')) {
                @exec($command);
            }
        } catch (\Throwable $e) {
            // best-effort: упадёт — подберёт cron/следующий импорт
        }
    }

    private function saveWorkoutLaps(int $workoutId, ?array $laps): void {
        if (!$workoutId || empty($laps) || !$this->tableExists('workout_laps')) {
            return;
        }
        $deleteStmt = $this->db->prepare("DELETE FROM workout_laps WHERE workout_id = ?");
        if (!$deleteStmt) {
            return;
        }
        $deleteStmt->bind_param("i", $workoutId);
        $deleteStmt->execute();
        $deleteStmt->close();

        $values = [];
        foreach ($laps as $index => $lap) {
            if (!is_array($lap)) {
                continue;
            }
            $lapIndex = isset($lap['lap_index']) ? max(1, (int)$lap['lap_index']) : ($index + 1);
            $lapName = isset($lap['name']) && trim((string)$lap['name']) !== ''
                ? "'" . $this->db->real_escape_string(trim((string)$lap['name'])) . "'"
                : 'NULL';
            $startTime = isset($lap['start_time']) && trim((string)$lap['start_time']) !== ''
                ? "'" . $this->db->real_escape_string(trim((string)$lap['start_time'])) . "'"
                : 'NULL';
            // FitParser и синтетические сплиты дают duration_seconds — используем как фолбэк,
            // иначе moving/elapsed_seconds остаются NULL и анализатор структуры отбрасывает все круги.
            $durationSeconds = isset($lap['duration_seconds']) ? max(0, (int)$lap['duration_seconds']) : null;
            $elapsedSeconds = isset($lap['elapsed_seconds']) ? max(0, (int)$lap['elapsed_seconds']) : ($durationSeconds ?? 'NULL');
            $movingSeconds = isset($lap['moving_seconds']) ? max(0, (int)$lap['moving_seconds']) : ($durationSeconds ?? 'NULL');
            $distanceKm = isset($lap['distance_km']) && $lap['distance_km'] !== null ? (float)$lap['distance_km'] : 'NULL';
            $averageSpeed = isset($lap['average_speed']) && $lap['average_speed'] !== null ? (float)$lap['average_speed'] : 'NULL';
            $maxSpeed = isset($lap['max_speed']) && $lap['max_speed'] !== null ? (float)$lap['max_speed'] : 'NULL';
            $avgHeartRate = isset($lap['avg_heart_rate']) && $lap['avg_heart_rate'] !== null ? (int)$lap['avg_heart_rate'] : 'NULL';
            $maxHeartRate = isset($lap['max_heart_rate']) && $lap['max_heart_rate'] !== null ? (int)$lap['max_heart_rate'] : 'NULL';
            $elevationGain = isset($lap['elevation_gain']) && $lap['elevation_gain'] !== null ? (float)$lap['elevation_gain'] : 'NULL';
            $cadence = isset($lap['cadence']) && $lap['cadence'] !== null ? (int)$lap['cadence'] : 'NULL';
            $startIndex = isset($lap['start_index']) && $lap['start_index'] !== null ? max(0, (int)$lap['start_index']) : 'NULL';
            $endIndex = isset($lap['end_index']) && $lap['end_index'] !== null ? max(0, (int)$lap['end_index']) : 'NULL';
            $payloadJson = json_encode($lap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $payloadValue = $payloadJson !== false
                ? "'" . $this->db->real_escape_string($payloadJson) . "'"
                : 'NULL';

            $values[] = sprintf(
                '(%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $workoutId,
                $lapIndex,
                $lapName,
                $startTime,
                $elapsedSeconds,
                $movingSeconds,
                $distanceKm,
                $averageSpeed,
                $maxSpeed,
                $avgHeartRate,
                $maxHeartRate,
                $elevationGain,
                $cadence,
                $startIndex,
                $endIndex,
                $payloadValue
            );
        }

        if (empty($values)) {
            return;
        }

        $sql = "INSERT INTO workout_laps (
                workout_id, lap_index, lap_name, start_time, elapsed_seconds, moving_seconds,
                distance_km, average_speed, max_speed, avg_heart_rate, max_heart_rate,
                elevation_gain, cadence, start_index, end_index, payload_json
            ) VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                lap_name = VALUES(lap_name),
                start_time = VALUES(start_time),
                elapsed_seconds = VALUES(elapsed_seconds),
                moving_seconds = VALUES(moving_seconds),
                distance_km = VALUES(distance_km),
                average_speed = VALUES(average_speed),
                max_speed = VALUES(max_speed),
                avg_heart_rate = VALUES(avg_heart_rate),
                max_heart_rate = VALUES(max_heart_rate),
                elevation_gain = VALUES(elevation_gain),
                cadence = VALUES(cadence),
                start_index = VALUES(start_index),
                end_index = VALUES(end_index),
                payload_json = VALUES(payload_json)";
        $this->db->query($sql);
    }

    /**
     * Получить timeline данные и круги тренировки.
     * 
     * @param int $workoutId ID тренировки
     * @param int $userId ID пользователя
     * @return array{timeline: array<int, array<string, mixed>>, laps: array<int, array<string, mixed>>}
     * @throws Exception
     */
    private const TIMELINE_MAX_POINTS = 500;

    public function getWorkoutTimeline($workoutId, $userId) {
        try {
            if (!$workoutId || $workoutId <= 0) {
                $this->throwException('Не указан ID тренировки', 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT id FROM workouts WHERE id = ? AND user_id = ? LIMIT 1");
            $checkStmt->bind_param("ii", $workoutId, $userId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $workout = $result->fetch_assoc();
            $checkStmt->close();
            
            if (!$workout) {
                $this->throwException('Тренировка не найдена или нет доступа', 404);
            }

            $timeline = [];
            $countStmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM workout_timeline WHERE workout_id = ?");
            if ($countStmt) {
                $countStmt->bind_param("i", $workoutId);
                $countStmt->execute();
                $totalRows = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
                $countStmt->close();

                if ($totalRows > 0) {
                    $step = max(1, (int)floor($totalRows / self::TIMELINE_MAX_POINTS));

                    if ($step <= 1) {
                        $timelineStmt = $this->db->prepare("
                            SELECT timestamp, heart_rate, pace, cadence, altitude, distance, latitude, longitude
                            FROM workout_timeline
                            WHERE workout_id = ?
                            ORDER BY timestamp ASC
                        ");
                        if ($timelineStmt) {
                            $timelineStmt->bind_param("i", $workoutId);
                        }
                    } else {
                        $timelineStmt = $this->db->prepare("
                            SELECT timestamp, heart_rate, pace, cadence, altitude, distance, latitude, longitude
                            FROM (
                                SELECT *, @rn := @rn + 1 AS row_num
                                FROM workout_timeline, (SELECT @rn := -1) vars
                                WHERE workout_id = ?
                                ORDER BY timestamp ASC
                            ) numbered
                            WHERE row_num % ? = 0 OR row_num = 0
                        ");
                        if ($timelineStmt) {
                            $timelineStmt->bind_param("ii", $workoutId, $step);
                        }
                    }

                    if (!empty($timelineStmt)) {
                        $timelineStmt->execute();
                        $timelineResult = $timelineStmt->get_result();

                        while ($row = $timelineResult->fetch_assoc()) {
                            $timeline[] = [
                                'timestamp' => $row['timestamp'],
                                'heart_rate' => $row['heart_rate'] !== null ? (int)$row['heart_rate'] : null,
                                'pace' => $row['pace'],
                                'cadence' => $row['cadence'] !== null ? (int)$row['cadence'] : null,
                                'altitude' => $row['altitude'] !== null ? (float)$row['altitude'] : null,
                                'distance' => $row['distance'] !== null ? (float)$row['distance'] : null,
                                'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                                'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                            ];
                        }
                        $timelineStmt->close();
                    }
                }
            }

            return [
                'timeline' => $timeline,
                'laps' => $this->getWorkoutLaps((int)$workoutId),
            ];
        } catch (Exception $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error("Error loading workout timeline", [
                'workout_id' => $workoutId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            $this->throwException('Ошибка загрузки timeline: ' . $e->getMessage(), 500, [
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function getWorkoutLaps(int $workoutId): array {
        if ($workoutId <= 0 || !$this->tableExists('workout_laps')) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT lap_index, lap_name, start_time, elapsed_seconds, moving_seconds,
                   distance_km, average_speed, max_speed, avg_heart_rate, max_heart_rate,
                   elevation_gain, cadence, start_index, end_index, payload_json
            FROM workout_laps
            WHERE workout_id = ?
            ORDER BY lap_index ASC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $workoutId);
        $stmt->execute();
        $result = $stmt->get_result();
        $laps = [];
        while ($row = $result->fetch_assoc()) {
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $laps[] = [
                'lap_index' => isset($payload['lap_index']) ? (int)$payload['lap_index'] : (int)$row['lap_index'],
                'name' => $payload['name'] ?? $row['lap_name'],
                'start_time' => $payload['start_time'] ?? $row['start_time'],
                'elapsed_seconds' => array_key_exists('elapsed_seconds', $payload)
                    ? ($payload['elapsed_seconds'] !== null ? (int)$payload['elapsed_seconds'] : null)
                    : ($row['elapsed_seconds'] !== null ? (int)$row['elapsed_seconds'] : null),
                'moving_seconds' => array_key_exists('moving_seconds', $payload)
                    ? ($payload['moving_seconds'] !== null ? (int)$payload['moving_seconds'] : null)
                    : ($row['moving_seconds'] !== null ? (int)$row['moving_seconds'] : null),
                'distance_km' => array_key_exists('distance_km', $payload)
                    ? ($payload['distance_km'] !== null ? (float)$payload['distance_km'] : null)
                    : ($row['distance_km'] !== null ? (float)$row['distance_km'] : null),
                'average_speed' => array_key_exists('average_speed', $payload)
                    ? ($payload['average_speed'] !== null ? (float)$payload['average_speed'] : null)
                    : ($row['average_speed'] !== null ? (float)$row['average_speed'] : null),
                'max_speed' => array_key_exists('max_speed', $payload)
                    ? ($payload['max_speed'] !== null ? (float)$payload['max_speed'] : null)
                    : ($row['max_speed'] !== null ? (float)$row['max_speed'] : null),
                'avg_pace' => $payload['avg_pace'] ?? null,
                'pace_seconds_per_km' => isset($payload['pace_seconds_per_km']) && $payload['pace_seconds_per_km'] !== null
                    ? (int)$payload['pace_seconds_per_km']
                    : null,
                'avg_heart_rate' => array_key_exists('avg_heart_rate', $payload)
                    ? ($payload['avg_heart_rate'] !== null ? (int)$payload['avg_heart_rate'] : null)
                    : ($row['avg_heart_rate'] !== null ? (int)$row['avg_heart_rate'] : null),
                'max_heart_rate' => array_key_exists('max_heart_rate', $payload)
                    ? ($payload['max_heart_rate'] !== null ? (int)$payload['max_heart_rate'] : null)
                    : ($row['max_heart_rate'] !== null ? (int)$row['max_heart_rate'] : null),
                'elevation_gain' => array_key_exists('elevation_gain', $payload)
                    ? ($payload['elevation_gain'] !== null ? (float)$payload['elevation_gain'] : null)
                    : ($row['elevation_gain'] !== null ? (float)$row['elevation_gain'] : null),
                'cadence' => array_key_exists('cadence', $payload)
                    ? ($payload['cadence'] !== null ? (int)$payload['cadence'] : null)
                    : ($row['cadence'] !== null ? (int)$row['cadence'] : null),
                'start_index' => array_key_exists('start_index', $payload)
                    ? ($payload['start_index'] !== null ? (int)$payload['start_index'] : null)
                    : ($row['start_index'] !== null ? (int)$row['start_index'] : null),
                'end_index' => array_key_exists('end_index', $payload)
                    ? ($payload['end_index'] !== null ? (int)$payload['end_index'] : null)
                    : ($row['end_index'] !== null ? (int)$row['end_index'] : null),
            ];
        }
        $stmt->close();
        return $laps;
    }

    private function tableExists(string $tableName): bool {
        if ($tableName === '') {
            return false;
        }
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return (bool)$this->tableExistsCache[$tableName];
        }
        $safeName = preg_replace('/[^a-z0-9_]/i', '', $tableName);
        if ($safeName === '') {
            $this->tableExistsCache[$tableName] = false;
            return false;
        }
        $result = $this->db->query("SHOW TABLES LIKE '" . $this->db->real_escape_string($safeName) . "'");
        $exists = $result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $this->tableExistsCache[$tableName] = $exists;
        return $exists;
    }

    /**
     * Получить версию данных (для polling).
     */
    public function getDataVersion(int $userId): string {
        // Версия охватывает всё, что отображается в виджетах/календаре:
        // тренировки (новые/удалённые), правки результатов, план (правка/удаление/регенерация),
        // упражнения ОФП/СБУ. users.updated_at НЕ берём — его дёргает last_activity (был бы churn).
        $stmt = $this->db->prepare(
            "SELECT
                (SELECT COALESCE(MAX(id), 0) FROM workouts WHERE user_id = ?) AS w_max,
                (SELECT COUNT(*) FROM workouts WHERE user_id = ?) AS w_cnt,
                (SELECT COALESCE(MAX(id), 0) FROM workout_log WHERE user_id = ?) AS l_max,
                (SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) FROM workout_log WHERE user_id = ?) AS l_upd,
                (SELECT COUNT(*) FROM training_plan_days WHERE user_id = ?) AS pd_cnt,
                (SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) FROM training_plan_days WHERE user_id = ?) AS pd_upd,
                (SELECT COUNT(*) FROM training_day_exercises WHERE user_id = ?) AS ex_cnt,
                (SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) FROM training_day_exercises WHERE user_id = ?) AS ex_upd"
        );
        $stmt->bind_param('iiiiiiii', $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return implode('_', [
            $row['w_max'] ?? 0, $row['w_cnt'] ?? 0,
            $row['l_max'] ?? 0, $row['l_upd'] ?? 0,
            $row['pd_cnt'] ?? 0, $row['pd_upd'] ?? 0,
            $row['ex_cnt'] ?? 0, $row['ex_upd'] ?? 0,
        ]);
    }

    /**
     * Загрузить результат тренировки по дате.
     */
    public function getWorkoutResultByDate(string $date, int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM workout_log
            WHERE user_id = ? AND training_date = ?
            LIMIT 1
        ");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Проверяет, является ли завершённая тренировка контрольной/забегом,
     * и если да — пересчитывает VDOT пользователя, уведомляет в чат.
     */
    public function checkVdotUpdateAfterResult(array $data, int $userId): void {
        try {
            $distanceKm = isset($data['result_distance']) ? (float)$data['result_distance'] : 0;
            $resultTime = $data['result_time'] ?? '';
            if ($distanceKm <= 0 || $resultTime === '') {
                return;
            }

            $weekNum = (int)($data['week'] ?? 0);
            $dayName = $data['day'] ?? '';
            if (!$weekNum || !$dayName) return;

            $dayMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
            $dayOfWeek = $dayMap[$dayName] ?? 0;
            if (!$dayOfWeek) return;

            $stmt = $this->db->prepare("
                SELECT tpd.type FROM training_plan_days tpd
                INNER JOIN training_plan_weeks tpw ON tpd.week_id = tpw.id
                WHERE tpw.user_id = ? AND tpw.week_number = ? AND tpd.day_of_week = ?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $userId, $weekNum, $dayOfWeek);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $type = $row['type'] ?? '';
            if (!in_array($type, ['control', 'race'])) {
                return;
            }

            $timeSec = $this->parseResultTimeSec($resultTime);
            if ($timeSec <= 0) return;

            require_once __DIR__ . '/TrainingStateBuilder.php';
            require_once __DIR__ . '/WorkoutPlanRecalculationService.php';
            require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

            $builder = new TrainingStateBuilder($this->db);
            $oldState = $builder->buildForUserId($userId);
            $oldVdot = isset($oldState['vdot']) ? (float)$oldState['vdot'] : null;

            $newVdot = estimateVDOT($distanceKm, $timeSec);
            if ($newVdot < 20 || $newVdot > 85) return;

            $date = $data['date'] ?? date('Y-m-d');

            // Определяем стандартную метку дистанции
            $distMap = [5 => '5k', 10 => '10k', 21 => 'half', 42 => 'marathon'];
            $lastRaceDist = 'other';
            $lastRaceDistKm = $distanceKm;
            foreach ($distMap as $km => $label) {
                if (abs($distanceKm - $km) < 0.5) {
                    $lastRaceDist = $label;
                    $lastRaceDistKm = null;
                    break;
                }
            }

            $updateStmt = $this->db->prepare("
                UPDATE users SET last_race_distance = ?, last_race_distance_km = ?, last_race_time = ?, last_race_date = ? WHERE id = ?
            ");
            $updateStmt->bind_param('sdssi', $lastRaceDist, $lastRaceDistKm, $resultTime, $date, $userId);
            $updateStmt->execute();
            $updateStmt->close();

            $newState = $builder->buildForUserId($userId);
            $newVdotR = !empty($newState['vdot']) ? round((float)$newState['vdot'], 1) : round($newVdot, 1);
            $formattedPaces = $newState['formatted_training_paces'] ?? null;
            $predictions = !empty($newState['vdot']) ? predictAllRaceTimes((float)$newState['vdot']) : predictAllRaceTimes($newVdot);
            $autoRecalc = (new WorkoutPlanRecalculationService($this->db))->maybeQueueAfterPerformanceUpdate(
                $userId, $type, $date, $oldVdot,
                isset($newState['vdot']) ? (float)$newState['vdot'] : $newVdot
            );

            $typeLabel = $type === 'control' ? 'контрольной тренировки' : 'забега';
            $msg = "Результат {$typeLabel}: {$distanceKm} км за {$resultTime}.\n";
            $msg .= "Ваш VDOT: **{$newVdotR}**";
            if ($oldVdot) {
                $diff = round($newVdotR - $oldVdot, 1);
                $arrow = $diff > 0 ? '+' : '';
                $msg .= " ({$arrow}{$diff})";
            }
            $msg .= "\n\n";
            if (!empty($newState['vdot_source_label'])) {
                $msg .= "Источник формы: {$newState['vdot_source_label']}";
                if (!empty($newState['vdot_confidence'])) {
                    $msg .= " ({$newState['vdot_confidence']})";
                }
                $msg .= "\n\n";
            }

            $msg .= "Обновлённые зоны:\n";
            if ($formattedPaces) {
                $msg .= "- Лёгкий: {$formattedPaces['easy']}/км\n";
                $msg .= "- Пороговый: {$formattedPaces['threshold']}/км\n";
                $msg .= "- Интервальный: {$formattedPaces['interval']}/км\n\n";
            } else {
                $paces = getTrainingPaces($newVdot);
                $msg .= "- Лёгкий: " . formatPaceSec($paces['easy'][0]) . " – " . formatPaceSec($paces['easy'][1]) . "/км\n";
                $msg .= "- Пороговый: " . formatPaceSec($paces['threshold']) . "/км\n";
                $msg .= "- Интервальный: " . formatPaceSec($paces['interval']) . "/км\n\n";
            }

            $msg .= "Прогнозы: ";
            $parts = [];
            foreach ($predictions as $label => $pred) {
                $distLabels = ['5k' => '5К', '10k' => '10К', 'half' => 'ПМ', 'marathon' => 'М'];
                $parts[] = ($distLabels[$label] ?? $label) . " " . $pred['formatted'];
            }
            $msg .= implode(' | ', $parts);

            if ($autoRecalc['queued']) {
                $msg .= "\n\nПлан автоматически поставлен на пересчёт с учётом нового результата.";
            } elseif (!empty($autoRecalc['skipped_reason'])) {
                $msg .= "\n\nАвтопересчёт плана не запускался: {$autoRecalc['skipped_reason']}.";
            }

            require_once __DIR__ . '/ChatService.php';
            $chatService = new ChatService($this->db);
            $chatService->addAIMessageToUser($userId, $msg, [
                'event_key' => 'performance.vdot_updated',
                'title' => 'VDOT обновлён',
                'link' => '/chat',
                'source_type' => $type,
                'workout_date' => $date,
            ]);
        } catch (\Throwable $e) {
            error_log("checkVdotUpdate error for user $userId: " . $e->getMessage());
        }
    }

    private function parseResultTimeSec(string $resultTime): int {
        $parts = explode(':', $resultTime);
        if (count($parts) === 3) {
            return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        } elseif (count($parts) === 2) {
            return (int)$parts[0] * 60 + (int)$parts[1];
        }
        return 0;
    }

    // maybeUpdateVdotFromWorkouts() удалён в v3.14 — был no-op stub.
    // VDOT теперь считается через TrainingStateBuilder → StatsService::getBestResultForVdot().
}
