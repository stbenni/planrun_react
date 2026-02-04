<?php
/**
 * Сервис для работы с упражнениями
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/ExerciseRepository.php';
require_once __DIR__ . '/../validators/ExerciseValidator.php';
require_once __DIR__ . '/../exceptions/AppException.php';

class ExerciseService extends BaseService {
    
    protected $repository;
    protected $validator;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new ExerciseRepository($db);
        $this->validator = new ExerciseValidator();
    }
    
    /**
     * Добавить упражнение к дню
     * 
     * @param array $data Данные упражнения
     * @param int $userId ID пользователя
     * @return array Результат операции
     * @throws Exception
     */
    public function addDayExercise($data, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateAddExercise($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            // Используем репозиторий
            $result = $this->repository->addExercise($data, $userId);
            
            // Инвалидируем кеш плана
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            
            return ['success' => true, 'exercise_id' => $result['insert_id']];
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка добавления упражнения: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обновить упражнение
     * 
     * @param array $data Данные упражнения
     * @param int $userId ID пользователя
     * @return array Результат операции
     * @throws Exception
     */
    public function updateDayExercise($data, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateUpdateExercise($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            $exerciseId = $data['exercise_id'];
            
            // Используем репозиторий
            $result = $this->repository->updateExercise($exerciseId, $data, $userId);
            
            // Инвалидируем кеш плана
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            
            return ['success' => true];
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка обновления упражнения: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Удалить упражнение
     * 
     * @param int $exerciseId ID упражнения
     * @param int $userId ID пользователя
     * @return array Результат операции
     * @throws Exception
     */
    public function deleteDayExercise($exerciseId, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateDeleteExercise(['exercise_id' => $exerciseId])) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            // Используем репозиторий
            $result = $this->repository->deleteExercise($exerciseId, $userId);
            
            // Инвалидируем кеш плана
            require_once __DIR__ . '/../cache_config.php';
            Cache::delete("training_plan_{$userId}");
            
            return ['success' => true];
        } catch (Exception $e) {
            if ($e instanceof AppException) {
                throw $e;
            }
            $this->throwException('Ошибка удаления упражнения: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'exercise_id' => $exerciseId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Изменить порядок упражнений
     * 
     * @param array $data Данные для изменения порядка
     * @param int $userId ID пользователя
     * @return array Результат операции
     * @throws Exception
     */
    public function reorderDayExercises($data, $userId) {
        try {
            // Валидация
            if (!$this->validator->validateReorderExercises($data)) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            if (!isset($data['items']) || !is_array($data['items'])) {
                $this->throwException('Не указан массив items с id и order_index', 400);
            }
            
            $this->db->begin_transaction();
            try {
                foreach ($data['items'] as $item) {
                    $id = isset($item['id']) ? (int)$item['id'] : 0;
                    $order = isset($item['order_index']) ? (int)$item['order_index'] : 0;
                    
                    if (!$id) continue;
                    
                    $stmt = $this->db->prepare("UPDATE training_day_exercises SET order_index = ? WHERE user_id = ? AND id = ?");
                    $stmt->bind_param("iii", $order, $userId, $id);
                    $stmt->execute();
                    
                    if ($stmt->error) {
                        $stmt->close();
                        throw new Exception('Ошибка БД: ' . $stmt->error);
                    }
                    $stmt->close();
                }
                
                $this->db->commit();
                
                // Инвалидируем кеш
                require_once __DIR__ . '/../cache_config.php';
                Cache::delete("training_plan_{$userId}");
                require_once __DIR__ . '/../config/Logger.php';
                Logger::debug("Training plan cache invalidated after reordering exercises", ['user_id' => $userId]);
                
                return ['success' => true];
            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            $this->throwException('Ошибка изменения порядка упражнений: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить библиотеку упражнений
     * 
     * @param int $userId ID пользователя
     * @return array Библиотека упражнений
     * @throws Exception
     */
    public function listExerciseLibrary($userId) {
        try {
            // Используем репозиторий
            $exercises = $this->repository->getExerciseLibrary();
            
            return ['exercises' => $exercises];
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки библиотеки упражнений: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
