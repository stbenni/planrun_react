<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/ProactiveCoachService.php';

class ProactiveCoachServiceTest extends TestCase {

    private $db;
    private \ProactiveCoachService $service;
    private int $testUserId = 999991;

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->service = new \ProactiveCoachService($this->db);
        $this->ensureCooldownTable();
        $this->clearCooldownLog();
    }

    protected function tearDown(): void {
        $this->clearCooldownLog();
        parent::tearDown();
    }

    public function test_pickNextAvailableEvent_skips_highest_priority_event_on_cooldown(): void {
        $this->insertCooldown('overload');

        $event = $this->invokePickNextAvailableEvent([
            ['type' => 'pause', 'priority' => 3, 'data' => []],
            ['type' => 'overload', 'priority' => 5, 'data' => []],
            ['type' => 'low_compliance', 'priority' => 2, 'data' => []],
        ]);

        $this->assertIsArray($event);
        $this->assertSame('pause', $event['type']);
    }

    public function test_pickNextAvailableEvent_returns_null_when_all_events_are_on_cooldown(): void {
        $this->insertCooldown('overload');
        $this->insertCooldown('pause');

        $event = $this->invokePickNextAvailableEvent([
            ['type' => 'pause', 'priority' => 3, 'data' => []],
            ['type' => 'overload', 'priority' => 5, 'data' => []],
        ]);

        $this->assertNull($event);
    }

    private function invokePickNextAvailableEvent(array $events): ?array {
        $ref = new \ReflectionClass($this->service);
        $method = $ref->getMethod('pickNextAvailableEvent');
        $method->setAccessible(true);
        return $method->invoke($this->service, $this->testUserId, $events);
    }

    private function ensureCooldownTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS proactive_coach_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_event (user_id, event_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->query($sql);
    }

    private function clearCooldownLog(): void {
        $stmt = $this->db->prepare("DELETE FROM proactive_coach_log WHERE user_id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $this->testUserId);
        $stmt->execute();
        $stmt->close();
    }

    private function insertCooldown(string $eventType): void {
        $stmt = $this->db->prepare("INSERT INTO proactive_coach_log (user_id, event_type) VALUES (?, ?)");
        $stmt->bind_param('is', $this->testUserId, $eventType);
        $stmt->execute();
        $stmt->close();
    }
}
