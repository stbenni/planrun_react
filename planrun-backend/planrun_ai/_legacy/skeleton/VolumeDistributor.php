<?php
/**
 * VolumeDistributor — распределение недельного объёма по дням.
 *
 * Принимает типы дней из PlanSkeletonBuilder (7-элементный массив:
 * 'easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'rest', 'race')
 * и целевой объём, возвращает массив дней с конкретными distance_km и pace.
 *
 * Алгоритм:
 *  1. Long  = из load_policy.long_run_targets_km
 *  2. Tempo = warmup + tempo_work + cooldown (из TempoProgressionBuilder)
 *  3. Interval = warmup + interval_work + rest_volume + cooldown (из IntervalProgressionBuilder)
 *  4. Fartlek = warmup + segments + cooldown (из FartlekBuilder)
 *  5. Easy  = остаток / кол-во easy-дней (min = load_policy.easy_min_km)
 *  6. Recovery week — все дистанции × recovery_cutback_ratio
 */

class VolumeDistributor
{
    /**
     * Распределить объём по дням.
     *
     * @param array  $dayTypes       7-элементный массив типов ['rest','easy','rest','tempo','rest','easy','long']
     * @param float  $targetVolumeKm Целевой объём недели в км
     * @param float  $longTargetKm   Целевая длительная в км
     * @param array  $paceRules      Темпы (pace_rules из TrainingStateBuilder)
     * @param array  $loadPolicy     Политика нагрузки (load_policy из TrainingStateBuilder)
     * @param string $phase          Название фазы ('base','build','peak','taper')
     * @param bool   $isRecovery     Разгрузочная неделя
     * @param int    $weekInPhase    Номер недели внутри фазы (1-based)
     * @param array  $workoutDetails Детали ключевых тренировок (из IntervalProgressionBuilder и т.д.)
     *                               Формат: ['interval' => [...], 'tempo' => [...], 'fartlek' => [...], 'control' => [...]]
     * @param float  $raceDistanceKm Дистанция забега (для race-дней)
     * @return array Массив из 7 элементов, каждый — данные дня
     */
    public static function distribute(
        array  $dayTypes,
        float  $targetVolumeKm,
        float  $longTargetKm,
        array  $paceRules,
        array  $loadPolicy,
        string $phase,
        bool   $isRecovery,
        int    $weekInPhase,
        array  $workoutDetails = [],
        float  $raceDistanceKm = 0.0
    ): array {

        $recoveryRatio = $isRecovery ? ($loadPolicy['recovery_cutback_ratio'] ?? 0.88) : 1.0;
        $longShareCap = (float) ($loadPolicy['long_share_cap'] ?? 0.45);
        $minLongOverEasyKm = (float) ($loadPolicy['min_long_over_easy_km'] ?? 0.5);
        $qualitySessionMinKm = (float) ($loadPolicy['quality_session_min_km'] ?? 4.0);
        $qualityShareCap = (float) ($loadPolicy['quality_workout_share_cap'] ?? 0.50);
        $hasLongDay = in_array('long', $dayTypes, true);
        $runDayCount = count(array_filter(
            $dayTypes,
            static fn(string $type): bool => in_array($type, ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race'], true)
        ));
        if ($longShareCap > 0) {
            if ($runDayCount <= 2) {
                $longShareCap = min(max($longShareCap, 0.50), 0.52);
            } elseif ($targetVolumeKm < 12.0) {
                $longShareCap = min($longShareCap, 0.40);
            } elseif ($targetVolumeKm < 20.0 || $runDayCount <= 3) {
                $longShareCap = min($longShareCap, 0.42);
            }
        }
        if ($hasLongDay && $longTargetKm <= 0 && $targetVolumeKm > 0) {
            $fallbackLongRatio = $phase === 'taper' ? 0.35 : 0.40;
            $longFloor = (float) ($loadPolicy['long_min_km'] ?? 8.0);
            $candidate = round($targetVolumeKm * $fallbackLongRatio, 1);
            $shareCapKm = $longShareCap > 0 ? round($targetVolumeKm * $longShareCap, 1) : $candidate;
            $longTargetKm = min(max($longFloor, $candidate), max($longFloor, $shareCapKm));
        }
        // Динамический минимум easy: 5% от недельного объёма, но не менее load_policy floor
        $policyEasyMin = (float) ($loadPolicy['easy_min_km'] ?? 2.0);
        $easyMinKm = max($policyEasyMin, round($targetVolumeKm * 0.05, 1));
        if ($hasLongDay && $longTargetKm > 0) {
            $longTargetKm = max($longTargetKm, round($easyMinKm + $minLongOverEasyKm, 1));
        }
        $protectedRaceLongFloorKm = self::resolveProtectedRaceLongFloorKm(
            $dayTypes,
            $targetVolumeKm,
            $raceDistanceKm,
            $easyMinKm,
            $phase,
            $isRecovery
        );
        if ($targetVolumeKm > 0 && $longTargetKm > 0 && $longShareCap > 0) {
            $longTargetKm = min(
                max($longTargetKm, $protectedRaceLongFloorKm),
                max($protectedRaceLongFloorKm, round($targetVolumeKm * $longShareCap, 1))
            );
        }

        $dayTypes = self::normalizeDayTypesForQualityViability(
            $dayTypes,
            $targetVolumeKm,
            $longTargetKm,
            $easyMinKm,
            $qualitySessionMinKm,
            $raceDistanceKm,
            $loadPolicy
        );
        $qualityCount = count(array_filter(
            $dayTypes,
            static fn(string $type): bool => self::isScalableQualityType($type)
        ));
        $qualityBudgetKm = self::resolveQualityBudgetKm(
            $dayTypes,
            $targetVolumeKm,
            $longTargetKm,
            $easyMinKm,
            $raceDistanceKm,
            $minLongOverEasyKm
        );
        $perQualityBudgetKm = $qualityCount > 0 ? round($qualityBudgetKm / $qualityCount, 1) : 0.0;
        $qualityCapKm = $qualityCount > 0 && $qualityShareCap > 0
            ? round(max($qualitySessionMinKm, min($perQualityBudgetKm, $targetVolumeKm * $qualityShareCap)), 1)
            : 0.0;

        // Шаг 1: посчитать дистанцию фиксированных тренировок
        $fixedKm = 0.0;
        $easyCount = 0;
        $dayDistances = array_fill(0, 7, 0.0);
        $dayData = [];

        for ($i = 0; $i < 7; $i++) {
            $dayData[$i] = self::buildDayBase($dayTypes[$i], $i);
        }

        // Назначить фиксированные дистанции
        for ($i = 0; $i < 7; $i++) {
            $type = $dayTypes[$i];

            switch ($type) {
                case 'long':
                    $km = round($longTargetKm * $recoveryRatio, 1);
                    $dayDistances[$i] = $km;
                    $dayData[$i]['distance_km'] = $km;
                    $dayData[$i]['pace'] = self::formatPace($paceRules['long_min_sec'] ?? $paceRules['easy_max_sec'] ?? 360);
                    $fixedKm += $km;
                    break;

                case 'tempo':
                    $tempoDetails = self::capWorkoutDetailsToPolicy(
                        'tempo',
                        $workoutDetails['tempo'] ?? null,
                        $qualityCapKm,
                        $paceRules,
                        $loadPolicy
                    );
                    $km = $tempoDetails ? self::calculateTotalKm($tempoDetails) : 6.5;
                    $km = round($km * $recoveryRatio, 1);
                    $dayDistances[$i] = $km;
                    $dayData[$i] = array_merge($dayData[$i], self::applyTempoDetails($tempoDetails, $paceRules, $km));
                    $fixedKm += $km;
                    break;

                case 'interval':
                    $intervalDetails = self::capWorkoutDetailsToPolicy(
                        'interval',
                        $workoutDetails['interval'] ?? null,
                        $qualityCapKm,
                        $paceRules,
                        $loadPolicy
                    );
                    $km = $intervalDetails ? self::calculateTotalKm($intervalDetails) : 8.0;
                    $km = round($km * $recoveryRatio, 1);
                    $dayDistances[$i] = $km;
                    $dayData[$i] = array_merge($dayData[$i], self::applyIntervalDetails($intervalDetails, $paceRules, $km));
                    $fixedKm += $km;
                    break;

                case 'fartlek':
                    $fartlekDetails = self::capWorkoutDetailsToPolicy(
                        'fartlek',
                        $workoutDetails['fartlek'] ?? null,
                        $qualityCapKm,
                        $paceRules,
                        $loadPolicy
                    );
                    $km = $fartlekDetails ? self::calculateTotalKm($fartlekDetails) : 7.0;
                    $km = round($km * $recoveryRatio, 1);
                    $dayDistances[$i] = $km;
                    $dayData[$i] = array_merge($dayData[$i], self::applyFartlekDetails($fartlekDetails, $paceRules, $km));
                    $fixedKm += $km;
                    break;

                case 'control':
                    $controlDetails = $workoutDetails['control'] ?? null;
                    $km = $controlDetails ? self::calculateTotalKm($controlDetails) : 6.0;
                    $km = round($km * $recoveryRatio, 1);
                    $dayDistances[$i] = $km;
                    $dayData[$i] = array_merge($dayData[$i], self::applyControlDetails($controlDetails, $paceRules, $km));
                    $fixedKm += $km;
                    break;

                case 'race':
                    $dayDistances[$i] = $raceDistanceKm;
                    $dayData[$i]['distance_km'] = $raceDistanceKm;
                    // Целевой темп забега: goal_pace → marathon pace → tempo (fallback)
                    $racePaceSec = $paceRules['race_pace_sec']
                        ?? $paceRules['marathon_sec']
                        ?? $paceRules['tempo_sec']
                        ?? 300;
                    $dayData[$i]['pace'] = self::formatPace($racePaceSec);
                    $fixedKm += $raceDistanceKm;
                    break;

                case 'easy':
                    $easyCount++;
                    break;

                case 'rest':
                default:
                    $dayData[$i]['distance_km'] = 0;
                    break;
            }
        }

        // Шаг 2: распределить остаток по easy-дням
        $effectiveTarget = $targetVolumeKm * $recoveryRatio;
        $remainingKm = max(0, $effectiveTarget - $fixedKm);

        if ($easyCount > 0) {
            $easyPace = self::formatPace($paceRules['easy_min_sec'] ?? 340);
            $easyAllocations = self::allocateEasyDays(
                $dayTypes,
                $remainingKm,
                $easyMinKm,
                $effectiveTarget,
                $phase
            );

            for ($i = 0; $i < 7; $i++) {
                if ($dayTypes[$i] === 'easy') {
                    $dayData[$i]['distance_km'] = $easyAllocations[$i] ?? $easyMinKm;
                    $dayData[$i]['pace'] = $easyPace;
                    $dayDistances[$i] = $dayData[$i]['distance_km'];
                }
            }
        }

        self::rebalanceLongShareIntoEasyDays(
            $dayTypes,
            $dayData,
            $dayDistances,
            $effectiveTarget,
            $longShareCap,
            $easyMinKm,
            $minLongOverEasyKm,
            $protectedRaceLongFloorKm
        );

        // Шаг 3: рассчитать фактический объём и duration
        $actualVolume = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $actualVolume += $dayDistances[$i];
            $dayData[$i] = self::calculateDuration($dayData[$i], $paceRules);
        }

        return [
            'days' => $dayData,
            'target_volume_km' => round($effectiveTarget, 1),
            'actual_volume_km' => round($actualVolume, 1),
            'volume_delta_km' => round($actualVolume - $effectiveTarget, 1),
        ];
    }

