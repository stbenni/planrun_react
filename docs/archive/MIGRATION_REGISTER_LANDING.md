# 🔄 Миграция регистрации и лендинга в React проект

## ✅ Что уже сделано

1. ✅ Создан компонент `LandingScreen.jsx`
2. ✅ CSS для лендинга (скопирован из planrun/css/landing.css)

## 📋 Что нужно сделать

### 1. Создать API endpoint для регистрации

**Файл:** `/var/www/s-vladimirov.ru/planrun-backend/register_api.php`

Создайте файл с содержимым (см. ниже) или скопируйте из `/var/www/planrun/register.php` и адаптируйте:

```bash
# Создайте файл register_api.php в planrun-backend
# Используйте упрощенную версию API для регистрации
```

**Важно:** 
- Используйте БД `sv` (уже настроено в `db_config.php`)
- Поддерживайте CORS для React приложения
- Возвращайте JSON ответы

### 2. Создать компонент RegisterScreen

**Файл:** `/var/www/s-vladimirov.ru/src/screens/RegisterScreen.jsx`

Создайте многошаговую форму регистрации на основе `/var/www/planrun/register.php`

**Основные шаги:**
- Шаг 0: Выбор режима тренировок (AI/Coach/Self)
- Шаг 1: Аккаунт (username, password, email)
- Шаг 2: Цель (health/race/weight_loss/time_improvement)
- Шаг 3: Профиль (gender, birth_year, height, weight, experience)

### 3. Обновить ApiClient.js

**Файл:** `/var/www/s-vladimirov.ru/src/api/ApiClient.js`

Добавьте метод `register()`:

```javascript
async register(userData) {
  const registerUrl = this.baseUrl === '/api' 
    ? `${this.baseUrl}/register_api.php`
    : `${this.baseUrl}/register_api.php`;
  
  const response = await fetch(registerUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    credentials: 'include',
    body: JSON.stringify(userData),
  });
  
  const data = await response.json();
  if (data.success) {
    // Автологин после регистрации
    const userData = await this.getCurrentUser();
    return { success: true, user: userData };
  }
  throw new ApiError({ code: 'REGISTRATION_FAILED', message: data.error });
}
```

### 4. Обновить App.jsx

**Файл:** `/var/www/s-vladimirov.ru/src/App.jsx`

Добавьте роуты:

```javascript
import LandingScreen from './screens/LandingScreen';
import RegisterScreen from './screens/RegisterScreen';

// В Routes добавить:
<Route path="/landing" element={<LandingScreen />} />
<Route path="/register" element={<RegisterScreen api={api} onRegister={handleRegister} />} />

// Изменить главный роут:
<Route
  path="/"
  element={
    user ? (
      <DashboardScreen api={api} user={user} />
    ) : (
      <Navigate to="/landing" replace />
    )
  }
/>
```

### 5. Создать API обертку для регистрации

**Файл:** `/var/www/s-vladimirov.ru/api/register_api.php`

Создайте обертку аналогично `login_api.php`:

```php
<?php
// CORS headers
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    exit(0);
}

// Настройки сессии
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

require_once __DIR__ . '/../planrun-backend/register_api.php';
```

## 🔧 Адаптация под БД sv

Все запросы к БД уже используют `db_config.php`, который настроен на БД `sv`. Проверьте:

1. ✅ `planrun-backend/db_config.php` использует `DB_NAME = 'sv'`
2. ✅ Все файлы используют `getDBConnection()` из `db_config.php`
3. ✅ Нет хардкода имени БД в коде

## 📝 Структура данных регистрации

Основные поля для упрощенной версии:

```javascript
{
  username: string (3-50 символов),
  password: string (мин. 6 символов),
  email: string (опционально),
  goal_type: 'health' | 'race' | 'weight_loss' | 'time_improvement',
  gender: 'male' | 'female',
  training_mode: 'ai' | 'coach' | 'both' | 'self',
  // Дополнительные поля можно добавить позже
}
```

## 🚀 Следующие шаги

1. Создайте `register_api.php` в `planrun-backend/`
2. Создайте `RegisterScreen.jsx` 
3. Обновите `ApiClient.js` и `App.jsx`
4. Создайте обертку `api/register_api.php`
5. Протестируйте регистрацию

## 📚 Ресурсы

- Оригинальная регистрация: `/var/www/planrun/register.php`
- Оригинальный лендинг: `/var/www/planrun/landing.php`
- API клиент: `/var/www/s-vladimirov.ru/src/api/ApiClient.js`
