# Backend AI-пайплайн 1/2 (генерация плана) — справочник функций

Актуально на 2026-06-09. Покрытие: генерация/пересчёт тренировочных планов через DeepSeek LLM, нормализация, self-critique, ревизия, ОФП-обогащение, рецензия, сохранение в БД.

## `planrun-backend/planrun_ai/create_empty_plan.php` (88 строк)
Создание пустого календаря тренировок для режима самостоятельных тренировок (без AI).

### `createEmptyPlan($userId, $startDate, $endDate = null)` — L17
Удаляет старый план пользователя (`training_plan_days`, `training_plan_weeks`), выравнивает старт на понедельник и вставляет N недель (12 по умолчанию или до `$endDate`) по 7 пустых дней с `type='free'`. Пишет напрямую в БД через `getDBConnection()`; бросает Exception при ошибках вставки. Внутри репозитория нигде не вызывается (вероятный мёртвый код).

## `planrun-backend/planrun_ai/description_parser.php` (116 строк)
Парсер текстовых описаний ОФП/СБУ-дней в структурированные упражнения «как в чате».

### `parseOfpSbuDescription(string $description, string $type): array` — L9
Разбирает многострочный description на упражнения: для СБУ — «Название — 30 м», для ОФП — «Название — 3×10, 20 кг» или «1 мин»; есть fallback для одноабзацного формата «Силовые: приседания, выпады (3 подхода по 12)». Возвращает массив структур {name, sets, reps, weight_kg, duration_sec, distance_m, notes}. Чистая функция без побочных эффектов; используется нормализатором и `WorkoutService`.

## `planrun-backend/planrun_ai/llm_planner/DeepSeekPlanPlanner.php` (1300 строк)
Production-планировщик планов на DeepSeek (single-pass, coaching prompt v4): собирает FACTS_JSON (профиль + training state + календарный скелет + hard rules), вызывает LLM через `LlmGateway` и возвращает план недель.

### class `DeepSeekPlanPlanner` — L18

#### `__construct(mysqli $db)` — L37
Читает конфигурацию из env (`PLAN_LLM_BASE_URL/MODEL/MAX_TOKENS/TIMEOUT_SECONDS`, reasoner-настройки `PLAN_LLM_REASONER_MODEL/AUTO_REASONER/REASONER_TIMEOUT_SECONDS`) с legacy-fallback'ами, берёт API-ключ через `LlmGateway::apiKey('plan')`.

#### `setObservabilityContext(?string $traceId, ?int $userId, string $surface)` — L72 (public)
Сохраняет trace_id/user_id/surface для логирования LLM-запросов в observability (передаются в `LlmGateway::requestChatCompletion`).

#### `generate(int $userId, string $jobType = 'generate', array $payload = []): array` — L80 (public)
Главная точка входа: грузит юзера (`UserRepository`), строит `TrainingStateBuilder`-state по режиму (generate/recalculate/next_plan), считает weeks_count, собирает контекст, выбирает модель (reasoner/base), делает LLM-вызов `generateFullPlan()`, выравнивает target_volume по факту дней, выводит macro_plan и проверяет полноту недель. Возвращает {plan (+`_generation_metadata`), user, training_state, start_date, macro_plan, planner_context, usage}.

#### `generateFullPlan(array $context, ?array $modelSelection): array` — L132 (private)
Строит full-plan промпт и делает `requestJson()` с выбранной моделью/таймаутом/thinking-режимом.

#### `resolveModelSelection(array $state): array` — L167 (public)
Выбор модели: при `PLAN_LLM_THINKING_ALWAYS=1` (default) — всегда `deepseek-reasoner` с thinking; иначе auto-эскалация на reasoner при complexity_score ≥ `PLAN_LLM_REASONER_THRESHOLD`; иначе базовая модель. Возвращает {model, enable_thinking, timeout_seconds, reason, score}.

#### `regenerateWeeks(array $context, array $existingPlan, array $weekNumbersToRedo, array $issueHints = []): array` — L216 (public)
Targeted retry после quality-gate failure: переотправляет LLM только 1–4 проблемные недели с issue-хинтами и контекстом существующего плана; валидирует, что все запрошенные week_number вернулись. LLM-вызов через `requestJson()` (базовая модель).

#### `applyRegeneratedWeeks(array $existingPlan, array $regeneratedWeeks): array` — L261 (public)
Мерджит регенерированные недели в существующий план по week_number (поддерживает оба формата `weeks_data.weeks` и `weeks`), пересчитывает target_volume и пишет `_generation_metadata.targeted_retry`.

#### `buildTargetedRetryPrompt(array $context, array $existingPlan, array $weekNumbersToRedo, array $issueHints): string` — L308 (private)
Собирает промпт targeted retry: компактная структура всех недель (фазы/объёмы/is_to_redo), отфильтрованный calendar_weeks только по перевыдаваемым неделям, hard_rules и список проблем.

#### `computeComplexityScore(array $state): int` — L369 (public)
Считает число одновременных факторов риска: sensitive population flags (беременность, возврат после травмы, боль, болезнь), high-risk scenario flags (b_race, short_runway и т.п.), goal_realism=major. Используется для выбора reasoner-модели и пишется в metadata.

#### `buildSystemPrompt(): string` — L405 (private)
System-промпт «опытный тренер»: метод диагноз → стратегия → календарь, только валидный JSON, все пользовательские строки на русском.

