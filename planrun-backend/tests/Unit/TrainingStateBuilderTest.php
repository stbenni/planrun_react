<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrainingStateBuilder;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/TrainingStateBuilder.php';
require_once __DIR__ . '/../../services/PostWorkoutFollowupService.php';
require_once __DIR__ . '/../../services/PlanReadinessCheckService.php';
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

    public function test_buildForUser_populates_planning_scenario_for_b_race_before_a_race(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-07-04',
            'race_target_time' => '03:30:00',
            'sessions_per_week' => 5,
            'weekly_base_km' => 60,
            'experience_level' => 'intermediate',
            'preferred_days' => ['mon', 'wed', 'fri', 'sat', 'sun'],
            'training_start_date' => '2026-04-13',
        ], 'generate', [
            'tune_up_event' => [
                'date' => '2026-06-28',
                'distance' => 'half',
                'type' => 'race',
            ],
        ]);

        $this->assertIsArray($state['planning_scenario'] ?? null);
        $this->assertContains('b_race_before_a_race', (array) ($state['planning_scenario']['flags'] ?? []));
        $this->assertSame('b_race_before_a_race', $state['planning_scenario']['primary'] ?? null);
        $this->assertIsArray($state['planning_scenario']['tune_up_event'] ?? null);
        $this->assertSame('2026-06-28', $state['planning_scenario']['tune_up_event']['date'] ?? null);
    }

    public function test_buildForUser_populates_goal_realism_for_unrealistic_marathon_target(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-07-04',
            'race_target_time' => '02:30:00',
            'sessions_per_week' => 3,
            'weekly_base_km' => 18,
            'experience_level' => 'novice',
            'easy_pace_sec' => 360,
            'training_start_date' => '2026-05-04',
        ]);

        $this->assertIsArray($state['goal_realism'] ?? null);
        $this->assertSame('major', $state['goal_realism']['severity'] ?? null);
        $this->assertSame('unrealistic', $state['goal_realism']['verdict'] ?? null);
        $this->assertNotEmpty($state['goal_realism']['issues'] ?? []);
    }

    public function test_buildForUser_omits_goal_realism_for_health_goal(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'health',
            'sessions_per_week' => 3,
            'weekly_base_km' => 15,
            'experience_level' => 'intermediate',
        ]);

        $this->assertArrayHasKey('goal_realism', $state);
        $this->assertNull($state['goal_realism']);
    }

    /**
     * PR9: pace_strategy для major-severity цели должна готовить к predicted_target_time
     * (не к недостижимой цели), но goal_paces всё равно вычисляются от effective target,
     * чтобы темпы tempo/interval подтягивали к реалистичной цели.
     */
    public function test_buildForUser_pace_strategy_falls_back_to_realistic_target_for_major_severity(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-07-04',
            'race_target_time' => '02:30:00',
            'sessions_per_week' => 3,
            'weekly_base_km' => 18,
            'experience_level' => 'novice',
            'easy_pace_sec' => 360,
            'training_start_date' => '2026-05-04',
        ]);

        $this->assertIsArray($state['pace_strategy'] ?? null);
        $this->assertSame('realistic_target', $state['pace_strategy']['mode']);
        $this->assertSame('major', $state['pace_strategy']['severity']);
        $this->assertNotNull($state['pace_strategy']['predicted_target_time']);
        $this->assertNotNull($state['pace_strategy']['effective_target_time']);
        $this->assertSame(
            $state['pace_strategy']['predicted_target_time'],
            $state['pace_strategy']['effective_target_time'],
            'major severity → effective = predicted'
        );
        $this->assertSame('2:30:00', $state['pace_strategy']['goal_target_time']);
        $this->assertIsArray($state['pace_strategy']['goal_paces'] ?? null);
        $this->assertArrayHasKey('threshold', $state['pace_strategy']['goal_paces']);
        $this->assertArrayHasKey('marathon', $state['pace_strategy']['goal_paces']);
        $this->assertGreaterThan(0, (float) ($state['pace_strategy']['gap_pct'] ?? 0));
    }

    /**
     * PR9: pace_strategy для realistic-цели → mode=goal_target, готовим к самой цели,
     * gap_pct близок к нулю или отрицательный.
     */
    public function test_buildForUser_pace_strategy_uses_goal_target_for_realistic_severity(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-09-01',
            'race_target_time' => '04:00:00',
            'sessions_per_week' => 4,
            'weekly_base_km' => 40,
            'experience_level' => 'intermediate',
            'easy_pace_sec' => 360,
            'training_start_date' => '2026-05-04',
        ]);

        $this->assertIsArray($state['pace_strategy'] ?? null);
        $this->assertSame('goal_target', $state['pace_strategy']['mode']);
        $this->assertSame('4:00:00', $state['pace_strategy']['goal_target_time']);
        $this->assertSame(
            $state['pace_strategy']['goal_target_time'],
            $state['pace_strategy']['effective_target_time'],
            'non-major severity → effective = goal'
        );
    }

    /**
     * PR9: для health/regular_running pace_strategy не выставляется (goal_type не race).
     */
    public function test_buildForUser_pace_strategy_omitted_for_health_goal(): void {
        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'goal_type' => 'health',
            'sessions_per_week' => 3,
            'weekly_base_km' => 15,
            'experience_level' => 'intermediate',
        ]);

        $this->assertArrayNotHasKey('pace_strategy', $state);
    }

    public function test_buildForUser_skips_scenario_fields_when_feature_flag_disabled(): void {
        $previous = getenv('PLANRUN_AI_STATE_SCENARIO');
        putenv('PLANRUN_AI_STATE_SCENARIO=0');
        $_ENV['PLANRUN_AI_STATE_SCENARIO'] = '0';

        try {
            $builder = new TrainingStateBuilder($this->db);
            $state = $builder->buildForUser([
                'goal_type' => 'race',
                'race_distance' => 'marathon',
                'race_date' => '2026-07-04',
                'race_target_time' => '03:30:00',
                'sessions_per_week' => 5,
                'weekly_base_km' => 60,
                'training_start_date' => '2026-04-13',
            ]);

            $this->assertArrayNotHasKey('planning_scenario', $state);
            $this->assertArrayNotHasKey('goal_realism', $state);
        } finally {
            if ($previous === false) {
                putenv('PLANRUN_AI_STATE_SCENARIO');
                unset($_ENV['PLANRUN_AI_STATE_SCENARIO']);
            } else {
                putenv('PLANRUN_AI_STATE_SCENARIO=' . $previous);
                $_ENV['PLANRUN_AI_STATE_SCENARIO'] = $previous;
            }
        }
    }

    public function test_buildForUser_uses_clear_plan_readiness_check_to_unblock_stale_pain_signal(): void {
        $userId = $this->createTestUser();
        $painDate = gmdate('Y-m-d', strtotime('-13 days'));
        $recentRunDate = gmdate('Y-m-d', strtotime('-2 days'));

        (new \PostWorkoutFollowupService($this->db))->ensureSchema();
        $this->insertCompletedFollowup($userId, 9301, $painDate, 'pain', 1, 0, 5, 6, 6, 6, 2, 0.84);
        $this->insertWorkout($userId, 'running', $recentRunDate . ' 07:00:00', $recentRunDate . ' 08:00:00', 12.0, 60);

        $readinessService = new \PlanReadinessCheckService($this->db);
        $check = $readinessService->maybeCreatePendingCheck($userId, 'recalculate');
        $this->assertIsArray($check);
        $this->assertSame('stale_pain_signal', $check['check_type']);

        $answer = $readinessService->submitAnswer($userId, (int) $check['id'], [
            'current_pain_score' => 0,
            'pain_worsened_after_runs' => false,
            'technique_changed' => false,
            'answer_text' => 'Сейчас боли нет, последние пробежки прошли нормально.',
        ]);
        $this->assertSame('clear', $answer['interpretation']);

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-07-04',
            'race_target_time' => '03:30:00',
            'sessions_per_week' => 4,
            'weekly_base_km' => 80,
            'experience_level' => 'intermediate',
            'planning_benchmark_distance' => 'half',
            'planning_benchmark_time' => '01:38:00',
        ]);

        $this->assertSame('high', $state['readiness']);
        $this->assertNotContains('recent_pain_signal', $state['special_population_flags']);
        $this->assertSame('neutral', $state['load_policy']['feedback_guard_level']);
        $this->assertFalse((bool) $state['feedback_analytics']['has_recent_pain']);
        $this->assertSame('clear', $state['plan_readiness_check']['interpretation']);
    }

    public function test_buildForUser_includes_recent_compliance_for_completed_workouts(): void {
        $userId = $this->createTestUser();

        // Используем прошлую полностью завершённую неделю (Mon..Sun), чтобы не зависеть от дня запуска теста.
        $thisMonday = new \DateTimeImmutable('monday this week');
        $prevMonday = $thisMonday->modify('-1 week');
        $prevMondayStr = $prevMonday->format('Y-m-d');

        $weekId = $this->insertPlanWeek($userId, $prevMondayStr);
        $this->insertPlanDay($userId, $weekId, $prevMondayStr, 1, 'easy', 0);
        $this->insertPlanDay($userId, $weekId, $prevMonday->modify('+2 days')->format('Y-m-d'), 3, 'tempo', 1);
        $this->insertPlanDay($userId, $weekId, $prevMonday->modify('+4 days')->format('Y-m-d'), 5, 'easy', 0);
        $this->insertPlanDay($userId, $weekId, $prevMonday->modify('+5 days')->format('Y-m-d'), 6, 'long', 1);

        $this->insertCompletedLog($userId, $prevMondayStr, 1, 'mon', 8.0, 50);
        $this->insertCompletedLog($userId, $prevMonday->modify('+2 days')->format('Y-m-d'), 1, 'wed', 10.0, 55);

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => 'half',
            'sessions_per_week' => 4,
            'weekly_base_km' => 30,
            'experience_level' => 'intermediate',
        ]);

        $this->assertArrayHasKey('recent_compliance', $state);
        $this->assertIsArray($state['recent_compliance']);
        $this->assertNotEmpty($state['recent_compliance']);

        $byStart = [];
        foreach ($state['recent_compliance'] as $row) {
            $byStart[$row['week_start']] = $row;
        }
        $this->assertArrayHasKey($prevMondayStr, $byStart);

        $week = $byStart[$prevMondayStr];
        $this->assertFalse($week['is_current_week']);
        $this->assertSame(4, $week['planned_count']);
        $this->assertSame(2, $week['completed_count']);
        $this->assertSame(2, $week['skipped_count']);
        $this->assertSame(18.0, $week['actual_km']);
        $this->assertSame(2, $week['key_workout_planned']);
        $this->assertSame(1, $week['key_workout_completed']);
        $this->assertSame(0.5, $week['compliance_ratio']);
        $this->assertSame(0.5, $week['key_workout_completion_pct']);
    }

    public function test_buildForUser_includes_recent_workouts_detailed_with_rpe_hr_pace(): void {
        $userId = $this->createTestUser();

        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $threeDaysAgo = (new \DateTimeImmutable('-3 days'))->format('Y-m-d');

        // Лог с RPE (rating) и средним пульсом — для тестирования B.2.
        $this->insertCompletedLogWithRating($userId, $threeDaysAgo, 1, 'mon', 10.0, 56, 4, 145);
        $this->insertWorkout($userId, 'running', $yesterday . ' 07:00:00', $yesterday . ' 08:10:00', 12.0, 70);

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => 'half',
            'sessions_per_week' => 4,
            'weekly_base_km' => 35,
            'experience_level' => 'intermediate',
        ]);

        $this->assertArrayHasKey('recent_workouts_detailed', $state);
        $rows = $state['recent_workouts_detailed'];
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']] = $row;
        }

        $this->assertArrayHasKey($yesterday, $byDate);
        $this->assertSame(12.0, $byDate[$yesterday]['distance_km']);
        $this->assertSame(70, $byDate[$yesterday]['duration_minutes']);
        $this->assertSame(350, $byDate[$yesterday]['pace_sec']);
        $this->assertSame('5:50', $byDate[$yesterday]['pace']);

        $this->assertArrayHasKey($threeDaysAgo, $byDate);
        $this->assertSame(10.0, $byDate[$threeDaysAgo]['distance_km']);
        $this->assertSame(56, $byDate[$threeDaysAgo]['duration_minutes']);
        $this->assertSame(336, $byDate[$threeDaysAgo]['pace_sec']);
        $this->assertSame(4, $byDate[$threeDaysAgo]['rpe']);
        $this->assertSame(145, $byDate[$threeDaysAgo]['hr_avg']);
        $this->assertSame('manual', $byDate[$threeDaysAgo]['source']);
    }

    public function test_buildForUser_includes_season_climate_context(): void {
        $userId = $this->createTestUser();

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-08-15',
            'sessions_per_week' => 5,
            'weekly_base_km' => 50,
            'experience_level' => 'intermediate',
            'training_start_date' => '2026-05-01',
            'timezone' => 'Europe/Moscow',
        ]);

        $this->assertArrayHasKey('season', $state);
        $season = $state['season'];
        $this->assertSame(5, $season['current_month']);
        $this->assertSame('may', $season['current_month_name']);
        $this->assertSame(8, $season['race_month']);
        $this->assertSame('august', $season['race_month_name']);
        $this->assertTrue($season['northern_hemisphere']);
        $this->assertSame('spring', $season['season_phase']);
        $this->assertSame('summer', $season['race_season_phase']);
        $this->assertSame('Europe/Moscow', $season['timezone']);
    }

    public function test_buildForUser_flips_hemisphere_for_southern_timezone(): void {
        $userId = $this->createTestUser();

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => '10k',
            'sessions_per_week' => 4,
            'weekly_base_km' => 30,
            'experience_level' => 'intermediate',
            'training_start_date' => '2026-07-01',
            'timezone' => 'Australia/Sydney',
        ]);

        $this->assertFalse($state['season']['northern_hemisphere']);
        // Июль на юге = середина зимы.
        $this->assertSame('winter', $state['season']['season_phase']);
    }

    public function test_buildForUser_includes_best_races_progression(): void {
        $userId = $this->createTestUser();

        // 5k за 4 недели назад (фактически race effort)
        $fiveKDate = (new \DateTimeImmutable('-4 weeks'))->format('Y-m-d');
        $this->insertCompletedLogWithResult($userId, $fiveKDate, 1, 'sun', 5.0, 22, '00:22:30');
        // 10k за 12 недель назад
        $tenKDate = (new \DateTimeImmutable('-12 weeks'))->format('Y-m-d');
        $this->insertCompletedLogWithResult($userId, $tenKDate, 1, 'sun', 10.0, 47, '00:47:00');
        // half за 24 недели назад
        $halfDate = (new \DateTimeImmutable('-24 weeks'))->format('Y-m-d');
        $this->insertCompletedLogWithResult($userId, $halfDate, 1, 'sun', 21.1, 110, '01:50:00');

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => 'half',
            'race_date' => '2026-09-15',
            'sessions_per_week' => 5,
            'weekly_base_km' => 50,
            'experience_level' => 'intermediate',
        ]);

        $this->assertArrayHasKey('best_races', $state);
        $this->assertIsArray($state['best_races']);
        $this->assertCount(3, $state['best_races']);

        $byLabel = [];
        foreach ($state['best_races'] as $race) {
            $byLabel[$race['distance_label']] = $race;
        }

        $this->assertArrayHasKey('5k', $byLabel);
        $this->assertSame(5.0, $byLabel['5k']['distance_km']);
        $this->assertSame(1350, $byLabel['5k']['time_sec']);
        $this->assertSame(270, $byLabel['5k']['pace_sec']);

        $this->assertArrayHasKey('10k', $byLabel);
        $this->assertSame(10.0, $byLabel['10k']['distance_km']);
        $this->assertSame(2820, $byLabel['10k']['time_sec']);

        $this->assertArrayHasKey('half', $byLabel);
        $this->assertSame(21.1, $byLabel['half']['distance_km']);
        $this->assertSame(6600, $byLabel['half']['time_sec']);

        // Phase B.5: best_races_at_target_distance внутри goal_realism.
        $this->assertIsArray($state['goal_realism'] ?? null);
        $this->assertArrayHasKey('best_races_at_target_distance', $state['goal_realism']);
        $this->assertCount(1, $state['goal_realism']['best_races_at_target_distance']);
        $this->assertSame('half', $state['goal_realism']['best_races_at_target_distance'][0]['distance_label']);
    }

    public function test_buildForUser_skips_recent_context_when_feature_flag_disabled(): void {
        $previous = getenv('PLANRUN_AI_STATE_RECENT_CONTEXT');
        putenv('PLANRUN_AI_STATE_RECENT_CONTEXT=0');

        try {
            $userId = $this->createTestUser();
            $this->insertCompletedLog($userId, gmdate('Y-m-d'), 1, 'mon', 5.0, 30);

            $builder = new TrainingStateBuilder($this->db);
            $state = $builder->buildForUser([
                'id' => $userId,
                'goal_type' => 'race',
                'race_distance' => '10k',
                'sessions_per_week' => 3,
                'weekly_base_km' => 25,
                'experience_level' => 'intermediate',
            ]);

            $this->assertArrayNotHasKey('recent_compliance', $state);
            $this->assertArrayNotHasKey('recent_workouts_detailed', $state);
        } finally {
            if ($previous === false) {
                putenv('PLANRUN_AI_STATE_RECENT_CONTEXT');
            } else {
                putenv('PLANRUN_AI_STATE_RECENT_CONTEXT=' . $previous);
            }
        }
    }

    private function insertPlanWeek(int $userId, string $weekStart): int {
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_weeks (user_id, week_number, start_date) VALUES (?, ?, ?)'
        );
        $weekNumber = 1;
        $stmt->bind_param('iis', $userId, $weekNumber, $weekStart);
        $stmt->execute();
        $weekId = (int) $this->db->insert_id;
        $stmt->close();
        return $weekId;
    }

    private function insertPlanDay(int $userId, int $weekId, string $date, int $dayOfWeek, string $type, int $isKey): void {
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_days (user_id, week_id, day_of_week, date, type, is_key_workout)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiissi', $userId, $weekId, $dayOfWeek, $date, $type, $isKey);
        $stmt->execute();
        $stmt->close();
    }

    private function insertCompletedLog(int $userId, string $date, int $weekNumber, string $dayName, float $distanceKm, int $durationMinutes, int $activityTypeId = 1): void {
        $stmt = $this->db->prepare(
            'INSERT INTO workout_log (user_id, training_date, week_number, day_name, activity_type_id, is_completed, distance_km, duration_minutes)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->bind_param('isisidi', $userId, $date, $weekNumber, $dayName, $activityTypeId, $distanceKm, $durationMinutes);
        $stmt->execute();
        $stmt->close();
    }

    private function insertCompletedLogWithResult(
        int $userId,
        string $date,
        int $weekNumber,
        string $dayName,
        float $distanceKm,
        int $durationMinutes,
        string $resultTime,
        int $activityTypeId = 1
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed,
                 distance_km, duration_minutes, result_time)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isisidis',
            $userId,
            $date,
            $weekNumber,
            $dayName,
            $activityTypeId,
            $distanceKm,
            $durationMinutes,
            $resultTime
        );
        $stmt->execute();
        $stmt->close();
    }

    private function insertCompletedLogWithRating(
        int $userId,
        string $date,
        int $weekNumber,
        string $dayName,
        float $distanceKm,
        int $durationMinutes,
        int $rating,
        int $avgHr,
        int $activityTypeId = 1
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed,
                 distance_km, duration_minutes, rating, avg_heart_rate)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isisidiii',
            $userId,
            $date,
            $weekNumber,
            $dayName,
            $activityTypeId,
            $distanceKm,
            $durationMinutes,
            $rating,
            $avgHr
        );
        $stmt->execute();
        $stmt->close();
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

    // ---------------------------------------------------------------
    // PR-A (coaching prompt v4): summary_for_coach + peak_volume_floor_km
    // ---------------------------------------------------------------

    /**
     * Утилита: вызывает private-метод через рефлексию (tests-only).
     */
    private function invokePrivate(object $obj, string $method, array $args) {
        $r = new \ReflectionClass($obj);
        $m = $r->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    public function test_compliance_summary_returns_empty_string_for_no_data(): void {
        $builder = new TrainingStateBuilder($this->db);
        $result = $this->invokePrivate($builder, 'buildRecentComplianceSummary', [[], 0.0]);
        $this->assertSame('', $result);
    }

    public function test_compliance_summary_describes_period_without_plan(): void {
        $builder = new TrainingStateBuilder($this->db);
        $weeks = [
            ['week_start' => '2026-04-13', 'planned_count' => 0, 'completed_count' => 4,
             'actual_km' => 25.0, 'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['week_start' => '2026-04-20', 'planned_count' => 0, 'completed_count' => 5,
             'actual_km' => 30.0, 'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['week_start' => '2026-04-27', 'planned_count' => 0, 'completed_count' => 5,
             'actual_km' => 35.0, 'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['week_start' => '2026-05-04', 'planned_count' => 0, 'completed_count' => 5,
             'actual_km' => 32.0, 'key_workout_planned' => 0, 'key_workout_completed' => 0],
        ];
        $result = $this->invokePrivate($builder, 'buildRecentComplianceSummary', [$weeks, 30.0]);
        $this->assertStringContainsString('без плана', $result);
        $this->assertStringContainsString('122', $result);
        $this->assertStringContainsString('30.5', $result);
        $this->assertStringContainsString('в среднем', $result);
        // PR-A: разделение «с планом» / «без плана» — здесь все недели без плана,
        // поэтому summary должен быть односегментным.
        $this->assertStringNotContainsString('с планом', $result);
    }

    public function test_compliance_summary_includes_planned_completed_and_keys(): void {
        $builder = new TrainingStateBuilder($this->db);
        $weeks = [
            ['planned_count' => 5, 'completed_count' => 4, 'actual_km' => 35.0,
             'key_workout_planned' => 2, 'key_workout_completed' => 2],
            ['planned_count' => 5, 'completed_count' => 3, 'actual_km' => 28.0,
             'key_workout_planned' => 2, 'key_workout_completed' => 1],
        ];
        $result = $this->invokePrivate($builder, 'buildRecentComplianceSummary', [$weeks, 30.0]);
        $this->assertStringContainsString('с планом запланировано 10', $result);
        $this->assertStringContainsString('выполнено 7', $result);
        $this->assertStringContainsString('63', $result);
        $this->assertStringContainsString('Ключевых выполнено 3 из 4', $result);
    }

    public function test_compliance_summary_splits_planned_and_unplanned_segments(): void {
        $builder = new TrainingStateBuilder($this->db);
        // PR-A: 2 недели без плана + 2 недели с планом — summary должен явно разделить
        // эти сегменты, чтобы модель не сложила «без плана выполнено» с «по плану выполнено».
        $weeks = [
            ['planned_count' => 0, 'completed_count' => 4, 'actual_km' => 50.0,
             'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['planned_count' => 0, 'completed_count' => 5, 'actual_km' => 55.0,
             'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['planned_count' => 5, 'completed_count' => 4, 'actual_km' => 40.0,
             'key_workout_planned' => 2, 'key_workout_completed' => 1],
            ['planned_count' => 5, 'completed_count' => 5, 'actual_km' => 45.0,
             'key_workout_planned' => 2, 'key_workout_completed' => 2],
        ];
        $result = $this->invokePrivate($builder, 'buildRecentComplianceSummary', [$weeks, 50.0]);
        $this->assertStringContainsString('mix', $result);
        $this->assertStringContainsString('с планом', $result);
        $this->assertStringContainsString('без плана', $result);
        // Сегменты считаются раздельно
        $this->assertStringContainsString('с планом запланировано 10', $result);
        $this->assertStringContainsString('Ключевых выполнено 3 из 4', $result);
    }

    public function test_compliance_summary_detects_overperforming_above_base(): void {
        $builder = new TrainingStateBuilder($this->db);
        $weeks = [
            ['planned_count' => 0, 'completed_count' => 0, 'actual_km' => 50.0,
             'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['planned_count' => 0, 'completed_count' => 0, 'actual_km' => 55.0,
             'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['planned_count' => 0, 'completed_count' => 0, 'actual_km' => 52.0,
             'key_workout_planned' => 0, 'key_workout_completed' => 0],
            ['planned_count' => 0, 'completed_count' => 0, 'actual_km' => 60.0,
             'key_workout_planned' => 0, 'key_workout_completed' => 0],
        ];
        $result = $this->invokePrivate($builder, 'buildRecentComplianceSummary', [$weeks, 30.0]);
        // avg ≈ 54.25, base = 30 → ratio ≈ 1.81 — overperforming
        $this->assertStringContainsString('выше заявленной базы', $result);
    }

    public function test_compliance_summary_detects_underperforming_below_base(): void {
        $builder = new TrainingStateBuilder($this->db);
        $weeks = [
            ['planned_count' => 4, 'completed_count' => 1, 'actual_km' => 8.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 0],
            ['planned_count' => 4, 'completed_count' => 1, 'actual_km' => 6.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 0],
            ['planned_count' => 4, 'completed_count' => 2, 'actual_km' => 12.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 1],
            ['planned_count' => 4, 'completed_count' => 1, 'actual_km' => 9.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 0],
        ];
        $result = $this->invokePrivate($builder, 'buildRecentComplianceSummary', [$weeks, 40.0]);
        // avg ≈ 8.75, base = 40 → ratio ≈ 0.22 — well under 0.50.
        // Без алармизма: даём числа и предлагаем подумать причину (recovery / болезнь / отпуск).
        $this->assertStringContainsString('ниже заявленной базы', $result);
        $this->assertStringContainsString('временный ли это спад', $result);
    }

    public function test_compliance_summary_detects_volume_trend_decrease(): void {
        $builder = new TrainingStateBuilder($this->db);
        $weeks = [
            ['planned_count' => 4, 'completed_count' => 4, 'actual_km' => 60.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 1],
            ['planned_count' => 4, 'completed_count' => 4, 'actual_km' => 55.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 1],
            ['planned_count' => 4, 'completed_count' => 3, 'actual_km' => 35.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 0],
            ['planned_count' => 4, 'completed_count' => 3, 'actual_km' => 30.0,
             'key_workout_planned' => 1, 'key_workout_completed' => 0],
        ];
        $result = $this->invokePrivate($builder, 'buildRecentComplianceSummary', [$weeks, 50.0]);
        $this->assertStringContainsString('снижается', $result);
    }

    public function test_peak_volume_floor_km_returns_max_actual(): void {
        $builder = new TrainingStateBuilder($this->db);
        $weeks = [
            ['actual_km' => 60.0],
            ['actual_km' => 65.0],
            ['actual_km' => 70.0],
            ['actual_km' => 68.0],
        ];
        $result = $this->invokePrivate($builder, 'computePeakVolumeFloorKm', [$weeks, 50.0]);
        $this->assertSame(70.0, $result);
    }

    public function test_peak_volume_floor_km_excludes_outlier_race_week(): void {
        $builder = new TrainingStateBuilder($this->db);
        // 4 недели: 3 «обычных» 50-60 и одна race-week 130 (марафон) — должна отсеяться
        $weeks = [
            ['actual_km' => 50.0],
            ['actual_km' => 55.0],
            ['actual_km' => 130.0], // outlier (>1.30 × median)
            ['actual_km' => 60.0],
        ];
        $result = $this->invokePrivate($builder, 'computePeakVolumeFloorKm', [$weeks, 50.0]);
        // После отсева outlier остаётся max=60 — должен совпасть
        $this->assertSame(60.0, $result);
    }

    public function test_peak_volume_floor_km_uses_base_when_actuals_low(): void {
        $builder = new TrainingStateBuilder($this->db);
        // Низкие actuals — fallback на reported_weekly_base × 0.85 = 60 × 0.85 = 51
        $weeks = [
            ['actual_km' => 20.0],
            ['actual_km' => 25.0],
            ['actual_km' => 22.0],
        ];
        $result = $this->invokePrivate($builder, 'computePeakVolumeFloorKm', [$weeks, 60.0]);
        $this->assertSame(51.0, $result);
    }

    public function test_peak_volume_floor_km_returns_null_for_no_data(): void {
        $builder = new TrainingStateBuilder($this->db);
        $result = $this->invokePrivate($builder, 'computePeakVolumeFloorKm', [[], 0.0]);
        $this->assertNull($result);
    }

    public function test_buildForUser_attaches_recent_compliance_summary_and_peak_floor(): void {
        $userId = $this->createTestUser();

        $thisMonday = new \DateTimeImmutable('monday this week');
        $prevMonday = $thisMonday->modify('-1 week');
        $prevMondayStr = $prevMonday->format('Y-m-d');

        // План на прошлую неделю
        $weekId = $this->insertPlanWeek($userId, $prevMondayStr);
        $this->insertPlanDay($userId, $weekId, $prevMondayStr, 1, 'easy', 0);
        $this->insertPlanDay($userId, $weekId, $prevMonday->modify('+2 days')->format('Y-m-d'), 3, 'tempo', 1);
        $this->insertPlanDay($userId, $weekId, $prevMonday->modify('+5 days')->format('Y-m-d'), 6, 'long', 1);

        // Выполненные тренировки
        $this->insertCompletedLog($userId, $prevMondayStr, 1, 'mon', 8.0, 50);
        $this->insertCompletedLog($userId, $prevMonday->modify('+2 days')->format('Y-m-d'), 1, 'wed', 10.0, 55);
        $this->insertCompletedLog($userId, $prevMonday->modify('+5 days')->format('Y-m-d'), 1, 'sat', 18.0, 110);

        $builder = new TrainingStateBuilder($this->db);
        $state = $builder->buildForUser([
            'id' => $userId,
            'goal_type' => 'race',
            'race_distance' => 'half',
            'sessions_per_week' => 4,
            'weekly_base_km' => 30,
            'experience_level' => 'intermediate',
        ]);

        $this->assertArrayHasKey('recent_compliance_summary', $state);
        $this->assertIsString($state['recent_compliance_summary']);
        $this->assertNotSame('', $state['recent_compliance_summary']);

        $this->assertIsArray($state['load_policy']);
        $this->assertArrayHasKey('peak_volume_floor_km', $state['load_policy']);
        $this->assertGreaterThan(0.0, (float) $state['load_policy']['peak_volume_floor_km']);
    }
}
