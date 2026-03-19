<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/plan_generator.php';

class RecalculationContextTest extends TestCase {
    public function test_resolveRecalculationCutoffDateValue_returns_today_when_no_workout_today(): void {
        $this->assertSame('2026-03-10', resolveRecalculationCutoffDateValue('2026-03-10', false));
    }

    public function test_resolveRecalculationCutoffDateValue_returns_tomorrow_when_workout_today_exists(): void {
        $this->assertSame('2026-03-11', resolveRecalculationCutoffDateValue('2026-03-10', true));
    }

    public function test_isRunningRelevantWorkoutEntry_acceptsRunningPlanTypes(): void {
        $this->assertTrue(isRunningRelevantWorkoutEntry([
            'distance_km' => 10.0,
            'plan_type' => 'long',
            'activity_type' => 'running',
            'source' => 'strava',
        ]));
    }

    public function test_isRunningRelevantWorkoutEntry_rejectsWalkingImports(): void {
        $this->assertFalse(isRunningRelevantWorkoutEntry([
            'distance_km' => 5.0,
            'plan_type' => 'walking',
            'activity_type' => 'walking',
            'source' => 'strava',
        ]));
    }

    public function test_isRunningRelevantWorkoutEntry_keepsManualCompletedRunEvenWithoutTypedPlan(): void {
        $this->assertTrue(isRunningRelevantWorkoutEntry([
            'distance_km' => 8.0,
            'plan_type' => 'rest',
            'activity_type' => '',
            'source' => 'manual',
        ]));
    }

    public function test_resolveRecalculationCutoffDateValue_ignores_non_running_activity_for_today_rule(): void {
        $today = '2026-03-10';
        $walkingImport = [
            'distance_km' => 2.3,
            'plan_type' => 'walking',
            'activity_type' => 'walking',
            'source' => 'strava',
        ];

        $this->assertFalse(isRunningRelevantWorkoutEntry($walkingImport));
        $this->assertSame($today, resolveRecalculationCutoffDateValue($today, false));
    }
}
