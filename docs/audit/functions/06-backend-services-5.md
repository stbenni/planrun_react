# Backend services 5/6 (SuuntoFitBuilder…WeekService) — справочник функций

## `planrun-backend/services/SuuntoFitBuilder.php` (110 строк)
Собирает FIT-файл из тренировки PlanRun (summary + точки `workout_timeline`) для загрузки в Suunto Cloud. Кодирование делегирует Node-хелперу `scripts/generate_fit.mjs` (Garmin FIT SDK).

### class SuuntoFitBuilder — L6
Единственный класс файла; используется в `SuuntoProvider::pushWorkout` (providers/SuuntoProvider.php:587).

#### `__construct($db)` — L8
Сохраняет mysqli-соединение в `$this->db`.

#### `env(string $k, string $d)` — L10 (private)
Безопасная обёртка над глобальной `env()`: если функция не определена или вернула null — возвращает дефолт. Используется для `SUUNTO_NODE_BIN`.

#### `buildFitFile(int $userId, int $workoutId)` — L18
Читает summary из `workouts` (тип, время, дистанция, HR, набор высоты) и трек из `workout_timeline`; темп «MM:SS» конвертирует в скорость м/с. Пишет payload во временный JSON, через `shell_exec` запускает Node-скрипт `generate_fit.mjs`, который генерирует .fit. Возвращает путь к временному .fit (удаляет caller) либо null при ошибке/отсутствии трека (<2 точек); при сбое логирует `Logger::warning`. БД: чтение `workouts`, `workout_timeline`; ФС: tempnam/unlink.

## `planrun-backend/services/TelegramLoginService.php` (492 строки)
OAuth/OIDC-вход через oauth.telegram.org (PKCE-флоу с file-based хранением состояния), привязка telegram_id к аккаунту и отправка сообщений через Bot API. Используется из `UserController` (auth_url), `api/telegram_login_callback.php` (callback), `NotificationDispatcher`/`UserProfileService` (отправка уведомлений).

### class TelegramLoginService — L5
Конфигурируется из env: `TELEGRAM_LOGIN_CLIENT_ID/SECRET/REDIRECT_URI/SCOPES`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_PROXY`, `JWT_SECRET_KEY` (для HMAC state).

#### `__construct($db)` — L17
Читает env-конфиг (client_id/secret/redirect_uri/scopes, добавляя `openid` при отсутствии), резолвит токен бота через `resolveBotToken()`, настраивает прокси.

#### `isBotConfigured()` — L33
true, если токен бота непустой. Вызывается из `NotificationSettingsService` (готовность Telegram-канала).

#### `resolveBotToken()` — L37 (private)
Берёт `TELEGRAM_BOT_TOKEN` из env, при отсутствии подгружает внешний конфиг бота (`loadExternalBotConfig`) и читает константу `TELEGRAM_BOT_TOKEN`. Возвращает '' если нигде нет.

#### `loadExternalBotConfig()` — L55 (private static)
Одноразово (static-флаг) подключает `planrun-bot/bot/config.php` (или путь из `TELEGRAM_BOT_CONFIG_PATH`) через require_once.

#### `getCurlOpts(array $extra = [])` — L78 (private)
Базовые cURL-опции + проксирование (`CURLOPT_PROXY`, SOCKS5 при префиксе socks5).

#### `isConfigured()` — L92
true, если заданы client_id, client_secret и redirect_uri OAuth-приложения.

#### `createAuthorizationUrl(int $userId, bool $fromApp = false)` — L96
Создаёт PKCE-флоу: чистит протухшие флоу, генерит flow_id + code_verifier, сохраняет в файл (`saveFlow`), собирает подписанный HMAC-SHA256 state и возвращает URL `https://oauth.telegram.org/auth?...` с code_challenge S256. Бросает Exception если не сконфигурирован. ФС: запись flow-файла в tmp.

#### `getFlowFromState(string $state)` — L137
Проверяет HMAC-подпись state, TTL 10 минут, загружает flow-файл и сверяет uid. Возвращает `{flow_id, uid, app, code_verifier}`; бросает Exception при любом несоответствии.

#### `deleteFlow(string $flowId)` — L179
Удаляет файл флоу из tmp-каталога (вызывается из callback после завершения/ошибки).

#### `exchangeCodeForTokens(string $code, string $codeVerifier)` — L186
HTTP POST на `https://oauth.telegram.org/token` (Basic-auth client_id:secret, grant authorization_code + PKCE verifier). Возвращает массив токенов с `id_token`; бросает Exception при ошибке.

#### `validateIdToken(string $idToken)` — L224
Декодирует и валидирует JWT id_token через Firebase JWT/JWK по JWKS Telegram (`getJwks`): проверяет issuer `https://oauth.telegram.org`, audience = client_id, числовой `id`. Возвращает claims; требует composer-зависимость firebase/php-jwt.

#### `linkTelegramAccount(int $userId, array $claims)` — L264
Проверяет, что telegram_id не привязан к другому юзеру (SELECT users), затем UPDATE `users` (telegram_id, сброс telegram_link_code/_expires). Чистит кеш юзера (`clearUserCache` из user_functions.php). Бросает Exception если занят.

#### `sendWelcomeMessageIfConfigured(int $telegramId, ?string $displayName = null)` — L296
Шлёт HTML-приветствие через Bot API `sendMessage` (cURL POST, таймаут 15с) после привязки. При ошибке — `logWarning`, исключений не бросает.

#### `sendMessageIfConfigured(int $telegramId, string $title, string $body, array $options = [])` — L339
Универсальная отправка уведомления в Telegram: экранирует HTML, абсолютизирует ссылку через `APP_URL`, POST на Bot API `sendMessage`. Возвращает bool успеха; используется `NotificationDispatcher` и `UserProfileService::sendTestNotification`. Дублирует cURL-логику `sendWelcomeMessageIfConfigured`.

