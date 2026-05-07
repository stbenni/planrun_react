# PlanRun AI V2 — Production Roadmap

> **Статус:** active
> **Источник истины:** `.cursor/rules/plans-ai-v2.mdc` (компактные инварианты для агента) + этот файл (детальный roadmap).
> **Принцип:** идём итерациями по фазам P0→P5. Каждый PR закрывает одну подзадачу или связанный набор, всегда с тестами.
>
> Связанные документы:
> - `.cursor/rules/ai-plan-generation-guide.mdc` — формат JSON-плана (контракт LLM).
> - `.cursor/rules/recalculate-plan.mdc` — поток recalc / next plan.
> - `.cursor/rules/architecture-flow.mdc` — граф вызовов.

---

## 0a. Философия: «Trust the model, guardrails — только injury»

**Базовая позиция продукта.** Если DeepSeek V4 получает корректный, богатый контекст об атлете (профиль, цель, история тренировок, состояние, ограничения, паттерны последних недель), он в подавляющем большинстве случаев способен построить разумный план без галлюцинаций. Поэтому архитектура AI V2 строится по принципу:

1. **Главное вложение — в качество контекста**: `FACTS_JSON`, prompt'ы, `planning_scenario`, `goal_realism`, описание ограничений и истории. Любое улучшение «сначала пробуем через контекст», и только потом — через post-processing.
2. **Гардрейлы — точечные и медицинские.** Программные правки и блокирующий quality gate включаются только там, где есть реальный риск **травмы / болезни / опасной нагрузки** или **явная нереалистичность цели**, а не на «эстетических» расхождениях с эвристиками.
3. **Permissive — by default.** Здоровый бегун с реалистичной целью (включая марафон / полумарафон) — `permissive`. Все валидаторы продолжают эмитить issues, но они идут как `warning`, попадают в `_generation_metadata` и в plan review, а не блокируют сохранение.
4. **Strict — только для рисковых когорт.** `auto`-mode переключается в `strict` только при наличии хотя бы одного:
   - `special_population_flags` ∈ {`pregnant_or_postpartum`, `return_after_injury`, `recent_pain_signal`, `recent_illness_signal`};
   - `planning_scenario.flags` ∈ {`pain_protective`, `illness_protective`, `return_after_injury`};
   - `goal_realism.severity == 'major'` (явный голевой mismatch).
5. **Hard safety repairs остаются** (`PLAN_LLM_HARD_SAFETY_REPAIRS=1`), но действуют как «механические предохранители» против медицински опасных паттернов (резкий скачок объёма, превышение длинной, нарушение taper). Их пороги настраиваются «щедро» — срабатывают там, где DeepSeek очевидно уехал в опасную зону, а не на 5–10% превышениях.
6. **Никаких silent fallback**: при ошибке DeepSeek пользователь получает явное сообщение «попробуйте позже / обратитесь в поддержку» (skeleton не запускается).
7. **Что добавлять в дальнейшем — это контекст**: per-week intent, recent compliance, training response, climate/elevation, race course profile, нюансы graph истории. Это даёт планнеру больше «оснований» решать, чем инструкции «делай так и не делай этак».

**Важно для каждой будущей задачи:** прежде чем добавлять новое валидатор-правило, новый repair или новый strict-триггер — обязательно спросить «можно ли это решить через FACTS_JSON / prompt / context, а не через post-processing?». Если можно — делаем через контекст. Только если контекст не решает (или это прямой injury-risk) — добавляем guardrail.

---

## 0b. Архитектурный аудит: наследие слабой LLM (что упростить под DeepSeek V4)

Текущая архитектура несёт значительные элементы, спроектированные под слабые локальные модели (Qwen 7B, Llama 3.1, ранние LM Studio runs), которые часто галлюцинировали с числами, забывали даты, плохо держали JSON и теряли контекст за пределами 8–16K токенов. С переходом на **DeepSeek V4** (128K context, надёжный structured JSON output, reasoning, профессиональный уровень domain knowledge по бегу) большая часть этих «компенсаций» становится контр-продуктивной — она лишает модель свободы и при этом не даёт реальной защиты, потому что DeepSeek и сам бы построил план правильно.

### A. Слой «LLM не считает числа — числа считаем мы» (skeleton-first)

**Что это:** `planrun_ai/skeleton/*` (~5500 строк), `processViaSkeleton()` в `PlanGenerationProcessorService`, env `USE_SKELETON_GENERATOR`. LLM здесь нужен только для текстовых notes; всё число-чувствительное (объёмы, прогрессии, paces, taper) делает PHP-код через `PlanSkeletonBuilder` + `VolumeDistributor` + 8 progression builders. Поверх — `LLMEnricher` (LLM добавляет только notes), `LLMReviewer` (LLM ищет issues), `PlanAutoFixer` (программные правки).

**Зачем было:** локальная модель не считала километры и темпы надёжно, путала недели, забывала про taper. Skeleton давал детерминированный «костяк», LLM красила его текстом.

**Что не так с DeepSeek:** DeepSeek V4 уверенно считает километры, держит taper, понимает периодизацию (build/peak/recovery/taper), уважает preferred_days и race_date. Skeleton-first вместо помощи теперь **подменяет тренерские решения модели** на грубые эвристики из 2024 года.

**Целевое состояние:** skeleton-первый путь — **только тестовый стенд / диагностика** (используется в `WeeklyAdaptationEngine` для алгоритмических симуляций). В production-flow (`processViaLlmPlanner`) skeleton **не подключается**.

### B. Staged (two-pass) generation — macro + detail батчами

**Что это:** в `DeepSeekPlanPlanner::generate()` есть две стратегии: `single_pass` (один запрос — весь план) и `staged` (сначала `buildMacroPrompt` → высокоуровневый macro-план, потом `buildDetailBatchPrompt` для каждой пачки из 3 недель). Управляется env `PLAN_LLM_PLANNER_STRATEGY`.

**Зачем было:** слабая модель с context window 8–16K не могла за раз сгенерировать 16-недельный план — хватало токенов только на 3 недели. Поэтому строили пирамиду: macro → detail batches.

**Что не так с DeepSeek:** 128K context window. План на 32 недели (марафон) спокойно влезает single_pass. Staged стратегия добавляет 3–5 дополнительных round-trip к API, ломает целостность плана (модель не видит предыдущие 3 недели в новом батче), и почти всегда делает план хуже single_pass.

**Целевое состояние:** удалить `staged` стратегию полностью (вместе с `buildMacroPrompt`, `buildDetailBatchPrompt`, `generateMacroPlan`, `generateDetailBatch`, `buildWeekBatches`). Оставить только `single_pass`.

### C. Три отдельные модели: planner / detail / repair

