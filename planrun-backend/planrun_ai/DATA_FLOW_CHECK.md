# 🔍 Проверка передачи данных для создания плана

## 📊 Поток данных

### 1. Регистрация (RegisterScreen.jsx → register_api.php)

**Отправляется:**
```javascript
{
  training_mode, username, password, email,
  goal_type, race_distance, race_date, race_target_time,
  weight_goal_kg, weight_goal_date,
  health_program, health_plan_weeks, current_running_level,
  training_start_date,
  gender, birth_year, height_cm, weight_kg,
  experience_level, weekly_base_km, sessions_per_week,
  preferred_days[], preferred_ofp_days[],
  ofp_preference, training_time_pref, has_treadmill,
  health_notes, device_type,
  running_experience, easy_pace_min, easy_pace_sec,
  is_first_race, last_race_distance, last_race_distance_km,
  last_race_time, last_race_date
}
```

**Сохраняется в БД:**
- ✅ Все поля сохраняются в таблицу `users`
- ✅ JSON поля (`preferred_days`, `preferred_ofp_days`) кодируются в JSON

### 2. Генерация плана (plan_generator.php)

**Получает из БД:**
```php
SELECT 
  id, username, goal_type, race_distance, race_date, race_target_time,
  target_marathon_date, target_marathon_time, training_start_date,
  gender, birth_year, height_cm, weight_kg, experience_level,
  weekly_base_km, sessions_per_week, preferred_days, preferred_ofp_days,
  has_treadmill, ofp_preference, training_time_pref, health_notes,
  weight_goal_kg, weight_goal_date, health_program, health_plan_weeks,
  current_running_level, running_experience, easy_pace_sec,
  is_first_race_at_distance, last_race_distance, last_race_distance_km,
  last_race_time, last_race_date
FROM users WHERE id = ?
```

**Декодирует:**
- ✅ `preferred_days` - JSON → массив
- ✅ `preferred_ofp_days` - JSON → массив

### 3. Построение промпта (prompt_builder.php)

**Использует данные:**
- ✅ Основные: gender, birth_year, height_cm, weight_kg
- ✅ Опыт: experience_level, weekly_base_km, sessions_per_week
- ✅ Цель: goal_type + специфичные поля
- ✅ Предпочтения: preferred_days, preferred_ofp_days, training_time_pref, ofp_preference, has_treadmill
- ✅ Ограничения: health_notes
- ✅ Расширенный профиль (для race/time_improvement): running_experience, easy_pace_sec, is_first_race_at_distance, last_race_*

### 4. Отправка в PlanRun AI (planrun_ai_integration.php)

**Отправляется:**
```json
{
  "user_data": { все данные пользователя },
  "goal_type": "health|race|weight_loss|time_improvement",
  "include_knowledge": true,
  "temperature": 0.3,
  "max_tokens": 16384,
  "base_prompt": "построенный промпт"
}
```

## ✅ Соответствие полей

### Все поля из формы → БД → Промпт

| Поле формы | БД поле | В промпте | Статус |
|------------|---------|-----------|--------|
| training_mode | training_mode | ❌ (не используется в промпте) | ⚠️ |
| username | username | ❌ (не используется) | ✅ |
| password | password | ❌ (не используется) | ✅ |
| email | email | ❌ (не используется) | ✅ |
| goal_type | goal_type | ✅ | ✅ |
| race_distance | race_distance | ✅ | ✅ |
| race_date | race_date | ✅ | ✅ |
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
| device_type | device_type | ❌ (не используется) | ⚠️ |
| running_experience | running_experience | ✅ | ✅ |
| easy_pace_min/sec | easy_pace_sec | ✅ | ✅ |
| is_first_race | is_first_race_at_distance | ✅ | ✅ |
| last_race_distance | last_race_distance | ✅ | ✅ |
| last_race_distance_km | last_race_distance_km | ✅ | ✅ |
| last_race_time | last_race_time | ✅ | ✅ |
| last_race_date | last_race_date | ✅ | ✅ |

## ⚠️ Проблемы

### 1. Неполная валидация на сервере
- ❌ Нет проверки обязательных полей для разных типов целей
- ✅ Исправлено: добавлена валидация в register_api.php

### 2. Поля не используемые в промпте
- `device_type` - не используется (можно добавить или убрать)
- `training_mode` - не используется в промпте (но используется для выбора режима генерации)

### 3. Потенциальные NULL значения
- Если поля не заполнены, они могут быть NULL в БД
- В промпте проверяется `!empty()`, так что NULL поля не попадут в промпт
- Это нормально для опциональных полей

## ✅ Выводы

1. **Все обязательные поля валидируются на клиенте** ✅
2. **Добавлена валидация на сервере** ✅
3. **Все данные из формы сохраняются в БД** ✅
4. **Все данные из БД передаются в промпт** ✅
5. **Промпт учитывает все важные данные** ✅

**Система готова к использованию!** 🎉
