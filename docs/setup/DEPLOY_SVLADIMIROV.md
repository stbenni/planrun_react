# Деплой s-vladimirov.ru

Все файлы и пути — **внутри проекта**. Нет зависимостей от `/var/www/planrun` или других внешних каталогов. Скрипты определяют корень проекта по расположению себя.

## Структура (относительно корня проекта)

- **Фронт:** `dist/` (React build)
- **API:** `api/*.php` → подключают `planrun-backend/`
- **БД:** `planrun-backend/.env`, `db_config.php` (например, БД `sv`)

## Шаги деплоя

### 1. Сборка фронта

Из корня проекта:

```bash
npm run build
```

### 2. Apache (s-vladimirov.ru)

Конфиг собирается из `deploy/vladimirov-le-ssl.conf.template`; `{{PROJECT_ROOT}}` подставляется автоматически (путь к корню проекта).

Применить:

```bash
sudo ./deploy/apply-apache.sh
```

Скрипт вызывать из корня проекта (или из `deploy/` — корень = родитель `deploy/`).

### 3. Systemd (dev-сервер, опционально)

Юнит собирается из `planrun-react.service`; `{{PROJECT_ROOT}}` подставляется при установке.

Установка:

```bash
sudo ./deploy/install-systemd.sh
```

Затем:

```bash
sudo systemctl enable planrun-react && sudo systemctl start planrun-react
```

### 4. Локальный dev без systemd

```bash
./START_SERVER.sh
```

Скрипт сам переходит в корень проекта.

### 5. Проверка

- https://s-vladimirov.ru — SPA
- https://s-vladimirov.ru/api/api_wrapper.php?action=check_auth — JSON
- Маршруты `/login`, `/calendar` и т.п. отдают `index.html` через `dist/.htaccess`

## Изоляция

- Нет `require`/`include` вне проекта.
- CORS только s-vladimirov.ru и localhost (`api/cors.php`).
- ApiClient при origin s-vladimirov.ru всегда использует относительный `/api`.
