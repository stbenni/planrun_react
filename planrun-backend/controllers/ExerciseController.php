<?php
/**
 * Контроллер для работы с упражнениями
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/ExerciseService.php';

class ExerciseController extends BaseController {
    
    protected $exerciseService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->exerciseService = new ExerciseService($db);
    }
    
    /**
     * Добавить упражнение к дню
     * POST /api_v2.php?action=add_day_exercise
     */
    public function addDayExercise() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $result = $this->exerciseService->addDayExercise($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Обновить упражнение
     * POST /api_v2.php?action=update_day_exercise
     */
    public function updateDayExercise() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $result = $this->exerciseService->updateDayExercise($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Удалить упражнение
     * POST /api_v2.php?action=delete_day_exercise
     */
    public function deleteDayExercise() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $exerciseId = $data['exercise_id'] ?? null;
            if (!$exerciseId) {
                $this->returnError('ID упражнения обязателен', 400);
                return;
            }
            $result = $this->exerciseService->deleteDayExercise($exerciseId, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Изменить порядок упражнений
     * POST /api.php?action=reorder_day_exercises
     */
    public function reorderDayExercises() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $result = $this->exerciseService->reorderDayExercises($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Получить библиотеку упражнений
     * GET /api_v2.php?action=list_exercise_library
     */
    public function listExerciseLibrary() {
        try {
            $result = $this->exerciseService->listExerciseLibrary($this->calendarUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
