<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanReadinessCheckService.php';
require_once __DIR__ . '/../../services/PostWorkoutFollowupService.php';

class PlanReadinessCheckServiceTest extends TestCase {
    private \mysqli $db;
    private \PlanReadinessCheckService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->service = new \PlanReadinessCheckService($this->db);
        $this->service->ensureSchema();
        (new \PostWorkoutFollowupService($this->db))->ensureSchema();
        $this->db->begin_transaction();
    }

    protected function tearDown(): void {
        $this->db->rollback();
        parent::tearDown();
    }

    public function test_maybeCreatePendingCheck_asks_after_stale_pain_and_later_runs(): void {
        $userId = $this->createTestUser();
        $painDate = gmdate('Y-m-d', strtotime('-13 days'));
        $runDate = gmdate('Y-m-d', strtotime('-2 days'));

        $this->insertCompletedPainFollowup($userId, 9401, $painDate);
        $this->insertWorkout($userId, $runDate, 11.2);

        $check = $this->service->maybeCreatePendingCheck($userId, 'recalculate', ['reason' => 'Изменились цели']);

        $this->assertIsArray($check);
        $this->assertSame('pending', $check['status']);
        $this->assertSame('stale_pain_signal', $check['check_type']);
        $this->assertSame($painDate, $check['source']['date']);
        $this->assertSame(1, $check['source']['subsequent_run_count']);

        $sameCheck = $this->service->maybeCreatePendingCheck($userId, 'recalculate');
        $this->assertSame($check['id'], $sameCheck['id']);
    }

    public function test_submitAnswer_makes_same_source_no_longer_block_generation(): void {
        $userId = $this->createTestUser();
        $painDate = gmdate('Y-m-d', strtotime('-12 days'));
        $runDate = gmdate('Y-m-d', strtotime('-1 day'));

        $this->insertCompletedPainFollowup($userId, 9501, $painDate);
        $this->insertWorkout($userId, $runDate, 9.5);

        $check = $this->service->maybeCreatePendingCheck($userId, 'next_plan');
        $this->assertIsArray($check);

        $result = $this->service->submitAnswer($userId, (int) $check['id'], [
            'current_pain_score' => 1,
            'pain_worsened_after_runs' => false,
            'technique_changed' => false,
            'answer_text' => 'Боль 1/10, техника обычная.',
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame('clear', $result['interpretation']);
        $this->assertTrue($result['can_generate_more_effective_plan']);
        $this->assertNull($this->service->maybeCreatePendingCheck($userId, 'next_plan'));
    }

    public function test_maybeCreatePendingCheck_does_not_ask_without_later_run(): void {
        $userId = $this->createTestUser();
        $painDate = gmdate('Y-m-d', strtotime('-13 days'));

        $this->insertCompletedPainFollowup($userId, 9601, $painDate);

        $this->assertNull($this->service->maybeCreatePendingCheck($userId, 'recalculate'));
    }

    private function createTestUser(): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'plan_readiness_' . $suffix;
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
        $stmt->bind_param('ssssisss', $username, $username, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        $stmt->close();
        return $userId;
    }

    private function insertCompletedPainFollowup(int $userId, int $sourceId, string $workoutDate): void {
        $stmt = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, classification, pain_flag, fatigue_flag,
                 session_rpe, legs_score, breath_score, hr_strain_score, pain_score, recovery_risk_score,
                 status, due_at, sent_at, responded_at)
             VALUES (?, 'workout_log', ?, ?, 'pain', 1, 0, 5, 6, 6, 6, 2, 0.84, 'completed', NOW(), NOW(), NOW())"
        );
        $stmt->bind_param('iis', $userId, $sourceId, $workoutDate);
        $stmt->execute();
        $stmt->close();
    }

    private function insertWorkout(int $userId, string $date, float $distanceKm): void {
        $start = $date . ' 07:00:00';
        $end = $date . ' 08:00:00';
        $duration = 60;
        $stmt = $this->db->prepare(
            'INSERT INTO workouts (user_id, activity_type, start_time, end_time, distance_km, duration_minutes)
             VALUES (?, "running", ?, ?, ?, ?)'
        );
        $stmt->bind_param('issdi', $userId, $start, $end, $distanceKm, $duration);
        $stmt->execute();
        $stmt->close();
    }
}
