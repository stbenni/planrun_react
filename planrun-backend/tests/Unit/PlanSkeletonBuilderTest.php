<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlanSkeletonBuilder;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanSkeletonBuilder.php';

class PlanSkeletonBuilderTest extends TestCase {
    public function test_build_places_long_on_last_preferred_weekend_day(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'half',
            'race_date' => '2026-05-10',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 35,
            'sessions_per_week' => 6,
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
            'experience_level' => 'intermediate',
        ];

        $skeleton = $builder->build($user, 'race', ['weeks' => 6]);
        $week1 = $skeleton['weeks'][0]['days'];

        $this->assertSame('long', $week1[6], 'Long run must be placed on the latest selected weekend day.');
        $this->assertNotSame('long', $week1[5], 'Saturday should not hold the long run when Sunday is selected too.');
    }

    public function test_build_assigns_quality_days_in_build_phase_without_breaking_long_day(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-05-17',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 30,
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'experience_level' => 'intermediate',
        ];

        $skeleton = $builder->build($user, 'race', ['weeks' => 10]);
        $week2 = $skeleton['weeks'][1]['days'];

        $this->assertSame('tempo', $week2[1], 'Tuesday should hold the first build-phase quality workout.');
        $this->assertSame('interval', $week2[3], 'Thursday should hold the second build-phase quality workout.');
        $this->assertSame('long', $week2[6], 'Sunday remains the long-run day.');
    }

    public function test_build_places_race_on_exact_race_day_and_removes_long_from_that_week(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-03-29',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 28,
            'sessions_per_week' => 4,
            'preferred_days' => ['mon', 'wed', 'sat', 'sun'],
            'experience_level' => 'intermediate',
        ];

        $skeleton = $builder->build($user, 'race', ['weeks' => 4]);
        $raceWeek = $skeleton['weeks'][2]['days'];

        $this->assertSame('race', $raceWeek[6], 'Race should be placed on Sunday of week 3.');
        $this->assertNotSame('long', $raceWeek[6], 'Race week must not keep long on the race day.');
    }

    public function test_build_suppresses_quality_for_conservative_special_population_flags(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-05-17',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 30,
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'experience_level' => 'intermediate',
            'training_state' => [
                'special_population_flags' => ['return_after_break', 'low_confidence_vdot'],
            ],
        ];

        $skeleton = $builder->build($user, 'race', ['weeks' => 4]);
        $week2 = $skeleton['weeks'][1]['days'];

        $this->assertNotContains('tempo', $week2);
        $this->assertNotContains('interval', $week2);
        $this->assertSame('long', $week2[6]);
    }

    public function test_build_peak_marathon_phase_can_include_interval_as_second_quality(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-05-17',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 55,
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'experience_level' => 'intermediate',
        ];

        $skeleton = $builder->build($user, 'race', [
            'weeks' => 2,
            'current_phase' => [
                'weeks_into_phase' => 0,
                'remaining_phases' => [[
                    'name' => 'peak',
                    'label' => 'Пиковый',
                    'weeks_from' => 1,
                    'weeks_to' => 2,
                    'max_key_workouts' => 2,
                ]],
            ],
        ]);

        $week1 = $skeleton['weeks'][0]['days'];
        $this->assertContains('tempo', $week1);
        $this->assertContains('interval', $week1);
    }

    public function test_build_base_phase_with_short_runway_allows_one_quality_session(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-04-26',
            'training_start_date' => '2026-03-16',
            'weekly_base_km' => 40,
            'sessions_per_week' => 4,
            'preferred_days' => ['mon', 'wed', 'sat', 'sun'],
            'experience_level' => 'intermediate',
            'training_state' => [
                'weeks_to_goal' => 6,
            ],
        ];

        $skeleton = $builder->build($user, 'race', [
            'weeks' => 2,
            'current_phase' => [
                'weeks_into_phase' => 0,
                'remaining_phases' => [[
                    'name' => 'base',
                    'label' => 'Базовый',
                    'weeks_from' => 1,
                    'weeks_to' => 2,
                    'max_key_workouts' => 1,
                ]],
            ],
        ]);

        $week1 = $skeleton['weeks'][0]['days'];
        $this->assertContains('tempo', $week1);
    }
}
