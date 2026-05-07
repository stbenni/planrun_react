# PlanRun - бэкенд

Бэкенд PlanRun построен вокруг action-based PHP API: внешние entrypoint'ы в `api/` проксируют запросы в `planrun-backend/api_v2.php`, после чего запрос попадает в контроллер, сервисный слой, репозитории и интеграционные провайдеры.

## Поток запроса

```text
Client -> api/api_wrapper.php?action=...
       -> planrun-backend/api_v2.php
       -> Controller
       -> Service
       -> Repository / Provider / AI module
       -> MySQL / external integrations / local LLM stack
```

## Основные слои

### 1. `api/` и `planrun-backend/api_v2.php`

- `api/api_wrapper.php` подключает CORS и session bootstrap.
- `api/oauth_callback.php`, `api/strava_webhook.php`, `api/chat_sse.php`, `api/telegram_login_callback.php` обслуживают внешние интеграции и специальные transport-сценарии.
- `planrun-backend/api_v2.php` содержит карту action'ов и dispatch в контроллеры, а также публичные inline routes вроде `get_avatar`, `get_site_settings`, `assess_goal`, `get_user_by_slug`.

### 2. Контроллеры

Контроллеры тонкие: читают параметры, проверяют доступ, вызывают сервисы и формируют JSON-ответ.

Подробный ручной разбор responsibilities, side effects и скрытых зависимостей controller/service/repository слоя вынесен в `12-BACKEND-APPLICATION-REFERENCE.md`.

| Контроллер | Ответственность |
|------------|-----------------|
| `AuthController` | login/logout, refresh token, check auth, password reset |
| `UserController` | профиль, privacy, avatar, Telegram linking, web push subscription |
| `TrainingPlanController` | загрузка плана, статус генерации, regenerate/recalculate/next plan |
| `WorkoutController` | day view, result CRUD, timeline, import/delete/reset, version polling |
| `WeekController` | недели и тренировочные дни плана |
| `ExerciseController` | упражнения тренировочного дня и exercise library |
| `StatsController` | статистика, сводка тренировок, race prediction, weekly analysis |
| `ChatController` | AI-chat, streaming, admin chat, direct dialogs, broadcast |
| `IntegrationsController` | OAuth URL, status, sync, unlink, token errors |
| `PushController` | register/unregister native push token |
| `CoachController` | каталог тренеров, заявки, pricing, группы и athlete relations |
| `NoteController` | заметки к дням и неделям, plan notifications |
| `AdminController` | users, site settings, notification templates, coach approvals |
| `AdaptationController` | запуск weekly adaptation |

### Основные методы контроллеров

| Контроллер | Методы |
|------------|--------|
| `AuthController` | `login`, `logout`, `refreshToken`, `requestPasswordReset`, `confirmPasswordReset`, `checkAuth` |
| `UserController` | `getProfile`, `updateProfile`, `getNotificationSettings`, `getNotificationDeliveryLog`, `updateNotificationSettings`, `registerWebPushSubscription`, `unregisterWebPushSubscription`, `sendTestNotification`, `deleteUser`, `uploadAvatar`, `getAvatar`, `removeAvatar`, `updatePrivacy`, `getNotificationsDismissed`, `dismissNotification`, `getTelegramLoginUrl`, `generateTelegramLinkCode`, `unlinkTelegram` |
| `TrainingPlanController` | `load`, `save`, `checkStatus`, `regeneratePlan`, `regeneratePlanWithProgress`, `recalculatePlan`, `generateNextPlan`, `reactivatePlan`, `clearPlan`, `clearPlanGenerationMessage` |
| `WorkoutController` | `dataVersion`, `getDay`, `saveResult`, `getResult`, `uploadWorkout`, `getAllResults`, `deleteWorkout`, `save`, `reset`, `getWorkoutTimeline` |
| `WeekController` | `addWeek`, `deleteWeek`, `addTrainingDay`, `addTrainingDayByDate`, `updateTrainingDay`, `deleteTrainingDay`, `copyDay`, `copyWeek` |
| `ExerciseController` | `addDayExercise`, `updateDayExercise`, `deleteDayExercise`, `reorderDayExercises`, `listExerciseLibrary` |
| `StatsController` | `stats`, `getAllWorkoutsSummary`, `getAllWorkoutsList`, `prepareWeeklyAnalysis`, `racePrediction` |
| `ChatController` | `getMessages`, `sendMessage`, `sendMessageStream`, `clearAiChat`, `sendMessageToAdmin`, `getDirectMessages`, `sendMessageToUser`, `sendAdminMessage`, `clearDirectDialog`, `getDirectDialogs`, `getAdminChatUsers`, `getAdminUnreadNotifications`, `getAdminMessages`, `broadcastAdminMessage`, `markAllRead`, `markAdminAllRead`, `markRead`, `markAdminConversationRead`, `addAIMessage` |
| `IntegrationsController` | `getOAuthUrl`, `getStatus`, `syncWorkouts`, `getStravaTokenError`, `unlink` |
| `PushController` | `registerToken`, `unregisterToken` |
| `CoachController` | `listCoaches`, `requestCoach`, `getCoachRequests`, `acceptCoachRequest`, `rejectCoachRequest`, `getMyCoaches`, `removeCoach`, `applyCoach`, `getCoachAthletes`, `getCoachPricing`, `updateCoachPricing`, `getCoachGroups`, `saveCoachGroup`, `deleteCoachGroup`, `getGroupMembers`, `updateGroupMembers`, `getAthleteGroups` |
| `NoteController` | `getDayNotes`, `saveDayNote`, `deleteDayNote`, `getWeekNotes`, `saveWeekNote`, `deleteWeekNote`, `getNoteCounts`, `getPlanNotifications`, `markPlanNotificationRead` |
| `AdminController` | `listUsers`, `getUser`, `updateUser`, `getPublicSettings`, `getSettings`, `updateSettings`, `getNotificationTemplates`, `updateNotificationTemplate`, `resetNotificationTemplate`, `getCoachApplications`, `approveCoachApplication`, `rejectCoachApplication` |
| `AdaptationController` | `runWeeklyAdaptation` |

