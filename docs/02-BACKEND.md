# PlanRun — документация бэкенда

Полное описание PHP-файлов и функций.

---

## Точка входа API

### `planrun-backend/api_v2.php`
Главный роутер API. Получает `action` из `$_GET`, вызывает соответствующий контроллер.

**Публичные action'ы (без авторизации):**
- `get_avatar` — раздача аватара (до загрузки контроллеров)
- `get_site_settings` — настройки сайта
- `get_user_by_slug` — публичный профиль по slug

**Маршрутизация:** switch по `action` → создание контроллера → вызов метода.

---

## Контроллеры

### `planrun-backend/controllers/BaseController.php`
Базовый контроллер.

| Метод | Описание |
|-------|----------|
| `__construct($db)` | Инициализация с подключением к БД |
| `getParam($key)` | Параметр из GET/POST |
| `getJsonBody()` | Тело запроса как JSON |
| `returnSuccess($data)` | Успешный JSON-ответ |
| `returnError($message, $code)` | Ошибка |
| `requireAuth()` | Проверка авторизации |
| `requireEdit()` | Проверка прав редактирования (calendar) |
| `checkCsrfToken()` | Проверка CSRF |
| `handleException($e)` | Обработка исключений |

### `planrun-backend/controllers/AuthController.php`
Авторизация.

| Метод | Описание |
|-------|----------|
| `login()` | Вход (сессии или JWT) |
| `logout()` | Выход |
| `refreshToken()` | Обновление JWT |
| `requestPasswordReset()` | Запрос сброса пароля (email) |
| `confirmPasswordReset()` | Подтверждение сброса по токену |
| `checkAuth()` | Проверка авторизации, возврат user |

### `planrun-backend/controllers/UserController.php`
Профиль пользователя.

| Метод | Описание |
|-------|----------|
| `getProfile()` | Профиль текущего пользователя |
| `updateProfile()` | Обновление профиля |
| `deleteUser()` | Удаление аккаунта |
| `uploadAvatar()` | Загрузка аватара |
| `removeAvatar()` | Удаление аватара |
| `updatePrivacy()` | Обновление настроек приватности |
| `getNotificationsDismissed()` | Отклонённые уведомления |
| `dismissNotification()` | Отклонить уведомление |
| `unlinkTelegram()` | Отвязка Telegram |

### `planrun-backend/controllers/WorkoutController.php`
Тренировки и результаты.

| Метод | Описание |
|-------|----------|
| `getDay(date)` | Данные дня (план + результаты) |
| `saveResult()` | Сохранение результата тренировки |
| `getResult(date)` | Результат за дату |
| `uploadWorkout()` | Загрузка GPX/TCX |
| `getAllResults()` | Все результаты |
| `deleteWorkout()` | Удаление тренировки |
| `save()` | Сохранение плана (legacy) |
| `reset()` | Сброс дня |
| `getWorkoutTimeline(workoutId)` | Таймлайн тренировки |

### `planrun-backend/controllers/TrainingPlanController.php`
План тренировок.

| Метод | Описание |
|-------|----------|
| `load()` | Загрузка плана |
| `save()` | Сохранение плана |
| `checkStatus()` | Статус генерации плана |
| `regeneratePlan()` | Регенерация плана |
| `regeneratePlanWithProgress()` | Регенерация с учётом прогресса |
| `recalculatePlan()` | Пересчёт плана |
| `generateNextPlan()` | Генерация следующего плана |
| `reactivatePlan()` | Реактивация плана |
| `clearPlanGenerationMessage()` | Очистка сообщения о генерации |

### `planrun-backend/controllers/WeekController.php`
Недели и дни плана.

| Метод | Описание |
|-------|----------|
| `addWeek()` | Добавление недели |
| `deleteWeek()` | Удаление недели |
| `addTrainingDay()` | Добавление дня |
| `addTrainingDayByDate()` | Добавление дня по дате |
| `updateTrainingDay()` | Обновление дня |
| `deleteTrainingDay()` | Удаление дня |

### `planrun-backend/controllers/ExerciseController.php`
Упражнения дня.

| Метод | Описание |
|-------|----------|
| `addDayExercise()` | Добавление упражнения |
| `updateDayExercise()` | Обновление упражнения |
| `deleteDayExercise()` | Удаление упражнения |
| `reorderDayExercises()` | Изменение порядка |
| `listExerciseLibrary()` | Библиотека упражнений |

