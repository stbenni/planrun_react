# PlanRun - ручной справочник по backend entrypoints, ops и служебным модулям

Этот документ собран вручную по root-файлам `planrun-backend/*.php`, `config/*`, `providers/*`, `scripts/*` и связанным служебным папкам.

Он дополняет:

- [02-BACKEND.md](02-BACKEND.md) - обзор слоёв контроллеров/сервисов/репозиториев;
- [09-AI-MODULE-REFERENCE.md](09-AI-MODULE-REFERENCE.md) - глубокий разбор AI generation pipeline.

Здесь описан backend glue-layer: bootstrap, кеш, root helper-файлы, провайдеры интеграций, cron-скрипты, миграции и эксплуатационные точки входа.

## 1. Root PHP-файлы, которые живут вне controller/service слоёв

| Файл | Ключевые функции | Реальная ответственность |
|------|------------------|--------------------------|
| `auth.php` | `isAuthenticated`, `login`, `logout`, `requireAuth` | Legacy session-auth helper; до сих пор используется как низкоуровневый слой поверх PHP session |
| `db_config.php` | `getDBConnection` | Единственная точка инициализации `mysqli`, читает DB config из `.env` |
| `cache_config.php` | `getCache`, классы `RedisCache`, `MemcachedCache`, `FileCache`, `NullCache`, фасад `Cache::*` | Абстракция кеша для user data, training plan, rate limiting и других доменов |
| `user_functions.php` | `getUserData`, `getCurrentUser`, `clearUserCache`, `getCurrentUserId`, `getUserByTelegramId`, `getUserActivePlan`, `getUserTimezone`, `generateUsernameSlug` | Централизованная работа с пользователем и session-cache |
| `workout_types.php` | `getActivityTypes`, `getActivityType` | Кешируемый доступ к справочнику `activity_types` |
| `load_training_plan.php` | `loadTrainingPlanForUser` | Загружает план пользователя в frontend-compatible `weeks_data`, пересчитывает объём из `training_day_exercises`, кеширует результат |
| `training_utils.php` | `findTrainingDay`, `linkWorkoutToCalendar`, `formatDuration` | Сопоставляет реальные тренировки датам плана и поддерживает простой training-plan lookup |
| `prepare_weekly_analysis.php` | `prepareWeeklyAnalysis`, `getCurrentWeekNumber`, `prepareFullPlanAnalysis` | Готовит структурированный JSON с планом, фактом и weekly metrics для AI review/adaptation |
| `calendar_access.php` | `getCalendarAccess`, `getCalendarUser`, `getUserCalendarUrl`, `getWorkoutDetailsUrl` | Правила доступа к публичному/coach view календаря и построение красивых URL |
| `query_helpers.php` | `getTotalTrainingDays`, `getCompletedDaysKeys`, `getUserCoachAccess`, `isUserCoach`, `parsePreferredDays`, `formatPreferredDays`, `getWeekDates` | Небольшой централизованный SQL/helper слой вокруг coach access и training-plan metadata |
| `register_api.php` | набор локальных helper-функций + POST flow | Полный legacy endpoint регистрации, field validation, email verification и авто-логин через сессию |
| `complete_specialization_api.php` | POST flow второго этапа | Завершает специализацию после минимальной регистрации, обновляет профиль и ставит plan generation в очередь |
| `api_v2.php` | `planrunRouteControllerAction` + inline public routes | Центральный action dispatcher, а также дом для `get_avatar`, `get_site_settings`, `assess_goal`, `get_user_by_slug` |

### Что важно про `load_training_plan.php`

Этот файл по-прежнему очень важен, потому что:

- фронтенд и часть root helper'ов ожидают плоский `weeks_data`;
- объём недели может пересчитываться не из `training_plan_weeks.total_volume`, а из суммарной дистанции упражнений в `training_day_exercises`;
- кеш `training_plan_{userId}` очищается уже при сохранении плана в AI pipeline.

### Что важно про `prepare_weekly_analysis.php`

Это не просто "статистика недели". Файл:

