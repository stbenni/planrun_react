<?php
/**
 * Smoke-тесты CoachTemplateService.
 *
 * Использует тот же FakeDb-стиль, что и RegistrationServiceTest.
 * Покрывает базовые валидации входа и behavior bulkAssign без реальных таблиц.
 */

namespace Tests\Unit\Coach;

use PHPUnit\Framework\TestCase;
use CoachTemplateService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/CoachTemplateService.php';

class CoachFakeResult {
    public int $num_rows;
    private array $rows;
    public function __construct(array $rows = []) {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }
    public function fetch_assoc() { return array_shift($this->rows) ?: null; }
    public function fetch_all($mode = MYSQLI_ASSOC) {
        $out = [];
        while (($r = $this->fetch_assoc()) !== null) $out[] = $r;
        return $out;
    }
}

class CoachFakeStmt {
    public string $error = '';
    private array $rows;
    private $onExecute;
    public function __construct(array $rows = [], ?callable $onExecute = null) {
        $this->rows = $rows;
        $this->onExecute = $onExecute;
    }
    public function bind_param(...$args): bool { return true; }
    public function execute(): bool {
        if ($this->onExecute) return (bool) call_user_func($this->onExecute);
        return true;
    }
    public function get_result(): CoachFakeResult { return new CoachFakeResult($this->rows); }
    public function close(): void {}
}

class CoachFakeDb {
    public int $insert_id = 0;
    public string $error = '';
    public bool $inTx = false;
    private array $handlers = [];

    public function whenPrepareContains(string $needle, callable $factory): void {
        $this->handlers[] = [$needle, $factory];
    }
    public function prepare(string $sql) {
        foreach ($this->handlers as [$needle, $factory]) {
            if (str_contains($sql, $needle)) return $factory($sql, $this);
        }
        // Default: empty result
        return new CoachFakeStmt([], null);
    }
    public function begin_transaction(): bool { $this->inTx = true; return true; }
    public function commit(): bool { $this->inTx = false; return true; }
    public function rollback(): bool { $this->inTx = false; return true; }
}

class CoachTemplateServiceTest extends TestCase {

    public function test_bulkAssign_returns_error_when_no_athletes(): void {
        $db = new CoachFakeDb();
        $service = new CoachTemplateService($db);

        $result = $service->bulkAssign(/*coachId*/ 1, /*templateId*/ 1, [], '2026-06-01', false);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Не выбраны атлеты', $result['errors'][0]);
    }

    public function test_bulkAssign_throws_on_invalid_date_format(): void {
        $db = new CoachFakeDb();
        $service = new CoachTemplateService($db);

        $this->expectException(\Exception::class);
        $service->bulkAssign(1, 1, [10], 'tomorrow-not-iso', false);
    }

    public function test_bulkAssign_returns_error_when_template_not_owned(): void {
        $db = new CoachFakeDb();
        // Coach owns no templates → SELECT FROM coach_workout_templates returns empty
        $db->whenPrepareContains('FROM coach_workout_templates', function () {
            return new CoachFakeStmt([], null);
        });

        $service = new CoachTemplateService($db);
        $result = $service->bulkAssign(1, /*nonexistent*/ 999, [10], '2026-06-01', false);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Шаблон не найден', $result['errors'][0]);
    }

    public function test_bulkAssign_filters_athletes_without_edit_permission(): void {
        $db = new CoachFakeDb();
        // Template found
        $db->whenPrepareContains('FROM coach_workout_templates WHERE id = ?', function () {
            return new CoachFakeStmt([
                ['id' => 5, 'name' => 'Easy', 'type' => 'easy', 'distance' => 6.0,
                 'emoji' => '🟢', 'description' => 'desc', 'is_key_workout' => 0],
            ]);
        });
        // user_coaches: ни один атлет не authorized → пустой результат
        $db->whenPrepareContains('FROM user_coaches', function () {
            return new CoachFakeStmt([]);
        });

        $service = new CoachTemplateService($db);
        $result = $service->bulkAssign(1, 5, [10, 20, 30], '2026-06-01', false);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Нет прав', $result['errors'][0]);
    }

    public function test_bulkAssign_returns_conflicts_when_overwrite_false(): void {
        $db = new CoachFakeDb();
        $db->whenPrepareContains('FROM coach_workout_templates WHERE id = ?', function () {
            return new CoachFakeStmt([
                ['id' => 5, 'name' => 'Easy', 'type' => 'easy', 'distance' => 6.0,
                 'emoji' => '🟢', 'description' => 'desc', 'is_key_workout' => 0],
            ]);
        });
        // Athletes 10, 20 authorized
        $db->whenPrepareContains('SELECT user_id FROM user_coaches', function () {
            return new CoachFakeStmt([
                ['user_id' => 10],
                ['user_id' => 20],
            ]);
        });
        // Existing plan_day для атлета 10
        $db->whenPrepareContains('FROM training_plan_days', function () {
            return new CoachFakeStmt([
                ['id' => 555, 'user_id' => 10, 'type' => 'tempo', 'description' => 'Старый план'],
            ]);
        });
        // Names lookup
        $db->whenPrepareContains('FROM users WHERE id IN', function () {
            return new CoachFakeStmt([
                ['id' => 10, 'username' => 'runner10'],
            ]);
        });

        $service = new CoachTemplateService($db);
        $result = $service->bulkAssign(1, 5, [10, 20], '2026-06-01', false);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('conflicts', $result);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame(10, $result['conflicts'][0]['athlete_id']);
        $this->assertSame('runner10', $result['conflicts'][0]['athlete_name']);
        $this->assertSame('tempo', $result['conflicts'][0]['existing']['type']);
    }
}
