<?php
/**
 * Тесты для StatsService
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StatsService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/StatsService.php';

class StatsServiceTest extends TestCase {
    
    private $db;
    private $service;
    
    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->service = new StatsService($this->db);
    }
    
    /**
     * Тест получения статистики для несуществующего пользователя
     */
    public function test_getStats_returnsZeroForNonExistentUser(): void {
        $result = $this->service->getStats(999999);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('completed', $result);
        $this->assertArrayHasKey('percentage', $result);
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['completed']);
        $this->assertEquals(0, $result['percentage']);
    }
    
    /**
     * Тест получения сводки тренировок для несуществующего пользователя
     */
    public function test_getAllWorkoutsSummary_returnsEmptyForNonExistentUser(): void {
        $result = $this->service->getAllWorkoutsSummary(999999);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