    private static function buildDayBase(string $type, int $dayIndex): array
    {
        $dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        return [
            'day_of_week' => $dayIndex + 1,
            'day_name' => $dayNames[$dayIndex] ?? 'mon',
            'type' => $type,
            'distance_km' => 0.0,
            'pace' => null,
            'duration_minutes' => null,
            'is_key_workout' => in_array($type, ['tempo', 'interval', 'fartlek', 'control', 'race'], true),
            // Структурные поля — заполняются для ключевых тренировок
            'warmup_km' => null,
            'cooldown_km' => null,
            'reps' => null,
            'interval_m' => null,
            'rest_m' => null,
            'rest_type' => null,
            'interval_pace' => null,
            'tempo_km' => null,
            'segments' => null,
            'notes' => null,
        ];
    }

    private static function allocateEasyDays(
        array $dayTypes,
        float $remainingKm,
        float $easyMinKm,
        float $effectiveTarget,
        string $phase
    ): array {
        $easyIndexes = [];
        foreach ($dayTypes as $idx => $type) {
            if ($type === 'easy') {
                $easyIndexes[] = (int) $idx;
            }
        }

        if ($easyIndexes === []) {
            return [];
        }

        $equalKm = round($remainingKm / count($easyIndexes), 1);
        $equalKm = max($easyMinKm, $equalKm);
        $maxEasyKm = round($effectiveTarget * 0.25, 1);
        if ($equalKm > $maxEasyKm && $maxEasyKm > $easyMinKm) {
            $equalKm = $maxEasyKm;
        }

        $default = [];
        foreach ($easyIndexes as $idx) {
            $default[$idx] = $equalKm;
        }

        $eventIndex = array_search('control', $dayTypes, true);
        $raceIndex = array_search('race', $dayTypes, true);
        if ($phase !== 'taper' && $eventIndex === false && $raceIndex === false) {
            return $default;
        }
        if ($eventIndex === false && $raceIndex === false) {
            return $default;
        }

        $anchorIndex = $raceIndex !== false ? (int) $raceIndex : (int) $eventIndex;
        $weights = [];
        foreach ($easyIndexes as $idx) {
            $daysToEvent = $anchorIndex - $idx;
            if ($daysToEvent <= 1) {
                $weights[$idx] = $raceIndex !== false ? 0.55 : 0.70;
            } elseif ($daysToEvent === 2) {
                $weights[$idx] = $raceIndex !== false ? 0.75 : 0.90;
            } elseif ($daysToEvent <= 3) {
                $weights[$idx] = $raceIndex !== false ? 1.15 : 1.10;
            } elseif ($daysToEvent <= 5) {
                $weights[$idx] = 1.0;
            } else {
                $weights[$idx] = 0.9;
            }
        }

        $poolKm = max($remainingKm, $easyMinKm * count($easyIndexes));
        $weightSum = array_sum($weights);
        if ($weightSum <= 0) {
            return $default;
        }

        $allocations = [];
        $remainingPool = $poolKm;
        $remainingWeight = $weightSum;
        $lastIdx = end($easyIndexes);
        foreach ($easyIndexes as $idx) {
            if ($idx === $lastIdx) {
                $km = round(max($easyMinKm, $remainingPool), 1);
            } else {
                $share = $weights[$idx] / $remainingWeight;
                $km = round(max($easyMinKm, $remainingPool * $share), 1);
                $remainingPool -= $km;
                $remainingWeight -= $weights[$idx];
            }
            $allocations[$idx] = $km;
        }

        return $allocations;
    }

