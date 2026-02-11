<?php
/**
 * Сервис для работы с неделями плана
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/WeekRepository.php';
require_once __DIR__ . '/../validators/WeekValidator.php';
require_once __DIR__ . '/../exceptions/AppException.php';

class WeekService extends BaseService {
    
    protected $repository;
    protected $validator;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new WeekRepository($db);
        $this->validator = new WeekValidator();
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

            $result = $this->repository->addTrainingDay([
                'week_id' => $weekId,
                'day_of_week' => $dayOfWeek,
                'type' => $data['type'],
                'description' => $data['description'] ?? null,
                'date' => $date,
                'is_key_workout' => isset($data['is_key_workout']) ? (int)(bool)$data['is_key_workout'] : 0
            ], $userId);
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
}
