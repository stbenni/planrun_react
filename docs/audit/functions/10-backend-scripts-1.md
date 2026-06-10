# Backend scripts 1/2 (ai_runtime_smoke…live_plan_generation_batch) — справочник

## `planrun-backend/scripts/ai_runtime_smoke.php` (174 строки)
Назначение: smoke-тест AI-runtime инфраструктуры без реальных LLM-вызовов; запускается вручную (`php scripts/ai_runtime_smoke.php`), без аргументов. Подключает `tests/bootstrap.php`, открывает транзакцию и создаёт временного пользователя `ai_smoke_<hex>` с двумя записями в `workout_log`, follow-up'ами в `post_workout_followups`, заметками в `plan_day_notes` / `plan_week_notes` и сообщением через `ChatRepository`. Затем последовательно проверяет 4 блока: `AthleteSignalsService::getSignalsBetween` (подсчёт сигналов сна/командировки и фидбэка), `PostWorkoutFollowupService::snoozeFollowup` (откладывание на 30 мин), `PlanExplanationService::buildExplanation` (фраза «Пересчёт сделан» в summary) и `AiObservabilityService` (запись и чтение события в `ai_runtime_events`). В `finally` всегда делает `rollback`, так что БД не меняется. Печатает JSON-сводку `{ok, results}` и завершает с exit-кодом 1, если хоть одна проверка провалилась. Таблицы: `users`, `workout_log`, `chat_conversations`/`chat_messages` (через репозиторий), `post_workout_followups`, `plan_day_notes`, `plan_week_notes`, `ai_runtime_events` (всё в откатываемой транзакции).

## `planrun-backend/scripts/backfill_avatar_variants.php` (53 строки)
Назначение: разовый backfill — генерирует миниатюры (варианты) для уже существующих локальных аватаров в каталоге `uploads/avatars/`. Запускается вручную: `php scripts/backfill_avatar_variants.php`, без аргументов. Перебирает файлы `avatar_*.*`, пропускает уже сгенерированные варианты (имена с `__`) и файлы, не прошедшие `AvatarService::isManagedAvatarFileName`, для остальных вызывает `AvatarService::ensureAllVariantsForFileName`. БД не трогает, работает только с файловой системой. Выводит счётчики processed/skipped/failed, exit-код 1 при наличии ошибок.

## `planrun-backend/scripts/backfill_detected_type.php` (95 строк)
Назначение: разовый backfill колонки `workouts.detected_type` — классифицирует все импортированные тренировки через `WorkoutClassifier::classify`. Запуск вручную: `php scripts/backfill_detected_type.php [--user=ID] [--only-null]` (`--only-null` обрабатывает только тренировки без типа). Для каждого пользователя строит training state через `TrainingStateBuilder::buildForUserId`, получает тренировочные темпы по VDOT (`getTrainingPaces` из `prompt_builder.php`), берёт max HR из `users.birth_year` (`WorkoutClassifier::maxHrFromBirthYear`), подтягивает круги из `workout_laps` и обновляет `workouts.detected_type`. Таблицы: чтение `workouts`, `workout_laps`, `users`; запись `workouts`. В конце печатает количество пользователей и обновлённых тренировок.

## `planrun-backend/scripts/backfill_workout_analyses.php` (158 строк)
Назначение: разовый backfill таблицы `workout_analyses` для одного пользователя — прогоняет исторические тренировки через `WorkoutStructureAnalyzer` и сохраняет структурный анализ через `WorkoutAnalysisRepository`, не вызывая LLM (поле `llm_review_text` остаётся NULL). Запуск вручную: `php scripts/backfill_workout_analyses.php <user_id> [--since=YYYY-MM-DD] [--dry-run]`; по умолчанию `since` = 6 месяцев назад. Для каждой тренировки из `workouts` подтягивает плановый день из `training_plan_days`/`training_plan_weeks` и фидбэк из `post_workout_followups`, формирует `summary_line` через `WorkoutAnalysisRepository::formatSummaryLine` и сохраняет (`save`) с upsert-семантикой. С `--dry-run` только печатает строки без записи. Таблицы: чтение `workouts`, `training_plan_days`, `training_plan_weeks`, `post_workout_followups`; запись `workout_analyses` (через репозиторий).