    private static function rebalanceLongShareIntoEasyDays(
        array $dayTypes,
        array &$dayData,
        array &$dayDistances,
        float $effectiveTarget,
        float $longShareCap,
        float $easyMinKm,
        float $minLongOverEasyKm,
        float $protectedLongFloorKm = 0.0
    ): void {
        if ($effectiveTarget <= 0.0 || $longShareCap <= 0.0) {
            return;
        }

        $longIndex = array_search('long', $dayTypes, true);
        if ($longIndex === false) {
            return;
        }

        $easyIndexes = [];
        foreach ($dayTypes as $idx => $type) {
            if ($type === 'easy') {
                $easyIndexes[] = (int) $idx;
            }
        }

        $longIndex = (int) $longIndex;
        $currentLongKm = (float) ($dayDistances[$longIndex] ?? 0.0);
        $maxLongKm = round($effectiveTarget * $longShareCap, 1);
        $minUsefulLongKm = round(max($easyMinKm + $minLongOverEasyKm, $protectedLongFloorKm), 1);
        if ($currentLongKm <= $maxLongKm || $maxLongKm < $minUsefulLongKm) {
            return;
        }

        $newLongKm = max($minUsefulLongKm, $maxLongKm);
        $excessKm = round($currentLongKm - $newLongKm, 1);
        if ($excessKm <= 0.0) {
            return;
        }

        $dayDistances[$longIndex] = $newLongKm;
        $dayData[$longIndex]['distance_km'] = $newLongKm;

        if ($easyIndexes === []) {
            return;
        }

        $remaining = $excessKm;
        $lastEasyIndex = end($easyIndexes);
        foreach ($easyIndexes as $idx) {
            $addKm = $idx === $lastEasyIndex
                ? $remaining
                : round($excessKm / count($easyIndexes), 1);
            $remaining = round($remaining - $addKm, 1);
            if ($addKm <= 0.0) {
                continue;
            }

            $dayDistances[$idx] = round((float) ($dayDistances[$idx] ?? 0.0) + $addKm, 1);
            $dayData[$idx]['distance_km'] = $dayDistances[$idx];
        }
    }

