# Backend scripts 2/2 (live_recalculate_batch…workout_share_worker) — справочник

## `planrun-backend/scripts/live_recalculate_batch.php` (1291 строка)

Назначение: диагностический батч-прогон live-пересчёта плана на синтетических пользователях `live50_*`. Сеет «выполненные» тренировки в `workout_log` по сохранённому плану (с детерминированными или mixed-сценариями: on_track / volume_down / missed_key / overload_ok / pain_recovery), затем гонит `PlanGenerationProcessorService->process($userId, 'recalculate', $payload)` и оценивает результат «как тренер»: якорь будущих недель, рост объёма, доля длительной, race-день на целевую дату. Запускается вручную: `php scripts/live_recalculate_batch.php --prefix=… --limit=… --scenario-mode=mixed --dry-run=1 --skip-seed=1 --cutoff-date=…`. Форсирует `PLAN_GENERATION_MODE=llm_planner`; при `--fast-llm-fallback=1` (по умолчанию) подменяет `LLM_CHAT_BASE_URL` на недоступный адрес, чтобы пересчёт шёл алгоритмическим фолбэком без реального LLM. Таблицы: читает `users`, `training_plan_weeks`, `training_plan_days`, `training_day_exercises`; пишет/удаляет seed-строки в `workout_log` (помечены `notes LIKE 'live_recalc_seed:%'`); косвенно через процессор перезаписывает план. Артефакты (JSON + Markdown отчёт) сохраняет в `tmp/live_plan_generation/`. Exit-код 1, если были падения пересчёта.

### `liveRecalcParseArgs(array $argv)` — L24
Парсит CLI-аргументы вида `--key=value` поверх дефолтов (prefix, limit, completed-weeks, cutoff-date, save-dir, fast-llm-fallback, scenario-mode, skip-seed, skip-recalculate, dry-run).

### `liveRecalcBool(mixed $value)` — L54
Приводит строковое значение флага к bool ('1'/'true'/'yes'/'on').

### `liveRecalcSetEnv(string $key, string $value)` — L59
Устанавливает переменную окружения сразу в `$_ENV`, `$_SERVER` и через `putenv()`.

### `liveRecalcDateAddWeeks(string $date, int $weeks)` — L66
Возвращает дату `Y-m-d` со сдвигом на N недель вперёд.

### `liveRecalcFetchUsers(mysqli $db, string $prefix, int $limit)` — L71
Выбирает из `users` синтетических пользователей по `username LIKE prefix%` с лимитом.

### `liveRecalcCaseCode(array $user)` — L93
Извлекает код кейса из username вида `live50_YYYYMMDD_NN_<case>`; иначе возвращает сам username.

### `liveRecalcFetchPlanDays(mysqli $db, int $userId, string $fromDate, string $cutoffDate)` — L103
Читает не-rest дни плана в окне [fromDate, cutoffDate) с агрегацией run-дистанции/времени/темпа и количества ОФП/СБУ-позиций из `training_plan_days` + `training_day_exercises`.

### `liveRecalcFetchSavedPlan(mysqli $db, int $userId)` — L138
Загружает весь сохранённый план пользователя: недели (`training_plan_weeks`) с вложенными днями и run-агрегатами; возвращает массив недель с `days`.

### `liveRecalcSummarizePlan(array $weeks, string $cutoffDate)` — L217
Сводит план в метрики: количество kept/future недель, объёмы будущих недель, пиковая длительная и её доля от недели, race-дни, контексты будущих недель.

### `liveRecalcDayName(int $dayOfWeek)` — L293
ISO-номер дня недели → короткое имя ('mon'…'sun').

### `liveRecalcRunTypes()` — L298
Список беговых типов дней: easy, long, tempo, interval, fartlek, control, race, walking.

### `liveRecalcActivityTypeId(string $type, float $distanceKm)` — L303
Маппинг типа дня в `activity_type_id` для workout_log: walking=10, sbu=9, бег=1, иначе=2.

### `liveRecalcParsePaceSec(?string $pace)` — L318
Парсит темп «M:SS» в секунды на км; null при невалидном формате.

### `liveRecalcFormatPace(int $seconds)` — L328
Секунды → строка темпа «M:SS» (минимум 1 сек).

### `liveRecalcFormatDuration(int $seconds)` — L334
Секунды → «HH:MM:SS» либо «MM:SS» если меньше часа.

### `liveRecalcFallbackDistanceKm(string $type, int $weekNumber)` — L346
Эвристическая дистанция по типу дня и номеру недели, когда в плане не задана (easy 3+, long 5+, race 5 и т.д.).