### 3. Сервисы

Сервисный слой - основное место бизнес-логики.

Для глубокого ручного описания application-сервисов и их связей с контроллерами, чатами, очередями и notification stack смотрите `12-BACKEND-APPLICATION-REFERENCE.md`.

#### Базовые домены

- `AuthService` - авторизация, refresh tokens, password reset.
- `TrainingPlanService` - статус плана, загрузка, очистка, постановка задач генерации в очередь.
- `WorkoutService` - day/result workflow, импорт тренировок, timeline/laps, VDOT refresh.
- `StatsService` - агрегированная статистика и race prediction.
- `WeekService` и `ExerciseService` - CRUD плана на уровне недель, дней и упражнений.
- `RegisterApiService`, `RegistrationService`, `EmailVerificationService`, `JwtService`, `AvatarService`, `TelegramLoginService`.

### Основные публичные методы сервисов

| Сервис | Публичные методы |
|--------|------------------|
| `AuthService` | `login`, `logout`, `refreshToken`, `requestPasswordReset`, `confirmPasswordReset`, `validateJwtToken` |
| `TrainingPlanService` | `loadPlan`, `checkPlanStatus`, `regeneratePlan`, `regeneratePlanWithProgress`, `recalculatePlan`, `generateNextPlan`, `reactivatePlan`, `clearPlan`, `clearPlanGenerationMessage` |
| `WorkoutService` | `getAllResults`, `getResult`, `getDay`, `saveResult`, `importWorkouts`, `saveProgress`, `resetProgress`, `deleteWorkout`, `getWorkoutTimeline`, `maybeUpdateVdotFromWorkouts` |
| `StatsService` | `getStats`, `getAllWorkoutsSummary`, `getAllWorkoutsList`, `getBestResultForVdot`, `prepareWeeklyAnalysis` |
| `WeekService` | `addWeek`, `deleteWeek`, `addTrainingDay`, `addTrainingDayByDate`, `updateTrainingDayById`, `deleteTrainingDayById`, `copyDay`, `copyWeek` |
| `ExerciseService` | `addDayExercise`, `updateDayExercise`, `deleteDayExercise`, `reorderDayExercises`, `listExerciseLibrary` |
| `PlanGenerationQueueService` | `enqueue`, `reserveNextJob`, `markCompleted`, `markFailed`, `getJobById`, `findLatestActiveJobForUser`, `findLatestJobForUser`, `isQueueAvailable` |
| `PlanGenerationProcessorService` | `process`, `persistFailure` |
| `ChatService` | `sendMessageAndGetResponse`, `streamResponse`, `getMessages`, `clearAiChat`, `markAsRead`, `sendUserMessageToAdmin`, `sendUserMessageToUser`, `sendAdminMessage`, `getDirectMessagesWithUser`, `clearDirectDialog`, `getAdminMessages`, `getUsersWithAdminChat`, `getUsersWhoWroteToMe`, `getUnreadUserMessagesForAdmin`, `getAdminUnreadCount`, `markAllAsRead`, `markAllAdminAsRead`, `markAdminConversationRead`, `addAIMessageToUser`, `broadcastAdminMessage` |
| `NotificationSettingsService` | `ensureSchema`, `getSettings`, `saveSettings`, `canDeliver`, `hasAnyDeliverableChannel`, `getWorkoutReminderSchedule`, `logDelivery`, `getQuietHoursResumeAt`, `isInQuietHours`, `getEmailDigestMode`, `getNextEmailDigestAt`, `queueDelivery`, `queueEmailDigestItem`, `reserveDueEmailDigestUsers`, `reserveDueEmailDigestItemsForUser`, `markEmailDigestItemsCompleted`, `rescheduleEmailDigestItems`, `reserveDueQueuedDeliveries`, `markQueuedDeliveryCompleted`, `rescheduleQueuedDelivery`, `getDeliveryLog`, `acquireDispatchGuard`, `markDispatchGuardSent`, `releaseDispatchGuard` |
| `NotificationDispatcher` | `dispatchToUser`, `processQueuedDelivery` |
| `PushNotificationService` | `isPushAllowed`, `sendToUser`, `getUserTokens`, `sendToTokens`, `sendDataPush` |
| `WebPushNotificationService` | `isConfigured`, `getPublicKey`, `registerSubscription`, `unregisterSubscription`, `sendToUser`, `sendToEndpoint`, `getSubscriptionCount` |
| `PlanNotificationService` | `notify`, `notifyCoachPlanUpdated`, `notifyAthleteResultLogged`, `getUnread`, `markRead`, `markAllRead` |

