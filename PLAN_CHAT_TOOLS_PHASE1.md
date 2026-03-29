# План: Полная интеграция ИИ-чата с системой PlanRun — Фаза 1

**Дата:** 28 марта 2026
**Цель:** Добавить новые инструменты (tools) в ИИ-чат, чтобы тренер мог полноценно работать с данными пользователя.

---

## Обзор текущего состояния

### Существующие инструменты (10 шт.)
| # | Tool | Назначение |
|---|------|-----------|
| 1 | `get_date` | Парсит русские фразы дат → Y-m-d |
| 2 | `get_plan` | План на неделю/дату |
| 3 | `get_workouts` | История выполненных тренировок |
| 4 | `get_day_details` | Детали дня (план + факт) |
| 5 | `update_training_day` | Изменить тренировку |
| 6 | `swap_training_days` | Поменять местами |
| 7 | `delete_training_day` | Удалить тренировку |
| 8 | `move_training_day` | Перенести тренировку |
| 9 | `recalculate_plan` | Пересчитать план |
| 10 | `generate_next_plan` | Новый план |

### Новые инструменты (12 шт.)
| # | Tool | Приоритет | Назначение |
|---|------|-----------|-----------|
| 11 | `log_workout` | P0 | Залогировать результат тренировки |
| 12 | `get_stats` | P0 | Статистика и прогресс |
| 13 | `race_prediction` | P0 | Прогноз на забег (VDOT) |
| 14 | `get_profile` | P0 | Получить профиль пользователя |
| 15 | `update_profile` | P0 | Обновить цель/данные профиля |
| 16 | `get_training_load` | P1 | Нагрузка ACWR и восстановление |
| 17 | `add_training_day` | P1 | Добавить тренировку по дате |
| 18 | `copy_day` | P1 | Скопировать день |
| 19 | `add_exercise` | P2 | Добавить упражнение в день |
| 20 | `list_exercises` | P2 | Библиотека упражнений |
| 21 | `get_integration_status` | P2 | Статус подключений (Strava и т.д.) |
| 22 | `add_day_note` | P2 | Заметка к дню тренировки |

---

## Архитектура добавления нового инструмента

Каждый инструмент затрагивает 3 места в коде:

```
1. ChatService::getChatTools()          — определение tool (name, description, parameters)
2. ChatService::executeTool()           — диспетчер (switch/case → вызов execute*)
3. ChatService::execute{ToolName}()     — реализация (новый private метод)
```

Дополнительно (при необходимости):
```
4. ChatContextBuilder                   — новый метод для получения данных
5. Системный промпт (buildChatMessages) — описание нового инструмента для ИИ
6. Frontend (chatQuickReplies.js)       — новые быстрые кнопки (при необходимости)
```

**Паттерн каждого execute-метода:**
```php
private function executeToolName(array $args, ?int $userId): string {
    // 1. Проверка $userId
    if (!$userId) return json_encode(['error' => 'user_required']);

    // 2. Валидация параметров
    // 3. Вызов сервиса/репозитория
    // 4. Форматирование результата
    // 5. return json_encode([...]);
}
```

---

## Задача 1: `log_workout` — Залогировать тренировку

**Что делает:** Пользователь говорит "я пробежал 5 км за 28 минут" → ИИ записывает результат.

### 1.1 Определение tool в `getChatTools()`

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'log_workout',
        'description' => 'Записать результат тренировки пользователя. Используй когда пользователь сообщает о выполненной тренировке.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Дата тренировки в формате Y-m-d. Сначала вызови get_date если нужно.'
                ],
                'distance_km' => [
                    'type' => 'number',
                    'description' => 'Дистанция в километрах (например 5.2)'
                ],
                'duration_minutes' => [
                    'type' => 'number',
                    'description' => 'Продолжительность в минутах (например 28.5)'
                ],
                'avg_heart_rate' => [
                    'type' => 'integer',
                    'description' => 'Средний пульс (например 145). Необязательно.'
                ],
                'rating' => [
                    'type' => 'integer',
                    'description' => 'Ощущение от 1 до 5: 1=очень тяжело, 2=тяжело, 3=нормально, 4=хорошо, 5=отлично'
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Заметки к тренировке'
                ]
            ],
            'required' => ['date', 'distance_km']
        ]
    ]
]
```

### 1.2 Диспетчер в `executeTool()`

```php
case 'log_workout':
    return $this->executeLogWorkout($args, $userId);
