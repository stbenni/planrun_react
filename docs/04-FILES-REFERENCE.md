# PlanRun - справочник по файлам

Справочник по исходным файлам приложения и смежных backend-модулей.

В справочник включено **310** исходных файлов. Колонка `Символов` помогает быстро понять насыщенность модуля публичной логикой.

## Фронтенд

### Точка входа

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/main.jsx` | 32 | 0 | Точка входа React-приложения: инициализирует логгер, native-режим и service worker для web push. |

### Оболочка приложения

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/App.jsx` | 243 | 3 | Главная оболочка приложения: роутинг, auth-gating, maintenance mode, deep links и запуск фоновых инициализаций. |

### Frontend API модули

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/api/adminApi.js` | 44 | 10 | Набор frontend-функций для вызовов API домена admin api. |
| `src/api/ApiClient.js` | 1454 | 117 | Универсальный API-клиент для веба и Capacitor: токены, refresh, таймауты и агрегирование доменных API-модулей. |
| `src/api/apiError.js` | 48 | 3 | Набор frontend-функций для вызовов API домена api error. |
| `src/api/authApi.js` | 469 | 14 | Набор frontend-функций для вызовов API домена auth api. |
| `src/api/chatApi.js` | 201 | 21 | Набор frontend-функций для вызовов API домена chat api. |
| `src/api/coachApi.js` | 84 | 20 | Набор frontend-функций для вызовов API домена coach api. |
| `src/api/getAuthClient.js` | 20 | 1 | Набор frontend-функций для вызовов API домена get auth client. |
| `src/api/planApi.js` | 35 | 7 | Набор frontend-функций для вызовов API домена plan api. |
| `src/api/statsApi.js` | 53 | 11 | Набор frontend-функций для вызовов API домена stats api. |
| `src/api/workoutApi.js` | 178 | 26 | Набор frontend-функций для вызовов API домена workout api. |

### Store

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/stores/useAuthStore.js` | 505 | 17 | Главный Zustand store авторизации: bootstrap сессии, lock-screen, PIN/биометрия и восстановление credentials. |
| `src/stores/usePlanStore.js` | 420 | 14 | Zustand store плана тренировок: статус генерации, polling очереди, загрузка и пересчёт плана. |
| `src/stores/usePreloadStore.js` | 18 | 1 | Zustand store для состояния домена use preload store. |
| `src/stores/useWorkoutRefreshStore.js` | 181 | 7 | Служебный store автообновления плана/тренировок по polling и внешним событиям. |
| `src/stores/useWorkoutStore.js` | 187 | 7 | Zustand store результатов и данных тренировочного дня. |

