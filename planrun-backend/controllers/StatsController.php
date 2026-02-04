<?php
/**
 * Контроллер для работы со статистикой
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/StatsService.php';

class StatsController extends BaseController {
    
    protected $statsService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->statsService = new StatsService($db);
    }
    
    /**
     * Получить статистику
     * GET /api_v2.php?action=stats
     */
    public function stats() {
        try {
            $data = $this->statsService->getStats($this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Получить сводку всех тренировок
     * GET /api_v2.php?action=get_all_workouts_summary
     */
    public function getAllWorkoutsSummary() {
        try {
            $data = $this->statsService->getAllWorkoutsSummary($this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Подготовить недельный анализ
     * GET /api_v2.php?action=prepare_weekly_analysis&week=1
     */
    public function prepareWeeklyAnalysis() {
        try {
            $userId = $this->getParam('user_id', $this->calendarUserId);
            $weekNumber = $this->getParam('week') ? (int)$this->getParam('week') : null;
            
            $analysis = $this->statsService->prepareWeeklyAnalysis($userId, $weekNumber);
            $this->returnSuccess($analysis);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
