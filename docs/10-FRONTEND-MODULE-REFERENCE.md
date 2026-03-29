# PlanRun - ручной справочник по фронтенд-модулям

Этот документ собран вручную по `src/main.jsx`, `src/App.jsx`, экранным helper-модулям, `src/services/*`, `src/hooks/*` и ключевым `src/utils/*`.

Его задача: описать не только "какие есть экраны", но и какой glue-layer реально связывает UI, сторы, платформенные API и backend.

## 1. Bootstrap и shell

### `src/main.jsx`

| Что делает | Детали |
|------------|--------|
| Инициализирует логирование | `initLogger()` включает файловый лог на native, `installGlobalErrorLogger()` цепляет перехват глобальных ошибок |
| Подготавливает native-режим | Для Capacitor Android/iOS добавляет класс `native-app` на `documentElement` |
| Регистрирует web push service worker | Если `WebPushService.isSupported()` возвращает `true`, вызывает `registerServiceWorker()` |
| Монтирует приложение | Оборачивает `App` в `AppErrorBoundary` и `React.StrictMode` |

### `src/App.jsx`

| Блок | Реальная роль |
|------|----------------|
| `initialize()` из `useAuthStore` | Поднимает auth state, токены, lock-state и текущего пользователя |
| `startAppUpdatePolling()` | Проверяет `version.json` и перезагружает приложение при появлении нового build id |
| `api.getSiteSettings()` | Тянет maintenance mode и флаг доступности регистрации |
| `preloadAuthenticatedModules()` / `preloadScreenModulesDelayed()` | Разогревает лениво импортируемые экраны после авторизации |
| Dynamic import `PushService` | На native автоматически регистрирует FCM push |
| `appUrlOpen` listener | Ловит deep link `planrun://oauth-callback...` после OAuth в In-App Browser и перебрасывает пользователя в `SettingsScreen` |
| Router tree | Разделяет публичные маршруты, авторизованную зону и публичный профиль пользователя |

## 2. Экранные локальные домены

### `src/screens/chat/*`

Эти файлы делят `ChatScreen` на независимые поддомены: навигация, каталоги, списки сообщений и отправка.

| Файл | Ключевые функции | Реальная ответственность |
|------|------------------|--------------------------|
| `useChatNavigation.js` | `useChatNavigation` | Выводит текущий чат из route/search params, поддерживает admin mode, direct dialog, загрузку контакта по `username_slug`, вычисляет `dialogUserId`, `contactUserForDialog`, `selectedChatUser` |
| `useChatMessageLists.js` | `loadMessages`, `loadUserDialogMessages`, `loadChatAdminMessages` | Поддерживает три независимых набора сообщений: AI/system, direct dialog и admin-chat; отдельно ведёт loading/error state для каждого режима |
| `useChatDirectories.js` | `loadDirectDialogs`, `loadChatUsers` | Загружает список direct dialogs и admin user directory; direct dialogs тянутся автоматически при монтировании |
| `useChatSubmitHandlers.js` | `sendContent`, `handleSubmit`, `sendDirect`, `handleAdminChatSend`, `handleClearAiChat`, `handleClearDirectDialog` | Центральный submit-pipeline: optimistic user message, ветвление по режимам `admin/direct/AI`, запуск стрима, fallback на обычный HTTP-ответ, перезагрузка плана и временные баннеры при `plan_recalculated` / `plan_next_generated` |
| `chatQuickReplies.js` | `getQuickReplies`, `SUGGESTED_PROMPTS` | Контекстные подсказки для AI-чата по regex-паттернам последнего ответа AI |
| `chatTime.js` | `formatChatTime` | Форматирует время сообщения с учётом таймзоны пользователя и давности сообщения |
| `chatConstants.js` | export констант табов | Источник истины для режимов `ai/admin/direct` и системных идентификаторов чат-вкладок |

### Как реально работает submit в чате

```text
input/prompt
  -> useChatSubmitHandlers.sendContent()
     -> admin tab: api.chatSendMessageToAdmin()
     -> direct dialog: api.chatSendMessageToUser()
     -> ai tab:
         optimistic placeholder
         -> api.chatSendMessageStream()
         -> chunk flush через requestAnimationFrame
         -> onPlanUpdated => usePlanStore.loadPlan()
         -> onPlanRecalculating / onPlanGeneratingNext => временные уведомления
         -> fallback api.chatSendMessage()
```

