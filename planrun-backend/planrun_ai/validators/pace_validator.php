<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

function collectPaceValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $paceRules = $trainingState['pace_rules'] ?? null;
    if (!$paceRules) {
        return $issues;
    }

    foreach (($normalizedPlan['weeks'] ?? []) as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        foreach (($week['days'] ?? []) as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $date = $day['date'] ?? 'unknown-date';
            $paceSec = parsePaceToSeconds($day['pace'] ?? null);
            if ($paceSec === null) {
                continue;
            }

            if ($type === 'easy') {
                $min = max(150, (int) $paceRules['easy_min_sec'] - 15);
                $max = min(600, (int) $paceRules['easy_max_sec'] + 20);
                if ($paceSec < $min || $paceSec > $max) {
                    $delta = $paceSec < $min ? ($min - $paceSec) : ($paceSec - $max);
                    $issues[] = [
                        'severity' => $delta > 25 ? 'error' : 'warning',
                        'code' => 'easy_pace_out_of_range',
                        'week_number' => $weekNumber,
                        'date' => $date,
                        'message' => "Неделя {$weekNumber}, {$date}: easy pace {$day['pace']} вне рекомендованного коридора " . validatorFormatPaceSec($min) . "–" . validatorFormatPaceSec($max) . ".",
                    ];
                }
            } elseif ($type === 'long') {
                $min = max(150, (int) $paceRules['long_min_sec'] - 15);
                $max = min(600, (int) $paceRules['long_max_sec'] + 20);
                if ($paceSec < $min || $paceSec > $max) {
                    $delta = $paceSec < $min ? ($min - $paceSec) : ($paceSec - $max);
                    $issues[] = [
                        'severity' => $delta > 25 ? 'error' : 'warning',
                        'code' => 'long_pace_out_of_range',
                        'week_number' => $weekNumber,
                        'date' => $date,
                        'message' => "Неделя {$weekNumber}, {$date}: long pace {$day['pace']} вне рекомендованного коридора " . validatorFormatPaceSec($min) . "–" . validatorFormatPaceSec($max) . ".",
                    ];
                }
            } elseif ($type === 'tempo') {
                $goalSpecificTarget = resolveGoalSpecificTempoPaceTargetSec($day, $trainingState, $weekNumber);
                $target = $goalSpecificTarget ?: (int) ($paceRules['tempo_sec'] ?? 0);
                $tolerance = (int) ($paceRules['tempo_tolerance_sec'] ?? 10) + 5;
                if ($goalSpecificTarget) {
                    $tolerance = max($tolerance, 20);
                }
                if ($target > 0 && abs($paceSec - $target) > $tolerance) {
                    $delta = abs($paceSec - $target);
                    $issues[] = [
                        'severity' => $delta > ($tolerance + 15) ? 'error' : 'warning',
                        'code' => 'tempo_pace_out_of_range',
                        'week_number' => $weekNumber,
                        'date' => $date,
                        'message' => "Неделя {$weekNumber}, {$date}: tempo pace {$day['pace']} слишком далеко от целевого " . validatorFormatPaceSec($target) . ".",
                    ];
                }
            }
        }
    }

    return $issues;
}
