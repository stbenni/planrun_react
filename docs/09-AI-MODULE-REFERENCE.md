# PlanRun - ручной справочник по AI-модулю

Этот документ собран **вручную по исходникам** `planrun-backend/planrun_ai/*`, `services/PlanGenerationProcessorService.php`, `services/TrainingStateBuilder.php` и связанным skeleton/validator-модулям.

Задача документа: объяснить, **что реально исполняется**, где принимаются решения, какие модули считаются источником истины для чисел, а какие только дописывают текст.

## 1. Реальные режимы работы

В проекте существуют три самостоятельных AI-сценария:

1. `Генерация плана с нуля`
   Используется при первом создании плана.
2. `Пересчёт плана`
   Сохраняет уже пройденные недели и генерирует только хвост.
3. `Новый план после завершения предыдущего`
   Собирает полную историю старого цикла и стартует следующий план не с нуля, а от фактической формы.

Для этих сценариев backend идёт через `LLM planner` (DeepSeek V4):

- `LLM planner` (production)
  `TrainingStateBuilder -> DeepSeekPlanPlanner -> single_pass prompt -> JSON -> sanitize -> hard safety repairs -> normalize -> PlanQualityGate (auto) -> plan_saver -> chat review`.

PR7 / Phase D.3: `Legacy LLM-first` и `Skeleton-first` пути полностью удалены. Env `USE_SKELETON_GENERATOR` тоже удалён. См. [PlanGenerationProcessorService.php](../planrun-backend/services/PlanGenerationProcessorService.php) — единственный production-путь `processViaLlmPlanner`.

## 2. Главная orchestration-цепочка

```text
TrainingPlanController
  -> TrainingPlanService
  -> PlanGenerationQueueService
  -> scripts/plan_generation_worker.php
  -> PlanGenerationProcessorService
      -> processViaLlmPlanner (DeepSeek V4)
      -> plan_saver.php
      -> plan_review_generator.php
      -> ChatService::addAIMessageToUser()
```

Что здесь важно:

- `TrainingPlanService` не генерирует план сам, он управляет постановкой и статусом job.
- `PlanGenerationProcessorService` - главный orchestrator, в нём сходятся `generate`, `recalculate` и `next_plan`.
- review плана в чат добавляется **после сохранения**, а не в момент генерации ответа LLM.

## 3. Legacy LLM-first path

```text
plan_generator.php
  -> buildTrainingPlanPrompt() / buildRecalculationPrompt() / buildNextPlanPrompt()
  -> callAIAPI()
  -> parseAndRepairPlanJSON()
  -> validatePlanStructure()
  -> plan_normalizer.php
  -> plan_validator.php
  -> plan_saver.php
```

### Роль верхнеуровневых файлов

| Файл | Что делает |
|------|------------|
| [planrun_ai_config.php](../planrun-backend/planrun_ai/planrun_ai_config.php) | Поднимает env-константы `PLANRUN_AI_API_URL`, `PLANRUN_AI_TIMEOUT`, `USE_PLANRUN_AI` и даёт health-check `isPlanRunAIAvailable()` |
| [planrun_ai_integration.php](../planrun-backend/planrun_ai/planrun_ai_integration.php) | Инкапсулирует HTTP-вызов локального AI-service, retry/backoff, `max_tokens` и формирование payload |
| [prompt_builder.php](../planrun-backend/planrun_ai/prompt_builder.php) | Главный слой бизнес-правил: макроцикл, VDOT/pace math, периодизация, prompt blocks, split generation |
| [plan_generator.php](../planrun-backend/planrun_ai/plan_generator.php) | Собирает user data из БД, строит prompt, вызывает AI, repair'ит JSON и решает generate/recalculate/next_plan сценарии |
| [plan_normalizer.php](../planrun-backend/planrun_ai/plan_normalizer.php) | Переводит сырой ответ LLM в структуру, пригодную для сохранения |
| [plan_validator.php](../planrun-backend/planrun_ai/plan_validator.php) | Применяет набор validators к нормализованному плану |
| [plan_saver.php](../planrun-backend/planrun_ai/plan_saver.php) | Сохраняет план транзакционно, пересобирая недели, дни и упражнения |
| [plan_review_generator.php](../planrun-backend/planrun_ai/plan_review_generator.php) | Строит human-readable рецензию плана и потом отправляет её в чат |

### Что делает `plan_generator.php` по сценариям

