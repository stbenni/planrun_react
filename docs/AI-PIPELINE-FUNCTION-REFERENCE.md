# AI Pipeline — Function Reference & Glossary

Опорная карта для точных фиксов по [CODE-AUDIT-AI-PIPELINE.md](./CODE-AUDIT-AI-PIPELINE.md) / [FIX-PLAN-AI-PIPELINE.md](./FIX-PLAN-AI-PIPELINE.md).
Покрывает 36 файлов AI-пайплайна (~23 500 строк). Сигнатуры извлечены 2026-05-15, описания — из построчного аудита.

---

## ЧАСТЬ 1 — ГЛОССАРИЙ ПРОЕКТА

### Спортивная наука / тренерская методика

| Термин | Значение |
|---|---|
| **VDOT** | Метрика аэробной формы (Jack Daniels). Выводится из лучшего результата на дистанции (`estimateVDOT`). Чем выше — тем быстрее бегун. Из VDOT выводятся все тренировочные темпы. |
| **Training paces** | Целевые темпы по зонам, производные от VDOT: easy, marathon, threshold (tempo), interval, repetition. Считаются в `getTrainingPaces`. |
| **easy** | Лёгкий бег, ~65% VO2max, разговорный темп. Основа объёма. |
| **long** | Длительный бег. Всегда отдельный type (не easy). 1 на тренировочную неделю. Доля ≤35% недельного объёма (`long_share_cap`). |
| **tempo** | Темповый/пороговый бег (~85-88% VO2max, lactate threshold). subtype=`race_pace` = в темпе гонки. |
| **interval** | Интервальная: warmup + N×(interval_m в interval_pace) + rest_m + cooldown. ~97% VO2max. |
| **fartlek** | Игра скоростей: массив `segments[]` с разными темпами (fast/recovery/tempo). |
| **control** | Контрольная прикидка (B-race). AI ставит сам, может модифицировать. |
| **race** | Забег. Главный (`race_date`) или промежуточный (`intermediate_races`). **distance_km = ручной ввод пользователя, защищён** от перезаписи AI. |
| **sbu** | Специальные беговые упражнения (СБУ): «Название — 30 м» построчно. |
| **other / ОФП** | Силовые/функциональные. Не занимает беговой слот, ставится в rest-дни в `preferred_ofp_days`. |
| **rest** | Полный отдых. |
| **free** | «Свободный день» (можно добавить тренировку). Не «отдых». Используется в self-режиме (`create_empty_plan`). |
| **ACWR** | Acute:Chronic Workload Ratio. Острая нагрузка (7д) / хроническая (28д, нормированная на неделю). Зоны: <0.8 low, 0.8-1.3 optimal, 1.3-1.5 caution, >1.5 danger. Считается `ChatContextBuilder::calculateACWR` через sRPE. |
| **sRPE** | Session RPE = duration_min × intensity_factor, где factor = (6 − rating)/5 (rating 1=тяжело → 1.0, 5=легко → 0.2). Fallback при отсутствии rating: distance × 6. |
| **ATL / CTL / TSB** | Acute/Chronic Training Load + Training Stress Balance (TSB = CTL − ATL). >25 свежесть, −10..5 оптимум, <−30 перетренированность. Считает `TrainingLoadService`. |
| **TRIMP** | Training Impulse — нагрузка по ЧСС. Поле `workouts.trimp`. |
| **macrocycle** | Периодизация плана по фазам. `computeMacrocycle` (race) / `computeHealthMacrocycle` (health). |
| **phase** | Фаза макроцикла: `pre_base`/`adaptation` → `base` → `build`/`development`/`maintenance` → `peak` → `taper` → `race`. У каждой `max_key_workouts`. |
| **recovery week** | Разгрузочная неделя (75-85% от предыдущего объёма), каждые 3-4 недели прогрессии. `is_recovery=true`. |
| **taper** | Подводка перед стартом: marathon 3 нед, half 2 нед, 5k/10k 1 нед. Снижение объёма по убыванию. |
| **peak volume** | Пиковый недельный км. `load_policy.peak_volume_floor_km` ± 10%. Якорь генерации. |
| **peak long** | Пиковая длительная за 2-3 нед до старта: 5k→12-15, 10k→14-18, half→19-24, marathon→28-32 км. |
| **detraining** | Потеря формы при паузе. `calculateDetrainingFactor(daysSince, level)` снижает эффективный VDOT. |
| **goal realism** | Оценка достижимости цели (`assessGoalRealism`). verdict: realistic/challenging/caution/unrealistic; severity: none/minor/major. |
| **pace_strategy** | «Мост к цели» (PR9). mode=realistic_target если цель недостижима за цикл; goal_paces (Daniels от целевого VDOT) vs current_paces; effective_target_time. |

### Сценарии / флаги планирования

| Термин | Значение |
|---|---|
| **goal_type** | `health` / `weight_loss` / `race` / `time_improvement` / `distance`. Определяет интенсивность и структуру. |
| **planning_scenario** | Результат `PlanScenarioResolver::resolve`: `{primary, flags[], schedule_anchor_date, race_position, tune_up_event, policy_decisions[]}`. |
| **scenario flags** | `short_runway_taper` (≤3 нед до гонки), `short_runway_long_race`, `b_race_before_a_race`, `return_after_injury`, `return_after_break`, `low_confidence_start`, `overload_recovery`, `pain_protective`, `illness_protective`, `high_caution`, `explicit_tune_up_event`. |
| **special_population_flags** | `pregnant_or_postpartum`, `return_after_injury`, `older_adult_65_plus`, `chronic_condition_flag`, `recent_pain_signal`, `recent_fatigue_spike`, `recent_illness_signal`, `recent_sleep_signal`, `recent_stress_signal`, `recent_travel_signal`, `low_confidence_vdot`. Ограничивают quality-сессии. |
| **readiness** | `low` / `normal` / `high`. Влияет на allowed_growth_ratio и caps. |
| **feedback_guard_level** | `neutral` / `fatigue_high` / `pain_protective` / `illness_protective`. Из feedback-аналитики. |
| **cohort** | Производный признак для метрик: pregnant > injury_return > pain_signal > illness_signal > unrealistic_goal > healthy (`AiPlanGenerationEventLogger::deriveCohort`). |
| **tune-up event / B-race** | Промежуточный старт. Если за 5-10 дней до главного и дистанция < главной → `b_race_before_a_race` → форсится в `control`. |
| **load_policy** | Параметры нагрузки: allowed_growth_ratio, long_share_cap, recovery_weeks, quality_mode, peak_volume_floor_km, easy_min_km, и т.д. Строится в `TrainingStateBuilder`. |
| **training_state** | Главный объект состояния атлета: vdot, paces, readiness, special_population_flags, planning_scenario, load_policy, goal_realism, intermediate_races, recent_compliance, season, best_races. Строит `TrainingStateBuilder::buildForUser`. |

