<?php
/**
 * Контроллер для адаптации плана
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/AdaptationService.php';

class AdaptationController extends BaseController {
    
    protected $adaptationService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->adaptationService = new AdaptationService($db);
    }
    
    /**
     * Запустить недельную адаптацию
     * GET /api_v2.php?action=run_weekly_adaptation
     */
    public function runWeeklyAdaptation() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        try {
            $result = $this->adaptationService->runWeeklyAdaptation($this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
