# Гайд: проксирование Telegram webhook для обхода ограничений в РФ

**Для агента:** полная инструкция по настройке webhook за пределами РФ, чтобы бот PlanRun и другие Telegram-боты стабильно получали обновления.

---

## 1. Контекст проблемы

### 1.1 Цепочка доставки обновлений

```
[Пользователь в Telegram] 
    → отправляет сообщение
[Серверы Telegram]
    → POST на https://planrun.ru/bot/webhook.php (наш webhook)
[Наш сервер planrun.ru]
    → обрабатывает, вызывает api.telegram.org (sendMessage, getFile и т.д.)
    → ответ уходит пользователю
```

### 1.2 Где возникает задержка/блокировка

| Участок | Направление | Проблема в РФ |
|---------|-------------|---------------|
| **Доставка обновлений** | Серверы Telegram → planrun.ru | Канал дросселируется. Telegram не получает ответ 200 вовремя → «Read timeout expired». Обновления накапливаются в pending. |
| **Исходящие запросы** | planrun.ru → api.telegram.org | Уже решено: SOCKS5 proxy (127.0.0.1:10808). |
| **Доставка ответа пользователю** | Telegram → пользователь | Зависит от клиента (MTProxy, VPN). |

### 1.3 Симптомы

- Пользователь пишет боту `/start` или команду — бот не отвечает или отвечает с большой задержкой.
- В `getWebhookInfo`: `last_error_message: "Read timeout expired"`, `pending_update_count` растёт.
- В логах бота (`bot.log`) обновления приходят, но с задержкой или не приходят вовсе.

---

## 2. Решение: webhook-прокси на VPS в EU/US

Идея: разместить **лёгкий прокси** на VPS за пределами РФ (тот же, что для Strava). Он принимает POST от Telegram, сразу отвечает 200, и асинхронно пересылает тело запроса на planrun.ru.

```
[Telegram] → VPS (EU): /webhook-proxy.php
                → мгновенный 200 OK
                → в фоне: POST body → planrun.ru/bot/webhook-internal.php
[planrun.ru] получает update, обрабатывает, шлёт sendMessage через SOCKS5
```

### 2.1 Требования

