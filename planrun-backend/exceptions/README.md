# Исключения (Exceptions)

Централизованная система обработки исключений для приложения.

## Структура

```
exceptions/
├── AppException.php           # Базовое исключение
├── ValidationException.php    # Ошибки валидации
├── NotFoundException.php      # Ресурс не найден
├── UnauthorizedException.php  # Не авторизован
├── ForbiddenException.php     # Доступ запрещен
└── README.md                  # Этот файл
```

## Исключения

### AppException
Базовое исключение для всех ошибок приложения.

**Особенности:**
- Содержит контекст ошибки
- Имеет HTTP статус код
- Может быть преобразовано в массив для JSON ответа

**Использование:**
```php
throw new AppException('Ошибка загрузки плана', 500, null, ['user_id' => $userId]);
```

### ValidationException
Исключение для ошибок валидации (400).

**Особенности:**
- Содержит массив ошибок валидации
- Автоматически устанавливает статус 400

**Использование:**
```php
throw new ValidationException('Ошибка валидации', ['user_id' => ['Поле обязательно']]);
```

### NotFoundException
Исключение для ресурсов, которые не найдены (404).

**Использование:**
```php
throw new NotFoundException('План тренировок не найден', 404, null, ['user_id' => $userId]);
```

### UnauthorizedException
Исключение для ошибок авторизации (401).

**Использование:**
```php
throw new UnauthorizedException('Требуется авторизация');
```

### ForbiddenException
Исключение для ошибок доступа (403).

**Использование:**
```php
throw new ForbiddenException('Нет прав на редактирование');
```

## Использование в сервисах

### BaseService методы
- `throwException($message, $code, $context)` - базовое исключение
- `throwValidationException($message, $validationErrors)` - валидация
- `throwNotFoundException($message, $context)` - не найдено
- `throwUnauthorizedException($message, $context)` - не авторизован
- `throwForbiddenException($message, $context)` - доступ запрещен

### Пример
```php
class TrainingPlanService extends BaseService {
    public function loadPlan($userId) {
        if (!$userId) {
            $this->throwValidationException('ID пользователя обязателен', [
                'user_id' => ['Поле обязательно']
            ]);
        }
        
        $plan = $this->repository->getPlanByUserId($userId);
        if (!$plan) {
            $this->throwNotFoundException('План не найден', ['user_id' => $userId]);
        }
        
        return $plan;
    }
}
```

## Использование в контроллерах

### BaseController::handleException()
Автоматически обрабатывает исключения и возвращает правильный HTTP ответ.

**Пример:**
```php
try {
    $data = $this->service->doSomething();
    $this->returnSuccess($data);
} catch (Exception $e) {
    $this->handleException($e);
}
```

## Преимущества

1. **Централизованная обработка** - все исключения обрабатываются одинаково
2. **Правильные HTTP коды** - автоматически устанавливаются статус коды
3. **Контекст ошибок** - можно передавать дополнительную информацию
4. **Логирование** - автоматическое логирование всех ошибок
5. **Безопасность** - в продакшене не показываются детали ошибок