#### `getJwks()` — L396 (private)
Возвращает JWKS Telegram: файл-кеш на 1 час в tmp (`telegram_login_jwks.json`), при промахе — HTTP GET `oauth.telegram.org/.well-known/jwks.json`; бросает Exception при сбое.

#### `saveFlow(array $data)` — L424 (private)
Пишет JSON флоу в файл (`getFlowPath`), создаёт каталог при необходимости; Exception при ошибке записи.

#### `loadFlow(string $flowId)` — L436 (private)
Читает и декодирует JSON флоу из файла; null если нет/битый.

#### `cleanupStaleFlows()` — L446 (private)
Удаляет flow_*.json старше 1 часа из каталога флоу.

#### `getFlowDir()` — L459 (private)
Путь каталога флоу: `<tmp>/planrun_oidc/telegram_login_flows`.

#### `getFlowPath(string $flowId)` — L463 (private)
Путь файла флоу; flowId санитизируется до hex-символов.

#### `getCacheDir()` — L467 (private)
Создаёт (0770) и возвращает `<tmp>/planrun_oidc`.

#### `buildCodeChallenge(string $codeVerifier)` — L475 (private)
PKCE S256: base64url(sha256(verifier)).

#### `base64UrlEncode(string $value)` — L479 (private)
base64url-кодирование без padding.

#### `logWarning(string $message, array $context = [])` — L483 (private)
Лениво подключает config/Logger.php и пишет `Logger::warning`.

## `planrun-backend/services/TelegramMiniAppService.php` (185 строк)
Валидация `initData` Telegram Mini App (HMAC по токену бота по спецификации WebApp) и резолв/автосоздание пользователя PlanRun по telegram_id. Используется в `AuthController` (телеграм-вход в Mini App).

### class TelegramMiniAppService — L12
Конфиг: `TELEGRAM_BOT_TOKEN` (или внешний конфиг бота), `TELEGRAM_MINIAPP_MAX_AGE_SECONDS` (default 86400).

#### `__construct(mysqli $db)` — L18
Резолвит токен бота, читает max-age подписи (защита от нуля/отрицательных).

#### `isConfigured()` — L27
true, если токен бота известен.

#### `validateInitData(string $initData)` — L37
Парсит query-string initData, строит data_check_string (все поля кроме hash, ksort), HMAC-SHA256 с ключом `HMAC(botToken, 'WebAppData')`, сравнивает с hash (`hash_equals`), проверяет свежесть `auth_date`. Возвращает данные TG-пользователя `{id, first_name, last_name, username, language_code, photo_url}`; Exception(401/503) при любой ошибке.

#### `resolveOrCreateUser(array $tgUser, ?string $timezone = null)` — L98
Ищет юзера по `users.telegram_id`; если нет — создаёт через `RegistrationService::registerFromTelegram` (авто-онбординг). Возвращает `{user_id, username, is_new, onboarding_completed}`. БД: SELECT users; запись — внутри RegistrationService.

#### `parseInitData(string $initData)` — L130 (private)
Разбирает `k=v&...` с urldecode в ассоциативный массив (без потери пустых значений).

#### `resolveBotToken()` — L148 (private)
Копия одноимённого метода TelegramLoginService: env → внешний конфиг бота → ''.

#### `loadExternalBotConfig()` — L166 (private static)
Копия одноимённого метода TelegramLoginService (отдельный static-флаг класса).

## `planrun-backend/services/TrainingLoadService.php` (421 строка)
Расчёт тренировочной нагрузки: TRIMP (Banister), rTSS по темпу, ATL/CTL/TSB (EMA 7/42 дня, модель TrainingPeaks PMC) и ACWR. Используется `StatsController::getTrainingLoad` и chat-tool в `ChatToolRegistry`.

### class TrainingLoadService — L5
Наследует BaseService; лениво создаёт `UserRepository`.

#### `userRepo()` — L8 (private)
Ленивая инициализация `UserRepository` (`??=`).

#### `computeTrimp(float $durationMin, float $avgHr, float $restHr, float $maxHr)` — L17
Чистая формула Banister TRIMP: `min × ΔHRratio × 0.64 × e^(1.92×ΔHRratio)`; null при невалидных параметрах. Без побочных эффектов.

#### `parsePaceSec(?string $pace)` — L30 (private)
Парсит «MM:SS» в сек/км; null если формат или диапазон (120–900 сек) невалиден.

#### `estimateTrimpFromPace(float $durationMin, int $paceSec, int $easyPaceSec)` — L53
rTSS-оценка нагрузки без пульса: IF = thresholdPace/actualPace (threshold ≈ easy×0.82, cap IF 1.30), `rTSS = h × IF³ × 100`, приведение к Banister-шкале ×1.6. Чистая функция.

#### `getUserHrParams(int $userId)` — L75
HR-параметры юзера из `UserRepository::getForHrCalculation` (max_hr/rest_hr из БД с валидацией диапазонов), fallback: max_hr = 220−возраст, rest_hr = 60. Возвращает `{max_hr, rest_hr, source}`. Публичный, но вызывается только внутри сервиса.

#### `computeAndCacheWorkoutTrimp(int $workoutId, ?array $hrParams = null)` — L109
Считает TRIMP одной тренировки из `workouts` и кеширует UPDATE `workouts.trimp`. Внешних вызовов в проекте не найдено (suspected dead).

#### `recalculateAllTrimp(int $userId)` — L147
Пересчитывает и пишет `workouts.trimp` для всех тренировок юзера с пульсом (например после смены max_hr/rest_hr); возвращает число обновлённых. Внешних вызовов не найдено (suspected dead).