### `liveRecalcSeedProfile(array $user, string $scenarioMode = 'deterministic', int $index = 0)` — L359
Строит профиль сеялки: в mixed-режиме круговой выбор из 5 сценариев (factor, skip_types, adaptation_type); в детерминированном — factor по `user_id % 10`; «консервативность» по травмам/возрасту из `health_notes`/`birth_year` ограничивает factor ≤0.92.

### `liveRecalcReasonForSeedProfile(array $seedProfile)` — L460
Возвращает русскоязычную «reason»-строку для payload пересчёта по сценарию профиля.

### `liveRecalcShouldSkipDayForSeed(array $day, array $seedProfile)` — L472
True, если тип дня входит в `skip_types` профиля и номер недели ≥ `skip_after_week` (имитация пропусков).

### `liveRecalcBuildWorkoutRow(array $user, array $day, array $seedProfile)` — L486
Собирает синтетическую запись workout_log из планового дня: фактическая дистанция = plan×factor, темп со смещением по сценарию, синтетические пульс/каденс/набор/калории, notes с маркером `live_recalc_seed:`.

### `liveRecalcDeleteSeededLogs(mysqli $db, int $userId, string $fromDate, string $cutoffDate)` — L594
Удаляет ранее насеянные строки `workout_log` (по `notes LIKE 'live_recalc_seed:%'`) в окне дат; возвращает число удалённых.

### `liveRecalcInsertWorkoutLog(mysqli $db, int $userId, array $row)` — L615
INSERT одной строки в `workout_log` (is_completed=1, is_successful=1) со всеми синтетическими метриками; возвращает insert_id.

### `liveRecalcSeedCompletedWorkouts(mysqli $db, array $user, string $fromDate, string $cutoffDate, bool $dryRun, string $scenarioMode, int $index)` — L670
Оркестратор сеялки для одного пользователя: чистит старые seed-строки, проходит по дням плана, пропускает дни по сценарию, вставляет остальные; возвращает summary (planned/actual km, skipped days, weekly avg за 4 нед).

### `liveRecalcFetchKeptWeeks(mysqli $db, int $userId, string $cutoffDate)` — L740
MAX(week_number) недель плана с `start_date < cutoff` — сколько прошлых недель должно сохраниться при пересчёте.

### `liveRecalcBuildPayload(mysqli $db, array $user, array $seed, string $cutoffDate)` — L760
Формирует payload для job 'recalculate': cutoff_date, mutable_from_date, kept_weeks, actual_weekly_km_4w, reason; при adaptation_type добавляет adaptation_metrics.

### `liveRecalcIssue(string $severity, string $code, string $message, array $context = [])` — L786
Конструктор записи issue {severity, code, message, context} для отчёта.

### `liveRecalcLongShareLimits(int $sessions, bool $isNovice, bool $isConservative)` — L796
Возвращает [мягкий, жёсткий] лимит доли длительной от недельного объёма в зависимости от числа сессий и консервативности (0.40–0.72).

### `liveRecalcIsLongShareMaterial(float $peakLongKm, int $sessions)` — L809
True, если пиковая длительная достаточно велика (≥5 км; для ≤2 сессий ≥8 км), чтобы доля имела значение.

### `liveRecalcAllowsShortRaceLongShare(array $user, array $after, float $peakLongKm)` — L822
Разрешает высокую долю длительной для коротких целей (5 км): race ≤5.1 км, long ≤5.5 км, объём ≤12 км/нед.

### `liveRecalcUserRaceDistanceKm(array $user)` — L837
Парсит `users.race_distance` ('5k'/'10k'/'half'/'marathon'/число) в километры.

### `liveRecalcRaceDate(array $user)` — L849
Возвращает `users.race_date` строкой.

### `liveRecalcEvaluate(array $user, array $seed, array $payload, array $before, array $after, ?array $recalcResult, ?string $recalcError, string $cutoffDate)` — L854
«Тренерская» оценка пересчитанного плана: ошибки (потеря kept-недель, нет будущих недель, неверный якорь, отсутствие race-дня), варнинги (старт выше/ниже фактической нагрузки, резкий рост объёма с учётом cutback-недель, высокая доля длительной), info про progression counters; возвращает issues + summary.

### `liveRecalcBuildMarkdown(array $report)` — L1025
Рендерит Markdown-отчёт: контекст, summary, топ кодов issue, таблица по пользователям, секция «Coach Review: Problems To Fix».

## `planrun-backend/scripts/migrate_all.php` (281 строка)

