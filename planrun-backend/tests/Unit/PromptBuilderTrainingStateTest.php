<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/prompt_builder.php';

class PromptBuilderTrainingStateTest extends TestCase {
    public function test_buildPaceZonesBlock_prefersTrainingStateOverLegacyFallback(): void {
        $user = [
            'easy_pace_sec' => 390,
            'training_state' => [
                'vdot_confidence' => 'high',
                'pace_rules' => [
                    'easy_min_sec' => 300,
                    'easy_max_sec' => 320,
                    'long_min_sec' => 315,
                    'long_max_sec' => 340,
                    'tempo_sec' => 275,
                    'tempo_tolerance_sec' => 8,
                    'interval_sec' => 255,
                    'interval_tolerance_sec' => 8,
                    'recovery_min_sec' => 330,
                    'recovery_max_sec' => 355,
                ],
            ],
        ];

        $block = buildPaceZonesBlock($user);

        $this->assertStringContainsString('5:00 – 5:20', $block);
        $this->assertStringNotContainsString('6:30', $block);
    }

    public function test_buildTrainingPlanPrompt_includes_week_skeleton_block_when_present(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-05-17',
            'training_start_date' => '2026-03-09',
            'sessions_per_week' => 4,
            'preferred_days' => ['mon', 'wed', 'sat', 'sun'],
            'training_state' => [
                'vdot' => 45.0,
                'vdot_source_label' => 'лучшие свежие тренировки',
                'vdot_confidence' => 'medium',
                'pace_rules' => [
                    'easy_min_sec' => 320,
                    'easy_max_sec' => 340,
                    'long_min_sec' => 335,
                    'long_max_sec' => 360,
                    'tempo_sec' => 290,
                    'tempo_tolerance_sec' => 8,
                    'interval_sec' => 270,
                    'interval_tolerance_sec' => 8,
                    'recovery_min_sec' => 345,
                    'recovery_max_sec' => 370,
                ],
            ],
            'plan_skeleton' => [
                'weeks' => [[
                    'week_number' => 1,
                    'phase_label' => 'Базовый',
                    'days' => ['easy', 'rest', 'easy', 'rest', 'rest', 'easy', 'long'],
                ]],
            ],
        ];

        $prompt = buildTrainingPlanPrompt($user, 'race');

