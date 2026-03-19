<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/plan_validator.php';

class PlanValidatorTest extends TestCase {
    public function test_validateNormalizedPlanAgainstTrainingState_warnsOnAggressiveVolumeSpike(): void {
        $normalized = [
            'weeks' => [
                ['week_number' => 1, 'total_volume' => 20.0, 'days' => []],
                ['week_number' => 2, 'total_volume' => 28.0, 'days' => []],
            ],
        ];

        $trainingState = [
            'readiness' => 'low',
            'pace_rules' => null,
        ];

        $warnings = validateNormalizedPlanAgainstTrainingState($normalized, $trainingState);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('слишком агрессивным', $warnings[0]);
    }

    public function test_validateNormalizedPlanAgainstTrainingState_warnsOnEasyPaceOutsideCorridor(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'total_volume' => 30.0,
                'days' => [
                    ['date' => '2026-03-09', 'type' => 'easy', 'pace' => '4:20'],
                    ['date' => '2026-03-10', 'type' => 'long', 'pace' => '5:55'],
                    ['date' => '2026-03-11', 'type' => 'tempo', 'pace' => '4:35'],
                ],
            ]],
        ];

        $trainingState = [
            'readiness' => 'normal',
            'pace_rules' => [
                'easy_min_sec' => 320,
                'easy_max_sec' => 340,
                'long_min_sec' => 335,
                'long_max_sec' => 360,
                'tempo_sec' => 275,
                'tempo_tolerance_sec' => 8,
                'interval_sec' => 255,
                'interval_tolerance_sec' => 8,
                'recovery_min_sec' => 345,
                'recovery_max_sec' => 370,
            ],
        ];

        $warnings = validateNormalizedPlanAgainstTrainingState($normalized, $trainingState);

        $this->assertNotEmpty($warnings);
        $this->assertTrue(
            count(array_filter($warnings, static fn(string $warning): bool => str_contains($warning, 'easy pace 4:20'))) >= 1
        );
    }

    public function test_collectNormalizedPlanValidationIssues_marksLargeTempoDeviationAsError(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'total_volume' => 26.0,
                'days' => [
                    ['date' => '2026-03-10', 'type' => 'tempo', 'pace' => '4:00'],
                ],
            ]],
        ];

        $trainingState = [
            'readiness' => 'normal',
            'pace_rules' => [
                'easy_min_sec' => 320,
                'easy_max_sec' => 340,
                'long_min_sec' => 335,
                'long_max_sec' => 360,
                'tempo_sec' => 275,
                'tempo_tolerance_sec' => 8,
            ],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, $trainingState);

        $codes = array_column($issues, 'code');
        $this->assertContains('tempo_pace_out_of_range', $codes);
        $this->assertContains('key_workout_missing_structure', $codes);
        $tempoIssue = array_values(array_filter($issues, static fn(array $issue): bool => ($issue['code'] ?? '') === 'tempo_pace_out_of_range'));
        $this->assertNotEmpty($tempoIssue);
        $this->assertSame('error', $tempoIssue[0]['severity']);
        $this->assertTrue(shouldRunCorrectiveRegeneration($issues));
    }

    public function test_collectNormalizedPlanValidationIssues_flagsTempoWithoutConcreteStructure(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 2,
                'total_volume' => 60.0,
                'days' => [
                    ['date' => '2026-03-17', 'type' => 'tempo', 'pace' => '4:42', 'distance_km' => null, 'duration_minutes' => null, 'notes' => null, 'exercises' => []],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
        ]);

        $codes = array_column($issues, 'code');
        $this->assertContains('key_workout_missing_structure', $codes);
        $this->assertTrue(shouldRunCorrectiveRegeneration($issues));
    }

    public function test_collectNormalizedPlanValidationIssues_allowsTempoWithConcreteNotesStructure(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 2,
                'total_volume' => 60.0,
                'days' => [
                    ['date' => '2026-03-17', 'type' => 'tempo', 'pace' => '4:42', 'distance_km' => null, 'duration_minutes' => null, 'notes' => '3x3 км в пороговом темпе, пауза 2 мин трусцой', 'exercises' => []],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
        ]);

        $codes = array_column($issues, 'code');
        $this->assertNotContains('key_workout_missing_structure', $codes);
    }

    public function test_collectNormalizedPlanValidationIssues_allows_goal_specific_marathon_tempo_near_goal_pace(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 2,
                'total_volume' => 72.0,
                'days' => [
                    ['date' => '2026-03-17', 'type' => 'tempo', 'pace' => '5:00', 'distance_km' => null, 'duration_minutes' => null, 'notes' => '2 км разминка, затем 10 км в целевом темпе марафона, 1.5 км заминка', 'exercises' => []],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'goal_pace_sec' => 298,
            'pace_rules' => [
                'tempo_sec' => 282,
                'tempo_tolerance_sec' => 8,
            ],
        ]);

        $codes = array_column($issues, 'code');
        $this->assertNotContains('tempo_pace_out_of_range', $codes);
    }

    public function test_collectNormalizedPlanValidationIssues_allows_goal_specific_marathon_tempo_by_week_contract(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'total_volume' => 72.0,
                'days' => [
                    ['date' => '2026-03-10', 'type' => 'tempo', 'pace' => '5:00', 'distance_km' => null, 'duration_minutes' => null, 'notes' => '2 км разминка, затем 10 км устойчиво, 1.5 км заминка', 'exercises' => []],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'goal_pace_sec' => 298,
            'pace_rules' => [
                'tempo_sec' => 282,
                'tempo_tolerance_sec' => 8,
            ],
            'plan_intent_contract' => [
                'weeks' => [[
                    'week' => 1,
                    'contracts' => [
                        ['type' => 'tempo', 'intent' => 'goal_pace_specific', 'directive' => 'Tempo around marathon goal pace'],
                    ],
                ]],
            ],
        ]);

        $codes = array_column($issues, 'code');
        $this->assertNotContains('tempo_pace_out_of_range', $codes);
    }

    public function test_collectNormalizedPlanValidationIssues_flagsControlWithoutConcreteTask(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 3,
                'total_volume' => 62.0,
                'days' => [
                    ['date' => '2026-03-28', 'type' => 'control', 'distance_km' => 10.0, 'warmup_km' => null, 'cooldown_km' => null, 'notes' => null, 'exercises' => []],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
        ]);

        $codes = array_column($issues, 'code');
        $messages = array_column($issues, 'message');
        $this->assertContains('key_workout_missing_structure', $codes);
        $this->assertTrue(array_reduce($messages, static fn(bool $carry, string $message): bool => $carry || str_contains($message, 'контрольной задачи'), false));
    }

    public function test_collectNormalizedPlanValidationIssues_flagsTempoStimulusTooSmallRelativeToTrainingState(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 2,
                'total_volume' => 60.0,
                'days' => [
                    ['date' => '2026-03-17', 'type' => 'tempo', 'pace' => '4:42', 'distance_km' => 3.0, 'duration_minutes' => 14, 'notes' => null, 'exercises' => []],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
            'load_policy' => [
                'easy_build_min_km' => 10.0,
                'tempo_floor_ratio' => 0.75,
                'tempo_min_km' => 3.0,
                'recovery_weeks' => [],
            ],
        ]);

        $codes = array_column($issues, 'code');
        $this->assertContains('tempo_stimulus_too_small', $codes);
        $this->assertTrue(shouldRunCorrectiveRegeneration($issues));
    }

    public function test_collectNormalizedPlanValidationIssues_flagsRunOutsidePreferredDays(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'total_volume' => 18.0,
                'days' => [
                    ['date' => '2026-03-09', 'type' => 'easy'],
                    ['date' => '2026-03-10', 'type' => 'rest'],
                    ['date' => '2026-03-11', 'type' => 'easy'],
                    ['date' => '2026-03-12', 'type' => 'rest'],
                    ['date' => '2026-03-13', 'type' => 'easy'],
                    ['date' => '2026-03-14', 'type' => 'rest'],
                    ['date' => '2026-03-15', 'type' => 'rest'],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [], [
            'preferred_days' => ['mon', 'wed'],
            'sessions_per_week' => 2,
        ]);

        $this->assertNotEmpty($issues);
        $this->assertSame('run_on_non_preferred_day', $issues[0]['code']);
        $this->assertSame('error', $issues[0]['severity']);
    }

    public function test_collectNormalizedPlanValidationIssues_flags_invalid_week_day_count_without_php_warning(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'days' => [
                    ['type' => 'easy'],
                    ['type' => 'easy'],
                    ['type' => 'easy'],
                    ['type' => 'easy'],
                    ['type' => 'easy'],
                    ['type' => 'easy'],
                    ['type' => 'easy'],
                    ['type' => 'easy'],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [], [
            'preferred_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
            'sessions_per_week' => 6,
        ]);

        $codes = array_column($issues, 'code');
        $this->assertContains('invalid_week_day_count', $codes);
    }

    public function test_collectNormalizedPlanValidationIssues_flagsTooMuchIntensityForHealthGoal(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 1,
                'total_volume' => 24.0,
                'days' => [
                    ['date' => '2026-03-09', 'type' => 'tempo'],
                    ['date' => '2026-03-10', 'type' => 'rest'],
                    ['date' => '2026-03-11', 'type' => 'interval'],
                    ['date' => '2026-03-12', 'type' => 'rest'],
                    ['date' => '2026-03-13', 'type' => 'easy'],
                    ['date' => '2026-03-14', 'type' => 'long'],
                    ['date' => '2026-03-15', 'type' => 'rest'],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'health',
            'experience_level' => 'novice',
        ]);

        $this->assertNotEmpty($issues);
        $this->assertSame('health_too_many_quality_sessions', $issues[0]['code']);
        $this->assertSame('error', $issues[0]['severity']);
    }

    public function test_collectNormalizedPlanValidationIssues_flagsWeakMarathonTaper(): void {
        $normalized = [
            'weeks' => [
                ['week_number' => 1, 'total_volume' => 50.0, 'days' => []],
                ['week_number' => 2, 'total_volume' => 50.0, 'days' => []],
                ['week_number' => 3, 'total_volume' => 46.0, 'days' => [
                    ['date' => '2026-04-26', 'type' => 'race'],
                ]],
            ],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
        ]);

        $codes = array_column($issues, 'code');
        $this->assertContains('taper_not_reduced', $codes);
        $this->assertContains('taper_race_week_too_big', $codes);
    }

    public function test_collectNormalizedPlanValidationIssues_doesNotWarnWhenShortRaceWeekMatchesSupplementaryCap(): void {
        $normalized = [
            'weeks' => [
                ['week_number' => 1, 'total_volume' => 48.5, 'days' => []],
                ['week_number' => 2, 'total_volume' => 39.6, 'days' => [
                    ['date' => '2026-04-26', 'type' => 'race', 'distance_km' => 10.0],
                    ['date' => '2026-04-21', 'type' => 'easy', 'distance_km' => 8.0],
                    ['date' => '2026-04-23', 'type' => 'easy', 'distance_km' => 8.0],
                    ['date' => '2026-04-24', 'type' => 'easy', 'distance_km' => 8.0],
                    ['date' => '2026-04-25', 'type' => 'easy', 'distance_km' => 5.6],
                ]],
            ],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'load_policy' => [
                'race_week_supplementary_ratio' => 0.60,
            ],
        ]);

        $codes = array_column($issues, 'code');
        $this->assertNotContains('taper_race_week_too_big', $codes);
    }

    public function test_collectNormalizedPlanValidationIssues_allows_race_day_only_for_return_after_injury_race_week(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 8,
                'total_volume' => 18.0,
                'days' => [
                    ['date' => '2026-04-27', 'type' => 'easy'],
                    ['date' => '2026-04-29', 'type' => 'easy'],
                    ['date' => '2026-05-03', 'type' => 'race'],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
            'special_population_flags' => ['return_after_injury'],
        ]);

        $codes = array_column($issues, 'code');
        $this->assertNotContains('special_population_quality_not_allowed', $codes);
    }

    public function test_collectNormalizedPlanValidationIssues_still_flags_extra_quality_for_return_after_injury(): void {
        $normalized = [
            'weeks' => [[
                'week_number' => 7,
                'total_volume' => 26.0,
                'days' => [
                    ['date' => '2026-04-20', 'type' => 'tempo'],
                    ['date' => '2026-04-23', 'type' => 'easy'],
                    ['date' => '2026-04-27', 'type' => 'race'],
                ],
            ]],
        ];

        $issues = collectNormalizedPlanValidationIssues($normalized, [
            'goal_type' => 'race',
            'special_population_flags' => ['return_after_injury'],
        ]);

        $codes = array_column($issues, 'code');
        $this->assertContains('special_population_quality_not_allowed', $codes);
    }
}
