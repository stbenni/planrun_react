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

    public function test_system_prompt_uses_coaching_method_diagnose_strategy_calendar(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner);

        // PR-C: тренерский метод — диагноз → стратегия → календарь
        $this->assertStringContainsString('диагноз', $prompt);
        $this->assertStringContainsString('стратегию', $prompt);
        $this->assertStringContainsString('календарю', $prompt);
        $this->assertStringContainsString('базовую физиологию', $prompt);
        // Формат ответа
        $this->assertStringContainsString('только валидный JSON', $prompt);
        $this->assertStringContainsString('только на русском', $prompt);
        // Системный промпт должен быть компактным (≤ 1500 символов)
        $this->assertLessThan(1500, mb_strlen($prompt), 'System prompt должен быть коротким — детали в FACTS_JSON');
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

    public function test_full_plan_prompt_describes_format_and_calendar_weeks_skeleton(): void
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

        // PR-C: формат ответа и calendar_weeks как skeleton
        $this->assertStringContainsString('plan_summary', $prompt);
        $this->assertStringContainsString('risk_review', $prompt);
        $this->assertStringContainsString('calendar_weeks', $prompt);
        $this->assertStringContainsString('РОВНО weeks_count', $prompt);
        $this->assertStringContainsString('Длительный бег всегда type=long', $prompt);
        // Маркеры на дне
        $this->assertStringContainsString('suggested_default', $prompt);
        $this->assertStringContainsString('race_proximity', $prompt);
        $this->assertStringContainsString('pre_race_day_minus_1', $prompt);
        $this->assertStringContainsString('post_race_recovery_day_1', $prompt);
        // Медицинские границы передаются как hard_rules в FACTS_JSON
        $this->assertStringContainsString('long_run_safety', $prompt);
        $this->assertStringContainsString('Recovery weeks', $prompt);
        // PR-D итерация: явные coaching-инварианты (не «костыли», а медицинские границы)
        $this->assertStringContainsString('Hard/easy alternation', $prompt);
        $this->assertStringContainsString('marathon — 3 нед. taper', $prompt);
        $this->assertStringContainsString('half — 2 нед. taper', $prompt);
        $this->assertStringContainsString('Special populations', $prompt);
        $this->assertStringContainsString('return_after_injury', $prompt);
        $this->assertStringContainsString('Pregnancy/postpartum', $prompt);
        $this->assertStringContainsString('training_paces', $prompt);
        // PR-D итерация 2: peak long ranges + long share cap + long progression
        $this->assertStringContainsString('Peak long', $prompt);
        $this->assertStringContainsString('marathon → 28-32км', $prompt);
        $this->assertStringContainsString('Long share', $prompt);
        $this->assertStringContainsString('35% от недельного объёма', $prompt);
        $this->assertStringContainsString('Long progression и cutback', $prompt);
        // PR-D итерация 3: peak weekly volume должен использовать peak_volume_floor_km
        $this->assertStringContainsString('Peak weekly volume', $prompt);
        $this->assertStringContainsString('peak_volume_floor_km', $prompt);
        // PR9: pace_strategy — мост к цели + marathon-specific MP-runs
        $this->assertStringContainsString('pace_strategy', $prompt);
        $this->assertStringContainsString('goal_paces', $prompt);
        $this->assertStringContainsString('effective_target_pace', $prompt);
        $this->assertStringContainsString('realistic_target', $prompt);
        $this->assertStringContainsString('Marathon-specific', $prompt);
        $this->assertStringContainsString('marathon-pace runs', $prompt);
        // Структура полей дня
        $this->assertStringContainsString('"segments":null', $prompt);
        // Язык
        $this->assertStringContainsString('только на русском', $prompt);
    }

    public function test_full_plan_prompt_does_not_micromanage_compliance_or_sanity_floor(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildFullPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner, [
            'weeks_count' => 9,
            'training_state' => ['load_policy' => null],
            'hard_rules' => ['allowed_run_day_numbers' => [1, 3, 5, 7]],
        ]);

        // PR-C: prose-инструкции «при compliance 60-89% делай X», «sanity-floor =
        // MAX(actual_km, median(...), reported_weekly_base × 0.85)» удалены — это работа
        // тренерского рассуждения по фактам в FACTS_JSON, а не пошаговая инструкция.
        $this->assertStringNotContainsString('compliance 60-89%', $prompt);
        $this->assertStringNotContainsString('compliance 90-110%', $prompt);
        $this->assertStringNotContainsString('SANITY-FLOOR', $prompt);
        $this->assertStringNotContainsString('MAX(recent_compliance.actual_km)', $prompt);
        // Также убраны явные «диктовки» о peak volume цифрами в промпт
        $this->assertStringNotContainsString('подними peak на 10-15%', $prompt);
        $this->assertStringNotContainsString('Снизь общий объём на 10-15%', $prompt);
        // И prose про reasoning по recent_workouts
        $this->assertStringNotContainsString('Рост HR на тех же темпах', $prompt);
        // Coaching prompt должен оставаться компактным (≤ 6000 chars без FACTS_JSON).
        // PR-D итерация добавила hard_rules про taper/alternation/special-populations,
        // peak long ranges, long share cap, long progression. PR9 добавил pace_strategy
        // и marathon-specific MP-runs. Всё равно в разы меньше legacy ~10000.
        $promptWithoutFacts = preg_replace('/FACTS_JSON:\s*.*?\nСегодня:.+$/s', '', $prompt);
        $this->assertLessThan(6000, mb_strlen($promptWithoutFacts ?? ''), 'Coaching prompt должен быть компактным (≤ 6000 chars без FACTS_JSON)');
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

    /**
     * PR-B (coaching prompt v4): race_proximity — семантический ярлык.
     * Helper: выкатываем все дни плоско по дате → race_proximity для удобства проверки.
     */
    private function flattenRaceProximity(array $weeks): array
    {
        $byDate = [];
        foreach ($weeks as $week) {
            foreach ((array) ($week['days'] ?? []) as $day) {
                $byDate[$day['date']] = $day['race_proximity'] ?? null;
            }
        }
        return $byDate;
    }

    public function test_race_proximity_marks_pre_taper_minus1_race_post1_post2(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildCalendarWeeks');
        $method->setAccessible(true);

        // Plan from Mon 2026-05-11 (week 1) to Sun 2026-05-17 (week 1 day 7).
        // Race on Saturday 2026-05-16. Need at least 2 weeks to cover days after race.
        $weeks = $method->invoke($this->planner, '2026-05-11', 2, '2026-05-16');
        $byDate = $this->flattenRaceProximity($weeks);

        // 11.05 (mon) — 5 дней до race → pre_race_taper
        $this->assertSame('pre_race_taper', $byDate['2026-05-11'] ?? null);
        // 12.05 (tue) — 4 дня до race → pre_race_taper
        $this->assertSame('pre_race_taper', $byDate['2026-05-12'] ?? null);
        // 13.05 (wed) — 3 дня до race → pre_race_taper
        $this->assertSame('pre_race_taper', $byDate['2026-05-13'] ?? null);
        // 14.05 (thu) — 2 дня до race → pre_race_taper
        $this->assertSame('pre_race_taper', $byDate['2026-05-14'] ?? null);
        // 15.05 (fri) — 1 день до race → pre_race_day_minus_1 (priority over pre_race_taper)
        $this->assertSame('pre_race_day_minus_1', $byDate['2026-05-15'] ?? null);
        // 16.05 (sat) — race
        $this->assertSame('race_day', $byDate['2026-05-16'] ?? null);
        // 17.05 (sun) — день после
        $this->assertSame('post_race_recovery_day_1', $byDate['2026-05-17'] ?? null);
    }

    public function test_race_proximity_marks_post_race_day_2_and_null_after(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildCalendarWeeks');
        $method->setAccessible(true);

        // 3-week horizon from 2026-05-11. Race on 2026-05-16 (Sat). Check days after.
        $weeks = $method->invoke($this->planner, '2026-05-11', 3, '2026-05-16');
        $byDate = $this->flattenRaceProximity($weeks);

        $this->assertSame('post_race_recovery_day_1', $byDate['2026-05-17'] ?? null);
        $this->assertSame('post_race_recovery_day_2', $byDate['2026-05-18'] ?? null);
        // 19.05 (tue) — 3 дня после, никаких других race-дней не задано → null
        $this->assertArrayHasKey('2026-05-19', $byDate);
        $this->assertNull($byDate['2026-05-19']);
        $this->assertArrayHasKey('2026-05-20', $byDate);
        $this->assertNull($byDate['2026-05-20']);
    }

    /**
     * PR9: training_state.pace_strategy должна попадать в FACTS_JSON под training_state,
     * чтобы модель видела goal_paces и effective_target_pace.
     */
    public function test_planner_context_includes_pace_strategy_block(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildPlannerContext');
        $method->setAccessible(true);

        $user = [
            'id' => 0,
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-07-04',
            'race_target_time' => '03:15:00',
            'experience_level' => 'intermediate',
            'weekly_base_km' => 80,
            'sessions_per_week' => 5,
            'preferred_days' => ['monday', 'wednesday', 'friday', 'sunday'],
            'preferred_ofp_days' => [],
        ];
        $state = [
            'pace_strategy' => [
                'mode' => 'realistic_target',
                'effective_target_time' => '03:25:00',
                'effective_target_pace' => '4:51',
                'goal_target_time' => '03:15:00',
                'goal_target_pace' => '4:37',
                'predicted_target_time' => '03:25:00',
                'gap_pct' => 4.9,
                'severity' => 'major',
                'goal_paces' => [
                    'easy' => '5:35 – 6:08',
                    'marathon' => '4:51',
                    'threshold' => '4:32',
                    'interval' => '4:08',
                    'repetition' => '3:51',
                ],
                'current_paces' => [
                    'easy' => '5:31 – 6:04',
                    'marathon' => '5:00',
                    'threshold' => '4:40',
                    'interval' => '4:13',
                    'repetition' => '3:58',
                ],
                'race_distance' => 'marathon',
            ],
        ];

        $context = $method->invoke($this->planner, $user, $state, [], 'recalculate', '2026-05-11', 8);

        $this->assertIsArray($context['training_state']['pace_strategy'] ?? null);
        $this->assertSame('realistic_target', $context['training_state']['pace_strategy']['mode']);
        $this->assertSame('4:51', $context['training_state']['pace_strategy']['effective_target_pace']);
        $this->assertSame('03:15:00', $context['training_state']['pace_strategy']['goal_target_time']);
        $this->assertArrayHasKey('goal_paces', $context['training_state']['pace_strategy']);
        $this->assertSame('4:32', $context['training_state']['pace_strategy']['goal_paces']['threshold']);
    }

    public function test_race_proximity_handles_intermediate_race(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildCalendarWeeks');
        $method->setAccessible(true);

        // Главная race 2026-07-04, intermediate 2026-05-16.
        $weeks = $method->invoke(
            $this->planner,
            '2026-05-11',
            8,
            '2026-07-04',
            ['2026-05-16']
        );
        $byDate = $this->flattenRaceProximity($weeks);

        // Около intermediate race ярлыки тоже работают
        $this->assertSame('pre_race_day_minus_1', $byDate['2026-05-15'] ?? null);
        $this->assertSame('race_day', $byDate['2026-05-16'] ?? null);
        $this->assertSame('post_race_recovery_day_1', $byDate['2026-05-17'] ?? null);
        $this->assertSame('post_race_recovery_day_2', $byDate['2026-05-18'] ?? null);

        // Около главной race
        $this->assertSame('race_day', $byDate['2026-07-04'] ?? null);
        $this->assertSame('pre_race_day_minus_1', $byDate['2026-07-03'] ?? null);
        $this->assertSame('post_race_recovery_day_1', $byDate['2026-07-05'] ?? null);
    }

    public function test_race_proximity_priority_pre_race_over_post_race_when_overlap(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildCalendarWeeks');
        $method->setAccessible(true);

        // Два race подряд через 4 дня: 2026-05-16 и 2026-05-20.
        // 19.05 = day -1 от 20.05 race AND day +3 от 16.05 race → pre_race_day_minus_1 (приоритет)
        // 18.05 = day -2 от 20.05 race AND day +2 от 16.05 race → post_race_recovery_day_2 не подходит,
        //         но pre_race_taper (diff=2) подходит. По приоритету post_race_day_1 не активен (-2),
        //         pre_race_taper выше → pre_race_taper.
        // 17.05 = day -3 от 20.05 race AND day +1 от 16.05 race → post_race_recovery_day_1 (приоритет)
        $weeks = $method->invoke(
            $this->planner,
            '2026-05-11',
            2,
            '2026-05-16',
            ['2026-05-20']
        );
        $byDate = $this->flattenRaceProximity($weeks);

        $this->assertSame('race_day', $byDate['2026-05-16'] ?? null);
        $this->assertSame('post_race_recovery_day_1', $byDate['2026-05-17'] ?? null);
        $this->assertSame('pre_race_taper', $byDate['2026-05-18'] ?? null);
        $this->assertSame('pre_race_day_minus_1', $byDate['2026-05-19'] ?? null);
        $this->assertSame('race_day', $byDate['2026-05-20'] ?? null);
    }

    public function test_race_proximity_is_null_when_no_race_in_horizon(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildCalendarWeeks');
        $method->setAccessible(true);

        // Нет race_date, нет intermediate.
        $weeks = $method->invoke($this->planner, '2026-05-11', 2, '');
        $byDate = $this->flattenRaceProximity($weeks);

        foreach ($byDate as $date => $proximity) {
            $this->assertNull($proximity, "Expected null race_proximity for {$date}");
        }
    }

    public function test_full_plan_prompt_passes_planning_scenario_and_goal_realism_via_facts_json(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildFullPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner, [
            'weeks_count' => 12,
            'training_state' => ['load_policy' => ['allowed_growth_ratio' => 1.10]],
            'planning_scenario' => [
                'primary' => 'b_race_before_a_race',
                'flags' => ['b_race_before_a_race'],
            ],
            'goal_realism' => [
                'verdict' => 'unrealistic',
                'severity' => 'major',
                'recommended_target_time' => '03:45:00',
            ],
            'hard_rules' => ['allowed_run_day_numbers' => [1, 3, 5, 7]],
        ]);

        // PR-C: prose-инструкции «при scenario X делай Y» удалены. Данные попадают в FACTS_JSON,
        // тренер-модель решает сама. Проверяем, что объекты сериализуются в FACTS_JSON.
        $this->assertStringContainsString('"planning_scenario"', $prompt);
        $this->assertStringContainsString('"b_race_before_a_race"', $prompt);
        $this->assertStringContainsString('"goal_realism"', $prompt);
        $this->assertStringContainsString('"severity": "major"', $prompt);
        $this->assertStringContainsString('"recommended_target_time": "03:45:00"', $prompt);
        // Микро-инструкций больше нет
        $this->assertStringNotContainsString('Сценарии и goal_realism', $prompt);
        $this->assertStringNotContainsString("planning_scenario.primary='return_after_injury'", $prompt);
    }

    public function test_full_plan_prompt_passes_recent_compliance_summary_to_facts_json(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildFullPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner, [
            'weeks_count' => 12,
            'training_state' => ['load_policy' => null],
            'recent_compliance' => [
                ['week_start' => '2026-04-13', 'planned_count' => 5, 'completed_count' => 3, 'compliance_ratio' => 0.6],
            ],
            'recent_compliance_summary' => 'За 4 нед. запланировано 20 тренировок, выполнено 12.',
            'hard_rules' => ['allowed_run_day_numbers' => [1, 3, 5, 7]],
        ]);

        // PR-A: тренерский summary в FACTS_JSON
        $this->assertStringContainsString('recent_compliance_summary', $prompt);
        $this->assertStringContainsString('запланировано 20 тренировок, выполнено 12', $prompt);
        // PR-C: prose «при compliance 60-89% делай X» удалена
        $this->assertStringNotContainsString('compliance 60-89', $prompt);
        $this->assertStringNotContainsString('Контекст последних недель', $prompt);
    }

    public function test_full_plan_prompt_passes_season_and_best_races_via_facts_json(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildFullPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->planner, [
            'weeks_count' => 12,
            'training_state' => ['load_policy' => null],
            'season' => [
                'current_month_name' => 'may',
                'race_season_phase' => 'summer',
            ],
            'best_races' => [
                ['distance_label' => 'half', 'distance_km' => 21.1, 'time_sec' => 6600],
            ],
            'hard_rules' => ['allowed_run_day_numbers' => [1, 3, 5, 7]],
        ]);

        // PR-C: данные по климату и истории — в FACTS_JSON, prose-инструкции удалены
        $this->assertStringContainsString('"season"', $prompt);
        $this->assertStringContainsString('"current_month_name": "may"', $prompt);
        $this->assertStringContainsString('"best_races"', $prompt);
        $this->assertStringNotContainsString('Климат и сезон', $prompt);
        $this->assertStringNotContainsString('История лучших результатов', $prompt);
    }

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

    // ========================================================================
    // Phase C.1 (PR5): tests for complexity scoring and model selection
    // ========================================================================

    public function test_compute_complexity_score_returns_zero_for_clean_state(): void
    {
        $score = $this->planner->computeComplexityScore([
            'planning_scenario' => ['flags' => []],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ]);

        $this->assertSame(0, $score);
    }

    public function test_compute_complexity_score_counts_scenario_flags(): void
    {
        $score = $this->planner->computeComplexityScore([
            'planning_scenario' => [
                'flags' => ['return_after_injury', 'pain_protective', 'b_race_before_a_race'],
            ],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ]);

        $this->assertSame(3, $score);
    }

    public function test_compute_complexity_score_counts_population_flags_and_goal_realism(): void
    {
        $score = $this->planner->computeComplexityScore([
            'planning_scenario' => ['flags' => ['short_runway_long_race']],
            'special_population_flags' => ['return_after_injury', 'recent_pain_signal'],
            'goal_realism' => ['severity' => 'major'],
        ]);

        $this->assertSame(4, $score);
    }

    public function test_resolve_model_selection_defaults_to_reasoner_with_thinking_always(): void
    {
        // PR-C (coaching prompt v4): thinking_always=true (default) → всегда reasoner+thinking.
        $previous = getenv('PLAN_LLM_THINKING_ALWAYS');
        putenv('PLAN_LLM_THINKING_ALWAYS=1');
        try {
            $planner = new \DeepSeekPlanPlanner(getDBConnection());
            $selection = $planner->resolveModelSelection([
                'planning_scenario' => ['flags' => []],
                'special_population_flags' => [],
                'goal_realism' => ['severity' => 'none'],
            ]);

            $this->assertSame(0, $selection['score']);
            $this->assertSame('thinking_always', $selection['reason']);
            $this->assertTrue($selection['enable_thinking']);
            $this->assertSame('deepseek-reasoner', $selection['model']);
        } finally {
            $previous === false ? putenv('PLAN_LLM_THINKING_ALWAYS') : putenv('PLAN_LLM_THINKING_ALWAYS=' . $previous);
        }
    }

    /**
     * Полное переопределение env-переменной для тестов: env_loader клад$ёт значение
     * в $_ENV / $_SERVER, и putenv() этого не сбрасывает. Возвращает callback для restore.
     */
    private function overrideEnv(string $key, string $value): callable
    {
        $prevEnv = $_ENV[$key] ?? null;
        $prevServer = $_SERVER[$key] ?? null;
        $prevGetenv = getenv($key);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
        return function () use ($key, $prevEnv, $prevServer, $prevGetenv): void {
            if ($prevEnv === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $prevEnv; }
            if ($prevServer === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $prevServer; }
            $prevGetenv === false ? putenv($key) : putenv("$key=$prevGetenv");
        };
    }

    public function test_resolve_model_selection_falls_back_to_auto_escalation_when_thinking_always_disabled(): void
    {
        // PR-C: с PLAN_LLM_THINKING_ALWAYS=0 возвращаемся к старой эвристике (Phase C.1).
        $restore = $this->overrideEnv('PLAN_LLM_THINKING_ALWAYS', '0');
        try {
            $planner = new \DeepSeekPlanPlanner(getDBConnection());
            $selection = $planner->resolveModelSelection([
                'planning_scenario' => ['flags' => []],
                'special_population_flags' => [],
                'goal_realism' => ['severity' => 'none'],
            ]);

            $this->assertSame(0, $selection['score']);
            $this->assertSame('default', $selection['reason']);
            $this->assertFalse($selection['enable_thinking']);
        } finally {
            $restore();
        }
    }

    public function test_resolve_model_selection_escalates_to_reasoner_for_complex_scenario_when_thinking_always_off(): void
    {
        $restore = $this->overrideEnv('PLAN_LLM_THINKING_ALWAYS', '0');
        try {
            $planner = new \DeepSeekPlanPlanner(getDBConnection());
            $selection = $planner->resolveModelSelection([
                'planning_scenario' => ['flags' => ['return_after_injury', 'b_race_before_a_race']],
                'special_population_flags' => [],
                'goal_realism' => ['severity' => 'major'],
            ]);

            $this->assertGreaterThanOrEqual(2, $selection['score']);
            $this->assertSame('complex_scenario', $selection['reason']);
            $this->assertTrue($selection['enable_thinking']);
            $this->assertSame('deepseek-reasoner', $selection['model']);
            $this->assertGreaterThan(120, (int) $selection['timeout_seconds']);
        } finally {
            $restore();
        }
    }

    public function test_resolve_model_selection_does_not_escalate_for_single_minor_risk_when_thinking_always_off(): void
    {
        $restore = $this->overrideEnv('PLAN_LLM_THINKING_ALWAYS', '0');
        try {
            $planner = new \DeepSeekPlanPlanner(getDBConnection());
            $selection = $planner->resolveModelSelection([
                'planning_scenario' => ['flags' => ['low_confidence_start']],
                'special_population_flags' => [],
                'goal_realism' => ['severity' => 'none'],
            ]);

            $this->assertSame(1, $selection['score']);
            // Phase C.1: один minor flag — score=1 — но эскалация всё равно идёт.
            $this->assertSame('complex_scenario', $selection['reason']);
            $this->assertTrue($selection['enable_thinking']);
        } finally {
            $restore();
        }
    }

    // ========================================================================
    // Phase C.2 (PR5): tests for targeted retry
    // ========================================================================

    public function test_build_targeted_retry_prompt_focuses_on_requested_weeks(): void
    {
        $method = new \ReflectionMethod($this->planner, 'buildTargetedRetryPrompt');
        $method->setAccessible(true);

        $context = [
            'weeks_count' => 4,
            'calendar_weeks' => [
                ['week_number' => 1, 'days' => []],
                ['week_number' => 2, 'days' => []],
                ['week_number' => 3, 'days' => []],
                ['week_number' => 4, 'days' => []],
            ],
            'training_state' => ['load_policy' => null],
            'hard_rules' => ['allowed_run_day_numbers' => [1, 3, 5, 7]],
        ];
        $existingPlan = [
            'weeks_data' => [
                'weeks' => [
                    ['week_number' => 1, 'phase' => 'base', 'target_volume_km' => 30],
                    ['week_number' => 2, 'phase' => 'build', 'target_volume_km' => 38],
                    ['week_number' => 3, 'phase' => 'build', 'target_volume_km' => 42],
                    ['week_number' => 4, 'phase' => 'recovery', 'target_volume_km' => 28],
                ],
            ],
        ];

        $prompt = $method->invoke($this->planner, $context, $existingPlan, [2, 3], [
            'Week 2: long run share = 55% of weekly volume — too high',
            'Week 3: tempo placed on day 7 instead of day 5',
        ]);

        $this->assertStringContainsString('Недели для перевыдачи (week_numbers): 2, 3', $prompt);
        $this->assertStringContainsString('Week 2: long run share', $prompt);
        $this->assertStringContainsString('Week 3: tempo placed on day 7', $prompt);
        $this->assertStringContainsString('"is_to_redo": true', $prompt);
        // Должна остаться структура других недель (для контекста фаз).
        $this->assertStringContainsString('"phase": "base"', $prompt);
        $this->assertStringContainsString('"phase": "recovery"', $prompt);
        $this->assertStringContainsString('ровно 2 элементов', $prompt);
    }

    public function test_apply_regenerated_weeks_replaces_only_target_weeks(): void
    {
        $existingPlan = [
            'weeks_data' => [
                'weeks' => [
                    ['week_number' => 1, 'phase' => 'base', 'target_volume_km' => 30, 'days' => []],
                    ['week_number' => 2, 'phase' => 'build', 'target_volume_km' => 38, 'days' => []],
                    ['week_number' => 3, 'phase' => 'build', 'target_volume_km' => 42, 'days' => []],
                    ['week_number' => 4, 'phase' => 'recovery', 'target_volume_km' => 28, 'days' => []],
                ],
            ],
        ];

        $regenerated = [
            ['week_number' => 2, 'phase' => 'build', 'target_volume_km' => 36, 'days' => [
                ['day_of_week' => 1, 'type' => 'easy', 'distance_km' => 8.0],
                ['day_of_week' => 2, 'type' => 'rest'],
                ['day_of_week' => 3, 'type' => 'tempo', 'distance_km' => 10.0],
                ['day_of_week' => 4, 'type' => 'rest'],
                ['day_of_week' => 5, 'type' => 'easy', 'distance_km' => 6.0],
                ['day_of_week' => 6, 'type' => 'rest'],
                ['day_of_week' => 7, 'type' => 'long', 'distance_km' => 12.0],
            ]],
        ];

        $result = $this->planner->applyRegeneratedWeeks($existingPlan, $regenerated);

        $weeks = $result['weeks_data']['weeks'];
        $this->assertCount(4, $weeks);
        $this->assertSame(1, $weeks[0]['week_number']);
        $this->assertSame(30, (int) $weeks[0]['target_volume_km'], 'Week 1 must remain unchanged');

        $this->assertSame(2, $weeks[1]['week_number']);
        $this->assertCount(7, $weeks[1]['days'], 'Week 2 days must come from regenerated payload');

        $this->assertSame(3, $weeks[2]['week_number']);
        $this->assertSame(42, (int) $weeks[2]['target_volume_km'], 'Week 3 must remain unchanged');

        $this->assertSame(4, $weeks[3]['week_number']);
        $this->assertSame(28, (int) $weeks[3]['target_volume_km'], 'Week 4 must remain unchanged');

        $this->assertArrayHasKey('targeted_retry', $result['_generation_metadata']);
        $this->assertSame([2], $result['_generation_metadata']['targeted_retry']['regenerated_week_numbers']);
    }

    public function test_apply_regenerated_weeks_aligns_target_volume_to_day_sum(): void
    {
        $existingPlan = [
            'weeks_data' => [
                'weeks' => [
                    ['week_number' => 1, 'phase' => 'base', 'target_volume_km' => 30, 'days' => []],
                ],
            ],
        ];

        $regenerated = [
            ['week_number' => 1, 'phase' => 'base', 'target_volume_km' => 99.0, 'days' => [
                ['day_of_week' => 1, 'type' => 'easy', 'distance_km' => 8.0],
                ['day_of_week' => 2, 'type' => 'rest'],
                ['day_of_week' => 3, 'type' => 'tempo', 'distance_km' => 10.0],
                ['day_of_week' => 4, 'type' => 'rest'],
                ['day_of_week' => 5, 'type' => 'easy', 'distance_km' => 6.0],
                ['day_of_week' => 6, 'type' => 'rest'],
                ['day_of_week' => 7, 'type' => 'long', 'distance_km' => 12.0],
            ]],
        ];

        $result = $this->planner->applyRegeneratedWeeks($existingPlan, $regenerated);

        // Sum 8 + 10 + 6 + 12 = 36; target_volume_km должен быть выровнен к этой сумме.
        $this->assertSame(36.0, (float) $result['weeks_data']['weeks'][0]['target_volume_km']);
    }

    public function test_regenerate_weeks_rejects_empty_week_list(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('weekNumbersToRedo cannot be empty');
        $this->planner->regenerateWeeks([], [], []);
    }

    public function test_regenerate_weeks_rejects_too_many_weeks(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many weeks');
        $this->planner->regenerateWeeks([], [], [1, 2, 3, 4, 5, 6, 7]);
    }
}
