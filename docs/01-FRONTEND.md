# PlanRun - фронтенд

`src/` содержит React/Vite клиент, который работает и как веб-приложение, и как Capacitor-обёртка для Android/iOS. Основная идея фронтенда: один код UI, единый `ApiClient`, Zustand как источник правды и тонкие доменные API-модули поверх action-based PHP API.

## Точки входа и оболочка

| Файл | Роль |
|------|------|
| `src/main.jsx` | Bootstrap React, инициализация логгера, переключение в `native-app`, регистрация service worker для web push |
| `src/App.jsx` | Глобальный роутинг, проверка auth state, maintenance mode, lazy routes, запуск фоновых pollers |
| `src/components/AppLayout.jsx` | Каркас авторизованной зоны: header, notifications, onboarding modal, banner, bottom nav |
| `src/components/AppTabsContent.jsx` | Режим "живых вкладок": экраны не размонтируются, а переключаются через видимость и lazy import |

## Навигация и экранная модель

Приложение разделено на публичные маршруты и авторизованную зону.

- Публичные маршруты: `LandingScreen`, `RegisterScreen`, `ForgotPasswordScreen`, `ResetPasswordScreen`, публичный `UserProfileScreen`.
- Основные вкладки авторизованной зоны: `DashboardScreen`, `CalendarScreen`, `StatsScreen`, `ChatScreen`, `TrainersScreen`, `SettingsScreen`.
- Специальные ветки: `AdminScreen` для админов, `AthletesOverviewScreen` для coach-ролей.

Экранные папки содержат не только JSX-экраны, но и локальные helper-модули, например `src/screens/chat/*` и `src/screens/settings/*`. Эти файлы поддерживают экранную логику, но не являются отдельными маршрутами.

## Доступ к данным

Frontend data layer разбит на два уровня.

### 1. `src/api/ApiClient.js`

`ApiClient` инкапсулирует:

- выбор `baseUrl` для web/native окружений;
- хранение access/refresh токенов;
- proactive refresh и recovery после истечения токена;
- request timeout и нормализацию API-ошибок;
- агрегацию всех доменных методов (`auth`, `plan`, `workout`, `stats`, `chat`, `coach`, `admin`).

### Основные группы методов `ApiClient`

| Группа | Методы |
|-------|--------|
| Auth | `login`, `loginWithJwt`, `logout`, `requestResetPassword`, `confirmResetPassword`, `sendVerificationCode`, `registerMinimal`, `register`, `completeSpecialization`, `validateField`, `getCurrentUser` |
| План | `getPlan`, `savePlan`, `regeneratePlan`, `recalculatePlan`, `generateNextPlan`, `checkPlanStatus`, `clearPlan` |
| Тренировки | `getDay`, `saveResult`, `getResult`, `uploadWorkout`, `getAllResults`, `reset`, `deleteWorkout`, `deleteTrainingDay`, `copyDay`, `copyWeek`, `updateTrainingDay` |
| Заметки и plan notifications | `getDayNotes`, `saveDayNote`, `deleteDayNote`, `getWeekNotes`, `saveWeekNote`, `deleteWeekNote`, `getNoteCounts`, `getPlanNotifications`, `markPlanNotificationRead`, `markAllPlanNotificationsRead` |
| Статистика и интеграции | `getStats`, `getAllWorkoutsSummary`, `getAllWorkoutsList`, `getRacePrediction`, `getIntegrationOAuthUrl`, `syncWorkouts`, `getIntegrationsStatus`, `unlinkIntegration`, `getStravaTokenError`, `getWorkoutTimeline`, `runAdaptation` |
| Admin | `getAdminUsers`, `getAdminUser`, `updateAdminUser`, `deleteUser`, `getAdminSettings`, `updateAdminSettings`, `getAdminNotificationTemplates`, `updateAdminNotificationTemplate`, `resetAdminNotificationTemplate`, `getSiteSettings` |
| Чат | `chatGetMessages`, `chatSendMessage`, `chatSendMessageStream`, `chatSendMessageToAdmin`, `chatGetDirectDialogs`, `chatGetDirectMessages`, `chatSendMessageToUser`, `chatClearDirectDialog`, `chatMarkRead`, `chatClearAi`, `chatMarkAllRead`, `chatAdminMarkAllRead`, `chatAdminSendMessage`, `getAdminChatUsers`, `chatAdminGetMessages`, `chatAdminMarkConversationRead`, `chatAddAIMessage`, `chatAdminGetUnreadNotifications`, `chatAdminBroadcast` |
| Coach | `listCoaches`, `requestCoach`, `getCoachRequests`, `acceptCoachRequest`, `rejectCoachRequest`, `getMyCoaches`, `removeCoach`, `applyCoach`, `getCoachAthletes`, `getCoachPricing`, `updateCoachPricing`, `getCoachGroups`, `saveCoachGroup`, `deleteCoachGroup`, `getGroupMembers`, `updateGroupMembers`, `getAthleteGroups`, `getCoachApplications`, `approveCoachApplication`, `rejectCoachApplication` |

