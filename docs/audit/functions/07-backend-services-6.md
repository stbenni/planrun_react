# Backend services 6/6 (Workout*) — справочник функций

## `planrun-backend/services/WorkoutAnalysisRepository.php` (538 строк)
Persistence + запросы для разборов тренировок (таблица `workout_analyses`): план vs факт, классификация, структура лапов, LLM-разбор и feedback. Контекст для WeeklyPlanAdaptationService, ChatPromptBuilder/ChatContextBuilder, ProactiveCoachService, plan_generator (пересчёт плана).

### class WorkoutAnalysisRepository — L14
Репозиторий над таблицей `workout_analyses` с автосозданием схемы и форматированием summary-строк для LLM-промптов.

#### `__construct($db)` — L19
Сохраняет mysqli-соединение и сразу вызывает `ensureSchema()`.

#### `ensureSchema(): void` — L24
Создаёт таблицу `workout_analyses` (CREATE TABLE IF NOT EXISTS) и тихо прогоняет ALTER-миграции (колонки `planned_is_key`, `is_significant`, индекс `idx_user_significant`). Защищено static-флагом `$schemaEnsured` от повторного выполнения в рамках процесса.

#### `save(array $data): int` — L82
Upsert разбора по уникальному ключу (user_id, source_kind, source_id) через INSERT ... ON DUPLICATE KEY UPDATE; feedback-поля и `llm_review_text` обновляются через COALESCE (не затираются NULL'ом). Автоматически проставляет `is_significant` через `detectSignificance()`; структуру сериализует в JSON. Пишет в `workout_analyses`; возвращает insert_id (0 при обновлении/ошибке).

#### `getSummaryLinesForActivePlan(int $userId, int $contextWeeksBeforePlan = 6): array` — L198
Возвращает summary-строки всех тренировок активного плана + хвост N недель до старта плана (continuity между мезоциклами). Без активного плана — окно последних 12 недель. Делегирует `getActivePlanStartDate()` и `getSummaryLinesSince()`.

#### `getSummaryLinesSince(int $userId, string $sinceDate, int $limit = 200): array` — L211
Читает `summary_line` из `workout_analyses` с даты `sinceDate` по возрастанию даты (LIMIT 200 по умолчанию).

#### `getWeeklyRollupSince(int $userId, string $sinceDate): array` — L236
Агрегирует тренировки по ISO-неделям (Пн–Вс): км, число тренировок, топ-3 типа, маркеры [МАРАФОН]/[гонка]. Возвращает готовые строки-«факты» для LLM, чтобы модель не считала сама. Читает `workout_analyses`.

#### `getKeyWorkoutSummary(int $userId, string $sinceDate): array` — L300
Выбирает из `workout_analyses` строки с `planned_is_key=1` или `is_significant=1` и форматирует каждую через `formatKeyLine()` (выполнено/пропущено/недовыполнено + feedback-флаги).

#### `getKeyWorkoutSummaryForActivePlan(int $userId, int $contextWeeksBeforePlan = 6): array` — L323
Обёртка над `getKeyWorkoutSummary()` с окном от старта активного плана минус N недель (или 12 недель назад).

#### `formatKeyLine(array $row): string` — L331 (private static)
Форматирует одну ключевую тренировку: маркер ★/◆, план vs факт, статус [выполнено]/[ПРОПУЩЕНА]/[НЕДОВЫПОЛНЕНА] (факт <60% плановой дистанции через `extractPlannedKmFromDesc`), флаги БОЛЬ/усталость/RPE.

#### `extractPlannedKmFromDesc(string $desc): float` — L375 (private static)
Регуляркой вытаскивает первую дистанцию «N км» из текстового описания плана; 0.0 если не нашлось.

#### `getWeeklyRollupForActivePlan(int $userId, int $contextWeeksBeforePlan = 6): array` — L383
Обёртка над `getWeeklyRollupSince()` с окном от старта активного плана (или 12 недель).

#### `getRecent(int $userId, int $limit = 20): array` — L391
Последние N разборов пользователя (DESC по дате) с основными полями и `llm_review_text`. Используется WorkoutController::getRecentWorkoutAnalyses (API).

#### `getBySource(int $userId, string $sourceKind, int $sourceId): ?array` — L414
Одна запись разбора по источнику (workout / workout_log + id). Используется скриптом backfill_workout_analyses.php.

#### `getActivePlanStartDate(int $userId): ?string` — L428 (private)
Дата старта активного плана: сперва `user_training_plans.is_active=1`, fallback — MIN(start_date) из `training_plan_weeks` с week_number=1 за 6 мес.

#### `detectSignificance(array $data): bool` — L469 (public static)
Эвристика значимости: ключевая по плану, либо race, либо long ≥20 км, либо interval ≥8 км, либо tempo ≥10 км при интенсивности ≥0.82.

#### `formatSummaryLine(array $data): string` — L481 (public static)
Собирает компактную summary-строку «дата ПЛАН … → ФАКТ …» с дистанцией/темпом/hr%, структурой интервалов (work_laps) и feedback-флагами; обрезает до 250 символов. Вызывается из WorkoutService::persistWorkoutAnalysis.

#### `extractDistanceFromDesc(string $desc): string` — L531 (private static)
Вытаскивает «Nкм» из описания плана для summary-строки (аналог extractPlannedKmFromDesc, но возвращает строку).

## `planrun-backend/services/WorkoutBuilderService.php` (279 строк)
Детерминированный конструктор ОФП/СБУ-сессий из `exercise_library` — используется вместо AI-генерации структурированных силовых тренировок (PlanGenerationProcessorService).

### class WorkoutBuilderService — L10
Собирает шаблонные runner-specific сессии с персонализацией весов (история выполнения → формула от bodyweight → дефолт библиотеки).

#### `const BODYWEIGHT_FACTORS` — L28 (private)
Маппинг «название упражнения → коэффициент к весу тела» для расчёта рабочего веса.

#### `__construct($db)` — L14
Сохраняет соединение с БД.

#### `computePersonalizedWeight(string $exerciseName, ?float $bodyweight, ?string $experienceLevel): ?float` — L42 (private)
Считает вес как bodyweight × фактор × experience-скейл (novice 0.7 / advanced 1.2), округляет до 2.5 кг. Возвращает null для bodyweight-упражнений и невалидного веса (вне 30–200 кг).

#### `buildOfpSession(string $preference = 'gym', ?float $bodyweight = null, ?string $experienceLevel = null, ?int $userId = null): array` — L65
Собирает ОФП-сессию (5–6 упражнений) из шаблона gym/home по библиотеке. Приоритет весов: история ExecutedExerciseService::getLastExecuted (+progressive overload +2.5 кг при ≥14 дней и выполненных sets) → формула bodyweight → дефолт библиотеки. Читает `exercise_library`; при пустой библиотеке — `fallbackBodyweightOfp()`.

#### `buildSbuSession(): array` — L170
Собирает СБУ-сессию (дриллы + плиометрика, ~20 мин) из `exercise_library` по предпочтительному списку названий; пустой массив, если библиотека пуста.

#### `buildOfpDescription(array $exercises): string` — L216
Форматирует список упражнений в многострочное текстовое описание («Название — 3×15, 60 кг»). Вызывается из PlanGenerationProcessorService.

#### `loadLibrary(string $category): array` — L236 (private)
Читает активные упражнения категории (ofp/sbu) из `exercise_library`.

#### `findExerciseByName(array $byName, string $matchSubstring, ?string $fallbackSubstring): ?array` — L254 (private)
Поиск упражнения по подстроке имени (lowercase), с запасным вариантом.

#### `fallbackBodyweightOfp(): array` — L268 (private)
Жёстко зашитый минимальный bodyweight-набор из 6 упражнений на случай пустой/недоступной библиотеки.

## `planrun-backend/services/WorkoutClassifier.php` (126 строк)
Статический классификатор типа беговой тренировки (interval/fartlek/tempo/long/easy/recovery) по сводным метрикам и лапам. Вызывается при импорте (WorkoutService::importWorkouts) и в backfill_detected_type.php.

### class WorkoutClassifier — L3
Чисто статический, без состояния и без обращений к БД.

#### `const NON_RUN` — L5 (private)
Список activity_type, которые не классифицируются (walking, cycling, swimming, ofp...).

#### `classify(array $ctx): ?string` — L7 (public static)
Главный вход: null для не-бега; сперва детект интервалов по лапам (`detectIntervals` → `intervalOrFartlek`), затем эвристики по темпу относительно VDOT-зон (tempo/long/easy/recovery), по дистанции/длительности (long ≥18 км или ≥95 мин), и по HR-отношению avg/max (tempo ≥0.85 / easy ≥0.68 / recovery). Дефолт — 'easy'.

#### `detectIntervals(array $laps): array` — L62 (private static)
Ищет в лапах чередование «быстрый→медленный» (relGap ≥1.22, absGap ≥45 сек/км, восстановление сопоставимой длины); требует ≥4 кандидатов и ≥3 пар. Возвращает ['isInterval', 'work'].

#### `intervalOrFartlek(array $work): string` — L106 (private static)
Коэффициент вариации дистанций рабочих отрезков: CV <0.25 → 'interval', иначе 'fartlek'.

#### `maxHrFromBirthYear(?int $birthYear): int` — L120 (public static)
Оценка max HR по формуле 220 − возраст; 0 при невалидном годе рождения/возрасте.

## `planrun-backend/services/WorkoutPlanRecalculationService.php` (112 строк)
Решает, ставить ли автопересчёт плана в очередь после обновления VDOT (контрольная/забег), и деактивирует активные планы при постановке.

### class WorkoutPlanRecalculationService extends BaseService — L6
Константы: `MIN_FUTURE_WORKOUTS` (L7, минимум 2 будущих тренировки) и `MIN_VDOT_DELTA` (L8, минимум 1.0).

#### `maybeQueueAfterPerformanceUpdate(int $userId, string $type, string $resultDate, ?float $oldVdot, float $newVdot): array` — L10
Гейты: training_mode='ai' (читает `users`), ≥2 будущих плановых дней, |ΔVDOT| ≥1.0, доступность очереди. При прохождении — `PlanGenerationQueueService::enqueue('recalculate')` и `deactivateActivePlans()`. Возвращает ['queued', 'job_id'/'skipped_reason']. Вызывается из WorkoutService (checkVdotUpdateAfterResult, maybeUpdateLastRaceFromImport).

#### `deactivateActivePlans(int $userId): void` — L80 (private)
UPDATE `user_training_plans` SET is_active=FALSE для всех активных планов пользователя.

#### `countRemainingPlannedDaysAfterDate(int $userId, string $date): int` — L93 (private)
COUNT будущих тренировочных дней (не rest) в `training_plan_days` после даты.

## `planrun-backend/services/WorkoutSegmentDetector.php` (318 строк)
Авто-детект структуры тренировки (интервалы/фартлек) по сырому посекундному стриму `workout_timeline`, независимо от записанных кругов (à la TrainingPeaks Interval Detection). Вызывается только из WorkoutStructureAnalyzer::detectStreamStructure.

### class WorkoutSegmentDetector — L16
Параметры-поля: окно сглаживания 12 сек, доп. сглаживание 5 точек, мин. контраст темпа 0.20, мин. рабочий отрезок 200 м, мин. восстановление 15 сек.

#### `detect(array $timeline, ?int $maxHr = null): ?array` — L27
Пайплайн: чистка точек → сглаженный темп → 2-means порог → маркировка work/rest → чистка коротких сегментов → суммаризация → фильтр артефактов (работа быстрее восстановления, ≤3.5 км) → требование ≥3 репитов (120–3000 м медиана) и ≥2 восстановлений → `classify()`. null = ровный бег без структуры.

#### `cleanPoints(array $timeline): array` — L77 (private)
Нормализует точки: монотонная кумулятивная дистанция, парсинг готового темпа «mm:ss» (120–1500 сек/км), HR.

#### `smoothedPaceSeries(array $pts): array` — L109 (private)
Темп (сек/км) на каждую точку: приоритет — готовый темп из FIT, фолбэк — из кумулятивной дистанции центрированным окном ±12 сек.

#### `twoMeansThreshold(array $vals): array` — L128 (private)
1D 2-means кластеризация темпа (старт с 25/75 перцентилей, ≤25 итераций); возвращает [порог=(fast+slow)/2, fastMean, slowMean].

#### `fillGaps(array $marks): array` — L151 (private)
Заполняет null-метки последним значением и медианно сглаживает одиночные выбросы (окно 3).

#### `smoothSegments(array $marks, array $pts): array` — L165 (private)
До 12 проходов: находит самый короткий сегмент ниже минимума (работа <200 м / восстановление <15 сек) и переворачивает его в соседний тип.

#### `buildSegments(array $pts, array $marks): array` — L186 (private)
Группирует подряд идущие одинаковые метки в сегменты {work, i0, i1}.

#### `summariseSegment(array $pts, int $i0, int $i1, ?int $maxHr): ?array` — L200 (private)
Сводка сегмента: дистанция (м), длительность, темп, avg/max HR, hr_pct от maxHr.

#### `classify(array $reps, array $recov, ?int $maxHr): array` — L224 (private)
CV дистанций репитов <0.20 → 'interval' (confidence high при <0.12), иначе 'fartlek'. Собирает pattern («N × ~400 м @ 4:05»), диапазоны темпа/HR и нарратив.

#### `narrative(string $type, array $reps, array $recov, string $pattern, array $repHrs): string` — L260 (private)
Русскоязычное описание структуры с пульсом и средней трусцой между отрезками.

#### `smoothSeries(array $vals, int $win): array` — L279 (private)
Скользящее среднее по ряду, null'ы пропускаются.

#### `mean(array $v): float` — L294 (private)
Среднее арифметическое (0.0 для пустого).

#### `cv(array $v): float` — L296 (private)
Коэффициент вариации (stddev/mean).

#### `roundDist(float $m): int` — L306 (private)
Округление дистанции до 100/50/25 м в зависимости от величины.

#### `fmtPace(int $secPerKm): string` — L312 (private)
Форматирует сек/км в «m:ss».

## `planrun-backend/services/WorkoutService.php` (2432 строки)
Центральный сервис тренировок: день календаря, сохранение результатов, импорт из внешних источников (Strava/Garmin/Huawei/Suunto/GPX...), timeline/круги, post-workout coach flow (LLM-разбор + follow-up), VDOT-апдейты, очередь share-карточек, Suunto-зеркалирование, уведомления о рекордах.

### class WorkoutService extends BaseService — L16
Использует WorkoutRepository + WorkoutValidator; лениво создаёт WorkoutShareCardCacheService.

#### `__construct($db)` — L23
Инициализирует репозиторий (`WorkoutRepository`) и валидатор (`WorkoutValidator`).

#### `workoutShareCardCache(): WorkoutShareCardCacheService` — L29 (private)
Ленивый синглтон кэша share-карточек.

#### `queueWorkoutShareCards(int $userId, int $workoutId, string $workoutKind): int` — L36 (private)
Ставит карточки шаринга в очередь через `WorkoutShareCardCacheService::refreshQueuedCardsForWorkout`; возвращает число задач; ошибки только логирует.

#### `schedulePostWorkoutFollowup(int $userId, string $workoutDate, string $sourceKind, int $sourceId, ?int $analysisMessageId = null): bool` — L60 (private)
Планирует чек-ин самочувствия через `PostWorkoutFollowupService::scheduleForWorkout`; ошибки логирует, не пробрасывает.

#### `handlePostWorkoutCoachFlow(int $userId, string $workoutDate, string $sourceKind, int $sourceId): void` — L91 (private)
Оркестратор post-workout flow: follow-up → (если включён анализ и нет attached message) генерация LLM-разбора (`createPostWorkoutAnalysisMessage`) и привязка message_id к `post_workout_followups`.

#### `triggerPostWorkoutCoachFlow(int $userId, string $workoutDate, int $workoutId, string $sourceKind = 'workout'): void` — L133
Публичная точка входа в post-workout flow для внешних импортёров, пишущих в БД напрямую (по докблоку — Telegram-бот). Делегирует `handlePostWorkoutCoachFlow`. В репозитории вызовов не найдено.

#### `isPostWorkoutAnalysisEnabled(): bool` — L140 (private)
Фича-флаг POST_WORKOUT_ANALYSIS_ENABLED (env); в APP_ENV=testing по умолчанию выключен.

#### `getPostWorkoutFollowupRow(int $userId, string $sourceKind, int $sourceId): ?array` — L149 (private)
Читает строку из `post_workout_followups` (id, status, analysis_message_id).

#### `attachPostWorkoutAnalysisMessage(int $userId, string $sourceKind, int $sourceId, int $messageId): void` — L167 (private)
UPDATE `post_workout_followups.analysis_message_id`.

#### `createPostWorkoutAnalysisMessage(int $userId, string $workoutDate, string $sourceKind, int $sourceId): int` — L181 (private)
Собирает сводку факта + план дня + структуру (WorkoutStructureAnalyzer для kind='workout'), генерирует LLM-текст, сохраняет разбор (`persistWorkoutAnalysis`) и отправляет AI-сообщение в чат через ChatService::addAIMessageToUser (push + event_key). Возвращает message_id.

#### `fetchWorkoutAnalysisSummary(int $userId, string $sourceKind, int $sourceId): ?array` — L213 (private)
Сводка фактической тренировки: из `workouts` (kind='workout') или `workout_log`+`activity_types` (manual; добавляет rating/notes).

#### `fetchPlannedWorkoutForDate(int $userId, string $date): ?array` — L268 (private)
Первый плановый день на дату из `training_plan_days` JOIN `training_plan_weeks` (type, description, is_key_workout).

#### `maybeUpdateLastRaceFromImport(int $userId, string $workoutDate, ?float $distanceKm, ?int $durationSeconds, ?int $durationMinutes, ?string $avgPace): void` — L299 (private)
Если импортированный workout (≥4 км) пришёлся на плановый race/control день и новее текущего last_race — обновляет `users.last_race_*`, считает VDOT (estimateVDOT из prompt_builder, валидный диапазон 20–85) и запускает автопересчёт через WorkoutPlanRecalculationService. Читает `training_plan_days`, `users`; пишет `users`.

#### `formatSecToTime(int $sec): string` — L401 (private)
Секунды → «HH:MM:SS».

#### `persistWorkoutAnalysis(int $userId, string $sourceKind, int $sourceId, array $summary, ?array $planned, ?array $structure, string $llmText): void` — L408 (private)
Собирает данные разбора (включая feedback из `post_workout_followups`), формирует summary_line через `WorkoutAnalysisRepository::formatSummaryLine` и сохраняет через `WorkoutAnalysisRepository::save`. Ошибки только логирует.

#### `fetchLatestFeedbackForWorkout(int $userId, string $sourceKind, int $sourceId): array` — L467 (private)
Последний responded/completed feedback (RPE, legs, pain, fatigue) из `post_workout_followups`.

#### `generatePostWorkoutAnalysisText(int $userId, array $summary, ?array $planned, ?array $structure = null): string` — L483 (private)
Текст разбора: fake-ответ в testing, иначе факты → LLM (`callPostWorkoutAnalysisLlm`), при неудаче — статический фолбэк.

#### `buildPostWorkoutAnalysisFacts(array $summary, ?array $planned, ?array $structure = null): string` — L502 (private)
Формирует текстовый блок фактов для LLM: метрики тренировки, план дня, структура (lap-таблица с метками ▲/▽/·, автодетект интервалов по треку).

#### `callPostWorkoutAnalysisLlm(string $facts, int $userId): string` — L582 (private)
HTTP-вызов chat-LLM через LlmGateway::requestChatCompletion (LLM_CHAT_BASE_URL/MODEL) с жёстким русскоязычным system-промптом (3–4 предложения, без bullet'ов/эмодзи/похвал); ответ нормализует `normalizeLlmProse`, обрезает до 2500 символов; '' при ошибке.

#### `normalizeLlmProse(string $text): string` — L654 (private)
Снимает markdown bold/italic/backticks и ведущие bullet-маркеры построчно, схлопывает в один абзац (страховка от нарушения LLM запрета на списки).

#### `buildPostWorkoutAnalysisFallback(array $summary, ?array $planned): string` — L677 (private)
Статический текст «тренировка сохранена» при недоступном LLM.

#### `deleteWorkoutShareCards(int $userId, int $workoutId, string $workoutKind): void` — L694 (private)
Удаляет share-ассеты через `WorkoutShareCardCacheService::deleteWorkoutAssets`; ошибки логирует.

#### `launchWorkoutShareWorkerAsync(): void` — L716 (private)
Запускает detached-процесс `scripts/workout_share_worker.php --drain` через popen/exec (не из CLI). Побочный эффект — порождение фонового процесса.

#### `getAllResults($userId)` — L756
Все результаты тренировок через `WorkoutRepository::getAllResults`; оборачивает в ['results' => ...].

#### `getResult($date, $weekNumber, $dayName, $userId)` — L780
Результат за конкретный день через `WorkoutRepository::getResultByDate`. В контроллерах не вызывается — только unit-тест.

#### `getDay($date, $userId)` — L801
Полные данные дня календаря: все записи плана (`training_plan_days`, несколько в день), HTML-блоки плана с кнопками, номер недели (`training_plan_weeks`), импортированные тренировки (репозиторий getWorkoutsByDate + workout_url), ручная из `workout_log` (с дедупом по дистанции ±10%), структурированные упражнения (`ExerciseRepository::getExercisesByDayId`) с фолбэком на парсинг description (parseOfpSbuDescription) для старого формата.

#### `getDays($dates, $userId)` — L1145
Batch-обёртка над `getDay` для префетча недели (до 42 дат, формат YYYY-MM-DD); проблемные дни пропускает.

#### `saveResult($data, $userId)` — L1172
Валидация (WorkoutValidator), запрет будущих дат, автоподсчёт темпа и duration из result_time, upsert в `workout_log` (по user+date+week+day), инвалидация кеша training_plan, постановка share-карточек + запуск воркера, post-workout coach flow. Возвращает workout_log_id.

#### `importWorkouts($userId, array $workouts, $source)` — L1312
Импорт нормализованных тренировок: дедуп по (external_id, source), по (start_time, source) для GPX, кросс-источник ±2 мин и timezone-offset-эвристика (кратно часу ±5 мин + совпадение дистанции/длительности/HR). Классифицирует тип (`WorkoutClassifier::classify` с VDOT-зонами из TrainingStateBuilder), пишет `workouts` + timeline + laps, ставит Suunto-зеркало и share-карточки, запускает post-workout flow, синк last_race, инвалидация кеша, уведомления (`emitImportNotifications`). Возвращает ['imported', 'skipped'].

#### `const PR_BUCKETS` — L1512 (private)
Бакеты дистанций (5к/10к/полумарафон/марафон) для детекта личных рекордов.

#### `formatSeconds(int $sec): string` — L1519 (private)
Секунды → «H:MM:SS» или «M:SS».

#### `emitImportNotifications(int $userId, string $source, array $details): void` — L1531 (private)
In-app уведомления по итогам импорта через PlanNotificationService: «Загружена тренировка» (только Strava) и «Личный рекорд» (сравнение с историческим best в `workouts` по бакетам PR_BUCKETS).

#### `saveProgress($data, $userId)` — L1608
Upsert прогресса в legacy-таблицу `training_progress` (completed, result_time/distance/pace, notes) + инвалидация кеша training_plan.

#### `resetProgress($userId)` — L1684
Удаляет все записи пользователя из `workout_log` и инвалидирует кеш.

#### `deleteWorkout($workoutId, $isManual, $userId)` — L1720
Удаляет ручную тренировку из `workout_log` либо (в транзакции) импортированную из `workouts` + `workout_timeline` + `workout_laps`; чистит share-карточки.

#### `saveWorkoutTimeline(int $workoutId, ?array $timeline): void` — L1817 (private)
Перезаписывает `workout_timeline`: DELETE + батч-INSERT по 500 строк; downsample до 500 точек (TIMELINE_MAX_POINTS) с сохранением последней.

#### `maybeEnqueueSuuntoMirror(int $userId, int $workoutId, string $source): bool` — L1868 (private)
Ставит тренировку в `suunto_upload_queue` (INSERT IGNORE), если включено зеркалирование: env SUUNTO_MIRROR_USERS + `users.suunto_mirror_enabled` + подключённый Suunto-токен; источник 'suunto' не зеркалит.

#### `launchSuuntoUploadWorkerAsync(): void` — L1913 (private)
Detached-запуск `scripts/suunto_upload_worker.php` (popen/exec, с заменой php-fpm на php и override SUUNTO_PHP_BIN); best-effort.

#### `saveWorkoutLaps(int $workoutId, ?array $laps): void` — L1946 (private)
Перезаписывает `workout_laps`: DELETE + мульти-INSERT с ON DUPLICATE KEY UPDATE; duration_seconds — фолбэк для elapsed/moving_seconds; полный лап хранится в payload_json.

#### `const TIMELINE_MAX_POINTS` — L2045 (private)
Лимит точек timeline (500) при записи и чтении.

#### `getWorkoutTimeline($workoutId, $userId)` — L2047
Проверяет владение тренировкой, читает `workout_timeline` с downsample через row_num % step (до 500 точек) и круги через `getWorkoutLaps`. Используется WorkoutController и WorkoutShareCardService.

#### `getWorkoutLaps(int $workoutId): array` — L2140 (private)
Читает `workout_laps`, мержит колонки с payload_json (payload приоритетнее), нормализует типы.

#### `tableExists(string $tableName): bool` — L2214 (private)
SHOW TABLES LIKE с per-instance кешем.

#### `getDataVersion(int $userId): string` — L2238
Версия данных для polling: конкатенация max id/count/updated_at по `workouts`, `workout_log`, `training_plan_days`, `training_day_exercises`.

#### `getWorkoutResultByDate(string $date, int $userId): ?array` — L2269
Одна строка `workout_log` по дате.

#### `checkVdotUpdateAfterResult(array $data, int $userId): void` — L2287
Если сохранённый результат — плановый control/race день (по week+day → `training_plan_days`): обновляет `users.last_race_*`, пересобирает состояние через TrainingStateBuilder, ставит автопересчёт (WorkoutPlanRecalculationService) и шлёт в чат сообщение с новым VDOT, зонами темпа и прогнозами (ChatService::addAIMessageToUser). Ошибки глотает в error_log.

#### `parseResultTimeSec(string $resultTime): int` — L2420 (private)
«HH:MM:SS»/«MM:SS» → секунды; 0 при невалидном формате.

## `planrun-backend/services/WorkoutShareCardBrowserRendererService.php` (130 строк)
Браузерный renderer PNG-карточек шаринга через Node.js + Playwright (`scripts/render_workout_share_card.mjs`); при недоступности вызывающий код использует GD-фолбэк.

### class WorkoutShareCardBrowserRendererService — L9
Обёртка над запуском внешнего Node-процесса с временными JSON-input/PNG-output файлами.

#### `__construct(?string $repoRoot = null, ?string $nodeBinary = null)` — L14
Вычисляет repoRoot, путь к .mjs-скрипту и node-бинарь (env NODE_BINARY или 'node').

#### `isAvailable(): bool` — L20
true, если .mjs-скрипт существует на диске.

#### `render(array $payload): array` — L28
Сериализует payload в temp-JSON, запускает Node-скрипт (`runProcess`), читает PNG из temp-файла; бросает RuntimeException при любой ошибке; temp-файлы чистит в finally. Возвращает {body, contentType}.

#### `runProcess(array $command): array` — L97 (private)
proc_open с пайпами stdout/stderr, возвращает [exitCode, combinedOutput]; при исключении — terminate+close.

## `planrun-backend/services/WorkoutShareCardCacheService.php` (546 строк)
Кэш (`workout_share_cards` + PNG в storage/share-cards) и очередь (`workout_share_jobs`) фоновой генерации карточек шаринга. Используется WorkoutService, WorkoutController и scripts/workout_share_worker.php.

### class WorkoutShareCardCacheService extends BaseService — L8
Константы: `JOBS_TABLE` L9, `CARDS_TABLE` L10; статусы `STATUS_PENDING` L12, `STATUS_RUNNING` L13, `STATUS_COMPLETED` L14, `STATUS_FAILED` L15; виды `KIND_WORKOUT` L17, `KIND_MANUAL` L18; шаблоны `TEMPLATE_ROUTE` L20, `TEMPLATE_MINIMAL` L21; версии рендера `RENDERER_VERSION` L22 (v3-playwright), `RENDERER_VERSION_CLIENT` L23 (v4-client-html2canvas); private `MIN_VALID_IMAGE_WIDTH`/`MIN_VALID_IMAGE_HEIGHT` L24–25 (200×200).

#### `refreshQueuedCardsForWorkout(int $userId, int $workoutId, string $workoutKind = KIND_WORKOUT): array` — L32
Определяет нужные шаблоны (`detectTemplatesForWorkout`), удаляет старые ассеты и ставит job на каждый шаблон. Возвращает результаты enqueue.

#### `enqueue(int $userId, int $workoutId, string $workoutKind, string $template, int $maxAttempts = 2): array` — L53
INSERT в `workout_share_jobs` со status=pending; дедуп по активной (pending/running) задаче через `findActiveJob`. Возвращает {job_id, queued, deduplicated, status}.

#### `reserveNextJob(): ?array` — L94
Берёт старейшую pending-задачу с available_at<=NOW() и атомарно переводит в running (UPDATE ... WHERE status=pending), инкрементируя attempts; null если нечего брать или гонка проиграна.

#### `markCompleted(int $jobId, array $result = []): void` — L133
UPDATE job → completed с result_json и finished_at.

#### `markFailed(int $jobId, string $errorMessage, int $attempts, int $maxAttempts, int $retryDelaySeconds = 300): void` — L148
При attempts < maxAttempts возвращает в pending с отложенным available_at (+300 сек), иначе финальный failed.

#### `getCachedCard(int $userId, int $workoutId, string $workoutKind, string $template, ?string $preferredRendererVersion = null): ?array` — L172
Читает запись из `workout_share_cards`, валидирует renderer_version (устаревшие — удаляет вместе с файлами), наличие файла и валидность PNG (`isValidRenderedImageBody`); возвращает строку + absolute_path + body или null.

#### `storeRenderedCard(int $userId, int $workoutId, string $workoutKind, string $template, array $rendered): array` — L234
Валидирует image body, атомарно пишет PNG (tempnam + rename, chmod 0664) в storage/share-cards/user_N/, upsert метаданных в `workout_share_cards` (ON DUPLICATE KEY UPDATE).

#### `isValidRenderedImageBody($body): bool` — L318 (private)
getimagesizefromstring: минимум 200×200 и mime png/jpeg.

#### `deleteWorkoutAssets(int $userId, int $workoutId, string $workoutKind): void` — L338
Удаляет PNG-файлы (`deleteCardFiles`), записи из `workout_share_cards` и pending/running jobs из `workout_share_jobs`.

#### `clearPendingJobsForCard(int $userId, int $workoutId, string $workoutKind, string $template): void` — L367
DELETE pending-задач конкретной карточки (используется контроллером после клиентского рендера).

#### `isInfrastructureAvailable(): bool` — L387
true, если существуют обе таблицы (jobs + cards).

#### `detectTemplatesForWorkout(int $userId, int $workoutId, string $workoutKind): array` — L394 (private)
Manual → только minimal; импортированная → minimal + route, если в `workout_timeline` ≥2 GPS-точек.

#### `findActiveJob(int $userId, int $workoutId, string $workoutKind, string $template): ?array` — L425 (private)
Последняя pending/running задача данной карточки.

#### `deleteCardRecord(int $userId, int $workoutId, string $workoutKind, string $template): void` — L443 (private)
DELETE одной записи из `workout_share_cards`.

#### `deleteCardFiles(int $userId, int $workoutId, string $workoutKind): void` — L460 (private)
unlink всех PNG-файлов карточек тренировки по file_path из БД.

#### `buildRelativePath(int $userId, int $workoutId, string $workoutKind, string $template): string` — L484 (private)
'share-cards/user_{id}/{kind}_{workoutId}_{template}.png'.

#### `resolveStoragePath(string $relativePath): string` — L494 (private)
Абсолютный путь внутри planrun-backend/storage/.

#### `normalizeTemplate(string $template): string` — L499 (private)
'minimal' либо 'route' (дефолт).

#### `normalizeWorkoutKind(string $workoutKind): string` — L507 (private)
'manual'/'log'/'workout_log' → KIND_MANUAL, иначе KIND_WORKOUT.

#### `assertInfrastructureAvailable(): void` — L515 (private)
RuntimeException 503 с подсказкой про миграции, если таблиц нет.

#### `tableExists(string $tableName): bool` — L524 (private)
SHOW TABLES LIKE с кешем (дубликат WorkoutService::tableExists).

## `planrun-backend/services/WorkoutShareCardService.php` (1074 строки)
Серверная генерация PNG-карточек шаринга: сперва браузерный renderer (Playwright), затем фолбэк на PHP GD (шаблоны route/minimal); карта — Mapbox/MapTiler static либо локально нарисованный маршрут.

### class WorkoutShareCardService extends BaseService — L15
Константы: `TEMPLATE_ROUTE` L16, `TEMPLATE_MINIMAL` L17; размеры `CARD_WIDTH` L19, `ROUTE_HEIGHT` L20, `MINIMAL_HEIGHT` L21, `CARD_PADDING` L23, `INNER_WIDTH` L24, `MAP_WIDTH` L26, `MAP_HEIGHT` L27, `MAP_REQUEST_WIDTH` L28, `MAP_REQUEST_HEIGHT` L29, `MAP_REQUEST_SCALE` L30; шрифты DejaVu `FONT_REGULAR` L32, `FONT_BOLD` L33, `FONT_ITALIC` L34, `FONT_BOLD_ITALIC` L35; словари `ACTIVITY_TYPE_LABELS` L37, `SOURCE_LABELS` L59, `MAP_ATTRIBUTIONS` L69 (все private).

#### `__construct($db)` — L78
Создаёт WorkoutService, WorkoutShareMapService и WorkoutShareCardBrowserRendererService.

#### `render(int $workoutId, int $userId, string $template = 'route', string $workoutKind = 'workout'): array` — L88
Главный вход (вызывается WorkoutController и workout_share_worker.php): грузит тренировку, для route — timeline (через WorkoutService::getWorkoutTimeline) и static map с 2 попытками (HTTP к Mapbox/MapTiler); строит модель и payload; пытается браузерный renderer, при ошибке — GD-рендер (renderRouteCard/renderMinimalCard) → PNG. Возвращает {body, contentType, fileName, mapProvider}.

#### `normalizeTemplate(string $template): string` — L197 (private)
'route'|'minimal', дефолт route.

#### `loadWorkoutForShare(int $workoutId, int $userId, string $workoutKind = 'workout'): array` — L208 (private)
Грузит импортированную и/или ручную тренировку в зависимости от kind ('any' пробует обе); 404 если не найдена.

#### `loadImportedWorkout(int $workoutId, int $userId): ?array` — L237 (private)
SELECT из `workouts`, нормализует типы полей, is_manual=false.

#### `loadManualWorkout(int $workoutId, int $userId): ?array` — L281 (private)
SELECT из `workout_log` JOIN `activity_types` (is_completed=1); duration_seconds из result_time, is_manual=true.

#### `parseTimeToSeconds($value): ?int` — L329 (private)
«MM:SS» / «HH:MM:SS» / чистые секунды → int; null при невалидном.

#### `hasRoutePoints(array $timeline): bool` — L355 (private)
Есть ли хоть одна точка с числовыми lat/lng.

#### `buildModel(array $workout, string $template, ?string $mapAttribution): array` — L367 (private)
Вью-модель карточки: русские лейблы типа/источника, дата («9 ИЮНЯ»), дистанция/время/темп/пульс/высота, обрезанные заметки.

#### `buildBrowserPayload(array $model, array $timeline, ?array $mapPayload): array` — L400 (private)
JSON-payload для Node-рендера: card-поля, GPS-точки маршрута, static map как data:URL base64.

#### `formatDistanceValue($distanceKm): ?array` — L443 (private)
{value: '10,52', unit: 'км'} или null.

#### `formatDurationValue(array $workout): ?string` — L458 (private)
Длительность из duration_seconds («H:MM:SS»/«M:SS») или duration_minutes.

#### `formatRussianDate(DateTime $dateTime): string` — L479 (private)
«9 июня» (русские родительные месяцы).

#### `truncateText($value, int $maxLength): ?string` — L499 (private)
Схлопывает пробелы и обрезает с «…».

#### `buildFileName(array $model, string $template): string` — L519 (private)
'planrun-{дата}-{activity}[-{template}].png'.

#### `renderRouteCard(array $model, array $timeline, $mapImage = null)` — L534 (private)
GD-рендер route-карточки 840×1160: градиент, glow, wordmark, бейджи, крупная дистанция, панель времени, карта (static map либо `renderFallbackRouteMap`), тайлы метрик, attribution.

#### `renderMinimalCard(array $model)` — L637 (private)
GD-рендер minimal-карточки 840×980: дистанция/время, подзаголовок темпа, таблица «Тип/Источник/Время/Темп/Пульс/Высота».

#### `drawMetricTile($image, int $x, int $y, int $width, int $height, string $label, string $value, string $unit, bool $accent): void` — L689 (private)
Тайл одной метрики (фон/рамка/лейбл, accent-вариант оранжевый).

#### `drawWordmark($image, int $x, int $y, int $size): void` — L708 (private)
Логотип «planRUN» (italic + bold-italic, RUN оранжевым).

#### `createCanvas(int $width, int $height)` — L719 (private)
truecolor-канва с альфой и белым фоном.

#### `createTransparentCanvas(int $width, int $height)` — L729 (private)
Полностью прозрачная канва (для скруглённых изображений).

#### `color($image, string $value, float $opacity = 1.0): int` — L738 (private)
Аллокация цвета из '#hex' или 'rgba(...)' с альфой.

#### `gdAlpha(float $opacity): int` — L762 (private)
Opacity 0..1 → GD alpha 127..0.

#### `fillVerticalGradient($image, ...): void` — L767 (private)
Построчный вертикальный градиент между двумя hex-цветами.

#### `hexToRgb(string $value): array` — L783 (private)
'#abc'/'#aabbcc' → [r,g,b].

#### `drawSoftGlow($image, int $centerX, int $centerY, int $radius, string $colorHex, float $maxOpacity): void` — L796 (private)
Мягкое свечение из 18 концентрических полупрозрачных эллипсов.

#### `drawRoundedPanel($image, ...): void` — L807 (private)
Скруглённая панель: рамка (внешняя заливка) + внутренняя заливка.

#### `fillRoundedRect($image, ...): void` — L831 (private)
Скруглённый прямоугольник из 2 прямоугольников + 4 эллипсов.

#### `measureText(string $text, int $size, string $font): array` — L849 (private)
imagettfbbox → {width, height, left, top}.

#### `drawTextTop($image, ...): void` — L868 (private)
Текст с привязкой к верхнему левому углу (компенсация baseline).

#### `drawTextTopRight($image, ...): void` — L875 (private)
Текст, выровненный по правому краю.

#### `fitTextSize(string $text, int $startSize, int $minSize, int $maxWidth, string $font): int` — L881 (private)
Подбор максимального кегля, влезающего в ширину.

#### `font(string $variant): string` — L890 (private)
Путь к TTF DejaVu по варианту с фолбэками (bold-italic→bold, italic→regular).

#### `wrapText(string $text, int $size, string $font, int $maxWidth): array` — L917 (private)
Перенос текста по словам под максимальную ширину. Нигде в классе не вызывается — мёртвый код.

#### `createRoundedResizedImage($source, int $width, int $height, int $radius)` — L941 (private)
Ресайз изображения + прозрачные скруглённые углы попиксельно.

#### `renderFallbackRouteMap(array $timeline, int $width, int $height)` — L963 (private)
Локальная отрисовка маршрута без внешней карты: тёмный фон с сеткой, проекция GPS-точек (sample до ~180), трёхслойная оранжевая линия, маркеры старт/финиш, скругление.

## `planrun-backend/services/WorkoutShareMapService.php` (268 строк)
Генерация статической карты маршрута для share-карточек: Mapbox Static Images API → MapTiler Static Maps API; без настроенного провайдера бросает исключение (caller рисует SVG/GD-фолбэк).

### class WorkoutShareMapService — L11
Константы (private): `MAX_ROUTE_POINTS` L12 (140), `DEFAULT_ROUTE_COLOR` L13 (ea580c), `DEFAULT_MAPBOX_STYLE` L14, `DEFAULT_MAPTILER_STYLE` L15.

#### `render(array $timeline, int $width, int $height, int $scale = 2): array` — L21
Извлекает GPS-точки, клампит размеры (240–1280) и scale (1–2), выбирает провайдера по env-токенам; RuntimeException при <2 точках или отсутствии провайдера. Возвращает {body, contentType, provider}.

#### `hasMapboxConfig(): bool` — L42 (private)
env MAPBOX_ACCESS_TOKEN не пуст.

#### `hasMapTilerConfig(): bool` — L46 (private)
env MAPTILER_API_KEY не пуст.

#### `extractRoutePoints(array $timeline): array` — L54 (private)
Фильтрует валидные координаты (диапазоны, Null Island), округляет до 6 знаков, равномерно сэмплирует до 140 точек с сохранением последней.

#### `fetchMapboxMap(array $points, int $width, int $height, int $scale): array` — L109 (private)
Строит URL Mapbox Static Images (стиль из env MAPBOX_STATIC_STYLE, polyline-overlay через `encodePolyline`, @2x ретина) и грузит через `fetchRemoteImage`.

#### `fetchMapTilerMap(array $points, int $width, int $height, int $scale): array` — L151 (private)
Строит URL MapTiler Static Maps (path-параметр с координатами через `formatCoordinate`) и грузит изображение.

#### `fetchRemoteImage(string $url, string $provider): array` — L187 (private)
HTTP GET через cURL (timeout 20с, IPv4, UA PlanRunShareMap); RuntimeException при сетевой ошибке или не-2xx.

#### `encodePolyline(array $points): string` — L233 (private)
Google polyline-кодирование точек (дельты ×1e5).

#### `encodeSignedNumber(int $value): string` — L252 (private)
Кодирование одного знакового числа в polyline-формате (5-битные чанки +63).

#### `formatCoordinate(float $value): string` — L265 (private)
Число с 6 знаками без хвостовых нулей/точки.

## `planrun-backend/services/WorkoutStructureAnalyzer.php` (387 строк)
Структурный анализатор тренировки по `workout_laps`: классифицирует (interval/tempo/fartlek/easy/long/race/mixed), выделяет work/recovery-лапы, строит lap-таблицу и нарратив для LLM-разбора; уточняет тип потоковым детектором по таймлайну.

### class WorkoutStructureAnalyzer — L10
Константа `ZONE_THRESHOLDS` L12 (private) — пульсовые зоны z1–z5 по доле от max HR.

#### `__construct($db)` — L22
Сохраняет соединение с БД.

#### `analyze(int $workoutId, ?int $maxHrCap = null, ?int $userId = null): ?array` — L30
Главный вход (вызывается WorkoutService::createPostWorkoutAnalysisMessage и backfill/test-скриптами): грузит лапы (≥3 валидных: ≥0.5 км и ≥60 сек), считает медиану темпа и пороги ±15%, делит на fast/slow/normal, классифицирует по эвристикам (race: ≥21 км при интенсивности ≥0.82; interval: ≥2 fast + чередование; tempo/fartlek/long/easy по variance и HR%). Возвращает массив с типом, confidence, лапами, lap_table, narrative; затем уточняет через `detectStreamStructure` (поле 'detected', при размытом типе перезаписывает type/confidence/narrative). maxHr берёт из workout либо глобальный максимум юзера.

#### `detectStreamStructure(int $workoutId, ?int $maxHr): ?array` — L153 (private)
Грузит таймлайн (≥30 точек) и прогоняет `WorkoutSegmentDetector::detect`.

#### `loadTimeline(int $workoutId): array` — L161 (private)
SELECT timestamp/distance/pace/heart_rate из `workout_timeline` по id ASC.

#### `loadLaps(int $workoutId): array` — L184 (private)
SELECT лапов из `workout_laps` по lap_index ASC.

#### `fetchWorkoutMaxHr(int $workoutId): ?int` — L203 (private)
max_heart_rate тренировки из `workouts`.

#### `fetchUserGlobalMaxHr(int $userId): ?int` — L214 (private)
Среднее топ-3 max_heart_rate (100–210) за 12 мес из `workouts` — сглаживает сенсорные выбросы.

#### `median(array $values): float` — L242 (private)
Медиана массива.

#### `mean(array $values): ?float` — L253 (private)
Среднее по положительным значениям; null если пусто.

#### `relativeVariance(array $paces): float` — L259 (private)
Относительное стандартное отклонение темпа (stddev/mean).

#### `hasAlternation(array $laps, float $fastT, float $slowT): bool` — L271 (private)
Ищет паттерн F → не-F → F в последовательности лапов (признак интервалов).

#### `summariseLaps(array $laps, ?int $maxHr): array` — L287 (private)
Лапы → {idx, km, pace, pace_sec, hr, hr_pct, zone}.

#### `buildLapTable(array $laps, float $fastT, float $slowT, ?int $maxHr): array` — L301 (private)
Таблица всех лапов с метками ▲ (быстрее) / ▽ (медленнее) / · для LLM-фактов.

#### `zoneForHr(?int $hr, ?int $maxHr): ?string` — L319 (private)
Имя пульсовой зоны (z1–z5) по ZONE_THRESHOLDS.

#### `formatPace(int $secPerKm): string` — L330 (private)
Сек/км → «m:ss».

#### `buildNarrative(string $type, array $fast, array $slow, array $normal, bool $alternating, ?int $maxHr): string` — L337 (private)
Русскоязычное описание тренировки по типу (интервалы с темпами/пульсом/восстановлением, темповая, фартлек, гонка, длительная, лёгкая, смешанная).

#### `pluralIntervals(int $n): string` — L372 (private)
Русская плюрализация «рабочий отрезок / отрезка / отрезков».

#### `pluralKm(int $n): string` — L380 (private)
Русская плюрализация «километр / километра / километров».
