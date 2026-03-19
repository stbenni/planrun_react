<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

function hasMeaningfulTempoStructure(array $day): bool {
    $durationMinutes = isset($day['duration_minutes']) ? (int) $day['duration_minutes'] : 0;
    if ($durationMinutes > 0) {
        return true;
    }

    $notes = trim((string) ($day['notes'] ?? ''));
    if ($notes !== '') {
        return true;
    }

    $distanceKm = isset($day['distance_km']) ? (float) $day['distance_km'] : 0.0;
    $warmupKm = isset($day['warmup_km']) ? (float) $day['warmup_km'] : 0.0;
    $cooldownKm = isset($day['cooldown_km']) ? (float) $day['cooldown_km'] : 0.0;
    if ($distanceKm > 0.0 && ($warmupKm > 0.0 || $cooldownKm > 0.0)) {
        return true;
    }

    $exercises = $day['exercises'] ?? null;
    return is_array($exercises) && !empty($exercises);
}

function hasMeaningfulControlStructure(array $day): bool {
    $notes = trim((string) ($day['notes'] ?? ''));
    if ($notes !== '') {
        return true;
    }

    $exercises = $day['exercises'] ?? null;
    if (is_array($exercises) && !empty($exercises)) {
        return true;
    }

    $distanceKm = isset($day['distance_km']) ? (float) $day['distance_km'] : 0.0;
    $warmupKm = isset($day['warmup_km']) ? (float) $day['warmup_km'] : 0.0;
    $cooldownKm = isset($day['cooldown_km']) ? (float) $day['cooldown_km'] : 0.0;
    return $distanceKm > 0.0 && ($warmupKm > 0.0 || $cooldownKm > 0.0);
}

function hasMeaningfulComplexWorkoutStructure(array $day): bool {
    $reps = isset($day['reps']) ? (int) $day['reps'] : 0;
    $intervalM = isset($day['interval_m']) ? (int) $day['interval_m'] : 0;
    if ($reps > 0 && $intervalM > 0) {
        return true;
    }

    $segments = $day['segments'] ?? null;
    if (is_array($segments) && !empty($segments)) {
        return true;
    }

    $durationMinutes = isset($day['duration_minutes']) ? (int) $day['duration_minutes'] : 0;
    if ($durationMinutes > 0) {
        return true;
    }

    $notes = trim((string) ($day['notes'] ?? ''));
    return $notes !== '';
}

function resolvePersonalizedTempoStimulusFloorKm(array $trainingState, int $weekNumber, ?int $raceWeekNumber): ?float {
    $loadPolicy = is_array($trainingState['load_policy'] ?? null) ? $trainingState['load_policy'] : [];
    $recoveryWeeks = !empty($loadPolicy['recovery_weeks']) && is_array($loadPolicy['recovery_weeks'])
        ? array_map('intval', $loadPolicy['recovery_weeks'])
        : [];

    if (in_array($weekNumber, $recoveryWeeks, true)) {
        return null;
    }

    if ($raceWeekNumber !== null && $weekNumber >= max(1, $raceWeekNumber - 1)) {
        return null;
    }

    $easyBuildMinKm = isset($loadPolicy['easy_build_min_km']) ? (float) $loadPolicy['easy_build_min_km'] : 0.0;
    $tempoFloorRatio = isset($loadPolicy['tempo_floor_ratio']) ? (float) $loadPolicy['tempo_floor_ratio'] : 0.0;
    $tempoMinKm = isset($loadPolicy['tempo_min_km']) ? (float) $loadPolicy['tempo_min_km'] : 0.0;

    $candidates = [];
    if ($tempoMinKm > 0.0) {
        $candidates[] = $tempoMinKm;
    }
    if ($easyBuildMinKm > 0.0 && $tempoFloorRatio > 0.0) {
        $candidates[] = round($easyBuildMinKm * $tempoFloorRatio, 1);
    }

    if (empty($candidates)) {
        return null;
    }

    return max($candidates);
}

function collectWorkoutCompletenessValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $goalType = (string) ($trainingState['goal_type'] ?? ($context['goal_type'] ?? 'health'));
    $raceWeekNumber = findNormalizedPlanRaceWeekNumber($normalizedPlan);

    foreach (($normalizedPlan['weeks'] ?? []) as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);

        foreach (($week['days'] ?? []) as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $date = $day['date'] ?? null;

            if ($type === 'tempo' && !hasMeaningfulTempoStructure($day)) {
                $issues[] = [
                    'severity' => in_array($goalType, ['race', 'time_improvement'], true) ? 'error' : 'warning',
                    'code' => 'key_workout_missing_structure',
                    'week_number' => $weekNumber,
                    'date' => $date,
                    'message' => "Неделя {$weekNumber}" . ($date ? " ({$date})" : '') . ": {$type} day не содержит конкретной структуры тренировки.",
                ];
            }

            if ($type === 'control' && !hasMeaningfulControlStructure($day)) {
                $issues[] = [
                    'severity' => in_array($goalType, ['race', 'time_improvement'], true) ? 'error' : 'warning',
                    'code' => 'key_workout_missing_structure',
                    'week_number' => $weekNumber,
                    'date' => $date,
                    'message' => "Неделя {$weekNumber}" . ($date ? " ({$date})" : '') . ": {$type} day не содержит конкретной контрольной задачи.",
                ];
            }

            if ($type === 'tempo' && in_array($goalType, ['race', 'time_improvement'], true)) {
                $notes = trim((string) ($day['notes'] ?? ''));
                $exercises = $day['exercises'] ?? null;
                $distanceKm = isset($day['distance_km']) ? (float) $day['distance_km'] : 0.0;
                $tempoFloorKm = resolvePersonalizedTempoStimulusFloorKm($trainingState, $weekNumber, $raceWeekNumber);

                if ($tempoFloorKm !== null && $distanceKm > 0.0 && $distanceKm < $tempoFloorKm && $notes === '' && (!is_array($exercises) || empty($exercises))) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'tempo_stimulus_too_small',
                        'week_number' => $weekNumber,
                        'date' => $date,
                        'message' => "Неделя {$weekNumber}" . ($date ? " ({$date})" : '') . ": tempo day слишком короткий для текущего training state ({$distanceKm} км < {$tempoFloorKm} км).",
                    ];
                }
            }

            if (in_array($type, ['interval', 'fartlek'], true) && !hasMeaningfulComplexWorkoutStructure($day)) {
                $issues[] = [
                    'severity' => in_array($goalType, ['race', 'time_improvement'], true) ? 'error' : 'warning',
                    'code' => 'complex_workout_missing_structure',
                    'week_number' => $weekNumber,
                    'date' => $date,
                    'message' => "Неделя {$weekNumber}" . ($date ? " ({$date})" : '') . ": {$type} day не содержит конкретной структуры отрезков/сегментов.",
                ];
            }
        }
    }

    return $issues;
}
