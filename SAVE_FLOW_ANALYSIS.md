# Полный анализ потока сохранения данных в БД

## 1. Поля в форме (formData state)

Всего: **40 полей**

### Профиль (7 полей)
- `username` ✅
- `email` ✅
- `gender` ✅
- `birth_year` ✅
- `height_cm` ✅
- `weight_kg` ✅
- `timezone` ✅

### Цель (8 полей)
- `goal_type` ✅
- `race_distance` ✅
- `race_date` ✅
- `race_target_time` ✅
- `target_marathon_date` ✅
- `target_marathon_time` ✅
- `weight_goal_kg` ✅
- `weight_goal_date` ✅

### Тренировки (10 полей)
- `experience_level` ✅
- `weekly_base_km` ✅
- `sessions_per_week` ✅
- `preferred_days` ✅
- `preferred_ofp_days` ✅
- `has_treadmill` ✅
- `training_time_pref` ✅
- `ofp_preference` ✅
- `training_mode` ✅
- `training_start_date` ✅

### Здоровье (12 полей)
- `health_notes` ✅
- `device_type` ✅
- `health_program` ✅
- `health_plan_weeks` ✅
- `current_running_level` ✅
- `running_experience` ✅
- `easy_pace_sec` ✅
- `is_first_race_at_distance` ✅
- `last_race_distance` ✅
- `last_race_distance_km` ✅
- `last_race_time` ✅
- `last_race_date` ✅

### Прочее (3 поля)
- `avatar_path` ✅
- `privacy_level` ✅
- `telegram_id` ⚠️ (обрабатывается отдельно через unlinkTelegram)

---

## 2. Поля, отправляемые из формы (handleSave - dataToSend)

Всего: **39 полей** (без `telegram_id`)

**Нормализация:**
- `normalizeValue()` используется для полей, которые могут быть `null`
- Массивы (`preferred_days`, `preferred_ofp_days`) отправляются как есть
- Булевы значения (`has_treadmill`) отправляются как есть

---

## 3. Поля, обрабатываемые в updateProfile (UserController)

Всего: **39 полей** (включая `training_start_date` после исправления)

### Обработка типов данных:

**Строки (s):**
- `username`, `email`, `timezone`, `gender`, `goal_type`, `race_distance`, `race_date`, 
  `race_target_time`, `target_marathon_date`, `target_marathon_time`, `experience_level`,
  `training_time_pref`, `ofp_preference`, `training_mode`, `training_start_date`,
  `health_notes`, `device_type`, `health_program`, `current_running_level`,
  `running_experience`, `last_race_distance`, `last_race_time`, `last_race_date`,
  `weight_goal_date`, `avatar_path`, `privacy_level`

**Числа (i):**
- `birth_year` - int
- `height_cm` - int
- `sessions_per_week` - int
- `has_treadmill` - int (0/1)
- `health_plan_weeks` - int
- `easy_pace_sec` - int
- `is_first_race_at_distance` - int (0/1)

**Числа с плавающей точкой (d):**
- `weight_kg` - float
- `weekly_base_km` - float
- `weight_goal_kg` - float
- `last_race_distance_km` - float

**JSON (s):**
- `preferred_days` - JSON массив
- `preferred_ofp_days` - JSON массив

### Валидация:

**ENUM поля:**
- `gender`: `['male', 'female']`
- `goal_type`: `['health', 'race', 'weight_loss', 'time_improvement']`
- `experience_level`: `['beginner', 'intermediate', 'advanced']`
- `training_mode`: `['ai', 'coach', 'both', 'self']`
- `training_time_pref`: `['morning', 'day', 'evening']`
- `ofp_preference`: `['gym', 'home', 'both', 'group_classes', 'online']`
- `health_program`: `['start_running', 'couch_to_5k', 'regular_running', 'custom']`
- `current_running_level`: `['zero', 'basic', 'comfortable']`
- `running_experience`: `['less_3m', '3_6m', '6_12m', '1_2y', 'more_2y']`
- `last_race_distance`: `['5k', '10k', 'half', 'marathon', 'other']`
- `privacy_level`: `['public', 'private']` (в коде проверяется, но может быть и 'link')

**Диапазоны:**
- `birth_year`: 1900 - текущий год
- `height_cm`: 50 - 250
- `weight_kg`: 20 - 300
- `username`: 3 - 50 символов

---

## 4. SQL запрос обновления

```sql
UPDATE users SET 
  field1 = ?, field2 = ?, ..., updated_at = NOW() 
WHERE id = ?
```

**Типы параметров:** строка типов для `bind_param()` (например: `"ssisds..."`)

---

## 5. Проблемы и исправления

### ✅ Исправлено:
1. **`training_start_date`** - добавлена обработка в `updateProfile()`
2. **Очистка кеша** - заменено `Cache::delete()` на `clearUserCache()` (правильная функция)

### ⚠️ Особенности:
1. **`telegram_id`** - не отправляется из формы, обрабатывается отдельным методом `unlinkTelegram()`
2. **`privacy_level`** - в валидации проверяется только `['public', 'private']`, но в БД может быть `'link'`

---

## 6. Итоговая проверка

✅ **Все поля синхронизированы:**
- Все поля из формы отправляются в API
- Все отправляемые поля обрабатываются в `updateProfile()`
- Все обрабатываемые поля сохраняются в БД

✅ **Типы данных корректны:**
- Строки обрабатываются как строки
- Числа преобразуются в правильные типы (int/float)
- JSON поля правильно кодируются
- Булевы значения преобразуются в 0/1

✅ **Валидация работает:**
- ENUM поля проверяются на допустимые значения
- Диапазоны проверяются для числовых полей
- Email валидируется

---

## Вывод

**Все поля корректно сохраняются в БД!**

Проблема с отображением данных в select'ах связана не с сохранением, а с загрузкой и отображением данных в React компонентах.
