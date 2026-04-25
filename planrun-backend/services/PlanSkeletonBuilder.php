<?php

require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

class PlanSkeletonBuilder {
    private const DEFAULT_RUN_DAY_ORDERS = [
        1 => ['wed'],
        2 => ['tue', 'sat'],
        3 => ['mon', 'wed', 'sat'],
        4 => ['mon', 'wed', 'fri', 'sun'],
        5 => ['mon', 'tue', 'thu', 'sat', 'sun'],
        6 => ['mon', 'tue', 'wed', 'thu', 'sat', 'sun'],
        7 => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
    ];

    public function build(array $userData, string $goalType, array $options = []): array {
        $startDate = $options['start_date'] ?? ($userData['training_start_date'] ?? null);
        $weeks = isset($options['weeks']) ? (int) $options['weeks'] : (int) (getSuggestedPlanWeeks($userData, $goalType) ?? 0);
        if (!$startDate || $weeks < 1) {
            return ['start_date' => $startDate, 'weeks' => []];
        }

        $runDays = $this->resolveRunDays($userData);
        $sessions = (int) ($userData['sessions_per_week'] ?? count($runDays));
        $longDayKey = $this->resolveLongDayKey($userData, $runDays);
        $longDayIndex = $longDayKey !== null ? (getPromptWeekdayOrder()[$longDayKey] - 1) : null;
        $hasRace = in_array($goalType, ['race', 'time_improvement'], true);
        $raceDate = $hasRace ? ($userData['race_date'] ?? $userData['target_marathon_date'] ?? null) : null;
        $racePosition = $raceDate ? computeRaceDayPosition($startDate, $raceDate) : null;

        $phasePlan = $this->resolvePhasePlan($userData, $goalType, $weeks, $options);
        $recoveryWeeks = $this->resolveRecoveryWeeks($userData, $goalType, $options);
        $controlWeeks = $this->resolveControlWeeks($userData, $goalType, $options);
        $forceInitialRecoveryWeek = !empty($userData['training_state']['load_policy']['force_initial_recovery_week']);

        $skeletonWeeks = [];
        for ($weekNumber = 1; $weekNumber <= $weeks; $weekNumber++) {
            $phase = $phasePlan[$weekNumber] ?? ['name' => 'base', 'label' => 'Базовый', 'max_key_workouts' => 0];
            $phaseWeekIndex = (int) ($phase['phase_week_index'] ?? 1);
            $isRecovery = in_array($weekNumber, $recoveryWeeks, true)
                || ($forceInitialRecoveryWeek && $weekNumber === 1);
            $isControl = in_array($weekNumber, $controlWeeks, true);
            $isRaceWeek = $racePosition && ((int) $racePosition['week'] === $weekNumber);
            $isPostGoalRaceWeek = $racePosition && ((int) $racePosition['week'] + 1 === $weekNumber);
            $raceDayIndex = $isRaceWeek ? (int) $racePosition['dayIndex'] : null;
            $tuneUpEvent = $this->resolveWeekTuneUpEvent($userData, $weekNumber);
            $weekRunDays = $this->resolveWeekRunDays(
                $runDays,
                $phase,
                $weeks,
                $weekNumber,
                $isRaceWeek,
                $raceDayIndex,
                $isPostGoalRaceWeek,
                $racePosition ? (int) $racePosition['dayIndex'] : null,
                $longDayKey,
                $userData
            );

            $days = array_fill(0, 7, 'rest');
            foreach ($weekRunDays as $runDayKey) {
                $idx = getPromptWeekdayOrder()[$runDayKey] - 1;
                $days[$idx] = 'easy';
            }

            if ($isRaceWeek && $raceDayIndex !== null) {
                $days[$raceDayIndex] = 'race';
            } elseif ($isPostGoalRaceWeek) {
                // Неделя после goal race остаётся восстановительной: без long и без quality.
            } elseif ($tuneUpEvent !== null && isset($tuneUpEvent['dayIndex'])) {
                $days[(int) $tuneUpEvent['dayIndex']] = (string) ($tuneUpEvent['forced_type'] ?? 'control');
            } elseif ($longDayIndex !== null && isset($days[$longDayIndex])) {
                $days[$longDayIndex] = 'long';
            }

            $qualityTypes = $this->resolveQualityTypes(
                $goalType,
                $phase,
                $sessions,
                $weekNumber,
                $isRecovery,
                $isControl,
                $isRaceWeek,
                $isPostGoalRaceWeek,
                $userData
            );
            $qualityIndexes = $this->pickQualityIndexes($weekRunDays, $longDayIndex, $raceDayIndex, count($qualityTypes));
            foreach ($qualityTypes as $i => $type) {
                if (!isset($qualityIndexes[$i])) {
                    break;
                }
                $days[$qualityIndexes[$i]] = $type;
            }

            $skeletonWeeks[] = [
                'week_number' => $weekNumber,
                'phase_name' => $phase['name'] ?? null,
                'phase_label' => $phase['label'] ?? null,
                'phase_week_index' => $phaseWeekIndex,
                'days' => $days,
            ];
        }

        return [
            'start_date' => $startDate,
            'weeks' => $skeletonWeeks,
        ];
    }