Назначение: разовая мастер-миграция auth/notification-инфраструктуры — запускается на сервере вручную (`php scripts/migrate_all.php`). Создаёт (IF NOT EXISTS) таблицы: `email_verification_codes`, `plan_generation_jobs`, `llm_gateway_locks` (SQL из `migrations/*.sql`), `workout_share_jobs`, `workout_share_cards`, `password_reset_tokens`, `refresh_tokens`, `notification_dismissals`, `push_tokens`, `notification_channel_settings`, `notification_preferences`, `web_push_subscriptions`, `notification_deliveries`, `notification_dispatch_guards`, `notification_delivery_queue`, `notification_email_digest_items`, `notification_template_overrides`. Затем идемпотентно добавляет колонки: `refresh_tokens.device_id` + индекс `idx_user_device`; `users.push_workout_minute/push_workouts_enabled/push_chat_enabled/push_workout_hour/first_name/last_name`; `training_plan_weeks.phase`. Останавливается с exit 1 на первой ошибке SQL. Функций нет — линейный поток.

## `planrun-backend/scripts/migrate_executed_exercises.php` (55 строк)

Назначение: разовая миграция — создаёт таблицу `executed_exercises` (фактическое выполнение упражнений: плановые vs выполненные sets/reps/вес/время/дистанция, RPE, notes) для progressive overload, знания AI о рабочих весах и UI отметки выполнения ОФП/СБУ. Запуск вручную `php scripts/migrate_executed_exercises.php`. Только `CREATE TABLE IF NOT EXISTS`, без функций.

## `planrun-backend/scripts/migrate_notifications_refkey.php` (36 строк)

Назначение: разовая миграция для свёртки уведомлений по сущности — добавляет колонку `plan_notifications.ref_key` (ключ вида "chat:123") и уникальный индекс `uniq_user_ref (user_id, ref_key)` для UPSERT. Обе операции идемпотентны (SHOW COLUMNS / SHOW INDEX перед ALTER). Запуск вручную. Без функций.

## `planrun-backend/scripts/migrate_suunto_upload.php` (44 строки)

Назначение: разовая миграция для зеркалирования PlanRun → Suunto — добавляет колонку `users.suunto_mirror_enabled` (per-user тумблер) и создаёт таблицу `suunto_upload_queue` (очередь заливок со статусами pending/processing/done/skipped/error, дедуп по UNIQUE(user_id, workout_id)). Запуск вручную. Без функций.

## `planrun-backend/scripts/plan_generation_worker.php` (76 строк)

Назначение: worker очереди генерации плана (`plan_generation_jobs`). Режимы: `--once` (одна job и выход — для cron) или `--daemon --sleep=N` (вечный цикл). Резервирует job через `PlanGenerationQueueService->reserveNextJob()`, выполняет `PlanGenerationProcessorService->process(userId, jobType, payload, jobId)`, при успехе `markCompleted`. При ошибке: `persistFailure` пользователю, расчёт retry-задержки через `LlmGateway::isRetryableThrowable/queueRetryDelaySeconds` (нерetryable `LlmGatewayRequestException` сразу исчерпывает attempts), `markFailed`. Без функций.

## `planrun-backend/scripts/polar_register_webhook.php` (58 строк)

Назначение: разовая ручная регистрация Polar AccessLink webhook (один на приложение). Требует env: `POLAR_CLIENT_ID`, `POLAR_CLIENT_SECRET`, `POLAR_WEBHOOK_CALLBACK_URL`; валидирует их наличие с понятными подсказками. Делегирует в `PolarProvider->ensureWebhookSubscription()`; при успехе секрет подписи сохраняется провайдером в `storage/polar_webhook_secret.txt`. Печатает результат (created/updated/already correct) и предупреждение, если секрет не записался. Без функций.

## `planrun-backend/scripts/polar_webhook_health.php` (35 строк)

Назначение: cron-проверка/восстановление Polar webhook (раз в сутки). Тихо выходит (exit 0), если `POLAR_WEBHOOK_CALLBACK_URL` не задан. Вызывает `PolarProvider->ensureWebhookSubscription()` и логирует результат через `Logger` (info/warning). Без функций.

## `planrun-backend/scripts/post_workout_followups.php` (59 строк)

Назначение: cron каждые 5 минут — отправка назревших post-workout follow-up сообщений AI-тренера. Гейт `POST_WORKOUT_FOLLOWUPS_ENABLED` (по умолчанию включён), flock-лок в tmp от параллельных запусков. Лимит batch — argv[1] или `POST_WORKOUT_FOLLOWUPS_LIMIT` (дефолт 50, клип 1–500). Вся логика в `PostWorkoutFollowupService->processDueFollowups($limit)`; печатает stats (sent/skipped/expired/errors) при ненулевой активности или `POST_WORKOUT_FOLLOWUPS_VERBOSE=1`. Без функций.