### 2. Доменные API-модули

Файлы `src/api/*.js` содержат функции-обёртки над отдельными action'ами:

- `authApi.js` - логин, reset password, регистрация, специализация;
- `planApi.js` - загрузка и запуск генерации плана;
- `workoutApi.js` - day view, CRUD тренировок, заметки, notifications по плану;
- `statsApi.js` - статистика, race prediction, integrations;
- `chatApi.js` - AI-chat, admin chat, direct dialogs;
- `coachApi.js` - coach catalog, заявки, pricing, groups;
- `adminApi.js` - admin users, site settings, notification templates.

### Экспортируемые функции доменных API-модулей

| Файл | Экспортируемые функции |
|------|------------------------|
| `src/api/authApi.js` | `login`, `loginWithJwt`, `logout`, `requestResetPassword`, `confirmResetPassword`, `sendVerificationCode`, `registerMinimal`, `register`, `completeSpecialization`, `validateField` |
| `src/api/planApi.js` | `getPlan`, `savePlan`, `regeneratePlan`, `recalculatePlan`, `generateNextPlan`, `checkPlanStatus`, `clearPlan` |
| `src/api/workoutApi.js` | `getUserBySlug`, `getDay`, `saveResult`, `getResult`, `uploadWorkout`, `getAllResults`, `resetWorkout`, `deleteWeek`, `addWeek`, `addTrainingDayByDate`, `deleteWorkout`, `deleteTrainingDay`, `copyDay`, `copyWeek`, `getDayNotes`, `saveDayNote`, `deleteDayNote`, `getWeekNotes`, `saveWeekNote`, `deleteWeekNote`, `getNoteCounts`, `getPlanNotifications`, `markPlanNotificationRead`, `markAllPlanNotificationsRead`, `updateTrainingDay` |
| `src/api/statsApi.js` | `getStats`, `getAllWorkoutsSummary`, `getAllWorkoutsList`, `getRacePrediction`, `getIntegrationOAuthUrl`, `syncWorkouts`, `getIntegrationsStatus`, `unlinkIntegration`, `getStravaTokenError`, `getWorkoutTimeline`, `runAdaptation` |
| `src/api/chatApi.js` | `chatGetMessages`, `chatSendMessage`, `chatSendMessageStream`, `chatSendMessageToAdmin`, `chatGetDirectDialogs`, `chatGetDirectMessages`, `chatSendMessageToUser`, `chatClearDirectDialog`, `chatMarkRead`, `chatClearAi`, `chatMarkAllRead`, `chatAdminMarkAllRead`, `chatAdminSendMessage`, `getAdminChatUsers`, `chatAdminGetMessages`, `chatAdminMarkConversationRead`, `chatAddAIMessage`, `chatAdminGetUnreadNotifications`, `chatAdminBroadcast`, `getNotificationsDismissed`, `dismissNotification` |
| `src/api/coachApi.js` | `listCoaches`, `requestCoach`, `getCoachRequests`, `acceptCoachRequest`, `rejectCoachRequest`, `getMyCoaches`, `removeCoach`, `applyCoach`, `getCoachAthletes`, `getCoachPricing`, `updateCoachPricing`, `getCoachGroups`, `saveCoachGroup`, `deleteCoachGroup`, `getGroupMembers`, `updateGroupMembers`, `getAthleteGroups`, `getCoachApplications`, `approveCoachApplication`, `rejectCoachApplication` |
| `src/api/adminApi.js` | `getAdminUsers`, `getAdminUser`, `updateAdminUser`, `deleteUser`, `getAdminSettings`, `updateAdminSettings`, `getAdminNotificationTemplates`, `updateAdminNotificationTemplate`, `resetAdminNotificationTemplate`, `getSiteSettings` |