- объединяет ручные записи `workout_log` и автоматические `workouts`;
- строит day-by-day структуру `planned + actual + compliance`;
- считает completion rate, фактический объём и пульс;
- является входом и для старого weekly review, и для нового adaptation engine.

## 2. Конфиг, bootstrap и служебная инфраструктура

| Файл | Ключевые функции / классы | Роль |
|------|---------------------------|------|
| `config/env_loader.php` | `loadEnv`, `env` | Загружает `.env` без внешних зависимостей |
| `config/init.php` | bootstrap include | Подключает logger/error handler/rate limiter/cache и включает глобальные обработчики |
| `config/Logger.php` | `Logger::init/log/debug/info/warning/error/critical/exception/rotate` | Структурированное JSON-логирование с ротацией файлов |
| `config/error_handler.php` | `ErrorHandler::register`, `handleException`, `returnJsonError`, `returnJsonSuccess`, `validateEnum` | Единый перехват ошибок, перевод исключений в JSON-ответы и логирование |
| `config/RateLimiter.php` | `resolveApiActionBucket`, `check`, `getInfo`, `reset`, `checkApiLimit` | Rate limit для API, особенно для `plan_generation`, `adaptation`, `chat`, `login` |
| `config/constants.php` | набор enum-like классов | Справочники goal types, roles, genders, workout types, exercise categories и т.д. |

### Практический смысл этих файлов

- `env_loader.php` и `db_config.php` - самый нижний bootstrap-уровень.
- `Logger.php` и `error_handler.php` - инфраструктурный слой, который видит практически каждый request.
- `RateLimiter.php` не только считает запросы, но и умеет различать обычные plan-related read-actions и тяжёлые generation actions.

## 3. OAuth и импорт тренировок

### Общий интерфейс

| Файл | Роль |
|------|------|
| `providers/WorkoutImportProvider.php` | Контракт для интеграций: `getOAuthUrl`, `exchangeCodeForTokens`, `refreshToken`, `fetchWorkouts`, `isConnected`, `disconnect` |

### Конкретные провайдеры

| Провайдер | Ключевые методы | Реальная ответственность |
|-----------|-----------------|--------------------------|
| `StravaProvider.php` | `getOAuthUrl`, `exchangeCodeForTokens`, `refreshToken`, `fetchWorkouts`, `fetchSingleActivity`, `ensureIntegrationHealthy`, `ensureWebhookSubscription`, `disconnect` | Самый развитый провайдер: OAuth, token refresh, pagination, webhook health, activity streams, laps и восстановление `external_athlete_id` |
| `HuaweiHealthProvider.php` | `getOAuthUrl`, `exchangeCodeForTokens`, `refreshToken`, `fetchWorkouts`, `mapHuaweiResponseToWorkouts`, `disconnect` | OAuth + маппинг Huawei activity payload в внутренний workout format |
| `PolarProvider.php` | `getOAuthUrl`, `exchangeCodeForTokens`, `registerUser`, `fetchWorkouts`, `mapExerciseToWorkout`, `buildTimeline`, `disconnect` | OAuth для Polar AccessLink, импорт exercises и построение timeline |

### Что важно про `StravaProvider.php`

Этот файл закрывает сразу несколько проблемных зон:

- ранний refresh токена, если до истечения осталось меньше 5 минут;
- батчевый импорт `/athlete/activities`;
- enrichment через отдельные запросы за details/streams/laps;
- специальный single-activity path для webhook-событий;
- контроль существования webhook subscription и исправление `external_athlete_id`.

Именно поэтому эксплуатационные Strava-скрипты не пишут HTTP-код сами, а опираются на методы этого провайдера.

## 4. Ops-скрипты: workers, cron и техобслуживание

### 4.1 Queue, AI и адаптация

