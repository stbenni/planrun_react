# Тестирование PlanRun Backend

## Установка

```bash
cd planrun-backend
composer install
```

## Запуск тестов

```bash
# Все тесты
./vendor/bin/phpunit

# Только Unit тесты
./vendor/bin/phpunit tests/Unit

# Только Feature тесты
./vendor/bin/phpunit tests/Feature

# Конкретный тест
./vendor/bin/phpunit tests/Unit/EnvLoaderTest.php
```

## Структура тестов

```
tests/
├── Unit/              # Unit тесты (изолированные функции)
│   ├── EnvLoaderTest.php
│   └── DbConfigTest.php
├── Feature/           # Feature тесты (интеграционные)
│   └── AuthTest.php
└── bootstrap.php      # Загрузка зависимостей
```

## Написание тестов

### Unit тесты
Для изолированных функций без зависимостей:
- `env()` функция
- Вспомогательные функции
- Утилиты

### Feature тесты
Для функций с зависимостями:
- Работа с БД
- Аутентификация
- API endpoints

## Важно

- Тесты не должны зависеть от реальной БД (используйте моки или тестовую БД)
- Не коммитить тестовые данные в продакшн
- Использовать `setUp()` и `tearDown()` для подготовки окружения