#### Чат и AI

- `ChatService` - основной orchestrator AI-чата, direct messaging, streaming, tool calling, push after response.
- `ChatContextBuilder` - сбор контекста пользователя, плана, статистики и памяти.
- `DateResolver` - нормализация дат из текстовых запросов.
- `TrainingStateBuilder` - подготовка training state для AI и оценки целей.

#### Очередь генерации и пересчёт плана

- `PlanGenerationQueueService` - очередь задач генерации.
- `PlanGenerationProcessorService` - worker-side выполнение job.
- `WorkoutPlanRecalculationService` - корректировки плана по факту выполненных тренировок.
- `PlanSkeletonBuilder` - базовая сборка skeleton/planning state для генератора.

#### Уведомления

- `PlanNotificationService` - доменные события плана и coach-athlete уведомления.
- `NotificationSettingsService` - каналы доставки, quiet hours, digest, лог очередей.
- `NotificationDispatcher` - fan-out по каналам.
- `PushNotificationService` - native push.
- `WebPushNotificationService` - browser web push.
- `EmailNotificationService` и `NotificationTemplateService` - email-канал и шаблоны.

### 4. Репозитории

Репозитории изолируют SQL и возвращают данным сервисам структурированный результат.

- `TrainingPlanRepository`, `WorkoutRepository`, `WeekRepository`, `ExerciseRepository`
- `StatsRepository`, `ChatRepository`
- `NoteRepository`, `NotificationRepository`
- `BaseRepository` как общий слой helpers

### Основные методы репозиториев

| Репозиторий | Основные методы |
|-------------|-----------------|
| `TrainingPlanRepository` | `getPlanByUserId`, `updateErrorMessage`, `clearErrorMessage`, `getWeeksByUserId`, `getDaysByWeekId` |
| `WorkoutRepository` | `getAllResults`, `getResultByDate`, `getWorkoutsByDate` |
| `WeekRepository` | `getWeekById`, `getMaxWeekNumber`, `addWeek`, `deleteWeek`, `getWeekByWeekNumber`, `getWeekByDate`, `getWeekByStartDate`, `getDayByDate`, `addTrainingDay`, `updateTrainingDayById`, `deleteTrainingDayById`, `getDaysByDate`, `getDaysByWeekId` |
| `ExerciseRepository` | `getExercisesByDayId`, `addExercise`, `updateExercise`, `deleteExercise`, `getExerciseLibrary` |
| `StatsRepository` | `getTotalDays`, `getWorkoutDates`, `getWorkoutsSummary`, `getWorkoutLogSummary` |
| `ChatRepository` | `getOrCreateConversation`, `getConversationById`, `getMessages`, `getDirectMessagesBetweenUsers`, `getAdminTabMessages`, `getMessagesAscending`, `addMessage`, `searchInChat`, `deleteMessagesByConversation`, `touchConversation`, `getUnreadCounts`, `getAllUserIdsForBroadcast`, `getUsersWithAdminChat`, `getUsersWhoWroteToMe`, `getUnreadCountsPerDirectDialogPartner`, `deleteDirectMessagesBetweenUsers`, `markDirectDialogRead`, `getUnreadUserMessagesForAdmin`, `getAdminUnreadCount`, `markUserMessagesReadByAdmin`, `markAllConversationsReadForUser`, `markAllAdminUserMessagesRead`, `markMessagesRead` |
| `NoteRepository` | `getDayNotes`, `addDayNote`, `updateDayNote`, `deleteDayNote`, `getWeekNotes`, `addWeekNote`, `updateWeekNote`, `deleteWeekNote`, `getDayNoteCounts`, `getWeekNoteCounts` |
| `NotificationRepository` | `getDismissedIds`, `dismiss` |

