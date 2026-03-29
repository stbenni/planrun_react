# PlanRun - ручной справочник по backend application layer

Этот документ собран вручную по `planrun-backend/controllers/*`, `planrun-backend/services/*` и `planrun-backend/repositories/*`.

Он дополняет:

- `02-BACKEND.md` - обзор слоёв и общая архитектура;
- `09-AI-MODULE-REFERENCE.md` - глубокий разбор AI generation/adaptation модулей;
- `11-BACKEND-OPS-REFERENCE.md` - root helper-файлы, providers, cron/worker и миграции.

Здесь зафиксирован именно application layer: кто принимает request, кто владеет сценарием, где появляются побочные эффекты и какие модули считаются источником истины для runtime-поведения.

## 1. Как реально проходит backend-запрос

```text
Frontend ApiClient
  -> api/*.php
  -> planrun-backend/api_v2.php
  -> Controller
  -> Service
  -> Repository / Provider / AI module / root helper
  -> MySQL / external API / local LLM stack
```

Практически важные детали этого потока:

- `api_v2.php` не только диспатчит action'ы в контроллеры, но и держит часть публичных inline-route'ов вроде `get_avatar`, `get_site_settings`, `assess_goal`, `get_user_by_slug`.
- `BaseController` - это мост между session-auth, JWT-auth и calendar access logic из `calendar_access.php`.
- Основная доменная orchestration живёт в сервисах, а не в контроллерах.
- Репозитории изолируют SQL только частично: в проекте всё ещё есть legacy-участки, где часть SQL остаётся в контроллерах или сервисах.
- Для AI-пересчёта, weekly adaptation и уведомлений почти всегда есть дополнительные побочные эффекты: чат, queue, push, web push, email, обновление кеша.

## 2. Общий фундамент контроллеров

### `BaseController.php`

| Узел | Реальная роль |
|------|---------------|
| `initializeAccess()` | Собирает viewer-context, пользователя календаря, owner/edit/view flags и учитывает и сессию, и JWT |
| `requireAuth()` / `requireAuthEdit()` / `requireCoachOrAdmin()` | Централизованные access guards для типовых сценариев |
| `requireCsrf()` | Защищает mutating action'ы, где backend ожидает CSRF-токен |
| `getJsonInput()` | Один раз читает и кеширует JSON body запроса |
| `successResponse()` / `errorResponse()` | Нормализуют JSON envelope ответа |
| `handleException()` | Переводит доменные исключения в предсказуемые HTTP/JSON ошибки |
| `notifyCoachPlanUpdated()` / `notifyAthleteResultLogged()` | Общие bridge-методы к notification domain после изменения плана или результата |

Что важно помнить:

- `BaseController` уже знает о coach/athlete календарном доступе, поэтому многие action'ы умеют работать не только для owner, но и в контексте `viewContext`.
- Это также означает, что часть прав доступа вычисляется не прямо в action, а ещё до вызова сервисов.

## 3. Контроллеры: кто принимает action и что реально делает

### `AuthController.php`

- Методы: `login`, `logout`, `refreshToken`, `requestPasswordReset`, `confirmPasswordReset`, `checkAuth`.
- Роль: точка входа в session/JWT hybrid auth и password reset flow.
- Особенности: логин и reset ограничены через `RateLimiter`; `checkAuth` возвращает не только факт логина, но и прикладной snapshot пользователя, включая `avatar_path`, `role`, `onboarding_completed`, `timezone`, `training_mode`.
- Отдельный нюанс: в отличие от большинства контроллеров, access context здесь упрощён и не зависит от calendar ownership.

### `TrainingPlanController.php`

- Методы: `load`, `save`, `checkStatus`, `regeneratePlan`, `regeneratePlanWithProgress`, `recalculatePlan`, `generateNextPlan`, `reactivatePlan`, `clearPlan`, `clearPlanGenerationMessage`.
- Роль: тонкий facade над `TrainingPlanService`.
- Особенности: почти вся тяжёлая логика намеренно вынесена в сервис и очередь; `save()` пока фактически заглушка и возвращает `501`.
- Здесь важно, что controller не генерирует план сам, а только ставит задачу и отдаёт фронтенду состояние.

### `WeekController.php`