```

### 1.3 Реализация `executeLogWorkout()`

**Файл:** `ChatService.php`

```php
private function executeLogWorkout(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    $date = $args['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
    }

    $distanceKm = (float) ($args['distance_km'] ?? 0);
    if ($distanceKm <= 0 || $distanceKm > 300) {
        return json_encode(['error' => 'invalid_distance', 'message' => 'Дистанция должна быть от 0.1 до 300 км']);
    }

    $durationMin = isset($args['duration_minutes']) ? (float) $args['duration_minutes'] : null;
    $avgHr = isset($args['avg_heart_rate']) ? (int) $args['avg_heart_rate'] : null;
    $rating = isset($args['rating']) ? (int) $args['rating'] : null;
    $notes = isset($args['notes']) ? trim($args['notes']) : null;

    // Валидация рейтинга
    if ($rating !== null && ($rating < 1 || $rating > 5)) {
        $rating = null;
    }

    try {
        require_once __DIR__ . '/WorkoutService.php';
        $workoutService = new WorkoutService($this->db);

        // Вычисляем время в формате H:i:s
        $resultTime = null;
        $pace = null;
        if ($durationMin) {
            $totalSec = (int) round($durationMin * 60);
            $h = intdiv($totalSec, 3600);
            $m = intdiv($totalSec % 3600, 60);
            $s = $totalSec % 60;
            $resultTime = sprintf('%d:%02d:%02d', $h, $m, $s);

            // Вычисляем темп мин/км
            if ($distanceKm > 0) {
                $paceSecPerKm = $totalSec / $distanceKm;
                $paceMin = intdiv((int) $paceSecPerKm, 60);
                $paceSec = ((int) $paceSecPerKm) % 60;
                $pace = sprintf('%d:%02d', $paceMin, $paceSec);
            }
        }

        // Находим тренировочный день
        $dayId = $this->findDayIdByDate($userId, $date);

        $data = [
            'user_id'        => $userId,
            'date'           => $date,
            'distance_km'    => $distanceKm,
            'result_time'    => $resultTime,
            'pace'           => $pace,
            'avg_heart_rate' => $avgHr,
            'rating'         => $rating,
            'notes'          => $notes,
            'source'         => 'chat',
        ];

        if ($dayId) {
            $data['training_day_id'] = $dayId;
        }

        $workoutService->saveResult($userId, $data);

        // Формируем ответ
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        $dateFormatted = $dt ? $dt->format('d.m.Y') : $date;

        $summary = "Тренировка на {$dateFormatted} записана: {$distanceKm} км";
        if ($resultTime) {
            $summary .= ", время {$resultTime}";
        }
        if ($pace) {
            $summary .= ", темп {$pace} мин/км";
        }

        return json_encode([
            'success' => true,
            'message' => $summary,
            'data'    => [
                'date'        => $date,
                'distance_km' => $distanceKm,
                'time'        => $resultTime,
                'pace'        => $pace,
                'avg_hr'      => $avgHr,
                'rating'      => $rating,
            ]
        ]);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'log_failed',
            'message' => 'Не удалось записать тренировку: ' . $e->getMessage()
        ]);
    }
}
```

### 1.4 Обновление системного промпта

Добавить в секцию ИНСТРУМЕНТЫ:
```
11. log_workout(date, distance_km, duration_minutes?, avg_heart_rate?, rating?, notes?) — записать результат тренировки. Когда пользователь говорит «я пробежал», «сегодня сделал 10 км», «тренировка — 5 км за 30 минут» — используй этот инструмент. Сначала вызови get_date если дата задана фразой. ОБЯЗАТЕЛЬНО подтверди данные перед записью: «Записываю: 5 км, 28 минут, темп 5:36. Верно?»
```

### 1.5 Обновление стратегии в промпте

Добавить:
```
- Пользователь сообщает о тренировке → Извлеки данные (дистанция, время, пульс, ощущения), подтверди → log_workout.
- Если данных мало (только "я пробежал") → уточни: «Сколько пробежал? За какое время?»
```

### 1.6 Quick replies (`chatQuickReplies.js`)

Добавить для состояния после `get_day_details` (если тренировка на сегодня):
```js
{ label: 'Записать результат', text: 'Хочу записать результат тренировки' }
```

---

## Задача 2: `get_stats` — Статистика и прогресс

**Что делает:** "Как у меня дела?", "Покажи статистику" → общая сводка с прогрессом.

### 2.1 Определение tool

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'get_stats',
        'description' => 'Получить статистику тренировок: объёмы, выполнение плана, динамику. Используй для ответа на вопросы о прогрессе и результатах.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'Период: "week" (текущая неделя), "month" (последний месяц), "plan" (весь план), "all" (вся история)',
                    'enum' => ['week', 'month', 'plan', 'all']
                ]
            ],
            'required' => []
        ]
    ]
]
```

