# Android: эталонные алгоритмы аутентификации и сравнение с PlanRun

Справочник по официальным и рекомендуемым практикам для Android, сравнение с текущей реализацией PlanRun.

---

## 1. Официальные источники

| Источник | URL | Содержание |
|----------|-----|------------|
| **Android Identity** | [developer.android.com/identity](https://developer.android.com/identity) | Credential Manager, Passkeys |
| **Secure authentication** | [developer.android.com/security/fraud-prevention/authentication](https://developer.android.com/security/fraud-prevention/authentication) | Биометрия, таймауты, Class 3 |
| **Credential Manager** | [developer.android.com/identity/credential-manager](https://developer.android.com/identity/credential-manager) | Единый API для credentials (API 34+) |
| **Capacitor Security** | [capacitorjs.com/docs/guides/security](https://capacitorjs.com/docs/guides/security) | Хранение токенов, Keychain/Keystore |
| **Capgo Token Storage** | [capgo.app/blog/secure-token-storage-best-practices](https://capgo.app/blog/secure-token-storage-best-practices-for-mobile-developers/) | Сравнение методов, lifecycle |

---

## 2. Хранение токенов — рекомендуемый порядок

### Официальная рекомендация (Capacitor, Capgo, Android)

> **Не использовать localStorage/IndexedDB для чувствительных данных** — они могут быть очищены ОС при нехватке памяти и не имеют встроенного шифрования.

| Метод | Безопасность | Рекомендация |
|-------|--------------|--------------|
| **Android Keystore** | Очень высокая | Основной источник для токенов |
| **EncryptedSharedPreferences** | Высокая | Упрощённая обёртка над Keystore (deprecated в 1.1.0-alpha07, миграция на DataStore + Tink) |
| **Preferences (Capacitor)** | Средняя | Только как бэкап, не для единственного хранения |
| **localStorage** | Низкая | Только для нечувствительных данных |

### Наша реализация (PlanRun)

| Компонент | Источник | Соответствие |
|-----------|----------|--------------|
| TokenStorageService | SecureStorage (Keystore) + Preferences (бэкап) | ✅ Соответствует |
| ApiClient.getToken | TokenStorageService первым, localStorage fallback | ✅ Исправлено |
| BiometricService.getTokens | TokenStorageService первым, localStorage fallback | ✅ Исправлено |
| PinAuthService | AES-GCM, PBKDF2, Preferences | ✅ Допустимо (PIN шифрует ключ) |

---

## 3. Жизненный цикл токенов

### Рекомендации (Capgo, OWASP)

- **Access token:** короткий срок (5–15 мин)
- **Refresh token:** ротация при каждом использовании
- **Проактивный refresh:** обновлять за 60 сек до истечения
- **При foreground:** обновлять токены при возврате в приложение

### Наша реализация

| Параметр | Значение | Соответствие |
|----------|----------|--------------|
| PROACTIVE_REFRESH_MS | 60 сек | ✅ |
| Refresh при foreground | api.getCurrentUser() при `isAuthenticated \|\| _lockEnabled` | ✅ |
| Ротация refresh | На сервере (если реализовано) | Проверить бэкенд |

---

## 4. Биометрическая аутентификация (Android)

### Официальные рекомендации (Android)

- **androidx.biometric** — библиотека для fingerprint/face
- **Class 3** — для финансовых приложений
- **CryptoObject** — для усиленной защиты
- **allowDeviceCredential: true** — fallback на PIN/pattern устройства
- **Таймаут:** ~15 мин после ухода в фон

### Наша реализация

| Параметр | Значение | Соответствие |
|----------|----------|--------------|
| Библиотека | @aparajita/capacitor-biometric-auth | ✅ |
| allowDeviceCredential | true | ✅ |
| Таймаут блокировки | 15 мин (LOCK_AFTER_MS) | ✅ |
| Диалог при старте | Не показывать (риск зависания) | ✅ |

---

## 5. Почему после перезапуска приложения попадаем на landing

**Причины (исправлены):**

1. **TokenStorageService.getTokens()** — при недоступности SecureStorage (`_getSecureStorage()` возвращает null) не проверялся Preferences-бэкап, только localStorage. На Android localStorage очищается при kill → токены не находились.
2. **ApiClient.setToken()** — не ожидал завершения `saveTokens()` (fire-and-forget). При быстром закрытии приложения токены не успевали записаться в Preferences.

**Исправления:** Preferences проверяется при любом сбое SecureStorage; setToken ожидает saveTokens (Preferences пишется синхронно, SecureStorage — в фоне).

---

## 6. Известные проблемы Android (WebView / Capacitor)

### localStorage

- **Проблема:** localStorage может очищаться при убийстве приложения (swipe из recent)
- **Источник:** [Stack Overflow](https://stackoverflow.com/questions/16864280/android-localstorage-disappear-after-erasing-the-app-from-ram)
- **Решение:** Читать TokenStorageService (Preferences + SecureStorage) первым, localStorage — fallback

### KeyStore после обновления

- **Проблема:** KeyStore может сбрасываться после обновления Android
- **Симптом:** BadPaddingException при чтении SecureStorage
- **Решение:** Preferences-бэкап (auth_tokens_backup), fallback при ошибке чтения

### SecureStorage таймаут

- **Проблема:** Первое обращение к KeyStore может зависать 3+ сек
- **Решение:** withTimeout 5 сек, асинхронная запись в фоне

---

## 7. Чек-лист соответствия

| # | Критерий | Статус |
|---|----------|--------|
| 1 | Токены в Keystore/SecureStorage | ✅ |
| 2 | Бэкап при потере KeyStore | ✅ Preferences |
| 3 | Не полагаться на localStorage первым | ✅ Исправлено |
| 4 | Проактивный refresh при foreground (в т.ч. locked) | ✅ |
| 5 | Биометрия: allowDeviceCredential | ✅ |
| 6 | Таймаут блокировки 15 мин | ✅ |
| 7 | CredentialBackup для recovery | ✅ |
| 8 | HTTPS для API | ✅ |
| 9 | Certificate pinning | ❌ Не реализовано |
| 10 | Refresh token rotation | ⚠️ Частично: новый refresh выдаётся, но старый не отзывается |

---

## 8. Refresh token rotation — результат проверки бэкенда

**Файл:** `planrun-backend/services/JwtService.php`, метод `refreshAccessToken()`

| Что делает | Статус |
|------------|--------|
| Выдаёт новый access_token | ✅ |
| Выдаёт новый refresh_token | ✅ |
| **Отзывает старый refresh_token** | ❌ Нет |

**Проблема:** Старый refresh token остаётся в таблице `refresh_tokens` и действителен до истечения. При краже оба токена (старый и новый) работают параллельно.

**Исправление:** После создания нового refresh token вызвать `revokeRefreshToken($refreshToken)` для старого:

```php
// В refreshAccessToken(), после createRefreshToken():
$this->revokeRefreshToken($refreshToken);  // отозвать использованный токен
return [...];
```

---

## 9. Возможные улучшения

1. **Certificate pinning** — защита от MitM (OkHttp, Capacitor)
2. **Отзыв старого refresh token** при rotation (см. п. 8)
3. **Credential Manager** (API 34+) — если нужна поддержка Passkeys
4. **Миграция с EncryptedSharedPreferences** — если используется нативно (Android Keystore остаётся приоритетом)
