<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

function collectLoadValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $readiness = $trainingState['readiness'] ?? 'normal';

    $allowedGrowth = match ($readiness) {
        'low' => 1.08,
        'high' => 1.12,
        default => 1.10,
    };

    $prevVolume = null;
    foreach (($normalizedPlan['weeks'] ?? []) as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $weekVolume = (float) ($week['total_volume'] ?? 0);
        $hasRace = false;
        foreach (($week['days'] ?? []) as $day) {
            if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                $hasRace = true;
                break;
            }
        }

        $volumeCap = $prevVolume !== null ? (($prevVolume * $allowedGrowth) + 0.5) : null;
        if (
            !$hasRace
            && $prevVolume !== null
            && $prevVolume >= 8
            && $volumeCap !== null
            && $weekVolume > ($volumeCap + 0.75)
        ) {
            $ratio = $prevVolume > 0 ? ($weekVolume / $prevVolume) : 0.0;
            $severity = ($prevVolume >= 12 && ($weekVolume - $volumeCap) >= 3.0)
                || $ratio >= ($allowedGrowth + 0.08)
                ? 'error'
                : 'warning';
            $issues[] = [
                'severity' => $severity,
                'code' => 'weekly_volume_spike',
                'week_number' => $weekNumber,
                'date' => null,
                'message' => "Неделя {$weekNumber}: объём {$weekVolume} км выглядит слишком агрессивным после {$prevVolume} км (readiness={$readiness}).",
            ];
        }
        $prevVolume = $weekVolume;

        $prevKeyType = null;
        $prevKeyDate = null;
        foreach (($week['days'] ?? []) as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $isKey = !empty($day['is_key_workout']) || in_array($type, PLAN_KEY_WORKOUT_TYPES, true);
            if (!$isKey) {
                $prevKeyType = null;
                $prevKeyDate = null;
                continue;
            }

            if ($prevKeyType !== null) {
                $issues[] = [
                    'severity' => in_array($type, ['race', 'long'], true) || in_array($prevKeyType, ['race', 'long'], true) ? 'error' : 'warning',
                    'code' => 'back_to_back_key_workouts',
                    'week_number' => $weekNumber,
                    'date' => $day['date'] ?? null,
                    'message' => "Неделя {$weekNumber}: подряд стоят ключевые тренировки {$prevKeyType} ({$prevKeyDate}) и {$type} (" . ($day['date'] ?? 'unknown-date') . ").",
                ];
            }

            $prevKeyType = $type;
            $prevKeyDate = $day['date'] ?? 'unknown-date';
        }
    }

    return $issues;
}
