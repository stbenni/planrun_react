# Мёртвый код: backend (верифицировано)

Дата проверки: 2026-06-10. Метод: адверсариальная верификация — для каждого кандидата искались прямые вызовы, динамические вызовы (`call_user_func`, `[$obj, 'name']`, `->{$var}()`, Reflection `getMethod()`), строковые упоминания в маршрутизаторах (api_v2.php action-map — там только литеральные имена методов), использование вне PHP (openapi.yaml, systemd-юниты, crontab сервера, shell-скрипты, package.json, src/). Поиск регистронезависимый (имена PHP-функций case-insensitive).

## 1. Подтверждённый мёртвый код (можно удалять)

### Глобальные функции

| Символ | Файл:строка | Строк | Как проверено |
|---|---|---|---|
| `getUserCalendarUrl` | planrun-backend/calendar_access.php:125–173 | 49 | 0 вызовов (grep -i по всему репо), нет в строках/маршрутизаторах |
| `prepareFullPlanAnalysis` | planrun-backend/prepare_weekly_analysis.php:310–677 | 368 | 0 вызовов; файл подключается weekly_ai_review/StatsService, но используют только `prepareWeeklyAnalysis`/`getCurrentWeekNumber` |
| `getTotalTrainingDays` | planrun-backend/query_helpers.php:16–30 | 15 | 0 вызовов; в файле живы только getCompletedDaysKeys, getUserCoachAccess, isUserCoach |
| `parsePreferredDays` | planrun-backend/query_helpers.php:114–139 | 26 | 0 вызовов в PHP; одноимённая функция в src/screens/settings/profileForm.js — независимая JS-копия |
| `formatPreferredDays` | planrun-backend/query_helpers.php:147–179 | 33 | 0 вызовов |
| `getWeekDates` | planrun-backend/query_helpers.php:189–216 | 28 | 0 вызовов |
| `getUserByTelegramId` | planrun-backend/user_functions.php:146–163 | 18 | 0 вызовов (телеграм-логин идёт другим путём) |
| `getUserActivePlan` | planrun-backend/user_functions.php:168–177 | 10 | 0 вызовов |
| `getActivityTypes` | planrun-backend/workout_types.php:22–55 | 34 | 0 вызовов; `Cache::get('activity_types')` — внутри самой функции |
| `getActivityType` | planrun-backend/workout_types.php:60–72 | 13 | Бонус-находка при проверке: 0 вызовов (getActivityTypeRu в PostWorkoutFollowupService — другой метод). Обе функции workout_types.php мертвы ⇒ можно убрать и сам файл вместе с `require_once` в WorkoutController.php:9 и WorkoutService.php:7 |

### Методы классов

| Символ | Файл:строка | Строк | Как проверено |
|---|---|---|---|
| `Cache::remember` | planrun-backend/cache_config.php:288–300 | 13 | Отдельно искались `Cache::remember` и `->remember(` — 0 вхождений. Сам класс Cache живой (десятки вызовов get/set/delete/invalidate) — удалять только метод |
| `BaseService::throwUnauthorizedException` | planrun-backend/services/BaseService.php:66–69 | 4 | 0 вызовов, в т.ч. из наследников ($this->…) |
| `BaseService::throwForbiddenException` | planrun-backend/services/BaseService.php:74–77 | 4 | 0 вызовов |
| `ChatConfirmationHandler::tryExtractFromLastProposal` | planrun-backend/services/ChatConfirmationHandler.php:124–160 | 37 | 0 вызовов; проверен прокси ChatActionParser — он проксирует только `isConfirmationMessage`; живые вызовы хендлера: tryHandleSwap/ReplaceWithRace/GenericUpdate (ChatService:325–332) |
| `PlanGenerationProcessorService::syncRaceTargetTimeIfAdjusted` | planrun-backend/services/PlanGenerationProcessorService.php:2163–2180 | 18 | private, 0 вызовов (по batch-отчёту — осознанно отключён) |
| `SiteSettingsService::getAllowedKeys` | planrun-backend/services/SiteSettingsService.php:75–77 | 3 | 0 вызовов |
| `TrainingLoadService::computeAndCacheWorkoutTrimp` | planrun-backend/services/TrainingLoadService.php:109–141 | 33 | 0 вызовов; сам сервис живой (StatsController:181, ChatToolRegistry:739 — getTrainingLoad) |
| `TrainingLoadService::recalculateAllTrimp` | planrun-backend/services/TrainingLoadService.php:147–178 | 32 | 0 вызовов, в т.ч. из скриптов (update_vdot_from_training не использует) |
| `WebPushNotificationService::getSubscriptionCount` | planrun-backend/services/WebPushNotificationService.php:147–157 | 11 | 0 вызовов |
| `WorkoutService::triggerPostWorkoutCoachFlow` | planrun-backend/services/WorkoutService.php:133–138 | 6 | Публичная обёртка без вызовов; внутренний `handlePostWorkoutCoachFlow` ЖИВ (3 вызова: WorkoutService:1289, 1406, 1474) — удалять только обёртку |

