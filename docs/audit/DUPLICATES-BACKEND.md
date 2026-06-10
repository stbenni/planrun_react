# Дубли: backend (верифицировано)

Источники: `docs/audit/_work/clones.txt` (95 PHP-групп, из них 30 целиком в tests/) + секция «Backend» из `docs/audit/_work/dup_candidates.md` (21 семантический кандидат). Каждый кандидат проверен по исходникам обоих фрагментов. Группы внутри `scripts/_applied_migrations` игнорированы по ТЗ.

---

## 1. Критичные дубли (риск рассинхрона / уже разошлись)

### 1.1. DDL уведомлений: `scripts/migrate_all.php` ↔ `NotificationSettingsService::ensureSchema()` — УЖЕ РАЗОШЛИСЬ
- Места: `planrun-backend/scripts/migrate_all.php:92-205` ↔ `planrun-backend/services/NotificationSettingsService.php:36-152` (7 таблиц `CREATE TABLE IF NOT EXISTS`, в clones.txt — 7 групп по 13–24 строки).
- Расхождение-баг: в `NotificationSettingsService.php:51` колонка `paused TINYINT(1) NOT NULL DEFAULT 0` есть, в `migrate_all.php:92-109` — НЕТ. На прод её добавила разовая миграция (`scripts/_applied_migrations/migrate_notifications_paused.php`), но bootstrap свежей БД через `migrate_all.php` создаст `notification_channel_settings` без `paused`; `ensureSchema()` делает только `CREATE IF NOT EXISTS` и колонку не добавит → `INSERT ... paused` (`NotificationSettingsService.php:471,486`) упадёт с SQL-ошибкой 1054 на свежей установке.
- Рекомендация: единый источник DDL — оставить схему в одном месте (либо `ensureSchema()` сервиса как канон и вызывать его из `migrate_all.php`, либо наоборот) и синхронизировать `paused` в `migrate_all.php` немедленно.

### 1.2. `paceFromKmAndMinutes` ×4 — форк с багом и с TypeError-минами
- Места: `providers/CorosProvider.php:437` (эталон, `?string`, корректный перенос `$s = 0; $m++`), `providers/GarminProvider.php:357`, `providers/PolarProvider.php:229`, `utils/GpxTcxParser.php:426`.
- Расхождения:
  - **Баг** в `GpxTcxParser.php:431`: `if ($s >= 60) { $s += 60; $m++; }` вместо `$s = 0` — при округлении секунд до 60 темп отрисуется как «5:120» вместо «6:00».
  - Garmin/Polar/GpxTcx объявлены `: string`, но содержат `return null;` при `km <= 0` → латентный `TypeError` (сейчас спасает только guard `$distanceKm > 0` на вызывающей стороне). В Coros тип уже исправлен на `?string`, в остальных — нет.
- Рекомендация: один статический хелпер (например `utils/PaceUtils::fromKmAndMinutes(float, int): ?string`), все 4 копии удалить; багфикс GpxTcx — при выносе.

### 1.3. `register_api.php` ↔ `complete_specialization_api.php` — форк ~200 строк с общим латентным багом
- Места: `planrun-backend/register_api.php:174-393` ↔ `planrun-backend/complete_specialization_api.php:95-262` (7 групп в clones.txt по 13–27 строк: парсинг ~35 полей онбординга + матрица условной валидации по goal_type/training_mode) + CORS-блок и `planrunEnsureSessionStarted` (строки 10-50 обоих файлов).
- Расхождения:
  - Оба файла используют **неопределённую** переменную `$targetMarathonDate` в валидации (`register_api.php:352,357`; `complete_specialization_api.php:222,227` и присваивание самой себе в `:264`) — ветки «или целевую дату» мертвы, фактически для race/time_improvement обязателен только `race_date`. Общий унаследованный баг форка.
  - `register_api` парсит `birth_month` и `timezone` — `complete_specialization_api` их **не** принимает (повторный онбординг теряет эти поля); `complete_specialization_api` парсит `first_name`/`last_name`, которых нет в register.
  - `complete_specialization_api.php:345-397` инлайнит логику, для которой уже есть канон в `RegistrationService::createInitialTrainingPlan()` (`:497`) и `startPlanGeneration()` (`:526`) — расчёт plan_date/plan_time, `is_active = (mode==='ai' ? 0 : 1)`, enqueue + те же три сообщения. Третья копия этих правил.
