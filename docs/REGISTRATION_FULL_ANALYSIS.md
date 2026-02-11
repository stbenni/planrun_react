# Регистрация: полный разбор шагов и API

Доскональный анализ всех сценариев регистрации, фронтовых шагов и бэкенд-API.

---

## 1. Единый сценарий: минимальная регистрация + специализация

**Везде внедрена только минимальная регистрация; специализация проходит после входа на дашборде.**

| Этап | Описание | Где |
|------|----------|-----|
| **1. Регистрация** | Только логин, email, пароль. | Лендинг (модалка и кнопка «Сгенерировать AI‑план»), страница `/register`, PublicHeader «Зарегистрироваться». |
| **2. Специализация** | Режим → цель → профиль. | После входа: авто-попап на дашборде или кнопка «Настроить план» в шапке. |

Полная многошаговая форма (режим → аккаунт → цель → профиль) **отключена**: в коде используется только режим минимальной формы (`isMinimalFlow = !specializationOnly`) или режим специализации.

---

## 2. Точки входа на фронте

| Где | Действие | Что открывается |
|-----|----------|-----------------|
| **Лендинг** (`LandingScreen`) | Кнопка «Регистрация» в шапке | `RegisterModal` → `RegisterScreen` с **minimalOnly** (только 3 поля). |
| **Лендинг** | Кнопка «Сгенерировать AI‑план» | `navigate('/register')` → страница `RegisterScreen` с **minimalOnly**. |
| **App** | Маршрут `GET /register` | `RegisterScreen` с **minimalOnly** (если не авторизован и регистрация включена). |
| **Дашборд** (после минимальной регистрации) | При `user.onboarding_completed === false` | Авто-попап `SpecializationModal` → `RegisterScreen` с **specializationOnly** (режим → цель → профиль). |
| **Шапка** (TopHeader) | Кнопка «Настроить план» | Открывает тот же попап специализации (`setShowOnboardingModal(true)`). |

---

## 3. Режимы RegisterScreen

| Режим | Условие | Шаги формы | Submit |
|-------|---------|------------|--------|
| **Минимальная регистрация** | `!specializationOnly` (по умолчанию) | Один шаг: логин, email, пароль. | `api.registerMinimal()` → редирект на `/`. |
| **Специализация** | `specializationOnly` (попап на дашборде) | 3 шага (или 2 для self): 0=режим, 1=цель (или пропуск), 2=профиль. | `api.completeSpecialization()` → обновление user, закрытие попапа. |

Внутри компонента: `isMinimalFlow = !specializationOnly`. Полная форма (4 шага) не отображается.

---

## 4. API: сводка

Все запросы идут на **same-origin** `/api/` (например `https://s-vladimirov.ru/api/`). Обёртки в `api/` подключают CORS и сессию, затем включают бэкенд из `planrun-backend/`.

| API | Файл обёртки | Бэкенд | Метод | Назначение |
|-----|----------------|--------|--------|------------|
| Валидация поля | `api/register_api.php` | `planrun-backend/register_api.php` | GET | Проверка username/email (формат, уникальность). |
| Регистрация (минимальная и полная) | `api/register_api.php` | `planrun-backend/register_api.php` | POST | Создание пользователя: минимальная (register_minimal) или полная. |
| Завершение специализации | `api/complete_specialization_api.php` | `planrun-backend/complete_specialization_api.php` | POST | Обновление профиля и создание плана для уже авторизованного пользователя. |

---

## 5. API: детали по шагам

### 5.1 Валидация поля

- **URL:** `GET /api/register_api.php?action=validate_field&field=<name>&value=<value>`
- **Поля:** `username`, `email`.
- **Ответ:** `{ "valid": true|false, "message": "..." }` (UTF-8).

**username:**

- Не пустой, 3–50 символов.
- Паттерн: `[a-zA-Z0-9_а-яА-ЯёЁ\s-]+`.
- Уникален в `users` (проверка по БД).

**email:**

- Не пустой, валидный email.
- Уникален в `users` (где email не пустой).

Вызывается с фронта в `RegisterScreen` через `api.validateField(field, value)` при шаге «Аккаунт» (полная форма) и перед отправкой минимальной формы.

---

