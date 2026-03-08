# Анализ: ключевые и контрольные тренировки в PlanRun

## 1. Определения

| Термин | Описание |
|--------|----------|
| **Ключевая тренировка** | Тренировка, дающая основной тренировочный стимул в неделе. Выделяется визуально в UI и учитывается в контексте для AI. |
| **Контрольная тренировка** | Тест-забег на дистанцию короче целевой для замера прогресса. Тип `control`. Всегда `is_key_workout: true`. При сохранении результата обновляет VDOT. |
| **Забег** | Соревнование. Тип `race`. Всегда `is_key_workout: true`. При сохранении результата обновляет VDOT. |

---

## 2. Типы, считающиеся ключевыми (бэкенд)

**Файл:** `planrun-backend/planrun_ai/plan_normalizer.php`

```php
const PLAN_KEY_WORKOUT_TYPES = ['interval', 'tempo', 'long', 'fartlek', 'race', 'control'];
```

Типы, которые по умолчанию считаются ключевыми, если LLM не указал `is_key_workout` явно.

**Разрешённые типы плана:**
```php
const PLAN_ALLOWED_TYPES = ['rest', 'tempo', 'interval', 'long', 'race', 'other', 'free', 'easy', 'sbu', 'fartlek', 'control'];
```

---

## 3. Определение `is_key_workout`

### 3.1 Нормализатор (AI → БД)

**Файл:** `planrun-backend/planrun_ai/plan_normalizer.php` → `resolveIsKeyWorkout()`

1. Если LLM явно указал `is_key_workout` (bool) — используется это значение.
2. Иначе — фолбэк: `in_array($type, PLAN_KEY_WORKOUT_TYPES)`.

```php
function resolveIsKeyWorkout(array $day, string $type): bool {
    if (isset($day['is_key_workout']) && is_bool($day['is_key_workout'])) {
        return $day['is_key_workout'];
    }
    if (isset($day['is_key_workout'])) {
        $val = $day['is_key_workout'];
        if ($val === 1 || $val === '1' || $val === 'true') return true;
        if ($val === 0 || $val === '0' || $val === 'false' || $val === null) return false;
    }
    return in_array($type, PLAN_KEY_WORKOUT_TYPES, true);
}
```

### 3.2 Промпт для LLM

**Файл:** `planrun-backend/planrun_ai/prompt_builder.php` → `buildKeyWorkoutsBlock()`

- Описывает, какие типы считаются ключевыми: tempo, interval, fartlek, long, race.
- Контрольная (`control`) явно указана в примерах как `is_key_workout: true`.
- Правила: 1–3 ключевых в неделю, не подряд, в разгрузку — 0–1.

**Примеры в промпте:**
- `control`: `is_key_workout: true`, `pace: null`
- `race`: `is_key_workout: true`, `pace: null`
- `long`, `tempo`, `interval`, `fartlek`: `is_key_workout: true`
- `easy`, `other`, `sbu`, `rest`, `free`: `is_key_workout: false`

### 3.3 Ручное добавление (AddTrainingModal)

**Файл:** `src/components/Calendar/AddTrainingModal.jsx`

- Чекбокс «Ключевая тренировка» — пользователь задаёт вручную.
- По умолчанию `isKeyWorkout = false`.
- При редактировании берётся из `initialData.is_key_workout`.
- При добавлении новой тренировки — не зависит от выбранного типа (нет автоустановки по типу).

**Типы в UI:** easy, tempo, long, interval, fartlek, control, race (для бега).

---

## 4. Контрольные забеги (control)

### 4.1 Расположение в плане

**Файл:** `planrun-backend/planrun_ai/prompt_builder.php`

- `control_weeks` — номера недель, в которые ставятся контрольные.
- Вычисляются: `controlW = raceWeek - 1`, при `controlW >= 3 && controlW <= trainWeeks - 1`.
- Дистанция: из `getDistanceSpec()` → `control_dist` (1–3 км, 3–5 км, 5–10 км, 10–15 км в зависимости от целевой дистанции).

### 4.2 Особенности