## Zustand stores

| Store | Ответственность |
|-------|-----------------|
| `useAuthStore` | bootstrap авторизации, lock-screen, PIN/биометрия, credential recovery, актуальный `user` и `api` |
| `usePlanStore` | состояние плана, polling очереди генерации, признаки `isGenerating`, загрузка/очистка/пересчёт |
| `useWorkoutStore` | результат дня, список workouts/results, сохранение результата |
| `useWorkoutRefreshStore` | автообновление данных по polling, resume и внешним сигналам |
| `usePreloadStore` | служебные флаги предзагрузки модулей |

Ключевой архитектурный принцип: экраны не строят transport logic самостоятельно, а используют stores и `ApiClient` как единый источник состояния и сетевых запросов.

### Основные actions store-слоя

| Store | Важные actions |
|-------|----------------|
| `useAuthStore` | `initialize`, `setupBackgroundLock`, `unlock`, `_completeUnlock`, `login`, `logout`, `beginPasswordReauth`, `pinLogin`, `biometricLogin`, `updateUser`, `checkBiometricAvailability`, `checkPinAvailability` |
| `usePlanStore` | `_updateGeneratingState`, `startStatusPolling`, `stopStatusPolling`, `initPlanStatus`, `applyQueuedPlanState`, `loadPlan`, `savePlan`, `checkPlanStatus`, `regeneratePlan`, `recalculatePlan`, `generateNextPlan`, `clearPlan`, `setPlanStatusChecked`, `setPlan` |
| `useWorkoutStore` | `loadAllResults`, `loadDay`, `saveResult`, `resetResult`, `getResult`, `hasResult`, `clearWorkouts` |
| `useWorkoutRefreshStore` | `triggerRefresh`, `checkForUpdates`, `startAutoRefresh`, `stopAutoRefresh`, `startDataPolling`, `stopDataPolling` |
| `usePreloadStore` | `triggerPreload` |

## Платформенные сервисы

`src/services/` закрывает различия между браузером и native-средой.

- `TokenStorageService` - общее хранение токенов, device id и native/web fallback.
- `BiometricService` и `PinAuthService` - локальная разблокировка приложения.
- `CredentialBackupService` - восстановление сессии после истечения токена или очистки storage.
- `PushService` - регистрация нативных push-уведомлений в Capacitor.
- `WebPushService` - браузерные push-подписки и работа с service worker.
- `ChatSSE` и `ChatStreamWorker` - потоковая обработка AI-ответов и long-lived chat streams.

### Публичные методы и функции сервисов

| Модуль | Основные функции / методы |
|--------|---------------------------|
| `src/services/TokenStorageService.js` | `isNativeCapacitor`, `getTokens`, `_getTokensFromPreferencesBackup`, `_tryRestoreToSecureStorage`, `saveTokens`, `clearTokens`, `getDeviceId`, `saveDeviceId`, `getOrCreateDeviceId`, `isPasswordReauthBypassEnabled`, `setPasswordReauthBypass` |
| `src/services/BiometricService.js` | `checkAvailability`, `authenticate`, `saveTokens`, `getTokens`, `isBiometricEnabled`, `clearTokens`, `authenticateAndGetTokens` |
| `src/services/PinAuthService.js` | `isAvailable`, `isPinEnabled`, `_getLockState`, `_setLockState`, `_clearLockState`, `_checkLockState`, `_registerFailure`, `setPinAndSaveTokens`, `verifyAndGetTokens`, `clearPin` |
| `src/services/CredentialBackupService.js` | `isAvailable`, `hasCredentials`, `hasCredentialsFor`, `isBiometricRecoveryEnabled`, `saveCredentialsSecure`, `recoverAndLoginBiometric`, `saveCredentials`, `recoverAndLogin`, `clearCredentials` |
| `src/services/WebPushService.js` | `isSupported`, `getPermission`, `registerServiceWorker`, `ensureSubscription`, `getCurrentSubscription`, `unregister` |
| `src/services/PushService.js` | `registerPushNotifications`, `unregisterPushNotifications` |
| `src/services/ChatSSE.js` | `ChatSSE.connect/disconnect/subscribe/unsubscribe/getUnreadData/setUnreadData/getUnreadTotal/getUnreadByType` |
| `src/services/ChatStreamWorker.js` | `runChatStreamInWorker` |

