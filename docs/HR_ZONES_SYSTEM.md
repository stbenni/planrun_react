# Система зон ЧСС (Heart Rate Zones)

## Обзор

PlanRun автоматически рассчитывает целевой пульс для каждой тренировки на основе реальных данных из тренировок пользователя. Система адаптивная — с накоплением данных рекомендации становятся точнее.

## Приоритет источников данных

1. **Реальные данные из тренировок** (P25-P75 за последние 6 недель) — наивысший приоритет
2. **Ручной ввод** (max_hr, rest_hr в профиле) → зоны Карвонена
3. **Формула Карвонена** при наличии ЧСС покоя: `RestHR + (MaxHR - RestHR) × %`
4. **Формула 220−возраст** — fallback

## Реальные HR диапазоны

### Как рассчитываются

`UserProfileService::detectRealHrRanges()` анализирует тренировки за **6-недельное скользящее окно**:

| Бакет | Темп | Маппинг типов |
|-------|------|---------------|
| easy | ≥5:30 мин/км | easy, long |
| moderate | 5:00–5:29 мин/км | tempo |
| intense | <5:00 мин/км | interval, fartlek, control |

Для каждого бакета берутся перцентили P25-P75 средней ЧСС тренировок.

### Фильтрация артефактов

- **Ratio filter**: если `max_hr / avg_hr > 1.20` — тренировка пропускается (спайк датчика)
- **Hard cap**: `max_hr > 210` — пропускается
- Минимум `avg_hr > 100` для валидности

### Детекция трендов

Сравнение последних 3 недель vs предыдущих 3 недель:
- Разница ≥3 уд/мин → **improving** (пульс снижается) или **worsening** (пульс растёт)
- Иначе → **stable**

## Маппинг типов тренировок → целевой пульс

`UserProfileService::getTargetHrForWorkoutType()`:

| Тип тренировки | Реальный бакет | Зоны (fallback) |
|----------------|----------------|-----------------|
| easy, long | easy | Z1-Z2 |
| tempo | moderate | Z2-Z3 |
| interval, fartlek, control | intense | Z3-Z4 |
| race | — | Z3-Z5 |
| rest, free, sbu, other | — | null |

## Хранение в БД

```sql
ALTER TABLE training_plan_days
  ADD COLUMN target_hr_min SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN target_hr_max SMALLINT UNSIGNED DEFAULT NULL;
```

## Точки заполнения target_hr

1. **Генерация плана** (`plan_saver.php`) — при INSERT каждого дня
2. **Пересчёт плана** (`plan_saver.php::saveRecalculatedPlan`) — аналогично
3. **Ручное добавление дня** (`WeekService::addTrainingDay`, `addTrainingDayByDate`) — авто-обогащение
4. **Обновление дня** (`WeekService::updateTrainingDayById`) — пересчёт при смене типа
5. **Чат-бот** (через ChatToolRegistry → WeekService) — прозрачно

## Авто-пересчёт

После каждого импорта тренировки (Strava webhook, ручной ввод результата):
- `UserProfileService::recalculateHrTargetsForFutureDays($userId)`
- Обновляет все будущие дни плана текущими HR данными
- Сбрасывает кэш

**Триггеры:**
- `api/strava_webhook.php` — после `importWorkouts()`
- `WorkoutController::saveResult()` — после сохранения результата

## Backfill

```bash
php planrun-backend/scripts/backfill_hr_targets.php
```

Заполняет target_hr для существующих планов у всех пользователей.

## Отображение

### Календарь (WorkoutCard)
Целевой пульс показывается рядом с типом тренировки: ♥ 155–167

### Настройки (SettingsScreen)
- Таблица зон ЧСС (Z1-Z5)
- Блок «Реальный пульс из тренировок» с бакетами и стрелками трендов

### AI-контекст
- `ChatContextBuilder::formatHrZones()` — зоны + реальные данные + тренды в промпте чата
- `enrichment_prompt_builder::buildHrZonesBlock()` — компактная строка для генерации плана
- `ChatPromptBuilder` — правило: приоритет реальных данных над формулами

## Файлы системы

| Файл | Роль |
|------|------|
| `services/UserProfileService.php` | Ядро: расчёт зон, реальных диапазонов, маппинг, пересчёт |
| `planrun_ai/plan_saver.php` | Заполнение HR при сохранении плана |
| `repositories/WeekRepository.php` | INSERT/UPDATE с target_hr полями |
| `services/WeekService.php` | Авто-обогащение HR при add/update |
| `services/ChatContextBuilder.php` | HR данные в AI-контексте |
| `planrun_ai/skeleton/enrichment_prompt_builder.php` | HR для генерации плана |
| `api/strava_webhook.php` | Авто-пересчёт после импорта |
| `controllers/WorkoutController.php` | Авто-пересчёт после ручного результата |
| `load_training_plan.php` | API: отдаёт target_hr |
| `services/WorkoutService.php` | API: getDay с target_hr |
| `src/components/Calendar/WorkoutCard.jsx` | UI: отображение целевого пульса |
| `src/screens/SettingsScreen.jsx` | UI: реальные HR диапазоны |
| `scripts/backfill_hr_targets.php` | Миграция существующих данных |
