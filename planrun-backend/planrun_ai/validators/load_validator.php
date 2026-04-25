<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

function collectLoadValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $readiness = $trainingState['readiness'] ?? 'normal';
    $loadPolicy = is_array($trainingState['load_policy'] ?? null) ? $trainingState['load_policy'] : [];

    $allowedGrowth = isset($loadPolicy['allowed_growth_ratio'])
        ? (float) $loadPolicy['allowed_growth_ratio']
        : match ($readiness) {
            'low' => 1.08,
            'high' => 1.12,
            default => 1.10,
        };
    $preThresholdVolumeKm = (float) ($loadPolicy['pre_threshold_volume_km'] ?? 0.0);
    $preThresholdAbsoluteGrowthKm = (float) ($loadPolicy['pre_threshold_absolute_growth_km'] ?? 0.0);

    $prevVolume = null;
    $prevWasRecovery = false;
    $lastNormalVolume = null;
    foreach (($normalizedPlan['weeks'] ?? []) as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $weekVolume = (float) ($week['total_volume'] ?? 0);
        $isRecoveryWeek = !empty($week['is_recovery']);
        $hasRace = false;
        foreach (($week['days'] ?? []) as $day) {
            if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                $hasRace = true;
                break;
            }
        }

        $referenceVolume = $prevVolume;
        if ($prevWasRecovery && !$isRecoveryWeek && $lastNormalVolume !== null) {
            $referenceVolume = $lastNormalVolume;
        }

        $volumeCap = $referenceVolume !== null ? (($referenceVolume * $allowedGrowth) + 0.5) : null;
        if (!$hasRace && $referenceVolume !== null) {
            if (
                $referenceVolume >= 8
                && $volumeCap !== null
                && $weekVolume > ($volumeCap + 0.75)
            ) {
                $ratio = $referenceVolume > 0 ? ($weekVolume / $referenceVolume) : 0.0;
                $severity = ($referenceVolume >= 12 && ($weekVolume - $volumeCap) >= 3.0)
                    || $ratio >= ($allowedGrowth + 0.08)
                    ? 'error'
                    : 'warning';
                $issues[] = [
                    'severity' => $severity,
                    'code' => 'weekly_volume_spike',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: объём {$weekVolume} км выглядит слишком агрессивным после {$referenceVolume} км (readiness={$readiness}).",
                ];
            } elseif (
                $preThresholdVolumeKm > 0.0
                && $preThresholdAbsoluteGrowthKm > 0.0
                && $referenceVolume < $preThresholdVolumeKm
                && $weekVolume > ($referenceVolume + $preThresholdAbsoluteGrowthKm)
            ) {
                $issues[] = [
                    'severity' => ($weekVolume - $referenceVolume) >= ($preThresholdAbsoluteGrowthKm + 1.5) ? 'error' : 'warning',
                    'code' => 'weekly_volume_spike',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: объём {$weekVolume} км слишком резко вырос после {$referenceVolume} км для low-base профиля.",
                ];
            }
        }

        if (!$isRecoveryWeek) {
            $lastNormalVolume = $weekVolume;
        }
        $prevWasRecovery = $isRecoveryWeek;
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
