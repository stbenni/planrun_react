# Деплой

Скрипты используют только пути внутри проекта. `PROJECT_ROOT` = родитель каталога `deploy/`.

## Nginx (рекомендуется)

- **apply-nginx.sh** — генерирует конфиг из `vladimirov-le-ssl.nginx.conf.template` (SPA из `dist/` + `/api` через PHP-FPM), подставляет `{{PROJECT_ROOT}}` и сокет PHP-FPM, копирует в `sites-available`, включает сайт, перезагружает Nginx. Запуск из корня проекта: `sudo ./deploy/apply-nginx.sh`. Сокет FPM подставляется автоматически (php8.3/8.2); свой: `PHP_FPM_SOCK=unix:/run/php/php8.2-fpm.sock sudo ./deploy/apply-nginx.sh`.

## Apache (альтернатива)

- **apply-apache.sh** — генерирует Apache vhost из `vladimirov-le-ssl.conf.template`, копирует в `sites-available`, перезагружает Apache. Запуск: `sudo ./deploy/apply-apache.sh` из корня проекта.

## Общее

- **install-systemd.sh** — генерирует `planrun-react.service` (подставляет `{{PROJECT_ROOT}}`), копирует в `/etc/systemd/system/`. Запуск: `sudo ./deploy/install-systemd.sh` из корня проекта.

## Если в браузере 404 на /api/

- **Локальная разработка (`npm run dev`):** запросы к `/api` проксируются на бэкенд (по умолчанию `https://s-vladimirov.ru`). Свой бэкенд: `VITE_API_PROXY_TARGET=http://localhost npm run dev`.
- **Прод с Nginx:** выполните `sudo ./deploy/apply-nginx.sh`. Убедитесь, что PHP-FPM запущен и что SSL на месте.
- **Прод с Apache:** выполните `sudo ./deploy/apply-apache.sh`, проверьте, что vhost активен и обрабатывается PHP.

## Если 500 при логине или «Забыли пароль?»

На **том сервере**, где крутится сайт, один раз выполните миграции (создание таблиц `password_reset_tokens` и `refresh_tokens`):

```bash
cd /var/www/vladimirov/planrun-backend
php scripts/migrate_all.php
```

Должно вывести: `OK: password_reset_tokens`, `OK: refresh_tokens`, `All migrations done.`  
- **password_reset_tokens** — для сброса пароля по ссылке из письма.  
- **refresh_tokens** — для входа с JWT (в т.ч. с мобильного приложения).
