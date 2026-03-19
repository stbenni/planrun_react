<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/plan_generator.php';

class PlanGeneratorCorrectivePassTest extends TestCase {
    public function test_decodeGeneratedPlanResponse_throwsWhenPlanIsShorterThanSkeleton(): void {
        $user = [
            'plan_skeleton' => [
                'weeks' => array_fill(0, 4, ['days' => ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']]),
            ],
        ];

        $response = json_encode([
            'weeks' => [
                ['days' => [['type' => 'easy']]],
                ['days' => [['type' => 'easy']]],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Неполный план');

        decodeGeneratedPlanResponse($response, $user, 'PlanRun AI Test');
    }

    public function test_decodeGeneratedPlanResponse_trimsWeeksLongerThanSkeleton(): void {
        $user = [
            'plan_skeleton' => [
                'weeks' => array_fill(0, 2, ['days' => ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']]),
            ],
        ];

        $response = json_encode([
            'weeks' => [
                ['days' => [['type' => 'easy']]],
                ['days' => [['type' => 'easy']]],
                ['days' => [['type' => 'easy']]],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $decoded = decodeGeneratedPlanResponse($response, $user, 'PlanRun AI Test');

        $this->assertCount(2, $decoded['weeks']);
    }

    public function test_maybeApplyCorrectiveRegenerationToPlan_skipsSecondPassWithoutErrors(): void {
        $planData = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'tempo', 'distance_km' => 8, 'pace' => '4:35'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'long', 'distance_km' => 18, 'pace' => '5:45'],
                    ['type' => 'rest'],
                ],
            ]],
        ];

        $user = [
            'sessions_per_week' => 3,
            'preferred_days' => ['mon', 'tue', 'sat'],
            'preferred_ofp_days' => [],
            'plan_skeleton' => [
                'weeks' => [[
                    'days' => ['easy', 'tempo', 'rest', 'rest', 'rest', 'long', 'rest'],
                ]],
            ],
            'training_state' => [
                'readiness' => 'normal',
                'vdot_source' => 'target_time',
                'vdot_confidence' => 'low',
                'pace_rules' => [
                    'easy_min_sec' => 320,
                    'easy_max_sec' => 340,
                    'long_min_sec' => 335,
                    'long_max_sec' => 360,
                    'tempo_sec' => 275,
                    'tempo_tolerance_sec' => 8,
                ],
            ],
        ];

        $called = false;
        $result = maybeApplyCorrectiveRegenerationToPlan(
            $planData,
            $user,
            'base prompt',
            '2026-03-09',
            0,
            123,
            function () use (&$called): string {
                $called = true;
                return '';
            },
            'PlanRun AI Test'
        );

        $this->assertFalse($called, 'Corrective AI pass should not run when there are no error-level issues.');
        $this->assertSame($planData['weeks'], $result['weeks']);
        $this->assertArrayHasKey('_generation_metadata', $result);
        $this->assertSame(0, $result['_generation_metadata']['repair_count']);
        $this->assertFalse($result['_generation_metadata']['corrective_regeneration_used']);
    }

    public function test_maybeApplyCorrectiveRegenerationToPlan_usesImprovedCorrectedPlan(): void {
        $planData = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'tempo', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'rest'],
                    ['type' => 'interval', 'warmup_km' => 2, 'cooldown_km' => 1.5, 'reps' => 5, 'interval_m' => 800, 'rest_m' => 400, 'rest_type' => 'jog', 'interval_pace' => '4:10'],
                    ['type' => 'rest'],
                    ['type' => 'long', 'distance_km' => 18, 'pace' => '5:45'],
                    ['type' => 'rest'],
                ],
            ]],
        ];

        $correctedPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'tempo', 'distance_km' => 8, 'pace' => '5:30'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 6, 'pace' => '5:35'],
                    ['type' => 'rest'],
                    ['type' => 'long', 'distance_km' => 18, 'pace' => '5:45'],
                    ['type' => 'rest'],
                ],
            ]],
        ];

        $user = [
            'goal_type' => 'health',
            'sessions_per_week' => 4,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat'],
            'preferred_ofp_days' => [],
            'training_state' => [
                'goal_type' => 'health',
                'experience_level' => 'novice',
                'readiness' => 'normal',
                'vdot_source' => 'target_time',
                'vdot_confidence' => 'low',
                'pace_rules' => [
                    'easy_min_sec' => 320,
                    'easy_max_sec' => 340,
                    'long_min_sec' => 335,
                    'long_max_sec' => 360,
                    'tempo_sec' => 275,
                    'tempo_tolerance_sec' => 8,
                ],
            ],
        ];

        $promptSeen = null;
        $result = maybeApplyCorrectiveRegenerationToPlan(
            $planData,
            $user,
            'base prompt',
            '2026-03-09',
            0,
            123,
            function (string $prompt) use (&$promptSeen, $correctedPlan): string {
                $promptSeen = $prompt;
                return json_encode($correctedPlan, JSON_UNESCAPED_UNICODE);
            },
            'PlanRun AI Test'
        );

        $this->assertNotNull($promptSeen);
        $this->assertStringContainsString('VALIDATION FAILURE', $promptSeen);
        $this->assertSame('easy', $result['weeks'][0]['days'][3]['type']);
        $this->assertArrayHasKey('_generation_metadata', $result);
        $this->assertSame('generate', $result['_generation_metadata']['generation_mode']);
        $this->assertSame(1, $result['_generation_metadata']['repair_count']);
        $this->assertTrue($result['_generation_metadata']['corrective_regeneration_used']);
        $this->assertSame('target_time', $result['_generation_metadata']['vdot_source']);
    }
}
