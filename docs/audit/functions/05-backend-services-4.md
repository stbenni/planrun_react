# Backend services 4/6 (PlanQualityGate…StatsService) — справочник функций

## `planrun-backend/services/PlanQualityGate.php` (789 строк)
Финальный quality gate над сгенерированным AI-планом: нормализует план тем же контрактом normalizer/validator, что используется перед сохранением, собирает issues (errors/warnings), применяет детерминированные repair-ы и blocking policy. Подключает `planrun_ai/plan_normalizer.php` и `planrun_ai/plan_validator.php`. Потребитель: `PlanGenerationProcessorService` (и `planrun_ai/plan_saver.php`).

### class PlanQualityGate — L10
Stateless-оценщик качества плана; БД не трогает, работает только с переданными массивами.

#### `evaluate(array $plan, string $startDate, array $trainingState = [], array $context = []): array` — L12
Главная точка входа: нормализует план (`normalizeTrainingPlan`), строит baseline-оценку, опционально применяет детерминированные repair-ы и выбирает лучший вариант, применяет blocking policy. Возвращает status (ok/warning/blocked), normalized_plan, issues, score, флаги should_block_save / should_run_corrective_regeneration / repairs_applied.

#### `buildEvaluation(array $normalizedPlan, array $trainingState, array $context): array` — L52 (private)
Собирает полный набор issues для плана: базовые валидационные (`collectNormalizedPlanValidationIssues`), сценарные, контрактные LLM-планировщика, goal feasibility; затем понижает severity для protective-сценариев, фильтрует под сценарий и сортирует. Возвращает план + issues + score + has_errors + status.

#### `applyDeterministicRepairs(array $normalizedPlan, array $trainingState): array` — L74 (private)
Применяет цепочку детерминированных repair-функций из plan_normalizer: pace repairs, workout detail fallbacks, load repairs, minimum-distance repairs (с повторным прогоном load repair после minimum-distance, т.к. он может снова поднять недельный объём за cap).

#### `isCandidateBetter(array $baseline, array $candidate): bool` — L87 (private)
Сравнивает baseline- и repaired-оценку лексикографически по кортежу (has_errors, score, count issues); true если кандидат строго лучше.

#### `planHash(array $plan): string` — L103 (private)
md5 от JSON-сериализации плана — для определения, изменили ли repair-ы план фактически.

#### `collectScenarioIssues(array $normalizedPlan, array $trainingState, array $context): array` — L108 (private)
Проверяет соблюдение tune-up event из planning_scenario: событие должно попасть в горизонт плана и быть отражено в неделе; при флаге `b_race_before_a_race` требует тип control, запрещает длительную и лишние quality-дни в tune-up неделе; warning при нескольких race/control днях в одной неделе.

#### `collectLlmPlannerContractIssues(array $normalizedPlan, array $trainingState, array $context): array` — L223 (private)
Агрегатор: объединяет issues от `collectUserFacingLanguageIssues`, `collectMacroDetailConsistencyIssues`, `collectLongRunSafetyIssues`, `collectFreshLongEffortIssues`.

#### `collectUserFacingLanguageIssues(array $normalizedPlan, array $context): array` — L233 (private)
Ищет английские тренировочные термины (threshold, taper, long run и т.д.) в пользовательских полях notes/description дней плана и quality_focus/risk_note macro-недель; severity error для LLM-планировщика, иначе warning.

#### `collectMacroDetailConsistencyIssues(array $normalizedPlan, array $context): array` — L280 (private)
Сверяет недельный объём и длительную календаря с macro-планом (target_volume_km, long_run_km): расхождение сверх допуска (5% / 3 км) даёт warning, большое (15% / 8 км; long >5 км) — error. Допускает осознанный пересмотр detail-планировщиком с указанной причиной (`macro_adjustment_reason`), иначе warning `macro_detail_adjusted_without_reason`. Пропускается при planner_strategy=single_pass.

#### `collectLongRunSafetyIssues(array $normalizedPlan, array $trainingState, array $context): array` — L363 (private)
Безопасность длительных: доля длительной от недельного объёма не выше long_share_cap (из load_policy или hard rules, дефолт 0.45); запрет тренировочной длительной на/выше дистанции гонки; для марафона — длительная не более 38 км и не более 32 км в последние 21 день перед стартом.

#### `collectFreshLongEffortIssues(array $normalizedPlan, array $context): array` — L440 (private)
Проверки для guard «свежий очень длинный забег перед стартом плана» (fresh_long_effort_guard из hard rules): неделя 1 должна быть восстановительной, без quality-работ, длительная не больше week_1_long_run_max_km (дефолт 24 км).

#### `collectGoalFeasibilityIssues(array $trainingState): array` — L493 (private)
Транслирует goal_realism-вердикт из training state (unrealistic/challenging/caution) в до 3 warning-issues с текстами сообщений; при пустом списке сообщений ставит дефолтное предупреждение о слишком амбициозной цели.

#### `downgradeProtectiveScenarioIssues(array $issues, array $trainingState, array $context): array` — L553 (private)
Для protective-сценариев (readiness=low или флаги high_caution / return_after_* / overload_recovery / pain|illness_protective) понижает `weekly_volume_spike` с error до warning.

#### `filterIssuesForScenario(array $issues, array $normalizedPlan, array $trainingState, array $context): array` — L584 (private)
Удаляет issues `missing_run_on_required_day` в неделях, где контракт обязательных дней осознанно ослаблен: relaxed-флаги сценария, force_initial_recovery_week / fresh-long-effort recovery на неделе 1, race-неделя или пост-race неделя с capped днями.

#### `findWeek(array $plan, int $weekNumber): ?array` — L637 (private)
Находит неделю плана по week_number или null.

#### `weekContainsType(?array $week, string $type): bool` — L648 (private)
true если в неделе есть день с указанным нормализованным типом.

#### `plannerHardRules(array $context): array` — L663 (private)
Возвращает context['planner_hard_rules'] либо context['hard_rules'] либо пустой массив.

#### `maxDayDistanceByType(?array $week, string $type): float` — L676 (private)
Максимальная distance_km среди дней недели заданного типа, округлённая до 0.1.

#### `containsForbiddenEnglishTrainingText(string $text): bool` — L693 (private)
Regex-детектор английских тренировочных терминов (threshold, marathon pace, long run, taper, mp/hmp и др.).

#### `daysBetween(string $fromDate, string $toDate): ?int` — L701 (private)
Знаковая разница в днях между датами; null при невалидных датах.

