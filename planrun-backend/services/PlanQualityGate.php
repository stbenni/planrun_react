<?php

require_once __DIR__ . '/../planrun_ai/plan_normalizer.php';
require_once __DIR__ . '/../planrun_ai/plan_validator.php';

/**
 * Финальный quality gate над тем же normalizer/validator contract,
 * который реально используется перед сохранением.
 */
class PlanQualityGate
{
    public function evaluate(array $plan, string $startDate, array $trainingState = [], array $context = []): array
    {
        $weekNumberOffset = (int) ($context['week_number_offset'] ?? 0);
        $userPreferences = is_array($context['user_preferences'] ?? null) ? $context['user_preferences'] : null;
        $expectedSkeleton = is_array($context['expected_skeleton'] ?? null) ? $context['expected_skeleton'] : null;

        $normalizedPlan = normalizeTrainingPlan($plan, $startDate, $weekNumberOffset, $userPreferences, $expectedSkeleton);
        $baseline = $this->buildEvaluation($normalizedPlan, $trainingState, $context);

        $repairedPlan = $this->applyDeterministicRepairs($normalizedPlan, $trainingState);
        $repairsAttempted = $this->planHash($repairedPlan) !== $this->planHash($normalizedPlan);
        $selected = $baseline;

        if ($repairsAttempted) {
            $candidate = $this->buildEvaluation($repairedPlan, $trainingState, $context);
            if ($this->isCandidateBetter($baseline, $candidate)) {
                $selected = $candidate;
            }
        }

        return [
            'status' => $selected['status'],
            'normalized_plan' => $selected['plan'],
            'normalizer_warnings' => $selected['plan']['warnings'] ?? [],
            'issues' => $selected['issues'],
            'score' => $selected['score'],
            'has_errors' => $selected['has_errors'],
            'should_block_save' => $selected['has_errors'],
            'should_run_corrective_regeneration' => shouldRunCorrectiveRegeneration($selected['issues']),
            'repairs_applied' => $repairsAttempted && $selected['plan'] === $repairedPlan,
        ];
    }

    private function buildEvaluation(array $normalizedPlan, array $trainingState, array $context): array
    {
        $issues = collectNormalizedPlanValidationIssues($normalizedPlan, $trainingState, $context);
        $issues = array_merge($issues, $this->collectScenarioIssues($normalizedPlan, $trainingState, $context));
        $issues = array_merge($issues, $this->collectGoalFeasibilityIssues($trainingState));
        $issues = $this->downgradeProtectiveScenarioIssues($issues, $trainingState, $context);
        $issues = $this->filterIssuesForScenario($issues, $normalizedPlan, $trainingState, $context);
        $issues = $this->sortIssues($issues);

        $score = scoreValidationIssues($issues);
        $hasErrors = $this->hasErrors($issues);

        return [
            'plan' => $normalizedPlan,
            'issues' => $issues,
            'score' => $score,
            'has_errors' => $hasErrors,
            'status' => $hasErrors ? 'blocked' : (!empty($issues) ? 'warning' : 'ok'),
        ];
    }

    private function applyDeterministicRepairs(array $normalizedPlan, array $trainingState): array
    {
        $repaired = applyTrainingStatePaceRepairs($normalizedPlan, $trainingState);
        $repaired = applyTrainingStateWorkoutDetailFallbacks($repaired, $trainingState);
        $repaired = applyTrainingStateLoadRepairs($repaired, $trainingState);
        $repaired = applyTrainingStateMinimumDistanceRepairs($repaired, $trainingState);
        $repaired = applyTrainingStateLoadRepairs($repaired, $trainingState);

        return $repaired;
    }

    private function isCandidateBetter(array $baseline, array $candidate): bool
    {
        $baselineRank = [
            $baseline['has_errors'] ? 1 : 0,
            (int) $baseline['score'],
            count($baseline['issues'] ?? []),
        ];
        $candidateRank = [
            $candidate['has_errors'] ? 1 : 0,
            (int) $candidate['score'],
            count($candidate['issues'] ?? []),
        ];

        return $candidateRank < $baselineRank;
    }

