<?php
/**
 * Тесты для RegistrationService без реальной БД.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RegistrationService;
use EmailVerificationService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/EmailVerificationService.php';
require_once __DIR__ . '/../../services/RegistrationService.php';

class FakeResult {
    public int $num_rows;
    private array $rows;

    public function __construct(array $rows = []) {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc() {
        return array_shift($this->rows) ?: null;
    }
}

class FakeStmt {
    public string $error = '';
    private array $rows;
    private $onExecute;
    private array $params = [];

    public function __construct(array $rows = [], ?callable $onExecute = null) {
        $this->rows = $rows;
        $this->onExecute = $onExecute;
    }

    public function bind_param($types, &...$params): bool {
        $this->params = $params;
        return true;
    }

    public function execute(): bool {
        if ($this->onExecute) {
            return (bool) call_user_func($this->onExecute, $this->params, $this);
        }
        return true;
    }

    public function get_result(): FakeResult {
        return new FakeResult($this->rows);
    }

    public function close(): void {
    }
}

class FakeDb {
    public int $insert_id = 0;
    public string $error = '';
    private array $prepareHandlers = [];
    private array $queryHandlers = [];

    public function whenPrepareContains(string $needle, callable $factory): void {
        $this->prepareHandlers[] = [$needle, $factory];
    }

    public function whenQueryContains(string $needle, $result): void {
        $this->queryHandlers[] = [$needle, $result];
    }

    public function prepare(string $sql) {
        foreach ($this->prepareHandlers as [$needle, $factory]) {
            if (str_contains($sql, $needle)) {
                return $factory($sql, $this);
            }
        }

        $this->error = 'Unexpected prepare: ' . $sql;
        return false;
    }

    public function query(string $sql) {
        foreach ($this->queryHandlers as [$needle, $result]) {
            if (str_contains($sql, $needle)) {
                return is_callable($result) ? $result($sql, $this) : $result;
            }
        }

        return new FakeResult([]);
    }
}

class RegistrationServiceTest extends TestCase {
    public function test_validateField_rejects_short_username_before_db_lookup(): void {
        $service = new RegistrationService(new FakeDb(), $this->createMock(EmailVerificationService::class));

        $result = $service->validateField('username', 'ab');

        $this->assertFalse($result['valid']);
        $this->assertSame('Имя пользователя должно быть не менее 3 символов', $result['message']);
    }

    public function test_prepareRegistrationIdentity_rejects_when_registration_disabled(): void {
        $db = new FakeDb();
        $db->whenQueryContains("SHOW TABLES LIKE 'site_settings'", new FakeResult([['Tables_in_db' => 'site_settings']]));
        $db->whenQueryContains("SELECT value FROM site_settings", new FakeResult([['value' => '0']]));

        $service = new RegistrationService($db, $this->createMock(EmailVerificationService::class));
        $result = $service->prepareRegistrationIdentity('runner', 'runner@example.com');

        $this->assertFalse($result['success']);
        $this->assertSame('Регистрация отключена администратором', $result['error']);
    }

    public function test_registerMinimal_returns_verification_error_payload(): void {
        $verification = $this->createMock(EmailVerificationService::class);
        $verification->method('verifyCode')->willReturn([
            'success' => false,
            'error' => 'Неверный код. Осталось попыток: 2',
            'attempts_left' => 2,
            'code_required' => true,
        ]);

        $service = new RegistrationService(new FakeDb(), $verification);
        $result = $service->registerMinimal([
            'username' => 'runner',
            'password' => 'secret123',
            'email' => 'runner@example.com',
            'verification_code' => '000000',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(2, $result['attempts_left']);
        $this->assertTrue($result['code_required']);
    }

    public function test_registerMinimal_creates_user_and_returns_payload(): void {
        $db = new FakeDb();
        $db->whenQueryContains("SHOW TABLES LIKE 'site_settings'", new FakeResult([]));

        $db->whenPrepareContains('SELECT id FROM users WHERE username = ?', fn() => new FakeStmt([]));
        $db->whenPrepareContains('SELECT id FROM users WHERE email = ? AND email IS NOT NULL AND email != ""', fn() => new FakeStmt([]));
        $db->whenPrepareContains('SELECT id FROM users WHERE username_slug = ?', fn() => new FakeStmt([]));
        $db->whenPrepareContains(
            'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender)',
            fn($sql, $fakeDb) => new FakeStmt([], function () use ($fakeDb) {
                $fakeDb->insert_id = 42;
                return true;
            })
        );

        $verification = $this->createMock(EmailVerificationService::class);
        $verification->method('verifyCode')->willReturn(['success' => true]);

        $service = new RegistrationService($db, $verification);
        $result = $service->registerMinimal([
            'username' => 'runner',
            'password' => 'secret123',
            'email' => 'runner@example.com',
            'verification_code' => '123456',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['user']['id']);
        $this->assertSame('runner', $result['user']['username']);
        $this->assertSame('runner@example.com', $result['user']['email']);
        $this->assertSame(0, $result['user']['onboarding_completed']);
    }
}
