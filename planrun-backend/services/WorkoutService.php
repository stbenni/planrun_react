<?php
/**
 * Сервис для работы с тренировками и результатами
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../workout_types.php';
require_once __DIR__ . '/../calendar_access.php';
require_once __DIR__ . '/../query_helpers.php';
require_once __DIR__ . '/../repositories/WorkoutRepository.php';
require_once __DIR__ . '/../validators/WorkoutValidator.php';

class WorkoutService extends BaseService {
    
    protected $repository;
    protected $validator;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new WorkoutRepository($db);
        $this->validator = new WorkoutValidator();
    }
    
    /**
     * Получить все результаты тренировок
     * 
     * @param int $userId ID пользователя
     * @return array Массив результатов
     * @throws Exception
     */
    public function getAllResults($userId) {
        try {
            // Используем репозиторий
            $results = $this->repository->getAllResults($userId);
            
            return ['results' => $results];
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки всех результатов: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить результат тренировки за конкретный день
     * 
     * @param string $date Дата тренировки (Y-m-d)
     * @param int $weekNumber Номер недели
     * @param string $dayName Название дня (mon, tue, etc.)
     * @param int $userId ID пользователя
     * @return array|null Результат тренировки или null
     * @throws Exception
     */
    public function getResult($date, $weekNumber, $dayName, $userId) {
        try {
            // Используем репозиторий
            return $this->repository->getResultByDate($userId, $date, $weekNumber, $dayName);
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки результата: ' . $e->getMessage(), 500, [
                'date' => $date,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить день тренировки со всеми данными
     * 
     * @param string $date Дата (Y-m-d)
     * @param int $userId ID пользователя
     * @return array Данные дня тренировки
     * @throws Exception
     */
    public function getDay($date, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateGetDay(['date' => $date])) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            require_once __DIR__ . '/../load_training_plan.php';
            require_once __DIR__ . '/../user_functions.php';
            
            // Получаем все записи плана на этот день (несколько тренировок в день разрешены)
            $plan = null;
            $planHtml = null;
            $planType = null;
            $planDayId = null;
            $planDays = [];
            $weekNumber = null;
            
            $dateObj = new DateTime($date);
            $dayOfWeek = (int)$dateObj->format('N');
            $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
            $dayName = $dayNames[$dayOfWeek];
            
            $planStmt = $this->db->prepare("SELECT id, type, description, date, is_key_workout FROM training_plan_days WHERE user_id = ? AND date = ? ORDER BY id");
            $planStmt->bind_param("is", $userId, $date);
            $planStmt->execute();
            $planResult = $planStmt->get_result();
            $planBlocks = [];
            while ($planRow = $planResult->fetch_assoc()) {
                $pid = (int)$planRow['id'];
                $planDays[] = [
                    'id' => $pid,
                    'type' => $planRow['type'],
                    'description' => $planRow['description'],
                    'is_key_workout' => (bool)($planRow['is_key_workout'] ?? 0),
                ];
                if ($planDayId === null) {
                    $planDayId = $pid;
                    $planType = $planRow['type'];
                    $plan = strip_tags(str_replace('<br>', "\n", $planRow['description'] ?? ''));
                }
                $desc = $planRow['description'] ?? '';
                $planBlocks[] = '<div class="plan-day-block" data-plan-day-id="' . (int)$pid . '">'
                    . '<div class="plan-day-text">' . $desc . '</div>'
                    . '<div class="plan-day-actions">'
                    . '<button type="button" class="btn-edit-plan-day" data-plan-day-id="' . (int)$pid . '" title="Редактировать тренировку">Изменить</button>'
                    . '<button type="button" class="btn-delete-plan-day" data-plan-day-id="' . (int)$pid . '" title="Удалить тренировку">Удалить</button>'
                    . '</div></div>';
                if ($weekNumber === null && $planRow['date'] && $planRow['type'] !== 'rest') {
                    $weekStmt = $this->db->prepare("
                        SELECT week_number FROM training_plan_weeks
                        WHERE user_id = ? AND start_date <= ? AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ?
                        ORDER BY start_date DESC LIMIT 1
                    ");
                    $weekStmt->bind_param("iss", $userId, $date, $date);
                    $weekStmt->execute();
                    $weekResultInner = $weekStmt->get_result();
                    if ($wr = $weekResultInner->fetch_assoc()) {
                        $weekNumber = (int)$wr['week_number'];
                    }
                    $weekStmt->close();
                }
            }
            $planStmt->close();
            if (count($planBlocks) > 0) {
                $planHtml = '<div class="plan-day-blocks">' . implode('', $planBlocks) . '</div>';
            }
            $firstPlan = $planDays[0] ?? null;
            
            // Если week_number не найден, пытаемся найти его по дате
            if (!$weekNumber) {
                $weekStmt = $this->db->prepare("
                    SELECT week_number 
                    FROM training_plan_weeks 
                    WHERE user_id = ? 
                      AND start_date <= ? 
                      AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ?
                    ORDER BY start_date DESC
                    LIMIT 1
                ");
                $weekStmt->bind_param("iss", $userId, $date, $date);
                $weekStmt->execute();
                $weekResult = $weekStmt->get_result();
                if ($weekRow = $weekResult->fetch_assoc()) {
                    $weekNumber = (int)$weekRow['week_number'];
                }
                $weekStmt->close();
            }
            
            // Получаем тренировки за этот день
            $dateStart = $date . ' 00:00:00';
            $dateEnd = $date . ' 23:59:59';
            $workoutsRaw = $this->repository->getWorkoutsByDate($userId, $dateStart, $dateEnd);
            
            // Формируем массив тренировок с URL
            $workouts = [];
            foreach ($workoutsRaw as $workout) {
                $workout['workout_url'] = getWorkoutDetailsUrl($workout['id'], $workout['user_id']);
                $workouts[] = $workout;
            }
            
            if (!is_array($workouts)) {
                $workouts = [];
            }
            
            // Получаем ручные тренировки из workout_log
            $logStmt = null;
            $logRow = null;
            
            if ($weekNumber) {
                $logStmt = $this->db->prepare("
                    SELECT wl.id, wl.distance_km, wl.result_time, wl.duration_minutes, wl.pace, 
                           wl.avg_heart_rate, wl.max_heart_rate, wl.elevation_gain, wl.notes,
                           at.name as activity_type_name
                    FROM workout_log wl
                    LEFT JOIN activity_types at ON wl.activity_type_id = at.id
                    WHERE wl.user_id = ? AND wl.training_date = ? AND wl.week_number = ? AND wl.day_name = ? 
                    AND wl.is_completed = 1
                    LIMIT 1
                ");
                $logStmt->bind_param("isis", $userId, $date, $weekNumber, $dayName);
            } else {
                $logStmt = $this->db->prepare("
                    SELECT wl.id, wl.distance_km, wl.result_time, wl.duration_minutes, wl.pace, 
                           wl.avg_heart_rate, wl.max_heart_rate, wl.elevation_gain, wl.notes,
                           at.name as activity_type_name
                    FROM workout_log wl
                    LEFT JOIN activity_types at ON wl.activity_type_id = at.id
                    WHERE wl.user_id = ? AND wl.training_date = ? AND wl.day_name = ? 
                    AND wl.is_completed = 1
                    LIMIT 1
                ");
                $logStmt->bind_param("iss", $userId, $date, $dayName);
            }
            
            $logStmt->execute();
            $logResult = $logStmt->get_result();
            $logRow = $logResult->fetch_assoc();
            $logStmt->close();
            
            if ($logRow) {
                // Проверяем, есть ли соответствующая автоматическая тренировка
                $hasAutomaticWorkout = false;
                if ($logRow['distance_km'] && $logRow['distance_km'] > 0) {
                    foreach ($workouts as $workout) {
                        $workoutDate = date('Y-m-d', strtotime($workout['start_time']));
                        if ($workoutDate === $date && 
                            abs($workout['distance_km'] - $logRow['distance_km']) < 0.1) {
                            $hasAutomaticWorkout = true;
                            break;
                        }
                    }
                }
                
                if (!$hasAutomaticWorkout) {
                    $manualTraining = [
                        'id' => (int)$logRow['id'],
                        'user_id' => $userId,
                        'activity_type' => $logRow['activity_type_name'] ?? 'running',
                        'start_time' => $date . ' 12:00:00',
                        'duration_minutes' => $logRow['duration_minutes'],
                        'result_time' => $logRow['result_time'],
                        'distance_km' => $logRow['distance_km'],
                        'avg_pace' => $logRow['pace'],
                        'avg_heart_rate' => $logRow['avg_heart_rate'],
                        'max_heart_rate' => $logRow['max_heart_rate'],
                        'elevation_gain' => $logRow['elevation_gain'],
                        'is_manual' => true,
                        'type' => $firstPlan['type'] ?? null,
                        'description' => $firstPlan['description'] ?? null,
                        'is_key_workout' => $firstPlan['is_key_workout'] ?? false,
                        'notes' => $logRow['notes']
                    ];
                    array_unshift($workouts, $manualTraining);
                }
            } else if ($firstPlan && ($firstPlan['type'] === 'other' || $firstPlan['type'] === 'free')) {
                $manualTraining = [
                    'id' => null,
                    'user_id' => $userId,
                    'activity_type' => 'other',
                    'start_time' => $date . ' 12:00:00',
                    'duration_minutes' => null,
                    'distance_km' => null,
                    'avg_pace' => null,
                    'avg_heart_rate' => null,
                    'max_heart_rate' => null,
                    'elevation_gain' => null,
                    'is_manual' => true,
                    'type' => $firstPlan['type'],
                    'description' => $firstPlan['description'],
                    'is_key_workout' => $firstPlan['is_key_workout']
                ];
                array_unshift($workouts, $manualTraining);
            }
            
            // Получаем структурированные упражнения дня — по всем плановым дням (новый формат)
            $dayExercises = [];
            $planDayIdsWithExercises = [];
            if (count($planDays) > 0) {
                try {
                    require_once __DIR__ . '/../repositories/ExerciseRepository.php';
                    $exerciseRepo = new ExerciseRepository($this->db);
                    foreach ($planDays as $pd) {
                        $pid = (int)$pd['id'];
                        $exercisesRaw = $exerciseRepo->getExercisesByDayId($pid, $userId);
                        foreach ($exercisesRaw as $exerciseRow) {
                            $planDayIdsWithExercises[$pid] = true;
                            $dayExercises[] = [
                                'id' => (int)$exerciseRow['id'],
                                'exercise_id' => $exerciseRow['exercise_id'] ? (int)$exerciseRow['exercise_id'] : null,
                                'plan_day_id' => $pid,
                                'category' => $exerciseRow['category'],
                                'name' => $exerciseRow['name'],
                                'sets' => $exerciseRow['sets'],
                                'reps' => $exerciseRow['reps'],
                                'distance_m' => $exerciseRow['distance_m'],
                                'duration_sec' => $exerciseRow['duration_sec'],
                                'weight_kg' => $exerciseRow['weight_kg'] ? (float)$exerciseRow['weight_kg'] : null,
                                'pace' => $exerciseRow['pace'],
                                'notes' => $exerciseRow['notes'],
                                'order_index' => (int)$exerciseRow['order_index']
                            ];
                        }
                    }
                    // Поддержка старого формата: плановые дни без записей в training_day_exercises
                    // ОФП/СБУ: парсим description построчно (формат «как в чате») — в попапе отдельные упражнения
                    $typeLabels = [
                        'easy' => 'Легкий бег', 'long' => 'Длительный бег', 'long-run' => 'Длительный бег',
                        'tempo' => 'Темповый бег', 'interval' => 'Интервалы', 'other' => 'ОФП',
                        'sbu' => 'СБУ', 'fartlek' => 'Фартлек', 'race' => 'Соревнование',
                        'rest' => 'День отдыха', 'free' => 'Пустой день'
                    ];
                    $typeCategory = ['easy' => 'run', 'long' => 'run', 'long-run' => 'run', 'tempo' => 'run', 'interval' => 'run', 'fartlek' => 'run', 'race' => 'run', 'other' => 'ofp', 'sbu' => 'sbu', 'rest' => 'run', 'free' => 'run'];
                    foreach ($planDays as $pd) {
                        $pid = (int)$pd['id'];
                        if (!empty($planDayIdsWithExercises[$pid])) {
                            continue;
                        }
                        $type = $pd['type'] ?? '';
                        $desc = strip_tags(str_replace('<br>', "\n", $pd['description'] ?? ''));
                        $category = $typeCategory[$type] ?? 'run';
                        if (($type === 'other' || $type === 'sbu') && $desc !== '') {
                            require_once __DIR__ . '/../planrun_ai/description_parser.php';
                            $parsed = parseOfpSbuDescription($desc, $type);
                            if (count($parsed) > 0) {
                                foreach ($parsed as $idx => $ex) {
                                    $notes = $ex['notes'];
                                    if ($ex['sets'] !== null || $ex['reps'] !== null || $ex['weight_kg'] !== null || $ex['duration_sec'] !== null || $ex['distance_m'] !== null) {
                                        $parts = [];
                                        if ($ex['sets'] !== null && $ex['reps'] !== null) {
                                            $parts[] = $ex['sets'] . '×' . $ex['reps'];
                                        }
                                        if ($ex['weight_kg'] !== null) {
                                            $parts[] = $ex['weight_kg'] . ' кг';
                                        }
                                        if ($ex['duration_sec'] !== null) {
                                            $parts[] = (int)($ex['duration_sec'] / 60) . ' мин';
                                        }
                                        if ($ex['distance_m'] !== null) {
                                            $parts[] = $ex['distance_m'] . ' м';
                                        }
                                        $notes = implode(', ', $parts) . ($notes ? ' — ' . $notes : '');
                                    }
                                    $dayExercises[] = [
                                        'id' => 0,
                                        'exercise_id' => null,
                                        'plan_day_id' => $pid,
                                        'category' => $category,
                                        'name' => $ex['name'],
                                        'sets' => $ex['sets'] !== null ? (string)$ex['sets'] : null,
                                        'reps' => $ex['reps'] !== null ? (string)$ex['reps'] : null,
                                        'distance_m' => $ex['distance_m'],
                                        'duration_sec' => $ex['duration_sec'],
                                        'weight_kg' => $ex['weight_kg'],
                                        'pace' => null,
                                        'notes' => $notes,
                                        'order_index' => $idx
                                    ];
                                }
                                continue;
                            }
                        }
                        $name = $typeLabels[$type] ?? $type ?: 'Тренировка';
                        $dayExercises[] = [
                            'id' => 0,
                            'exercise_id' => null,
                            'plan_day_id' => $pid,
                            'category' => $category,
                            'name' => $name,
                            'sets' => null,
                            'reps' => null,
                            'distance_m' => null,
                            'duration_sec' => null,
                            'weight_kg' => null,
                            'pace' => null,
                            'notes' => $desc !== '' ? $desc : null,
                            'order_index' => 0
                        ];
                    }
                } catch (Exception $e) {
                    require_once __DIR__ . '/../config/Logger.php';
                    Logger::error("Error loading day exercises", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            if ($weekNumber === null) {
                $weekNumber = 1;
            }

            return [
                'success' => true,
                'date' => $date,
                'week_number' => $weekNumber,
                'day_name' => $dayName,
                'plan' => $plan,
                'planHtml' => $planHtml,
                'planType' => $planType,
                'planDayId' => $planDayId,
                'planDays' => $planDays,
                'dayExercises' => $dayExercises,
                'workouts' => $workouts
            ];
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки дня: ' . $e->getMessage(), 500, [
                'date' => $date,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранить результат тренировки
     * 
     * @param array $data Данные результата
     * @param int $userId ID пользователя
     * @return array Результат сохранения
     * @throws Exception
     */
    public function saveResult($data, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateSaveResult($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            $date = $data['date'];
            $week = (int)$data['week'];
            $day = $data['day'];
            $activityTypeId = (int)$data['activity_type_id'];
            
            // Проверяем, что дата не в будущем
            $dateObj = new DateTime($date);
            $today = new DateTime();
            $today->setTime(0, 0, 0, 0);
            $dateObj->setTime(0, 0, 0, 0);
            
            if ($dateObj > $today) {
                $this->throwException('Нельзя отметить тренировку как выполненную для будущих дат', 400);
            }
            
            $isSuccessful = isset($data['is_successful']) ? ($data['is_successful'] === true ? 1 : ($data['is_successful'] === false ? 0 : null)) : null;
            $resultTime = isset($data['result_time']) && !empty($data['result_time']) ? $data['result_time'] : null;
            $distanceKm = isset($data['result_distance']) && $data['result_distance'] !== null ? (float)$data['result_distance'] : null;
            $resultPace = isset($data['result_pace']) && !empty($data['result_pace']) ? $data['result_pace'] : null;
            $durationMinutes = isset($data['duration_minutes']) && $data['duration_minutes'] !== null ? (int)$data['duration_minutes'] : null;
            $rating = isset($data['rating']) && $data['rating'] !== null ? (int)$data['rating'] : null;
            $notes = isset($data['notes']) && !empty($data['notes']) ? $data['notes'] : null;
            $avgHeartRate = isset($data['avg_heart_rate']) && $data['avg_heart_rate'] !== null ? (int)$data['avg_heart_rate'] : null;
            $maxHeartRate = isset($data['max_heart_rate']) && $data['max_heart_rate'] !== null ? (int)$data['max_heart_rate'] : null;
            $avgCadence = isset($data['avg_cadence']) && $data['avg_cadence'] !== null ? (int)$data['avg_cadence'] : null;
            $elevationGain = isset($data['elevation_gain']) && $data['elevation_gain'] !== null ? (int)$data['elevation_gain'] : null;
            $calories = isset($data['calories']) && $data['calories'] !== null ? (int)$data['calories'] : null;
            
            // Рассчитываем темп, если не передан
            if (!$resultPace && $distanceKm && $distanceKm > 0 && $resultTime) {
                $timeParts = explode(':', $resultTime);
                $totalMinutes = 0;
                if (count($timeParts) === 2) {
                    $totalMinutes = (int)$timeParts[0] + ((int)$timeParts[1] / 60);
                } elseif (count($timeParts) === 3) {
                    $totalMinutes = ((int)$timeParts[0] * 60) + (int)$timeParts[1] + ((int)$timeParts[2] / 60);
                }
                
                if ($totalMinutes > 0) {
                    $paceMinutesPerKm = $totalMinutes / $distanceKm;
                    $paceMinutes = floor($paceMinutesPerKm);
                    $paceSeconds = round(($paceMinutesPerKm - $paceMinutes) * 60);
                    
                    if ($paceSeconds >= 60) {
                        $paceSeconds = $paceSeconds - 60;
                        $paceMinutes = $paceMinutes + 1;
                    }
                    
                    $resultPace = sprintf("%d:%02d", $paceMinutes, $paceSeconds);
                }
            }
            
            // Рассчитываем duration_minutes, если не передан
            if (!$durationMinutes && $resultTime) {
                $timeParts = explode(':', $resultTime);
                if (count($timeParts) === 2) {
                    $durationMinutes = (int)$timeParts[0] + round((int)$timeParts[1] / 60);
                } elseif (count($timeParts) === 3) {
                    $durationMinutes = ((int)$timeParts[0] * 60) + (int)$timeParts[1] + round((int)$timeParts[2] / 60);
                }
            }
            
            // Проверяем существование записи
            $checkStmt = $this->db->prepare("SELECT id FROM workout_log WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ? LIMIT 1");
            $checkStmt->bind_param("isis", $userId, $date, $week, $day);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $existing = $result->fetch_assoc();
            $checkStmt->close();
            
            if ($existing) {
                // Обновляем существующую запись
                $updateStmt = $this->db->prepare("UPDATE workout_log SET activity_type_id = ?, is_successful = ?, result_time = ?, distance_km = ?, pace = ?, duration_minutes = ?, rating = ?, notes = ?, avg_heart_rate = ?, max_heart_rate = ?, avg_cadence = ?, elevation_gain = ?, calories = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->bind_param("iisdssiisiiiii", $activityTypeId, $isSuccessful, $resultTime, $distanceKm, $resultPace, $durationMinutes, $rating, $notes, $avgHeartRate, $maxHeartRate, $avgCadence, $elevationGain, $calories, $existing['id']);
                $updateStmt->execute();
                if ($updateStmt->error) {
                    $updateStmt->close();
                    $this->throwException('Ошибка БД: ' . $updateStmt->error, 500);
                }
                $updateStmt->close();
            } else {
                // Создаем новую запись
                $insertStmt = $this->db->prepare("INSERT INTO workout_log (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, result_time, distance_km, pace, duration_minutes, rating, notes, avg_heart_rate, max_heart_rate, avg_cadence, elevation_gain, calories) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->bind_param("isisisdssiisiiiii", $userId, $date, $week, $day, $activityTypeId, $isSuccessful, $resultTime, $distanceKm, $resultPace, $durationMinutes, $rating, $notes, $avgHeartRate, $maxHeartRate, $avgCadence, $elevationGain, $calories);
                $insertStmt->execute();
                if ($insertStmt->error) {
                    $insertStmt->close();
                    $this->throwException('Ошибка БД: ' . $insertStmt->error, 500);
                }
                $insertStmt->close();
            }
            
            // Инвалидируем кеш
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            require_once __DIR__ . '/../config/Logger.php';
            Logger::debug("Training plan cache invalidated after saving result", ['user_id' => $userId]);
            
            return ['success' => true];
        } catch (Exception $e) {
            $this->throwException('Ошибка сохранения результата: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранить прогресс тренировки (старая функция save)
     * 
     * @param array $data Данные прогресса
     * @param int $userId ID пользователя
     * @return array Результат сохранения
     * @throws Exception
     */
    public function saveProgress($data, $userId) {
        try {
            $date = $data['date'];
            $week = (int)$data['week'];
            $day = $data['day'];
            $completed = $data['completed'] ? 1 : 0;
            $completedAt = $completed ? date('Y-m-d H:i:s') : null;
            $resultTime = isset($data['result_time']) ? $data['result_time'] : null;
            $resultDistance = isset($data['result_distance']) ? (float)$data['result_distance'] : null;
            $resultPace = isset($data['result_pace']) ? $data['result_pace'] : null;
            $notes = isset($data['notes']) ? $data['notes'] : null;
            
            // Проверяем существование записи
            $checkStmt = $this->db->prepare("SELECT id FROM training_progress WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?");
            $checkStmt->bind_param("isis", $userId, $date, $week, $day);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $checkStmt->close();
            
            if ($result && $result->num_rows > 0) {
                // Обновляем существующую запись
                if ($completedAt) {
                    $updateStmt = $this->db->prepare("UPDATE training_progress SET completed = ?, completed_at = ?, result_time = ?, result_distance = ?, result_pace = ?, notes = ? WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?");
                    $updateStmt->bind_param("issdssisiss", $completed, $completedAt, $resultTime, $resultDistance, $resultPace, $notes, $userId, $date, $week, $day);
                } else {
                    $updateStmt = $this->db->prepare("UPDATE training_progress SET completed = ?, completed_at = NULL, result_time = ?, result_distance = ?, result_pace = ?, notes = ? WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?");
                    $updateStmt->bind_param("isdssissis", $completed, $resultTime, $resultDistance, $resultPace, $notes, $userId, $date, $week, $day);
                }
                $updateStmt->execute();
                if ($updateStmt->error) {
                    $updateStmt->close();
                    $this->throwException('Ошибка БД: ' . $updateStmt->error, 500);
                }
                $updateStmt->close();
            } else {
                // Создаем новую запись
                if ($completedAt) {
                    $insertStmt = $this->db->prepare("INSERT INTO training_progress (user_id, training_date, week_number, day_name, completed, completed_at, result_time, result_distance, result_pace, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("isisisdss", $userId, $date, $week, $day, $completed, $completedAt, $resultTime, $resultDistance, $resultPace, $notes);
                } else {
                    $insertStmt = $this->db->prepare("INSERT INTO training_progress (user_id, training_date, week_number, day_name, completed, result_time, result_distance, result_pace, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("isisdsdss", $userId, $date, $week, $day, $completed, $resultTime, $resultDistance, $resultPace, $notes);
                }
                $insertStmt->execute();
                if ($insertStmt->error) {
                    $insertStmt->close();
                    $this->throwException('Ошибка БД: ' . $insertStmt->error, 500);
                }
                $insertStmt->close();
            }
            
            // Инвалидируем кеш
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            require_once __DIR__ . '/../config/Logger.php';
            Logger::debug("Training plan cache invalidated after saving progress", ['user_id' => $userId]);
            
            return ['success' => true];
        } catch (Exception $e) {
            $this->throwException('Ошибка сохранения прогресса: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сбросить прогресс
     * 
     * @param int $userId ID пользователя
     * @return array Результат сброса
     * @throws Exception
     */
    public function resetProgress($userId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM workout_log WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            if ($this->db->error) {
                $stmt->close();
                $this->throwException('Ошибка БД: ' . $this->db->error, 500);
            }
            $stmt->close();
            
            // Инвалидируем кеш
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            require_once __DIR__ . '/../config/Logger.php';
            Logger::debug("Training plan cache invalidated after reset", ['user_id' => $userId]);
            
            return ['success' => true];
        } catch (Exception $e) {
            $this->throwException('Ошибка сброса прогресса: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Удалить тренировку
     * 
     * @param int $workoutId ID тренировки
     * @param bool $isManual Ручная тренировка или автоматическая
     * @param int $userId ID пользователя
     * @return array Результат удаления
     * @throws Exception
     */
    public function deleteWorkout($workoutId, $isManual, $userId) {
        try {
            if (!$workoutId || $workoutId <= 0) {
                $this->throwException('Не указан ID тренировки', 400);
            }
            
            if ($isManual) {
                // Удаление вручную добавленной тренировки из workout_log
                $checkStmt = $this->db->prepare("SELECT id FROM workout_log WHERE id = ? AND user_id = ?");
                $checkStmt->bind_param("ii", $workoutId, $userId);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $checkStmt->close();
                
                if ($checkResult->num_rows === 0) {
                    $this->throwException('Тренировка не найдена или нет доступа', 404);
                }
                
                $deleteStmt = $this->db->prepare("DELETE FROM workout_log WHERE id = ? AND user_id = ?");
                $deleteStmt->bind_param("ii", $workoutId, $userId);
                $deleteStmt->execute();
                $deleteStmt->close();
                
                return ['success' => true, 'message' => 'Запись о тренировке удалена'];
            } else {
                // Удаление автоматически загруженной тренировки из workouts
                $checkStmt = $this->db->prepare("SELECT id FROM workouts WHERE id = ? AND user_id = ?");
                $checkStmt->bind_param("ii", $workoutId, $userId);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult->num_rows === 0) {
                    $checkStmt->close();
                    $this->throwException('Тренировка не найдена или нет доступа', 404);
                }
                $checkStmt->close();
                
                // Начинаем транзакцию
                $this->db->begin_transaction();
                
                try {
                    // Удаляем все trackpoints
                    $deleteTimelineStmt = $this->db->prepare("DELETE FROM workout_timeline WHERE workout_id = ?");
                    if ($deleteTimelineStmt) {
                        $deleteTimelineStmt->bind_param("i", $workoutId);
                        $deleteTimelineStmt->execute();
                        $deleteTimelineStmt->close();
                    }
                    
                    // Удаляем основную запись
                    $deleteWorkoutStmt = $this->db->prepare("DELETE FROM workouts WHERE id = ? AND user_id = ?");
                    $deleteWorkoutStmt->bind_param("ii", $workoutId, $userId);
                    $deleteWorkoutStmt->execute();
                    $deleteWorkoutStmt->close();
                    
                    $this->db->commit();
                    
                    return ['success' => true, 'message' => 'Тренировка и все связанные данные удалены'];
                } catch (Exception $e) {
                    $this->db->rollback();
                    throw $e;
                }
            }
        } catch (Exception $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error("Error deleting workout", [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);
            $this->throwException('Ошибка удаления тренировки: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить timeline данные тренировки (пульс, темп по времени)
     * 
     * @param int $workoutId ID тренировки
     * @param int $userId ID пользователя
     * @return array Массив точек timeline
     * @throws Exception
     */
    public function getWorkoutTimeline($workoutId, $userId) {
        try {
            if (!$workoutId || $workoutId <= 0) {
                $this->throwException('Не указан ID тренировки', 400);
            }
            
            // Проверяем, что тренировка принадлежит пользователю
            $checkStmt = $this->db->prepare("SELECT id FROM workouts WHERE id = ? AND user_id = ? LIMIT 1");
            $checkStmt->bind_param("ii", $workoutId, $userId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $workout = $result->fetch_assoc();
            $checkStmt->close();
            
            if (!$workout) {
                $this->throwException('Тренировка не найдена или нет доступа', 404);
            }
            
            // Получаем timeline данные
            $timelineStmt = $this->db->prepare("
                SELECT timestamp, heart_rate, pace, cadence, altitude, distance 
                FROM workout_timeline 
                WHERE workout_id = ? 
                ORDER BY timestamp ASC
            ");
            $timelineStmt->bind_param("i", $workoutId);
            $timelineStmt->execute();
            $timelineResult = $timelineStmt->get_result();
            
            $timeline = [];
            while ($row = $timelineResult->fetch_assoc()) {
                $timeline[] = [
                    'timestamp' => $row['timestamp'],
                    'heart_rate' => $row['heart_rate'] !== null ? (int)$row['heart_rate'] : null,
                    'pace' => $row['pace'],
                    'cadence' => $row['cadence'] !== null ? (int)$row['cadence'] : null,
                    'altitude' => $row['altitude'] !== null ? (float)$row['altitude'] : null,
                    'distance' => $row['distance'] !== null ? (float)$row['distance'] : null
                ];
            }
            $timelineStmt->close();
            
            return $timeline;
        } catch (Exception $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error("Error loading workout timeline", [
                'workout_id' => $workoutId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            $this->throwException('Ошибка загрузки timeline: ' . $e->getMessage(), 500, [
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
