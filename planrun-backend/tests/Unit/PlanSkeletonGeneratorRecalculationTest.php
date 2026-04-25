<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/skeleton/PlanSkeletonGenerator.php';
require_once __DIR__ . '/../../services/PlanGenerationProcessorService.php';

class PlanSkeletonGeneratorRecalculationTest extends TestCase {
    private \mysqli $db;
    private \PlanSkeletonGenerator $generator;
    private \ReflectionMethod $adjustStateForRecalculation;

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->db->begin_transaction();
        $this->generator = new \PlanSkeletonGenerator($this->db);
        $this->adjustStateForRecalculation = new \ReflectionMethod($this->generator, 'adjustStateForRecalculation');
        $this->adjustStateForRecalculation->setAccessible(true);
    }

    protected function tearDown(): void {
        $this->db->rollback();
        parent::tearDown();
    }

    public function test_adjustStateForRecalculation_reasonWithNemnogoDobavitIncreasesLoadInsteadOfReducing(): void {
        $state = [
            'weekly_base_km' => 40.0,
            'load_policy' => ['allowed_growth_ratio' => 1.10],
        ];

        $adjusted = $this->adjustStateForRecalculation->invoke(
            $this->generator,
            $state,
            [],
            ['reason' => 'можно немного добавить объем и пересчитать']
        );

        $this->assertSame(42.0, $adjusted['weekly_base_km']);
    }

    public function test_adjustStateForRecalculation_reasonWithStandaloneMnogoStillReducesLoad(): void {
        $state = [
            'weekly_base_km' => 40.0,
            'load_policy' => ['allowed_growth_ratio' => 1.10],
        ];

        $adjusted = $this->adjustStateForRecalculation->invoke(
            $this->generator,
            $state,
            [],
            ['reason' => 'в последнее время слишком много нагрузки, хочу мягче']
        );

        $this->assertSame(36.0, $adjusted['weekly_base_km']);
    }

    public function test_adjustStateForRecalculation_accepts_structured_reason_payload(): void {
        $state = [
            'weekly_base_km' => 40.0,
            'load_policy' => ['allowed_growth_ratio' => 1.10],
        ];

        $adjusted = $this->adjustStateForRecalculation->invoke(
            $this->generator,
            $state,
            [],
            ['reason' => ['felt' => 'тяжело', 'request' => 'хочу мягче']]
        );

        $this->assertSame(36.0, $adjusted['weekly_base_km']);
    }

    public function test_generate_uses_effective_weekly_base_after_long_break(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 40,
            'sessions_per_week' => 5,
            'race_distance' => '10k',
            'race_date' => '2026-06-14',
            'race_target_time' => '00:45:00',
            'easy_pace_sec' => 350,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'sat', 'sun']),
        ]);
        $lastWorkoutDate = gmdate('Y-m-d', strtotime('-35 days'));
        $this->insertWorkout($userId, 'running', $lastWorkoutDate . ' 07:00:00', $lastWorkoutDate . ' 08:00:00', 10.0, 60);

        $plan = $this->generator->generate($userId, 'generate');
        $state = $this->generator->getLastState();

        $this->assertSame(20.0, (float) ($state['weekly_base_km'] ?? 0.0));
        $this->assertContains('return_after_break', $state['special_population_flags'] ?? []);
        $this->assertLessThan(30.0, (float) ($plan['weeks'][0]['target_volume_km'] ?? 0.0));
    }

    public function test_generate_short_runway_marathon_taper_aligns_midweek_start_and_trims_race_week(): void {
        $startDate = gmdate('Y-m-d');
        $raceDate = gmdate('Y-m-d', strtotime('+13 days'));

        $userId = $this->createTestUser([
            'weekly_base_km' => 44.5,
            'sessions_per_week' => 6,
            'race_distance' => 'marathon',
            'race_date' => $raceDate,
            'race_target_time' => '03:30:00',
            'easy_pace_sec' => 330,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'tue', 'wed', 'thu', 'sat', 'sun']),
            'training_start_date' => $startDate,
        ]);

        $plan = $this->generator->generate($userId, 'generate');
        $meta = is_array($plan['_metadata'] ?? null) ? $plan['_metadata'] : [];

        $this->assertCount(3, $plan['weeks']);
        $this->assertSame(gmdate('Y-m-d', strtotime('monday this week')), $meta['schedule_anchor_date'] ?? null);

        $weekOne = $plan['weeks'][0];
        $weekTwo = $plan['weeks'][1];
        $weekThree = $plan['weeks'][2];

        $weekOneLong = array_values(array_filter(
            $weekOne['days'],
            static fn(array $day): bool => ($day['type'] ?? '') === 'long'
        ))[0] ?? null;
        $this->assertNotNull($weekOneLong);
        $this->assertGreaterThan(0.0, (float) ($weekOneLong['distance_km'] ?? 0.0));

        $weekOneRunDays = count(array_filter(
            $weekOne['days'],
            static fn(array $day): bool => in_array($day['type'] ?? 'rest', ['easy', 'long', 'tempo', 'interval', 'control', 'fartlek', 'race'], true)
        ));
        $weekTwoRunDays = count(array_filter(
            $weekTwo['days'],
            static fn(array $day): bool => in_array($day['type'] ?? 'rest', ['easy', 'long', 'tempo', 'interval', 'control', 'fartlek', 'race'], true)
        ));
        $weekThreeRunDays = count(array_filter(
            $weekThree['days'],
            static fn(array $day): bool => in_array($day['type'] ?? 'rest', ['easy', 'long', 'tempo', 'interval', 'control', 'fartlek', 'race'], true)
        ));

        $this->assertLessThanOrEqual(4, $weekOneRunDays);
        $this->assertLessThanOrEqual(4, $weekTwoRunDays);
        $this->assertLessThanOrEqual(3, $weekThreeRunDays);
        $this->assertContains('race', array_column($weekThree['days'], 'type'));
    }

    public function test_generate_first_marathon_twenty_week_plan_reaches_coach_peak_long_run(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 34.0,
            'sessions_per_week' => 5,
            'race_distance' => 'marathon',
            'race_date' => '2026-09-20',
            'race_target_time' => '04:15:00',
            'easy_pace_sec' => 390,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'sat', 'sun']),
            'training_start_date' => '2026-05-04',
            'is_first_race_at_distance' => 1,
        ]);

        $plan = $this->generator->generate($userId, 'generate');
        $weeks = $plan['weeks'] ?? [];

        $peakLong = 0.0;
        foreach ($weeks as $week) {
            foreach (($week['days'] ?? []) as $day) {
                if (($day['type'] ?? '') === 'long') {
                    $peakLong = max($peakLong, (float) ($day['distance_km'] ?? 0.0));
                }
            }
        }

        $this->assertGreaterThanOrEqual(18, count($weeks));
        $this->assertGreaterThanOrEqual(26.0, round($peakLong, 1));
        $this->assertLessThanOrEqual(30.0, round($peakLong, 1));
    }

    public function test_generate_recalculate_b_race_before_a_race_keeps_meaningful_support_volume(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 44.5,
            'sessions_per_week' => 6,
            'race_distance' => 'marathon',
            'race_date' => '2026-05-03',
            'race_target_time' => '03:30:00',
            'easy_pace_sec' => 330,
            'experience_level' => 'intermediate',
            'training_start_date' => '2026-04-20',
            'preferred_days' => json_encode(['mon', 'tue', 'wed', 'thu', 'sat', 'sun']),
            'last_race_distance' => 'half',
            'last_race_distance_km' => 21.1,
            'last_race_time' => '01:39:00',
            'last_race_date' => '2026-03-28',
            'is_first_race_at_distance' => 0,
        ]);

        $plan = $this->generator->generate($userId, 'recalculate', [
            'cutoff_date' => '2026-04-20',
            'actual_weekly_km_4w' => 44.5,
            'secondary_race_date' => '2026-04-26',
            'secondary_race_distance' => 'half',
            'secondary_race_type' => 'control',
            'secondary_race_target_time' => '01:39:00',
        ]);

        $scenario = $plan['_metadata']['planning_scenario'] ?? [];
        $this->assertSame('b_race_before_a_race', $scenario['primary'] ?? null);

        $weekOne = $plan['weeks'][0] ?? [];
        $weekTwo = $plan['weeks'][1] ?? [];
        $weekOneDays = $weekOne['days'] ?? [];
        $weekTwoDays = $weekTwo['days'] ?? [];

        $this->assertGreaterThanOrEqual(35.0, (float) ($weekOne['target_volume_km'] ?? 0.0));
        $this->assertGreaterThanOrEqual(55.0, (float) ($weekTwo['target_volume_km'] ?? 0.0));

        $weekOneEasy = array_values(array_filter(
            $weekOneDays,
            static fn(array $day): bool => ($day['type'] ?? '') === 'easy'
        ));
        $weekTwoEasy = array_values(array_filter(
            $weekTwoDays,
            static fn(array $day): bool => ($day['type'] ?? '') === 'easy'
        ));

        $this->assertCount(3, $weekOneEasy);
        $this->assertCount(3, $weekTwoEasy);

        $weekOneEasyDistances = array_map(
            static fn(array $day): float => round((float) ($day['distance_km'] ?? 0.0), 1),
            $weekOneEasy
        );
        sort($weekOneEasyDistances);
        $this->assertGreaterThanOrEqual(12.0, round(array_sum($weekOneEasyDistances), 1));
        $this->assertGreaterThanOrEqual(4.5, (float) end($weekOneEasyDistances));
        $this->assertLessThanOrEqual(4.2, (float) $weekOneEasyDistances[0]);
        $this->assertGreaterThanOrEqual(1.0, round(((float) end($weekOneEasyDistances)) - ((float) $weekOneEasyDistances[0]), 1));
        $weekTwoEasyDistances = array_map(
            static fn(array $day): float => round((float) ($day['distance_km'] ?? 0.0), 1),
            $weekTwoEasy
        );
        sort($weekTwoEasyDistances);
        $this->assertGreaterThanOrEqual(12.0, round(array_sum($weekTwoEasyDistances), 1));
        $this->assertGreaterThanOrEqual(5.0, (float) end($weekTwoEasyDistances));
        $this->assertLessThanOrEqual(4.2, (float) $weekTwoEasyDistances[0]);
        $this->assertGreaterThanOrEqual(1.2, round(((float) end($weekTwoEasyDistances)) - ((float) $weekTwoEasyDistances[0]), 1));

        $weekOneControl = array_values(array_filter(
            $weekOneDays,
            static fn(array $day): bool => ($day['type'] ?? '') === 'control'
        ))[0] ?? null;
        $weekTwoRace = array_values(array_filter(
            $weekTwoDays,
            static fn(array $day): bool => ($day['type'] ?? '') === 'race'
        ))[0] ?? null;

        $this->assertNotNull($weekOneControl);
        $this->assertNotNull($weekTwoRace);
        $this->assertSame(21.1, round((float) ($weekOneControl['distance_km'] ?? 0.0), 1));
        $this->assertSame(42.2, round((float) ($weekTwoRace['distance_km'] ?? 0.0), 1));
    }

    public function test_generate_low_base_novice_10k_plan_stays_recovery_focused(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 3.0,
            'sessions_per_week' => 4,
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:48:46',
            'easy_pace_sec' => 380,
            'experience_level' => 'novice',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'fri']),
            'training_start_date' => '2026-04-27',
            'is_first_race_at_distance' => 1,
        ]);

        $plan = $this->generator->generate($userId, 'generate');
        $state = $this->generator->getLastState();
        $weeks = $plan['weeks'] ?? [];
        $shareCap = (float) ($state['load_policy']['quality_workout_share_cap'] ?? 0.38);

        $this->assertCount(8, $weeks);
        foreach (array_slice($weeks, 0, 4) as $week) {
            $this->assertSame([], array_filter(
                $week['days'] ?? [],
                static fn(array $day): bool => in_array($day['type'] ?? '', ['tempo', 'interval', 'fartlek'], true)
            ));
        }

        foreach ($weeks as $week) {
            $actualVolume = (float) ($week['actual_volume_km'] ?? 0.0);
            foreach (($week['days'] ?? []) as $day) {
                if (!in_array($day['type'] ?? '', ['tempo', 'interval', 'fartlek'], true) || $actualVolume <= 0.0) {
                    continue;
                }
                $this->assertLessThanOrEqual(
                    round(($actualVolume * $shareCap) + 0.2, 1),
                    (float) ($day['distance_km'] ?? 0.0)
                );
            }
        }

        $raceWeekIndex = null;
        foreach ($weeks as $idx => $week) {
            if (in_array('race', array_column($week['days'] ?? [], 'type'), true)) {
                $raceWeekIndex = $idx;
                break;
            }
        }

        $this->assertNotNull($raceWeekIndex);
        $raceWeek = $weeks[$raceWeekIndex];
        $raceWeekRunDays = count(array_filter(
            $raceWeek['days'] ?? [],
            static fn(array $day): bool => in_array($day['type'] ?? '', ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race'], true)
        ));
        $this->assertLessThanOrEqual(3, $raceWeekRunDays);

        $postRaceWeek = $weeks[$raceWeekIndex + 1] ?? null;
        if ($postRaceWeek !== null) {
            $this->assertSame('rest', $postRaceWeek['days'][0]['type'] ?? null);
            $this->assertNotContains('long', array_column($postRaceWeek['days'] ?? [], 'type'));
        }
    }

    public function test_generate_low_base_first_5k_reaches_race_distance_long_run(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 8.0,
            'sessions_per_week' => 3,
            'race_distance' => '5k',
            'race_date' => '2026-06-14',
            'race_target_time' => '00:32:00',
            'easy_pace_sec' => 420,
            'experience_level' => 'novice',
            'preferred_days' => json_encode(['mon', 'wed', 'sun']),
            'training_start_date' => '2026-04-27',
            'is_first_race_at_distance' => 1,
        ]);

        $plan = $this->generator->generate($userId, 'generate');
        $weeks = $plan['weeks'] ?? [];

        $peakLong = 0.0;
        foreach ($weeks as $week) {
            foreach (($week['days'] ?? []) as $day) {
                if (($day['type'] ?? '') !== 'long') {
                    continue;
                }
                $peakLong = max($peakLong, (float) ($day['distance_km'] ?? 0.0));
            }
        }

        $this->assertCount(7, $weeks);
        $this->assertGreaterThanOrEqual(5.0, round($peakLong, 1));
        $this->assertLessThanOrEqual(12.0, max(array_map(
            static fn(array $week): float => (float) ($week['actual_volume_km'] ?? 0.0),
            $weeks
        )));
    }

    public function test_generate_recalculate_continues_detected_phase_from_processor_payload(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 30.0,
            'sessions_per_week' => 4,
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:45:00',
            'easy_pace_sec' => 350,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'wed', 'fri', 'sun']),
            'training_start_date' => '2026-02-23',
        ]);

        for ($i = 0; $i < 8; $i++) {
            $weekNumber = $i + 1;
            $startDate = gmdate('Y-m-d', strtotime('2026-02-23 +' . ($i * 7) . ' days'));
            $stmt = $this->db->prepare(
                'INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)'
            );
            $totalVolume = 22.0 + $i;
            $stmt->bind_param('iisd', $userId, $weekNumber, $startDate, $totalVolume);
            $stmt->execute();
            $stmt->close();
        }

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);
        $payload = $method->invoke($service, $userId, ['reason' => 'нужно продолжить текущий цикл']);

        $expectedPhase = $payload['current_phase']['phase'] ?? null;

        $this->assertNotNull($expectedPhase);
        $this->assertNotSame('base', $expectedPhase);

        $plan = $this->generator->generate($userId, 'recalculate', $payload);
        $weeks = $plan['weeks'] ?? [];

        $this->assertSame($expectedPhase, $weeks[0]['phase'] ?? null);

        $taperIndex = null;
        foreach ($weeks as $index => $week) {
            if (($week['phase'] ?? null) === 'taper') {
                $taperIndex = $index;
                break;
            }
        }

        $this->assertNotNull($taperIndex);
        $this->assertGreaterThan(0, $taperIndex);
        $lastPeakTarget = (float) ($weeks[$taperIndex - 1]['target_volume_km'] ?? 0.0);
        $firstTaperTarget = (float) ($weeks[$taperIndex]['target_volume_km'] ?? 0.0);
        $this->assertLessThanOrEqual($lastPeakTarget, $firstTaperTarget);
    }

    public function test_generate_recalculate_uses_progression_counters_to_continue_quality_sequence(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 30.0,
            'sessions_per_week' => 5,
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:45:00',
            'easy_pace_sec' => 350,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'sat', 'sun']),
            'training_start_date' => '2026-02-23',
        ]);

        for ($i = 0; $i < 8; $i++) {
            $weekNumber = $i + 1;
            $startDate = gmdate('Y-m-d', strtotime('2026-02-23 +' . ($i * 7) . ' days'));
            $stmt = $this->db->prepare(
                'INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)'
            );
            $totalVolume = 24.0 + $i;
            $stmt->bind_param('iisd', $userId, $weekNumber, $startDate, $totalVolume);
            $stmt->execute();
            $stmt->close();
        }

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);
        $payload = $method->invoke($service, $userId, ['reason' => 'продолжить текущий цикл']);

        $baselinePlan = $this->generator->generate($userId, 'recalculate', $payload);

        $continuedPayload = $payload;
        $continuedPayload['progression_counters'] = [
            'tempo_count' => 3,
            'interval_count' => 2,
            'fartlek_count' => 1,
            'race_pace_count' => 1,
        ];
        $continuedPayload['continuation_context']['progression_counters'] = $continuedPayload['progression_counters'];
        $continuedPlan = $this->generator->generate($userId, 'recalculate', $continuedPayload);

        $baselineTempo = $this->findFirstDayByType($baselinePlan['weeks'] ?? [], 'tempo');
        $continuedTempo = $this->findFirstDayByType($continuedPlan['weeks'] ?? [], 'tempo');

        $this->assertNotNull($baselineTempo);
        $this->assertNotNull($continuedTempo);
        $this->assertArrayNotHasKey('subtype', $baselineTempo);
        $this->assertSame('race_pace', $continuedTempo['subtype'] ?? null);
        $this->assertNotSame($baselineTempo['pace'] ?? null, $continuedTempo['pace'] ?? null);
    }

    public function test_generate_recalculate_continuation_does_not_spike_after_recovery_week(): void {
        $currentPhase = [
            'phase' => 'build',
            'phase_label' => 'Развивающий',
            'weeks_into_phase' => 2,
            'weeks_left_in_phase' => 3,
            'next_phase' => 'peak',
            'next_phase_label' => 'Пиковый',
            'remaining_phases' => [
                [
                    'name' => 'build',
                    'label' => 'Развивающий',
                    'weeks_from' => 4,
                    'weeks_to' => 8,
                    'max_key_workouts' => 2,
                ],
                [
                    'name' => 'peak',
                    'label' => 'Пиковый',
                    'weeks_from' => 9,
                    'weeks_to' => 11,
                    'max_key_workouts' => 3,
                ],
                [
                    'name' => 'taper',
                    'label' => 'Подводка',
                    'weeks_from' => 12,
                    'weeks_to' => 13,
                    'max_key_workouts' => 1,
                ],
            ],
            'long_run_progression' => [
                6 => 14,
                7 => 15,
                8 => 12,
                9 => 15,
                10 => 16,
                11 => 13,
                12 => 9,
                13 => 8,
            ],
            'recovery_weeks' => [8],
            'control_weeks' => [7],
            'peak_volume_km' => 37,
            'start_volume_km' => 24,
        ];

        $method = new \ReflectionMethod($this->generator, 'buildContinuationLoadPolicy');
        $method->setAccessible(true);
        $policy = $method->invoke(
            $this->generator,
            [
                'allowed_growth_ratio' => 1.12,
                'recovery_cutback_ratio' => 0.78,
                'start_volume_km' => 24.0,
                'peak_volume_km' => 37.0,
            ],
            [
                'weekly_base_km' => 20.6,
            ],
            [
                'mode' => 'recalculate',
                'kept_weeks' => 5,
                'current_phase' => $currentPhase,
            ],
            '10k'
        );

        $targets = $policy['weekly_volume_targets_km'] ?? [];
        $recoveryWeeks = $policy['recovery_weeks'] ?? [];

        $this->assertSame([3], $recoveryWeeks);
        $weekThreeTarget = (float) ($targets[3] ?? 0.0);
        $weekFourTarget = (float) ($targets[4] ?? 0.0);
        $weekFiveTarget = (float) ($targets[5] ?? 0.0);

        $this->assertLessThanOrEqual(
            round(($weekThreeTarget * 1.12) + 0.1, 1),
            $weekFourTarget
        );
        $this->assertLessThan(23.0, $weekFourTarget);
        $this->assertLessThanOrEqual(
            round(($weekFourTarget * 1.12) + 0.1, 1),
            $weekFiveTarget
        );
    }

    public function test_generate_recalculate_after_completed_race_returns_post_goal_recovery_plan(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 30.0,
            'sessions_per_week' => 4,
            'race_distance' => '10k',
            'race_date' => '2026-05-24',
            'race_target_time' => '00:52:00',
            'easy_pace_sec' => 395,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'wed', 'fri', 'sun']),
            'training_start_date' => '2026-04-27',
        ]);

        $plan = $this->generator->generate($userId, 'recalculate', [
            'cutoff_date' => '2026-05-25',
            'actual_weekly_km_4w' => 30.7,
            'kept_weeks' => 4,
            'reason' => 'забег выполнен, нужен аккуратный пересчет после старта',
        ]);

        $this->assertCount(4, $plan['weeks'] ?? []);
        $this->assertSame('health', $plan['_metadata']['goal_type'] ?? null);
        $this->assertSame('post_goal_recovery', $plan['_metadata']['planning_scenario']['primary'] ?? null);
        $this->assertContains('post_goal_recovery', $plan['_metadata']['planning_scenario']['flags'] ?? []);
        $this->assertSame('2026-05-24', $plan['_metadata']['completed_goal_context']['race_date'] ?? null);

        foreach ($plan['weeks'] as $week) {
            $this->assertNotContains('race', array_column($week['days'] ?? [], 'type'));
        }
    }

    public function test_adjustStateForRecalculation_insertRecovery_sets_protective_policy_flags(): void {
        $state = [
            'vdot' => 42.0,
            'weekly_base_km' => 32.0,
            'sessions_per_week' => 5,
            'load_policy' => [
                'allowed_growth_ratio' => 1.10,
                'quality_delay_weeks' => 0,
                'quality_workout_share_cap' => 0.50,
                'recovery_cutback_ratio' => 0.88,
            ],
            'pace_rules' => [
                'easy_min_sec' => 340,
                'easy_max_sec' => 360,
                'tempo_sec' => 300,
                'interval_sec' => 280,
            ],
        ];

        $adjusted = $this->adjustStateForRecalculation->invoke(
            $this->generator,
            $state,
            [],
            [
                'adaptation_type' => 'insert_recovery',
                'adaptation_metrics' => ['actual_volume_km' => 18.0],
            ]
        );

        $this->assertTrue((bool) ($adjusted['load_policy']['force_initial_recovery_week'] ?? false));
        $this->assertSame(3, (int) ($adjusted['load_policy']['initial_recovery_run_day_cap'] ?? 0));
        $this->assertSame('simplified', $adjusted['load_policy']['quality_mode'] ?? null);
        $this->assertSame(1, (int) ($adjusted['load_policy']['quality_delay_weeks'] ?? 0));
        $this->assertSame(1.04, (float) ($adjusted['load_policy']['allowed_growth_ratio'] ?? 0.0));
        $this->assertSame(17.1, (float) ($adjusted['weekly_base_km'] ?? 0.0));
        $this->assertSame('insert_recovery', $adjusted['adaptation_context']['type'] ?? null);
    }

    public function test_generate_next_plan_uses_payload_anchor_and_recent_base(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 3.0,
            'sessions_per_week' => 4,
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:48:46',
            'easy_pace_sec' => 380,
            'experience_level' => 'novice',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'fri']),
            'training_start_date' => '2026-04-27',
        ]);

        $plan = $this->generator->generate($userId, 'next_plan', [
            'cutoff_date' => '2026-04-20',
            'last_plan_avg_km' => 8.0,
        ]);
        $state = $this->generator->getLastState();

        $this->assertSame('2026-04-20', $plan['_metadata']['schedule_anchor_date'] ?? null);
        $this->assertSame(8.0, (float) ($state['weekly_base_km'] ?? 0.0));
    }

    public function test_generate_next_plan_after_completed_race_returns_post_goal_recovery_plan(): void {
        $userId = $this->createTestUser([
            'weekly_base_km' => 28.0,
            'sessions_per_week' => 4,
            'race_distance' => '10k',
            'race_date' => '2026-05-24',
            'race_target_time' => '00:52:00',
            'easy_pace_sec' => 395,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'wed', 'fri', 'sun']),
            'training_start_date' => '2026-04-27',
        ]);

        $plan = $this->generator->generate($userId, 'next_plan', [
            'cutoff_date' => '2026-05-25',
            'last_plan_avg_km' => 27.5,
            'goals' => 'новый восстановительный блок после завершенного старта',
        ]);

        $this->assertCount(4, $plan['weeks'] ?? []);
        $this->assertSame('health', $plan['_metadata']['goal_type'] ?? null);
        $this->assertSame('post_goal_recovery', $plan['_metadata']['planning_scenario']['primary'] ?? null);
        $this->assertContains('post_goal_recovery', $plan['_metadata']['planning_scenario']['flags'] ?? []);
        $this->assertSame('2026-05-24', $plan['_metadata']['completed_goal_context']['race_date'] ?? null);
        $this->assertSame('2026-05-25', $plan['_metadata']['schedule_anchor_date'] ?? null);
    }

    private function createTestUser(array $fields = []): int {
        $suffix = bin2hex(random_bytes(4));
        $record = array_merge([
            'username' => 'skeleton_break_' . $suffix,
            'username_slug' => 'skeleton_break_' . $suffix,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'email' => 'skeleton_break_' . $suffix . '@example.com',
            'onboarding_completed' => 1,
            'training_mode' => 'self',
            'goal_type' => 'race',
            'gender' => 'male',
            'training_start_date' => '2026-04-14',
        ], $fields);

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

    private function findFirstDayByType(array $weeks, string $type): ?array
    {
        foreach ($weeks as $week) {
            foreach (($week['days'] ?? []) as $day) {
                if (($day['type'] ?? null) === $type) {
                    return $day;
                }
            }
        }

        return null;
    }
}
