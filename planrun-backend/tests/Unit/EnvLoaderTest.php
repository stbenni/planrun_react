<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Тесты для загрузчика переменных окружения
 */
class EnvLoaderTest extends TestCase
{
    private string $testEnvPath;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Создаем временный .env файл для тестов
        $this->testEnvPath = __DIR__ . '/../../.env.test';
        
        // Очищаем переменные окружения перед каждым тестом
        putenv('TEST_VAR');
        unset($_ENV['TEST_VAR']);
    }
    
    protected function tearDown(): void
    {
        // Удаляем тестовый .env файл
        if (file_exists($this->testEnvPath)) {
            unlink($this->testEnvPath);
        }
        
        parent::tearDown();
    }
    
    public function test_env_function_returns_default_when_not_set(): void
    {
        $value = env('NON_EXISTENT_VAR', 'default_value');
        $this->assertEquals('default_value', $value);
    }
    
    public function test_env_function_reads_from_environment(): void
    {
        putenv('TEST_VAR=test_value');
        $value = env('TEST_VAR', 'default');
        $this->assertEquals('test_value', $value);
    }
    
    public function test_env_function_reads_from_env_array(): void
    {
        $_ENV['TEST_VAR'] = 'array_value';
        $value = env('TEST_VAR', 'default');
        $this->assertEquals('array_value', $value);
    }
    
    public function test_loadenv_loads_from_file(): void
    {
        // Очищаем переменные перед тестом
        putenv('TEST_KEY');
        unset($_ENV['TEST_KEY']);
        putenv('TEST_NUMBER');
        unset($_ENV['TEST_NUMBER']);
        
        // Создаем тестовый .env файл
        file_put_contents($this->testEnvPath, "TEST_KEY=test_value\nTEST_NUMBER=123\n");
        
        // Загружаем его
        loadEnv($this->testEnvPath);
        
        // Проверяем, что значения загружены
        $this->assertEquals('test_value', env('TEST_KEY'));
        $this->assertEquals('123', env('TEST_NUMBER'));
    }
    
    public function test_loadenv_ignores_comments(): void
    {
        // Очищаем переменные перед тестом
        putenv('TEST_KEY');
        unset($_ENV['TEST_KEY']);
        
        file_put_contents($this->testEnvPath, "# This is a comment\nTEST_KEY=value\n# Another comment\n");
        
        loadEnv($this->testEnvPath);
        
        $this->assertEquals('value', env('TEST_KEY'));
    }
    
    public function test_loadenv_handles_quoted_values(): void
    {
        // Очищаем переменные перед тестом
        putenv('TEST_KEY');
        unset($_ENV['TEST_KEY']);
        
        file_put_contents($this->testEnvPath, 'TEST_KEY="quoted value"');
        
        loadEnv($this->testEnvPath);
        
        $this->assertEquals('quoted value', env('TEST_KEY'));
    }
    
    public function test_loadenv_handles_empty_file(): void
    {
        file_put_contents($this->testEnvPath, '');
        
        // Не должно быть ошибок
        $this->expectNotToPerformAssertions();
        loadEnv($this->testEnvPath);
    }
    
    public function test_loadenv_handles_missing_file_gracefully(): void
    {
        // Не должно быть ошибок при отсутствии файла
        $this->expectNotToPerformAssertions();
        loadEnv(__DIR__ . '/../../.env.nonexistent');
    }
}
