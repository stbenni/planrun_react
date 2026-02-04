# CORS и деплой (s-vladimirov.ru)

## Проблема

При запросах с `https://s-vladimirov.ru` к API на другом домене браузер блокирует ответ из‑за CORS:

```
Access to fetch at '...' from origin 'https://s-vladimirov.ru' has been blocked by CORS policy:
Response to preflight request doesn't pass access control check:
No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

## Что сделано (январь 2026)

1. **Общий CORS** (`api/cors.php`):
   - Обработка preflight `OPTIONS` (204, все нужные заголовки).
   - Разрешённые origins: `s-vladimirov.ru`, localhost, 127.0.0.1, 192.168.x.x. (изоляция от planrun)
   - Подключён в `api_wrapper.php`, `login_api.php`, `register_api.php`, `logout_api.php`.

2. **Без дублирования**:
   - При вызове через `api_wrapper` в `api_v2` CORS не выставляется (константа `API_WRAPPER_CORS_SENT`).
   - Аналогично для `register_api` при вызове через обёртку (`API_CORS_SENT`).

3. **Фронтенд**:
   - `ApiClient` по умолчанию использует относительный `/api` (same-origin).
   - Опционально: `VITE_API_BASE_URL` в `.env` — если задан и не пустой, используется как base URL API.

## Рекомендуемая схема

**Same-origin:** фронт и API на одном домене (например `https://s-vladimirov.ru`).

- Фронт: `https://s-vladimirov.ru/` (или подпуть, например `/react/`).
- API: `https://s-vladimirov.ru/api/` → `api/*.php` и `planrun-backend`.

Тогда запросы идут на тот же origin, CORS не требуется.

## Если API на другом домене

Для s-vladimirov.ru используется **только same-origin** `/api`. CORS whitelist в `api/cors.php` не включает planrun. При необходимости добавить другие origins в `$allowed` (строго по доменам, не `*`).

## Добавление нового origin

В `api/cors.php` массив `$allowed`:

```php
$allowed = [
    'https://s-vladimirov.ru',
    'https://www.s-vladimirov.ru',
    // ...
];
```

Добавить нужный origin (с протоколом, без слэша в конце).

## Сборка и деплой

1. `npm run build` (или `npx vite build`) в корне проекта.
2. Выложить `dist/` на фронт-хостинг (s-vladimirov.ru и т.п.).
3. Убедиться, что веб-сервер направляет `/api/*` на `api/*.php` и что DocumentRoot/alias указывают на актуальные файлы (в т.ч. `api/`, `planrun-backend/`).

После этого запросы с `s-vladimirov.ru` к same-origin API не должны упираться в CORS; при cross-origin — достаточно правильного CORS на стороне API и whitelist в `cors.php`.
