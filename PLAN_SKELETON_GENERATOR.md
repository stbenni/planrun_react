# План: Новая архитектура генерации тренировочных планов

## Контекст

Текущий подход: промпт ~175KB отправляется в 14B LLM, которая генерирует весь план целиком. Результат — нелогичные темпы, отсутствие прогрессии, скачки объёмов. LLM не справляется с математикой и длинным контекстом.

**Новый подход:** код генерирует полный числовой скелет (объёмы, дистанции, темпы, прогрессия) → LLM получает короткий промпт (~10-15KB) и обогащает деталями (structure интервалов, notes, персонализация по health_notes).

## Архитектура

```
TrainingStateBuilder (существует) → VDOT, темпы, load_policy
PlanSkeletonBuilder (существует)  → типы дней по дням недели
computeMacrocycle() (существует)  → фазы, прогрессия длительной

         ↓ всё это подаётся в ↓

PlanSkeletonGenerator (НОВЫЙ) → полный числовой план
  ├── VolumeDistributor        → распределение km по дням
  ├── IntervalProgressionBuilder → конкретные интервалы по фазам
  ├── TempoProgressionBuilder    → прогрессия темповых
  ├── FartlekBuilder             → фартлек-сегменты
  ├── StartRunningProgramBuilder → бег/ходьба для начинающих
  ├── OfpProgressionBuilder      → ОФП-упражнения
  └── ControlWorkoutBuilder      → контрольные тренировки

         ↓ скелет (~5-10KB JSON) ↓

┌────────────────────────────────────────────────────────┐
│ LLM PIPELINE (до 3 вызовов, цикл самопроверки)        │
│                                                        │
│  Вызов #1: ОБОГАЩЕНИЕ                                  │
│  LLMEnricher → notes, structure, персонализация         │
│       ↓                                                │
│  Код: SkeletonValidator → числа не сломаны? (±5%)      │
│       ↓                                                │
│  Вызов #2: РЕВЬЮ                                       │
│  LLMReviewer → "найди ошибки в логике плана"            │
│       ↓                                                │
│  Если ошибки найдены:                                  │
│    Код: автофикс (пересчёт проблемных недель)           │
│    Вызов #3: повторное РЕВЬЮ (финальная проверка)       │
│  Если ошибок нет:                                      │
│    → готово                                            │
└────────────────────────────────────────────────────────┘

         ↓ обогащённый + проверенный план ↓

plan_normalizer.php (существует) → финальная нормализация
plan_saver.php (существует)      → сохранение в БД
```

## Новые файлы

Все в `planrun-backend/planrun_ai/skeleton/`:

| Файл | Назначение |
|------|-----------|
| `PlanSkeletonGenerator.php` | Оркестратор: собирает state+skeleton+macrocycle, генерирует числовой план |
| `VolumeDistributor.php` | Распределение недельного объёма по дням (long → tempo/interval → easy = остаток) |
| `IntervalProgressionBuilder.php` | Таблица прогрессии интервалов по фазам и дистанции |
| `TempoProgressionBuilder.php` | Прогрессия темповых тренировок |
| `FartlekBuilder.php` | Генерация фартлек-сегментов |
| `StartRunningProgramBuilder.php` | Фиксированные программы бег/ходьба (start_running, couch_to_5k) |
| `OfpProgressionBuilder.php` | ОФП-упражнения с прогрессией |
| `ControlWorkoutBuilder.php` | Контрольные тренировки (перед разгрузочными) |
| `LLMEnricher.php` | Вызов LLM #1: обогащение (notes, structure) + парсинг ответа |
| `LLMReviewer.php` | Вызов LLM #2-3: ревью логики плана, поиск ошибок |
| `SkeletonValidator.php` | Алгоритмическая валидация: числа не изменены (±5%), темпы в зонах |
| `PlanAutoFixer.php` | Автоматический фикс ошибок найденных LLM-ревью (пересчёт недель) |
| `WeeklyAdaptationEngine.php` | Еженедельная проверка план vs факт, решение об адаптации |
| `enrichment_prompt_builder.php` | Построение промптов для обогащения и ревью |

## Модификация существующих файлов

| Файл | Изменение |
|------|-----------|
| `services/PlanGenerationProcessorService.php` | Заменить вызов `generatePlanViaPlanRunAI()` на `PlanSkeletonGenerator` + `LLMEnricher` |
| `planrun_ai/plan_generator.php` | Добавить feature flag `USE_SKELETON_GENERATOR` для постепенной миграции |
| `services/AdaptationService.php` | Заменить заглушку реальной логикой (вызов WeeklyAdaptationEngine) |
| `scripts/weekly_ai_review.php` | Интеграция с WeeklyAdaptationEngine вместо только ревью |
| `.env` | Добавить `USE_SKELETON_GENERATOR=1` |

