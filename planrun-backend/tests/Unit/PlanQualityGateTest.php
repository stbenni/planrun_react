<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanQualityGate.php';

class PlanQualityGateTest extends TestCase
{
    public function test_evaluate_blocks_tune_up_week_with_long_run_and_extra_quality(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'days' => [
                        ['type' => 'easy'],
                        ['type' => 'tempo', 'distance_km' => 6.0, 'duration_minutes' => 28, 'notes' => '3x2 км', 'exercises' => []],
                        ['type' => 'rest'],
                        ['type' => 'easy'],
                        ['type' => 'rest'],
                        ['type' => 'long', 'distance_km' => 16.0, 'pace' => '5:15'],
                        ['type' => 'race', 'distance_km' => 21.1, 'pace' => '4:42'],
                    ],
                ],
                [
                    'week_number' => 2,
                    'days' => [
                        ['type' => 'easy'],
                        ['type' => 'rest'],
                        ['type' => 'easy'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'race', 'distance_km' => 42.2, 'pace' => '4:59'],
                    ],
                ],
            ],
        ];

        $result = $gate->evaluate($plan, '2026-04-20', [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'planning_scenario' => [
                'flags' => ['b_race_before_a_race'],
                'tune_up_event' => [
                    'date' => '2026-04-26',
                    'week' => 1,
                ],
            ],
        ]);

        $codes = array_column($result['issues'], 'code');

        $this->assertTrue($result['should_block_save']);
        $this->assertContains('tune_up_event_not_downgraded_to_control', $codes);
        $this->assertContains('tune_up_week_has_extra_quality', $codes);
        $this->assertTrue((bool) ($result['repairs_applied'] ?? false));
    }

    public function test_evaluate_passes_controlled_tune_up_week(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 6.0, 'pace' => '5:20'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:20'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'control', 'distance_km' => 21.1, 'warmup_km' => 1.5, 'cooldown_km' => 1.5, 'pace' => '4:42'],
                    ],
                ],
                [
                    'week_number' => 2,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:20'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:20'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'race', 'distance_km' => 42.2, 'pace' => '4:59'],
                    ],
                ],
            ],
        ];

        $result = $gate->evaluate($plan, '2026-04-20', [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'planning_scenario' => [
                'flags' => ['b_race_before_a_race'],
                'tune_up_event' => [
                    'date' => '2026-04-26',
                    'week' => 1,
                ],
            ],
        ]);

        $codes = array_column($result['issues'], 'code');

        $this->assertFalse($result['should_block_save']);
        $this->assertNotContains('tune_up_event_not_downgraded_to_control', $codes);
        $this->assertNotContains('tune_up_week_contains_long_run', $codes);
        $this->assertNotContains('tune_up_week_has_extra_quality', $codes);
    }

    public function test_evaluate_relaxes_required_run_day_contract_for_short_taper_scenarios(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [[
                'week_number' => 1,
                'days' => [
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:20'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:20'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'race', 'distance_km' => 42.2, 'pace' => '4:59'],
                ],
            ]],
        ];

        $result = $gate->evaluate($plan, '2026-04-27', [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'sessions_per_week' => 6,
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
            'planning_scenario' => [
                'flags' => ['short_runway_taper', 'high_caution'],
            ],
        ], [
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
            'sessions_per_week' => 6,
            'planning_scenario' => [
                'flags' => ['short_runway_taper', 'high_caution'],
            ],
        ]);

        $codes = array_column($result['issues'], 'code');

        $this->assertNotContains('missing_run_on_required_day', $codes);
    }

    public function test_evaluate_relaxes_required_run_day_contract_for_race_week_run_day_cap(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [[
                'week_number' => 13,
                'days' => [
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:20'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:20'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'race', 'distance_km' => 10.0, 'pace' => '4:30'],
                ],
            ]],
        ];

        $result = $gate->evaluate($plan, '2026-06-15', [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'load_policy' => [
                'race_week_run_day_cap' => 3,
            ],
        ], [
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'sessions_per_week' => 5,
        ]);

        $codes = array_column($result['issues'], 'code');

        $this->assertNotContains('missing_run_on_required_day', $codes);
    }

    public function test_evaluate_relaxes_required_run_day_contract_for_any_race_week(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [[
                'week_number' => 5,
                'days' => [
                    ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:20'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:20'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'race', 'distance_km' => 10.0, 'pace' => '4:30'],
                ],
            ]],
        ];

        $result = $gate->evaluate($plan, '2026-06-15', [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
        ], [
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'sessions_per_week' => 5,
        ]);

        $codes = array_column($result['issues'], 'code');

        $this->assertNotContains('missing_run_on_required_day', $codes);
    }

    public function test_evaluate_allows_conservative_low_volume_10k_progression_without_false_spike_or_taper_issue(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'tempo', 'distance_km' => 6.0, 'pace' => '5:00', 'warmup_km' => 1.75, 'cooldown_km' => 1.25, 'tempo_km' => 3.0],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'long', 'distance_km' => 2.9, 'pace' => '6:30'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
                [
                    'week_number' => 2,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'tempo', 'distance_km' => 7.0, 'pace' => '5:00', 'warmup_km' => 1.75, 'cooldown_km' => 1.25, 'tempo_km' => 4.0],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'long', 'distance_km' => 3.2, 'pace' => '6:30'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
                [
                    'week_number' => 3,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'interval', 'warmup_km' => 1.75, 'cooldown_km' => 1.25, 'reps' => 6, 'interval_m' => 600, 'rest_m' => 400, 'rest_type' => 'walk', 'interval_pace' => '4:40'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'long', 'distance_km' => 3.0, 'pace' => '6:30'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
                [
                    'week_number' => 4,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'interval', 'warmup_km' => 1.75, 'cooldown_km' => 1.25, 'reps' => 5, 'interval_m' => 800, 'rest_m' => 400, 'rest_type' => 'jog', 'interval_pace' => '4:35'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'long', 'distance_km' => 2.9, 'pace' => '6:30'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
                [
                    'week_number' => 5,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'easy', 'distance_km' => 1.6, 'pace' => '6:20'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 1.8, 'pace' => '6:20'],
                        ['type' => 'easy', 'distance_km' => 1.5, 'pace' => '6:20'],
                        ['type' => 'rest'],
                        ['type' => 'race', 'distance_km' => 10.0, 'pace' => '4:53'],
                    ],
                ],
            ],
        ];

        $result = $gate->evaluate($plan, '2026-05-18', [
            'readiness' => 'low',
            'race_distance' => '10k',
            'load_policy' => [
                'race_week_supplementary_ratio' => 0.60,
            ],
        ]);

        $codes = array_column($result['issues'], 'code');

        $this->assertFalse($result['should_block_save']);
        $this->assertNotContains('weekly_volume_spike', $codes);
        $this->assertNotContains('taper_race_week_too_big', $codes);
    }

    public function test_evaluate_applies_deterministic_load_repairs_before_blocking_save(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '6:20'],
                        ['type' => 'tempo', 'distance_km' => 6.5, 'pace' => '5:00', 'warmup_km' => 2.0, 'cooldown_km' => 1.5, 'notes' => 'Пороговая работа'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 2.5, 'pace' => '6:20'],
                        ['type' => 'long', 'distance_km' => 4.0, 'pace' => '6:30'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
                [
                    'week_number' => 2,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 4.5, 'pace' => '6:20'],
                        ['type' => 'tempo', 'distance_km' => 8.0, 'pace' => '5:00', 'warmup_km' => 2.0, 'cooldown_km' => 1.5, 'notes' => 'Пороговая работа'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 3.8, 'pace' => '6:20'],
                        ['type' => 'long', 'distance_km' => 5.4, 'pace' => '6:30'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
            ],
        ];

        $result = $gate->evaluate($plan, '2026-04-20', [
            'readiness' => 'low',
            'race_distance' => '10k',
            'load_policy' => [
                'allowed_growth_ratio' => 1.08,
                'easy_min_km' => 1.5,
                'tempo_min_km' => 2.5,
                'long_min_km' => 5.0,
            ],
        ]);

        $weekVolumes = array_map(
            static fn(array $week): float => (float) ($week['total_volume'] ?? 0.0),
            $result['normalized_plan']['weeks'] ?? []
        );

        $this->assertFalse($result['should_block_save']);
        $this->assertTrue((bool) ($result['repairs_applied'] ?? false));
        $this->assertSame([], array_filter(
            $result['issues'],
            static fn(array $issue): bool => ($issue['code'] ?? '') === 'weekly_volume_spike'
        ));
        $this->assertSame(16.0, round($weekVolumes[0] ?? 0.0, 1));
        $this->assertSame(17.8, round($weekVolumes[1] ?? 0.0, 1));
        $this->assertLessThan(21.7, round($weekVolumes[1] ?? 0.0, 1));
    }

    public function test_evaluate_does_not_flag_week_after_recovery_when_it_only_rebounds_to_normal_load(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'is_recovery' => false,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 6.0, 'pace' => '5:45'],
                        ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:45'],
                        ['type' => 'long', 'distance_km' => 8.0, 'pace' => '6:00'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
                [
                    'week_number' => 2,
                    'is_recovery' => true,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 4.5, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'long', 'distance_km' => 9.0, 'pace' => '6:00'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
                [
                    'week_number' => 3,
                    'is_recovery' => false,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 5.5, 'pace' => '5:45'],
                        ['type' => 'tempo', 'distance_km' => 7.0, 'pace' => '4:35', 'warmup_km' => 2.0, 'cooldown_km' => 1.5, 'tempo_km' => 3.5],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 4.6, 'pace' => '5:45'],
                        ['type' => 'long', 'distance_km' => 8.0, 'pace' => '6:00'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                    ],
                ],
            ],
        ];

        $result = $gate->evaluate($plan, '2026-04-20', [
            'readiness' => 'normal',
            'race_distance' => '10k',
            'load_policy' => [
                'allowed_growth_ratio' => 1.10,
            ],
        ]);

        $this->assertFalse($result['should_block_save']);
        $this->assertSame([], array_filter(
            $result['issues'],
            static fn(array $issue): bool => ($issue['code'] ?? '') === 'weekly_volume_spike'
        ));
    }

    public function test_evaluate_preserves_recovery_week_metadata_under_schedule_enforcement(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'phase' => 'base',
                    'is_recovery' => false,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:45'],
                        ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 4.0, 'pace' => '5:45'],
                        ['type' => 'long', 'distance_km' => 8.0, 'pace' => '6:00'],
                    ],
                ],
                [
                    'week_number' => 2,
                    'phase' => 'build',
                    'is_recovery' => true,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 2.5, 'pace' => '5:45'],
                        ['type' => 'easy', 'distance_km' => 2.5, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 2.5, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 2.5, 'pace' => '5:45'],
                        ['type' => 'long', 'distance_km' => 8.5, 'pace' => '6:00'],
                    ],
                ],
                [
                    'week_number' => 3,
                    'phase' => 'build',
                    'is_recovery' => false,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 4.5, 'pace' => '5:45'],
                        ['type' => 'easy', 'distance_km' => 4.5, 'pace' => '5:45'],
                        ['type' => 'rest'],
                        ['type' => 'tempo', 'distance_km' => 5.5, 'pace' => '4:40', 'warmup_km' => 1.5, 'cooldown_km' => 1.0, 'tempo_km' => 3.0],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 4.5, 'pace' => '5:45'],
                        ['type' => 'long', 'distance_km' => 8.5, 'pace' => '6:00'],
                    ],
                ],
            ],
        ];

        $expectedSkeleton = [
            'weeks' => [
                ['week_number' => 1, 'phase' => 'base', 'is_recovery' => false, 'days' => ['easy', 'easy', 'rest', 'easy', 'rest', 'easy', 'long']],
                ['week_number' => 2, 'phase' => 'build', 'is_recovery' => true, 'days' => ['easy', 'easy', 'rest', 'easy', 'rest', 'easy', 'long']],
                ['week_number' => 3, 'phase' => 'build', 'is_recovery' => false, 'days' => ['easy', 'easy', 'rest', 'tempo', 'rest', 'easy', 'long']],
            ],
        ];

        $result = $gate->evaluate($plan, '2026-04-20', [
            'readiness' => 'normal',
            'race_distance' => '10k',
            'load_policy' => [
                'allowed_growth_ratio' => 1.10,
            ],
        ], [
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'user_preferences' => ['preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun']],
            'expected_skeleton' => $expectedSkeleton,
        ]);

        $weekTwo = $result['normalized_plan']['weeks'][1] ?? [];
        $codes = array_column($result['issues'], 'code');

        $this->assertArrayHasKey('is_recovery', $weekTwo);
        $this->assertTrue((bool) $weekTwo['is_recovery']);
        $this->assertNotContains('weekly_volume_spike', $codes);
    }

    public function test_evaluate_surfaces_goal_feasibility_warning_without_blocking_save(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [[
                'week_number' => 1,
                'days' => [
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '6:00'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '6:00'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '6:00'],
                    ['type' => 'long', 'distance_km' => 8.0, 'pace' => '6:20'],
                ],
            ]],
        ];

        $result = $gate->evaluate($plan, '2026-04-20', [
            'goal_realism' => [
                'verdict' => 'unrealistic',
                'messages' => [[
                    'type' => 'error',
                    'text' => 'Марафон через 8 недель при текущей базе слишком рискован.',
                    'suggestions' => [],
                ]],
            ],
        ]);

        $codes = array_column($result['issues'], 'code');

        $this->assertContains('goal_feasibility_unrealistic', $codes);
        $this->assertFalse($result['should_block_save']);
    }

    public function test_evaluate_downgrades_volume_spike_for_high_caution_scenario(): void
    {
        $gate = new \PlanQualityGate();

        $plan = [
            'weeks' => [
                [
                    'week_number' => 1,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 8.0, 'pace' => '6:20'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'long', 'distance_km' => 12.0, 'pace' => '6:40'],
                    ],
                ],
                [
                    'week_number' => 2,
                    'days' => [
                        ['type' => 'easy', 'distance_km' => 8.0, 'pace' => '6:20'],
                        ['type' => 'rest'],
                        ['type' => 'easy', 'distance_km' => 8.0, 'pace' => '6:20'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'rest'],
                        ['type' => 'long', 'distance_km' => 12.0, 'pace' => '6:40'],
                    ],
                ],
            ],
        ];

        $result = $gate->evaluate($plan, '2026-04-27', [
            'readiness' => 'low',
            'load_policy' => [
                'allowed_growth_ratio' => 1.08,
                'easy_min_km' => 8.0,
                'long_min_km' => 12.0,
                'long_share_cap' => 0.50,
            ],
            'planning_scenario' => [
                'flags' => ['high_caution', 'low_confidence_start'],
            ],
        ]);

        $spikes = array_values(array_filter(
            $result['issues'],
            static fn(array $issue): bool => ($issue['code'] ?? '') === 'weekly_volume_spike'
        ));

        $this->assertNotEmpty($spikes);
        $this->assertSame('warning', $spikes[0]['severity'] ?? null);
        $this->assertFalse($result['should_block_save']);
    }
}