### 5.2 Регистрация (POST register_api.php)

- **URL:** `POST /api/register_api.php`
- **Тело:** JSON (или form-urlencoded).
- **Обязательные поля в теле:** `username`, `password`, `email` (для обоих вариантов).

Логика внутри одного endpoint:

1. Парсинг JSON / `$_POST`, trim username/password/email.
2. Базовая валидация: username и password не пустые, пароль ≥ 6, email не пустой и валидный.
3. **Ветка минимальной регистрации:** если в теле есть `register_minimal === true` (или truthy):
   - Проверка `site_settings.registration_enabled` (если таблица есть и значение `'0'` → ошибка).
   - Проверка уникальности username, генерация уникального `username_slug`.
   - **INSERT в `users`** только: `username`, `username_slug`, `password` (hash), `email`, `role='user'`, `onboarding_completed=0`, `training_mode='self'`, `goal_type='health'`, `gender='male'`.
   - **План не создаётся**, запись в `user_training_plans` не создаётся.
   - `session_start()`, запись в `$_SESSION`: `authenticated`, `user_id`, `username`, `login_time`.
   - Ответ: `{ "success": true, "message": "...", "plan_message": null, "user": { "id", "username", "email", "onboarding_completed": 0 } }`.
   - **exit** — дальше полная регистрация не выполняется.

4. **Полная регистрация** (если не минимальная):
   - Нормализация режима: `training_mode` из `['ai','coach','both','self']`, `coach` → `ai`; для `self` принудительно `goal_type = 'health'`.
   - Валидация по матрице обязательных полей (пол, цель, даты и т.д. в зависимости от режима и цели).
   - Проверка `site_settings.registration_enabled`, уникальность username, генерация slug.
   - **INSERT в `users`** — полный набор полей (включая `onboarding_completed=1`).
   - **INSERT в `user_training_plans`** (одна строка, `is_active=FALSE`).
   - План:
     - **self:** вызов `planrun_ai/create_empty_plan.php` (пустой календарь).
     - **ai/both:** запуск в фоне `planrun_ai/generate_plan_async.php`.
   - Сессия и ответ: `{ "success": true, "message": "...", "plan_message": "...", "user": { "id", "username", "email" } }`.

Любая ошибка валидации или БД возвращается как `{ "success": false, "error": "..." }`.

---

### 5.3 Завершение специализации (POST complete_specialization_api.php)

- **URL:** `POST /api/complete_specialization_api.php`
- **Авторизация:** обязательна (сессия: `$_SESSION['user_id']`, `username`).
- **Тело:** JSON с полями специализации (без username, password, email).

Последовательность:

1. Проверка сессии; при отсутствии user_id/username — `{ "success": false, "error": "Требуется авторизация" }`.
2. Парсинг JSON: режим, цель, даты, профиль (пол, опыт, предпочтения, темп, последний забег и т.д.) — те же правила и списки значений, что в полной регистрации.
3. Валидация обязательных полей по режиму и цели (пол, дата начала, поля по цели и т.д.).
4. **UPDATE `users`** по `id = $_SESSION['user_id']`: все перечисленные поля + `onboarding_completed=1`.
5. Очистка кеша пользователя: `clearUserCache($userId)`.
6. Если записи в `user_training_plans` для пользователя ещё нет — **INSERT** одной строки (start_date, marathon_date, target_time, is_active=FALSE).
7. Создание плана:
   - **self:** `create_empty_plan.php` (пустой календарь).
   - **ai/both:** запуск в фоне `generate_plan_async.php`.
8. Ответ: `{ "success": true, "message": "...", "plan_message": "...", "onboarding_completed": 1 }`.

Ошибки — в `error`.

---

## 6. Фронт: пошаговые сценарии

### 6.1 Минимальная регистрация (minimalOnly)

- **Шаги:** один экран — логин, email, пароль.
- **Валидация на фронте:** длина username ≥ 3, пароль ≥ 6, email не пустой; затем вызовы `validateField('username', ...)` и `validateField('email', ...)`.
- **Submit:** `handleSubmitMinimal()` → `api.registerMinimal({ username, email, password })` (в теле добавляется `register_minimal: true`).
- **После успеха:** обновление store (user, isAuthenticated), `onRegister(result.user)`, `navigate('/', { state: { registrationSuccess: true } })`. Модалка исчезает из-за перехода на дашборд.