### Экран

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/screens/AdminScreen.jsx` | 1055 | 9 | Админский кабинет: пользователи, site settings, шаблоны уведомлений и одобрение/отклонение coach applications. |
| `src/screens/AthletesOverviewScreen.jsx` | 529 | 9 | Coach-экран обзора спортсменов: карточки атлетов, сигналы attention/compliance и управление группами. |
| `src/screens/CalendarScreen.jsx` | 768 | 1 | Главный календарный экран: week/month view, day/result modals, ручное редактирование тренировок, notes и CRUD плана. |
| `src/screens/ChatScreen.jsx` | 835 | 1 | Экран AI-чата и direct-сообщений с потоковым ответом и синхронизацией unread-состояния. |
| `src/screens/DashboardScreen.jsx` | 44 | 1 | Тонкий route-экран, монтирующий основной `Dashboard` и почти не содержащий собственной бизнес-логики. |
| `src/screens/ForgotPasswordScreen.jsx` | 74 | 1 | Экран запроса восстановления пароля с retry/cooldown UX. |
| `src/screens/LandingScreen.jsx` | 291 | 2 | Публичный marketing/entry экран с CTA, mobile-specific ветками и переходами в auth. |
| `src/screens/LoginScreen.jsx` | 96 | 1 | Экран входа пользователя с переходами в register/reset и modal/native сценариями. |
| `src/screens/RegisterScreen.jsx` | 1783 | 1 | Многошаговый onboarding и регистрация: аккаунт, цель, профиль бегуна, специализация и валидация полей. |
| `src/screens/ResetPasswordScreen.jsx` | 125 | 1 | Экран подтверждения reset flow и установки нового пароля. |
| `src/screens/SettingsScreen.jsx` | 3117 | 9 | Экран настроек профиля, интеграций, PIN/биометрии и уведомлений. |
| `src/screens/StatsScreen.jsx` | 529 | 1 | Экран аналитики: графики, heatmap, race prediction, детали тренировок и drill-down по workout history. |
| `src/screens/TrainersScreen.jsx` | 294 | 1 | Экран coach-модуля: каталог тренеров, заявки, текущие связи coach-athlete и переходы в group/apply flows. |
| `src/screens/UserProfileScreen.jsx` | 775 | 2 | Публичный или ролевой профиль пользователя с целями, беговыми метриками и privacy-aware блоками. |

### Компонент

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/components/AppTabsContent.jsx` | 173 | 12 | Постоянно смонтированные вкладки приложения с ленивой загрузкой экранов без размонтирования. |
| `src/components/Calendar/AddTrainingModal.jsx` | 1188 | 1 | Большое модальное окно ручного добавления/редактирования тренировки, включая run/OFP/SBU поля и разбор структуры тренировки. |
| `src/components/Calendar/Calendar.jsx` | 81 | 2 | Переиспользуемый React-компонент: calendar. |
| `src/components/Calendar/Day.jsx` | 100 | 1 | Переиспользуемый React-компонент: day. |
| `src/components/Calendar/DayModal.jsx` | 607 | 3 | Детальный просмотр тренировочного дня: exercises, notes, быстрые действия, связка с результатом и маршрутом. |
| `src/components/Calendar/MonthlyCalendar.jsx` | 506 | 2 | Переиспользуемый React-компонент: monthly calendar. |
| `src/components/Calendar/ResultModal.jsx` | 756 | 2 | Модальное окно ввода/редактирования фактического результата тренировки, включая блоки pace/hr/laps/route. |
| `src/components/Calendar/RouteMap.jsx` | 113 | 1 | Переиспользуемый React-компонент: route map. |
| `src/components/Calendar/Week.jsx` | 123 | 1 | Переиспользуемый React-компонент: week. |
| `src/components/Calendar/WeekCalendar.jsx` | 782 | 11 | Основной weekly-calendar renderer с виртуальными неделями, навигацией и derived helper-логикой по типам тренировок. |
| `src/components/Calendar/WeekCalendarIcons.jsx` | 13 | 0 | Переиспользуемый React-компонент: week calendar icons. |
| `src/components/Calendar/WorkoutCard.jsx` | 433 | 4 | Карточка тренировки с типом, статусом выполнения, notes preview и action affordances для календаря. |
| `src/components/common/AppErrorBoundary.jsx` | 116 | 8 | Переиспользуемый React-компонент: app error boundary. |
| `src/components/common/BottomNav.jsx` | 90 | 1 | Переиспользуемый React-компонент: bottom nav. |
| `src/components/common/BottomNavIcons.jsx` | 76 | 7 | Переиспользуемый React-компонент: bottom nav icons. |
| `src/components/common/ChatNotificationButton.jsx` | 44 | 1 | Переиспользуемый React-компонент: chat notification button. |
| `src/components/common/Icons.jsx` | 272 | 51 | Переиспользуемый React-компонент: icons. |
| `src/components/common/LockScreen.jsx` | 183 | 1 | Экран локальной блокировки приложения для PIN/биометрии после resume/background сценариев. |
| `src/components/common/LogoLoading.jsx` | 17 | 1 | Переиспользуемый React-компонент: logo loading. |
| `src/components/common/Modal.jsx` | 92 | 1 | Переиспользуемый React-компонент: modal. |
| `src/components/common/Notifications.jsx` | 401 | 2 | Глобальная UI-точка уведомлений/баннеров с dismissed-state и action handling. |
| `src/components/common/PageTransition.jsx` | 15 | 1 | Переиспользуемый React-компонент: page transition. |
| `src/components/common/PinInput.jsx` | 134 | 1 | Переиспользуемый React-компонент: pin input. |
| `src/components/common/PinSetupModal.jsx` | 133 | 1 | Переиспользуемый React-компонент: pin setup modal. |
| `src/components/common/PlanGeneratingBanner.jsx` | 42 | 1 | Переиспользуемый React-компонент: plan generating banner. |
| `src/components/common/PublicHeader.jsx` | 56 | 1 | Переиспользуемый React-компонент: public header. |
| `src/components/common/SkeletonScreen.jsx` | 238 | 1 | Переиспользуемый React-компонент: skeleton. |
| `src/components/common/TopHeader.jsx` | 360 | 3 | Верхняя навигационная шапка приложения с role-aware actions, profile entry и contextual controls. |
| `src/components/Dashboard/Dashboard.jsx` | 795 | 7 | Главная композиция домашнего экрана: текущий день, week strip, stats widgets, race prediction и персональные карточки. |
| `src/components/Dashboard/DashboardMetricIcons.jsx` | 12 | 0 | Переиспользуемый React-компонент: dashboard metric icons. |
| `src/components/Dashboard/DashboardStatsWidget.jsx` | 164 | 1 | Переиспользуемый React-компонент: dashboard stats widget. |
| `src/components/Dashboard/DashboardWeekStrip.jsx` | 238 | 5 | Переиспользуемый React-компонент: dashboard week strip. |
| `src/components/Dashboard/ProfileQuickMetricsWidget.jsx` | 145 | 1 | Переиспользуемый React-компонент: profile quick metrics widget. |
| `src/components/Dashboard/RacePredictionWidget.jsx` | 333 | 1 | Виджет прогноза результата по дистанциям на основе backend race prediction/statistics. |
| `src/components/LoginForm.jsx` | 218 | 1 | Переиспользуемый React-компонент: login form. |
| `src/components/LoginModal.jsx` | 34 | 1 | Переиспользуемый React-компонент: login modal. |
| `src/components/ParticlesBackground.jsx` | 94 | 2 | Переиспользуемый React-компонент: particles background. |
| `src/components/RegisterModal.jsx` | 46 | 1 | Переиспользуемый React-компонент: register modal. |
| `src/components/SpecializationModal.jsx` | 48 | 1 | Переиспользуемый React-компонент: specialization modal. |
| `src/components/Stats/AchievementCard.jsx` | 21 | 1 | Переиспользуемый React-компонент: achievement card. |
| `src/components/Stats/ActivityHeatmap.jsx` | 334 | 1 | Переиспользуемый React-компонент: activity heatmap. |
| `src/components/Stats/DistanceChart.jsx` | 166 | 1 | Переиспользуемый React-компонент: distance chart. |
| `src/components/Stats/HeartRateChart.jsx` | 379 | 1 | Переиспользуемый React-компонент: heart rate chart. |
| `src/components/Stats/PaceChart.jsx` | 390 | 2 | Переиспользуемый React-компонент: pace chart. |
| `src/components/Stats/RecentWorkoutIcons.jsx` | 18 | 0 | Переиспользуемый React-компонент: recent workout icons. |
| `src/components/Stats/RecentWorkoutsList.jsx` | 122 | 1 | Переиспользуемый React-компонент: recent workouts list. |
| `src/components/Stats/WeeklyProgressChart.jsx` | 48 | 1 | Переиспользуемый React-компонент: weekly progress chart. |
| `src/components/Stats/WorkoutDetailsModal.jsx` | 650 | 8 | Drill-down модалка одной тренировки: laps, charts, summary metrics и share-oriented представление. |
| `src/components/Stats/WorkoutShareCard.jsx` | 185 | 4 | Переиспользуемый React-компонент: workout share card. |
| `src/components/Trainers/ApplyCoachForm.jsx` | 618 | 1 | Крупная форма подачи заявки тренера с профилем, опытом, специализацией и pricing-related полями. |

