<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../controllers/BaseController.php';

class BaseControllerGetParamProbe extends \BaseController {
    private array $fakeJsonBody;

    public function __construct(array $fakeJsonBody = []) {
        $this->fakeJsonBody = $fakeJsonBody;
    }

    protected function initializeAccess() {
        $this->calendarUserId = 1;
        $this->currentUserId = 1;
        $this->canEdit = true;
        $this->canView = true;
        $this->isOwner = true;
    }

    protected function getJsonBody() {
        return $this->fakeJsonBody;
    }

    public function readParam(string $key, $default = null) {
        return $this->getParam($key, $default);
    }
}

class BaseControllerGetParamTest extends TestCase {
    protected function setUp(): void {
        $_GET = [];
        $_POST = [];
    }

    public function test_getParam_reads_json_body_when_post_is_empty(): void {
        $controller = new BaseControllerGetParamProbe(['reason' => 'хочу лонг по воскресеньям']);

        $this->assertSame('хочу лонг по воскресеньям', $controller->readParam('reason'));
    }
}
