# Red Status Audit — 2026-04-03

Этот файл фиксирует всё, что на момент проверки находится в "красном" состоянии: падающие проверки, подтверждённые runtime/logic regression и разрывы между модулями.

## Полные сырые списки

- `lint`: `docs/reports/red-status-2026-04-03/lint-full.txt`
- `phpunit unit`: `docs/reports/red-status-2026-04-03/phpunit-unit-full.txt`
- `phpunit feature (TrainingPlanControllerTest)`: `docs/reports/red-status-2026-04-03/phpunit-feature-training-plan.txt`

## Что в красном состоянии

### 1. Frontend lint

- Команда: `npm run lint`
- Статус: `FAILED`
- Код выхода: `1`
- Итого: `297 errors`, `22 warnings`, `74` затронутых файла

Наиболее проблемные файлы:

- `src/screens/SettingsScreen.jsx` — `135`
- `src/utils/calendarHelpers.js` — `30`
- `src/api/ApiClient.js` — `10`
- `src/components/Calendar/WeekCalendar.jsx` — `8`
- `src/screens/CalendarScreen.jsx` — `7`
- `src/components/Calendar/ResultModal.jsx` — `6`
- `src/components/Calendar/WorkoutCard.jsx` — `6`

Типы проблем:

- `no-unused-vars`
- `no-empty`
- `react-hooks/exhaustive-deps`
- `no-mixed-spaces-and-tabs`
- `react/no-unescaped-entities`
- `no-case-declarations`
- `no-constant-condition`
- `no-async-promise-executor`

Отдельно подтверждённый runtime-риск из lint:

- `src/components/Stats/ActivityHeatmap.jsx` — conditional hook order (`react-hooks/rules-of-hooks`)

### 2. Backend unit tests

- Команда: `php ./vendor/bin/phpunit --testsuite Unit --display-errors --display-warnings`
- Статус: `FAILED`
- Код выхода: `2`
- Итого: `173 tests`, `850 assertions`, `45 errors`, `15 failures`, `7 warnings`

#### 2.1. Отсутствующие функции / сломанный validation-eval контур

Unit-тесты не находят следующие функции:

- `decodeGeneratedPlanResponse`
- `maybeApplyCorrectiveRegenerationToPlan`
- `applyTrainingStatePaceRepairs`
- `applyControlWorkoutFallback`
- `applyTrainingStateLoadRepairs`
- `applyTrainingStateMinimumDistanceRepairs`
- `applyTrainingStateWorkoutDetailFallbacks`
- `buildTrainingStateBlock`
- `applyScheduleOverridesToUserData`
- `resolveRecalculationCutoffDateValue`
- `isRunningRelevantWorkoutEntry`
- `normalizePreferredDayKeys`

Дополнительно в коде используется, но не найдена в проекте:

- `buildPlanValidationContext`

Затронутые места:

- `planrun-backend/tests/Unit/PlanGeneratorCorrectivePassTest.php`
- `planrun-backend/tests/Unit/PlanNormalizerTest.php`
- `planrun-backend/tests/Unit/PromptBuilderTrainingStateTest.php`
- `planrun-backend/tests/Unit/RecalculationContextTest.php`
- `planrun-backend/planrun_ai/validators/schedule_validator.php`
- `planrun-backend/scripts/eval_plan_generation.php`

#### 2.2. Логические регрессии, подтверждённые падающими тестами

- `normalizeTrainingPlan()` больше не переносит `long`, `race`, `tempo`, `interval` по `preferred_days` и `expectedSkeleton`; тесты ожидают перенос/repair, а код сейчас в основном только нормализует день и иногда превращает тренировку в `rest`.
- `buildPaceZonesBlock()` не использует ожидаемый формат/приоритет `training_state.pace_rules`, из-за чего падает проверка на диапазон `5:00 – 5:20`.
- `buildTrainingPlanPrompt()` и `buildRecalculationPrompt()` не включают ожидаемые блоки или формулировки (`WEEK SKELETON`, `WORKOUT INTENT`, `QUALITY DAY CONTRACT`, flexible-mode expectations).
- `computeMacrocycle()` выдаёт слишком много `control_weeks` для marathon-case.
- `PlanSkeletonBuilder` конфликтует с golden policy:
  - кейс `return_after_break_health_easy_only` получает неожиданный `fartlek`
  - кейс `weight_loss_intermediate_four_days_includes_fartlek` не получает обязательный `fartlek`