### 2.2 Реализация `executeGetStats()`

```php
private function executeGetStats(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    $period = $args['period'] ?? 'plan';

    try {
        require_once __DIR__ . '/StatsService.php';
        $statsService = new StatsService($this->db);

        // Базовая статистика
        $stats = $statsService->getStats($userId);

        // Все тренировки за период (summary)
        $dateFrom = null;
        $dateTo = date('Y-m-d');

        switch ($period) {
            case 'week':
                $dateFrom = date('Y-m-d', strtotime('monday this week'));
                break;
            case 'month':
                $dateFrom = date('Y-m-d', strtotime('-30 days'));
                break;
            case 'plan':
                // Начало плана — из training_plan_weeks
                $dateFrom = null; // StatsService получит сам
                break;
            case 'all':
                $dateFrom = null;
                break;
        }

        $summary = $statsService->getAllWorkoutsSummary($userId, $dateFrom, $dateTo);

        // Дополним данные о тренировочной нагрузке
        $weeklyVolumes = [];
        if (!empty($summary)) {
            foreach ($summary as $s) {
                $weekNum = date('W', strtotime($s['date']));
                $weeklyVolumes[$weekNum] = ($weeklyVolumes[$weekNum] ?? 0) + (float)($s['distance_km'] ?? 0);
            }
        }

        $totalKm = array_sum(array_column($summary, 'distance_km'));
        $totalWorkouts = count($summary);
        $avgPace = null;

        // Средний темп из тренировок с дистанцией и временем
        $paceEntries = array_filter($summary, fn($s) => !empty($s['distance_km']) && !empty($s['duration_sec']) && $s['duration_sec'] > 0);
        if (!empty($paceEntries)) {
            $totalSec = array_sum(array_column($paceEntries, 'duration_sec'));
            $totalDist = array_sum(array_column($paceEntries, 'distance_km'));
            if ($totalDist > 0) {
                $avgPaceSec = $totalSec / $totalDist;
                $avgPace = sprintf('%d:%02d', intdiv((int)$avgPaceSec, 60), ((int)$avgPaceSec) % 60);
            }
        }

        $result = [
            'period' => $period,
            'plan_completion' => [
                'total_planned_days' => $stats['total_days'] ?? 0,
                'completed_days'     => $stats['completed_days'] ?? 0,
                'completion_percent' => $stats['completion_percent'] ?? 0,
            ],
            'volume' => [
                'total_km'      => round($totalKm, 1),
                'total_workouts' => $totalWorkouts,
                'avg_pace'       => $avgPace,
            ],
        ];

        // Недельная динамика (последние 4 недели)
        if (count($weeklyVolumes) > 1) {
            $lastWeeks = array_slice($weeklyVolumes, -4, null, true);
            $result['weekly_trend_km'] = array_map(fn($v) => round($v, 1), $lastWeeks);
        }

        return json_encode($result);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'stats_failed',
            'message' => 'Не удалось получить статистику: ' . $e->getMessage()
        ]);
    }
}
```

### 2.3 Обновление системного промпта

```
12. get_stats(period?) — статистика тренировок и прогресс. period: "week", "month", "plan", "all". Используй при вопросах «как у меня дела?», «мой прогресс», «статистика». Комментируй цифры как тренер: хвали прогресс, обращай внимание на тренд.
```