### AI-инфраструктура

| Термин | Значение |
|---|---|
| **LlmGateway** | Статический фасад для DeepSeek/OpenAI-API: rotation ключей, retry+backoff, concurrency limiter (MySQL GET_LOCK + leases), observability. |
| **purpose** | `chat` / `plan` — определяет какие env-ключи и лимиты использовать. |
| **thinking mode** | DeepSeek reasoning. `withThinkingMode(payload, baseUrl, enable)`. Для deepseek-reasoner. |
| **FACTS_JSON** | Полный контекст для DeepSeek-планировщика (training_state + hard_rules + calendar_weeks + user). Передаётся как есть (128k window). |
| **hard_rules** | Medical-only инварианты: required/allowed run days, race date/distance, language_contract, long_run_safety, fresh_long_effort_guard. |
| **calendar_weeks** | Skeleton всех weeks×7 дней с метками: date, days_to_race, is_race_date, is_run_day, is_past, suggested_default, race_proximity. |
| **race_proximity** | Семантический ярлык: race_day / pre_race_day_minus_1 / pre_race_taper / post_race_recovery_day_1 / post_race_recovery_day_2 / null. |
| **PlanQualityGate** | Финальная проверка перед save: normalize → repairs → validators → scenario issues → blocking policy. status: ok/warning/blocked. |
| **blocking_policy** | `strict` (любой error блокирует save) / `permissive` (только fatal codes блокируют). Авто-выбор по cohort риска. |
| **quality gate mode (auto)** | strict для рисковых когорт (pregnant/injury/pain/illness/unrealistic), иначе permissive. |
| **deterministic repairs** | Не-LLM правки: pace, workout-detail fallbacks, load, minimum-distance. В `plan_normalizer.php`. |
| **hard safety repairs** | Medical: marathon long >32км в последний 21 день → cap; long_share > cap → cut. |
| **self-critique** | Независимый LLM-pass «opposing coach» (`runPlanSelfCritique`) → при should_revise → `revisePlanWithCritique`. |
| **OFP enricher** | Отдельный LLM-вызов подбирает ОФП-упражнения из exercise_library под нагрузку недели. Fallback — template. |
| **prefix cache** | DeepSeek KV-cache: байты от token-0 должны совпадать между запросами. Поэтому даты — в КОНЕЦ user-сообщения, не в system. |
| **observability** | `ai_runtime_events` (generic, `AiObservabilityService`) + `ai_plan_generation_events` (plan-specific, `AiPlanGenerationEventLogger`). |
| **trace_id** | Связывает события одной генерации/чата. Формат `{surface}-{12hex}`. |
| **concurrency lease** | Строка в `llm_gateway_locks` с TTL. Лимитирует параллельные LLM-вызовы per purpose. |

### Источники данных (таблицы)

| Таблица | Содержание |
|---|---|
| `users` | Профиль: goal_type, race_*, preferred_days, preferred_ofp_days, weekly_base_km, vdot-источники, last_plan_summary. |
| `training_plan_weeks` → `training_plan_days` → `training_day_exercises` | Сохранённый план. day.type/description/is_key_workout/date. |
| `workout_log` | Ручные отметки выполнения («Отметить»). is_completed=1. |
| `workouts` | Импорт со Strava/Garmin/часов. start_time, activity_type, avg_pace, trimp. |
| `chat_conversations` → `chat_messages` | Чат. sender_type ∈ {`user`,`ai`,`admin`} (**не** `assistant`!). |
| `chat_user_memory` | content (долговременная память) + history_summary (сжатая старая история). |
| `daily_wellness` | sleep_quality/mood/soreness/stress/energy/last_workout_rpe (1-5, RPE 1-10). |
| `plan_day_notes` / `plan_week_notes` | Заметки атлета (анализирует `AthleteSignalsService`). |
| `post_workout_followups` | Followup-чек-ины: status, classification, pain_flag, recovery_risk_score. |
| `plan_readiness_checkins` | Stale-pain check перед recalculate. |
| `plan_generation_jobs` | Очередь генерации. status: pending/running/completed/failed. |
| `proactive_coach_log` | Cooldown проактивных сообщений (user_id, event_type, created_at). |
| `ai_runtime_events` / `ai_plan_generation_events` | Observability. |
| `llm_gateway_locks` | Concurrency leases. |

### Контракт дня плана (normalized day)

```
{ type, description, day_of_week (1-7), date (Y-m-d), is_key_workout,
  distance_km, pace, duration_minutes, subtype (null|race_pace),
  warmup_km, cooldown_km, tempo_km,                       // tempo/intervals
  reps, interval_m, interval_pace, rest_m, rest_type,     // intervals
  segments[],                                              // fartlek
  notes, exercises[] }
```

### Job types

- `generate` — первичная генерация (онбординг).
- `recalculate` — пересчёт: сохраняет прошлые недели до cutoff_date, заменяет текущую+будущие. kept_weeks, mutable_from_date.
- `next_plan` — новый цикл после завершения. cutoff_date = понедельник.

---

## ЧАСТЬ 2 — FUNCTION REFERENCE

> Формат: `строка: сигнатура` — описание. Файлы сгруппированы по фазам аудита.

### Phase 1 — Chat подсистема

#### `controllers/ChatController.php`

- `36 getLatestProactiveMessage()` — GET последнего проактивного инсайта (daily_briefing/weekly_digest) за окно часов для dashboard hero.
- `53 getMessages()` — пагинированный список сообщений (ai|admin), + pending_ai_response state.
- `76 sendMessage()` — non-stream AI-ответ. Rate-limit + sanitize ≤4000 + releaseSessionLock.
- `111 enforceChatRateLimit(): bool` — анти-спам: 30/мин + min 2с gap. **Fail-open** при ошибке prepare.
- `148 sendMessageStream()` — NDJSON-стриминг. `ignore_user_abort(true)` (ответ сохраняется даже если юзер ушёл).
- `189 clearAiChat()` — удалить историю AI + history_summary.
- `204 sendMessageToAdmin()` / `261 sendMessageToUser()` / `295 sendAdminMessage()` / `427 broadcastAdminMessage()` — мессаджинг.
- `382 getAdminUnreadNotifications()` — непрочитанные от юзеров (для админа), fail-soft (пустой массив при ошибке).
- `459/474/489/512 markAllRead/markAdminAllRead/markRead/markAdminConversationRead()` — read receipts.
- `535 addAIMessage()` — admin-only досыл AI-сообщения юзеру.

#### `services/ChatService.php` (оркестратор)

