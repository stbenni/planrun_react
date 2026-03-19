<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

function collectScheduleValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $preferredDays = normalizePreferredDayKeys($context['preferred_days'] ?? ($trainingState['preferred_days'] ?? []));
    $sessionsPerWeek = (int) ($context['sessions_per_week'] ?? ($trainingState['sessions_per_week'] ?? 0));
    $expectedSkeleton = $context['expected_skeleton'] ?? null;

    foreach (($normalizedPlan['weeks'] ?? []) as $weekIndex => $week) {
        $weekNumber = (int) ($week['week_number'] ?? ($weekIndex + 1));
        $days = $week['days'] ?? [];

        if (is_array($days) && !empty($days) && count($days) !== 7) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'invalid_week_day_count',
                'week_number' => $weekNumber,
                'date' => null,
                'message' => "Неделя {$weekNumber}: ожидается ровно 7 дней, получено " . count($days) . ".",
            ];
        }

        if (is_array($expectedSkeleton['weeks'][$weekIndex]['days'] ?? null)) {
            $skeletonDays = $expectedSkeleton['weeks'][$weekIndex]['days'];
            foreach ($days as $dayIndex => $day) {
                if (!array_key_exists($dayIndex, PLAN_DAY_KEYS)) {
                    continue;
                }
                $actualType = normalizeTrainingType($day['type'] ?? null);
                $expectedType = normalizeSkeletonDayType($skeletonDays[$dayIndex] ?? null);
                if ($actualType === $expectedType) {
                    continue;
                }

                $issues[] = [
                    'severity' => 'error',
                    'code' => 'schedule_skeleton_mismatch',
                    'week_number' => $weekNumber,
                    'date' => $day['date'] ?? null,
                    'message' => "Неделя {$weekNumber}: {$actualType} стоит в " . PLAN_DAY_KEYS[$dayIndex] . ", но skeleton ожидает {$expectedType}.",
                ];
            }
        }

        if (empty($preferredDays)) {
            continue;
        }

        $preferredIndexes = array_map(static fn(string $dayKey): int => PLAN_DAY_KEY_TO_INDEX[$dayKey], $preferredDays);
        foreach ($days as $dayIndex => $day) {
            if (!array_key_exists($dayIndex, PLAN_DAY_KEYS)) {
                continue;
            }
            $type = normalizeTrainingType($day['type'] ?? null);
            if ($type !== 'race' && isRunTypeForSchedule($type) && !in_array($dayIndex, $preferredIndexes, true)) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'run_on_non_preferred_day',
                    'week_number' => $weekNumber,
                    'date' => $day['date'] ?? null,
                    'message' => "Неделя {$weekNumber}: беговой день {$type} стоит в " . PLAN_DAY_KEYS[$dayIndex] . ", которого нет в preferred_days.",
                ];
            }
        }

        if ($sessionsPerWeek > 0 && $sessionsPerWeek === count($preferredIndexes)) {
            foreach ($preferredIndexes as $dayIndex) {
                $type = normalizeTrainingType($days[$dayIndex]['type'] ?? null);
                if ($type === 'rest' || $type === 'free') {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'missing_run_on_required_day',
                        'week_number' => $weekNumber,
                        'date' => $days[$dayIndex]['date'] ?? null,
                        'message' => "Неделя {$weekNumber}: в обязательный беговой день " . PLAN_DAY_KEYS[$dayIndex] . " попал {$type}.",
                    ];
                }
            }
        }
    }

    return $issues;
}
