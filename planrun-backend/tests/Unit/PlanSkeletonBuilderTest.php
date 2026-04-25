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

    public function test_build_simplified_quality_mode_reduces_peak_week_to_single_milder_quality(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-05-17',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 32,
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'experience_level' => 'intermediate',
            'training_state' => [
                'load_policy' => [
                    'quality_mode' => 'simplified',
                    'quality_delay_weeks' => 0,
                ],
            ],
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
        $qualityDays = array_values(array_filter(
            $week1,
            static fn(string $type): bool => in_array($type, ['tempo', 'interval', 'fartlek', 'control'], true)
        ));

        $this->assertSame(['tempo'], $qualityDays);
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

    public function test_build_low_base_novice_short_race_trims_race_and_post_race_weeks(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'training_start_date' => '2026-04-27',
            'weekly_base_km' => 3.0,
            'sessions_per_week' => 4,
            'preferred_days' => ['mon', 'tue', 'thu', 'fri'],
            'experience_level' => 'novice',
            'training_state' => [
                'weeks_to_goal' => 8,
                'load_policy' => [
                    'protect_low_base_novice' => true,
                    'quality_delay_weeks' => 4,
                    'quality_session_min_km' => 4.5,
                    'weekly_volume_targets_km' => [
                        1 => 6.8,
                        2 => 7.0,
                        3 => 6.5,
                        4 => 7.4,
                        5 => 7.7,
                        6 => 8.0,
                        7 => 8.2,
                        8 => 4.5,
                    ],
                    'race_week_run_day_cap' => 3,
                    'post_goal_race_run_day_cap' => 2,
                ],
            ],
        ];

        $skeleton = $builder->build($user, 'race', ['weeks' => 9]);
        $raceWeek = $skeleton['weeks'][7]['days'];
        $postRaceWeek = $skeleton['weeks'][8]['days'];

        $raceWeekRunCount = count(array_filter(
            $raceWeek,
            static fn(string $type): bool => in_array($type, ['easy', 'long', 'tempo', 'interval', 'control', 'fartlek', 'race'], true)
        ));

        $this->assertLessThanOrEqual(3, $raceWeekRunCount);
        $this->assertSame('rest', $postRaceWeek[0], 'Monday after the goal race should stay off for a low-base novice.');
        $this->assertNotContains('long', $postRaceWeek, 'The immediate post-race week should not include a long run.');
        foreach (array_slice($skeleton['weeks'], 0, 4) as $week) {
            $this->assertNotContains('tempo', $week['days']);
            $this->assertNotContains('interval', $week['days']);
            $this->assertNotContains('fartlek', $week['days']);
        }
    }

    public function test_build_forceInitialRecoveryWeek_keeps_first_week_without_quality(): void {
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
                'load_policy' => [
                    'force_initial_recovery_week' => true,
                ],
            ],
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
        $week2 = $skeleton['weeks'][1]['days'];

        $this->assertNotContains('tempo', $week1);
        $this->assertNotContains('interval', $week1);
        $this->assertContains('tempo', $week2);
    }

    public function test_build_initialRecoveryRunDayCap_trims_first_recovery_week(): void {
        $builder = new PlanSkeletonBuilder();
        $user = [
            'goal_type' => 'health',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 8,
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'experience_level' => 'novice',
            'training_state' => [
                'load_policy' => [
                    'force_initial_recovery_week' => true,
                    'initial_recovery_run_day_cap' => 3,
                ],
            ],
        ];

        $skeleton = $builder->build($user, 'health', ['weeks' => 2]);
        $week1 = $skeleton['weeks'][0]['days'];
        $week2 = $skeleton['weeks'][1]['days'];
        $week1RunCount = count(array_filter(
            $week1,
            static fn(string $type): bool => in_array($type, ['easy', 'long'], true)
        ));
        $week2RunCount = count(array_filter(
            $week2,
            static fn(string $type): bool => in_array($type, ['easy', 'long'], true)
        ));

        $this->assertSame(3, $week1RunCount);
        $this->assertContains('long', $week1);
        $this->assertSame(5, $week2RunCount);
    }
}
