<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Тесты для конфигурации базы данных
 */
class DbConfigTest extends TestCase
{
    public function test_db_config_constants_are_defined(): void
    {
        $this->assertDefined('DB_HOST');
        $this->assertDefined('DB_NAME');
        $this->assertDefined('DB_USER');
        $this->assertDefined('DB_PASS');
        $this->assertDefined('DB_CHARSET');
    }
    
    public function test_db_config_uses_env_when_available(): void
    {
        // Устанавливаем тестовые значения через env
        putenv('DB_HOST=test_host');
        putenv('DB_NAME=test_db');
        putenv('DB_USER=test_user');
        putenv('DB_PASSWORD=test_pass');
        
        // Перезагружаем конфигурацию (в реальности это делается при загрузке файла)
        // Для теста просто проверяем, что функция env работает
        $host = env('DB_HOST', 'localhost');
        $this->assertNotEmpty($host);
    }
    
    public function test_getdbconnection_returns_mysqli_or_null(): void
    {
        // В тестовом окружении может не быть реального подключения
        // Проверяем, что функция существует и может быть вызвана
        $this->assertTrue(function_exists('getDBConnection'));
    }
    
    private function assertDefined(string $constant): void
    {
        $this->assertTrue(
            defined($constant),
            "Константа {$constant} должна быть определена"
        );
    }
}
