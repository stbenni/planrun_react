<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanSkeletonBuilder.php';

class PlanSkeletonBuilderTuneUpRaceTest extends TestCase
{
    public function test_build_places_tune_up_event_on_explicit_day_and_removes_extra_quality(): void
    {
        $builder = new \PlanSkeletonBuilder();

        $skeleton = $builder->build(
            [
                'training_start_date' => '2026-04-20',
                'goal_type' => 'race',
                'race_date' => '2026-05-03',
                'race_distance' => 'marathon',
                'sessions_per_week' => 5,
                'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
                'training_state' => [
                    'weeks_to_goal' => 2,
                    'readiness' => 'normal',
                    'load_policy' => ['feedback_guard_level' => 'neutral'],
                    'planning_scenario' => [
                        'flags' => ['b_race_before_a_race'],
                        'tune_up_event' => [
                            'date' => '2026-04-26',
                            'week' => 1,
                            'dayIndex' => 6,
                            'forced_type' => 'control',
                        ],
                    ],
                ],
            ],
            'race',
            ['start_date' => '2026-04-20', 'weeks' => 2]
        );

        $weekOne = $skeleton['weeks'][0]['days'] ?? [];
        $this->assertSame('control', $weekOne[6] ?? null);
        $this->assertNotContains('long', $weekOne);
        $this->assertNotContains('tempo', $weekOne);
        $this->assertNotContains('interval', $weekOne);
        $this->assertNotContains('fartlek', $weekOne);
        $runLikeDays = array_values(array_filter(
            $weekOne,
            static fn(string $type): bool => in_array($type, ['easy', 'long', 'tempo', 'interval', 'control', 'fartlek', 'race'], true)
        ));
        $this->assertCount(4, $runLikeDays);
        $this->assertCount(
            3,
            array_values(array_filter($weekOne, static fn(string $type): bool => $type === 'easy'))
        );
    }

    public function test_build_race_week_after_control_keeps_late_week_shakeout_pattern(): void
    {
        $builder = new \PlanSkeletonBuilder();

        $skeleton = $builder->build(
            [
                'training_start_date' => '2026-04-20',
                'goal_type' => 'race',
                'race_date' => '2026-05-03',
                'race_distance' => 'marathon',
                'sessions_per_week' => 5,
                'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
                'training_state' => [
                    'weeks_to_goal' => 2,
                    'readiness' => 'normal',
                    'load_policy' => ['feedback_guard_level' => 'neutral'],
                    'planning_scenario' => [
                        'flags' => ['b_race_before_a_race'],
                        'tune_up_event' => [
                            'date' => '2026-04-26',
                            'week' => 1,
                            'dayIndex' => 6,
                            'forced_type' => 'control',
                        ],
                    ],
                ],
            ],
            'race',
            ['start_date' => '2026-04-20', 'weeks' => 2]
        );

        $weekTwo = $skeleton['weeks'][1]['days'] ?? [];
        $this->assertSame('race', $weekTwo[6] ?? null);
        $this->assertSame('easy', $weekTwo[1] ?? null);
        $this->assertSame('easy', $weekTwo[3] ?? null);
        $this->assertSame('easy', $weekTwo[5] ?? null);
        $this->assertSame('rest', $weekTwo[0] ?? null);
        $this->assertSame('rest', $weekTwo[4] ?? null);
    }
}