    private static function resolveProtectedRaceLongFloorKm(
        array $dayTypes,
        float $targetVolumeKm,
        float $raceDistanceKm,
        float $easyMinKm,
        string $phase,
        bool $isRecovery
    ): float {
        if (
            $isRecovery
            || $phase === 'taper'
            || $raceDistanceKm <= 0.0
            || $raceDistanceKm > 5.1
            || !in_array('long', $dayTypes, true)
        ) {
            return 0.0;
        }

        $floorKm = 5.0;
        $easyCount = count(array_filter(
            $dayTypes,
            static fn(string $type): bool => $type === 'easy'
        ));
        $requiredSupportKm = $easyCount * $easyMinKm;

        if ($targetVolumeKm < round($floorKm + $requiredSupportKm, 1)) {
            return 0.0;
        }

        return $floorKm;
    }

    private static function normalizeDayTypesForQualityViability(
        array $dayTypes,
        float $targetVolumeKm,
        float $longTargetKm,
        float $easyMinKm,
        float $qualitySessionMinKm,
        float $raceDistanceKm,
        array $loadPolicy
    ): array {
        if ($qualitySessionMinKm <= 0.0) {
            return $dayTypes;
        }

        $qualityIndexes = [];
        foreach ($dayTypes as $idx => $type) {
            if (self::isScalableQualityType($type)) {
                $qualityIndexes[] = (int) $idx;
            }
        }

        if ($qualityIndexes === []) {
            return $dayTypes;
        }

        $minLongGap = (float) ($loadPolicy['min_long_over_easy_km'] ?? 0.5);
        $availableQualityBudget = self::resolveQualityBudgetKm(
            $dayTypes,
            $targetVolumeKm,
            $longTargetKm,
            $easyMinKm,
            $raceDistanceKm,
            $minLongGap
        );
        $supportedQualityCount = (int) floor($availableQualityBudget / max(0.1, $qualitySessionMinKm));

        if ($supportedQualityCount >= count($qualityIndexes)) {
            return $dayTypes;
        }

        foreach ($qualityIndexes as $position => $idx) {
            if ($position < $supportedQualityCount) {
                continue;
            }
            $dayTypes[$idx] = 'easy';
        }

        return $dayTypes;
    }