### `fetchWorkouts($db, int $userId, string $since)` — L109
Выбирает из `workouts` все тренировки пользователя с датой (`DATE(COALESCE(end_time, start_time))`) не раньше `$since`, с полями дистанции, длительности, темпа и пульса; сортирует по `start_time`.

### `fetchPlannedForDate($db, int $userId, string $date)` — L129
Возвращает плановый день (`type`, `description`, `is_key_workout`) из `training_plan_days` с join на `training_plan_weeks` для указанной даты, либо null.

### `fetchFeedback($db, int $userId, string $sourceKind, int $sourceId)` — L145
Достаёт последний отвеченный follow-up (`session_rpe`, `legs_score`, `pain_flag`, `fatigue_flag`) из `post_workout_followups` со статусом `responded`/`completed` для пары source_kind/source_id.

## `planrun-backend/scripts/check_chat_debug.php` (39 строк)
Назначение: отладочная диагностика чата — выводит беседы пользователя и последние 10 сообщений каждой. Запуск вручную: `php scripts/check_chat_debug.php [user_id]` (по умолчанию user_id=1). Читает напрямую (без prepared statements, через интерполяцию int) таблицы `chat_conversations` и `chat_messages`, печатает превью первых 50 символов контента. Ничего не изменяет.

## `planrun-backend/scripts/check_login.php` (61 строка)
Назначение: отладочная диагностика входа — проверяет существование пользователя по username/email и валидность хеша пароля в `users`. Запуск вручную: `php scripts/check_login.php <username|email> [тестовый_пароль]`. Без второго аргумента только сообщает о наличии пользователя и хеша; с паролем выполняет `password_verify` и печатает результат, подсказывая сброс пароля при FAIL. Только чтение таблицы `users`.

## `planrun-backend/scripts/check_password_reset.php` (45 строк)
Назначение: отладочная проверка цепочки сброса пароля на сервере. Запуск вручную: `php scripts/check_password_reset.php [username]` (по умолчанию `st_benni`). Проверяет три вещи: наличие таблицы `password_reset_tokens` (`SHOW TABLES`), существование пользователя в `users`, инстанцируемость `EmailService` (PHPMailer + SMTP-конфиг). Ничего не изменяет, только диагностический вывод.

## `planrun-backend/scripts/check_push.php` (91 строка)
Назначение: диагностика инфраструктуры push-уведомлений (FCM). Запуск вручную: `php planrun-backend/scripts/check_push.php [user_id|username]`; без аргумента — только проверка инфраструктуры. Проверяет: env `FIREBASE_CREDENTIALS`/`FIREBASE_CREDENTIALS_JSON`, наличие класса `Kreait\Firebase\Factory` (composer-пакет), количество записей в `push_tokens`, наличие колонки `users.push_chat_enabled`. Для конкретного пользователя через `PushNotificationService` выводит число FCM-токенов (`getUserTokens`) и флаг разрешения push для чата (`isPushAllowed`). Только чтение: `users`, `push_tokens`.

## `planrun-backend/scripts/check_strava.php` (116 строк)
Назначение: диагностика интеграции Strava. Запуск вручную: `php scripts/check_strava.php [user_id]`. Шаги: 1) выводит env `STRAVA_CLIENT_ID`/`STRAVA_CLIENT_SECRET`/`STRAVA_REDIRECT_URI`/`STRAVA_PROXY`; 2) делает curl HEAD-запрос к `https://www.strava.com/api/v3/athlete` (через прокси, если задан, с поддержкой socks5) и интерпретирует HTTP-код (401 — норма, 403 — региональная блокировка); 3) строит OAuth-URL для сверки redirect_uri с настройками Strava Dashboard; 4) при наличии user_id читает токены из `integration_tokens` (provider='strava') и сообщает об истечении access_token / отсутствии refresh_token. Только чтение БД.