### Хук

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/components/Dashboard/useDashboardData.js` | 429 | 12 | Пользовательский React-хук: use dashboard data. |
| `src/components/Dashboard/useDashboardPullToRefresh.js` | 75 | 1 | Пользовательский React-хук: use dashboard pull to refresh. |
| `src/hooks/useChatUnread.js` | 39 | 2 | Пользовательский React-хук: use chat unread. |
| `src/hooks/useIsTabActive.js` | 8 | 1 | Пользовательский React-хук: use is tab active. |
| `src/hooks/useMediaQuery.js` | 24 | 1 | Пользовательский React-хук: use media query. |
| `src/hooks/usePasswordResetRequest.js` | 68 | 1 | Пользовательский React-хук: use password reset request. |
| `src/hooks/useRetryCooldown.js` | 51 | 1 | Пользовательский React-хук: use retry cooldown. |
| `src/hooks/useSwipeableTabs.js` | 133 | 2 | Пользовательский React-хук: use swipeable tabs. |
| `src/hooks/useVerificationCodeFlow.js` | 64 | 1 | Пользовательский React-хук: use verification code flow. |
| `src/screens/chat/useChatDirectories.js` | 48 | 1 | Пользовательский React-хук: use chat directories. |
| `src/screens/chat/useChatMessageLists.js` | 101 | 1 | Пользовательский React-хук: use chat message lists. |
| `src/screens/chat/useChatNavigation.js` | 322 | 1 | Пользовательский React-хук: use chat navigation. |
| `src/screens/chat/useChatSubmitHandlers.js` | 296 | 1 | Пользовательский React-хук: use chat submit handlers. |
| `src/screens/settings/useCoachPricing.js` | 64 | 1 | Пользовательский React-хук: use coach pricing. |
| `src/screens/settings/useMyCoaches.js` | 36 | 1 | Пользовательский React-хук: use my coaches. |
| `src/screens/settings/useSettingsActions.js` | 331 | 1 | Пользовательский React-хук: use settings actions. |
| `src/screens/settings/useSettingsProfile.js` | 244 | 1 | Пользовательский React-хук: use settings profile. |

### Сервис

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/services/BiometricService.js` | 253 | 9 | Frontend-сервис или платформенная абстракция для домена biometric. |
| `src/services/ChatSSE.js` | 127 | 12 | Frontend-сервис или платформенная абстракция для домена chat sse. |
| `src/services/ChatStreamWorker.js` | 63 | 1 | Frontend-сервис или платформенная абстракция для домена chat stream worker. |
| `src/services/CredentialBackupService.js` | 307 | 18 | Frontend-сервис или платформенная абстракция для домена credential backup. |
| `src/services/PinAuthService.js` | 301 | 19 | Frontend-сервис или платформенная абстракция для домена pin auth. |
| `src/services/PushService.js` | 115 | 3 | Регистрация и синхронизация push-уведомлений в Capacitor-приложении. |
| `src/services/TokenStorageService.js` | 300 | 15 | Абстракция хранения токенов и device id для веба и native-среды. |
| `src/services/WebPushService.js` | 115 | 8 | Web Push и service worker для браузерной версии приложения. |