- Методы: `addWeek`, `deleteWeek`, `addTrainingDay`, `addTrainingDayByDate`, `updateTrainingDay`, `deleteTrainingDay`, `copyDay`, `copyWeek`.
- Роль: mutation-layer для структуры плана на уровне недель и дней.
- Особенности: все write-action'ы требуют CSRF; после изменения плана могут уходить coach/athlete notification-события.
- `addTrainingDayByDate` важен тем, что умеет сначала автоматически создать нужную неделю, если пользователь добавляет тренировку по календарной дате.

### `ExerciseController.php`

- Методы: `addDayExercise`, `updateDayExercise`, `deleteDayExercise`, `reorderDayExercises`, `listExerciseLibrary`.
- Роль: CRUD упражнений и библиотеки упражнений.
- Особенности: контроллер остаётся тонким и почти полностью делегирует в `ExerciseService`.

### `WorkoutController.php`

- Методы: `dataVersion`, `getDay`, `saveResult`, `getResult`, `uploadWorkout`, `getAllResults`, `deleteWorkout`, `save`, `reset`, `getWorkoutTimeline`.
- Роль: runtime-слой фактических тренировок, результатов, импорта файлов и timeline/laps.
- `getDay` - один из самых нагруженных action'ов: он отдаёт фронтенду план дня, упражнения, импортированные тренировки, manual log и fallback HTML/text представление.
- `saveResult` и импорт могут запускать VDOT-related side effects.
- Внутренний `checkVdotUpdate()` обновляет `users.last_race_*`, пересчитывает training state, может поставить автоматический `recalculate` в очередь и отправить AI-сообщение в чат о смене формы.
- Legacy-методы `save` и `reset` до сих пор поддерживаются для старого интерфейса прогресса.

### `StatsController.php`

- Методы: `stats`, `getAllWorkoutsSummary`, `getAllWorkoutsList`, `prepareWeeklyAnalysis`, `racePrediction`.
- Роль: прикладная аналитика и прогнозные данные для dashboard/stats/profile.
- `racePrediction` не просто читает сохранённые поля, а строит training state через `TrainingStateBuilder`, использует pace helpers и сравнивает результат с Riegel-like prediction.
- `prepareWeeklyAnalysis` служит bridge-точкой между экраном статистики, weekly review и adaptation engine.

### `NoteController.php`

- Методы: `getDayNotes`, `saveDayNote`, `deleteDayNote`, `getWeekNotes`, `saveWeekNote`, `deleteWeekNote`, `getNoteCounts`, `getPlanNotifications`, `markPlanNotificationRead`.
- Роль: заметки к плану и in-app уведомления по изменению плана.
- Особенности: note ownership проверяется на уровне автора, coach/admin получают более широкий доступ; plan notifications здесь же читаются и помечаются прочитанными.

### `ChatController.php`

- Методы: `getMessages`, `sendMessage`, `sendMessageStream`, `clearAiChat`, `sendMessageToAdmin`, `getDirectMessages`, `sendMessageToUser`, `sendAdminMessage`, `clearDirectDialog`, `getDirectDialogs`, `getAdminChatUsers`, `getAdminUnreadNotifications`, `getAdminMessages`, `broadcastAdminMessage`, `markAllRead`, `markAdminAllRead`, `markRead`, `markAdminConversationRead`, `addAIMessage`.
- Роль: общий transport-controller для AI-чата, direct messaging и admin messaging.
- Особенности: поддерживает и обычный JSON-ответ, и streaming NDJSON; через этот слой проходят plan-changing AI tool flows.
- Именно отсюда фронтенд получает сигналы о том, что AI уже начал пересчёт плана, закончил пересчёт или сгенерировал next plan.

### `IntegrationsController.php`

- Методы: `getOAuthUrl`, `getStatus`, `syncWorkouts`, `getStravaTokenError`, `unlink`.
- Роль: единая точка входа для `strava`, `huawei`, `polar`.
- Особенности: контроллер содержит provider registry, подписывает state для OAuth и умеет возвращать отладочную информацию о проблеме Strava-токена.

### `PushController.php`

- Методы: `registerToken`, `unregisterToken`.
- Роль: минимальный native push endpoint.
- Особенности: это один из примеров legacy-слоя, где контроллер работает почти напрямую с таблицей `push_tokens`, без отдельного репозитория.

### `UserController.php`