        $this->assertStringContainsString('WEEK SKELETON', $prompt);
        $this->assertStringContainsString('Пн easy | Вт rest | Ср easy', $prompt);
    }

    public function test_buildTrainingStateBlock_includes_special_population_flags(): void {
        $block = buildTrainingStateBlock([
            'training_state' => [
                'vdot' => 38.5,
                'vdot_source_label' => 'оценка по лёгкому темпу',
                'vdot_confidence' => 'low',
                'readiness' => 'low',
                'weeks_to_goal' => 8,
                'preferred_long_day' => 'sun',
                'age_years' => 67,
                'load_policy' => [
                    'allowed_growth_ratio' => 1.08,
                    'recovery_weeks' => [3, 6],
                ],
                'special_population_flags' => ['older_adult_65_plus', 'low_confidence_vdot'],
            ],
        ]);

        $this->assertStringContainsString('Special population flags: older_adult_65_plus, low_confidence_vdot', $block);
        $this->assertStringContainsString('Предпочтительный день длительной: Воскресенье', $block);
        $this->assertStringContainsString('Возраст: 67', $block);
        $this->assertStringContainsString('Safety envelope по объёму: рост недельного объёма обычно не выше ~8%', $block);
        $this->assertStringContainsString('Это мягкий коридор безопасности, а не требование делать одинаковые недели.', $block);
    }

    public function test_applyScheduleOverridesToUserData_extracts_long_and_rest_days_from_reason(): void {
        $user = [
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            'sessions_per_week' => 7,
        ];

        $updated = applyScheduleOverridesToUserData($user, 'хочу лонги по воскресеньям, а отдыхать в пятницу');

        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'sat', 'sun'], $updated['preferred_days']);
        $this->assertSame(6, $updated['sessions_per_week']);
        $this->assertSame('sun', $updated['preferred_long_day']);
        $this->assertSame('fri', $updated['schedule_reason_overrides']['rest_day']);
        $this->assertSame('sun', $updated['schedule_reason_overrides']['long_day']);
        $this->assertSame('sun', getPreferredLongRunDayKey($updated));
    }

    public function test_applyScheduleOverridesToUserData_extracts_benchmark_and_easy_floor_from_reason(): void {
        $user = [
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
            'sessions_per_week' => 6,
        ];

        $updated = applyScheduleOverridesToUserData(
            $user,
            'Ориентир текущей формы: полумарафон около 1:40. Обычные easy-пробежки не короче 10 км.'
        );

        $this->assertSame('half', $updated['planning_benchmark_distance']);
        $this->assertSame('1:40:00', $updated['planning_benchmark_time']);
        $this->assertSame(10.0, $updated['planning_easy_min_km']);
    }

    public function test_buildRecalculationPrompt_flexible_mode_omits_training_state_and_week_skeleton(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-05-10',
            'training_start_date' => '2026-03-10',
            'sessions_per_week' => 6,
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
            'training_state' => [
                'vdot' => 45.1,
                'vdot_confidence' => 'high',
            ],
            'plan_skeleton' => [
                'weeks' => [[
                    'week_number' => 1,
                    'phase_label' => 'Пиковый',
                    'days' => ['easy', 'tempo', 'easy', 'interval', 'rest', 'easy', 'long'],
                ]],
            ],
        ];

        $prompt = buildRecalculationPrompt($user, 'race', [
            'new_start_date' => '2026-03-10',
            'kept_weeks' => 16,
            'weeks_to_generate' => 8,
            'generation_mode' => 'flexible',
        ]);

        $this->assertStringNotContainsString('TRAINING STATE', $prompt);
        $this->assertStringNotContainsString('WEEK SKELETON', $prompt);
        $this->assertStringContainsString('Конкретную структуру tempo/control/interval подбирай самостоятельно', $prompt);
    }

    public function test_buildTrainingPlanPrompt_marathon_explains_control_is_not_regular_mp_work(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-05-10',
            'training_start_date' => '2026-01-05',
            'sessions_per_week' => 5,
            'preferred_days' => ['mon', 'tue', 'thu', 'sat', 'sun'],
            'weekly_base_km' => 55,
            'experience_level' => 'intermediate',
        ];

        $prompt = buildTrainingPlanPrompt($user, 'race');

        $this->assertStringContainsString('обычно должна быть type: tempo или частью long, а не control', $prompt);
        $this->assertStringContainsString('обычно 0-1 раза за специфический блок', $prompt);
        $this->assertStringContainsString('tempo для марафона не должен быть только пороговым', $prompt);
        $this->assertStringContainsString('WORKOUT INTENT', $prompt);
        $this->assertStringContainsString('QUALITY DAY CONTRACT', $prompt);
        $this->assertStringContainsString('goal_pace_specific', $prompt);
        $this->assertStringContainsString('tempo => goal_pace_specific', $prompt);
    }

    public function test_buildRecalculationPrompt_flexible_mode_includes_workout_intent_block(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_date' => '2026-05-10',
            'race_target_time' => '03:30:00',
            'training_start_date' => '2026-03-10',
            'sessions_per_week' => 6,
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
            'experience_level' => 'intermediate',
            'training_state' => [
                'goal_pace' => '4:59',
            ],
        ];

        $prompt = buildRecalculationPrompt($user, 'race', [
            'new_start_date' => '2026-03-10',
            'kept_weeks' => 16,
            'weeks_to_generate' => 8,
            'generation_mode' => 'flexible',
            'current_phase' => [
                'weeks_into_phase' => 0,
                'recovery_weeks' => [19, 23],
                'control_weeks' => [20],
                'remaining_phases' => [
                    ['name' => 'build', 'label' => 'Развивающий', 'weeks_from' => 17, 'weeks_to' => 20],
                    ['name' => 'peak', 'label' => 'Пиковый', 'weeks_from' => 21, 'weeks_to' => 22],
                    ['name' => 'taper', 'label' => 'Подводка', 'weeks_from' => 23, 'weeks_to' => 24],
                ],
            ],
        ]);

        $this->assertStringContainsString('WORKOUT INTENT', $prompt);
        $this->assertStringContainsString('QUALITY DAY CONTRACT', $prompt);
        $this->assertStringContainsString('goal_pace_specific', $prompt);
        $this->assertStringContainsString('Control используй только там, где intent прямо говорит о benchmark/tune-up', $prompt);
        $this->assertStringContainsString('tempo => goal_pace_specific', $prompt);
    }

    public function test_computeMacrocycle_marathon_control_weeks_are_rare(): void {
        $user = [
            'race_distance' => 'marathon',
            'race_date' => '2026-05-10',
            'training_start_date' => '2026-01-05',
            'weekly_base_km' => 55,
            'sessions_per_week' => 5,
            'experience_level' => 'intermediate',
        ];

        $macro = computeMacrocycle($user, 'race');

        $this->assertNotNull($macro);
        $trainWeeks = $macro['total_weeks'] - ($macro['phases'][count($macro['phases']) - 1]['weeks_to'] - $macro['phases'][count($macro['phases']) - 1]['weeks_from'] + 1);
        $this->assertLessThanOrEqual(2, count($macro['control_weeks']));
        foreach (($macro['control_weeks'] ?? []) as $week) {
            $this->assertGreaterThanOrEqual(5, $week);
            $this->assertLessThanOrEqual($trainWeeks - 4, $week);
        }
    }
}