### 5. Провайдеры и интеграции

- `StravaProvider`, `HuaweiHealthProvider`, `PolarProvider`
- `WorkoutImportProvider`
- `utils/GpxTcxParser.php` для GPX/TCX импорта

Эти модули используются как при явной синхронизации из UI, так и из webhook/cron сценариев.

### Основные методы провайдеров

| Провайдер | Методы |
|-----------|--------|
| `WorkoutImportProvider` | `getProviderId`, `getOAuthUrl`, `exchangeCodeForTokens`, `refreshToken`, `fetchWorkouts`, `isConnected`, `disconnect` |
| `StravaProvider` | `getProviderId`, `getOAuthUrl`, `exchangeCodeForTokens`, `refreshToken`, `fetchWorkouts`, `fetchSingleActivity`, `isConnected`, `ensureIntegrationHealthy`, `disconnect`, `ensureWebhookSubscription` |
| `HuaweiHealthProvider` | `getProviderId`, `getOAuthUrl`, `exchangeCodeForTokens`, `refreshToken`, `fetchWorkouts`, `isConnected`, `disconnect` |
| `PolarProvider` | `getProviderId`, `getOAuthUrl`, `exchangeCodeForTokens`, `refreshToken`, `fetchWorkouts`, `isConnected`, `disconnect` |

### 6. Валидация и ошибки

- `validators/*` - проверка payload для plan/workout/week/exercise доменов.
- `exceptions/*` - доменные исключения (`ValidationException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`).
- `config/error_handler.php`, `config/Logger.php`, `config/RateLimiter.php`, `config/env_loader.php`, `config/init.php`.

## AI-пайплайн

`planrun-backend/planrun_ai/` отвечает за генерацию и постобработку плана.

Важно: production-путь — **`LLM planner` (DeepSeek V4 single-pass)**. После Phase A (PR2) skeleton-first перенесён в `_legacy/`, staged-стратегия удалена. Legacy-пути сохранены только для backwards-compat / диагностики.

### Режимы генерации

1. `LLM planner` (production default — DeepSeek)
   `PLAN_GENERATION_MODE=llm_planner` — единственный production-путь. `PlanGenerationProcessorService::processViaLlmPlanner()` строит `trainingState` через `TrainingStateBuilder`, передаёт его в `DeepSeekPlanPlanner`, который через `LlmGateway` делает один запрос к DeepSeek API (`single_pass`, одна модель из `PLAN_LLM_MODEL`) и получает готовый план. Дальше план проходит `applySinglePassHardSafetyRepairs()` (если `PLAN_LLM_HARD_SAFETY_REPAIRS=1`), race-week capping (если `PLAN_LLM_RACE_WEEK_CAP_REPAIRS=1`), `PlanQualityGate` (auto / strict / permissive) и сохраняется. Skeleton-fallback **не используется** — при ошибке планировщика пользователь увидит сообщение «обратитесь к админу или повторите позже».
2. `Legacy LLM-first` (старый путь, не рекомендуется)
   `plan_generator.php` строит большой prompt, вызывает PlanRun AI API, затем ответ проходит через `parseAndRepairPlanJSON()`, `plan_normalizer.php`, `plan_validator.php` и сохранение в БД. Используется только когда `PLAN_GENERATION_MODE` не равен `llm_planner` и `USE_SKELETON_GENERATOR=0`.
3. `Skeleton-first` (DEPRECATED, Phase A.1 / PR2)
   Перенесён в `planrun_ai/_legacy/skeleton/`. При `USE_SKELETON_GENERATOR=1` `PlanGenerationProcessorService::processViaSkeleton()` бросает явный `RuntimeException` («skeleton-first отключён в production»). Diagnostic-скрипты (`live_planning_e2e.php`, `recalc_feedback_scenarios.php`) и `WeeklyAdaptationEngine` через `services/AdaptationService.php` продолжают использовать `_legacy/skeleton/` напрямую.

### Что оркестрирует backend вокруг AI

| Модуль | Что делает в пайплайне |
|--------|-------------------------|
| `TrainingPlanService` | Ставит job в очередь, отслеживает статус и отдаёт фронтенду состояние генерации |
| `PlanGenerationQueueService` | Хранит задания `generate` / `recalculate` / `next_plan`, резервирует job для worker'а |
| `scripts/plan_generation_worker.php` | Поднимает queue-worker и вызывает processor |
| `PlanGenerationProcessorService` | Центральный orchestrator: маршрутизирует в `processViaLlmPlanner()` (production) или legacy-путь, сохраняет план, активирует последнюю версию, добавляет AI-review в чат |
| `TrainingStateBuilder` | Строит `training state`: VDOT, pace rules, load policy, readiness, weeks_to_goal, special flags |
| `PlanSkeletonBuilder` | Определяет типы дней по неделям и дням недели, опираясь на preferred days, phases и race placement |
| `ChatContextBuilder` | Используется при recalculate/next plan для фактической истории тренировок, compliance, ACWR и recent workouts |

