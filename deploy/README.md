# Деплой

Скрипты используют только пути внутри проекта. `PROJECT_ROOT` = родитель каталога `deploy/`.

## Nginx (рекомендуется)

- **apply-nginx.sh** — генерирует конфиг из `planrun.nginx.conf.template` для `planrun.ru`/`www.planrun.ru` (SPA из `dist/` + `/api` через PHP-FPM), подставляет `{{PROJECT_ROOT}}`, `{{BOT_ROOT}}` и сокет PHP-FPM, копирует в `sites-available/planrun`, включает сайт и перезагружает Nginx. Запуск из корня проекта: `sudo ./deploy/apply-nginx.sh`. Сокет FPM подставляется автоматически (php8.3/8.2); свой: `PHP_FPM_SOCK=unix:/run/php/php8.2-fpm.sock sudo ./deploy/apply-nginx.sh`. При нестандартном пути к Telegram-боту можно задать `BOT_ROOT=/var/www/planrun-bot`.

## Apache (альтернатива)

- **apply-apache.sh** — генерирует Apache vhost из `planrun.apache.conf.template`, копирует в `sites-available/planrun-ssl.conf`, перезагружает Apache. Запуск: `sudo ./deploy/apply-apache.sh` из корня проекта.

## Общее

- **install-systemd.sh** — генерирует `planrun-react.service` (подставляет `{{PROJECT_ROOT}}`), копирует в `/etc/systemd/system/`. Запуск: `sudo ./deploy/install-systemd.sh` из корня проекта.

## AI serving stack

Основная production-архитектура — **DeepSeek API** (`https://api.deepseek.com`):

- Чат: `deepseek-v4-flash` через `LLM_CHAT_BASE_URL`
- Планогенерация: `deepseek-v4-pro` (planner/repair) + `deepseek-v4-flash` (detail/enricher)
- Все LLM-запросы идут через `LlmGateway` с retry, concurrency limiter, key pool

### Legacy: локальный llama-server (не используется в production)

Скрипты для локального стека остались для справки:

- **install-llama-serving-stack.sh** — ставит llama-server + planrun-ai + LM Studio
- `planrun-ai :8000` — Python orchestration layer, RAG (используется при `USE_SKELETON_GENERATOR=0`)

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
- **llm_gateway_locks** — для DB-backed ограничения одновременных запросов к DeepSeek.

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
- Для параллельной обработки планов используйте template unit после установки:

```bash
sudo systemctl enable --now \
  planrun-plan-generation-worker@1 \
  planrun-plan-generation-worker@2 \
  planrun-plan-generation-worker@3
```

Начинайте с 3 воркеров и повышайте количество только если в логах DeepSeek нет частых `429`/`503`.
Если есть несколько DeepSeek ключей, добавьте их в `PLAN_LLM_API_KEYS` через запятую/пробел/новую строку: общий `LlmGateway` будет распределять plan-запросы по пулу и писать fingerprint выбранного ключа в `ai_runtime_events`, не сохраняя сам ключ.
Одновременность регулируется через `LLM_GATEWAY_GLOBAL_MAX_CONCURRENT`, `PLAN_LLM_MAX_CONCURRENT` и `LLM_CHAT_MAX_CONCURRENT`. Для старта на одном DeepSeek ключе держите примерно `global=8`, `plan=3`, `chat=5`; если появятся `429`/`503`, снижайте сначала `chat`, затем `global`.

## Важные замечания по chat/AI env

- `LLM_CHAT_BASE_URL` и `PLAN_LLM_BASE_URL` — по умолчанию `https://api.deepseek.com`
- `LLM_CHAT_MODEL` — по умолчанию `deepseek-v4-flash`
- API ключи: `PLAN_LLM_API_KEY` (или пул `PLAN_LLM_API_KEYS`), fallback на `DEEPSEEK_API_KEY`
- `PLANRUN_AI_API_URL` — legacy orchestration layer, используется только при `PLAN_GENERATION_MODE` != `llm_planner`

## Proxy / Xray / Strava note (2026-03-24)

- Сервер в LAN: `192.168.0.6`.
- Прямой внешний IP сервера: `93.90.41.237`.
- В shell-сессиях пользователя `st_benni` заданы `HTTP_PROXY`/`HTTPS_PROXY` через локальный `xray` (`127.0.0.1:10809`; также доступны `127.0.0.1:10808` SOCKS и LAN-порты `192.168.0.6:10808/10809`).
- При выходе через этот proxy внешний IP виден как `45.148.117.13`.
- Важно: это не transparent tunnel всего сервера. Default route остаётся обычным (`via 192.168.0.1`), policy routing и firewall redirect всего исходящего трафика в `xray` не настроены. Через туннель идут только приложения/сессии, которые уважают `HTTP_PROXY`/`HTTPS_PROXY` или явно используют proxy.
- Для Strava отдельный `STRAVA_PROXY` в `planrun-backend/.env` оказался вредным: refresh token и webhook/sync падали по timeout.
- Рабочее состояние на 2026-03-24: `STRAVA_PROXY=` (пусто), Strava-интеграция работает напрямую, две пропавшие walking-активности успешно импортировались после отключения отдельного proxy.
- Если Strava снова начнёт блокироваться на прямом выходе, сначала перепроверьте `php scripts/check_strava.php`, и только потом возвращайтесь к отдельному proxy. Справка по отдельному Strava-proxy: `docs/STRAVA_PROXY_SETUP.md`.
