<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanGenerationProcessorService.php';

class PlanGenerationProcessorServiceTest extends TestCase {
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

    public function test_resolveQualityGateMode_returns_permissive_for_healthy_marathon_runner_in_auto_mode(): void {
        // Trust-the-model: здоровый бегун с реалистичной целью на марафон не должен
        // получать блокирующий strict только из-за длинной дистанции — DeepSeek
        // получает достаточный контекст в FACTS_JSON и сам строит безопасный план.
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
        ], [
            'race_distance' => 'marathon',
            'special_population_flags' => [],
            'goal_realism' => [
                'verdict' => 'realistic',
                'severity' => 'none',
            ],
        ]);

        $this->assertSame('permissive', $result[0]);
        $this->assertSame('auto_default_permissive', $result[1]);
    }

    public function test_resolveQualityGateMode_returns_permissive_for_healthy_half_marathon_runner_in_auto_mode(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'race',
            'race_distance' => 'half',
        ], [
            'race_distance' => 'half',
            'special_population_flags' => [],
            'goal_realism' => [
                'verdict' => 'realistic',
                'severity' => 'none',
            ],
        ]);

        $this->assertSame('permissive', $result[0]);
        $this->assertSame('auto_default_permissive', $result[1]);
    }

    public function test_resolveQualityGateMode_returns_strict_for_marathon_with_return_after_injury(): void {
        // Травмо-критическая когорта остаётся под strict (даже на марафоне).
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
        ], [
            'race_distance' => 'marathon',
            'special_population_flags' => ['return_after_injury'],
        ]);

        $this->assertSame('strict', $result[0]);
        $this->assertStringContainsString('return_after_injury', $result[1]);
    }

    public function test_resolveQualityGateMode_does_not_force_strict_for_return_after_break_scenario(): void {
        // return_after_break — про восстановительный объём, не про injury risk.
        // DeepSeek с FACTS_JSON разруливает сам; gate работает в permissive.
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'race',
            'race_distance' => '10k',
        ], [
            'race_distance' => '10k',
            'special_population_flags' => [],
            'planning_scenario' => [
                'flags' => ['return_after_break'],
            ],
            'goal_realism' => [
                'verdict' => 'realistic',
                'severity' => 'none',
            ],
        ]);

        $this->assertSame('permissive', $result[0]);
        $this->assertSame('auto_default_permissive', $result[1]);
    }

    public function test_resolveQualityGateMode_returns_strict_for_return_after_injury_flag(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'race',
            'race_distance' => '10k',
        ], [
            'race_distance' => '10k',
            'special_population_flags' => ['return_after_injury'],
        ]);

        $this->assertSame('strict', $result[0]);
        $this->assertStringContainsString('return_after_injury', $result[1]);
    }

    public function test_resolveQualityGateMode_returns_strict_for_unrealistic_goal_realism(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'race',
            'race_distance' => '5k',
        ], [
            'race_distance' => '5k',
            'special_population_flags' => [],
            'goal_realism' => [
                'verdict' => 'unrealistic',
                'severity' => 'major',
            ],
        ]);

        $this->assertSame('strict', $result[0]);
        $this->assertSame('auto_goal_unrealistic', $result[1]);
    }

    public function test_resolveQualityGateMode_returns_strict_for_protective_scenario_flags(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'health',
        ], [
            'race_distance' => null,
            'special_population_flags' => [],
            'planning_scenario' => [
                'flags' => ['pain_protective'],
            ],
        ]);

        $this->assertSame('strict', $result[0]);
        $this->assertStringContainsString('pain_protective', $result[1]);
    }

    public function test_resolveQualityGateMode_returns_permissive_for_healthy_runner_in_auto_mode(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'auto', [
            'goal_type' => 'race',
            'race_distance' => '10k',
        ], [
            'race_distance' => '10k',
            'special_population_flags' => [],
            'planning_scenario' => [
                'flags' => ['standard_race_build'],
            ],
            'goal_realism' => [
                'verdict' => 'realistic',
                'severity' => 'none',
            ],
        ]);

        $this->assertSame('permissive', $result[0]);
        $this->assertSame('auto_default_permissive', $result[1]);
    }

    public function test_resolveQualityGateMode_respects_explicit_strict_env(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'strict', [
            'goal_type' => 'race',
            'race_distance' => '10k',
        ], [
            'race_distance' => '10k',
            'special_population_flags' => [],
        ]);

        $this->assertSame('strict', $result[0]);
        $this->assertSame('env_explicit', $result[1]);
    }

    public function test_resolveQualityGateMode_respects_explicit_permissive_env(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'resolveQualityGateMode');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'permissive', [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
        ], [
            'race_distance' => 'marathon',
            'special_population_flags' => ['return_after_injury'],
        ]);

        $this->assertSame('permissive', $result[0]);
        $this->assertSame('env_explicit', $result[1]);
    }

    public function test_applySinglePassHardSafetyRepairs_caps_late_marathon_long_runs(): void {
        // Phase A.6 (PR3): после поднятия longShareCap до 0.60 (медицинский потолок) при
        // late-long cap до 32 км и week_total ~62 км share становится 0.51 < 0.60 — share cap
        // не срабатывает. Остаётся только медицинский late-long repair.
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'applySinglePassHardSafetyRepairs');
        $method->setAccessible(true);

        [$plan, $repairs] = $method->invoke($service, [
            '_generation_metadata' => [
                'macro_plan' => [
                    'weeks' => [[
                        'week' => 7,
                        'target_volume_km' => 68.0,
                        'long_run_km' => 38.0,
                    ]],
                ],
            ],
            'weeks' => [[
                'week_number' => 7,
                'phase' => 'peak',
                'days' => [
                    ['day_of_week' => 1, 'type' => 'easy', 'distance_km' => 10.0],
                    ['day_of_week' => 2, 'type' => 'rest'],
                    ['day_of_week' => 3, 'type' => 'easy', 'distance_km' => 10.0],
                    ['day_of_week' => 4, 'type' => 'rest'],
                    ['day_of_week' => 5, 'type' => 'easy', 'distance_km' => 10.0],
                    ['day_of_week' => 6, 'type' => 'rest'],
                    ['day_of_week' => 7, 'type' => 'long', 'distance_km' => 38.0, 'pace' => '5:30'],
                ],
            ]],
        ], [
            'race_distance' => 'marathon',
            'race_date' => '2026-07-04',
        ], '2026-05-04');

        $this->assertSame(32.0, (float) ($plan['weeks'][0]['days'][6]['distance_km'] ?? 0.0));
        $this->assertSame(62.0, (float) ($plan['weeks'][0]['target_volume_km'] ?? 0.0));
        $this->assertSame(32.0, (float) ($plan['_generation_metadata']['macro_plan']['weeks'][0]['long_run_km'] ?? 0.0));
        $this->assertCount(1, $repairs);
        $this->assertSame('cap_late_marathon_long_run', $repairs[0]['code'] ?? null);
        $this->assertSame(13, (int) ($repairs[0]['days_to_race'] ?? 0));
    }

    public function test_applySinglePassHardSafetyRepairs_caps_extreme_long_share(): void {
        // Phase A.6 (PR3): share cap (medical 0.60) срабатывает только при экстремальном перекосе.
        // Конфигурация: week_total=44, long=30, share=30/44=0.682 — выше 0.60, попадает под repair.
        // Ожидаемый результат: max long = 14 * 0.6 / (1 - 0.6) = 21.0; cap до 21 км.
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'applySinglePassHardSafetyRepairs');
        $method->setAccessible(true);

        [$plan, $repairs] = $method->invoke($service, [
            '_generation_metadata' => ['macro_plan' => ['weeks' => [[
                'week' => 4,
                'target_volume_km' => 44.0,
                'long_run_km' => 30.0,
            ]]]],
            'weeks' => [[
                'week_number' => 4,
                'phase' => 'build',
                'days' => [
                    ['day_of_week' => 1, 'type' => 'easy', 'distance_km' => 6.0],
                    ['day_of_week' => 2, 'type' => 'rest'],
                    ['day_of_week' => 3, 'type' => 'easy', 'distance_km' => 4.0],
                    ['day_of_week' => 4, 'type' => 'rest'],
                    ['day_of_week' => 5, 'type' => 'easy', 'distance_km' => 4.0],
                    ['day_of_week' => 6, 'type' => 'rest'],
                    ['day_of_week' => 7, 'type' => 'long', 'distance_km' => 30.0, 'pace' => '6:00'],
                ],
            ]],
        ], [
            'race_distance' => 'marathon',
            'race_date' => '2026-09-13',
        ], '2026-05-04');

        $this->assertSame(21.0, (float) ($plan['weeks'][0]['days'][6]['distance_km'] ?? 0.0));
        $this->assertCount(1, $repairs);
        $this->assertSame('cap_long_run_week_share', $repairs[0]['code'] ?? null);
        $this->assertSame(0.60, (float) ($repairs[0]['share_cap'] ?? 0.0));
    }

    public function test_enrichRecalculatePayload_excludesWalkingAndManualCrossTrainingFromActualWeeklyKm(): void {
        $userId = $this->createTestUser();
        $runningTypeId = $this->ensureActivityType('running');
        $cyclingTypeId = $this->ensureActivityType('cycling_test');

        $runningWorkoutDate = date('Y-m-d', strtotime('-7 days'));
        $walkingWorkoutDate = date('Y-m-d', strtotime('-6 days'));
        $oldRunningWorkoutDate = date('Y-m-d', strtotime('-35 days'));
        $manualRunDate = date('Y-m-d', strtotime('-14 days'));
        $manualCyclingDate = date('Y-m-d', strtotime('-13 days'));

        $this->insertWorkout($userId, 'running', $runningWorkoutDate . ' 07:00:00', $runningWorkoutDate . ' 08:00:00', 10.0, 60);
        $this->insertWorkout($userId, 'walking', $walkingWorkoutDate . ' 07:00:00', $walkingWorkoutDate . ' 08:00:00', 5.0, 60);
        $this->insertWorkout($userId, 'running', $oldRunningWorkoutDate . ' 07:00:00', $oldRunningWorkoutDate . ' 07:45:00', 8.0, 45);

        $this->insertWorkoutLog($userId, $manualRunDate, $runningTypeId, 7.0, 42);
        $this->insertWorkoutLog($userId, $manualCyclingDate, $cyclingTypeId, 20.0, 55);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, ['reason' => 'тестовый пересчёт']);

        $this->assertSame(8.5, $payload['actual_weekly_km_4w']);
    }

    public function test_enrichRecalculatePayload_sets_mutable_from_date_to_tomorrow_when_running_workout_today_exists(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $this->insertWorkout($userId, 'running', $today . ' 07:00:00', $today . ' 08:00:00', 8.0, 48);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, ['reason' => 'тестовый пересчёт']);

        $this->assertSame(date('Y-m-d', strtotime($today . ' +1 day')), $payload['mutable_from_date']);
    }

    public function test_enrichRecalculatePayload_aligns_cutoff_to_future_plan_start_and_includes_current_phase(): void {
        $planStartDate = date('Y-m-d', strtotime('monday next week'));
        $raceDate = date('Y-m-d', strtotime($planStartDate . ' +8 weeks +6 days'));

        $userId = $this->createPlanningUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => $raceDate,
            'race_target_time' => '00:48:46',
            'training_start_date' => $planStartDate,
            'weekly_base_km' => 3.0,
            'sessions_per_week' => 4,
            'experience_level' => 'novice',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'fri']),
            'easy_pace_sec' => 380,
        ]);

        $this->insertPlanWeek($userId, 1, $planStartDate, 6.8, ['easy', 'easy', 'rest', 'easy', 'long', 'rest', 'rest']);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, ['reason' => 'пересчитать план']);

        $this->assertSame($planStartDate, $payload['cutoff_date'] ?? null);
        $this->assertSame(0, (int) ($payload['kept_weeks'] ?? -1));
        $this->assertIsArray($payload['current_phase'] ?? null);
        $this->assertSame('base', $payload['current_phase']['phase'] ?? null);
        $this->assertSame('recalculate', $payload['continuation_context']['mode'] ?? null);
        $this->assertSame($planStartDate, $payload['continuation_context']['anchor_date'] ?? null);
    }

    public function test_enrichRecalculatePayload_builds_progression_counters_from_completed_key_days(): void {
        $userId = $this->createPlanningUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:45:00',
            'training_start_date' => '2026-03-23',
            'weekly_base_km' => 24.0,
            'sessions_per_week' => 5,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'sat', 'sun']),
            'easy_pace_sec' => 350,
        ]);

        $this->insertPlanWeek($userId, 1, '2026-03-23', 24.0, ['easy', 'tempo', 'rest', 'interval', 'rest', 'fartlek', 'long']);
        $this->insertPlanWeek($userId, 2, '2026-03-30', 26.0, ['easy', 'tempo', 'rest', 'interval', 'rest', 'control', 'long']);

        $runningTypeId = $this->ensureActivityType('running');
        $this->insertWorkoutLog($userId, '2026-03-24', $runningTypeId, 8.0, 42);
        $this->insertWorkoutLog($userId, '2026-03-26', $runningTypeId, 9.0, 45);
        $this->insertWorkoutLog($userId, '2026-03-31', $runningTypeId, 8.5, 43);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, [
            'reason' => 'нужно продолжить план',
            'cutoff_date' => '2026-04-06',
        ]);

        $counters = $payload['progression_counters'] ?? [];

        $this->assertSame(2, (int) ($counters['tempo_count'] ?? 0));
        $this->assertSame(1, (int) ($counters['interval_count'] ?? 0));
        $this->assertSame(0, (int) ($counters['fartlek_count'] ?? 0));
        $this->assertSame(0, (int) ($counters['control_count'] ?? 0));
        $this->assertSame(3, (int) ($counters['completed_key_days'] ?? 0));
        $this->assertSame(1, (int) ($counters['race_pace_count'] ?? 0));
        $this->assertSame(2, (int) ($payload['continuation_context']['progression_counters']['tempo_count'] ?? 0));
    }

    public function test_enrichNextPlanPayload_uses_recent_non_race_weeks_before_new_start(): void {
        $nextPlanStart = date('Y-m-d', strtotime('monday this week'));
        $weekOneStart = date('Y-m-d', strtotime($nextPlanStart . ' -4 weeks'));
        $weekTwoStart = date('Y-m-d', strtotime($nextPlanStart . ' -3 weeks'));
        $weekThreeStart = date('Y-m-d', strtotime($nextPlanStart . ' -2 weeks'));
        $weekFourStart = date('Y-m-d', strtotime($nextPlanStart . ' -1 week'));
        $raceDate = date('Y-m-d', strtotime($nextPlanStart . ' +4 weeks +6 days'));

        $userId = $this->createPlanningUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => $raceDate,
            'race_target_time' => '00:48:46',
            'training_start_date' => $weekOneStart,
            'weekly_base_km' => 8.0,
            'sessions_per_week' => 4,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'wed', 'fri', 'sun']),
            'easy_pace_sec' => 360,
        ]);

        $this->insertPlanWeek($userId, 1, $weekOneStart, 7.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']);
        $this->insertPlanWeek($userId, 2, $weekTwoStart, 8.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']);
        $this->insertPlanWeek($userId, 3, $weekThreeStart, 20.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'race']);
        $this->insertPlanWeek($userId, 4, $weekFourStart, 9.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichNextPlanPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, []);

        $this->assertSame($nextPlanStart, $payload['cutoff_date'] ?? null);
        $this->assertSame(8.0, (float) ($payload['last_plan_avg_km'] ?? 0.0));
        $this->assertSame('next_plan', $payload['continuation_context']['mode'] ?? null);
        $this->assertCount(3, $payload['continuation_context']['recent_plan_weeks'] ?? []);
    }

    public function test_attachGenerationExplanation_adds_explanation_metadata(): void {
        $userId = $this->createTestUser();
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'attachGenerationExplanation');
        $method->setAccessible(true);

        $planData = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'planned_km' => 8.0],
                    ['type' => 'tempo', 'planned_km' => 10.0],
                    ['type' => 'rest', 'planned_km' => 0.0],
                    ['type' => 'easy', 'planned_km' => 7.0],
                    ['type' => 'interval', 'planned_km' => 9.0],
                    ['type' => 'rest', 'planned_km' => 0.0],
                    ['type' => 'long', 'planned_km' => 16.0],
                ],
            ]],
        ];
        $state = [
            'readiness' => 'normal',
            'vdot' => 42.1,
            'vdot_source_label' => 'лучшие свежие тренировки',
            'athlete_signals' => [
                'prompt_summary' => 'post-workout feedback: 2 ответа; overall=moderate',
            ],
        ];

        $result = $method->invoke($service, $userId, 'recalculate', ['reason' => 'после тяжёлой недели'], $planData, $state);
        $summary = (string) ($result['_generation_metadata']['explanation']['summary'] ?? '');

        $this->assertIsArray($result['_generation_metadata']['explanation'] ?? null);
        $this->assertStringNotContainsString('readiness=', $summary);
        $this->assertStringNotContainsString('quality-дней', $summary);
        $this->assertStringNotContainsString('post-workout feedback', $summary);
        $this->assertStringNotContainsString('VDOT', $summary);
        $this->assertStringNotContainsString('readiness', $summary);
        $this->assertMatchesRegularExpression('/форм|ориентир/u', $summary);
        $this->assertStringContainsString('объём ближайшей недели', $summary);
        $this->assertSame(2, (int) ($result['_generation_metadata']['explanation']['plan_outline']['week_1_quality_count'] ?? 0));
    }

    public function test_buildQualityGateFailureMessage_prefers_blocking_errors_over_warnings(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'buildQualityGateFailureMessage');
        $method->setAccessible(true);

        $message = $method->invoke($service, [
            ['severity' => 'warning', 'message' => 'warning: taper too big'],
            ['severity' => 'error', 'message' => 'error: weekly spike'],
            ['severity' => 'warning', 'message' => 'warning: adjacent workouts'],
            ['severity' => 'error', 'message' => 'error: race mismatch'],
        ]);

        $this->assertSame('error: weekly spike | error: race mismatch', $message);
    }

    public function test_syncLatestTrainingPlanSnapshot_updates_latest_row_and_clears_error(): void {
        $userId = $this->createTestUserWithGoal([
            'goal_type' => 'time_improvement',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:48:46',
            'training_start_date' => '2026-04-22',
        ]);

        $activeStmt = $this->db->prepare(
            'INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active, error_message, plan_description)
             VALUES (?, ?, ?, ?, 1, NULL, ?)'
        );
        $activeStartDate = '2026-04-01';
        $activePlanDate = '2026-06-01';
        $activeTargetTime = '52:00';
        $activeDescription = 'older active description';
        $activeStmt->bind_param('issss', $userId, $activeStartDate, $activePlanDate, $activeTargetTime, $activeDescription);
        $activeStmt->execute();
        $activeStmt->close();

        $stmt = $this->db->prepare(
            'INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active, error_message, plan_description)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        );
        $oldStartDate = '2026-04-22';
        $oldPlanDate = '2026-06-21';
        $oldTargetTime = '48:46';
        $oldError = 'old generation error';
        $oldDescription = 'old description';
        $stmt->bind_param('isssss', $userId, $oldStartDate, $oldPlanDate, $oldTargetTime, $oldError, $oldDescription);
        $stmt->execute();
        $stmt->close();

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'syncLatestTrainingPlanSnapshot');
        $method->setAccessible(true);

        $method->invoke($service, $userId, '2026-04-27', [
            '_generation_metadata' => [
                'explanation' => [
                    'summary' => 'Новый актуальный план',
                ],
            ],
        ]);

        $row = $this->db->query(
            'SELECT start_date, marathon_date, target_time, is_active, error_message, plan_description
             FROM user_training_plans WHERE user_id = ' . (int) $userId . ' ORDER BY id DESC LIMIT 1'
        )->fetch_assoc();

        $this->assertSame('2026-04-27', $row['start_date'] ?? null);
        $this->assertSame('2026-06-21', $row['marathon_date'] ?? null);
        $this->assertSame('48:46', $row['target_time'] ?? null);
        $this->assertSame('1', (string) ($row['is_active'] ?? '0'));
        $this->assertNull($row['error_message']);
        $this->assertSame('Новый актуальный план', $row['plan_description'] ?? null);

        $activeCount = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM user_training_plans WHERE user_id = ' . (int) $userId . ' AND is_active = 1'
        )->fetch_assoc();
        $this->assertSame(1, (int) ($activeCount['cnt'] ?? 0));
    }

    public function test_syncLatestTrainingPlanSnapshot_keeps_hour_component_for_marathon_target(): void {
        $userId = $this->createTestUserWithGoal([
            'goal_type' => 'race',
            'race_date' => '2026-07-04',
            'race_target_time' => '03:30:00',
            'training_start_date' => '2026-05-04',
        ]);

        $stmt = $this->db->prepare(
            'INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active, error_message, plan_description)
             VALUES (?, ?, ?, ?, 0, NULL, NULL)'
        );
        $oldStartDate = '2026-04-01';
        $oldPlanDate = '2026-05-03';
        $oldTargetTime = '30:00';
        $stmt->bind_param('isss', $userId, $oldStartDate, $oldPlanDate, $oldTargetTime);
        $stmt->execute();
        $stmt->close();

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'syncLatestTrainingPlanSnapshot');
        $method->setAccessible(true);

        $method->invoke($service, $userId, '2026-05-04', ['weeks' => []]);

        $row = $this->db->query(
            'SELECT target_time FROM user_training_plans WHERE user_id = ' . (int) $userId . ' ORDER BY id DESC LIMIT 1'
        )->fetch_assoc();

        $this->assertSame('3:30:00', $row['target_time'] ?? null);
    }

    public function test_enforceRaceDayConsistency_restores_target_marathon_distance_and_pace(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enforceRaceDayConsistency');
        $method->setAccessible(true);

        $plan = [
            'weeks' => [[
                'week_number' => 3,
                'target_volume_km' => 25.9,
                'actual_volume_km' => 25.9,
                'days' => [
                    ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '5:03'],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '5:03'],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'race', 'distance_km' => 19.9, 'pace' => '4:59', 'description' => '19.9 км · 1:39:10'],
                ],
            ]],
        ];
        $trainingState = [
            'race_distance' => 'marathon',
            'goal_pace_sec' => 299,
            'training_paces' => ['marathon' => 299],
        ];
        $user = ['race_distance' => 'marathon'];

        $result = $method->invoke($service, $plan, $trainingState, $user);
        $raceDay = $result['weeks'][0]['days'][6];

        $this->assertSame(42.2, (float) ($raceDay['distance_km'] ?? 0.0));
        $this->assertSame('4:59', $raceDay['pace'] ?? null);
        $this->assertSame(48.2, (float) ($result['weeks'][0]['actual_volume_km'] ?? 0.0));
        $this->assertGreaterThanOrEqual(42.2, (float) ($result['weeks'][0]['target_volume_km'] ?? 0.0));
    }

    public function test_saveRecalculatedPlan_preserves_past_days_of_current_week_before_mutable_date(): void {
        $userId = $this->createTestUser();

        $weekStmt = $this->db->prepare(
            'INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)'
        );
        $weekNumber = 1;
        $startDate = '2026-04-20';
        $totalVolume = 20.0;
        $weekStmt->bind_param('iisd', $userId, $weekNumber, $startDate, $totalVolume);
        $weekStmt->execute();
        $weekId = (int) $this->db->insert_id;
        $weekStmt->close();

        $this->insertPlanDay($userId, $weekId, 1, 'easy', 'old monday', 0, '2026-04-20');
        $this->insertPlanDay($userId, $weekId, 2, 'walking', 'old tuesday walk', 0, '2026-04-21');
        $this->insertPlanDay($userId, $weekId, 3, 'rest', 'old wednesday', 0, '2026-04-22');
        $this->insertPlanDay($userId, $weekId, 4, 'rest', 'old thursday', 0, '2026-04-23');
        $this->insertPlanDay($userId, $weekId, 5, 'rest', 'old friday', 0, '2026-04-24');
        $this->insertPlanDay($userId, $weekId, 6, 'rest', 'old saturday', 0, '2026-04-25');
        $this->insertPlanDay($userId, $weekId, 7, 'rest', 'old sunday', 0, '2026-04-26');

        $newPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:10'],
                    ['type' => 'easy', 'distance_km' => 6.0, 'pace' => '5:10'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'control', 'distance_km' => 21.1, 'pace' => '4:42', 'notes' => 'control'],
                ],
            ]],
        ];

        saveRecalculatedPlan(
            $this->db,
            $userId,
            $newPlan,
            '2026-04-20',
            null,
            '2026-04-22'
        );

        $monday = $this->fetchPlanDay($userId, '2026-04-20');
        $tuesday = $this->fetchPlanDay($userId, '2026-04-21');
        $wednesday = $this->fetchPlanDay($userId, '2026-04-22');
        $thursday = $this->fetchPlanDay($userId, '2026-04-23');
        $sunday = $this->fetchPlanDay($userId, '2026-04-26');

        $this->assertSame('easy', $monday['type'] ?? null);
        $this->assertSame('walking', $tuesday['type'] ?? null);
        $this->assertSame('easy', $wednesday['type'] ?? null);
        $this->assertSame('easy', $thursday['type'] ?? null);
        $this->assertSame('control', $sunday['type'] ?? null);
    }

    private function createTestUser(): int {
        return $this->createTestUserWithGoal();
    }

    private function createPlanningUser(array $overrides = []): int {
        $suffix = bin2hex(random_bytes(4));
        $record = array_merge([
            'username' => 'planning_user_' . $suffix,
            'username_slug' => 'planning_user_' . $suffix,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'email' => 'planning_user_' . $suffix . '@example.com',
            'onboarding_completed' => 1,
            'training_mode' => 'self',
            'goal_type' => 'race',
            'gender' => 'male',
        ], $overrides);

        $columns = array_keys($record);
        $values = array_map(function ($value): string {
            if ($value === null) {
                return 'NULL';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            return "'" . $this->db->real_escape_string((string) $value) . "'";
        }, array_values($record));

        $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $this->db->query($sql);

        return (int) $this->db->insert_id;
    }

    private function createTestUserWithGoal(array $overrides = []): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'recalc_proc_' . $suffix;
        $slug = $username;
        $email = $username . '@example.com';
        $password = password_hash('secret123', PASSWORD_DEFAULT);
        $trainingMode = 'self';
        $goalType = (string) ($overrides['goal_type'] ?? 'race');
        $gender = 'male';
        $onboardingCompleted = 1;
        $raceDate = $overrides['race_date'] ?? null;
        $raceTargetTime = $overrides['race_target_time'] ?? null;
        $trainingStartDate = $overrides['training_start_date'] ?? null;

        $stmt = $this->db->prepare(
            'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender, race_date, race_target_time, training_start_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssissssss', $username, $slug, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender, $raceDate, $raceTargetTime, $trainingStartDate);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        $stmt->close();

        return $userId;
    }

    private function ensureActivityType(string $name): int {
        $stmt = $this->db->prepare('SELECT id FROM activity_types WHERE name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return (int) $row['id'];
        }

        $icon = '?';
        $color = 'primary';
        $isActive = 1;
        $sortOrder = 0;
        $insert = $this->db->prepare(
            'INSERT INTO activity_types (name, icon, color, is_active, sort_order) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->bind_param('sssii', $name, $icon, $color, $isActive, $sortOrder);
        $insert->execute();
        $id = (int) $this->db->insert_id;
        $insert->close();

        return $id;
    }

    private function insertWorkout(int $userId, string $activityType, string $startTime, string $endTime, float $distanceKm, int $durationMinutes): void {
        $stmt = $this->db->prepare(
            'INSERT INTO workouts (user_id, activity_type, start_time, end_time, duration_minutes, distance_km)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssid', $userId, $activityType, $startTime, $endTime, $durationMinutes, $distanceKm);
        $stmt->execute();
        $stmt->close();
    }

    private function insertWorkoutLog(int $userId, string $trainingDate, int $activityTypeId, float $distanceKm, int $durationMinutes): void {
        $dayName = strtolower((new \DateTimeImmutable($trainingDate))->format('D'));
        $weekNumber = 1;
        $isCompleted = 1;
        $isSuccessful = 1;

        $stmt = $this->db->prepare(
            'INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->bind_param(
            'isisiiidi',
            $userId,
            $trainingDate,
            $weekNumber,
            $dayName,
            $activityTypeId,
            $isCompleted,
            $isSuccessful,
            $distanceKm,
            $durationMinutes
        );
        $stmt->execute();
        $stmt->close();
    }

    private function insertPlanDay(
        int $userId,
        int $weekId,
        int $dayOfWeek,
        string $type,
        string $description,
        int $isKeyWorkout,
        string $date
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_days (user_id, week_id, day_of_week, type, description, is_key_workout, date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiissis', $userId, $weekId, $dayOfWeek, $type, $description, $isKeyWorkout, $date);
        $stmt->execute();
        $stmt->close();
    }

    private function insertPlanWeek(int $userId, int $weekNumber, string $startDate, float $totalVolume, array $types): void {
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iisd', $userId, $weekNumber, $startDate, $totalVolume);
        $stmt->execute();
        $weekId = (int) $this->db->insert_id;
        $stmt->close();

        foreach (array_values($types) as $index => $type) {
            $date = date('Y-m-d', strtotime($startDate . ' +' . $index . ' days'));
            $description = 'day ' . ($index + 1) . ' ' . $type;
            $isKeyWorkout = in_array($type, ['tempo', 'interval', 'fartlek', 'control', 'race'], true) ? 1 : 0;
            $this->insertPlanDay($userId, $weekId, $index + 1, $type, $description, $isKeyWorkout, $date);
        }
    }

    private function fetchPlanDay(int $userId, string $date): ?array {
        $stmt = $this->db->prepare(
            'SELECT type, description FROM training_plan_days WHERE user_id = ? AND date = ? LIMIT 1'
        );
        $stmt->bind_param('is', $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}
