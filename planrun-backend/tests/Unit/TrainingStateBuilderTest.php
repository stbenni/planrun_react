<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrainingStateBuilder;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/TrainingStateBuilder.php';

class TrainingStateBuilderTest extends TestCase {
    public function test_buildForUser_derives_special_population_flags_and_preferred_long_day(): void {
        $builder = new TrainingStateBuilder(getDBConnection());
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
        $builder = new TrainingStateBuilder(getDBConnection());
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
    }

    public function test_buildForUser_uses_reason_benchmark_override_and_personal_easy_floor(): void {
        $builder = new TrainingStateBuilder(getDBConnection());
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
}