### Legacy LLM-first path

```text
PlanGenerationProcessorService
  -> generatePlanViaPlanRunAI() / recalculatePlanViaPlanRunAI() / generateNextPlanViaPlanRunAI()
  -> prompt_builder.php
  -> callAIAPI() -> PlanRun AI API
  -> parseAndRepairPlanJSON()
  -> validatePlanStructure()
  -> saveTrainingPlan() / saveRecalculatedPlan()
  -> generatePlanReview()
  -> ChatService::addAIMessageToUser()
```

### Skeleton-first path (DEPRECATED, перенесено в `_legacy/`)

После Phase A.1 (PR2) skeleton-первый путь не используется в production. `processViaSkeleton()` остаётся как explicit `RuntimeException` (для пользователей, у кого ещё остался env-флаг). Историческая структура (числовой генератор `PlanSkeletonGenerator` → `LLMEnricher` → `SkeletonValidator` → `LLMReviewer` → `PlanAutoFixer`) сохранена в `planrun_ai/_legacy/skeleton/` для diagnostic-скриптов и `WeeklyAdaptationEngine`. Не использовать как production fallback.

### LLM planner path (DeepSeek V4, production default — после Phase A)

```text
PlanGenerationProcessorService::processViaLlmPlanner()
  -> TrainingStateBuilder::buildForUser($userId, $mode, $payload)
      -> + planning_scenario (PlanScenarioResolver)
      -> + goal_realism      (assessGoalRealism)
  -> DeepSeekPlanPlanner::generate($userId, $jobType, $payload)
      -> buildPlannerContext()   # FACTS_JSON со всем training_state, planning_scenario, goal_realism
      -> buildFullPlanPrompt()   # single_pass всегда (Phase A.2)
      -> LlmGateway::request()   # одна модель PLAN_LLM_MODEL (Phase A.3); retries, concurrency
      -> JSON-extraction + sanitize
  -> enforceRaceDayConsistency()         # позиция race day и race-week cap
  -> applySinglePassHardSafetyRepairs()  # PLAN_LLM_HARD_SAFETY_REPAIRS=1
  -> normalizeTrainingPlan()
  -> PlanQualityGate::evaluate()         # mode = auto / strict / permissive
  -> saveTrainingPlan() / saveRecalculatedPlan()
  -> generatePlanReview()
```

Phase A (PR2) упрощения, отражённые в этом пайплайне:
- ❌ Убрана `staged` стратегия (`generateMacroPlan` + `generateDetailBatch` пакеты по 3 недели). Теперь **только `single_pass`**.
- ❌ Убраны три модели (`plannerModel`/`detailModel`/`repairModel`). Одна `PLAN_LLM_MODEL` (default `deepseek-chat`).
- ❌ Убран LLM repair-loop (`repairPlan` + `buildRepairPrompt`). При quality gate failure — explicit error; targeted retry — задача Phase C.2.
- ❌ Убран `buildExpectedSkeletonContract` (был для skeleton-flow).

**Quality gate auto-mode** (`PLAN_LLM_QUALITY_GATE_MODE=auto` — default). По философии «trust the model + injury-only guardrails» (см. `docs/PLANS-AI-V2.md` раздел 0a) `resolveQualityGateMode()` переключается в `strict` ТОЛЬКО при:

- `special_population_flags` содержат `pregnant_or_postpartum`, `return_after_injury`, `recent_pain_signal` или `recent_illness_signal`;
- `planning_scenario.flags` содержат `pain_protective`, `illness_protective` или `return_after_injury`;
- `goal_realism.severity === 'major'` (явно нереалистичная цель).

В остальных случаях — `permissive`. Это включает marathon / half у здорового бегуна, `return_after_break`, `overload_recovery`, `b_race_before_a_race`, `short_runway*` и т.д. Для них валидаторы продолжают эмитить issues, но `permissive` режим даунгрейдит большинство ошибок до warning, и план сохраняется. Безопасность для длинных дистанций обеспечивается hard safety repairs (long run cap, race-week cap, volume spikes) и контекстом в FACTS_JSON, а не блокирующим quality gate.

### Верхнеуровневые AI-модули