---

## Задача 3: `race_prediction` — Прогноз на забег

**Что делает:** "На сколько я готов на полумарафон?" → прогноз по VDOT.

### 3.1 Определение tool

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'race_prediction',
        'description' => 'Прогноз времени на забег по текущему уровню подготовки (VDOT). Показывает прогноз на разные дистанции.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'distance' => [
                    'type' => 'string',
                    'description' => 'Дистанция забега: "5k", "10k", "half", "marathon". Если не указана — покажет все.',
                    'enum' => ['5k', '10k', 'half', 'marathon']
                ]
            ],
            'required' => []
        ]
    ]
]
```

### 3.2 Реализация `executeRacePrediction()`

```php
private function executeRacePrediction(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    try {
        require_once __DIR__ . '/StatsService.php';
        $statsService = new StatsService($this->db);

        $predictions = $statsService->racePrediction($userId);

        if (empty($predictions) || empty($predictions['vdot'])) {
            return json_encode([
                'error'   => 'no_data',
                'message' => 'Недостаточно данных для прогноза. Нужна хотя бы одна тренировка или забег с дистанцией и временем.'
            ]);
        }

        $distance = $args['distance'] ?? null;

        $result = [
            'vdot'       => round($predictions['vdot'], 1),
            'based_on'   => $predictions['based_on'] ?? null,
        ];

        // Прогнозы по дистанциям
        $distMap = [
            '5k'       => '5 км',
            '10k'      => '10 км',
            'half'     => 'Полумарафон',
            'marathon' => 'Марафон',
        ];

        if ($distance && isset($predictions['predictions'][$distance])) {
            $result['prediction'] = [
                'distance' => $distMap[$distance] ?? $distance,
                'time'     => $predictions['predictions'][$distance],
            ];
        } else {
            $result['predictions'] = [];
            foreach ($predictions['predictions'] as $dist => $time) {
                $result['predictions'][] = [
                    'distance' => $distMap[$dist] ?? $dist,
                    'time'     => $time,
                ];
            }
        }

        // Тренировочные зоны темпа
        if (!empty($predictions['pace_zones'])) {
            $zoneNames = [
                'easy'      => 'Лёгкий',
                'tempo'     => 'Темповый',
                'threshold' => 'Пороговый',
                'interval'  => 'Интервальный (VO2max)',
                'repetition'=> 'Повторный',
            ];
            $result['pace_zones'] = [];
            foreach ($predictions['pace_zones'] as $zone => $pace) {
                $result['pace_zones'][] = [
                    'zone' => $zoneNames[$zone] ?? $zone,
                    'pace' => $pace,
                ];
            }
        }

        return json_encode($result);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'prediction_failed',
            'message' => 'Не удалось рассчитать прогноз: ' . $e->getMessage()
        ]);
    }
}
```

### 3.3 Обновление системного промпта

```
13. race_prediction(distance?) — прогноз времени на забег по текущей форме (VDOT). distance: "5k", "10k", "half", "marathon". Также показывает тренировочные зоны темпа. Комментируй результат: сравни с целью пользователя, скажи реалистична ли цель.
```

---

## Задача 4: `get_profile` — Получить профиль

**Что делает:** "Что ты обо мне знаешь?" → полная информация о пользователе.

### 4.1 Определение tool

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'get_profile',
        'description' => 'Получить профиль пользователя: цели, параметры, настройки тренировок, подключения.',
        'parameters' => [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ]
    ]
]
```

### 4.2 Реализация `executeGetProfile()`

