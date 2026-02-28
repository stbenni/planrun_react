# PlanRun — граф вызовов функций

Детальная карта: где каждая функция используется и с чем взаимодействует.

**Матрица влияния:** `.cursor/rules/impact-matrix.mdc` — при изменении любого файла проверь список затронутых.

---

## Легенда

- **→** вызывает
- **←** вызывается из
- **↔** взаимный вызов

---

## Фронтенд: Stores

### useAuthStore

| Функция | Вызывает | Вызывается из |
|---------|----------|---------------|
| `initialize` | ApiClient (new), api.getCurrentUser, BiometricService.authenticateAndGetTokens, BiometricService.saveTokens | App.jsx (useEffect) |
| `login` | api.login, BiometricService.saveTokens, api.getCurrentUser | App.jsx handleLogin, LoginForm, LoginScreen |
| `logout` | api.logout, BiometricService.clearTokens, PinAuthService.clearPin | App.jsx handleLogout, TopHeader |
| `pinLogin` | PinAuthService.verifyAndGetTokens, api.setToken, api.getCurrentUser, PinAuthService.setPinAndSaveTokens | LoginForm, LoginScreen |
| `biometricLogin` | BiometricService.authenticateAndGetTokens, api.setToken, api.getCurrentUser | LoginForm, LoginScreen |
| `updateUser` | — | App.jsx handleRegister, SpecializationModal, UserProfileScreen, TopHeader |
| `setDrawerOpen` | — | TopHeader |

### usePlanStore

| Функция | Вызывает | Вызывается из |
|---------|----------|---------------|
| `loadPlan` | api.getPlan (← useAuthStore.api) | CalendarScreen, ChatScreen onPlanUpdated, recalculatePlan, generateNextPlan, regeneratePlan |
| `savePlan` | api.savePlan | (редко напрямую) |
| `checkPlanStatus` | api.checkPlanStatus | recalculatePlan, generateNextPlan (polling) |
| `recalculatePlan` | api.recalculatePlan, checkPlanStatus (poll), loadPlan, api.request('reactivate_plan') | CalendarScreen |
| `generateNextPlan` | api.generateNextPlan, checkPlanStatus (poll), loadPlan, api.request('reactivate_plan') | CalendarScreen |
| `regeneratePlan` | api.regeneratePlan, loadPlan | — |

### useWorkoutStore

| Функция | Вызывает | Вызывается из |
|---------|----------|---------------|
| `loadAllResults` | api.getAllResults | — |
| `loadDay` | api.getDay | — |
| `saveResult` | api.saveResult | — |
| `resetResult` | api.reset | — |
| `getResult` | — (читает workouts[date]) | — |
| `hasResult` | — | — |

---

## Фронтенд: ApiClient → Backend action

| ApiClient метод | Backend action | Контроллер → Сервис |
|-----------------|----------------|---------------------|
| getPlan | load | TrainingPlanController → TrainingPlanService |
| savePlan | save | TrainingPlanController → TrainingPlanService |
| getDay | get_day | WorkoutController → WorkoutService |
| saveResult | save_result | WorkoutController → WorkoutService |
| getResult | get_result | WorkoutController → WorkoutService (loadWorkoutResult) |
| getAllResults | get_all_results | WorkoutController |
| uploadWorkout | upload_workout | WorkoutController → WorkoutService |
| getWorkoutTimeline | get_workout_timeline | WorkoutController → WorkoutService |
| getAllWorkoutsSummary | get_all_workouts_summary | StatsController → StatsService |
| getAllWorkoutsList | get_all_workouts_list | StatsController → StatsService |
| recalculatePlan | recalculate_plan | TrainingPlanController → TrainingPlanService |
| generateNextPlan | generate_next_plan | TrainingPlanController → TrainingPlanService |
| checkPlanStatus | check_plan_status | TrainingPlanController → TrainingPlanService |
| chatSendMessageStream | chat_send_message_stream | ChatController → ChatService |
| chatGetMessages | chat_get_messages | ChatController → ChatService |
| getProfile | get_profile | UserController |
| updateProfile | update_profile | UserController |
| getIntegrationOAuthUrl | integration_oauth_url | IntegrationsController |
| syncWorkouts | sync_workouts | IntegrationsController → WorkoutService |

---

## Фронтенд: компоненты → api

### CalendarScreen