### `planrun-backend/controllers/StatsController.php`
Статистика.

| Метод | Описание |
|-------|----------|
| `stats()` | Общая статистика |
| `getAllWorkoutsSummary()` | Сводка тренировок |
| `getAllWorkoutsList()` | Список тренировок |
| `prepareWeeklyAnalysis()` | Подготовка недельного анализа |

### `planrun-backend/controllers/ChatController.php`
Чат с AI и админом.

| Метод | Описание |
|-------|----------|
| `getMessages()` | Сообщения AI-чата |
| `sendMessage()` | Отправка сообщения |
| `sendMessageStream()` | Streaming ответа |
| `clearAiChat()` | Очистка AI-чата |
| `sendMessageToAdmin()` | Сообщение админу |
| `getDirectMessages()` | Сообщения диалога с админом |
| `sendMessageToUser()` | Сообщение пользователю (admin) |
| `sendAdminMessage()` | Admin: отправить сообщение |
| `getDirectDialogs()` | Диалоги |
| `getAdminChatUsers()` | Admin: пользователи чата |
| `getAdminUnreadNotifications()` | Admin: непрочитанные |
| `getAdminMessages()` | Admin: сообщения диалога |
| `broadcastAdminMessage()` | Admin: рассылка |
| `markAllRead()` | Отметить всё прочитанным |
| `markAdminAllRead()` | Admin: отметить всё |
| `markRead()` | Отметить прочитанным |
| `markAdminConversationRead()` | Admin: отметить диалог |
| `addAIMessage()` | Admin: добавить AI-сообщение |

### `planrun-backend/controllers/IntegrationsController.php`
Интеграции (Strava, Huawei, Garmin).

| Метод | Описание |
|-------|----------|
| `getOAuthUrl()` | URL для OAuth (provider в query) |
| `getStatus()` | Статус интеграций |
| `syncWorkouts()` | Синхронизация тренировок |
| `getStravaTokenError()` | Ошибка токена Strava |
| `unlink()` | Отвязка интеграции |

### `planrun-backend/controllers/AdminController.php`
Админ-панель.

| Метод | Описание |
|-------|----------|
| `listUsers()` | Список пользователей |
| `getUser()` | Пользователь по ID |
| `updateUser()` | Обновление пользователя |
| `getPublicSettings()` | Публичные настройки |
| `getSettings()` | Настройки (admin) |
| `updateSettings()` | Обновление настроек |

### `planrun-backend/controllers/AdaptationController.php`
Адаптация плана.

| Метод | Описание |
|-------|----------|
| `runWeeklyAdaptation()` | Запуск недельной адаптации |

---

## Сервисы

### `planrun-backend/services/WorkoutService.php`
Бизнес-логика тренировок: getDay, saveResult, uploadWorkout, deleteWorkout и др.

### `planrun-backend/services/TrainingPlanService.php`
Логика плана: load, save, regenerate, recalculate, generateNext.

### `planrun-backend/services/ChatService.php`
Чат: buildContextForUser, streamResponse, getChatTools, executeTool.

### `planrun-backend/services/ChatContextBuilder.php`
Построение контекста для AI: профиль, план, статистика, память.

### `planrun-backend/services/StatsService.php`
Статистика: расчёт метрик, сводок.

### `planrun-backend/services/AuthService.php`
Авторизация: проверка пароля, JWT.

### `planrun-backend/services/JwtService.php`
Работа с JWT: создание, валидация, refresh.

### `planrun-backend/services/EmailService.php`
Отправка email (сброс пароля, верификация).

### `planrun-backend/services/WeekService.php`
Логика недель и дней.

### `planrun-backend/services/ExerciseService.php`
Логика упражнений.

### `planrun-backend/services/AdaptationService.php`
Адаптация плана.

### `planrun-backend/services/DateResolver.php`
Разрешение дат (неделя, день).

---

## PlanRun AI

### `planrun-backend/planrun_ai/plan_generator.php`
Генерация плана через LLM.

| Функция | Описание |
|---------|----------|
| `isPlanRunAIConfigured()` | Проверка доступности PlanRun AI |
| `generatePlanViaPlanRunAI($userId)` | Генерация плана для пользователя |

### `planrun-backend/planrun_ai/plan_normalizer.php`
Нормализация JSON от LLM.

