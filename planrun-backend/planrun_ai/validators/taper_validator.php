<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

function collectTaperValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $weeks = array_values($normalizedPlan['weeks'] ?? []);
    if (count($weeks) < 2) {
        return $issues;
    }

    $raceWeekIndex = null;
    foreach ($weeks as $index => $week) {
        foreach (($week['days'] ?? []) as $day) {
            if (($day['type'] ?? null) === 'race') {
                $raceWeekIndex = $index;
                break 2;
            }
        }
    }

    if ($raceWeekIndex === null || $raceWeekIndex < 1) {
        return $issues;
    }

    $raceDistance = (string) ($trainingState['race_distance'] ?? '');
    $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
    $raceWeek = $weeks[$raceWeekIndex];
    $weekBefore = $weeks[$raceWeekIndex - 1];

    $raceWeekVolume = (float) ($raceWeek['total_volume'] ?? 0);
    $weekBeforeVolume = (float) ($weekBefore['total_volume'] ?? 0);
    $raceWeekNumber = (int) ($raceWeek['week_number'] ?? ($raceWeekIndex + 1));
    $raceDistanceKm = 0.0;
    foreach (($raceWeek['days'] ?? []) as $day) {
        if (normalizeTrainingType($day['type'] ?? null) === 'race') {
            $raceDistanceKm += (float) ($day['distance_km'] ?? 0.0);
        }
    }
    $supplementaryVolume = round(max(0.0, $raceWeekVolume - $raceDistanceKm), 1);
    $supplementaryRatio = isset($trainingState['load_policy']['race_week_supplementary_ratio'])
        ? (float) $trainingState['load_policy']['race_week_supplementary_ratio']
        : match ($raceDistance) {
            'marathon', '42.2k' => 0.35,
            'half', '21.1k' => 0.45,
            default => 0.60,
        };
    $supplementaryCap = round(max(6.0, ($weekBeforeVolume * $supplementaryRatio) + 0.5), 1);

    if ($weekBeforeVolume > 0 && $supplementaryVolume > ($supplementaryCap + 0.2)) {
        $issues[] = [
            'severity' => $isLongRace ? 'error' : 'warning',
            'code' => 'taper_race_week_too_big',
            'week_number' => $raceWeekNumber,
            'date' => null,
            'message' => "Неделя {$raceWeekNumber}: предгоночный объём без учёта самой гонки ({$supplementaryVolume} км) выглядит слишком большим относительно предгоночной недели {$weekBeforeVolume} км.",
        ];
    }

    if ($isLongRace && $raceWeekIndex >= 2) {
        $preTaperWeek = $weeks[$raceWeekIndex - 2];
        $preTaperVolume = (float) ($preTaperWeek['total_volume'] ?? 0);
        $weekBeforeNumber = (int) ($weekBefore['week_number'] ?? $raceWeekIndex);
        if ($preTaperVolume >= 10 && $weekBeforeVolume > ($preTaperVolume * 0.98) + 0.5) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'taper_not_reduced',
                'week_number' => $weekBeforeNumber,
                'date' => null,
                'message' => "Неделя {$weekBeforeNumber}: для длинной гонки taper почти не уменьшил объём ({$weekBeforeVolume} км после {$preTaperVolume} км).",
            ];
        }
    }

    return $issues;
}
