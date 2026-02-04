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
}