- Методы: `getProfile`, `updateProfile`, `getNotificationSettings`, `getNotificationDeliveryLog`, `updateNotificationSettings`, `registerWebPushSubscription`, `unregisterWebPushSubscription`, `sendTestNotification`, `deleteUser`, `uploadAvatar`, `getAvatar`, `removeAvatar`, `updatePrivacy`, `getNotificationsDismissed`, `dismissNotification`, `getTelegramLoginUrl`, `generateTelegramLinkCode`, `unlinkTelegram`.
- Роль: крупнейший профильный controller, который связывает account settings, privacy, avatar, notification preferences, Telegram и browser push.
- `updateProfile` - большой orchestration action: профиль, цели, training prefs, health notes, privacy flags, avatar metadata и часть coach-полей обновляются одним payload.
- `sendTestNotification` умеет прогонять сразу несколько каналов доставки.
- `deleteUser` - тяжёлый admin-side cascade delete по планам, тренировкам, интеграциям, токенам и связанным данным.

### `CoachController.php`

- Методы: `listCoaches`, `requestCoach`, `getCoachRequests`, `acceptCoachRequest`, `rejectCoachRequest`, `getMyCoaches`, `removeCoach`, `applyCoach`, `getCoachAthletes`, `getCoachPricing`, `updateCoachPricing`, `getCoachGroups`, `saveCoachGroup`, `deleteCoachGroup`, `getGroupMembers`, `updateGroupMembers`, `getAthleteGroups`.
- Роль: вся coach-athlete вертикаль.
- Особенности: контроллер обслуживает сразу три разные модели данных: каталог тренеров, coach relationships и coach groups/pricing.
- `getCoachAthletes` возвращает не только список учеников, но и activity/unread/context, который нужен coach dashboard.

### `AdminController.php`

- Методы: `listUsers`, `getUser`, `updateUser`, `getPublicSettings`, `getSettings`, `updateSettings`, `getNotificationTemplates`, `updateNotificationTemplate`, `resetNotificationTemplate`, `getCoachApplications`, `approveCoachApplication`, `rejectCoachApplication`.
- Роль: административный кабинет.
- Особенности: кроме user/settings CRUD, здесь находится approval pipeline для coach application; при approve профиль тренера и прайс-лист переносятся в боевые таблицы.
- Контроллер также умеет сам гарантировать наличие таблицы `site_settings`, то есть содержит немного migration-like логики.

### `AdaptationController.php`

- Методы: `runWeeklyAdaptation`.
- Роль: ручной или UI-triggered запуск weekly adaptation.
- Особенности: сам контроллер тонкий, а реальная работа живёт в `AdaptationService` и `WeeklyAdaptationEngine`.

## 4. Сервисы: кто владеет бизнес-сценарием

### 4.1 Базовые и identity-сервисы

| Сервис | Реальная роль | Важные побочные эффекты |
|--------|----------------|-------------------------|
| `BaseService` | Базовый слой логирования и доменных exception-helper'ов | Нормализует ошибки для сервисного слоя |
| `AuthService` | Session login/logout, JWT refresh, password reset | Пишет/читает refresh tokens, обновляет session, обращается к `JwtService` |
| `JwtService` | Выпуск, верификация, rotation и revoke access/refresh token'ов | Учитывает device id, grace period и ограничивает число refresh token'ов на пользователя |
| `RegisterApiService` | Лёгкий registration facade для field validation и email code | Использует `RateLimiter` для повторной отправки кода |
| `RegistrationService` | Минимальная регистрация, полная регистрация, специализация после входа | Создаёт пользователя, initial plan row, при `ai/both` ставит plan generation job |
| `EmailVerificationService` | Коды подтверждения email | Таблица `email_verification_codes`, 10-минутный TTL, ограничение попыток |
| `EmailService` | Низкоуровневая отправка email через SMTP или fallback `mail()` | Используется и verification flow, и reset flow, и notification email |
| `AvatarService` | Обработка и выдача аватаров | EXIF orientation, crop, генерация `sm/md/lg`, cache headers |
| `TelegramLoginService` | Telegram auth/linking и уведомления в Telegram | PKCE/state flow, temp files, JWKS validation, welcome message |

### 4.2 План, календарь и тренировки