| Файл | Ключевые функции | Реальная ответственность |
|------|------------------|--------------------------|
| `planrun_ai_config.php` | `isPlanRunAIAvailable` | Читает env, объявляет endpoint/timeout и проверяет доступность PlanRun AI API |
| `planrun_ai_integration.php` | `resolvePlanRunAIMaxTokens`, `callPlanRunAIAPI`, `callAIAPI` | HTTP-интеграция с локальным AI-service, retry/backoff и выбор размера ответа |
| `prompt_builder.php` | `buildTrainingPlanPrompt`, `buildPartialPlanPrompt`, `buildRecalculationPrompt`, `buildNextPlanPrompt`, `computeMacrocycle`, `computeHealthMacrocycle`, `calculatePaceZones`, `assessGoalRealism` | Самый насыщенный AI-модуль: математика темпов, макроцикл, правила periodization и сборка всех промптов |
| `plan_generator.php` | `generatePlanViaPlanRunAI`, `generateSplitPlan`, `parseAndRepairPlanJSON`, `validatePlanStructure`, `recalculatePlanViaPlanRunAI`, `generateNextPlanViaPlanRunAI`, `detectCurrentPhase` | Legacy orchestration для `generate/recalculate/next_plan`, включая split generation длинных планов и сбор контекста из БД |
| `plan_normalizer.php` | `normalizeTrainingType`, `normalizeTrainingDay`, `normalizeTrainingPlan`, `buildDescriptionFromFields` | Нормализует сырой LLM JSON, всегда пересчитывает даты, выводит `description`, считает derived fields и enforce'ит `preferred_days` / `preferred_ofp_days` |
| `plan_validator.php` | `collectNormalizedPlanValidationIssues`, `validateNormalizedPlanAgainstTrainingState`, `shouldRunCorrectiveRegeneration`, `scoreValidationIssues` | Сводит доменные validators в единый quality gate над нормализованным планом |
| `plan_saver.php` | `saveTrainingPlan`, `saveRecalculatedPlan` | Транзакционно пересобирает `training_plan_weeks`, `training_plan_days`, `training_day_exercises`, удаляя старую активную структуру |
| `plan_review_generator.php` | `buildPlanSummaryForReview`, `generatePlanReview` | После сохранения генерирует human-readable review плана и отправляет его в чат пользователя |
| `generate_plan_async.php` | CLI entrypoint | Старый standalone worker-path: читает CLI args, вызывает генерацию, сохраняет план и пишет review в чат |
| `description_parser.php` | `parseOfpSbuDescription` | Парсит текстовое описание ОФП/СБУ обратно в структуру упражнений |
| `text_generator.php` | `generateTextFromExercises`, `generateSimpleDescription` | Вспомогательная генерация короткого описания тренировки из exercises; сейчас это side-helper, а не главный путь |
| `create_empty_plan.php` | `createEmptyPlan` | Генерирует пустой календарь `free`-дней для сценария самостоятельных тренировок |

### Группы функций в `prompt_builder.php`

| Группа | Функции |
|--------|---------|
| Нормализация дней недели и schedule hints | `getPromptWeekdayOrder`, `getPromptWeekdayPatterns`, `sortPromptWeekdayKeys`, `getPromptWeekdayLabel`, `getPreferredLongRunDayKey`, `extractScheduleOverridesFromReason`, `computeRaceDayPosition` |
| Pace / VDOT математика | `calculatePaceZones`, `calculateDetrainingFactor`, `_vdotOxygenCost`, `_vdotFractionVO2max`, `estimateVDOT`, `predictRaceTime`, `getTrainingPaces`, `predictAllRaceTimes`, `assessGoalRealism` |
| Макроцикл | `getDistanceSpec`, `computeMacrocycle`, `computeHealthMacrocycle`, `formatMacrocyclePrompt`, `formatHealthMacrocyclePrompt` |
| Общие блоки prompt'а | `buildUserInfoBlock`, `buildGoalBlock`, `buildStartDateBlock`, `buildPreferencesBlock`, `buildPaceZonesBlock`, `buildTrainingPrinciplesBlock`, `buildKeyWorkoutsBlock`, `buildMandatoryRulesBlock`, `buildFormatResponseBlock` |
| Legacy generation entrypoints | `buildTrainingPlanPrompt`, `computePlanChunks`, `_splitByMacrocyclePhases`, `buildPartialPlanPrompt` |
| Recalculate / next plan entrypoints | `buildRecalculationPrompt`, `buildRecalcTrainingPrinciplesBlock`, `buildRecalcContextBlock`, `buildNextPlanPrompt`, `buildPreviousPlanHistoryBlock` |

### Skeleton subsystem (legacy, `planrun_ai/_legacy/skeleton/`)

После Phase A.1 (PR2) перенесено в `_legacy/`. Используется только из diagnostic-скриптов и `services/AdaptationService.php` (через `WeeklyAdaptationEngine`). Не подключать в production-paths.