- VPS в EU/US (например, тот же, где Tinyproxy для Strava).
- PHP 7.4+ с curl.
- HTTPS (Let's Encrypt или свой сертификат).
- Домен или поддомен, указывающий на VPS (например, `tg.planrun.ru` или `webhook.planrun.ru`).

---

## 3. Архитектура

### 3.1 Компоненты

| Компонент | Где | Назначение |
|-----------|-----|------------|
| **webhook-proxy.php** | VPS (EU) | Принимает POST от Telegram, сразу 200, пересылает на planrun.ru. |
| **webhook-internal.php** | planrun.ru | Внутренний endpoint, принимает update от прокси, вызывает ту же логику, что и webhook.php. |
| **Nginx на VPS** | VPS (EU) | Отдаёт webhook-proxy.php по HTTPS. |
| **Nginx на planrun.ru** | Основной сервер | Принимает запросы на /bot/webhook-internal.php (можно ограничить по IP VPS). |

### 3.2 Схема

```
Telegram API
    │
    │ POST (update)
    ▼
┌─────────────────────────────────────┐
│  VPS (EU)                            │
│  https://tg.planrun.ru/webhook-proxy │
│  - Принять POST                      │
│  - Ответить 200 сразу                 │
│  - В фоне: curl POST → planrun.ru    │
└─────────────────────────────────────┘
    │
    │ POST (тело update)
    ▼
┌─────────────────────────────────────┐
│  planrun.ru                          │
│  /bot/webhook-internal.php           │
│  - Та же логика, что webhook.php     │
│  - Вызов sendMessage через SOCKS5   │
└─────────────────────────────────────┘
```

---

## 4. Реализация

### 4.1 Скрипт webhook-proxy.php (VPS в EU)

Путь на VPS: `/var/www/telegram-webhook-proxy/webhook-proxy.php`

```php
<?php
/**
 * Прокси для Telegram webhook.
 * Принимает POST от Telegram, сразу отвечает 200, пересылает на planrun.ru.
 * Размещается на VPS в EU/US для обхода дросселирования в РФ.
 */

header('Content-Type: application/json');

$config = [
    'backend_url' => 'https://planrun.ru/bot/webhook-internal.php',
    'secret'      => getenv('WEBHOOK_PROXY_SECRET') ?: '', // секрет для проверки на planrun.ru
];

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty body']);
    exit;
}

// Сразу отвечаем Telegram
http_response_code(200);
header('Connection: close');
header('Content-Length: 0');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Пересылаем в фоне
$ch = curl_init($config['backend_url']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $rawBody,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Webhook-Proxy-Secret: ' . $config['secret'],
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 55,
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Логировать при ошибке (опционально)
if ($code !== 200) {
    error_log("Webhook proxy: backend returned $code");
}
```

### 4.2 webhook-internal.php (planrun.ru)

Путь: `/var/www/planrun-bot/bot/webhook-internal.php`

Это копия логики webhook.php, но:
- Проверяет заголовок `X-Webhook-Proxy-Secret`.
- Опционально: проверка IP (только с VPS).
- Читает тело из `php://input` (как обычно).

Минимальная реализация — вынести общую логику в функцию и вызывать из обоих файлов:

```php
<?php
/**
 * Внутренний webhook: принимает update от прокси на VPS.
 * Доступ только с доверенного IP или по секрету.
 */
$_SERVER['WEBHOOK_SOURCE'] = 'proxy';

$secret = getenv('WEBHOOK_PROXY_SECRET') ?: '';
$headerSecret = $_SERVER['HTTP_X_WEBHOOK_PROXY_SECRET'] ?? '';
if ($secret !== '' && !hash_equals($secret, $headerSecret)) {
    http_response_code(403);
    exit;
}

// Подключаем основной webhook (без дублирования кода)
require __DIR__ . '/webhook.php';
```

Но webhook.php сейчас не предназначен для require — он выполняет всё с нуля. Проще скопировать всю логику в webhook-internal.php или рефакторить webhook.php в два файла: `webhook-bootstrap.php` (чтение input, проверка) и `webhook-handler.php` (логика). Для простоты можно сделать webhook-internal.php, который читает input и вызывает ту же цепочку, что и webhook.php.

**Альтернатива:** webhook-internal.php — это тот же webhook.php, но с проверкой секрета в начале. Можно добавить в webhook.php:

```php
if (($_SERVER['WEBHOOK_SOURCE'] ?? '') === 'proxy') {
    // уже пришло от прокси, проверка секрета в webhook-internal
}
```

Проще всего: webhook-internal.php делает require после проверки, а webhook.php при require видит, что input уже прочитан... Нет, при require php://input уже consumed. Нужно по-другому.

**Правильный подход:** webhook-internal.php читает `php://input`, проверяет секрет, и передаёт тело в общую функцию. Рефакторинг:

1. Создать `webhook-handler.php` с функцией `handleWebhookUpdate(string $rawBody): void`.
2. `webhook.php` читает input, вызывает `handleWebhookUpdate($content)`.
3. `webhook-internal.php` читает input, проверяет секрет, вызывает `handleWebhookUpdate($content)`.

Это потребует рефакторинга webhook.php. Для гайда опишу упрощённый вариант: webhook-internal.php — полная копия webhook.php с добавленной проверкой секрета в начале. Дублирование кода, но работает.

### 4.3 Упрощённый webhook-internal.php

```php
<?php
/**
 * Внутренний webhook: принимает update от прокси на VPS (EU).
 * Проверка: X-Webhook-Proxy-Secret.
 */
$secret = getenv('WEBHOOK_PROXY_SECRET') ?: '';
if ($secret !== '') {
    $headerSecret = $_SERVER['HTTP_X_WEBHOOK_PROXY_SECRET'] ?? '';
    if (!hash_equals($secret, $headerSecret)) {
        http_response_code(403);
        exit;
    }
}

// Дальше — идентичная логика webhook.php
// Вариант: require после того как прокси передал тело в POST
// Прокси шлёт POST с телом = raw update. Мы читаем php://input.
require __DIR__ . '/webhook.php';
```

Проблема: при require webhook.php он снова прочитает php://input. Но к моменту require тело уже в $_POST или в php://input. Прокси шлёт Content-Type: application/json и body. На planrun.ru php://input будет содержать это тело. webhook.php читает php://input в начале. Так что при require webhook.php он прочитает то же тело. OK.

Но webhook.php в начале делает:
```php
$content = file_get_contents('php://input');
```
и потом
```php
$update = json_decode($content, true);
```

При require из webhook-internal.php, webhook.php выполнится и прочитает php://input. Всё верно. Единственное — нужно убедиться, что webhook-internal.php не читает input до require. Он не читает. Отлично.

Но подождите — webhook-internal.php будет обрабатываться nginx как отдельный location. Нужно добавить location в nginx для /bot/webhook-internal.php. И ограничить доступ по IP (опционально).

### 4.4 Nginx на planrun.ru

Добавить в `planrun.nginx.conf.template`:

```nginx
location = /bot/webhook-internal.php {
    allow 1.2.3.4;  # IP VPS-прокси
    deny all;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME {{BOT_ROOT}}/bot/webhook-internal.php;
    fastcgi_param SCRIPT_NAME /bot/webhook-internal.php;
    fastcgi_pass {{PHP_FPM_SOCK}};
    fastcgi_read_timeout 60;
    fastcgi_buffering off;
}
```

### 4.5 Nginx на VPS (EU)

```nginx
server {
    listen 443 ssl http2;
    server_name tg.planrun.ru;
    ssl_certificate /etc/letsencrypt/live/tg.planrun.ru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tg.planrun.ru/privkey.pem;

    root /var/www/telegram-webhook-proxy;
    location = /webhook-proxy.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/webhook-proxy.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_read_timeout 10;
        fastcgi_buffering off;
    }
}
```

### 4.6 Переменные окружения

**На VPS (EU):**
```bash
export WEBHOOK_PROXY_SECRET="$(openssl rand -hex 32)"
```

**На planrun.ru** (в .env или systemd unit для php-fpm):
```
WEBHOOK_PROXY_SECRET=<то же значение>
```

### 4.7 Регистрация webhook в Telegram

Вместо `https://planrun.ru/bot/webhook.php` указать:

```
https://tg.planrun.ru/webhook-proxy.php
```

Команда:
```bash
curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://tg.planrun.ru/webhook-proxy.php"
```

---

## 5. Чек-лист для агента

- [ ] VPS в EU/US с PHP и Nginx
- [ ] Домен tg.planrun.ru (или другой) → A-запись на IP VPS
- [ ] SSL (Let's Encrypt) для tg.planrun.ru
- [ ] Создать `/var/www/telegram-webhook-proxy/webhook-proxy.php`
- [ ] Сгенерировать `WEBHOOK_PROXY_SECRET`, прописать на VPS и planrun.ru
- [ ] Создать `webhook-internal.php` на planrun.ru (проверка секрета + require webhook.php)
- [ ] Добавить location для `/bot/webhook-internal.php` в nginx planrun.ru
- [ ] Добавить `allow IP_VPS` в location webhook-internal
- [ ] Настроить nginx на VPS для webhook-proxy.php
- [ ] Вызвать setWebhook с URL `https://tg.planrun.ru/webhook-proxy.php`
- [ ] Проверить: отправить /start боту, убедиться в быстром ответе

---

## 6. Миграция всех ботов

### 6.1 Боты и их backend-URL

| Бот | Текущий webhook | Backend для прокси |
|-----|-----------------|--------------------|
| **planrun** | planrun.ru/bot/webhook.php | planrun.ru/bot/webhook-internal.php (с секретом) |
| **hday** | alter-vision.ru/bots/hday/bot.php | alter-vision.ru/bots/hday/bot.php |
| **gpu-alert** | alter-vision.ru/bots/gpu-alert/bot.php | alter-vision.ru/bots/gpu-alert/bot.php |
| **tsd** | alter-vision.ru/bots/tsd/tsd.php | alter-vision.ru/bots/tsd/tsd.php |

### 6.2 Прокси с маршрутизацией по пути (VPS)

Заменить `webhook-proxy.php` на версию с несколькими бэкендами:

```php
<?php
/**
 * Прокси для Telegram webhook — несколько ботов.
 * Маршрутизация по пути: /webhook-proxy/planrun, /webhook-proxy/hday и т.д.
 */
header('Content-Type: application/json');

$secret = getenv('WEBHOOK_PROXY_SECRET') ?: '';

$defaults = [
    'planrun'   => 'https://planrun.ru/bot/webhook-internal.php',
    'hday'      => 'https://alter-vision.ru/bots/hday/bot.php',
    'gpu-alert' => 'https://alter-vision.ru/bots/gpu-alert/bot.php',
    'tsd'       => 'https://alter-vision.ru/bots/tsd/tsd.php',
];
// Backend URLs переопределяются через .env: WEBHOOK_BACKEND_HDAY=https://...

$path = trim($_SERVER['REQUEST_URI'] ?? '', '/');
$parts = explode('/', $path);
$bot = $parts[1] ?? ''; // webhook-proxy/planrun -> planrun

if (!isset($routes[$bot])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown bot']);
    exit;
}

$route = $routes[$bot];
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty body']);
    exit;
}

http_response_code(200);
header('Connection: close');
header('Content-Length: 0');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$headers = ['Content-Type: application/json'];
if ($route['secret'] && $secret !== '') {
    $headers[] = 'X-Webhook-Proxy-Secret: ' . $secret;
}

$ch = curl_init($route['url']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $rawBody,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 55,
]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) {
    error_log("Webhook proxy [$bot]: backend returned $code");
}
```

### 6.3 Nginx на VPS — location по префиксу

```nginx
location ~ ^/webhook-proxy/([a-z0-9-]+)$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/webhook-proxy.php;
    fastcgi_param SCRIPT_NAME /webhook-proxy.php;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_read_timeout 10;
    fastcgi_buffering off;
}
```

### 6.4 setWebhook для каждого бота

Webhook URL: `https://tg.planrun.ru:8443/webhook-proxy/{bot}`

| Бот | URL |
|-----|-----|
| planrun | https://tg.planrun.ru:8443/webhook-proxy/planrun |
| hday | https://tg.planrun.ru:8443/webhook-proxy/hday |
| gpu-alert | https://tg.planrun.ru:8443/webhook-proxy/gpu-alert |
| tsd | https://tg.planrun.ru:8443/webhook-proxy/tsd |

### 6.5 Изменения на бэкендах

- **planrun.ru**: уже есть `webhook-internal.php` и nginx location ✓
- **alter-vision.ru**: изменений не требуется — прокси пересылает на существующие `bot.php` / `tsd.php`

### 6.6 Обновить check_webhook / set_webhook

В `hday/check_webhook.php`, `hday/set_webhook.php` заменить URL:

```php
$webhookUrl = 'https://tg.planrun.ru/webhook-proxy/hday';
```

Аналогично для gpu-alert и tsd (если есть скрипты установки).

---

## 7. Альтернативы

### 7.1 Cloudflare Workers / Vercel / AWS Lambda

Можно разместить прокси на edge-функции: принять POST, сразу 200, переслать на planrun.ru. Меньше возни с VPS.

### 7.2 Long Polling (getUpdates)

Вместо webhook — скрипт по cron каждые 1–2 сек вызывает `getUpdates`. Запросы идут с planrun.ru через SOCKS5 к api.telegram.org. Ограничения РФ на входящий трафик не влияют. Минус: менее эффективно, не рекомендуется для production.

### 7.3 Telegram Bot API через прокси

Некоторые библиотеки поддерживают прокси для webhook-сервера. В нашем случае webhook — это входящие запросы, проксировать нужно «приём» на стороне сервера. Стандартный HTTP-прокси для входящих не подходит. Нужен именно reverse proxy (VPS принимает и пересылает).

---

## 7. Текущее состояние проекта

- **Прокси для исходящих** (api.telegram.org): `TELEGRAM_PROXY=socks5://127.0.0.1:10808` в planrun-backend/.env
- **Боты с прокси:** planrun, gpu-alert, hday, tsd (altervision)
- **Webhook planrun:** https://planrun.ru/bot/webhook.php
- **Проблема:** Read timeout из-за дросселирования Telegram → planrun.ru в РФ

---

## 8. Ссылки

- [Telegram Bot API: getUpdates vs Webhooks](https://core.telegram.org/bots/api#getupdates)
- [Telegram Webhooks](https://core.telegram.org/bots/webhooks)
- STRAVA_PROXY_SETUP.md — аналогичная схема для Strava (Tinyproxy на VPS)