Файлы **без изменений** (переиспользуются):
- `planrun_ai/TrainingStateBuilder.php` — VDOT, темпы, load_policy
- `services/PlanSkeletonBuilder.php` — типы дней
- `planrun_ai/prompt_builder.php` — `computeMacrocycle()`, `getTrainingPaces()`, `estimateVDOT()`
- `planrun_ai/plan_normalizer.php` — нормализация
- `planrun_ai/plan_saver.php` — сохранение в БД
- `services/PlanGenerationQueueService.php` — очередь

## Детали ключевых алгоритмов

### VolumeDistributor — распределение объёма по дням

Вход: типы дней (из PlanSkeletonBuilder), target_volume_km, long_target_km, фаза, темпы.

Алгоритм:
1. **Long** = longTarget (из макроцикла, уже с прогрессией)
2. **Tempo** = warmup(2km) + tempo_work_km (из TempoProgressionBuilder) + cooldown(1.5km)
3. **Interval** = warmup(2km) + interval_work_km + rest_volume (из IntervalProgressionBuilder) + cooldown(1.5km)
4. **Fartlek** = warmup(2km) + segments_km (из FartlekBuilder) + cooldown(1.5km)
5. **Easy** = (targetVolume - long - tempo - interval - fartlek) / count(easy_days)
6. Easy не менее `load_policy.easy_min_km` (1.5-2.0 км)
7. Если не сходится — пропорционально уменьшаем ключевые или даём warning
8. **Recovery week** — all distances × recovery_cutback_ratio (0.88)

### IntervalProgressionBuilder — прогрессия интервалов

Зависит от фазы, номера недели внутри фазы, race_distance:

**Для марафона/полумарафона (длинные отрезки):**
- build early: 4×800м → 5×800м
- build mid: 4×1000м → 5×1000м
- build late: 3×1600м
- peak: 4×1600м или 3×2000м
- taper: 3×800м

**Для 5к/10к (короткие отрезки):**
- build early: 6×400м → 8×400м
- build mid: 5×600м → 6×600м
- build late: 5×800м → 4×1000м
- peak: 6×800м или 5×1000м
- taper: 4×400м

Rest: 400м (jog) для ≤1000м, 600м для >1000м.
Warmup: 2км, cooldown: 1.5км.

### TempoProgressionBuilder

- base: нет
- build early: 3 km в T-pace (15 мин)
- build mid: 4 km (20 мин)
- build late: 5 km (25 мин)
- peak: 6-7 km (30-35 мин)
- taper: 3 km (15 мин)

Warmup: 2km, cooldown: 1.5km.

### StartRunningProgramBuilder

Фиксированные таблицы (без математики):
- **start_running** (8 нед, 3 дня): бег 1мин/ходьба 2мин ×8 → ... → бег 20мин непрерывно
- **couch_to_5k** (10 нед, 3 дня): бег 1мин/ходьба 2мин → ... → бег 30мин (5км)

Тип: easy. distance_km: рассчитывается из estimated pace. notes: описание интервалов.
LLM добавляет мотивационные notes.

## LLM-пайплайн — три этапа

### Вызов #1: ОБОГАЩЕНИЕ (LLMEnricher)

Промпт ~10-15KB:
```
Ты — тренер по бегу. Перед тобой числовой план и профиль бегуна.

БЕГУН:
- {age} лет, {gender}, {experience_level}, бегает {running_experience}
- Цель: {goal_type}, {race_distance} за {race_target_time}, забег {race_date}
- VDOT: {vdot}, темпы: E {easy_pace}, T {tempo_pace}, I {interval_pace}
- Здоровье: {health_notes}
- Особенности: {special_flags}

СКЕЛЕТ ПЛАНА:
{skeleton_json — ~5-10KB}

ЗАДАЧА:
1. Для interval/fartlek дней — добавь "notes" с описанием тренировки
   Пример: "Разминка 2 км. 5x1000м в темпе 4:40, пауза 400м трусцой. Заминка 1.5 км"
2. Для любых дней — добавь "notes" с полезными советами (1 строка, не для каждого дня)
3. Учти health_notes при написании notes
4. НЕ МЕНЯЙ числа: distance_km, pace, reps, interval_m, rest_m, warmup_km, cooldown_km, type

Верни тот же JSON с добавленными notes.
```

