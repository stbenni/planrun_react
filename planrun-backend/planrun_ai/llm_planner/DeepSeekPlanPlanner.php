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
    /** @var array<string,int|null> last LLM call usage metrics (prompt/completion/total/cache_hit/cache_miss) */
    private array $lastUsage = [];

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
            'prompt_version' => 'deepseek_llm_planner_v4_coaching_pace_strategy',
        ];

        return [
            'plan' => $plan,
            'user' => $user,
            'training_state' => $state,
            'start_date' => $startDate,
            'macro_plan' => $macro,
            'planner_context' => $context,
            'usage' => $this->lastUsage,
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
     * PR-C (coaching prompt v4): выбор модели.
     *
     * Тренерский подход: модель всегда «думает» над планом, как реальный тренер. По умолчанию
     * используется `deepseek-reasoner` с `enable_thinking=true` для всех генераций
     * (env `PLAN_LLM_THINKING_ALWAYS=1`, default). Стоимость reasoner-токенов компенсируется
     * заметным ростом качества рассуждения по сложным сценариям (recovery, race-week,
     * intermediate races, return_after_injury).
     *
     * Откат:
     *   - `PLAN_LLM_THINKING_ALWAYS=0` → возврат к старой эвристике auto-эскалации (Phase C.1):
     *     reasoner+thinking только при complexity_score ≥ PLAN_LLM_REASONER_THRESHOLD.
     *   - `PLAN_LLM_AUTO_REASONER=0` И `PLAN_LLM_THINKING_ALWAYS=0` → всегда базовая модель.
     *
     * complexity_score продолжает писаться в metadata для observability.
     */
    public function resolveModelSelection(array $state): array
    {
        $score = $this->computeComplexityScore($state);
        $thinkingAlways = $this->envBool('PLAN_LLM_THINKING_ALWAYS', true);

        if ($thinkingAlways) {
            return [
                'model' => $this->reasonerModel,
                'enable_thinking' => true,
                'timeout_seconds' => $this->reasonerTimeoutSeconds,
                'reason' => 'thinking_always',
                'score' => $score,
            ];
        }

        $threshold = $this->envInt('PLAN_LLM_REASONER_THRESHOLD', 1, 0, 10);
        if ($this->autoReasoner && $score >= $threshold) {
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

    /**
     * PR-C (coaching prompt v4): system prompt про метод тренерского мышления.
     * Не «список правил», а «сначала диагноз → стратегия → календарь».
     */
    private function buildSystemPrompt(): string
    {
        return 'Ты — опытный тренер по бегу. Получив FACTS_JSON о бегуне, '
            . 'сначала внутри себя поставь диагноз (форма, цель, риски, узкое место), '
            . 'затем выбери стратегию (peak volume, периодизация, key workouts, taper), '
            . 'затем разложи план по календарю с учётом фиксированных событий. '
            . 'Применяй базовую физиологию: после соревнования и длительной нужно восстановление, '
            . 'прогресс через адаптацию, recovery weeks обязательны. '
            . 'Возвращай только валидный JSON без markdown, комментариев и <think>. '
            . 'Все человекочитаемые строки внутри JSON — только на русском (notes, plan_summary, risk_review, macro_adjustment_reason, quality_focus, risk_note).';
    }

    /**
     * PR-C (coaching prompt v4): user-prompt максимально короткий — формат ответа,
     * семантические маркеры в calendar_weeks, медицинские границы, и FACTS_JSON.
     *
     * Никаких prose-инструкций «реагируй на signal X так-то», «при compliance 60-89% делай Y»,
     * «sanity-floor вычисляй из MAX/median». Тренер видит факты в FACTS_JSON и решает сам.
     */
    private function buildFullPlanPrompt(array $context): string
    {
        return "Составь календарный план тренировок: якорь пикового недельного км — load_policy.peak_volume_floor_km (±10%, см. ниже); это не жёсткий шаблон, но резать объём «от себя» без медицинских флагов нельзя.\n\n"
            . "Формат ответа:\n"
            . "{\n"
            . "  \"plan_summary\":\"короткое русское резюме логики плана (диагноз+стратегия одной фразой)\",\n"
            . "  \"risk_review\":[\"короткий риск или компромисс по-русски\"],\n"
            . "  \"weeks\":[ ровно weeks_count элементов ]\n"
            . "}\n"
            . "Неделя: {\"week_number\":1,\"phase\":\"base|build|peak|recovery|taper|race\",\"is_recovery\":false,\"target_volume_km\":0,\"macro_adjustment_reason\":null,\"days\":[7 элементов]}. "
            . "target_volume_km должен ≈ сумме distance_km дней.\n"
            . "День: {\"day_of_week\":1,\"type\":\"easy|rest|long|tempo|interval|fartlek|control|race|other|sbu\",\"distance_km\":8.0,\"pace\":\"5:20\",\"duration_minutes\":43,\"warmup_km\":null,\"cooldown_km\":null,\"tempo_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"subtype\":null,\"notes\":\"\"}.\n"
            . "Длительный бег всегда type=long (не easy). Каждая тренировочная неделя (кроме recovery/taper) — ровно 1 long.\n\n"
            . "Структура ответа задана calendar_weeks: возвращай РОВНО weeks_count недель и РОВНО 7 дней в каждой (те же даты, day_of_week 1..7). Маркеры на каждом дне:\n"
            . "- suggested_default: 'race' → type=race; 'rest' → type=rest; 'training' → свободный беговой день; 'keep_or_rest' → прошедший день, ставь rest, не выдумывай.\n"
            . "- race_proximity (семантический ярлык, применяй базовую физиологию):\n"
            . "  • 'race_day' — день старта (главный или промежуточный);\n"
            . "  • 'pre_race_day_minus_1' — день перед стартом (короткий лёгкий бег ≤8 км или отдых);\n"
            . "  • 'pre_race_taper' — за 2-5 дней до старта (без интервалов и длительной);\n"
            . "  • 'post_race_recovery_day_1' — сразу после старта (отдых или короткий восстановительный бег);\n"
            . "  • 'post_race_recovery_day_2' — +2 дня после старта (без длительной и интервалов).\n"
            . "- is_race_date / is_intermediate_race / days_to_race — фиксированные точки.\n\n"
            . "Медицина и coaching-инварианты (hard_rules — нарушать нельзя):\n"
            . "- Уважай required_run_day_numbers / allowed_run_day_numbers; отступаешь — объясни в macro_adjustment_reason.\n"
            . "- Hard/easy alternation: между двумя key workouts (long, tempo, interval, fartlek, control, race, race_pace) — минимум 1 день easy или rest. Подряд два таких дня — травма.\n"
            . "- Taper по дистанции главного старта:\n"
            . "  • marathon — 3 нед. taper: предпоследняя ~70% peak, перед-предпоследняя ~55% peak, race-week 30-40% peak без quality;\n"
            . "  • half — 2 нед. taper: предпоследняя ~70% peak, race-week 40-50% peak без quality;\n"
            . "  • 5k/10k — 1 нед. taper: race-week ~50% peak, можно 1 короткое разминочное.\n"
            . "- Special populations: при planning_scenario.flags ⊃ {return_after_injury, pain_protective, illness_protective, recent_pain_signal, recent_illness_signal} — первые 3 недели НИ интервалов, НИ tempo (только easy + лёгкая длительная); возвращение к quality постепенно с 4-й недели.\n"
            . "- Pregnancy/postpartum: never high-intensity quality; объёмы низкие, фокус на easy.\n"
            . "- Peak weekly volume: целься в load_policy.peak_volume_floor_km ± 10%. Этот floor уже учитывает реальную историю и compliance — он реалистичен. Если в recent_compliance есть недели с очень высоким объёмом или поле load_policy.historical_peak_weekly_km показывает прежний пик ≥ 80% от reported_base — это доказательство, что бегун может держать peak на уровне base, и нечего опираться только на средний свежий объём после race-recovery. Идти заметно ниже floor допустимо только при медицинском флаге (return_after_injury, illness, pregnancy) или явном risk_review-объяснении. Brutal cutting объёма «на всякий случай» при здоровом бегуне — главная причина недогруза перед marathon/half.\n"
            . "- Peak long (пиковая длительная за 2-3 недели до главной race): 5k → 12-15км; 10k → 14-18км; half → 19-24км; marathon → 28-32км (для первой марафонской цели — 26-30км). Идти ниже допустимо только для очень низкой базы или травмы.\n"
            . "- Long share: длительная не должна превышать 35% от недельного объёма. Если получается выше — добавь easy/recovery бег в другие дни (но не quality).\n"
            . "- Long progression и cutback: длительная растёт постепенно (≤ +2 км/нед в base/build), и каждые 3-4 недели идёт явная разгрузка длительной (-25..-40%) вместе с recovery week.\n"
            . "- Соблюдай long_run_safety (предельные длительные перед марафоном) и fresh_long_effort_guard (восстановительная неделя после свежего очень длинного забега).\n"
            . "- Темпы pace в днях ставятся по training_state.pace_strategy: tempo ≈ goal_paces.threshold, interval ≈ goal_paces.interval, easy/long ≈ goal_paces.easy, marathon-pace runs ≈ goal_paces.marathon. Race_pace в день забега = pace_strategy.effective_target_pace. Если pace_strategy.mode = realistic_target — цель из профиля недостижима за один цикл, план ведёт к pace_strategy.effective_target_time, но tempo/interval всё равно по goal_paces (это мост к цели). Если pace_strategy отсутствует — используй training_state.training_paces.\n"
            . "- Marathon-specific: при marathon goal в build/peak обязательны marathon-pace runs (8–15 км в темпе pace_strategy.effective_target_pace) — отдельной тренировкой или MP-сегментом в финале long run.\n"
            . "- ОФП (силовые/функциональные тренировки type='other'): если user.preferred_ofp_days не пустой И user.ofp_preference != 'none' — ОБЯЗАТЕЛЬНО ставь type='other' в эти дни (минимум 1-2 ОФП-дня в неделю). Это injury prevention, особенно для marathon-prep. Не оставляй preferred_ofp_days как rest. Описание: «силовые/функциональные 30-45 мин (приседания, выпады, планка, тяги)». ОФП НЕ занимает беговой слот — добавляется в rest-дни.\n"
            . "- forbidden_english_terms_in_user_text — не используй в notes/plan_summary.\n\n"
            . "Recovery weeks: при горизонте ≥6 недель каждые 3-4 недели прогрессии — разгрузка 75-85% от предыдущего объёма (is_recovery=true, длительная тоже короче). Без них растёт риск травмы.\n\n"
            . "Темпы и структура: для простого бега pace применяется ко всей тренировке, duration_minutes согласуй с distance_km. Для interval/tempo заполни warmup_km, cooldown_km, tempo_km, reps/interval_m/interval_pace/rest_m/rest_type. Для fartlek заполни segments. Если работа около целевого темпа гонки — subtype=race_pace.\n\n"
            . "Язык: plan_summary, risk_review, macro_adjustment_reason, notes — только на русском, без английских тренировочных терминов.\n\n"
            . "FACTS_JSON:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nСегодня: " . gmdate('Y-m-d') . " (UTC)";
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

        // Capture usage metrics so caller can persist them to ai_plan_generation_events.
        $usage = is_array($json['usage'] ?? null) ? (array) $json['usage'] : [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'prompt_cache_hit_tokens', 'prompt_cache_miss_tokens'] as $key) {
            $this->lastUsage[$key] = isset($usage[$key]) && is_numeric($usage[$key]) ? (int) $usage[$key] : null;
        }

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

    /**
     * Returns last LLM call usage metrics for observability logging.
     *
     * @return array<string,int|null>
     */
    public function getLastUsage(): array
    {
        return $this->lastUsage;
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
        // Cap raised to 30 to fit full marathon blocks where race_date is 25-28 weeks out;
        // previously 24 caused the race day to fall outside the plan horizon.
        $maxWeeks = $this->envInt('PLAN_LLM_MAX_WEEKS', 30, 8, 52);

        if (!empty($state['weeks_to_goal'])) {
            return max(1, min($maxWeeks, (int) $state['weeks_to_goal']));
        }

        $raceDate = (string) ($state['race_date'] ?? ($user['race_date'] ?? ''));
        if ($raceDate !== '') {
            try {
                $start = new DateTimeImmutable($startDate);
                $race = new DateTimeImmutable($raceDate);
                $days = max(1, (int) $start->diff($race)->format('%r%a') + 1);
                return max(1, min($maxWeeks, (int) ceil($days / 7)));
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

        // NOTE: today_utc is intentionally NOT included here — it would break DeepSeek's
        // prefix cache by changing daily. It is appended as a stable suffix in buildFullPlanPrompt
        // (after FACTS_JSON), so the JSON body itself stays cache-stable across the day.
        return [
            'job_type' => $jobType,
            'plan_start_monday' => $startDate,
            'weeks_count' => $weeksCount,
            'calendar_weeks' => $this->buildCalendarWeeks(
                $startDate,
                $weeksCount,
                (string) ($state['race_date'] ?? ($user['race_date'] ?? '')),
                array_column($state['intermediate_races'] ?? [], 'date'),
                $this->weekdayNumbers((array) ($user['preferred_days'] ?? [])),
                $jobType
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
                // PR9: pace_strategy — «мост к цели».
                // Содержит mode (realistic_target | goal_target), effective_target_time,
                // gap_pct, goal_paces (Daniels от целевого VDOT), current_paces.
                // Hard-rules ниже отсылают модель к pace_strategy.goal_paces вместо
                // training_paces (current), чтобы tempo/interval тянулись к цели,
                // а не закреплялся уровень текущей формы.
                'pace_strategy' => is_array($state['pace_strategy'] ?? null)
                    ? $state['pace_strategy']
                    : null,
                // Phase A.7 (PR3): убраны precomputed macrocycle hints (weekly_volume_targets_km,
                // long_run_targets_km, recovery_weeks, start_volume_km, peak_volume_km).
                // DeepSeek строит фазы и кривую объёма сам по weeks_count, weekly_base_km, vdot.
                'load_policy' => $this->stripMacrocyclePrecompute($state['load_policy'] ?? null),
                'feedback_analytics' => $state['feedback_analytics'] ?? null,
                'special_population_flags' => $state['special_population_flags'] ?? null,
                'intermediate_races' => !empty($state['intermediate_races']) ? $state['intermediate_races'] : null,
            ],
            'planning_scenario' => is_array($state['planning_scenario'] ?? null) ? $state['planning_scenario'] : null,
            'goal_realism' => is_array($state['goal_realism'] ?? null) ? $state['goal_realism'] : null,
            'hard_rules' => $this->buildHardRules($user, $state, $startDate, $recentWorkouts),
            // Phase B.1 (PR3): recent_compliance — последние 4 ISO-недели для оценки нагрузки.
            'recent_compliance' => is_array($state['recent_compliance'] ?? null) ? $state['recent_compliance'] : null,
            // PR-A (coaching prompt v4): тренерский саммари по фактам compliance — одна
            // короткая русская фраза. Тренер прочтёт и решит как реагировать; никаких enum/recommendation.
            'recent_compliance_summary' => isset($state['recent_compliance_summary']) && $state['recent_compliance_summary'] !== ''
                ? (string) $state['recent_compliance_summary']
                : null,
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

    /**
     * Skeleton всех weeks_count×7 дней для DeepSeek. Каждый день размечен:
     *  - date, day_of_week, days_to_race
     *  - is_race_date / is_intermediate_race — фиксированные точки
     *  - is_run_day — попадает ли в preferred_days пользователя (на off-day обычно ставим rest)
     *  - is_past — для recalculate: день уже прошёл (today позже даты), модель не должна
     *    «улучшать» прошлые дни, только заполнить как rest или (если был race) сохранить
     *
     * Цель — DeepSeek получает готовую структуру и НЕ ПРОПУСКАЕТ дни, как было в баге
     * с partial weeks (4-5 дней вместо 7).
     */
    private function buildCalendarWeeks(
        string $startDate,
        int $weeksCount,
        string $raceDate,
        array $intermediateRaceDates = [],
        array $preferredRunDayNumbers = [],
        string $jobType = 'generate'
    ): array {
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

        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $isRecalc = in_array($jobType, ['recalculate', 'next_plan'], true);
        $hasPreferredDays = !empty($preferredRunDayNumbers);

        // PR-B (coaching prompt v4): собираем все race-даты (главная + intermediate) для
        // вычисления race_proximity ярлыка на каждом дне.
        $allRaceDates = [];
        if ($race !== null) {
            $allRaceDates[] = $race->format('Y-m-d');
        }
        foreach ($intermediateRaceDates as $d) {
            $d = (string) $d;
            if ($d !== '' && !in_array($d, $allRaceDates, true)) {
                $allRaceDates[] = $d;
            }
        }

        $weeks = [];
        for ($weekNumber = 1; $weekNumber <= $weeksCount; $weekNumber++) {
            $weekStart = $start->modify('+' . (($weekNumber - 1) * 7) . ' days');
            $days = [];
            for ($dayOfWeek = 1; $dayOfWeek <= 7; $dayOfWeek++) {
                $date = $weekStart->modify('+' . ($dayOfWeek - 1) . ' days');
                $dateStr = $date->format('Y-m-d');
                $isPast = $isRecalc && $dateStr < $today;
                $isRunDay = $hasPreferredDays ? in_array($dayOfWeek, $preferredRunDayNumbers, true) : true;
                $isRace = $race !== null && $dateStr === $race->format('Y-m-d');
                $isIntermediate = in_array($dateStr, $intermediateRaceDates, true);

                $days[] = [
                    'day_of_week' => $dayOfWeek,
                    'date' => $dateStr,
                    'days_to_race' => $race !== null ? (int) $date->diff($race)->format('%r%a') : null,
                    'is_race_date' => $isRace,
                    'is_intermediate_race' => $isIntermediate,
                    'is_run_day' => $isRunDay,
                    'is_past' => $isPast,
                    'suggested_default' => $this->suggestDayDefault($isRace, $isIntermediate, $isRunDay, $isPast),
                    // PR-B: семантический ярлык для модели — она применяет физиологию сама.
                    'race_proximity' => $this->resolveRaceProximity($dateStr, $allRaceDates),
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
     * PR-B (coaching prompt v4): семантический ярлык близости к ближайшему race-дню.
     *
     * Возвращает один из ярлыков (без constraints — модель сама применяет физиологию):
     *   - "race_day" — день старта (главный или intermediate);
     *   - "pre_race_day_minus_1" — день перед race;
     *   - "pre_race_taper" — за 2-5 дней до race;
     *   - "post_race_recovery_day_1" — день сразу после race;
     *   - "post_race_recovery_day_2" — +2 дня после race;
     *   - null — никакой race-близости.
     *
     * Если день одновременно «после одного race» и «перед другим» (тур intermediate races
     * подряд), приоритет даём race_day → pre_race_day_minus_1 → post_race_recovery_day_1
     * → pre_race_taper → post_race_recovery_day_2 → null.
     *
     * @param string $dateStr Дата дня в формате Y-m-d.
     * @param string[] $allRaceDates Все race-даты в горизонте (главная + intermediate).
     */
    private function resolveRaceProximity(string $dateStr, array $allRaceDates): ?string
    {
        if (empty($allRaceDates)) {
            return null;
        }

        try {
            $day = new DateTimeImmutable($dateStr);
        } catch (Throwable $e) {
            return null;
        }

        $candidates = [];
        foreach ($allRaceDates as $raceDateStr) {
            try {
                $raceDate = new DateTimeImmutable((string) $raceDateStr);
            } catch (Throwable $e) {
                continue;
            }
            $diff = (int) $day->diff($raceDate)->format('%r%a');
            // diff > 0 → race в будущем, diff < 0 → race уже прошёл, diff === 0 → race-day
            if ($diff === 0) {
                return 'race_day';
            }
            $candidates[] = $diff;
        }

        if (empty($candidates)) {
            return null;
        }

        // Сначала проверяем «день перед race» — высший приоритет после race_day
        foreach ($candidates as $diff) {
            if ($diff === 1) {
                return 'pre_race_day_minus_1';
            }
        }

        // День после race — следующий приоритет
        foreach ($candidates as $diff) {
            if ($diff === -1) {
                return 'post_race_recovery_day_1';
            }
        }

        // Pre-race taper (2..5 дней до race)
        foreach ($candidates as $diff) {
            if ($diff >= 2 && $diff <= 5) {
                return 'pre_race_taper';
            }
        }

        // Post-race recovery day 2 (+2 дня после race)
        foreach ($candidates as $diff) {
            if ($diff === -2) {
                return 'post_race_recovery_day_2';
            }
        }

        return null;
    }

    /**
     * Подсказка по умолчанию: если день не race и не run_day — модель должна ставить rest.
     */
    private function suggestDayDefault(bool $isRace, bool $isIntermediate, bool $isRunDay, bool $isPast): string
    {
        if ($isRace || $isIntermediate) return 'race';
        if (!$isRunDay) return 'rest';
        if ($isPast) return 'keep_or_rest'; // Прошедший день: не выдумывай тренировку, можно rest
        return 'training'; // Свободно для тренерского решения (easy/long/tempo/interval)
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