#### `buildFullPlanPrompt(array $context): string` — L424 (private)
User-промпт полной генерации: формат ответа (weeks[].days[7]), семантика маркеров calendar_weeks (suggested_default, race_proximity), медицинские/coaching-инварианты (taper по дистанции, hard/easy чередование, peak volume floor, peak long, long share ≤35%, ОФП-дни, языковой контракт), затем FACTS_JSON и текущая дата UTC (вне JSON ради prefix-cache DeepSeek).

#### `requestJson(string $model, string $prompt, int $maxTokens, int $timeoutSeconds, bool $enableThinking, bool $allowLengthRetry = true): array` — L471 (private)
Выполняет chat-completion через `LlmGateway::requestChatCompletion` (response_format=json_object, temperature 0.2), сохраняет usage-метрики в `$lastUsage`. При finish_reason=length однократно ретраит с max_tokens×1.6 (cap 65536); бросает RuntimeException при пустом ключе/обрезке/невалидном JSON.

#### `loadUser(int $userId): array` — L532 (private)
Загружает профиль через `UserRepository::getForPlanning` и декодирует JSON-поля `preferred_days`/`preferred_ofp_days` в массивы.

#### `buildPlanningUser(array $user, string $jobType, array $payload): array` — L551 (private)
Копия юзера с подменённым `training_start_date` = resolveStartDate (для TrainingStateBuilder).

#### `resolveStartDate(array $user, string $jobType, array $payload): string` — L559 (private)
Дата старта плана: cutoff_date из payload (recalculate/next_plan) → training_start_date юзера → понедельник текущей недели.

#### `resolveWeeksCount(array $state, array $user, string $startDate): int` — L572 (private)
Число недель: `state.weeks_to_goal`, иначе расчёт от race_date, иначе 8; cap `PLAN_LLM_MAX_WEEKS` (default 30 — чтобы марафонские блоки 25–28 недель помещались).

#### `buildPlannerContext(array $user, array $state, array $payload, string $jobType, string $startDate, int $weeksCount): array` — L601 (private)
Собирает FACTS_JSON: job_type, calendar_weeks-скелет, профиль юзера, training_state (readiness, VDOT, pace_strategy «мост к цели», load_policy без macrocycle-precompute), planning_scenario, goal_realism, hard_rules, recent_compliance(+summary), recent_workouts (detailed или 8-недельный SQL-лог), season, best_races.

#### `buildCalendarWeeks(string $startDate, int $weeksCount, string $raceDate, array $intermediateRaceDates, array $preferredRunDayNumbers, string $jobType): array` — L698 (private)
Скелет всех weeks_count×7 дней: date, day_of_week, days_to_race, is_race_date/is_intermediate_race, is_run_day (по preferred_days), is_past (для recalculate), suggested_default и race_proximity-ярлык. Гарантирует, что LLM не пропустит дни.

#### `resolveRaceProximity(string $dateStr, array $allRaceDates): ?string` — L793 (private)
Семантический ярлык близости к ближайшему старту: race_day / pre_race_day_minus_1 / pre_race_taper (2–5 дн.) / post_race_recovery_day_1 / _2 / null, с приоритетом при наложении нескольких стартов.

#### `suggestDayDefault(bool $isRace, bool $isIntermediate, bool $isRunDay, bool $isPast): string` — L858 (private)
Дефолт дня: race → 'race', не run_day → 'rest', прошедший → 'keep_or_rest', иначе 'training'.

#### `buildHardRules(array $user, array $state, string $startDate, array $recentWorkouts): array` — L881 (private)
Medical-only hard rules: required/allowed run-day numbers, race date/distance/goal_pace, языковой контракт (запрещённые англицизмы в user-тексте), для марафона — long_run_safety (cap 32 км в последний 21 день), плюс fresh_long_effort_guard при свежем сверхдлинном забеге.

#### `buildRecentLongEffortGuard(array $recentWorkouts, string $startDate, float $raceDistanceKm, float $weeklyBaseKm): ?array` — L927 (private)
Если за ≤7 дней до старта плана был забег длиннее порога — возвращает guard: неделя 1 обязательно восстановительная, без quality, long ≤ 45% от дистанции усилия (8–20 км).

#### `resolveRecentLongEffortThreshold(float $raceDistanceKm, float $weeklyBaseKm): float` — L977 (private)
Порог «очень длинного» усилия: по целевой дистанции (30/18/16/12/25 км) и по базе (35% от weekly_base, 12–38 км), берётся максимум.

#### `resolveRaceDistanceKm(string $raceDistance): float` — L998 (private)
Маппинг кода дистанции (5k/10k/half/marathon/…) в километры; 0.0 если неизвестно.

#### `weekdayNumbers(array $days): array` — L1009 (private)
Преобразует список дней (eng/рус коды или числа) в отсортированный уникальный массив 1..7.

#### `stripMacrocyclePrecompute(?array $loadPolicy): ?array` — L1065 (private)
Удаляет из load_policy предрасчитанные macrocycle-таргеты (weekly_volume_targets_km, long_run_targets_km, recovery_weeks, start/peak_volume_km) — DeepSeek строит кривую объёма сам.

#### `loadRecentWorkouts(int $userId, string $startDate): array` — L1080 (private)
SQL-выборка тренировок за 8 недель до старта: `workout_log` (is_completed=1) + `workouts` (дедуп по дате через NOT EXISTS), фильтр беговых активностей, сортировка по дате, маппинг в компакт-структуры {type, date, source, distance_km, duration_minutes, avg_pace, avg_heart_rate, trimp}.

#### `isRunningRelevantActivity(string $activityType): bool` — L1163 (private)
true для бег/run/race/jog (пустой тип = true), false для walk/hike/ходьба.