## `planrun-backend/scripts/proactive_coach.php` (49 строк)

Назначение: cron-обёртка проактивного AI-тренера (рекомендация: 2 раза в день, `0 8,20 * * *`). Гейт `PROACTIVE_COACH_ENABLED` (по умолчанию выключен!). Вызывает `ProactiveCoachService->processAllUsers()` — детект событий (пауза, перегрузка, забег, рекорд, низкое выполнение) и отправка персональных сообщений в чат; печатает stats и детали по пользователям. Без функций.

## `planrun-backend/scripts/process_notification_delivery_queue.php` (83 строки)

Назначение: cron каждую минуту — доставка уведомлений, отложенных quiet hours. Через `NotificationSettingsService->reserveDueQueuedDeliveries(50)` берёт назревшие элементы `notification_delivery_queue`, прогоняет каждый через `NotificationDispatcher->processQueuedDelivery()` и по статусу: sent → `markQueuedDeliveryCompleted('sent')`; deferred → `rescheduleQueuedDelivery` (на конец quiet hours); skipped/failed → соответствующее завершение. Исключения помечают элемент failed. Печатает сводку при наличии обработанных. Без функций.

## `planrun-backend/scripts/process_notification_email_digest.php` (100 строк)

Назначение: cron каждую минуту — ежедневный email-дайджест. `NotificationSettingsService->reserveDueEmailDigestUsers(25)` → для каждого пользователя `reserveDueEmailDigestItemsForUser()` (таблица `notification_email_digest_items`). Если email-канал выключен/недоступен — items skipped; в quiet hours — `rescheduleEmailDigestItems` на resume-время; иначе `EmailNotificationService->sendDailyDigestToUser()` и пометка sent/failed. Каждый исход логируется в `notification_deliveries` через `logDelivery` (event_key `system.email_digest`). Без функций.

## `planrun-backend/scripts/process_strava_webhook_retries.php` (175 строк)

Назначение: cron-обработчик отложенных ретраев импорта Strava-активностей (`strava_webhook_retry_queue`). Тихо выходит, если таблица не установлена (легаси-кронтабы). Поток: сброс зависших processing (>30 мин) → выборка до 10 pending due jobs → пометка processing батчем → для каждого: дедуп-проверка по `workouts.external_id` ('strava_<id>'), `StravaProvider->ensureIntegrationHealthy`, `fetchSingleActivity`, при успехе `WorkoutService->importWorkouts` + best-effort data-push `workout_sync`; иначе reschedule с backoff или fail после max_attempts. В конце чистка completed/failed старше 7 дней. Логирует в `logs/strava_retry.log`.

### `markRetryCompleted(mysqli $db, int $jobId, int $attempts)` — L131
Помечает job в retry-очереди как completed с фиксацией attempts и finished_at.

### `rescheduleOrFailRetry(mysqli $db, int $jobId, int $activityId, int $userId, int $attempts, int $maxAttempts, string $lastError, int $lastHttpCode, callable $log)` — L141
Если попытки исчерпаны — status='failed'; иначе status='pending' с next_retry_at = NOW() + backoff; пишет в лог.

### `cleanupOldRetries(mysqli $db)` — L163
Удаляет completed/failed записи очереди старше 7 дней.

### `getBackoffSeconds(int $attempt)` — L171
Экспоненциальная шкала задержек: 60с, 5м, 15м, 1ч, 4ч, 12ч по номеру попытки.

## `planrun-backend/scripts/push_race_countdown.php` (103 строки)

Назначение: cron раз в день — in-app уведомления обратного отсчёта до забега на отметках 42/28/21/14/7/3/1 дней (константа MILESTONES). Тест: `--user=<id> --force`. Выбирает пользователей с будущей `race_date`, дедуп той же отметки за последние 3 дня по `JSON_EXTRACT(metadata,'$.milestone')` в `plan_notifications`. Заголовок склоняется («Завтра старт!», «N дней до старта», «N недель до марафона»). Пишет через `PlanNotificationService->notify(type='race_countdown')` — только колокол, без push.

### `pluralWeeks(int $n)` — L32
Русская плюрализация слова «неделя» (неделя/недели/недель).

### `pluralDays(int $n)` — L38
Русская плюрализация слова «день» (день/дня/дней).

## `planrun-backend/scripts/push_workout_reminders.php` (170 строк)