- `pace: null` — бег на результат, без целевого темпа.
- `is_key_workout: true` — всегда.

---

## 5. Обновление VDOT при control/race

**Файл:** `planrun-backend/controllers/WorkoutController.php` → `checkVdotUpdate()`

**Вызов:** после `saveResult()` (сохранение результата тренировки).

**Логика:**
1. Проверяет наличие `result_distance` и `result_time`.
2. По `week`, `day` из payload находит план-день в `training_plan_days`.
3. Берёт `type` из `training_plan_days`.
4. Если `type` не `control` и не `race` — выход.
5. Считает VDOT по формуле Riegel, обновляет `users.last_race_*`, отправляет сообщение в чат с новыми зонами и прогнозами.

**Важно:** VDOT обновляется только при сохранении результата для дня с типом `control` или `race` в плане.

---

## 6. Использование `key_workout_results` в AI

**Файл:** `planrun-backend/planrun_ai/plan_generator.php`

При генерации следующего плана в контекст попадают последние 6 ключевых тренировок:

```php
if (!empty($w['is_key_workout']) && $dist > 0) {
    $keyWorkoutResults[] = [
        'date' => $w['date'],
        'type' => $type,
        'distance_km' => $dist,
        'pace' => $w['pace'] ?? null,
        'rating' => $w['rating'] ?? null,
    ];
}
// ...
'key_workout_results' => array_slice($keyWorkoutResults, -6),
```

Источник: `ChatContextBuilder::getWorkoutsHistory()` — джойн с `training_plan_days`, откуда берётся `is_key_workout` и `type`.

---

## 7. Отображение на фронтенде

### 7.1 Календарь (WeekCalendar, Day)

**Файлы:** `WeekCalendar.jsx`, `Day.jsx`, `calendarHelpers.js`

- `normalizeDayActivities()`: `is_key_workout: !!(d.is_key_workout || d.key)` (API может отдавать `key: true`).
- `getTrainingClass(type, isKey)`: при `isKey` возвращает `key-session`.
- `marathon`, `race` в `calendarHelpers` всегда дают `key-session` даже без `isKey`.

### 7.2 WorkoutCard

- Бейдж «Ключевая» при `planDay.is_key_workout`.

### 7.3 MonthlyCalendar

- Точка `key-workout-dot` при `day.planDay.is_key_workout`.

### 7.4 Стили

- `calendar_v2.css`, `calendar.css`: `.key-session` — выделение ключевых тренировок.

---

## 8. Поток данных

```
AI (prompt_builder) → JSON с is_key_workout
       ↓
plan_normalizer.resolveIsKeyWorkout() → bool
       ↓
plan_saver → INSERT training_plan_days (is_key_workout)
       ↓
load_training_plan.php → key: true в weeks_data
       ↓
get_day / get_plan → planDays[].is_key_workout
       ↓
WeekCalendar, WorkoutCard, MonthlyCalendar → визуальное выделение
```

**Ручное добавление:**
```
AddTrainingModal (чекбокс) → is_key_workout в payload
       ↓
addTrainingDayByDate / updateTrainingDay → training_plan_days
```

**VDOT при сохранении результата:**
```
ResultModal → saveResult(week, day, result_distance, result_time)
       ↓
WorkoutController.checkVdotUpdate()
       ↓
SELECT type FROM training_plan_days WHERE week_number, day_of_week
       ↓
if type IN ('control','race') → estimateVDOT, UPDATE users, ChatService.addAIMessage
```

---

## 9. Несоответствия и замечания

1. **AddTrainingModal:** при выборе `control` или `race` чекбокс «Ключевая» не ставится автоматически — пользователь может снять его.
2. **calendarHelpers.getTrainingClass:** для `marathon` и `race` всегда возвращается `key-session`, даже если `isKey === false` — дублирование логики.
3. **PLAN_KEY_WORKOUT_TYPES** не включает `easy`, хотя в промпте длительная (long) явно ключевая; `easy` — нет, всё согласовано.
4. **Контрольная vs забег:** `control` — тест в рамках плана, `race` — целевой забег. Оба обновляют VDOT.