#### `deriveMacroPlanFromWeeks(array $weeks): array` — L1177 (private)
Производит macro_plan из ответа модели: по каждой неделе — phase, target_volume_km (или сумма дней), long_run_km (max long-дня, fallback — самый длинный не-race день), risk_note из macro_adjustment_reason.

#### `alignWeekTargetsToCalendar(array $weeks): array` — L1226 (private)
Заменяет target_volume_km каждой недели на фактическую сумму distance_km дней (если > 0).

#### `sumWeekDistance(array $days): float` — L1248 (private)
Сумма distance_km дней недели, округление до 0.1.

#### `normalizeWeekCollection(array $weeks, int $weeksCount): array` — L1260 (private)
Индексирует недели по week_number, отбрасывает вне диапазона 1..weeksCount, бросает RuntimeException если какая-то неделя отсутствует; возвращает упорядоченный `{weeks: [...]}`.

#### `envInt(string $key, int $default, int $min, int $max): int` — L1284 (private)
Чтение int из env с clamp в [min, max].

#### `envBool(string $key, bool $default): bool` — L1294 (private)
Чтение bool из env (1/true/yes/on).

## `planrun-backend/planrun_ai/ofp_enricher.php` (273 строки)
Шаг 3 поэтапной генерации: обогащение готового бегового плана персонализированными ОФП/СБУ-сессиями отдельным LLM-вызовом; при провале caller использует template-fallback.