    private static function resolveQualityBudgetKm(
        array $dayTypes,
        float $targetVolumeKm,
        float $longTargetKm,
        float $easyMinKm,
        float $raceDistanceKm,
        float $minLongGap
    ): float {
        $easyCount = 0;
        $hasLongDay = false;
        $raceCount = 0;

        foreach ($dayTypes as $type) {
            if ($type === 'easy') {
                $easyCount++;
            } elseif ($type === 'long') {
                $hasLongDay = true;
            } elseif ($type === 'race') {
                $raceCount++;
            }
        }

        $protectedEasyKm = $easyCount * $easyMinKm;
        $protectedLongKm = $hasLongDay ? max($longTargetKm, round($easyMinKm + $minLongGap, 1)) : 0.0;
        $protectedRaceKm = $raceCount > 0 ? ($raceCount * $raceDistanceKm) : 0.0;

        return round(max(0.0, $targetVolumeKm - $protectedEasyKm - $protectedLongKm - $protectedRaceKm), 1);
    }

    private static function isScalableQualityType(string $type): bool
    {
        return in_array($type, ['tempo', 'interval', 'fartlek'], true);
    }

    private static function capWorkoutDetailsToPolicy(
        string $type,
        ?array $details,
        float $qualityCapKm,
        array $paceRules,
        array $loadPolicy
    ): ?array {
        if ($details === null || $qualityCapKm <= 0.0 || !self::isScalableQualityType($type)) {
            return $details;
        }

        $currentKm = self::calculateTotalKm($details);
        if ($currentKm <= 0.0 || $currentKm <= $qualityCapKm) {
            return $details;
        }

        return match ($type) {
            'tempo' => self::buildTempoDetailsForCap($details, $qualityCapKm, $paceRules, $loadPolicy),
            'interval' => self::buildIntervalDetailsForCap($details, $qualityCapKm, $paceRules),
            'fartlek' => self::buildFartlekDetailsForCap($details, $qualityCapKm, $paceRules),
            default => $details,
        };
    }

