# PlanRun — справочник по файлам

Краткое описание каждого исходного файла проекта (без node_modules, vendor).

---

## src/ — фронтенд

| Файл | Описание |
|------|----------|
| `main.jsx` | Точка входа, рендер App |
| `App.jsx` | Роутинг, охрана маршрутов, maintenance |
| `App.css` | Стили App |
| `index.css` | Глобальные импорты стилей |
| `api/ApiClient.js` | API-клиент, все методы бэкенда |
| `stores/useAuthStore.js` | Zustand: авторизация |
| `stores/usePlanStore.js` | Zustand: план |
| `stores/useWorkoutStore.js` | Zustand: тренировки |
| `screens/LandingScreen.jsx` | Лендинг |
| `screens/LoginScreen.jsx` | Экран входа |
| `screens/RegisterScreen.jsx` | Регистрация |
| `screens/DashboardScreen.jsx` | Дашборд |
| `screens/CalendarScreen.jsx` | Календарь |
| `screens/StatsScreen.jsx` | Статистика |
| `screens/ChatScreen.jsx` | Чат с AI |
| `screens/TrainersScreen.jsx` | Тренеры |
| `screens/SettingsScreen.jsx` | Настройки |
| `screens/UserProfileScreen.jsx` | Публичный профиль |
| `screens/ForgotPasswordScreen.jsx` | Сброс пароля (запрос) |
| `screens/ResetPasswordScreen.jsx` | Сброс пароля (подтверждение) |
| `components/AppLayout.jsx` | Layout авторизованной зоны |
| `components/AppTabsContent.jsx` | Переключение контента по pathname |
| `components/SpecializationModal.jsx` | Модалка онбординга |
| `components/RegisterModal.jsx` | Модалка регистрации |
| `components/LoginForm.jsx` | Форма входа |
| `components/common/TopHeader.jsx` | Хедер |
| `components/common/BottomNav.jsx` | Нижняя навигация |
| `components/common/BottomNavIcons.jsx` | Иконки BottomNav |
| `components/common/PublicHeader.jsx` | Публичный хедер |
| `components/common/Modal.jsx` | Базовый модал |
| `components/common/Notifications.jsx` | Уведомления |
| `components/common/ChatNotificationButton.jsx` | Кнопка уведомлений чата |
| `components/common/SkeletonScreen.jsx` | Скелетон загрузки |
| `components/common/PageTransition.jsx` | Анимация перехода |
| `components/common/Icons.jsx` | Пул иконок |
| `components/common/PinInput.jsx` | Поле PIN |
| `components/common/PinSetupModal.jsx` | Настройка PIN |
| `components/Calendar/WeekCalendar.jsx` | Недельный календарь |
| `components/Calendar/MonthlyCalendar.jsx` | Месячный календарь |
| `components/Calendar/Week.jsx` | Неделя |
| `components/Calendar/Day.jsx` | День |
| `components/Calendar/DayModal.jsx` | Модалка дня |
| `components/Calendar/WorkoutCard.jsx` | Карточка тренировки |
| `components/Calendar/AddTrainingModal.jsx` | Добавление/редактирование тренировки |
| `components/Calendar/ResultModal.jsx` | Запись результата |
| `components/Calendar/RouteMap.jsx` | Карта маршрута |
| `components/Calendar/WeekCalendarIcons.jsx` | Иконки недельного календаря |
| `components/Dashboard/Dashboard.jsx` | Дашборд |
| `components/Dashboard/DashboardWeekStrip.jsx` | Полоска недели |
| `components/Dashboard/DashboardStatsWidget.jsx` | Виджет статистики |
| `components/Dashboard/DashboardMetricIcons.jsx` | Иконки метрик |
| `components/Dashboard/ProfileQuickMetricsWidget.jsx` | Быстрые метрики |
| `components/Stats/HeartRateChart.jsx` | График пульса |
| `components/Stats/PaceChart.jsx` | График темпа |
| `components/Stats/AchievementCard.jsx` | Карточка достижения |
| `components/Stats/RecentWorkoutsList.jsx` | Последние тренировки |
| `components/Stats/RecentWorkoutIcons.jsx` | Иконки типов |
| `components/Stats/WorkoutDetailsModal.jsx` | Детали тренировки |
| `components/Stats/WorkoutShareCard.jsx` | Карточка шаринга |
| `components/Stats/StatsUtils.js` | Утилиты статистики |
| `services/BiometricService.js` | Биометрия |
| `services/PinAuthService.js` | PIN-код |
| `services/ChatStreamWorker.js` | Worker чата |
| `services/ChatSSE.js` | SSE-клиент чата |
| `hooks/useIsTabActive.js` | Активность вкладки |
| `hooks/useMediaQuery.js` | Медиа-запросы |
| `utils/logger.js` | Логгер |
| `utils/avatarUrl.js` | URL аватара |
| `utils/calendarHelpers.js` | Хелперы календаря |
| `utils/modulePreloader.js` | Предзагрузка модулей |
| `workers/chatStream.worker.js` | Web Worker чата |

---

## api/ — точки входа PHP

| Файл | Описание |
|------|----------|
| `api_wrapper.php` | Прокси к api_v2, CORS, сессия |
| `cors.php` | CORS-заголовки |
| `session_init.php` | Инициализация сессии |
| `login_api.php` | Логин (legacy) |
| `logout_api.php` | Выход (legacy) |
| `register_api.php` | Регистрация |
| `oauth_callback.php` | OAuth callback (Strava, Huawei, Polar) |
| `strava_webhook.php` | Webhook Strava |
| `chat_sse.php` | SSE для чата |
| `health.php` | Health check |
| `complete_specialization_api.php` | Завершение специализации |

