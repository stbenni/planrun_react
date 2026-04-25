<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/skeleton/VolumeDistributor.php';

class VolumeDistributorTest extends TestCase {
    public function test_distribute_caps_long_run_share_for_conservative_profiles(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long'],
            targetVolumeKm: 12.0,
            longTargetKm: 8.0,
            paceRules: [
                'easy_min_sec' => 360,
                'easy_max_sec' => 380,
                'long_min_sec' => 370,
                'tempo_sec' => 320,
                'interval_sec' => 300,
            ],
            loadPolicy: [
                'easy_min_km' => 1.5,
                'recovery_cutback_ratio' => 0.88,
                'long_share_cap' => 0.45,
            ],
            phase: 'base',
            isRecovery: false,
            weekInPhase: 1,
            workoutDetails: [],
            raceDistanceKm: 0.0
        );

        $days = $result['days'] ?? [];
        $longDay = array_values(array_filter($days, static fn(array $day): bool => ($day['type'] ?? '') === 'long'))[0] ?? null;

        $this->assertNotNull($longDay);
        $this->assertSame(5.0, (float) ($longDay['distance_km'] ?? 0.0));
        $this->assertSame(12.0, (float) ($result['target_volume_km'] ?? 0.0));
        $this->assertLessThanOrEqual(42.0, round((((float) ($longDay['distance_km'] ?? 0.0)) / 12.0) * 100, 1));
    }

    public function test_distribute_derives_non_zero_long_run_when_target_missing(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long'],
            targetVolumeKm: 24.0,
            longTargetKm: 0.0,
            paceRules: [
                'easy_min_sec' => 360,
                'easy_max_sec' => 380,
                'long_min_sec' => 370,
                'tempo_sec' => 320,
                'interval_sec' => 300,
            ],
            loadPolicy: [
                'easy_min_km' => 1.5,
                'long_min_km' => 8.0,
                'long_share_cap' => 0.45,
            ],
            phase: 'taper',
            isRecovery: false,
            weekInPhase: 1,
            workoutDetails: [],
            raceDistanceKm: 0.0
        );

        $days = $result['days'] ?? [];
        $longDay = array_values(array_filter($days, static fn(array $day): bool => ($day['type'] ?? '') === 'long'))[0] ?? null;

        $this->assertNotNull($longDay);
        $this->assertSame(8.4, (float) ($longDay['distance_km'] ?? 0.0));
        $this->assertGreaterThan(0.0, (float) ($longDay['distance_km'] ?? 0.0));
    }

    public function test_distribute_preserves_feasible_5k_race_long_run_floor(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'rest', 'easy', 'rest', 'long', 'rest', 'rest'],
            targetVolumeKm: 9.8,
            longTargetKm: 12.0,
            paceRules: [
                'easy_min_sec' => 390,
                'easy_max_sec' => 420,
                'long_min_sec' => 410,
                'tempo_sec' => 350,
                'interval_sec' => 330,
            ],
            loadPolicy: [
                'easy_min_km' => 2.0,
                'long_share_cap' => 0.40,
                'min_long_over_easy_km' => 0.8,
            ],
            phase: 'build',
            isRecovery: false,
            weekInPhase: 4,
            workoutDetails: [],
            raceDistanceKm: 5.0
        );

        $days = $result['days'] ?? [];
        $longDay = array_values(array_filter($days, static fn(array $day): bool => ($day['type'] ?? '') === 'long'))[0] ?? null;

        $this->assertNotNull($longDay);
        $this->assertSame(5.0, (float) ($longDay['distance_km'] ?? 0.0));
        $this->assertSame(9.8, (float) ($result['actual_volume_km'] ?? 0.0));
    }

    public function test_distribute_keeps_5k_race_long_floor_disabled_when_week_is_too_small(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'rest', 'easy', 'rest', 'long', 'rest', 'rest'],
            targetVolumeKm: 8.0,
            longTargetKm: 12.0,
            paceRules: [
                'easy_min_sec' => 390,
                'easy_max_sec' => 420,
                'long_min_sec' => 410,
                'tempo_sec' => 350,
                'interval_sec' => 330,
            ],
            loadPolicy: [
                'easy_min_km' => 2.0,
                'long_share_cap' => 0.40,
                'min_long_over_easy_km' => 0.8,
            ],
            phase: 'build',
            isRecovery: false,
            weekInPhase: 1,
            workoutDetails: [],
            raceDistanceKm: 5.0
        );

        $days = $result['days'] ?? [];
        $longDay = array_values(array_filter($days, static fn(array $day): bool => ($day['type'] ?? '') === 'long'))[0] ?? null;

        $this->assertNotNull($longDay);
        $this->assertSame(3.2, (float) ($longDay['distance_km'] ?? 0.0));
        $this->assertSame(8.0, (float) ($result['actual_volume_km'] ?? 0.0));
    }

    public function test_distribute_biases_easy_days_toward_meaningful_taper_activations(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'race'],
            targetVolumeKm: 55.6,
            longTargetKm: 0.0,
            paceRules: [
                'easy_min_sec' => 360,
                'easy_max_sec' => 380,
                'long_min_sec' => 370,
                'tempo_sec' => 320,
                'interval_sec' => 300,
                'race_pace_sec' => 299,
            ],
            loadPolicy: [
                'easy_min_km' => 2.0,
                'long_share_cap' => 0.45,
            ],
            phase: 'taper',
            isRecovery: false,
            weekInPhase: 1,
            workoutDetails: [],
            raceDistanceKm: 42.2
        );

        $days = $result['days'] ?? [];
        $easyDays = array_values(array_filter(
            $days,
            static fn(array $day): bool => ($day['type'] ?? '') === 'easy'
        ));

        $this->assertCount(3, $easyDays);
        $this->assertGreaterThanOrEqual(4.0, (float) ($easyDays[0]['distance_km'] ?? 0.0));
        $this->assertGreaterThanOrEqual(4.0, (float) ($easyDays[1]['distance_km'] ?? 0.0));
        $this->assertGreaterThanOrEqual(3.0, (float) ($easyDays[2]['distance_km'] ?? 0.0));
        $this->assertGreaterThanOrEqual(
            (float) ($easyDays[0]['distance_km'] ?? 0.0),
            (float) ($easyDays[1]['distance_km'] ?? 0.0)
        );
        $this->assertLessThanOrEqual(
            (float) ($easyDays[0]['distance_km'] ?? 0.0),
            (float) ($easyDays[2]['distance_km'] ?? 0.0)
        );
        $this->assertLessThanOrEqual(4.2, (float) ($easyDays[2]['distance_km'] ?? 0.0));
    }

    public function test_distribute_downgrades_unviable_quality_to_easy_for_low_base_profile(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'tempo', 'rest', 'easy', 'long', 'rest', 'rest'],
            targetVolumeKm: 7.0,
            longTargetKm: 3.2,
            paceRules: [
                'easy_min_sec' => 360,
                'easy_max_sec' => 380,
                'long_min_sec' => 370,
                'tempo_sec' => 320,
                'interval_sec' => 300,
            ],
            loadPolicy: [
                'easy_min_km' => 1.5,
                'quality_session_min_km' => 4.5,
                'quality_workout_share_cap' => 0.38,
                'min_long_over_easy_km' => 0.8,
            ],
            phase: 'build',
            isRecovery: false,
            weekInPhase: 1,
            workoutDetails: [
                'tempo' => [
                    'warmup_km' => 1.75,
                    'cooldown_km' => 1.25,
                    'tempo_km' => 4.0,
                    'total_km' => 7.0,
                    'tempo_pace_sec' => 320,
                ],
            ],
            raceDistanceKm: 0.0
        );

        $days = $result['days'] ?? [];
        $types = array_column($days, 'type');

        $this->assertNotContains('tempo', $types);
        $this->assertSame(3, count(array_filter($types, static fn(string $type): bool => $type === 'easy')));
    }

    public function test_distribute_caps_single_quality_share_for_low_base_novice(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'interval', 'rest', 'easy', 'long', 'rest', 'rest'],
            targetVolumeKm: 15.0,
            longTargetKm: 3.0,
            paceRules: [
                'easy_min_sec' => 360,
                'easy_max_sec' => 380,
                'long_min_sec' => 370,
                'tempo_sec' => 320,
                'interval_sec' => 280,
            ],
            loadPolicy: [
                'easy_min_km' => 1.5,
                'quality_session_min_km' => 4.5,
                'quality_workout_share_cap' => 0.38,
                'min_long_over_easy_km' => 0.8,
            ],
            phase: 'peak',
            isRecovery: false,
            weekInPhase: 1,
            workoutDetails: [
                'interval' => [
                    'warmup_km' => 1.75,
                    'cooldown_km' => 1.25,
                    'reps' => 6,
                    'interval_m' => 600,
                    'rest_m' => 400,
                    'rest_type' => 'walk',
                    'work_km' => 3.6,
                    'rest_km' => 2.4,
                    'total_km' => 9.0,
                    'interval_pace_sec' => 280,
                ],
            ],
            raceDistanceKm: 0.0
        );

        $intervalDay = array_values(array_filter(
            $result['days'] ?? [],
            static fn(array $day): bool => ($day['type'] ?? '') === 'interval'
        ))[0] ?? null;

        $this->assertNotNull($intervalDay);
        $this->assertLessThanOrEqual(5.8, (float) ($intervalDay['distance_km'] ?? 0.0));
        $this->assertLessThanOrEqual(3, (int) ($intervalDay['reps'] ?? 0));
    }

    public function test_distribute_rebalances_excess_long_volume_into_easy_days(): void {
        $result = \VolumeDistributor::distribute(
            dayTypes: ['easy', 'rest', 'easy', 'rest', 'easy', 'long', 'rest'],
            targetVolumeKm: 17.2,
            longTargetKm: 11.0,
            paceRules: [
                'easy_min_sec' => 335,
                'easy_max_sec' => 360,
                'long_min_sec' => 345,
                'tempo_sec' => 300,
                'interval_sec' => 280,
            ],
            loadPolicy: [
                'easy_min_km' => 2.0,
                'long_share_cap' => 0.43,
                'min_long_over_easy_km' => 0.5,
            ],
            phase: 'build',
            isRecovery: false,
            weekInPhase: 1,
            workoutDetails: [],
            raceDistanceKm: 0.0
        );

        $days = $result['days'] ?? [];
        $longDay = array_values(array_filter(
            $days,
            static fn(array $day): bool => ($day['type'] ?? '') === 'long'
        ))[0] ?? null;
        $easyDays = array_values(array_filter(
            $days,
            static fn(array $day): bool => ($day['type'] ?? '') === 'easy'
        ));

        $this->assertNotNull($longDay);
        $this->assertSame(7.2, (float) ($longDay['distance_km'] ?? 0.0));
        $this->assertSame(17.1, (float) ($result['actual_volume_km'] ?? 0.0));
        $this->assertSame([3.3, 3.3, 3.3], array_map(
            static fn(array $day): float => (float) ($day['distance_km'] ?? 0.0),
            $easyDays
        ));
    }
}
