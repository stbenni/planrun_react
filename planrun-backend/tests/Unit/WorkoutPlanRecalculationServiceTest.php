<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WorkoutPlanRecalculationService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/WorkoutPlanRecalculationService.php';

class WorkoutPlanUpdateFakeResult {
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

class WorkoutPlanUpdateFakeStmt {
    public int $affected_rows = 1;
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

    public function get_result(): WorkoutPlanUpdateFakeResult {
        return new WorkoutPlanUpdateFakeResult($this->rows);
    }

    public function close(): void {
    }
}

class WorkoutPlanUpdateFakeDb {
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

        return new WorkoutPlanUpdateFakeResult([]);
    }
}

class WorkoutPlanRecalculationServiceTest extends TestCase {
    public function test_skips_when_too_few_future_workouts_remain(): void {
        $db = new WorkoutPlanUpdateFakeDb();
        $db->whenPrepareContains(
            'SELECT COUNT(*) AS cnt',
            fn() => new WorkoutPlanUpdateFakeStmt([['cnt' => 1]])
        );

        $service = new WorkoutPlanRecalculationService($db);
        $result = $service->maybeQueueAfterPerformanceUpdate(42, 'control', '2026-03-10', 45.0, 46.5);

        $this->assertFalse($result['queued']);
        $this->assertSame('в плане почти не осталось будущих тренировок', $result['skipped_reason']);
    }

    public function test_skips_when_vdot_change_is_too_small(): void {
        $db = new WorkoutPlanUpdateFakeDb();
        $db->whenPrepareContains(
            'SELECT COUNT(*) AS cnt',
            fn() => new WorkoutPlanUpdateFakeStmt([['cnt' => 6]])
        );

        $service = new WorkoutPlanRecalculationService($db);
        $result = $service->maybeQueueAfterPerformanceUpdate(42, 'race', '2026-03-10', 50.0, 50.6);

        $this->assertFalse($result['queued']);
        $this->assertSame('изменение формы слишком маленькое для автоматического пересчёта', $result['skipped_reason']);
    }

    public function test_queues_recalculation_when_future_plan_exists_and_delta_is_significant(): void {
        $db = new WorkoutPlanUpdateFakeDb();
        $db->whenPrepareContains(
            'SELECT COUNT(*) AS cnt',
            fn() => new WorkoutPlanUpdateFakeStmt([['cnt' => 8]])
        );
        $db->whenQueryContains("SHOW TABLES LIKE 'plan_generation_jobs'", new WorkoutPlanUpdateFakeResult([['plan_generation_jobs' => 'plan_generation_jobs']]));
        $db->whenPrepareContains(
            'UPDATE user_training_plans SET is_active = FALSE',
            fn() => new WorkoutPlanUpdateFakeStmt()
        );
        $db->whenPrepareContains(
            'SELECT id, status FROM plan_generation_jobs',
            fn() => new WorkoutPlanUpdateFakeStmt([])
        );
        $db->whenPrepareContains(
            'INSERT INTO plan_generation_jobs',
            fn($sql, $fakeDb) => new WorkoutPlanUpdateFakeStmt([], function () use ($fakeDb) {
                $fakeDb->insert_id = 91;
                return true;
            })
        );

        $service = new WorkoutPlanRecalculationService($db);
        $result = $service->maybeQueueAfterPerformanceUpdate(42, 'control', '2026-03-10', 47.0, 48.5);

        $this->assertTrue($result['queued']);
        $this->assertSame(91, $result['job_id']);
        $this->assertFalse($result['deduplicated']);
    }
}