### Утилита

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/components/AppLayout.jsx` | 62 | 1 | Каркас авторизованной зоны: header, notifications, banner, onboarding modal и таб-контент. |
| `src/components/Dashboard/dashboardConfig.js` | 14 | 0 | Утилитарный модуль для домена dashboard config. |
| `src/components/Dashboard/dashboardDateUtils.js` | 55 | 5 | Утилитарный модуль для домена dashboard date utils. |
| `src/components/Dashboard/dashboardLayout.js` | 106 | 10 | Утилитарный модуль для домена dashboard layout. |
| `src/components/Stats/index.js` | 31 | 0 | Утилитарный модуль для домена index. |
| `src/components/Stats/StatsUtils.js` | 367 | 6 | Утилитарный модуль для домена stats utils. |
| `src/screens/chat/chatConstants.js` | 24 | 1 | Утилитарный модуль для домена chat constants. |
| `src/screens/chat/chatQuickReplies.js` | 64 | 1 | Утилитарный модуль для домена chat quick replies. |
| `src/screens/chat/chatTime.js` | 26 | 1 | Утилитарный модуль для домена chat time. |
| `src/screens/settings/notificationSettings.js` | 305 | 5 | Утилитарный модуль для домена notification settings. |
| `src/screens/settings/profileForm.js` | 240 | 9 | Утилитарный модуль для домена profile form. |
| `src/screens/settings/settingsUtils.js` | 20 | 3 | Утилитарный модуль для домена settings utils. |
| `src/utils/appUpdate.js` | 80 | 2 | Утилитарный модуль для домена app update. |
| `src/utils/authError.js` | 30 | 2 | Утилитарный модуль для домена auth error. |
| `src/utils/avatarUrl.js` | 37 | 1 | Утилитарный модуль для домена avatar url. |
| `src/utils/calendarHelpers.js` | 433 | 11 | Утилитарный модуль для домена calendar helpers. |
| `src/utils/lazyWithRetry.js` | 45 | 2 | Утилитарный модуль для домена lazy with retry. |
| `src/utils/logger.js` | 122 | 6 | Утилитарный модуль для домена logger. |
| `src/utils/modulePreloader.js` | 55 | 4 | Утилитарный модуль для домена module preloader. |
| `src/utils/workoutFormUtils.js` | 147 | 9 | Утилитарный модуль для домена workout form utils. |

### Worker

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `src/workers/chatStream.worker.js` | 85 | 0 | Web Worker для фоновой обработки: chat stream. |

## API-слой

### PHP entrypoint

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `api/api_wrapper.php` | 69 | 0 | Прокси-обёртка для API: CORS, session_init и передача запроса в `planrun-backend/api_v2.php`. |
| `api/chat_sse.php` | 119 | 0 | SSE-точка входа для потокового ответа чата. |
| `api/complete_specialization_api.php` | 15 | 0 | Legacy-обёртка завершения специализации пользователя. |
| `api/cors.php` | 62 | 0 | Отправка CORS-заголовков и обработка preflight-запросов. |
| `api/health.php` | 8 | 0 | Минимальный health-check для API. |
| `api/login_api.php` | 48 | 0 | Legacy/login entrypoint для совместимости с ранними клиентами. |
| `api/logout_api.php` | 32 | 0 | Legacy/logout entrypoint для совместимости с ранними клиентами. |
| `api/oauth_callback.php` | 117 | 0 | OAuth callback внешних интеграций: Strava, Huawei Health и Polar. |
| `api/register_api.php` | 14 | 0 | Legacy/register entrypoint для сценариев регистрации. |
| `api/session_init.php` | 37 | 0 | Инициализация PHP-сессии и параметров cookie для API. |
| `api/strava_webhook.php` | 160 | 0 | Webhook Strava: подтверждение подписки и обработка событий активности. |
| `api/telegram_login_callback.php` | 136 | 3 | Callback Telegram Login Widget и привязки Telegram-аккаунта. |

## Бэкенд

### PHP ядро

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/api_v2.php` | 875 | 1 | Главный роутер action-based API: публичные маршруты, CORS fallback и диспетчеризация на контроллеры. |
| `planrun-backend/auth.php` | 64 | 4 | Низкоуровневые session-auth helpers: `isAuthenticated`, `login`, `logout`, `requireAuth`. |
| `planrun-backend/cache_config.php` | 304 | 33 | Абстракция кеша приложения: Redis/Memcached/file cache, фасад `Cache::*`, TTL и invalidation helpers. |
| `planrun-backend/calendar_access.php` | 222 | 4 | Проверка доступа к календарю по owner/coach/public-token модели и построение красивых URL пользователя/тренировки. |
| `planrun-backend/complete_specialization_api.php` | 404 | 1 | Второй этап регистрации: обновляет профиль после minimal signup и ставит генерацию плана в очередь. |
| `planrun-backend/db_config.php` | 51 | 1 | Подключение к MySQL и выдача mysqli-экземпляра приложению. |
| `planrun-backend/load_training_plan.php` | 153 | 1 | Загрузка плана пользователя в frontend-compatible `weeks_data`, пересчёт объёма из exercises и кеширование результата. |
| `planrun-backend/prepare_weekly_analysis.php` | 701 | 3 | Сбор `plan-vs-fact` структуры недели для weekly review и adaptation engine, включая manual и imported workouts. |
| `planrun-backend/query_helpers.php` | 219 | 7 | Централизованные SQL/helper-функции для coach access, preferred days и метаданных тренировочных недель. |
| `planrun-backend/register_api.php` | 464 | 4 | Полный legacy endpoint регистрации: field validation, email verification, conditional required fields и автологин через сессию. |
| `planrun-backend/training_utils.php` | 97 | 3 | Сопоставление даты тренировки с неделей/днём плана и вспомогательный форматтер длительности. |
| `planrun-backend/user_functions.php` | 243 | 8 | Централизованный доступ к данным пользователя, session-cache, timezone и генерации `username_slug`. |
| `planrun-backend/workout_types.php` | 82 | 2 | Кешируемый доступ к справочнику `activity_types` и lookup типа тренировки по id. |