### `enrichPlanWithOfp(array $planData, array $user, array $exerciseLibrary, int $userId): ?array` — L14
Собирает target-даты ОФП (preferred_ofp_days юзера ∩ rest/free/пустые other-sbu дни плана) с контекстом нагрузки недели, добавляет историю весов из `ExecutedExerciseService::getRecentHistoryForUser` (executed_exercises за 8 нед.), делает LLM-вызов через `LlmGateway::requestChatCompletion` (surface=ofp_enricher) и валидирует ответ против exercise_library (галлюцинированные упражнения отбрасываются, веса/sets бэкфиллятся библиотечными default'ами). Возвращает {date: [exercises]} или null (фича-флаг `OFP_ENRICHER_ENABLED`, нет ОФП-предпочтений, ошибка LLM, пустой результат).

### `ofpEnricherSummariseWeekLoad(array $days): string` — L232
Классифицирует неделю по дням плана в человекочитаемую метку для промпта: RACE-WEEK (marathon/обычный) / high load (peak) / medium load (build) / low load (recovery), с км и числом quality-тренировок.

### `ofpEnricherUserBlock(array $user): string` — L262
Текстовый блок профиля атлета для промпта: username, level, вес/рост/возраст, ofp_location, target-дистанция, health notes (до 100 симв.).

## `planrun-backend/planrun_ai/plan_critique_generator.php` (492 строки)
Self-critique pass: независимый LLM-«оппонент» ревьюит сгенерированный план; при critical/should_revise — точечная LLM-ревизия с sanity-проверкой против overcorrection. Флаги: `PLAN_CRITIQUE_ENABLED`, `PLAN_CRITIQUE_MAX_REVISIONS`.

### `runPlanSelfCritique(array $planData, array $user, array $context, int $userId): ?array` — L32
Строит summary плана (`buildPlanSummaryForReview`, обрезка до 8000 симв.), блоки атлета и истории, делает LLM-вызов (surface=plan_critique, temperature 0.2, json_object) с промптом «независимый тренер» (каноны марафонской подготовки, ОФП-проверка, severity-шкала). Парсит ответ через `repairAndParseCritiqueJson` и нормализует структуру {severity, should_revise, summary, issues[], strengths[]}; null при выключенном флаге/ошибке.

### `repairAndParseCritiqueJson(string $content): ?array` — L167
Robust-парсер JSON от LLM: снятие markdown-fences → извлечение первого `{...}` → удаление trailing commas → комбинация; null при полном провале. Также переиспользуется ofp_enricher'ом.

### `revisePlanWithCritique(array $planData, array $critique, array $user, array $context, int $userId, string $mode = 'ПЕРЕСЧЁТ'): ?array` — L201
Точечная LLM-ревизия плана по issues критика: промпт с обязательными инвариантами (1 long/неделю, прогрессия длительных, race-week, неприкосновенность type='race', цель/число тренировок не менять) и scoped-hint по затронутым неделям. Ответ парсится через `repairAndParseCritiqueJson` с fallback на `parseAndRepairPlanJSON`; отклоняется при отсутствии weeks или провале `validateRevisedPlan`. Возвращает обновлённый planData или null (caller оставляет исходный план).

### `validateRevisedPlan(array $originalPlan, array $revisedPlan): ?string` — L351
Sanity-check ревизии: race-дни не удалены (manual user-input), сохранено ≥60% длительных, в race-week ≥2 тренировочных дня кроме старта, ни одна неделя не срезана более чем вдвое по тренировочным дням. Возвращает текст проблемы или null. Содержит 3 inline-замыкания-счётчика (raceDays, countLongs, countWeeklyTrainingDays).

### `buildAthleteBlockForCritique(array $user, array $context): string` — L441
Текстовый блок атлета для critique-промпта: профиль, ОФП-предпочтения (с требованием type='other' дней), цель/последний старт, ACWR, compliance за 2 недели, средний объём, причина пересчёта.

### `buildHistoryBlockForCritique(array $context): string` — L472
Блок истории для промпта из `plan_history_rollup` (понедельные объёмы) и `plan_key_workouts` (ключевые/значимые тренировки).

## `planrun-backend/planrun_ai/plan_generator.php` (1251 строка)
Legacy-оркестратор генерации через «PlanRun AI API» (localhost:8000): полная генерация, сплит на чанки, пересчёт с историей, следующий план; плюс общий JSON-repair и corrective-regeneration хелперы для eval/тестов.

### `isPlanRunAIConfigured()` — L19
true если `USE_PLANRUN_AI` и API на порту 8000 отвечает (`isPlanRunAIAvailable`, HTTP-проба).

### `generatePlanViaPlanRunAI($userId)` — L31
Полная генерация: SELECT профиля из `users`, декод JSON-полей, при >16 недель — `generateSplitPlan`, иначе `buildTrainingPlanPrompt` (prompt_builder.php) → `callAIAPI` → `parseAndRepairPlanJSON` → `applyCritiquePassToPlanData`. Возвращает planData; Exception при недоступном API/юзере.

### `applyCritiquePassToPlanData(array $planData, array $user, int $userId, string $mode): array` — L117
Универсальная обёртка critique для generate/next: собирает мини-контекст (rollup и key workouts из `WorkoutAnalysisRepository`, ACWR из `ChatContextBuilder`), запускает `runPlanSelfCritique`, при should_revise — `revisePlanWithCritique`; результат критики кладёт в `_generation_metadata.critique`. Все ошибки глотает (план возвращается как есть).

### `generateSplitPlan(array $user, string $goalType, array $chunks, int $userId): array` — L169
Сплит-генерация длинного плана: для каждого чанка `buildPartialPlanPrompt` (с последней неделей предыдущего чанка как контекст) → `callAIAPI` → parse, перенумерация week_number в абсолютную, мердж всех недель, финальная перенумерация, `validatePlanStructure` и critique-pass.

### `parseAndRepairPlanJSON(string $response, int $userId): array` — L250
Repair-пайплайн ответа LLM: прямой json_decode → срез ```json-fences и текста вокруг {…} → голый массив недель ([{days:…}] оборачивается в {weeks}) → фиксы trailing commas/одинарных кавычек. При успехе всегда прогоняет `validatePlanStructure`; иначе Exception с json_last_error.

### `validatePlanStructure(array $planData, int $userId): array` — L310
Структурная валидация: у каждой недели есть days, каждый день — объект; неизвестные типы (вне allowed-списка, включая алиасы easy_run/ofp/marathon) принудительно → rest. Варнинги в error_log.

### `recalculatePlanViaPlanRunAI($userId, $userReason = null)` — L350
Пересчёт: cutoff = понедельник текущей недели; считает сохранённые недели (`WeekRepository::getMaxWeekNumberBefore`), сколько генерировать (по цели/`getSuggestedPlanWeeks`, cap 30); собирает большой recalc-контекст из SQL (summary сохранённых недель, max плановая длительная из `training_day_exercises`, последние 3 недели плана) и `ChatContextBuilder` (recent workouts, compliance, 4-недельные средние, детренированность `calculateDetrainingFactor`, ACWR, лучшая фактическая длительная), фазу — `detectCurrentPhase`. Затем `buildRecalculationPrompt` → `callAIAPI` → parse → inline critique/revision. Возвращает {plan, cutoff_date, kept_weeks}.

### `generateNextPlanViaPlanRunAI($userId, $userGoals = null)` — L707
Новый план после завершения старого: агрегирует ПОЛНУЮ историю тренировок за период старого плана (объёмы по неделям, peak/avg, прогрессия первая vs последняя четверть, лучшие long/tempo/interval, key workout results, форма за 4 недели, compliance), строит `buildNextPlanPrompt` с новой датой старта (понедельник) и числом недель по цели (cap 30) → `callAIAPI` → parse → `applyCritiquePassToPlanData`. Сохраняется потом через `saveTrainingPlan` (полная замена).

### `detectCurrentPhase(array $userData, string $goalType, int $keptWeeks): ?array` — L969
Определяет текущую фазу макроцикла: `computeMacrocycle()` (prompt_builder.php) + сопоставление недели keptWeeks+1 с фазами. Возвращает {phase, phase_label, weeks_into/left, next_phase, remaining_phases, long_run_progression, recovery_weeks, control_weeks, peak/start_volume_km} или null.

### `decodeGeneratedPlanResponse(string $response, array $userData, string $sourceLabel = 'PlanRun AI'): array` — L1044
Parse+repair ответа и сверка числа недель с `plan_skeleton`: меньше ожидаемого — Exception, больше — обрезка. Используется только corrective-пайплайном (eval-скрипт/тесты).

### `buildPlanValidationContext(array $userData): array` — L1063
Контекст валидации из userData/training_state: goal_type, preferred_days, sessions_per_week, expected_skeleton.

### `buildTrainingStateForValidation(array $userData): array` — L1072
training_state c бэкфиллом ключевых полей (goal_type, race_distance, goal_pace*, preferred_days, sessions_per_week, plan_intent_contract, load_policy) из плоского userData.

### `normalizePlanGenerationUserFields(array $user): array` — L1093
Гарантирует, что preferred_days/preferred_ofp_days — массивы (json_decode строк, иначе []).

### `hydratePlanGenerationUserState(mysqli $db, array $user): array` — L1105
Нормализует поля юзера и прикрепляет `training_state` через `TrainingStateBuilder::buildForUser`. Используется только eval-скриптом.

### `attachPlanSkeleton(array $user, string $goalType, array $options = []): array` — L1112
Прикрепляет `plan_skeleton` через `PlanSkeletonBuilder::build`. Используется только eval-скриптом.

### `normalizeGeneratedPlanForValidation(array $plan, array $user, string $startDate, int $weekNumberOffset = 0): array` — L1119
Полный нормализационный конвейер для валидации: `normalizeTrainingPlan` + все четыре applyTrainingState*-repair'а (pace, workout detail, load, minimum distance). Используется eval-скриптом.

### `buildCorrectiveRegenerationPrompt(string $basePrompt, array $validationIssues, array $planData): string` — L1133
Базовый промпт + блок «VALIDATION FAILURE» с issue-строками + JSON проблемного плана.

### `maybeApplyCorrectiveRegenerationToPlan(...)` — L1155
Однократная корректирующая регенерация: нормализует план, собирает issues (`collectNormalizedPlanValidationIssues`, plan_validator.php), при `shouldRunCorrectiveRegeneration` — повторный LLM-вызов с corrective-промптом; принимает исправленный план только если `scoreValidationIssues` не хуже. Пишет `_generation_metadata` (repair_count, corrective_regeneration_used). В production-пайплайне не вызывается (только eval-скрипт и тесты).

### `isRunningRelevantWorkoutEntry(array $workout): bool` — L1222
true если запись тренировки релевантна бегу: distance>0, source=manual → true; walking/hiking → false; running/treadmill → true; иначе по plan_type из беговых типов. Используется `PlanGenerationProcessorService`.

### `resolveRecalculationCutoffDateValue(string $today, bool $hasRunningWorkoutToday): string` — L1245
Дата-граница mutable-зоны пересчёта: сегодня, либо завтра, если сегодня уже была беговая тренировка. Используется `PlanGenerationProcessorService`.

## `planrun-backend/planrun_ai/plan_normalizer.php` (1803 строки)
Чистый (без БД) нормализатор сырого JSON-плана от LLM: типы, даты, дистанции, description, упражнения, enforcement расписания, repair-пайплайны темпов/объёмов/структуры тренировок по training_state.

### const `PLAN_TYPE_LABELS` — L12
Русские названия простых беговых типов для description.

### const `PLAN_REST_TYPE_MAP` — L23
Маппинг rest_type (jog/walk/rest) в русские слова для описаний интервалов/фартлека.

### `parsePaceToSeconds(?string $pace): ?int` — L32
"M:SS" → секунды на км; null при невалидном формате.

### `formatPaceFromSec(int $sec): string` — L43
Секунды → "M:SS".

### `formatDurationHMS(int $totalSec): string` — L50
Секунды → "Ч:ММ:СС".

### `calculateDurationMinutes(?float $distKm, ?string $pace): ?int` — L60
Длительность (мин) из дистанции и темпа; null если данных нет.

### `calculateIntervalTotalKm(array $day): float` — L70
Суммарная дистанция интервальной: warmup + reps×(interval_m+rest_m) + cooldown.

### `calculateFartlekTotalKm(array $day): float` — L82
Суммарная дистанция фартлека: warmup + cooldown + по сегментам reps×(distance_m+recovery_m).

### `normalizeFartlekSegments(mixed $segments): array` — L95
Нормализует сегменты фартлека (включая camelCase-алиасы accelDistM/recoveryDistM/accelPace), отбрасывает мусор (reps<1, distance<50 м), дефолт recovery_type='jog'.

### `hasUsableFartlekSegments(array $day): bool` — L129
true если после нормализации остался хотя бы один сегмент.

### `ensureFartlekWorkoutStructure(array $day): array` — L133
Если сегментов нет — подставляет дефолтную структуру 8×400 м/200 м трусцой с warmup 2 / cooldown 1.5 км и note об этом.

### `buildDescriptionFromFields(array $day): string` — L162
Собирает текстовый description из структурированных полей по типу: простой бег («X км · Ч:ММ:СС / Темп»), интервалы («Разминка… N×Mм в темпе…, пауза…»), фартлек (по сегментам), ОФП/СБУ (построчно из exercises); формат совместим с regex в AddTrainingModal.jsx. rest/free → ''.

### `formatPlanDistanceKm(float $distanceKm): string` — L288
Форматирует км без хвостовых нулей («8», «8.5»).

### `noteLooksLikeNumericRunPrescription(string $notes): bool` — L293
true если notes выглядит как числовое предписание (км/мин/темп/N×M) — такие notes дублируют поля и подлежат очистке.

### `sanitizeRunNotesForDescription(array $day, string $type): string` — L302
Для easy/long/walking/race вычищает «числовые» notes (чтобы не дублировались в description); иначе возвращает notes как есть.

### `refreshRunNotesAfterDistanceChange(array $day): array` — L316
После изменения дистанции пересобирает notes: для tempo — «Разминка X. Темповый отрезок Y в темпе/около целевого. Заминка Z», для control — аналогично, для easy/long/race — обнуляет числовые notes.

### const `PLAN_TYPE_MAP` — L367
Маппинг сырых типов LLM (easy_run, long-run, ofp, marathon, walk, recovery_walk…) в канонические.

### const `PLAN_ALLOWED_TYPES` — L390
12 допустимых канонических типов дня.

### const `PLAN_KEY_WORKOUT_TYPES` — L392
Типы, по умолчанию считающиеся ключевыми (interval, tempo, long, fartlek, race, control).

### const `PLAN_RUN_TYPES` — L394
Беговые типы (easy, long, tempo, interval, fartlek, race, control).

### const `PLAN_DAY_KEYS` — L396
Индекс 0–6 → коды mon..sun.

### const `PLAN_DAY_KEY_TO_INDEX` — L406
Обратный маппинг mon..sun → 0–6.

### const `PLAN_REST_RUN_KEYWORDS` — L416
Regex беговых ключевых слов для детекции «rest с беговым описанием».

### `normalizeTrainingType(?string $rawType): string` — L424
Канонизация типа через PLAN_TYPE_MAP/PLAN_ALLOWED_TYPES; неизвестное → 'rest'.

### `normalizePreferredDayKeys(array $days): array` — L438
Нормализует дни недели из любых алиасов (eng full/short, рус полные/краткие) в уникальные коды mon..sun, упорядоченные по неделе.

### `normalizeSkeletonDayType(?string $rawType): string` — L487
Тонкая обёртка над `normalizeTrainingType` для типов из скелета.

### `isRunTypeForSchedule(string $type): bool` — L491
Принадлежность типа к PLAN_RUN_TYPES (используется schedule_validator).

### `resolveIsKeyWorkout(array $day, string $type): bool` — L499
is_key_workout: явное значение LLM (bool/строки/числа), иначе фолбэк по PLAN_KEY_WORKOUT_TYPES.

### `resolveRunDistanceSafetyNet(string $type): float` — L511
Минимальная разумная дистанция: easy 1.5, tempo 2.5, прочее 3.0 км.

### `normalizeTrainingDay(array $day, string $computedDate, int $dayOfWeek): array` — L527
Нормализация одного дня: канонизация типа, принудительная вычисленная дата (даты LLM игнорируются), расчёт дистанции/длительности (interval/fartlek — из структуры), rest с дистанцией/беговым описанием → easy, long ≤0 км → rest, санити-чеки дистанций (0.5–60 км) и темпа (2:30–10:00), safety-net для коротких easy/tempo, извлечение км из description при отсутствии поля, пересборка description, генерация exercises (run-запись или ОФП/СБУ из `parseOfpSbuDescription`), плюс структурированные поля (warmup/cooldown/reps/segments/notes/subtype).

### `rebuildNormalizedDayArtifacts(array $day): array` — L716
Пересборка производных артефактов уже нормализованного дня после правок: distance (для interval/fartlek — пересчёт), duration, description, is_key_workout, run-exercise. Используется и plan_saver'ом.

### `retargetNormalizedDay(array $day, string $date, int $dayOfWeek): array` — L783
Переназначает дату/день недели и пересобирает артефакты.

### `retargetWeekDays(array $days, DateTime $weekStartDate): array` — L789
Перепривязывает все дни недели к датам от понедельника (индекс = смещение).

### `createCoercedSkeletonDay(array $day, string $expectedType): array` — L801
Принудительно приводит день к ожидаемому скелетом типу: rest — обнуляет всё; easy/long/tempo — дефолтные дистанции (6/16/8 км); quality-типы — is_key_workout=true.

### `alignWeekDaysToSkeleton(array $days, array $skeletonDays): array` — L837
Выравнивает дни недели под скелет: сначала ищет swap-кандидата с нужным типом, иначе coerce через `createCoercedSkeletonDay`.

### `resolvePreferredLongRunIndex(array $preferredDays, ?int $raceIndex = null): ?int` — L881
Индекс дня для длительной: приоритет вс → сб из preferred_days (исключая день race), иначе максимальный preferred-индекс.

### `moveLongRunToPreferredIndex(array $days, ?int $targetIndex): array` — L909
Свапает long-день на предпочитаемый индекс недели.

### `repairAdjacentKeyWorkouts(array $days): array` — L933
Разносит два подряд идущих hard-key дня (tempo/interval/fartlek/control/long): второй свапается с ближайшим последующим не-key/не-race днём.

### `calculateNormalizedWeekVolume(array $days): float` — L961
Сумма distance_km дней недели (>0), округление до 0.1.

### `normalizeTrainingPlan(array $rawPlan, string $startDate, int $weekNumberOffset = 0, ?array $preferences = null, ?array $expectedSkeleton = null): array` — L983
Главная функция нормализации плана: по каждой неделе вычисляет понедельник, нормализует дни (`normalizeTrainingDay`), применяет enforcement расписания (беговые вне preferred_days → rest, кроме race; ОФП вне preferred_ofp_days → rest), выравнивание под skeleton, перенос long на preferred-день, разнос смежных key (если нет скелета), retarget дат, пересчёт объёма. Возвращает {weeks (с phase/is_recovery/target_volume_km/total_volume), warnings}; бросает InvalidArgumentException без weeks.

### `updateRunExercisePace(array &$day): void` — L1162
Синхронизирует pace/notes run-упражнения дня с полями дня (по ссылке).

### `updateSimpleRunDayAfterDistanceChange(array $day): array` — L1179
Композитный апдейт после изменения дистанции/темпа: refresh notes → rebuild artifacts → sync run-exercise.

### `applyTrainingStatePaceRepairs(array $normalized, array $trainingState): array` — L1186
Repair темпов по pace_rules (производные VDOT): бэкфилл отсутствующих pace (easy/long — середина диапазона, tempo — пороговый или goal-specific, race — race_pace), clamp easy/long в диапазон, коррекция tempo при отклонении сверх tolerance (с расширенным допуском для целевого темпа `resolveGoalSpecificTempoPaceTargetSec`), бэкфилл interval_pace.

### `applyControlWorkoutFallback(array $day, array $trainingState): array` — L1262
Дефолты для control-дня: дистанция 8 км (марафонская цель) или 5 км, pace=null, стандартный notes про разминку/контрольный отрезок/заминку.

### `ceilToTenth(float $value): float` — L1283
Округление вверх до 0.1.

### `roundToHalf(float $value): float` — L1287
Округление до 0.5.

### `trimDaysByType(array &$days, array $types, float &$excess, callable $floorResolver): void` — L1291
Срезает излишек недельного объёма с дней заданных типов (от самых длинных), не опускаясь ниже floor; уменьшает $excess по ссылке, дни апдейтятся через `updateSimpleRunDayAfterDistanceChange`.

### `rebalanceLongShareWithinWeek(array &$days, array $loadPolicy, int $weekNumber): void` — L1324
Если длительная превышает long_share_cap (адаптивный: 0.40–0.52 в зависимости от объёма и числа беговых дней) — срезает long до cap и раскидывает разницу по easy-дням.

### `resolveEasyRepairFloorKm(array $loadPolicy, int $weekNumber, bool $containsRace): float` — L1398
Floor для easy при срезании: taper-min (race-неделя) / recovery-min (recovery-неделя) / easy_min_km (default 3.0).

### `resolveTempoRepairFloorKm(array $loadPolicy): float` — L1412
Floor для tempo/control (default 3.0).

### `resolveLongRepairFloorKm(array $loadPolicy): float` — L1416
Floor для long (default 6.0).

### `resolveRaceWeekSupplementaryCap(float $weekBeforeVolume, array $trainingState): float` — L1420
Cap дополнительного (не-race) объёма race-недели: доля от предыдущей недели по дистанции цели (marathon 0.35 / half 0.45 / прочее 0.60), минимум 6 км.

### `applyTrainingStateLoadRepairs(array $normalized, array $trainingState): array` — L1434
Repair объёмов по load_policy: лимит недели = race-week формула (race + supplementary cap) / pre-race taper ratio / macrocycle-таргет (только race-focused цели) / sanity-cap роста (1.10 race-focused, 1.20 health/weight_loss); излишек срезается каскадом easy → tempo/control → long (с floor по таргетам) → easy, затем `rebalanceLongShareWithinWeek`. Варнинги о коррекции в warnings.

### `applyTrainingStateMinimumDistanceRepairs(array $normalized, array $trainingState): array` — L1577
Поднимает слишком короткие easy до floor (easy_build_min_km / taper / recovery в зависимости от недели), пересчитывает объёмы недель.

### `findWeekIntentContract(array $trainingState, int $weekNumber, string $type): ?array` — L1624
Ищет контракт недели в plan_intent_contract (с учётом week_number_offset) и, если есть, мерджит запись по типу тренировки.

### `applyTrainingStateWorkoutDetailFallbacks(array $normalized, array $trainingState): array` — L1647
Достраивает неполные quality-тренировки: tempo без структуры → warmup 2/cooldown 1.5/дистанция ≥6–8 км с темпом (целевым при goal_pace_specific-контракте); control → `applyControlWorkoutFallback`; fartlek без сегментов → 8×400; interval без reps/interval_m/паузы → 4×2000 (марафон) или 4×1000, либо 4×400 при race_execution-теме. Варнинги в warnings.

### `findNormalizedPlanRaceWeekNumber(array $normalizedPlan): ?int` — L1735
Номер первой недели, содержащей race-день, или null (используется workout_completeness_validator).

### `resolveGoalPaceSecFromTrainingState(array $trainingState): ?int` — L1747
Целевой темп (сек/км): goal_pace_sec → parse goal_pace → training_paces.marathon → null.

### `resolveGoalSpecificTempoPaceTargetSec(array $day, array $trainingState, int $weekNumber): ?int` — L1764
Определяет, что tempo-день — «в целевом темпе» (subtype=race_pace, intent-контракт goal_pace_specific, или текст notes/description с MP/HMP/goal pace/«целевой темп»/«марафонский темп»), и возвращает целевой темп; только для half/marathon целей. Используется также pace_validator.

## `planrun-backend/planrun_ai/plan_review_generator.php` (483 строки)
Генерация человекочитаемой русской рецензии плана через LLM (после сохранения — пост в чат пользователя), с жёсткой пост-обработкой языка и тона.

### `buildPlanSummaryForReview(array $planData, string $startDate): string` — L19
Нормализует план и строит текстовый листинг «Неделя N: Пн: Тип — описание [ключевая]» для промпта. Переиспользуется critique-генератором.

### `generatePlanReview(array $planData, string $startDate, string $mode = 'ГЕНЕРАЦИЯ', ?array $realismContext = null): ?string` — L71
LLM-вызов (surface=plan_review): system-промпт «AI-тренер» с запретом канцелярита/англицизмов и правилами про race/control дни; факты из `buildPlanReviewFacts` + опциональный realism-блок (PR9: честное сообщение, под какой таргет реально готовит план). Результат прогоняется через `sanitizePlanReviewContent`, обрезка до 4000 симв.; null при ошибке.

### `buildRealismFactsForReview(?array $realism): string` — L170
Сухой facts-блок про реалистичность цели (дистанция, цель профиля, прогноз, фактический таргет плана, gap%, severity); '' при severity none/нет данных.

### `buildRealismDirectiveForReview(?array $realism): string` — L213
Дополнительное правило для system-промпта при moderate/major: отразить «Контекст по цели» в первой фразе ответа; '' иначе.

### `buildPlanReviewFacts(array $planData, string $startDate): string` — L225
Факты по race/control дням нормализованного плана (даты, км, темпы) + директивы трактовки («race — это сам старт», «control перед race — проверка формы»).

### `sanitizePlanReviewContent(string $content, array $planData, string $startDate): string` — L277
Если в плане есть race-день: режет текст на предложения, удаляет запрещённые (`isForbiddenRaceReviewSentence`), при удалении добавляет корректную замыкающую фразу, затем словарные замены и `polishPlanReviewTone`.

### `isForbiddenRaceReviewSentence(string $sentence, ?float $raceDistanceKm = null): bool` — L321
Детектор вредных формулировок: «удвоение дистанции», описание race-дистанции как длительной/подготовки к марафону/наращивания объёма, «активация» главного старта.

### `applyPlanReviewLanguageReplacements(string $content): string` — L360
strtr-замены англицизмов и жаргона (tune-up, taper, quality, recovery, «тейпер»…) на русские формулировки.

### `polishPlanReviewTone(string $content): string` — L385
Финальная полировка: нормализация пробелов, regex-замены канцелярских оборотов, дедупликация предложений по категориям (`detectPlanReviewSentenceCategory`), лимит 5 предложений, разбивка на 2 абзаца.

### `detectPlanReviewSentenceCategory(string $sentence): string` — L463
Категория предложения для дедупликации: overview / control / race / recovery / support / other.

## `planrun-backend/planrun_ai/planrun_ai_config.php` (38 строк)
Конфигурация подключения к «PlanRun AI API» (локальный RAG-сервис генерации) из .env.

### const `PLANRUN_AI_API_URL` — L13
URL API (default http://localhost:8000/api/v1/generate-plan).

### const `PLANRUN_AI_TIMEOUT` — L16
Таймаут запросов в секундах (default 300).

### const `USE_PLANRUN_AI` — L19
Флаг включения PlanRun AI.

### `isPlanRunAIAvailable()` — L24
HTTP HEAD-проба URL (curl, таймаут 5 с); true при HTTP 200 или 405.

## `planrun-backend/planrun_ai/planrun_ai_integration.php` (164 строки)
HTTP-клиент к PlanRun AI API (DeepSeek+RAG) с ретраями.

### `resolvePlanRunAIMaxTokens(array $userData): int` — L9
max_tokens по числу недель в plan_skeleton: ≥14 нед → 20000, ≥10 → 16000, иначе 12000; cap `PLANRUN_AI_MAX_TOKENS_HARD_LIMIT` (default 32768).

### `callPlanRunAIAPI($prompt, $userData, $maxRetries = 3, $userId = null)` — L41
POST на PLANRUN_AI_API_URL: {user_data, user_id, goal_type, include_knowledge (RAG), temperature 0.3, max_tokens, base_prompt}; до 3 попыток с экспоненциальной задержкой для timeout/Connection/5xx. Возвращает JSON-строку плана из result['plan']; Exception при отключённом флаге/недоступности/невалидном ответе.

### `callAIAPI($prompt, $userData, $maxRetries = 3, $userId = null)` — L152
Тонкая обёртка: повторяет те же проверки USE_PLANRUN_AI/`isPlanRunAIAvailable` и делегирует в `callPlanRunAIAPI` (дублирование проверок; единая точка вызова для plan_generator и скриптов).

## `planrun-backend/planrun_ai/plan_saver.php` (458 строк)
Сохранение нормализованного плана в БД (training_plan_weeks → training_plan_days → training_day_exercises) в транзакции; полная замена либо пересчёт с сохранением прошлых недель.

### const `SAVER_ALLOWED_TYPES` — L18
Допустимые типы дня при записи в БД (дублирует PLAN_ALLOWED_TYPES нормализатора).

### `planStructureLooksNormalized($planData): bool` — L27
Структурная проверка для фаст-пути alreadyNormalized (#80): каждая неделя ровно 7 дней, у дня есть строковый type, числовой day_of_week, дата YYYY-MM-DD. При провале caller форсирует полную нормализацию.

### `saverWriteNormalizedWeeks($db, int $userId, array $weeks, string $logPrefix): void` — L60
Общий insert-цикл (#81, DRY из двух сейверов): INSERT недель (с phase), дней (невалидный тип → rest) и упражнений (run-ветка и ofp/sbu-ветка с разными SQL). Вызывается внутри открытой транзакции caller'а; Exception при провале insert'ов.

### `saveTrainingPlan($db, $userId, $planData, $startDate, ?array $userPreferences = null, bool $alreadyNormalized = false)` — L163
Полная замена плана: нормализация (или фаст-путь при alreadyNormalized + проверке структуры), транзакция: DELETE старых exercises/days/weeks → `saverWriteNormalizedWeeks` → commit; rollback и rethrow при ошибке. Инвалидирует кэш `training_plan_{userId}`.

### `saveRecalculatedPlan($db, $userId, array $newPlanData, string $cutoffDate, ?array $userPreferences = null, ?string $mutableFromDate = null, bool $alreadyNormalized = false)` — L222
Пересчёт: прошлые недели (до cutoff, `WeekRepository::getMaxWeekNumberBefore`) сохраняются; новые недели нормализуются (или при alreadyNormalized — только перенумерация/start_date/объём, без двойного прогона репэров); при mutableFromDate > cutoff прошедшие дни текущей недели сохраняются (`loadPreservedRecalculationDays` + merge). Транзакция: удаление будущих exercises/days/weeks (по `getFutureWeekIds`) → insert → commit; инвалидация кэша.

### `loadPreservedRecalculationDays(mysqli $db, int $userId, string $cutoffDate, string $mutableFromDate): array` — L325
SELECT дней [cutoff, mutableFrom) с их упражнениями из БД и реконструкция в нормализованный формат дня (distance/duration/pace из run-упражнения) через `rebuildNormalizedDayArtifacts`, сортировка по дате.

### `mergePreservedDaysIntoRecalculatedWeek(array $week, array $preservedDays, string $mutableFromDate): array` — L426
Мерджит сохранённые прошедшие дни с будущими днями (date ≥ mutableFrom) первой пересчитанной недели, сортирует и пересчитывает total_volume/actual/target.