#### `getTrainingLoad(int $userId, int $days = 90)` — L188
Главный метод: собирает дневной TRIMP за days+42 дней из `workouts` (только бег, HR-based, с дедупликацией) и `workout_log` (manual; TRIMP по HR либо rTSS по темпу через `users.easy_pace_sec`; дедуп с авто-записями по дистанции ±15%), строит EMA-серии ATL(7)/CTL(42)/TSB, последние 14 дней тренировок, ACWR. Возвращает `{available, current{atl,ctl,tsb,acwr,acwr_status}, hr_params, daily[], recent_workouts[], days_with_data}`. БД: чтение workouts, workout_log, users.

#### `computeAcwr(array $dailyTrimp)` — L391 (private)
ACWR = sum(7 дн)/avg-week(28 дн/4); статус detrained/optimal/caution/risk/insufficient (порог chronic <30 — недостаточно данных).

## `planrun-backend/services/TrainingPlanService.php` (577 строк)
Оркестрация жизненного цикла AI-плана тренировок: загрузка, статус (с авто-восстановлением), постановка generate/recalculate/next_plan в очередь `PlanGenerationQueueService`, readiness-check, удаление плана. Используется `TrainingPlanController` и `ChatToolRegistry` (tools recalculate_plan/generate_next_plan).

### class TrainingPlanService — L15
Наследует BaseService; агрегирует `TrainingPlanRepository`, `TrainingPlanValidator`, `PlanGenerationQueueService`, `PlanReadinessCheckService`.

#### `__construct($db)` — L22
Создаёт репозиторий, валидатор и оба вспомогательных сервиса.

#### `startSessionIfAvailable()` — L30 (private)
session_start(), если сессии нет, не CLI и заголовки не отправлены.

#### `normalizePlanningContext(array $options)` — L40 (private)
Нормализует поля secondary_race_* / tune_up_race_* (trim, отбрасывание пустых) для payload очереди; рекурсивно разворачивает вложенный массив `secondary_race`.

#### `loadPlan($userId, $useCache = true)` — L89
Обёртка над глобальной `loadTrainingPlanForUser()` (load_training_plan.php); пустой план → `{weeks_data:[], has_plan:false}`; ошибки заворачивает в 500.

#### `checkPlanStatus($userId)` — L116
Статус генерации: опрашивает очередь (`findLatestActiveJobForUser`/`findLatestJobForUser` + диагностика), `user_training_plans` через репозиторий. Активный job → generating; error_message → error; неактивный план без job, но с валидными неделями → авто-восстановление через `reactivatePlan` (recovered:true); failed job → error. Иначе has_plan по наличию weeks_data. БД: чтение плана/очереди, запись при авто-восстановлении.

#### `assertAiPlanMode($userId)` — L269 (private)
Читает `users.training_mode`; если не 'ai' — Exception 403 (генерация/пересчёт только для AI-режима; защита от прямого вызова API).

#### `regeneratePlan($userId)` — L288
Ставит job 'generate' в очередь: assert ai-mode, валидация, readiness-check gate, очистка error_message (репозиторий), enqueue, сообщение в `$_SESSION`. Возвращает `{message, job_id, queued}`.

#### `regeneratePlanWithProgress($userId)` — L329
То же для job 'recalculate' (без отдельной валидации): enqueue + деактивация активных планов + session-сообщение.

#### `extractGenerationDiagnostics(?array $job)` — L356 (private)
Сжимает строку очереди в `{job_id, job_type, status, finished_at, started_at, generation_metadata?, last_error?}` (metadata из result_json).

#### `recalculatePlan($userId, $reason = null, array $options = [])` — L387
Пересчёт плана с учётом причины и промежуточных стартов: payload = reason + normalizePlanningContext, readiness-gate, enqueue 'recalculate', деактивация планов, session-сообщение.

#### `generateNextPlan($userId, $goals = null, array $options = [])` — L424
Генерация следующего плана после завершения предыдущего: payload = goals + контекст, enqueue 'next_plan', деактивация планов, session-сообщение.

#### `submitReadinessCheckAnswer($userId, int $checkId, array $answer)` — L457
Делегат в `PlanReadinessCheckService::submitAnswer`.

#### `buildReadinessCheckResponse(int $userId, string $jobType, array $payload = [])` — L461 (private)
Если `PlanReadinessCheckService::maybeCreatePendingCheck` создал проверку самочувствия — возвращает ответ `requires_readiness_check:true` (генерация откладывается), иначе null.

#### `reactivatePlan($userId)` — L478
Восстановление из «вечного generating»: находит последний неактивный план в `user_training_plans`, деактивирует все, активирует его и чистит error_message; инвалидирует кеш `training_plan_{userId}`.

#### `deactivateActivePlans(int $userId)` — L513 (private)
UPDATE `user_training_plans` SET is_active=FALSE для активных планов юзера.

#### `clearPlan($userId)` — L533
Удаляет AI-план: DELETE `training_day_exercises`, `training_plan_days`, `training_plan_weeks` (workout_log сохраняется); чистит error_message и кеш плана.

#### `clearPlanGenerationMessage()` — L570
Убирает `$_SESSION['plan_generation_message']`.

## `planrun-backend/services/TrainingStateBuilder.php` (1944 строки)
Строит FACTS_JSON `training_state` для AI-генерации плана: VDOT (с приоритетами источников), темповые зоны Daniels, readiness, load_policy с понедельными таргетами объёма, compliance/самочувствие за последние недели, климат/сезон, реализм цели и pace_strategy «мост к цели». Используется в api_v2.php (generate), WorkoutService, MetricsService, PlanExplanationService, ChatToolRegistry, cron-скриптах.