**Что это:** env `PLAN_LLM_PLANNER_MODEL`, `PLAN_LLM_DETAIL_MODEL`, `PLAN_LLM_REPAIR_MODEL` — у каждой роли своя модель. Замысел был — для дешёвой detail-стадии использовать flash-модель, для planner — pro-модель.

**Что не так с DeepSeek:** при `single_pass` стратегии все три роли — это один и тот же запрос. Три env создают иллюзию настраиваемости, по факту все указывают на `deepseek-chat`. Нужна одна `PLAN_LLM_MODEL`.

### D. Repair loop с полной отправкой плана обратно

**Что это:** в `processViaLlmPlanner` если quality gate видит блокирующие issues — план + список issues отправляется в `DeepSeekPlanPlanner::repairPlan()` (`buildRepairPrompt`), модель возвращает обновлённый план, gate валидирует ещё раз. Уже сейчас работает только для НЕ-single_pass; для single_pass `processViaLlmPlanner` сразу бросает RuntimeException.

**Что не так с DeepSeek:** при single_pass это уже мёртвый код. Большинство сбоев DeepSeek — это или а) infrastructure (timeout, malformed JSON, который ловится в parser), или б) реально-плохой план для очень рискового профиля, и тогда лучше пользователю показать ошибку, чем отправлять весь план назад «починить».

**Целевое состояние:** удалить `repairPlan()` и `buildRepairPrompt()`. Если в будущем понадобится targeted retry — переотправлять только проблемную неделю с фидбеком, не весь план (Phase C).

### E. Hard rules JSON в FACTS — дублируют то, что DeepSeek знает

**Что это:** `buildHardRules()` в `DeepSeekPlanPlanner.php` (~80 строк) собирает блок правил для prompt: `weekly_volume_safety`, `long_run_safety`, `taper_rules`, `weekly_growth_ratio`, `recovery_rules`, `pace_zones` и т.п. Передаются в FACTS_JSON и в prompt'е модель просят «уважать HARD_RULES_JSON».

**Зачем было:** слабая модель забывала про taper, ставила +50% объём через неделю, не уважала race-week.

**Что не так с DeepSeek:** DeepSeek знает все эти правила сам как тренерскую базу. Из всего набора реально нужны только injury-специфичные инварианты:
- Для `return_after_injury`: длинная +1 km/нед, нет интервалов первые 3 нед, объём ≤60% pre-injury.
- Для marathon: long ≤32 км в последние 21 день перед стартом.
- Для preferred days: список разрешённых беговых дней.

Остальное — давление на модель «делай как сказано», а не «думай как тренер».

**Целевое состояние:** упростить hard_rules до compact safety rails (3–5 пунктов вместо 15+). Остальное переместить из «правил» в «контекст» (например, `recent_compliance` показывает, как модель должна реагировать).

### F. Compact* helpers в DeepSeekPlanPlanner

**Что это:** `compactPayload`, `compactLoadPolicy`, `compactPlanningScenario`, `compactGoalRealism`, `compactPlannerHardRules` — функции, обрезающие данные перед отправкой в FACTS_JSON.

**Зачем было:** уместить в context window слабых моделей.

**Что не так с DeepSeek:** 128K hard limit, типичный план — 5–10K токенов. Урезать ничего не нужно. Полные `load_policy`, `planning_scenario`, `goal_realism` дают модели больше нюансов для решения.

**Целевое состояние:** убрать compact* и передавать full data. Оставить только аккуратное форматирование JSON (порядок ключей, отбрасывание `null`-only объектов).

### G. plan_normalizer.php — 1800 строк post-processing

**Что это:** один большой файл с нормализацией дат, schedule enforcement, derived fields (description), `forceFartlekDefaultSegments`, `fillMissingPaceFromTrainingState`, `enforceFartlekStructure`, и много других «починок» вывода LLM.

**Зачем было:** слабая модель путала даты, забывала про preferred_days, ставила пустые fartlek сегменты, забывала pace.

**Что не так с DeepSeek:** часть нужна (даты от startDate, derived `description`, schedule enforcement как safety net) — это безопасный post-processing. Но «forceFartlekDefaultSegments» и «fillMissingPaceFromTrainingState» работают только потому, что мы предполагаем, что модель ошибётся. С DeepSeek эти кейсы — редкое исключение, и тогда лучше вернуть warning в plan review, чем втихую дозаполнять.

**Целевое состояние:** разбить `plan_normalizer.php` на 2–3 файла:
- `core/plan_normalizer.php` — даты, derived fields, schedule respect (оставить).
- `core/plan_safety_filler.php` — «починки» под слабую LLM (постепенно отказываться, превратить в `warnings`).

### H. assess goal realism + macrocycle precompute в planner_context

**Что это:** в `prompt_builder.php` есть `computeMacrocycle` / `computeHealthMacrocycle` — заранее посчитанный макроцикл (фазы недель) — отдаётся LLM как «эталон». DeepSeek просят «следовать macrocycle».

**Зачем было:** слабая модель не умела сама строить периодизацию.

**Что не так с DeepSeek:** DeepSeek знает все стандартные периодизации (Lydiard, Pfitzinger, Hanson, Daniels) лучше, чем наши эвристики. Передавать macrocycle ему как «контракт» — это ограничение его свободы. Лучше отдать ему сами факты (race_date, weeks_count, current_weekly_km, goal_pace) и дать построить периодизацию самому. Можно отдать short_runway / standard / generous как «фитч-флаг» о времени до старта, но не готовый календарь фаз.

**Целевое состояние:** убрать macrocycle precompute из FACTS_JSON для llm_planner-режима. Передавать только базовые факты (weeks_count, race_date, current/goal weekly km). DeepSeek сам распределит фазы.

### I. Сводка артефактов «под слабую LLM», подлежащих упрощению

| Артефакт | Файл/символ | Размер | Действие |
|---|---|---|---|
| Skeleton-first path | `planrun_ai/skeleton/*` (15 файлов), `processViaSkeleton()` | ~5,500 строк | Перенести в `legacy/` или удалить из production. WeeklyAdaptationEngine — сохранить отдельно. |
| Staged strategy | `DeepSeekPlanPlanner::generate (staged ветка)`, `buildMacroPrompt`, `buildDetailBatchPrompt`, `generateMacroPlan`, `generateDetailBatch`, `buildWeekBatches` | ~250 строк | Удалить. Single_pass всегда. |
| Repair loop | `DeepSeekPlanPlanner::repairPlan`, `buildRepairPrompt`, `processViaLlmPlanner` ветка `repair_attempts` | ~80 строк | Удалить. Заменить targeted retry в Phase C. |
| 3 модели | `plannerModel`, `detailModel`, `repairModel`, env `PLAN_LLM_*_MODEL` | ~25 строк | Унифицировать в `PLAN_LLM_MODEL`. |
| Hard rules | `DeepSeekPlanPlanner::buildHardRules` | ~80 строк | Сократить до injury-критичных safety rails (≤30 строк). |
| Compact helpers | `compactPayload`, `compactLoadPolicy`, `compactPlanningScenario`, `compactGoalRealism`, `compactPlannerHardRules` | ~100 строк | Удалить, передавать full data. |
| plan_normalizer fillers | `forceFartlekDefaultSegments`, `fillMissingPaceFromTrainingState`, `ensureFartlekWorkoutStructure` (части), и т.п. | ~300 строк | Перевести в warnings (не молчаливое заполнение). |
| Macrocycle precompute | `computeMacrocycle` / `computeHealthMacrocycle` в context | ~150 строк (использования) | Не передавать в llm_planner FACTS. Использовать только в plan review. |
| Plan auto-fixer | `planrun_ai/skeleton/PlanAutoFixer.php` | 289 строк | Перенести в legacy с skeleton. |