| Скрипт | Что делает |
|--------|------------|
| `plan_generation_worker.php` | Worker очереди генерации: `reserveNextJob()` -> `PlanGenerationProcessorService->process()` -> `markCompleted/markFailed` |
| `weekly_ai_review.php` | Еженедельный cron: либо старое LLM review в чат, либо новый `AdaptationService->runWeeklyAdaptation()` при skeleton path |
| `daily_briefing.php` | Ежедневный утренний брифинг AI-тренера по сегодняшней тренировке |
| `weekly_digest.php` | Еженедельный дайджест AI-тренера по фактической неделе |
| `eval_plan_generation.php` | Batch-eval AI генерации на реальных пользователях или synthetic fixtures; собирает артефакты `first-pass` и `full` режимов |
| `post_workout_followups.php` | Cron после тренировок: отправляет due check-in от AI-тренера по записям `post_workout_followups` |

### Что важно про `weekly_ai_review.php`

В нём реально существуют два режима:

1. `USE_SKELETON_GENERATOR=0`
   `prepareWeeklyAnalysis()` -> `buildWeeklyReviewPromptData()` -> `generateWeeklyReview()` -> `ChatService->addAIMessageToUser()`
2. `USE_SKELETON_GENERATOR=1`
   `AdaptationService->runWeeklyAdaptation()` - новый путь, который и анализирует неделю, и при необходимости адаптирует план.

### 4.2 Уведомления

| Скрипт | Что делает |
|--------|------------|
| `push_workout_reminders.php` | Cron напоминаний о сегодняшней/завтрашней тренировке; строит summary из `weeks_data` и отправляет через `NotificationDispatcher` |
| `process_notification_delivery_queue.php` | Доставляет уведомления, отложенные из-за quiet hours |
| `process_notification_email_digest.php` | Собирает и отправляет ежедневные email-дайджесты |
| `send_test_push.php` | Локальная проверка push-канала |
| `get_jwt_for_push_test.php` | Получение JWT для ручного тестирования push-эндпоинтов |
| `generate_web_push_vapid_keys.php` | Генерирует VAPID ключи для browser web push |

### Что важно про notification scripts

- `push_workout_reminders.php` использует `NotificationSettingsService::acquireDispatchGuard()`, чтобы не слать дубль одного и того же напоминания.
- `process_notification_delivery_queue.php` и `process_notification_email_digest.php` работают с очередями/резервацией, а не просто с "сырыми" записями, поэтому они безопаснее к повторным запускам cron.

### 4.3 Strava и интеграции

| Скрипт | Что делает |
|--------|------------|
| `strava_register_webhook.php` | Ручная регистрация или перепривязка webhook subscription |
| `strava_daily_health_check.php` | Периодическая проверка токенов, `external_athlete_id` и webhook subscription |
| `strava_backfill_athlete_ids.php` | Backfill `external_athlete_id` для уже существующих Strava интеграций |
| `check_strava.php` | Точечная диагностика Strava-интеграции |

### 4.4 Статистика и техобслуживание

| Скрипт | Что делает |
|--------|------------|
| `update_vdot_from_training.php` | Вычисляет лучшую свежую тренировку и обновляет `last_race_*` у пользователя |
| `cleanup_expired_refresh_tokens.php` | Удаляет просроченные refresh tokens |
| `check_login.php` | Ручная проверка login flow |
| `check_password_reset.php` | Диагностика сценария password reset |
| `check_push.php` | Диагностика push-конфигурации |
| `check_chat_debug.php` | Диагностика чата и отладочных сценариев |

## 5. Seed, backfill и миграции

### Seed / backfill

| Скрипт | Что делает |
|--------|------------|
| `seed_coaches.php` | Начальное заполнение coach-related данных |
| `seed_coaches_avatars.php` | Наполнение аватаров тренеров |
| `backfill_avatar_variants.php` | Генерация недостающих avatar variants |

### Миграции схемы и данных

Ниже перечислены служебные скрипты миграций; по именам и месту в структуре видно, какую эволюцию схемы они обслуживают.

