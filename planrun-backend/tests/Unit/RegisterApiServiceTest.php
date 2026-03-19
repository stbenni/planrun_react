<?php
/**
 * Тесты для RegisterApiService без реальной БД.
 */

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RegisterApiService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/RegisterApiService.php';

class RegisterApiServiceTest extends TestCase {
    public function test_validateField_delegates_to_registration_service(): void {
        $registrationService = new class {
            public array $calls = [];

            public function validateField($field, $value) {
                $this->calls[] = [$field, $value];
                return ['valid' => true, 'message' => 'ok'];
            }
        };

        $service = new RegisterApiService(new \stdClass(), $registrationService, new \stdClass());
        $result = $service->validateField('username', 'runner');

        $this->assertTrue($result['valid']);
        $this->assertSame([['username', 'runner']], $registrationService->calls);
    }

    public function test_sendVerificationCode_rejects_invalid_email_before_external_calls(): void {
        $service = new RegisterApiService(new \stdClass(), new \stdClass(), new \stdClass());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Некорректный формат email');

        $service->sendVerificationCode('bad-email', '127.0.0.1');
    }
}