### class TrainingStateBuilder — L11
Агрегирует StatsService, PostWorkoutFollowupService, AthleteSignalsService, лениво WorkoutRepository и PlanScenarioResolver. Опирается на функции из `planrun_ai/prompt_builder.php` (estimateVDOT, getTrainingPaces, predictRaceTime, assessGoalRealism и др.).

#### `__construct(mysqli $db)` — L19
Создаёт StatsService, PostWorkoutFollowupService, AthleteSignalsService.

#### `buildForUserId(int $userId)` — L26
Загружает поля юзера (goal, race, experience, weekly_base_km, last_race_*) из `users` и делегирует в `buildForUser`. Заметка: часть полей, читаемых buildForUser (birth_year, timezone, preferred_days и др.), этим SELECT не выбирается — они доступны только при вызове buildForUser с полной записью.

#### `buildForUser(array $user, string $mode = 'generate', array $payload = [])` — L58
Главный конструктор состояния: вычисляет VDOT каскадом источников (benchmark_override → best_result за 12 нед. / свежий last_race → stale race → easy_pace → target_time×0.92), темпы Daniels, weeks_to_goal, детренированность и эффективную weekly_base, readiness, special_population_flags, load_policy, intermediate_races, сигналы атлета и readiness-check; опционально (feature-флаги) добавляет planning_scenario, goal_realism, pace_strategy, recent_compliance(+summary, peak floor, historical peak), recent_workouts_detailed, season/climate, best_races. БД: чтение через StatsService/репозитории. Возвращает массив state.

#### `buildClimateContext(array $user, string $startDate, string $raceDate)` — L367 (private)
Месяц старта/гонки, полушарие (по timezone), season_phase — климат-контекст для FACTS_JSON.

#### `isNorthernHemisphere(string $timezone)` — L402 (private)
Эвристика: южное полушарие по списку таймзон (Australia, Sao_Paulo и т.п.), иначе северное.

#### `resolveSeasonPhase(int $month, bool $northern)` — L416 (private)
match месяца → winter/early_spring/spring/summer/autumn/late_autumn (для южного — сдвиг на 6 месяцев).

#### `buildBestRacesProgression(int $userId)` — L436 (private)
Обёртка `StatsService::getBestRacesProgression(userId, 52)` с подавлением исключений.

#### `matchBestRacesToTargetDistance(array $bestRaces, string $raceDistance)` — L450 (private)
Фильтрует best_races до бакета целевой дистанции (5k/10k/half/marathon) по alias-карте.

#### `isRecentContextFeatureEnabled()` — L473 (private)
Feature flag `PLANRUN_AI_STATE_RECENT_CONTEXT` (default on).

#### `buildRecentCompliance(int $userId, int $weeks = 4)` — L489 (private)
По ISO-неделям (фактически вызывается с weeks=8): через `WorkoutRepository::getDetailedCompliance` собирает planned/completed/skipped, actual_km, ключевые тренировки, compliance_ratio; пустые недели пропускает.

#### `buildRecentComplianceSummary(array $weeks, float $reportedWeeklyBaseKm)` — L561 (private)
Русская тренерская фраза по неделям: сегменты «с планом»/«без плана», тенденция объёма (вторая половина vs первая), historical peak, сравнение со заявленной базой (включая кейс post-race recovery).

#### `ruWeeks(int $n)` — L706 (private)
Склонение «неделя/недели/недель».

#### `ruWorkouts(int $n)` — L721 (private)
Склонение «тренировка/тренировки/тренировок».

#### `computePeakVolumeFloorKm(array $weeks, float $reportedWeeklyBaseKm)` — L746 (private)
peak_volume_floor_km = max(max(actual_km без outlier >130% медианы), median, base×0.85); null если данных нет.

#### `medianOfSorted(array $sortedValues)` — L787 (private)
Медиана отсортированного массива.

#### `formatKm(float $km)` — L799 (private)
Формат км: целое или 1 знак после точки.

#### `buildRecentWorkoutsDetailed(int $userId, int $days = 14)` — L811 (private)
Последние N дней через `WorkoutRepository::getRecentDetailedWorkouts`: тип, дистанция, темп (вычисленный или из поля), HR, RPE(rating), заметки (обрезка 200 символов), is_key_workout, source.

#### `isScenarioFeatureEnabled()` — L870 (private)
Feature flag `PLANRUN_AI_STATE_SCENARIO` (default on).

#### `resolvePlanningScenario(array $user, array $state, string $mode, array $payload)` — L879 (private)
Лениво создаёт `PlanScenarioResolver` и делегирует resolve(); null при исключении.

#### `resolveGoalRealism(array $user, array $state)` — L893 (private)
Для goal_type race/time_improvement зовёт `assessGoalRealism()` (prompt_builder) с подмешанным training_state; сжимает в `{verdict, severity, issues(≤3), recommended_target_time, recommended_weeks/sessions, predictions, vdot}`.

#### `getIntermediateRaces(int $userId, ?string $mainRaceDate)` — L971 (private)
Будущие дни типа 'race' (кроме главного старта) из `training_plan_days`+`training_plan_weeks`+`training_day_exercises` (дистанция из max distance_m категории run), LIMIT 10.

#### `buildPaceRules(?array $trainingPaces, array $user, ?float $vdot = null)` — L1010 (private)
Жёсткие границы темпов для генератора: easy/long/recovery диапазоны от Daniels-easy, tempo/interval/repetition с допусками, marathon/race/half/10k pace (через predictRaceTime либо интерполяцию). Fallback — `calculatePaceZones($user)`.

#### `computeWeeksToGoal(array $user, string $goalType)` — L1085 (private)
Недели от training_start_date (или сейчас) до race_date/weight_goal_date; null если цели-даты нет.

