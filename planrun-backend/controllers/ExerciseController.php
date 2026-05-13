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

    /**
     * Отметить выполнение упражнений на ОФП-дне.
     * POST /api_v2.php?action=mark_exercises_completed
     * body: { plan_day_id: 123, executed_date: "YYYY-MM-DD", exercises: [...] }
     */
    public function markExercisesCompleted() {
        if (!$this->requireAuth() || !$this->requireEdit()) return;
        $this->checkCsrfToken();
        try {
            require_once __DIR__ . '/../services/ExecutedExerciseService.php';
            $data = $this->getJsonBody();
            $planDayId = (int) ($data['plan_day_id'] ?? 0);
            $executedDate = (string) ($data['executed_date'] ?? date('Y-m-d'));
            $exercises = is_array($data['exercises'] ?? null) ? $data['exercises'] : [];
            if ($planDayId <= 0) {
                $this->throwException('plan_day_id required', 400);
            }
            $svc = new ExecutedExerciseService($this->db);
            $result = $svc->markCompleted($this->calendarUserId, $planDayId, $executedDate, $exercises);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * История выполнения упражнений атлета.
     * GET /api_v2.php?action=get_exercise_history&weeks=12
     */
    public function getExerciseHistory() {
        if (!$this->requireAuth()) return;
        try {
            require_once __DIR__ . '/../services/ExecutedExerciseService.php';
            $weeks = isset($_GET['weeks']) ? max(1, min(52, (int) $_GET['weeks'])) : 12;
            $svc = new ExecutedExerciseService($this->db);
            $result = $svc->getRecentHistoryForUser($this->calendarUserId, $weeks);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Получить отмеченное выполнение для конкретного plan-day.
     * GET /api_v2.php?action=get_executed_for_day&plan_day_id=123
     */
    public function getExecutedForDay() {
        if (!$this->requireAuth()) return;
        try {
            require_once __DIR__ . '/../services/ExecutedExerciseService.php';
            $planDayId = isset($_GET['plan_day_id']) ? (int) $_GET['plan_day_id'] : 0;
            if ($planDayId <= 0) {
                $this->throwException('plan_day_id required', 400);
            }
            $svc = new ExecutedExerciseService($this->db);
            $result = $svc->getByPlanDay($this->calendarUserId, $planDayId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
