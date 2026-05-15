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
        $disableRepairs = !empty($context['disable_repairs']);

        $normalizedPlan = normalizeTrainingPlan($plan, $startDate, $weekNumberOffset, $userPreferences, $expectedSkeleton);
        $baseline = $this->buildEvaluation($normalizedPlan, $trainingState, $context);

        $repairedPlan = $disableRepairs ? $normalizedPlan : $this->applyDeterministicRepairs($normalizedPlan, $trainingState);
        $repairsAttempted = !$disableRepairs && $this->planHash($repairedPlan) !== $this->planHash($normalizedPlan);
        $selected = $baseline;

        if ($repairsAttempted) {
            $candidate = $this->buildEvaluation($repairedPlan, $trainingState, $context);
            if ($this->isCandidateBetter($baseline, $candidate)) {
                $selected = $candidate;
            }
        }

        $blockingPolicy = $this->resolveBlockingPolicy($context);
        $issues = $this->applyBlockingPolicy((array) ($selected['issues'] ?? []), $blockingPolicy);
        $score = scoreValidationIssues($issues);
        $hasErrors = $this->hasErrors($issues);

        return [
            'status' => $hasErrors ? 'blocked' : ($issues !== [] ? 'warning' : 'ok'),
            'normalized_plan' => $selected['plan'],
            'normalizer_warnings' => $selected['plan']['warnings'] ?? [],
            'issues' => $issues,
            'score' => $score,
            'has_errors' => $hasErrors,
            'should_block_save' => $hasErrors,
            'should_run_corrective_regeneration' => shouldRunCorrectiveRegeneration($issues),
            'repairs_applied' => $repairsAttempted && $selected['plan'] === $repairedPlan,
            'blocking_policy' => $blockingPolicy,
        ];
    }

    private function buildEvaluation(array $normalizedPlan, array $trainingState, array $context): array
    {
        $issues = collectNormalizedPlanValidationIssues($normalizedPlan, $trainingState, $context);
        $issues = array_merge($issues, $this->collectScenarioIssues($normalizedPlan, $trainingState, $context));
        $issues = array_merge($issues, $this->collectLlmPlannerContractIssues($normalizedPlan, $trainingState, $context));
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
        // minimum-distance repair поднимает слишком короткие тренировки → недельный объём
        // может снова выйти за cap, поэтому load repair прогоняется повторно после него.
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

    private function collectLlmPlannerContractIssues(array $normalizedPlan, array $trainingState, array $context): array
    {
        return array_merge(
            $this->collectUserFacingLanguageIssues($normalizedPlan, $context),
            $this->collectMacroDetailConsistencyIssues($normalizedPlan, $context),
            $this->collectLongRunSafetyIssues($normalizedPlan, $trainingState, $context),
            $this->collectFreshLongEffortIssues($normalizedPlan, $context)
        );
    }

    private function collectUserFacingLanguageIssues(array $normalizedPlan, array $context): array
    {
        $issues = [];
        $isLlmPlanner = !empty($context['planner_hard_rules']) || !empty($context['macro_plan']);
        $severity = $isLlmPlanner ? 'error' : 'warning';

        foreach (($normalizedPlan['weeks'] ?? []) as $week) {
            $weekNumber = (int) ($week['week_number'] ?? 0);
            foreach ((array) ($week['days'] ?? []) as $day) {
                foreach (['notes', 'description'] as $field) {
                    $text = trim((string) ($day[$field] ?? ''));
                    if ($text === '' || !$this->containsForbiddenEnglishTrainingText($text)) {
                        continue;
                    }

                    $issues[] = [
                        'severity' => $severity,
                        'code' => 'english_user_facing_plan_text',
                        'week_number' => $weekNumber,
                        'date' => $day['date'] ?? null,
                        'message' => "Неделя {$weekNumber}, " . ($day['date'] ?? 'unknown-date') . ": пользовательский текст поля {$field} содержит английские тренировочные термины.",
                    ];
                }
            }
        }

        foreach ((array) ($context['macro_plan']['weeks'] ?? []) as $macroWeek) {
            $weekNumber = (int) ($macroWeek['week'] ?? ($macroWeek['week_number'] ?? 0));
            foreach (['quality_focus', 'risk_note'] as $field) {
                $text = trim((string) ($macroWeek[$field] ?? ''));
                if ($text === '' || !$this->containsForbiddenEnglishTrainingText($text)) {
                    continue;
                }

                $issues[] = [
                    'severity' => $severity,
                    'code' => 'english_user_facing_macro_text',
                    'week_number' => $weekNumber > 0 ? $weekNumber : null,
                    'date' => null,
                    'message' => "Macro-неделя {$weekNumber}: поле {$field} содержит английские тренировочные термины.",
                ];
            }
        }

        return $issues;
    }

    private function collectMacroDetailConsistencyIssues(array $normalizedPlan, array $context): array
    {
        if ((string) ($context['planner_strategy'] ?? '') === 'single_pass') {
            return [];
        }

        $macroWeeks = (array) ($context['macro_plan']['weeks'] ?? []);
        if ($macroWeeks === []) {
            return [];
        }

        $macroByWeek = [];
        foreach ($macroWeeks as $macroWeek) {
            $weekNumber = (int) ($macroWeek['week'] ?? ($macroWeek['week_number'] ?? 0));
            if ($weekNumber > 0) {
                $macroByWeek[$weekNumber] = $macroWeek;
            }
        }

        $issues = [];
        foreach (($normalizedPlan['weeks'] ?? []) as $week) {
            $weekNumber = (int) ($week['week_number'] ?? 0);
            if ($weekNumber < 1 || !isset($macroByWeek[$weekNumber])) {
                continue;
            }

            $macroWeek = (array) $macroByWeek[$weekNumber];
            $macroTarget = isset($macroWeek['target_volume_km']) ? (float) $macroWeek['target_volume_km'] : 0.0;
            $actualVolume = (float) ($week['total_volume'] ?? 0.0);
            if ($macroTarget > 0.0 && $actualVolume > 0.0) {
                $detailTarget = isset($week['target_volume_km']) ? (float) $week['target_volume_km'] : 0.0;
                $detailTargetSource = (string) ($week['target_volume_source'] ?? '');
                $detailTargetTolerance = $detailTarget > 0.0 ? max(3.0, $detailTarget * 0.05) : 0.0;
                $detailTargetMatchesCalendar = $detailTarget > 0.0 && abs($actualVolume - $detailTarget) <= ($detailTargetTolerance + 0.1);
                $detailAdjustsMacro = $detailTargetSource === 'llm'
                    && $detailTargetMatchesCalendar
                    && abs($detailTarget - $macroTarget) > (max(3.0, $macroTarget * 0.05) + 0.1);

                if ($detailAdjustsMacro) {
                    $reason = trim((string) ($week['macro_adjustment_reason'] ?? ''));
                    if ($reason === '') {
                        $issues[] = [
                            'severity' => 'warning',
                            'code' => 'macro_detail_adjusted_without_reason',
                            'week_number' => $weekNumber,
                            'date' => null,
                            'message' => "Неделя {$weekNumber}: detail снизил target_volume_km с {$macroTarget} до {$detailTarget} км, но не объяснил причину пересмотра.",
                        ];
                    }
                    continue;
                }

                $tolerance = max(3.0, $macroTarget * 0.05);
                $delta = abs($actualVolume - $macroTarget);
                if ($delta > ($tolerance + 0.1)) {
                    $issues[] = [
                        'severity' => $delta > max(8.0, $macroTarget * 0.15) ? 'error' : 'warning',
                        'code' => 'macro_detail_volume_mismatch',
                        'week_number' => $weekNumber,
                        'date' => null,
                        'message' => "Неделя {$weekNumber}: macro target {$macroTarget} км, а календарь даёт {$actualVolume} км. Нужно пересмотреть macro, а не молча сохранять другой объём.",
                    ];
                }
            }

            $containsRace = $this->weekContainsType($week, 'race');
            $isRecoveryOrTaper = !empty($week['is_recovery']) || in_array((string) ($week['phase'] ?? ''), ['recovery', 'taper', 'race'], true);
            $macroLong = isset($macroWeek['long_run_km']) ? (float) $macroWeek['long_run_km'] : 0.0;
            $actualLong = $this->maxDayDistanceByType($week, 'long');
            if (!$containsRace && !$isRecoveryOrTaper && $macroLong > 0.0 && $actualLong > 0.0 && abs($actualLong - $macroLong) > 2.1) {
                $issues[] = [
                    'severity' => abs($actualLong - $macroLong) > 5.0 ? 'error' : 'warning',
                    'code' => 'macro_detail_long_run_mismatch',
                    'week_number' => $weekNumber,
                    'date' => null,
                    'message' => "Неделя {$weekNumber}: macro long_run {$macroLong} км, а календарь даёт {$actualLong} км.",
                ];
            }
        }

        return $issues;
    }

    private function collectLongRunSafetyIssues(array $normalizedPlan, array $trainingState, array $context): array
    {
        $issues = [];
        $hardRules = $this->plannerHardRules($context);
        $raceDistance = (string) ($trainingState['race_distance'] ?? ($hardRules['race_distance'] ?? ''));
        $raceDistanceKm = isset($hardRules['race_distance_km']) ? (float) $hardRules['race_distance_km'] : $this->resolveRaceDistanceKm($raceDistance);
        $raceDate = (string) ($trainingState['race_date'] ?? ($hardRules['race_date'] ?? ''));
        $loadPolicy = is_array($trainingState['load_policy'] ?? null) ? $trainingState['load_policy'] : [];
        $longShareCap = isset($loadPolicy['long_share_cap'])
            ? (float) $loadPolicy['long_share_cap']
            : (float) ($hardRules['long_run_safety']['long_share_cap'] ?? 0.45);
        $forbidTrainingRunAtRaceDistance = !empty($hardRules['long_run_safety']['no_training_run_at_or_above_race_distance_except_race_day']);
        $isMarathon = in_array($raceDistance, ['marathon', '42.2k'], true) || $raceDistanceKm >= 40.0;

        foreach (($normalizedPlan['weeks'] ?? []) as $week) {
            $weekNumber = (int) ($week['week_number'] ?? 0);
            $weekVolume = (float) ($week['total_volume'] ?? 0.0);
            $containsRace = $this->weekContainsType($week, 'race');
            $longDistance = $this->maxDayDistanceByType($week, 'long');

            if (!$containsRace && $weekVolume >= 12.0 && $longDistance > 0.0 && $longShareCap > 0.0) {
                $share = $longDistance / $weekVolume;
                if ($share > ($longShareCap + 0.04)) {
                    $issues[] = [
                        'severity' => ($share > ($longShareCap + 0.10) || $weekVolume >= 25.0) ? 'error' : 'warning',
                        'code' => 'long_run_share_too_high',
                        'week_number' => $weekNumber,
                        'date' => null,
                        'message' => "Неделя {$weekNumber}: длительная {$longDistance} км занимает " . round($share * 100) . "% недели при лимите около " . round($longShareCap * 100) . "%.",
                    ];
                }
            }

            foreach ((array) ($week['days'] ?? []) as $day) {
                if (normalizeTrainingType($day['type'] ?? null) !== 'long') {
                    continue;
                }

                $distance = (float) ($day['distance_km'] ?? 0.0);
                if ($forbidTrainingRunAtRaceDistance && $raceDistanceKm > 0.0 && $distance >= ($raceDistanceKm - 0.1)) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'training_long_run_at_race_distance',
                        'week_number' => $weekNumber,
                        'date' => $day['date'] ?? null,
                        'message' => "Неделя {$weekNumber}, " . ($day['date'] ?? 'unknown-date') . ": тренировочная длительная {$distance} км фактически равна дистанции гонки.",
                    ];
                }

                if ($isMarathon && $distance > 38.1) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'marathon_long_run_too_large',
                        'week_number' => $weekNumber,
                        'date' => $day['date'] ?? null,
                        'message' => "Неделя {$weekNumber}, " . ($day['date'] ?? 'unknown-date') . ": длительная {$distance} км слишком большая для марафонской подготовки.",
                    ];
                }

                if ($isMarathon && $raceDate !== '' && !empty($day['date']) && $distance > 32.1) {
                    $daysToRace = $this->daysBetween((string) $day['date'], $raceDate);
                    if ($daysToRace !== null && $daysToRace > 0 && $daysToRace <= 21) {
                        $issues[] = [
                            'severity' => 'error',
                            'code' => 'marathon_long_run_too_close_to_race',
                            'week_number' => $weekNumber,
                            'date' => $day['date'] ?? null,
                            'message' => "Неделя {$weekNumber}, " . ($day['date'] ?? 'unknown-date') . ": длительная {$distance} км стоит за {$daysToRace} дней до марафона.",
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    private function collectFreshLongEffortIssues(array $normalizedPlan, array $context): array
    {
        $guard = $this->plannerHardRules($context)['fresh_long_effort_guard'] ?? null;
        if (!is_array($guard) || empty($guard['applies'])) {
            return [];
        }

        $weekOne = $this->findWeek($normalizedPlan, 1);
        if ($weekOne === null) {
            return [];
        }

        $issues = [];
        $maxLong = isset($guard['week_1_long_run_max_km']) ? (float) $guard['week_1_long_run_max_km'] : 24.0;
        if (!empty($guard['week_1_must_be_recovery']) && empty($weekOne['is_recovery'])) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'fresh_long_effort_week1_not_recovery',
                'week_number' => 1,
                'date' => null,
                'message' => 'Неделя 1 должна быть восстановительной после свежего очень длинного забега.',
            ];
        }

        foreach ((array) ($weekOne['days'] ?? []) as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            if (!empty($guard['week_1_quality_allowed']) || !in_array($type, ['tempo', 'interval', 'fartlek', 'control'], true)) {
                continue;
            }

            $issues[] = [
                'severity' => 'error',
                'code' => 'fresh_long_effort_week1_has_quality',
                'week_number' => 1,
                'date' => $day['date'] ?? null,
                'message' => 'Неделя 1 содержит интенсивную тренировку, хотя перед стартом плана был свежий очень длинный забег.',
            ];
        }

        $actualLong = $this->maxDayDistanceByType($weekOne, 'long');
        if ($actualLong > ($maxLong + 0.1)) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'fresh_long_effort_week1_long_too_large',
                'week_number' => 1,
                'date' => null,
                'message' => "Неделя 1: длительная {$actualLong} км слишком большая сразу после свежего очень длинного забега; максимум {$maxLong} км.",
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
        $freshLongEffortGuard = $this->plannerHardRules($context)['fresh_long_effort_guard'] ?? null;
        $freshLongEffortRecoveryWeek = is_array($freshLongEffortGuard)
            && !empty($freshLongEffortGuard['applies'])
            && !empty($freshLongEffortGuard['week_1_must_be_recovery']);
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
            function (array $issue) use ($normalizedPlan, $relaxedRequiredDayContract, $loadPolicy, $freshLongEffortRecoveryWeek): bool {
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
                $forceFreshLongEffortRecoveryWeek = $freshLongEffortRecoveryWeek && $weekNumber === 1;
                $raceWeekCapEnabled = $containsRace;
                $postRaceCapEnabled = $prevContainsRace && (int) ($loadPolicy['post_goal_race_run_day_cap'] ?? 0) > 0;

                return !($forceInitialRecoveryWeek || $forceFreshLongEffortRecoveryWeek || $raceWeekCapEnabled || $postRaceCapEnabled);
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

    private function plannerHardRules(array $context): array
    {
        if (is_array($context['planner_hard_rules'] ?? null)) {
            return $context['planner_hard_rules'];
        }

        if (is_array($context['hard_rules'] ?? null)) {
            return $context['hard_rules'];
        }

        return [];
    }

    private function maxDayDistanceByType(?array $week, string $type): float
    {
        if (!is_array($week)) {
            return 0.0;
        }

        $max = 0.0;
        foreach ((array) ($week['days'] ?? []) as $day) {
            if (normalizeTrainingType($day['type'] ?? null) !== $type) {
                continue;
            }
            $max = max($max, (float) ($day['distance_km'] ?? 0.0));
        }

        return round($max, 1);
    }

    private function containsForbiddenEnglishTrainingText(string $text): bool
    {
        return preg_match(
            '/\b(threshold|marathon[\s-]*pace|race[\s-]*pace|long\s+run|easy\s+run|warm[\s-]*up|cool[\s-]*down|taper|recovery|mp|hmp)\b/iu',
            $text
        ) === 1;
    }

    private function daysBetween(string $fromDate, string $toDate): ?int
    {
        try {
            $from = new DateTimeImmutable($fromDate);
            $to = new DateTimeImmutable($toDate);
        } catch (Throwable $e) {
            return null;
        }

        return (int) $from->diff($to)->format('%r%a');
    }

    private function resolveRaceDistanceKm(string $raceDistance): float
    {
        return match ($raceDistance) {
            '5k' => 5.0,
            '10k' => 10.0,
            'half', '21.1k' => 21.1,
            'marathon', '42.2k' => 42.2,
            default => 0.0,
        };
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

    private function resolveBlockingPolicy(array $context): string
    {
        $policy = strtolower(trim((string) ($context['blocking_policy'] ?? 'strict')));
        return in_array($policy, ['strict', 'permissive'], true) ? $policy : 'strict';
    }

    private function applyBlockingPolicy(array $issues, string $policy): array
    {
        if ($policy !== 'permissive') {
            return $issues;
        }

        $fatalCodes = [
            'invalid_week_day_count',
            'schedule_skeleton_mismatch',
        ];

        foreach ($issues as &$issue) {
            $code = (string) ($issue['code'] ?? '');
            if (($issue['severity'] ?? 'warning') === 'error' && !in_array($code, $fatalCodes, true)) {
                $issue['severity'] = 'warning';
                $issue['blocking_policy_note'] = 'downgraded_by_permissive_llm_gate';
            }
        }
        unset($issue);

        return $issues;
    }
}
