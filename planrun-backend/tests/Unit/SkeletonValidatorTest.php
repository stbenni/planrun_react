<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/skeleton/SkeletonValidator.php';

class SkeletonValidatorTest extends TestCase
{
    public function test_validateConsistency_does_not_flag_build_after_tiny_recovery_week_for_low_base_profile(): void
    {
        $plan = [
            'weeks' => [
                ['week_number' => 1, 'phase' => 'base', 'is_recovery' => false, 'actual_volume_km' => 7.0, 'days' => []],
                ['week_number' => 2, 'phase' => 'base', 'is_recovery' => true, 'actual_volume_km' => 6.5, 'days' => []],
                ['week_number' => 3, 'phase' => 'build', 'is_recovery' => false, 'actual_volume_km' => 11.9, 'days' => []],
            ],
        ];

        $issues = \SkeletonValidator::validateConsistency($plan, [], [
            'readiness' => 'low',
            'load_policy' => ['allowed_growth_ratio' => 1.08],
        ]);

        $this->assertSame([], array_filter(
            $issues,
            static fn(array $issue): bool => ($issue['type'] ?? '') === 'volume_jump'
        ));
    }

    public function test_validateConsistency_still_flags_real_volume_spike_when_previous_week_is_substantial(): void
    {
        $plan = [
            'weeks' => [
                ['week_number' => 1, 'phase' => 'base', 'is_recovery' => false, 'actual_volume_km' => 12.0, 'days' => []],
                ['week_number' => 2, 'phase' => 'build', 'is_recovery' => false, 'actual_volume_km' => 17.5, 'days' => []],
            ],
        ];

        $issues = \SkeletonValidator::validateConsistency($plan, [], [
            'readiness' => 'low',
            'load_policy' => ['allowed_growth_ratio' => 1.08],
        ]);

        $this->assertNotSame([], array_filter(
            $issues,
            static fn(array $issue): bool => ($issue['type'] ?? '') === 'volume_jump'
        ));
    }

    public function test_validateConsistency_allows_rebound_after_recovery_for_normal_profile(): void
    {
        $plan = [
            'weeks' => [
                ['week_number' => 1, 'phase' => 'base', 'is_recovery' => false, 'actual_volume_km' => 24.0, 'days' => []],
                ['week_number' => 2, 'phase' => 'build', 'is_recovery' => true, 'actual_volume_km' => 18.5, 'days' => []],
                ['week_number' => 3, 'phase' => 'build', 'is_recovery' => false, 'actual_volume_km' => 25.1, 'days' => []],
            ],
        ];

        $issues = \SkeletonValidator::validateConsistency($plan, [], [
            'readiness' => 'normal',
            'load_policy' => ['allowed_growth_ratio' => 1.10],
        ]);

        $this->assertSame([], array_filter(
            $issues,
            static fn(array $issue): bool => ($issue['type'] ?? '') === 'volume_jump'
        ));
    }

    public function test_validateConsistency_flags_low_base_spike_when_policy_enables_absolute_guard(): void
    {
        $plan = [
            'weeks' => [
                ['week_number' => 1, 'phase' => 'base', 'is_recovery' => false, 'actual_volume_km' => 6.5, 'days' => []],
                ['week_number' => 2, 'phase' => 'build', 'is_recovery' => false, 'actual_volume_km' => 10.4, 'days' => []],
            ],
        ];

        $issues = \SkeletonValidator::validateConsistency($plan, [], [
            'readiness' => 'low',
            'load_policy' => [
                'allowed_growth_ratio' => 1.08,
                'pre_threshold_volume_km' => 8.0,
                'pre_threshold_absolute_growth_km' => 1.5,
            ],
        ]);

        $this->assertNotSame([], array_filter(
            $issues,
            static fn(array $issue): bool => ($issue['type'] ?? '') === 'volume_jump'
        ));
    }
}