### Конфиг

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/config/constants.php` | 238 | 24 | Enum-like справочники целей, ролей, полов, workout types, exercise categories и health/running уровней. |
| `planrun-backend/config/env_loader.php` | 68 | 2 | Лёгкий загрузчик `.env` и helper `env()` без внешних зависимостей. |
| `planrun-backend/config/error_handler.php` | 206 | 10 | Глобальный обработчик ошибок: JSON responses, exception logging и shutdown handling. |
| `planrun-backend/config/init.php` | 25 | 0 | Сводный bootstrap logger/error handler/rate limiter/cache для CLI и web-скриптов. |
| `planrun-backend/config/Logger.php` | 191 | 13 | Структурированное файловое логирование по уровням с JSON-context и ротацией старых логов. |
| `planrun-backend/config/RateLimiter.php` | 173 | 6 | API rate limiting с отдельными bucket'ами для plan generation, adaptation, chat, upload и login. |

### Контроллер

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/controllers/AdaptationController.php` | 35 | 3 | Контроллер API для домена adaptation. |
| `planrun-backend/controllers/AdminController.php` | 532 | 16 | Контроллер API для домена admin. |
| `planrun-backend/controllers/AuthController.php` | 313 | 10 | Контроллер API для домена auth. |
| `planrun-backend/controllers/BaseController.php` | 262 | 13 | Контроллер API для домена base. |
| `planrun-backend/controllers/ChatController.php` | 498 | 22 | Контроллер AI-чата и direct chat между пользователями и администраторами. |
| `planrun-backend/controllers/CoachController.php` | 878 | 19 | Контроллер coach-модуля: каталог тренеров, заявки, группы и pricing. |
| `planrun-backend/controllers/ExerciseController.php` | 116 | 7 | Контроллер API для домена exercise. |
| `planrun-backend/controllers/IntegrationsController.php` | 141 | 7 | Контроллер API для домена integrations. |
| `planrun-backend/controllers/NoteController.php` | 278 | 11 | Контроллер заметок к дням/неделям плана и уведомлений по плану. |
| `planrun-backend/controllers/PushController.php` | 72 | 3 | Контроллер регистрации и удаления push/web-push токенов устройства. |
| `planrun-backend/controllers/StatsController.php` | 175 | 8 | Контроллер API для домена stats. |
| `planrun-backend/controllers/TrainingPlanController.php` | 176 | 12 | Контроллер API для домена training plan. |
| `planrun-backend/controllers/UserController.php` | 1345 | 19 | Контроллер API для домена user. |
| `planrun-backend/controllers/WeekController.php` | 211 | 10 | Контроллер API для домена week. |
| `planrun-backend/controllers/WorkoutController.php` | 474 | 15 | Контроллер API для домена workout. |

### Сервис

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/services/AdaptationService.php` | 54 | 2 | Сервис бизнес-логики для домена adaptation. |
| `planrun-backend/services/AuthService.php` | 340 | 10 | Сервис авторизации, refresh-токенов и password reset. |
| `planrun-backend/services/AvatarService.php` | 546 | 29 | Сервис бизнес-логики для домена avatar. |
| `planrun-backend/services/BaseService.php` | 79 | 10 | Сервис бизнес-логики для домена base. |
| `planrun-backend/services/ChatContextBuilder.php` | 1064 | 23 | Сервис бизнес-логики для домена chat context builder. |
| `planrun-backend/services/ChatActionParser.php` | — | — | Санитизация ответа LLM и парсинг/исполнение ACTION-блоков; используется ChatService. |
| `planrun-backend/services/ChatToolRegistry.php` | — | — | Реестр и исполнение tools AI-чата; используется ChatService и ChatActionParser. |
| `planrun-backend/services/ChatService.php` | 2760 | 65 | Ядро AI-чата: history summarization, RAG, tool-calls, streaming и direct messaging. |
| `planrun-backend/services/DateResolver.php` | 118 | 3 | Сервис бизнес-логики для домена date resolver. |
| `planrun-backend/services/EmailNotificationService.php` | 141 | 5 | Сервис бизнес-логики для домена email notification. |
| `planrun-backend/services/EmailService.php` | 160 | 6 | Сервис бизнес-логики для домена email. |
| `planrun-backend/services/EmailVerificationService.php` | 193 | 7 | Сервис бизнес-логики для домена email verification. |
| `planrun-backend/services/ExerciseService.php` | 219 | 7 | Сервис бизнес-логики для домена exercise. |
| `planrun-backend/services/JwtService.php` | 342 | 14 | Сервис бизнес-логики для домена jwt. |
| `planrun-backend/services/NotificationDispatcher.php` | 250 | 8 | Оркестратор доставки уведомлений по каналам: push, web push, email, Telegram и digest. |
| `planrun-backend/services/NotificationSettingsService.php` | 1669 | 44 | Сервис пользовательских настроек уведомлений, quiet hours, очередей доставки и журналов. |
| `planrun-backend/services/NotificationTemplateService.php` | 619 | 20 | Сервис бизнес-логики для домена notification template. |
| `planrun-backend/services/PlanGenerationProcessorService.php` | 490 | 10 | Worker-side обработчик очереди генерации/пересчёта плана. |
| `planrun-backend/services/PlanGenerationQueueService.php` | 211 | 11 | Очередь AI-задач генерации планов и служебные операции резервирования job. |
| `planrun-backend/services/PlanNotificationService.php` | 214 | 12 | Доменные уведомления о смене плана, результатах тренировок и связанных coach-athlete событиях. |
| `planrun-backend/services/PlanSkeletonBuilder.php` | 371 | 11 | Сервис бизнес-логики для домена plan skeleton builder. |
| `planrun-backend/services/PushNotificationService.php` | 225 | 8 | Отправка нативных push-уведомлений через FCM и управление токенами. |
| `planrun-backend/services/RegisterApiService.php` | 46 | 5 | Сервис бизнес-логики для домена register api. |
| `planrun-backend/services/RegistrationService.php` | 405 | 14 | Сервис бизнес-логики для домена registration. |
| `planrun-backend/services/StatsService.php` | 385 | 7 | Сервис бизнес-логики для домена stats. |
| `planrun-backend/services/TelegramLoginService.php` | 493 | 25 | Сервис бизнес-логики для домена telegram login. |
| `planrun-backend/services/TrainingPlanService.php` | 403 | 12 | Сервис загрузки, статуса, очистки и запуска генерации тренировочных планов. |
| `planrun-backend/services/TrainingStateBuilder.php` | 627 | 18 | Сервис бизнес-логики для домена training state builder. |
| `planrun-backend/services/WebPushNotificationService.php` | 251 | 13 | Отправка web push-уведомлений в браузерные подписки. |
| `planrun-backend/services/WeekService.php` | 404 | 10 | Сервис бизнес-логики для домена week. |
| `planrun-backend/services/WorkoutPlanRecalculationService.php` | 101 | 3 | Логика пересчёта/адаптации плана на основе уже выполненных тренировок. |
| `planrun-backend/services/WorkoutService.php` | 1288 | 16 | Сервис тренировок и результатов: day-view, импорт, таймлайны, lap data и VDOT update. |

### Репозиторий

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/repositories/BaseRepository.php` | 79 | 6 | Репозиторий доступа к данным для домена base. |
| `planrun-backend/repositories/ChatRepository.php` | 440 | 24 | Репозиторий доступа к данным для домена chat. |
| `planrun-backend/repositories/ExerciseRepository.php` | 90 | 6 | Репозиторий доступа к данным для домена exercise. |
| `planrun-backend/repositories/NoteRepository.php` | 104 | 11 | Репозиторий доступа к данным для домена note. |
| `planrun-backend/repositories/NotificationRepository.php` | 34 | 3 | Репозиторий доступа к данным для домена notification. |
| `planrun-backend/repositories/StatsRepository.php` | 76 | 5 | Репозиторий доступа к данным для домена stats. |
| `planrun-backend/repositories/TrainingPlanRepository.php` | 50 | 6 | Репозиторий доступа к данным для домена training plan. |
| `planrun-backend/repositories/WeekRepository.php` | 146 | 14 | Репозиторий доступа к данным для домена week. |
| `planrun-backend/repositories/WorkoutRepository.php` | 49 | 4 | Репозиторий доступа к данным для домена workout. |

