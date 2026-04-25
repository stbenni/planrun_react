<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../load_training_plan.php';

class LoadTrainingPlanCacheTest extends TestCase {
    private \mysqli $db;
    private array $cacheKeys = [];

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->db->begin_transaction();
    }

    protected function tearDown(): void {
        foreach ($this->cacheKeys as $key) {
            \Cache::delete($key);
        }
        $this->db->rollback();
        parent::tearDown();
    }

    public function test_empty_plan_is_not_cached(): void {
        $userId = $this->createTestUser();
        $cacheKey = $this->trackCacheKey($userId);
        \Cache::delete($cacheKey);

        $plan = \loadTrainingPlanForUser($userId, true);

        $this->assertSame([], $plan['weeks_data']);
        $this->assertNull(\Cache::get($cacheKey));
    }

    public function test_stale_empty_cache_is_ignored_when_plan_exists(): void {
        $userId = $this->createTestUser();
        $cacheKey = $this->trackCacheKey($userId);
        \Cache::set($cacheKey, ['weeks_data' => []], 900);
        $this->insertPlanWeek($userId);

        $plan = \loadTrainingPlanForUser($userId, true);

        $this->assertCount(1, $plan['weeks_data']);
        $this->assertSame(1, (int) $plan['weeks_data'][0]['number']);
        $this->assertNotEmpty($plan['weeks_data'][0]['days']['mon']);
    }

    private function trackCacheKey(int $userId): string {
        $key = "training_plan_{$userId}";
        $this->cacheKeys[] = $key;
        return $key;
    }

    private function createTestUser(): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'load_cache_' . $suffix;
        $password = password_hash('secret123', PASSWORD_DEFAULT);
        $email = $username . '@example.com';
        $onboardingCompleted = 1;
        $trainingMode = 'ai';
        $goalType = 'race';
        $gender = 'female';

        $stmt = $this->db->prepare(
            'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssisss', $username, $username, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        $stmt->close();

        return $userId;
    }

    private function insertPlanWeek(int $userId): void {
        $weekNumber = 1;
        $startDate = '2026-04-20';
        $totalVolume = 12.5;
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iisd', $userId, $weekNumber, $startDate, $totalVolume);
        $stmt->execute();
        $weekId = (int) $this->db->insert_id;
        $stmt->close();

        $dayOfWeek = 1;
        $type = 'easy';
        $description = 'Easy run 5 km';
        $isKeyWorkout = 0;
        $date = '2026-04-20';
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_days (user_id, week_id, day_of_week, type, description, is_key_workout, date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiissis', $userId, $weekId, $dayOfWeek, $type, $description, $isKeyWorkout, $date);
        $stmt->execute();
        $stmt->close();
    }
}
