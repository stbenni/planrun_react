# Сервисы (Service Layer)

Сервисы содержат бизнес-логику приложения, отделенную от контроллеров.

## Структура

```
services/
├── BaseService.php           # Базовый класс для всех сервисов
├── PlanGenerationQueueService.php # Очередь задач генерации/пересчёта плана
├── PlanGenerationProcessorService.php # Исполнение AI-задач генерации
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

### EmailVerificationService
- `sendVerificationCode($email)` - сохранить код подтверждения и отправить письмо
- `verifyCode($email, $code)` - проверить код, срок действия и остаток попыток

### RegistrationService
- `validateField($field, $value)` - проверить доступность `username` и `email`
- `registerMinimal($input)` - создать пользователя для минимальной регистрации и вернуть payload для автологина

### PlanGenerationQueueService
- `enqueue($userId, $jobType, $payload = [])` - поставить AI-задачу в очередь
- `reserveNextJob()` - забрать следующую задачу worker-ом
- `markCompleted($jobId, $result = [])` - завершить задачу
- `markFailed($jobId, $errorMessage, $attempts, $maxAttempts)` - перевести задачу в retry/failed

### PlanGenerationProcessorService
- `process($userId, $jobType, $payload = [])` - выполнить генерацию, пересчёт или next plan
- `persistFailure($userId, $message)` - сохранить ошибку генерации в план пользователя

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
