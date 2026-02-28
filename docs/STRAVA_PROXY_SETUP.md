# Настройка прокси для Strava API на выделенном сервере

Инструкция для агента/скрипта: что выполнить на VPS (Ubuntu/Debian) для обхода региональной блокировки Strava.

---

## Цель

Поднять HTTP-прокси на VPS в EU/US, чтобы сервер `s-vladimirov.ru` мог делать запросы к Strava API через этот прокси.

---

## Предусловия

- VPS с Ubuntu 22.04 или Debian 12 (или новее)
- Root или sudo
- IP основного сервера (s-vladimirov.ru) — его нужно будет разрешить в прокси

---

## Шаги

### 1. Установить Tinyproxy

```bash
apt update && apt install -y tinyproxy
```

### 2. Настроить Tinyproxy

Файл: `/etc/tinyproxy/tinyproxy.conf`

Найти строку `Allow 127.0.0.1` и заменить на IP основного сервера. Или добавить после неё:

```
Port 8888
Listen 0.0.0.0

# Разрешить доступ ТОЛЬКО с IP основного сервера (s-vladimirov.ru)
Allow YOUR_MAIN_SERVER_IP

LogLevel Critical
```

Если IP основного сервера неизвестен — временно можно закомментировать все `Allow` (тогда прокси будет доступен всем; небезопасно, только для теста).

### 3. Перезапустить Tinyproxy

```bash
systemctl restart tinyproxy
systemctl enable tinyproxy
systemctl status tinyproxy
```

### 4. Открыть порт в firewall (если используется ufw)

```bash
ufw allow from YOUR_MAIN_SERVER_IP to any port 8888
ufw reload
```

Или, если прокси только для одного IP:

```bash
ufw allow 8888/tcp
ufw reload
```

### 5. Проверить

С основного сервера:

```bash
curl -x http://PROXY_SERVER_IP:8888 https://www.strava.com/api/v3/athlete -I
```

Должен вернуться 401 (Unauthorized) — это нормально, значит до Strava дошли. 403 от CloudFront — блокировка, прокси не сработал.

---

## Итоговые данные для .env

На основном сервере (s-vladimirov.ru) в `.env`:

```
STRAVA_PROXY=http://PROXY_SERVER_IP:8888
```

Заменить `PROXY_SERVER_IP` на IP или hostname VPS с прокси.

---

## Альтернатива: 3proxy (ещё легче)

```bash
apt install -y 3proxy
```

Конфиг `/etc/3proxy/3proxy.cfg`:

```
nserver 8.8.8.8
nscache 65536
timeouts 1 5 30 60 180 1800 15 60
daemon
log /var/log/3proxy.log D
logformat "- +_L%t.%. %N.%p %E %U %C:%c %R:%r %O %I %h %T"
auth none
allow YOUR_MAIN_SERVER_IP
proxy -p8888 -i0.0.0.0 -e0.0.0.0
```

Запуск:

```bash
systemctl enable 3proxy
systemctl start 3proxy
```

---

## Чеклист для агента

- [ ] `apt update && apt install -y tinyproxy`
- [ ] Отредактировать `/etc/tinyproxy/tinyproxy.conf`: Port 8888, Allow IP_ОСНОВНОГО_СЕРВЕРА
- [ ] `systemctl restart tinyproxy && systemctl enable tinyproxy`
- [ ] `ufw allow from IP_ОСНОВНОГО_СЕРВЕРА to any port 8888` (если ufw включён)
- [ ] Вернуть пользователю: IP прокси-сервера, порт 8888, строка для STRAVA_PROXY