#### `computeVdotConfidence(?string $source, ?float $weeksOld, ?int $daysSinceLastWorkout)` — L1112 (private)
high/medium/low по источнику VDOT; деградация при weeksOld>12 и перерыве >14 дней.

#### `computeReadiness(?int $daysSinceLastWorkout, string $vdotConfidence, array $feedbackAnalytics = [], array $athleteSignals = [])` — L1134 (private)
Готовность к нагрузке: 'low' при боли/болезни/высоком recovery_risk/усталости (жёсткие пороги); подсчёт «умеренных» сигналов (≥3 → low, ≥1 → понижение базовой readiness).

#### `computeBaseReadiness(?int $daysSinceLastWorkout, string $vdotConfidence)` — L1207 (private)
Базовая readiness из давности тренировок × уверенности VDOT (high/normal/low).

#### `downgradeReadiness(string $readiness)` — L1220 (private)
Понижение на ступень: high→normal→low.

#### `buildLoadPolicy(array $user, string $goalType, string $readiness, ...)` — L1228 (private)
Самый большой метод-политика: allowed_growth_ratio (с feedback-зажимами 1.05–1.08), cutback/taper-коэффициенты, защита novice с низкой базой (quality_delay_weeks, caps), консервативный repair-профиль, floors для easy/long/tempo/complex, share-caps; затем из `computeMacrocycle`/`computeHealthMacrocycle` — recovery_weeks, weekly_volume_targets_km (`buildWeeklyVolumeTargets`), long_run_targets_km, start/peak volume, feedback_guard_level.

#### `buildWeeklyVolumeTargets(array $macrocycle, string $goalType, string $raceDistance, float $allowedGrowthRatio = 1.10, float $recoveryCutbackRatio = 0.88)` — L1412 (private)
Понедельные таргеты объёма: кривая start→peak (pow 0.92), taper по `resolveTaperRatios`, клиппинг роста между «нормальными» неделями, recovery-недели = предыдущая нормальная × cutback.

#### `resolveTaperRatios(string $goalType, string $raceDistance, int $taperWeeks)` — L1502 (private)
Коэффициенты taper-недель: длинные старты [0.82,0.68,0.52]…, короткие [0.90,0.78,0.66]…, не-гоночные цели — 0.90.

#### `formatVdotSourceLabel(?string $source)` — L1527 (private)
Русская подпись источника VDOT для промпта.

#### `workoutRepo()` — L1539 (private)
Ленивая инициализация WorkoutRepository.

#### `resolveEffectiveWeeklyBaseKm(float $reportedWeeklyBaseKm, ?int $daysSinceLastWorkout, ?float $detrainingFactor)` — L1543 (private)
Срезает заявленную базу при перерыве: потолок 0.85/0.75/0.60 (14/21/28+ дней) и detraining-фактор (floor 0.50).

#### `getDaysSinceLastWorkout(int $userId)` — L1570 (private)
Делегат `WorkoutRepository::getDaysSinceLastWorkout`.

#### `computeAgeYears($birthYear)` — L1574 (private)
Возраст по году рождения (валидация 1900..текущий год).

#### `detectSpecialPopulationFlags(...)` — L1588 (private)
Флаги особых групп: 65+, перерыв >14 дней, low_confidence_vdot, сигналы боли/усталости/болезни/сна/стресса/перелётов из feedback и notes, по health_notes regex — беременность/травма/хроника.

#### `hasHighSubjectiveLoad(array $feedbackAnalytics)` — L1650 (private)
Высокая субъективная нагрузка: subjective_load_delta ≥0.75, RPE ≥7.5 с дельтой/риском, legs/breath/hr_strain ≥8.

#### `hasModerateSubjectiveLoad(array $feedbackAnalytics)` — L1665 (private)
Умеренная: high ИЛИ delta ≥0.45 / RPE-дельта ≥0.75 / RPE ≥7.0.

#### `getLatestPlanReadinessCheckAnswer(int $userId)` — L1672 (private)
`PlanReadinessCheckService::getLatestValidAnswer` с подавлением исключений.

#### `applyPlanReadinessCheckAnswer(array $feedbackAnalytics, array $athleteSignals, array $answer)` — L1680 (private)
Если ответ readiness-check = clear/mild_clear — капит recovery_risk (0.30/0.45), обнуляет pain-сигналы, чистит planning_biases 'protect_injury' и болевые highlights, помечает plan_readiness_check_applied.

#### `compactPlanReadinessCheckAnswer(?array $answer)` — L1726 (private)
Компактная версия ответа readiness-check для state (id, даты, pain_score, флаги, interpretation).

#### `getRecentFeedbackAnalytics(int $userId)` — L1742 (private)
`PostWorkoutFollowupService::getRecentFeedbackAnalytics(userId, 14)`; [] при ошибке. Используется только как fallback в getRecentAthleteSignals.

#### `getRecentAthleteSignals(int $userId)` — L1750 (private)
`AthleteSignalsService::getRecentSignalsSummary(userId, 14)`; при ошибке — fallback только на feedback-аналитику.

#### `computeGoalPaceSec(array $user)` — L1760 (private)
Целевой темп = race_target_time / race_distance (сек/км); null если данных нет.

#### `buildPaceStrategy(array $user, array $state)` — L1780 (private)
PR9 «мост к цели»: считает predicted time по текущему VDOT, gap_pct к цели, stretch-VDOT (рост 0.10–0.30/нед по уровню, cap 3.0); выбирает mode goal_target/stretch_target/realistic_target и возвращает goal_paces (Daniels от effective VDOT) + current_paces для FACTS_JSON.