    private function resolveWeekRunDays(
        array $runDays,
        array $phase,
        int $totalWeeks,
        int $weekNumber,
        bool $isRaceWeek,
        ?int $raceDayIndex,
        bool $isPostGoalRaceWeek,
        ?int $goalRaceDayIndex,
        ?string $longDayKey,
        array $userData
    ): array {
        $raceDistance = (string) ($userData['race_distance'] ?? '');
        $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        $weeksToGoal = isset($userData['training_state']['weeks_to_goal'])
            ? (int) $userData['training_state']['weeks_to_goal']
            : $totalWeeks;
        $scenarioFlags = array_map(
            'strval',
            (array) ($userData['training_state']['planning_scenario']['flags'] ?? [])
        );
        $hasShortRunwayScenario = in_array('short_runway_taper', $scenarioFlags, true);
        $hasLongRaceShortRunwayScenario = in_array('short_runway_long_race', $scenarioFlags, true);
        $hasBRaceBeforeARace = in_array('b_race_before_a_race', $scenarioFlags, true);
        $loadPolicy = is_array($userData['training_state']['load_policy'] ?? null)
            ? $userData['training_state']['load_policy']
            : [];
        $isInitialForcedRecoveryWeek = !empty($loadPolicy['force_initial_recovery_week'])
            && $weekNumber === 1
            && !$isRaceWeek
            && !$isPostGoalRaceWeek;
        $initialRecoveryRunDayCap = (int) ($loadPolicy['initial_recovery_run_day_cap'] ?? 0);
        if ($isInitialForcedRecoveryWeek && $initialRecoveryRunDayCap > 0 && count($runDays) > $initialRecoveryRunDayCap) {
            $mustKeep = $longDayKey !== null && in_array($longDayKey, $runDays, true) ? [$longDayKey] : [];
            return $this->limitRunDays($runDays, $initialRecoveryRunDayCap, $mustKeep);
        }

        if (!$isRaceWeek && !$isPostGoalRaceWeek && ((!$isLongRace || $weeksToGoal > 3) && !$hasShortRunwayScenario)) {
            return $runDays;
        }
        if (count($runDays) <= 3) {
            return $runDays;
        }

        $readiness = (string) ($userData['training_state']['readiness'] ?? 'normal');
        $feedbackGuard = (string) (($userData['training_state']['load_policy']['feedback_guard_level'] ?? 'neutral'));
        $raceWeekRunDayCap = (int) (($userData['training_state']['load_policy']['race_week_run_day_cap'] ?? 0));
        $postGoalRaceRunDayCap = (int) (($userData['training_state']['load_policy']['post_goal_race_run_day_cap'] ?? 3));
        $isHighCaution = $readiness === 'low' || in_array($feedbackGuard, ['fatigue_high', 'pain_protective', 'illness_protective'], true);
        $phaseName = (string) ($phase['name'] ?? 'base');
        $cap = null;
        $weekTuneUpEvent = $this->resolveWeekTuneUpEvent($userData, $weekNumber);

        if ($isRaceWeek) {
            if (!$isLongRace && $raceWeekRunDayCap > 0) {
                $cap = min($raceWeekRunDayCap, count($runDays));
            }
            // После control/B-race за неделю до главного старта сохраняем ещё одну активацию,
            // если состояние не high-caution: это даёт более живую, но всё ещё безопасную подводку.
            if ($cap === null) {
                $cap = ($hasBRaceBeforeARace && !$isHighCaution) ? 4 : 3;
            }
        } elseif ($isPostGoalRaceWeek) {
            $cap = min(max(1, $postGoalRaceRunDayCap), count($runDays));
        } elseif ($weekTuneUpEvent !== null && $hasBRaceBeforeARace) {
            // Tune-up event уже сам по себе является главной работой недели, поэтому additional easy days
            // считаем поверх него, а не вместе с ним.
            $cap = $isHighCaution ? 4 : 4;
        } elseif ($phaseName === 'taper' || $weeksToGoal <= 2 || $hasLongRaceShortRunwayScenario) {
            $cap = $isHighCaution ? 4 : min(5, count($runDays));
        } elseif ($weeksToGoal === 3 || $hasShortRunwayScenario) {
            $cap = min(5, count($runDays));
        }

        if ($cap === null || count($runDays) <= $cap) {
            return $runDays;
        }

        $mustKeep = [];
        if (!$isRaceWeek && !$isPostGoalRaceWeek && $longDayKey !== null && in_array($longDayKey, $runDays, true)) {
            $mustKeep[] = $longDayKey;
        }

        $pool = $runDays;
        if ($isRaceWeek && $raceDayIndex !== null) {
            $raceDayKey = $this->weekdayKeyFromIndex($raceDayIndex);
            $pool = array_values(array_filter(
                $runDays,
                static fn(string $dayKey): bool => $dayKey !== $raceDayKey
            ));
            $cap = max(2, $cap - 1);
        } elseif ($weekTuneUpEvent !== null && isset($weekTuneUpEvent['dayIndex'])) {
            $tuneUpDayKey = $this->weekdayKeyFromIndex((int) $weekTuneUpEvent['dayIndex']);
            if ($tuneUpDayKey !== null) {
                $pool = array_values(array_filter(
                    $runDays,
                    static fn(string $dayKey): bool => $dayKey !== $tuneUpDayKey
                ));
                $cap = max(2, $cap - 1);
            }
        }

        $priority = $this->resolveRunDayPriority(
            $userData,
            $weekNumber,
            $isRaceWeek,
            $isHighCaution,
            $weekTuneUpEvent !== null
        );
        if ($isPostGoalRaceWeek && $goalRaceDayIndex !== null && $goalRaceDayIndex >= 5) {
            $priority = ['wed', 'thu', 'fri', 'sat', 'tue', 'sun', 'mon'];
        }

        return $this->limitRunDays(
            $pool,
            $cap,
            $mustKeep,
            $priority
        );
    }