| Сервис | Реальная роль | Важные побочные эффекты |
|--------|----------------|-------------------------|
| `TrainingPlanService` | Загрузка плана, статус генерации, enqueue regenerate/recalculate/next plan, reactivate/clear | Деактивирует активный план перед пересчётом, пишет generation message в session/plan metadata |
| `PlanGenerationQueueService` | Очередь `plan_generation_jobs` | Дедуплицирует активные job'ы, резервирует pending job, планирует retry после fail |
| `PlanGenerationProcessorService` | Центральный worker-side orchestrator генерации | Выбирает skeleton или legacy path, сохраняет план, активирует последнюю версию, добавляет AI review в чат, persist failure в план |
| `WeekService` | CRUD недель/дней плана | Валидирует payload, копирует дни/недели, инвалидирует кеш плана |
| `ExerciseService` | CRUD упражнений дня и reorder exercise list | Валидирует и инвалидирует кеш |
| `WorkoutService` | `getDay`, manual result flow, import, timeline/laps, delete | Мержит plan + imported workouts + manual log, dedupe-ит импорт, может автообновлять VDOT |
| `StatsService` | Сводка, workout list, VDOT benchmark, weekly analysis | Комбинирует `workouts` и `workout_log`, выбирает лучший свежий результат для VDOT |
| `WorkoutPlanRecalculationService` | Автопостановка пересчёта после обновления формы | Смотрит остаток будущих тренировок, порог VDOT delta и ставит `recalculate` в очередь |

Что важно про этот кластер:

- `WorkoutService::getDay()` - один из главных anti-corruption слоёв backend'а: он собирает всё, что фронтенд ждёт в модалке дня.
- `PlanGenerationProcessorService` - единственная нормальная точка, где сходятся queue, AI, сохранение плана и сообщение в чат.
- `TrainingPlanService` сознательно не делает heavy AI сам, а только управляет жизненным циклом job.

### 4.3 Чат, AI-context и коммуникация с пользователем

| Сервис | Реальная роль | Важные побочные эффекты |
|--------|----------------|-------------------------|
| `ChatService` | Главный orchestrator AI-chat, admin/direct dialogs и streaming | Выполняет tool/action pipeline, сохраняет AI messages, шлёт push после ответа, обновляет unread state |
| `ChatContextBuilder` | Собирает профиль, план, stats, coaching context, memory и history summary для LLM | Формирует prompt-context и для AI-чата, и для review-like сценариев |
| `DateResolver` | Извлекает даты из естественного русского текста | Используется, когда user prompt привязан к дате тренировки |
| `TrainingStateBuilder` | Строит training state для AI и прогнозов | Вычисляет VDOT source/confidence, pace rules, readiness, load policy, weeks-to-goal |
| `PlanSkeletonBuilder` | Алгоритмический каркас будущего плана | Развешивает quality/long/recovery weeks по preferred days и фазам |
| `AdaptationService` | Вход в weekly adaptation engine | После анализа недели может отправить AI review-message в чат |

Практический смысл:

- `ChatService` знает не только про сообщения, но и про план, прямые диалоги, notification fan-out и AI-side actions.
- `TrainingStateBuilder` используется и в AI, и в stats/race prediction, поэтому это один из ключевых shared calculation слоёв backend'а.
- `AdaptationService` - bridge между cron/UI и `WeeklyAdaptationEngine`, а не самостоятельный аналитический движок.

### 4.4 Уведомления и каналы доставки

| Сервис | Реальная роль | Важные побочные эффекты |
|--------|----------------|-------------------------|
| `NotificationTemplateService` | Каталог событий и runtime override шаблонов | Гарантирует таблицу overrides и подмешивает DB-overrides к дефолтным шаблонам |
| `NotificationSettingsService` | Каналы, quiet hours, digest, delivery log, dispatch guards, delivery queue | Создаёт схему уведомлений, решает `canDeliver`, резервирует queued delivery и digest items |
| `NotificationDispatcher` | Единая fan-out orchestration по mobile/web/telegram/email | Учитывает quiet hours, daily digest, очереди, channel availability и guard against duplicates |
| `PlanNotificationService` | Доменные plan/coaching события | Пишет in-app `plan_notifications` и дополнительно запускает внешнюю доставку |
| `PushNotificationService` | Native mobile push через FCM | Учитывает settings/legacy flags, умеет очищать невалидные токены |
| `WebPushNotificationService` | Browser web push | Хранит subscriptions, шлёт VAPID-push, чистит протухшие endpoint'ы |
| `EmailNotificationService` | Email-канал для единичных уведомлений и digest | Собирает HTML/text письмо, достраивает action URL из `APP_URL`, использует `EmailService` |

Что важно про notification domain:

- Источник истины по тому, можно ли вообще доставлять событие пользователю, - `NotificationSettingsService`, а не конкретный канал.
- `NotificationDispatcher` решает, отправлять сейчас, откладывать до конца quiet hours или класть в email digest.
- `PlanNotificationService` связывает плановые бизнес-события с этим общим notification stack.