Практический смысл:

- `useChatSubmitHandlers.js` знает о последствиях AI-ответа для календаря и плана.
- `useChatMessageLists.js` ничего не отправляет, а только обслуживает загрузку и хранение разных списков.
- `useChatNavigation.js` решает, какой именно чат сейчас считается выбранным.

### `src/screens/settings/*`

`SettingsScreen` - один из самых плотных экранов проекта. Локальные модули внутри `src/screens/settings/*` разделяют его на вертикали: профиль, уведомления, lock/auth, coach-подсистему и UI-preferences.

| Файл | Ключевые функции | Реальная ответственность |
|------|------------------|--------------------------|
| `profileForm.js` | `normalizeValue`, `createInitialFormData`, `mapProfileToFormData`, `daysOfWeek` | Нормализует backend-профиль в UI-форму, приводит даты/время/pace, распаковывает `preferred_days` и privacy fields |
| `notificationSettings.js` | `createInitialNotificationSettings`, `normalizeNotificationSettings` | Источник каталога notification event'ов и каналов; нормализует тихие часы, расписания напоминаний и per-event preferences |
| `useSettingsProfile.js` | `loadProfile`, `handleInputChange`, `handleSave` | Загружает CSRF + профиль + notification settings, собирает большой payload `update_profile`, отдельно сохраняет notification settings и синхронизирует `useAuthStore.user` |
| `useSettingsActions.js` | `runStravaSync`, `handleEnableLock`, `handlePinSetupSuccess`, `handleAddFingerprint`, `handleDisableLock`, `ensureCsrfToken`, `handleAvatarUpload`, `handleRemoveAvatar`, `handleUnlinkTelegram` | Все action-oriented сценарии, которые не сводятся к обычной форме: sync Strava, включение PIN/биометрии, avatar upload, Telegram unlink |
| `useCoachPricing.js` | `loadCoachPricing`, `handleAddPricingItem`, `handlePricingChange`, `handleRemovePricingItem`, `handleSavePricing` | Редактирование прайс-листа тренера прямо внутри настроек |
| `useMyCoaches.js` | `loadMyCoaches`, `handleRemoveCoach` | Локальная модель текущих тренеров пользователя и отвязка coach-athlete связи |
| `settingsUtils.js` | `VALID_TABS`, `getSystemTheme`, `getThemePreference`, `applyTheme` | Управляет локальными настройками темы и mapping'ом табов настроек |

### Что важно про `useSettingsProfile.js`

`loadProfile()` делает не один, а три запроса параллельно:

1. `get_csrf_token`
2. `get_profile`
3. `get_notification_settings`

После этого он:

- маппит профиль через `mapProfileToFormData()`;
- подмешивает нормализованные уведомления через `normalizeNotificationSettings()`;
- подавляет ложный auto-save через `skipNextAutoSaveRef`.

`handleSave()` важно тем, что он:

- валидирует email на фронте;
- при необходимости добирает CSRF;
- отдельно шлёт `update_profile`;
- отдельно шлёт `update_notification_settings`;
- умеет корректно переживать частичный успех, когда профиль сохранился, а уведомления нет.

### Что важно про `profileForm.js`

Это один из ключевых anti-corruption слоёв между backend и UI.

Он:

- превращает MySQL `TIME` и длительности в вид, пригодный для `<input type="time">`;
- нормализует `race_distance` из разных текстовых вариантов в `5k/10k/half/marathon`;
- переводит старый и новый формат `preferred_days` в единый вид;
- считает `easy_pace_min` из `easy_pace_sec`.

Без этого слоя форма настроек разъезжалась бы на несовместимостях форматов.

## 3. Derived data и экранные агрегаторы

### `src/components/Dashboard/useDashboardData.js`

Этот хук - главный вычислительный слой домашнего экрана.

#### Внутренние helper-функции