---

## planrun-backend/ — бэкенд

| Файл | Описание |
|------|----------|
| `api_v2.php` | Роутер API |
| `auth.php` | Авторизация (сессии, JWT) |
| `db_config.php` | Подключение к БД |
| `cache_config.php` | Кэш |
| `user_functions.php` | Функции пользователей |
| `query_helpers.php` | Хелперы запросов |
| `training_utils.php` | Утилиты тренировок |
| `load_training_plan.php` | Загрузка плана (legacy) |
| `prepare_weekly_analysis.php` | Подготовка анализа |
| `register_api.php` | Регистрация (legacy) |
| `complete_specialization_api.php` | Специализация (legacy) |
| `config/Logger.php` | Логгер |
| `config/error_handler.php` | Обработка ошибок |
| `config/RateLimiter.php` | Rate limiting |
| `config/env_loader.php` | Загрузка .env |
| `config/constants.php` | Константы |
| `config/init.php` | Инициализация |
| `controllers/BaseController.php` | Базовый контроллер |
| `controllers/AuthController.php` | Авторизация |
| `controllers/UserController.php` | Профиль |
| `controllers/WorkoutController.php` | Тренировки |
| `controllers/TrainingPlanController.php` | План |
| `controllers/WeekController.php` | Недели и дни |
| `controllers/ExerciseController.php` | Упражнения |
| `controllers/StatsController.php` | Статистика |
| `controllers/ChatController.php` | Чат |
| `controllers/IntegrationsController.php` | Интеграции |
| `controllers/AdminController.php` | Админка |
| `controllers/AdaptationController.php` | Адаптация |
| `services/WorkoutService.php` | Логика тренировок |
| `services/TrainingPlanService.php` | Логика плана |
| `services/ChatService.php` | Логика чата |
| `services/ChatContextBuilder.php` | Контекст для AI |
| `services/StatsService.php` | Статистика |
| `services/AuthService.php` | Авторизация |
| `services/JwtService.php` | JWT |
| `services/EmailService.php` | Email |
| `services/WeekService.php` | Недели |
| `services/ExerciseService.php` | Упражнения |
| `services/AdaptationService.php` | Адаптация |
| `services/DateResolver.php` | Разрешение дат |
| `services/BaseService.php` | Базовый сервис |
| `repositories/BaseRepository.php` | Базовый репозиторий |
| `repositories/WorkoutRepository.php` | Тренировки |
| `repositories/TrainingPlanRepository.php` | План |
| `repositories/WeekRepository.php` | Недели |
| `repositories/ExerciseRepository.php` | Упражнения |
| `repositories/ChatRepository.php` | Чат |
| `repositories/StatsRepository.php` | Статистика |
| `repositories/NotificationRepository.php` | Уведомления |
| `planrun_ai/plan_generator.php` | Генерация плана |
| `planrun_ai/plan_normalizer.php` | Нормализация плана |
| `planrun_ai/plan_saver.php` | Сохранение плана |
| `planrun_ai/prompt_builder.php` | Промпты |
| `planrun_ai/description_parser.php` | Парсинг description |
| `planrun_ai/planrun_ai_integration.php` | Интеграция с AI |
| `planrun_ai/planrun_ai_config.php` | Конфиг AI |
| `planrun_ai/generate_plan_async.php` | Асинхронная генерация |
| `planrun_ai/plan_review_generator.php` | Обзор плана |
| `planrun_ai/text_generator.php` | Генерация текста |
| `planrun_ai/create_empty_plan.php` | Пустой план |
| `providers/StravaProvider.php` | Strava |
| `providers/HuaweiHealthProvider.php` | Huawei Health |
| `providers/PolarProvider.php` | Polar |
| `providers/WorkoutImportProvider.php` | Импорт GPX/TCX |
| `validators/WorkoutValidator.php` | Валидация тренировки |
| `validators/WeekValidator.php` | Валидация недели |
| `validators/ExerciseValidator.php` | Валидация упражнений |
| `validators/TrainingPlanValidator.php` | Валидация плана |
| `validators/BaseValidator.php` | Базовый валидатор |
| `utils/GpxTcxParser.php` | Парсер GPX/TCX |
| `exceptions/AppException.php` | Базовое исключение |
| `exceptions/ForbiddenException.php` | 403 |
| `exceptions/NotFoundException.php` | 404 |
| `exceptions/UnauthorizedException.php` | 401 |
| `exceptions/ValidationException.php` | Валидация |
| `scripts/migrate_*.php` | Миграции БД |
| `scripts/strava_register_webhook.php` | Регистрация Strava webhook |
| `scripts/strava_backfill_athlete_ids.php` | Заполнение athlete_id |
| `scripts/strava_daily_health_check.php` | Проверка Strava каждые 4 ч: athlete_id, refresh токена (cron) |

---

## Конфигурация и сборка

| Файл | Описание |
|------|----------|
| `package.json` | Зависимости, скрипты |
| `vite.config.js` | Конфиг Vite |
| `capacitor.config.json` | Конфиг Capacitor |
| `index.html` | HTML-точка входа |
| `openapi.yaml` | Спецификация API |