## `planrun-backend/scripts/cleanup_expired_refresh_tokens.php` (31 строка)
Назначение: cron-скрипт очистки просроченных refresh-токенов. Рекомендуемое расписание в шапке: `0 3 * * *` (раз в сутки). Считает количество строк `refresh_tokens` с `expires_at < NOW()`, и если есть — удаляет их одним DELETE, печатая количество. Таблица: `refresh_tokens` (чтение + удаление).

## `planrun-backend/scripts/daily_briefing.php` (37 строк)
Назначение: cron-скрипт утреннего брифинга от AI-коуча — раз в день в утреннем окне пользователя. Гейтится env-флагом `PROACTIVE_COACH_ENABLED` (при 0 выходит сразу с «ProactiveCoach disabled»). Вся логика делегирована `ProactiveCoachService::processDailyBriefings()`, скрипт лишь печатает статистику sent/skipped/errors и время выполнения. Таблицы трогает сервис (чат, пуши, пользователи); сам скрипт БД напрямую не модифицирует.

## `planrun-backend/scripts/dry_run_coaching_prompt.php` (297 строк)
Назначение: dry-run smoke-тест coaching-промпта v4 (PR8 / Phase E) — собирает FACTS_JSON и промпты для `DeepSeekPlanPlanner` БЕЗ вызова LLM API. Запуск вручную: `php scripts/dry_run_coaching_prompt.php --user=<id|username> [--job-type=generate|recalculate] [--cutoff-date=…] [--show-prompt=1] [--show-facts=1]` (дефолты: user=st_benni, job-type=recalculate). Через Reflection дергает приватные методы планировщика (`loadUser`, `buildPlanningUser`, `resolveStartDate`, `resolveWeeksCount`, `buildPlannerContext`, `buildSystemPrompt`, `buildFullPlanPrompt`) и `TrainingStateBuilder::buildForUser`. Печатает: state/context (vdot, readiness, weeks_to_goal), recent_compliance_summary и peak_volume_floor_km (PR-A), маркеры race_proximity по дням календаря (PR-B), pace_strategy (PR9), выбор модели через `resolveModelSelection` (PR-C), размеры system/user-промптов и ~15 sanity-проверок текста промпта (присутствие нужных секций, отсутствие устаревшей prose, лимиты длины). Exit-код 1 при провале любой sanity-проверки. БД только читает (`users` + всё что читают сервисы).

### `dryParseArgs(array $argv)` — L29
Парсит CLI-аргументы вида `--key=value` в массив с дефолтами (user=st_benni, job-type=recalculate, show-prompt/show-facts=0).

### `dryResolveUser(mysqli $db, string $userArg)` — L47
Находит пользователя в `users` по числовому id либо по username/username_slug; возвращает строку таблицы или пустой массив.

## `planrun-backend/scripts/dry_run_recalculate_prompt.php` (125 строк)
Назначение: dry-run сборки промпта recalculate для legacy-генератора (`plan_generator.php`/`prompt_builder.php`) без вызова LLM — печатает итоговый текст промпта и его длину. Запуск вручную: `php scripts/dry_run_recalculate_prompt.php <user_id>`. Загружает пользователя из `users`, декодирует preferred_days/preferred_ofp_days, считает weeks_to_generate относительно cutoff (понедельник текущей недели, `WeekRepository::getMaxWeekNumberBefore`) и целевой даты, затем собирает упрощённый (частично захардкоженный: compliance 83%, avg 28 км и т.п.) `$recalcContext` с реальными `calculateACWR` (ChatContextBuilder) и plan_history-полями из `WorkoutAnalysisRepository` (`getSummaryLinesForActivePlan`, `getWeeklyRollupForActivePlan`, `getKeyWorkoutSummaryForActivePlan`), после чего вызывает `buildRecalculationPrompt`. На L26 через `eval` объявляется функция `callAIAPI_overridden`, но она нигде не вызывается — мёртвый код от неудавшейся идеи перехвата `callAIAPI` (что прямо описано в комментариях L38–46). Только чтение БД.

