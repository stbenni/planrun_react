<?php

require_once __DIR__ . '/../../services/LlmGateway.php';
require_once __DIR__ . '/../../services/TrainingStateBuilder.php';
require_once __DIR__ . '/../../repositories/UserRepository.php';
require_once __DIR__ . '/../plan_normalizer.php';

/**
 * Production план-планировщик на DeepSeek V4 (single-pass).
 *
 * Phase A (PR2) — упрощения относительно legacy под слабые LLM:
 *  - A.2: убрана `staged` стратегия (macro + detail batches);
 *  - A.3: одна модель `PLAN_LLM_MODEL` вместо planner/detail/repair;
 *  - A.4: убран `repairPlan()` и связанные prompts (targeted retry — Phase C.2).
 *
 * См. `docs/PLANS-AI-V2.md` раздел 2a и `.cursor/rules/plans-ai-v2.mdc`.
 */
class DeepSeekPlanPlanner
{
    private mysqli $db;
    private string $baseUrl;
    private string $model;
    private string $apiKey;
    private int $maxTokens;
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;
    private bool $enableThinking;
    private string $reasonerModel;
    private bool $autoReasoner;
    private int $reasonerTimeoutSeconds;
    private ?string $observabilityTraceId = null;
    private ?int $observabilityUserId = null;
    private string $observabilitySurface = 'plan_generation';

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->baseUrl = rtrim((string) env('PLAN_LLM_BASE_URL', env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com')), '/');
        // Phase A.3: одна модель. Backwards compat: читаем legacy переменные, если новая не задана.
        $this->model = (string) env(
            'PLAN_LLM_MODEL',
            env('PLAN_LLM_PLANNER_MODEL', env('PLAN_LLM_REVIEWER_MODEL', 'deepseek-chat'))
        );
        $this->apiKey = LlmGateway::apiKey('plan');
        $this->maxTokens = $this->envInt(
            'PLAN_LLM_MAX_TOKENS',
            (int) env('PLAN_LLM_PLANNER_DETAIL_MAX_TOKENS', '20000'),
            2048,
            65536
        );
        $this->timeoutSeconds = $this->envInt(
            'PLAN_LLM_TIMEOUT_SECONDS',
            (int) env('PLAN_LLM_PLANNER_DETAIL_TIMEOUT_SECONDS', '240'),
            30,
            600
        );
        $this->connectTimeoutSeconds = $this->envInt('PLAN_LLM_CONNECT_TIMEOUT_SECONDS', 10, 1, 60);
        $this->enableThinking = $this->envBool('PLAN_LLM_PLANNER_THINKING', false);
        // Phase C.1 (PR5): deepseek-reasoner для сложных сценариев.
        $this->reasonerModel = (string) env('PLAN_LLM_REASONER_MODEL', 'deepseek-reasoner');
        $this->autoReasoner = $this->envBool('PLAN_LLM_AUTO_REASONER', true);
        $this->reasonerTimeoutSeconds = $this->envInt(
            'PLAN_LLM_REASONER_TIMEOUT_SECONDS',
            max(360, $this->timeoutSeconds + 120),
            60,
            900
        );
    }

    public function setObservabilityContext(?string $traceId, ?int $userId = null, string $surface = 'plan_generation'): void
    {
        $this->observabilityTraceId = $traceId !== null && trim($traceId) !== '' ? trim($traceId) : null;
        $this->observabilityUserId = $userId !== null && $userId > 0 ? $userId : null;
        $surface = trim($surface);
        $this->observabilitySurface = $surface !== '' ? $surface : 'plan_generation';
    }

    public function generate(int $userId, string $jobType = 'generate', array $payload = []): array
    {
        $user = $this->loadUser($userId);
        if ($user === []) {
            throw new RuntimeException('Не удалось загрузить профиль пользователя для LLM planner', 500);
        }

        $planningUser = $this->buildPlanningUser($user, $jobType, $payload);
        $stateBuilderMode = $jobType === 'recalculate' ? 'recalculate' : ($jobType === 'next_plan' ? 'next_plan' : 'generate');
        $state = (new TrainingStateBuilder($this->db))->buildForUser($planningUser, $stateBuilderMode, $payload);
        $startDate = $this->resolveStartDate($user, $jobType, $payload);
        $weeksCount = $this->resolveWeeksCount($state, $user, $startDate);
        $context = $this->buildPlannerContext($user, $state, $payload, $jobType, $startDate, $weeksCount);

        // Phase C.1 (PR5): для сложных сценариев (return_after_injury + b_race + goal_realism=major
        // или ≥2 risk-флагов одновременно) переключаемся на deepseek-reasoner с enable_thinking.
        // Auto-reasoner работает по умолчанию; отключается через PLAN_LLM_AUTO_REASONER=0.
        $modelSelection = $this->resolveModelSelection($state);

        $generationArtifact = $this->generateFullPlan($context, $modelSelection);
        $weeks = array_values((array) ($generationArtifact['weeks'] ?? []));
        $weeks = $this->alignWeekTargetsToCalendar($weeks);
        $macro = $this->deriveMacroPlanFromWeeks($weeks);

        $plan = $this->normalizeWeekCollection($weeks, $weeksCount);
        $plan['_generation_metadata'] = [
            'generator' => 'DeepSeekPlanPlanner',
            'generation_mode' => 'llm_planner',
            'model' => $modelSelection['model'],
            'model_selection_reason' => $modelSelection['reason'],
            'model_complexity_score' => $modelSelection['score'],
            'enable_thinking' => $modelSelection['enable_thinking'],
            'planner_strategy' => 'single_pass',
            'schedule_anchor_date' => $startDate,
            'macro_plan' => $macro,
            'raw_model_macro_plan_ignored' => is_array($generationArtifact['macro_plan'] ?? null),
            'plan_summary' => $generationArtifact['plan_summary'] ?? null,
            'risk_review' => $generationArtifact['risk_review'] ?? null,
            'prompt_version' => 'deepseek_llm_planner_v3_simplified',
        ];

        return [
            'plan' => $plan,
            'user' => $user,
            'training_state' => $state,
            'start_date' => $startDate,
            'macro_plan' => $macro,
            'planner_context' => $context,
        ];
    }

    private function generateFullPlan(array $context, ?array $modelSelection = null): array
    {
        $prompt = $this->buildFullPlanPrompt($context);
        $selection = $modelSelection ?? [
            'model' => $this->model,
            'enable_thinking' => $this->enableThinking,
            'timeout_seconds' => $this->timeoutSeconds,
            'reason' => 'default',
            'score' => 0,
        ];
        return $this->requestJson(
            (string) $selection['model'],
            $prompt,
            $this->maxTokens,
            (int) $selection['timeout_seconds'],
            (bool) $selection['enable_thinking']
        );
    }

    /**
     * Phase C.1 (PR5): выбор модели и thinking-режима на основе сложности сценария.
     *
     * Сложный сценарий = одновременно ≥2 факторов риска из:
     *   - planning_scenario.flags ∈ {return_after_injury, pain_protective, illness_protective,
     *     b_race_before_a_race, short_runway_taper, short_runway_long_race}
     *   - special_population_flags ∈ {pregnant_or_postpartum, return_after_injury,
     *     recent_pain_signal, recent_illness_signal}
     *   - goal_realism.severity == 'major'
     *
     * При complexity_score ≥ 2 — переключаемся на deepseek-reasoner с enable_thinking=true и
     * расширенным timeout (по умолчанию +120 сек). Это даёт модели больше внутреннего reasoning
     * budget для сложных кейсов, где простой single-pass может ошибиться.
     *
     * Auto-reasoner отключается через `PLAN_LLM_AUTO_REASONER=0` — тогда всегда используется
     * базовая модель (`PLAN_LLM_MODEL`).
     */
    public function resolveModelSelection(array $state): array
    {
        $score = $this->computeComplexityScore($state);

        if ($this->autoReasoner && $score >= 2) {
            return [
                'model' => $this->reasonerModel,
                'enable_thinking' => true,
                'timeout_seconds' => $this->reasonerTimeoutSeconds,
                'reason' => 'complex_scenario',
                'score' => $score,
            ];
        }

        return [
            'model' => $this->model,
            'enable_thinking' => $this->enableThinking,
            'timeout_seconds' => $this->timeoutSeconds,
            'reason' => $score > 0 ? 'simple_scenario_with_minor_risks' : 'default',
            'score' => $score,
        ];
    }

    /**
     * Phase C.2 (PR5): targeted retry для конкретных недель плана.
     *
     * Используется после quality gate failure, когда issues локализованы по 1-2 неделям.
     * Вместо полной регенерации плана (45-120 секунд + дорого) — переотправляем модели
     * только проблемные недели с конкретным фидбеком и существующим контекстом плана.
     *
     * @param array $context Существующий context из buildPlannerContext (FACTS_JSON).
     * @param array $existingPlan Существующий план (массив недель, week_number → week structure).
     * @param int[] $weekNumbersToRedo Номера недель, которые нужно перевыдать.
     * @param string[] $issueHints Конкретные issue messages для модели (по одному на week_number или общие).
     * @return array Результат: ['weeks' => [...перевыданные недели...], 'plan_summary' => ...]
     * @throws RuntimeException если ответ не содержит запрошенных недель.
     */
    public function regenerateWeeks(array $context, array $existingPlan, array $weekNumbersToRedo, array $issueHints = []): array
    {
        $weekNumbersToRedo = array_values(array_unique(array_map('intval', $weekNumbersToRedo)));
        sort($weekNumbersToRedo, SORT_NUMERIC);
        if (empty($weekNumbersToRedo)) {
            throw new RuntimeException('regenerateWeeks: weekNumbersToRedo cannot be empty', 400);
        }
        if (count($weekNumbersToRedo) > 4) {
            throw new RuntimeException('regenerateWeeks: too many weeks (max 4); use full regeneration instead', 400);
        }

        $prompt = $this->buildTargetedRetryPrompt($context, $existingPlan, $weekNumbersToRedo, $issueHints);

        // Targeted retry — обычно проще, чем full plan; используем базовую модель и стандартный timeout.
        $response = $this->requestJson($this->model, $prompt, $this->maxTokens, $this->timeoutSeconds, $this->enableThinking);

        $weeks = array_values((array) ($response['weeks'] ?? []));
        if (empty($weeks)) {
            throw new RuntimeException('regenerateWeeks: response did not contain weeks array', 500);
        }

        $returnedNumbers = array_map(fn($w) => (int) ($w['week_number'] ?? 0), $weeks);
        $missing = array_diff($weekNumbersToRedo, $returnedNumbers);
        if (!empty($missing)) {
            throw new RuntimeException(
                'regenerateWeeks: response missing weeks: ' . implode(',', $missing),
                500
            );
        }

        return [
            'weeks' => $weeks,
            'plan_summary' => $response['plan_summary'] ?? null,
            'risk_review' => $response['risk_review'] ?? null,
        ];
    }

    /**
     * Phase C.2 (PR5): применить регенерированные недели к существующему плану.
     * Заменяет недели по week_number; остальные остаются нетронутыми.
     *
     * @param array $existingPlan План в формате normalizeWeekCollection (`weeks_data.weeks`).
     * @param array $regeneratedWeeks Массив недель из regenerateWeeks.
     * @return array Обновлённый план.
     */
    public function applyRegeneratedWeeks(array $existingPlan, array $regeneratedWeeks): array
    {
        $weeks = (array) ($existingPlan['weeks_data']['weeks'] ?? $existingPlan['weeks'] ?? []);
        if (empty($weeks)) {
            return $existingPlan;
        }

        $regeneratedByNumber = [];
        foreach ($regeneratedWeeks as $w) {
            $num = (int) ($w['week_number'] ?? 0);
            if ($num > 0) {
                $regeneratedByNumber[$num] = $w;
            }
        }

        $merged = [];
        foreach ($weeks as $w) {
            $num = (int) ($w['week_number'] ?? 0);
            if ($num > 0 && isset($regeneratedByNumber[$num])) {
                $merged[] = $regeneratedByNumber[$num];
            } else {
                $merged[] = $w;
            }
        }

        $aligned = $this->alignWeekTargetsToCalendar($merged);

        if (isset($existingPlan['weeks_data']['weeks'])) {
            $existingPlan['weeks_data']['weeks'] = $aligned;
        }
        if (isset($existingPlan['weeks'])) {
            $existingPlan['weeks'] = $aligned;
        }

        $existingPlan['_generation_metadata']['targeted_retry'] = [
            'regenerated_week_numbers' => array_keys($regeneratedByNumber),
            'regenerated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return $existingPlan;
    }

    /**
     * Phase C.2 (PR5): prompt для targeted retry. Передаёт DeepSeek существующий план как
     * контекст (для непрерывности фаз/прогрессии), точные week_numbers и issue hints — и
     * просит вернуть только заменённые недели.
     */
    private function buildTargetedRetryPrompt(array $context, array $existingPlan, array $weekNumbersToRedo, array $issueHints): string
    {
        $existingWeeks = (array) ($existingPlan['weeks_data']['weeks'] ?? $existingPlan['weeks'] ?? []);
        $weeksContext = [];
        foreach ($existingWeeks as $w) {
            $num = (int) ($w['week_number'] ?? 0);
            $weeksContext[] = [
                'week_number' => $num,
                'phase' => $w['phase'] ?? null,
                'is_recovery' => (bool) ($w['is_recovery'] ?? false),
                'target_volume_km' => $w['target_volume_km'] ?? null,
                'is_to_redo' => in_array($num, $weekNumbersToRedo, true),
            ];
        }

        $promptContext = [
            'weeks_count' => $context['weeks_count'] ?? null,
            'calendar_weeks' => array_values(array_filter(
                (array) ($context['calendar_weeks'] ?? []),
                fn($w) => in_array((int) ($w['week_number'] ?? 0), $weekNumbersToRedo, true)
            )),
            'training_state' => $context['training_state'] ?? null,
            'planning_scenario' => $context['planning_scenario'] ?? null,
            'goal_realism' => $context['goal_realism'] ?? null,
            'hard_rules' => $context['hard_rules'] ?? null,
            'season' => $context['season'] ?? null,
            'best_races' => $context['best_races'] ?? null,
            'recent_compliance' => $context['recent_compliance'] ?? null,
            'recent_workouts' => $context['recent_workouts'] ?? null,
        ];

        $hintLines = '';
        if (!empty($issueHints)) {
            foreach ($issueHints as $h) {
                $hintLines .= "- " . trim((string) $h) . "\n";
            }
        }

        return "Ты — тренер по бегу. Тебе уже составлен план; quality gate указал на проблемы в конкретных неделях. "
            . "Перевыдай только эти недели целиком (по 7 дней), сохраняя совместимость с остальным планом и фазами.\n\n"
            . "Недели для перевыдачи (week_numbers): " . implode(', ', $weekNumbersToRedo) . "\n"
            . ($hintLines !== '' ? "\nИзвестные проблемы:\n" . $hintLines . "\n" : "")
            . "Существующая структура недель плана (для контекста, чтобы новые недели согласовывались с фазами):\n"
            . json_encode($weeksContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
            . "Используй HARD_RULES_JSON и FACTS_JSON ниже как медицинские/расписательные/языковые границы. "
            . "Уважай required_run_day_numbers / allowed_run_day_numbers и medical safety. "
            . "Все человекочитаемые строки на русском (notes, macro_adjustment_reason, plan_summary, risk_review).\n\n"
            . "Формат ответа — ровно тот же JSON, но в weeks включи только запрошенные недели:\n"
            . "{\n"
            . "  \"plan_summary\":\"короткое резюме изменений\",\n"
            . "  \"risk_review\":[\"короткий риск\"],\n"
            . "  \"weeks\":[ ровно " . count($weekNumbersToRedo) . " элементов с week_number в "
            . json_encode($weekNumbersToRedo) . " ]\n"
            . "}\n\n"
            . "FACTS_JSON:\n" . json_encode($promptContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Phase C.1 (PR5): подсчёт сложности сценария. См. resolveModelSelection.
     * Возвращает int 0..N где N — количество одновременных факторов риска.
     */
    public function computeComplexityScore(array $state): int
    {
        $score = 0;

        $sensitivePopulationFlags = ['pregnant_or_postpartum', 'return_after_injury', 'recent_pain_signal', 'recent_illness_signal'];
        $populationFlags = (array) ($state['special_population_flags'] ?? []);
        foreach ($sensitivePopulationFlags as $flag) {
            if (in_array($flag, $populationFlags, true)) {
                $score++;
            }
        }

        $highRiskScenarioFlags = [
            'return_after_injury', 'pain_protective', 'illness_protective',
            'b_race_before_a_race', 'short_runway_taper', 'short_runway_long_race',
            'low_confidence_start',
        ];
        $scenarioFlags = (array) ($state['planning_scenario']['flags'] ?? []);
        foreach ($highRiskScenarioFlags as $flag) {
            if (in_array($flag, $scenarioFlags, true)) {
                $score++;
            }
        }

        $severity = (string) ($state['goal_realism']['severity'] ?? '');
        if ($severity === 'major') {
            $score++;
        }

        return $score;
    }

    private function buildSystemPrompt(): string
    {
        return 'Ты — тренер по бегу и возвращаешь только валидный JSON без markdown, комментариев и <think>. '
            . 'Перед ответом внутри себя спокойно проанализируй профиль, цель, свежие тренировки, риски и ограничения; не выводи ход рассуждений. '
            . 'JSON keys, enum values и технические поля оставляй ровно как в схеме. '
            . 'Все человекочитаемые строки внутри JSON пиши только на русском языке: notes, quality_focus, risk_note, macro_adjustment_reason. '
            . 'Не используй английские тренировочные слова в этих строках.';
    }

    /**
     * Single-pass plan prompt для DeepSeek V4.
     *
     * Phase A.5 (PR3) — упрощён под trust-the-model: hard_rules выдают только medical/schedule
     * инварианты, остальное — тренерское решение модели на основе FACTS_JSON.
     */
    private function buildFullPlanPrompt(array $context): string
    {
        return "Ты — сильный тренер по бегу. Получи весь профиль пользователя и весь горизонт подготовки в FACTS_JSON, спокойно проанализируй ситуацию и составь полный календарный план single-pass.\n\n"
            . "Внутри себя оцени цель, свежие тренировки (recent_workouts), сроки, готовность (training_state), доступные дни, риски и сценарий (planning_scenario, goal_realism). Затем верни только JSON.\n\n"
            . "Главная задача — лучший реалистичный план для этого пользователя, а не максимальный километраж и не жёсткий шаблон. Используй HARD_RULES_JSON как медицинские/расписательные/языковые границы. Остальные факты — входные данные для тренерского анализа, а не клетка для ответа.\n\n"
            . "Формат ответа:\n"
            . "{\n"
            . "  \"plan_summary\":\"короткое русское резюме логики плана\",\n"
            . "  \"risk_review\":[\"короткий риск или компромисс по-русски\"],\n"
            . "  \"weeks\":[...]\n"
            . "}\n\n"
            . "Формат недели: {\"week_number\":1,\"phase\":\"base|build|peak|recovery|taper|race\","
            . "\"is_recovery\":false,\"target_volume_km\":0,\"macro_adjustment_reason\":null,\"days\":[7 days]}. "
            . "target_volume_km — итоговый недельный target после твоего анализа, должен примерно совпадать с суммой distance_km дней.\n\n"
            . "Формат дня: {\"day_of_week\":1,\"type\":\"easy|rest|long|tempo|interval|fartlek|control|race|other\","
            . "\"distance_km\":8.0,\"pace\":\"5:20\",\"duration_minutes\":43,\"warmup_km\":null,\"cooldown_km\":null,"
            . "\"tempo_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,"
            . "\"segments\":null,\"subtype\":null,\"notes\":\"\"}.\n\n"
            . "Hard boundaries (medical/schedule):\n"
            . "- Верни ровно weeks_count недель и ровно 7 дней в каждой; day_of_week всегда 1..7.\n"
            . "- Используй calendar_weeks из FACTS_JSON для дат недели; days_to_race и is_race_date уже посчитаны.\n"
            . "- Если race_date попадает в горизонт — поставь race именно на эту дату.\n"
            . "- Уважай required_run_day_numbers / allowed_run_day_numbers; если ради восстановления отступаешь — объясни в macro_adjustment_reason.\n"
            . "- Если задан long_run_safety.marathon_last_21_days_training_long_run_max_km — любая тренировочная длительная за 1..21 день до марафона ≤ этому лимиту.\n"
            . "- Если задан long_run_safety.no_training_run_at_or_above_race_distance_except_race_day — не ставь полный марафон до старта.\n"
            . "- Если задан fresh_long_effort_guard — после свежего очень длинного забега первая неделя может быть восстановительной, даже если readiness высокая.\n\n"
            . "Тренерская свобода:\n"
            . "- Сам выбирай фазы, объёмы, длительные, интенсивность и разгрузки на основе training_state.load_policy и истории.\n"
            . "- target_volume_km каждой недели должен быть твоим итоговым решением и должен примерно совпадать с суммой distance_km в календаре этой недели.\n"
            . "- Можно сделать план осторожнее или амбициознее входных ориентиров, если факты это поддерживают.\n"
            . "- Если цель/срок рискованные — не срывай генерацию: составь лучший безопасный вариант и отрази в risk_review.\n\n"
            . "Сценарии и goal_realism (контекст; реагируй там, где он применим):\n"
            . "- planning_scenario.primary='return_after_injury' → объём не более 60% от reported_weekly_base_km, без интервалов первые 3 недели, длительная +1 км/нед; в notes отрази возврат после травмы.\n"
            . "- planning_scenario.flags содержит 'pain_protective' / 'illness_protective' / 'high_caution' → quality сессии исключаются минимум на 1 неделю; объём стабильно или вниз.\n"
            . "- planning_scenario.flags содержит 'b_race_before_a_race' → tune-up идёт как control, без полной подводки.\n"
            . "- goal_realism.severity='major' → используй goal_realism.recommended_target_time как опорный темп; в plan_summary/risk_review отметь пересмотр цели.\n\n"
            . "Контекст последних недель (recent_compliance, recent_workouts) — не блокирующий, но твой первый источник правды о форме:\n"
            . "- recent_compliance показывает по неделям planned/completed_count, actual_km, key_workout_completion_pct, skipped_count. Если человек регулярно срывает >30% запланированного или ключевые тренировки выполнены <60% — план был слишком амбициозным, понизь объём и плотность качества.\n"
            . "- Если compliance стабильно высокий (>0.85) и actual_km ≥ planned_km — есть запас, можно держать темп прогресса.\n"
            . "- recent_workouts (за ~14 дней) содержит pace_sec, hr_avg, rpe (1=очень легко .. 5=очень тяжело), notes. Используй: рост HR при том же темпе или RPE>3 на easy — признак усталости, замедли темпы или дай recovery.\n"
            . "- Сравни pace в recent_workouts с training_paces.easy/marathon/threshold: если фактический темп easy медленнее ожидаемого — не форсируй интенсивность, дай адаптацию.\n"
            . "- Учитывай notes: жалобы на боль/усталость/болезнь = осторожнее даже если HR/pace в норме. Игнорируй recent_workouts только если массив пустой.\n\n"
            . "Климат и сезон (season) — учитывай при планировании темпов:\n"
            . "- season.current_month_name + season.season_phase описывают условия в начале плана; race_season_phase — на дату гонки.\n"
            . "- Если start или race-период попадают в summer (или summer/late_autumn для southern_hemisphere=false): жара заметно замедляет easy и tempo, не делай прогрессивных интервалов в самых жарких неделях, упоминай в notes о термонагрузке.\n"
            . "- Зимой (winter) на улице может быть скользко/холодно — это контекст для разговора с пользователем, не блокер. Указывай в notes альтернативы (treadmill, indoor) только если явно уместно.\n\n"
            . "История лучших результатов (best_races) — твоя база для оценки реалистичности:\n"
            . "- best_races содержит по бакетам 5k/10k/half/marathon: distance_km, time_sec, pace_sec, date, vdot. Сортировка по дате убыв.\n"
            . "- Сравни целевой goal_pace с историческим pace_sec на той же или соседней дистанции. Большой разрыв (>15-20 сек/км) — повод обсудить в risk_review.\n"
            . "- goal_realism.best_races_at_target_distance, если есть, показывает прежние попытки на той же дистанции — используй их как ориентир. Если человек уже бегал марафон 4:30, цель 3:30 без значимого роста VDOT за 6 месяцев — нереалистично, отрази в risk_review.\n"
            . "- Свежий (≤6 нед) сильный результат на короткой дистанции — повод доверять goal_pace. Старый (>26 нед) или единственный — повод быть осторожнее.\n\n"
            . "Темпы и структура (для понятной тренировки):\n"
            . "- Для простого бега pace относится ко всей тренировке, duration_minutes согласуй с distance_km.\n"
            . "- Для interval/tempo заполни структурные поля: warmup_km, cooldown_km, tempo_km, reps/interval_m/interval_pace/rest_m/rest_type.\n"
            . "- Для fartlek обязательно заполни segments: [{\"reps\":8,\"distance_m\":400,\"pace\":\"4:10\",\"recovery_m\":200,\"recovery_type\":\"jog\"}]. Не возвращай fartlek только с разминкой и заминкой.\n"
            . "- Если работа около целевого темпа гонки — subtype=race_pace и pace около goal_pace.\n"
            . "- Не ставь медленный steady/easy pace в type=tempo (tempo подразумевает темповую интенсивность).\n\n"
            . "Язык:\n"
            . "- Все человекочитаемые строки только на русском: plan_summary, risk_review, macro_adjustment_reason, notes.\n"
            . "- Не используй в них английские тренировочные термины: threshold, marathon-pace, race-pace, long run, easy run, warmup, cooldown, taper, recovery, MP, HMP.\n"
            . "- JSON keys и enum values оставляй как в схеме.\n\n"
            . "FACTS_JSON:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function requestJson(string $model, string $prompt, int $maxTokens, int $timeoutSeconds, bool $enableThinking): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('DeepSeek API key is empty', 500);
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => $maxTokens,
            'stream' => false,
            'response_format' => ['type' => 'json_object'],
        ];
        $payload = LlmGateway::withThinkingMode($payload, $this->baseUrl, $enableThinking);

        $json = LlmGateway::requestChatCompletion($this->baseUrl, $payload, [
            'feature' => 'DeepSeek planner',
            'purpose' => 'plan',
            'db' => $this->db,
            'surface' => $this->observabilitySurface,
            'event_type' => 'llm_request',
            'trace_id' => $this->observabilityTraceId,
            'user_id' => $this->observabilityUserId,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $this->connectTimeoutSeconds,
            'max_attempts' => $this->envInt('PLAN_LLM_REQUEST_MAX_ATTEMPTS', 2, 1, 5),
        ]);
        $choice = (array) ($json['choices'][0] ?? []);
        $finishReason = (string) ($choice['finish_reason'] ?? '');
        if ($finishReason === 'length') {
            throw new RuntimeException('DeepSeek planner response was truncated: increase PLAN_LLM_PLANNER_*_MAX_TOKENS', 500);
        }

        $content = trim((string) ($choice['message']['content'] ?? ''));
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('DeepSeek planner returned invalid JSON: ' . mb_substr($content, 0, 500, 'UTF-8'), 500);
        }

        return $parsed;
    }

    private function loadUser(int $userId): array
    {
        $user = (new UserRepository($this->db))->getForPlanning($userId);
        if (!is_array($user)) {
            return [];
        }

        foreach (['preferred_days', 'preferred_ofp_days'] as $field) {
            if (!empty($user[$field]) && is_string($user[$field])) {
                $decoded = json_decode($user[$field], true);
                $user[$field] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($user[$field] ?? null)) {
                $user[$field] = [];
            }
        }

        return $user;
    }

    private function buildPlanningUser(array $user, string $jobType, array $payload): array
    {
        $planningUser = $user;
        $startDate = $this->resolveStartDate($user, $jobType, $payload);
        $planningUser['training_start_date'] = $startDate;
        return $planningUser;
    }

    private function resolveStartDate(array $user, string $jobType, array $payload): string
    {
        if (in_array($jobType, ['recalculate', 'next_plan'], true) && !empty($payload['cutoff_date'])) {
            return (string) $payload['cutoff_date'];
        }

        if (!empty($user['training_start_date'])) {
            return (string) $user['training_start_date'];
        }

        return (new DateTimeImmutable('now'))->modify('monday this week')->format('Y-m-d');
    }

    private function resolveWeeksCount(array $state, array $user, string $startDate): int
    {
        if (!empty($state['weeks_to_goal'])) {
            return max(1, min(24, (int) $state['weeks_to_goal']));
        }

        $raceDate = (string) ($state['race_date'] ?? ($user['race_date'] ?? ''));
        if ($raceDate !== '') {
            try {
                $start = new DateTimeImmutable($startDate);
                $race = new DateTimeImmutable($raceDate);
                $days = max(1, (int) $start->diff($race)->format('%r%a') + 1);
                return max(1, min(24, (int) ceil($days / 7)));
            } catch (Throwable $e) {
            }
        }

        return 8;
    }

    /**
     * FACTS_JSON для DeepSeek: профиль + полное тренировочное состояние + контекст сценария +
     * тонкий medical-only hard_rules слой. Ничего не «компактим» (Phase A.5, PR3) — DeepSeek V4
     * имеет 128k context window и сам разбирает большие объекты.
     */
    private function buildPlannerContext(array $user, array $state, array $payload, string $jobType, string $startDate, int $weeksCount): array
    {
        $recentWorkouts = $this->loadRecentWorkouts((int) ($user['id'] ?? 0), $startDate);

        return [
            'today_utc' => gmdate('Y-m-d'),
            'job_type' => $jobType,
            'plan_start_monday' => $startDate,
            'weeks_count' => $weeksCount,
            'calendar_weeks' => $this->buildCalendarWeeks(
                $startDate,
                $weeksCount,
                (string) ($state['race_date'] ?? ($user['race_date'] ?? ''))
            ),
            'payload' => $payload,
            'user' => [
                'goal_type' => $user['goal_type'] ?? null,
                'race_distance' => $user['race_distance'] ?? null,
                'race_date' => $user['race_date'] ?? null,
                'race_target_time' => $user['race_target_time'] ?? null,
                'experience_level' => $user['experience_level'] ?? null,
                'reported_weekly_base_km' => isset($user['weekly_base_km']) ? (float) $user['weekly_base_km'] : null,
                'sessions_per_week' => isset($user['sessions_per_week']) ? (int) $user['sessions_per_week'] : null,
                'preferred_days' => $user['preferred_days'] ?? [],
                'preferred_run_day_numbers' => $this->weekdayNumbers((array) ($user['preferred_days'] ?? [])),
                'preferred_ofp_days' => $user['preferred_ofp_days'] ?? [],
                'health_notes' => $user['health_notes'] ?? null,
                'last_race_distance' => $user['last_race_distance'] ?? null,
                'last_race_time' => $user['last_race_time'] ?? null,
                'last_race_date' => $user['last_race_date'] ?? null,
            ],
            'training_state' => [
                'readiness' => $state['readiness'] ?? null,
                'weeks_to_goal' => $state['weeks_to_goal'] ?? null,
                'weekly_base_km' => $state['weekly_base_km'] ?? null,
                'vdot' => $state['vdot'] ?? null,
                'vdot_confidence' => $state['vdot_confidence'] ?? null,
                'vdot_source' => $state['vdot_source'] ?? null,
                'goal_pace' => $state['goal_pace'] ?? null,
                'training_paces' => $state['formatted_training_paces'] ?? null,
                'pace_rules' => $state['pace_rules'] ?? null,
                // Phase A.7 (PR3): убраны precomputed macrocycle hints (weekly_volume_targets_km,
                // long_run_targets_km, recovery_weeks, start_volume_km, peak_volume_km).
                // DeepSeek строит фазы и кривую объёма сам по weeks_count, weekly_base_km, vdot.
                'load_policy' => $this->stripMacrocyclePrecompute($state['load_policy'] ?? null),
                'feedback_analytics' => $state['feedback_analytics'] ?? null,
                'special_population_flags' => $state['special_population_flags'] ?? null,
            ],
            'planning_scenario' => is_array($state['planning_scenario'] ?? null) ? $state['planning_scenario'] : null,
            'goal_realism' => is_array($state['goal_realism'] ?? null) ? $state['goal_realism'] : null,
            'hard_rules' => $this->buildHardRules($user, $state, $startDate, $recentWorkouts),
            // Phase B.1 (PR3): recent_compliance — последние 4 ISO-недели для оценки нагрузки.
            'recent_compliance' => is_array($state['recent_compliance'] ?? null) ? $state['recent_compliance'] : null,
            // Phase B.2 (PR3): recent_workouts — компактные тренировки за 14 дней с RPE/HR/pace.
            // Если recent_workouts_detailed заполнен (TrainingStateBuilder), используем его;
            // иначе fallback на raw 8-недельный лог (для backwards compat).
            'recent_workouts' => is_array($state['recent_workouts_detailed'] ?? null)
                ? $state['recent_workouts_detailed']
                : $recentWorkouts,
            // Phase B.3 (PR4): season/climate — current_month, hemisphere, season_phase.
            'season' => is_array($state['season'] ?? null) ? $state['season'] : null,
            // Phase B.4 (PR4): best_races_progression — лучшие результаты на 5k/10k/half/marathon за 52 нед.
            'best_races' => is_array($state['best_races'] ?? null) ? $state['best_races'] : null,
        ];
    }

    private function buildCalendarWeeks(string $startDate, int $weeksCount, string $raceDate): array
    {
        try {
            $start = new DateTimeImmutable($startDate);
        } catch (Throwable $e) {
            return [];
        }

        $race = null;
        if ($raceDate !== '') {
            try {
                $race = new DateTimeImmutable($raceDate);
            } catch (Throwable $e) {
                $race = null;
            }
        }

        $weeks = [];
        for ($weekNumber = 1; $weekNumber <= $weeksCount; $weekNumber++) {
            $weekStart = $start->modify('+' . (($weekNumber - 1) * 7) . ' days');
            $days = [];
            for ($dayOfWeek = 1; $dayOfWeek <= 7; $dayOfWeek++) {
                $date = $weekStart->modify('+' . ($dayOfWeek - 1) . ' days');
                $days[] = [
                    'day_of_week' => $dayOfWeek,
                    'date' => $date->format('Y-m-d'),
                    'days_to_race' => $race !== null ? (int) $date->diff($race)->format('%r%a') : null,
                    'is_race_date' => $race !== null && $date->format('Y-m-d') === $race->format('Y-m-d'),
                ];
            }

            $weeks[] = [
                'week_number' => $weekNumber,
                'start_date' => $weekStart->format('Y-m-d'),
                'end_date' => $weekStart->modify('+6 days')->format('Y-m-d'),
                'days' => $days,
            ];
        }

        return $weeks;
    }

    /**
     * Phase A.5 (PR3): hard_rules сокращены до medical-only инвариантов.
     *
     * Оставляем только то, что DeepSeek сам не «угадает» по контексту:
     * - schedule контракт (allowed/required беговые дни, race date/distance);
     * - language contract (текст пользователя — только русский, без английских терминов);
     * - medical guards: marathon long run cap в последние 21 день, fresh_long_effort_guard.
     *
     * Удалены (DeepSeek V4 знает методику сам):
     * - run_types, race_pace_subtype, plain_tempo_rule, race_pace_tempo_rule;
     * - macro_detail_contract (нет staged-стратегии после A.2);
     * - weekly_volume_safety (DeepSeek знает прогрессию объёмов);
     * - race_week_contract (евристический шаблон — мешает trust the model);
     * - long_run_safety.long_share_cap, short_race_long_runs_may_exceed_race_distance.
     */
    private function buildHardRules(array $user, array $state, string $startDate, array $recentWorkouts): array
    {
        $requiredRunDayNumbers = $this->weekdayNumbers((array) ($user['preferred_days'] ?? []));
        $raceDistance = (string) ($state['race_distance'] ?? ($user['race_distance'] ?? ''));
        $raceDistanceKm = $this->resolveRaceDistanceKm($raceDistance);
        $weeklyBaseKm = isset($state['weekly_base_km'])
            ? (float) $state['weekly_base_km']
            : (isset($user['weekly_base_km']) ? (float) $user['weekly_base_km'] : 0.0);
        $recentLongEffortGuard = $this->buildRecentLongEffortGuard($recentWorkouts, $startDate, $raceDistanceKm, $weeklyBaseKm);

        $isMarathonRace = in_array($raceDistance, ['marathon', '42.2k'], true);

        $rules = [
            'required_run_day_numbers' => $requiredRunDayNumbers,
            'allowed_run_day_numbers' => $requiredRunDayNumbers,
            'race_date' => $state['race_date'] ?? ($user['race_date'] ?? null),
            'race_distance' => $raceDistance !== '' ? $raceDistance : null,
            'race_distance_km' => $raceDistanceKm > 0.0 ? $raceDistanceKm : null,
            'goal_pace' => $state['goal_pace'] ?? null,
            'language_contract' => [
                'user_facing_text_fields' => ['notes', 'quality_focus', 'risk_note', 'macro_adjustment_reason'],
                'user_facing_language' => 'ru',
                'forbidden_english_terms_in_user_text' => [
                    'threshold', 'marathon-pace', 'race-pace', 'long run', 'easy run',
                    'warmup', 'cooldown', 'taper', 'recovery', 'MP', 'HMP',
                ],
            ],
        ];

        // Medical guards — только там, где модель может ошибиться с реально опасным паттерном.
        if ($isMarathonRace) {
            $rules['long_run_safety'] = [
                'no_training_run_at_or_above_race_distance_except_race_day' => true,
                'marathon_last_21_days_training_long_run_max_km' => 32.0,
                'no_full_marathon_at_goal_pace_before_race' => true,
            ];
        }

        if ($recentLongEffortGuard !== null) {
            $rules['fresh_long_effort_guard'] = $recentLongEffortGuard;
        }

        return $rules;
    }

    private function buildRecentLongEffortGuard(array $recentWorkouts, string $startDate, float $raceDistanceKm, float $weeklyBaseKm = 0.0): ?array
    {
        try {
            $start = new DateTimeImmutable($startDate);
        } catch (Throwable $e) {
            return null;
        }

        $distanceThreshold = $this->resolveRecentLongEffortThreshold($raceDistanceKm, $weeklyBaseKm);
        $selected = null;
        foreach ($recentWorkouts as $workout) {
            $distanceKm = isset($workout['distance_km']) ? (float) $workout['distance_km'] : 0.0;
            if ($distanceKm < $distanceThreshold) {
                continue;
            }

            $date = (string) ($workout['date'] ?? '');
            if ($date === '') {
                continue;
            }

            try {
                $workoutDate = new DateTimeImmutable($date);
            } catch (Throwable $e) {
                continue;
            }

            $daysBeforeStart = (int) $workoutDate->diff($start)->format('%r%a');
            if ($daysBeforeStart < 0 || $daysBeforeStart > 7) {
                continue;
            }

            if ($selected === null || $distanceKm > (float) ($selected['recent_effort_distance_km'] ?? 0.0)) {
                $weekOneLongMaxKm = round(max(8.0, min(20.0, $distanceKm * 0.45)), 1);
                $selected = [
                    'applies' => true,
                    'recent_effort_date' => $date,
                    'recent_effort_distance_km' => round($distanceKm, 1),
                    'days_before_plan_start' => $daysBeforeStart,
                    'week_1_must_be_recovery' => true,
                    'week_1_quality_allowed' => false,
                    'week_1_long_run_max_km' => $weekOneLongMaxKm,
                    'week_1_volume_guidance' => 'сделай восстановительную неделю после свежего очень длинного забега; не ставь вторую марафонскую длительную сразу следом; длительная недели 1 должна также соблюдать long_share_cap',
                ];
            }
        }

        return $selected;
    }

    private function resolveRecentLongEffortThreshold(float $raceDistanceKm, float $weeklyBaseKm): float
    {
        if ($raceDistanceKm >= 40.0) {
            $raceBased = 30.0;
        } elseif ($raceDistanceKm >= 20.0) {
            $raceBased = 18.0;
        } elseif ($raceDistanceKm >= 9.0) {
            $raceBased = 16.0;
        } elseif ($raceDistanceKm > 0.0) {
            $raceBased = 12.0;
        } else {
            $raceBased = 25.0;
        }

        $baseBased = $weeklyBaseKm > 0.0
            ? min(38.0, max(12.0, $weeklyBaseKm * 0.35))
            : 0.0;

        return round(max($raceBased, $baseBased), 1);
    }

    private function resolveRaceDistanceKm(string $raceDistance): float
    {
        return match ($raceDistance) {
            '5k' => 5.0,
            '10k' => 10.0,
            'half', '21.1k' => 21.1,
            'marathon', '42.2k' => 42.2,
            default => 0.0,
        };
    }

    private function weekdayNumbers(array $days): array
    {
        $map = [
            'mon' => 1,
            'monday' => 1,
            'пн' => 1,
            'tue' => 2,
            'tuesday' => 2,
            'вт' => 2,
            'wed' => 3,
            'wednesday' => 3,
            'ср' => 3,
            'thu' => 4,
            'thursday' => 4,
            'чт' => 4,
            'fri' => 5,
            'friday' => 5,
            'пт' => 5,
            'sat' => 6,
            'saturday' => 6,
            'сб' => 6,
            'sun' => 7,
            'sunday' => 7,
            'вс' => 7,
        ];

        $numbers = [];
        foreach ($days as $day) {
            if (is_numeric($day)) {
                $num = (int) $day;
            } else {
                $key = strtolower(trim((string) $day));
                $num = $map[$key] ?? 0;
            }
            if ($num >= 1 && $num <= 7) {
                $numbers[] = $num;
            }
        }

        $numbers = array_values(array_unique($numbers));
        sort($numbers, SORT_NUMERIC);
        return $numbers;
    }

    // Phase A.5 (PR3): compactPayload, compactLoadPolicy, compactPlanningScenario, compactGoalRealism
    // удалены — передаём full data в FACTS_JSON. DeepSeek V4 знает, какие поля игнорировать.

    /**
     * Phase A.7 (PR3): macrocycle precompute (`weekly_volume_targets_km`, `long_run_targets_km`,
     * `recovery_weeks`, `start_volume_km`, `peak_volume_km`) убран из FACTS_JSON.
     *
     * DeepSeek сам решает, какая неделя восстановительная, и какой ramp применять. Передавать
     * заранее построенные таргеты — значит сужать модель под legacy «слабая LLM ↔ алгоритмический
     * скелет». Остальные поля load_policy (`allowed_growth_ratio`, `easy_min_km`, `long_share_cap`,
     * репэр-floors etc.) сохраняем как hints.
     */
    private function stripMacrocyclePrecompute(?array $loadPolicy): ?array
    {
        if (!is_array($loadPolicy)) {
            return null;
        }
        unset(
            $loadPolicy['weekly_volume_targets_km'],
            $loadPolicy['long_run_targets_km'],
            $loadPolicy['recovery_weeks'],
            $loadPolicy['start_volume_km'],
            $loadPolicy['peak_volume_km']
        );
        return $loadPolicy;
    }

    private function loadRecentWorkouts(int $userId, string $startDate): array
    {
        if ($userId < 1) {
            return [];
        }

        $rows = [];
        $stmt = $this->db->prepare(
            "SELECT wl.training_date AS workout_date,
                    wl.duration_minutes,
                    wl.distance_km,
                    wl.pace AS avg_pace,
                    wl.avg_heart_rate,
                    NULL AS trimp,
                    LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type,
                    'workout_log' AS source
             FROM workout_log wl
             LEFT JOIN activity_types at ON at.id = wl.activity_type_id
             WHERE wl.user_id = ? AND wl.is_completed = 1
               AND wl.training_date >= DATE_SUB(?, INTERVAL 8 WEEK)
             ORDER BY wl.training_date ASC"
        );
        if ($stmt) {
            $stmt->bind_param('is', $userId, $startDate);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if ($this->isRunningRelevantActivity((string) ($row['activity_type'] ?? ''))) {
                    $rows[] = $row;
                }
            }
            $stmt->close();
        }

        $stmt = $this->db->prepare(
            "SELECT DATE(start_time) AS workout_date,
                    duration_minutes,
                    distance_km,
                    avg_pace,
                    avg_heart_rate,
                    trimp,
                    LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type,
                    'workouts' AS source
             FROM workouts
             WHERE user_id = ? AND DATE(start_time) >= DATE_SUB(?, INTERVAL 8 WEEK)
               AND NOT EXISTS (
                   SELECT 1 FROM workout_log wl
                   WHERE wl.user_id = workouts.user_id
                     AND wl.training_date = DATE(workouts.start_time)
                     AND wl.is_completed = 1
               )
             ORDER BY start_time ASC"
        );
        if ($stmt) {
            $stmt->bind_param('is', $userId, $startDate);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if ($this->isRunningRelevantActivity((string) ($row['activity_type'] ?? ''))) {
                    $rows[] = $row;
                }
            }
            $stmt->close();
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => strcmp((string) ($left['workout_date'] ?? ''), (string) ($right['workout_date'] ?? ''))
        );

        return array_map(
            static fn(array $row): array => [
                'type' => $row['activity_type'] ?? null,
                'date' => substr((string) ($row['workout_date'] ?? ''), 0, 10),
                'source' => $row['source'] ?? null,
                'distance_km' => isset($row['distance_km']) ? (float) $row['distance_km'] : null,
                'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
                'avg_pace' => $row['avg_pace'] ?? null,
                'avg_heart_rate' => isset($row['avg_heart_rate']) ? (int) $row['avg_heart_rate'] : null,
                'trimp' => isset($row['trimp']) ? (float) $row['trimp'] : null,
            ],
            $rows
        );
    }

    private function isRunningRelevantActivity(string $activityType): bool
    {
        $type = strtolower(trim($activityType));
        if ($type === '') {
            return true;
        }

        if (preg_match('/walk|walking|hike|hiking|ход|прогул/u', $type)) {
            return false;
        }

        return preg_match('/run|running|race|бег|jog/u', $type) === 1 || $type === 'running';
    }

    private function deriveMacroPlanFromWeeks(array $weeks): array
    {
        $macroWeeks = [];
        foreach ($weeks as $week) {
            if (!is_array($week)) {
                continue;
            }

            $weekNumber = (int) ($week['week_number'] ?? ($week['week'] ?? 0));
            if ($weekNumber < 1) {
                continue;
            }

            $targetVolume = isset($week['target_volume_km'])
                ? round((float) $week['target_volume_km'], 1)
                : $this->sumWeekDistance((array) ($week['days'] ?? []));
            $longRunKm = 0.0;
            foreach ((array) ($week['days'] ?? []) as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $distance = isset($day['distance_km']) ? (float) $day['distance_km'] : 0.0;
                if ((string) ($day['type'] ?? '') === 'long') {
                    $longRunKm = max($longRunKm, $distance);
                }
            }

            if ($longRunKm <= 0.0) {
                foreach ((array) ($week['days'] ?? []) as $day) {
                    if (is_array($day) && (string) ($day['type'] ?? '') !== 'race') {
                        $longRunKm = max($longRunKm, isset($day['distance_km']) ? (float) $day['distance_km'] : 0.0);
                    }
                }
            }

            $macroWeeks[] = [
                'week' => $weekNumber,
                'phase' => (string) ($week['phase'] ?? 'build'),
                'target_volume_km' => $targetVolume,
                'long_run_km' => round($longRunKm, 1),
                'quality_focus' => 'См. детальный календарь недели.',
                'risk_note' => (string) ($week['macro_adjustment_reason'] ?? ''),
            ];
        }

        usort($macroWeeks, static fn(array $left, array $right): int => ((int) $left['week']) <=> ((int) $right['week']));
        return ['weeks' => $macroWeeks];
    }

    private function alignWeekTargetsToCalendar(array $weeks): array
    {
        foreach ($weeks as &$week) {
            if (!is_array($week)) {
                continue;
            }

            $days = (array) ($week['days'] ?? []);
            if ($days === []) {
                continue;
            }

            $actualVolume = $this->sumWeekDistance($days);
            if ($actualVolume > 0.0) {
                $week['target_volume_km'] = $actualVolume;
            }
        }
        unset($week);

        return $weeks;
    }

    private function sumWeekDistance(array $days): float
    {
        $sum = 0.0;
        foreach ($days as $day) {
            if (is_array($day) && isset($day['distance_km'])) {
                $sum += (float) $day['distance_km'];
            }
        }

        return round($sum, 1);
    }

    private function normalizeWeekCollection(array $weeks, int $weeksCount): array
    {
        $byNumber = [];
        foreach ($weeks as $week) {
            $weekNumber = (int) ($week['week_number'] ?? ($week['week'] ?? 0));
            if ($weekNumber < 1 || $weekNumber > $weeksCount) {
                continue;
            }
            $week['week_number'] = $weekNumber;
            $week['days'] = array_values((array) ($week['days'] ?? []));
            $byNumber[$weekNumber] = $week;
        }

        $ordered = [];
        for ($weekNumber = 1; $weekNumber <= $weeksCount; $weekNumber++) {
            if (!isset($byNumber[$weekNumber])) {
                throw new RuntimeException("DeepSeek planner did not return week {$weekNumber}", 500);
            }
            $ordered[] = $byNumber[$weekNumber];
        }

        return ['weeks' => $ordered];
    }

    private function envInt(string $key, int $default, int $min, int $max): int
    {
        $value = filter_var(env($key, $default), FILTER_VALIDATE_INT);
        if ($value === false) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function envBool(string $key, bool $default): bool
    {
        $raw = env($key, $default ? '1' : '0');
        $value = strtolower(trim((string) $raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