    private function limitRunDays(array $runDays, int $cap, array $mustKeep = [], ?array $priority = null): array {
        if ($cap < 1 || count($runDays) <= $cap) {
            return $runDays;
        }

        $selected = [];
        foreach ($mustKeep as $dayKey) {
            if (in_array($dayKey, $runDays, true) && !in_array($dayKey, $selected, true)) {
                $selected[] = $dayKey;
            }
        }

        $priority = $priority ?? ['mon', 'wed', 'thu', 'sat', 'tue', 'fri', 'sun'];
        foreach ($priority as $dayKey) {
            if (!in_array($dayKey, $runDays, true) || in_array($dayKey, $selected, true)) {
                continue;
            }
            $selected[] = $dayKey;
            if (count($selected) >= $cap) {
                break;
            }
        }

        if (count($selected) < $cap) {
            foreach ($runDays as $dayKey) {
                if (in_array($dayKey, $selected, true)) {
                    continue;
                }
                $selected[] = $dayKey;
                if (count($selected) >= $cap) {
                    break;
                }
            }
        }

        return sortPromptWeekdayKeys($selected);
    }

    private function resolveRunDayPriority(
        array $userData,
        int $weekNumber,
        bool $isRaceWeek,
        bool $isHighCaution,
        bool $hasWeekTuneUpEvent
    ): ?array {
        $scenarioFlags = array_map(
            'strval',
            (array) ($userData['training_state']['planning_scenario']['flags'] ?? [])
        );
        $hasBRaceBeforeARace = in_array('b_race_before_a_race', $scenarioFlags, true);
        if (!$hasBRaceBeforeARace || $isHighCaution) {
            return null;
        }

        if ($isRaceWeek) {
            // После контрольного старта за неделю до главной гонки:
            // 1) не ставим бег сразу на следующий день,
            // 2) держим две осмысленные активации по ходу недели,
            // 3) оставляем короткую встряску накануне старта.
            return ['tue', 'thu', 'sat', 'wed', 'mon', 'fri', 'sun'];
        }

        if ($hasWeekTuneUpEvent) {
            // В неделю контрольного старта хотим ранние лёгкие пробежки и короткую подводку,
            // а не набор километров в конце недели подряд.
            return ['tue', 'wed', 'sat', 'mon', 'thu', 'fri', 'sun'];
        }

        return null;
    }