| Файл (`_legacy/skeleton/...`) | Публичный API | Роль |
|------|---------------|------|
| `PlanSkeletonGenerator.php` | `generate`, `getLastUser`, `getLastState`, `getLastGoalRealism` | Главный rule-based generator: собирает user/state/macrocycle и строит полный числовой план без LLM (legacy) |
| `VolumeDistributor.php` | `distribute` | Размазывает недельный объём по дням, назначает pace/duration и структурные поля key workouts |
| `IntervalProgressionBuilder.php` | `build` | Прогрессия интервальных сессий по фазам и дистанции |
| `TempoProgressionBuilder.php` | `build` | Прогрессия threshold tempo-блоков |
| `RacePaceProgressionBuilder.php` | `build` | Темповые блоки в race pace, включая continuous и repeats варианты |
| `FartlekBuilder.php` | `build` | Фартлек-сессии с progression по сегментам |
| `ControlWorkoutBuilder.php` | `build` | Контрольные тренировки и тестовые недели |
| `OfpProgressionBuilder.php` | `build` | Недельная схема ОФП по предпочтениям и recovery-state |
| `StartRunningProgramBuilder.php` | `build`, `isFixedProgram` | Фиксированные beginner-программы (`start_running`, `couch_to_5k`) |
| `WarmupCooldownHelper.php` | `warmup`, `cooldown` | Базовые warmup/cooldown значения по дистанции |
| `enrichment_prompt_builder.php` | `buildEnrichmentPrompt`, `buildReviewPrompt`, `buildCompactProfile` | Компактные prompts для enrichment/review поверх скелета (legacy) |
| `LLMEnricher.php` | `enrich` | LLM может добавить только `notes`; числовые изменения дальше режутся validator'ом (legacy) |
| `LLMReviewer.php` | `review` | Возвращает JSON со статусом и issues для автофикса (legacy) |
| `SkeletonValidator.php` | `validateAgainstOriginal`, `validateConsistency`, `addAlgorithmicNotes` | Проверяет, что LLM не изменила числа, и что план логически непротиворечив (legacy) |
| `PlanAutoFixer.php` | `fix` | Применяет machine-readable fixes (legacy) |
| `WeeklyAdaptationEngine.php` | `analyze` | Сравнивает план и факт по неделе, определяет adaptation triggers и запускает recalculate pipeline (используется `services/AdaptationService.php`) |

### Валидаторы нормализованного плана

| Файл | Что ловит |
|------|-----------|
| `validators/schedule_validator.php` | Несовпадение с ожидаемым skeleton, бег не в `preferred_days`, пропущенные обязательные беговые дни |
| `validators/pace_validator.php` | Выход easy/long/tempo pace за допустимые коридоры training state |
| `validators/load_validator.php` | Скачки недельного объёма и back-to-back key workouts |
| `validators/taper_validator.php` | Недостаточное снижение объёма перед гонкой и перегруженную race week |
| `validators/goal_consistency_validator.php` | Несоответствие цели, уровня и special population flags интенсивности плана |
| `validators/workout_completeness_validator.php` | Пустые или слишком слабые key workouts без структуры/стимула |

### Weekly adaptation

`WeeklyAdaptationEngine` - отдельная post-plan логика поверх уже существующего календаря:

- считает compliance, key completion, skipped days и deviation по easy pace;
- ищет триггеры `low_compliance`, `many_skipped_days`, `pace_too_slow`, `pace_too_fast`, `consecutive_low_compliance`;
- выбирает adaptation type (`volume_down`, `volume_up`, `insert_recovery`, `vdot_adjust_down`, `simplify_key`);
- обновляет `weekly_base_km` при необходимости и затем запускает обычный `PlanGenerationProcessorService::process(..., 'recalculate', ...)`.

### Инварианты AI-слоя

- `plan_normalizer.php` **не доверяет датам из LLM** и всегда вычисляет их от `startDate`.
- `description` считается derived field и пересобирается из структурных полей.
- Для `interval` и `fartlek` итоговый `distance_km` тоже считается кодом, а не LLM.
- Enforcement расписания может принудительно превратить день в `rest`, если он не попадает в `preferred_days` / `preferred_ofp_days`.
- В legacy `_legacy/skeleton/` LLM не должна менять числа: `SkeletonValidator::validateAgainstOriginal()` сравнивает типы, дистанции и темпы с исходным скелетом. В production не используется.
- В llm_planner path при флагах travma/illness/pregnancy или `goal_realism.severity='major'` quality gate работает в `strict` (см. `resolveQualityGateMode`). Для здоровых бегунов (включая marathon/half) — `permissive` (философия trust the model + injury-only guardrails). Skeleton fallback при сбое DeepSeek **отключён** намеренно: пользователю показывается ошибка с просьбой повторить позже / обратиться к админу.
- После успешного сохранения план дополняется chat-review через `generatePlanReview()` и `ChatService::addAIMessageToUser()`.

### Ключевые переменные окружения AI-пайплайна