#### `parseDistanceKm(?string $distance, ?string $distanceKm)` — L1898 (private)
Дистанция в км: явное число либо alias-карта ('5k', 'half', 'marathon', ... '100k').

#### `parseTimeSec(?string $time)` — L1924 (private)
Парсит «H:MM:SS»/«MM:SS» в секунды; эвристика для «MM:SS:xx», введённого как H:M:S (минуты >20 при <2 часах).

## `planrun-backend/services/UserProfileService.php` (774 строки)
Управление профилем пользователя: чтение/обновление (динамический UPDATE с валидацией ~40 полей), каскадное удаление юзера, приватность, аватар, Telegram-привязка, тестовые уведомления. Используется `UserController`.

### class UserProfileService — L12
Наследует BaseService; использует AvatarService и точечно подключает push/telegram/email-сервисы.

#### `getProfile(int $userId)` — L17
`getUserData()` (user_functions.php, с кешем выключенным), декодирует preferred_days/preferred_ofp_days из JSON, убирает password. 404 если нет.

#### `updateProfile(int $userId, array $data)` — L41
Динамически собирает UPDATE `users` по присутствующим ключам: username (уникальность + длина, плюс username_slug через `generateUsernameSlug`), email, имя/фамилия, timezone, gender/birth_year/birth_month/height/weight (диапазоны), goal_type/race_*, experience_level/weekly_base_km/sessions_per_week/preferred_days(JSON)/training_mode, health-поля, расширенный профиль бегуна (easy_pace_sec, last_race_*), avatar_path (через `AvatarService::normalizeStoredAvatarPath`), bool-поля приватности и push, push_workout_hour/minute, privacy_level (+ генерация public_token при 'link'). Использует локальную closure `$normalizeNull` и хелпер `addNullableStringField`. После UPDATE — `clearUserCache`, возвращает свежий getProfile. БД: SELECT/UPDATE users.

#### `deleteUser(int $targetUserId, int $adminUserId)` — L457
Полное каскадное удаление в транзакции: training_day_exercises/plan_days/weeks/phases, user_training_plans, training_progress, workout_log, integration_tokens; опциональные таблицы (workout_timeline, workout_laps, password_reset_tokens, refresh_tokens, notification_dismissals, push_tokens — с проверкой SHOW TABLES), workouts, user_coaches (обе стороны), users. Вне транзакции — удаление аватара с диска и clearUserCache. Запрет самоудаления.

#### `updatePrivacy(int $userId, string $privacyLevel)` — L570
UPDATE `users.privacy_level`; для 'link' генерит/возвращает public_token. clearUserCache.

#### `uploadAvatar(int $userId, array $file)` — L612
Сохраняет файл через `AvatarService::storeUploadedAvatar`, пишет avatar_path в users (при ошибке БД — откатывает файл), удаляет старый аватар с диска, чистит кеш. Возвращает path + свежий профиль.

#### `removeAvatar(int $userId)` — L647
NULL-ит avatar_path в users, удаляет файл через AvatarService, чистит кеш.

#### `generateTelegramLinkCode(int $userId)` — L672
Генерит 16-символьный hex-код привязки c TTL 10 минут, пишет в `users.telegram_link_code/_expires`, чистит кеш. Возвращает `{code, expires_at}`.

#### `unlinkTelegram(int $userId)` — L690
Обнуляет telegram_id и код привязки в users; clearUserCache.

#### `sendTestNotification(int $userId, string $channel, string $endpoint = '')` — L703
Тест канала: mobile_push → `PushNotificationService::sendToUser`, web_push → `WebPushNotificationService::sendToEndpoint` (требует endpoint), telegram → `TelegramLoginService::sendMessageIfConfigured` (telegram_id из users), email → `EmailNotificationService::sendToUser`. Логирует доставку через `NotificationSettingsService::logDelivery` (event 'system.test_notification'). Возвращает `{success, channel, error}`.

#### `addNullableStringField(array $data, string $key, array &$fields, array &$values, string &$types, callable $normalizeNull)` — L766 (private)
Хелпер updateProfile: добавляет nullable string-поле в массивы динамического UPDATE.

## `planrun-backend/services/WeatherService.php` (249 строк)
Прогноз погоды для тренировок через OpenWeatherMap 5-day/3-hour forecast с кэшем в таблице `weather_forecast_cache` (TTL 3 ч, ключ — округлённые до 0.5° координаты). Используется chat-tool'ом в `ChatToolRegistry` (get_weather_forecast).

### class WeatherService — L18
Наследует BaseService. Константы: `API_BASE` (L19, endpoint OWM), `CACHE_TTL` (L20, 10800 сек). Env: `WEATHER_API_KEY` (без ключа сервис тихо выключен), `WEATHER_CACHE_TTL_SECONDS`.

#### `__construct($db)` — L25
Читает api-ключ и TTL кэша (clamp 300..86400).

#### `isEnabled()` — L31
true при непустом WEATHER_API_KEY.

#### `getForecastForUser(int $userId, array $dates)` — L39
Резолвит локацию, берёт raw-прогноз из кэша или API (с записью в кэш), агрегирует по дням и фильтрует по запрошенным датам. Возвращает `{location, forecasts{date: {...}}}` либо null.

#### `resolveUserLocation(int $userId)` — L62 (private)
SELECT latitude/longitude/location_city/timezone из `users`; при отсутствии координат — fallback timezone → город из `timezoneToCityFallback`; иначе null.

#### `timezoneToCityFallback()` — L92 (private)
Статичная карта ~20 таймзон (РФ/СНГ + мировые) → координаты крупного города.

#### `locationKey(float $lat, float $lon)` — L117 (private)
Ключ кэша: координаты, округлённые до 0.5° (~50 км).