| Функция | Что делает |
|---------|------------|
| `isAiPlanMode` | Определяет, надо ли вообще проверять plan generation status |
| `getSummaryObject` | Приводит `getAllWorkoutsSummary()` к унифицированному объекту по датам |
| `buildResultsData` | Группирует `allResults.results` по `training_date` |
| `buildWorkoutsList` | Достаёт плоский список тренировок из разных вариантов ответа API |
| `buildWorkoutsListByDate` | Группирует workouts по дате старта |
| `hasAnyPlannedWorkout` | Проверяет, есть ли в плане хоть одна не-пустая сущность дня |
| `buildProgressDataMap` | Отмечает даты, которые считаются завершёнными по совокупности плана, summary, results и imported workouts |
| `findDashboardWorkouts` | Ищет сегодняшнюю и следующую тренировку с учётом таймзоны пользователя |
| `hasWorkoutForCategory` | Сверяет факт и план на уровне категории дня (`running/ofp/...`) |
| `calculateWeekProgress` | Считает прогресс текущей недели по всем planned items |
| `buildMetrics` | Достаёт distance/workouts/time через `processStatsData()` |

#### Что делает сам hook

`useDashboardData()`:

- синхронизируется с `usePlanStore.planStatus`;
- тянет план, результаты, summary и список тренировок параллельно;
- решает, показывать ли `showPlanMessage` для нового пользователя;
- обновляет `usePlanStore.setPlan(planData)` после успешной загрузки;
- слушает visibility change, refresh store и статус генерации;
- даёт `handleRegeneratePlan()`, который повторно запускает backend generation flow.

### `src/components/Stats/StatsUtils.js`

| Функция | Реальная роль |
|---------|----------------|
| `getDaysFromRange` | Переводит UI-range в `startDate + days` для текущей недели/месяца/квартала/года |
| `formatDateStr` | Форматирует дату без timezone-shift артефактов |
| `formatPace` | Подаёт темп в `MM:SS` |
| `processStatsData` | Главный агрегатор вкладки обзора: total distance/time/workouts, avg pace, chart data по дням, прогресс плана, recent workouts |

Важно:

- `processStatsData()` не доверяет MySQL `AVG()` для pace-строк и пересчитывает средний темп на фронте;
- умеет работать как с grouped summary, так и с плоским списком тренировок;
- считает `planProgress` только по дням, которые реально являются тренировочными, а не `rest`.

### `src/utils/calendarHelpers.js`

| Функция | Реальная роль |
|---------|----------------|
| `getDateForDay` | Переводит `week.start_date + mon/tue/...` в конкретную дату |
| `getTrainingClass` | Mapping plan type -> CSS class |
| `getShortDescription` | Генерирует compact HTML summary тренировки для календарных карточек |
| `formatDateShort`, `getDayName` | Локализованное форматирование даты и дня недели |
| `planTypeToCategory`, `workoutTypeToCategory` | Сводят типы плана и фактических workouts к общим категориям сравнения |
| `getPlanDayForDate` | Находит день плана по календарной дате |
| `getDayCompletionStatus` | Определяет completion по комбинации plan/results/workouts summary |
| `getPlanWeekCategories` | Достаёт категории тренировок по неделе для календарного UI |

Особенно важна `getShortDescription()`:

- она не просто обрезает текст;
- для `tempo/easy/long/interval/ofp/race/...` парсит дистанцию, пульс, темп и строит разные HTML-ветки.

### `src/utils/workoutFormUtils.js`

| Группа | Функции |
|--------|---------|
| Время | `parseTime`, `formatTime` |
| Темп | `parsePace`, `formatPace` |
| Input masks | `maskTimeInput`, `maskPaceInput` |
| Label maps | `RUN_TYPES`, `SIMPLE_RUN_TYPES`, `TYPE_LABELS`, `ACTIVITY_TYPE_LABELS`, `SOURCE_LABELS` |
| Display helpers | `getActivityTypeLabel`, `getWorkoutDisplayType`, `getSourceLabel` |

Этот модуль нужен сразу нескольким местам: `AddTrainingModal`, `ResultModal`, `WorkoutDetailsModal` и карточкам тренировок.

## 4. Платформенные сервисы и защита сессии

### `src/services/TokenStorageService.js`

Это базовый транспортный сервис авторизации на клиенте.