    private function weekdayKeyFromIndex(int $dayIndex): ?string {
        foreach (getPromptWeekdayOrder() as $dayKey => $order) {
            if ($order - 1 === $dayIndex) {
                return $dayKey;
            }
        }

        return null;
    }

    private function resolveRunDays(array $userData): array {
        $preferredDays = !empty($userData['preferred_days']) && is_array($userData['preferred_days'])
            ? sortPromptWeekdayKeys($userData['preferred_days'])
            : [];
        if (!empty($preferredDays)) {
            return $preferredDays;
        }

        $sessions = max(1, min(7, (int) ($userData['sessions_per_week'] ?? 3)));
        return self::DEFAULT_RUN_DAY_ORDERS[$sessions] ?? self::DEFAULT_RUN_DAY_ORDERS[3];
    }

    private function resolveLongDayKey(array $userData, array $runDays): ?string {
        $candidate = getPreferredLongRunDayKey(array_merge($userData, ['preferred_days' => $runDays]));
        if ($candidate !== null) {
            return $candidate;
        }
        return !empty($runDays) ? end($runDays) : null;
    }

    private function resolvePhasePlan(array $userData, string $goalType, int $weeks, array $options): array {
        if (!empty($options['current_phase']) && is_array($options['current_phase'])) {
            return $this->buildPhasePlanFromCurrentPhase($options['current_phase'], $weeks);
        }

        $macro = in_array($goalType, ['race', 'time_improvement'], true)
            ? computeMacrocycle($userData, $goalType)
            : computeHealthMacrocycle($userData, $goalType);

        $plan = [];
        if ($macro && !empty($macro['phases'])) {
            foreach ($macro['phases'] as $phase) {
                $from = (int) ($phase['weeks_from'] ?? 0);
                $to = (int) ($phase['weeks_to'] ?? 0);
                for ($w = $from; $w <= $to && $w <= $weeks; $w++) {
                $plan[$w] = $phase;
                $plan[$w]['phase_week_index'] = $w - $from + 1;
            }
        }
        }

        return $plan;
    }

    private function buildPhasePlanFromCurrentPhase(array $currentPhase, int $weeks): array {
        $plan = [];
        $remainingPhases = $currentPhase['remaining_phases'] ?? [];
        $weeksIntoPhase = (int) ($currentPhase['weeks_into_phase'] ?? 0);
        $cursor = 1;

        foreach ($remainingPhases as $idx => $phase) {
            $duration = ((int) ($phase['weeks_to'] ?? 0) - (int) ($phase['weeks_from'] ?? 0)) + 1;
            if ($idx === 0 && $weeksIntoPhase > 0) {
                $duration -= $weeksIntoPhase;
            }
            if ($duration < 1) {
                continue;
            }

            for ($i = 0; $i < $duration && $cursor <= $weeks; $i++, $cursor++) {
                $plan[$cursor] = [
                    'name' => $phase['name'] ?? null,
                    'label' => $phase['label'] ?? null,
                    'max_key_workouts' => (int) ($phase['max_key_workouts'] ?? 0),
                    'phase_week_index' => $idx === 0
                        ? ($weeksIntoPhase + $i + 1)
                        : ($i + 1),
                ];
            }

            if ($cursor > $weeks) {
                break;
            }
        }

        return $plan;
    }

    private function resolveRecoveryWeeks(array $userData, string $goalType, array $options): array {
        if (!empty($options['current_phase']['recovery_weeks']) && isset($options['kept_weeks'])) {
            $result = [];
            foreach ($options['current_phase']['recovery_weeks'] as $week) {
                $mapped = (int) $week - (int) $options['kept_weeks'];
                if ($mapped >= 1) {
                    $result[] = $mapped;
                }
            }
            return $result;
        }

        $macro = in_array($goalType, ['race', 'time_improvement'], true)
            ? computeMacrocycle($userData, $goalType)
            : computeHealthMacrocycle($userData, $goalType);

        return !empty($macro['recovery_weeks']) ? array_map('intval', $macro['recovery_weeks']) : [];
    }