После вызова — **SkeletonValidator** (код) проверяет:
- Все числа на месте (±5%)
- Типы не изменились
- Темпы в зонах VDOT
- Если сломано — берём скелет без обогащения для этих дней

### Вызов #2: РЕВЬЮ (LLMReviewer)

Промпт ~8-12KB:
```
Ты — эксперт-рецензент тренировочных планов по бегу.

БЕГУН: {компактный профиль — 5-7 строк}

ГОТОВЫЙ ПЛАН: {обогащённый JSON}

Проверь план на ошибки. Ищи:
1. Логика темпов: easy должен быть МЕДЛЕННЕЕ tempo, tempo МЕДЛЕННЕЕ interval
2. Прогрессия объёмов: рост не более 10-15%/нед (кроме recovery)
3. Прогрессия длительной: должна расти от недели к неделе (кроме recovery/taper)
4. Нет двух ключевых тренировок подряд (день за днём)
5. Recovery weeks: объём снижен на ~20%
6. Подводка (taper): объём снижается последние 2-3 недели
7. Учёт здоровья: {health_notes} — нет ли противопоказанных нагрузок?
8. Слишком агрессивная прогрессия для возраста/уровня?

Ответь JSON:
{
  "status": "ok" | "has_issues",
  "issues": [
    {
      "week": 5,
      "day": "tue",
      "type": "pace_logic",
      "description": "easy pace 5:00 быстрее tempo 5:20",
      "fix_suggestion": "easy должен быть 5:40-6:00"
    }
  ]
}

Если ошибок нет, верни {"status": "ok", "issues": []}.
```

### Автофикс (PlanAutoFixer) — если ревью нашло ошибки

Код обрабатывает каждую issue по типу:
- `pace_logic` → пересчитать pace из VDOT (уже есть в state)
- `volume_jump` → пересчитать неделю через VolumeDistributor с ограничением роста
- `consecutive_key` → переставить тренировку на другой день
- `missing_recovery` → применить recovery_cutback_ratio
- `health_concern` → заменить interval на tempo или easy
- `too_aggressive` → снизить growth_ratio для проблемных недель

### Вызов #3: Повторное РЕВЬЮ (если были ошибки)

Тот же промпт, но с исправленным планом. Максимум 2 итерации.
Если после 2-й итерации ещё есть ошибки — логируем и сохраняем как есть (скелет уже корректный).

### Итого: LLM-вызовы на план

| Сценарий | Вызовов LLM |
|----------|-------------|
| Всё ок с первого раза | 2 (обогащение + ревью) |
| Ревью нашло ошибки | 3 (обогащение + ревью + повторное ревью) |
| start_running / couch_to_5k | 2 (обогащение notes + ревью) |

Каждый вызов ~10-15KB промпт → 30-60 сек на Ministral 14B.
Общее время: 1-3 мин вместо текущих 3-5 мин.

## Стратегии по goal_type

| goal_type | Скелет | LLM |
|-----------|--------|-----|
| health (start_running, couch_to_5k) | StartRunningProgramBuilder — фиксированные таблицы | Мотивационные notes |
| health (regular_running) | computeHealthMacrocycle → VolumeDistributor, без интервалов | Notes |
| weight_loss | computeHealthMacrocycle → VolumeDistributor + FartlekBuilder | Notes про зону жиросжигания |
| race | computeMacrocycle → полная прогрессия всех типов | Structure + notes + health-адаптация |
| time_improvement | Как race, но больше скоростной работы | Structure + notes |

## Еженедельная проверка и адаптация плана

### Концепция

Каждое воскресенье вечером (уже есть cron `weekly_ai_review.php`) система:
1. Сравнивает план vs факт за неделю
2. Решает нужна ли адаптация будущих недель
3. Если да — пересчитывает оставшиеся недели
4. Отправляет ревью + уведомление в чат

### Новый файл: `planrun_ai/skeleton/WeeklyAdaptationEngine.php`