| Метод | Роль |
|-------|------|
| `isNativeCapacitor` | Отличает Android/iOS от web |
| `_getSecureStorage` | Ленивая и безопасная инициализация SecureStorage |
| `getTokens` | На native сначала читает Preferences backup, потом SecureStorage, потом fallback localStorage |
| `_getTokensFromPreferencesBackup` | Читает резервную копию токенов из Preferences |
| `_tryRestoreToSecureStorage` | Пытается восстановить токены обратно в SecureStorage |
| `saveTokens` | Сначала сохраняет backup в Preferences, затем фоново пишет в SecureStorage |
| `clearTokens` | Чистит backup, localStorage и SecureStorage |
| `getDeviceId` / `saveDeviceId` / `getOrCreateDeviceId` | Поддерживают стабильный `device_id` для push и refresh tokens |
| `isPasswordReauthBypassEnabled` / `setPasswordReauthBypass` | Хранят локальный флаг пропуска повторного ввода пароля |

Ключевой архитектурный смысл:

- в web-режиме источник истины - `localStorage`;
- на native основной источник истины - `Preferences`, потому что KeyStore может теряться после обновлений ОС.

### `src/services/BiometricService.js`

| Метод | Роль |
|-------|------|
| `checkAvailability` | Проверяет, доступна ли биометрия и какой это тип |
| `authenticate` | Показывает системный biometric prompt |
| `saveTokens` | Включает флаг `biometric_enabled` и сохраняет токены через `TokenStorageService` |
| `getTokens` | Достаёт токены для биометрического входа |
| `isBiometricEnabled` | Проверяет локальный флаг настройки |
| `clearTokens` | Отключает биометрическую авторизацию локально |
| `authenticateAndGetTokens` | Полный flow: проверить настройку -> пройти biometric prompt -> получить токены |

Смысл сервиса: биометрия не логинит пользователя сама, а разблокирует доступ к уже сохранённой сессии или запускает recovery.

### `src/services/PinAuthService.js`

| Блок | Что делает |
|------|------------|
| PBKDF2 + AES-GCM | PIN не хранится, а используется для derivation ключа шифрования |
| Lock state | После нескольких неверных попыток включает экспоненциальную блокировку |
| `setPinAndSaveTokens` | Сохраняет токены в зашифрованном payload |
| `verifyAndGetTokens` | Проверяет PIN, расшифровывает payload и возвращает токены |
| `clearPin` | Удаляет PIN-конфигурацию и lock state |

### `src/services/CredentialBackupService.js`

Этот сервис нужен не для обычной работы, а для аварийного восстановления, когда токены утрачены.

| Метод | Роль |
|-------|------|
| `hasCredentials` / `hasCredentialsFor` | Проверяют, есть ли резервные credentials для PIN и/или биометрии |
| `saveCredentialsSecure` | Сохраняет `username/password` в SecureStorage для recovery после biometric unlock |
| `recoverAndLoginBiometric` | Поднимает credentials из SecureStorage и делает обычный `api.login()` |
| `saveCredentials` | Сохраняет `username/password`, зашифрованные PIN-кодом |
| `recoverAndLogin` | Сначала пытается восстановить PIN-encrypted credentials, потом fallback на SecureStorage |
| `clearCredentials` | Удаляет резервные credentials |

Практический смысл:

- PIN и биометрия могут восстановить не только токены, но и сам логин;
- это страховка от случаев, когда refresh token или SecureStorage были потеряны.

### `src/services/PushService.js`

| Функция | Роль |
|---------|------|
| `registerPushNotifications` | Запрашивает разрешение, регистрирует listeners, вызывает `PushNotifications.register()` и отправляет FCM token на backend |
| `unregisterPushNotifications` | Отключает push на устройстве и удаляет токен на сервере |

Listener-часть внутри `setupListeners()`:

- отправляет registration token в backend с `device_id`;
- обрабатывает клики по notification action;
- умеет открывать `/chat` или `/calendar?date=...`.

### `src/services/WebPushService.js`

| Метод | Роль |
|-------|------|
| `isSupported` | Проверяет наличие Service Worker, PushManager и Notification API |
| `getPermission` | Возвращает текущее состояние разрешения браузера |
| `registerServiceWorker` | Регистрирует `/sw.js` |
| `ensureSubscription` | Создаёт или переиспользует push subscription и отправляет её на backend |
| `getCurrentSubscription` | Находит текущую подписку в service worker |
| `unregister` | Отписывает браузерную подписку и удаляет её на сервере |

### `src/services/ChatSSE.js`

Это singleton-слой для real-time unread counters.

