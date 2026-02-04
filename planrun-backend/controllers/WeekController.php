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
}
