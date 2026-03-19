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

# Golden regression по планировщику
./vendor/bin/phpunit tests/Unit/GoldenPlanPolicyTest.php

# Batch-eval живых пользователей через AI pipeline
php scripts/eval_plan_generation.php --user-ids=1,2,3
php scripts/eval_plan_generation.php --user-ids=1,2 --mode=full

# Synthetic benchmark без реальных пользователей
php scripts/eval_plan_generation.php --fixture=synthetic
php scripts/eval_plan_generation.php --fixture=synthetic --case-names=novice_couch_to_5k_three_days,first_half_low_base
```

## Структура тестов

```
tests/
├── Unit/              # Unit тесты (изолированные функции)
│   ├── EnvLoaderTest.php
│   ├── PlanGenerationQueueServiceTest.php
│   ├── RegistrationServiceTest.php
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

## Batch Eval

- `scripts/eval_plan_generation.php` — CLI-харнесс для прогонов по реальным `user_id`
- `tests/Fixtures/synthetic_plan_eval_cases.php` — synthetic benchmark cases для prelaunch-проверок
- режим `first-pass`: один raw AI pass + normalizer/validator, полезен для диагностики LLM quality
- режим `full`: штатный `generatePlanViaPlanRunAI()` с metadata и corrective pass
- режим `fixture=synthetic`: те же прогоны, но на виртуальных пользователях без записей в `users`
- артефакты сохраняются в `tmp/eval_artifacts/summary.json` и `tmp/eval_artifacts/user_<id>.json`