- Рекомендация: вынести парсинг+валидацию профиля онбординга в сервис (например `OnboardingInputService`), `complete_specialization_api` перевести на `RegistrationService::createInitialTrainingPlan/startPlanGeneration`; решить судьбу `$targetMarathonDate` (удалить мёртвые ветки или вернуть поле).

### 1.4. Тройной каталог уведомлений (14 event_key × 3 места)
- Места: `services/NotificationTemplateService.php:241-346` (`getDefaultRuntimeTemplate` — title/link/push_data per event), `:348+` (`getEditableDefinitionMap` — шаблоны с плейсхолдерами), `services/NotificationSettingsService.php:165+` (`getEventCatalog` — label/description/channels).
- Три параллельных match/map по одним и тем же ключам (`workout.reminder.today`, `chat.*`, `plan.*`, `coach.*`, `performance.vdot_updated`). Новое событие требует трёх синхронных правок; рассинхрон даст событие без дефолтного шаблона или без настройки у юзера, причём молча (default-ветка).
- Рекомендация: один реестр событий (константный массив `NotificationEventRegistry` с полями label/description/title/link/template/placeholders), из которого собираются все три представления.

### 1.5. `ChatContextBuilder::getRecentWorkouts` ↔ `getWorkoutsHistory` — форк UNION-запроса, потеряна дедупликация
- Места: `services/ChatContextBuilder.php:541-599` ↔ `:1204-1260` (~50 строк SQL `workout_log UNION ALL workouts` + join плановых дней).
- Расхождение: в `getRecentWorkouts` импортные тренировки фильтруются `NOT EXISTS (... workout_log wl2 ...)` (`:596-599`) — исключение дублей «ручная запись + импорт за один день». В `getWorkoutsHistory` (`:1256-1258`) этого условия **нет** → история для AI-чата может содержать одну тренировку дважды (раздув объёмов в контексте LLM). Также во второй версии нет `max_heart_rate`/`is_completed`.
- Рекомендация: общий приватный билдер SQL с параметрами (период, дедуп, поля); поведение `NOT EXISTS` распространить на history-вариант (проверить, не опирается ли кто-то на текущее поведение).

### 1.6. `plan_generator.php`: тройная загрузка пользователя
- Места: `planrun_ai/plan_generator.php:36-80` (generate) ↔ `:355-393` (recalculate) ↔ `:712-750` (next plan) — байт-в-байт одинаковые SELECT из 30+ полей `users` + декод `preferred_days`/`preferred_ofp_days` (3 группы в clones.txt: 24/23/21/17 строк). Четвёртая урезанная копия — `scripts/dry_run_recalculate_prompt.php:50-66` (`SELECT *` + тот же декод, осознанная симуляция).
- Риск: новое поле профиля (как уже случилось с `birth_month`/`timezone` в регистрации) надо добавлять в 3 SELECT-а; пропуск одного — молчаливо обнулённое поле в одном из трёх режимов генерации.
- Рекомендация: `function loadUserForPlanGeneration(mysqli $db, int $userId): array` в том же файле; dry_run-скрипт перевести на неё же.

### 1.7. VDOT из easy-pace: разные константы в двух копиях формулы
- Места: `planrun_ai/prompt_builder.php:212-219` (`calculatePaceZones`: `$easyVO2 / 0.66`) ↔ `:680-692` (`assessGoalRealism`: `$easyVO2 / 0.68`).
- Один и тот же расчёт «VDOT по комфортному темпу» даёт разный результат в зонах темпа и в оценке реалистичности цели (~3% расхождение VDOT для одного юзера). Не факт, что осознанно — комментарии в обеих копиях называют одно и то же («easy ≈ 66-70% VO2max»).
- Рекомендация: одна функция `estimateVdotFromEasyPace(int $easySec): ?float` с одной константой (выбрать и зафиксировать).