Итого секция 1: **20 символов, ~755 строк** (без учёта docblock-комментариев над функциями).

## 2. Жив только в тестах

Семейство schedule-overrides в planrun-backend/planrun_ai/prompt_builder.php. Из продакшен-кода не вызывается ни одна; точка входа `applyScheduleOverridesToUserData` дёргается только юнит-тестами, остальные пять — только внутри цепочки.

| Символ | Файл:строка | Строк | Какие тесты |
|---|---|---|---|
| `applyScheduleOverridesToUserData` | prompt_builder.php:2053–2082 | 30 | tests/Unit/PromptBuilderTrainingStateTest.php:146–176 (2 теста: long/rest days, benchmark/easy floor) |
| `extractScheduleOverridesFromReason` | prompt_builder.php:76–106 | 31 | транзитивно (вызов только из applySchedule…:2055) |
| `getPromptWeekdayPatterns` | prompt_builder.php:22–32 | 11 | транзитивно (только из extractScheduleOverrides…:83) |
| `formatPromptTimeForBenchmark` | prompt_builder.php:1989–2003 | 15 | транзитивно (только из extractPlanningBenchmark…:2035) |
| `extractPlanningBenchmarkFromReason` | prompt_builder.php:2005–2037 | 33 | транзитивно (только из applySchedule…:2071) |
| `extractPlanningEasyFloorFromReason` | prompt_builder.php:2039–2051 | 13 | транзитивно (только из applySchedule…:2076) |

Итого: **6 функций, ~133 строки**. При удалении нужно удалить и 2 теста в PromptBuilderTrainingStateTest.php.

## 3. Одноразовые / ручные скрипты (кандидаты на перенос в архив)

Проверено по crontab сервера (16 заданий planrun), systemd-юнитам (/etc/systemd/system + корень репо), exec-вызовам из PHP, shell-скриптам и package.json. Прецедент архива уже есть: `planrun-backend/scripts/_applied_migrations/` (37 файлов).

### Применённые миграции (перенести в _applied_migrations/)
- `migrate_executed_exercises.php` — миграция executed exercises; применена, нигде не вызывается.
- `migrate_notifications_refkey.php` — миграция ref-key уведомлений; применена.
- `migrate_suunto_upload.php` — таблицы Suunto upload; применена.
- `migrate_all.php` — НЕ переносить: это канонический раннер base-миграций, на него ссылаются рантайм-ошибки (см. секцию 4).

### Сидеры
- `seed_coaches.php` — наполнение демо-тренеров; одноразовый, единственная ссылка — комментарий в seed_coaches_avatars.
- `seed_coaches_avatars.php` — аватары демо-тренеров; одноразовый.

### Бэкфилы (выполнены, нигде не вызываются)
- `backfill_avatar_variants.php` — генерация вариантов аватаров задним числом.
- `backfill_detected_type.php` — бэкфил detected_type тренировок.
- `backfill_workout_analyses.php` — бэкфил AI-анализов тренировок.
- `strava_backfill_athlete_ids.php` — бэкфил athlete_id в integration_tokens.

### Отладочные check_* / debug-утилиты (ручной запуск при инцидентах)
- `check_chat_debug.php`, `check_login.php`, `check_password_reset.php`, `check_push.php`, `check_strava.php` — диагностика по областям; нигде не вызываются автоматически.
- `get_jwt_for_push_test.php` — получить JWT для теста пушей.
- `generate_web_push_vapid_keys.php` — одноразовая генерация VAPID-ключей (ключи уже в .env).