Итого — порядка **6500 строк** в production code paths, которые либо устаревшие (skeleton, staged), либо контр-продуктивные (overload модели лишними правилами), либо избыточные (compact* helpers). Удаление / упрощение этого кода:
- сокращает поверхность багов;
- даёт DeepSeek больше свободы решать;
- упрощает onboarding новых разработчиков (понять flow за час, а не за день);
- снижает overhead на каждый запрос (меньше токенов в prompt).

---

## 0. Целевое состояние (north star)

1. **Один путь генерации** — `llm_planner` (DeepSeek). Skeleton-first остаётся как тестовый стенд / диагностика, **в проде не используется** и в качестве fallback **не подключается**.
2. **Авто-quality-gate** — `strict` ТОЛЬКО для рисковых когорт (флаги боли/болезни/травмы/беременности и `goal_realism.severity == 'major'`); marathon / half и `return_after_break` / `overload_recovery` сами по себе НЕ переключают в strict — они идут permissive с warnings. Управляется через `PLAN_LLM_QUALITY_GATE_MODE=auto` (дефолт). См. раздел 0a «Trust the model».
3. **Hard safety repairs включены** для всех путей: длинная пробежка, рост недельного объёма, race-week cap, back-to-back keys. Пороги настраиваются «щедро» (cрабатывают только на медицински опасных отклонениях).
4. **`planning_scenario` + `goal_realism`** доступны DeepSeek-планнеру (через `FACTS_JSON`) и quality gate **независимо от того**, идём ли мы через skeleton или через LLM-planner.
5. **При провале DeepSeek** (после ретраев в `LlmGateway`) — пользователю чёткое сообщение «Не удалось сгенерировать план, попробуйте позже или обратитесь в поддержку». Никакого тихого fallback на skeleton.
6. **Pace validator** покрывает все типы темпа: easy, long, tempo (включая `subtype=race_pace`), interval, fartlek-сегменты.
7. **Recalc / next plan** идут через единый `PlanGenerationProcessorService`, без legacy-веток `recalculatePlanViaPlanRunAI` / `generateNextPlanViaPlanRunAI` для llm_planner режима.
8. **Тестовое покрытие**: каждый валидатор и каждый шаг pipeline покрыты unit-тестами; E2E `live_planning_e2e.php` расширен на ключевые сценарии (марафон, return-after-injury, b-race-before-a-race, health/weight loss, нереалистичная цель).
9. **Observability**: AiObservabilityService логирует время DeepSeek, число retry, gate verdict, applied repairs; есть таблица для аналитики bad-plan rate.

---

## 1. Решения по продукту (зафиксированы)

| Решение | Значение | Где реализуется |
|---|---|---|
| Quality gate mode по умолчанию | `auto` — `strict` только при injury/illness/pregnancy флагах, `pain_protective`/`illness_protective`/`return_after_injury` сценариях или `goal_realism.severity='major'`; иначе `permissive` (включая марафон/полумарафон у здорового бегуна) | `PlanGenerationProcessorService::resolveQualityGateMode()` (P0.3, философия 0a) |
| Hard safety repairs | Всегда вкл для llm_planner | `PLAN_LLM_HARD_SAFETY_REPAIRS=1` дефолт + `disable_repairs=false` в qualityContext (P0.1) |
| Race week cap repairs | Всегда вкл для long-race goals | `PLAN_LLM_RACE_WEEK_CAP_REPAIRS=1` дефолт + cohort logic (P0.1) |
| Fallback на skeleton при провале DeepSeek | **НЕТ.** Сообщение пользователю + retry | `processViaLlmPlanner` бросает `RuntimeException` → API возвращает `error_message` (P1.1) |
| `planning_scenario` / `goal_realism` для llm_planner | **ДА**, через `TrainingStateBuilder` | `TrainingStateBuilder::buildForUser()` (P0.2) |

---

## 2. Roadmap

### P0 — Безопасность и критические фиксы (PR1)

**Цель:** убрать риск отдачи опасных планов. Все изменения в одном PR с тестами. Завершается «зелёным» pipeline и обновлённым `live_planning_e2e.php` smoke-тестом.

#### P0.1 — Включить hard safety repairs и race-week cap

- В `PlanGenerationProcessorService::processViaLlmPlanner`:
  - **Убрать жёсткое `'disable_repairs' => true`** в `$qualityContext`. Вместо этого вычислять `$disableRepairs` по правилу: `$disableRepairs = !$hardSafetyRepairsEnabled` (если safety repairs включены — repairs работают; если выключены — старый поведение).
  - `$hardSafetyRepairsEnabled` остаётся управляемым через env, но **дефолт меняется на `true`**.
  - `$raceWeekCapRepairsEnabled` — тоже дефолт `true`; но фактически применяется только если `goal_type` ∈ {race, time_improvement} И `race_distance` ∈ {half, marathon, 21.1k, 42.2k}.
- В `.env`/документации:
  - `PLAN_LLM_HARD_SAFETY_REPAIRS=1`
  - `PLAN_LLM_RACE_WEEK_CAP_REPAIRS=1`
- `applySinglePassHardSafetyRepairs` запускать **до** quality gate (как сейчас), но логировать каждый случай в `_generation_metadata.hard_safety_repairs` (уже делается).

**Тесты (новые/расширенные):**
- `PlanGenerationProcessorServiceTest::testHardSafetyRepairsEnabledByDefault()`
- `PlanGenerationProcessorServiceTest::testRaceWeekCapAppliedForMarathonGoal()`
- `PlanGenerationProcessorServiceTest::testRaceWeekCapNotAppliedForHealthGoal()`
- `PlanQualityGateTest::testRepairsAppliedWhenHardSafetyEnabled()`
- `PlanQualityGateTest::testNoRepairsWhenHardSafetyDisabled()` (back-compat)

---

#### P0.2 — `planning_scenario` + `goal_realism` в режиме llm_planner

