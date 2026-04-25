<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/AthleteSignalsService.php';

class AthleteSignalsServiceTest extends TestCase {
    private \mysqli $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->db->begin_transaction();
    }

    protected function tearDown(): void {
        $this->db->rollback();
        parent::tearDown();
    }

    public function test_getSignalsBetween_combines_feedback_with_day_and_week_note_signals(): void {
        $userId = $this->createTestUser();
        $today = gmdate('Y-m-d');
        $weekStart = gmdate('Y-m-d', strtotime('monday this week'));

        $this->insertCompletedFollowup($userId, 9101, $today, 'fatigue', 0, 1, 8, 8, 7, 7, 1, 0.66);

        $dayNote = $this->db->prepare(
            "INSERT INTO plan_day_notes (user_id, author_id, date, content) VALUES (?, ?, ?, ?)"
        );
        $dayContent = 'Плохо спал и чувствуется стресс после рабочей недели, ноги тяжёлые.';
        $dayNote->bind_param('iiss', $userId, $userId, $today, $dayContent);
        $dayNote->execute();
        $dayNote->close();

        $weekNote = $this->db->prepare(
            "INSERT INTO plan_week_notes (user_id, author_id, week_start, content) VALUES (?, ?, ?, ?)"
        );
        $weekContent = 'На неделе была командировка и перелёт, поэтому режим восстановления сбился.';
        $weekNote->bind_param('iiss', $userId, $userId, $weekStart, $weekContent);
        $weekNote->execute();
        $weekNote->close();

        $signals = (new \AthleteSignalsService($this->db))->getSignalsBetween($userId, $weekStart, $today);

        $this->assertSame(1, (int) ($signals['feedback']['total_responses'] ?? 0));
        $this->assertSame(1, (int) $signals['day_notes_count']);
        $this->assertSame(1, (int) $signals['week_notes_count']);
        $this->assertSame(1, (int) $signals['note_sleep_count']);
        $this->assertSame(1, (int) $signals['note_stress_count']);
        $this->assertSame(1, (int) $signals['note_travel_count']);
        $this->assertContains('sleep_guard', $signals['planning_biases']);
        $this->assertContains('stress_guard', $signals['planning_biases']);
        $this->assertContains('travel_guard', $signals['planning_biases']);
        $this->assertNotSame('low', (string) $signals['overall_risk_level']);
        $this->assertNotEmpty($signals['recent_note_excerpts']);
        $this->assertStringContainsString('sleep=1', (string) $signals['prompt_summary']);
    }

    private function createTestUser(): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'athlete_signals_' . $suffix;
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

    private function insertCompletedFollowup(
        int $userId,
        int $sourceId,
        string $workoutDate,
        string $classification,
        int $painFlag,
        int $fatigueFlag,
        int $sessionRpe,
        int $legsScore,
        int $breathScore,
        int $hrStrainScore,
        int $painScore,
        float $riskScore
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, classification, pain_flag, fatigue_flag, session_rpe, legs_score, breath_score, hr_strain_score, pain_score, recovery_risk_score, status, due_at, sent_at, responded_at)
             VALUES (?, 'workout_log', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW(), NOW())"
        );
        $stmt->bind_param(
            'iissiiiiiiid',
            $userId,
            $sourceId,
            $workoutDate,
            $classification,
            $painFlag,
            $fatigueFlag,
            $sessionRpe,
            $legsScore,
            $breathScore,
            $hrStrainScore,
            $painScore,
            $riskScore
        );
        $stmt->execute();
        $stmt->close();
    }
}