- `61 tryHandlePostWorkoutFollowupReply(userId, convId, msgId, content): ?array` — короткий путь: если ждём followup-ответ — обработать его, минуя LLM.
- `79 persistPostWorkoutFollowupReply(userId, convId, followupReply): int` — сохранить followup-ответ AI + push + memory extraction.
- `112 checkLlmHealth(): bool` — GET /models (3с timeout). **#4: вызывается на каждый stream.**
- `140 applyHistorySummarization(userId, convId, &history)` — при ≥35 сообщений сжать старые в summary.
- `156 summarizeOlderMessages(messages, userId): string` — LLM-вызов для сжатия истории (≤500 симв).
- `202 sendMessageAndGetResponse(userId, content): array` — **non-stream flow. #2: нет confirmation/memory/health.** **#1 (исправлен): message_id из addMessage.**
- `243 streamResponse(userId, content): void` — **stream flow.** Полный pipeline: context → prompt → confirmation → health → tool loop → think-buffered stream → sanitize → persist → memory.
- `381 triggerMemoryExtraction(userId, convId)` — non-blocking запуск ChatMemoryManager (последние 20 сообщений).
- `393 resolveToolCalls(messages, userId, &toolsUsed): array` — tool loop для stream (до 5 раундов), стриминг marker `tool_executing`.
- `443 callLlm(messages, userId): array` — non-stream LLM + tool loop (до 3 раундов, env CHAT_MAX_TOOL_ROUNDS). **#3: $toolsUsedAccum до объявления.**
- `516 callLlmDirect(messages, tools, userId): array` — один LLM-вызов через LlmGateway.
- `540 callPlanRunAIChat / 706 callPlanRunAIChatStream` — fallback на legacy PlanRun AI (**#5: хрупкий URL transform**).
- `556 callLlmStream / 573 callLlmStreamDirect` — SSE-стриминг через cURL WRITEFUNCTION + concurrency lease.
- `691 logLlmStreamEvent(...)` — observability стрима.
- `741 getLatestProactiveMessage / 751 getMessages / 768 clearAiChat / 774 markAsRead` — messaging CRUD.
- `779-916` — sendUserMessageToAdmin/User, sendAdminMessage, getDirectMessagesWithUser, broadcastAdminMessage, dispatchNotificationEvent, getAdminUserIds.
- `834 addAIMessageToUser(userId, content, opts): array` — программная отправка AI-сообщения (используется ProactiveCoach, plan review).

#### `services/ChatContextBuilder.php` (сбор контекста)

- `23 formatPlanHistoryAnalyses(userId): string` — блок «история тренировок» из WorkoutAnalysisRepository (rollup + key workouts + детально).
- `60 buildContextForUser(userId): string` — **главный**: профиль + plan-summary + stats + coaching insights + recent activity + wellness + memory + history summary.
- `93 getUserMemory / 106 getHistorySummary / 120 setHistorySummary / 136 setUserMemory` — chat_user_memory CRUD.
- `145 formatProfile(user): string` — секция профиля (пол/возраст/уровень/цель/темп/дни).
- `251 formatTimeForPrompt(time): string` — HH:MM:SS → «X ч Y мин».
- `260 formatPlanSummary(plan, userId): string` — текущая неделя плана (**#49: цикл по неделям**).
- `340 getStats(userId): array` — выполнено/всего дней плана + %.
- `386 formatLatestPlanGeneratorSummary(user): string` — оценка плана от генератора (risk_review + critique) для цитирования в чате.
- `451 formatRecentActivity(userId): string` — последние 3 тренировки + дни без отдыха (**#51: `⚠` стрипается parser'ом**).
- `487 formatRecentWellness(userId): string` — последние wellness check-ins (**#44: CURDATE() без TZ**).
- `523 daysSinceLastRest(userId): ?int` — дней подряд без rest (хитрый UNION 15 чисел, **#45**).
- `553 getRecentWorkouts(userId, limit): array` — N последних тренировок (workout_log ∪ workouts). **#46: дубль SQL с getWorkoutsHistory.**
- `638 formatCoachingInsights(userId): string` — сводка: последняя тренировка, неделя, plan-vs-actual, compliance, load trend, ACWR.
- `760 calculateACWR(userId): array` — **ACWR через sRPE.** Зоны low/optimal/caution/danger. **#47: proxy dist×6 завышает.** Public — используется ToolRegistry, ProactiveCoach.
- `841 getWeeklyCompliance(userId): array` — planned/completed/missed за 2 недели.
- `899 getLoadTrend(userId): ?int` — % изменения км этой недели vs прошлой.
- `949 getThisWeekWorkoutCount / 1013 getThisWeekPlanVsActual` — недельная статистика + несовпадение план/факт.
- `1117 getDayDetails(userId, date): array` — план + упражнения + результат дня (multi-workout aware).
- `1216 getWorkoutsHistory(userId, from, to, limit): array` — история за период (для tool get_workouts).

#### `services/ChatToolRegistry.php` (25 tools)

- `21 getChatTools(): array` — JSON-schema всех 25 tools для function calling.
- `114 executeTool(name, argsJson, userId): string` — диспетчер. **#37: 25 closures на вызов.** Graceful JSON-parse fallback.
- `181 toolDef / 186 requireUser / 190 validateDate / 194 formatDateRu / 199 getUserTz` — хелперы.
- `208 resolveNaturalDateArgs(&args, userId)` — авто-резолв «завтра»/«в среду» → Y-m-d перед вызовом tool.
- `229 getDayPlanDataByDate / 244 findDayIdByDate` — поиск дня плана (ORDER BY id DESC LIMIT 1).
- `261 executeGetDate` — текст → Y-m-d.
- `271 executeGetPlan` — неделя плана.
- `304 executeGetWorkouts` — история (limit 100).
- `335 executeGetDayDetails` — детали дня (multi-workout).
- `393 executeUpdateTrainingDay / 416 executeDeleteTrainingDay / 432 executeMoveTrainingDay / 476 executeSwapTrainingDays` — write-операции через WeekService.
- `501 executeRecalculatePlan / 512 executeGenerateNextPlan` — запуск async через TrainingPlanService.
- `523 executeLogWorkout` — запись результата (distance ≤300км).
- `579 executeGetStats / 618 executeRacePrediction / 644 executeGetProfile / 689 executeUpdateProfile / 705 executeGetTrainingLoad` — read/update.
- `739 executeAddTrainingDay / 757 executeCopyDay` — add/copy.
- `776 calculateVdotPredictions / 792 calculateVdotPaceZones / 811 formatSeconds` — **VDOT-формулы Daniels (#38: magic numbers).**
- `823 loadTrainingState(userId): array` — lazy+cached TrainingStateBuilder (#40).
- `835 executeGetPersonalRecords / 859 executeGetComplianceHistory / 871 executeGetMacrocyclePhase / 912 executeGetLoadPolicy` — из training_state.
- `934 executeLogWellness` — UPSERT daily_wellness (clamp 1-5/1-10).
- `996 executeGetWeather` — прогноз (≤6 дней).
- `1033 executeGetWellnessTrend` — тренды самочувствия + средние.

#### `services/ChatPromptBuilder.php`

- `35 estimateTokens / 44 estimateMessagesTokens` — оценка токенов (3.2 симв/токен, **#35**).
- `56 buildChatMessages(userId, context, history, question): array` — **главный**: system + context + history + enriched question. Бюджет 32K (**#34**).
- `118 buildCompressedSystemPrompt(...)` — статичный prompt (cache-friendly, даты в конце).
- `163 buildDatesSuffix` — переменный date-блок (в конце user-msg для prefix cache).
- `172 getRaceReplacementAddon / 176 getAddTrainingAddon` — динамические addon'ы по интенту.
- `193 trimToTokenBudget / 217 trimHistoryToTokenBudget / 234 trimOldestMessages` — бюджетирование.
- `259 normalizeMessagesForStrictAlternation(messages): array` — стыковка кривой истории под требование строгого чередования user/assistant.
- `333 appendChatSearchSnippet(context, convId, msg): string` — поиск по истории чата.
- `376 appendRagSnippet(context, msg): string` — RAG к PlanRun AI /retrieve-knowledge (**#32: без кэша**).
- `427 hasAddTrainingIntent / 461 hasReplaceWithRaceIntent` — keyword intent-detection (**#31: «запиши» triggers add**).
- `502 resolveDateFromUserMessage / 516 enrichQuestionWithDates` — резолв дат через DateResolver.

#### `services/ChatActionParser.php`

- `33 isConfirmationMessage(text): bool` — proxy на ConfirmationHandler.
- `40 sanitizeResponse(text): string` — очистка LLM-вывода: think-tags, en-preamble, emoji, legacy ACTION (**#18**).
- `98 parseAndExecuteActions(...)` — теперь только стрипает legacy ACTION-блоки (имя не отражает поведение).
- `111 stripAllActionBlocks` — regex по ACTION_TOOLS (**#19: потенциально dead**).
- `118 replaceEnglishTerms(text): string` — словарь ~75 en→ru (**#16 исправлен частично / #17: ~225 preg**).
- `182 stripEmoji(text): string` — 11 unicode-диапазонов (**#20**).
- `200 logLeakedEnglish(text)` — warning при английских словах (allowlist VDOT/ACWR/...).

#### `services/ChatConfirmationHandler.php`

- `22 isConfirmationMessage(text): bool` — короткое «да/ок/давай».
- `32 tryHandleSwapConfirmation(...)` — swap при подтверждении.
- `56 tryHandleReplaceWithRaceConfirmation(...)` — замена 2 дней на race+recovery.
- `97 tryHandleGenericUpdateConfirmation(...)` — обобщённый: пробует 9 try*-методов.
- `115 tryExtractFromLastProposal(history, userId): ?array` — **#26 (ИСПРАВЛЕН): искал 'assistant', теперь 'ai'.**
- `155 getLastAssistantMessage(history): string` — последнее AI-сообщение (sender_type='ai').
- `164 extractSwapDatesFromText / 191 extractSingleDateFromText / 204 extractReplaceDatesFromText` — парсинг дат (#29: alias).
- `222-365 tryExecute*FromProposal` — 9 regex-парсеров предложений (delete/move/add/log/update/recalc/genNext/copy/profile). **#27: хрупко.**
- `368 parseGenericUpdateProposal / 401 extractDescriptionFromProposal / 417 parseReplaceWithRaceProposal` — парсинг тела предложения.

#### `services/ChatMemoryManager.php`

- `32 extractAndSaveMemory(userId, recentMessages): bool` — извлечь факты → мерж → сохранить.
- `47 extractFacts(messages, existing, userId): array` — LLM-вызов извлечения фактов (категории, ≤10).
- `136 mergeFacts(existing, new): string` — дедуп по 60% сходству + compress.
- `167 isSimilarFact(a, b): bool` — 60% общих значимых слов (**#23: стопворды включают бег/км**).
- `185 compressMemory(memory): string` — FIFO drop при >2000 симв (**#22: теряет травмы**).
- `195 getMemory / 205 saveMemory / 223 addFact / 233 clearMemory` — CRUD (**#21: race; #25: addFact не atomic**).

#### `services/LlmGateway.php` (статический фасад)

- `LlmGatewayRequestException` — несёт httpStatus, retryable, retryAfterSeconds, responseBody.
- `59 provider(baseUrl): string` — deepseek|openai-compatible.
- `71 apiKey / 81 apiKeys(purpose): array` — per-purpose ключи (PLAN_LLM_*, LLM_CHAT_*, DEEPSEEK_*).
- `117 headers / 128 apiKeyFingerprint / 134 splitApiKeys / 148 selectApiKey` — auth helpers (**#12: empty key fallback**).
- `157 withThinkingMode(payload, baseUrl, enable): array` — нормализует payload под провайдер.
- `176 requestChatCompletion → 181 requestJson(baseUrl, path, payload, opts): array` — **главный**: retry + backoff + lease + observability.
- `376 acquireConcurrencyLease / 427 releaseConcurrencyLease / 454 describeConcurrencyLease` — лимитер (GET_LOCK + INSERT).
- `459-596 resolveConcurrencyLimits / acquirePoolLease / ...` — внутренности лимитера.
- `598 ensureLimiterTable` — auto-create llm_gateway_locks (**runtime schema**).
- `731 isRetryableThrowable / 736 queueRetryDelaySeconds` — для queue-воркера.
- `757 isRetryableHttpStatus / 762 parseRetryAfter / 785 backoffSeconds` — retry-логика.
- `797 sleepBeforeRetry(seconds)` — **#11 (ИСПРАВЛЕН): cap 30→120с.**
- `808 envInt` — **#13: getenv() vs env() default mismatch.**
- `822 extractUsageMetrics / 841 logRequestEvent / 867 sanitizeObservabilityPayload` — observability (вырезает ключ/messages/PII).

### Phase 2 — Plan generation

#### `services/PlanGenerationProcessorService.php` (оркестратор)

- `19 process(userId, jobType, payload, jobId): array` — **главная точка очереди.** Dual-path llm_planner|legacy (**#70**).
- `270 processViaLlmPlanner(...)` — современный путь: DeepSeek → race consistency → hard repairs → QualityGate.
- `399 applySinglePassHardSafetyRepairs(plan, state, startDate): array` — medical: marathon long >32км @ ≤21д cap; long_share cut.
- `544 buildMacroPlanMetadataFromWeeks / 571 sumWeekDistances / 582 resolvePlanDayDistanceKm` — расчёт объёмов.
- `646 resolveQualityGateMode(configMode, user, state): array` — auto: strict для рисковых когорт.
- `703 enforceRaceDayConsistency(...)` — гарантирует race-дни на правильных датах + intermediate.
- `784 ensureIntermediateRacesInPlan(plan, races): array` — safety-net: форсит промежуточные забеги (защищает distance_km).
- `873 capRaceWeekSupplementaryVolume / 932 placeRaceOnCalendarDate` — размещение race + cap supplementary.
- `1070 enrichRecalculatePayload(userId, payload): array` — cutoff_date, kept_weeks, actual_weekly_km_4w, progression_counters (**#71: фильтр в PHP**).
- `1177 enrichNextPlanPayload(...)` — last_plan_avg_km, recent_plan_weeks.
- `1269 buildCompletedProgressionCounters` — счётчики выполненных key-тренировок до cutoff.
- `1505 isRunningRelevantManualActivity / 1514 isRunningRelevantImportedActivity` — фильтр бег vs ходьба/вело.
- `1523 persistFailure(userId, message)` — записать ошибку в user_training_plans.
- `1622 enrichPlanWithOfpAndSbu(planData, userId): array` — вызов OFP-enricher + парсинг описаний.
- `1712 ensureOfpDaysInPlan(userId, planData): array` — template fallback ОФП.
- `1796 applyPlanCritique(planData, userId, mode, state): array` — self-critique + revision.
- `1865 persistPlanSummary / 1896 syncLatestTrainingPlanSnapshot / 2058 appendPlanReview` — пост-обработка.
- `2226 buildRealismContextForReview` — severity/predicted vs goal для plan review.
- `2301 attachGenerationExplanation(...)` — PlanExplanationService → metadata.

#### `services/PlanQualityGate.php`

- `12 evaluate(plan, startDate, state, context): array` — **главная**: normalize → repairs → baseline vs repaired → blocking. Возвращает status/normalized_plan/issues/should_block_save.
- `52 buildEvaluation(...)` — сбор всех issues + score.
- `74 applyDeterministicRepairs(...)` — **#67 (ЗАДОКУМЕНТИРОВАН): load repair дважды (намеренно после minimum-distance).**
- `87 isCandidateBetter / 103 planHash` — выбор лучшего из baseline/repaired.
- `108 collectScenarioIssues` — tune-up event consistency (b-race должен быть control).
- `223 collectLlmPlannerContractIssues` — язык + macro/detail + long-run safety + fresh-long.
- `233 collectUserFacingLanguageIssues` — английские термины в notes/description.
- `280 collectMacroDetailConsistencyIssues` — macro target vs календарь.
- `363 collectLongRunSafetyIssues` — long_share, training run at race distance, marathon long too large/close.
- `440 collectFreshLongEffortIssues` — неделя 1 после свежего длинного забега.
- `493 collectGoalFeasibilityIssues` — из goal_realism.
- `553 downgradeProtectiveScenarioIssues` — volume_spike → warning для conservative.
- `584 filterIssuesForScenario` — relax `missing_run_on_required_day` для race/recovery недель.
- `691 containsForbiddenEnglishTrainingText` — **#68: дубль словаря из ChatActionParser.**
- `761 resolveBlockingPolicy / 767 applyBlockingPolicy` — **#69: permissive пропускает медриски.**

#### `services/PlanSkeletonBuilder.php` (не в llm_planner-пути)

- `16 build(userData, goalType, options): array` — skeleton weeks×days по фазам/сценарию.
- `110 resolveWeekRunDays(...)` — какие дни недели беговые (cap по race/taper/scenario).
- `231 limitRunDays / 269 resolveRunDayPriority / 302 weekdayKeyFromIndex` — приоритезация дней.
- `332 resolvePhasePlan / 356 buildPhasePlanFromCurrentPhase` — раскладка фаз по неделям.
- `390 resolveRecoveryWeeks / 409 resolveControlWeeks` — recovery/control недели.
- `428 resolveQualityTypes(...)` — **#65: 170 строк, 8+ branches.** Какие quality-сессии (tempo/interval/fartlek) в неделю.
- `600 resolveSpecialPopulationFlags / 605 resolveWeekTuneUpEvent / 623 pickQualityIndexes / 669 isAdjacentToAny` — хелперы.

#### `services/PlanReadinessCheckService.php`

- `13 ensureSchema()` — **#63: runtime CREATE TABLE.**
- `45 maybeCreatePendingCheck(userId, jobType, payload): ?array` — создать stale-pain check если pain-сигнал ≥7д назад + были пробежки.
- `106 submitAnswer(userId, checkId, answer): array` — ответ → interpretation (clear/mild_clear/protective) → valid_until.
- `169 getLatestValidAnswer(userId): ?array` — последний валидный ответ (если нет нового pain после source).
- `204 findLatestStalePainSignal / 226 findLatestPainSignal / 251 countRunsAfterDate` — детекция.
- `412 resolvePainScore / 425 interpretAnswer / 435 normalizeBool` — парсинг ответа (**#64: magic windows**).

#### `services/PlanGenerationQueueService.php`

- `15 enqueue(userId, jobType, payload, maxAttempts): array` — дедуп по активной задаче юзера.
- `62 reserveNextJob(): ?array` — FOR UPDATE SKIP LOCKED + legacy fallback.
- `82 reserveNextJobWithLock / 117 reserveNextJobLegacy / 140 markJobRunning` — резервация.
- `158 recoverStaleRunningJobs(timeout): array` — зависшие running → requeue/failed (default 30 мин).
- `209 markCompleted / 224 markFailed` — финализация (retry с backoff).
- `289 isQueueAvailable / 311 assertQueueTableAvailable` — **#62: SHOW TABLES каждый вызов.**
- `326 isSkipLockedCompatibilityError` — **#61: heuristic по тексту ошибки.**

#### `services/PlanScenarioResolver.php`

- `15 resolve(user, state, mode, payload): array` — **главная**: flags + schedule_anchor + race_position + tune_up + policy_decisions.
- `116 resolveScheduleAnchorDate(...)` — выравнивание старта до понедельника.
- `137 resolvePrimaryScenario` — приоритетный сценарий.
- `158 resolveTuneUpEvent(...)` — детекция промежуточного старта из payload.
- `240 isBRaceBeforeARace(...)` — **5-10 дней до главного + дистанция < главной → B-race (#60 magic).**
- `264 normalizeTuneUpType / 273 resolveDistanceKm / 293 parseTimeToSeconds` — нормализация.

#### `services/PlanExplanationService.php`

- `8 buildExplanation(userId, jobType, payload, planData, state): array` — summary + inputs + plan_outline + readiness/vdot/risk.
- `27 extractPlanOutline` — week1 volume/quality/rest/long.
- `81 buildInputSignals / 116 buildSummary / 140 buildHumanSignalSummary / 243 buildHumanStateSummary / 277 buildHumanFormSignal` — генерация русских фраз (i18n-blocker).

#### `services/PlanNotificationService.php`

- `21 notify(userId, type, message, metadata)` — универсальное уведомление.
- `76 notifyCoachPlanUpdated / 103 notifyAthleteResultLogged` — coach-маркетплейс события.
- `123 getUnread / 147 markRead / 160 markAllRead` — CRUD.
- `202 mapTypeToEventKey` — type → event_key для диспетчера.

#### `planrun_ai/llm_planner/DeepSeekPlanPlanner.php` (production client)

- `80 generate(userId, jobType, payload): array` — **главная**: user → state → scenario → context → LLM → normalize.
- `132 generateFullPlan(context, modelSelection): array` — один LLM-вызов полного плана.
- `167 resolveModelSelection(state): array` — **#75: PLAN_LLM_THINKING_ALWAYS=true default = reasoner всегда.**
- `216 regenerateWeeks(...)` / `261 applyRegeneratedWeeks / 308 buildTargetedRetryPrompt` — targeted retry конкретных недель (Phase C.2).
- `369 computeComplexityScore(state): int` — счётчик факторов риска для эскалации модели.
- `405 buildSystemPrompt` — «тренер: диагноз → стратегия → календарь».
- `424 buildFullPlanPrompt(context): string` — user-prompt + FACTS_JSON + medical-инварианты.
- `471 requestJson(model, prompt, maxTokens, timeout, thinking, allowLengthRetry=true): array` — **#74 (ИСПРАВЛЕН): retry ×1.6 tokens при finish_reason=length.**
- `610 buildPlannerContext(...)` — FACTS_JSON: user + training_state + planning_scenario + hard_rules + calendar_weeks + recent_compliance/workouts + season + best_races.
- `707 buildCalendarWeeks(...)` — skeleton с метками (date, days_to_race, race_proximity, suggested_default).
- `795 resolveRaceProximity / 867 suggestDayDefault` — семантические ярлыки.
- `890 buildHardRules(...)` — medical-only (required days, race, language, long_run_safety, fresh_long_effort_guard).
- `936 buildRecentLongEffortGuard / 986 resolveRecentLongEffortThreshold` — guard после свежего длинного забега.
- `1089 loadRecentWorkouts(userId, startDate): array` — 8 недель тренировок (бег only).
- `1186 deriveMacroPlanFromWeeks / 1235 alignWeekTargetsToCalendar / 1269 normalizeWeekCollection` — пост-обработка ответа.

#### `planrun_ai/prompt_builder.php` (3538 строк, **#88**)

**VDOT/паса:**
- `354 _vdotOxygenCost / 373 estimateVDOT(distKm, timeSec): float` — формула Daniels: VDOT из результата.
- `388 predictRaceTime(vdot, distKm): int` — предсказание времени.
- `416 getTrainingPaces(vdot): array` — темпы по зонам.
- `477 predictAllRaceTimes(vdot): array` — предсказания на все дистанции.
- `175 calculatePaceZones(userData) / 300 getMinEasyKm / 324 calculateDetrainingFactor` — зоны/детренинг.

**Цель/реалистичность:**
- `496 assessGoalRealism(userData): array` — verdict/severity/messages (~340 строк).
- `837 softenGoalAssessmentForRegistration / 862 ...Message` — смягчение для онбординга.

**Макроцикл:**
- `967 computeMacrocycle(userData, goalType): ?array` — фазы для race (318 строк).
- `1285 computeHealthMacrocycle(...)` — фазы для health.
- `1435 formatHealthMacrocyclePrompt / 1477 formatMacrocyclePrompt` — текст для промпта.

**Schedule helpers:**
- `18 getPromptWeekdayOrder / 34 sortPromptWeekdayKeys / 47 getPromptWeekdayLabel / 57 getPreferredLongRunDayKey` — дни недели.
- `108 computeRaceDayPosition(startDate, raceDate): ?array` — week+dayIndex гонки.
- `136 getSuggestedPlanWeeks` — кол-во недель плана.
- `76 extractScheduleOverridesFromReason / 2012 applyScheduleOverridesToUserData` — парсинг reason пересчёта.

**Build* (сборка промпта):**
- `1576 buildUserInfoBlock / 1639 buildGoalBlock / 1843 buildStartDateBlock / 1855 buildPreferencesBlock / 1912 buildPaceZonesBlock / 2043 buildTrainingStateBlock / 2131 buildWeekSkeletonBlock / 2161 buildWorkoutIntentBlock / 2190 buildTrainingPrinciplesBlock / 2310 buildKeyWorkoutsBlock / 2466 buildMandatoryRulesBlock / 2528 buildFormatResponseBlock`.
- `2589 buildTrainingPlanPrompt(userData, goalType): string` — **legacy entry-prompt.**
- `2631 computePlanChunks / 2676 _splitByMacrocyclePhases / 2740 buildPartialPlanPrompt` — сплит длинных планов.
- `2809 buildRecalculationPrompt / 2923 buildRecalcTrainingPrinciplesBlock / 3103 buildRecalcContextBlock` — пересчёт.
- `3335 buildNextPlanPrompt / 3406 buildPreviousPlanHistoryBlock` — новый план.

#### `planrun_ai/plan_normalizer.php` (1791 строк, **#86**)

**Парсинг/расчёт:**
- `32 parsePaceToSeconds / 43 formatPaceFromSec / 50 formatDurationHMS / 60 calculateDurationMinutes` — pace/duration.
- `70 calculateIntervalTotalKm / 82 calculateFartlekTotalKm` — объём сложных тренировок.
- `95 normalizeFartlekSegments / 129 hasUsableFartlekSegments / 133 ensureFartlekWorkoutStructure` — fartlek.
- `162 buildDescriptionFromFields(day): string` — генерация description из полей (126 строк).

**Нормализация:**
- `437 normalizeTrainingType / 451 normalizePreferredDayKeys / 500 normalizeSkeletonDayType / 504 isRunTypeForSchedule` — нормализация типов.
- `512 resolveIsKeyWorkout / 524 resolveRunDistanceSafetyNet` — флаги/safety.
- `540 normalizeTrainingDay(day, date, dow): array` — **главная нормализация дня (189 строк).**
- `729 rebuildNormalizedDayArtifacts(day): array` — пересборка description/exercises после правок.
- `796 retargetNormalizedDay / 802 retargetWeekDays / 814 createCoercedSkeletonDay / 850 alignWeekDaysToSkeleton` — выравнивание под skeleton.
- `894 resolvePreferredLongRunIndex / 922 moveLongRunToPreferredIndex / 946 repairAdjacentKeyWorkouts` — расстановка long/key.
- `974 calculateNormalizedWeekVolume(days): float` — сумма км недели.
- `996 normalizeTrainingPlan(rawPlan, startDate, offset, prefs, skeleton): array` — **ГЛАВНАЯ точка входа нормализатора.**

**Repairs (детерминированные):**
- `1199 applyTrainingStatePaceRepairs` — pace по training_state.
- `1250 applyControlWorkoutFallback` — fallback для control.
- `1279 trimDaysByType / 1312 rebalanceLongShareWithinWeek` — балансировка объёма.
- `1386-1408 resolveEasy/Tempo/Long/RaceWeek...Floor/Cap` — пороги.
- `1422 applyTrainingStateLoadRepairs` — load caps (вызывается дважды в QualityGate, см. #67).
- `1565 applyTrainingStateMinimumDistanceRepairs` — поднять короткие тренировки.
- `1635 applyTrainingStateWorkoutDetailFallbacks` — детали для quality.
- `1723 findNormalizedPlanRaceWeekNumber / 1735 resolveGoalPaceSecFromTrainingState / 1752 resolveGoalSpecificTempoPaceTargetSec` — хелперы.

#### `planrun_ai/plan_generator.php` (1268, legacy entry)

- `31 generatePlanViaPlanRunAI(userId)` — legacy генерация.
- `117 applyCritiquePassToPlanData(...)` — **#77: дубль critique с Processor.**
- `169 generateSplitPlan(...)` — сплит длинных планов.
- `250 parseAndRepairPlanJSON(response, userId): array` — **#78: 5-уровневый fallback парсинг JSON.**
- `310 validatePlanStructure` — валидация структуры.
- `350 recalculatePlanViaPlanRunAI / 707 generateNextPlanViaPlanRunAI` — legacy recalc/next.
- `969 detectCurrentPhase(userData, goalType, keptWeeks): ?array` — текущая фаза для recalc.
- `1239 isRunningRelevantWorkoutEntry / 1262 resolveRecalculationCutoffDateValue` — хелперы (используются и Processor'ом).

#### `planrun_ai/plan_saver.php` (495)

- `30 saveTrainingPlan(db, userId, planData, startDate, prefs, alreadyNormalized): void` — **DELETE+INSERT всего плана в транзакции. #80: alreadyNormalized пропускает валидацию. #81/#82.**
- `174 saveRecalculatedPlan(...)` — сохранить прошлые недели, заменить будущие. preserved current-week days.
- `362 loadPreservedRecalculationDays / 463 mergePreservedDaysIntoRecalculatedWeek` — сохранение прошедших дней текущей недели.

#### `planrun_ai/plan_critique_generator.php` (492)

- `32 runPlanSelfCritique(planData, user, context, userId): ?array` — независимый LLM-ревью (severity/issues/strengths).
- `167 repairAndParseCritiqueJson(content): ?array` — **#84: дубль parseAndRepairPlanJSON.**
- `201 revisePlanWithCritique(...)` — LLM-revision по замечаниям (sanity-checked).
- `351 validateRevisedPlan(orig, revised): ?string` — **#85: ловит overcorrection (race-day removal, long count, race-week).**
- `441 buildAthleteBlockForCritique / 472 buildHistoryBlockForCritique` — контекст для промпта.

#### `planrun_ai/plan_review_generator.php` (483)

- `19 buildPlanSummaryForReview / 225 buildPlanReviewFacts` — данные для ревью.
- `71 generatePlanReview(...)` — LLM-генерация ревью плана в чат.
- `170 buildRealismFactsForReview / 213 buildRealismDirectiveForReview` — realism в ревью (PR9).
- `277 sanitizePlanReviewContent / 321 isForbiddenRaceReviewSentence / 360 applyPlanReviewLanguageReplacements / 385 polishPlanReviewTone / 463 detectPlanReviewSentenceCategory` — пост-обработка текста.

#### `planrun_ai/ofp_enricher.php` (273)

- `14 enrichPlanWithOfp(planData, user, exerciseLibrary, userId): ?array` — LLM подбирает ОФП-сессии под нагрузку недели, validate против library.
- `232 ofpEnricherSummariseWeekLoad / 262 ofpEnricherUserBlock` — контекст для промпта.

#### Прочее planrun_ai/

- `planrun_ai_integration.php`: `9 resolvePlanRunAIMaxTokens` (**#52 ИСПРАВЛЕН: default 4096→32768**), `41 callPlanRunAIAPI` (**#53/#54/#55**), `151 callAIAPI`.
- `plan_validator.php`: `11 collectNormalizedPlanValidationIssues` (агрегирует 6 валидаторов), `52 shouldRunCorrectiveRegeneration`, `62 scoreValidationIssues`.
- `description_parser.php`: `9 parseOfpSbuDescription(description, type): array` — парсер ОФП/СБУ.
- `text_generator.php`: `16 generateTextFromExercises` (**#58: dead LLM-ветка**), `70 generateSimpleDescription`.
- `create_empty_plan.php`: `17 createEmptyPlan(userId, startDate, endDate)` — 12 пустых недель (self-режим).
- `planrun_ai_config.php`: `24 isPlanRunAIAvailable()` — health-check (405 = OK).
- `generate_plan_async.php` — CLI-скрипт (нет функций, **#56 legacy entry**).

#### Validators (`planrun_ai/validators/`)

- `schedule_validator.php:5 collectScheduleValidationIssues` — preferred_days + skeleton mismatch + 7-дней-в-неделе.
- `pace_validator.php:20 collectPaceValidationIssues` + `_paceCheckEasy/Long/Tempo/Interval/Fartlek` — pace в коридоре VDOT (**#59 dead branch**).
- `load_validator.php:5 collectLoadValidationIssues` — volume spike + back-to-back key workouts.
- `taper_validator.php:5 collectTaperValidationIssues` — race-week supplementary + taper reduction.
- `goal_consistency_validator.php:5 collectGoalConsistencyValidationIssues` — health too many quality + special-pop guards.
- `workout_completeness_validator.php` — `hasMeaningful{Tempo,Control,ComplexWorkout}Structure`, `collectWorkoutCompletenessValidationIssues` — наличие структуры у quality-дней.

### Phase 3 — Coaching + observability

#### `services/PostWorkoutFollowupService.php` (1528)

- `28 ensureSchema()` + `1216 ensureColumnExists` — **#107: runtime schema (10 проверок колонок).**
- `87 getRecentFeedbackAnalytics / 100 getFeedbackAnalyticsBetween` — аналитика feedback за окно.
- `149 getPendingCheckinState(userId): ?array` — текущий pending/sent followup для UI.
- `187 scheduleForWorkout(userId, date, sourceKind, sourceId, analysisMsgId): bool` — поставить followup в очередь (delay 15 мин).
- `260 snoozeFollowup(userId, followupId, preset)` — отложить (30m/evening/tomorrow).
- `305 processDueFollowups(limit): array` — **cron: отправить due followups.**
- `375 tryHandleUserReply(userId, convId, msgId, content): ?array` — **обработка ответа юзера: analyzeFeedback → note → workout_log → coach reply.**
- `521 supersedeOtherActiveFollowups` — **#109: новый followup затирает старые active.**
- `555 expireStaleSentFollowups(): int` — sent >36ч → expired.
- `575 isFirstUserReplyAfterFollowup` — **#111: защита от ложной привязки.**
- `594 getWorkoutSummary / 636 shouldScheduleForSummary` — валидация (бег, сегодня, ≤8ч).
- `670 buildFollowupPrompt` — текст вопроса.
- `712 appendFeedbackToWorkoutLogIfPossible` — **#108: read-check-write race.**
- `751 analyzeFeedback(content): array` — **#105: regex pain/fatigue/positive БЕЗ negation («не болит»→pain).**
- `841 isLikelyFeedbackResponse` — **#106: ловит non-feedback ответы.**
- `858 buildCoachReply / 889 buildNextDayAdvice` — ответ тренера + совет на след. день.
- `1402 resolveRiskLevel / 1424 resolveSessionRpe / 1438 resolveTenPointScore / 1455 resolvePainScore / 1474 extractStructuredScore / 1487 normalizeStructuredScore` — извлечение оценок (**#112**).
- `1279 buildFeedbackAnalyticsSummary / 1500 buildStructuredMetricSummary` — агрегаты feedback.

#### `services/ProactiveCoachService.php` (797)

- `60 processAllUsers(): array` — **cron: detect → pick → generate → send → cooldown.**
- `95 detectEvents(userId, user): array` — все детекторы.
- `131 detectPause` — **#101: 4-14д без ЛЮБОЙ активности (не только бег).**
- `154 detectOverload` — ACWR danger/caution.
- `167 detectUpcomingRace` — ≤14д до забега.
- `187 detectLowCompliance` — <40% за 2 нед.
- `201 detectMilestone` — рекорд дистанции вчера.
- `238 detectGoalMilestones` — из GoalProgressService.
- `256 pickNextAvailableEvent` — **#102: один тип per tick.**
- `271 buildGoalContext / 304 buildHistoryBlock` — контекст для LLM.
- `330 generateMessage(userId, user, event): string` — LLM-сообщение по событию + fallback.
- `390 getFallbackMessage / 406 getDailyBriefingFallback` — детерминированные fallback'и.
- `427 sendProactiveMessage` — addAIMessageToUser.
- `443 isOnCooldown / 464 recordCooldown` — **#100: race между воркерами.**
- `473 processDailyBriefings / 581 processWeeklyDigests` — утренний брифинг / недельный итог (cron).
- `670 callLlmSimple / 707 normalizeProse` — LLM-вызов + анти-bullet (**#104**).
- `779 getActiveUsers(): array` — **#99: без пагинации.**

#### `services/AiPlanGenerationEventLogger.php` (541)

- `33 recordSuccess / 65 recordFailure` — запись plan-generation события.
- `96 getRecentEvents(limit, filters): array` — для admin-дашборда.
- `176 getMetricsSummary(hours): array` — bad_plan_rate/repair_rate/blocked_rate by cohort/model.
- `256 deriveCohort(state): string` — pregnant>injury>pain>illness>unrealistic>healthy.
- `293 isDeepSeekOffPeakNow(): bool` — **#97: 16:30-00:30 UTC hardcoded.**
- `301 buildRow / 363 insert` — **#96: 28-параметрный bind_param.**
- `494 enrichAggregateRow` — расчёт rate'ов.

#### `services/AthleteSignalsService.php` (424)

- `14 getRecentSignalsSummary(userId, days, endDate): array` — главная: feedback + notes → risk.
- `23 getSignalsBetween` — окно дат.
- `35 getAthleteNotesBetween` — day/week notes (без followup-заметок).
- `89 buildNoteMetrics(notes): array` — счётчики сигналов.
- `185 analyzeNote(content): array` — **#93: regex pain/fatigue/sleep/illness/stress/travel без negation. #94: magic weights.**
- `251 mergeSignalSummaries(...)` — **#95: 35+ ключей.** Итоговый risk_level + planning_biases + highlights.
- `411 resolveRiskLevel` — low/moderate/high.

#### `services/AiObservabilityService.php` (76)

- `8 ensureSchema()` — auto-create ai_runtime_events (static guard).
- `36 createTraceId(surface): string` — `{prefix}-{12hex}`.
- `41 logEvent(surface, eventType, status, payload, userId, traceId, durationMs)` — **#91: silent fail при prepare.** Вырезает PII через sanitize.

---

## ЧАСТЬ 3 — КАРТА «ГДЕ ЧТО ПРАВИТЬ»

При фиксе ориентируйся:

| Хочу поправить... | Иду в... |
|---|---|
| Поведение AI-чата (ответы, tools) | ChatService → ChatToolRegistry / ChatPromptBuilder |
| Контекст который видит чат-LLM | ChatContextBuilder::buildContextForUser |
| Подтверждения «да» к действиям | ChatConfirmationHandler (хрупко — см. #27) |
| Очистку вывода LLM | ChatActionParser::sanitizeResponse |
| Память между диалогами | ChatMemoryManager + chat_user_memory |
| Любой LLM-вызов (retry/limit/keys) | LlmGateway |
| Генерацию плана (структура, фазы) | DeepSeekPlanPlanner::buildFullPlanPrompt + buildHardRules |
| Что блокирует сохранение плана | PlanQualityGate::evaluate + validators/* |
| Нормализацию/repair плана | plan_normalizer.php (normalizeTrainingPlan + apply*Repairs) |
| Запись плана в БД | plan_saver.php |
| Self-critique плана | plan_critique_generator.php |
| Ревью плана в чат | plan_review_generator.php |
| ОФП в плане | ofp_enricher.php + Processor::ensureOfpDaysInPlan |
| Сценарии (B-race, taper, return) | PlanScenarioResolver |
| VDOT/паса/макроцикл | prompt_builder.php (estimateVDOT/getTrainingPaces/computeMacrocycle) |
| Проактивные сообщения | ProactiveCoachService |
| Post-workout чек-ины | PostWorkoutFollowupService |
| Сигналы самочувствия для плана | AthleteSignalsService |
| Метрики AI | AiObservabilityService / AiPlanGenerationEventLogger |
| Очередь генерации | PlanGenerationQueueService |
| Главный flow генерации | PlanGenerationProcessorService::process |

---

**Документ создан 2026-05-15.** Сигнатуры — снимок на дату; при рефакторинге обновлять параллельно с кодом.