### `callAIAPI_overridden($prompt, $user, $retries, $userId)` — L26 (объявлена через eval)
Заглушка, которая должна была печатать промпт вместо реального LLM-вызова; фактически не используется — скрипт пошёл по пути ручной сборки контекста.

## `planrun-backend/scripts/dry_run_weekly_adaptation.php` (99 строк)
Назначение: dry-run для `WeeklyPlanAdaptationService` — собирает входные данные, РЕАЛЬНО вызывает LLM, валидирует предложенный патч, но не применяет изменения и не шлёт уведомления. Запуск вручную: `php scripts/dry_run_weekly_adaptation.php <user_id>`. Через Reflection дергает приватные методы сервиса по этапам: `loadUser` → `collectInputs` (план недели, факт, ACWR, compliance, фидбэк, дни следующей недели) → `buildPrompt` (печать первых 1500 символов) → `callLlm` → `validatePatch`. Выводит ответ LLM, результат валидации и список принятых изменений (date, old_type → new_type). Внимание: тратит реальные LLM-токены. БД не модифицирует.

## `planrun-backend/scripts/eval_plan_generation.php` (444 строки)
Назначение: batch-eval генерации тренировочных планов — прогоняет реальную LLM-генерацию для списка пользователей или синтетических fixture-кейсов и сохраняет JSON-артефакты с планами, ошибками валидации и issue-score. Запуск вручную: `php scripts/eval_plan_generation.php --user-ids=1,2,3 | --fixture=synthetic [--case-names=…] [--mode=first-pass|full] [--save-dir=tmp/eval_artifacts]`. Режим `first-pass` — один сырой AI-проход (`callAIAPI` → `decodeGeneratedPlanResponse` → `normalizeGeneratedPlanForValidation` → `collectNormalizedPlanValidationIssues`) без corrective-цикла; `full` — штатный `generatePlanViaPlanRunAI()` для реальных юзеров или pipeline с `maybeApplyCorrectiveRegenerationToPlan` для синтетики. Fixture-кейсы лежат в `tests/Fixtures/synthetic_plan_eval_cases.php` и гидрируются через `hydratePlanGenerationUserState` + `attachPlanSkeleton`. Результаты каждого кейса пишутся в `case_<name>.json` / `user_<id>.json`, общий итог — в `summary.json`. В режиме `full` для реальных user-ids ПИШЕТ план в БД (через `generatePlanViaPlanRunAI`); first-pass БД не модифицирует.

### `evalUsage()` — L25
Печатает usage-текст со всеми вариантами вызова скрипта.

### `evalParseUserIds(string $raw)` — L35
Разбирает CSV-строку `--user-ids` в массив уникальных положительных int.

### `evalFetchUser(mysqli $db, int $userId)` — L42
Загружает полную строку пользователя из `users` по id, либо null.

### `evalLoadFixtureCases(string $fixtureName, ?string $caseNamesRaw)` — L55
Загружает массив синтетических кейсов из fixture-файла (`synthetic` → `tests/Fixtures/synthetic_plan_eval_cases.php`); опционально фильтрует по списку имён и бросает RuntimeException при неизвестных именах.

### `evalHydrateSyntheticUser(mysqli $db, array $case)` — L89
Готовит синтетического «пользователя» к генерации: проставляет goal_type, прогоняет через `hydratePlanGenerationUserState` и `attachPlanSkeleton`.

### `evalSummarizeIssues(array $issues)` — L98
Сворачивает список валидационных issues в сводку: `issue_score` (через `scoreValidationIssues`), количество и счётчики по кодам.

### `evalBuildFirstPassArtifact(mysqli $db, int $userId)` — L113
Полный first-pass для реального пользователя: промпт → один вызов `callAIAPI` → декодирование, нормализация, сбор issues; возвращает артефакт с планом, issues и summary.