    private function planHash(array $plan): string
    {
        return md5((string) json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function collectScenarioIssues(array $normalizedPlan, array $trainingState, array $context): array
    {
        $scenario = $trainingState['planning_scenario'] ?? ($context['planning_scenario'] ?? null);
        if (!is_array($scenario)) {
            return [];
        }

        $flags = array_map('strval', (array) ($scenario['flags'] ?? []));
        $tuneUpEvent = $scenario['tune_up_event'] ?? null;
        if (!is_array($tuneUpEvent) || (int) ($tuneUpEvent['week'] ?? 0) < 1) {
            return [];
        }

        $weekNumber = (int) $tuneUpEvent['week'];
        $tuneUpDate = (string) ($tuneUpEvent['date'] ?? '');
        $week = null;
        foreach (($normalizedPlan['weeks'] ?? []) as $candidateWeek) {
            if ((int) ($candidateWeek['week_number'] ?? 0) === $weekNumber) {
                $week = $candidateWeek;
                break;
            }
        }

        if ($week === null) {
            return [[
                'severity' => 'warning',
                'code' => 'tune_up_event_outside_plan_horizon',
                'week_number' => $weekNumber,
                'date' => $tuneUpDate !== '' ? $tuneUpDate : null,
                'message' => "Неделя {$weekNumber}: explicit tune-up event не попал в фактический горизонт плана.",
            ]];
        }

        $issues = [];
        $raceLikeCount = 0;
        $extraQualityCount = 0;
        $hasLong = false;
        $tuneUpType = null;

        foreach (($week['days'] ?? []) as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $date = (string) ($day['date'] ?? '');
            $isTuneUpDay = $tuneUpDate !== '' && $date === $tuneUpDate;

            if (in_array($type, ['control', 'race'], true)) {
                $raceLikeCount++;
                if ($isTuneUpDay) {
                    $tuneUpType = $type;
                }
            }

            if ($type === 'long') {
                $hasLong = true;
            }

            if (!$isTuneUpDay && in_array($type, ['tempo', 'interval', 'fartlek', 'control', 'race'], true)) {
                $extraQualityCount++;
            }
        }

        if ($tuneUpType === null) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'tune_up_event_missing_from_plan',
                'week_number' => $weekNumber,
                'date' => $tuneUpDate !== '' ? $tuneUpDate : null,
                'message' => "Неделя {$weekNumber}" . ($tuneUpDate !== '' ? " ({$tuneUpDate})" : '') . ": explicit tune-up event не был отражён в плане.",
            ];
        }

        if (in_array('b_race_before_a_race', $flags, true)) {
            if ($tuneUpType !== null && $tuneUpType !== 'control') {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'tune_up_event_not_downgraded_to_control',
                    'week_number' => $weekNumber,
                    'date' => $tuneUpDate !== '' ? $tuneUpDate : null,
                    'message' => "Неделя {$weekNumber}" . ($tuneUpDate !== '' ? " ({$tuneUpDate})" : '') . ": tune-up event перед главным стартом должен идти как control, а не {$tuneUpType}.",
                ];
            }

            if ($hasLong) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'tune_up_week_contains_long_run',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: в tune-up week перед A-race осталась длительная, это конфликтует с подводкой.",
                ];
            }

            if ($extraQualityCount > 0) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'tune_up_week_has_extra_quality',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: вокруг tune-up event осталось {$extraQualityCount} дополнительных quality-дней, что перегружает подводку.",
                ];
            }
        }

        if ($raceLikeCount > 1) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'tune_up_week_multiple_race_like_days',
                'week_number' => $weekNumber,
                'date' => null,
                'message' => "Неделя {$weekNumber}: в одной неделе оказалось несколько race/control дней ({$raceLikeCount}).",
            ];
        }

        return $issues;
    }

    private function collectGoalFeasibilityIssues(array $trainingState): array
    {
        $assessment = $trainingState['goal_realism'] ?? null;
        if (!is_array($assessment)) {
            return [];
        }

        $verdict = (string) ($assessment['verdict'] ?? 'realistic');
        if (in_array($verdict, ['', 'realistic'], true)) {
            return [];
        }

        $code = match ($verdict) {
            'unrealistic' => 'goal_feasibility_unrealistic',
            'challenging', 'caution' => 'goal_feasibility_challenging',
            default => 'goal_feasibility_warning',
        };

        $issues = [];
        foreach ((array) ($assessment['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $type = (string) ($message['type'] ?? 'info');
            if (!in_array($type, ['warning', 'error'], true)) {
                continue;
            }

            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $issues[] = [
                'severity' => 'warning',
                'code' => $code,
                'week_number' => null,
                'date' => null,
                'message' => $text,
            ];

            if (count($issues) >= 3) {
                break;
            }
        }

        if ($issues === []) {
            $issues[] = [
                'severity' => 'warning',
                'code' => $code,
                'week_number' => null,
                'date' => null,
                'message' => 'Цель выглядит слишком амбициозной для текущего горизонта подготовки. План сохранён как осторожный стартовый вариант, но цель нужно пересмотреть по первым тренировкам.',
            ];
        }

        return $issues;
    }

    private function downgradeProtectiveScenarioIssues(array $issues, array $trainingState, array $context): array
    {
        $scenario = $trainingState['planning_scenario'] ?? ($context['planning_scenario'] ?? null);
        $flags = is_array($scenario)
            ? array_map('strval', (array) ($scenario['flags'] ?? []))
            : [];
        $isProtectiveScenario = (string) ($trainingState['readiness'] ?? 'normal') === 'low'
            || !empty(array_intersect($flags, [
                'high_caution',
                'low_confidence_start',
                'return_after_break',
                'return_after_injury',
                'overload_recovery',
                'pain_protective',
                'illness_protective',
            ]));

        if (!$isProtectiveScenario) {
            return $issues;
        }

        foreach ($issues as &$issue) {
            if (($issue['code'] ?? '') === 'weekly_volume_spike') {
                $issue['severity'] = 'warning';
            }
        }
        unset($issue);

        return $issues;
    }

    private function filterIssuesForScenario(array $issues, array $normalizedPlan, array $trainingState, array $context): array
    {
        $scenario = $trainingState['planning_scenario'] ?? ($context['planning_scenario'] ?? null);
        $flags = is_array($scenario)
            ? array_map('strval', (array) ($scenario['flags'] ?? []))
            : [];
        $loadPolicy = is_array($trainingState['load_policy'] ?? null) ? $trainingState['load_policy'] : [];
        $relaxedRequiredDayContract = !empty(array_intersect($flags, [
            'short_runway_taper',
            'short_runway_long_race',
            'b_race_before_a_race',
            'high_caution',
            'return_after_break',
            'return_after_injury',
            'overload_recovery',
            'pain_protective',
            'illness_protective',
        ]));

        return array_values(array_filter(
            $issues,
            function (array $issue) use ($normalizedPlan, $relaxedRequiredDayContract, $loadPolicy): bool {
                if ((string) ($issue['code'] ?? '') !== 'missing_run_on_required_day') {
                    return true;
                }

                if ($relaxedRequiredDayContract) {
                    return false;
                }

                $weekNumber = (int) ($issue['week_number'] ?? 0);
                if ($weekNumber < 1) {
                    return true;
                }

                $week = $this->findWeek($normalizedPlan, $weekNumber);
                $prevWeek = $weekNumber > 1 ? $this->findWeek($normalizedPlan, $weekNumber - 1) : null;
                $containsRace = $this->weekContainsType($week, 'race');
                $prevContainsRace = $this->weekContainsType($prevWeek, 'race');
                $forceInitialRecoveryWeek = !empty($loadPolicy['force_initial_recovery_week']) && $weekNumber === 1;
                $raceWeekCapEnabled = $containsRace;
                $postRaceCapEnabled = $prevContainsRace && (int) ($loadPolicy['post_goal_race_run_day_cap'] ?? 0) > 0;

                return !($forceInitialRecoveryWeek || $raceWeekCapEnabled || $postRaceCapEnabled);
            }
        ));
    }

    private function findWeek(array $plan, int $weekNumber): ?array
    {
        foreach ((array) ($plan['weeks'] ?? []) as $week) {
            if ((int) ($week['week_number'] ?? 0) === $weekNumber) {
                return $week;
            }
        }

        return null;
    }

    private function weekContainsType(?array $week, string $type): bool
    {
        if (!is_array($week)) {
            return false;
        }

        foreach ((array) ($week['days'] ?? []) as $day) {
            if (normalizeTrainingType($day['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    private function sortIssues(array $issues): array
    {
        usort(
            $issues,
            static function (array $left, array $right): int {
                $severityRank = ['error' => 0, 'warning' => 1];
                $leftRank = $severityRank[$left['severity'] ?? 'warning'] ?? 1;
                $rightRank = $severityRank[$right['severity'] ?? 'warning'] ?? 1;

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                $leftWeek = (int) ($left['week_number'] ?? 0);
                $rightWeek = (int) ($right['week_number'] ?? 0);
                if ($leftWeek !== $rightWeek) {
                    return $leftWeek <=> $rightWeek;
                }

                return strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
            }
        );

        return $issues;
    }

    private function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? 'warning') === 'error') {
                return true;
            }
        }

        return false;
    }
}