## Компоненты и составные домены

Во `src/components/` есть несколько крупных подсистем:

Подробный ручной разбор app shell, экранов, модалок и крупных UI-подсистем вынесен в `13-FRONTEND-COMPONENT-REFERENCE.md`.

- `Calendar/*` - календарная сетка, карточки тренировок, day/result modals, маршрут и drag-like операции.
- `Dashboard/*` - виджеты дашборда, недельная полоса, быстрые метрики и race prediction.
- `Stats/*` - графики, achievements, heatmap, детали тренировок, share card.
- `common/*` - модальные окна, top/bottom navigation, lock screen, notifications, error boundary, loading states.
- `Trainers/*` - формы и UI для coach-модуля.

## Экранные домены

### Основные экраны

| Экран | Что делает | Локальные домены / зависимости |
|-------|------------|--------------------------------|
| `LandingScreen` | Публичный marketing entrypoint: CTA, переходы в auth, device-aware ветки для mobile/iOS | `PublicHeader`, `ParticlesBackground`, `detectIOSDevice` |
| `LoginScreen` | Вход пользователя и переход в password reset / register ветки | `LoginForm`, `LoginModal`, `authApi`, `useAuthStore` |
| `RegisterScreen` | Многошаговый onboarding: аккаунт, цель, профиль бегуна, специализация, валидация полей | `RegisterModal`, `SpecializationModal`, `authApi`, `useVerificationCodeFlow` |
| `ForgotPasswordScreen` | Запрос восстановления пароля и cooldown/retry UX | `usePasswordResetRequest`, `authApi` |
| `ResetPasswordScreen` | Подтверждение reset flow и установка нового пароля | `authApi`, `authError` helpers |
| `DashboardScreen` | Тонкий route-слой поверх `Dashboard`, почти вся логика живёт в `components/Dashboard/*` | `Dashboard`, `usePlanStore`, `useWorkoutRefreshStore` |
| `CalendarScreen` | Главный календарный workflow: неделя/месяц, day/result modals, редактирование плана, заметки и ручные тренировки | `Calendar/*`, `workoutApi`, `usePlanStore`, `useWorkoutStore` |
| `StatsScreen` | Аналитика по тренировкам: heatmap, charts, race prediction, детализация workout history | `Stats/*`, `statsApi`, `WorkoutDetailsModal` |
| `ChatScreen` | AI-чат, admin chat и direct dialogs с потоковым ответом, unread state и post-action refresh плана | `src/screens/chat/*`, `chatApi`, `ChatSSE`, `ChatStreamWorker` |
| `TrainersScreen` | Каталог тренеров, заявки coach-athlete, текущие связи и работа с группами | `coachApi`, `ApplyCoachForm`, `useMyCoaches` |
| `SettingsScreen` | Самый крупный экран настроек: профиль, privacy, integrations, notifications, PIN/биометрия, pricing и coach-related блоки | `src/screens/settings/*`, `PushService`, `WebPushService`, `BiometricService`, `PinAuthService` |
| `UserProfileScreen` | Публичный или ролевой профиль спортсмена: цели, беговые метрики, агрегаты и privacy-aware секции | `statsApi`, `avatarUrl`, profile helpers |
| `AdminScreen` | Админский кабинет: users CRUD, site settings, шаблоны уведомлений, coach applications | `adminApi`, `coachApi`, template/profile formatters |
| `AthletesOverviewScreen` | Кабинет тренера с обзором спортсменов, сигналами attention/compliance и групповыми действиями | `coachApi`, `AthleteCard`, `GroupsModal` |

### Локальные модули экранов

