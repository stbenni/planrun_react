<?php
/**
 * Контроллер для работы с планами тренировок
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/TrainingPlanService.php';

class TrainingPlanController extends BaseController {
    
    protected $planService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->planService = new TrainingPlanService($db);
    }
    
    /**
     * Загрузить план тренировок
     * GET /api_v2.php?action=load
     */
    public function load() {
        $userId = $this->getParam('user_id', $this->calendarUserId);
        
        try {
            $planData = $this->planService->loadPlan($userId);
            $this->returnSuccess($planData);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Сохранить план тренировок
     * POST /api_v2.php?action=save
     * 
     * TODO: Реализовать сохранение плана
     */
    public function save() {
        $this->returnError('Сохранение плана пока не реализовано. Используйте редактирование через интерфейс.', 501);
    }
    
    /**
     * Проверить статус плана
     * GET /api_v2.php?action=check_plan_status
     */
    public function checkStatus() {
        $checkUserId = $this->getParam('user_id', $this->calendarUserId);
        
        try {
            $status = $this->planService->checkPlanStatus($checkUserId);
            $this->returnSuccess($status);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Регенерировать план
     * GET /api_v2.php?action=regenerate_plan
     */
    public function regeneratePlan() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        try {
            $result = $this->planService->regeneratePlan($this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Регенерировать план с учетом прогресса
     * POST /api_v2.php?action=regenerate_plan_with_progress
     */
    public function regeneratePlanWithProgress() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        try {
            $result = $this->planService->regeneratePlanWithProgress($this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Очистить сообщение о генерации плана
     * GET /api_v2.php?action=clear_plan_generation_message
     */
    public function clearPlanGenerationMessage() {
        if (!$this->requireAuth()) {
            return;
        }
        
        $this->planService->clearPlanGenerationMessage();
        $this->returnSuccess();
    }
}