#### `resolveRaceDistanceKm(string $raceDistance): float` — L713 (private)
Маппинг ключа дистанции (5k/10k/half/marathon/21.1k/42.2k) в километры; 0.0 для неизвестных.

#### `sortIssues(array $issues): array` — L724 (private)
Сортирует issues: сначала errors, затем по week_number, затем по коду.

#### `hasErrors(array $issues): bool` — L750 (private)
true если среди issues есть хотя бы один с severity=error.

#### `resolveBlockingPolicy(array $context): string` — L761 (private)
Нормализует context['blocking_policy'] до 'strict' или 'permissive' (дефолт strict).

#### `applyBlockingPolicy(array $issues, string $policy): array` — L767 (private)
В permissive-режиме понижает все error-issues до warning (с пометкой `downgraded_by_permissive_llm_gate`), кроме фатальных кодов invalid_week_day_count и schedule_skeleton_mismatch.

## `planrun-backend/services/PlanReadinessCheckService.php` (487 строк)
Readiness-чекины перед пересчётом плана: если у юзера был «застарелый» болевой сигнал (pain followup ≥7 дней назад и были пробежки после), создаёт pending-вопрос «как сейчас состояние?», принимает ответ и интерпретирует его (clear/mild_clear/protective). Таблица `plan_readiness_checkins`. Потребители: `TrainingPlanService` (maybeCreatePendingCheck/submitAnswer), `TrainingStateBuilder` (getLatestValidAnswer).

### class PlanReadinessCheckService extends BaseService — L6
Константы: TABLE (L7), STATUS_PENDING (L8), STATUS_ANSWERED (L9), STATUS_DISMISSED (L10), CHECK_STALE_PAIN (L11).

#### `ensureSchema(): void` — L13
CREATE TABLE IF NOT EXISTS `plan_readiness_checkins` со всеми колонками и индексами; RuntimeException при неудаче. Запись в БД (DDL).

#### `maybeCreatePendingCheck(int $userId, string $jobType = 'recalculate', array $payload = []): ?array` — L45
Гасит просроченные pending-чеки, ищет последний stale-болевой сигнал; если на него нет валидного ответа и нет свежего pending — INSERT нового чека с вопросом на русском. Возвращает публичный payload чека или null. Читает post_workout_followups/workouts/workout_log, пишет в plan_readiness_checkins.

#### `submitAnswer(int $userId, int $checkId, array $answer): array` — L106
Валидирует ответ (pain score 0-10 явный или распарсенный из текста, обязательные boolean «усиливалась боль» и «менялась техника»), интерпретирует (`interpretAnswer`), вычисляет valid_until (10 дней для clear/mild_clear, 5 для protective) и UPDATE-ит запись. Бросает 400/404/422 при ошибках. Возвращает saved + interpretation + can_generate_more_effective_plan + публичный payload.

#### `getLatestValidAnswer(int $userId): ?array` — L169
Последний answered-чек с непросроченным valid_until; возвращает null, если после source_date ответа появился новый болевой сигнал (за 21 день). Чтение plan_readiness_checkins + post_workout_followups.

#### `findLatestStalePainSignal(int $userId): ?array` — L204 (private)
Последний болевой followup в окне PLAN_READINESS_CHECK_PAIN_LOOKBACK_DAYS (дефолт 21), возраст ≥ PLAN_READINESS_CHECK_MIN_PAIN_AGE_DAYS (дефолт 7) и минимум 1 пробежка после. Добавляет days_since_signal и subsequent_run_count.

#### `findLatestPainSignal(int $userId, int $lookbackDays): ?array` — L226 (private)
SELECT последнего completed followup с pain_flag=1 из post_workout_followups (JOIN chat_messages для текста ответа); предварительно вызывает `PostWorkoutFollowupService::ensureSchema()`.

#### `countRunsAfterDate(int $userId, string $sourceDate): int` — L251 (private)
Считает пробежки после даты: COUNT по workouts (activity_type=running, distance>0) + COUNT по workout_log (is_completed=1, distance>0).

#### `hasValidAnswerForSource(int $userId, string $sourceDate): bool` — L291 (private)
Есть ли answered-чек с source_date ≥ заданной и непросроченным valid_until.

#### `findPendingCheckForSource(int $userId, string $sourceDate): ?array` — L315 (private)
Pending-чек по точному source_date, созданный за последние 2 дня.

#### `findCheckById(int $userId, int $checkId): ?array` — L339 (private)
SELECT чека по id+user_id.

#### `dismissExpiredPendingChecks(int $userId): void` — L351 (private)
UPDATE: pending-чеки старше 2 дней переводит в dismissed.

#### `toPublicCheckPayload(array $check): array` — L369 (private)
Преобразует строку БД в публичный payload: id/status/check_type/question + блоки source (дата, days_ago, summary, pain_score) и answer (scores, interpretation, valid_until).

#### `buildPainSignalSummary(array $painSignal): string` — L395 (private)
Краткая строка по сигналу: «боль N/10; риск восстановления X; <обрезанный текст ответа до 180 символов>».

#### `resolveAnswerPainScore($raw, string $answerText): ?int` — L412 (private)
Pain score из числового поля или regex-парсингом из текста («боль/дискомфорт/pain N» либо «N/10»), clamp 0-10; null если не найден.

#### `interpretAnswer(int $painScore, bool $worsened, bool $techniqueChanged): string` — L425 (private)
clear (боль ≤1, без ухудшения/смены техники), mild_clear (≤2), иначе protective.

#### `normalizeBool($value): ?bool` — L435 (private)
Нормализация bool из bool/int/строк (1/true/yes/да/y и 0/false/no/нет/n); null если неоднозначно.

#### `nullableInt($value): ?int` — L455 (private)
null для null/пустой строки, иначе (int).

#### `daysSince(string $date): ?int` — L462 (private)
Число дней от даты до сегодня (UTC), минимум 0; null при ошибке парсинга.

#### `formatDateRu(string $date): string` — L472 (private)
Дата в формате d.m.Y; при ошибке возвращает исходную строку.

#### `envInt(string $key, int $default, int $min, int $max): int` — L480 (private)
Читает int из env() с дефолтом и clamp в [min, max].

## `planrun-backend/services/PlanScenarioResolver.php` (318 строк)
Авторитетный слой поверх planning state: выравнивает anchor-дату плана на понедельник, определяет первичный сценарий генерации, флаги и policy decisions, разбирает tune-up event (B-race/контрольный старт). БД не использует; функции дат/гонок из `planrun_ai/prompt_builder.php`. Потребитель: `TrainingStateBuilder`.