```php
private function executeGetProfile(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    try {
        require_once __DIR__ . '/UserProfileService.php';
        $profileService = new UserProfileService($this->db);

        $profile = $profileService->getProfile($userId);

        if (!$profile) {
            return json_encode(['error' => 'not_found', 'message' => 'Профиль не найден']);
        }

        $genderRu = ['male' => 'мужской', 'female' => 'женский'];
        $levelRu  = [
            'beginner' => 'начинающий', 'novice' => 'продвинутый начинающий',
            'intermediate' => 'средний', 'advanced' => 'продвинутый', 'expert' => 'эксперт'
        ];
        $goalRu = [
            'race' => 'подготовка к забегу', 'time_improvement' => 'улучшение времени',
            'weight_loss' => 'похудение', 'health' => 'здоровье и форма',
            'distance' => 'увеличение дистанции'
        ];

        $result = [
            'name'             => $profile['name'] ?? null,
            'gender'           => $genderRu[$profile['gender'] ?? ''] ?? $profile['gender'],
            'age'              => $profile['age'] ?? null,
            'weight_kg'        => $profile['weight'] ?? null,
            'height_cm'        => $profile['height'] ?? null,
            'running_level'    => $levelRu[$profile['running_level'] ?? ''] ?? $profile['running_level'],
            'goal'             => $goalRu[$profile['goal_type'] ?? ''] ?? $profile['goal_type'],
            'running_days_per_week' => $profile['running_days'] ?? null,
            'preferred_time'   => $profile['preferred_time'] ?? null,
            'weekly_base_km'   => $profile['weekly_base_km'] ?? null,
            'easy_pace'        => $profile['easy_pace'] ?? null,
            'health_notes'     => $profile['health_notes'] ?? null,
            'timezone'         => $profile['timezone'] ?? null,
        ];

        // Данные о забеге (если цель = забег)
        if (in_array($profile['goal_type'] ?? '', ['race', 'time_improvement'])) {
            $result['race'] = [
                'distance'    => $profile['race_distance'] ?? null,
                'target_time' => $profile['race_target_time'] ?? null,
                'date'        => $profile['race_date'] ?? null,
            ];
        }

        // Подключения (Strava, Polar, etc.)
        $integrations = $this->contextBuilder->getIntegrationStatuses($userId);
        if (!empty($integrations)) {
            $result['integrations'] = $integrations;
        }

        return json_encode($result);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'profile_failed',
            'message' => 'Не удалось получить профиль: ' . $e->getMessage()
        ]);
    }
}
```

### 4.3 Новый метод в `ChatContextBuilder`

```php
public function getIntegrationStatuses(int $userId): array
{
    $stmt = $this->db->prepare(
        'SELECT provider, status, last_sync_at FROM integration_tokens WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'provider'  => $row['provider'],
            'connected' => ($row['status'] === 'active'),
            'last_sync' => $row['last_sync_at'] ?? null,
        ];
    }
    return $result;
}
```

### 4.4 Обновление системного промпта

```
14. get_profile() — получить полный профиль пользователя: цели, параметры, забег, подключения. Используй при «что ты обо мне знаешь?», «мои настройки», «какая у меня цель?».
```

---

## Задача 5: `update_profile` — Обновить профиль

**Что делает:** "Хочу пробежать марафон за 3:30", "Мой вес 75 кг" → обновляет профиль.

