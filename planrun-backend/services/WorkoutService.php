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
require_once __DIR__ . '/WorkoutShareCardCacheService.php';

class WorkoutService extends BaseService {
    
    protected $repository;
    protected $validator;
    private $tableExistsCache = [];
    private $shareCardCacheService = null;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new WorkoutRepository($db);
        $this->validator = new WorkoutValidator();
    }

    private function workoutShareCardCache(): WorkoutShareCardCacheService {
        if (!$this->shareCardCacheService) {
            $this->shareCardCacheService = new WorkoutShareCardCacheService($this->db);
        }
        return $this->shareCardCacheService;
    }

    private function queueWorkoutShareCards(int $userId, int $workoutId, string $workoutKind): int {
        if ($userId <= 0 || $workoutId <= 0) {
            return 0;
        }

        try {
            $cache = $this->workoutShareCardCache();
            if (!$cache->isInfrastructureAvailable()) {
                return 0;
            }

            $jobs = $cache->refreshQueuedCardsForWorkout($userId, $workoutId, $workoutKind);
            return count($jobs);
        } catch (Throwable $e) {
            $this->logInfo('Workout share card queue skipped', [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'workout_kind' => $workoutKind,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function deleteWorkoutShareCards(int $userId, int $workoutId, string $workoutKind): void {
        if ($userId <= 0 || $workoutId <= 0) {
            return;
        }

        try {
            $cache = $this->workoutShareCardCache();
            if (!$cache->isInfrastructureAvailable()) {
                return;
            }

            $cache->deleteWorkoutAssets($userId, $workoutId, $workoutKind);
        } catch (Throwable $e) {
            $this->logInfo('Workout share card cleanup skipped', [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'workout_kind' => $workoutKind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function launchWorkoutShareWorkerAsync(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $scriptPath = dirname(__DIR__) . '/scripts/workout_share_worker.php';
        if (!is_file($scriptPath)) {
            return;
        }

        $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' --drain > /dev/null 2>&1 &';

        try {
            if (function_exists('popen') && function_exists('pclose')) {
                $process = @popen($command, 'r');
                if (is_resource($process)) {
                    @pclose($process);
                    return;
                }
            }

            if (function_exists('exec')) {
                @exec($command);
            }
        } catch (Throwable $e) {
            $this->logInfo('Workout share worker launch skipped', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
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
            
            $planStmt = $this->db->prepare("SELECT id, type, description, date, is_key_workout, target_hr_min, target_hr_max FROM training_plan_days WHERE user_id = ? AND date = ? ORDER BY id");
            $planStmt->bind_param("is", $userId, $date);
            $planStmt->execute();
            $planResult = $planStmt->get_result();
            $planBlocks = [];
            while ($planRow = $planResult->fetch_assoc()) {
                $pid = (int)$planRow['id'];
                $dayEntry = [
                    'id' => $pid,
                    'type' => $planRow['type'],
                    'description' => $planRow['description'],
                    'is_key_workout' => (bool)($planRow['is_key_workout'] ?? 0),
                ];
                if (!empty($planRow['target_hr_min']) && !empty($planRow['target_hr_max'])) {
                    $dayEntry['target_hr_min'] = (int) $planRow['target_hr_min'];
                    $dayEntry['target_hr_max'] = (int) $planRow['target_hr_max'];
                }
                $planDays[] = $dayEntry;
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
            $workoutLogId = 0;
            $shareQueueJobs = 0;
            
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
                $workoutLogId = (int) $existing['id'];
            } else {
                // Создаем новую запись
                $insertStmt = $this->db->prepare("INSERT INTO workout_log (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, result_time, distance_km, pace, duration_minutes, rating, notes, avg_heart_rate, max_heart_rate, avg_cadence, elevation_gain, calories) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->bind_param("isisisdssiisiiiii", $userId, $date, $week, $day, $activityTypeId, $isSuccessful, $resultTime, $distanceKm, $resultPace, $durationMinutes, $rating, $notes, $avgHeartRate, $maxHeartRate, $avgCadence, $elevationGain, $calories);
                $insertStmt->execute();
                if ($insertStmt->error) {
                    $insertStmt->close();
                    $this->throwException('Ошибка БД: ' . $insertStmt->error, 500);
                }
                $workoutLogId = (int) $this->db->insert_id;
                $insertStmt->close();
            }
            
            // Инвалидируем кеш
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            require_once __DIR__ . '/../config/Logger.php';
            Logger::debug("Training plan cache invalidated after saving result", ['user_id' => $userId]);

            $shareQueueJobs += $this->queueWorkoutShareCards((int) $userId, $workoutLogId, WorkoutShareCardCacheService::KIND_MANUAL);
            if ($shareQueueJobs > 0) {
                $this->launchWorkoutShareWorkerAsync();
            }
            
            return ['success' => true, 'workout_log_id' => $workoutLogId];
        } catch (Exception $e) {
            $this->throwException('Ошибка сохранения результата: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Импорт тренировок из внешних источников (Huawei, Garmin, Strava, GPX и др.)
     * Дедупликация:
     * 1) По (user_id, external_id, source) — обновление при повторной синхронизации того же источника
     * 2) По (user_id, start_time, source) — для GPX без external_id
     * 3) Кросс-источник: (user_id, start_time ±2 мин) — если тренировка уже есть из другого источника, пропускаем
     *
     * @param int $userId ID пользователя
     * @param array $workouts Массив тренировок в нормализованном формате
     * @param string $source Источник: 'huawei', 'garmin', 'strava', 'polar', 'coros', 'gpx'
     * @return array ['imported' => int, 'skipped' => int]
     */
    public function importWorkouts($userId, array $workouts, $source) {
        $imported = 0;
        $skipped = 0;
        $shareQueueJobs = 0;
        $insertStmt = $this->db->prepare("
            INSERT INTO workouts (user_id, session_id, source, external_id, activity_type, start_time, end_time, duration_minutes, duration_seconds, distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $checkByExternalStmt = $this->db->prepare("SELECT id, avg_heart_rate, elevation_gain FROM workouts WHERE user_id = ? AND external_id = ? AND source = ? LIMIT 1");
        $checkByTimeStmt = $this->db->prepare("SELECT id, avg_heart_rate, elevation_gain FROM workouts WHERE user_id = ? AND start_time = ? AND source = ? AND (external_id IS NULL OR external_id = '') LIMIT 1");
        // Cross-source: ±14 часов покрывает timezone mismatches (FIT=UTC, Strava=local)
        $checkCrossSourceStmt = $this->db->prepare("
            SELECT id, distance_km, duration_seconds, duration_minutes, start_time, avg_heart_rate FROM workouts
            WHERE user_id = ? AND start_time BETWEEN DATE_SUB(?, INTERVAL 14 HOUR) AND DATE_ADD(?, INTERVAL 14 HOUR) LIMIT 10
        ");
        $updateStmt = $this->db->prepare("
            UPDATE workouts SET activity_type = ?, end_time = ?, duration_minutes = ?, duration_seconds = ?, distance_km = ?, avg_pace = ?, avg_heart_rate = ?, max_heart_rate = ?, elevation_gain = ?
            WHERE id = ?
        ");
        foreach ($workouts as $w) {
            $activityType = $w['activity_type'] ?? 'running';
            $startTime = $w['start_time'] ?? null;
            $endTime = $w['end_time'] ?? $startTime;
            $durationMinutes = isset($w['duration_minutes']) ? (int)$w['duration_minutes'] : null;
            $durationSeconds = isset($w['duration_seconds']) ? (int)$w['duration_seconds'] : null;
            $distanceKm = isset($w['distance_km']) ? (float)$w['distance_km'] : null;
            $avgPace = $w['avg_pace'] ?? null;
            $avgHeartRate = isset($w['avg_heart_rate']) ? (int)$w['avg_heart_rate'] : null;
            $maxHeartRate = isset($w['max_heart_rate']) ? (int)$w['max_heart_rate'] : null;
            $elevationGain = isset($w['elevation_gain']) ? (int)$w['elevation_gain'] : null;
            $externalId = !empty($w['external_id']) ? (string)$w['external_id'] : null;
            if (!$startTime) {
                $skipped++;
                continue;
            }
            $existing = null;
            if ($externalId) {
                $checkByExternalStmt->bind_param("iss", $userId, $externalId, $source);
                $checkByExternalStmt->execute();
                $existing = $checkByExternalStmt->get_result()->fetch_assoc();
            } else {
                $checkByTimeStmt->bind_param("iss", $userId, $startTime, $source);
                $checkByTimeStmt->execute();
                $existing = $checkByTimeStmt->get_result()->fetch_assoc();
            }
            if ($existing) {
                $updateStmt->bind_param("ssiidsiiii",
                    $activityType, $endTime, $durationMinutes, $durationSeconds, $distanceKm, $avgPace,
                    $avgHeartRate, $maxHeartRate, $elevationGain,
                    $existing['id']
                );
                if ($updateStmt->execute()) {
                    $imported++;
                    $this->saveWorkoutTimeline((int)$existing['id'], $w['timeline'] ?? null);
                    $this->saveWorkoutLaps((int)$existing['id'], $w['laps'] ?? null);
                    $shareQueueJobs += $this->queueWorkoutShareCards((int) $userId, (int) $existing['id'], WorkoutShareCardCacheService::KIND_WORKOUT);
                } else {
                    $skipped++;
                }
                continue;
            }
            $checkCrossSourceStmt->bind_param("iss", $userId, $startTime, $startTime);
            $checkCrossSourceStmt->execute();
            $crossResult = $checkCrossSourceStmt->get_result();
            $crossExisting = false;
            $newStartTs = strtotime($startTime);
            while ($crossRow = $crossResult->fetch_assoc()) {
                $existingTs = strtotime($crossRow['start_time']);
                $timeDiffSec = abs($newStartTs - $existingTs);
                $isCloseTime = ($timeDiffSec <= 120); // ±2 минуты
                $mod = $timeDiffSec % 3600;
                    $isTimezoneOffset = !$isCloseTime && ($mod < 300 || $mod > 3300); // кратно часу ±5 мин

                if ($isCloseTime) {
                    // Близкое время — однозначный дубликат
                    $crossExisting = true;
                    break;
                } elseif ($isTimezoneOffset) {
                    // Timezone mismatch — проверяем дистанцию И длительность
                    $existDist = isset($crossRow['distance_km']) ? (float)$crossRow['distance_km'] : 0;
                    $existDurSec = isset($crossRow['duration_seconds']) ? (int)$crossRow['duration_seconds']
                        : (isset($crossRow['duration_minutes']) ? (int)$crossRow['duration_minutes'] * 60 : 0);

                    $distMatch = ($distanceKm > 0 && $existDist > 0)
                        ? abs($distanceKm - $existDist) <= max(0.15, $distanceKm * 0.03)
                        : false;
                    $durMatch = ($durationSeconds > 0 && $existDurSec > 0)
                        ? abs($durationSeconds - $existDurSec) <= 180
                        : false;

                    // Пульс как доп. подтверждение (если оба имеют данные)
                    $existHr = isset($crossRow['avg_heart_rate']) ? (int)$crossRow['avg_heart_rate'] : 0;
                    $hrMatch = true; // по умолчанию не блокирует (если нет данных)
                    if ($avgHeartRate > 0 && $existHr > 0) {
                        $hrMatch = abs($avgHeartRate - $existHr) <= 10; // ±10 bpm
                    }

                    // Требуем: (дистанция + длительность) + пульс если доступен
                    if ($distMatch && $durMatch && $hrMatch) {
                        $crossExisting = true;
                        break;
                    }
                }
            }
            if ($crossExisting) {
                $skipped++;
                continue;
            }
            $insertStmt->bind_param("isssssiidsiii",
                $userId, $source, $externalId,
                $activityType, $startTime, $endTime, $durationMinutes, $durationSeconds, $distanceKm, $avgPace,
                $avgHeartRate, $maxHeartRate, $elevationGain
            );
            if ($insertStmt->execute()) {
                $workoutId = (int)$this->db->insert_id;
                $w['_imported_id'] = $workoutId; // запоминаем для postWorkoutAnalysis
                $imported++;
                $this->saveWorkoutTimeline($workoutId, $w['timeline'] ?? null);
                $this->saveWorkoutLaps($workoutId, $w['laps'] ?? null);
                $shareQueueJobs += $this->queueWorkoutShareCards((int) $userId, $workoutId, WorkoutShareCardCacheService::KIND_WORKOUT);
            } else {
                $skipped++;
            }
        }
        $insertStmt->close();
        $checkByExternalStmt->close();
        $checkByTimeStmt->close();
        $checkCrossSourceStmt->close();
        $updateStmt->close();
        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");

        // Автообновление VDOT по свежим тренировкам
        if ($imported > 0) {
            $this->maybeUpdateVdotFromWorkouts($userId);
        }

        if ($shareQueueJobs > 0) {
            $this->launchWorkoutShareWorkerAsync();
        }

        // Trigger post-workout AI analysis for each imported workout
        if ($imported > 0 && (int) env('PROACTIVE_COACH_ENABLED', 0) === 1) {
            try {
                require_once __DIR__ . '/ProactiveCoachService.php';
                $coach = new ProactiveCoachService($this->db);

                // Собираем все успешно импортированные тренировки (с _imported_id)
                foreach ($workouts as $w) {
                    $wId = $w['_imported_id'] ?? null;
                    if ($wId === null) continue; // пропущенные/дубликаты

                    $wDate = isset($w['start_time'])
                        ? date('Y-m-d', is_numeric($w['start_time']) ? $w['start_time'] : strtotime($w['start_time']))
                        : null;
                    if (!$wDate) continue;

                    try {
                        $coach->postWorkoutAnalysis($userId, $wDate, 0, $wId);
                    } catch (Throwable $e) {
                        // Ошибка одной тренировки не должна блокировать остальные
                        Logger::debug('WorkoutService: post-workout analysis failed for workout', [
                            'workoutId' => $wId, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (Throwable $e) {
                // Non-critical — don't fail the import
            }
        }

        // Пересчёт целевого пульса после импорта (новые HR данные могут сместить диапазоны)
        if ($imported > 0) {
            try {
                require_once __DIR__ . '/UserProfileService.php';
                $ups = new UserProfileService($this->db);
                $ups->recalculateHrTargetsForFutureDays($userId);
            } catch (Throwable $e) {
                error_log("importWorkouts HR recalc error: " . $e->getMessage());
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
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

            // Автообновление VDOT по свежим результатам
            if ($completed && $resultDistance > 0 && ($resultTime || $resultPace)) {
                $this->maybeUpdateVdotFromWorkouts($userId);
            }

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

                $this->deleteWorkoutShareCards((int) $userId, (int) $workoutId, WorkoutShareCardCacheService::KIND_MANUAL);

                require_once __DIR__ . '/../cache_config.php';
                Cache::delete("training_plan_{$userId}");

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

                    if ($this->tableExists('workout_laps')) {
                        $deleteLapsStmt = $this->db->prepare("DELETE FROM workout_laps WHERE workout_id = ?");
                        if ($deleteLapsStmt) {
                            $deleteLapsStmt->bind_param("i", $workoutId);
                            $deleteLapsStmt->execute();
                            $deleteLapsStmt->close();
                        }
                    }
                    
                    // Удаляем основную запись
                    $deleteWorkoutStmt = $this->db->prepare("DELETE FROM workouts WHERE id = ? AND user_id = ?");
                    $deleteWorkoutStmt->bind_param("ii", $workoutId, $userId);
                    $deleteWorkoutStmt->execute();
                    $deleteWorkoutStmt->close();
                    
                    $this->db->commit();

                    $this->deleteWorkoutShareCards((int) $userId, (int) $workoutId, WorkoutShareCardCacheService::KIND_WORKOUT);

                    require_once __DIR__ . '/../cache_config.php';
                    Cache::delete("training_plan_{$userId}");

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
     * Сохранить timeline точки в workout_timeline
     *
     * @param int $workoutId ID тренировки
     * @param array|null $timeline Массив точек [['timestamp','heart_rate','pace','altitude','distance','cadence'], ...]
     */
    private function saveWorkoutTimeline(int $workoutId, ?array $timeline): void {
        if (!$workoutId || empty($timeline)) {
            return;
        }
        $deleteStmt = $this->db->prepare("DELETE FROM workout_timeline WHERE workout_id = ?");
        $deleteStmt->bind_param("i", $workoutId);
        $deleteStmt->execute();
        $deleteStmt->close();
        $count = count($timeline);
        if ($count > self::TIMELINE_MAX_POINTS) {
            $step = max(1, (int)floor($count / self::TIMELINE_MAX_POINTS));
            $sampled = [];
            for ($i = 0; $i < $count; $i += $step) {
                $sampled[] = $timeline[$i];
            }
            if (end($sampled) !== end($timeline)) {
                $sampled[] = end($timeline);
            }
            $timeline = $sampled;
        }
        $batchSize = 500;
        $batches = array_chunk($timeline, $batchSize);
        foreach ($batches as $batch) {
            $values = [];
            foreach ($batch as $p) {
                $ts = isset($p['timestamp']) ? "'" . $this->db->real_escape_string($p['timestamp']) . "'" : 'NULL';
                $hr = isset($p['heart_rate']) ? (int)$p['heart_rate'] : 'NULL';
                $pace = isset($p['pace']) && $p['pace'] !== null ? "'" . $this->db->real_escape_string($p['pace']) . "'" : 'NULL';
                $alt = isset($p['altitude']) && $p['altitude'] !== null ? (float)$p['altitude'] : 'NULL';
                $dist = isset($p['distance']) && $p['distance'] !== null ? (float)$p['distance'] : 'NULL';
                $cad = isset($p['cadence']) ? (int)$p['cadence'] : 'NULL';
                $lat = isset($p['latitude']) && $p['latitude'] !== null ? (float)$p['latitude'] : 'NULL';
                $lng = isset($p['longitude']) && $p['longitude'] !== null ? (float)$p['longitude'] : 'NULL';
                $values[] = "($workoutId, $ts, $hr, $pace, $alt, $dist, $cad, $lat, $lng)";
            }
            $sql = "INSERT INTO workout_timeline (workout_id, timestamp, heart_rate, pace, altitude, distance, cadence, latitude, longitude) VALUES " . implode(',', $values);
            $this->db->query($sql);
        }
    }

    /**
     * Сохранить круги/отрезки тренировки в workout_laps.
     *
     * @param int $workoutId ID тренировки
     * @param array|null $laps Массив кругов [['lap_index', 'name', 'distance_km', ...], ...]
     */
    private function saveWorkoutLaps(int $workoutId, ?array $laps): void {
        if (!$workoutId || empty($laps) || !$this->tableExists('workout_laps')) {
            return;
        }
        $deleteStmt = $this->db->prepare("DELETE FROM workout_laps WHERE workout_id = ?");
        if (!$deleteStmt) {
            return;
        }
        $deleteStmt->bind_param("i", $workoutId);
        $deleteStmt->execute();
        $deleteStmt->close();

        $values = [];
        foreach ($laps as $index => $lap) {
            if (!is_array($lap)) {
                continue;
            }
            $lapIndex = isset($lap['lap_index']) ? max(1, (int)$lap['lap_index']) : ($index + 1);
            $lapName = isset($lap['name']) && trim((string)$lap['name']) !== ''
                ? "'" . $this->db->real_escape_string(trim((string)$lap['name'])) . "'"
                : 'NULL';
            $startTime = isset($lap['start_time']) && trim((string)$lap['start_time']) !== ''
                ? "'" . $this->db->real_escape_string(trim((string)$lap['start_time'])) . "'"
                : 'NULL';
            $elapsedSeconds = isset($lap['elapsed_seconds']) ? max(0, (int)$lap['elapsed_seconds']) : 'NULL';
            $movingSeconds = isset($lap['moving_seconds']) ? max(0, (int)$lap['moving_seconds']) : 'NULL';
            $distanceKm = isset($lap['distance_km']) && $lap['distance_km'] !== null ? (float)$lap['distance_km'] : 'NULL';
            $averageSpeed = isset($lap['average_speed']) && $lap['average_speed'] !== null ? (float)$lap['average_speed'] : 'NULL';
            $maxSpeed = isset($lap['max_speed']) && $lap['max_speed'] !== null ? (float)$lap['max_speed'] : 'NULL';
            $avgHeartRate = isset($lap['avg_heart_rate']) && $lap['avg_heart_rate'] !== null ? (int)$lap['avg_heart_rate'] : 'NULL';
            $maxHeartRate = isset($lap['max_heart_rate']) && $lap['max_heart_rate'] !== null ? (int)$lap['max_heart_rate'] : 'NULL';
            $elevationGain = isset($lap['elevation_gain']) && $lap['elevation_gain'] !== null ? (float)$lap['elevation_gain'] : 'NULL';
            $cadence = isset($lap['cadence']) && $lap['cadence'] !== null ? (int)$lap['cadence'] : 'NULL';
            $startIndex = isset($lap['start_index']) && $lap['start_index'] !== null ? max(0, (int)$lap['start_index']) : 'NULL';
            $endIndex = isset($lap['end_index']) && $lap['end_index'] !== null ? max(0, (int)$lap['end_index']) : 'NULL';
            $payloadJson = json_encode($lap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $payloadValue = $payloadJson !== false
                ? "'" . $this->db->real_escape_string($payloadJson) . "'"
                : 'NULL';

            $values[] = sprintf(
                '(%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $workoutId,
                $lapIndex,
                $lapName,
                $startTime,
                $elapsedSeconds,
                $movingSeconds,
                $distanceKm,
                $averageSpeed,
                $maxSpeed,
                $avgHeartRate,
                $maxHeartRate,
                $elevationGain,
                $cadence,
                $startIndex,
                $endIndex,
                $payloadValue
            );
        }

        if (empty($values)) {
            return;
        }

        $sql = "INSERT INTO workout_laps (
                workout_id, lap_index, lap_name, start_time, elapsed_seconds, moving_seconds,
                distance_km, average_speed, max_speed, avg_heart_rate, max_heart_rate,
                elevation_gain, cadence, start_index, end_index, payload_json
            ) VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                lap_name = VALUES(lap_name),
                start_time = VALUES(start_time),
                elapsed_seconds = VALUES(elapsed_seconds),
                moving_seconds = VALUES(moving_seconds),
                distance_km = VALUES(distance_km),
                average_speed = VALUES(average_speed),
                max_speed = VALUES(max_speed),
                avg_heart_rate = VALUES(avg_heart_rate),
                max_heart_rate = VALUES(max_heart_rate),
                elevation_gain = VALUES(elevation_gain),
                cadence = VALUES(cadence),
                start_index = VALUES(start_index),
                end_index = VALUES(end_index),
                payload_json = VALUES(payload_json)";
        $this->db->query($sql);
    }

    /**
     * Получить timeline данные и круги тренировки.
     * 
     * @param int $workoutId ID тренировки
     * @param int $userId ID пользователя
     * @return array{timeline: array<int, array<string, mixed>>, laps: array<int, array<string, mixed>>}
     * @throws Exception
     */
    private const TIMELINE_MAX_POINTS = 500;

    public function getWorkoutTimeline($workoutId, $userId) {
        try {
            if (!$workoutId || $workoutId <= 0) {
                $this->throwException('Не указан ID тренировки', 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT id FROM workouts WHERE id = ? AND user_id = ? LIMIT 1");
            $checkStmt->bind_param("ii", $workoutId, $userId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $workout = $result->fetch_assoc();
            $checkStmt->close();
            
            if (!$workout) {
                $this->throwException('Тренировка не найдена или нет доступа', 404);
            }

            $timeline = [];
            $countStmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM workout_timeline WHERE workout_id = ?");
            if ($countStmt) {
                $countStmt->bind_param("i", $workoutId);
                $countStmt->execute();
                $totalRows = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
                $countStmt->close();

                if ($totalRows > 0) {
                    $step = max(1, (int)floor($totalRows / self::TIMELINE_MAX_POINTS));

                    if ($step <= 1) {
                        $timelineStmt = $this->db->prepare("
                            SELECT timestamp, heart_rate, pace, cadence, altitude, distance, latitude, longitude
                            FROM workout_timeline
                            WHERE workout_id = ?
                            ORDER BY timestamp ASC
                        ");
                        if ($timelineStmt) {
                            $timelineStmt->bind_param("i", $workoutId);
                        }
                    } else {
                        $timelineStmt = $this->db->prepare("
                            SELECT timestamp, heart_rate, pace, cadence, altitude, distance, latitude, longitude
                            FROM (
                                SELECT *, @rn := @rn + 1 AS row_num
                                FROM workout_timeline, (SELECT @rn := -1) vars
                                WHERE workout_id = ?
                                ORDER BY timestamp ASC
                            ) numbered
                            WHERE row_num % ? = 0 OR row_num = 0
                        ");
                        if ($timelineStmt) {
                            $timelineStmt->bind_param("ii", $workoutId, $step);
                        }
                    }

                    if (!empty($timelineStmt)) {
                        $timelineStmt->execute();
                        $timelineResult = $timelineStmt->get_result();

                        while ($row = $timelineResult->fetch_assoc()) {
                            $timeline[] = [
                                'timestamp' => $row['timestamp'],
                                'heart_rate' => $row['heart_rate'] !== null ? (int)$row['heart_rate'] : null,
                                'pace' => $row['pace'],
                                'cadence' => $row['cadence'] !== null ? (int)$row['cadence'] : null,
                                'altitude' => $row['altitude'] !== null ? (float)$row['altitude'] : null,
                                'distance' => $row['distance'] !== null ? (float)$row['distance'] : null,
                                'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                                'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                            ];
                        }
                        $timelineStmt->close();
                    }
                }
            }

            return [
                'timeline' => $timeline,
                'laps' => $this->getWorkoutLaps((int)$workoutId),
            ];
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

    private function getWorkoutLaps(int $workoutId): array {
        if ($workoutId <= 0 || !$this->tableExists('workout_laps')) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT lap_index, lap_name, start_time, elapsed_seconds, moving_seconds,
                   distance_km, average_speed, max_speed, avg_heart_rate, max_heart_rate,
                   elevation_gain, cadence, start_index, end_index, payload_json
            FROM workout_laps
            WHERE workout_id = ?
            ORDER BY lap_index ASC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $workoutId);
        $stmt->execute();
        $result = $stmt->get_result();
        $laps = [];
        while ($row = $result->fetch_assoc()) {
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $laps[] = [
                'lap_index' => isset($payload['lap_index']) ? (int)$payload['lap_index'] : (int)$row['lap_index'],
                'name' => $payload['name'] ?? $row['lap_name'],
                'start_time' => $payload['start_time'] ?? $row['start_time'],
                'elapsed_seconds' => array_key_exists('elapsed_seconds', $payload)
                    ? ($payload['elapsed_seconds'] !== null ? (int)$payload['elapsed_seconds'] : null)
                    : ($row['elapsed_seconds'] !== null ? (int)$row['elapsed_seconds'] : null),
                'moving_seconds' => array_key_exists('moving_seconds', $payload)
                    ? ($payload['moving_seconds'] !== null ? (int)$payload['moving_seconds'] : null)
                    : ($row['moving_seconds'] !== null ? (int)$row['moving_seconds'] : null),
                'distance_km' => array_key_exists('distance_km', $payload)
                    ? ($payload['distance_km'] !== null ? (float)$payload['distance_km'] : null)
                    : ($row['distance_km'] !== null ? (float)$row['distance_km'] : null),
                'average_speed' => array_key_exists('average_speed', $payload)
                    ? ($payload['average_speed'] !== null ? (float)$payload['average_speed'] : null)
                    : ($row['average_speed'] !== null ? (float)$row['average_speed'] : null),
                'max_speed' => array_key_exists('max_speed', $payload)
                    ? ($payload['max_speed'] !== null ? (float)$payload['max_speed'] : null)
                    : ($row['max_speed'] !== null ? (float)$row['max_speed'] : null),
                'avg_pace' => $payload['avg_pace'] ?? null,
                'pace_seconds_per_km' => isset($payload['pace_seconds_per_km']) && $payload['pace_seconds_per_km'] !== null
                    ? (int)$payload['pace_seconds_per_km']
                    : null,
                'avg_heart_rate' => array_key_exists('avg_heart_rate', $payload)
                    ? ($payload['avg_heart_rate'] !== null ? (int)$payload['avg_heart_rate'] : null)
                    : ($row['avg_heart_rate'] !== null ? (int)$row['avg_heart_rate'] : null),
                'max_heart_rate' => array_key_exists('max_heart_rate', $payload)
                    ? ($payload['max_heart_rate'] !== null ? (int)$payload['max_heart_rate'] : null)
                    : ($row['max_heart_rate'] !== null ? (int)$row['max_heart_rate'] : null),
                'elevation_gain' => array_key_exists('elevation_gain', $payload)
                    ? ($payload['elevation_gain'] !== null ? (float)$payload['elevation_gain'] : null)
                    : ($row['elevation_gain'] !== null ? (float)$row['elevation_gain'] : null),
                'cadence' => array_key_exists('cadence', $payload)
                    ? ($payload['cadence'] !== null ? (int)$payload['cadence'] : null)
                    : ($row['cadence'] !== null ? (int)$row['cadence'] : null),
                'start_index' => array_key_exists('start_index', $payload)
                    ? ($payload['start_index'] !== null ? (int)$payload['start_index'] : null)
                    : ($row['start_index'] !== null ? (int)$row['start_index'] : null),
                'end_index' => array_key_exists('end_index', $payload)
                    ? ($payload['end_index'] !== null ? (int)$payload['end_index'] : null)
                    : ($row['end_index'] !== null ? (int)$row['end_index'] : null),
            ];
        }
        $stmt->close();
        return $laps;
    }

    private function tableExists(string $tableName): bool {
        if ($tableName === '') {
            return false;
        }
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return (bool)$this->tableExistsCache[$tableName];
        }
        $safeName = preg_replace('/[^a-z0-9_]/i', '', $tableName);
        if ($safeName === '') {
            $this->tableExistsCache[$tableName] = false;
            return false;
        }
        $result = $this->db->query("SHOW TABLES LIKE '" . $this->db->real_escape_string($safeName) . "'");
        $exists = $result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $this->tableExistsCache[$tableName] = $exists;
        return $exists;
    }

    /**
     * Получить версию данных (для polling).
     */
    public function getDataVersion(int $userId): string {
        $stmt = $this->db->prepare(
            "SELECT
                (SELECT COALESCE(MAX(id), 0) FROM workouts WHERE user_id = ?) AS w_max,
                (SELECT COALESCE(MAX(id), 0) FROM workout_log WHERE user_id = ?) AS l_max,
                (SELECT COUNT(*) FROM workouts WHERE user_id = ?) AS w_cnt"
        );
        $stmt->bind_param('iii', $userId, $userId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ($row['w_max'] ?? 0) . '_' . ($row['l_max'] ?? 0) . '_' . ($row['w_cnt'] ?? 0);
    }

    /**
     * Загрузить результат тренировки по дате.
     */
    public function getWorkoutResultByDate(string $date, int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM workout_log
            WHERE user_id = ? AND training_date = ?
            LIMIT 1
        ");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Проверяет, является ли завершённая тренировка контрольной/забегом,
     * и если да — пересчитывает VDOT пользователя, уведомляет в чат.
     */
    public function checkVdotUpdateAfterResult(array $data, int $userId): void {
        try {
            $distanceKm = isset($data['result_distance']) ? (float)$data['result_distance'] : 0;
            $resultTime = $data['result_time'] ?? '';
            if ($distanceKm <= 0 || $resultTime === '') {
                return;
            }

            $weekNum = (int)($data['week'] ?? 0);
            $dayName = $data['day'] ?? '';
            if (!$weekNum || !$dayName) return;

            $dayMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
            $dayOfWeek = $dayMap[$dayName] ?? 0;
            if (!$dayOfWeek) return;

            $stmt = $this->db->prepare("
                SELECT tpd.type FROM training_plan_days tpd
                INNER JOIN training_plan_weeks tpw ON tpd.week_id = tpw.id
                WHERE tpw.user_id = ? AND tpw.week_number = ? AND tpd.day_of_week = ?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $userId, $weekNum, $dayOfWeek);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $type = $row['type'] ?? '';
            if (!in_array($type, ['control', 'race'])) {
                return;
            }

            $timeSec = $this->parseResultTimeSec($resultTime);
            if ($timeSec <= 0) return;

            require_once __DIR__ . '/TrainingStateBuilder.php';
            require_once __DIR__ . '/WorkoutPlanRecalculationService.php';
            require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

            $builder = new TrainingStateBuilder($this->db);
            $oldState = $builder->buildForUserId($userId);
            $oldVdot = isset($oldState['vdot']) ? (float)$oldState['vdot'] : null;

            $newVdot = estimateVDOT($distanceKm, $timeSec);
            if ($newVdot < 20 || $newVdot > 85) return;

            $date = $data['date'] ?? date('Y-m-d');

            // Определяем стандартную метку дистанции
            $distMap = [5 => '5k', 10 => '10k', 21 => 'half', 42 => 'marathon'];
            $lastRaceDist = 'other';
            $lastRaceDistKm = $distanceKm;
            foreach ($distMap as $km => $label) {
                if (abs($distanceKm - $km) < 0.5) {
                    $lastRaceDist = $label;
                    $lastRaceDistKm = null;
                    break;
                }
            }

            $updateStmt = $this->db->prepare("
                UPDATE users SET last_race_distance = ?, last_race_distance_km = ?, last_race_time = ?, last_race_date = ? WHERE id = ?
            ");
            $updateStmt->bind_param('sdssi', $lastRaceDist, $lastRaceDistKm, $resultTime, $date, $userId);
            $updateStmt->execute();
            $updateStmt->close();

            $newState = $builder->buildForUserId($userId);
            $newVdotR = !empty($newState['vdot']) ? round((float)$newState['vdot'], 1) : round($newVdot, 1);
            $formattedPaces = $newState['formatted_training_paces'] ?? null;
            $predictions = !empty($newState['vdot']) ? predictAllRaceTimes((float)$newState['vdot']) : predictAllRaceTimes($newVdot);
            $autoRecalc = (new WorkoutPlanRecalculationService($this->db))->maybeQueueAfterPerformanceUpdate(
                $userId, $type, $date, $oldVdot,
                isset($newState['vdot']) ? (float)$newState['vdot'] : $newVdot
            );

            $typeLabel = $type === 'control' ? 'контрольной тренировки' : 'забега';
            $msg = "Результат {$typeLabel}: {$distanceKm} км за {$resultTime}.\n";
            $msg .= "Ваш VDOT: **{$newVdotR}**";
            if ($oldVdot) {
                $diff = round($newVdotR - $oldVdot, 1);
                $arrow = $diff > 0 ? '+' : '';
                $msg .= " ({$arrow}{$diff})";
            }
            $msg .= "\n\n";
            if (!empty($newState['vdot_source_label'])) {
                $msg .= "Источник формы: {$newState['vdot_source_label']}";
                if (!empty($newState['vdot_confidence'])) {
                    $msg .= " ({$newState['vdot_confidence']})";
                }
                $msg .= "\n\n";
            }

            $msg .= "Обновлённые зоны:\n";
            if ($formattedPaces) {
                $msg .= "- Лёгкий: {$formattedPaces['easy']}/км\n";
                $msg .= "- Пороговый: {$formattedPaces['threshold']}/км\n";
                $msg .= "- Интервальный: {$formattedPaces['interval']}/км\n\n";
            } else {
                $paces = getTrainingPaces($newVdot);
                $msg .= "- Лёгкий: " . formatPaceSec($paces['easy'][0]) . " – " . formatPaceSec($paces['easy'][1]) . "/км\n";
                $msg .= "- Пороговый: " . formatPaceSec($paces['threshold']) . "/км\n";
                $msg .= "- Интервальный: " . formatPaceSec($paces['interval']) . "/км\n\n";
            }

            $msg .= "Прогнозы: ";
            $parts = [];
            foreach ($predictions as $label => $pred) {
                $distLabels = ['5k' => '5К', '10k' => '10К', 'half' => 'ПМ', 'marathon' => 'М'];
                $parts[] = ($distLabels[$label] ?? $label) . " " . $pred['formatted'];
            }
            $msg .= implode(' | ', $parts);

            if ($autoRecalc['queued']) {
                $msg .= "\n\nПлан автоматически поставлен на пересчёт с учётом нового результата.";
            } elseif (!empty($autoRecalc['skipped_reason'])) {
                $msg .= "\n\nАвтопересчёт плана не запускался: {$autoRecalc['skipped_reason']}.";
            }

            require_once __DIR__ . '/ChatService.php';
            $chatService = new ChatService($this->db);
            $chatService->addAIMessageToUser($userId, $msg, [
                'event_key' => 'performance.vdot_updated',
                'title' => 'VDOT обновлён',
                'link' => '/chat',
                'source_type' => $type,
                'workout_date' => $date,
            ]);
        } catch (\Throwable $e) {
            error_log("checkVdotUpdate error for user $userId: " . $e->getMessage());
        }
    }

    private function parseResultTimeSec(string $resultTime): int {
        $parts = explode(':', $resultTime);
        if (count($parts) === 3) {
            return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        } elseif (count($parts) === 2) {
            return (int)$parts[0] * 60 + (int)$parts[1];
        }
        return 0;
    }

    /**
     * Устаревший метод — VDOT из тренировок теперь рассчитывается на лету
     * через TrainingStateBuilder → StatsService::getBestResultForVdot().
     *
     * Ранее метод перезаписывал last_race_* полями из обычных тренировок,
     * что загрязняло источник (race-поля заполнялись не-race данными)
     * и создавало рассинхрон: виджет показывал новый VDOT, а план не пересчитывался.
     *
     * @deprecated Оставлен для обратной совместимости вызовов, ничего не делает.
     */
    public function maybeUpdateVdotFromWorkouts(int $userId): void {
        // No-op: TrainingStateBuilder.best_result обрабатывает этот кейс корректно
    }
}
