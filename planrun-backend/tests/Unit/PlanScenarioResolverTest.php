<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanScenarioResolver.php';

class PlanScenarioResolverTest extends TestCase
{
    public function test_resolve_aligns_schedule_anchor_and_extends_short_runway_race_week(): void
    {
        $resolver = new \PlanScenarioResolver();

        $scenario = $resolver->resolve(
            [
                'goal_type' => 'race',
                'race_distance' => 'marathon',
                'race_date' => '2026-05-04',
                'training_start_date' => '2026-04-21',
            ],
            [
                'goal_type' => 'race',
                'race_distance' => 'marathon',
                'race_date' => '2026-05-04',
                'weeks_to_goal' => 2,
                'readiness' => 'low',
                'load_policy' => ['feedback_guard_level' => 'neutral'],
                'special_population_flags' => ['low_confidence_vdot'],
            ],
            'generate'
        );

        $this->assertSame('2026-04-20', $scenario['schedule_anchor_date'] ?? null);
        $this->assertSame(3, (int) ($scenario['effective_weeks_to_goal'] ?? 0));
        $this->assertSame('short_runway_long_race', $scenario['primary'] ?? null);
        $this->assertContains('short_runway_taper', $scenario['flags'] ?? []);
        $this->assertContains('short_runway_long_race', $scenario['flags'] ?? []);
        $this->assertContains('schedule_anchor_aligned_to_monday', $scenario['policy_decisions'] ?? []);
    }

    public function test_resolve_prioritizes_return_after_break_over_generic_race_build(): void
    {
        $resolver = new \PlanScenarioResolver();

        $scenario = $resolver->resolve(
            [
                'goal_type' => 'race',
                'race_distance' => '10k',
                'race_date' => '2026-06-14',
                'training_start_date' => '2026-04-21',
            ],
            [
                'goal_type' => 'race',
                'race_distance' => '10k',
                'race_date' => '2026-06-14',
                'weeks_to_goal' => 8,
                'readiness' => 'normal',
                'load_policy' => ['feedback_guard_level' => 'neutral'],
                'special_population_flags' => ['return_after_break'],
            ],
            'generate'
        );

        $this->assertSame('return_after_break', $scenario['primary'] ?? null);
        $this->assertContains('return_after_break', $scenario['flags'] ?? []);
    }

    public function test_resolve_detects_b_race_before_a_race_and_downgrades_to_control(): void
    {
        $resolver = new \PlanScenarioResolver();

        $scenario = $resolver->resolve(
            [
                'goal_type' => 'race',
                'race_distance' => 'marathon',
                'race_date' => '2026-05-03',
                'training_start_date' => '2026-04-20',
            ],
            [
                'goal_type' => 'race',
                'race_distance' => 'marathon',
                'race_date' => '2026-05-03',
                'weeks_to_goal' => 2,
                'readiness' => 'normal',
                'load_policy' => ['feedback_guard_level' => 'neutral'],
                'special_population_flags' => [],
            ],
            'generate',
            [
                'secondary_race_date' => '2026-04-26',
                'secondary_race_distance' => 'half',
                'secondary_race_type' => 'race',
                'secondary_race_target_time' => '01:39:00',
            ]
        );

        $this->assertSame('b_race_before_a_race', $scenario['primary'] ?? null);
        $this->assertContains('b_race_before_a_race', $scenario['flags'] ?? []);
        $this->assertContains('protect_primary_race_priority', $scenario['policy_decisions'] ?? []);
        $this->assertSame('control', $scenario['tune_up_event']['forced_type'] ?? null);
        $this->assertSame(1, (int) ($scenario['tune_up_event']['week'] ?? 0));
        $this->assertSame(6, (int) ($scenario['tune_up_event']['dayIndex'] ?? -1));
        $this->assertSame(7, (int) ($scenario['tune_up_event']['day_of_week'] ?? -1));
    }

    public function test_resolve_prioritizes_b_race_before_a_race_over_overload_recovery(): void
    {
        $resolver = new \PlanScenarioResolver();

        $scenario = $resolver->resolve(
            [
                'goal_type' => 'race',
                'race_distance' => 'marathon',
                'race_date' => '2026-05-03',
                'training_start_date' => '2026-04-20',
            ],
            [
                'goal_type' => 'race',
                'race_distance' => 'marathon',
                'race_date' => '2026-05-03',
                'weeks_to_goal' => 2,
                'readiness' => 'low',
                'load_policy' => ['feedback_guard_level' => 'fatigue_high'],
                'special_population_flags' => [],
            ],
            'generate',
            [
                'secondary_race_date' => '2026-04-26',
                'secondary_race_distance' => 'half',
                'secondary_race_type' => 'race',
                'secondary_race_target_time' => '01:39:00',
            ]
        );

        $this->assertSame('b_race_before_a_race', $scenario['primary'] ?? null);
        $this->assertContains('b_race_before_a_race', $scenario['flags'] ?? []);
        $this->assertContains('overload_recovery', $scenario['flags'] ?? []);
        $this->assertTrue((bool) ($scenario['is_high_caution'] ?? false));
    }
}
