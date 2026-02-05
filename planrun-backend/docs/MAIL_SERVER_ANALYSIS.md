# Анализ почтового сервера и настройка SMTP

## Текущая конфигурация в проекте

| Компонент | Где | Назначение |
|-----------|-----|------------|
| **Отправка писем** | `services/EmailService.php` (PHPMailer) | Сброс пароля, в будущем — уведомления |
| **Конфиг** | `planrun-backend/.env` | MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION |
| **Резервный путь** | `AuthService::sendPasswordResetViaMail()` | PHP `mail()` без SMTP, если MAIL_HOST/MAIL_USERNAME пустые |

### Логика выбора способа отправки

- Если заданы **MAIL_HOST** и **MAIL_USERNAME** → используется **SMTP** (PHPMailer).
- Если оба пустые → используется **PHP mail()** (локальный sendmail/postfix).

---

## Что проверить, если Roundcube отправляет, а приложение — нет

### 1. Хост и порт SMTP

В `.env` не должен оставаться плейсхолдер:

- **MAIL_HOST**  
  - Если Roundcube и сайт на **одном сервере**: укажите `localhost` или `127.0.0.1`.  
  - Если почта на **другом сервере**: укажите тот же хост, что в настройках SMTP Roundcube (например `mail.s-vladimirov.ru` или FQDN сервера).
- **MAIL_PORT**  
  - `587` — обычно submission с **STARTTLS** (в .env задайте `MAIL_ENCRYPTION=tls`).  
  - `465` — SMTPS по SSL (задайте `MAIL_ENCRYPTION=ssl`).  
  - `25` — без шифрования (оставьте `MAIL_ENCRYPTION` пустым или не задавайте).

В Roundcube: «Настройки → Серверы → SMTP» — оттуда возьмите хост и порт и продублируйте в `.env`.

### 2. Логин и пароль

- **MAIL_USERNAME** — полный адрес ящика (как в Roundcube), например `noreply@s-vladimirov.ru` или `info@s-vladimirov.ru`.  
- **MAIL_PASSWORD** — пароль этого ящика (тот же, что в Roundcube).

Домен в логине должен совпадать с доменом почтового сервера (часто один и тот же, что и у Roundcube).

### 3. Шифрование

- В Roundcube для SMTP указано «STARTTLS» / «TLS» → в .env: `MAIL_ENCRYPTION=tls`, порт обычно 587.  
- Указано «SSL» / «SSL/TLS» → `MAIL_ENCRYPTION=ssl`, порт обычно 465.

### 4. Самоподписанный сертификат

Если сервер использует самоподписанный сертификат и PHPMailer падает с ошибкой проверки сертификата, в `.env` можно добавить (только для доверенной среды):

```env
MAIL_VERIFY_PEER=0
```

В коде это обрабатывается в `EmailService` (SMTPOptions с `verify_peer` = false при `MAIL_VERIFY_PEER=0`).

---

## Проверка на сервере (командная строка)

Убедиться, что SMTP доступен с того же хоста, где крутится приложение:

```bash
# Проверка порта 587 (TLS)
openssl s_client -connect localhost:587 -starttls smtp -brief 2>/dev/null || true

# Или для удалённого хоста (подставьте свой MAIL_HOST)
openssl s_client -connect mail.s-vladimirov.ru:587 -starttls smtp -brief 2>/dev/null || true
```

Проверка отправки через PHP (без веб-сервера):

```bash
cd /var/www/vladimirov/planrun-backend
php scripts/check_password_reset.php YOUR_USERNAME
```

Скрипт выведет, видит ли приложение пользователя, таблицу сброса пароля и инициализацию EmailService (PHPMailer). Реальную отправку проверяйте через «Забыли пароль» в браузере и по логам.

---

## Рекомендуемый .env для своего сервера (Roundcube на том же хосте)

```env
# Почта — те же параметры, что в Roundcube (SMTP)
MAIL_HOST=localhost
MAIL_PORT=587
MAIL_USERNAME=noreply@s-vladimirov.ru
MAIL_PASSWORD=ваш_пароль_ящика
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@s-vladimirov.ru
MAIL_FROM_NAME=PlanRun
```

Если Roundcube подключается к **удалённому** SMTP — подставьте в MAIL_HOST тот же хост, что в настройках SMTP Roundcube.

---

## Логи

После запроса сброса пароля смотрите:

- `planrun-backend/logs/info_*.log` — шаги [PWR], в т.ч. «EmailService send: start», «send: success» или «send: failed».
- `planrun-backend/logs/error_*.log` — текст ошибки PHPMailer (например таймаут, отказ в авторизации, проверка сертификата).

По ним можно точно понять: неверный хост/порт, неверный логин/пароль или ошибка SSL.