#### 2.3. PHP warnings в prompt-builder

Unit-тесты также поднимают warnings по отсутствующим ключам:

- `phase_label`
- `weeks_left_in_phase`
- `next_phase_label`
- `description`
- `max_key_workouts`

Файлы/строки:

- `planrun-backend/planrun_ai/prompt_builder.php:2847`
- `planrun-backend/planrun_ai/prompt_builder.php:2848`
- `planrun-backend/planrun_ai/prompt_builder.php:2851`
- `planrun-backend/planrun_ai/prompt_builder.php:2875`
- `planrun-backend/planrun_ai/prompt_builder.php:3148`

### 3. Backend feature test для TrainingPlanController

- Команда: `php ./vendor/bin/phpunit tests/Feature/TrainingPlanControllerTest.php --display-errors --display-warnings`
- Статус: `BROKEN / NON-STANDARD`
- Фактический вывод вместо сводки PHPUnit:

```json
{"success":false,"error":"Требуется авторизация"}
```

Это означает, что тест упирается в ранний JSON-ответ/`exit` из runtime-контроллера и не доходит до нормальной тестовой сводки.

Наиболее вероятная точка разрыва:

- `planrun-backend/controllers/BaseController.php`
- `planrun-backend/controllers/TrainingPlanController.php`

### 4. Подтверждённые runtime / integration regressions

#### 4.1. Нормализатор плана влияет на реальное сохранение плана

Критично, потому что именно этот путь используется при сохранении плана:

- `planrun-backend/planrun_ai/plan_saver.php:52`
- `planrun-backend/planrun_ai/plan_normalizer.php:504`

Проблема:

- параметр `expectedSkeleton` передаётся, но фактически не применяется
- перенос тренировок по `preferred_days` и skeleton не реализован
- вместо repair код в ряде случаев просто сбрасывает тренировку в `rest`

Следствие:

- план может сохраняться в БД без ожидаемой расстановки ключевых тренировок
- расчёт/коррекция плана может расходиться с тем, что ожидают тесты и AI policy

#### 4.2. Heatmap статистики может падать при смене состояния данных

Файл:

- `src/components/Stats/ActivityHeatmap.jsx`

Проблема:

- ранний `return` идёт до `useEffect`, из-за чего при переходе `нет данных -> данные появились` меняется порядок hooks

Следствие:

- экран статистики потенциально нестабилен в реальном UI

#### 4.3. Активен skeleton path, а часть красных проблем относится именно к нему

В текущем окружении:

- `planrun-backend/.env`: `USE_SKELETON_GENERATOR=1`

Это важно, потому что найденные проблемы в:

- `planrun-backend/services/PlanSkeletonBuilder.php`
- `planrun-backend/planrun_ai/prompt_builder.php`

относятся не к мёртвому коду, а к активному пути генерации.

## Что не в красном состоянии

Для границ проверки:

- `npm run build` — проходит
- `php -l` по backend-файлам — проходит
- `php ./vendor/bin/phpunit tests/Feature/AuthTest.php` — проходит

## Итог

Красный статус сейчас есть сразу в трёх слоях:

1. `frontend quality gate` — lint красный
2. `backend regression gate` — unit tests красные
3. `business logic / integration` — подтверждённые регрессии в нормализаторе плана, skeleton policy и одном React-компоненте

Полный построчный список ошибок и падений сохранён в raw-файлах рядом с этим отчётом.