#### `fetchFromCache(float $lat, float $lon)` — L122 (private)
SELECT payload_json из `weather_forecast_cache` где expires_at > NOW(); null при промахе.

#### `saveToCache(float $lat, float $lon, array $payload)` — L135 (private)
INSERT … ON DUPLICATE KEY UPDATE в `weather_forecast_cache` с expires_at = now+TTL.

#### `fetchFromApi(float $lat, float $lon)` — L150 (private)
HTTP GET OpenWeatherMap forecast (units=metric, lang=ru, таймаут 10с); при не-200 — logError и null.

#### `aggregateByDay(array $raw)` — L179 (private)
Сворачивает 3-часовые слоты в дневные сводки: temp_min/max, суммы осадков/снега, max ветер, список условий + `buildHumanSummary`.

#### `buildHumanSummary(array $d)` — L223 (private)
Человекочитаемая строка: «+5°…+12°C, дождь, осадки 3.2 мм, ветер до 10 м/с».

#### `classifyConditions(array $dayForecast)` — L235
Эвристические теги для адаптации тренировки: extreme_heat/hot/cold/extreme_cold/rain/heavy_rain/snow/strong_wind. Чистая функция; вызывается из ChatToolRegistry.

## `planrun-backend/services/WebPushNotificationService.php` (250 строк)
Браузерные Web Push уведомления через VAPID (библиотека minishlink/web-push): регистрация/удаление подписок в `web_push_subscriptions` и отправка нотификаций. Используется `UserController` (подписки), `NotificationDispatcher` (канал web_push), `UserProfileService` (тест), скриптом генерации VAPID-ключей.

### class WebPushNotificationService — L15
Наследует BaseService. Env: `WEB_PUSH_VAPID_PUBLIC_KEY/PRIVATE_KEY/SUBJECT` (subject fallback — APP_URL или mailto).

#### `__construct($db)` — L20
Читает VAPID-ключи и subject из env.

#### `createVapidKeys()` — L32 (static)
Делегат `VAPID::createVapidKeys()`; используется `scripts/generate_web_push_vapid_keys.php`.

#### `isConfigured()` — L36
true, если заданы оба VAPID-ключа.

#### `getPublicKey()` — L40
Публичный VAPID-ключ ('' если не сконфигурирован). Вызовов в проекте не найдено — фронт получает ключ через NotificationSettingsService (env напрямую); suspected dead.

#### `registerSubscription(int $userId, array $subscription, ?string $userAgent = null)` — L44
UPSERT подписки (endpoint, p256dh, auth, user_agent, last_seen_at) в `web_push_subscriptions`; принимает keys как вложенный объект или плоско.

#### `unregisterSubscription(int $userId, ?string $endpoint = null)` — L79
DELETE подписки по endpoint либо всех подписок юзера; возвращает число удалённых.

#### `sendToUser(int $userId, string $title, string $body, array $data = [])` — L108
Шлёт push на все подписки юзера через `sendToSubscriptions`; false если не сконфигурирован/нет подписок.

#### `sendToEndpoint(int $userId, string $endpoint, string $title, string $body, array $data = [])` — L121
Шлёт push на конкретную подписку (SELECT по user_id+endpoint); используется тестовым уведомлением.

#### `getSubscriptionCount(int $userId)` — L147
COUNT(*) подписок юзера. Внешних вызовов не найдено (suspected dead).

#### `sendToSubscriptions(array $subscriptions, int $userId, ...)` — L159 (private)
Собирает JSON-payload (title/body/icon/badge/data.link), создаёт WebPush с VAPID, шлёт по одной (`sendOneNotification`); протухшие подписки удаляет (`deleteByEndpoint`), сбои логирует Logger::warning. true если хоть одна доставлена.

#### `getUserSubscriptions(int $userId)` — L222 (private)
SELECT endpoint/p256dh/auth всех подписок юзера.

#### `deleteByEndpoint(string $endpoint)` — L238 (private)
DELETE подписки по endpoint (очистка expired).

## `planrun-backend/services/WeeklyPlanAdaptationService.php` (686 строк)
Еженедельная LLM-адаптация плана: в воскресенье 21:00 локального времени юзера собирает «план vs факт» недели, ACWR/compliance/самочувствие, просит DeepSeek предложить JSON-патч (≤4 изменений, race/control защищены), валидирует, применяет через WeekService и уведомляет. Запускается cron-скриптом `scripts/weekly_plan_adaptation.php` и тест/dry-run скриптами.

### class WeeklyPlanAdaptationService — L17
Константы: `PROTECTED_TYPES` (L23, race/control), `ALLOWED_NEW_TYPES` (L24), `MAX_CHANGES` (L25, =4), `COOLDOWN_DAYS` (L26, =6), `SCHEDULE_HOUR/MINUTE/DOW` (L27-29, Вс 21:00).

#### `__construct($db)` — L31
Читает LLM-конфиг (`LLM_CHAT_BASE_URL`, `LLM_CHAT_MODEL`), вызывает `ensureSchema()` (CREATE TABLE IF NOT EXISTS на каждом конструировании).

#### `processAllUsers()` — L41
Cron-режим: для каждого eligible-юзера зовёт `processUser`, агрегирует статистику `{processed, adapted, skipped, errors, details}`; ошибки юзеров глотает с Logger::warning.

#### `processUser(int $userId, ?array $user = null, bool $forceIgnoreCooldown = false)` — L74
Полный пайплайн одного юзера: проверка training_mode='ai', cooldown 6 дней (`proactive_coach_log`), сбор входов (`collectInputs`), вызов LLM, валидация патча, применение, уведомление, запись cooldown. Возвращает `{adapted, reason/changes_count/summary}`.

