<?php
/**
 * Smoke-тесты CoachEventsService.
 *
 * Проверяет helper-форматирование и базовый шейп ответа getEvents.
 * Полная проверка SQL-агрегации — интеграционные тесты с реальной БД.
 */

namespace Tests\Unit\Coach;

use PHPUnit\Framework\TestCase;
use CoachEventsService;
use ReflectionClass;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/CoachEventsService.php';

class CoachEventsFakeResult {
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

class CoachEventsFakeStmt {
    public function __construct(private array $rows = []) {}
    public function bind_param(...$args): bool { return true; }
    public function execute(): bool { return true; }
    public function get_result(): CoachEventsFakeResult { return new CoachEventsFakeResult($this->rows); }
    public function close(): void {}
}

class CoachEventsFakeDb {
    public function prepare(string $sql) {
        // Все запросы возвращают пустой результат — getEvents() → events: []
        return new CoachEventsFakeStmt([]);
    }
}

class CoachEventsServiceTest extends TestCase {

    public function test_getEvents_returns_empty_array_when_no_data(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $result = $service->getEvents(1, 48);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('events', $result);
        $this->assertSame([], $result['events']);
    }

    public function test_formatPrTime_under_hour(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'formatPrTime');

        $this->assertSame('22:14', $method->invoke($service, 22 * 60 + 14));
        $this->assertSame('0:42', $method->invoke($service, 42));
    }

    public function test_formatPrTime_over_hour(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'formatPrTime');

        $this->assertSame('1:35:42', $method->invoke($service, 3600 + 35 * 60 + 42));
        $this->assertSame('3:20:50', $method->invoke($service, 3 * 3600 + 20 * 60 + 50));
    }

    public function test_formatPrTime_zero_or_negative(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'formatPrTime');

        $this->assertSame('—', $method->invoke($service, 0));
        $this->assertSame('—', $method->invoke($service, -10));
    }

    public function test_prDistanceLabel_maps_known_keys(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'prDistanceLabel');

        $this->assertSame('5 км', $method->invoke($service, '5k'));
        $this->assertSame('10 км', $method->invoke($service, '10k'));
        $this->assertSame('Полумарафон', $method->invoke($service, 'half'));
        $this->assertSame('Марафон', $method->invoke($service, 'marathon'));
        // Unknown — passthrough
        $this->assertSame('unknown', $method->invoke($service, 'unknown'));
    }

    public function test_activityTypeLabel_known_and_default(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'activityTypeLabel');

        $this->assertSame('Лёгкий бег', $method->invoke($service, 'easy'));
        $this->assertSame('Темповая', $method->invoke($service, 'tempo'));
        $this->assertSame('Бег', $method->invoke($service, 'running'));
        $this->assertSame('Тренировка', $method->invoke($service, 'unknown-type'));
    }

    public function test_formatKm_compact(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'formatKm');

        $this->assertSame('5', $method->invoke($service, 5.0));
        $this->assertSame('8.2', $method->invoke($service, 8.2));
        $this->assertSame('21.1', $method->invoke($service, 21.0975));
        // ≥100 — округляем до целого
        $this->assertSame('123', $method->invoke($service, 123.45));
    }

    public function test_formatUploadDetail_combines_parts(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'formatUploadDetail');

        $r = $method->invoke($service, 8.2, '4:18', 42, 165);
        $this->assertStringContainsString('42 мин', $r);
        $this->assertStringContainsString('4:18 /км', $r);
        $this->assertStringContainsString('ЧСС 165', $r);

        $r2 = $method->invoke($service, null, null, 0, null);
        $this->assertSame('', $r2);
    }

    public function test_collectPRs_returns_empty_when_no_athletes(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'collectPRs');

        $result = $method->invoke($service, /*coachId*/ 1, /*hoursBack*/ 168);
        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function test_formatPrTime_boundary_values(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'formatPrTime');

        $this->assertSame('1:00', $method->invoke($service, 60));
        $this->assertSame('1:00:00', $method->invoke($service, 3600));
        $this->assertSame('59:59', $method->invoke($service, 3599));
    }

    public function test_formatKm_does_not_show_trailing_zero(): void {
        $service = new CoachEventsService(new CoachEventsFakeDb());
        $method = $this->method($service, 'formatKm');

        $this->assertSame('10', $method->invoke($service, 10.0));
        $this->assertSame('10.5', $method->invoke($service, 10.5));
        $this->assertSame('10', $method->invoke($service, 10.00));
    }

    private function method($obj, string $name): \ReflectionMethod {
        $ref = new ReflectionClass($obj);
        $m = $ref->getMethod($name);
        $m->setAccessible(true);
        return $m;
    }
}