```
CalendarScreen
  → useAuthStore (api, user)
  → usePlanStore (recalculatePlan, generateNextPlan, loadPlan)
  → api.getPlan, api.getAllWorkoutsSummary, api.getAllResults, api.getDay, api.getResult
  → DayModal (onTrainingAdded → loadPlan)
  → AddTrainingModal (onTrainingAdded → loadPlan)
  → ResultModal
```

### DayModal

```
DayModal
  ← CalendarScreen, UserProfileScreen, StatsScreen
  → api.getDay (loadDayData)
  → AddTrainingModal (при добавлении тренировки)
  → WorkoutDetailsModal (при просмотре деталей)
  → ResultModal (при записи результата)
```

### ResultModal

```
ResultModal
  ← DayModal
  → api.getDay (loadDayPlan)
  → api.getResult (loadExistingResult)
  → api.saveResult (handleSubmit)
```

### AddTrainingModal

```
AddTrainingModal
  ← DayModal, CalendarScreen
  → api.saveResult (если editResultData — режим редактирования результата)
  → api.addTrainingDayByDate (если новый день — add_training_day_by_date)
```

### ChatScreen

```
ChatScreen
  → api.chatSendMessageStream(content, onChunk, { onPlanUpdated })
  → onPlanUpdated: usePlanStore.getState().loadPlan()
  → api.chatGetMessages, api.chatGetDirectDialogs, api.chatAdminGetMessages, ...
```

---

## Бэкенд: контроллер → сервис → репозиторий

### WorkoutController

| Метод | Вызывает | Репозиторий/сервис |
|-------|----------|---------------------|
| getDay | WorkoutService::getDay | WorkoutRepository, TrainingPlanRepository |
| saveResult | WorkoutService::saveResult | WorkoutRepository |
| uploadWorkout | WorkoutService::uploadWorkout | GpxTcxParser, WorkoutRepository |
| deleteWorkout | WorkoutService::deleteWorkout | WorkoutRepository |
| getWorkoutTimeline | WorkoutService::getWorkoutTimeline | WorkoutRepository |

### TrainingPlanController

| Метод | Вызывает | Репозиторий/сервис |
|-------|----------|---------------------|
| load | TrainingPlanService::load | TrainingPlanRepository |
| recalculatePlan | TrainingPlanService::recalculatePlan | planrun_ai, TrainingPlanRepository |
| generateNextPlan | TrainingPlanService::generateNextPlan | planrun_ai, TrainingPlanRepository |

### ChatController

| Метод | Вызывает | Репозиторий/сервис |
|-------|----------|---------------------|
| sendMessageStream | ChatService::streamResponse | ChatRepository, ChatContextBuilder, TrainingPlanService (tools) |
| getMessages | ChatService | ChatRepository |

### ChatService (tools)

| Tool | Вызывает |
|------|----------|
| update_training_day | WeekService::updateTrainingDay |
| recalculate_plan | TrainingPlanService::recalculatePlan |
| generate_next_plan | TrainingPlanService::generateNextPlan |
| get_plan | TrainingPlanService::load |
| get_workouts | WorkoutRepository |
| get_day_details | WorkoutService::getDay |

---

## Критические пути

### Путь: пользователь записывает результат

```
ResultModal.handleSubmit
  → api.saveResult(payload)
  → ApiClient.saveResult → POST /api?action=save_result
  → WorkoutController::saveResult
  → WorkoutService::saveResult
  → WorkoutRepository (insert/update workout_log)
```

### Путь: AI изменил план через чат

```
ChatScreen: api.chatSendMessageStream(..., { onPlanUpdated })
  → ChatController::sendMessageStream
  → ChatService::streamResponse
  → LLM вызывает tool update_training_day
  → ChatService::executeTool → WeekService::updateTrainingDay
  → Stream: { plan_updated: true }
  → ApiClient парсит NDJSON → onPlanUpdated()
  → usePlanStore.getState().loadPlan()
  → api.getPlan → TrainingPlanController::load
```

### Путь: пересчёт плана

```
CalendarScreen: recalculatePlan(reason)
  → usePlanStore.recalculatePlan
  → api.recalculatePlan(reason)
  → TrainingPlanController::recalculatePlan
  → TrainingPlanService::recalculatePlan
  → generate_plan_async.php (асинхронно)
  → Polling: api.checkPlanStatus() каждые 5 сек
  → При has_plan: loadPlan() → api.getPlan
```