### 1.8. `NoteController::saveDayNote` ↔ `saveWeekNote` — форк, потеряно уведомление
- Места: `controllers/NoteController.php:45-90` ↔ `:143-185` (валидация/права/update-create идентичны).
- Расхождение: day-версия после создания зовёт `$this->notifyAthleteIfCoach('note', $date)` (`:84`), week-версия (`:179-180`) — **нет**: атлет не получает уведомление о недельной заметке тренера. Похоже на пропуск при копировании, а не на решение.
- Рекомендация: общий приватный `saveNote(... $isWeek)` либо хотя бы добавить notify в week-ветку.

---

## 2. Точные копии — кандидаты на вынос в общий хелпер

| Что | Где (все копии) | Куда выносить | ~строк экономии |
|---|---|---|---|
| Token-CRUD провайдеров: `isConnected`/`disconnect`/`getTokenRow`/`saveTokens` (идентичный SQL по `integration_tokens`) | CorosProvider:451-488, GarminProvider:371-408, SuuntoProvider:659-696, PolarProvider:295-330 (saveTokens урезан), HuaweiHealthProvider:315-345 (без external_id) | абстрактный `AbstractOAuthProvider` или трейт `IntegrationTokenStorage` | ~150 |
| `resolveBotToken()` + `loadExternalBotConfig()` | TelegramLoginService.php:37-76 ↔ TelegramMiniAppService.php:148-184 (разница только в форматировании foreach) | общий `TelegramBotConfig` (static) | ~40 |
| `normalizeProse` ≡ `normalizeLlmProse` (стрип markdown/буллетов из LLM-ответа) | ProactiveCoachService.php:719-736 ↔ WorkoutService.php:654-675 | `utils/LlmTextUtils::normalizeProse()` | ~20 |
| `tableExists()` с кешем | WorkoutService.php:2214-2233 ↔ WorkoutShareCardCacheService.php:524-545 | трейт `ChecksTableExistence` | ~20 |
| Компаратор issues (severity→week→code) | planrun_ai/plan_validator.php:21-40 ↔ services/PlanQualityGate.php:726-745 (`sortIssues`) | функция в plan_validator.php, PlanQualityGate вызывает её | ~20 |
| `isRunningRelevantManualActivity` ≡ `isRunningRelevantImportedActivity` (байт-в-байт) | PlanGenerationProcessorService.php:1510-1517 ↔ :1519-1526 | оставить одну `isRunningRelevantActivity()` | ~8 |
| Отправка sendMessage в Telegram API (payload+curl+лог ошибки) | TelegramLoginService.php:309-336 ↔ :363-390 | приватный `postSendMessage(int $chatId, string $html): bool` | ~25 |
| Литерал «день → rest» + `$dayNames` | planrun_ai/plan_normalizer.php:1073-1086 ↔ :1092-1105 | хелпер `makeRestDay($date, $dow)` | ~12 |
| Блок «Последние тренировки» в промпте | planrun_ai/prompt_builder.php:3259-3274 ↔ :3550-3565 (отличие — слово «(факт)» в заголовке) | `formatRecentWorkoutsBlock(array $recent): array` | ~14 |
| Парсинг `H:MM:SS|M:SS → сек` (3 строки × 8 вхождений) | prompt_builder.php:203-206, 225-228, 261-264, 665-671, 701-707, 1718+, 1807+, 1990 | `parseTimeToSeconds(string): int` рядом с `formatTimeSec` (:463) | ~25 |
| catch-блок «password_reset_tokens missing» | controllers/AuthController.php:239-253 ↔ :286-300 | приватный `handlePasswordResetException(Throwable $e)` | ~14 |
| Валидация чат-сообщения (empty/4000 chars) | controllers/ChatController.php:80-93, 247-260, 344-357, 584-597 | приватный `validateChatMessageInput(...): ?string` | ~30 |
| `findActiveJobForUser` ⊂ `findLatestActiveJobForUser` (тот же WHERE, у́же SELECT) | PlanGenerationQueueService.php:282-297 ↔ :245-260 | private-версию удалить, вызывать публичную | ~15 |
| prepare/bind/execute/fetch-цикл, дублирующий собственный `fetchAll()` | AiPlanGenerationEventLogger.php:141-160 ↔ :452-471 | использовать `$this->fetchAll()` + декод JSON после | ~15 |
| `parseArgs` CLI-скриптов ×6 + `resolveUser` ×2 | dry_run_coaching_prompt.php:29/47, live_generate_one_user.php:28/45, inspect_ai_runtime.php:97, live_next_plan_batch.php:22, live_recalculate_batch.php:24, live_plan_generation_batch.php:29 | `scripts/lib/cli_helpers.php` | ~90 |
| Копирование дня с упражнениями (вставка дня + цикл по exercises) | services/WeekService.php:283-312 (`copyDay`) ↔ :367-396 (`copyWeek`) | приватный `copyDayRecord(array $day, int $weekId, int $dow, string $date, int $userId)` | ~30 |

