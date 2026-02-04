# 🔐 JWT Аутентификация - Руководство по использованию

## 📋 Обзор

JWT (JSON Web Token) аутентификация реализована для поддержки мобильных приложений и stateless API. Система поддерживает как JWT токены, так и PHP сессии для обратной совместимости.

## 🏗️ Архитектура

### Компоненты

1. **JwtService** (`services/JwtService.php`)
   - Создание и верификация JWT токенов
   - Управление refresh tokens
   - Хранение refresh tokens в БД

2. **AuthService** (`services/AuthService.php`)
   - Авторизация пользователей
   - Выход из системы
   - Обновление токенов
   - Валидация JWT токенов

3. **AuthController** (`controllers/AuthController.php`)
   - HTTP endpoints для аутентификации
   - Обработка запросов login/logout/refresh

4. **ApiClient** (`src/api/ApiClient.js`)
   - Клиентская библиотека для работы с JWT
   - Автоматическое обновление токенов
   - Хранение токенов в localStorage/AsyncStorage

## 🔑 Endpoints

### POST `/api_v2.php?action=login`

Авторизация пользователя с JWT токенами.

**Request:**
```json
{
  "username": "user",
  "password": "pass",
  "use_jwt": true
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user_id": 1,
    "username": "user",
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 3600
  }
}
```

### POST `/api_v2.php?action=logout`

Выход из системы и отзыв refresh token.

**Request:**
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "success": true
  }
}
```

### POST `/api_v2.php?action=refresh_token`

Обновление access token используя refresh token.

**Request:**
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 3600
  }
}
```

### GET `/api_v2.php?action=check_auth`

Проверка авторизации (поддерживает JWT и сессии).

**Response (JWT):**
```json
{
  "success": true,
  "data": {
    "authenticated": true,
    "user_id": 1,
    "username": "user",
    "auth_method": "jwt"
  }
}
```

**Response (Session):**
```json
{
  "success": true,
  "data": {
    "authenticated": true,
    "user_id": 1,
    "username": "user",
    "auth_method": "session"
  }
}
```

## 💻 Использование на фронтенде

### React (веб)

```javascript
import ApiClient from './api/ApiClient';

const api = new ApiClient();

// Авторизация с JWT
const result = await api.login('username', 'password', true);
// Токены автоматически сохраняются в localStorage

// Токены автоматически добавляются в заголовки запросов
const plan = await api.getPlan();

// Выход из системы
await api.logout();
```

### React Native / Capacitor

```javascript
import ApiClient from './api/ApiClient';

const api = new ApiClient();

// Для мобильных приложений JWT используется автоматически
const result = await api.login('username', 'password');
// Токены сохраняются в AsyncStorage

// Автоматическое обновление токенов при 401
api.onTokenExpired = () => {
  // Перенаправление на экран логина
  navigation.navigate('Login');
};
```

## 🔄 Автоматическое обновление токенов

ApiClient автоматически обновляет access token при получении 401 ошибки:

1. Проверяет наличие refresh token
2. Вызывает `/api_v2.php?action=refresh_token`
3. Сохраняет новые токены
4. Повторяет оригинальный запрос

## 🔒 Безопасность

### Access Token
- **Время жизни:** 1 час (3600 секунд)
- **Хранение:** localStorage (веб) / AsyncStorage (мобильное)
- **Использование:** В заголовке `Authorization: Bearer <token>`

### Refresh Token
- **Время жизни:** 7 дней (604800 секунд)
- **Хранение:** 
  - Клиент: localStorage/AsyncStorage
  - Сервер: БД таблица `refresh_tokens` (хешированный)
- **Использование:** Только для обновления access token

### Защита
- Refresh tokens хешируются перед сохранением в БД
- Старые refresh tokens удаляются при создании новых
- Refresh tokens можно отозвать через logout
- Секретный ключ настраивается через `.env` (`JWT_SECRET_KEY`)

## ⚙️ Конфигурация

### Backend (.env)

```env
JWT_SECRET_KEY=your-secret-key-change-in-production
JWT_EXPIRATION_TIME=3600
JWT_REFRESH_EXPIRATION_TIME=604800
```

### База данных

Таблица `refresh_tokens` создается автоматически:

```sql
CREATE TABLE refresh_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id),
  INDEX idx_token_hash (token_hash),
  INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 🔄 Обратная совместимость

Система поддерживает оба метода аутентификации:

1. **PHP сессии** (веб, по умолчанию)
   - Использует cookies
   - Работает через `login_api.php` / `logout_api.php`

2. **JWT токены** (мобильные приложения)
   - Использует заголовки Authorization
   - Работает через `api_v2.php?action=login`

`BaseController` автоматически определяет метод аутентификации и устанавливает сессию из JWT для обратной совместимости.

## 📝 Примеры

### Полный цикл авторизации

```javascript
// 1. Авторизация
const loginResult = await api.login('user', 'pass', true);
console.log('Access token:', loginResult.access_token);

// 2. Использование API
const plan = await api.getPlan();

// 3. Автоматическое обновление при истечении
// (происходит автоматически при 401)

// 4. Выход
await api.logout();
```

### Обработка ошибок

```javascript
try {
  await api.login('user', 'pass', true);
} catch (error) {
  if (error.code === 'LOGIN_FAILED') {
    console.error('Неверный логин или пароль');
  } else if (error.code === 'UNAUTHORIZED') {
    console.error('Требуется авторизация');
  }
}
```

## 🐛 Отладка

### Проверка токенов

```javascript
// Проверка авторизации
const auth = await api.request('check_auth', {}, 'GET');
console.log('Auth method:', auth.auth_method); // 'jwt' или 'session'
```

### Логирование

Включите логирование в ApiClient для отладки:

```javascript
// В ApiClient.js добавьте console.log для запросов
console.log('Request:', url, options);
console.log('Response:', data);
```

## ✅ Чеклист внедрения

- [x] JwtService создан и протестирован
- [x] AuthService создан и интегрирован
- [x] AuthController создан с endpoints
- [x] Таблица refresh_tokens создана
- [x] BaseController обновлен для поддержки JWT
- [x] ApiClient обновлен для работы с JWT
- [x] Автоматическое обновление токенов реализовано
- [x] Документация создана
- [ ] Тесты для JWT (опционально)
- [ ] Биометрическая аутентификация (следующий шаг)

---

**JWT аутентификация полностью реализована и готова к использованию!** 🎉
