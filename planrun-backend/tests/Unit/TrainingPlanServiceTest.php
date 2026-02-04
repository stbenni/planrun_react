<?php
/**
 * Тесты для TrainingPlanService
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrainingPlanService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/TrainingPlanService.php';

class TrainingPlanServiceTest extends TestCase {
    
    private $db;
    private $service;
    
    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->service = new TrainingPlanService($this->db);
    }
    
    /**
     * Тест загрузки плана для несуществующего пользователя
     */
    public function test_loadPlan_returnsEmptyForNonExistentUser(): void {
        $result = $this->service->loadPlan(999999);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('phases', $result);
        $this->assertArrayHasKey('has_plan', $result);
        $this->assertFalse($result['has_plan']);
        $this->assertEmpty($result['phases']);
    }
    
    /**
     * Тест проверки статуса плана для несуществующего пользователя
     */
    public function test_checkPlanStatus_returnsNoPlanForNonExistentUser(): void {
        $result = $this->service->checkPlanStatus(999999);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('has_plan', $result);
        $this->assertFalse($result['has_plan']);
        $this->assertArrayHasKey('user_id', $result);
    }
    
    /**
     * Тест очистки сообщения о генерации плана
     */
    public function test_clearPlanGenerationMessage_works(): void {
        // Устанавливаем сообщение
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'Test message';
        
        // Очищаем
        $this->service->clearPlanGenerationMessage();
        
        // Проверяем, что сообщение удалено
        $this->assertFalse(isset($_SESSION['plan_generation_message']));
    }
}
