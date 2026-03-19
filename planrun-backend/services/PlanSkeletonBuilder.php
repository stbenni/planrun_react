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

        $skeletonWeeks = [];
        for ($weekNumber = 1; $weekNumber <= $weeks; $weekNumber++) {
            $phase = $phasePlan[$weekNumber] ?? ['name' => 'base', 'label' => 'Базовый', 'max_key_workouts' => 0];
            $isRecovery = in_array($weekNumber, $recoveryWeeks, true);
            $isControl = in_array($weekNumber, $controlWeeks, true);
            $isRaceWeek = $racePosition && ((int) $racePosition['week'] === $weekNumber);
            $raceDayIndex = $isRaceWeek ? (int) $racePosition['dayIndex'] : null;

            $days = array_fill(0, 7, 'rest');
            foreach ($runDays as $runDayKey) {
                $idx = getPromptWeekdayOrder()[$runDayKey] - 1;
                $days[$idx] = 'easy';
            }

            if ($isRaceWeek && $raceDayIndex !== null) {
                $days[$raceDayIndex] = 'race';
            } elseif ($longDayIndex !== null && isset($days[$longDayIndex])) {
                $days[$longDayIndex] = 'long';
            }

            $qualityTypes = $this->resolveQualityTypes($goalType, $phase, $sessions, $weekNumber, $isRecovery, $isControl, $isRaceWeek, $userData);
            $qualityIndexes = $this->pickQualityIndexes($runDays, $longDayIndex, $raceDayIndex, count($qualityTypes));
            foreach ($qualityTypes as $i => $type) {
                if (!isset($qualityIndexes[$i])) {
                    break;
                }
                $days[$qualityIndexes[$i]] = $type;
            }

            if ($isRaceWeek && $longDayIndex !== null && $raceDayIndex !== null && $longDayIndex !== $raceDayIndex) {
                $days[$longDayIndex] = 'easy';
            }

            $skeletonWeeks[] = [
                'week_number' => $weekNumber,
                'phase_name' => $phase['name'] ?? null,
                'phase_label' => $phase['label'] ?? null,
                'days' => $days,
            ];
        }

        return [
            'start_date' => $startDate,
            'weeks' => $skeletonWeeks,
        ];
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
        array $userData
    ): array {
        $specialFlags = $this->resolveSpecialPopulationFlags($userData);
        $weeksToGoal = isset($userData['training_state']['weeks_to_goal'])
            ? (int) $userData['training_state']['weeks_to_goal']
            : (isset($userData['weeks_to_goal']) ? (int) $userData['weeks_to_goal'] : null);
        $hasHighCautionFlags = !empty(array_intersect($specialFlags, [
            'pregnant_or_postpartum',
            'chronic_condition_flag',
        ]));
        $isInjuryReturn = in_array('return_after_injury', $specialFlags, true);
        $hasConservativeFlags = !empty(array_intersect($specialFlags, [
            'older_adult_65_plus',
            'return_after_break',
            'low_confidence_vdot',
        ]));

        if ($isRecovery || $isRaceWeek) {
            return [];
        }

        if ($hasHighCautionFlags) {
            return [];
        }

        $phaseName = (string) ($phase['name'] ?? '');
        $phaseKeys = max(0, (int) ($phase['max_key_workouts'] ?? 0));

        // Возврат после травмы: постепенное введение нагрузки
        // Недели 1-2: только лёгкий бег, без ключевых
        // Недели 3-4: только фартлек
        // Неделя 5+: обычный режим с ограничением 1 ключевая
        if ($isInjuryReturn) {
            if ($weekNumber <= 2) {
                return [];
            }
            if ($weekNumber <= 4) {
                return ['fartlek'];
            }
            $phaseKeys = min($phaseKeys, 1);
        }

        if ($isControl) {
            if ($hasConservativeFlags) {
                return [];
            }
            return ['control'];
        }
        if ($hasConservativeFlags) {
            $phaseKeys = min($phaseKeys, 1);
            if (in_array('return_after_break', $specialFlags, true)) {
                if ($weekNumber <= 2) {
                    return [];
                }
                if ($weekNumber <= 3) {
                    return ['fartlek'];
                }
            }
        }

        if (in_array($goalType, ['health', 'weight_loss'], true)) {
            // 65+ — только лёгкий бег, без интенсива
            if (in_array('older_adult_65_plus', $specialFlags, true)) {
                return [];
            }
            // Возврат после перерыва управляется выше (недели 1-3 ограничены)
            // low_confidence_vdot НЕ блокирует фартлеки — они по ощущениям, без точного темпа

            // Weight loss: фартлек с 3-й недели при ≥3 сессиях
            if ($goalType === 'weight_loss' && $phaseKeys > 0 && $sessions >= 3 && $weekNumber >= 3) {
                return ['fartlek'];
            }
            // Health: фартлек для intermediate+ (не новичков)
            if ($goalType === 'health' && $phaseKeys > 0 && !in_array(($userData['experience_level'] ?? 'novice'), ['novice', 'beginner'], true)) {
                return ['fartlek'];
            }
            return [];
        }

        if ($phaseKeys < 1) {
            return [];
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
            if ($this->isAdjacentToAny($idx, $selected)) {
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
