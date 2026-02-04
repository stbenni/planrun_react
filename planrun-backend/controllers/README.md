# Контроллеры API

Структура контроллеров для рефакторинга монолитного `api.php`.

## Структура

```
controllers/
├── BaseController.php           # Базовый класс с общей логикой
├── TrainingPlanController.php   # Планы тренировок
├── WorkoutController.php        # Тренировки и результаты
├── StatsController.php          # Статистика
├── ExerciseController.php       # Упражнения
├── WeekController.php           # Недели плана
└── AdaptationController.php     # Адаптация плана
```

## Использование

### Старый API (api.php)
```
GET /api.php?action=load
GET /api.php?action=get_day&date=2026-01-25
POST /api.php?action=save_result
```

### Новый API (api_v2.php)
```
GET /api_v2.php?action=load
GET /api_v2.php?action=get_day&date=2026-01-25
POST /api_v2.php?action=save_result
```

**ВАЖНО:** Оба API работают параллельно. Старый API продолжает работать для обратной совместимости.

## Контроллеры

### BaseController
Базовый класс с общей логикой:
- Управление доступом (авторизация, права)
- CSRF защита
- Утилиты для ответов (success/error)
- Работа с параметрами запроса

### TrainingPlanController
- `load()` - загрузка плана тренировок
- `checkStatus()` - проверка статуса плана

### WorkoutController
- `getDay()` - получить день тренировки
- `saveResult()` - сохранить результат тренировки
- `getResult()` - получить результат тренировки
- `getAllResults()` - получить все результаты
- `deleteWorkout()` - удалить тренировку

### StatsController
- `stats()` - получить статистику
- `getAllWorkoutsSummary()` - сводка всех тренировок
- `prepareWeeklyAnalysis()` - недельный анализ

### ExerciseController
- `addDayExercise()` - добавить упражнение к дню
- `updateDayExercise()` - обновить упражнение
- `deleteDayExercise()` - удалить упражнение
- `reorderDayExercises()` - изменить порядок упражнений
- `listExerciseLibrary()` - библиотека упражнений

### WeekController
- `addWeek()` - добавить неделю
- `deleteWeek()` - удалить неделю
- `addTrainingDay()` - добавить день тренировки

### AdaptationController
- `runWeeklyAdaptation()` - запустить недельную адаптацию

## Миграция

Постепенная миграция действий из `api.php` в контроллеры:
1. ✅ Основные действия мигрированы (18+ действий)
2. ⏳ Остальные действия (save, reset, generate_plan, etc.) - в процессе
3. ⏳ Вынос бизнес-логики в сервисы (следующий этап)

## Принципы

- **Обратная совместимость** - старый API работает
- **Постепенная миграция** - по одному действию
- **Переиспользование** - используем существующие функции из api.php
- **Тестирование** - тесты для новых контроллеров