#### `getEligibleUsers()` — L136 (private)
SQL: ai-режим, есть недели плана с не-прошедшим концом, активность за 14 дней (workout_log или workouts); затем PHP-фильтр «сейчас Вс 21:00 в таймзоне юзера» (точное совпадение минуты — cron должен бежать каждую минуту).

#### `loadUser(int $userId)` — L175 (private)
SELECT профильных полей юзера (timezone с fallback Europe/Moscow, race-цель).

#### `collectInputs(int $userId, array $user)` — L192 (private)
Собирает: план/факт прошедшей недели, план следующей недели, ACWR и weekly compliance (`ChatContextBuilder`), свежий feedback, историю плана (`WorkoutAnalysisRepository`: summary lines, weekly rollup, key workouts).

#### `getPlannedDays(int $userId, string $startDate, string $endDate)` — L225 (private)
SELECT дней из `training_plan_days` за диапазон дат.

#### `getActualWorkouts(int $userId, string $startDate, string $endDate)` — L244 (private)
Объединяет завершённые `workout_log` и импортированные `workouts` за период, сортирует по дате. (Дедупликации между источниками нет.)

#### `getRecentFeedback(int $userId)` — L287 (private)
Последние ≤10 записей `post_workout_followups` за 7 дней (RPE, scores, pain/fatigue флаги).

#### `callLlm(int $userId, array $inputs)` — L309 (private)
Строит промпт, шлёт через `LlmGateway::requestChatCompletion` (json_object, temp 0.3, 2 попытки, таймаут 60с), срезает markdown-ограждения, парсит JSON; null при сбое.

#### `buildPrompt(array $inputs)` — L363 (private)
Большой русскоязычный промпт: атлет/цель, план vs факт недели, метрики (ACWR, compliance), самочувствие, история объёмов и ключевых тренировок, план следующей недели с пометками [ЗАЩИЩЁН], правила адаптации и строгий JSON-формат ответа.

#### `validatePatch(array $changes, array $inputs)` — L502 (private)
Фильтрует изменения LLM: лимит MAX_CHANGES, дата только из следующей недели, защищённые типы не трогать, set_rest/change_type не для ключевого long, new_type из whitelist. Возвращает filtered_changes с day_id и old/new значениями.

#### `applyPatch(int $userId, array $changes)` — L567 (private)
Применяет каждое изменение через `WeekService::updateTrainingDayById` и логирует в `weekly_plan_adaptation_log` (`logChange`); ошибки отдельных изменений глотает. Возвращает число применённых.

#### `notifyUser(int $userId, string $summary)` — L594 (private)
Сообщение от AI-тренера через `ChatService::addAIMessageToUser` с event_key 'plan.weekly_adaptation' (попадёт в чат + push/каналы).

#### `isOnCooldown(int $userId)` — L612 (private)
Последняя запись 'weekly_adaptation' в `proactive_coach_log` моложе 6 дней?

#### `recordCooldown(int $userId)` — L629 (private)
INSERT события 'weekly_adaptation' в `proactive_coach_log`.

#### `ensureSchema()` — L641 (private)
CREATE TABLE IF NOT EXISTS `weekly_plan_adaptation_log`.

#### `logChange(int $userId, array $change)` — L656 (private)
INSERT строки аудита изменения (old/new type+description) в `weekly_plan_adaptation_log`.

#### `pluralChanges(int $n)` — L679 (private)
Склонение «изменение/изменения/изменений».

## `planrun-backend/services/WeekService.php` (403 строки)
CRUD недель и дней плана тренировок поверх `WeekRepository`/`ExerciseRepository` с валидацией и инвалидацией кеша `training_plan_{userId}`. Используется `WeekController`, `ChatToolRegistry` (чат-правки плана), `WeeklyPlanAdaptationService`.

### class WeekService — L12
Наследует BaseService; агрегирует WeekRepository, ExerciseRepository, WeekValidator.

#### `__construct($db)` — L18
Создаёт репозитории и валидатор.

#### `addWeek($data, $userId)` — L33
Валидация → `WeekRepository::addWeek` → инвалидация кеша плана. Возвращает `{success, week_id}`.

#### `deleteWeek($weekId, $userId)` — L70
Валидация → `WeekRepository::deleteWeek` → инвалидация кеша.

#### `addTrainingDay($data, $userId)` — L108
Валидация → `WeekRepository::addTrainingDay` → инвалидация кеша. Возвращает `{success, day_id}`.

#### `addTrainingDayByDate($data, $userId)` — L145
Календарная модель: по дате вычисляет понедельник, находит или создаёт неделю (`getWeekByStartDate`/`addWeek` с номером maxWeek+1), добавляет день с day_of_week/типом/описанием/is_key_workout. Инвалидация кеша.

#### `updateTrainingDayById($dayId, $userId, $data)` — L207
Валидация payload → `WeekRepository::updateTrainingDayById` (type/description/is_key_workout) → инвалидация кеша. Точка применения LLM-адаптаций и чат-правок.

#### `deleteTrainingDayById($dayId, $userId)` — L230
`WeekRepository::deleteTrainingDayById`; 404 если ничего не удалено. Инвалидация кеша.

#### `copyDay($sourceDate, $targetDate, $userId)` — L251
Копирует все тренировки одной даты на другую: создаёт целевую неделю при необходимости, копирует дни и их упражнения через `ExerciseRepository::getExercisesByDayId/addExercise`. Возвращает `{success, copied_days}`.

#### `copyWeek($sourceWeekId, $targetStartDate, $userId)` — L328
Копирует все дни недели на целевую неделю (target обязан быть понедельником): get-or-create целевой недели (наследует total_volume), пересчёт дат дней от целевого понедельника, копирование упражнений. Возвращает `{success, copied_days}`. Логика копирования упражнений дублирует copyDay.