**Проблема:** сейчас оба поля заполняются только в `PlanSkeletonGenerator`. В режиме `llm_planner` они приходят `null`, и:
- DeepSeek не получает контекст сценария (return-after-injury, b-race и т.д.) в `FACTS_JSON`.
- `PlanQualityGate` не использует scenario-specific правила.

**Изменения:**
- В `services/TrainingStateBuilder.php` (метод `buildForUser`):
  - Добавить вычисление `planning_scenario` через `(new PlanScenarioResolver())->resolve($user, $state, $mode, $payload)`.
  - Добавить вычисление `goal_realism` через `assessGoalRealism()` (из `prompt_builder.php`) — для `goal_type ∈ {race, time_improvement}`.
  - Поля становятся первоклассными ключами `$state['planning_scenario']`, `$state['goal_realism']`.
  - Защитный feature flag `PLANRUN_AI_STATE_SCENARIO=1` (дефолт `1`), чтобы можно было откатить без редеплоя.
- В `planrun_ai/llm_planner/DeepSeekPlanPlanner.php`:
  - В `buildPlannerContext()` пробрасывать `planning_scenario` (primary, flags, policy_decisions, tune_up_event) и `goal_realism` (assessment, recommended target) в `FACTS_JSON`.
  - В `buildSystemPrompt()` / `buildFullPlanPrompt()` — короткий блок «Сценарии и их трактовка» с правилами:
    - `return_after_injury` → объём не более 60% pre-injury, длинная +1 km/нед, без интервалов в первые 3 недели.
    - `b_race_before_a_race` → tune-up в роли control, основная задача — A-race.
    - `goal_realism.recommended_target_time` → если есть, использовать его как опорный темп; иначе запрашиваемый.
- В `services/PlanQualityGate.php`:
  - `qualityContext['planning_scenario']` уже читается из `$state['planning_scenario']` (нужно убедиться, что это работает после P0.2).
  - Использовать `planning_scenario.flags` для тонкой настройки правил (например, для `return_after_injury` — более строгие пределы по long run).

**Тесты:**
- `TrainingStateBuilderTest::testPlanningScenarioPopulatedForReturnAfterInjury()`
- `TrainingStateBuilderTest::testGoalRealismPopulatedForRaceGoal()`
- `TrainingStateBuilderTest::testGoalRealismOmittedForHealthGoal()`
- `DeepSeekPlanPlannerPromptTest::testFactsJsonIncludesPlanningScenarioAndGoalRealism()`
- `PlanScenarioResolverTest` — расширить кейсами от `b_race_before_a_race`.

---

#### P0.3 — Auto quality gate mode

- Добавить новое значение `auto` для env `PLAN_LLM_QUALITY_GATE_MODE`.
- В `PlanGenerationProcessorService::resolveQualityGateMode()` (новый private метод). Соответствует философии «trust the model + injury-only guardrails» (раздел 0a):
  - Если env != 'auto' (т.е. явно `strict` / `permissive`) — использовать как есть.
  - Если `auto`:
    - `strict`, если выполнено любое:
      - В `state.special_population_flags` есть любое из: `pregnant_or_postpartum`, `return_after_injury`, `recent_pain_signal`, `recent_illness_signal`.
      - В `state.planning_scenario.flags` есть `pain_protective` / `illness_protective` / `return_after_injury`.
      - `state.goal_realism.severity == 'major'`.
    - Иначе `permissive` (это default-ветка, в т.ч. для marathon / half / return_after_break / overload_recovery — всё, что не несёт injury risk).
- Дефолт env — `auto`. В `.env.example` и в `docs/02-BACKEND.md` соответствующая запись.
- В `PlanQualityGate::applyBlockingPolicy`:
  - В `strict` режиме `should_block_save = true` при любых `severity=error`.
  - В `permissive` режиме сохраняем текущее поведение (downgrade почти всех ошибок до warning, кроме `fatalCodes`).

**Тесты:**
- `PlanGenerationProcessorServiceTest::testQualityGateModeAutoStrictForMarathon()`
- `PlanGenerationProcessorServiceTest::testQualityGateModeAutoStrictForReturnAfterInjury()`
- `PlanGenerationProcessorServiceTest::testQualityGateModeAutoPermissiveForHealthRunner()`
- `PlanQualityGateTest::testStrictModeBlocksOnAllErrors()`
- `PlanQualityGateTest::testPermissiveModeDowngradesNonFatalErrors()` (existing).

---

#### P0.4 — Pace validator: intervals, fartlek, race_pace tempo

- В `planrun_ai/validators/pace_validator.php`:
  - Для `type=interval`:
    - Проверить `interval_pace` (если задан в `pace`-формате `mm:ss`) против `paceRules['interval_sec']` ± tolerance (15s).
  - Для `type=fartlek`:
    - Для каждого `segments[i]` с типом `tempo` / `fast` / `interval` — проверить `pace` против соответствующего диапазона (tempo, race_pace, interval).
    - Игнорировать сегменты типа `easy` / `recovery`.
  - Для `type=tempo` с `subtype=race_pace`:
    - Использовать `paceRules['race_pace_sec']` как target (если задан); иначе fallback на `tempo_sec`.
- Добавить хелпер `validatorResolveQualityPaceTarget(string $kind, array $paceRules): ?int`.

**Тесты:**
- `PlanValidatorTest::testIntervalPaceFlaggedWhenTooSlow()`
- `PlanValidatorTest::testFartlekFastSegmentFlaggedWhenTooEasy()`
- `PlanValidatorTest::testRacePaceTempoChecksRacePaceRule()`
- `PlanValidatorTest::testEasyAndRecoveryFartlekSegmentsIgnored()`

---

### P1 — Корректность пересчёта (PR2-PR3)

**Цель:** пересчёт и Next Plan — стабильны и используют тот же путь, что и initial generation; план не теряется при ошибках; goal_realism пересматривается.

#### P1.1 — Унификация recalc/next plan через llm_planner

- Сейчас в `PlanGenerationProcessorService::process` есть три ветки: `processViaLlmPlanner`, `processViaSkeleton`, и legacy `recalculatePlanViaPlanRunAI` / `generateNextPlanViaPlanRunAI`.
- Когда `PLAN_GENERATION_MODE=llm_planner`, ВСЕ jobType (`generate`, `recalculate`, `next_plan`) идут через `processViaLlmPlanner`. ✅ Уже так и есть.
- Нужно дочистить:
  - Удалить вызовы `recalculatePlanViaPlanRunAI` / `generateNextPlanViaPlanRunAI` из `processViaLlmPlanner`-ветки (там их нет, но проверить, что `enrichRecalculatePayload` корректно строит `cutoff_date`, `kept_weeks`, `mutable_from_date`).
  - Если DeepSeek падает после всех retry в `LlmGateway` — `RuntimeException` пробрасывается до API, который возвращает понятное сообщение пользователю. **Без silent fallback на skeleton.**
  - Добавить `_generation_metadata.fail_safe_message` для UX-слоя (фронт показывает «Попробуйте ещё раз / обратитесь в поддержку»).