### `evalBuildFullArtifact(int $userId)` — L152
Запускает штатный `generatePlanViaPlanRunAI($userId)` (со всеми corrective-механизмами и сохранением) и формирует артефакт из `_generation_metadata`.

### `evalBuildSyntheticFirstPassArtifact(mysqli $db, array $case)` — L173
Аналог first-pass артефакта для синтетического кейса (user_id=null), включая нормализацию и issue-сводку.

### `evalBuildSyntheticFullArtifact(mysqli $db, array $case)` — L209
Синтетический full-pipeline: first-pass + `maybeApplyCorrectiveRegenerationToPlan` с метаданными corrective_used/repair_count.

## `planrun-backend/scripts/generate_web_push_vapid_keys.php` (11 строк)
Назначение: одноразовая утилита генерации VAPID-ключей для Web Push. Запуск вручную, без аргументов. Вызывает `WebPushNotificationService::createVapidKeys()` и печатает три готовые строки для `.env`: `WEB_PUSH_VAPID_PUBLIC_KEY`, `WEB_PUSH_VAPID_PRIVATE_KEY`, `WEB_PUSH_VAPID_SUBJECT`. БД не трогает.

## `planrun-backend/scripts/get_jwt_for_push_test.php` (52 строки)
Назначение: отладочная утилита — логинится через `AuthService::login` и печатает готовую curl-команду с реальным JWT для теста эндпоинта `register_push_token`. Запуск вручную: `php scripts/get_jwt_for_push_test.php <username> <password>`. Использует `AuthService`/`JwtService`, host берёт из env `APP_URL`. Побочный эффект: реальный логин (создаёт refresh-токен для device `test-device-001`).

## `planrun-backend/scripts/goal_progress_snapshot.php` (28 строк)
Назначение: cron-скрипт еженедельных снапшотов прогресса к цели для всех активных пользователей; рекомендован к запуску в воскресенье вечером. Запуск: `php goal_progress_snapshot.php [--date=YYYY-MM-DD]`. Вся логика в `GoalProgressService::processAllUsers($date)`; скрипт печатает количество обработанных пользователей. Таблицы трогает сервис (снапшоты прогресса целей).

## `planrun-backend/scripts/inspect_ai_runtime.php` (200 строк)
Назначение: read-only инспектор таблицы `ai_runtime_events` (observability AI-вызовов) — выводит JSON со сводкой по surface/event_type/status, перцентилями длительности (p50/p95/max) и списком последних событий с компактным payload. Запуск вручную: `php scripts/inspect_ai_runtime.php [--hours=24] [--limit=50] [--surface=…] [--status=…] [--event_type=…] [--trace=…] [--user_id=…]`. Фильтры экранируются через `real_escape_string` и подставляются в WHERE. Только чтение `ai_runtime_events`.

### `parseArgs(array $argv)` — L97
Парсит `--key=value` и булевые `--flag` аргументы в ассоциативный массив.

### `fetchAll(mysqli $db, string $sql)` — L114
Выполняет запрос и возвращает все строки как массив ассоц-массивов; при ошибке запроса печатает её в STDERR и завершает скрипт с кодом 1.

### `percentile(array $values, int $percentile)` — L128
Считает перцентиль по отсортированному массиву методом ceil-индекса; null для пустого массива.

### `formatEvent(array $row)` — L137
Приводит строку события к типизированному виду и декодирует `payload_json`, прогоняя его через `compactPayload`.

### `compactPayload(array $payload)` — L156
Оставляет из payload только белый список ключей (model, tokens, limiter_*, http_status, error и т.п.) для компактного вывода.

## `planrun-backend/scripts/inspect_plan_generation_failures.php` (97 строк)
Назначение: read-only сводка по упавшим задачам генерации планов — выбирает последние записи `plan_generation_jobs` со status='failed', категоризирует каждую по тексту `last_error` и печатает JSON со счётчиками категорий и превью ошибок (320 символов). Запуск вручную: `php scripts/inspect_plan_generation_failures.php [--limit=30]` (limit 1–500). Очередь не мутирует.

