# Настройка API регистрации

Все пути — относительно корня проекта.

## Что уже есть в проекте

- `api/register_api.php` — обёртка с CORS, подключает `planrun-backend/register_api.php`
- `planrun-backend/register_api.php` — логика регистрации, БД `sv`
- `src/screens/RegisterScreen.jsx`, `ApiClient` с `register()` и `validateField()`

## Права доступа

Из корня проекта:

```bash
chmod 644 planrun-backend/register_api.php api/register_api.php
# При работе под www-data:
# sudo chown www-data:www-data planrun-backend/register_api.php api/register_api.php
```

## Проверка

1. Локально: `./START_SERVER.sh`, открыть `http://localhost:3200/landing`, форма регистрации.
2. API:
   ```bash
   curl -X POST http://localhost/api/register_api.php \
     -H "Content-Type: application/json" \
     -d '{"username":"test","password":"test123","goal_type":"health","gender":"male","training_mode":"ai"}'
   ```

## Структура (от корня проекта)

```
├── planrun-backend/
│   └── register_api.php
├── api/
│   └── register_api.php
└── src/
    └── screens/
        └── RegisterScreen.jsx
```
