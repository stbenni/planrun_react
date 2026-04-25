<?php
/**
 * Feature тесты для TrainingPlanController
 */

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use TrainingPlanController;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../controllers/TrainingPlanController.php';

class TrainingPlanControllerTest extends TestCase {
    
    private $db;
    private $controller;
    
    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'phpunit';
        $_GET = [];
        $_POST = [];
        $this->controller = new TrainingPlanController($this->db);
    }

    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        unset($_SESSION['authenticated'], $_SESSION['user_id'], $_SESSION['username']);
        parent::tearDown();
    }
    
    /**
     * Тест загрузки плана для несуществующего пользователя
     */
    public function test_load_returnsEmptyForNonExistentUser(): void {
        // Устанавливаем параметр через GET
        $_GET['user_id'] = 999999;
        
        // Захватываем вывод
        ob_start();
        try {
            $this->controller->load();
        } catch (Exception $e) {
            // Ожидаем, что будет ошибка или пустой результат
        }
        $output = ob_get_clean();
        
        // Проверяем, что вывод валидный JSON
        $data = json_decode($output, true);
        $this->assertNotNull($data, 'Ответ должен быть валидным JSON');
        $this->assertArrayHasKey('success', $data);
    }

    public function test_load_ignores_user_id_param_and_uses_authorized_calendar_user(): void {
        $_GET['user_id'] = 999999;

        $fakeService = new class {
            public function loadPlan($userId): array {
                return ['seen_user_id' => (int) $userId];
            }
        };
        $property = new \ReflectionProperty(TrainingPlanController::class, 'planService');
        $property->setAccessible(true);
        $property->setValue($this->controller, $fakeService);

        ob_start();
        $this->controller->load();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertSame(1, (int) ($data['data']['seen_user_id'] ?? 0));
    }

    public function test_checkStatus_ignores_user_id_param_and_uses_authorized_calendar_user(): void {
        $_GET['user_id'] = 999999;

        $fakeService = new class {
            public function checkPlanStatus($userId): array {
                return ['seen_user_id' => (int) $userId];
            }
        };
        $property = new \ReflectionProperty(TrainingPlanController::class, 'planService');
        $property->setAccessible(true);
        $property->setValue($this->controller, $fakeService);

        ob_start();
        $this->controller->checkStatus();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertSame(1, (int) ($data['data']['seen_user_id'] ?? 0));
    }
}