```
Вход: userId, прошедшая неделя (план vs факт)

Шаг 1: СБОР ДАННЫХ (код)
  - Плановый объём недели (из training_plan_weeks)
  - Фактический объём (из training_log / completed workouts)
  - Выполненные ключевые тренировки (да/нет, какие темпы реально)
  - Пропущенные дни
  - Средний темп easy vs плановый
  - RPE / rating если есть
  - Травмы / жалобы (из health_notes или чата)

Шаг 2: АНАЛИЗ ОТКЛОНЕНИЙ (код)
  compliance = фактический_объём / плановый_объём
  key_workout_completion = выполненные_ключевые / запланированные_ключевые
  pace_deviation = фактический_easy_pace / плановый_easy_pace

  Триггеры адаптации:
  - compliance < 0.7 (выполнено менее 70%) → снизить нагрузку
  - compliance > 1.15 (перевыполнение > 15%) → можно чуть поднять
  - key_workout_completion < 0.5 → упростить ключевые
  - pace_deviation > 1.10 (бежит на 10%+ медленнее) → пересчитать VDOT вниз
  - pace_deviation < 0.95 (бежит быстрее) → пересчитать VDOT вверх
  - 2+ недели подряд compliance < 0.7 → значительное снижение
  - пропуск > 5 дней → detraining factor

Шаг 3: РЕШЕНИЕ (код)
  Если нет триггеров → только ревью в чат, план не меняется.
  Если есть триггеры:
    adaptation_type:
    - 'volume_down': снизить объёмы на X% для оставшихся недель
    - 'volume_up': поднять объёмы (осторожно, max +5%)
    - 'vdot_adjust': пересчитать VDOT и все темпы
    - 'simplify_key': заменить часть interval → tempo или tempo → easy
    - 'extend_phase': продлить текущую фазу на 1 неделю
    - 'insert_recovery': вставить доп. разгрузочную неделю

Шаг 4: ПЕРЕСЧЁТ (PlanSkeletonGenerator)
  Если adaptation_type != none:
    - Пересчитать скелет от текущей недели+1 до конца
    - Стартовый объём = фактический объём текущей недели (не плановый!)
    - Пик остаётся прежним (или корректируется если vdot_adjust)
    - LLM обогащает новую часть
    - LLM ревью новой части
    - Сохранить через saveRecalculatedPlan()

Шаг 5: УВЕДОМЛЕНИЕ
  Отправить в чат:
  - Если адаптация: "На основе твоих тренировок на этой неделе я скорректировал
    план. Объём снижен на 10% — ты выполнил 65% запланированного. Не переживай,
    это нормально. Новый план учитывает твой текущий уровень."
  - Если без адаптации: обычное еженедельное ревью (как сейчас)
```

### Интеграция с существующим cron

Модифицируем `scripts/weekly_ai_review.php`:
- Вместо "только ревью" → вызываем `WeeklyAdaptationEngine`
- Engine решает: нужна адаптация или только ревью
- Ревью-сообщение включает информацию об адаптации (если была)

### Существующие ресурсы для переиспользования
- `prepare_weekly_analysis.php` — сбор данных план vs факт (уже есть!)
- `AdaptationService.php` — заглушка, заменим реальной логикой
- `AdaptationController.php` — endpoint для ручного запуска адаптации
- `weekly_ai_review.php` — cron-скрипт (доработаем)

### Оценка достижимости цели (assessGoalRealism)

Уже существует `assessGoalRealism()` в `prompt_builder.php` (строка 473) — проверяет:
- Достаточно ли недель до забега (мин: марафон 12-18, полумарафон 8-12, 10к 6-8, 5к 4-6)
- Достаточно ли базового объёма (марафон мин 15 км/нед, рек 30+)
- Достижимо ли целевое время по текущему VDOT (разрыв VDOT)
- Достаточно ли тренировок в неделю

**Интеграция в новый пайплайн:**

1. **При генерации плана** — вызвать `assessGoalRealism()` ДО построения скелета.
   - Вердикт `unrealistic` → не генерировать план, вернуть пользователю ошибку + suggestions
   - Вердикт `challenging` → генерировать план, но добавить предупреждение
   - Вердикт `realistic` → генерировать план

2. **При еженедельной адаптации** — проверять прогресс к цели.
   Добавить в `WeeklyAdaptationEngine`:
   ```
   Неделя 8 из 16. Цель: марафон 3:30 (VDOT 45).
   Текущий фактический VDOT (по тренировкам): 42.
   Прогнозное время марафона при VDOT 42: 3:48.

   Решение:
   - Если отставание < 5% от цели → "challenging", продолжаем план
   - Если отставание 5-15% → предупреждение: "Целевое время может быть
     недостижимо. Рекомендуем скорректировать цель на 3:45."
   - Если отставание > 15% → "Цель марафон за 3:30 недостижима при текущей
     форме. Предлагаем цель 3:50 или выбрать полумарафон."
   ```