### class PlanScenarioResolver — L13

#### `resolve(array $user, array $state, string $mode = 'generate', array $payload = []): array` — L15
Собирает сценарий планирования: anchor-дата (понедельник), позиция гонки (`computeRaceDayPosition`), tune-up event, флаги (short_runway_taper, b_race_before_a_race, return_after_injury/break, overload_recovery, pain/illness_protective, high_caution и др.) и policy decisions. Возвращает primary-сценарий, flags, schedule_anchor_date, race_position, effective_weeks_to_goal, tune_up_event, policy_decisions.

#### `resolveScheduleAnchorDate(array $user, string $mode = 'generate', array $payload = []): ?string` — L116
Берёт payload['cutoff_date'] либо user['training_start_date'] и откатывает дату к ближайшему предыдущему понедельнику (или оставляет, если уже понедельник); null при невалидной дате. Публичный, но снаружи класса не вызывается (только внутри resolve()).

#### `resolvePrimaryScenario(array $flags, bool $isRaceGoal): string` — L137 (private)
Возвращает первый совпавший флаг из приоритетного списка (return_after_injury → … → low_confidence_start), иначе standard_race_build / general_fitness.

#### `resolveTuneUpEvent(array $payload, ?string $scheduleAnchorDate, string $mainRaceDate, string $mainRaceDistance): ?array` — L158 (private)
Парсит tune-up event из payload (tune_up_event / secondary_race / плоские поля *_date|*_distance|*_type|*_target_time); валидирует, что событие раньше главной гонки; вычисляет неделю/день в плане, дистанцию в км, целевое время и темп. Возвращает нормализованный массив события или null.

#### `isBRaceBeforeARace(string $goalType, string $mainRaceDistance, array $tuneUpEvent): bool` — L240 (private)
true если race-цель на half/marathon, tune-up за 5-10 дней до главного старта и его дистанция короче главной — тогда событие принудительно понижается до control.

#### `normalizeTuneUpType(mixed $rawType): string` — L264 (private)
'race' для race/забег/соревнование, иначе 'control'.

#### `resolveDistanceKm(mixed $rawDistance, string $fallbackDistance): float` — L273 (private)
Дистанция в км из числа или строкового ключа (5k/10k/half/marathon, рус. варианты); 0.0 для неизвестных.

#### `parseTimeToSeconds(mixed $rawTime): ?int` — L293 (private)
Парсит MM:SS или HH:MM:SS в секунды; null при невалидном формате.

## `planrun-backend/services/PlanSkeletonBuilder.php` (677 строк)
Строит детерминированный скелет плана: для каждой недели — массив из 7 типов дней (rest/easy/long/tempo/interval/fartlek/control/race) на основе предпочитаемых дней, фаз макроцикла, recovery/control недель, race-недели и tune-up event. БД не трогает; использует функции `prompt_builder.php` (computeMacrocycle, getPromptWeekdayOrder и др.). Потребитель: `planrun_ai/plan_generator.php`.

### class PlanSkeletonBuilder — L5
Константа DEFAULT_RUN_DAY_ORDERS (L6) — дефолтные беговые дни для 1-7 сессий в неделю.

#### `build(array $userData, string $goalType, array $options = []): array` — L16
Главный метод: определяет беговые дни, день длительной, фазовый план, recovery/control недели, race-позицию; для каждой недели проставляет easy-дни, race/tune-up/long день и quality-типы по `resolveQualityTypes`+`pickQualityIndexes`. Возвращает {start_date, weeks:[{week_number, phase_name, phase_label, phase_week_index, days[7]}]}.

#### `resolveWeekRunDays(...): array` — L110 (private)
Решает, какие беговые дни оставить в конкретной неделе: каппирует число дней для race-недели (3-4), пост-race недели (post_goal_race_run_day_cap), tune-up недели при b_race_before_a_race (4), taper/короткого runway (4-5), форсированной recovery-недели 1 (initial_recovery_run_day_cap); исключает race/tune-up день из пула и применяет приоритеты дней.

#### `limitRunDays(array $runDays, int $cap, array $mustKeep = [], ?array $priority = null): array` — L231 (private)
Урезает список дней до cap: сначала mustKeep (например, день длительной), затем по приоритетному порядку, остаток — по исходному порядку; результат сортируется `sortPromptWeekdayKeys`.

#### `resolveRunDayPriority(array $userData, int $weekNumber, bool $isRaceWeek, bool $isHighCaution, bool $hasWeekTuneUpEvent): ?array` — L269 (private)
Кастомные приоритеты дней только для сценария b_race_before_a_race (не high-caution): для race-недели и tune-up недели возвращает специальные порядки дней; иначе null (дефолтный приоритет).

#### `weekdayKeyFromIndex(int $dayIndex): ?string` — L302 (private)
Обратный маппинг индекса дня (0-6) в ключ дня недели через getPromptWeekdayOrder().

#### `resolveRunDays(array $userData): array` — L312 (private)
preferred_days пользователя (отсортированные) либо дефолтный набор по sessions_per_week из DEFAULT_RUN_DAY_ORDERS.

#### `resolveLongDayKey(array $userData, array $runDays): ?string` — L324 (private)
День длительной: `getPreferredLongRunDayKey` либо последний беговой день.

#### `resolvePhasePlan(array $userData, string $goalType, int $weeks, array $options): array` — L332 (private)
Карта week_number → фаза: из options['current_phase'] (адаптация/пересчёт) либо из computeMacrocycle/computeHealthMacrocycle; добавляет phase_week_index.

#### `buildPhasePlanFromCurrentPhase(array $currentPhase, int $weeks): array` — L356 (private)
Разворачивает remaining_phases текущего макроцикла в недельную карту с учётом weeks_into_phase (сколько недель первой фазы уже пройдено).

#### `resolveRecoveryWeeks(array $userData, string $goalType, array $options): array` — L390 (private)
Номера recovery-недель: из current_phase с пересдвигом на kept_weeks либо из макроцикла.

#### `resolveControlWeeks(array $userData, string $goalType, array $options): array` — L409 (private)
Аналогично recovery — номера недель с контрольным забегом.

#### `resolveQualityTypes(...): array` — L428 (private)
Определяет quality-типы недели (tempo/interval/fartlek/control, 0-2 шт.) по фазе, числу сессий, goal_type, load_policy (quality_mode, quality_delay_weeks, protect_low_base_novice), special population флагам (травма → пусто; беременность/хроника → пусто; консервативные флаги → урезание) и race/recovery/tune-up неделям.