| Функция | Описание |
|---------|----------|
| `normalizeTrainingPlan($rawPlan, $startDate)` | Нормализация: типы, description, даты, упражнения |

### `planrun-backend/planrun_ai/plan_saver.php`
Сохранение плана в БД.

| Функция | Описание |
|---------|----------|
| `saveTrainingPlan($db, $userId, $rawPlan, $startDate)` | Сохранение в training_plan_weeks, training_plan_days, training_day_exercises |

### `planrun-backend/planrun_ai/prompt_builder.php`
Построение промптов для LLM.

| Функция | Описание |
|---------|----------|
| `buildTrainingPlanPrompt($user, $goalType)` | Промпт для генерации плана |

### `planrun-backend/planrun_ai/description_parser.php`
Парсинг description в структурированные упражнения.

### `planrun-backend/planrun_ai/planrun_ai_integration.php`
Интеграция с PlanRun AI (HTTP-запросы).

### `planrun-backend/planrun_ai/planrun_ai_config.php`
Конфигурация PlanRun AI.

### `planrun-backend/planrun_ai/generate_plan_async.php`
Асинхронная генерация плана.

### `planrun-backend/planrun_ai/plan_review_generator.php`
Генерация обзора плана.

### `planrun-backend/planrun_ai/text_generator.php`
Генерация текста (универсальный).

### `planrun-backend/planrun_ai/create_empty_plan.php`
Создание пустого плана.

---

## Провайдеры интеграций

### `planrun-backend/providers/StravaProvider.php`
OAuth и API Strava: получение токенов, импорт активностей.

### `planrun-backend/providers/HuaweiHealthProvider.php`
Huawei Health Kit: OAuth, получение тренировок.

### `planrun-backend/providers/PolarProvider.php`
Polar: интеграция (заглушка или частичная).

### `planrun-backend/providers/WorkoutImportProvider.php`
Импорт тренировок из GPX/TCX.

---

## Репозитории

### `planrun-backend/repositories/BaseRepository.php`
Базовый репозиторий.

### `planrun-backend/repositories/WorkoutRepository.php`
Доступ к workout_log, workouts.

### `planrun-backend/repositories/TrainingPlanRepository.php`
Доступ к training_plan_weeks, training_plan_days.

### `planrun-backend/repositories/WeekRepository.php`
Доступ к неделям и дням.

### `planrun-backend/repositories/ExerciseRepository.php`
Доступ к exercise_library, training_day_exercises.

### `planrun-backend/repositories/ChatRepository.php`
Доступ к chat_conversations, chat_messages.

### `planrun-backend/repositories/StatsRepository.php`
Запросы для статистики.

### `planrun-backend/repositories/NotificationRepository.php`
Уведомления.

---

## Утилиты и конфигурация

### `planrun-backend/auth.php`
Функции авторизации: `isAuthenticated()`, `getCurrentUserId()`, проверка JWT.

### `planrun-backend/db_config.php`
Подключение к БД: `getDBConnection()`.

### `planrun-backend/user_functions.php`
Вспомогательные функции пользователей.

### `planrun-backend/query_helpers.php`
Хелперы для SQL-запросов.

### `planrun-backend/training_utils.php`
Утилиты для тренировок.

### `planrun-backend/utils/GpxTcxParser.php`
Парсинг GPX/TCX файлов.

### `planrun-backend/config/Logger.php`
Логирование.

### `planrun-backend/config/error_handler.php`
Обработка ошибок: `ErrorHandler::returnJsonError()`, `ErrorHandler::register()`.

### `planrun-backend/config/RateLimiter.php`
Rate limiting для API.

### `planrun-backend/config/env_loader.php`
Загрузка .env.

### `planrun-backend/validators/WorkoutValidator.php`
Валидация данных тренировки.

### `planrun-backend/validators/WeekValidator.php`
Валидация недель.

### `planrun-backend/validators/ExerciseValidator.php`
Валидация упражнений.

### `planrun-backend/validators/TrainingPlanValidator.php`
Валидация плана.

---

## Скрипты миграций

Скрипты в `planrun-backend/scripts/`:
- `migrate_*.php` — миграции схемы БД
- `strava_register_webhook.php` — регистрация Strava webhook
- `strava_backfill_athlete_ids.php` — заполнение athlete_id
- `check_chat_debug.php`, `check_password_reset.php` — утилиты