    private function resolveControlWeeks(array $userData, string $goalType, array $options): array {
        if (!empty($options['current_phase']['control_weeks']) && isset($options['kept_weeks'])) {
            $result = [];
            foreach ($options['current_phase']['control_weeks'] as $week) {
                $mapped = (int) $week - (int) $options['kept_weeks'];
                if ($mapped >= 1) {
                    $result[] = $mapped;
                }
            }
            return $result;
        }

        $macro = in_array($goalType, ['race', 'time_improvement'], true)
            ? computeMacrocycle($userData, $goalType)
            : computeHealthMacrocycle($userData, $goalType);

        return !empty($macro['control_weeks']) ? array_map('intval', $macro['control_weeks']) : [];
    }

    private function resolveQualityTypes(
        string $goalType,
        array $phase,
        int $sessions,
        int $weekNumber,
        bool $isRecovery,
        bool $isControl,
        bool $isRaceWeek,
        bool $isPostGoalRaceWeek,
        array $userData
    ): array {
        $specialFlags = $this->resolveSpecialPopulationFlags($userData);
        $weeksToGoal = isset($userData['training_state']['weeks_to_goal'])
            ? (int) $userData['training_state']['weeks_to_goal']
            : (isset($userData['weeks_to_goal']) ? (int) $userData['weeks_to_goal'] : null);
        $loadPolicy = is_array($userData['training_state']['load_policy'] ?? null)
            ? $userData['training_state']['load_policy']
            : [];
        $qualityMode = (string) ($loadPolicy['quality_mode'] ?? 'normal');
        $weeklyTargetKm = (float) (($loadPolicy['weekly_volume_targets_km'][$weekNumber] ?? 0.0));
        $qualityDelayWeeks = (int) ($loadPolicy['quality_delay_weeks'] ?? 0);
        $qualitySessionMinKm = (float) ($loadPolicy['quality_session_min_km'] ?? 0.0);
        $protectLowBaseNovice = !empty($loadPolicy['protect_low_base_novice']);
        $hasHighCautionFlags = !empty(array_intersect($specialFlags, [
            'pregnant_or_postpartum',
            'chronic_condition_flag',
        ]));
        $isInjuryReturn = in_array('return_after_injury', $specialFlags, true);
        $hasConservativeFlags = !empty(array_intersect($specialFlags, [
            'older_adult_65_plus',
            'return_after_break',
            'low_confidence_vdot',
            'recent_pain_signal',
            'recent_fatigue_spike',
            'recent_illness_signal',
            'recent_sleep_signal',
            'recent_stress_signal',
            'recent_travel_signal',
        ]));

        if ($isRecovery || $isRaceWeek || $isPostGoalRaceWeek) {
            return [];
        }

        if ($this->resolveWeekTuneUpEvent($userData, $weekNumber) !== null) {
            return [];
        }

        if ($hasHighCautionFlags) {
            return [];
        }

        if ($sessions <= 2) {
            return [];
        }

        $phaseName = (string) ($phase['name'] ?? '');
        $phaseKeys = max(0, (int) ($phase['max_key_workouts'] ?? 0));

        // Возврат после травмы: только лёгкий бег. Валидатор special population
        // запрещает non-race quality для этого флага, поэтому не ставим fartlek/tempo.
        if ($isInjuryReturn) {
            return [];
        }

        if ($isControl) {
            if ($hasConservativeFlags || $qualityMode === 'simplified') {
                return [];
            }
            return ['control'];
        }

        if ($goalType === 'health') {
            return [];
        }

        if ($goalType === 'weight_loss') {
            if ($hasConservativeFlags || $sessions < 4 || in_array($phaseName, ['adaptation'], true)) {
                return [];
            }
            return ['fartlek'];
        }

        if ($hasConservativeFlags) {
            $phaseKeys = min($phaseKeys, 1);
            if (in_array('return_after_break', $specialFlags, true)) {
                if ($weekNumber <= 2) {
                    return [];
                }
                if ($weekNumber <= 3 && count(array_intersect($specialFlags, ['older_adult_65_plus', 'low_confidence_vdot'])) === 0) {
                    return ['fartlek'];
                }
                return [];
            }
        }

        if ($phaseKeys < 1) {
            return [];
        }

        if ($qualityMode === 'simplified') {
            if ($qualityDelayWeeks > 0 && $weekNumber <= $qualityDelayWeeks) {
                return [];
            }

            if ($phaseName === 'base') {
                return [($userData['race_distance'] ?? '') === '5k' ? 'fartlek' : 'tempo'];
            }

            $fallbackType = in_array($phaseName, ['build', 'development', 'maintenance', 'peak', 'taper'], true)
                ? 'tempo'
                : 'fartlek';
            if ($protectLowBaseNovice || $hasConservativeFlags || $sessions < 4) {
                $fallbackType = 'fartlek';
            }

            return [$fallbackType];
        }

        if ($protectLowBaseNovice) {
            $phaseKeys = min($phaseKeys, 1);
            if ($qualityDelayWeeks > 0 && $weekNumber <= $qualityDelayWeeks) {
                return [];
            }
            if ($qualitySessionMinKm > 0.0 && $weeklyTargetKm > 0.0 && $weeklyTargetKm < ($qualitySessionMinKm + 3.0)) {
                return [];
            }
            if (in_array($phaseName, ['base', 'pre_base', 'adaptation', 'taper'], true)) {
                return [];
            }
        }

        if (in_array($phaseName, ['pre_base', 'adaptation'], true)) {
            return [];
        }

        if ($phaseName === 'base') {
            $shortRunway = $weeksToGoal !== null && $weeksToGoal <= 8;
            if ($shortRunway && !$hasConservativeFlags) {
                return [($userData['race_distance'] ?? '') === '5k' ? 'interval' : 'tempo'];
            }
            return [];
        }

        if ($phaseName === 'taper') {
            if ($hasConservativeFlags) {
                return [];
            }
            return [($userData['race_distance'] ?? '') === '5k' ? 'interval' : 'tempo'];
        }

        $types = [];
        if (in_array($phaseName, ['peak'], true)) {
            $isMarathonGoal = ($userData['race_distance'] ?? '') === 'marathon' || ($userData['race_distance'] ?? '') === '42.2k';
            $types[] = $isMarathonGoal ? 'tempo' : 'interval';
            if ($phaseKeys >= 2 && $sessions >= 4) {
                $types[] = $isMarathonGoal ? 'interval' : 'tempo';
            }
            return array_slice(array_unique($types), 0, min($phaseKeys, 2));
        }

        if (in_array($phaseName, ['build', 'development', 'maintenance'], true)) {
            $types[] = 'tempo';
            if ($phaseKeys >= 2 && $sessions >= 4) {
                $types[] = 'interval';
            }
            return array_slice($types, 0, min($phaseKeys, 2));
        }

        return [];
    }

