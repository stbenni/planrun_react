<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/plan_normalizer.php';

class PlanNormalizerTest extends TestCase {
    public function test_normalizeTrainingPlan_movesLongToLastPreferredWeekendDay(): void {
        $rawPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'interval', 'warmup_km' => 2, 'cooldown_km' => 1.5, 'reps' => 5, 'interval_m' => 1000, 'rest_m' => 400, 'rest_type' => 'jog', 'interval_pace' => '4:25'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'tempo', 'distance_km' => 10, 'pace' => '4:45'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'long', 'distance_km' => 28, 'pace' => '5:45'],
                    ['type' => 'rest'],
                ],
            ]],
        ];

        $preferences = [
            'sessions_per_week' => 6,
            'preferred_days' => ['tue', 'wed', 'sun', 'thu', 'sat', 'mon'],
            'preferred_ofp_days' => [],
        ];

        $normalized = normalizeTrainingPlan($rawPlan, '2026-03-09', 0, $preferences);
        $days = $normalized['weeks'][0]['days'];

        $this->assertSame('rest', $days[4]['type'], 'Friday must remain the rest day.');
        $this->assertSame('long', $days[6]['type'], 'Long run should move to Sunday as the latest preferred weekend day.');
        $this->assertNotSame('long', $days[5]['type'], 'Saturday should no longer hold the long run after normalization.');
    }

    public function test_normalizeTrainingPlan_movesLongToLatestPreferredDayWhenNoWeekendSelected(): void {
        $rawPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'long', 'distance_km' => 18, 'pace' => '5:45'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                ],
            ]],
        ];

        $preferences = [
            'sessions_per_week' => 4,
            'preferred_days' => ['mon', 'tue', 'wed', 'thu'],
            'preferred_ofp_days' => [],
        ];

        $normalized = normalizeTrainingPlan($rawPlan, '2026-03-09', 0, $preferences);
        $days = $normalized['weeks'][0]['days'];

        $this->assertSame('easy', $days[2]['type']);
        $this->assertSame('long', $days[3]['type'], 'Long run should move to the latest preferred weekday when weekends are unavailable.');
    }

    public function test_normalizeTrainingPlan_keepsRaceOnPreferredLongDay(): void {
        $rawPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 5, 'pace' => '5:30'],
                    ['type' => 'easy', 'distance_km' => 5, 'pace' => '5:30'],
                    ['type' => 'easy', 'distance_km' => 5, 'pace' => '5:30'],
                    ['type' => 'easy', 'distance_km' => 5, 'pace' => '5:30'],
                    ['type' => 'rest'],
                    ['type' => 'long', 'distance_km' => 10, 'pace' => '5:45'],
                    ['type' => 'race', 'distance_km' => 42.2],
                ],
            ]],
        ];

        $preferences = [
            'sessions_per_week' => 6,
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
            'preferred_ofp_days' => [],
        ];

        $normalized = normalizeTrainingPlan($rawPlan, '2026-04-27', 0, $preferences);
        $days = $normalized['weeks'][0]['days'];

        $this->assertSame('race', $days[6]['type'], 'Race day must remain on Sunday.');
        $this->assertSame('long', $days[5]['type'], 'Long run should stay on Saturday when Sunday is reserved for race.');
    }

    public function test_normalizeTrainingPlan_alignsQualityDaysToSkeletonAndRecomputesDates(): void {
        $rawPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'date' => '2026-03-09', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'interval', 'date' => '2026-03-10', 'warmup_km' => 2, 'cooldown_km' => 1.5, 'reps' => 5, 'interval_m' => 800, 'rest_m' => 400, 'rest_type' => 'jog', 'interval_pace' => '4:20'],
                    ['type' => 'easy', 'date' => '2026-03-11', 'distance_km' => 7, 'pace' => '5:35'],
                    ['type' => 'tempo', 'date' => '2026-03-12', 'distance_km' => 10, 'pace' => '4:45'],
                    ['type' => 'rest', 'date' => '2026-03-13'],
                    ['type' => 'long', 'date' => '2026-03-14', 'distance_km' => 22, 'pace' => '5:45'],
                    ['type' => 'rest', 'date' => '2026-03-15'],
                ],
            ]],
        ];

        $skeleton = [
            'weeks' => [[
                'days' => ['easy', 'tempo', 'easy', 'interval', 'rest', 'long', 'rest'],
            ]],
        ];

        $normalized = normalizeTrainingPlan($rawPlan, '2026-03-09', 0, null, $skeleton);
        $days = $normalized['weeks'][0]['days'];

        $this->assertSame('tempo', $days[1]['type']);
        $this->assertSame('2026-03-10', $days[1]['date'], 'Tuesday date must be recomputed after swap.');
        $this->assertSame('interval', $days[3]['type']);
        $this->assertSame('2026-03-12', $days[3]['date'], 'Thursday date must be recomputed after swap.');
    }

    public function test_normalizeTrainingPlan_enforcesLongAndRacePlacementFromSkeleton(): void {
        $rawPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 6, 'pace' => '5:35'],
                    ['type' => 'easy', 'distance_km' => 6, 'pace' => '5:35'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'rest'],
                    ['type' => 'race', 'distance_km' => 10],
                    ['type' => 'easy', 'distance_km' => 5, 'pace' => '5:40'],
                    ['type' => 'long', 'distance_km' => 18, 'pace' => '5:45'],
                ],
            ]],
        ];

        $skeleton = [
            'weeks' => [[
                'days' => ['easy', 'easy', 'easy', 'rest', 'easy', 'long', 'race'],
            ]],
        ];

        $normalized = normalizeTrainingPlan($rawPlan, '2026-03-09', 0, null, $skeleton);
        $days = $normalized['weeks'][0]['days'];

        $this->assertSame('easy', $days[4]['type'], 'Friday should no longer contain the race after skeleton repair.');
        $this->assertSame('long', $days[5]['type'], 'Long run should move to Saturday.');
        $this->assertSame('race', $days[6]['type'], 'Race should move to Sunday.');
    }

    public function test_normalizeTrainingPlan_coercesMissingSkeletonWorkoutType(): void {
        $rawPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 6, 'pace' => '5:35'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 7, 'pace' => '5:35'],
                    ['type' => 'rest'],
                    ['type' => 'long', 'distance_km' => 20, 'pace' => '5:45'],
                    ['type' => 'rest'],
                ],
            ]],
        ];

        $skeleton = [
            'weeks' => [[
                'days' => ['easy', 'tempo', 'rest', 'easy', 'rest', 'long', 'rest'],
            ]],
        ];

        $normalized = normalizeTrainingPlan($rawPlan, '2026-03-09', 0, null, $skeleton);
        $days = $normalized['weeks'][0]['days'];

        $this->assertSame('tempo', $days[1]['type'], 'Missing quality workout should be coerced to the skeleton type.');
        $this->assertTrue($days[1]['is_key_workout'], 'Coerced tempo day must remain a key workout.');
    }

    public function test_applyTrainingStatePaceRepairs_clampsSimpleRunPacesToPolicy(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'start_date' => '2026-03-09',
                'total_volume' => 36.0,
                'days' => [
                    [
                        'date' => '2026-03-09',
                        'day_of_week' => 1,
                        'type' => 'easy',
                        'description' => 'Лёгкий бег: 8 км, темп 6:00',
                        'distance_km' => 8.0,
                        'duration_minutes' => 48,
                        'pace' => '6:00',
                        'is_key_workout' => false,
                        'exercises' => [[
                            'category' => 'run',
                            'name' => 'Бег 8.0 км',
                            'distance_m' => 8000,
                            'duration_sec' => 2880,
                            'sets' => null,
                            'reps' => null,
                            'weight_kg' => null,
                            'pace' => '6:00',
                            'notes' => 'old',
                            'order_index' => 0,
                        ]],
                    ],
                    [
                        'date' => '2026-03-10',
                        'day_of_week' => 2,
                        'type' => 'tempo',
                        'description' => 'Темповый бег: 8 км, темп 4:10',
                        'distance_km' => 8.0,
                        'duration_minutes' => 33,
                        'pace' => '4:10',
                        'is_key_workout' => true,
                        'exercises' => [],
                    ],
                    [
                        'date' => '2026-03-15',
                        'day_of_week' => 7,
                        'type' => 'long',
                        'description' => 'Длительный бег: 20 км, темп 6:55',
                        'distance_km' => 20.0,
                        'duration_minutes' => 138,
                        'pace' => '7:20',
                        'is_key_workout' => true,
                        'exercises' => [],
                    ],
                ],
            ]],
            'warnings' => [],
        ];

        $trainingState = [
            'pace_rules' => [
                'easy_min_sec' => 363,
                'easy_max_sec' => 399,
                'long_min_sec' => 373,
                'long_max_sec' => 424,
                'tempo_sec' => 308,
                'tempo_tolerance_sec' => 8,
            ],
        ];

        $repaired = applyTrainingStatePaceRepairs($normalized, $trainingState);
        $days = $repaired['weeks'][0]['days'];

        $this->assertSame('6:03', $days[0]['pace'], 'Easy pace should clamp to the lower bound of the allowed range.');
        $this->assertSame('6:03', $days[0]['exercises'][0]['pace'], 'Run exercise pace should stay in sync with repaired day pace.');
        $this->assertSame('5:08', $days[1]['pace'], 'Tempo pace should snap to the target tempo pace when far outside tolerance.');
        $this->assertSame('7:04', $days[2]['pace'], 'Long pace should clamp to the upper bound of the allowed range.');
    }

    public function test_applyControlWorkoutFallback_for_marathon_keeps_control_as_benchmark_not_mp_work(): void {
        $day = [
            'type' => 'control',
            'distance_km' => null,
            'warmup_km' => null,
            'cooldown_km' => null,
            'notes' => '',
            'pace' => null,
        ];

        $trainingState = [
            'race_distance' => 'marathon',
            'training_paces' => [
                'marathon' => 302,
            ],
        ];

        $repaired = applyControlWorkoutFallback($day, $trainingState);

        $this->assertSame(8.0, $repaired['distance_km']);
        $this->assertNull($repaired['pace']);
        $this->assertStringContainsString('контрольный непрерывный забег', $repaired['notes']);
        $this->assertStringNotContainsString('целевого марафонского темпа', $repaired['notes']);
    }

    public function test_applyTrainingStatePaceRepairs_keeps_goal_specific_marathon_tempo_near_goal_pace(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'days' => [[
                    'date' => '2026-03-17',
                    'type' => 'tempo',
                    'pace' => '5:00',
                    'notes' => '2 км разминка, затем 10 км в целевом темпе марафона, 1.5 км заминка',
                    'description' => '',
                    'exercises' => [],
                ]],
            ]],
        ];

        $trainingState = [
            'race_distance' => 'marathon',
            'goal_pace_sec' => 298,
            'pace_rules' => [
                'easy_min_sec' => 360,
                'easy_max_sec' => 390,
                'long_min_sec' => 370,
                'long_max_sec' => 415,
                'tempo_sec' => 282,
                'tempo_tolerance_sec' => 8,
            ],
        ];

        $repaired = applyTrainingStatePaceRepairs($normalized, $trainingState);
        $tempoDay = $repaired['weeks'][0]['days'][0];

        $this->assertSame('5:00', $tempoDay['pace']);
    }

    public function test_applyTrainingStateLoadRepairs_capsAggressiveSpikeWithoutBreakingLongRun(): void {
        $normalized = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'start_date' => '2026-03-09',
                    'total_volume' => 20.0,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 6.0, 'pace' => '6:20', 'duration_minutes' => 38, 'description' => '', 'exercises' => []],
                        ['type' => 'tempo', 'distance_km' => 6.0, 'pace' => '5:10', 'duration_minutes' => 31, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 8.0, 'pace' => '6:25', 'duration_minutes' => 51, 'description' => '', 'exercises' => []],
                    ],
                ],
                [
                    'week_number' => 2,
                    'start_date' => '2026-03-16',
                    'total_volume' => 28.0,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 10.0, 'pace' => '6:18', 'duration_minutes' => 63, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 8.0, 'pace' => '6:20', 'duration_minutes' => 51, 'description' => '', 'exercises' => []],
                        ['type' => 'long', 'distance_km' => 10.0, 'pace' => '6:35', 'duration_minutes' => 66, 'description' => '', 'exercises' => []],
                    ],
                ],
            ],
            'warnings' => [],
        ];

        $repaired = applyTrainingStateLoadRepairs($normalized, [
            'readiness' => 'normal',
            'load_policy' => [
                'allowed_growth_ratio' => 1.10,
                'recovery_cutback_ratio' => 0.88,
                'race_week_ratio' => 1.00,
                'pre_race_taper_ratio' => 1.00,
                'recovery_weeks' => [],
                'weekly_volume_targets_km' => [],
                'long_run_targets_km' => [2 => 10.0],
            ],
        ]);

        $this->assertSame(22.5, $repaired['weeks'][1]['total_volume']);
        $this->assertSame(10.0, $repaired['weeks'][1]['days'][2]['distance_km'], 'Long run should be preserved when easy days can absorb the cutback.');
        $this->assertSame(5.0, $repaired['weeks'][1]['days'][0]['distance_km']);
        $this->assertSame(7.5, $repaired['weeks'][1]['days'][1]['distance_km']);
        $this->assertNotEmpty($repaired['warnings']);
    }

    public function test_applyTrainingStateLoadRepairs_prefers_easy_and_long_cuts_before_interval(): void {
        $normalized = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'start_date' => '2026-03-09',
                    'total_volume' => 39.5,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 8.0, 'pace' => '6:15', 'duration_minutes' => 50, 'description' => '', 'exercises' => []],
                        ['type' => 'tempo', 'distance_km' => 7.0, 'pace' => '5:08', 'duration_minutes' => 36, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 6.5, 'pace' => '6:18', 'duration_minutes' => 41, 'description' => '', 'exercises' => []],
                        ['type' => 'interval', 'distance_km' => 8.0, 'duration_minutes' => 42, 'description' => 'Интервалы', 'exercises' => [['category' => 'run', 'name' => 'Бег 8.0 км', 'distance_m' => 8000, 'duration_sec' => 2520, 'notes' => 'Интервалы']]],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'long', 'distance_km' => 10.0, 'pace' => '6:30', 'duration_minutes' => 65, 'description' => '', 'exercises' => []],
                    ],
                ],
                [
                    'week_number' => 2,
                    'start_date' => '2026-03-16',
                    'total_volume' => 52.0,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 4.8, 'pace' => '6:03', 'duration_minutes' => 29, 'description' => '', 'exercises' => []],
                        ['type' => 'tempo', 'distance_km' => 6.4, 'pace' => '5:08', 'duration_minutes' => 33, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 4.8, 'pace' => '6:03', 'duration_minutes' => 29, 'description' => '', 'exercises' => []],
                        ['type' => 'interval', 'distance_km' => 13.5, 'duration_minutes' => 68, 'description' => 'Разминка: 2 км. 5×1600м ...', 'exercises' => [['category' => 'run', 'name' => 'Бег 13.5 км', 'distance_m' => 13500, 'duration_sec' => 4080, 'notes' => 'old']]],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'long', 'distance_km' => 22.5, 'pace' => '6:13', 'duration_minutes' => 140, 'description' => '', 'exercises' => []],
                    ],
                ],
            ],
            'warnings' => [],
        ];

        $repaired = applyTrainingStateLoadRepairs($normalized, [
            'readiness' => 'normal',
            'load_policy' => [
                'allowed_growth_ratio' => 1.10,
                'recovery_cutback_ratio' => 0.88,
                'race_week_ratio' => 1.00,
                'pre_race_taper_ratio' => 1.00,
                'recovery_weeks' => [],
                'weekly_volume_targets_km' => [2 => 35.0],
                'long_run_targets_km' => [2 => 22.5],
            ],
        ]);

        $this->assertSame(41.8, $repaired['weeks'][1]['total_volume']);
        $this->assertSame(13.5, $repaired['weeks'][1]['days'][3]['distance_km'], 'Interval volume should stay untouched while easier cuts are still available.');
        $this->assertLessThan(22.5, $repaired['weeks'][1]['days'][6]['distance_km'], 'Long run can absorb the last part of the cutback before interval volume is touched.');
        $this->assertLessThan(4.8, $repaired['weeks'][1]['days'][0]['distance_km']);
        $this->assertLessThan(6.4, $repaired['weeks'][1]['days'][1]['distance_km']);
    }

    public function test_applyTrainingStateLoadRepairs_strengthensPreRaceAndRaceWeekTaper(): void {
        $normalized = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'start_date' => '2026-04-06',
                    'total_volume' => 50.0,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 12.0, 'pace' => '6:15', 'duration_minutes' => 75, 'description' => '', 'exercises' => []],
                        ['type' => 'tempo', 'distance_km' => 10.0, 'pace' => '5:05', 'duration_minutes' => 51, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 10.0, 'pace' => '6:20', 'duration_minutes' => 63, 'description' => '', 'exercises' => []],
                        ['type' => 'long', 'distance_km' => 18.0, 'pace' => '6:35', 'duration_minutes' => 119, 'description' => '', 'exercises' => []],
                    ],
                ],
                [
                    'week_number' => 2,
                    'start_date' => '2026-04-13',
                    'total_volume' => 48.0,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 10.0, 'pace' => '6:18', 'duration_minutes' => 63, 'description' => '', 'exercises' => []],
                        ['type' => 'tempo', 'distance_km' => 8.0, 'pace' => '5:08', 'duration_minutes' => 41, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 10.0, 'pace' => '6:20', 'duration_minutes' => 63, 'description' => '', 'exercises' => []],
                        ['type' => 'long', 'distance_km' => 20.0, 'pace' => '6:38', 'duration_minutes' => 133, 'description' => '', 'exercises' => []],
                    ],
                ],
                [
                    'week_number' => 3,
                    'start_date' => '2026-04-20',
                    'total_volume' => 46.0,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 10.0, 'pace' => '6:15', 'duration_minutes' => 63, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 8.0, 'pace' => '6:18', 'duration_minutes' => 50, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 6.9, 'pace' => '6:20', 'duration_minutes' => 44, 'description' => '', 'exercises' => []],
                        ['type' => 'race', 'distance_km' => 21.1, 'pace' => '5:30', 'duration_minutes' => 116, 'description' => '', 'exercises' => []],
                    ],
                ],
            ],
            'warnings' => [],
        ];

        $repaired = applyTrainingStateLoadRepairs($normalized, [
            'goal_type' => 'race',
            'race_distance' => 'half',
            'load_policy' => [
                'allowed_growth_ratio' => 1.10,
                'recovery_cutback_ratio' => 0.88,
                'race_week_ratio' => 0.85,
                'pre_race_taper_ratio' => 0.92,
                'race_week_supplementary_ratio' => 0.45,
                'recovery_weeks' => [],
                'weekly_volume_targets_km' => [],
                'long_run_targets_km' => [2 => 18.0],
            ],
        ]);

        $this->assertSame(46.5, $repaired['weeks'][1]['total_volume']);
        $this->assertSame(42.5, $repaired['weeks'][2]['total_volume']);
        $this->assertSame(21.1, $repaired['weeks'][2]['days'][3]['distance_km'], 'Race distance must stay untouched.');
        $this->assertGreaterThanOrEqual(2, count($repaired['warnings']));
    }

    public function test_applyTrainingStateLoadRepairs_can_retrim_easy_days_after_first_cutback_pass(): void {
        $normalized = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'start_date' => '2026-03-30',
                    'total_volume' => 12.8,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '6:27', 'duration_minutes' => 19, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '6:27', 'duration_minutes' => 19, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'long', 'distance_km' => 6.8, 'pace' => '6:37', 'duration_minutes' => 45, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                    ],
                ],
                [
                    'week_number' => 2,
                    'start_date' => '2026-04-06',
                    'total_volume' => 15.4,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '6:27', 'duration_minutes' => 19, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '6:27', 'duration_minutes' => 19, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                        ['type' => 'long', 'distance_km' => 9.4, 'pace' => '6:37', 'duration_minutes' => 62, 'description' => '', 'exercises' => []],
                        ['type' => 'rest', 'distance_km' => null, 'description' => '', 'exercises' => []],
                    ],
                ],
            ],
            'warnings' => [],
        ];

        $repaired = applyTrainingStateLoadRepairs($normalized, [
            'readiness' => 'low',
            'load_policy' => [
                'allowed_growth_ratio' => 1.08,
                'recovery_cutback_ratio' => 0.88,
                'race_week_ratio' => 1.00,
                'pre_race_taper_ratio' => 1.00,
                'recovery_weeks' => [],
                'weekly_volume_targets_km' => [],
                'long_run_targets_km' => [2 => 11.0],
            ],
        ]);

        $this->assertSame(14.4, $repaired['weeks'][1]['total_volume']);
        $this->assertLessThan(3.0, $repaired['weeks'][1]['days'][0]['distance_km']);
        $this->assertNotEmpty($repaired['warnings']);
    }

    public function test_applyTrainingStateMinimumDistanceRepairs_raises_short_easy_days_for_personal_override(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 1,
                'start_date' => '2026-03-09',
                'total_volume' => 56.0,
                'days' => [
                    ['date' => '2026-03-09', 'day_of_week' => 1, 'type' => 'easy', 'distance_km' => 4.0, 'duration_minutes' => 26, 'pace' => '6:30', 'description' => '', 'is_key_workout' => false, 'exercises' => []],
                    ['date' => '2026-03-10', 'day_of_week' => 2, 'type' => 'tempo', 'distance_km' => 10.0, 'duration_minutes' => 55, 'pace' => '5:28', 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                    ['date' => '2026-03-11', 'day_of_week' => 3, 'type' => 'easy', 'distance_km' => 6.0, 'duration_minutes' => 39, 'pace' => '6:30', 'description' => '', 'is_key_workout' => false, 'exercises' => []],
                    ['date' => '2026-03-12', 'day_of_week' => 4, 'type' => 'easy', 'distance_km' => 8.0, 'duration_minutes' => 52, 'pace' => '6:30', 'description' => '', 'is_key_workout' => false, 'exercises' => []],
                    ['date' => '2026-03-13', 'day_of_week' => 5, 'type' => 'rest', 'distance_km' => null, 'duration_minutes' => null, 'pace' => null, 'description' => '', 'is_key_workout' => false, 'exercises' => []],
                    ['date' => '2026-03-14', 'day_of_week' => 6, 'type' => 'easy', 'distance_km' => 8.0, 'duration_minutes' => 52, 'pace' => '6:30', 'description' => '', 'is_key_workout' => false, 'exercises' => []],
                    ['date' => '2026-03-15', 'day_of_week' => 7, 'type' => 'long', 'distance_km' => 20.0, 'duration_minutes' => 135, 'pace' => '6:45', 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateMinimumDistanceRepairs($normalized, [
            'load_policy' => [
                'easy_build_min_km' => 10.0,
                'easy_recovery_min_km' => 8.0,
                'easy_taper_min_km' => 8.0,
                'recovery_weeks' => [],
            ],
        ]);

        $this->assertSame(10.0, $repaired['weeks'][0]['days'][0]['distance_km']);
        $this->assertSame(10.0, $repaired['weeks'][0]['days'][2]['distance_km']);
        $this->assertSame(10.0, $repaired['weeks'][0]['days'][3]['distance_km']);
        $this->assertSame(10.0, $repaired['weeks'][0]['days'][5]['distance_km']);
        $this->assertGreaterThan(56.0, $repaired['weeks'][0]['total_volume']);
        $this->assertNotEmpty($repaired['warnings']);
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_expands_generic_tempo_into_meaningful_structure(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 2,
                'start_date' => '2026-03-16',
                'total_volume' => 70.1,
                'days' => [
                    ['date' => '2026-03-16', 'day_of_week' => 1, 'type' => 'easy', 'distance_km' => 10.0, 'duration_minutes' => 58, 'pace' => '5:50', 'description' => '', 'is_key_workout' => false, 'exercises' => []],
                    ['date' => '2026-03-17', 'day_of_week' => 2, 'type' => 'tempo', 'distance_km' => null, 'duration_minutes' => null, 'pace' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                    ['date' => '2026-03-18', 'day_of_week' => 3, 'type' => 'easy', 'distance_km' => 10.0, 'duration_minutes' => 58, 'pace' => '5:50', 'description' => '', 'is_key_workout' => false, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'race_distance' => 'marathon',
            'pace_rules' => [
                'tempo_sec' => 282,
            ],
            'load_policy' => [
                'recovery_weeks' => [],
            ],
        ]);

        $tempoDay = $repaired['weeks'][0]['days'][1];
        $this->assertSame(11.5, $tempoDay['distance_km']);
        $this->assertSame(2.0, $tempoDay['warmup_km']);
        $this->assertSame(1.5, $tempoDay['cooldown_km']);
        $this->assertSame('4:42', $tempoDay['pace']);
        $this->assertStringContainsString('затем 8 км', $tempoDay['description']);
        $this->assertNotEmpty($tempoDay['exercises']);
        $this->assertNotEmpty($repaired['warnings']);
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_enriches_generic_control_day(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 3,
                'start_date' => '2026-03-23',
                'total_volume' => 60.0,
                'days' => [
                    ['date' => '2026-03-28', 'day_of_week' => 6, 'type' => 'control', 'distance_km' => 10.0, 'duration_minutes' => null, 'pace' => null, 'warmup_km' => null, 'cooldown_km' => null, 'notes' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'race_distance' => 'marathon',
            'training_paces' => [
                'marathon' => 298,
                'threshold' => 282,
            ],
        ]);

        $controlDay = $repaired['weeks'][0]['days'][0];
        $this->assertSame(2.0, $controlDay['warmup_km']);
        $this->assertSame(1.5, $controlDay['cooldown_km']);
        $this->assertStringContainsString('контрольный непрерывный забег', $controlDay['notes']);
        $this->assertStringNotContainsString('целевого марафонского темпа', $controlDay['notes']);
        $this->assertStringContainsString('разминка', mb_strtolower($controlDay['description']));
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_adds_segments_for_missing_fartlek_structure(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 4,
                'start_date' => '2026-03-30',
                'total_volume' => 64.0,
                'days' => [
                    ['date' => '2026-04-04', 'day_of_week' => 6, 'type' => 'fartlek', 'distance_km' => null, 'duration_minutes' => null, 'pace' => null, 'warmup_km' => null, 'cooldown_km' => null, 'segments' => null, 'notes' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'pace_rules' => [
                'interval_sec' => 255,
            ],
        ]);

        $fartlekDay = $repaired['weeks'][0]['days'][0];
        $this->assertNotEmpty($fartlekDay['segments']);
        $this->assertSame(8, $fartlekDay['segments'][0]['reps']);
        $this->assertSame(400, $fartlekDay['segments'][0]['distance_m']);
        $this->assertStringContainsString('8×400', $fartlekDay['description']);
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_adds_interval_structure_when_missing(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 2,
                'start_date' => '2026-03-16',
                'total_volume' => 60.0,
                'days' => [
                    ['date' => '2026-03-17', 'day_of_week' => 2, 'type' => 'interval', 'distance_km' => 6.0, 'duration_minutes' => null, 'pace' => null, 'warmup_km' => null, 'cooldown_km' => null, 'reps' => null, 'interval_m' => null, 'interval_pace' => null, 'rest_m' => null, 'rest_type' => null, 'notes' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'race_distance' => 'marathon',
            'pace_rules' => [
                'interval_sec' => 255,
            ],
        ]);

        $intervalDay = $repaired['weeks'][0]['days'][0];
        $this->assertSame(4, $intervalDay['reps']);
        $this->assertSame(2000, $intervalDay['interval_m']);
        $this->assertSame(600, $intervalDay['rest_m']);
        $this->assertSame('4:15', $intervalDay['interval_pace']);
        $this->assertStringContainsString('4×2000м', $intervalDay['description']);
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_enriches_generic_tempo_day(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 2,
                'start_date' => '2026-03-16',
                'total_volume' => 60.0,
                'days' => [
                    ['date' => '2026-03-19', 'day_of_week' => 4, 'type' => 'tempo', 'distance_km' => 3.0, 'duration_minutes' => null, 'pace' => '4:42', 'warmup_km' => null, 'cooldown_km' => null, 'notes' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'race_distance' => 'marathon',
            'pace_rules' => [
                'tempo_sec' => 282,
            ],
        ]);

        $tempoDay = $repaired['weeks'][0]['days'][0];
        $this->assertSame(2.0, $tempoDay['warmup_km']);
        $this->assertSame(1.5, $tempoDay['cooldown_km']);
        $this->assertSame(11.5, $tempoDay['distance_km']);
        $this->assertStringContainsString('затем 8 км', $tempoDay['description']);
        $this->assertStringContainsString('разминка', mb_strtolower($tempoDay['description']));
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_uses_week_contract_for_goal_specific_marathon_tempo(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 1,
                'start_date' => '2026-03-10',
                'total_volume' => 58.0,
                'days' => [
                    ['date' => '2026-03-10', 'day_of_week' => 2, 'type' => 'tempo', 'distance_km' => 6.0, 'duration_minutes' => null, 'pace' => '4:42', 'warmup_km' => null, 'cooldown_km' => null, 'notes' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'race_distance' => 'marathon',
            'goal_pace_sec' => 299,
            'pace_rules' => [
                'tempo_sec' => 282,
            ],
            'plan_intent_contract' => [
                'weeks' => [[
                    'week' => 1,
                    'contracts' => [
                        ['type' => 'tempo', 'intent' => 'goal_pace_specific', 'directive' => 'Tempo around marathon goal pace'],
                    ],
                ]],
            ],
        ]);

        $tempoDay = $repaired['weeks'][0]['days'][0];
        $this->assertSame('4:59', $tempoDay['pace']);
        $this->assertStringContainsString('целевого темпа', $tempoDay['description']);
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_respects_recalculate_week_offset_in_contract(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 18,
                'start_date' => '2026-03-23',
                'total_volume' => 60.0,
                'days' => [
                    ['date' => '2026-03-24', 'day_of_week' => 2, 'type' => 'tempo', 'distance_km' => 6.0, 'duration_minutes' => null, 'pace' => '4:42', 'warmup_km' => null, 'cooldown_km' => null, 'notes' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'race_distance' => 'marathon',
            'goal_pace_sec' => 299,
            'pace_rules' => [
                'tempo_sec' => 282,
            ],
            'plan_intent_contract' => [
                'week_number_offset' => 15,
                'weeks' => [[
                    'week' => 3,
                    'contracts' => [
                        ['type' => 'tempo', 'intent' => 'goal_pace_specific', 'directive' => 'Tempo around marathon goal pace'],
                    ],
                ]],
            ],
        ]);

        $tempoDay = $repaired['weeks'][0]['days'][0];
        $this->assertSame('4:59', $tempoDay['pace']);
        $this->assertStringContainsString('целевого темпа', $tempoDay['description']);
    }

    public function test_applyTrainingStateWorkoutDetailFallbacks_uses_shorter_interval_fallback_in_race_execution_week(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 23,
                'start_date' => '2026-04-27',
                'total_volume' => 42.0,
                'days' => [
                    ['date' => '2026-04-30', 'day_of_week' => 4, 'type' => 'interval', 'distance_km' => 6.0, 'duration_minutes' => null, 'pace' => null, 'warmup_km' => null, 'cooldown_km' => null, 'reps' => null, 'interval_m' => null, 'interval_pace' => null, 'rest_m' => null, 'rest_type' => null, 'notes' => null, 'description' => '', 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateWorkoutDetailFallbacks($normalized, [
            'race_distance' => 'marathon',
            'pace_rules' => [
                'interval_sec' => 255,
            ],
            'plan_intent_contract' => [
                'week_number_offset' => 15,
                'weeks' => [[
                    'week' => 8,
                    'theme' => 'race_execution',
                    'contracts' => [
                        ['type' => 'race', 'intent' => 'race_execution', 'directive' => 'Race week'],
                    ],
                ]],
            ],
        ]);

        $intervalDay = $repaired['weeks'][0]['days'][0];
        $this->assertSame(4, $intervalDay['reps']);
        $this->assertSame(400, $intervalDay['interval_m']);
        $this->assertSame(200, $intervalDay['rest_m']);
    }

    public function test_normalizeTrainingPlan_repairs_adjacent_key_workouts_when_no_skeleton(): void {
        $rawPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'tempo', 'distance_km' => 10, 'pace' => '4:45', 'is_key_workout' => true, 'notes' => 'tempo'],
                    ['type' => 'interval', 'warmup_km' => 2, 'cooldown_km' => 1.5, 'reps' => 5, 'interval_m' => 1000, 'rest_m' => 400, 'rest_type' => 'jog', 'interval_pace' => '4:15', 'is_key_workout' => true],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '6:00'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '6:00'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '6:00'],
                    ['type' => 'long', 'distance_km' => 24, 'pace' => '6:00', 'is_key_workout' => true],
                ],
            ]],
        ];

        $normalized = normalizeTrainingPlan($rawPlan, '2026-03-16');
        $days = $normalized['weeks'][0]['days'];

        $this->assertSame('tempo', $days[0]['type']);
        $this->assertSame('easy', $days[1]['type']);
        $this->assertSame('interval', $days[2]['type']);
    }

    public function test_applyTrainingStateLoadRepairs_simplifies_race_week_long_and_tempo(): void {
        $normalized = [
            'warnings' => [],
            'weeks' => [[
                'week_number' => 23,
                'start_date' => '2026-04-27',
                'total_volume' => 70.0,
                'days' => [
                    ['date' => '2026-04-28', 'day_of_week' => 2, 'type' => 'interval', 'warmup_km' => 2, 'cooldown_km' => 1.5, 'reps' => 4, 'interval_m' => 400, 'rest_m' => 200, 'rest_type' => 'jog', 'interval_pace' => '4:15', 'distance_km' => 4.4, 'is_key_workout' => true, 'exercises' => []],
                    ['date' => '2026-04-30', 'day_of_week' => 4, 'type' => 'tempo', 'distance_km' => 11.5, 'pace' => '4:59', 'warmup_km' => 2.0, 'cooldown_km' => 1.5, 'notes' => 'old', 'is_key_workout' => true, 'exercises' => []],
                    ['date' => '2026-05-02', 'day_of_week' => 6, 'type' => 'long', 'distance_km' => 6.8, 'pace' => '6:10', 'is_key_workout' => true, 'exercises' => []],
                    ['date' => '2026-05-03', 'day_of_week' => 7, 'type' => 'race', 'distance_km' => 42.2, 'is_key_workout' => true, 'exercises' => []],
                ],
            ]],
        ];

        $repaired = applyTrainingStateLoadRepairs($normalized, [
            'goal_pace_sec' => 299,
            'pace_rules' => [
                'easy_min_sec' => 340,
                'easy_max_sec' => 370,
            ],
        ]);

        $days = $repaired['weeks'][0]['days'];
        $this->assertSame('tempo', $days[1]['type']);
        $this->assertSame(6.0, $days[1]['distance_km']);
        $this->assertSame('easy', $days[2]['type']);
        $this->assertLessThanOrEqual(4.0, (float) $days[2]['distance_km']);
    }
}
