# Сервисы (Service Layer)

Сервисы содержат бизнес-логику приложения, отделенную от контроллеров.

## Структура

```
services/
├── BaseService.php           # Базовый класс для всех сервисов
├── TrainingPlanService.php   # Сервис для работы с планами тренировок
└── README.md                 # Этот файл
```

## Принципы

### Разделение ответственности
- **Контроллеры** - обрабатывают HTTP запросы, валидацию, ответы
- **Сервисы** - содержат бизнес-логику, работу с БД, вычисления
- **Модели/Repository** - работа с данными (следующий этап)

### Преимущества
- ✅ Легче тестировать - сервисы можно тестировать без HTTP
- ✅ Переиспользование - логику можно использовать в разных местах
- ✅ Чистый код - контроллеры становятся тоньше
- ✅ Независимость - сервисы не зависят от HTTP

## Использование

### В контроллере
```php
class TrainingPlanController extends BaseController {
    protected $planService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->planService = new TrainingPlanService($db);
    }
    
    public function load() {
        try {
            $planData = $this->planService->loadPlan($userId);
            $this->returnSuccess($planData);
        } catch (Exception $e) {
            $this->returnError($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
```

### Прямое использование
```php
$service = new TrainingPlanService($db);
$plan = $service->loadPlan($userId);
```

## Сервисы

### TrainingPlanService
- `loadPlan($userId, $useCache = true)` - загрузить план
- `checkPlanStatus($userId)` - проверить статус плана
- `regeneratePlan($userId)` - регенерировать план
- `regeneratePlanWithProgress($userId)` - регенерировать с прогрессом
- `clearPlanGenerationMessage()` - очистить сообщение

### WorkoutService
- `getAllResults($userId)` - получить все результаты тренировок
- `getResult($date, $weekNumber, $dayName, $userId)` - получить результат за день
- `getDay($date, $userId)` - получить день тренировки со всеми данными

### StatsService
- `getStats($userId)` - получить статистику тренировок
- `getAllWorkoutsSummary($userId)` - получить сводку всех тренировок
- `prepareWeeklyAnalysis($userId, $weekNumber = null)` - подготовить недельный анализ

### ExerciseService
- `addDayExercise($data, $userId)` - добавить упражнение к дню
- `updateDayExercise($data, $userId)` - обновить упражнение
- `deleteDayExercise($exerciseId, $userId)` - удалить упражнение
- `reorderDayExercises($data, $userId)` - изменить порядок упражнений
- `listExerciseLibrary($userId)` - получить библиотеку упражнений

### WeekService
- `addWeek($data, $userId)` - добавить неделю
- `deleteWeek($weekId, $userId)` - удалить неделю
- `addTrainingDay($data, $userId)` - добавить день тренировки

### AdaptationService
- `runWeeklyAdaptation($userId)` - запустить недельную адаптацию

## Следующие шаги

1. Создать сервисы для других доменов:
   - `WorkoutService` - тренировки
   - `StatsService` - статистика
   - `ExerciseService` - упражнения
   - `WeekService` - недели

2. Создать Repository слой для работы с БД

3. Добавить Dependency Injection контейнер