Итого по точным копиям: **~530 строк**; вместе с разделом 1 (онбординг ~180, DDL ~120, SQL-билдер чата ~50) — **~850-900 строк**.

---

## 3. Параллельные реализации одной задачи

1. **`CoachService::listCoaches` (живая) vs `UserRepository::listCoaches` (мёртвая)** — `services/CoachService.php:24-98` и `repositories/UserRepository.php:349+`. Роут `api_v2.php:855 → CoachController → CoachService`; репозиторная версия не вызывается никем (docblock в репозитории врёт, что используется сервисом). Канон — CoachService; правильный рефакторинг: перенести SQL в UserRepository и заставить сервис вызывать его, либо удалить репозиторную копию как мёртвый код.
2. **OAuth-флоу провайдеров (Coros/Garmin/Suunto/Polar/Huawei)** — `requestToken`/`refreshToken`/`fetchWorkouts` повторяют скелет «curl POST form → json → save tokens» (группы Coros:218-229↔Garmin:174-185, Coros:273-295↔Garmin:220-242, Garmin:93-108↔163-178, Huawei:56-69↔99-112 и т.д.). Канон по гибкости — CorosProvider (поддержка basic/body client auth, настраиваемые пути). Рекомендация: базовый класс с шаблонным методом `exchangeToken(array $body): array` — вместе с token-CRUD из раздела 2 это закроет ~8 клон-групп.
3. **Маппинг полей тренировки провайдеров** — `Coros:395-414` ↔ `Garmin:315-334` ↔ `Suunto:515-529` возвращают идентичный массив workout-DTO (отличны только имена исходных ключей API). Рекомендация: value-object/билдер `ImportedWorkout::toArray()`, провайдер заполняет только специфичные поля.
4. **Webhook-приём: `api/coros_workout_push.php:60-104` ↔ `api/suunto_webhook.php:90-130`** — общий скелет «resolve user по external_athlete_id → ответить 200 → fastcgi_finish_request → импорт». Различие осознанное (Suunto маппит один workout из FIT, Coros перефетчивает 14 дней), но резолв юзера и early-ack стоит вынести в `api/_webhook_common.php`.
5. **QA-батчи генерации: `live_plan_generation_batch` / `live_recalculate_batch` / `live_next_plan_batch`** — next-batch подтверждён как урезанный форк (fetchUsers/issue/bool/счётчики/markdown-репорт байт-в-байт, 7 клон-групп). Найдено расхождение: SQL выборки дней в recalculate-версии содержит `AND e.user_id = d.user_id` в JOIN (`live_recalculate_batch.php:183`), в generation-версии (`live_plan_generation_batch.php:347`) — нет. Все три гоняют боевой `PlanGenerationProcessorService`, так что продакшн не задет; рекомендация — `scripts/lib/live_batch_helpers.php`.
6. **`eval_plan_generation.php`: user-ветка vs synthetic-ветка** — `evalBuildFirstPassArtifact`/`evalBuildFullArtifact` (:107-171) ≈ `evalBuildSynthetic*` (:173-260): один пайплайн prompt→callAIAPI→normalize→validate→summary. Канон — параметризованный билдер с источником юзера (id | synthetic case).
7. **Два пути запуска пересчёта**: боевой `scripts/run_recalculate_for_user.php` (enqueue → worker) и QA `live_recalculate_batch.php` (прямой вызов процессора) — оба сходятся в `PlanGenerationProcessorService`, рассинхрон движка исключён; дублируется только обвязка (см. п.5).
8. **`test_weekly_review_for_user.php` ↔ `weekly_ai_review.php`** — тест-скрипт повторяет внутренний пайплайн ревью (prepareWeeklyAnalysis → enrichment → generateWeeklyReview → отправка), при том что в `weekly_ai_review.php:37` уже есть bypass `WEEKLY_REVIEW_FORCE_USER`. Канон — weekly_ai_review; тест-скрипт можно удалить или свести к вызову с env.
9. **3 форматтера темпа `сек → M:SS`**: `prompt_builder.php:289 formatPace`, `:456 formatPaceSec`, `plan_validator.php:70 validatorFormatPaceSec` — идентичны. Канон — `formatPaceSec` (типизирован); остальные → алиасы/удаление. (Плюс `paceFromKmAndMinutes` из раздела 1.2 — отдельная семантика «км+мин → темп».)
10. **`_paceCheckEasy` ↔ `_paceCheckLong`** (`planrun_ai/validators/pace_validator.php:64-96`) — идентичная логика, отличаются только ключи правил (`easy_*`/`long_*`) и код issue. Семантика совпадает (приоритет `&&` над `||` делает отсутствие скобок в easy-версии безвредным). Канон — параметризованный `_paceCheckRange($paceSec, $rules, 'easy'|'long', ...)`.
11. **`computeMacrocycle`/`formatMacrocyclePrompt` ↔ `computeHealthMacrocycle`/`formatHealthMacrocyclePrompt`** (`prompt_builder.php:1008/1518/1326/1476`) — параллельные структуры фаз для race- и health-целей. Доменная логика действительно разная (фазы adapt/develop/maintain vs base/build/peak/taper), полное слияние не нужно, но формат вывода и recovery-week-логика дублируются — выносить общие куски по мере правок.
12. **`NotificationSettingsService`: `isInQuietHours(userId)` (:710) ↔ `isInQuietHoursFromSettings(settings)` (:1715)** — два обёрточных пути к общему `isInQuietHoursAtTime`; первый должен просто вызывать второй с `getSettings($userId)`. Аналогично `normalizeTime` (:1562) ↔ `normalizeStoredTime` (:1574) — отличие только в опциональном `:SS`; объединить регэкспом `(?::\d{2})?`.
13. **`fetch workout по (source_kind, source_id)` из workouts|workout_log** — `PostWorkoutFollowupService.php:595-634` ↔ `WorkoutService.php:213-266` (`fetchWorkoutAnalysisSummary`), а также lookup follow-up-строки `PostWorkoutFollowupService::getFollowupBySource` (:463) ↔ `WorkoutService::getPostWorkoutFollowupRow` (:149, +поле `analysis_message_id`). Оба сервиса работают с одной таблицей `post_workout_followups` — кандидат на общий `PostWorkoutFollowupRepository`.

