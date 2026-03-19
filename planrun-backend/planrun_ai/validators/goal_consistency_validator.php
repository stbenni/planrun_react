<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

function collectGoalConsistencyValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $goalType = (string) ($trainingState['goal_type'] ?? ($context['goal_type'] ?? 'health'));
    $experienceLevel = (string) ($trainingState['experience_level'] ?? '');
    $specialFlags = is_array($trainingState['special_population_flags'] ?? null)
        ? $trainingState['special_population_flags']
        : [];
    $qualityTypes = ['tempo', 'interval', 'fartlek', 'control', 'race'];
    $severeFlags = array_values(array_intersect($specialFlags, ['pregnant_or_postpartum', 'chronic_condition_flag', 'return_after_injury']));
    $conservativeFlags = array_values(array_intersect($specialFlags, ['older_adult_65_plus', 'return_after_break', 'low_confidence_vdot']));

    foreach (($normalizedPlan['weeks'] ?? []) as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $qualityCount = 0;
        $nonRaceQualityCount = 0;
        $raceDayCount = 0;
        $controlCount = 0;
        $hasRaceLike = false;
        foreach (($week['days'] ?? []) as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            if (in_array($type, $qualityTypes, true)) {
                $qualityCount++;
            }
            if (in_array($type, ['tempo', 'interval', 'fartlek'], true)) {
                $nonRaceQualityCount++;
            }
            if ($type === 'control') {
                $controlCount++;
                $nonRaceQualityCount++;
            }
            if ($type === 'race') {
                $raceDayCount++;
            }
            if (in_array($type, ['race', 'control'], true)) {
                $hasRaceLike = true;
            }
        }

        if ($goalType === 'health') {
            if ($qualityCount > 1) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'health_too_many_quality_sessions',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: для health-цели запланировано слишком много интенсивных сессий ({$qualityCount}).",
                ];
            } elseif ($qualityCount > 0 && in_array($experienceLevel, ['novice', 'beginner'], true)) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'health_novice_quality_session',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: у novice/beginner health-плана есть quality session, проверь переносимость нагрузки.",
                ];
            }

            if ($hasRaceLike) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'health_contains_race_like_day',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: health-план содержит race/control день, что требует дополнительной проверки.",
                ];
            }
        } elseif ($goalType === 'weight_loss') {
            if ($qualityCount > 1) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'weight_loss_too_many_quality_sessions',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: для weight_loss-цели запланировано {$qualityCount} интенсивных сессий, это может ухудшить adherence.",
                ];
            }
        }

        $onlyRaceDayQuality = $goalType === 'race' && $raceDayCount === 1 && $nonRaceQualityCount === 0;

        if (!empty($severeFlags) && $nonRaceQualityCount > 0) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'special_population_quality_not_allowed',
                'week_number' => $weekNumber,
                'date' => null,
                'message' => "Неделя {$weekNumber}: для special population (" . implode(', ', $severeFlags) . ") есть интенсивные сессии ({$nonRaceQualityCount}).",
            ];
        } elseif (!empty($severeFlags) && $qualityCount > 0 && !$onlyRaceDayQuality) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'special_population_race_week_check',
                'week_number' => $weekNumber,
                'date' => null,
                'message' => "Неделя {$weekNumber}: для special population (" . implode(', ', $severeFlags) . ") есть race/control день, проверь оправданность старта.",
            ];
        } elseif (!empty($conservativeFlags) && $qualityCount > 1) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'special_population_too_much_intensity',
                'week_number' => $weekNumber,
                'date' => null,
                'message' => "Неделя {$weekNumber}: для консервативного special population (" . implode(', ', $conservativeFlags) . ") интенсивность выглядит высокой ({$qualityCount} quality sessions).",
            ];
        }
    }

    return $issues;
}
