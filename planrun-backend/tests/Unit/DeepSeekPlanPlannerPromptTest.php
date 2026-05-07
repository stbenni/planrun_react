<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/llm_planner/DeepSeekPlanPlanner.php';

class DeepSeekPlanPlannerPromptTest extends TestCase
{
    private \DeepSeekPlanPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new \DeepSeekPlanPlanner(getDBConnection());
    }

    public function test_system_prompt_requires_russian_user_facing_fields(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner);

        $this->assertStringContainsString('только валидный JSON', $prompt);
        $this->assertStringContainsString('только на русском языке', $prompt);
        $this->assertStringContainsString('notes', $prompt);
        $this->assertStringContainsString('quality_focus', $prompt);
        $this->assertStringContainsString('risk_note', $prompt);
    }

    // Phase A.2 (PR2): тесты test_macro_prompt_gives_model_room_to_analyze и
    // test_detail_prompt_requires_russian_notes_and_allows_macro_revision удалены —
    // методы buildMacroPrompt/buildDetailBatchPrompt больше не существуют (single_pass only).

    public function test_strip_macrocycle_precompute_removes_phase_a7_fields(): void
    {
        $method = new \ReflectionMethod($this->planner, 'stripMacrocyclePrecompute');
        $method->setAccessible(true);

        $loadPolicy = [
            'allowed_growth_ratio' => 1.12,
            'easy_min_km' => 5.0,
            'long_share_cap' => 0.45,
            'weekly_volume_targets_km' => [40, 45, 50, 38, 55],
            'long_run_targets_km' => [12, 14, 16, 12, 18],
            'recovery_weeks' => [4, 8],
            'start_volume_km' => 35,
            'peak_volume_km' => 70,
        ];

        $stripped = $method->invoke($this->planner, $loadPolicy);

        $this->assertArrayNotHasKey('weekly_volume_targets_km', $stripped);
        $this->assertArrayNotHasKey('long_run_targets_km', $stripped);
        $this->assertArrayNotHasKey('recovery_weeks', $stripped);
        $this->assertArrayNotHasKey('start_volume_km', $stripped);
        $this->assertArrayNotHasKey('peak_volume_km', $stripped);

        $this->assertSame(1.12, $stripped['allowed_growth_ratio']);
        $this->assertSame(5.0, $stripped['easy_min_km']);
        $this->assertSame(0.45, $stripped['long_share_cap']);
    }

    public function test_strip_macrocycle_precompute_handles_null_policy(): void
    {
        $method = new \ReflectionMethod($this->planner, 'stripMacrocyclePrecompute');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($this->planner, null));
    }

    public function test_full_plan_prompt_gives_deepseek_single_pass_autonomy(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildFullPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner, [
            'weeks_count' => 9,
            'training_state' => [
                'load_policy' => [
                    'allowed_growth_ratio' => 1.12,
                    'peak_volume_km' => 78,
                ],
            ],
            'hard_rules' => [
                'allowed_run_day_numbers' => [1, 3, 5, 7],
                'long_run_safety' => [
                    'marathon_last_21_days_training_long_run_max_km' => 32.0,
                    'no_training_run_at_or_above_race_distance_except_race_day' => true,
                ],
            ],
        ]);

        $this->assertStringContainsString('single-pass', $prompt);
        $this->assertStringContainsString('входные данные для тренерского анализа, а не клетка', $prompt);
        $this->assertStringContainsString('calendar_weeks', $prompt);
        $this->assertStringContainsString('Сам выбирай фазы, объёмы, длительные', $prompt);
        $this->assertStringContainsString('target_volume_km каждой недели должен быть твоим итоговым решением', $prompt);
        // Phase A.5 (PR3): из prompt убраны weekly_volume_safety / long_share_cap / threshold_pace —
        // DeepSeek решает по training_state.load_policy сам. Остался только medical guard.
        $this->assertStringContainsString('marathon_last_21_days_training_long_run_max_km', $prompt);
        $this->assertStringContainsString('no_training_run_at_or_above_race_distance_except_race_day', $prompt);
        $this->assertStringContainsString('Не ставь медленный steady/easy pace в type=tempo', $prompt);
        $this->assertStringContainsString('"segments":null', $prompt);
        $this->assertStringContainsString('Для fartlek обязательно заполни segments', $prompt);
        $this->assertStringContainsString('Не возвращай fartlek только с разминкой и заминкой', $prompt);
        // Удалённые legacy конструкты не должны проникать в prompt:
        $this->assertStringNotContainsString('macro_detail_contract', $prompt);
        $this->assertStringNotContainsString('week_volume_tolerance', $prompt);
        $this->assertStringNotContainsString('week_total_km * long_share_cap', $prompt);
    }

    public function test_single_pass_macro_is_derived_from_detail_weeks(): void
    {
        $method = new \ReflectionMethod($this->planner, 'deriveMacroPlanFromWeeks');
        $method->setAccessible(true);

        $macro = $method->invoke($this->planner, [[
            'week_number' => 2,
            'phase' => 'build',
            'target_volume_km' => 52.0,
            'macro_adjustment_reason' => 'Первая неделя после марафона сделана мягче.',
            'days' => [
                ['type' => 'easy', 'distance_km' => 10.0],
                ['type' => 'rest'],
                ['type' => 'tempo', 'distance_km' => 10.0],
                ['type' => 'rest'],
                ['type' => 'easy', 'distance_km' => 10.0],
                ['type' => 'rest'],
                ['type' => 'long', 'distance_km' => 22.0],
            ],
        ]]);

        $this->assertSame(2, (int) ($macro['weeks'][0]['week'] ?? 0));
        $this->assertSame(52.0, (float) ($macro['weeks'][0]['target_volume_km'] ?? 0.0));
        $this->assertSame(22.0, (float) ($macro['weeks'][0]['long_run_km'] ?? 0.0));
        $this->assertSame('См. детальный календарь недели.', $macro['weeks'][0]['quality_focus'] ?? '');
    }

    public function test_single_pass_week_target_is_aligned_to_calendar_total(): void
    {
        $method = new \ReflectionMethod($this->planner, 'alignWeekTargetsToCalendar');
        $method->setAccessible(true);

        $weeks = $method->invoke($this->planner, [[
            'week_number' => 1,
            'target_volume_km' => 72.0,
            'days' => [
                ['type' => 'easy', 'distance_km' => 10.0],
                ['type' => 'rest'],
                ['type' => 'tempo', 'distance_km' => 11.0],
                ['type' => 'rest'],
                ['type' => 'easy', 'distance_km' => 8.0],
                ['type' => 'rest'],
                ['type' => 'long', 'distance_km' => 22.0],
            ],
        ]]);

        $this->assertSame(51.0, (float) ($weeks[0]['target_volume_km'] ?? 0.0));
    }

    public function test_calendar_weeks_include_days_to_race(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildCalendarWeeks');
        $method->setAccessible(true);

        $weeks = $method->invoke($this->planner, '2026-06-08', 2, '2026-07-04');

        $this->assertSame('2026-06-14', $weeks[0]['days'][6]['date'] ?? null);
        $this->assertSame(20, (int) ($weeks[0]['days'][6]['days_to_race'] ?? 0));
        $this->assertFalse((bool) ($weeks[0]['days'][6]['is_race_date'] ?? true));
    }

    public function test_full_plan_prompt_includes_planning_scenario_and_goal_realism_guidance(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildFullPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner, [
            'weeks_count' => 12,
            'training_state' => [
                'load_policy' => ['allowed_growth_ratio' => 1.10],
            ],
            'planning_scenario' => [
                'primary' => 'b_race_before_a_race',
                'flags' => ['b_race_before_a_race'],
            ],
            'goal_realism' => [
                'verdict' => 'unrealistic',
                'severity' => 'major',
                'recommended_target_time' => '03:45:00',
            ],
            'hard_rules' => [
                'allowed_run_day_numbers' => [1, 3, 5, 7],
            ],
        ]);

        $this->assertStringContainsString('Сценарии и goal_realism', $prompt);
        $this->assertStringContainsString("planning_scenario.primary='return_after_injury'", $prompt);
        $this->assertStringContainsString("'b_race_before_a_race'", $prompt);
        $this->assertStringContainsString("goal_realism.severity='major'", $prompt);
        $this->assertStringContainsString('recommended_target_time', $prompt);
    }

    public function test_full_plan_prompt_includes_recent_compliance_and_workouts_guidance(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildFullPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner, [
            'weeks_count' => 12,
            'training_state' => ['load_policy' => null],
            'recent_compliance' => [
                ['week_start' => '2026-04-13', 'planned_count' => 5, 'completed_count' => 3, 'compliance_ratio' => 0.6],
            ],
            'recent_workouts' => [
                ['date' => '2026-04-25', 'type' => 'easy', 'pace_sec' => 320, 'hr_avg' => 150, 'rpe' => 4],
            ],
            'hard_rules' => ['allowed_run_day_numbers' => [1, 3, 5, 7]],
        ]);

        $this->assertStringContainsString('recent_compliance', $prompt);
        $this->assertStringContainsString('Контекст последних недель', $prompt);
        $this->assertStringContainsString('key_workout_completion_pct', $prompt);
        $this->assertStringContainsString('recent_workouts', $prompt);
        $this->assertStringContainsString('hr_avg', $prompt);
        $this->assertStringContainsString('rpe', $prompt);
    }

    // Phase A.5 (PR3): compactPlanningScenario / compactGoalRealism удалены — DeepSeek получает
    // полный объект как есть. Соответствующий unit-тест удалён, новые проверки full-data попадают
    // в test_full_plan_prompt_includes_planning_scenario_and_goal_realism_guidance.

    public function test_recent_long_effort_guard_detects_marathon_before_plan_start(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildRecentLongEffortGuard');
        $method->setAccessible(true);

        $guard = $method->invoke($this->planner, [
            ['date' => '2026-05-03', 'distance_km' => 42.69],
            ['date' => '2026-05-01', 'distance_km' => 5.08],
        ], '2026-05-04', 42.2, 110.6);

        $this->assertIsArray($guard);
        $this->assertTrue((bool) ($guard['applies'] ?? false));
        $this->assertSame('2026-05-03', $guard['recent_effort_date'] ?? null);
        $this->assertSame(1, (int) ($guard['days_before_plan_start'] ?? 0));
        $this->assertSame(19.2, (float) ($guard['week_1_long_run_max_km'] ?? 0.0));
    }
}