### `categorizeFailure(string $error)` — L76
Эвристически классифицирует текст ошибки в одну из категорий: `user_missing`, `code_bug` (TypeError/trim), `quality_gate`, `provider_rate_limit` (429), `provider_overload` (5xx/overload), `llm_output_format` (invalid json/truncated), иначе `other`.

## `planrun-backend/scripts/live_generate_one_user.php` (214 строк)
Назначение: live-регрессия PR8 (coaching prompt v4) для одного пользователя — запускает РЕАЛЬНУЮ генерацию плана через `DeepSeekPlanPlanner::generate()` (тратит токены DeepSeek, может занять 60–300 с), но БД не изменяет: planner только возвращает `weeks_data`, без сохранения. Запуск вручную: `php scripts/live_generate_one_user.php --user=<id|username> [--job-type=generate|recalculate] [--cutoff-date=…] [--save-dir=…]` (дефолты: st_benni / recalculate). Печатает метаданные генерации (модель, thinking, токены, quality_gate), срез race-week (захардкоженное окно 2026-05-13…2026-05-21 под конкретный кейс st_benni), plan_summary и обзор всех недель (объём, длительная, ключевые типы). Полный результат сохраняет в JSON в `tmp/pr8_live_generation/`. Только чтение `users` + всё, что читает planner.

### `liveGenParseArgs(array $argv)` — L28
Парсит `--key=value` аргументы с дефолтами user=st_benni, job-type=recalculate.

### `liveGenResolveUser(mysqli $db, string $userArg)` — L45
Находит пользователя по числовому id либо username/username_slug в `users` (дубликат `dryResolveUser` из dry_run_coaching_prompt.php).

## `planrun-backend/scripts/live_next_plan_batch.php` (384 строки)
Назначение: live-прогон генерации `next_plan` (следующий блок после завершения текущего плана) для пачки синтетических пользователей, созданных ранее `live_plan_generation_batch.php`. Запуск вручную: `php scripts/live_next_plan_batch.php [--prefix=live50_20260424] [--limit=50] [--save-dir=…] [--fast-llm-fallback=1]`. Принудительно ставит `PLAN_GENERATION_MODE=llm_planner`; при fast-llm-fallback (включён по умолчанию) подменяет `LLM_CHAT_BASE_URL` на заведомо нерабочий адрес, чтобы пошёл алгоритмический fallback без трат токенов. Для каждого пользователя по prefix: читает недели до (`training_plan_weeks`), строит payload (cutoff = понедельник после последней недели, средний объём последних 4 недель), вызывает `PlanGenerationProcessorService::process($userId, 'next_plan', $payload)` — это ПИШЕТ новый план в БД, затем сравнивает недели после и собирает issues (wrong_anchor, too_short, starts_above_recent_load). Итог сохраняет в JSON + Markdown в `tmp/live_plan_generation/`; exit-код 1 при любых next_plan-ошибках. Таблицы: чтение `users`, `training_plan_weeks`; запись плана через processor.

### `liveNextParseArgs(array $argv)` — L22
Парсит `--key=value` аргументы с дефолтами prefix=live50_20260424, limit=50, fast-llm-fallback=1.

### `liveNextBool(mixed $value)` — L46
Приводит строковое значение к bool по списку '1'/'true'/'yes'/'on'.

### `liveNextSetEnv(string $key, string $value)` — L51
Устанавливает env-переменную сразу в `$_ENV`, `$_SERVER` и через `putenv`.

### `liveNextFetchUsers(mysqli $db, string $prefix, int $limit)` — L58
Выбирает пользователей из `users` по `username LIKE prefix%` с лимитом.

### `liveNextFetchPlanWeeks(mysqli $db, int $userId)` — L80
Читает недели плана (`week_number`, `start_date`, `total_volume`) из `training_plan_weeks` пользователя.

### `liveNextCaseCode(array $user)` — L100
Извлекает код кейса из username, отрезая захардкоженный префикс `live50_20260424_`.

