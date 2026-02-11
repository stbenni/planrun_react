<?php
/**
 * Контроллер для работы с неделями плана
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/WeekService.php';

class WeekController extends BaseController {
    
    protected $weekService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->weekService = new WeekService($db);
    }
    
    /**
     * Добавить неделю
     * POST /api_v2.php?action=add_week
     */
    public function addWeek() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $result = $this->weekService->addWeek($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Удалить неделю
     * POST /api_v2.php?action=delete_week
     */
    public function deleteWeek() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $weekId = $data['week_id'] ?? null;
            if (!$weekId) {
                $this->returnError('ID недели обязателен', 400);
                return;
            }
            $result = $this->weekService->deleteWeek($weekId, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Добавить день тренировки
     * POST /api_v2.php?action=add_training_day
     */
    public function addTrainingDay() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $result = $this->weekService->addTrainingDay($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Добавить тренировку на дату (календарная модель: только дата + тип + описание).
     * POST /api_v2.php?action=add_training_day_by_date
     * Body: { "date": "Y-m-d", "type": "easy"|"long"|..., "description": "...?", "is_key_workout": false? }
     */
    public function addTrainingDayByDate() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $result = $this->weekService->addTrainingDayByDate($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Обновить тренировку (день плана) по id.
     * POST /api_v2.php?action=update_training_day
     * Body: { "day_id": 123, "type": "easy", "description": "...", "is_key_workout": 0, "csrf_token": "..." }
     */
    public function updateTrainingDay() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $dayId = $data['day_id'] ?? null;
            if ($dayId === null) {
                $this->returnError('day_id обязателен', 400);
                return;
            }
            $result = $this->weekService->updateTrainingDayById((int) $dayId, $this->currentUserId, [
                'type' => $data['type'] ?? null,
                'description' => $data['description'] ?? null,
                'is_key_workout' => isset($data['is_key_workout']) ? (int) (bool) $data['is_key_workout'] : null,
            ]);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Удалить тренировку (день плана) по id.
     * POST /api_v2.php?action=delete_training_day
     * Body: { "day_id": 123 }
     */
    public function deleteTrainingDay() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $dayId = $data['day_id'] ?? null;
            if ($dayId === null) {
                $this->returnError('day_id обязателен', 400);
                return;
            }
            $result = $this->weekService->deleteTrainingDayById((int) $dayId, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