### 6.2 Специализация (specializationOnly)

- **Шаги:**
  - **0:** выбор режима (ai / self / coach disabled). При self следующий шаг — профиль (цель не показывается).
  - **1:** цель (если не self): health / race / weight_loss / time_improvement + поля по цели + дата начала тренировок.
  - **2:** профиль: пол (обязательно), опыт (для не-self), остальные поля (рост, вес, дни, ОФП, темп, последний забег и т.д.).
- **Валидация на фронте:** на каждом шаге при «Далее» проверяются обязательные поля (режим, даты по цели, пол, опыт).
- **Submit на последнем шаге:** `handleSubmitSpecialization()` → `api.completeSpecialization(submitData)` (весь formData без username/password/email).
- **После успеха:** `api.getCurrentUser()` → `updateUser(userData)` (в т.ч. `onboarding_completed: true`), `onSpecializationSuccess()`, `onClose()`. Попап закрывается, дашборд больше не показывает попап/кнопку «Настроить план».

---

## 7. Цепочка вызовов (кратко)

**Минимальная регистрация:**

```
Landing/Register → RegisterScreen (minimalOnly)
  → validateField('username'), validateField('email')
  → POST /api/register_api.php { username, email, password, register_minimal: true }
  → planrun-backend/register_api.php: INSERT users (минимальный набор), session, exit
  → getCurrentUser() → navigate('/')
  → App: user.onboarding_completed === false → SpecializationModal
```

**Специализация:**

```
SpecializationModal → RegisterScreen (specializationOnly)
  → шаги 0, 1, 2 (режим, цель, профиль)
  → POST /api/complete_specialization_api.php (JSON с полями специализации)
  → planrun-backend/complete_specialization_api.php: UPDATE users, user_training_plans, create_empty_plan / generate_plan_async
  → getCurrentUser(), updateUser() → onClose()
```

---

## 8. Файлы

| Назначение | Путь |
|------------|------|
| Обёртка регистрации (CORS, сессия) | `api/register_api.php` |
| Логика валидации и регистрации (GET + POST) | `planrun-backend/register_api.php` |
| Обёртка специализации | `api/complete_specialization_api.php` |
| Логика специализации (POST) | `planrun-backend/complete_specialization_api.php` |
| Форма и шаги (минимальная / специализация / полная) | `src/screens/RegisterScreen.jsx` |
| Вызовы API (registerMinimal, register, completeSpecialization, validateField) | `src/api/ApiClient.js` |
| Модалка на лендинге (minimalOnly) | `src/components/RegisterModal.jsx` |
| Попап специализации на дашборде | `src/components/SpecializationModal.jsx` |
| Показ попапа и кнопки «Настроить план» | `src/App.jsx`, `src/components/common/TopHeader.jsx` |
| check_auth с onboarding_completed | `planrun-backend/controllers/AuthController.php` |
| Пустой план (self) | `planrun-backend/planrun_ai/create_empty_plan.php` |
| Асинхронная генерация плана (ai/both) | `planrun-backend/planrun_ai/generate_plan_async.php` |

---

## 9. Матрица обязательных полей (бэкенд)

- **Минимальная регистрация:** только username, password, email (на бэкенде остальное подставляется по умолчанию).
- **self (полная или специализация):** пол (или дефолт male), цель не выбирается (goal_type=health), experience_level = NULL, дата начала опциональна.
- **ai/both + health:** goal_type, training_start_date, health_program; при custom — health_plan_weeks; пол, опыт (или beginner).
- **ai/both + race / time_improvement:** goal_type, training_start_date, хотя бы одна дата (race_date или target_marathon_date); пол, опыт.
- **ai/both + weight_loss:** goal_type, training_start_date, weight_goal_kg, weight_goal_date; пол, опыт.

Общие для всех не-минимальных сценариев: пол (gender). Уникальность username и email проверяется при минимальной и полной регистрации; при специализации пользователь уже создан.

---

Везде внедрена только минимальная регистрация; специализация выполняется после входа на дашборде. Полная многошаговая форма отключена в UI.
