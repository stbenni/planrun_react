<?php
/**
 * Тесты для репозиториев
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrainingPlanRepository;
use WorkoutRepository;
use StatsRepository;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../repositories/TrainingPlanRepository.php';
require_once __DIR__ . '/../../repositories/WorkoutRepository.php';
require_once __DIR__ . '/../../repositories/StatsRepository.php';

class RepositoryTest extends TestCase {
    
    private $db;
    
    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
    }
    
    /**
     * Тест TrainingPlanRepository::getPlanByUserId для несуществующего пользователя
     */
    public function test_trainingPlanRepository_getPlanByUserId_returnsNullForNonExistentUser(): void {
        $repository = new TrainingPlanRepository($this->db);
        $result = $repository->getPlanByUserId(999999);
        $this->assertNull($result);
    }
    
    /**
     * Тест WorkoutRepository::getAllResults для несуществующего пользователя
     */
    public function test_workoutRepository_getAllResults_returnsEmptyForNonExistentUser(): void {
        $repository = new WorkoutRepository($this->db);
        $result = $repository->getAllResults(999999);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    /**
     * Тест StatsRepository::getTotalDays для несуществующего пользователя
     */
    public function test_statsRepository_getTotalDays_returnsZeroForNonExistentUser(): void {
        $repository = new StatsRepository($this->db);
        $result = $repository->getTotalDays(999999);
        $this->assertEquals(0, $result);
    }
}
