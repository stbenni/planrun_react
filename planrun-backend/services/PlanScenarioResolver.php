<?php

require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

/**
 * PlanScenarioResolver
 *
 * Небольшой авторитетный слой поверх planning state:
 * - выравнивает недельный anchor до понедельника для week-based planning;
 * - определяет ключевой сценарий генерации;
 * - возвращает флаги и policy decisions для explainability/runtime.
 */
class PlanScenarioResolver
{
    public function resolve(array $user, array $state, string $mode = 'generate', array $payload = []): array
    {
        $goalType = (string) ($state['goal_type'] ?? $user['goal_type'] ?? 'health');
        $raceDistance = (string) ($state['race_distance'] ?? $user['race_distance'] ?? '');
        $raceDate = (string) ($state['race_date'] ?? $user['race_date'] ?? $user['target_marathon_date'] ?? '');
        $specialFlags = array_values(array_unique(array_map(
            'strval',
            is_array($state['special_population_flags'] ?? null) ? $state['special_population_flags'] : []
        )));
        $guardLevel = (string) ($state['load_policy']['feedback_guard_level'] ?? 'neutral');
        $readiness = (string) ($state['readiness'] ?? 'normal');

        $requestedStartDate = $this->resolveScheduleAnchorDate($user, $mode, $payload);
        $racePosition = ($requestedStartDate !== null && $raceDate !== '')
            ? computeRaceDayPosition($requestedStartDate, $raceDate)
            : null;
        $tuneUpEvent = $this->resolveTuneUpEvent($payload, $requestedStartDate, $raceDate, $raceDistance);

        $effectiveWeeksToGoal = max(0, (int) ($state['weeks_to_goal'] ?? 0));
        if (!empty($racePosition['week'])) {
            $effectiveWeeksToGoal = max($effectiveWeeksToGoal, (int) $racePosition['week']);
        }

        $isRaceGoal = in_array($goalType, ['race', 'time_improvement'], true);
        $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        $isHighCaution = $readiness === 'low' || in_array($guardLevel, ['fatigue_high', 'pain_protective', 'illness_protective'], true);

        $flags = [];
        if ($isRaceGoal && $effectiveWeeksToGoal > 0 && $effectiveWeeksToGoal <= 3) {
            $flags[] = 'short_runway_taper';
        }
        if ($isLongRace && in_array('short_runway_taper', $flags, true)) {
            $flags[] = 'short_runway_long_race';
        }
        if ($tuneUpEvent !== null) {
            $flags[] = 'explicit_tune_up_event';
            if ($this->isBRaceBeforeARace($goalType, $raceDistance, $tuneUpEvent)) {
                $flags[] = 'b_race_before_a_race';
                $tuneUpEvent['forced_type'] = 'control';
            }
        }
        if (in_array('return_after_injury', $specialFlags, true)) {
            $flags[] = 'return_after_injury';
        }
        if (in_array('return_after_break', $specialFlags, true)) {
            $flags[] = 'return_after_break';
        }
        if (in_array('low_confidence_vdot', $specialFlags, true)) {
            $flags[] = 'low_confidence_start';
        }
        if ($guardLevel === 'fatigue_high') {
            $flags[] = 'overload_recovery';
        }
        if ($guardLevel === 'pain_protective') {
            $flags[] = 'pain_protective';
        }
        if ($guardLevel === 'illness_protective') {
            $flags[] = 'illness_protective';
        }
        if ($isHighCaution) {
            $flags[] = 'high_caution';
        }

        $flags = array_values(array_unique($flags));

        $policyDecisions = [];
        $rawStartDate = $payload['cutoff_date'] ?? ($user['training_start_date'] ?? null);
        if ($requestedStartDate !== null && $rawStartDate !== null && $requestedStartDate !== $rawStartDate) {
            $policyDecisions[] = 'schedule_anchor_aligned_to_monday';
        }
        if (in_array('short_runway_taper', $flags, true)) {
            $policyDecisions[] = 'short_runway_taper_policy';
        }
        if (in_array('short_runway_long_race', $flags, true)) {
            $policyDecisions[] = 'long_race_taper_policy';
        }
        if (in_array('b_race_before_a_race', $flags, true)) {
            $policyDecisions[] = 'protect_primary_race_priority';
            $policyDecisions[] = 'downshift_tune_up_to_controlled_effort';
        } elseif ($tuneUpEvent !== null) {
            $policyDecisions[] = 'respect_explicit_tune_up_event';
        }
        if (in_array('return_after_break', $flags, true)) {
            $policyDecisions[] = 'return_after_break_guard';
        }
        if ($isHighCaution) {
            $policyDecisions[] = 'high_caution_caps';
        }

        return [
            'primary' => $this->resolvePrimaryScenario($flags, $isRaceGoal),
            'flags' => $flags,
            'schedule_anchor_date' => $requestedStartDate,
            'race_position' => $racePosition,
            'effective_weeks_to_goal' => $effectiveWeeksToGoal > 0 ? $effectiveWeeksToGoal : null,
            'is_high_caution' => $isHighCaution,
            'tune_up_event' => $tuneUpEvent,
            'policy_decisions' => $policyDecisions,
        ];
    }

