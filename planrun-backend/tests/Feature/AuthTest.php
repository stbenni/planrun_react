<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Тесты для системы аутентификации
 * 
 * ВАЖНО: Эти тесты требуют реальной БД или моков
 */
class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Начинаем новую сессию для каждого теста
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }
    
    protected function tearDown(): void
    {
        // Очищаем сессию после теста
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        
        parent::tearDown();
    }
    
    public function test_isauthenticated_returns_false_when_not_logged_in(): void
    {
        $this->assertFalse(isAuthenticated());
    }
    
    public function test_isauthenticated_returns_true_when_session_set(): void
    {
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = 1;
        
        $this->assertTrue(isAuthenticated());
    }
    
    public function test_isauthenticated_requires_user_id(): void
    {
        $_SESSION['authenticated'] = true;
        // user_id не установлен
        
        $this->assertFalse(isAuthenticated());
    }
    
    public function test_logout_clears_session(): void
    {
        // Устанавливаем сессию
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'test';
        
        // Выходим
        logout();
        
        // Проверяем, что сессия очищена
        $this->assertEmpty($_SESSION);
    }
    
    /**
     * Тест логина требует реальной БД или моков
     * Пока закомментирован, чтобы не падать без БД
     */
    /*
    public function test_login_with_valid_credentials(): void
    {
        $result = login('test_user', 'test_password');
        // Этот тест требует настройки тестовой БД
    }
    */
}