`src/screens/chat/*` разбивает сложный `ChatScreen` на отдельные доменные части:

| Файл | Роль |
|------|------|
| `src/screens/chat/useChatNavigation.js` | Управляет табами AI/admin/direct, route params, выбором диалога и восстановлением навигационного состояния |
| `src/screens/chat/useChatSubmitHandlers.js` | Содержит submit pipeline для AI, admin и direct chat, запускает streaming и post-submit side effects |
| `src/screens/chat/useChatMessageLists.js` | Собирает и нормализует отдельные списки сообщений для AI/admin/direct режимов |
| `src/screens/chat/useChatDirectories.js` | Загружает директории пользователей, диалогов и списки для admin/coaches сценариев |
| `src/screens/chat/chatQuickReplies.js` | Быстрые подсказки и suggested prompts для AI-чата |
| `src/screens/chat/chatTime.js` | Форматирование времени сообщений |
| `src/screens/chat/chatConstants.js` | Константы табов, системных диалогов и routing ids |

`src/screens/settings/*` изолирует отдельные вертикали огромного `SettingsScreen`:

| Файл | Роль |
|------|------|
| `src/screens/settings/useSettingsProfile.js` | Загрузка профиля, преобразование backend-данных в форму и обратная сериализация |
| `src/screens/settings/useSettingsActions.js` | Account actions и платформенные сценарии: integrations, PIN/biometric, avatar, Telegram, sync и прочие side effects |
| `src/screens/settings/useCoachPricing.js` | CRUD логика coach pricing |
| `src/screens/settings/useMyCoaches.js` | Загрузка и обновление отношений спортсмена с тренерами |
| `src/screens/settings/notificationSettings.js` | Нормализация notification preferences, quiet hours и channel toggles |
| `src/screens/settings/profileForm.js` | Маппинг доменной модели профиля в UI-форму и обратно |
| `src/screens/settings/settingsUtils.js` | Theme/system preference helpers |

## Ключевые составные компоненты

### Calendar subsystem

| Компонент | Роль |
|-----------|------|
| `AddTrainingModal` | Ручное добавление/редактирование тренировки, включая run/OFP/SBU структуры и валидацию формы |
| `DayModal` | Детальный просмотр дня плана, notes, exercises и быстрые действия над днём |
| `ResultModal` | Ввод и редактирование фактического результата тренировки, включая route/metrics blocks |
| `WeekCalendar` | Основной weekly renderer с навигацией по неделям, вычислением virtual weeks и сводкой типов дней |
| `WorkoutCard` | Карточка тренировки с типом, статусом выполнения, notes и action affordances |
| `MonthlyCalendar` | Месячная сетка с индикаторами тренировочных типов и переходом в day details |
| `RouteMap` | Визуализация GPS-маршрута тренировки |

### Dashboard subsystem

| Компонент / модуль | Роль |
|--------------------|------|
| `Dashboard` | Главная композиция домашнего экрана: текущий день, недельная структура, widgets и quick actions |
| `useDashboardData` | Оркестрация загрузки плана, статистики, race prediction и derived dashboard-state |
| `useDashboardPullToRefresh` | Pull-to-refresh сценарий и синхронизация с refresh-store |
| `DashboardWeekStrip` | Горизонтальная полоса недели с текущим днём, completion и типами тренировок |
| `DashboardStatsWidget` | Агрегаты по плану/прогрессу на текущий момент |
| `RacePredictionWidget` | Виджет прогноза результатов на основе stats API |
| `dashboardDateUtils.js`, `dashboardLayout.js` | Расчёт дат, layout slots и персонализация раскладки dashboard-блоков |

### Stats and common UI

| Компонент / модуль | Роль |
|--------------------|------|
| `WorkoutDetailsModal` | Drill-down по одной тренировке: laps, pace, hr, charts, share/export-oriented view |
| `StatsUtils.js` | Агрегации для чартов, форматирование серий и derived stats |
| `Notifications` | Глобальная лента уведомлений/toasts и точка интеграции с dismissed state |
| `TopHeader` | Верхняя навигация, title/actions, role-aware кнопки и переходы в профиль/настройки |
| `LockScreen` | Локальная блокировка приложения для PIN/биометрии после background/resume |
| `AppErrorBoundary` | Глобальный перехват ошибок UI-дерева |
| `ApplyCoachForm` | Крупная форма подачи заявки тренера с pricing/experience/profile блоками |

