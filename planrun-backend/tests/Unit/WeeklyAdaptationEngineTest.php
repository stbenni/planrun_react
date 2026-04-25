<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/skeleton/WeeklyAdaptationEngine.php';

class WeeklyAdaptationEngineTest extends TestCase {
    public function test_detectTriggers_marks_subjective_pain_signal_and_prefers_recovery_adaptation(): void {
        $engine = new \WeeklyAdaptationEngine(getDBConnection());
        $detectTriggers = new \ReflectionMethod($engine, 'detectTriggers');
        $detectTriggers->setAccessible(true);
        $decideAdaptation = new \ReflectionMethod($engine, 'decideAdaptation');
        $decideAdaptation->setAccessible(true);

        $metrics = [
            'compliance' => 0.92,
            'key_completion' => 1.0,
            'planned_key_workouts' => 2,
            'skipped_days' => 1,
            'avg_actual_easy_pace_sec' => null,
            'subjective_pain_count' => 1,
            'subjective_fatigue_count' => 1,
            'subjective_recovery_risk' => 0.88,
        ];

        $triggers = $detectTriggers->invoke($engine, $metrics, []);
        $adaptationType = $decideAdaptation->invoke($engine, $triggers, $metrics);

        $this->assertContains('subjective_pain_signal', $triggers);
        $this->assertSame('insert_recovery', $adaptationType);
    }

    public function test_detectTriggers_marks_subjective_fatigue_signal_and_reduces_volume(): void {
        $engine = new \WeeklyAdaptationEngine(getDBConnection());
        $detectTriggers = new \ReflectionMethod($engine, 'detectTriggers');
        $detectTriggers->setAccessible(true);
        $decideAdaptation = new \ReflectionMethod($engine, 'decideAdaptation');
        $decideAdaptation->setAccessible(true);

        $metrics = [
            'compliance' => 0.95,
            'key_completion' => 1.0,
            'planned_key_workouts' => 2,
            'skipped_days' => 0,
            'avg_actual_easy_pace_sec' => null,
            'subjective_pain_count' => 0,
            'subjective_fatigue_count' => 2,
            'subjective_recovery_risk' => 0.64,
        ];

        $triggers = $detectTriggers->invoke($engine, $metrics, []);
        $adaptationType = $decideAdaptation->invoke($engine, $triggers, $metrics);

        $this->assertContains('subjective_fatigue_signal', $triggers);
        $this->assertSame('volume_down', $adaptationType);
    }

    public function test_detectTriggers_uses_structured_load_spike_even_without_multiple_fatigue_text_flags(): void {
        $engine = new \WeeklyAdaptationEngine(getDBConnection());
        $detectTriggers = new \ReflectionMethod($engine, 'detectTriggers');
        $detectTriggers->setAccessible(true);
        $decideAdaptation = new \ReflectionMethod($engine, 'decideAdaptation');
        $decideAdaptation->setAccessible(true);

        $metrics = [
            'compliance' => 0.98,
            'key_completion' => 1.0,
            'planned_key_workouts' => 2,
            'skipped_days' => 0,
            'avg_actual_easy_pace_sec' => null,
            'subjective_pain_count' => 0,
            'subjective_fatigue_count' => 1,
            'subjective_recovery_risk' => 0.48,
            'subjective_recent_session_rpe' => 8.1,
            'subjective_session_rpe_delta' => 1.4,
            'subjective_recent_legs_score' => 8.0,
            'subjective_recent_breath_score' => 7.0,
            'subjective_recent_hr_strain_score' => 8.4,
            'subjective_recent_pain_score' => 1.0,
            'subjective_pain_score_delta' => 0.5,
            'subjective_load_delta' => 0.88,
        ];

        $triggers = $detectTriggers->invoke($engine, $metrics, []);
        $adaptationType = $decideAdaptation->invoke($engine, $triggers, $metrics);

        $this->assertContains('subjective_fatigue_signal', $triggers);
        $this->assertSame('volume_down', $adaptationType);
    }

    public function test_detectTriggers_uses_athlete_note_signals_for_recovery_adaptation(): void {
        $engine = new \WeeklyAdaptationEngine(getDBConnection());
        $detectTriggers = new \ReflectionMethod($engine, 'detectTriggers');
        $detectTriggers->setAccessible(true);
        $decideAdaptation = new \ReflectionMethod($engine, 'decideAdaptation');
        $decideAdaptation->setAccessible(true);

        $metrics = [
            'compliance' => 0.98,
            'key_completion' => 1.0,
            'planned_key_workouts' => 2,
            'skipped_days' => 0,
            'avg_actual_easy_pace_sec' => null,
            'subjective_pain_count' => 0,
            'subjective_fatigue_count' => 0,
            'subjective_recovery_risk' => 0.20,
            'athlete_signal_note_risk_score' => 0.62,
            'athlete_signal_note_illness_count' => 1,
            'athlete_signal_note_sleep_count' => 1,
            'athlete_signal_note_stress_count' => 0,
            'athlete_signal_note_travel_count' => 0,
        ];

        $triggers = $detectTriggers->invoke($engine, $metrics, []);
        $adaptationType = $decideAdaptation->invoke($engine, $triggers, $metrics);

        $this->assertContains('athlete_illness_signal', $triggers);
        $this->assertContains('athlete_recovery_context_signal', $triggers);
        $this->assertSame('insert_recovery', $adaptationType);
    }

    public function test_detectTriggers_uses_easy_pace_delta_for_positive_adaptation(): void {
        $engine = new \WeeklyAdaptationEngine(getDBConnection());
        $detectTriggers = new \ReflectionMethod($engine, 'detectTriggers');
        $detectTriggers->setAccessible(true);
        $decideAdaptation = new \ReflectionMethod($engine, 'decideAdaptation');
        $decideAdaptation->setAccessible(true);

        $metrics = [
            'compliance' => 1.00,
            'key_completion' => 1.0,
            'planned_key_workouts' => 2,
            'skipped_days' => 0,
            'avg_actual_easy_pace_sec' => 305,
            'planned_easy_pace_sec' => 345,
            'subjective_pain_count' => 0,
            'subjective_fatigue_count' => 0,
            'subjective_recovery_risk' => 0.10,
        ];

        $triggers = $detectTriggers->invoke($engine, $metrics, []);
        $adaptationType = $decideAdaptation->invoke($engine, $triggers, $metrics);

        $this->assertContains('pace_too_fast', $triggers);
        $this->assertSame('vdot_adjust_up', $adaptationType);
    }
}
