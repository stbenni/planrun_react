# PlanRun — auth и security policy

Краткая памятка по текущей политике авторизации и мобильной разблокировки.

---

## JWT

- `JWT_SECRET_KEY` обязателен в production и должен быть не короче 32 символов.
- В local/dev допускается только локальный fallback-секрет.
- По умолчанию:
  - `JWT_ACCESS_EXPIRATION_DAYS=1`
  - `JWT_REFRESH_INITIAL_DAYS=30`
  - `JWT_REFRESH_SLIDING_DAYS=30`
  - `JWT_REFRESH_MAX_AGE_DAYS=90`

## Anti-abuse

- `login`: лимит по IP и по логину в `planrun-backend/controllers/AuthController.php`
- `request_password_reset`: лимит по IP и по email/логину
- `confirm_password_reset`: лимит по IP и по reset token
- `send_verification_code`: лимит по IP и по email
- Фронтенд auth-форм читает `retry_after`, показывает понятное сообщение и блокирует повторную попытку на время cooldown.

## Frontend auth client

- Auth-операции вынесены из `src/api/ApiClient.js` в отдельный модуль `src/api/authApi.js`.
- Ошибки API и `retry_after` нормализуются через `src/api/apiError.js`.
- Экранные auth-формы используют общий клиент через `src/api/getAuthClient.js`, чтобы не плодить локальные экземпляры.

## Backend auth/register services

- `planrun-backend/services/EmailVerificationService.php` отвечает за хранение, проверку и отправку email-кодов регистрации.
- `planrun-backend/services/RegistrationService.php` централизует `validate_field`, minimal registration и основную регистрацию.
- `planrun-backend/register_api.php` теперь тоньше: runtime DDL убран, бизнес-логика вынесена в service layer.

## Mobile unlock

- PIN хранит только `accessToken` и `refreshToken`, зашифрованные через `AES-GCM`.
- Новый формат PIN-данных использует versioned payload и `PBKDF2` `120000`.
- Старый PIN-формат на `PBKDF2` `1000` всё ещё читается для обратной совместимости.
- После серии неверных PIN-попыток включается локальный lockout с нарастающей задержкой.

## Recovery credentials

- PIN recovery хранит логин/пароль только в PIN-encrypted виде.
- Biometric recovery хранит логин/пароль в SecureStorage только если биометрия явно включена пользователем.
- Silent recovery по истёкшему токену использует только biometric recovery, а не общий recovery для всех native-пользователей.

## Что проверить на проде

- В `planrun-backend/.env` задан `JWT_SECRET_KEY`
- Нет старых значений TTL токенов, противоречащих новой политике
- Cache backend для `RateLimiter` работает стабильно
- UX с сообщениями `429` понятен на форме логина, reset и регистрации