### `liveNextMondayAfter(string $date)` — L107
Возвращает дату ровно через неделю от переданной (предполагается, что вход — понедельник последней недели).

### `liveNextBuildPayload(array $user, array $beforeWeeks)` — L112
Строит payload для next_plan: cutoff_date (понедельник после последней недели), средний км последних 4 ненулевых недель, текстовая цель нового блока.

### `liveNextSummarizeWeeks(array $weeks, string $cutoffDate)` — L131
Сводка по неделям: количество, первая/последняя start_date, объём первой недели и максимум; содержит мёртвый цикл (тело пустое) и всегда пустой `race_days`.

### `liveNextIssue(string $severity, string $code, string $message, array $context)` — L155
Конструктор issue-записи {severity, code, message, context}.

### `liveNextEvaluate(array $user, array $payload, array $beforeWeeks, array $afterWeeks, ?array $result, ?string $error)` — L165
Оценивает результат next_plan: ошибка генерации, неверная дата старта (next_plan_wrong_anchor), меньше 4 недель, первая неделя > 125% среднего прошлого блока; возвращает issues + сводку.

### `liveNextBuildMarkdown(array $report)` — L219
Рендерит Markdown-отчёт: summary, таблица по пользователям, секция Problems со списком issues.

## `planrun-backend/scripts/live_plan_generation_batch.php` (1169 строк)
Назначение: большой live-тестовый стенд — создаёт до 50 разнообразных синтетических пользователей через `RegistrationService::registerFull()` (10 health-кейсов, 8 weight_loss, 16 race, 10 time_improvement, 10 edge-кейсов с захардкоженными профилями), запускает для каждого штатную генерацию плана через `PlanGenerationProcessorService` (тот же путь, что queue-worker, включая `PlanGenerationQueueService::markCompleted/markFailed`), затем оценивает сохранённые планы «тренерскими» эвристиками и пишет JSON+Markdown-отчёт. Запуск вручную: `php scripts/live_plan_generation_batch.php [--limit=50] [--prefix=…] [--start-date=…] [--skip-generation=1] [--reuse-existing=1] [--fast-llm-fallback=1] [--case-codes=a,b] [--parallel=N]`. При `--parallel>1` родитель шардирует кейсы и порождает воркеров через `proc_open` (рекурсивный вызов самого себя с `--shard=idx/total`), затем мержит их JSON-артефакты. Эвристики оценки (~25 issue-кодов): старт не с понедельника, бег вне preferred_days, интенсив у новичков/«осторожных» слишком рано, доля длительной от недельного объёма, рост объёма >12–18% без разгрузки, race-день не на дату гонки, тяжёлая работа за ≤2 дня до гонки, пиковая длительная вне диапазона дистанции и т.д. ПИШЕТ в БД: `users` (регистрация), `training_plan_weeks`/`training_plan_days`/`training_day_exercises` (через processor), `plan_generation_jobs` (через queue). Артефакты — в `tmp/live_plan_generation/`.

### `liveBatchParseArgs(array $argv)` — L29
Парсит `--key=value` аргументы с дефолтами (limit=50, prefix=live50_<ts>, start-date=следующий понедельник, parallel=1, shard — служебный).

### `liveBatchBool(mixed $value)` — L59
Приводит значение к bool по списку '1'/'true'/'yes'/'on'.

### `liveBatchNextMonday(string $date)` — L64
Возвращает ближайший понедельник, не раньше переданной даты.

### `liveBatchDateAddWeeks(string $date, int $weeks, int $extraDays)` — L72
Сдвигает дату на N недель плюс дополнительные дни (используется для race_date/weight_goal_date кейсов).

### `liveBatchJsonDays(array $days)` — L80
JSON-кодирует массив дней недели для полей preferred_days/preferred_ofp_days.

### `liveBatchUsername(string $prefix, int $number, string $code)` — L85
Собирает username вида `prefix_NN_code` с обрезкой до 50 символов.

