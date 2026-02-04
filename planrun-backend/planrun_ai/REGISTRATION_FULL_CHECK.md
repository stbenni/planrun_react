# ✅ Полная проверка регистрации и передачи данных

## 📋 Обязательные поля

### Шаг 0: Режим
- ✅ `training_mode` - обязателен (по умолчанию 'ai')

### Шаг 1: Аккаунт (для 'ai'/'both')
- ✅ `username` - обязателен (мин. 3 символа)
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере
  - ✅ Проверка уникальности
- ✅ `password` - обязателен (мин. 6 символов)
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере
- ⚠️ `email` - необязателен
  - ✅ Валидация формата (если указан)

### Шаг 2: Цель (для 'ai'/'both')
- ✅ `goal_type` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)
  - ✅ По умолчанию 'health'
- ✅ `training_start_date` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)

**Для goal_type = 'race' или 'time_improvement':**
- ✅ `race_date` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)

**Для goal_type = 'weight_loss':**
- ✅ `weight_goal_kg` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)
- ✅ `weight_goal_date` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)

**Для goal_type = 'health':**
- ✅ `health_program` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)
- ✅ `current_running_level` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)
- ✅ `health_plan_weeks` - обязателен (если health_program = 'custom')
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено)

### Шаг 3: Профиль
- ✅ `gender` - обязателен
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере
  - ⚠️ Для режима 'self' устанавливается 'male' по умолчанию
- ✅ `experience_level` - обязателен (для 'ai'/'both')
  - ✅ Валидация на клиенте
  - ✅ Валидация на сервере (добавлено, по умолчанию 'beginner')

## 🔄 Поток данных

### 1. Форма → API (RegisterScreen.jsx → register_api.php)

**Отправляется:**
```javascript
{
  // Обязательные
  training_mode, username, password, goal_type, training_start_date,
  gender, experience_level,
  
  // Условно обязательные (зависят от goal_type)
  race_date, weight_goal_kg, weight_goal_date,
  health_program, current_running_level, health_plan_weeks,
  
  // Опциональные
  email, birth_year, height_cm, weight_kg, weekly_base_km,
  sessions_per_week, preferred_days[], preferred_ofp_days[],
  ofp_preference, training_time_pref, has_treadmill,
  health_notes, device_type,
  race_distance, race_target_time,
  running_experience, easy_pace_min, easy_pace_sec,
  is_first_race, last_race_distance, last_race_distance_km,
  last_race_time, last_race_date
}
```

**Валидация на сервере:**
- ✅ username, password - проверяются
- ✅ gender - проверяется (кроме 'self')
- ✅ goal_type, training_start_date - проверяются (добавлено)
- ✅ Условные поля - проверяются в зависимости от goal_type (добавлено)
- ✅ experience_level - устанавливается 'beginner' по умолчанию (добавлено)

### 2. API → БД (register_api.php)

**Сохраняется:**
- ✅ Все поля из формы сохраняются в таблицу `users`
- ✅ JSON поля кодируются: `preferred_days`, `preferred_ofp_days`
- ✅ Преобразования:
  - `is_first_race` → `is_first_race_at_distance` (0/1)
  - `easy_pace_min/sec` → `easy_pace_sec` (секунды)
  - `last_race_date` (month) → добавляется '-01'

### 3. БД → Промпт (plan_generator.php → prompt_builder.php)

**Получается из БД:**
- ✅ Все поля из таблицы `users`
- ✅ Декодируются JSON: `preferred_days`, `preferred_ofp_days`

**Используется в промпте:**
- ✅ Основные данные: gender, birth_year (как возраст), height_cm, weight_kg
- ✅ Опыт: experience_level, weekly_base_km, sessions_per_week
- ✅ Цель: goal_type + специфичные поля
- ✅ Предпочтения: preferred_days, preferred_ofp_days, training_time_pref, ofp_preference, has_treadmill
- ✅ Ограничения: health_notes
- ✅ Расширенный профиль: running_experience, easy_pace_sec, is_first_race_at_distance, last_race_*

### 4. Промпт → PlanRun AI

**Отправляется:**
```json
{
  "user_data": { все данные пользователя },
  "goal_type": "health|race|weight_loss|time_improvement",
  "include_knowledge": true,
  "temperature": 0.3,
  "max_tokens": 16384,
  "base_prompt": "полный промпт с данными пользователя"
}
```

## ✅ Исправления

### Добавлена валидация на сервере

В `register_api.php` добавлена проверка:
- ✅ `goal_type` - обязателен для режима 'ai'/'both'
- ✅ `training_start_date` - обязателен
- ✅ `race_date` - обязателен для goal_type='race'/'time_improvement'
- ✅ `weight_goal_kg` и `weight_goal_date` - обязательны для goal_type='weight_loss'
- ✅ `health_program` и `current_running_level` - обязательны для goal_type='health'
- ✅ `health_plan_weeks` - обязателен для health_program='custom'
- ✅ `experience_level` - устанавливается 'beginner' по умолчанию

## 📊 Соответствие полей

| Поле формы | БД поле | В промпте | Статус |
|------------|---------|-----------|--------|
| training_mode | training_mode | ❌ | ✅ (используется для выбора режима) |
| username | username | ❌ | ✅ |
| password | password | ❌ | ✅ |
| email | email | ❌ | ✅ |
| goal_type | goal_type | ✅ | ✅ |
| race_date | race_date | ✅ | ✅ |
| race_distance | race_distance | ✅ | ✅ |
| race_target_time | race_target_time | ✅ | ✅ |
| weight_goal_kg | weight_goal_kg | ✅ | ✅ |
| weight_goal_date | weight_goal_date | ✅ | ✅ |
| health_program | health_program | ✅ | ✅ |
| health_plan_weeks | health_plan_weeks | ✅ | ✅ |
| current_running_level | current_running_level | ✅ | ✅ |
| training_start_date | training_start_date | ✅ | ✅ |
| gender | gender | ✅ | ✅ |
| birth_year | birth_year | ✅ (как возраст) | ✅ |
| height_cm | height_cm | ✅ | ✅ |
| weight_kg | weight_kg | ✅ | ✅ |
| experience_level | experience_level | ✅ | ✅ |
| weekly_base_km | weekly_base_km | ✅ | ✅ |
| sessions_per_week | sessions_per_week | ✅ | ✅ |
| preferred_days | preferred_days (JSON) | ✅ | ✅ |
| preferred_ofp_days | preferred_ofp_days (JSON) | ✅ | ✅ |
| ofp_preference | ofp_preference | ✅ | ✅ |
| training_time_pref | training_time_pref | ✅ | ✅ |
| has_treadmill | has_treadmill | ✅ | ✅ |
| health_notes | health_notes | ✅ | ✅ |
| running_experience | running_experience | ✅ | ✅ |
| easy_pace_min/sec | easy_pace_sec | ✅ | ✅ |
| is_first_race | is_first_race_at_distance | ✅ | ✅ |
| last_race_* | last_race_* | ✅ | ✅ |

## ✅ Выводы

1. **Валидация на клиенте:** ✅ Все обязательные поля проверяются
2. **Валидация на сервере:** ✅ Добавлена проверка всех обязательных полей
3. **Сохранение в БД:** ✅ Все поля сохраняются корректно
4. **Передача в промпт:** ✅ Все важные данные передаются
5. **Промпт для AI:** ✅ Учитывает все данные пользователя и цель

**Система полностью проверена и готова!** 🎉