### 5.1 Определение tool

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'update_profile',
        'description' => 'Обновить данные профиля: цель, вес, темп, дни тренировок, данные забега и др.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'description' => 'Поле для обновления',
                    'enum' => [
                        'weight', 'height', 'goal_type', 'running_level',
                        'running_days', 'easy_pace', 'weekly_base_km',
                        'race_distance', 'race_target_time', 'race_date',
                        'health_notes', 'preferred_time'
                    ]
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'Новое значение поля'
                ]
            ],
            'required' => ['field', 'value']
        ]
    ]
]
```

### 5.2 Реализация `executeUpdateProfile()`

```php
private function executeUpdateProfile(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    $field = $args['field'] ?? '';
    $value = $args['value'] ?? '';

    // Белый список полей
    $allowedFields = [
        'weight', 'height', 'goal_type', 'running_level',
        'running_days', 'easy_pace', 'weekly_base_km',
        'race_distance', 'race_target_time', 'race_date',
        'health_notes', 'preferred_time'
    ];

    if (!in_array($field, $allowedFields, true)) {
        return json_encode(['error' => 'invalid_field', 'message' => 'Поле недопустимо: ' . $field]);
    }

    // Валидация по типу поля
    $fieldLabels = [
        'weight' => 'Вес', 'height' => 'Рост', 'goal_type' => 'Цель',
        'running_level' => 'Уровень', 'running_days' => 'Дней в неделю',
        'easy_pace' => 'Лёгкий темп', 'weekly_base_km' => 'Базовый недельный объём',
        'race_distance' => 'Дистанция забега', 'race_target_time' => 'Целевое время',
        'race_date' => 'Дата забега', 'health_notes' => 'Здоровье',
        'preferred_time' => 'Время для бега'
    ];

    try {
        require_once __DIR__ . '/UserProfileService.php';
        $profileService = new UserProfileService($this->db);

        $profileService->updateProfile($userId, [$field => $value]);

        $label = $fieldLabels[$field] ?? $field;

        return json_encode([
            'success' => true,
            'message' => "{$label} обновлено: {$value}"
        ]);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'update_failed',
            'message' => 'Не удалось обновить профиль: ' . $e->getMessage()
        ]);
    }
}
```

### 5.3 Обновление системного промпта

```
15. update_profile(field, value) — обновить данные профиля. Поля: weight, height, goal_type, running_level, running_days, easy_pace, weekly_base_km, race_distance, race_target_time, race_date, health_notes, preferred_time. ОБЯЗАТЕЛЬНО подтверди перед изменением: «Обновляю вес на 75 кг. Верно?». При смене цели или данных забега ПРЕДУПРЕДИ, что стоит пересчитать план.
```

---

## Задача 6: `get_training_load` — Нагрузка и восстановление

### 6.1 Определение tool

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'get_training_load',
        'description' => 'Анализ тренировочной нагрузки: ACWR (соотношение острой и хронической нагрузки), риск травмы, рекомендации.',
        'parameters' => [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ]
    ]
]
```

### 6.2 Реализация `executeGetTrainingLoad()`

```php
private function executeGetTrainingLoad(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    try {
        require_once __DIR__ . '/TrainingLoadService.php';
        $loadService = new TrainingLoadService($this->db);

        $load = $loadService->getTrainingLoad($userId);

        if (empty($load)) {
            return json_encode([
                'error'   => 'no_data',
                'message' => 'Недостаточно данных для анализа нагрузки. Нужно минимум 2 недели тренировок.'
            ]);
        }

        // Интерпретация ACWR
        $acwr = $load['acwr'] ?? null;
        $status = 'нет данных';
        if ($acwr !== null) {
            if ($acwr < 0.8)       $status = 'недогруз (риск детренированности)';
            elseif ($acwr <= 1.3)  $status = 'оптимальная зона';
            elseif ($acwr <= 1.5)  $status = 'повышенная (риск перетренировки)';
            else                   $status = 'опасная (высокий риск травмы)';
        }

        $result = [
            'acwr'            => $acwr ? round($acwr, 2) : null,
            'status'          => $status,
            'acute_load_km'   => round($load['acute_load'] ?? 0, 1),
            'chronic_load_km' => round($load['chronic_load'] ?? 0, 1),
            'week_volume_km'  => round($load['current_week_km'] ?? 0, 1),
            'prev_week_km'    => round($load['prev_week_km'] ?? 0, 1),
            'change_percent'  => $load['change_percent'] ?? null,
        ];

        return json_encode($result);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'load_failed',
            'message' => 'Не удалось получить данные нагрузки: ' . $e->getMessage()
        ]);
    }
}
```

### 6.3 Обновление системного промпта

```
16. get_training_load() — анализ тренировочной нагрузки (ACWR). Показывает соотношение острой/хронической нагрузки, статус и риски. Используй при «не перетренирован ли я?», «можно ли увеличить нагрузку?», «как мои объёмы?». Интерпретируй ACWR: <0.8 недогруз, 0.8-1.3 оптимально, 1.3-1.5 повышенная, >1.5 опасно.
```

---

## Задача 7: `add_training_day` — Добавить тренировку