## 5. Репозитории: где живёт SQL и какие структуры они возвращают

| Репозиторий | За что отвечает | Где особенно важен |
|-------------|------------------|--------------------|
| `BaseRepository` | Общие helpers доступа к БД | Базовый слой для всех остальных repository-классов |
| `TrainingPlanRepository` | Метаданные user plan, недели и дни плана, error message генерации | `TrainingPlanService`, `PlanGenerationProcessorService` |
| `WeekRepository` | SQL для недель, дней и датной адресации тренировок | `WeekService`, `Calendar`-related сценарии |
| `WorkoutRepository` | Manual/imported workouts, timeline, laps, results, day lookups | `WorkoutService`, `StatsService` |
| `ExerciseRepository` | Упражнения дня и exercise library | `ExerciseService`, `WorkoutService::getDay()` |
| `StatsRepository` | Агрегации по дням, summary, workouts list и выборки для прогнозов | `StatsService`, `TrainingStateBuilder` |
| `ChatRepository` | Conversations, messages, unread state, direct/admin dialogs | `ChatService` |
| `NoteRepository` | Day/week notes и note counts | `NoteController`, `WeekCalendar`, `DayModal` |
| `NotificationRepository` | Dismissed notification ids и простые notification-related выборки | frontend notification ribbon |

Практически важные детали:

- `ChatRepository` - это не просто CRUD сообщений; в нём спрятана логика direct/admin unread state и выборки списка пользователей для broadcast/admin tabs.
- `WorkoutRepository` и `StatsRepository` вместе формируют основу почти всех экранов с аналитикой, потому что у приложения две разные фактические модели тренировок: `workouts` и `workout_log`.
- `TrainingPlanRepository` остаётся привязанным к legacy-совместимому формату плана, который ожидает фронтенд.

## 6. Сквозные инварианты и скрытые зависимости

### 1. Двойная модель авторизации

- В системе одновременно живут PHP session и JWT.
- `AuthService`, `JwtService`, `BaseController` и `useAuthStore` на фронте опираются на это совместное поведение.
- Любые изменения в auth-flow нужно проверять сразу для web и native-клиента.

### 2. Coach/public calendar context проходит через весь стек

- Многие контроллеры и сервисы умеют работать не только в owner-context, но и в `viewContext` пользователя по `slug` и privacy/access rules.
- Поэтому изменения в `calendar_access.php`, `BaseController` или `get_user_by_slug` почти всегда влияют сразу на `CalendarScreen`, `StatsScreen`, `UserProfileScreen` и coach-flow.

### 3. Изменение результата тренировки может менять не только статистику

- Результат или импорт тренировки может обновить VDOT.
- Обновление VDOT может поменять training state.
- Смена training state может поставить план на автоматический пересчёт.
- После этого пользователь ещё получает AI explanation в чат.

### 4. План и чат у приложения связаны теснее, чем кажется по UI

- AI-чат не просто отвечает текстом: он может инициировать `recalculate`, `next_plan`, перестройку части плана и сообщение о результате.
- `PlanGenerationProcessorService` после выполнения job тоже возвращается в чат и записывает туда review.

### 5. Notification stack построен как отдельная доменная подсистема

- In-app уведомление, push, web push, Telegram и email уже не являются независимыми ad-hoc вызовами.
- Основной поток теперь такой: событие -> `PlanNotificationService` или `ChatService` -> `NotificationDispatcher` -> settings/queue/channel.

### 6. Legacy-код всё ещё часть runtime

- Даже при наличии controller/service/repository структуры проект всё ещё использует root helper-файлы вроде `load_training_plan.php`, `prepare_weekly_analysis.php`, `calendar_access.php`, `prompt_builder.php`.
- При анализе поведения нельзя исходить только из controller/service слоя; часть истины всё ещё лежит в legacy helper-ах и AI-модулях.

## 7. Как читать backend дальше

Если нужно идти последовательно и глубоко:

1. Сначала `02-BACKEND.md` как карту слоёв.
2. Затем этот документ для понимания ownership и side effects.
3. После этого:
   `09-AI-MODULE-REFERENCE.md` для AI/skeleton/adaptation;
   `11-BACKEND-OPS-REFERENCE.md` для providers, cron и root helpers;
   `03-API.md` для action-routing;
   исходники контроллеров/сервисов/репозиториев для точной построчной проверки.