    private static function buildTempoDetailsForCap(
        array $details,
        float $qualityCapKm,
        array $paceRules,
        array $loadPolicy
    ): array {
        $minTempoTotalKm = max(3.5, (float) ($loadPolicy['quality_session_min_km'] ?? 4.0));
        $targetKm = max($minTempoTotalKm, round($qualityCapKm, 1));
        $warmup = min((float) ($details['warmup_km'] ?? 2.0), $targetKm >= 5.5 ? 1.75 : 1.25);
        $cooldown = min((float) ($details['cooldown_km'] ?? 1.5), $targetKm >= 5.5 ? 1.25 : 1.0);
        $tempoKm = max(1.0, round($targetKm - $warmup - $cooldown, 1));
        $totalKm = round($warmup + $tempoKm + $cooldown, 1);

        $details['warmup_km'] = $warmup;
        $details['cooldown_km'] = $cooldown;
        $details['tempo_km'] = $tempoKm;
        $details['total_km'] = $totalKm;
        $details['tempo_pace_sec'] = (int) ($details['tempo_pace_sec'] ?? $paceRules['tempo_sec'] ?? 300);

        return $details;
    }

    private static function buildIntervalDetailsForCap(array $details, float $qualityCapKm, array $paceRules): array
    {
        $targetKm = max(4.0, round($qualityCapKm, 1));
        $warmup = min((float) ($details['warmup_km'] ?? 1.75), $targetKm >= 5.5 ? 1.5 : 1.25);
        $cooldown = min((float) ($details['cooldown_km'] ?? 1.25), 1.0);
        $intervalM = (int) ($details['interval_m'] ?? 600);
        $restM = (int) ($details['rest_m'] ?? 400);

        if ($targetKm <= 5.2) {
            $intervalM = min($intervalM, 400);
            $restM = min($restM, 200);
        } elseif ($targetKm <= 6.0) {
            $intervalM = min($intervalM, 600);
            $restM = min($restM, 300);
        }

        $repKm = max(0.3, ($intervalM + $restM) / 1000.0);
        $workBudgetKm = max(1.2, $targetKm - $warmup - $cooldown);
        $reps = max(3, (int) floor($workBudgetKm / $repKm));
        while ($reps > 3 && round($warmup + $cooldown + ($reps * $repKm), 1) > $targetKm) {
            $reps--;
        }

        $workKm = round(($reps * $intervalM) / 1000.0, 1);
        $restKm = round(($reps * $restM) / 1000.0, 1);
        $details['warmup_km'] = $warmup;
        $details['cooldown_km'] = $cooldown;
        $details['reps'] = $reps;
        $details['interval_m'] = $intervalM;
        $details['rest_m'] = $restM;
        $details['rest_type'] = $intervalM <= 600 ? 'walk' : ($details['rest_type'] ?? 'jog');
        $details['work_km'] = $workKm;
        $details['rest_km'] = $restKm;
        $details['total_km'] = round($warmup + $cooldown + $workKm + $restKm, 1);
        $details['interval_pace_sec'] = (int) ($details['interval_pace_sec'] ?? $paceRules['interval_sec'] ?? 280);

        return $details;
    }

    private static function buildFartlekDetailsForCap(array $details, float $qualityCapKm, array $paceRules): array
    {
        $targetKm = max(4.0, round($qualityCapKm, 1));
        $warmup = min((float) ($details['warmup_km'] ?? 1.75), 1.25);
        $cooldown = min((float) ($details['cooldown_km'] ?? 1.25), 1.0);
        $segment = $details['segments'][0] ?? [];
        $distanceM = (int) ($segment['distance_m'] ?? 300);
        $recoveryM = (int) ($segment['recovery_m'] ?? 300);

        if ($targetKm <= 5.0) {
            $distanceM = min($distanceM, 200);
            $recoveryM = min($recoveryM, 200);
        }

        $repKm = max(0.3, ($distanceM + $recoveryM) / 1000.0);
        $segmentBudgetKm = max(1.6, $targetKm - $warmup - $cooldown);
        $reps = max(4, (int) floor($segmentBudgetKm / $repKm));
        $totalKm = round($warmup + $cooldown + ($reps * $repKm), 1);

        $details['warmup_km'] = $warmup;
        $details['cooldown_km'] = $cooldown;
        $details['segments'] = [[
            'reps' => $reps,
            'distance_m' => $distanceM,
            'recovery_m' => $recoveryM,
            'pace' => $segment['pace'] ?? 'fast',
            'recovery_type' => $segment['recovery_type'] ?? 'jog',
        ]];
        $details['total_km'] = $totalKm;
        $details['fast_pace_sec'] = (int) ($details['fast_pace_sec'] ?? $paceRules['interval_sec'] ?? 280);
        $details['recovery_pace_sec'] = (int) ($details['recovery_pace_sec'] ?? $paceRules['easy_max_sec'] ?? ($paceRules['easy_min_sec'] ?? 340) + 20);

        return $details;
    }