### Провайдер

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/providers/HuaweiHealthProvider.php` | 234 | 13 | OAuth и импорт Huawei Health тренировок с маппингом внешнего payload в внутренний workout format. |
| `planrun-backend/providers/PolarProvider.php` | 317 | 17 | Polar AccessLink интеграция: OAuth, импорт exercises, построение timeline и disconnect flow. |
| `planrun-backend/providers/StravaProvider.php` | 857 | 28 | Самый тяжёлый импорт-провайдер: OAuth, refresh token, webhook health, activity streams, laps и single-activity fetch. |
| `planrun-backend/providers/WorkoutImportProvider.php` | 43 | 7 | Контракт импорт-провайдера: OAuth, token refresh, fetch workouts, check connected и disconnect. |

### Валидатор

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/validators/BaseValidator.php` | 116 | 10 | Базовый набор validator-helpers: required/type/date/range/length checks и накопление ошибок. |
| `planrun-backend/validators/ExerciseValidator.php` | 95 | 5 | Проверка payload для CRUD упражнений и reorder day exercises. |
| `planrun-backend/validators/TrainingPlanValidator.php` | 37 | 3 | Валидация action'ов regenerate/check-status и связанных параметров планогенерации. |
| `planrun-backend/validators/WeekValidator.php` | 121 | 6 | Проверка операций над неделями и training days: add/delete/update/copy. |
| `planrun-backend/validators/WorkoutValidator.php` | 49 | 3 | Валидация day/result запросов и payload сохранения тренировки. |

