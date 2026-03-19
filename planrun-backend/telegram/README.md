# Telegram webhook — planrun.ru и прокси

## 1. planrun.ru (уже настроено)

- `webhook-internal.php` в `/var/www/planrun-bot/bot/` (источник: planrun-bot)
- `WEBHOOK_PROXY_SECRET` в planrun-backend/.env
- Nginx: см. `nginx-location.conf` или `deploy/planrun.nginx.conf.template`

## 2. VPS (tg.planrun.ru:8443) — прокси для всех ботов

Скопировать на VPS в `/var/www/telegram-webhook-proxy/`:

- `webhook-proxy.php` — маршрутизация по пути, backend URLs из .env
- `env-vps.example` → `.env`

Пути: `/webhook-proxy/planrun`, `/webhook-proxy/hday`, `/webhook-proxy/gpu-alert`, `/webhook-proxy/tsd`  
Обратная совместимость: `/webhook-proxy.php` → planrun

Backend URLs по умолчанию:
- planrun → planrun.ru/bot/webhook-internal.php
- hday, gpu-alert, tsd → alter-vision.ru/bots/.../bot.php или tsd.php

Переопределить в .env: `WEBHOOK_BACKEND_HDAY`, `WEBHOOK_BACKEND_GPU_ALERT`, `WEBHOOK_BACKEND_TSD`

## 3. Регистрация webhook

```bash
php planrun-backend/telegram/set-all-webhooks.php
```

Webhook URL: `https://tg.planrun.ru:8443/webhook-proxy/{bot}`

## 4. Проверка

Отправить `/start` каждому боту — ответ без задержки.