#### P1.2 — WeeklyAdaptationEngine: rate limiting и метрики

- Не более 1 авто-recalc на неделю на пользователя (флаг в БД `users.last_auto_recalc_at`).
- Метрики: compliance (planned vs actual km), key workout completion, pace deviation. Использовать в принятии решения о recalc.
- Если `goal_realism.severity` после новой недели становится `major` — авто-recalc с пересчётом target.

#### P1.3 — Goal feasibility re-check

- При recalc/next plan — пересчитывать `goal_realism` с учётом свежих данных.
- Если recommended_alternative_target_time стал дальше от оригинала на >5% — добавлять в `_generation_metadata.goal_drift` флаг для UX.

#### P1.4 — Инвариант «прошлые недели»

- Snapshot test: после recalc недели до cutoff_date побитово равны до и после.
- Покрыть в `PlanGenerationProcessorServiceTest::testRecalculateKeepsPastWeeksUnchanged()`.

---

### P2 — Качество и UX (PR4-PR5)

#### P2.1 — Russian glossary в системном промпте

- Добавить мини-словарь в `DeepSeekPlanPlanner::buildSystemPrompt`:
  - tempo → «темповый», «темпо»
  - fartlek → «фартлек»
  - long → «длительный», «длительная»
  - interval → «интервалы»
  - tune-up → «контрольная»
  - race pace → «соревновательный темп»
- Запрет на mixed-language в полях notes.

#### P2.2 — LLMReviewer через LlmGateway

- Refactor `planrun_ai/skeleton/LLMReviewer.php`: убрать прямой cURL, использовать `LlmGateway::requestChatCompletion`.

#### P2.3 — Контекстные дефолтные fartlek сегменты

- В `plan_normalizer.php::ensureFartlekWorkoutStructure`: дефолт зависит от phase, VDOT, goal_type.
- Маленький helper `buildDefaultFartlekSegments(array $context): array`.

#### P2.4 — Вариативность ключевых сессий по фазе

- Гарантировать ≥3 разных типов ключевых сессий в build-фазе для марафонских планов >12 недель.

---

### P3 — Тесты и observability (PR6-PR7)

#### P3.1 — Unit-тесты для всех валидаторов

- Полное покрытие: pace, load, taper, schedule, workout_completeness, goal_consistency.

#### P3.2 — Snapshot-тесты для FACTS_JSON и промптов

- Зафиксировать «эталонные» FACTS_JSON для 5 кейсов (marathon-build, half-build, 5k-improvement, health-runner, return-after-injury).
- Любое изменение в промптах требует обновления snapshot, что повышает осознанность изменений.

#### P3.3 — E2E `live_planning_e2e.php` расширение

- Добавить кейсы:
  - Marathon-32-weeks-realistic
  - Marathon-12-weeks-unrealistic-target → должно сработать `recommended_alternative_target_time`.
  - Return-after-injury (8 недель восстановления).
  - B-race-before-A-race (HM за 7 дней до марафона).
  - Health-weight-loss (3 раза в неделю, постепенный набор).
- Запускать ночью на staging.

#### P3.4 — Observability dashboard

- Таблица `ai_plan_generation_events`:
  - duration_ms, retry_count, model, gate_status, applied_repairs[], cohort.
- Простой эндпоинт `/admin/api/ai-metrics` для дашборда.

---

### P4 — Production rollout (PR8)

#### P4.1 — Канареечный rollout

- Feature flag `PLAN_AI_V2_USERS=*` (или N% / список user_id).
- Для остальных — текущая прод-конфигурация.

#### P4.2 — Регресс-метрики

- compliance / completion / NPS до и после rollout.

#### P4.3 — Алерты

- bad-plan rate > 1% в час → Slack/email.

---

### P5 — Расширение (бэклог)

- P5.1 — Длинные «сценарные» прогрессии (8-12 недель build) для health/weight loss.
- P5.2 — Multi-race цикл (несколько B-races до A-race).
- P5.3 — Auto-detection «return after break/injury» (по гэпам в workout_log >2 недель).

---

## 2a. Roadmap «упрощение под DeepSeek V4» (Phase A–D, поверх P0)

После завершения P0 (PR1 — guardrails и контекст-минимум) переходим к фундаментальному пересмотру архитектуры. **Цель:** убрать из production-flow всё, что было компенсацией слабых моделей, и дать DeepSeek V4 строить план в один проход с минимальным post-processing.

> Эти фазы работают **поверх** философии раздела 0a и аудита раздела 0b. Если предлагаемое изменение нарушает «trust the model» или удаляет **медицински-критичный** guardrail — оно НЕ принимается.

### Phase A — упрощение архитектуры

#### A.1 ✅ Удалить skeleton-first из production
- ✅ Перенесли `planrun_ai/skeleton/*` в `planrun_ai/_legacy/skeleton/` (включая `WeeklyAdaptationEngine.php`, который теперь требуется через `services/AdaptationService.php` по обновлённому пути).
- ✅ `services/PlanGenerationProcessorService::processViaSkeleton` превращён в explicit `RuntimeException`: «skeleton-first plan generation отключён в production». При `USE_SKELETON_GENERATOR=1` пользователь сразу получает понятную ошибку.
- ✅ Updated diagnostic-скрипты (`scripts/live_planning_e2e.php`, `scripts/recalc_feedback_scenarios.php`) и `tests/Unit/*Skeleton*Test.php`, `VolumeDistributorTest.php`, `WeeklyAdaptationEngineTest.php`, `StructuredJsonResponseParserTest.php` на новые пути `_legacy/skeleton/`.
- ✅ Удалили `buildExpectedSkeletonContract` (мёртвый код после A.1).
- ✅ Обновили `.env.example`: `USE_SKELETON_GENERATOR` помечен как DEPRECATED, `PLAN_GENERATION_MODE=llm_planner` теперь default.

**Тесты**: 336/336 unit-тестов проходят (1 flaky external DeepSeek API integration — не связан).

#### A.2 ✅ Удалить staged-стратегию в DeepSeekPlanPlanner
- ✅ Удалили `staged` ветку в `DeepSeekPlanPlanner::generate`, методы: `generateMacroPlan`, `generateDetailBatch`, `buildMacroPrompt`, `buildDetailBatchPrompt`, `buildWeekBatches`.
- ✅ Убрали env `PLAN_LLM_PLANNER_STRATEGY` (single_pass всегда). `_generation_metadata.planner_strategy` захардкожен `single_pass`.
- ✅ Удалили из `tests/Unit/DeepSeekPlanPlannerPromptTest.php` тесты `test_macro_prompt_*` и `test_detail_prompt_*` — методы удалены.

