# Анализ: форма → БД → генерация плана

Проверка каждого поля и варианта формы: сохраняется ли в БД, читается ли в plan_generator, попадает ли в промпт.

---

## Условные обозначения

- **Форма** — поле/вариант в RegisterScreen.jsx (formData или опции селектов).
- **register_api** — полная регистрация (INSERT в users).
- **complete_specialization** — специализация (UPDATE users).
- **plan_generator SELECT** — читается ли поле в plan_generator.php из users.
- **prompt_builder** — используется ли в buildTrainingPlanPrompt().

---

## Поля формы и варианты

### Шаг 0: Режим

| Поле | Варианты формы | register_api | complete_specialization | plan_generator SELECT | prompt_builder |
|------|----------------|--------------|--------------------------|------------------------|----------------|
| training_mode | ai, self, both, coach (disabled) | ✅ сохраняется | ✅ сохраняется | ❌ не SELECT | ❌ не нужен для плана (генерация только при ai/both) |

---

### Шаг 1: Аккаунт (не для плана)

| Поле | Форма | В генерации не участвует |
|------|-------|---------------------------|
| username, password, email | текст | Сохраняются в users, в план не передаются |

---

### Шаг 2: Цель

| Поле | Варианты формы | register_api | complete_specialization | plan_generator SELECT | prompt_builder |
|------|----------------|--------------|--------------------------|------------------------|----------------|
| goal_type | health, race, weight_loss, time_improvement | ✅ | ✅ | ✅ | ✅ (ветки по goalType) |
| race_distance | 5k, 10k, half, marathon | ✅ | ✅ | ✅ | ✅ (race + time_improvement), маппинг 5k/10k/half/marathon |
| race_date | YYYY-MM-DD | ✅ | ✅ | ✅ | ✅ |
| race_target_time | текст/число | ✅ | ✅ | ✅ | ✅ |
| target_marathon_date | YYYY-MM-DD | ✅ | ✅ | ✅ | ✅ (time_improvement, расчёт недель) |
| target_marathon_time | текст | ✅ | ✅ | ✅ | ✅ (time_improvement) |
| weight_goal_kg | число | ✅ | ✅ | ✅ | ✅ |
| weight_goal_date | YYYY-MM-DD | ✅ | ✅ | ✅ | ✅ + getSuggestedPlanWeeks |
| health_program | start_running, couch_to_5k, regular_running, custom | ✅ | ✅ | ✅ | ✅ (health), маппинг + кол-во недель |
| health_plan_weeks | 4, 8, 12, 16 (select) + произвольное | ✅ | ✅ | ✅ | ✅ (health custom) + getSuggestedPlanWeeks |
| training_start_date | YYYY-MM-DD | ✅ | ✅ | ✅ | ✅ + расчёт недель |

---

### Шаг 3: Профиль

| Поле | Варианты формы | register_api | complete_specialization | plan_generator SELECT | prompt_builder |
|------|----------------|--------------|--------------------------|------------------------|----------------|
| gender | male, female | ✅ | ✅ | ✅ | ✅ |
| birth_year | число | ✅ | ✅ | ✅ | ✅ (возраст) |
| height_cm | число | ✅ | ✅ | ✅ | ✅ |
| weight_kg | число | ✅ | ✅ | ✅ | ✅ |
| experience_level | novice, beginner, intermediate, advanced, expert | ✅ | ✅ | ✅ | ✅, маппинг всех 5 |
| weekly_base_km | число | ✅ | ✅ | ✅ | ✅ |
| sessions_per_week | число (или из preferred_days) | ✅ | ✅ | ✅ | ✅ |
| preferred_days | массив: mon,tue,wed,thu,fri,sat,sun | ✅ (JSON) | ✅ (JSON) | ✅ декодируется | ✅ (список дней + правила) |
| will_do_ofp | yes, no | только на фронте | только на фронте | — | не в БД, влияет на показ preferred_ofp_days |
| preferred_ofp_days | массив: mon..sun | ✅ (JSON) | ✅ (JSON) | ✅ декодируется | ✅ |
| ofp_preference | gym, home, both, group_classes, online | ✅ | ✅ | ✅ | ✅, маппинг всех 5 |
| training_time_pref | morning, day, evening | ✅ | ✅ | ✅ | ✅, маппинг |
| has_treadmill | bool | ✅ (1/0) | ✅ (1/0) | ✅ | ✅ |
| health_notes | текст | ✅ | ✅ | ✅ | ✅ |
| device_type | текст (Garmin, Polar...) | ✅ но в submitData **device_type: undefined** | ✅ (если есть в formData) | ❌ не в SELECT | ❌ не в промпте |

---

### Расширенный профиль (race / time_improvement)

| Поле | Варианты формы | register_api | complete_specialization | plan_generator SELECT | prompt_builder |
|------|----------------|--------------|--------------------------|------------------------|----------------|
| easy_pace_min | MM:SS (фронт) | — | — | — | — |
| easy_pace_sec | секунды на км | ✅ | ✅ | ✅ | ✅ (комфортный темп) |
| is_first_race_at_distance | bool | ✅ (1/0) | ✅ (1/0) | ✅ | ✅ (race) |
| last_race_distance | 5k, 10k, half, marathon, other | ✅ | ✅ | ✅ | ✅ (race, time_improvement) |
| last_race_distance_km | число (при other) | ✅ | ✅ | ✅ | ✅ при last_race_distance=other |
| last_race_time | текст | ✅ | ✅ | ✅ | ✅ |
| last_race_date | YYYY-MM-DD или YYYY-MM | ✅ | ✅ | ✅ | ✅ |
| running_experience | less_3m, 3_6m, 6_12m, 1_2y, more_2y | ✅ | ✅ | ✅ | ✅ (race, time_improvement), маппинг |
| current_running_level | zero, basic, comfortable | ✅ (health) | ✅ | ✅ | ✅ (health), маппинг |

---

## Найденные разрывы (исправлены)

1. **device_type** — исправлено:
   - В formData добавлено начальное значение `device_type: ''`.
   - Убрана перезапись `device_type: undefined` в submitData при полной регистрации (теперь уходит значение из formData).
   - В plan_generator добавлен `device_type` в SELECT.
   - В prompt_builder добавлена строка «Устройство/платформа: …» при непустом значении.

2. **health_plan_weeks: варианты 4, 8, 12, 16** — разрыва нет; бэк и промпт принимают любое число.

---

## Итог

- Все поля цели и профиля сохраняются в register_api и complete_specialization, читаются в plan_generator и используются в prompt_builder.
- Варианты селектов совпадают с допустимыми значениями на бэке и маппингами в промпте.
- Цепочка форма → БД → генерация плана замкнута по всем полям формы.