| Функция | Реальная роль |
|---------|---------------|
| `generatePlanViaPlanRunAI()` | Базовый generate path: user profile -> prompt -> AI -> JSON repair |
| `generateSplitPlan()` | Делит длинный план на чанки и потом склеивает недели обратно |
| `parseAndRepairPlanJSON()` | Чинит markdown fences, лишний текст, bare arrays, trailing commas и single quotes |
| `validatePlanStructure()` | Минимальный structural gate: `weeks[]`, `days[]`, допустимые `type` |
| `recalculatePlanViaPlanRunAI()` | Собирает реальную историю тренировок, compliance, ACWR, detraining, текущую фазу и отдаёт всё в recalc prompt |
| `generateNextPlanViaPlanRunAI()` | Анализирует весь предыдущий цикл: weekly volumes, best long run, best tempo/interval pace, recent form |
| `detectCurrentPhase()` | Сопоставляет уже пройденные недели с исходным макроциклом и выдаёт текущую/следующие фазы |

## 4. `prompt_builder.php`: где лежит реальная математика плана

Этот файл - не просто builder строк. В нём сосредоточены:

- календарная логика дней недели;
- VDOT/pace math;
- макроцикл для race/time_improvement и health/weight_loss;
- формирование prompt'ов для `generate`, `partial`, `recalculate`, `next_plan`.

### Группы функций

| Группа | Основные функции | Что считают |
|--------|------------------|-------------|
| Weekday helpers | `getPromptWeekdayOrder`, `sortPromptWeekdayKeys`, `getPreferredLongRunDayKey`, `extractScheduleOverridesFromReason`, `computeRaceDayPosition` | Порядок дней, preferred long run day, schedule overrides из пользовательского текста |
| Pace / VDOT math | `calculatePaceZones`, `estimateVDOT`, `predictRaceTime`, `getTrainingPaces`, `predictAllRaceTimes`, `assessGoalRealism`, `calculateDetrainingFactor` | Источник pace-zones, VDOT, realism verdict и detraining |
| Macrocycle math | `getDistanceSpec`, `computeMacrocycle`, `computeHealthMacrocycle` | Фазы, recovery weeks, control weeks, progression длительной и weekly volume targets |
| Prompt blocks | `buildUserInfoBlock`, `buildGoalBlock`, `buildPreferencesBlock`, `buildPaceZonesBlock`, `buildTrainingPrinciplesBlock`, `buildMandatoryRulesBlock` | Доменные блоки промпта с уже встроенными правилами плана |
| Prompt entrypoints | `buildTrainingPlanPrompt`, `buildPartialPlanPrompt`, `buildRecalculationPrompt`, `buildNextPlanPrompt` | Финальные prompt'ы для всех generation-сценариев |

### Что особенно важно в макроцикле

- Для race целей `computeMacrocycle()` сам определяет фазы `pre_base`, `base`, `build`, `peak`, `taper`.
- Recovery weeks вставляются каждые 3-4 недели, а control weeks обычно ставятся перед разгрузкой.
- Прогрессия длительной и peak volume ограничены безопасными коэффициентами роста.
- Для health/weight_loss используется отдельный `computeHealthMacrocycle()` без соревновательной periodization.

Именно этот файл определяет, почему у пользователя вообще должны появиться:

- разгрузочные недели;
- контрольные недели;
- long run progression;
- ограничение роста объёма;
- разные типы key workouts по фазам.

## 5. Нормализация и сохранение

### `plan_normalizer.php`

Это один из самых критичных файлов AI-слоя.

Что он делает:

- переводит alias-ы типа `easy_run`, `long-run`, `ofp` в внутренние типы;
- **не доверяет дате из LLM** и всегда вычисляет её от `startDate`;
- для `easy/long/tempo/race/control` пытается достроить `duration_minutes` из `distance_km` и `pace`;
- для `interval` и `fartlek` сам считает итоговую дистанцию;
- для `rest` умеет повышать день до `easy`, если LLM положила туда беговую структуру;
- собирает `description` из структурных полей через `buildDescriptionFromFields()`;
- строит `training_day_exercises`, в том числе парся ОФП/СБУ через `description_parser.php`;
- enforce'ит `preferred_days` и `preferred_ofp_days`, при конфликте превращая день в `rest`;
- возвращает не только `weeks`, но и `warnings`.

Ключевой инвариант:

- `description` считается **derived field**, а не источником истины.
- источником истины считаются `type`, `distance_km`, `pace`, интервальные/сегментные поля и `exercises`.

### `plan_saver.php`

Что важно про сохранение:

- сохранение идёт в транзакции;
- старый план сначала удаляется;
- затем пересоздаются `training_plan_weeks`, `training_plan_days`, `training_day_exercises`;
- при `recalculate` прошлые недели сохраняются, а текущая и будущие перестраиваются заново;
- `Cache::delete("training_plan_{$userId}")` очищает кеш после коммита.

## 6. Валидация качества

### Агрегатор

[plan_validator.php](../planrun-backend/planrun_ai/plan_validator.php) объединяет все validators и сортирует issues по severity/week/code.

Основные функции:

- `collectNormalizedPlanValidationIssues()`
- `validateNormalizedPlanAgainstTrainingState()`
- `shouldRunCorrectiveRegeneration()`
- `scoreValidationIssues()`

### Конкретные validators

| Файл | Что проверяет |
|------|---------------|
| [schedule_validator.php](../planrun-backend/planrun_ai/validators/schedule_validator.php) | 7 дней в неделе, соответствие skeleton'у, бег только в preferred days |
| [pace_validator.php](../planrun-backend/planrun_ai/validators/pace_validator.php) | Коридоры easy/long/tempo pace относительно `training state` |
| [load_validator.php](../planrun-backend/planrun_ai/validators/load_validator.php) | Скачки недельного объёма и подряд идущие ключевые тренировки |
| [taper_validator.php](../planrun-backend/planrun_ai/validators/taper_validator.php) | Слишком тяжёлую race week и слабое снижение объёма перед гонкой |
| [goal_consistency_validator.php](../planrun-backend/planrun_ai/validators/goal_consistency_validator.php) | Несоответствие интенсивности цели, уровню и special population flags |
| [workout_completeness_validator.php](../planrun-backend/planrun_ai/validators/workout_completeness_validator.php) | Пустые tempo/control/interval/fartlek сессии без структуры |

## 7. Skeleton-first path (УДАЛЁН)

PR7 / Phase D.3: всё содержимое `planrun_ai/_legacy/skeleton/` (17 файлов: `PlanSkeletonGenerator`, `VolumeDistributor`, `SkeletonValidator`, `LLMEnricher`, `LLMReviewer`, `PlanAutoFixer`, 8 progression builders, `WeeklyAdaptationEngine`, …) удалено вместе с `services/AdaptationService.php`, `controllers/AdaptationController.php`, route `run_weekly_adaptation`, env `USE_SKELETON_GENERATOR` и связанными тестами/diagnostic-скриптами. Все ссылки в коде стерты. Production использует только LLM planner path (`processViaLlmPlanner`, см. п.3 выше).

## 8. Weekly adaptation

PR7: `WeeklyAdaptationEngine` удалён. Adaptive recalc теперь через DeepSeek-driven recalculate pipeline (chat tool `recalculate_plan`, ручная кнопка пользователя или ручной вызов админа). Cron `weekly_ai_review.php` оставлен только как review-only (пишет AI-комментарий по неделе в чат, без алгоритмических triggers и без изменения плана).

## 9. Второстепенные, но важные файлы

| Файл | Почему важен |
|------|--------------|
| [generate_plan_async.php](../planrun-backend/planrun_ai/generate_plan_async.php) | Старый standalone entrypoint до queue/processor orchestration; до сих пор полезен как reference сценариев generate/recalculate/next_plan |
| [plan_review_generator.php](../planrun-backend/planrun_ai/plan_review_generator.php) | Объясняет, как plan summary превращается в chat review после сохранения |
| [description_parser.php](../planrun-backend/planrun_ai/description_parser.php) | Мост между текстовым `description` и structured OFP/SBU exercises |
| [text_generator.php](../planrun-backend/planrun_ai/text_generator.php) | Старый helper для коротких описаний из exercises |
| [create_empty_plan.php](../planrun-backend/planrun_ai/create_empty_plan.php) | Не генерирует AI-план, но создаёт каркас календаря для manual/self-training mode |

## 10. Практические инварианты для правок

Если меняется AI-слой, руками проверьте:

1. Где теперь источник истины для чисел: LLM или skeleton?
2. Не сломали ли `preferred_days` / `preferred_ofp_days` enforcement в `plan_normalizer.php`.
3. Совпадает ли expected format prompt'ов с тем, что потом умеют repair/normalize/save.
4. Не потеряли ли post-save side effects: активацию плана, review в чат, статус job.
5. Нужно ли обновить не только [02-BACKEND.md](02-BACKEND.md), но и [05-CALL-GRAPH.md](05-CALL-GRAPH.md), [08-AI-SERVING-STACK.md](08-AI-SERVING-STACK.md) и этот документ.