    private function resolveSpecialPopulationFlags(array $userData): array {
        $flags = $userData['training_state']['special_population_flags'] ?? ($userData['special_population_flags'] ?? []);
        return is_array($flags) ? array_values(array_unique($flags)) : [];
    }

    private function resolveWeekTuneUpEvent(array $userData, int $weekNumber): ?array
    {
        $event = $userData['training_state']['planning_scenario']['tune_up_event'] ?? null;
        if (!is_array($event)) {
            return null;
        }

        if ((int) ($event['week'] ?? 0) !== $weekNumber) {
            return null;
        }

        if (!isset($event['dayIndex'])) {
            return null;
        }

        return $event;
    }

    private function pickQualityIndexes(array $runDays, ?int $longDayIndex, ?int $raceDayIndex, int $count): array {
        if ($count < 1) {
            return [];
        }

        $runIndexes = array_map(fn($dayKey) => getPromptWeekdayOrder()[$dayKey] - 1, $runDays);
        $preferredOrder = [1, 3, 2, 4, 0, 5, 6];

        $blocked = array_filter([$longDayIndex, $raceDayIndex], fn($v) => $v !== null);
        $selected = [];

        foreach ($preferredOrder as $idx) {
            if (!in_array($idx, $runIndexes, true)) {
                continue;
            }
            if (in_array($idx, $blocked, true)) {
                continue;
            }
            if ($this->isAdjacentToAny($idx, $blocked) || $this->isAdjacentToAny($idx, $selected)) {
                continue;
            }
            $selected[] = $idx;
            if (count($selected) >= $count) {
                return $selected;
            }
        }

        foreach ($preferredOrder as $idx) {
            if (!in_array($idx, $runIndexes, true)) {
                continue;
            }
            if (in_array($idx, $blocked, true) || in_array($idx, $selected, true)) {
                continue;
            }
            if ($this->isAdjacentToAny($idx, $blocked) || $this->isAdjacentToAny($idx, $selected)) {
                continue;
            }
            $selected[] = $idx;
            if (count($selected) >= $count) {
                return $selected;
            }
        }

        return $selected;
    }

    private function isAdjacentToAny(int $index, array $indexes): bool {
        foreach ($indexes as $candidate) {
            if (abs($candidate - $index) === 1) {
                return true;
            }
        }
        return false;
    }
}