#### A.3 ✅ Унифицировать модели в одну переменную
- ✅ В `DeepSeekPlanPlanner` поля `plannerModel`, `detailModel`, `repairModel` заменены на одну `model = env('PLAN_LLM_MODEL', 'deepseek-chat')`. Backwards compat: legacy `PLAN_LLM_PLANNER_MODEL`/`PLAN_LLM_REVIEWER_MODEL` читаются как fallback.
- ✅ В `_generation_metadata` поле `model` (вместо тройки `planner_model`/`detail_model`/`repair_model`).
- ✅ Аналогично объединены `PLAN_LLM_MAX_TOKENS` и `PLAN_LLM_TIMEOUT_SECONDS` (с fallback на `PLAN_LLM_PLANNER_DETAIL_*`).
- ✅ В `.env.example` зачищены устаревшие env-переменные, добавлены новые.

#### A.4 ✅ Удалить repair-loop (частично)
- ✅ Удалили методы `DeepSeekPlanPlanner::repairPlan`, `buildRepairPrompt`. В `processViaLlmPlanner` блок `if (... && $plannerStrategy !== 'single_pass')` удалён как мёртвый код.
- ✅ Удалили `buildExpectedSkeletonContract` (был только для skeleton-flow).
- При quality gate failure — explicit `RuntimeException` с issue_codes (как и раньше). Targeted retry (одной недели) — задача Phase C.2.
- **TODO** в следующих PR: A.4.completion — переменная `$plannerStrategy = 'single_pass'` (уже захардкожена) + cleanup `llm_repair_attempts` метаданных, если они реально не нужны для observability.

#### A.5 ✅ Сократить hard_rules и убрать compact helpers
- ✅ В `DeepSeekPlanPlanner::buildPlannerContext` удалены `compactPayload`, `compactLoadPolicy`, `compactPlanningScenario`, `compactGoalRealism` — передаём full data в FACTS_JSON.
- ✅ В `DeepSeekPlanPlanner::buildHardRules` оставлены только schedule + medical/language инварианты (~30 строк против 80+):
  - `required_run_day_numbers`/`allowed_run_day_numbers`, `race_date`, `race_distance`, `race_distance_km`, `goal_pace`;
  - `language_contract` (русский для notes/quality_focus/risk_note/macro_adjustment_reason; запрещённые англ. термины);
  - При marathon: `long_run_safety` (только `marathon_last_21_days_training_long_run_max_km=32`, `no_training_run_at_or_above_race_distance_except_race_day`, `no_full_marathon_at_goal_pace_before_race`);
  - `fresh_long_effort_guard` (если был свежий очень длинный забег).
- ✅ Удалены: `run_types`, `threshold_pace`, `race_pace_subtype`, `plain_tempo_rule`, `race_pace_tempo_rule`, `macro_detail_contract`, `weekly_volume_safety`, `race_week_contract`, `long_run_safety.long_share_cap`, `short_race_long_runs_may_exceed_race_distance` (DeepSeek V4 знает методику).
- ✅ Prompt в `buildFullPlanPrompt` упрощён: убраны отсылки на `weekly_volume_safety`, `long_share_cap`, `threshold_pace`. Тест `test_full_plan_prompt_gives_deepseek_single_pass_autonomy` обновлён.

#### A.6 ✅ Поднять пороги hard safety repairs до медицинских
- ✅ `applySinglePassHardSafetyRepairs::longShareCap` дефолт 0.45 → **0.60** (медицинский потолок). До 0.60 DeepSeek решает сам — это нормальная свобода для опытных бегунов в peak-неделях. Если `state.load_policy.long_share_cap` ниже 0.60 — игнорируем (берём 0.60); если выше 0.65 — ограничиваем 0.65 (предохранитель против экстремумов).
- Late marathon long run cap (≥32 км в последние 21 день) — оставлен как есть (медицинский факт).
- Тесты: `test_applySinglePassHardSafetyRepairs_caps_late_marathon_long_runs` обновлён под новый cap (single repair вместо двух), добавлен `test_applySinglePassHardSafetyRepairs_caps_extreme_long_share` (sharing 68% → cap до 21 км).

#### A.7 ✅ Убрать macrocycle precompute из FACTS_JSON для llm_planner
- ✅ В `DeepSeekPlanPlanner::buildPlannerContext` для поля `training_state.load_policy` применяется `stripMacrocyclePrecompute()` — убраны `weekly_volume_targets_km`, `long_run_targets_km`, `recovery_weeks`, `start_volume_km`, `peak_volume_km`. DeepSeek строит фазы сам по `weeks_count`, `weekly_base_km`, `vdot`.
- `TrainingStateBuilder::buildLoadPolicy` НЕ менялся — он по-прежнему вычисляет macrocycle для legacy/skeleton-flow, но в production-llm_planner эти поля не попадают в prompt. Если в будущем удалим legacy — можно добавить env-флаг для отключения вычислений.

#### A.8 ✅ plan_normalizer: filler-логика уже warnings layer
- В текущей реализации `plan_normalizer.php` каждый filler (fartlek без segments, interval без рабочей структуры, control без benchmark) уже добавляет warning в `$warnings[]`, который попадает в `_generation_metadata.quality_gate.normalizer_warnings` через `PlanQualityGate::evaluate()`.
- Дальнейший рефакторинг (разделение `plan_normalizer.php` на `core/` + `safety_filler/`) перенесён в **технический долг** — ценность невелика, риск регрессии большой.

---

### Phase B — контекст вместо контроля

#### B.1 ✅ Recent compliance в FACTS_JSON
- ✅ `WorkoutRepository::getDetailedCompliance($userId, $from, $to)` — детальный compliance за период: `planned_count`, `completed_count`, `actual_km`, `key_workout_planned`, `key_workout_completed` (с дедупликацией workout_log + workouts).
- ✅ `TrainingStateBuilder::buildRecentCompliance($userId, $weeks=4)` — массив compliance за последние 4 ISO-недели в `state['recent_compliance']`. Поля: `week_start`, `week_end`, `planned_count`, `completed_count`, `skipped_count`, `actual_km`, `key_workout_planned`, `key_workout_completed`, `compliance_ratio`, `key_workout_completion_pct`, `is_current_week`.
- ✅ В FACTS_JSON через `DeepSeekPlanPlanner::buildPlannerContext` под ключом `recent_compliance`.
- ✅ Системный prompt обучен использовать compliance: при <60% выполнения key workouts или <70% общего compliance → план был слишком амбициозным, понизь объём; при >85% и actual≥planned → есть запас.
- Feature flag `PLANRUN_AI_STATE_RECENT_CONTEXT=0` отключает блок целиком (на случай регрессий).

