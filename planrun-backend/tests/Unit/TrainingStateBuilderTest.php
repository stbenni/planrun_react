<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrainingStateBuilder;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/TrainingStateBuilder.php';
require_once __DIR__ . '/../../services/PostWorkoutFollowupService.php';
require_once __DIR__ . '/../../repositories/ChatRepository.php';

class TrainingStateBuilderTest extends TestCase {
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

    public function test_buildForUser_derives_special_population_flags_and_preferred_long_day(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'health',
            'birth_year' => 1955,
            'health_notes' => 'После травмы колена, хроническая гипертония',
            'preferred_days' => ['mon', 'wed', 'sun'],
            'preferred_ofp_days' => ['tue'],
            'sessions_per_week' => 3,
            'weekly_base_km' => 20,
            'easy_pace_sec' => 390,
        ]);

        $this->assertSame('health', $state['goal_type']);
        $this->assertSame('sun', $state['preferred_long_day']);
        $this->assertContains('older_adult_65_plus', $state['special_population_flags']);
        $this->assertContains('return_after_injury', $state['special_population_flags']);
        $this->assertContains('chronic_condition_flag', $state['special_population_flags']);
        $this->assertContains('low_confidence_vdot', $state['special_population_flags']);
        $this->assertSame('conservative', $state['return_to_run_state']);
        $this->assertSame(1.08, $state['load_policy']['allowed_growth_ratio']);
        $this->assertIsArray($state['load_policy']['weekly_volume_targets_km']);
    }

    public function test_buildForUser_uses_conservative_repair_profile_for_low_base_first_long_race(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => 'half',
            'sessions_per_week' => 4,
            'weekly_base_km' => 12,
            'experience_level' => 'beginner',
            'is_first_race_at_distance' => 1,
        ]);

        $this->assertSame('low', $state['readiness']);
        $this->assertSame('conservative', $state['load_policy']['repair_floor_profile']);
        $this->assertSame(0.67, $state['load_policy']['long_floor_ratio']);
        $this->assertSame(0.55, $state['load_policy']['complex_floor_ratio']);
        $this->assertSame(1.5, $state['load_policy']['easy_min_km']);
        $this->assertFalse((bool) ($state['load_policy']['protect_low_base_novice'] ?? false));
    }

    public function test_buildForUser_enables_low_base_novice_short_race_protections(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'sessions_per_week' => 4,
            'weekly_base_km' => 3,
            'experience_level' => 'novice',
            'is_first_race_at_distance' => 1,
        ]);

        $this->assertSame('low', $state['readiness']);
        $this->assertTrue((bool) ($state['load_policy']['protect_low_base_novice'] ?? false));
        $this->assertSame(4, (int) ($state['load_policy']['quality_delay_weeks'] ?? 0));
        $this->assertSame(0.38, (float) ($state['load_policy']['quality_workout_share_cap'] ?? 0.0));
        $this->assertSame(3, (int) ($state['load_policy']['race_week_run_day_cap'] ?? 0));
    }

    public function test_buildForUser_uses_reason_benchmark_override_and_personal_easy_floor(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-05-03',
            'race_target_time' => '03:30:00',
            'sessions_per_week' => 6,
            'weekly_base_km' => 40,
            'experience_level' => 'intermediate',
            'planning_benchmark_distance' => 'half',
            'planning_benchmark_time' => '01:40:00',
            'planning_easy_min_km' => 10,
        ]);

        $this->assertSame('benchmark_override', $state['vdot_source']);
        $this->assertSame('high', $state['vdot_confidence']);
        $this->assertSame('normal', $state['readiness']);
        $this->assertSame(299, $state['goal_pace_sec']);
        $this->assertSame('4:59', $state['goal_pace']);
        $this->assertSame(10.0, $state['load_policy']['easy_build_min_km']);
        $this->assertSame(8.0, $state['load_policy']['easy_recovery_min_km']);
        $this->assertSame(8.0, $state['load_policy']['easy_taper_min_km']);
    }

    public function test_buildForUser_uses_recent_subjective_feedback_to_lower_readiness_and_add_pain_flag(): void {
        $userId = $this->createTestUser();
        $today = gmdate('Y-m-d');

        $insertWorkout = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 10.0, 55, NOW())"
        );
        $insertWorkout->bind_param('is', $userId, $today);
        $insertWorkout->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insertWorkout->close();

        $followupService = new \PostWorkoutFollowupService($this->db);
        $followupService->ensureSchema();
        $chatRepository = new \ChatRepository($this->db);
        $conversation = $chatRepository->getOrCreateConversation($userId, 'ai');
        $followupMessageId = $chatRepository->addMessage((int) $conversation['id'], 'ai', null, 'Как ощущения после тренировки?');
        $userMessageId = $chatRepository->addMessage((int) $conversation['id'], 'user', $userId, 'Было тяжело, появилась боль в икре и пульс оставался высоким');

        $insertFollowup = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, followup_message_id, response_message_id, status, classification, pain_flag, fatigue_flag, recovery_risk_score, due_at, sent_at, responded_at)
             VALUES (?, 'workout_log', ?, ?, ?, ?, 'completed', 'pain', 1, 1, 0.92, NOW(), NOW(), NOW())"
        );
        $insertFollowup->bind_param('iisii', $userId, $workoutLogId, $today, $followupMessageId, $userMessageId);
        $insertFollowup->execute();
        $insertFollowup->close();

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-01',
            'race_target_time' => '00:48:00',
            'sessions_per_week' => 4,
            'weekly_base_km' => 36,
            'experience_level' => 'intermediate',
            'planning_benchmark_distance' => '10k',
            'planning_benchmark_time' => '00:49:00',
        ]);

        $this->assertSame('low', $state['readiness']);
        $this->assertContains('recent_pain_signal', $state['special_population_flags']);
        $this->assertSame(1.05, $state['load_policy']['allowed_growth_ratio']);
        $this->assertSame(1, $state['feedback_analytics']['pain_count']);
        $this->assertSame('high', $state['feedback_analytics']['risk_level']);
    }

    public function test_buildForUser_uses_structured_load_spike_to_add_fatigue_flag_and_tighten_growth(): void {
        $userId = $this->createTestUser();
        $today = gmdate('Y-m-d');

        $insertWorkout = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 12.0, 67, NOW())"
        );
        $insertWorkout->bind_param('is', $userId, $today);
        $insertWorkout->execute();
        $insertWorkout->close();

        $followupService = new \PostWorkoutFollowupService($this->db);
        $followupService->ensureSchema();

        $this->insertCompletedFollowup($userId, 8101, $today, 'fatigue', 0, 1, 8, 8, 8, 8, 1, 0.66);
        $this->insertCompletedFollowup($userId, 8102, gmdate('Y-m-d', strtotime('-1 day')), 'fatigue', 0, 1, 8, 8, 8, 8, 1, 0.64);
        $this->insertCompletedFollowup($userId, 8103, gmdate('Y-m-d', strtotime('-2 day')), 'fatigue', 0, 1, 7, 8, 6, 8, 1, 0.60);
        $this->insertCompletedFollowup($userId, 8104, gmdate('Y-m-d', strtotime('-8 day')), 'good', 0, 0, 5, 4, 4, 4, 0, 0.20);
        $this->insertCompletedFollowup($userId, 8105, gmdate('Y-m-d', strtotime('-10 day')), 'good', 0, 0, 4, 4, 4, 4, 0, 0.18);

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-01',
            'race_target_time' => '00:48:00',
            'sessions_per_week' => 4,
            'weekly_base_km' => 36,
            'experience_level' => 'intermediate',
            'planning_benchmark_distance' => '10k',
            'planning_benchmark_time' => '00:49:00',
        ]);

        $this->assertSame('low', $state['readiness']);
        $this->assertContains('recent_fatigue_spike', $state['special_population_flags']);
        $this->assertSame(1.06, $state['load_policy']['allowed_growth_ratio']);
        $this->assertSame('fatigue_high', $state['load_policy']['feedback_guard_level']);
        $this->assertGreaterThan(0.75, (float) $state['feedback_analytics']['subjective_load_delta']);
        $this->assertGreaterThan(7.5, (float) $state['feedback_analytics']['recent_session_rpe_avg']);
    }

    public function test_buildForUser_does_not_double_penalize_single_moderate_feedback_via_overall_risk(): void {
        $userId = $this->createTestUser();
        $today = gmdate('Y-m-d');

        $this->insertWorkout($userId, 'running', $today . ' 07:00:00', $today . ' 08:00:00', 12.0, 67);
        $this->insertCompletedFollowup($userId, 9101, $today, 'fatigue', 0, 1, 6, 7, 6, 6, 0, 0.63);

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-05-03',
            'race_target_time' => '03:30:00',
            'sessions_per_week' => 6,
            'weekly_base_km' => 42,
            'experience_level' => 'intermediate',
            'planning_benchmark_distance' => 'half',
            'planning_benchmark_time' => '01:39:00',
        ]);

        $this->assertSame('high', $state['vdot_confidence']);
        $this->assertSame('normal', $state['readiness']);
        $this->assertSame('fatigue_moderate', $state['load_policy']['feedback_guard_level']);
        $this->assertSame(1.06, $state['load_policy']['allowed_growth_ratio']);
        $this->assertSame(0.63, (float) $state['athlete_signals']['overall_risk_score']);
        $this->assertSame(0.0, (float) $state['athlete_signals']['note_risk_score']);
    }

    public function test_buildForUser_uses_day_and_week_notes_as_additional_athlete_signals(): void {
        $userId = $this->createTestUser();
        $today = gmdate('Y-m-d');
        $weekStart = gmdate('Y-m-d', strtotime('monday this week'));

        $dayStmt = $this->db->prepare(
            "INSERT INTO plan_day_notes (user_id, author_id, date, content) VALUES (?, ?, ?, ?)"
        );
        $dayContent = 'Плохо спал и накопился стресс после рабочей недели.';
        $dayStmt->bind_param('iiss', $userId, $userId, $today, $dayContent);
        $dayStmt->execute();
        $dayStmt->close();

        $weekStmt = $this->db->prepare(
            "INSERT INTO plan_week_notes (user_id, author_id, week_start, content) VALUES (?, ?, ?, ?)"
        );
        $weekContent = 'Командировка и перелёт сбили режим восстановления.';
        $weekStmt->bind_param('iiss', $userId, $userId, $weekStart, $weekContent);
        $weekStmt->execute();
        $weekStmt->close();

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-01',
            'race_target_time' => '00:48:00',
            'sessions_per_week' => 4,
            'weekly_base_km' => 36,
            'experience_level' => 'intermediate',
            'planning_benchmark_distance' => '10k',
            'planning_benchmark_time' => '00:49:00',
        ]);

        $this->assertContains('recent_sleep_signal', $state['special_population_flags']);
        $this->assertContains('recent_stress_signal', $state['special_population_flags']);
        $this->assertContains('recent_travel_signal', $state['special_population_flags']);
        $this->assertSame('fatigue_moderate', $state['load_policy']['feedback_guard_level']);
        $this->assertSame(1, (int) ($state['athlete_signals']['note_sleep_count'] ?? 0));
        $this->assertSame(1, (int) ($state['athlete_signals']['note_travel_count'] ?? 0));
    }

    public function test_buildForUser_reduces_effective_weekly_base_after_month_break(): void {
        $userId = $this->createTestUser();
        $lastWorkoutDate = gmdate('Y-m-d', strtotime('-35 days'));

        $this->insertWorkout($userId, 'running', $lastWorkoutDate . ' 07:00:00', $lastWorkoutDate . ' 08:00:00', 10.0, 60);

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-14',
            'race_target_time' => '00:45:00',
            'sessions_per_week' => 5,
            'weekly_base_km' => 40,
            'experience_level' => 'intermediate',
            'last_race_distance' => '10k',
            'last_race_time' => '00:42:30',
            'last_race_date' => '2026-01-20',
        ]);

        $this->assertSame(35, $state['days_since_last_workout']);
        $this->assertSame('low', $state['readiness']);
        $this->assertSame(40.0, $state['reported_weekly_base_km']);
        $this->assertSame(20.0, $state['weekly_base_km']);
        $this->assertContains('return_after_break', $state['special_population_flags']);
    }

    private function createTestUser(): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'feedback_state_' . $suffix;
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

    private function insertWorkout(
        int $userId,
        string $activityType,
        string $startTime,
        string $endTime,
        float $distanceKm,
        int $durationMinutes
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO workouts (user_id, activity_type, start_time, end_time, distance_km, duration_minutes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssdi', $userId, $activityType, $startTime, $endTime, $distanceKm, $durationMinutes);
        $stmt->execute();
        $stmt->close();
    }
}
