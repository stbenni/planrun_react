<?php
/**
 * Тесты для WorkoutService
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WorkoutService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/WorkoutService.php';

class WorkoutServiceTest extends TestCase {
    
    private $db;
    private $service;
    
    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->service = new WorkoutService($this->db);
    }
    
    /**
     * Тест получения всех результатов для несуществующего пользователя
     */
    public function test_getAllResults_returnsEmptyForNonExistentUser(): void {
        $result = $this->service->getAllResults(999999);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertIsArray($result['results']);
        $this->assertEmpty($result['results']);
    }
    
    /**
     * Тест получения результата для несуществующей тренировки
     */
    public function test_getResult_returnsNullForNonExistentWorkout(): void {
        $result = $this->service->getResult('2026-01-01', 1, 'mon', 999999);
        
        $this->assertNull($result);
    }
}
