# Fix Plan — AI Pipeline

План фиксов по находкам из [CODE-AUDIT-AI-PIPELINE.md](./CODE-AUDIT-AI-PIPELINE.md). Группировка — по сложности/риску, не по фазам аудита.

## Принципы

- Каждая партия фиксов = 1 коммит = 1 bump tag (`v3.20 → v3.21`).
- Сначала закрываем критичные баги хирургическими правками (низкий риск).
- Архитектурные изменения откладываем — они требуют отдельных тегов и могут вызвать регрессии.
- После каждой партии — smoke-test (минимум: AI чат отвечает, генерация плана не падает).

---

## Batch 1 — Surgical critical fixes (6 правок, ~30 минут)

Простые хирургические правки. Каждая — 1-5 строк. Минимальный риск регрессии.

| # | Файл | Что делаем |
|---|---|---|
| 1 | [ChatService.php:228-237](../planrun-backend/services/ChatService.php#L228) | Сохранить return value `addMessage` в `$messageId`, использовать его вместо `$db->insert_id` |
| 26 | [ChatConfirmationHandler.php:118](../planrun-backend/services/ChatConfirmationHandler.php#L118) | Заменить `=== 'assistant'` → `=== 'ai'` для совпадения с DB-форматом |
| 11 | [LlmGateway.php:797-800](../planrun-backend/services/LlmGateway.php#L797) | Поднять cap `min(30, ...)` до `min(120, ...)` для длинных Retry-After |
| 52 | [planrun_ai_integration.php:22](../planrun-backend/planrun_ai/planrun_ai_integration.php#L22) | Поднять default `PLANRUN_AI_MAX_TOKENS_HARD_LIMIT` с 4096 до 32768 |
| 67 | [PlanQualityGate.php:78-80](../planrun-backend/services/PlanQualityGate.php#L78) | Убрать дублированный вызов `applyTrainingStateLoadRepairs` (или добавить комментарий с обоснованием) |
| 74 | [DeepSeekPlanPlanner.php:511](../planrun-backend/planrun_ai/llm_planner/DeepSeekPlanPlanner.php#L511) | Добавить retry с увеличенным `max_tokens` при `finish_reason='length'` |

**Risk**: низкий. **Value**: исправляет 6 из 15 критичных багов.

**Commit message**: `v3.21: audit fix batch 1 — surgical critical bugs (message_id, sender_type, retry-after, max_tokens, quality gate dup, length retry)`

---

## Batch 1.5 — Консолидация опасных дублей (ДО любых pain/risk-фиксов!)

Из Phase 4 deep-pass. **Делать перед Batch 2** — иначе #105 уйдёт в одну из двух копий.

| # | Файл | Что делаем |
|---|---|---|
| 119 | PlanReadinessCheckService:412 / PostWorkoutFollowupService:1455 | `resolvePainScore` — 2 разные реализации с одним именем. Разнести имена (`resolveReadinessPainScore` / `resolveFeedbackPainScore`) ИЛИ вынести в shared helper. Цель — исключить «поправил не ту копию». |
| 120 | AthleteSignalsService:411 / PostWorkoutFollowupService:1402 | `resolveRiskLevel` — 2 разные сигнатуры/логики. Аналогично — разнести имена или консолидировать с явными порогами. |

**Risk**: низкий (переименование private-методов в пределах класса). **Value**: убирает ловушку, породившую #105.

**Commit message**: `v3.22: audit fix batch 1.5 — disambiguate duplicate resolvePainScore/resolveRiskLevel`

---

## Batch 2 — Medium-risk bug fixes (4 правки, ~1-2 часа)

| # | Файл | Что делаем |
|---|---|---|
| 2 | [ChatService.php:202, 375](../planrun-backend/services/ChatService.php#L202) | Вынести pre/post-обработку (confirmation handlers, memory extraction, health check) в общий приватный метод, вызывать из обоих путей |
| 105 | [PostWorkoutFollowupService.php:751](../planrun-backend/services/PostWorkoutFollowupService.php#L751) | Добавить negation-aware regex для боли (lookbehind на `не\s+` в окне 30 символов) |
| 21 | [ChatMemoryManager.php:205-217](../planrun-backend/services/ChatMemoryManager.php#L205) | Обернуть `getMemory → mergeFacts → saveMemory` в `BEGIN ... SELECT FOR UPDATE ... UPDATE ... COMMIT` |
| 80 | [plan_saver.php:30-36](../planrun-backend/planrun_ai/plan_saver.php#L30) | Добавить базовую sanity-проверку (`assert isset($planData['weeks'][0]['days'])`) даже при `$alreadyNormalized=true` |

**Risk**: средний — требует тестирования chat-флоу и followup-флоу.

**Commit message**: `v3.22: audit fix batch 2 — non-stream parity, negation handling, memory race, save guard`

---

## Batch 3 — Quality + dead code (5 правок, ~1 час)

| # | Файл | Что делаем |
|---|---|---|
| 53 | [planrun_ai_integration.php:126](../planrun-backend/planrun_ai/planrun_ai_integration.php#L126) | Инициализировать `$httpCode = 0` перед try, чтобы catch-блок не падал на undefined |
| 71 | [PlanGenerationProcessorService.php:1104](../planrun-backend/services/PlanGenerationProcessorService.php#L1104) | Добавить `WHERE activity_type IN ('running', ...)` в SQL, убрать post-filter в PHP |
| 16 | [ChatActionParser.php:172-173](../planrun-backend/services/ChatActionParser.php#L172) | Убрать удаление местоимений `your/my/his/her/its/our/their` — оставить только log via `logLeakedEnglish` |
| 88 (часть) | [prompt_builder.php](../planrun-backend/planrun_ai/prompt_builder.php) | Только пометить TODO с предлагаемым разбиением; собственно разбиение — отдельный тег |
| 96 | [AiPlanGenerationEventLogger.php:413](../planrun-backend/services/AiPlanGenerationEventLogger.php#L413) | Завернуть 28-параметрный bind_param в helper, который собирает type-string программно |

**Risk**: средний-низкий.

**Commit message**: `v3.23: audit fix batch 3 — quality and resilience fixes`

---

## Batch 4 — Performance + DRY (отложить, разные коммиты)

Эти можно делать без срочности по мере касания файлов:
- #4 ChatService.php:285 — кэшировать health-check
- #17 ChatActionParser.php — оптимизировать словарь en→ru
- #32 ChatPromptBuilder.php:386 — кэшировать RAG snippet
- #37 ChatToolRegistry.php:129 — заменить closure dispatch на `match`
- #46 ChatContextBuilder.php:553/1216 — выделить `WorkoutQueryBuilder`
- #81 plan_saver.php — DRY save/recalculate
- #102 ProactiveCoachService.php — отправлять несколько event-types за tick

---

## Архитектурные правки (отдельные релизы, недели работы)

| Что | Объём | Когда |
|---|---|---|
| Разбить prompt_builder.php (3538 строк) на 5-8 файлов | ~2-3 дня | После Batch 1-3 |
| Убрать regex-based confirmation handlers, заменить на `propose_action()` tool | ~3-5 дней | После Batch 1-3, требует продумывания контракта tool |
| Централизовать schema management в `scripts/migrate_all.php` | ~1 день | Когда удобно |
| Удалить legacy plan-generation путь | ~2-3 дня + долгий мониторинг в проде | После того, как PLAN_GENERATION_MODE=llm_planner в проде стабилен ≥ 2 недель |
| Заменить regex-классификацию самочувствия на LLM | ~2-3 дня | После того, как negation-fix покажет проблему всё ещё актуальной |

---

## Batch 6 — Dead code cleanup (отдельный тег, ПОСЛЕ стабильности llm_planner ≥2 нед)

Из Phase 4 deep-pass. ~6 100 строк мёртвого в production кода. **Не удалять сразу** — нужно тестам/dry-run.

| # | Что | Действие |
|---|---|---|
| 117 | `text_generator.php`, orphan `hasStructuredFields`/`parsePlanRunAIResponse` | Удалить (0 ссылок везде, включая тесты) |
| 113 | `generate_plan_async.php` | Удалить (systemd использует scripts/plan_generation_worker.php) |
| 116/118 | `create_empty_plan.php` + self-mode | **Продуктовое решение**: звать createEmptyPlan при self-регистрации ЛИБО осознанно исключить self-mode из проактива + удалить файл |
| 115 | legacy generation chain (plan_generator, prompt_builder build*, planrun_ai_integration) | `@deprecated` + guard «не вызывать при PLAN_GENERATION_MODE=llm_planner», миграция тестов на моки, удаление после N недель |
| 114 | `PlanSkeletonBuilder.php` | Решить вместе с #115 (используется только legacy+тесты) |

**Risk**: низкий технически, но требует подтверждения что dry-run/тесты переведены. Отдельный тег `v3.2x`.

---

## Прогресс

- [x] Batch 1 — surgical critical (6 правок) → `v3.21` ✅ commit 62a743a
- [x] **Batch 1.5 — дубли resolvePainScore/resolveRiskLevel → `v3.22`** ✅ переименованы: resolveAnswerPainScore / resolveFeedbackPainScore / resolveRiskLevelFromScore / resolveFeedbackRiskLevel
- [ ] Batch 2 — medium-risk bugs (4 правки) → `v3.23`
- [ ] Batch 3 — quality + dead code (5 правок) → `v3.24`
- [ ] Batch 4+ — по мере касания файлов
- [ ] Batch 6 — dead code cleanup (после стабильности llm_planner ≥2 нед)

После закрытия Batch 1-3: останется 97 не-критичных находок из 112. Архитектурные — отдельной дорожной картой.
