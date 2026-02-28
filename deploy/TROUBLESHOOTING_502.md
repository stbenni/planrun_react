# 502/500 на /api/

## 500 Internal Server Error (Invalid JSON)

Если фронт получает 500 и «Invalid JSON response» — PHP возвращает не-JSON (HTML/текст ошибки). Смотри логи PHP-FPM.

**Исправление прав (обязательно после деплоя):**
```bash
sudo ./deploy/fix-api-permissions.sh
```

---

# 502 Bad Gateway на /api/

## 1. Проверка: отдаёт ли Nginx+PHP хотя бы простой скрипт

Открой в браузере или выполни:

```bash
curl -s -o /dev/null -w "%{http_code}" https://s-vladimirov.ru/api/health.php
```

- **200** — Nginx и PHP-FPM работают, путь к скриптам верный. Значит 502 даёт уже `api_wrapper.php` или его зависимости (см. шаг 2).
- **502** — проблема в конфиге Nginx или PHP-FPM (путь к скрипту, сокет, права). См. шаг 3.

## 2. Если health.php возвращает 200, а api_wrapper.php — 502

Смотри логи PHP-FPM (там будет текст ошибки PHP):

```bash
sudo tail -50 /var/log/php8.3-fpm.log
# или
sudo journalctl -u php8.3-fpm -n 50 --no-pager
```

Частые причины: нет расширения (mysqli, json, mbstring), ошибка в коде при подключении БД, нет/нечитаемый `planrun-backend/.env`.

Проверь права (PHP-FPM обычно под пользователем `www-data`):

```bash
ls -la /var/www/vladimirov/api/
ls -la /var/www/vladimirov/planrun-backend/.env
sudo -u www-data cat /var/www/vladimirov/planrun-backend/.env >/dev/null && echo "OK: www-data читает .env" || echo "FAIL: www-data не может прочитать .env"
```

## 3. Если и health.php отдаёт 502

Проверь логи Nginx и сгенерированный конфиг:

```bash
sudo tail -20 /var/log/nginx/vladimirov-error.log
grep -A2 "SCRIPT_FILENAME" /etc/nginx/sites-available/vladimirov-le-ssl.conf
```

Убедись, что путь к скрипту существует и совпадает с тем, что в конфиге:

```bash
ls -la /var/www/vladimirov/api/api_wrapper.php
```

Проверь, что сокет PHP-FPM доступен и сервис запущен:

```bash
ls -la /run/php/php8.3-fpm.sock
sudo systemctl status php8.3-fpm
```

## 4. PHP-FPM слушает на TCP (127.0.0.1:9999), а не на Unix socket

Если в `journalctl -u php8.3-fpm` видно `using inherited socket fd=8, "127.0.0.1:9999"`, то Nginx должен подключаться по TCP. Скрипт `apply-nginx.sh` теперь сам подставляет адрес из конфига пула (`listen =` в `/etc/php/8.3/fpm/pool.d/*.conf`). Перепримените конфиг:

```bash
sudo ./deploy/apply-nginx.sh
```

Если автоопределение не сработало, задайте вручную:

```bash
PHP_FPM_SOCK=127.0.0.1:9999 sudo ./deploy/apply-nginx.sh
```

## 5. open_basedir в PHP-FPM

Если в пуле задан `open_basedir`, он должен разрешать и каталог API, и backend:

```bash
sudo grep -r open_basedir /etc/php/8.3/fpm/
```

Если там только один каталог (например, только `dist`), добавь туда `/var/www/vladimirov` или отключи ограничение для этого пула.