---

## 4. Осознанные/допустимые дубли

- **Все 30 клон-групп в `planrun-backend/tests/`** (PlanQualityGateTest ×7, PlanSkeletonBuilderTest ×3, TrainingStateBuilderTest ×4, PostWorkoutFollowupServiceTest ×3, PlanGenerationQueueService/Registration/WorkoutPlanRecalculation setup ×3, PlanNormalizerTest ×2, PlanScenarioResolverTest, PlanGeneratorCorrectivePassTest, AthleteSignalsService/MetricsService/TrainingStateBuilder fixtures, CoachEvents/CoachTemplate setup, PlanSkeletonBuilderTuneUpRace, test_chat_fixes↔test_chat_tools, golden_plan_policy_cases fixture) — повторяющиеся fixture/arrange-блоки; читаемость тестов важнее DRY. Не рефакторить (максимум — общие builder-хелперы по мере правок).
- `scripts/ai_runtime_smoke.php:115-131` ↔ `tests/Unit/PlanGenerationProcessorServiceTest.php:471-487` — smoke-скрипт намеренно повторяет сценарий теста.
- `scripts/migrate_executed_exercises.php:17-48` ↔ `ExecutedExerciseService::ensureSchema()` (:230-257) — DDL идентичен (отличия косметические `NULL DEFAULT NULL`); та же пара «миграция + runtime ensure», что и в п.1.1, но пока БЕЗ расхождений. Допустимо, однако это тот же паттерн, который уже выстрелил с `paused` — при изменении схемы менять оба места.
- Крон-обёртки `scripts/daily_briefing.php` / `proactive_coach.php` / `weekly_digest.php` (бойлерплейт env+db+флаг `PROACTIVE_COACH_ENABLED`) и пара `run_weekly_adaptation_for_user.php` / `weekly_plan_adaptation.php` — тонкие энтрипойнты, вся логика в `ProactiveCoachService`/`WeeklyPlanAdaptationService`. Норма для cron-скриптов.
- `GarminProvider::requestToken` (:93-127) ↔ `refreshToken` (:152-190), `HuaweiHealthProvider` (:56-86 ↔ :99-130) — стандартная пара OAuth-флоу внутри одного класса; сольётся сама при выносе базового провайдера (раздел 3.2).
- `AthleteSignalsService.php:89-110` ↔ `:370-394` — список дефолтных ключей метрик в `buildNoteMetrics` и `buildEmptySignalsSummary`; терпимо, но при добавлении метрики менять оба (можно `EMPTY_NOTE_METRICS` константой).