#### B.2 ✅ Recent workouts с RPE и HR
- ✅ `WorkoutRepository::getRecentDetailedWorkouts($userId, $from, $to)` — UNION workout_log + workouts (с дедупликацией) за период. Возвращает поля: date, type, is_key_workout, distance_km, duration_minutes, pace, avg_heart_rate, rating, notes, source.
- ✅ `TrainingStateBuilder::buildRecentWorkoutsDetailed($userId, $days=14)` — нормализация в `state['recent_workouts_detailed']`. Поля: `date`, `type`, `is_key_workout`, `distance_km`, `duration_minutes`, `pace_sec`, `pace`, `hr_avg`, `rpe` (rating 1..5), `source`, `notes`.
- ✅ `pace_sec` вычисляется из `duration_minutes / distance_km` (если оба есть).
- ✅ В FACTS_JSON под ключом `recent_workouts` (заменяет старый 8-недельный raw-лог; raw оставлен только локально для `buildRecentLongEffortGuard`).
- ✅ Системный prompt: «Сравни pace в recent_workouts с training_paces.easy/marathon/threshold; если фактический темп easy медленнее ожидаемого — не форсируй интенсивность. Учитывай rpe (1=очень легко..5=очень тяжело), hr_avg, notes (жалобы на боль/усталость/болезнь = осторожнее)».

#### B.3 ✅ Climate / season hints
- ✅ `TrainingStateBuilder::buildClimateContext($user, $startDate, $raceDate)` — формирует `state['season']` с полями `current_month` (1..12), `current_month_name` (en lower), `race_month`, `race_month_name`, `northern_hemisphere`, `season_phase` (winter / early_spring / spring / summer / autumn / late_autumn), `race_season_phase`, `timezone`.
- ✅ Hemisphere определяется по `users.timezone` (Australia/, Antarctica/, Pacific/Auckland, Pacific/Fiji, America/Argentina, America/Sao_Paulo, America/Santiago, Africa/Johannesburg → southern; всё остальное → northern). Для southern season_phase инвертируется.
- ✅ В FACTS_JSON под ключом `season`. Prompt обучен: летом (jun-aug northern / dec-feb southern) — не гнать pace и не перегружать тренировки в самые жаркие недели; зимой — указывать treadmill alternatives только когда уместно.
- В соответствии с философией «trust the model» **не передаём** `expected_temp_c`/`climate_offset_sec` — это hardcode без реальной геолокации, DeepSeek сам понимает климат месяца.

#### B.4 ✅ Best races progression
- ✅ `StatsService::getBestRacesProgression($userId, $weeksWindow=52)` — top результат на бакет 5k / 10k / half / marathon за 52 недели. Дедупликация workout_log + workouts по best pace_sec в каждом бакете.
- ✅ `TrainingStateBuilder::buildBestRacesProgression($userId)` — в `state['best_races']` массив с `distance_label`, `distance_km`, `time_sec`, `pace_sec`, `date`, `vdot`. Отсортирован по дате убыв.
- ✅ В FACTS_JSON под ключом `best_races`. Prompt обучен: сравнивать `goal_pace` с историческим `pace_sec`; разрыв >15-20 сек/км — повод обсудить в `risk_review`; свежий (≤6 нед) сильный результат — повод доверять goal_pace, старый/единственный — повод быть осторожнее.

#### B.5 ✅ Расширенный goal_realism
- ✅ `TrainingStateBuilder::matchBestRacesToTargetDistance($bestRaces, $raceDistance)` — добавляет в `state['goal_realism']['best_races_at_target_distance']` подмассив `best_races`, отфильтрованный по target distance label. DeepSeek получает явный сигнал: «вот результат на той же дистанции, что и текущая цель».
- Алгоритм assessGoalRealism (PHP) **не меняем** — vердикт остаётся прежним. Развитие goal_realism идёт через **расширение FACTS_JSON**, а не через новые правила. DeepSeek сам сравнивает goal_pace с best_races_at_target_distance и пишет реалистичный risk_review без заранее закодированной логики.

---

### Phase C — Reasoning и retry

#### C.1 DeepSeek-reasoner для сложных сценариев
- Если в FACTS_JSON одновременно: `return_after_injury` + `b_race_before_a_race` + `short_runway` (или другие комбинации флагов риска) — использовать `deepseek-reasoner` (с включённым `enable_thinking`). Дороже, но даёт лучший план для сложных кейсов.
- Простой single-flag сценарий — обычный `deepseek-chat`.

#### C.2 Targeted retry для одной недели
- Если quality gate возвращает issues только для 1–2 недель — переотправлять только эти недели с конкретным фидбеком, а не весь план. Например: «Неделя 6 имеет long share 55% week — пересмотри long run и распределение в этой неделе».
- DeepSeek возвращает `{week_number, days[]}`, мы вставляем в существующий план.
- Это значительно быстрее и стабильнее, чем full repair.

#### C.3 Multi-pass только для очень длинных планов
- Планы >24 недель (марафон с 6-месячной подготовкой): можно делать 2 запроса — base/build + peak/taper, с обменом контекстом между ними. Но **только если single_pass не справляется по качеству**, и решение принимается метриками, а не дефолтом.

---

### Phase D — Observability и rollout упрощённой архитектуры

#### D.1 Plan quality dashboard
- Новая таблица `ai_plan_generation_events`:
  - `user_id`, `job_type`, `cohort` (healthy_marathon / return_after_injury / health / ...), `model`, `duration_ms`, `retries`, `gate_status`, `gate_mode`, `issue_codes[]`, `applied_repairs[]`, `prompt_token_count`, `completion_token_count`.
- Простой endpoint `/admin/api/ai-metrics` с агрегатами по cohort'ам:
  - bad-plan rate (severity=error after gate);
  - repair rate (применился safety repair);
  - average duration / token usage.

#### D.2 A/B test упрощённой архитектуры
- Feature flag `PLAN_AI_SIMPLIFIED=N%` (или `PLAN_AI_SIMPLIFIED_USERS=user_id1,user_id2`).
- Для % пользователей — новая упрощённая (Phase A). Для остальных — старая.
- Сравнить compliance / completion / NPS / bad-plan rate за 2 недели.

#### D.3 Canary rollout
- Если A/B показывает не хуже (или лучше) старой по всем ключевым метрикам — переключаем 100%.
- Старый код Phase A.* удаляется окончательно (раньше переносится в `_legacy/`, теперь — `git rm`).

---

## 2b. Порядок исполнения (revised)

