# Репозитории (Repository Layer)

Репозитории содержат всю работу с базой данных, изолируя SQL запросы от бизнес-логики.

## Структура

```
repositories/
├── BaseRepository.php           # Базовый класс
├── TrainingPlanRepository.php   # Планы тренировок
├── WorkoutRepository.php        # Тренировки
├── StatsRepository.php          # Статистика
└── README.md                    # Этот файл
```

## Принципы

### Разделение ответственности
- **Сервисы** - бизнес-логика, валидация, вычисления
- **Репозитории** - работа с БД, SQL запросы
- **Контроллеры** - HTTP запросы/ответы

### Преимущества
- ✅ Изоляция SQL - все запросы в одном месте
- ✅ Легче тестировать - можно мокировать репозитории
- ✅ Переиспользование - запросы можно использовать в разных сервисах
- ✅ Легче оптимизировать - все SQL в одном месте

## Использование

### В сервисе
```php
class TrainingPlanService extends BaseService {
    protected $repository;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new TrainingPlanRepository($db);
    }
    
    public function checkPlanStatus($userId) {
        $plan = $this->repository->getPlanByUserId($userId);
        // ... бизнес-логика
    }
}
```

## Репозитории

### TrainingPlanRepository
- `getPlanByUserId($userId)` - получить план пользователя
- `updateErrorMessage($userId, $errorMessage)` - обновить ошибку
- `clearErrorMessage($userId)` - очистить ошибку
- `getWeeksByUserId($userId)` - получить недели
- `getDaysByWeekId($weekId, $userId)` - получить дни недели

### WorkoutRepository
- `getAllResults($userId)` - все результаты
- `getResultByDate($userId, $date, $weekNumber, $dayName)` - результат за день
- `getWorkoutsByDate($userId, $dateStart, $dateEnd)` - тренировки за период

### StatsRepository
- `getTotalDays($userId)` - общее количество дней
- `getWorkoutDates($userId)` - даты тренировок
- `getWorkoutsSummary($userId)` - сводка тренировок

### ExerciseRepository
- `getExercisesByDayId($planDayId, $userId)` - получить упражнения дня
- `addExercise($data, $userId)` - добавить упражнение
- `updateExercise($exerciseId, $data, $userId)` - обновить упражнение
- `deleteExercise($exerciseId, $userId)` - удалить упражнение
- `getExerciseLibrary()` - получить библиотеку упражнений

### WeekRepository
- `getWeekById($weekId, $userId)` - получить неделю по ID
- `getMaxWeekNumber($userId)` - получить максимальный номер недели
- `addWeek($data, $userId)` - добавить неделю
- `deleteWeek($weekId, $userId)` - удалить неделю
- `getDayByDate($date, $userId)` - получить день по дате
- `addTrainingDay($data, $userId)` - добавить день тренировки

## Следующие шаги

1. Создать репозитории для остальных доменов
2. Добавить кеширование на уровне репозиториев
3. Добавить транзакции для сложных операций
