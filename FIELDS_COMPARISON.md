# Сравнение полей формы настроек профиля

## Поля в formData (состояние формы)

Всего: **40 полей**

### Профиль (7 полей)
1. `username` ✅
2. `email` ✅
3. `gender` ✅
4. `birth_year` ✅
5. `height_cm` ✅
6. `weight_kg` ✅
7. `timezone` ✅

### Цель (8 полей)
8. `goal_type` ✅
9. `race_distance` ✅
10. `race_date` ✅
11. `race_target_time` ✅
12. `target_marathon_date` ✅
13. `target_marathon_time` ✅
14. `weight_goal_kg` ✅
15. `weight_goal_date` ✅

### Тренировки (10 полей)
16. `experience_level` ✅
17. `weekly_base_km` ✅
18. `sessions_per_week` ✅
19. `preferred_days` ✅
20. `preferred_ofp_days` ✅
21. `has_treadmill` ✅
22. `training_time_pref` ✅
23. `ofp_preference` ✅
24. `training_mode` ✅
25. `training_start_date` ✅

### Здоровье (12 полей)
26. `health_notes` ✅
27. `device_type` ✅
28. `health_program` ✅
29. `health_plan_weeks` ✅
30. `current_running_level` ✅
31. `running_experience` ✅
32. `easy_pace_sec` ✅
33. `is_first_race_at_distance` ✅
34. `last_race_distance` ✅
35. `last_race_distance_km` ✅
36. `last_race_time` ✅
37. `last_race_date` ✅

### Прочее (3 поля)
38. `avatar_path` ✅
39. `privacy_level` ✅
40. `telegram_id` ✅

---

## Поля в getUserData() (загрузка из БД)

Всего: **44 поля** (включая служебные)

### Все поля из формы: ✅ 40 полей
Все поля из formData присутствуют в getUserData()

### Служебные поля (не в форме): ✅ 4 поля
1. `id` - ID пользователя
2. `role` - Роль пользователя
3. `created_at` - Дата создания
4. `updated_at` - Дата обновления

---

## Поля в обработке данных (loadProfile)

Все поля из formData обрабатываются в функции `loadProfile`:
- ✅ Все 40 полей преобразуются в `newFormData`
- ✅ Все поля устанавливаются в состояние через `setFormData`

---

## Поля в сохранении данных (handleSave)

Все поля из formData отправляются в API через `handleSave`:
- ✅ Все 40 полей включены в `dataToSend`
- ✅ Все поля нормализуются через `normalizeValue()`

---

## Поля в рендеринге формы (JSX)

Проверка использования полей в JSX:

### Вкладка "Профиль"
- ✅ `username` - input
- ✅ `email` - input
- ✅ `gender` - select
- ✅ `birth_year` - input
- ✅ `height_cm` - input
- ✅ `weight_kg` - input
- ✅ `timezone` - select
- ✅ `avatar_path` - отображение аватара

### Вкладка "Тренировки"
- ✅ `goal_type` - radio buttons
- ✅ `race_distance` - select
- ✅ `race_date` - input date
- ✅ `race_target_time` - input time
- ✅ `target_marathon_date` - input date
- ✅ `target_marathon_time` - input time
- ✅ `weight_goal_kg` - input
- ✅ `weight_goal_date` - input date
- ✅ `experience_level` - select
- ✅ `weekly_base_km` - input
- ✅ `sessions_per_week` - input
- ✅ `preferred_days` - checkboxes
- ✅ `preferred_ofp_days` - checkboxes
- ✅ `has_treadmill` - checkbox
- ✅ `training_time_pref` - select
- ✅ `ofp_preference` - select
- ✅ `training_mode` - select
- ✅ `training_start_date` - input date
- ✅ `health_notes` - textarea
- ✅ `device_type` - input
- ✅ `health_program` - select
- ✅ `health_plan_weeks` - input
- ✅ `current_running_level` - select
- ✅ `running_experience` - select
- ✅ `easy_pace_sec` - input
- ✅ `is_first_race_at_distance` - checkbox
- ✅ `last_race_distance` - select
- ✅ `last_race_distance_km` - input
- ✅ `last_race_time` - input time
- ✅ `last_race_date` - input date

### Вкладка "Социальное"
- ✅ `privacy_level` - radio buttons

### Вкладка "Интеграции"
- ✅ `telegram_id` - отображение статуса

---

## ИТОГОВАЯ СВОДКА

✅ **Все поля совпадают между:**
- formData state (40 полей)
- getUserData() (40 полей + 4 служебных)
- loadProfile обработка (40 полей)
- handleSave отправка (40 полей)
- JSX рендеринг (40 полей)

✅ **Все поля загружаются из БД**
✅ **Все поля обрабатываются правильно**
✅ **Все поля отображаются в форме**

---

## ВЫВОД

**Все поля синхронизированы и работают корректно!**

Проблема с отображением данных в select'ах связана не с отсутствием полей, а с тем, как React обновляет контролируемые компоненты после асинхронной загрузки данных.
