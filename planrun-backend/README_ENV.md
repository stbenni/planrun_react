# Настройка переменных окружения

## Быстрый старт

1. Скопируйте шаблон конфигурации:
```bash
cd planrun-backend
cp .env.example .env
```

2. Отредактируйте `.env` файл и укажите реальные значения:
```bash
nano .env
# или
vim .env
```

3. Убедитесь, что `.env` файл не попадает в git (уже добавлен в .gitignore)

## Важные переменные

### База данных
- `DB_PASSWORD` - **ОБЯЗАТЕЛЬНО** измените на реальный пароль
- `DB_NAME` - имя базы данных (по умолчанию `sv`)
- `DB_USER` - пользователь БД (по умолчанию `root`)

### Безопасность
- `JWT_SECRET` - секретный ключ для JWT токенов (сгенерируйте случайную строку)
- Используйте разные значения для разных окружений (dev/prod)

### Почта (восстановление пароля)
- `APP_URL` - URL сайта (для ссылок в письмах), например `https://s-vladimirov.ru`
- `MAIL_FROM_ADDRESS` - адрес отправителя писем (по умолчанию **info@planrun.ru**)
- `MAIL_FROM_NAME` - имя отправителя (например, PlanRun)
- Для отправки через ваш почтовый сервер укажите:
  - `MAIL_HOST` - хост SMTP (например `localhost` или `mail.planrun.ru`)
  - `MAIL_PORT` - порт (587 для TLS, 465 для SSL, 25 без шифрования)
  - `MAIL_USERNAME` - логин (часто совпадает с `MAIL_FROM_ADDRESS`, например `info@planrun.ru`)
  - `MAIL_PASSWORD` - пароль от ящика
  - `MAIL_ENCRYPTION` - `tls`, `ssl` или пусто (для порта 25)
- Если SMTP не настроен, используется PHP `mail()` (локальный sendmail)

### Таблицы для сброса пароля и JWT

При первом деплое или если в логах ошибка **Table '…password_reset_tokens' doesn't exist**, выполните один раз на сервере:

```bash
cd planrun-backend
php scripts/migrate_all.php
```

Создаются таблицы `password_reset_tokens` (сброс пароля по ссылке из письма) и `refresh_tokens` (JWT). Учётные данные берутся из `.env` (те же, что у приложения).

## Обратная совместимость

Если `.env` файл отсутствует, система будет использовать значения по умолчанию из кода. Это позволяет постепенно мигрировать на `.env` без поломки существующей функциональности.

## Проверка

После настройки `.env`, проверьте подключение к БД:
```php
<?php
require_once 'db_config.php';
$db = getDBConnection();
if ($db) {
    echo "Подключение успешно!";
} else {
    echo "Ошибка подключения!";
}
```

## Логи

Приложение пишет логи в каталог **`planrun-backend/logs/`**. На сервере каталог должен существовать и быть доступен для записи пользователю PHP-FPM (обычно `www-data`):

```bash
cd /var/www/vladimirov/planrun-backend
sudo mkdir -p logs
sudo chown www-data:www-data logs
```

| Файл | Содержимое |
|------|------------|
| `info_YYYY-MM-DD.log` | Инфо-события (отправка письма сброса пароля, обновление профиля и т.д.) |
| `error_YYYY-MM-DD.log` | Ошибки (падение отправки почты, исключения) |
| `php_errors.log` | Ошибки PHP (из `error_log`) |

Просмотр последних записей об отправке писем сброса пароля:
```bash
cd /var/www/vladimirov/planrun-backend
tail -20 logs/info_$(date +%Y-%m-%d).log
# или поиск по ключевым словам
grep -r "сброс пароля\|password reset\|Email send" logs/
```