### `liveBatchBaseProfile(string $startDate, array $overrides)` — L91
Базовый профиль регистрации (training_mode=ai, мужчина 1992 г.р., 10 км/нед и т.п.), поверх которого мержатся overrides; пересчитывает sessions_per_week из preferred_days.

### `liveBatchBuildProfiles(string $prefix, string $startDate)` — L142
Возвращает ~54 захардкоженных кейс-профиля (health/weight_loss/race/time_improvement/edge) с уникальными username/email и `_case_code`.

### `liveBatchFetchUserByUsername(mysqli $db, string $username)` — L223
Загружает строку `users` по точному username, либо null.

### `liveBatchRegisterOrReuse(mysqli $db, RegistrationService $registration, array $profile, bool $reuseExisting)` — L236
Переиспользует существующего пользователя (при reuse-existing) или регистрирует нового через `registerFull`; бросает исключение при конфликте/ошибке регистрации.

### `liveBatchRunGeneration(mysqli $db, PlanGenerationQueueService $queue, PlanGenerationProcessorService $processor, int $userId)` — L256
Находит активную job в очереди (если регистрация её создала), вызывает `processor->process`, помечает job completed/failed и при ошибке вызывает `persistFailure`; возвращает {ok, job_id, duration_ms, result|error}.

### `liveBatchFetchSavedPlan(mysqli $db, int $userId)` — L303
Читает сохранённый план из `training_plan_weeks` + `training_plan_days` с агрегацией run-упражнений из `training_day_exercises` (дистанция, длительность, темп) в структуру недель/дней.

### `liveBatchIssue(string $severity, string $code, string $message, array $context)` — L381
Конструктор issue-записи (идентичен `liveNextIssue`).

### `liveBatchDecodeDays(mixed $raw)` — L391
Декодирует preferred_days из JSON-строки или массива в массив строк-кодов дней.

### `liveBatchDayCodeToNumber(string $code)` — L403
Маппит 'mon'…'sun' в ISO-номер дня недели 1–7.

### `liveBatchDistanceKm(?string $distance)` — L409
Преобразует код дистанции ('5k'/'10k'/'half'/'marathon'/числа) в километры.

### `liveBatchExpectedWeeks(array $user)` — L421
Оценивает ожидаемую длину плана: health_plan_weeks / дефолты программ для health, недели до race_date/weight_goal_date для остальных целей.

### `liveBatchEvaluatePlan(array $user, array $weeks, array $generation)` — L450
Главная «тренерская» оценка сохранённого плана: ~25 эвристик по дням и неделям (preferred days, интенсив для новичков, доля длительной, рост объёма, race-день, peak long и пр.); возвращает отсортированные по severity issues и сводку.

### `liveBatchHasExplicitQualityPace(array $day)` — L758
Проверяет, задан ли темп ключевой тренировки: поле pace либо «темп»/«мин/км»/паттерн M:SS в описании.

### `liveBatchInferRecoveryWeeks(array $volumes)` — L774
Эвристически помечает разгрузочные недели: объём ≤92% предыдущей и следующая ≥108% текущей.

### `liveBatchLongShareLimits(int $sessions, bool $isNovice, bool $isConservative, float $raceDistanceKm)` — L801
Возвращает пару [warning, error] лимитов доли длительной от недельного объёма в зависимости от числа сессий, осторожности и дистанции гонки.

### `liveBatchIsLongShareMaterial(float $longDistanceKm, float $weekVolumeKm, int $sessions)` — L822
Отсекает несущественные срабатывания long_run_share_high при малых объёмах/коротких длительных.

### `liveBatchIsGrowthMaterial(float $referenceVolumeKm, float $deltaKm, bool $isCautious)` — L839
Отсекает несущественные срабатывания weekly_growth_high при малых абсолютных приростах на низких объёмах.

### `liveBatchBuildMarkdown(array $report)` — L857
Рендерит Markdown-отчёт батча: контекст, summary, топ issue-кодов, таблица пользователей, секция «Coach Review: Problems To Fix» (до 8 issues на кейс).
