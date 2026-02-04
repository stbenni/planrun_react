# Валидаторы

Валидаторы проверяют входные данные перед обработкой в сервисах.

## Структура

```
validators/
├── BaseValidator.php            # Базовый класс
├── TrainingPlanValidator.php    # Валидация планов
├── WorkoutValidator.php         # Валидация тренировок
└── README.md                   # Этот файл
```

## Принципы

### Разделение ответственности
- **Валидаторы** - проверка данных
- **Сервисы** - бизнес-логика
- **Контроллеры** - HTTP

### Преимущества
- ✅ Централизованная валидация
- ✅ Переиспользование правил
- ✅ Легче тестировать
- ✅ Чистый код

## Использование

### В сервисе
```php
class TrainingPlanService extends BaseService {
    protected $validator;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->validator = new TrainingPlanValidator();
    }
    
    public function regeneratePlan($userId) {
        // Валидация
        if (!$this->validator->validateRegeneratePlan(['user_id' => $userId])) {
            $this->throwException($this->validator->getFirstError(), 400);
        }
        
        // ... бизнес-логика
    }
}
```

## Валидаторы

### TrainingPlanValidator
- `validateRegeneratePlan($data)` - валидация регенерации
- `validateCheckStatus($data)` - валидация проверки статуса

### WorkoutValidator
- `validateGetDay($data)` - валидация получения дня
- `validateSaveResult($data)` - валидация сохранения результата

### ExerciseValidator
- `validateAddExercise($data)` - валидация добавления упражнения
- `validateUpdateExercise($data)` - валидация обновления упражнения
- `validateDeleteExercise($data)` - валидация удаления упражнения
- `validateReorderExercises($data)` - валидация изменения порядка

### WeekValidator
- `validateAddWeek($data)` - валидация добавления недели
- `validateDeleteWeek($data)` - валидация удаления недели
- `validateAddTrainingDay($data)` - валидация добавления дня тренировки

## Методы валидации

### BaseValidator
- `validateRequired($value, $fieldName)` - обязательное поле
- `validateType($value, $type, $fieldName)` - тип данных
- `validateDate($value, $format)` - дата
- `validateRange($value, $min, $max, $fieldName)` - диапазон
- `validateLength($value, $min, $max, $fieldName)` - длина строки

## Следующие шаги

1. Создать валидаторы для остальных доменов
2. Добавить более сложные правила валидации
3. Добавить кастомные сообщения об ошибках