#### `resolveSpecialPopulationFlags(array $userData): array` — L600 (private)
Уникальные special_population_flags из training_state либо корня userData.

#### `resolveWeekTuneUpEvent(array $userData, int $weekNumber): ?array` — L605 (private)
tune_up_event из planning_scenario, если он приходится на данную неделю и имеет dayIndex.

#### `pickQualityIndexes(array $runDays, ?int $longDayIndex, ?int $raceDayIndex, int $count): array` — L623 (private)
Выбирает индексы дней под quality-работы из беговых дней по предпочтительному порядку (вт, чт, ср…), исключая long/race дни и соседние с ними/друг с другом дни (двухпроходный отбор).

#### `isAdjacentToAny(int $index, array $indexes): bool` — L669 (private)
true если index отстоит ровно на 1 от любого из indexes.

## `planrun-backend/services/PostWorkoutFollowupService.php` (1533 строки)
Пост-тренировочные чекины: планирует вопрос «как самочувствие?» после тренировки, отправляет его в чат (с push), ловит ответ юзера, классифицирует (good/neutral/fatigue/pain) с извлечением структурных оценок (RPE, ноги, дыхание, пульс, боль), сохраняет в таблицу `post_workout_followups` + заметку дня, и строит аналитику фидбека для training state. Потребители: `WorkoutService` (scheduleForWorkout), `ChatService` (tryHandleUserReply), `TrainingStateBuilder`/`AthleteSignalsService` (аналитика), `PlanReadinessCheckService`, cron `scripts/post_workout_followups.php`.

### class PostWorkoutFollowupService extends BaseService — L9
Статический флаг $schemaEnsured (L10). Константы: статусы STATUS_PENDING/SENT/COMPLETED/SKIPPED/EXPIRED (L12-16), источники SOURCE_WORKOUT/SOURCE_WORKOUT_LOG (L18-19), дефолты таймингов DEFAULT_DELAY_MINUTES/MAX_AGE_HOURS/REPLY_WINDOW_HOURS/ANALYTICS_LOOKBACK_DAYS/SNOOZE_EVENING_HOUR/SNOOZE_TOMORROW_HOUR (L21-26).

#### `ensureSchema(): void` — L28
CREATE TABLE IF NOT EXISTS post_workout_followups + миграции недостающих колонок через `ensureColumnExists`; кэширует выполнение в static-флаге. DDL в БД.

#### `getRecentFeedbackAnalytics(int $userId, int $days = 14, ?string $endDate = null): array` — L87
Обёртка: считает окно [end-days+1, end] и делегирует `getFeedbackAnalyticsBetween`.

#### `getFeedbackAnalyticsBetween(int $userId, string $startDate, string $endDate): array` — L100
SELECT completed-followups за период (JOIN chat_messages для текста ответа), гидратирует строки и строит сводку (counts по классификациям, риски, дельты метрик, risk_level). Чтение post_workout_followups/chat_messages.

#### `getPendingCheckinState(int $userId): ?array` — L149
Состояние чекина для клиента: активный sent-followup (awaiting reply) либо ближайший pending (при наступлении due — сразу диспатчит сообщение в чат без push). Возвращает client payload или null. Производственных HTTP-потребителей не найдено (только unit-тесты).

#### `scheduleForWorkout(int $userId, string $workoutDate, string $sourceKind, int $sourceId, ?int $analysisMessageId = null): bool` — L187
Планирует followup для тренировки (источник workouts или workout_log): проверяет применимость (`shouldScheduleForSummary`), гасит другие активные followups (supersede), INSERT нового pending с due_at = now + delay либо реактивация существующего skipped/expired. Вызывается из WorkoutService после импорта/записи тренировки.

#### `snoozeFollowup(int $userId, int $followupId, string $preset = '30m'): ?array` — L260
Откладывает followup по пресету (30m/evening/tomorrow_morning в TZ юзера): UPDATE due_at/snoozed_until/snooze_count. Возвращает client payload. Используется только scripts/ai_runtime_smoke.php и тестами.

#### `processDueFollowups(int $limit = 50): array` — L305
Cron-обработчик: экспирирует протухшие sent, выбирает pending с due_at<=NOW и диспатчит сообщения с уведомлениями; возвращает статистику sent/skipped/expired/errors.

#### `tryHandleUserReply(int $userId, int $conversationId, int $userMessageId, string $content): ?array` — L375
Перехват ответа юзера в чате: если текст похож на фидбек и это первый ответ после followup-сообщения — анализирует (`analyzeFeedback`), сохраняет заметку дня (NoteRepository::addDayNote), дописывает фидбек в workout_log.notes, UPDATE followup → completed со скорами, формирует ответ тренера. Возвращает {assistant_content, metadata} или null. Вызывается из ChatService.

#### `getFollowupBySource(int $userId, string $sourceKind, int $sourceId): ?array` — L463 (private)
SELECT id/status followup по уникальному источнику.

#### `getLatestAwaitingReply(int $userId): ?array` — L480 (private)
Последний followup в статусе sent (ожидает ответа).

#### `getNextPendingForUser(int $userId): ?array` — L500 (private)
Ближайший pending followup по due_at.

#### `supersedeOtherActiveFollowups(int $userId, string $sourceKind, int $sourceId): void` — L521 (private)
UPDATE: все другие pending/sent followups юзера переводит в skipped (актуален только последний).

#### `markFollowupStatus(int $followupId, string $status): void` — L545 (private)
UPDATE статуса followup по id.

#### `expireStaleSentFollowups(): int` — L555 (private)
UPDATE: sent-followups старше reply window (36ч) переводит в expired; возвращает число затронутых.

#### `isFirstUserReplyAfterFollowup(int $conversationId, int $followupMessageId, int $userMessageId): bool` — L575 (private)
Проверяет в chat_messages, что между followup-сообщением и данным ответом не было других сообщений юзера.

#### `getWorkoutSummary(int $userId, string $sourceKind, int $sourceId): ?array` — L594 (private)
SELECT сводки тренировки из workouts либо workout_log (+activity_types): дата, время, дистанция, длительность, тип активности.

#### `shouldScheduleForSummary(int $userId, array $summary): bool` — L636 (private)
Применимость чекина: не walking, тренировка сегодня (в TZ юзера) и не старше max age hours (8ч).

#### `buildFollowupPrompt(int $userId, array $summary): string` — L670 (private)
Текст вопроса на русском («Как ты после сегодняшней пробежки на X км?…») с подсказкой про шкалы 1-10.