### AI модуль

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/planrun_ai/create_empty_plan.php` | 89 | 1 | Создание пустого календаря `free`-дней для режима самостоятельных тренировок с пересборкой недель от ближайшего понедельника. |
| `planrun-backend/planrun_ai/description_parser.php` | 117 | 1 | Парсер текстовых описаний ОФП/СБУ обратно в структурированные exercises для сохранения в БД. |
| `planrun-backend/planrun_ai/plan_generator.php` | 954 | 9 | Legacy orchestration генерации, пересчёта и next-plan сценариев: prompt, вызов AI, JSON repair и сбор контекста из БД. |
| `planrun-backend/planrun_ai/plan_normalizer.php` | 630 | 12 | Нормализует сырой LLM JSON, пересчитывает даты, строит `description`, derived distances и enforce'ит preferred-days расписание. |
| `planrun-backend/planrun_ai/plan_review_generator.php` | 117 | 2 | Строит краткую рецензию уже сохранённого плана через chat LLM и готовит её для отправки в пользовательский чат. |
| `planrun-backend/planrun_ai/plan_saver.php` | 345 | 2 | Транзакционное сохранение недель, дней и exercises, включая полную замену или частичный recalculate хвоста плана. |
| `planrun-backend/planrun_ai/plan_validator.php` | 75 | 5 | Агрегатор validators нормализованного плана с severity scoring и решением о corrective regeneration. |
| `planrun-backend/planrun_ai/planrun_ai_config.php` | 39 | 1 | Env-конфигурация локального AI-service: endpoint, timeout, feature flag и health-check. |
| `planrun-backend/planrun_ai/planrun_ai_integration.php` | 157 | 3 | HTTP-интеграция с PlanRun AI API: payload, retry/backoff, max_tokens и возврат JSON-плана. |
| `planrun-backend/planrun_ai/prompt_builder.php` | 3074 | 44 | Построение промптов и оценка реалистичности целей для AI-пайплайна. |
| `planrun-backend/planrun_ai/skeleton/ControlWorkoutBuilder.php` | 55 | 2 | Шаблон контрольных тренировок и тестовых недель по race distance и pace rules. |
| `planrun-backend/planrun_ai/skeleton/enrichment_prompt_builder.php` | 212 | 4 | Компактные prompt builders для LLM-enrichment и LLM-review поверх numeric skeleton. |
| `planrun-backend/planrun_ai/skeleton/FartlekBuilder.php` | 147 | 4 | Прогрессия фартлек-сессий по сложности сегментов и дистанции. |
| `planrun-backend/planrun_ai/skeleton/IntervalProgressionBuilder.php` | 189 | 3 | Генератор интервальных сессий по фазам, счётчику тренировок и целевой дистанции. |
| `planrun-backend/planrun_ai/skeleton/LLMEnricher.php` | 199 | 6 | Вызывает LLM только для обогащения скелета текстовыми `notes`, не доверяя ей числовые поля. |
| `planrun-backend/planrun_ai/skeleton/LLMReviewer.php` | 126 | 5 | Прогоняет готовый skeleton-plan через LLM reviewer и получает machine-readable issues. |
| `planrun-backend/planrun_ai/skeleton/OfpProgressionBuilder.php` | 74 | 2 | Строит недельную схему ОФП с учётом preference и recovery-state. |
| `planrun-backend/planrun_ai/skeleton/PlanAutoFixer.php` | 289 | 10 | Автоматически чинит pace logic, volume jumps, consecutive key workouts и recovery/taper issues. |
| `planrun-backend/planrun_ai/skeleton/PlanSkeletonGenerator.php` | 607 | 15 | Главный rule-based numeric generator: user/state/macrocycle -> полный числовой план без LLM. |
| `planrun-backend/planrun_ai/skeleton/RacePaceProgressionBuilder.php` | 253 | 4 | Генерирует race-pace tempo блоки и повторные сессии для MP/HMP/10k/R-pace сценариев. |
| `planrun-backend/planrun_ai/skeleton/SkeletonValidator.php` | 286 | 5 | Сравнивает enriched план с исходным скелетом и валидирует его внутреннюю непротиворечивость. |
| `planrun-backend/planrun_ai/skeleton/StartRunningProgramBuilder.php` | 162 | 5 | Фиксированные beginner-программы для `start_running`/`couch_to_5k`. |
| `planrun-backend/planrun_ai/skeleton/TempoProgressionBuilder.php` | 148 | 2 | Прогрессия threshold tempo сессий по фазам и счётчику недель. |
| `planrun-backend/planrun_ai/skeleton/VolumeDistributor.php` | 398 | 11 | Распределяет недельный объём по дням и создаёт структурные поля pace/duration/key workouts. |
| `planrun-backend/planrun_ai/skeleton/WarmupCooldownHelper.php` | 46 | 4 | Вспомогательные warmup/cooldown defaults по дистанции. |
| `planrun-backend/planrun_ai/skeleton/WeeklyAdaptationEngine.php` | 727 | 18 | Еженедельный анализ plan-vs-fact с trigger-based адаптацией и запуском recalculate pipeline. |
| `planrun-backend/planrun_ai/text_generator.php` | 95 | 2 | Вспомогательная генерация короткого текстового описания тренировки из массива exercises. |
| `planrun-backend/planrun_ai/validators/goal_consistency_validator.php` | 114 | 1 | Проверка соответствия интенсивности цели, уровню и special population flags. |
| `planrun-backend/planrun_ai/validators/load_validator.php` | 78 | 1 | Проверка скачков недельного объёма и подряд идущих key workouts. |
| `planrun-backend/planrun_ai/validators/pace_validator.php` | 71 | 1 | Проверка pace corridors для easy/long/tempo дней относительно training state. |
| `planrun-backend/planrun_ai/validators/schedule_validator.php` | 86 | 1 | Проверка совпадения с skeleton'ом и размещения беговых дней по preferred schedule. |
| `planrun-backend/planrun_ai/validators/taper_validator.php` | 77 | 1 | Контроль taper и объёма на предгоночной/race week. |
| `planrun-backend/planrun_ai/validators/workout_completeness_validator.php` | 161 | 5 | Проверка, что key workouts содержат достаточную структуру и training stimulus. |

### Скрипт

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/scripts/backfill_avatar_variants.php` | 54 | 0 | Генерирует недостающие уменьшенные варианты пользовательских аватаров. |
| `planrun-backend/scripts/cleanup_expired_refresh_tokens.php` | 32 | 0 | Периодическая очистка просроченных refresh tokens. |
| `planrun-backend/scripts/eval_plan_generation.php` | 445 | 10 | Batch-eval AI генерации плана на реальных пользователях и synthetic fixtures с сохранением артефактов. |
| `planrun-backend/scripts/generate_web_push_vapid_keys.php` | 12 | 0 | Генерация VAPID ключей для browser web push. |
| `planrun-backend/scripts/get_jwt_for_push_test.php` | 53 | 0 | Получение JWT для ручного тестирования защищённых push-эндпоинтов. |
| `planrun-backend/scripts/migrate_activity_types_walking.php` | 58 | 0 | CLI/cron-скрипт: migrate activity types walking. |
| `planrun-backend/scripts/migrate_all.php` | 223 | 0 | CLI/cron-скрипт: migrate all. |
| `planrun-backend/scripts/migrate_chat_history_summary.php` | 33 | 0 | CLI/cron-скрипт: migrate chat history summary. |
| `planrun-backend/scripts/migrate_chat_tables.php` | 56 | 0 | CLI/cron-скрипт: migrate chat tables. |
| `planrun-backend/scripts/migrate_chat_user_memory.php` | 34 | 0 | CLI/cron-скрипт: migrate chat user memory. |
| `planrun-backend/scripts/migrate_coach_tables.php` | 108 | 0 | CLI/cron-скрипт: migrate coach tables. |
| `planrun-backend/scripts/migrate_email_verification_codes.php` | 32 | 0 | CLI/cron-скрипт: migrate email verification codes. |
| `planrun-backend/scripts/migrate_exercise_library.php` | 56 | 0 | CLI/cron-скрипт: migrate exercise library. |
| `planrun-backend/scripts/migrate_experience_level_nullable.php` | 26 | 0 | CLI/cron-скрипт: migrate experience level nullable. |
| `planrun-backend/scripts/migrate_integration_tokens_athlete_id.php` | 37 | 0 | CLI/cron-скрипт: migrate integration tokens athlete id. |
| `planrun-backend/scripts/migrate_integration_tokens.php` | 40 | 0 | CLI/cron-скрипт: migrate integration tokens. |
| `planrun-backend/scripts/migrate_onboarding_completed.php` | 37 | 0 | CLI/cron-скрипт: migrate onboarding completed. |
| `planrun-backend/scripts/migrate_password_reset_table.php` | 32 | 0 | CLI/cron-скрипт: migrate password reset table. |
| `planrun-backend/scripts/migrate_profile_privacy.php` | 40 | 0 | CLI/cron-скрипт: migrate profile privacy. |
| `planrun-backend/scripts/migrate_push_workout_minute.php` | 27 | 0 | CLI/cron-скрипт: migrate push workout minute. |
| `planrun-backend/scripts/migrate_refresh_tokens_device_id.php` | 44 | 0 | CLI/cron-скрипт: migrate refresh tokens device id. |
| `planrun-backend/scripts/migrate_refresh_tokens_table.php` | 35 | 0 | CLI/cron-скрипт: migrate refresh tokens table. |
| `planrun-backend/scripts/migrate_role_enum.php` | 43 | 0 | CLI/cron-скрипт: migrate role enum. |
| `planrun-backend/scripts/migrate_site_settings.php` | 31 | 0 | CLI/cron-скрипт: migrate site settings. |
| `planrun-backend/scripts/migrate_training_plan_days_multiple_per_date.php` | 32 | 0 | CLI/cron-скрипт: migrate training plan days multiple per date. |
| `planrun-backend/scripts/migrate_workout_laps.php` | 47 | 0 | CLI/cron-скрипт: migrate workout laps. |
| `planrun-backend/scripts/migrate_workout_timeline.php` | 38 | 0 | CLI/cron-скрипт: migrate workout timeline. |
| `planrun-backend/scripts/migrate_workouts_duration_seconds.php` | 36 | 0 | CLI/cron-скрипт: migrate workouts duration seconds. |
| `planrun-backend/scripts/migrate_workouts_session_id_nullable.php` | 32 | 0 | CLI/cron-скрипт: migrate workouts session id nullable. |
| `planrun-backend/scripts/migrate_workouts_source.php` | 60 | 0 | CLI/cron-скрипт: migrate workouts source. |
| `planrun-backend/scripts/plan_generation_worker.php` | 70 | 0 | Worker очереди генерации/пересчёта плана: резервирует job и передаёт её в `PlanGenerationProcessorService`. |
| `planrun-backend/scripts/process_notification_delivery_queue.php` | 84 | 0 | Доставка уведомлений, которые были отложены из-за quiet hours. |
| `planrun-backend/scripts/process_notification_email_digest.php` | 101 | 0 | Сбор и отправка ежедневных email-дайджестов по queued notification items. |
| `planrun-backend/scripts/push_workout_reminders.php` | 171 | 1 | Cron напоминаний о сегодняшней/завтрашней тренировке с извлечением summary из `weeks_data`. |
| `planrun-backend/scripts/send_test_push.php` | 68 | 0 | Отправка тестового push-уведомления по выбранному токену/пользователю. |
| `planrun-backend/scripts/backfill_hr_targets.php` | 35 | 0 | Backfill `target_hr_min`/`target_hr_max` для будущих дней планов всех пользователей. |
| `planrun-backend/scripts/strava_backfill_athlete_ids.php` | 60 | 0 | Backfill `external_athlete_id` для существующих Strava integration tokens. |
| `planrun-backend/scripts/strava_daily_health_check.php` | 100 | 0 | Регулярная проверка Strava-интеграции: refresh токенов, athlete id и webhook subscription. |
| `planrun-backend/scripts/strava_register_webhook.php` | 46 | 0 | Ручная регистрация или перепривязка Strava webhook subscription. |
| `planrun-backend/scripts/update_vdot_from_training.php` | 81 | 0 | Выбирает лучшую свежую тренировку и обновляет user-level `last_race_*`/VDOT-ориентиры. |
| `planrun-backend/scripts/weekly_ai_review.php` | 251 | 2 | Еженедельный AI cron: review в чат или full weekly adaptation через `AdaptationService`. |
| `planrun-backend/telegram/set-all-webhooks.php` | 75 | 0 | CLI/cron-скрипт: set all webhooks. |
| `planrun-backend/telegram/webhook-proxy.php` | 101 | 1 | CLI/cron-скрипт: webhook proxy. |

### Исключение

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/exceptions/AppException.php` | 35 | 5 | Пользовательский тип исключения: app exception. |
| `planrun-backend/exceptions/ForbiddenException.php` | 14 | 2 | Пользовательский тип исключения: forbidden exception. |
| `planrun-backend/exceptions/NotFoundException.php` | 14 | 2 | Пользовательский тип исключения: not found exception. |
| `planrun-backend/exceptions/UnauthorizedException.php` | 14 | 2 | Пользовательский тип исключения: unauthorized exception. |
| `planrun-backend/exceptions/ValidationException.php` | 27 | 4 | Пользовательский тип исключения: validation exception. |

### Библиотека

| Файл | Строк | Символов | Назначение |
|------|------:|---------:|------------|
| `planrun-backend/utils/GpxTcxParser.php` | 175 | 8 | Вспомогательный модуль домена gpx tcx parser. |