| ENV | Default | Назначение |
|-----|---------|------------|
| `PLAN_GENERATION_MODE` | `llm_planner` | Production-путь. Любое другое значение → legacy LLM-first (deprecated). |
| `PLAN_LLM_MODEL` | `deepseek-chat` | Одна модель для планировщика (Phase A.3, PR2). Legacy `PLAN_LLM_PLANNER_MODEL`/`*_REVIEWER_MODEL` читаются как fallback. |
| `PLAN_LLM_MAX_TOKENS` | `20000` | Лимит токенов для plan-generation запроса |
| `PLAN_LLM_TIMEOUT_SECONDS` | `240` | Таймаут одного запроса к DeepSeek |
| `USE_SKELETON_GENERATOR` | DEPRECATED | После Phase A.1 (PR2) — приводит к explicit `RuntimeException`. Skeleton-код перенесён в `_legacy/skeleton/`. |
| `PLAN_LLM_PLANNER_STRATEGY` | DEPRECATED | После Phase A.2 (PR2) — игнорируется, single_pass всегда. |
| `PLAN_LLM_PLANNER_MODEL` / `PLAN_LLM_DETAIL_MODEL` / `PLAN_LLM_REPAIR_MODEL` | DEPRECATED | После Phase A.3 (PR2) — fallback only. Использовать `PLAN_LLM_MODEL`. |
| `PLAN_LLM_QUALITY_GATE_MODE` | `auto` | `auto` / `strict` / `permissive`. В `auto` решает `resolveQualityGateMode()` |
| `PLAN_LLM_HARD_SAFETY_REPAIRS` | `1` | Программные исправления критичных нарушений (long run cap, volume spikes) до quality gate |
| `PLAN_LLM_RACE_WEEK_CAP_REPAIRS` | `1` | Авто-cap объёма недели гонки |
| `PLANRUN_AI_STATE_SCENARIO` | `1` | Считать `planning_scenario` и `goal_realism` в `TrainingStateBuilder` |
| `DEEPSEEK_API_KEY` | — | Ключ DeepSeek (обязателен для llm_planner) |
| `DEEPSEEK_BASE_URL` | `https://api.deepseek.com` | Endpoint DeepSeek |
| `DEEPSEEK_MODEL` | `deepseek-chat` | Модель планировщика |
| `LMSTUDIO_BASE_URL` | `http://localhost:1234` | LM Studio для AI-чата (не для планов) |

Подробный ручной справочник по этим файлам: [09-AI-MODULE-REFERENCE.md](09-AI-MODULE-REFERENCE.md). Детальная дорожная карта по AI-плану: [PLANS-AI-V2.md](PLANS-AI-V2.md).

## Скрипты и фоновые задачи

`planrun-backend/scripts/` содержит migration, maintenance и worker-утилиты.

- Миграции: `migrate_*.php`
- Очередь генерации: `plan_generation_worker.php`
- Уведомления: `push_workout_reminders.php`, `process_notification_delivery_queue.php`, `process_notification_email_digest.php`
- Интеграции: `strava_register_webhook.php`, `strava_daily_health_check.php`, `strava_backfill_athlete_ids.php`
- Диагностика и тестовые сценарии: `check_*`, `send_test_push.php`, `eval_plan_generation.php`

## Что смотреть по задачам

| Задача | Основные файлы |
|-------|----------------|
| Авторизация и security | `api/api_wrapper.php`, `planrun-backend/api_v2.php`, `controllers/AuthController.php`, `services/AuthService.php`, `services/JwtService.php` |
| План и генерация | `controllers/TrainingPlanController.php`, `services/TrainingPlanService.php`, `services/PlanGenerationQueueService.php`, `services/PlanGenerationProcessorService.php`, `planrun_ai/*` |
| Тренировки и импорт | `controllers/WorkoutController.php`, `services/WorkoutService.php`, `repositories/WorkoutRepository.php`, `providers/*`, `utils/GpxTcxParser.php` |
| AI-чат | `controllers/ChatController.php`, `services/ChatService.php`, `services/ChatActionParser.php`, `services/ChatToolRegistry.php`, `services/ChatContextBuilder.php`, `repositories/ChatRepository.php` |
| Уведомления | `controllers/PushController.php`, `controllers/UserController.php`, `services/NotificationDispatcher.php`, `services/NotificationSettingsService.php`, `services/PushNotificationService.php`, `services/WebPushNotificationService.php` |
| Coach-модуль | `controllers/CoachController.php`, `controllers/AdminController.php`, `repositories/NotificationRepository.php`, `services/PlanNotificationService.php` |

## Где смотреть детали

- Action map: [03-API.md](03-API.md)
- Полный список файлов: [04-FILES-REFERENCE.md](04-FILES-REFERENCE.md)
- Root helper-файлы, providers и ops/scripts: [11-BACKEND-OPS-REFERENCE.md](11-BACKEND-OPS-REFERENCE.md)
- Глубокий AI reference: [09-AI-MODULE-REFERENCE.md](09-AI-MODULE-REFERENCE.md)
