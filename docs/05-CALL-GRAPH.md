# PlanRun - ключевые цепочки вызовов

Документ фиксирует не полный call graph по каждой строке, а самые важные рабочие цепочки, которые помогают быстро локализовать изменение.

## 1. Bootstrap приложения

```text
src/main.jsx
  -> initLogger()
  -> installGlobalErrorLogger()
  -> WebPushService.registerServiceWorker()
  -> <App />

src/App.jsx
  -> useAuthStore.initialize()
  -> startAppUpdatePolling()
  -> preloadAuthenticatedModules()
  -> PushService.registerPushNotifications()   # только native
```

## 2. Авторизация и разблокировка

```text
Login / landing / register screens
  -> src/api/authApi.js
  -> src/api/ApiClient.js
  -> api/api_wrapper.php?action=login|request_password_reset|confirm_password_reset
  -> planrun-backend/api_v2.php
  -> AuthController
  -> AuthService
  -> JwtService / EmailService / DB
```

Native unlock flow:

```text
useAuthStore
  -> TokenStorageService
  -> BiometricService / PinAuthService / CredentialBackupService
  -> ApiClient.setToken()
  -> ApiClient.getCurrentUser()
```

## 3. Загрузка и генерация плана

```text
Dashboard / Calendar / Chat / Banner
  -> usePlanStore
  -> ApiClient.getPlan() / checkPlanStatus() / recalculatePlan() / generateNextPlan()
  -> TrainingPlanController
  -> TrainingPlanService
  -> PlanGenerationQueueService
  -> scripts/plan_generation_worker.php
  -> PlanGenerationProcessorService
  -> legacy path OR skeleton path
  -> save plan in DB
  -> generatePlanReview()
  -> ChatService.addAIMessageToUser()
  -> usePlanStore.loadPlan()
```

Legacy path:

```text
PlanGenerationProcessorService
  -> planrun_ai/plan_generator.php
  -> planrun_ai/prompt_builder.php
  -> planrun_ai/planrun_ai_integration.php
  -> PlanRun AI API
  -> parseAndRepairPlanJSON()
  -> plan_normalizer.php
  -> plan_validator.php
  -> plan_saver.php
```

Skeleton path (`USE_SKELETON_GENERATOR=1`):

```text
PlanGenerationProcessorService::processViaSkeleton()
  -> TrainingStateBuilder
  -> PlanSkeletonBuilder
  -> skeleton/PlanSkeletonGenerator.php
  -> skeleton/LLMEnricher.php
  -> skeleton/SkeletonValidator.php
  -> skeleton/LLMReviewer.php
  -> skeleton/PlanAutoFixer.php
  -> plan_saver.php
```

## 4. День тренировки и результат

```text
CalendarScreen / DayModal / ResultModal / AddTrainingModal
  -> src/api/workoutApi.js
  -> ApiClient.getDay() / saveResult() / uploadWorkout() / deleteWorkout()
  -> WorkoutController / WeekController / ExerciseController / NoteController
  -> WorkoutService / WeekService / ExerciseService
  -> WorkoutRepository / TrainingPlanRepository / NoteRepository
```

## 5. AI-чат и tool calling

```text
ChatScreen
  -> chatApi.chatSendMessageStream()
  -> api/chat_sse.php or api_wrapper -> action=chat_send_message_stream
  -> ChatController::sendMessageStream()
  -> ChatService::streamResponse()
  -> ChatContextBuilder
  -> local LLM / LM Studio / PlanRun AI
  -> tool execution inside ChatService
      -> WeekService / TrainingPlanService / WorkoutService
  -> NDJSON chunk with plan_updated / plan_recalculating
  -> usePlanStore.loadPlan()
```

## 6. Синхронизация тренировок и webhook

```text
SettingsScreen / integrations UI
  -> statsApi.getIntegrationOAuthUrl() / syncWorkouts()
  -> api/oauth_callback.php
  -> IntegrationsController
  -> StravaProvider / HuaweiHealthProvider / PolarProvider
  -> WorkoutService.importWorkouts()

api/strava_webhook.php
  -> StravaProvider
  -> WorkoutService.importWorkouts() / delete flow
  -> useWorkoutRefreshStore polling notices new data version
```

## 6b. Целевой пульс (HR targets)

```text
Генерация плана:
  plan_saver.php → resolveTargetHrForDay() → UserProfileService.getTargetHrForWorkoutType()
    → INSERT training_plan_days с target_hr_min/max

Ручное добавление/обновление:
  WeekService.addTrainingDay/updateTrainingDayById → enrichWithTargetHr()
    → UserProfileService.getTargetHrForWorkoutType()

Авто-пересчёт после импорта:
  strava_webhook.php → importWorkouts() → UserProfileService.recalculateHrTargetsForFutureDays()
  WorkoutController.saveResult() → UserProfileService.recalculateHrTargetsForFutureDays()

AI-контекст:
  ChatContextBuilder.buildContextForUser() → formatHrZones()
    → UserProfileService.getHrZonesData() → detectRealHrRanges()
```

## 7. Push и web push уведомления

```text
Frontend
  -> PushService / WebPushService
  -> register_push_token / register_web_push_subscription

Backend
  -> PushController / UserController
  -> NotificationSettingsService
  -> NotificationDispatcher
  -> PushNotificationService / WebPushNotificationService / EmailNotificationService / Telegram
```

## 8. Coach и заметки к плану

```text
TrainersScreen / SettingsScreen / ApplyCoachForm
  -> coachApi.*
  -> CoachController / AdminController
  -> DB tables user_coaches / coach_requests / coach_groups / coach_pricing

Calendar / profile views
  -> workoutApi.getDayNotes() / saveDayNote() / getPlanNotifications()
  -> NoteController
  -> NoteRepository / NotificationRepository / PlanNotificationService
```

## 9. Weekly adaptation

```text
Stats / adaptation UI
  -> statsApi.runAdaptation()
  -> AdaptationController
  -> AdaptationService
  -> skeleton/WeeklyAdaptationEngine.php
      -> prepare_weekly_analysis.php
      -> detectTriggers() / decideAdaptation()
      -> PlanGenerationProcessorService::process(..., 'recalculate', ...)
  -> plan review / updated plan
  -> frontend reloads plan and stats
```

## Когда этот документ особенно полезен

- если меняется `ApiClient` или один из action'ов в `api_v2.php`;
- если AI-чат должен менять план или уведомления;
- если правка затрагивает и web, и Capacitor сценарии;
- если после синхронизации из Strava/Huawei данные не доходят до UI.