## 5. Ложные срабатывания

- `api/complete_specialization_api.php` vs `planrun-backend/complete_specialization_api.php` — не дубль: api-файл это 14-строчная обёртка (CORS+session), делает `require` бэкендового файла.
- `strava_register_webhook.php` ⊂ `strava_daily_health_check.php` и `polar_register_webhook.php` ⊂ `polar_webhook_health.php` — кандидаты из фазы 1 уже не актуальны: все четыре скрипта делегируют в `$provider->ensureWebhookSubscription()`, дублирования логики нет.
- `PlanNotificationService::getUnread` (:114) vs `getRecent` (:139) — разная семантика (непрочитанные vs лента за N дней), разные WHERE и поля; общий только тривиальный цикл маппинга. Не сливать (сольётся хуже, чем есть).
- `complete_specialization_api.php:204-229` ↔ `register_api.php:326-359` детектор посчитал отдельной группой — это часть того же форка из п.1.3, не самостоятельный дубль.
- `WeekService.php:289-315↔373-399` и `:304-317↔388-401` — две группы детектора по одному и тому же дублю copyDay/copyWeek (учтены один раз, раздел 2).
- `TelegramLoginService:52-68` ↔ `TelegramMiniAppService:163-178` — под-диапазон группы из раздела 2 (resolveBotToken/loadExternalBotConfig), не отдельный случай.

---

## Сводка

| Метрика | Значение |
|---|---|
| Групп проверено | 86 (65 продакшн-групп из clones.txt + 21 семантический кандидат из dup_candidates; 30 тестовых групп сведены строкой) |
| Критичных (рассинхрон/уже разошлись) | 8 (раздел 1; из них 3 с подтверждённым багом: DDL `paused`, перенос секунд в GpxTcxParser, потерянный NOT EXISTS в истории тренировок чата) |
| Точных копий — кандидатов на вынос | 16 семейств (раздел 2) |
| Параллельных реализаций | 13 (раздел 3) |
| ~строк потенциальной экономии | ~850-900 |