| Метод | Роль |
|-------|------|
| `connect` / `disconnect` | Управляют одним `EventSource` на всё приложение |
| `subscribe` / `unsubscribe` | Реестр подписчиков на unread updates |
| `getUnreadData`, `getUnreadTotal`, `getUnreadByType` | Синхронное чтение текущего состояния |
| `setUnreadData` | Ручной update unread state без сервера |

Особенность:

- reconnect делается с exponential backoff;
- соединение держится только пока есть хотя бы один подписчик.

### `src/services/ChatStreamWorker.js`

`runChatStreamInWorker()` уносит чтение NDJSON-стрима AI-ответа в Web Worker.

Практически это означает:

- основной поток не занят чтением стрима;
- воркер получает `url/body/token`;
- в главный поток возвращаются `chunk`, `plan_updated`, `done`, `error`.

## 5. Shared hooks и компактные utils

### Hooks

| Файл | Хук | Реальная роль |
|------|-----|----------------|
| `useChatUnread.js` | `useChatUnread` | Подписывается на `ChatSSE`, обновляет unread state только если payload реально изменился |
| `useRetryCooldown.js` | `useRetryCooldown` | Универсальный countdown для rate-limit/retry UX |
| `useVerificationCodeFlow.js` | `useVerificationCodeFlow` | Управляет шагом подтверждения email-кода, cooldown и количеством оставшихся попыток |
| `usePasswordResetRequest.js` | `usePasswordResetRequest` | Обслуживает forgot-password форму, включая retry-after и состояние "ссылка отправлена" |
| `useIsTabActive.js` | `useIsTabActive` | Проверяет, соответствует ли текущий route конкретной вкладке |
| `useMediaQuery.js` | `useMediaQuery` | Реактивная обёртка над `matchMedia` |
| `useSwipeableTabs.js` | `useSwipeableTabs` | Горизонтальный свайп между табами с axis-lock, minimum distance и исключением input-like элементов |

### Utils

| Файл | Ключевые функции | Роль |
|------|------------------|------|
| `authError.js` | `getAuthErrorMessage`, `getAuthRetryAfter` | Преобразует backend rate-limit ошибки в человекочитаемый текст |
| `avatarUrl.js` | `getAvatarSrc` | Собирает URL через `get_avatar`, поддерживает variants `sm/md/lg` и отбрасывает некорректные имена файлов |
| `modulePreloader.js` | `preloadScreenModules`, `preloadScreenModulesDelayed`, `preloadAuthenticatedModules` | Legacy preloading вспомогательных экранов |
| `lazyWithRetry.js` | `isChunkLoadError`, `lazyWithRetry` | При ошибке загрузки чанка один раз делает `window.location.reload()` |
| `appUpdate.js` | `startAppUpdatePolling` | Смотрит `version.json` и один раз на build id делает reload |
| `logger.js` | `initLogger`, `installGlobalErrorLogger`, `logger.log/warn/error` | В web пишет в console, в native дублирует лог в файл `planrun.log` и режет файл по размеру |

## 6. Где менять что

| Сценарий | Основные файлы |
|----------|----------------|
| AI-чат и direct dialogs | `src/screens/chat/*`, `src/services/ChatSSE.js`, `src/services/ChatStreamWorker.js` |
| PIN/биометрия/recovery | `src/services/TokenStorageService.js`, `src/services/BiometricService.js`, `src/services/PinAuthService.js`, `src/services/CredentialBackupService.js` |
| Профиль и notification settings | `src/screens/settings/useSettingsProfile.js`, `src/screens/settings/profileForm.js`, `src/screens/settings/notificationSettings.js` |
| Интеграции, avatar, Telegram, push | `src/screens/settings/useSettingsActions.js`, `src/services/PushService.js`, `src/services/WebPushService.js` |
| Dashboard derived state | `src/components/Dashboard/useDashboardData.js`, `src/components/Stats/StatsUtils.js`, `src/utils/calendarHelpers.js` |
| Общий resilience-layer | `src/utils/lazyWithRetry.js`, `src/utils/appUpdate.js`, `src/utils/logger.js` |

## 7. Что читать вместе с этим документом

- архитектурный обзор клиентского слоя: [01-FRONTEND.md](01-FRONTEND.md)
- карта файлов всего проекта: [04-FILES-REFERENCE.md](04-FILES-REFERENCE.md)
- цепочки вызовов между фронтом и бэком: [05-CALL-GRAPH.md](05-CALL-GRAPH.md)