### Ручные тестовые скрипты (не в phpunit, гоняются руками против боевой БД)
- `test_chat_with_history.php`, `test_ofp_enricher_scenarios.php`, `test_ofp_scenarios.php`, `test_post_workout_analysis.php`, `test_post_workout_reply.php`, `test_structure_analyzer.php`, `test_vdot_state.php`, `test_weekly_adaptation_mock.php`.
- `test_weekly_review_for_user.php` — СЛОМАН: вызывает `collectReviewEnrichment`/`buildWeeklyReviewPromptData`/`generateWeeklyReview` (строки 30–34), которые определены в scripts/weekly_ai_review.php, а он не подключён → fatal error при запуске. Первый кандидат на удаление.
- `send_real_test_notifications.php`, `send_test_push.php` — ручная отправка тестовых пушей.
- `tests/test_chat_fixes.php`, `tests/test_chat_tools.php` — лежат в корне tests/, phpunit.xml гоняет только tests/Unit и tests/Feature ⇒ CI их не видит; test_chat_tools захардкожен на user_id=1.

### Разовые ops-скрипты
- `regenerate_ai_messages.php` — пере-генерация AI-сообщений (Reflection до private-методов); разовая операция.
- `update_vdot_from_training.php` — разовый пересчёт VDOT.
- `strava_register_webhook.php` — регистрация Strava-вебхука; функционально покрыт strava_daily_health_check (cron каждые 4 ч).
- `polar_register_webhook.php` — то же для Polar; покрыт polar_webhook_health (cron, 04:00).

### Эксперименты генерации планов (PR8 / live50 — захардкоженные одноразовые прогоны)
- `dry_run_coaching_prompt.php` + `live_generate_one_user.php` — захардкожены под эксперимент PR8.
- `dry_run_recalculate_prompt.php` — использует legacy prompt_builder; внутри мёртвая `callAIAPI_overridden` (L26, определена через eval, ни разу не вызывается).
- `live_next_plan_batch.php` — захардкожен префикс `live50_20260424`; форк live_plan_generation_batch (дублируются parseArgs/issue/bool/buildMarkdown-хелперы).
- `live_plan_generation_batch.php`, `live_recalculate_batch.php` — батч-прогоны по боевой БД; ручные.
- `run_recalculate_for_user.php`, `run_weekly_adaptation_for_user.php`, `dry_run_weekly_adaptation.php` — ручные точечные прогоны (дублируют пути cron-скриптов).

### Рабочие ручные инструменты (оставить в scripts/, НЕ архив)
- `eval_plan_generation.php` (+ tests/Fixtures/synthetic_plan_eval_cases.php), `inspect_ai_runtime.php`, `inspect_plan_generation_failures.php`, `ai_runtime_smoke.php` — действующие инструменты диагностики AI-пайплайна.

## 4. Опровергнутые кандидаты (живые)

| Символ | Где реально используется |
|---|---|
| `AiPlanGenerationEventLogger::enrichAggregateRow` | Динамический вызов: `array_map([$this, 'enrichAggregateRow'], $byCohort/$byModel)` — services/AiPlanGenerationEventLogger.php:247–248. Grep по `->enrichAggregateRow(` его не видит |
| `scripts/suunto_upload_worker.php` | Спавнится из PHP: services/WorkoutService.php:1917 (`$scriptPath = … '/scripts/suunto_upload_worker.php'`) |
| `scripts/workout_share_worker.php` | Спавнится из PHP: services/WorkoutService.php:721 |
| `scripts/plan_generation_worker.php` | systemd: planrun-plan-generation-worker.service и @.service (`ExecStart … plan_generation_worker.php --daemon`) |
| `scripts/migrate_all.php` | Канонический раннер: на него указывают рантайм-сообщения PlanGenerationQueueService.php:302 и WorkoutShareCardCacheService.php:518, плюс check_password_reset.php:21, check_push.php:70 |
| `tests/Fixtures/synthetic_plan_eval_cases.php` | scripts/eval_plan_generation.php:57 |
| 15 cron-скриптов | crontab сервера: push_workout_reminders, strava_daily_health_check, cleanup_expired_refresh_tokens, process_notification_delivery_queue, process_notification_email_digest, polar_webhook_health, weekly_ai_review, daily_briefing, weekly_digest, goal_progress_snapshot, proactive_coach, process_strava_webhook_retries, post_workout_followups, weekly_plan_adaptation, suunto_auto_sync |