Назначение: cron каждую минуту — напоминания о сегодняшней/завтрашней тренировке. Выбирает пользователей с хоть одним каналом доставки (push_tokens / telegram_id / email), для каждого сравнивает локальное время (`users.timezone`) с расписанием из `NotificationSettingsService->getWorkoutReminderSchedule(today|tomorrow)` и проверяет `hasAnyDeliverableChannel`. Грузит план через `loadTrainingPlanForUser`, извлекает описание тренировки на целевую дату; защита от дублей через dispatch guard (`acquireDispatchGuard`/`markDispatchGuardSent`/`releaseDispatchGuard`). Отправляет `NotificationDispatcher->dispatchToUser` с event_key `workout.reminder.today|tomorrow`, ссылкой на календарь и push_data.

### `planrunExtractWorkoutSummary(array $weeksData, string $targetDate, string $timezone, array $restTypes)` — L38
Находит в weeks_data плана неделю и день, соответствующие целевой дате; склеивает до 2 описаний не-rest позиций дня в строку ≤80 символов; null если день отдыха/нет данных.

## `planrun-backend/scripts/regenerate_ai_messages.php` (242 строки)

Назначение: ручной отладочный скрипт принудительной регенерации всех AI-сообщений для пользователя: `php scripts/regenerate_ai_messages.php <user_id> [N_workouts]` (дефолт 10). Поток: (1) сброс cooldown — DELETE из `proactive_coach_log` по списку event_type; (2) `ProactiveCoachService->processDailyBriefings()` (для всех пользователей — точечного метода нет); (3) `processWeeklyDigests()`; (4) сбор последних тренировок из `workout_log` + `workouts` + уже существующих `workout_analyses`, подгрузка планового дня из `training_plan_days`, затем через Reflection вызывает private-методы `WorkoutService::generatePostWorkoutAnalysisText` и `persistWorkoutAnalysis` для каждой беговой тренировки (ОФП/СБУ без дистанции пропускаются). Без объявленных функций.

## `planrun-backend/scripts/run_recalculate_for_user.php` (30 строк)

Назначение: ручной запуск реального пересчёта плана для одного пользователя — кладёт job 'recalculate' в очередь через `PlanGenerationQueueService->enqueue(userId, 'recalculate', {reason, source:'manual_test'})`; выполнение делает `plan_generation_worker.php`. Usage: `php scripts/run_recalculate_for_user.php <user_id> "[reason]"`. Печатает JSON-результат enqueue. Без функций.

## `planrun-backend/scripts/run_weekly_adaptation_for_user.php` (31 строка)

Назначение: ручной полный запуск `WeeklyPlanAdaptationService->processUser($userId, null, true)` для одного пользователя — применяет изменения, шлёт уведомление, игнорирует cooldown (третий аргумент force). Usage: `php scripts/run_weekly_adaptation_for_user.php <user_id>`. Печатает JSON-результат. Без функций.

## `planrun-backend/scripts/seed_coaches_avatars.php` (154 строки)

Назначение: разовый ручной сидер — обновляет 10 тестовых тренеров (по `username_slug`, role='coach'): скачивает аватары с randomuser.me (по hardcoded gender/номеру портрета), сохраняет в `uploads/avatars/` (фолбэк: tmp-папка или внешний URL), удаляет старый локальный аватар, и записывает расширенные `coach_bio` + `avatar_path` в `users`. В конце подсказывает скопировать файлы из tmp при проблемах с правами. Без функций.

## `planrun-backend/scripts/seed_coaches.php` (222 строки)

Назначение: разовый ручной сидер — создаёт 10 тестовых тренеров (hardcoded массив: имя, slug, email coach1-10@planrun.local, bio, специализация, философия, опыт, прайсинг). Пароль у всех `coach123`. Пропускает существующих (по slug/email), вставляет в `users` (role='coach', training_mode='coach', onboarding_completed=1) и тарифы в `coach_pricing`. Без функций.

## `planrun-backend/scripts/send_real_test_notifications.php` (38 строк)

Назначение: ручной e2e-тест единой системы уведомлений «как в проде»: для userId (argv[1], дефолт 1) реальными продюсерами шлёт (1) AI-сообщение `ChatService->addAIMessageToUser` (chat.ai_message), (2) `sendAdminMessage` (chat.admin_message), (3) `PlanNotificationService->notifyCoachPlanUpdated` (plan.coach_updated). В конце печатает счётчик непрочитанных в `plan_notifications`. Без функций.

## `planrun-backend/scripts/send_test_push.php` (67 строк)

Назначение: ручная отправка тестового FCM-push: `php scripts/send_test_push.php <username|user_id>`. Резолвит пользователя (по id / username / username_slug), проверяет наличие токенов (`PushNotificationService->getUserTokens`) и разрешение `isPushAllowed(userId,'chat')`, шлёт `sendToUser` с типом chat и ссылкой /chat. Диагностические сообщения при отсутствии токенов / выключенном push / неинициализированном Firebase. Без функций.

