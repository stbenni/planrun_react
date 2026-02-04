# Настройка тестирования

## Установка Composer

Composer не найден в системе. Установите его:

```bash
# Скачать и установить Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer
```

Или через пакетный менеджер:
```bash
# Ubuntu/Debian
sudo apt update
sudo apt install composer

# CentOS/RHEL
sudo yum install composer
```

## Установка зависимостей

После установки Composer:

```bash
# Из корня проекта
cd planrun-backend
composer install
```

Это установит:
- PHPUnit 10.x
- Автозагрузчик классов

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

# С подробным выводом
./vendor/bin/phpunit --verbose
```

## Структура тестов

```
tests/
├── Unit/              # Unit тесты (изолированные функции)
│   ├── EnvLoaderTest.php    # Тесты загрузчика .env
│   └── DbConfigTest.php      # Тесты конфигурации БД
├── Feature/           # Feature тесты (интеграционные)
│   └── AuthTest.php          # Тесты аутентификации
├── bootstrap.php      # Загрузка зависимостей
└── README.md          # Документация
```

## Что уже создано

✅ `composer.json` - конфигурация зависимостей  
✅ `phpunit.xml` - конфигурация PHPUnit  
✅ `tests/bootstrap.php` - загрузчик для тестов  
✅ `tests/Unit/EnvLoaderTest.php` - тесты загрузчика .env  
✅ `tests/Unit/DbConfigTest.php` - тесты конфигурации БД  
✅ `tests/Feature/AuthTest.php` - тесты аутентификации  

## Следующие шаги

После установки Composer и запуска тестов:
1. Добавить тесты для критичных функций API
2. Настроить CI/CD для автоматического запуска тестов
3. Добавить тесты для новых функций по мере разработки
