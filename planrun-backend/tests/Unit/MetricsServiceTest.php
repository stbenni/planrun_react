<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use MetricsService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/MetricsService.php';

class MetricsServiceTest extends TestCase {
    private \mysqli $db;
    private MetricsService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->db->begin_transaction();
        $this->service = new MetricsService($this->db);
    }

    protected function tearDown(): void {
        $this->db->rollback();
        parent::tearDown();
    }

    public function test_calculateACWR_ignores_walking_load_for_running_overload_signal(): void {
        $userId = $this->createTestUser();

        $this->insertWorkout($userId, 'walking', gmdate('Y-m-d 07:00:00', strtotime('-1 day')), gmdate('Y-m-d 08:00:00', strtotime('-1 day')), 20.0, 180);
        $this->insertWorkout($userId, 'running', gmdate('Y-m-d 07:00:00', strtotime('-10 day')), gmdate('Y-m-d 08:00:00', strtotime('-10 day')), 10.0, 60);
        $this->insertWorkout($userId, 'running', gmdate('Y-m-d 07:00:00', strtotime('-17 day')), gmdate('Y-m-d 08:00:00', strtotime('-17 day')), 10.0, 60);
        $this->insertWorkout($userId, 'running', gmdate('Y-m-d 07:00:00', strtotime('-24 day')), gmdate('Y-m-d 08:00:00', strtotime('-24 day')), 10.0, 60);

        $acwr = $this->service->calculateACWR($userId);

        $this->assertSame(0.0, (float) $acwr['acute']);
        $this->assertSame(45.0, (float) $acwr['chronic']);
        $this->assertSame(0.0, (float) $acwr['acwr']);
        $this->assertSame('low', $acwr['zone']);
    }

    private function createTestUser(): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'metrics_' . $suffix;
        $slug = $username;
        $email = $username . '@example.com';
        $password = password_hash('secret123', PASSWORD_DEFAULT);
        $trainingMode = 'self';
        $goalType = 'race';
        $gender = 'male';
        $onboardingCompleted = 1;

        $stmt = $this->db->prepare(
            'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssisss', $username, $slug, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        $stmt->close();

        return $userId;
    }

    private function insertWorkout(
        int $userId,
        string $activityType,
        string $startTime,
        string $endTime,
        float $distanceKm,
        int $durationMinutes
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO workouts (user_id, activity_type, start_time, end_time, duration_minutes, distance_km)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssid', $userId, $activityType, $startTime, $endTime, $durationMinutes, $distanceKm);
        $stmt->execute();
        $stmt->close();
    }
}