| Скрипт | Зона изменения |
|--------|----------------|
| `migrate_all.php` | Общий запускатель набора миграций |
| `migrate_role_enum.php` | Расширение/исправление ролей пользователей |
| `migrate_profile_privacy.php` | Добавление privacy-полей профиля |
| `migrate_onboarding_completed.php` | Поддержка onboarding completion flag |
| `migrate_experience_level_nullable.php` | Ослабление ограничения `experience_level` |
| `migrate_push_workout_minute.php` | Детализация времени напоминания до минут |
| `migrate_site_settings.php` | Таблица/данные site settings |
| `migrate_email_verification_codes.php` | Таблица кодов верификации email |
| `migrate_password_reset_table.php` | Таблица password reset tokens |
| `migrate_refresh_tokens_table.php` | Таблица refresh tokens |
| `migrate_refresh_tokens_device_id.php` | Связка refresh token с device id |
| `migrate_integration_tokens.php` | Таблица integration tokens |
| `migrate_integration_tokens_athlete_id.php` | Добавление external athlete id для Strava webhook mapping |
| `migrate_chat_tables.php` | Первичная схема chat/conversation/messages |
| `migrate_chat_history_summary.php` | История/summary чата |
| `migrate_chat_user_memory.php` | User memory для chat/AI-контекста |
| `migrate_coach_tables.php` | Таблицы coach-athlete/groups/pricing |
| `migrate_exercise_library.php` | Библиотека упражнений |
| `migrate_training_plan_days_multiple_per_date.php` | Поддержка нескольких тренировок на дату/день |
| `migrate_workout_laps.php` | Таблицы/поля laps |
| `migrate_workout_timeline.php` | Timeline/stream-поля тренировок |
| `migrate_workouts_duration_seconds.php` | Явное хранение `duration_seconds` |
| `migrate_workouts_session_id_nullable.php` | Ослабление ограничения `session_id` |
| `migrate_workouts_source.php` | Источник импортированной тренировки |
| `migrate_activity_types_walking.php` | Добавление walking/hiking activity types |

## 6. Telegram proxy и внешняя публикация webhook'ов

Папка `planrun-backend/telegram/` - это не runtime API для фронтенда, а deployment toolkit для Telegram webhook proxy.

| Файл | Роль |
|------|------|
| `telegram/README.md` | Инструкции по настройке proxy/webhook |
| `telegram/webhook-proxy.php` | Точка приёма webhook'ов |
| `telegram/set-all-webhooks.php` | Массовая регистрация webhook'ов |
| `telegram/deploy-to-vps.sh` | Deployment helper для VPS |
| `telegram/nginx-vps.conf`, `telegram/nginx-location.conf` | Примеры nginx-конфига |
| `telegram/env-vps.example` | Пример env для VPS-окружения |

## 7. Инварианты ops-слоя

### 1. Root helper-файлы всё ещё критичны

Несмотря на controller/service архитектуру, файлы вроде `load_training_plan.php`, `prepare_weekly_analysis.php`, `calendar_access.php`, `user_functions.php` и `query_helpers.php` остаются источниками истины для legacy-сценариев, cron-скриптов и публичных route-ов.

### 2. Скрипты редко работают "в вакууме"

Почти каждый ops-скрипт:

- поднимает `.env`;
- берёт `mysqli` через `getDBConnection()`;
- использует существующие сервисы/провайдеры вместо прямой бизнес-логики внутри скрипта.

Это означает, что правки в сервисах и провайдерах автоматически меняют поведение cron/worker-пути.

### 3. Strava - отдельная эксплуатационная подсистема

У Strava здесь самый тяжёлый lifecycle:

- OAuth привязка;
- refresh токенов;
- webhook subscription;
- backfill `external_athlete_id`;
- import activities со streams и laps.

Любое изменение Strava flow нужно отражать не только в API, но и в `StravaProvider.php`, health-check скриптах и интеграционной документации.

## 8. Что читать вместе с этим документом

- общая архитектура backend: [02-BACKEND.md](02-BACKEND.md)
- action-routing и public endpoints: [03-API.md](03-API.md)
- AI generation и weekly adaptation: [09-AI-MODULE-REFERENCE.md](09-AI-MODULE-REFERENCE.md)
- полный список файлов: [04-FILES-REFERENCE.md](04-FILES-REFERENCE.md)