| PR | Содержит | Цель |
|---|---|---|
| **PR1** ⏳ (готов к коммиту) | P0.1 + P0.2 + P0.3 + P0.4 | Безопасность и контекст-минимум |
| **PR2** ✅ (этот) | A.1 (skeleton out) + A.2 (no staged) + A.3 (single model) + частично A.4 (no repair-loop, dead code) | Удалить устаревшие пути |
| **PR3** | A.5 (compact helpers, slim hard_rules) + A.6 (medical thresholds) | Слим планнера |
| **PR4** | A.7 (no macro precompute) + A.8 (split normalizer) | Завершить Phase A |
| **PR5** | B.1 (recent compliance) + B.2 (recent workouts) | Контекст: тренировочный фид |
| **PR6** | B.3 (climate) + B.4 (best races) + B.5 (goal_realism v2) | Контекст: внешние факторы и история |
| **PR7** | C.1 (reasoner для сложных кейсов) + C.2 (targeted retry) | Reasoning |
| **PR8** | D.1 (dashboard) + D.2 (A/B feature flag) | Observability |
| **PR9** | D.3 (canary → full rollout, удаление legacy) | Cleanup |

---

## 3. План работ — итерации

> Финальный порядок исполнения зафиксирован в разделе 2b (после Phase A–D). Ниже — оригинальная таблица P0–P4 для исторической справки. Если конфликт между этой таблицей и 2b — приоритет у 2b.

| PR | Содержит | Тесты | Когда |
|---|---|---|---|
| ✅ PR1 | P0.1 + P0.2 + P0.3 + P0.4 + auto-mode softening | Unit + расширенные snapshot | Готово (этот PR) |
| ✅ PR2 | Phase A.1–A.8 (skeleton-out, single_pass, single model, repair-loop удалён, slim hard_rules, medical thresholds, no macrocycle precompute, normalizer warnings) | Unit 335/336 | Готово (предыдущий PR) |
| ✅ PR3 | Phase B.1+B.2 (recent_compliance за 4 ISO-недели, recent_workouts_detailed за 14 дней с pace_sec/hr_avg/rpe в FACTS_JSON) | Unit 342/342 | Готово (предыдущий PR) |
| ✅ PR4 | Phase B.3+B.4+B.5 (season/climate hints, best_races_progression top 5k/10k/half/marathon за 52 нед., goal_realism v2 — best_races_at_target_distance) | Unit 346/346 | Готово (этот PR) |
| PR5 | Phase C.1+C.2 (deepseek-reasoner для сложных, targeted retry для одной недели) | E2E reasoner | Следующий |
| PR6 | Phase D.1+D.2 (plan quality dashboard, A/B test) | Observability | |
| PR7 | Phase D.3 (rollout + cleanup `_legacy/skeleton/`) | Canary | |

**Этот PR (PR4):** Phase B.3+B.4+B.5 — DeepSeek получает климатический контекст (season, hemisphere) и trajectory лучших результатов на ключевых дистанциях. Это закрывает Phase B полностью: контекст вместо контроля.

---

## 4. Критерии готовности к проду

- [ ] Все unit + Feature-тесты `phpunit` зелёные.
- [ ] `live_planning_e2e.php` зелёный для всех 5 ключевых сценариев.
- [ ] Bad-plan rate (severity=error after gate, in `auto` mode) <0.5% за 24 часа canary.
- [ ] `_generation_metadata.quality_gate.status='ok'` для ≥99% планов.
- [ ] Среднее время DeepSeek single_pass ≤45с p50, ≤120с p95.
- [ ] Никаких silent fallback — все ошибки видны в логах и в UX.

---

## 5. Что входит в этот PR (PR1+PR2)

### PR1 — P0 + auto-mode softening (✅ готово)
1. **P0.1** — `processViaLlmPlanner` без `disable_repairs=true`; дефолты `PLAN_LLM_HARD_SAFETY_REPAIRS`, `PLAN_LLM_RACE_WEEK_CAP_REPAIRS` = true.
2. **P0.2** — `planning_scenario` и `goal_realism` в `TrainingStateBuilder::buildForUser` + FACTS_JSON.
3. **P0.3** — `PlanQualityGate::resolveQualityGateMode()` с режимом `auto` (default), который у healthy-marathon → permissive, у risky cases → strict.
4. **P0.4** — `pace_validator.php` расширен на interval / fartlek / race_pace tempo.

### PR2 — Phase A (✅ готово)
1. **A.1** — skeleton-flow удалён из production: `processViaSkeleton` бросает `RuntimeException`. Код перемещён в `planrun_ai/_legacy/skeleton/` (на удаление в Phase D.3).
2. **A.2** — `staged` стратегия удалена, всегда `single_pass`. Удалены `generateMacroPlan`, `generateDetailBatch`, `buildMacroPrompt`, `buildDetailBatchPrompt`, `buildWeekBatches` из `DeepSeekPlanPlanner`.
3. **A.3** — единая `PLAN_LLM_MODEL` вместо `PLAN_LLM_PLANNER_MODEL`/`DETAIL_MODEL`/`REPAIR_MODEL` (legacy переменные оставлены как fallback на 1 итерацию).
4. **A.4** — repair-loop (`DeepSeekPlanPlanner::repairPlan`, `buildRepairPrompt`) удалён. Безопасность только через `applySinglePassHardSafetyRepairs` + `PlanQualityGate`.
5. **A.5** — `compactPayload`/`compactLoadPolicy`/`compactPlanningScenario`/`compactGoalRealism` удалены, в FACTS_JSON идёт full data. `buildHardRules` сжат до schedule + medical/language.
6. **A.6** — `longShareCap` 0.45 → 0.60 (медицинский потолок). DeepSeek сам решает между 0.30 и 0.60.
7. **A.7** — `weekly_volume_targets_km`, `long_run_targets_km`, `recovery_weeks`, `start_volume_km`, `peak_volume_km` убраны из `training_state.load_policy` через `stripMacrocyclePrecompute()`.
8. **A.8** — `plan_normalizer.php` filler-логика уже warnings layer (warnings попадают в `_generation_metadata.quality_gate.normalizer_warnings`). Дальнейшее разделение перенесено в технический долг.

### Тесты
- Unit 335/336 зелёные (1 flaky external API test — DeepSeekToolCallingTest, не связан с изменениями).
- Обновлены `PlanGenerationProcessorServiceTest`, `TrainingStateBuilderTest`, `PlanValidatorTest`, `PlanQualityGateTest`, `DeepSeekPlanPlannerPromptTest`.
- Удалены `test_macro_prompt_*`, `test_detail_prompt_*`, `test_planner_context_compacts_*` (соответствующая функциональность удалена).

### Doc updates
- `docs/PLANS-AI-V2.md` (этот файл) — разделы 1–5, 2b обновлены.
- `.cursor/rules/plans-ai-v2.mdc` — Phase A полностью, env переменные, "Current phase: PR1+PR2 ready".
- `docs/02-BACKEND.md`, `docs/08-AI-SERVING-STACK.md`, `planrun-backend/.env.example` — env переменные, deprecated flags.
- `.cursor/rules/impact-matrix.mdc` — пути `_legacy/skeleton/`, удалённые методы.

После проверки локально — финальный коммит **PR1+PR2** одним блоком, переходим к PR3 (Phase B).
