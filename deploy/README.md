# Деплой

Скрипты используют только пути внутри проекта. `PROJECT_ROOT` = родитель каталога `deploy/`.

## Nginx (рекомендуется)

- **apply-nginx.sh** — генерирует конфиг из `planrun.nginx.conf.template` для `planrun.ru`/`www.planrun.ru` (SPA из `dist/` + `/api` через PHP-FPM), подставляет `{{PROJECT_ROOT}}`, `{{BOT_ROOT}}` и сокет PHP-FPM, копирует в `sites-available/planrun`, включает сайт и перезагружает Nginx. Запуск из корня проекта: `sudo ./deploy/apply-nginx.sh`. Сокет FPM подставляется автоматически (php8.3/8.2); свой: `PHP_FPM_SOCK=unix:/run/php/php8.2-fpm.sock sudo ./deploy/apply-nginx.sh`. При нестандартном пути к Telegram-боту можно задать `BOT_ROOT=/var/www/planrun-bot`.

## Apache (альтернатива)

- **apply-apache.sh** — генерирует Apache vhost из `planrun.apache.conf.template`, копирует в `sites-available/planrun-ssl.conf`, перезагружает Apache. Запуск: `sudo ./deploy/apply-apache.sh` из корня проекта.

## Общее

- **install-systemd.sh** — генерирует `planrun-react.service` (подставляет `{{PROJECT_ROOT}}`), копирует в `/etc/systemd/system/`. Запуск: `sudo ./deploy/install-systemd.sh` из корня проекта.

## AI serving stack

- **install-llama-serving-stack.sh** — ставит и включает production-стек для локальной LLM:
  - `llama-server.service` с `Ministral-3-14B-Reasoning`
  - `planrun-ai.service` для `/api/v1/chat`, `/api/v1/generate-plan`, `/api/v1/retrieve-knowledge`
  - `lm-studio.service.d/load-models.conf` для автозагрузки embedding-модели в LM Studio

Запуск:

```bash
sudo ./deploy/install-llama-serving-stack.sh
```

Архитектура после установки:

- `llama-server :8081` — reasoning-модель для чата и генерации планов
- `lm-studio :1234` — только embedding-модель `text-embedding-nomic-embed-text-v1.5`
- `planrun-ai :8000` — Python orchestration layer, RAG, plan JSON parsing/validation

Проверка:

```bash
systemctl status llama-server planrun-ai lm-studio --no-pager
curl -s http://127.0.0.1:8081/v1/models
curl -s http://127.0.0.1:1234/v1/models
curl -s http://127.0.0.1:8000/health
```

Если LM Studio нужен только для embedding-модели, можно отдельно переустановить именно этот кусок:

```bash
sudo ./deploy/install-lm-studio-models.sh
```

## Если в браузере 404 на /api/

- **Локальная разработка (`npm run dev`):** запросы к `/api` проксируются на бэкенд по умолчанию на `http://localhost` (см. `vite.config.js`). Если нужен удалённый бэкенд: `VITE_API_PROXY_TARGET=https://planrun.ru npm run dev`.
- **Прод с Nginx:** выполните `sudo ./deploy/apply-nginx.sh`. Убедитесь, что PHP-FPM запущен и что SSL на месте.
- **Прод с Apache:** выполните `sudo ./deploy/apply-apache.sh`, проверьте, что vhost активен и обрабатывается PHP.

## Если 500 при логине, регистрации, сбросе пароля или генерации плана

На **том сервере**, где крутится сайт, один раз выполните миграции (создание таблиц `email_verification_codes`, `password_reset_tokens`, `refresh_tokens` и `plan_generation_jobs`):

```bash
cd /var/www/planrun/planrun-backend
php scripts/migrate_all.php
```

Должно вывести: `OK: email_verification_codes`, `OK: password_reset_tokens`, `OK: refresh_tokens`, `OK: plan_generation_jobs`, `All migrations done.`  
- **email_verification_codes** — для кодов подтверждения email при регистрации.  
- **password_reset_tokens** — для сброса пароля по ссылке из письма.  
- **refresh_tokens** — для входа с JWT (в т.ч. с мобильного приложения).
- **plan_generation_jobs** — для очереди AI-генерации и пересчёта планов.

## Telegram Login

Для новой бесшовной привязки Telegram в `Настройки -> Интеграции` нужно:

- добавить в `planrun-backend/.env`:
  - `TELEGRAM_LOGIN_CLIENT_ID`
  - `TELEGRAM_LOGIN_CLIENT_SECRET`
  - `TELEGRAM_LOGIN_REDIRECT_URI=https://your-domain.com/api/telegram_login_callback.php`
  - опционально `TELEGRAM_BOT_TOKEN` и `TELEGRAM_BOT_USERNAME`, если бот должен сразу отправлять welcome-сообщение после логина
- в `@BotFather -> Bot Settings -> Web Login` зарегистрировать Allowed URLs:
  - `https://your-domain.com`
  - `https://your-domain.com/api/telegram_login_callback.php`

Без этих настроек кнопка Telegram Login будет показывать ошибку конфигурации.

## Worker очереди AI-планов

После миграций нужно запустить отдельный worker, иначе задачи генерации будут накапливаться в очереди и не выполняться:

```bash
cd /var/www/planrun/planrun-backend
php scripts/plan_generation_worker.php --daemon --sleep=10
```

- Разовый прогон для диагностики: `php scripts/plan_generation_worker.php --once`
- Для постоянной работы через `systemd`: `sudo ./deploy/install-plan-generation-worker.sh`
- После установки unit-файла: `sudo systemctl enable --now planrun-plan-generation-worker`

## Важные замечания по chat/AI env

- `PLANRUN_AI_API_URL` должен указывать на `http://127.0.0.1:8000/api/v1/generate-plan`
- Если хотите вести chat через общий orchestration layer, используйте `CHAT_USE_PLANRUN_AI=1`
- Если нужен прямой chat в backend без PlanRun AI, `LMSTUDIO_BASE_URL` можно оставить на `:1234` или переключить на `http://127.0.0.1:8081/v1` только после проверки tool-calling сценариев
