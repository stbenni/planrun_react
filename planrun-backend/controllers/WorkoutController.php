<?php
/**
 * Контроллер для работы с тренировками и результатами
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../workout_types.php';

class WorkoutController extends BaseController {
    
    /**
     * Получить день тренировки
     * GET /api_v2.php?action=get_day&date=2026-01-25
     */
    public function getDay() {
        $date = $this->getParam('date');
        if (!$date) {
            $this->returnError('Параметр date обязателен');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $data = $service->getDay($date, $this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Сохранить результат тренировки
     * POST /api_v2.php?action=save_result
     */
    public function saveResult() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        // Получаем данные из JSON body
        $data = $this->getJsonBody();
        if (!$data) {
            $this->returnError('Данные не переданы');
            return;
        }
        
        if (!isset($data['date']) || !isset($data['week']) || !isset($data['day']) || !isset($data['activity_type_id'])) {
            $this->returnError('Недостаточно данных: требуется date, week, day, activity_type_id');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $result = $service->saveResult($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Получить результат тренировки
     * GET /api.php?action=get_result&date=2026-01-25
     */
    public function getResult() {
        $date = $this->getParam('date');
        if (!$date) {
            $this->returnError('Параметр date обязателен');
            return;
        }
        
        try {
            $result = $this->loadWorkoutResult($date, $this->calendarUserId);
            $this->returnSuccess(['result' => $result]);
        } catch (Exception $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error('Ошибка загрузки результата', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            $this->returnError('Ошибка загрузки результата', 500);
        }
    }
    
    /**
     * Получить все результаты
     * GET /api_v2.php?action=get_all_results
     */
    public function getAllResults() {
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $data = $service->getAllResults($this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Удалить тренировку
     * POST /api_v2.php?action=delete_workout
     */
    public function deleteWorkout() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        // Получаем данные из JSON body
        $data = $this->getJsonBody();
        if (!$data || !isset($data['workout_id'])) {
            $this->returnError('Не указан ID тренировки');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $workoutId = (int)$data['workout_id'];
            $isManual = isset($data['is_manual']) ? (bool)$data['is_manual'] : false;
            $result = $service->deleteWorkout($workoutId, $isManual, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Загрузить упражнения дня
     */
    private function loadDayExercises($planDayId, $userId) {
        $stmt = $this->db->prepare("
            SELECT id, category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index 
            FROM training_day_exercises 
            WHERE user_id = ? AND plan_day_id = ? 
            ORDER BY order_index ASC, id ASC
        ");
        $stmt->bind_param("ii", $userId, $planDayId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $exercises = [];
        while ($row = $result->fetch_assoc()) {
            $exercises[] = $row;
        }
        $stmt->close();
        
        return $exercises;
    }
    
    /**
     * Сохранить прогресс тренировки (старая функция save)
     * POST /api_v2.php?action=save
     */
    public function save() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        // Получаем данные из JSON body
        $data = $this->getJsonBody();
        if (!$data) {
            $this->returnError('Данные не переданы');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $result = $service->saveProgress($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Сбросить прогресс
     * POST /api_v2.php?action=reset
     */
    public function reset() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $result = $service->resetProgress($this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Получить timeline данные тренировки
     * GET /api_v2.php?action=get_workout_timeline&workout_id=123
     */
    public function getWorkoutTimeline() {
        $workoutId = $this->getParam('workout_id');
        if (!$workoutId) {
            $this->returnError('Параметр workout_id обязателен');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $timeline = $service->getWorkoutTimeline((int)$workoutId, $this->currentUserId);
            $this->returnSuccess(['timeline' => $timeline]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Загрузить результат тренировки
     */
    private function loadWorkoutResult($date, $userId) {
        $stmt = $this->db->prepare("
            SELECT * FROM workout_log 
            WHERE user_id = ? AND training_date = ? 
            LIMIT 1
        ");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ?: null;
    }
}