3. **Предиктор финишного времени** — на основе текущего VDOT и оставшихся недель
   рассчитать прогнозное финишное время. Функция `predictFinishTimeForRemainingWeeks()`:
   - Текущий VDOT
   - Тренд VDOT за последние 4 недели (растёт/стагнирует/падает)
   - Оставшиеся недели тренировок
   - Ожидаемый прирост VDOT: ~0.5-1 VDOT за 4 недели (для intermediate)
   - Прогнозный VDOT на день забега → прогнозное время

## Recalculation и next_plan

**Recalculation:**
1. Определяем cutoffDate, keptWeeks
2. Актуализируем VDOT из свежих тренировок
3. Корректируем weekly_base_km по реальным объёмам последних 4 недель
4. Генерируем скелет только для оставшихся недель (с правильной фазой)
5. LLM обогащает только новую часть

**Next plan:**
1. Берём итоги предыдущего плана (пиковый объём, лучшие результаты)
2. Пересчитываем VDOT
3. Обновляем weekly_base_km = средний объём последних 4 тренировочных недель
4. Генерируем новый план с нуля, стартовый объём из реальных данных

## Порядок реализации

### Фаза 1: Ядро — DONE
1. [x] `VolumeDistributor.php`
2. [x] `PlanSkeletonGenerator.php` (оркестратор)
3. [ ] Тест: генерация скелета для race goal_type

### Фаза 2: Прогрессии тренировок — DONE
4. [x] `IntervalProgressionBuilder.php`
5. [x] `TempoProgressionBuilder.php`
6. [x] `FartlekBuilder.php`
7. [x] `ControlWorkoutBuilder.php`

### Фаза 3: Специальные программы — DONE
8. [x] `StartRunningProgramBuilder.php`
9. [x] `OfpProgressionBuilder.php`

### Фаза 4: LLM-пайплайн (обогащение + ревью + автофикс) — DONE
10. [x] `enrichment_prompt_builder.php` — промпты для обогащения и ревью
11. [x] `LLMEnricher.php` — вызов #1 (обогащение)
12. [x] `SkeletonValidator.php` — алгоритмическая проверка после обогащения
13. [x] `LLMReviewer.php` — вызов #2-3 (ревью логики)
14. [x] `PlanAutoFixer.php` — автофикс ошибок найденных ревью

### Фаза 5: Интеграция — DONE
15. [x] Модификация `PlanGenerationProcessorService.php`
16. [x] Feature flag `USE_SKELETON_GENERATOR` в `.env`
17. [x] Поддержка recalculate и next_plan (в processViaSkeleton)

### Фаза 6: Еженедельная адаптация — DONE
18. [x] `WeeklyAdaptationEngine.php` — сбор данных, анализ отклонений, решение об адаптации
19. [x] Доработка `AdaptationService.php` — реальная логика вместо заглушки
20. [x] Доработка `weekly_ai_review.php` — интеграция с WeeklyAdaptationEngine

## Верификация

1. [x] Сгенерировать план для тестового пользователя (race, marathon, intermediate) — 7 недель до забега, VDOT 48.9
2. [x] Проверить: объёмы растут монотонно (42→43.7→45.2→recovery 33.7→48.1→49.5→50.8), длительная прогрессирует (22→26km)
3. [x] Проверить: темпы корректны по VDOT 48.9 (Easy 5:13, T-pace 4:25, I-pace 3:59)
4. [x] Проверить: ключевые тренировки прогрессируют (tempo 3→4km, intervals 4x800→5x800)
5. [x] Проверить: recovery неделя (#4) с коэффициентом 0.88, контрольные перед recovery
6. [x] Проверить: LLM-обогащение добавляет notes, fallback при timeout работает
7. [x] Проверить: SkeletonValidator — числа не сломаны (consistency_errors=0)
8. [x] Проверить: plan_normalizer корректно обрабатывает формат, easy safety net работает
9. [x] Проверить: saveTrainingPlan корректно сохраняет (7 недель, 49 дней, 42 exercises)
10. [x] Проверить: recalculate — KEPT 7 старых + NEW 7 новых, фазы build→taper, race week
11. [ ] Проверить: фронтенд отображает план без изменений
12. [ ] Сравнить с текущим LLM-планом: объёмы, темпы, логика прогрессии

## Исправления при тестировании
- `formatPaceSec()` дублирование → `function_exists` guard в enrichment_prompt_builder.php
- LLM Enricher timeout 120s → 300s
- `easy_min_km` — динамический расчёт: max(policy_floor, weekly_volume * 5%) в VolumeDistributor
- PlanAutoFixer — добавлены issue types от LLM: `interval_pace_logic`, `type_mismatch`, `taper_violation`
- Recalculate: подмена `training_start_date` на `cutoff_date` для правильного расчёта фаз