## Вспомогательные модули

- `src/hooks/` - общие React hooks, не привязанные к одному экрану.
- `src/utils/` - мелкие утилиты, module preloading, lazy retry, логгер, helper-функции формы и календаря.
- `src/workers/` - web worker для chat streaming.

### Основные hooks

| Файл | Хуки / функции |
|------|----------------|
| `src/hooks/useIsTabActive.js` | `useIsTabActive` |
| `src/hooks/useMediaQuery.js` | `useMediaQuery` |
| `src/hooks/useRetryCooldown.js` | `useRetryCooldown` |
| `src/hooks/useSwipeableTabs.js` | `useSwipeableTabs` и helper `shouldIgnoreSwipeTarget` |
| `src/hooks/useChatUnread.js` | `useChatUnread` и comparator `sameUnread` |
| `src/hooks/useVerificationCodeFlow.js` | `useVerificationCodeFlow` |
| `src/hooks/usePasswordResetRequest.js` | `usePasswordResetRequest` |

### Основные utility-модули

| Файл | Ключевые функции |
|------|------------------|
| `src/utils/logger.js` | `initLogger`, `installGlobalErrorLogger`, `logger`, а также внутренние `timestamp`, `format`, `appendToFile`, `trimLogIfNeeded` |
| `src/utils/modulePreloader.js` | `preloadScreenModules`, `preloadScreenModulesDelayed`, `preloadAuthenticatedModules` |
| `src/utils/lazyWithRetry.js` | `isChunkLoadError`, `lazyWithRetry` |
| `src/utils/appUpdate.js` | `startAppUpdatePolling` |
| `src/utils/authError.js` | `getAuthErrorMessage`, `getAuthRetryAfter` |
| `src/utils/avatarUrl.js` | `getAvatarSrc` |
| `src/utils/workoutFormUtils.js` | `parseTime`, `formatTime`, `parsePace`, `formatPace`, `maskTimeInput`, `maskPaceInput`, `getActivityTypeLabel`, `getWorkoutDisplayType`, `getSourceLabel` |
| `src/utils/calendarHelpers.js` | `getDateForDay`, `getTrainingClass`, `getShortDescription`, `formatDateShort`, `getDayName`, `planTypeToCategory`, `workoutTypeToCategory`, `getPlanDayForDate`, `getDayCompletionStatus`, `getPlanWeekCategories` |

## Что изменяется чаще всего

| Задача | Основные файлы |
|-------|----------------|
| Авторизация/lock-screen | `src/App.jsx`, `src/stores/useAuthStore.js`, `src/services/TokenStorageService.js`, `src/services/BiometricService.js`, `src/services/PinAuthService.js` |
| План тренировок | `src/stores/usePlanStore.js`, `src/api/planApi.js`, `src/screens/CalendarScreen.jsx`, `src/components/common/PlanGeneratingBanner.jsx` |
| Тренировки/календарь | `src/api/workoutApi.js`, `src/stores/useWorkoutStore.js`, `src/components/Calendar/*`, `src/screens/CalendarScreen.jsx` |
| AI-чат | `src/screens/ChatScreen.jsx`, `src/api/chatApi.js`, `src/services/ChatSSE.js`, `src/workers/chatStream.worker.js` |
| Настройки и уведомления | `src/screens/SettingsScreen.jsx`, `src/screens/settings/*`, `src/services/PushService.js`, `src/services/WebPushService.js` |
| Тренеры и группы | `src/screens/TrainersScreen.jsx`, `src/components/Trainers/*`, `src/api/coachApi.js` |

## Где смотреть дальше

- список файлов по папкам: [04-FILES-REFERENCE.md](04-FILES-REFERENCE.md)
- backend-слой и API: [02-BACKEND.md](02-BACKEND.md), [03-API.md](03-API.md)
- подробный ручной разбор glue-layer и helper-модулей: [10-FRONTEND-MODULE-REFERENCE.md](10-FRONTEND-MODULE-REFERENCE.md)