Особый случай: `scripts/push_race_countdown.php` — написан под cron (шапка файла: «Запуск (cron, раз в день)»), но в crontab ОТСУТСТВУЕТ и ниоткуда не вызывается. Либо фича молча не работает (нужно добавить в cron), либо скрипт в архив.

## 5. Полу-мёртвое (неиспользуемые параметры, мёртвые ветки)

Все пункты проверены по телам функций.

### Неиспользуемые параметры
- `$totalWeeks` — `_splitByMacrocyclePhases()`, planrun_ai/prompt_builder.php:2717 (в теле не встречается).
- `$sessions` — `getMinEasyKm()`, planrun_ai/prompt_builder.php:302 (присваивается и не используется).
- `$errorText` — `markEmailDigestItemsCompleted()` (services/NotificationSettingsService.php:940) и `rescheduleEmailDigestItems()` (:957). Хуже простого мусора: вызывающий код реально передаёт `'digest_send_failed'`, `$e->getMessage()`, `'quiet_hours'` (scripts/process_notification_email_digest.php:56,72,80), но тело методов их молча выбрасывает — текст ошибки нигде не сохраняется.
- `$payload`, `$inputs` — `PlanExplanationService::buildSummary()`, services/PlanExplanationService.php:116–138.
- `$trainingState` — `PlanGenerationProcessorService::applyPlanCritique()`, services/PlanGenerationProcessorService.php:1785–1852 (1 вхождение — только сигнатура).

### Мёртвые ветки / выражения
- `buildFormatResponseBlock($userData ?? $modifiedUser ?? null)` — prompt_builder.php:2657, 2834, 2952, 3439. `$userData` — всегда определённый параметр функции, поэтому `?? $modifiedUser ?? null` не выполняется никогда (а на :2657/:2834 `$modifiedUser` вообще не существует в области видимости).
- `$min === 0` в `_paceCheckEasy` (planrun_ai/validators/pace_validator.php:66) и `_paceCheckLong` (:84) — мёртв строго: `$min = max(150, …)` ⇒ всегда ≥150. `$max === 0` мёртв практически: `$max = min(600, X+20)` обнуляется только при X=-20.
- `liveNextSummarizeWeeks()` — scripts/live_next_plan_batch.php:131–140: foreach вычисляет `$weekNumber`/`$startDate` и делает `continue`, массив `$raceDays` объявлен и никогда не заполняется — цикл-пустышка.
- `callAIAPI_overridden` — scripts/dry_run_recalculate_prompt.php:26: функция определена через eval «для перехвата callAIAPI», но ни одного вызова нет (сам скрипт ниже идёт другим путём — комментарии :38–44 это признают).
- `AuthTest::test_login_with_valid_credentials` — tests/Feature/AuthTest.php:74–80: закомментирован целиком.

## Сводка

- **Подтверждено мёртвых: 20 символов (~755 строк)** — 10 глобальных функций (594 строки, из них prepareFullPlanAnalysis — 368) + 10 методов (161 строка). Бонус к плану: можно удалить весь workout_types.php с двумя require.
- **Жив только в тестах: 6 функций (~133 строки)** — семейство applyScheduleOverridesToUserData в prompt_builder.php (+2 теста при удалении).
- **Опровергнуто: 1 метод из 11 кандидатов** (enrichAggregateRow — динамический вызов через array_map) + подтверждена живость воркеров, migrate_all и 15 cron-скриптов.
- **Одноразовые/ручные скрипты: ~35 файлов** в scripts/ — кандидаты на перенос в архив по образцу _applied_migrations/ (миграции 3, сидеры 2, бэкфилы 4, check_* 7, ручные test_*/send_* 13, разовые ops 4, эксперименты PR8/live50 ~8); из них 1 сломан (test_weekly_review_for_user.php).
- **Полу-мёртвое: 12 пунктов** — 6 неиспользуемых параметров (включая молча теряемый `$errorText`), 4 мёртвые ветки, 1 cron-скрипт без cron-записи (push_race_countdown), 1 закомментированный тест.

Суммарно к безопасному удалению: **~888 строк** (755 + 133), плюс архивация ~35 ручных скриптов отдельным решением.