    private static function calculateTotalKm(array $details): float
    {
        return (float) ($details['total_km'] ?? 0);
    }

    private static function applyTempoDetails(?array $details, array $paceRules, float $totalKm): array
    {
        if (!$details) {
            return [
                'distance_km' => $totalKm,
                'pace' => self::formatPace($paceRules['tempo_sec'] ?? 300),
                'warmup_km' => 2.0,
                'cooldown_km' => 1.5,
                'tempo_km' => max(1.0, $totalKm - 3.5),
            ];
        }

        $subtype = $details['subtype'] ?? 'threshold';

        // Race-pace (5k R-pace repeats) — interval-подобная структура
        if ($subtype === 'race_pace' && isset($details['reps'])) {
            return self::applyIntervalDetails($details, $paceRules, $totalKm);
        }

        // Race-pace (marathon/half/10k) — continuous run, другой темп
        if ($subtype === 'race_pace') {
            $paceSec = $details['tempo_pace_sec'] ?? $paceRules['race_pace_sec'] ?? 300;
            return [
                'distance_km' => $totalKm,
                'pace' => self::formatPace($paceSec),
                'subtype' => 'race_pace',
                'race_pace_label' => $details['race_pace_label'] ?? 'Race-pace',
                'warmup_km' => (float) ($details['warmup_km'] ?? 2.0),
                'cooldown_km' => (float) ($details['cooldown_km'] ?? 1.5),
                'tempo_km' => (float) ($details['tempo_km'] ?? max(1.0, $totalKm - 3.5)),
            ];
        }

        // Threshold tempo (по умолчанию)
        return [
            'distance_km' => $totalKm,
            'pace' => self::formatPace($paceRules['tempo_sec'] ?? 300),
            'warmup_km' => (float) ($details['warmup_km'] ?? 2.0),
            'cooldown_km' => (float) ($details['cooldown_km'] ?? 1.5),
            'tempo_km' => (float) ($details['tempo_km'] ?? max(1.0, $totalKm - 3.5)),
        ];
    }

    private static function applyIntervalDetails(?array $details, array $paceRules, float $totalKm): array
    {
        if (!$details) {
            return [
                'distance_km' => $totalKm,
                'pace' => self::formatPace($paceRules['easy_min_sec'] ?? 340),
                'interval_pace' => self::formatPace($paceRules['interval_sec'] ?? 280),
                'warmup_km' => 2.0,
                'cooldown_km' => 1.5,
                'reps' => 4,
                'interval_m' => 1000,
                'rest_m' => 400,
                'rest_type' => 'jog',
            ];
        }

        return [
            'distance_km' => $totalKm,
            'pace' => self::formatPace($paceRules['easy_min_sec'] ?? 340),
            'interval_pace' => self::formatPace($paceRules['interval_sec'] ?? 280),
            'warmup_km' => (float) ($details['warmup_km'] ?? 2.0),
            'cooldown_km' => (float) ($details['cooldown_km'] ?? 1.5),
            'reps' => (int) ($details['reps'] ?? 4),
            'interval_m' => (int) ($details['interval_m'] ?? 1000),
            'rest_m' => (int) ($details['rest_m'] ?? 400),
            'rest_type' => $details['rest_type'] ?? 'jog',
        ];
    }