    public function resolveScheduleAnchorDate(array $user, string $mode = 'generate', array $payload = []): ?string
    {
        $candidate = $payload['cutoff_date'] ?? ($user['training_start_date'] ?? null);
        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($candidate);
        } catch (Throwable $e) {
            return null;
        }

        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek === 1) {
            return $date->format('Y-m-d');
        }

        return $date->modify('-' . ($dayOfWeek - 1) . ' days')->format('Y-m-d');
    }

    private function resolvePrimaryScenario(array $flags, bool $isRaceGoal): string
    {
        foreach ([
            'return_after_injury',
            'return_after_break',
            'illness_protective',
            'pain_protective',
            'b_race_before_a_race',
            'overload_recovery',
            'short_runway_long_race',
            'short_runway_taper',
            'low_confidence_start',
        ] as $candidate) {
            if (in_array($candidate, $flags, true)) {
                return $candidate;
            }
        }

        return $isRaceGoal ? 'standard_race_build' : 'general_fitness';
    }

    private function resolveTuneUpEvent(array $payload, ?string $scheduleAnchorDate, string $mainRaceDate, string $mainRaceDistance): ?array
    {
        if ($scheduleAnchorDate === null || $mainRaceDate === '') {
            return null;
        }

        $event = null;
        if (!empty($payload['tune_up_event']) && is_array($payload['tune_up_event'])) {
            $event = $payload['tune_up_event'];
        } elseif (!empty($payload['secondary_race']) && is_array($payload['secondary_race'])) {
            $event = $payload['secondary_race'];
        } else {
            $date = $payload['tune_up_race_date']
                ?? $payload['secondary_race_date']
                ?? $payload['b_race_date']
                ?? $payload['control_date']
                ?? null;
            if (is_string($date) && trim($date) !== '') {
                $event = [
                    'date' => $date,
                    'distance' => $payload['tune_up_race_distance']
                        ?? $payload['secondary_race_distance']
                        ?? $payload['b_race_distance']
                        ?? $payload['control_distance']
                        ?? null,
                    'type' => $payload['tune_up_race_type']
                        ?? $payload['secondary_race_type']
                        ?? $payload['b_race_type']
                        ?? ((isset($payload['control_date']) && trim((string) $payload['control_date']) !== '') ? 'control' : 'race'),
                    'target_time' => $payload['tune_up_race_target_time']
                        ?? $payload['secondary_race_target_time']
                        ?? $payload['b_race_target_time']
                        ?? null,
                ];
            }
        }

        if (!is_array($event)) {
            return null;
        }

        $date = isset($event['date']) ? trim((string) $event['date']) : '';
        if ($date === '') {
            return null;
        }

        try {
            $eventDate = new DateTimeImmutable($date);
            $mainDate = new DateTimeImmutable($mainRaceDate);
        } catch (Throwable $e) {
            return null;
        }

        $daysBeforeMainRace = (int) $eventDate->diff($mainDate)->format('%r%a');
        if ($daysBeforeMainRace <= 0) {
            return null;
        }

        $position = computeRaceDayPosition($scheduleAnchorDate, $date);
        $normalizedType = $this->normalizeTuneUpType($event['type'] ?? null);
        $distanceKm = $this->resolveDistanceKm($event['distance'] ?? null, $mainRaceDistance);
        $targetTimeSec = $this->parseTimeToSeconds($event['target_time'] ?? null);
        $paceSec = ($targetTimeSec !== null && $distanceKm > 0)
            ? (int) round($targetTimeSec / $distanceKm)
            : null;

        return [
            'date' => $eventDate->format('Y-m-d'),
            'type' => $normalizedType,
            'forced_type' => $normalizedType,
            'distance' => $event['distance'] ?? null,
            'distance_km' => $distanceKm,
            'target_time' => $event['target_time'] ?? null,
            'target_time_sec' => $targetTimeSec,
            'pace_sec' => $paceSec,
            'week' => isset($position['week']) ? (int) $position['week'] : null,
            'dayIndex' => isset($position['dayIndex']) ? (int) $position['dayIndex'] : null,
            'day_of_week' => isset($position['dayIndex']) ? ((int) $position['dayIndex'] + 1) : null,
            'days_before_main_race' => $daysBeforeMainRace,
        ];
    }

    private function isBRaceBeforeARace(string $goalType, string $mainRaceDistance, array $tuneUpEvent): bool
    {
        if (!in_array($goalType, ['race', 'time_improvement'], true)) {
            return false;
        }

        $mainDistanceKm = $this->resolveDistanceKm($mainRaceDistance, $mainRaceDistance);
        $eventDistanceKm = (float) ($tuneUpEvent['distance_km'] ?? 0.0);
        $daysBeforeMainRace = (int) ($tuneUpEvent['days_before_main_race'] ?? 0);
        if ($daysBeforeMainRace < 5 || $daysBeforeMainRace > 10) {
            return false;
        }

        if (!in_array($mainRaceDistance, ['half', '21.1k', 'marathon', '42.2k'], true)) {
            return false;
        }

        if ($eventDistanceKm <= 0 || $mainDistanceKm <= 0 || $eventDistanceKm >= $mainDistanceKm) {
            return false;
        }

        return true;
    }

    private function normalizeTuneUpType(mixed $rawType): string
    {
        $value = trim(mb_strtolower((string) $rawType, 'UTF-8'));
        return match ($value) {
            'race', 'забег', 'соревнование' => 'race',
            default => 'control',
        };
    }

    private function resolveDistanceKm(mixed $rawDistance, string $fallbackDistance): float
    {
        if (is_numeric($rawDistance)) {
            return round((float) $rawDistance, 1);
        }

        $distance = trim(mb_strtolower((string) $rawDistance, 'UTF-8'));
        if ($distance === '') {
            $distance = trim(mb_strtolower($fallbackDistance, 'UTF-8'));
        }

        return match ($distance) {
            '5k', '5 км', '5km' => 5.0,
            '10k', '10 км', '10km' => 10.0,
            'half', '21.1k', '21.1 км', 'полумарафон' => 21.1,
            'marathon', '42.2k', '42.2 км', 'марафон' => 42.2,
            default => 0.0,
        };
    }

    private function parseTimeToSeconds(mixed $rawTime): ?int
    {
        $time = trim((string) $rawTime);
        if ($time === '') {
            return null;
        }

        $parts = explode(':', $time);
        if (count($parts) === 2) {
            [$minutes, $seconds] = $parts;
            if (is_numeric($minutes) && is_numeric($seconds)) {
                return ((int) $minutes * 60) + (int) $seconds;
            }
            return null;
        }

        if (count($parts) === 3) {
            [$hours, $minutes, $seconds] = $parts;
            if (is_numeric($hours) && is_numeric($minutes) && is_numeric($seconds)) {
                return ((int) $hours * 3600) + ((int) $minutes * 60) + (int) $seconds;
            }
        }

        return null;
    }
}