### 7.1 Определение tool

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'add_training_day',
        'description' => 'Добавить новую тренировку на конкретную дату (когда на эту дату ничего не запланировано).',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Дата в формате Y-m-d'
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Тип тренировки',
                    'enum' => ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'rest', 'other', 'sbu', 'race', 'free']
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Описание тренировки (разминка, основная часть, заминка)'
                ]
            ],
            'required' => ['date', 'type']
        ]
    ]
]
```

### 7.2 Реализация `executeAddTrainingDay()`

```php
private function executeAddTrainingDay(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    $date = $args['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
    }

    $type = $args['type'] ?? '';
    $allowed = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'rest', 'other', 'sbu', 'race', 'free'];
    if (!in_array($type, $allowed, true)) {
        return json_encode(['error' => 'invalid_type', 'message' => 'Допустимые типы: ' . implode(', ', $allowed)]);
    }

    // Проверяем — может уже есть тренировка на этот день
    $existingDayId = $this->findDayIdByDate($userId, $date);
    if ($existingDayId) {
        return json_encode([
            'error'   => 'day_exists',
            'message' => "На {$date} уже есть тренировка. Используй update_training_day для изменения."
        ]);
    }

    try {
        require_once __DIR__ . '/WeekService.php';
        $weekService = new WeekService($this->db);

        $data = [
            'type'        => $type,
            'description' => $args['description'] ?? null,
        ];

        $weekService->addTrainingDayByDate($userId, $date, $data);

        $typeRu = [
            'easy' => 'Лёгкий бег', 'long' => 'Длительный', 'tempo' => 'Темповый',
            'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'control' => 'Контрольный',
            'rest' => 'Отдых', 'other' => 'ОФП', 'sbu' => 'СБУ', 'race' => 'Забег', 'free' => 'Свободная'
        ];
        $typeName = $typeRu[$type] ?? $type;

        $dt = DateTime::createFromFormat('Y-m-d', $date);
        $dateFormatted = $dt ? $dt->format('d.m.Y') : $date;

        return json_encode([
            'success' => true,
            'message' => "Тренировка «{$typeName}» добавлена на {$dateFormatted}"
        ]);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'add_failed',
            'message' => 'Не удалось добавить тренировку: ' . $e->getMessage()
        ]);
    }
}
```

### 7.3 Обновление системного промпта

```
17. add_training_day(date, type, description?) — добавить тренировку на дату, где ничего не запланировано. Если на дату уже есть тренировка — используй update_training_day. ОБЯЗАТЕЛЬНО подтверди перед добавлением.
```

---

## Задача 8: `copy_day` — Скопировать день

### 8.1 Определение tool

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'copy_day',
        'description' => 'Скопировать тренировку с одной даты на другую (дублирование).',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'source_date' => [
                    'type' => 'string',
                    'description' => 'Дата-источник в формате Y-m-d'
                ],
                'target_date' => [
                    'type' => 'string',
                    'description' => 'Целевая дата в формате Y-m-d'
                ]
            ],
            'required' => ['source_date', 'target_date']
        ]
    ]
]
```

### 8.2 Реализация `executeCopyDay()`

```php
private function executeCopyDay(array $args, ?int $userId): string
{
    if (!$userId) {
        return json_encode(['error' => 'user_required']);
    }

    $sourceDate = $args['source_date'] ?? '';
    $targetDate = $args['target_date'] ?? '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        return json_encode(['error' => 'invalid_dates', 'message' => 'Формат дат: Y-m-d']);
    }

    if ($sourceDate === $targetDate) {
        return json_encode(['error' => 'same_dates', 'message' => 'Даты должны отличаться']);
    }

    try {
        require_once __DIR__ . '/WeekService.php';
        $weekService = new WeekService($this->db);

        $weekService->copyDay($userId, $sourceDate, $targetDate);

        $srcDt = DateTime::createFromFormat('Y-m-d', $sourceDate);
        $tgtDt = DateTime::createFromFormat('Y-m-d', $targetDate);

        return json_encode([
            'success' => true,
            'message' => sprintf(
                'Тренировка скопирована с %s на %s',
                $srcDt ? $srcDt->format('d.m.Y') : $sourceDate,
                $tgtDt ? $tgtDt->format('d.m.Y') : $targetDate
            )
        ]);

    } catch (Exception $e) {
        return json_encode([
            'error'   => 'copy_failed',
            'message' => 'Не удалось скопировать: ' . $e->getMessage()
        ]);
    }
}
```

### 8.3 Обновление системного промпта