    private static function applyFartlekDetails(?array $details, array $paceRules, float $totalKm): array
    {
        if (!$details) {
            return [
                'distance_km' => $totalKm,
                'pace' => self::formatPace($paceRules['easy_min_sec'] ?? 340),
                'warmup_km' => 2.0,
                'cooldown_km' => 1.5,
                'segments' => [],
            ];
        }

        return [
            'distance_km' => $totalKm,
            'pace' => self::formatPace($paceRules['easy_min_sec'] ?? 340),
            'warmup_km' => (float) ($details['warmup_km'] ?? 2.0),
            'cooldown_km' => (float) ($details['cooldown_km'] ?? 1.5),
            'segments' => $details['segments'] ?? [],
        ];
    }

    private static function applyControlDetails(?array $details, array $paceRules, float $totalKm): array
    {
        if (!$details) {
            return [
                'distance_km' => $totalKm,
                'pace' => self::formatPace($paceRules['tempo_sec'] ?? 300),
                'warmup_km' => 2.0,
                'cooldown_km' => 1.5,
            ];
        }

        return [
            'distance_km' => $totalKm,
            'pace' => self::formatPace($details['pace_sec'] ?? $paceRules['tempo_sec'] ?? 300),
            'warmup_km' => (float) ($details['warmup_km'] ?? 2.0),
            'cooldown_km' => (float) ($details['cooldown_km'] ?? 1.5),
        ];
    }

    private static function calculateDuration(array $day, array $paceRules): array
    {
        $distKm = (float) ($day['distance_km'] ?? 0);
        if ($distKm <= 0) {
            $day['duration_minutes'] = 0;
            return $day;
        }

        $type = $day['type'] ?? 'easy';

        switch ($type) {
            case 'interval':
                $day['duration_minutes'] = self::estimateIntervalDuration($day, $paceRules);
                break;

            case 'tempo':
                $warmup = (float) ($day['warmup_km'] ?? 2.0);
                $cooldown = (float) ($day['cooldown_km'] ?? 1.5);
                $tempoKm = (float) ($day['tempo_km'] ?? max(0, $distKm - $warmup - $cooldown));
                $easyPaceSec = $paceRules['easy_min_sec'] ?? 340;
                // Для race-pace используем фактический темп из поля pace
                $tempoPaceSec = self::parsePaceToSec($day['pace'] ?? null) ?: ($paceRules['tempo_sec'] ?? 300);
                $day['duration_minutes'] = (int) round(
                    ($warmup * $easyPaceSec + $tempoKm * $tempoPaceSec + $cooldown * $easyPaceSec) / 60
                );
                break;

            case 'fartlek':
                // Приблизительно: средний темп между easy и interval
                $avgPaceSec = (($paceRules['easy_min_sec'] ?? 340) + ($paceRules['interval_sec'] ?? 280)) / 2;
                $day['duration_minutes'] = (int) round($distKm * $avgPaceSec / 60);
                break;

            default:
                $paceSec = self::parsePaceToSec($day['pace'] ?? null) ?: ($paceRules['easy_min_sec'] ?? 340);
                $day['duration_minutes'] = (int) round($distKm * $paceSec / 60);
                break;
        }

        return $day;
    }

    private static function estimateIntervalDuration(array $day, array $paceRules): int
    {
        $warmupKm = (float) ($day['warmup_km'] ?? 2.0);
        $cooldownKm = (float) ($day['cooldown_km'] ?? 1.5);
        $reps = (int) ($day['reps'] ?? 4);
        $intervalM = (int) ($day['interval_m'] ?? 1000);
        $restM = (int) ($day['rest_m'] ?? 400);

        $easyPaceSec = $paceRules['easy_min_sec'] ?? 340;
        $intervalPaceSec = $paceRules['interval_sec'] ?? 280;
        $recoveryPaceSec = $paceRules['recovery_min_sec'] ?? ($easyPaceSec + 30);

        $warmupSec = $warmupKm * $easyPaceSec;
        $cooldownSec = $cooldownKm * $easyPaceSec;
        $workSec = $reps * ($intervalM / 1000.0) * $intervalPaceSec;
        $restSec = $reps * ($restM / 1000.0) * $recoveryPaceSec;

        return (int) round(($warmupSec + $workSec + $restSec + $cooldownSec) / 60);
    }

    private static function formatPace(int $seconds): string
    {
        $min = (int) floor($seconds / 60);
        $sec = $seconds % 60;
        return $min . ':' . str_pad((string) $sec, 2, '0', STR_PAD_LEFT);
    }

    private static function parsePaceToSec(?string $pace): ?int
    {
        if ($pace === null || $pace === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($pace), $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        return null;
    }
}