#### `buildStoredNoteContent(string $content, array $feedbackAnalysis = []): string` — L690 (private)
Текст заметки дня: «Самочувствие после тренировки: <ответ> [тяжесть N/10, ноги N/10, …]».

#### `appendFeedbackToWorkoutLogIfPossible(array $followup, string $content): void` — L712 (private)
Для источника workout_log дописывает тегированный фидбек в notes записи (если ещё не дописан). Чтение+UPDATE workout_log.

#### `analyzeFeedback(string $content): array` — L747 (private)
Эвристический NLP-анализ ответа: negation guard для отрицаемой боли (#105), детекция боли/усталости/позитива regex-ами, классификация (pain/fatigue/good/neutral), извлечение структурных оценок (RPE, ноги, дыхание, пульс, боль) и расчёт recovery_risk_score (0-1).

#### `isLikelyFeedbackResponse(string $content): bool` — L846 (private)
Эвристика «это фидбек, а не команда/вопрос»: отсеивает вопросы с командными глаголами, принимает тексты с лексикой самочувствия или короткие «ок/норм/тяжело».

#### `buildCoachReply(int $userId, string $workoutDate, string $classification, ?array $summary): string` — L863 (private)
Шаблонный ответ тренера по классификации (pain/fatigue/good/neutral) + совет о следующем плановом дне.

#### `getNextPlannedDay(int $userId, string $workoutDate): ?array` — L875 (private)
SELECT ближайшего дня плана после даты из training_plan_days JOIN training_plan_weeks.

#### `buildNextDayAdvice(?array $nextDay): string` — L894 (private)
Строка-совет о следующем дне плана (отдых → «используй для восстановления», тренировка → «не форсируй»).

#### `isFollowupDue(array $followup): bool` — L910 (private)
due_at <= now (true при пустой/невалидной дате).

#### `isFollowupVisibleNow(array $followup): bool` — L925 (private)
snoozed_until <= now в UTC (true если не отложен).

#### `dispatchFollowupMessage(array $followup, array $summary, bool $withNotifications = true): ?array` — L940 (private)
Создаёт сообщение чекина в чате (с push через ChatService либо in-app через ChatRepository), UPDATE followup → sent с followup_message_id; возвращает обновлённый followup.

#### `createNotifiedFollowupMessage(int $userId, string $message): int` — L981 (private)
`ChatService::addAIMessageToUser` с метаданными события coach.proactive_post_workout_checkin (триггерит push/уведомления); возвращает message_id.

#### `createInAppFollowupMessage(int $userId, string $message): int` — L995 (private)
Тихое добавление AI-сообщения через ChatRepository (без уведомлений); touch конверсации.

#### `buildClientFollowupPayload(array $followup, bool $isReady, ?array $summary = null): array` — L1009 (private)
Полный клиентский payload чекина: статусы, ISO-даты, snooze-инфо, заголовок/подзаголовок, текст вопроса (из строки/чата/генерации), сводка тренировки, пресеты snooze.

#### `buildClientFollowupSubtitle(?array $summary, string $workoutDate): string` — L1051 (private)
Подзаголовок «После пробежки · 8.5 км · 05.06.2026» либо дефолтная фраза.

#### `getFollowupMessageContent(int $messageId): ?string` — L1073 (private)
SELECT content из chat_messages по id.

#### `getDayTypeRu(string $type): string` — L1092 (private)
Маппинг типа дня плана в русское название.

#### `getActivityTypeRu(string $type): string` — L1110 (private)
Маппинг типа активности в русскую форму родительного падежа («после … пробежки»).

#### `getDelayMinutes(): int` — L1123 (private)
env POST_WORKOUT_FOLLOWUP_DELAY_MINUTES (дефолт 15).

#### `getMaxAgeHours(): int` — L1128 (private)
env POST_WORKOUT_FOLLOWUP_MAX_AGE_HOURS (дефолт 8).

#### `getReplyWindowHours(): int` — L1133 (private)
env POST_WORKOUT_FOLLOWUP_REPLY_WINDOW_HOURS (дефолт 36).

#### `getSnoozeEveningHour(): int` — L1138 (private)
env POST_WORKOUT_FOLLOWUP_SNOOZE_EVENING_HOUR, clamp 16-23 (дефолт 19).

#### `getSnoozeTomorrowHour(): int` — L1143 (private)
env POST_WORKOUT_FOLLOWUP_SNOOZE_TOMORROW_HOUR, clamp 6-12 (дефолт 9).

#### `getUserTimezone(int $userId): DateTimeZone` — L1148 (private)
TZ юзера через `getUserTimezone()` из user_functions; фоллбэк Europe/Moscow.

#### `formatDateRu(string $date): string` — L1156 (private)
Y-m-d → d.m.Y.

#### `formatDateTimeIso(string $dateTime): ?string` — L1161 (private)
Datetime (UTC) → ISO 8601 (ATOM); null при пустом/невалидном.

#### `isValidDate(string $date): bool` — L1175 (private)
Regex-проверка формата YYYY-MM-DD.

#### `getFollowupByIdForUser(int $userId, int $followupId): ?array` — L1179 (private)
SELECT followup по id+user_id.

#### `resolveSnoozeUntil(int $userId, string $preset): ?DateTimeImmutable` — L1199 (private)
Время отложки по пресету в локальной TZ юзера: +30 мин / вечер / завтра утром; null при неизвестном пресете.

#### `resolveEveningSnoozeTime(DateTimeImmutable $nowLocal): DateTimeImmutable` — L1213 (private)
Сегодняшний вечерний час, либо завтрашний если уже прошёл.

#### `ensureColumnExists(string $column, string $definition): void` — L1221 (private)
SHOW COLUMNS + ALTER TABLE ADD COLUMN при отсутствии; throwException при ошибке DDL.

#### `hydrateFeedbackAnalyticsRow(array $row): array` — L1241 (private)
Нормализует строку аналитики: использует сохранённые скоры, а при их полном отсутствии — деривацию `analyzeFeedback` из текста ответа (обратная совместимость со старыми записями).

#### `buildFeedbackAnalyticsSummary(array $rows, string $startDate, string $endDate): array` — L1284 (private)
Агрегирует строки в сводку: счётчики классификаций/флагов, средние и max recovery risk, recent/baseline средние и дельты по каждой метрике (`buildStructuredMetricSummary`), составной subjective_load_delta и итоговый risk_level.

#### `buildEmptyFeedbackAnalytics(string $startDate, string $endDate): array` — L1356 (private)
Пустая сводка со всеми нулевыми полями и window_days.

#### `resolveFeedbackRiskLevel(array $summary): string` — L1407 (private)
high (свежая боль / risk≥0.75 / pain avg≥4 / pain delta≥2), moderate (≥2 fatigue-флага / risk≥0.45 / load delta≥0.75 / RPE delta≥1), иначе low.

#### `resolveSessionRpe(string $normalized, string $classification): ?int` — L1429 (private)
RPE из структурного парсинга («тяжесть/rpe N») либо дефолт по классификации (good=4, fatigue=8-9, pain=7, neutral=6).

#### `resolveTenPointScore(string $normalized, array $labels, string $classification): ?int` — L1443 (private)
10-балльный скор по меткам (ноги/дыхание/пульс): парсинг либо эвристика по классификации и модификаторам «немного»/«очень».

#### `resolveFeedbackPainScore(string $normalized, string $classification, bool $painFlag): ?int` — L1460 (private)
Pain score: парсинг, либо при pain_flag — 8 (сильная), 3 (лёгкая), 6 (дефолт); без флага — 0 (good) / 1.

#### `extractStructuredScore(string $normalized, array $labels, int $targetScale, int $minValue): ?int` — L1479 (private)
Regex-извлечение «метка: N[/M]» с пересчётом шкалы через `normalizeStructuredScore`.

#### `normalizeStructuredScore(int $raw, int $sourceScale, int $targetScale, int $minValue): int` — L1492 (private)
Clamp и пропорциональный пересчёт значения между шкалами.

#### `buildStructuredMetricSummary(string $prefix, array $values, int $recentWindow): array` — L1505 (private)
recent_avg (первые N значений), baseline_avg (остальные либо все) и delta для метрики.

#### `nullableInt(mixed $value): ?int` — L1526 (private)
null для null/пустой строки, иначе (int).

## `planrun-backend/services/ProactiveCoachService.php` (857 строк)
Проактивный AI-тренер: детектирует события (пауза, перегрузка ACWR, близкий забег, рекорды, низкое выполнение, goal-milestones), генерирует персональные сообщения через LLM (LlmGateway, DeepSeek-совместимый API) и шлёт их в чат; также утренние брифинги и недельные дайджесты. Cooldown-учёт в таблице `proactive_coach_log`. Потребители: cron-скрипты proactive_coach.php, daily_briefing.php, weekly_digest.php, regenerate_ai_messages.php.

### class ProactiveCoachService — L17
Константы: COOLDOWN_HOURS=48 (L23), COOLDOWN_MAP (L24, per-type cooldown: daily_briefing 20ч, weekly_digest 144ч), TYPE_RU_MAP (L29, маппинг типов тренировок на русский).

#### `__construct($db)` — L50
Сохраняет mysqli и читает LLM_CHAT_BASE_URL / LLM_CHAT_MODEL из env.

#### `processAllUsers(): array` — L60
Для всех активных юзеров: проверяет TZ-окно отправки, детектирует события, берёт первое не на cooldown по приоритету, генерирует и шлёт сообщение, записывает cooldown. Возвращает статистику sent/skipped/errors/details.

#### `detectEvents(int $userId, ?array $user = null): array` — L97
Запускает все детекторы (pause, overload, race, low compliance, milestone, goal milestones из GoalProgressService) и возвращает список событий. Public, но внешних вызовов вне класса нет.

#### `detectPause(int $userId, DateTimeZone $tz): ?array` — L133 (private)
Последняя дата тренировки из workout_log+workouts (UNION); событие pause при 4-14 днях без тренировок.

#### `detectOverload(int $userId): ?array` — L156 (private)
ACWR через `ChatContextBuilder::calculateACWR`: zone=danger → overload (приоритет 5), caution с ACWR>1.4 → overload_warning.

#### `detectUpcomingRace(array $user, DateTimeZone $tz): ?array` — L169 (private)
race_approaching при 1-14 днях до race_date юзера.

#### `detectLowCompliance(int $userId, DateTimeZone $tz): ?array` — L189 (private)
Через `ChatContextBuilder::getWeeklyCompliance`: при ≥4 запланированных и выполнении <40% — событие low_compliance.

#### `detectMilestone(int $userId, DateTimeZone $tz): ?array` — L203 (private)
Рекорд дистанции: вчерашняя дистанция (≥5 км) больше исторического максимума из workout_log+workouts → distance_record.

#### `detectGoalMilestones(int $userId): array` — L240 (private)
Делегирует `GoalProgressService::detectMilestones` (vdot_improvement, volume_record, consistency_streak, goal_achievable); пустой массив при ошибке.

#### `orderEventsByPriority(array $events): array` — L253 (private)
Сортировка событий по убыванию priority.

#### `pickNextAvailableEvent(int $userId, array $events): ?array` — L258 (private)
Первое по приоритету событие, не находящееся на cooldown.

#### `buildGoalContext(int $userId): string` — L273 (private)
Блок «ЦЕЛЬ: дистанция, целевое время, дней до старта» из users для промпта LLM; пустая строка если цели нет/прошла.

#### `buildHistoryBlock(int $userId, int $maxLines = 16): string` — L306 (private)
Блок истории тренировок для промпта: weekly rollup, ключевые тренировки и последние строки через `WorkoutAnalysisRepository`.

#### `generateMessage(int $userId, array $user, array $event): string` — L332 (private)
Формирует описание события + промпт с форматом/тоном/запретами, вызывает LLM (`callLlmSimple`, 220 токенов); при пустом ответе — шаблонный фоллбэк.

#### `getFallbackMessage(string $type, array $data): string` — L392 (private)
Шаблонные русские сообщения по типу события (pause/overload/…/goal_achievable) на случай отказа LLM.

#### `getDailyBriefingFallback(string $typeRu, string $description, bool $isKey, string $acwrZone): string` — L408 (private)
Фоллбэк-брифинг: «Сегодня по плану X. <первая строка описания>. <совет по ACWR/key>».

#### `sendProactiveMessage(int $userId, string $message, array $event): void` — L429 (private)
Шлёт сообщение через `ChatService::addAIMessageToUser` с метаданными coach.proactive_<type> (триггерит уведомления/push); пишет лог.

#### `isOnCooldown(int $userId, string $eventType): bool` — L445 (private)
Проверяет последнюю отправку этого типа в proactive_coach_log против COOLDOWN_MAP/48ч (суффикс «:xxx» отбрасывается до базового типа).

#### `recordCooldown(int $userId, string $eventType): void` — L466 (private)
INSERT записи в proactive_coach_log.

#### `processDailyBriefings(): array` — L475
Утренний брифинг: для активных юзеров вне cooldown и в TZ-окне — берёт сегодняшний день плана (не rest/free), ACWR, строит промпт и шлёт LLM-сообщение (фоллбэк при отказе). Чтение training_plan_days/weeks; отправка в чат; cooldown daily_briefing.

#### `processWeeklyDigests(): array` — L588
Недельный дайджест (воскресенье в TZ юзера): статистика за 7 дней (план vs факт, объём, ACWR), LLM-итог недели с фоллбэком; cooldown weekly_digest.

#### `callLlmSimple(string $prompt, int $maxTokens = 300, ?int $userId = null): string` — L682 (private)
HTTP-вызов LLM через `LlmGateway::requestChatCompletion` (с withThinkingMode, retries из LLM_MAX_RETRIES); возвращает нормализованный текст или '' при ошибке.

#### `normalizeProse(string $text): string` — L719 (private)
Стрипает markdown bold/italic/backticks, ведущие bullet-маркеры и нумерацию, схлопывает в один связный текст.

#### `countPlannedWorkouts(int $userId, string $startDate, string $endDate): int` — L738 (private)
COUNT дней плана (не rest/free) за период из training_plan_days/weeks.

#### `getActualWorkoutStats(int $userId, string $startDate, string $endDate): array` — L757 (private)
Сессии и км за период: workout_log (completed) UNION workouts (исключая дни, уже покрытые workout_log — антидубль).

#### `getActiveUsers(): array` — L791 (private)
SELECT юзеров с onboarding_completed=1, не забаненных, с хотя бы одной неделей плана.

#### `getUserTz(int $userId, array $user): DateTimeZone` — L805 (private)
TZ из users.timezone с фоллбэком Europe/Moscow.

#### `isWithinUserSendWindow(array $user, string $kind = 'daily'): bool` — L825 (private)
TZ-гейт проактива (#126): шлёт только в локальном окне юзера (morning [6,9) / day [11,14) / evening [17,20) из training_time_pref, override PROACTIVE_SEND_WINDOW); для weekly дополнительно требует воскресенья в TZ юзера. Требует ежечасного cron.

#### `resolveSendWindowHours(array $user): array` — L842 (private)
[startHour, endHour) окна отправки: env-override «H-H» либо по training_time_pref.

## `planrun-backend/services/PushNotificationService.php` (224 строки)
Отправка push через Firebase Cloud Messaging (FCM HTTP v1, kreait/firebase-php): нотификационные и data-only (silent) сообщения, гейтинг через NotificationSettingsService, чистка невалидных токенов. Таблица `push_tokens`.

### class PushNotificationService extends BaseService — L15

#### `getMessaging()` — L22 (private)
Ленивая инициализация Firebase Messaging из FIREBASE_CREDENTIALS (путь) или FIREBASE_CREDENTIALS_JSON (содержимое/путь); null при отсутствии кредов/библиотеки/ошибке (с error_log).

#### `isPushAllowed(int $userId, string $type): bool` — L73
Читает users.push_workouts_enabled / push_chat_enabled по типу; true для неизвестных типов. Используется также scripts/send_test_push.php, check_push.php.

#### `sendToUser(int $userId, string $title, string $body, array $data = []): bool` — L92
Отправка push юзеру: при event_key — гейт `NotificationSettingsService::canDeliver` (quiet hours, настройки) с логированием доставки (logDelivery: skipped/sent/failed); иначе legacy-проверка isPushAllowed. Берёт токены и шлёт через sendToTokens.

#### `getUserTokens(int $userId): array` — L141
SELECT непустых fcm_token из push_tokens по user_id.

#### `sendToTokens(array $tokens, string $title, string $body, array $data = []): bool` — L157
Шлёт CloudMessage (notification+data, high priority для Android Doze) на каждый токен через FCM; удаляет невалидные/unregistered токены; true если хоть одна отправка удалась.

#### `sendDataPush(int $userId, array $data): bool` — L190
Data-only (silent) push с normal priority — фоновое обновление UI без уведомления. Потребители: scripts/suunto_auto_sync.php, scripts/process_strava_webhook_retries.php.

#### `removeInvalidToken(string $token): void` — L218 (private)
DELETE токена из push_tokens.

## `planrun-backend/services/RegisterApiService.php` (45 строк)
Тонкий фасад для register_api.php: валидация поля регистрации и отправка кода подтверждения email с rate limiting. Делегирует RegistrationService и EmailVerificationService.

### class RegisterApiService — L7

#### `__construct($db, $registrationService = null, $emailVerificationService = null)` — L12
DI с дефолтным созданием RegistrationService/EmailVerificationService.

#### `validateField($field, $value)` — L18
Прокси к `RegistrationService::validateField`.

#### `sendVerificationCode($email, $ipAddress)` — L22
Валидирует email, применяет RateLimiter (10/15мин на IP, 3/15мин на email-хэш) и делегирует `EmailVerificationService::sendVerificationCode` (отправка письма). InvalidArgumentException 400 при невалидном email.

#### `normalizeRateLimitValue($value)` — L39 (private)
trim + lowercase (mb при наличии) для ключа rate limit.

## `planrun-backend/services/RegistrationService.php` (547 строк)
Регистрация пользователей: валидация полей, минимальная регистрация (email+пароль+код), авторегистрация из Telegram Mini App, полная регистрация с онбордингом и запуском генерации плана. Пишет в таблицы users, user_training_plans; ставит job в PlanGenerationQueueService. Потребители: register_api.php, TelegramMiniAppService, scripts/live_plan_generation_batch.php.

### class RegistrationService extends BaseService — L11

#### `userRepo(): UserRepository` — L16 (private)
Ленивый синглтон UserRepository.

#### `__construct($db, ?EmailVerificationService $verificationService = null)` — L20
Инициализирует EmailVerificationService (DI) и PlanGenerationQueueService.

#### `validateField(string $field, string $value): array` — L26
Валидация username (длина 3-50, допустимые символы, уникальность) и email (формат, уникальность); прочие поля — valid. Чтение users через UserRepository.

#### `registerMinimal(array $input): array` — L66
Минимальная регистрация: пароль ≥6, email, проверка кода через `EmailVerificationService::verifyCode`, гейт isRegistrationEnabled и уникальности email; INSERT users с временным username и финальным `user_<id>` после insert (UPDATE). Возвращает {success, user{id, username, email, onboarding_completed:0}}.

#### `registerFromTelegram(array $tgUser, ?string $timezone = null): array` — L176
Авто-аккаунт для Telegram Mini App: email NULL, случайный пароль, username из tg-данных; защита от гонки по telegram_id (поиск до INSERT и обработка Duplicate). Возвращает {user_id, username}.

#### `generateUniqueUsernameFromTelegram(array $tgUser): string` — L248 (private)
Кандидаты username из tg username / имени-фамилии с суффиксами _1.._99; фоллбэк tg_<id>(_hash).

#### `normalizeUsername(string $value): string` — L288 (private)
Оставляет только допустимые символы username, схлопывает пробелы.

#### `registerFull(array $data): array` — L298
Полная регистрация с данными онбординга: валидация, identity (slug/email), INSERT полного профиля (`insertFullUser`), создание стартового user_training_plans (`createInitialTrainingPlan`) и запуск AI-генерации плана (`startPlanGeneration`).

#### `prepareRegistrationIdentity(string $username, ?string $email): array` — L331
Гейт регистрации + уникальность username/email; генерирует уникальный username_slug. Возвращает {success, username_slug, email|null} или ошибку.

#### `isRegistrationEnabled(): bool` — L352 (private)
Читает site_settings.registration_enabled (true если таблицы/ключа нет).

#### `userExistsByUsername(string $username): bool` — L363 (private)
Через UserRepository::findIdByUsername.

#### `userExistsByEmail(string $email): bool` — L367 (private)
Через UserRepository::findIdByEmail.

#### `generateUniqueUsernameSlug(string $username): string` — L371 (private)
Слагификация (lowercase, [a-z0-9_]) с числовыми суффиксами до уникальности.

#### `usernameSlugExists(string $slug): bool` — L393 (private)
Через UserRepository::findIdBySlug.

#### `sanitizeTimezone($value): ?string` — L401 (private)
Валидация IANA-таймзоны (строка ≤64, конструктор DateTimeZone); null при невалидной.

#### `insertFullUser(array $data, array $identity, string $password): int` — L413 (private)
Динамический INSERT в users (~40 полей онбординга: цель, гонка, антропометрия, опыт, предпочтения дней, режим тренировок и т.д.) с типизированным bind_param; возвращает новый user id, RuntimeException при ошибке.

#### `createInitialTrainingPlan(int $userId, array $data): void` — L497 (private)
INSERT в user_training_plans (start_date=CURDATE, целевая дата/время по goal_type); is_active=0 для ai-режима (план активируется job-ом генерации), 1 для self/coach.

#### `startPlanGeneration(int $userId, string $trainingMode): ?string` — L526 (private)
Для ai — ставит job 'generate' в PlanGenerationQueueService и возвращает сообщение о генерации; для self/coach — текст о готовом календаре; логирует ошибку постановки в очередь.

## `planrun-backend/services/SiteSettingsService.php` (78 строк)
CRUD настроек сайта (таблица site_settings): имя/описание сайта, режим обслуживания, разрешение регистрации, контактный email. Потребитель: AdminController.

### class SiteSettingsService extends BaseService — L8
Константы: ON_OFF_KEYS (L10) — boolean-ключи, DEFAULTS (L12) — дефолтные значения.

#### `getAll(): array` — L23
Дефолты + значения из site_settings (если таблица есть); boolean-ключи нормализуются к '0'/'1'.

#### `update(array $settings): void` — L49
Для каждого разрешённого ключа — INSERT … ON DUPLICATE KEY UPDATE в site_settings с нормализацией boolean/строк.

#### `getAllowedKeys(): array` — L75
Список допустимых ключей (ключи DEFAULTS). Потребителей не найдено — suspected dead.

## `planrun-backend/services/StatsService.php` (569 строк)
Статистика тренировок: проценты выполнения плана, сводки/списки тренировок (объединение workouts + workout_log с дедупликацией), VDOT по реальным тренировкам и trajectory лучших результатов по дистанциям, недельный анализ. Потребители: StatsController, api_v2.php, ChatToolRegistry, TrainingStateBuilder, WorkoutService, GoalProgressService, CoachEventsService, cron-скрипты.

### class StatsService extends BaseService — L12

#### `__construct($db)` — L16
Инициализирует StatsRepository.

#### `normalizeActivityType(?string $raw): string` — L26 (private static)
Маппинг кириллических имён activity_types («Бег», «ОФП», «СБУ»…) в английские ключи для фронтенда; дефолт running.

#### `getStats($userId)` — L51
Проценты выполнения плана: total дней из репозитория, выполненные из getCompletedDaysKeys + сопоставление дат workouts с днями плана (findTrainingDay). Возвращает {total, completed, percentage}. Чтение training_plan_*/workouts/workout_log.

#### `getAllWorkoutsSummary($userId)` — L94
Сводка тренировок по датам: объединяет агрегаты workouts (StatsRepository::getWorkoutsSummary, с workout_url) и workout_log (getWorkoutLogSummary), складывая count/distance/duration при пересечении дат.

#### `getAllWorkoutsList($userId, $limit = 500)` — L164
Плоский список тренировок (каждая отдельно): GPS-тренировки из workouts + ручные из workout_log с антидублем (близкая дистанция в тот же день — GPS приоритетнее); сортировка по start_time убыв. Чтение workouts/workout_log/activity_types.

#### `getBestResultForVdot(int $userId, int $weeksWindow = 6, ?float $targetDistKm = null): ?array` — L275
VDOT по реальным тренировкам: кандидаты из workout_log+workouts (бег, 2-50 км, окно 6 недель), `estimateVDOT` из prompt_builder, дедупликация по дате+дистанции, отсев easy (<85% от лучшего VDOT), взвешенное среднее топ-5 с recency decay 0.85^weeks и релевантностью к целевой дистанции. Возвращает {distance_km, time_sec, vdot, vdot_source_detail} или null.

#### `getBestRacesProgression(int $userId, int $weeksWindow = 52): array` — L433
Trajectory лучших результатов по бакетам 5k/10k/half/marathon за окно (дефолт 52 нед.): объединяет workout_log+workouts, для каждого бакета — лучший темп с расчётом VDOT; сортировка по дате убыв. Для LLM-контекста прогресса (Phase B.4).

#### `prepareWeeklyAnalysis($userId, $weekNumber = null)` — L557
Обёртка над глобальной `prepareWeeklyAnalysis()` (prepare_weekly_analysis.php) с конвертацией исключений в API-ошибку 400.