```
18. copy_day(source_date, target_date) — скопировать тренировку с одной даты на другую. Используй при «сделай как во вторник», «повтори тренировку». ОБЯЗАТЕЛЬНО подтверди.
```

---

## Обновление стриминга (события для фронта)

В `ChatService::streamResponse()` добавить `add_training_day` и `copy_day` к списку инструментов, триггерящих `plan_updated`:

```php
// Текущий код (примерно строка 204-217):
$planModifyingTools = [
    'update_training_day', 'swap_training_days', 'delete_training_day',
    'move_training_day', 'add_training_day', 'copy_day', 'log_workout'  // ← ДОБАВИТЬ
];

if (array_intersect($planModifyingTools, $toolsUsed)) {
    echo json_encode(['plan_updated' => true]) . "\n";
    ob_flush(); flush();
}
```

---

## Обновление `chatQuickReplies.js`

Добавить новые варианты быстрых ответов:

```js
// После проверки "что на сегодня" ответов, добавить:
{ label: 'Мой прогресс', text: 'Как у меня дела с тренировками?' },
{ label: 'Прогноз на забег', text: 'На сколько я готов на забег?' },
{ label: 'Записать тренировку', text: 'Хочу записать результат тренировки' },
{ label: 'Моя нагрузка', text: 'Не перетренирован ли я?' },
```

---

## Порядок выполнения

### Блок 1 (утро) — Ядро: ChatService.php
1. [ ] Добавить все 8 определений tools в `getChatTools()`
2. [ ] Добавить 8 case в `executeTool()` (диспетчер)
3. [ ] Реализовать `executeLogWorkout()`
4. [ ] Реализовать `executeGetStats()`
5. [ ] Реализовать `executeRacePrediction()`
6. [ ] Реализовать `executeGetProfile()`
7. [ ] Реализовать `executeUpdateProfile()`
8. [ ] Реализовать `executeGetTrainingLoad()`
9. [ ] Реализовать `executeAddTrainingDay()`
10. [ ] Реализовать `executeCopyDay()`

### Блок 2 (день) — Контекст и промпт
11. [ ] Добавить `getIntegrationStatuses()` в ChatContextBuilder
12. [ ] Обновить системный промпт: описания 8 новых инструментов
13. [ ] Обновить стратегию: новые сценарии поведения
14. [ ] Обновить список plan-modifying tools для стриминга

### Блок 3 (день) — Фронтенд
15. [ ] Обновить `chatQuickReplies.js` — новые быстрые кнопки
16. [ ] При необходимости — обработка новых streaming-событий

### Блок 4 (вечер) — Тестирование
17. [ ] Тест: «Я пробежал 5 км за 28 минут» → log_workout
18. [ ] Тест: «Как мой прогресс?» → get_stats
19. [ ] Тест: «На сколько я готов на полумарафон?» → race_prediction
20. [ ] Тест: «Что ты обо мне знаешь?» → get_profile
21. [ ] Тест: «Мой вес 75 кг» → update_profile
22. [ ] Тест: «Не перетренирован ли я?» → get_training_load
23. [ ] Тест: «Добавь лёгкий бег на субботу» → add_training_day
24. [ ] Тест: «Сделай как во вторник» → copy_day
25. [ ] Тест: цепочки tool calls (get_date → log_workout)
26. [ ] Тест: стриминг с новыми tools
27. [ ] Тест: ошибки и edge cases (нет данных, невалидные параметры)

---

## Метрики успеха

- ИИ-тренер может залогировать тренировку через чат
- ИИ-тренер отвечает на вопросы о прогрессе с реальными цифрами
- ИИ-тренер даёт прогноз на забег
- ИИ-тренер знает профиль и может его обновить
- ИИ-тренер анализирует нагрузку и предупреждает о рисках
- ИИ-тренер может добавлять и копировать тренировки
- Все операции работают в стриминге
- UI обновляется после модификаций плана

---

## Фаза 2 (следующий день)

После завершения Фазы 1:
- `add_exercise` / `list_exercises` — работа с упражнениями внутри дня
- `get_integration_status` — подробный статус интеграций с возможностью синхронизации
- `add_day_note` — заметки к дням (коуч-атлет коммуникация)
- `get_pace_zones` — детальные зоны темпа и пульса
