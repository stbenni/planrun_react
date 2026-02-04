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
        $this->controller = new TrainingPlanController($this->db);
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
}
