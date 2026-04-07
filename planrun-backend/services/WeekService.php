<?php
/**
 * Сервис для работы с неделями плана
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/UserProfileService.php';
require_once __DIR__ . '/../repositories/WeekRepository.php';
require_once __DIR__ . '/../repositories/ExerciseRepository.php';
require_once __DIR__ . '/../validators/WeekValidator.php';
require_once __DIR__ . '/../exceptions/AppException.php';

class WeekService extends BaseService {
    
    protected $repository;
    protected $exerciseRepo;
    protected $validator;

    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new WeekRepository($db);
        $this->exerciseRepo = new ExerciseRepository($db);
        $this->validator = new WeekValidator();
    }

    /**
     * Обогатить данные дня целевым пульсом на основе типа тренировки.
     */
    private function enrichWithTargetHr(array $data, int $userId): array {
        if (!empty($data['target_hr_min']) && !empty($data['target_hr_max'])) {
            return $data;
        }
        $type = $data['type'] ?? 'rest';
        try {
            $svc = new UserProfileService($this->db);
            $hr = $svc->getTargetHrForWorkoutType($userId, $type);
            if ($hr) {
                $data['target_hr_min'] = (int) $hr['min'];
                $data['target_hr_max'] = (int) $hr['max'];
            }
        } catch (Throwable $e) {
            error_log("enrichWithTargetHr error: " . $e->getMessage());
        }
        return $data;
    }
    
    /**
     * Добавить неделю
     * 
     * @param array $data Данные недели
     * @param int $userId ID пользователя
     * @return array Результат операции
     * @throws Exception
     */
    public function addWeek($data, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateAddWeek($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            // Используем репозиторий
            $result = $this->repository->addWeek($data, $userId);
            
            // Инвалидируем кеш плана
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            
            return ['success' => true, 'week_id' => $result['insert_id']];
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка добавления недели: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Удалить неделю
     * 
     * @param int $weekId ID недели
     * @param int $userId ID пользователя
     * @return array Результат операции
     * @throws Exception
     */
    public function deleteWeek($weekId, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateDeleteWeek(['week_id' => $weekId])) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            // Используем репозиторий
            $result = $this->repository->deleteWeek($weekId, $userId);
            
            // Инвалидируем кеш плана
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            
            return ['success' => true];
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка удаления недели: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'week_id' => $weekId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Добавить день тренировки
     * 
     * @param array $data Данные дня
     * @param int $userId ID пользователя
     * @return array Результат операции
     * @throws Exception
     */
    public function addTrainingDay($data, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateAddTrainingDay($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            // Обогащаем целевым пульсом
            $data = $this->enrichWithTargetHr($data, $userId);
            // Используем репозиторий
            $result = $this->repository->addTrainingDay($data, $userId);
            
            // Инвалидируем кеш плана
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            
            return ['success' => true, 'day_id' => $result['insert_id']];
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка добавления дня тренировки: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Добавить тренировку на дату (календарная модель: только дата + тип).
     * Неделя находится или создаётся автоматически по дате.
     *
     * @param array $data ['date' => Y-m-d, 'type' => ..., 'description' => ?, 'is_key_workout' => ?]
     * @param int $userId
     * @return array ['success' => true, 'day_id' => ...]
     */
    public function addTrainingDayByDate($data, $userId) {
        try {
            if (!$this->validator->validateAddTrainingDayByDate($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }

            $date = $data['date'];
            $dateObj = new DateTime($date . ' 12:00:00');
            $dayOfWeek = (int) $dateObj->format('N'); // 1=Mon .. 7=Sun
            $daysToMonday = $dayOfWeek - 1;
            $monday = clone $dateObj;
            $monday->modify("-{$daysToMonday} days");
            $startDate = $monday->format('Y-m-d');

            $week = $this->repository->getWeekByStartDate($userId, $startDate);
            if (!$week) {
                $maxWeek = $this->repository->getMaxWeekNumber($userId);
                $addResult = $this->repository->addWeek([
                    'week_number' => $maxWeek + 1,
                    'start_date' => $startDate,
                    'total_volume' => null
                ], $userId);
                $week = $this->repository->getWeekById($addResult['insert_id'], $userId);
                if (!$week) {
                    $this->throwException('Не удалось создать неделю для даты ' . $date, 500);
                }
            }

            $weekId = (int) $week['id'];

            $dayData = $this->enrichWithTargetHr([
                'week_id' => $weekId,
                'day_of_week' => $dayOfWeek,
                'type' => $data['type'],
                'description' => $data['description'] ?? null,
                'date' => $date,
                'is_key_workout' => isset($data['is_key_workout']) ? (int)(bool)$data['is_key_workout'] : 0,
                'target_hr_min' => $data['target_hr_min'] ?? null,
                'target_hr_max' => $data['target_hr_max'] ?? null
            ], $userId);

            $result = $this->repository->addTrainingDay($dayData, $userId);
            $dayId = (int) $result['insert_id'];

            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");

            return ['success' => true, 'day_id' => $dayId];
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка добавления тренировки на дату: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Обновить тренировку (день плана) по id.
     * POST /api_v2.php?action=update_training_day, body: { "day_id": 123, "type": "easy", "description": "...", "is_key_workout": 0 }
     */
    public function updateTrainingDayById($dayId, $userId, $data) {
        if (!$dayId) {
            $this->throwException('day_id обязателен', 400);
        }
        $payload = [
            'day_id' => (int) $dayId,
            'type' => $data['type'] ?? null,
            'description' => $data['description'] ?? null,
            'is_key_workout' => $data['is_key_workout'] ?? null,
        ];
        if (!$this->validator->validateUpdateTrainingDay($payload)) {
            $this->throwValidationException('Ошибка валидации', $this->validator->getErrors());
        }

        $data = $this->enrichWithTargetHr($data, $userId);
        $this->repository->updateTrainingDayById((int) $dayId, $userId, $data);
        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");
        return ['success' => true];
    }

    /**
     * Удалить тренировку (день плана) по id.
     * POST /api_v2.php?action=delete_training_day, body: { "day_id": 123 }
     */
    public function deleteTrainingDayById($dayId, $userId) {
        if (!$dayId) {
            $this->throwException('day_id обязателен', 400);
        }
        $result = $this->repository->deleteTrainingDayById((int) $dayId, $userId);
        if (($result['affected_rows'] ?? 0) === 0) {
            $this->throwException('Запись не найдена или уже удалена', 404);
        }
        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");
        return ['success' => true];
    }

    /**
     * Скопировать все тренировки с одной даты на другую (с упражнениями).
     *
     * @param string $sourceDate Y-m-d
     * @param string $targetDate Y-m-d
     * @param int $userId
     * @return array ['success' => true, 'copied_days' => int]
     */
    public function copyDay($sourceDate, $targetDate, $userId) {
        if ($sourceDate === $targetDate) {
            $this->throwException('Исходная и целевая даты совпадают', 400);
        }

        $sourceDays = $this->repository->getDaysByDate($sourceDate, $userId);
        if (empty($sourceDays)) {
            $this->throwException('На дату ' . $sourceDate . ' нет тренировок для копирования', 404);
        }

        // Ensure target week exists
        $targetDateObj = new \DateTime($targetDate . ' 12:00:00');
        $targetDow = (int) $targetDateObj->format('N');
        $daysToMonday = $targetDow - 1;
        $monday = clone $targetDateObj;
        $monday->modify("-{$daysToMonday} days");
        $targetStartDate = $monday->format('Y-m-d');

        $targetWeek = $this->repository->getWeekByStartDate($userId, $targetStartDate);
        if (!$targetWeek) {
            $maxWeek = $this->repository->getMaxWeekNumber($userId);
            $addResult = $this->repository->addWeek([
                'week_number' => $maxWeek + 1,
                'start_date' => $targetStartDate,
                'total_volume' => null,
            ], $userId);
            $targetWeek = $this->repository->getWeekById($addResult['insert_id'], $userId);
        }
        $targetWeekId = (int) $targetWeek['id'];

        $copiedCount = 0;
        foreach ($sourceDays as $day) {
            $newDayResult = $this->repository->addTrainingDay([
                'week_id' => $targetWeekId,
                'day_of_week' => $targetDow,
                'type' => $day['type'],
                'description' => $day['description'],
                'date' => $targetDate,
                'is_key_workout' => $day['is_key_workout'] ?? 0,
            ], $userId);
            $newDayId = (int) $newDayResult['insert_id'];

            // Copy exercises
            $exercises = $this->exerciseRepo->getExercisesByDayId((int) $day['id'], $userId);
            foreach ($exercises as $ex) {
                $this->exerciseRepo->addExercise([
                    'plan_day_id' => $newDayId,
                    'exercise_id' => $ex['exercise_id'] ?? null,
                    'category' => $ex['category'],
                    'name' => $ex['name'],
                    'sets' => $ex['sets'],
                    'reps' => $ex['reps'],
                    'distance_m' => $ex['distance_m'],
                    'duration_sec' => $ex['duration_sec'],
                    'weight_kg' => $ex['weight_kg'],
                    'pace' => $ex['pace'],
                    'notes' => $ex['notes'],
                    'order_index' => $ex['order_index'] ?? 0,
                ], $userId);
            }
            $copiedCount++;
        }

        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");

        return ['success' => true, 'copied_days' => $copiedCount];
    }

    /**
     * Скопировать все тренировки одной недели на другую (по дате понедельника целевой недели).
     *
     * @param int $sourceWeekId ID исходной недели
     * @param string $targetStartDate Y-m-d (понедельник целевой недели)
     * @param int $userId
     * @return array ['success' => true, 'copied_days' => int]
     */
    public function copyWeek($sourceWeekId, $targetStartDate, $userId) {
        $sourceWeek = $this->repository->getWeekById($sourceWeekId, $userId);
        if (!$sourceWeek) {
            $this->throwException('Исходная неделя не найдена', 404);
        }

        // Validate target is Monday
        $targetDateObj = new \DateTime($targetStartDate . ' 12:00:00');
        if ((int) $targetDateObj->format('N') !== 1) {
            $this->throwException('Целевая дата должна быть понедельником', 400);
        }

        if ($sourceWeek['start_date'] === $targetStartDate) {
            $this->throwException('Исходная и целевая недели совпадают', 400);
        }

        // Get or create target week
        $targetWeek = $this->repository->getWeekByStartDate($userId, $targetStartDate);
        if (!$targetWeek) {
            $maxWeek = $this->repository->getMaxWeekNumber($userId);
            $addResult = $this->repository->addWeek([
                'week_number' => $maxWeek + 1,
                'start_date' => $targetStartDate,
                'total_volume' => $sourceWeek['total_volume'],
            ], $userId);
            $targetWeek = $this->repository->getWeekById($addResult['insert_id'], $userId);
        }
        $targetWeekId = (int) $targetWeek['id'];

        $sourceDays = $this->repository->getDaysByWeekId($userId, (int) $sourceWeek['id']);
        $copiedCount = 0;

        foreach ($sourceDays as $day) {
            $dow = (int) $day['day_of_week'];
            // Calculate target date from target Monday + day offset
            $dayDateObj = clone $targetDateObj;
            $dayDateObj->modify('+' . ($dow - 1) . ' days');
            $dayDate = $dayDateObj->format('Y-m-d');

            $newDayResult = $this->repository->addTrainingDay([
                'week_id' => $targetWeekId,
                'day_of_week' => $dow,
                'type' => $day['type'],
                'description' => $day['description'],
                'date' => $dayDate,
                'is_key_workout' => $day['is_key_workout'] ?? 0,
            ], $userId);
            $newDayId = (int) $newDayResult['insert_id'];

            // Copy exercises
            $exercises = $this->exerciseRepo->getExercisesByDayId((int) $day['id'], $userId);
            foreach ($exercises as $ex) {
                $this->exerciseRepo->addExercise([
                    'plan_day_id' => $newDayId,
                    'exercise_id' => $ex['exercise_id'] ?? null,
                    'category' => $ex['category'],
                    'name' => $ex['name'],
                    'sets' => $ex['sets'],
                    'reps' => $ex['reps'],
                    'distance_m' => $ex['distance_m'],
                    'duration_sec' => $ex['duration_sec'],
                    'weight_kg' => $ex['weight_kg'],
                    'pace' => $ex['pace'],
                    'notes' => $ex['notes'],
                    'order_index' => $ex['order_index'] ?? 0,
                ], $userId);
            }
            $copiedCount++;
        }

        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");

        return ['success' => true, 'copied_days' => $copiedCount];
    }
}