## `planrun-backend/scripts/strava_backfill_athlete_ids.php` (59 строк)

Назначение: разовый бэкфилл `integration_tokens.external_athlete_id` для Strava-пользователей, у которых он пуст: для каждого берёт access_token, дёргает Strava `/athlete` напрямую через curl (с опц. `STRAVA_PROXY`), сохраняет `athlete.id`. Пауза 300мс между запросами. Запуск вручную; сейчас то же самое делает `strava_daily_health_check.php` через `ensureIntegrationHealthy`. Без функций.

## `planrun-backend/scripts/strava_daily_health_check.php` (99 строк)

Назначение: cron каждые 4 часа (access token Strava живёт ~6ч) — поддержание здоровья интеграции. Требует `STRAVA_CLIENT_ID/SECRET`. Сначала `StravaProvider->ensureWebhookSubscription()`, затем для каждого пользователя из `integration_tokens (provider='strava')` — `ensureIntegrationHealthy(userId)`: бэкфилл external_athlete_id и refresh токена, если истекает в ближайшие 4 часа; пауза 300мс. При любых изменениях/ошибках печатает сводку и пишет `Logger::info`. Без функций.

## `planrun-backend/scripts/strava_register_webhook.php` (45 строк)

Назначение: ручная регистрация Strava webhook-подписки (`php scripts/strava_register_webhook.php`). Требует env `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`, `STRAVA_WEBHOOK_CALLBACK_URL` (+ verify token). Делегирует в `StravaProvider->ensureWebhookSubscription()`; печатает удалённые старые подписки, created/already active и callback URL. Дублируется ежедневным health check'ом. Без функций.

## `planrun-backend/scripts/suunto_auto_sync.php` (77 строк)

Назначение: cron каждые ~15 минут — поллинг-фолбэк к ненадёжному Suunto webhook: для всех пользователей с `integration_tokens (provider='suunto')` тянет тренировки за последние `SUUNTO_AUTO_SYNC_DAYS` (дефолт 3) через `SuuntoProvider->fetchWorkouts` и импортирует `WorkoutService->importWorkouts` (идемпотентно по external_id). При импорте шлёт best-effort data-push `suunto_sync` для обновления UI. Гейт `SUUNTO_AUTO_SYNC_ENABLED`, flock-лок. Ошибки по пользователю — `Logger::warning`. Без функций.

## `planrun-backend/scripts/suunto_upload_worker.php` (81 строка)

Назначение: cron каждые 2–5 минут — заливает в Suunto тренировки из `suunto_upload_queue` (зеркало PlanRun → Suunto). Гейт `SUUNTO_UPLOAD_WORKER_ENABLED`, flock-лок. Батчами (`SUUNTO_UPLOAD_BATCH`=10, до `SUUNTO_UPLOAD_MAX_ITER`=50 итераций) берёт pending/error с attempts<3, помечает processing, вызывает `SuuntoProvider->uploadWorkout(uid, wid)`: PROCESSED→done (с workoutKey), SKIPPED→skipped, иначе pending для ретрая либо error после 3 попыток. Пауза `SUUNTO_UPLOAD_DELAY_MS` (3с) между заливками против троттлинга. Без функций.

## `planrun-backend/scripts/test_chat_with_history.php` (62 строки)

Назначение: ручной тест AI-чата — проверяет, использует ли чат историю разборов. Usage: `php scripts/test_chat_with_history.php <user_id> "<question>"`. Собирает контекст `ChatContextBuilder->buildContextForUser` и сообщения `ChatPromptBuilder->buildChatMessages`, печатает размер system prompt, затем синхронно вызывает `LlmGateway::requestChatCompletion` (LLM_CHAT_BASE_URL/MODEL, temperature 0.3, max_tokens 600) и выводит ответ. Без функций.

## `planrun-backend/scripts/test_ofp_enricher_scenarios.php` (87 строк)

