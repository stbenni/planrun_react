<?php
/**
 * Тесты для очереди генерации плана без реальной БД.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlanGenerationQueueService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanGenerationQueueService.php';

class QueueFakeResult {
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

class QueueFakeStmt {
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

    public function get_result(): QueueFakeResult {
        return new QueueFakeResult($this->rows);
    }

    public function close(): void {
    }
}

class QueueFakeDb {
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

        return new QueueFakeResult([]);
    }
}

class PlanGenerationQueueServiceTest extends TestCase {
    public function test_enqueue_deduplicates_existing_active_job(): void {
        $db = new QueueFakeDb();
        $db->whenQueryContains("SHOW TABLES LIKE 'plan_generation_jobs'", new QueueFakeResult([['plan_generation_jobs' => 'plan_generation_jobs']]));
        $db->whenPrepareContains(
            'SELECT id, status FROM plan_generation_jobs',
            fn() => new QueueFakeStmt([['id' => 15, 'status' => 'pending']])
        );

        $service = new PlanGenerationQueueService($db);
        $result = $service->enqueue(42, 'generate');

        $this->assertTrue($result['queued']);
        $this->assertTrue($result['deduplicated']);
        $this->assertSame(15, $result['job_id']);
        $this->assertSame('pending', $result['status']);
    }

    public function test_enqueue_creates_new_job_when_no_active_job_exists(): void {
        $db = new QueueFakeDb();
        $db->whenQueryContains("SHOW TABLES LIKE 'plan_generation_jobs'", new QueueFakeResult([['plan_generation_jobs' => 'plan_generation_jobs']]));
        $db->whenPrepareContains(
            'SELECT id, status FROM plan_generation_jobs',
            fn() => new QueueFakeStmt([])
        );
        $db->whenPrepareContains(
            'INSERT INTO plan_generation_jobs',
            fn($sql, $fakeDb) => new QueueFakeStmt([], function () use ($fakeDb) {
                $fakeDb->insert_id = 77;
                return true;
            })
        );

        $service = new PlanGenerationQueueService($db);
        $result = $service->enqueue(42, 'recalculate', ['reason' => 'fatigue']);

        $this->assertTrue($result['queued']);
        $this->assertFalse($result['deduplicated']);
        $this->assertSame(77, $result['job_id']);
        $this->assertSame('pending', $result['status']);
    }

    public function test_findLatestJobForUser_returns_latest_job(): void {
        $db = new QueueFakeDb();
        $db->whenPrepareContains(
            'SELECT * FROM plan_generation_jobs WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            fn() => new QueueFakeStmt([['id' => 99, 'status' => 'completed', 'job_type' => 'recalculate']])
        );

        $service = new PlanGenerationQueueService($db);
        $job = $service->findLatestJobForUser(42);

        $this->assertNotNull($job);
        $this->assertSame(99, (int) $job['id']);
        $this->assertSame('completed', $job['status']);
    }
}