Назначение: ручной тест ОФП-энричера (LLM #4): для пользователя id=1 и активной `exercise_library` гоняет `enrichPlanWithOfp()` на 4 синтетических неделях (HIGH-LOAD peak / MEDIUM build / LOW recovery / RACE-WEEK taper) и печатает подобранные сессии (упражнение, подходы×повторы/время, вес, заметки). Реально вызывает LLM. Без функций.

## `planrun-backend/scripts/test_ofp_scenarios.php` (41 строка)

Назначение: ручной тест `WorkoutBuilderService->buildOfpSession(preference, bodyweight, experience)` на 8 сценариях (зал/дом × уровни × вес, включая дефолты без bodyweight) — печатает упражнения с весами и источником веса; плюс одна СБУ-сессия `buildSbuSession()`. Алгоритмический, без LLM. Без функций.

## `planrun-backend/scripts/test_post_workout_analysis.php` (33 строки)

Назначение: ручной тест post-workout анализа — через Reflection вызывает private `WorkoutService::createPostWorkoutAnalysisMessage(userId, date, 'workout', workoutId)` для существующей тренировки и печатает message_id. Usage: `php scripts/test_post_workout_analysis.php <user_id> <workout_id> <yyyy-mm-dd>`. Без функций.

## `planrun-backend/scripts/test_post_workout_reply.php` (58 строк)

Назначение: ручной тест ответа AI на check-in после тренировки (фикс №3 — event_key `coach.proactive_post_workout_checkin_reply`): берёт/создаёт ai-диалог через `ChatRepository->getOrCreateConversation`, через Reflection вызывает private `ChatService::persistPostWorkoutFollowupReply` с фейковым follow-up payload (classification=fatigue, RPE, scores) и печатает message_id. Usage: `php scripts/test_post_workout_reply.php <user_id>`. Без функций.

## `planrun-backend/scripts/test_structure_analyzer.php` (24 строки)

Назначение: ручной тест `WorkoutStructureAnalyzer->analyze(workoutId, null, userId?)` — печатает JSON-результат анализа структуры тренировки. Usage: `php scripts/test_structure_analyzer.php <workout_id> [user_id]`. Без функций.

## `planrun-backend/scripts/test_vdot_state.php` (28 строк)

Назначение: ручной smoke-тест `TrainingStateBuilder->buildForUserId(1)` — печатает VDOT-поля (значение, источник, давность, confidence), pace_strategy (effective/goal target time, severity, gap) и расчётные тренировочные темпы. Hardcoded user id=1. Без функций.

## `planrun-backend/scripts/test_weekly_adaptation_mock.php` (68 строк)

Назначение: ручной тест WeeklyPlanAdaptationService с замоканным LLM-ответом — через Reflection дёргает private-методы loadUser → collectInputs → validatePatch → applyPatch → notifyUser → recordCooldown с одним изменением change_type на заданную дату; проверяет валидатор/apply/уведомление/cooldown без реального DeepSeek. Usage: `php scripts/test_weekly_adaptation_mock.php <user_id> <yyyy-mm-dd> <new_type> [new_desc]`. ВНИМАНИЕ: реально применяет изменение к плану. Без объявленных функций.

## `planrun-backend/scripts/test_weekly_review_for_user.php` (54 строки)

Назначение: ручной запуск weekly review для одного пользователя с обходом фильтра «воскресенье 20:00»: getCurrentWeekNumber + prepareWeeklyAnalysis из `prepare_weekly_analysis.php`, затем collectReviewEnrichment → buildWeeklyReviewPromptData → generateWeeklyReview (реальный DeepSeek) и сохранение через `ChatService->addAIMessageToUser` (event_key plan.weekly_review). ВНИМАНИЕ (баг): функции collectReviewEnrichment/buildWeeklyReviewPromptData/generateWeeklyReview объявлены ТОЛЬКО в `weekly_ai_review.php`, который этот скрипт не подключает — при запуске «как есть» будет fatal «undefined function» (рабочий аналог обхода — env `WEEKLY_REVIEW_FORCE_USER` у самого weekly_ai_review.php). Usage: `php scripts/test_weekly_review_for_user.php <user_id>`. Без функций.

## `planrun-backend/scripts/update_vdot_from_training.php` (80 строк)

Назначение: ручное обновление `users.last_race_*` из лучшей тренировки: `php scripts/update_vdot_from_training.php <username_slug>` (дефолт st_benni). Берёт `StatsService->getBestResultForVdot(userId)` (2–25 км за 12 недель), маппит дистанцию в label (5k/10k/half/marathon/other + km), форматирует время HH:MM:SS и UPDATE-ит `last_race_distance`, `last_race_distance_km`, `last_race_time`, `last_race_date`. Без функций.

## `planrun-backend/scripts/weekly_ai_review.php` (383 строки)

Назначение: cron каждую минуту — еженедельное AI-ревью тренировок; сам фильтрует пользователей по локальному времени (воскресенье 20:00, env `WEEKLY_REVIEW_FORCE_USER` обходит фильтр для теста). Выбирает пользователей с `training_mode='ai'` и активным/недавним планом (`training_plan_weeks`), для каждого: `prepareWeeklyAnalysis` (из prepare_weekly_analysis.php) → enrichment (тренды объёмов, ACWR, VDOT, прогресс к цели, промежуточные забеги) → промпт-текст → DeepSeek через `LlmGateway::requestChatCompletion` → `ChatService->addAIMessageToUser` (event_key `plan.weekly_review`). Review-only: план не меняет (адаптацию делает weekly_plan_adaptation.php). Читает `workout_log`, `workouts`, использует ChatContextBuilder/TrainingStateBuilder/GoalProgressService/StatsService.

### `buildWeeklyReviewPromptData(array $analysis, ?array $enrichment = null)` — L117
Формирует структурированный русский текст для промпта: статистика недели, по-дневная раскладка план/факт с пометкой ключевых, тренд объёмов за 4 недели, ACWR с зоной, цель/дни до забега, VDOT с трендом, прогресс к цели, промежуточные забеги.

### `collectReviewEnrichment(int $userId, $db)` — L250
Собирает обогащение: суммы км за 4 недели из `workout_log` + `workouts` (с дедупом по дате), ACWR из `ChatContextBuilder->calculateACWR`, intermediate_races из `TrainingStateBuilder`, VDOT/goal progress из `GoalProgressService` (фолбэк `StatsService->getBestResultForVdot`).

### `generateWeeklyReview(string $weekData, string $username)` — L329
Вызывает DeepSeek (LLM_CHAT_BASE_URL/MODEL, max_tokens 800, temperature 0.5) с system-промптом «AI-тренер PlanRun, 4-6 предложений, по-русски, без emoji»; возвращает текст ревью ≤4000 символов или null при ошибке/пустом ответе.

## `planrun-backend/scripts/weekly_digest.php` (37 строк)

Назначение: cron раз в неделю (вс вечером) — обёртка над `ProactiveCoachService->processWeeklyDigests()`. Гейт `PROACTIVE_COACH_ENABLED` (дефолт выключен). Печатает stats sent/skipped/errors и время выполнения. Без функций.

## `planrun-backend/scripts/weekly_plan_adaptation.php` (41 строка)

Назначение: cron каждую минуту — обёртка над `WeeklyPlanAdaptationService->processAllUsers()`; сервис сам фильтрует пользователей по локальному времени (цель — воскресенье 21:00 локально) и выполняет LLM-адаптацию плана на следующую неделю. Печатает сводку processed/adapted/skipped/errors и детали изменений по пользователям только при ненулевой активности. Без функций.

## `planrun-backend/scripts/workout_share_worker.php` (102 строки)

Назначение: worker очереди фоновой генерации share-карточек тренировок (`workout_share_jobs` → `workout_share_cards`). Режимы: `--once` (дефолт), `--drain` (до опустошения очереди), `--daemon --sleep=N`. Цикл: `WorkoutShareCardCacheService->reserveNextJob()` → если карточка уже в кэше (`getCachedCard`) — markCompleted со skip-причиной; иначе `WorkoutShareCardService->render(workoutId, userId, template, kind)` (Playwright-рендер PNG), повторная проверка кэша (гонка), `storeRenderedCard` и `markCompleted` с метаданными файла. Ошибка → `markFailed` с учётом attempts/maxAttempts; в `--once` падение завершает процесс с кодом 1. Без функций.

## Применённые миграции (`planrun-backend/scripts/_applied_migrations/`)

Применённые миграции, исторические (36 файлов + README.md) — не входят в активный код:

- migrate_activity_types_walking.php
- migrate_ai_plan_generation_events.php
- migrate_ai_plan_generation_events_cache_tokens.php
- migrate_chat_history_summary.php
- migrate_chat_tables.php
- migrate_chat_user_memory.php
- migrate_coach_tables.php
- migrate_daily_wellness.php
- migrate_email_verification_codes.php
- migrate_exercise_library.php
- migrate_experience_level_nullable.php
- migrate_goal_progress_snapshots.php
- migrate_integration_tokens.php
- migrate_integration_tokens_athlete_id.php
- migrate_notifications_paused.php
- migrate_onboarding_completed.php
- migrate_password_reset_table.php
- migrate_proactive_coach_log.php
- migrate_profile_privacy.php
- migrate_push_workout_minute.php
- migrate_refresh_tokens_device_id.php
- migrate_refresh_tokens_table.php
- migrate_role_enum.php
- migrate_site_settings.php
- migrate_training_load.php
- migrate_training_plan_days_multiple_per_date.php
- migrate_users_birth_month.php
- migrate_users_plan_summary.php
- migrate_weather_support.php
- migrate_workout_laps.php
- migrate_workout_share_cards.php
- migrate_workout_timeline.php
- migrate_workout_timeline_gps.php
- migrate_workouts_duration_seconds.php
- migrate_workouts_session_id_nullable.php
- migrate_workouts_source.php
