<?php

require_once __DIR__ . '/StatsService.php';
require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

class TrainingStateBuilder {
    private mysqli $db;
    private StatsService $statsService;

    public function __construct(mysqli $db) {
        $this->db = $db;
        $this->statsService = new StatsService($db);
    }

    public function buildForUserId(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT
                id, goal_type, race_distance, race_date, race_target_time,
                target_marathon_date, target_marathon_time, training_start_date,
                experience_level, weekly_base_km, easy_pace_sec,
                last_race_distance, last_race_distance_km, last_race_time, last_race_date,
                planning_benchmark_distance, planning_benchmark_distance_km, planning_benchmark_time,
                planning_benchmark_date, planning_benchmark_type, planning_benchmark_effort
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return [];
        }

        return $this->buildForUser($user);
    }

    public function buildForUser(array $user): array {
        $userId = (int) ($user['id'] ?? 0);
        $goalType = (string) ($user['goal_type'] ?? 'health');
        $ageYears = $this->computeAgeYears($user['birth_year'] ?? null);
        $healthNotes = trim((string) ($user['health_notes'] ?? ''));
        $preferredDays = !empty($user['preferred_days'])
            ? (is_array($user['preferred_days']) ? $user['preferred_days'] : (json_decode((string) $user['preferred_days'], true) ?: []))
            : [];
        $preferredOfpDays = !empty($user['preferred_ofp_days'])
            ? (is_array($user['preferred_ofp_days']) ? $user['preferred_ofp_days'] : (json_decode((string) $user['preferred_ofp_days'], true) ?: []))
            : [];
        $preferredDays = sortPromptWeekdayKeys($preferredDays);
        $preferredOfpDays = sortPromptWeekdayKeys($preferredOfpDays);
        $targetDistKm = $this->parseDistanceKm($user['race_distance'] ?? null, null);
        $targetTimeSec = $this->parseTimeSec($user['race_target_time'] ?? $user['target_marathon_time'] ?? null);
        $goalPaceSec = ($targetDistKm > 0 && $targetTimeSec > 0)
            ? (int) round($targetTimeSec / $targetDistKm)
            : null;
        $weeksToGoal = $this->computeWeeksToGoal($user, $goalType);

        $vdot = null;
        $vdotSource = null;
        $vdotSourceDetail = null;
        $vdotWeeksOld = null;
        $sourceDistanceKm = null;
        $sourceTimeSec = null;

        $planningBenchmarkDistKm = $this->parseDistanceKm($user['planning_benchmark_distance'] ?? null, $user['planning_benchmark_distance_km'] ?? null);
        $planningBenchmarkTimeSec = $this->parseTimeSec($user['planning_benchmark_time'] ?? null);
        $planningBenchmarkDate = $user['planning_benchmark_date'] ?? null;
        $planningBenchmarkWeeksOld = null;
        if ($planningBenchmarkDate) {
            $planningBenchmarkTs = strtotime($planningBenchmarkDate);
            if ($planningBenchmarkTs !== false) {
                $planningBenchmarkWeeksOld = (time() - $planningBenchmarkTs) / (7 * 86400);
            }
        }
        $planningBenchmarkType = (string) ($user['planning_benchmark_type'] ?? '');
        $planningBenchmarkEffort = (string) ($user['planning_benchmark_effort'] ?? '');
        $planningBenchmarkLooksLikePerformance = $planningBenchmarkType === ''
            || in_array($planningBenchmarkType, ['race', 'control'], true)
            || ($planningBenchmarkType === 'hard_workout' && in_array($planningBenchmarkEffort, ['', 'max', 'hard'], true));
        if (in_array($planningBenchmarkEffort, ['steady', 'easy'], true)) {
            $planningBenchmarkLooksLikePerformance = false;
        }
        if ($planningBenchmarkDistKm > 0 && $planningBenchmarkTimeSec > 0) {
            if ($planningBenchmarkLooksLikePerformance) {
                $vdot = estimateVDOT($planningBenchmarkDistKm, $planningBenchmarkTimeSec);
                $vdotSource = 'benchmark_override';
                $vdotSourceDetail = 'явный ориентир текущей формы от пользователя';
                $vdotWeeksOld = $planningBenchmarkWeeksOld;
                $sourceDistanceKm = $planningBenchmarkDistKm;
                $sourceTimeSec = $planningBenchmarkTimeSec;
            }
        }

        $lastRaceDistKm = $this->parseDistanceKm($user['last_race_distance'] ?? null, $user['last_race_distance_km'] ?? null);
        $lastRaceTimeSec = $this->parseTimeSec($user['last_race_time'] ?? null);
        $lastRaceDate = $user['last_race_date'] ?? null;
        $lastRaceWeeksOld = null;
        if ($lastRaceDate) {
            $lastRaceWeeksOld = (time() - strtotime($lastRaceDate)) / (7 * 86400);
        }

        $hasLastRaceBenchmark = $lastRaceDistKm > 0 && $lastRaceTimeSec > 0;

        if (!$vdot && $hasLastRaceBenchmark && $lastRaceWeeksOld !== null && $lastRaceWeeksOld <= 8) {
            $vdot = estimateVDOT($lastRaceDistKm, $lastRaceTimeSec);
            $vdotSource = 'last_race';
            $vdotSourceDetail = 'свежий результат забега/контрольной';
            $vdotWeeksOld = $lastRaceWeeksOld;
            $sourceDistanceKm = $lastRaceDistKm;
            $sourceTimeSec = $lastRaceTimeSec;
        }

        if (!$vdot && $userId > 0) {
            $best = $this->statsService->getBestResultForVdot($userId, 6, $targetDistKm > 0 ? $targetDistKm : null);
            if ($best) {
                $vdot = (float) $best['vdot'];
                $vdotSource = 'best_result';
                $vdotSourceDetail = $best['vdot_source_detail'] ?? null;
                $sourceDistanceKm = isset($best['distance_km']) ? (float) $best['distance_km'] : null;
                $sourceTimeSec = isset($best['time_sec']) ? (int) $best['time_sec'] : null;
            } elseif ($hasLastRaceBenchmark) {
                $vdot = estimateVDOT($lastRaceDistKm, $lastRaceTimeSec);
                $vdotSource = 'last_race_stale';
                $vdotSourceDetail = 'устаревший race/control fallback';
                $vdotWeeksOld = $lastRaceWeeksOld;
                $sourceDistanceKm = $lastRaceDistKm;
                $sourceTimeSec = $lastRaceTimeSec;
            }
        }

        if (!$vdot && $hasLastRaceBenchmark) {
            $vdot = estimateVDOT($lastRaceDistKm, $lastRaceTimeSec);
            $vdotSource = $lastRaceWeeksOld === null ? 'last_race_undated' : 'last_race_stale';
            $vdotSourceDetail = $lastRaceWeeksOld === null
                ? 'результат последнего забега без даты'
                : 'устаревший race/control fallback';
            $vdotWeeksOld = $lastRaceWeeksOld;
            $sourceDistanceKm = $lastRaceDistKm;
            $sourceTimeSec = $lastRaceTimeSec;
        }

        if (!$vdot && !empty($user['easy_pace_sec'])) {
            $easyPaceSec = (int) $user['easy_pace_sec'];
            if ($easyPaceSec >= 240 && $easyPaceSec <= 540) {
                $easyVelocity = 1000.0 / ($easyPaceSec / 60.0);
                $easyVO2 = _vdotOxygenCost($easyVelocity);
                $vdot = max(20, min(85, round($easyVO2 / 0.65, 1)));
                $vdotSource = 'easy_pace';
                $vdotSourceDetail = 'оценка по комфортному темпу';
            }
        }

        if (!$vdot && $targetDistKm > 0 && $targetTimeSec > 0) {
            $rawVdot = estimateVDOT($targetDistKm, $targetTimeSec);
            $vdot = round($rawVdot * 0.92, 1);
            $vdot = max(20.0, min(85.0, $vdot));
            $vdotSource = 'target_time';
            $vdotSourceDetail = 'слабый fallback по целевому времени (×0.92 — цель ещё не достигнута)';
            $sourceDistanceKm = $targetDistKm;
            $sourceTimeSec = $targetTimeSec;
        }

        $trainingPaces = null;
        $formattedTrainingPaces = null;
        if ($vdot) {
            $trainingPaces = getTrainingPaces($vdot);
            $easyRange = array_map('intval', $trainingPaces['easy']);
            sort($easyRange, SORT_NUMERIC);
            $formattedTrainingPaces = [
                'easy' => formatPaceSec($easyRange[0]) . ' – ' . formatPaceSec($easyRange[1]),
                'marathon' => formatPaceSec($trainingPaces['marathon']),
                'threshold' => formatPaceSec($trainingPaces['threshold']),
                'interval' => formatPaceSec($trainingPaces['interval']),
                'repetition' => formatPaceSec($trainingPaces['repetition']),
            ];
        }

        $daysSinceLastWorkout = $userId > 0 ? $this->getDaysSinceLastWorkout($userId) : null;
        $expLevelForDetraining = $user['experience_level'] ?? 'intermediate';
        $detrainingFactor = $daysSinceLastWorkout !== null ? calculateDetrainingFactor($daysSinceLastWorkout, $expLevelForDetraining) : null;
        $vdotConfidence = $this->computeVdotConfidence($vdotSource, $vdotWeeksOld, $daysSinceLastWorkout);
        $readiness = $this->computeReadiness($daysSinceLastWorkout, $vdotConfidence);
        $specialPopulationFlags = $this->detectSpecialPopulationFlags($ageYears, $healthNotes, $daysSinceLastWorkout, $vdotConfidence);
        $loadPolicy = $this->buildLoadPolicy($user, $goalType, $readiness, $specialPopulationFlags, $weeksToGoal);

        return [
            'goal_type' => $goalType,
            'race_distance' => $user['race_distance'] ?? null,
            'race_date' => $user['race_date'] ?? ($user['target_marathon_date'] ?? null),
            'race_target_time' => $user['race_target_time'] ?? ($user['target_marathon_time'] ?? null),
            'goal_pace_sec' => $goalPaceSec,
            'goal_pace' => $goalPaceSec ? formatPaceSec($goalPaceSec) : null,
            'experience_level' => $user['experience_level'] ?? null,
            'age_years' => $ageYears,
            'sessions_per_week' => isset($user['sessions_per_week']) ? (int) $user['sessions_per_week'] : null,
            'weekly_base_km' => isset($user['weekly_base_km']) ? (float) $user['weekly_base_km'] : null,
            'preferred_days' => $preferredDays,
            'preferred_ofp_days' => $preferredOfpDays,
            'preferred_long_day' => getPreferredLongRunDayKey(['preferred_days' => $preferredDays]),
            'vdot' => $vdot ? round($vdot, 1) : null,
            'vdot_source' => $vdotSource,
            'vdot_source_label' => $this->formatVdotSourceLabel($vdotSource),
            'vdot_source_detail' => $vdotSourceDetail,
            'source_distance_km' => $sourceDistanceKm,
            'source_time_sec' => $sourceTimeSec,
            'vdot_confidence' => $vdotConfidence,
            'vdot_weeks_old' => $vdotWeeksOld !== null ? round($vdotWeeksOld, 1) : null,
            'days_since_last_workout' => $daysSinceLastWorkout,
            'detraining_factor' => $detrainingFactor !== null ? round($detrainingFactor, 2) : null,
            'readiness' => $readiness,
            'weeks_to_goal' => $weeksToGoal,
            'training_paces' => $trainingPaces,
            'formatted_training_paces' => $formattedTrainingPaces,
            'pace_rules' => $this->buildPaceRules($trainingPaces, $user, $vdot),
            'load_policy' => $loadPolicy,
            'special_population_flags' => $specialPopulationFlags,
            'return_to_run_state' => in_array('return_after_break', $specialPopulationFlags, true) || in_array('return_after_injury', $specialPopulationFlags, true)
                ? 'conservative'
                : null,
        ];
    }

    private function buildPaceRules(?array $trainingPaces, array $user, ?float $vdot = null): ?array {
        if ($trainingPaces) {
            $easyRange = array_map('intval', $trainingPaces['easy']);
            sort($easyRange, SORT_NUMERIC);
            $easyMin = $easyRange[0];
            $easyMax = $easyRange[1];
            $longMin = min(600, $easyMin + 10);
            $longMax = min(600, $easyMax + 25);
            $recoveryMin = min(600, $easyMin + 20);
            $recoveryMax = min(600, $easyMax + 35);

            // race_pace_sec: целевой темп забега (из goal_pace или marathon pace)
            $goalPaceSec = $this->computeGoalPaceSec($user);
            $marathonPaceSec = isset($trainingPaces['marathon']) ? (int) $trainingPaces['marathon'] : null;
            $racePaceSec = $goalPaceSec ?? $marathonPaceSec;

            $tempoSec = (int) $trainingPaces['threshold'];
            $intervalSec = (int) $trainingPaces['interval'];
            $repetitionSec = isset($trainingPaces['repetition']) ? (int) $trainingPaces['repetition'] : ($intervalSec - 12);

            // HMP и 10k pace: предпочитаем VDOT-предсказание, fallback на интерполяцию
            if ($vdot !== null && function_exists('predictRaceTime')) {
                $halfTimeSec = predictRaceTime($vdot, 21.0975);
                $halfPaceSec = (int) round($halfTimeSec / 21.0975);
                $tenKTimeSec = predictRaceTime($vdot, 10.0);
                $tenKPaceSec = (int) round($tenKTimeSec / 10.0);
            } else {
                $halfPaceSec = $marathonPaceSec
                    ? (int) round($tempoSec + ($marathonPaceSec - $tempoSec) * 0.35)
                    : (int) round($tempoSec * 1.04);
                $tenKPaceSec = (int) round($tempoSec - ($tempoSec - $intervalSec) * 0.20);
            }

            return [
                'easy_min_sec' => $easyMin,
                'easy_max_sec' => $easyMax,
                'long_min_sec' => $longMin,
                'long_max_sec' => $longMax,
                'tempo_sec' => $tempoSec,
                'tempo_tolerance_sec' => 8,
                'interval_sec' => $intervalSec,
                'interval_tolerance_sec' => 8,
                'recovery_min_sec' => $recoveryMin,
                'recovery_max_sec' => $recoveryMax,
                'marathon_sec' => $marathonPaceSec,
                'race_pace_sec' => $racePaceSec,
                'repetition_sec' => $repetitionSec,
                'half_pace_sec' => $halfPaceSec,
                'ten_k_pace_sec' => $tenKPaceSec,
            ];
        }

        $fallbackZones = calculatePaceZones($user);
        if (!$fallbackZones) {
            return null;
        }

        $goalPaceSec = $this->computeGoalPaceSec($user);

        return [
            'easy_min_sec' => max(150, (int) $fallbackZones['easy'] - 10),
            'easy_max_sec' => min(600, (int) $fallbackZones['easy'] + 10),
            'long_min_sec' => max(150, (int) $fallbackZones['long'] - 10),
            'long_max_sec' => min(600, (int) $fallbackZones['long'] + 10),
            'tempo_sec' => (int) $fallbackZones['tempo'],
            'tempo_tolerance_sec' => 10,
            'interval_sec' => (int) $fallbackZones['interval'],
            'interval_tolerance_sec' => 10,
            'recovery_min_sec' => max(150, (int) $fallbackZones['recovery'] - 10),
            'recovery_max_sec' => min(600, (int) $fallbackZones['recovery'] + 10),
            'marathon_sec' => null,
            'race_pace_sec' => $goalPaceSec,
        ];
    }

    private function computeWeeksToGoal(array $user, string $goalType): ?int {
        $goalDate = null;
        if (in_array($goalType, ['race', 'time_improvement'], true)) {
            $goalDate = $user['race_date'] ?? $user['target_marathon_date'] ?? null;
        } elseif ($goalType === 'weight_loss') {
            $goalDate = $user['weight_goal_date'] ?? null;
        }

        if (!$goalDate) {
            return null;
        }

        $goalTs = strtotime($goalDate);
        if (!$goalTs) {
            return null;
        }

        return max(0, (int) ceil(($goalTs - time()) / (7 * 86400)));
    }

    private function computeVdotConfidence(?string $source, ?float $weeksOld, ?int $daysSinceLastWorkout): string {
        $confidence = match ($source) {
            'last_race' => 'high',
            'benchmark_override' => 'high',
            'best_result' => 'medium',
            'last_race_stale' => 'medium',
            'last_race_undated' => 'medium',
            'easy_pace' => 'low',
            'target_time' => 'low',
            default => 'low',
        };

        if ($source === 'benchmark_override' && $weeksOld !== null && $weeksOld > 8 && $confidence === 'high') {
            $confidence = 'medium';
        }

        if ($weeksOld !== null && $weeksOld > 12 && $confidence === 'medium') {
            $confidence = 'low';
        }

        if ($daysSinceLastWorkout !== null && $daysSinceLastWorkout > 14) {
            $confidence = $confidence === 'high' ? 'medium' : 'low';
        }

        return $confidence;
    }

    private function computeReadiness(?int $daysSinceLastWorkout, string $vdotConfidence): string {
        if ($daysSinceLastWorkout === null) {
            return $vdotConfidence === 'high' ? 'normal' : 'low';
        }
        if ($daysSinceLastWorkout <= 3 && $vdotConfidence === 'high') {
            return 'high';
        }
        if ($daysSinceLastWorkout <= 10 && $vdotConfidence !== 'low') {
            return 'normal';
        }
        return 'low';
    }

    private function buildLoadPolicy(array $user, string $goalType, string $readiness, array $specialPopulationFlags = [], ?int $weeksToGoal = null): array {
        $allowedGrowthRatio = match ($readiness) {
            'low' => 1.08,
            'high' => 1.12,
            default => 1.10,
        };

        $raceDistance = (string) ($user['race_distance'] ?? '');
        $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        $macrocycle = in_array($goalType, ['race', 'time_improvement'], true)
            ? computeMacrocycle($user, $goalType)
            : computeHealthMacrocycle($user, $goalType);

        $weeklyBaseKm = isset($user['weekly_base_km']) ? (float) $user['weekly_base_km'] : 0.0;
        $isLowBase = $weeklyBaseKm > 0 ? ($weeklyBaseKm <= 15.0) : ((int) ($user['sessions_per_week'] ?? 0) <= 4);
        $isFirstRaceAtDistance = !empty($user['is_first_race_at_distance']);
        $isFirstLongRace = $isFirstRaceAtDistance && in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        $requestedEasyMinKm = isset($user['planning_easy_min_km']) ? (float) $user['planning_easy_min_km'] : 0.0;
        $hasConservativeFlag = count(array_intersect($specialPopulationFlags, [
            'pregnant_or_postpartum',
            'return_after_injury',
            'return_after_break',
            'older_adult_65_plus',
            'chronic_condition_flag',
        ])) > 0;
        $useConservativeRepairProfile = $readiness === 'low' && (
            $isLowBase
            || $isFirstLongRace
            || $goalType === 'weight_loss'
            || $hasConservativeFlag
        );
        $useExplicitEasyFloor = !$useConservativeRepairProfile
            && in_array($goalType, ['race', 'time_improvement'], true)
            && in_array($raceDistance, ['marathon', '42.2k'], true)
            && $requestedEasyMinKm >= 6.0
            && ($weeksToGoal === null || $weeksToGoal <= 10);

        $policy = [
            'allowed_growth_ratio' => $allowedGrowthRatio,
            'recovery_cutback_ratio' => 0.88,
            'race_week_ratio' => $isLongRace ? 0.85 : 1.00,
            'pre_race_taper_ratio' => $isLongRace ? 0.92 : 1.00,
            'race_week_supplementary_ratio' => match ($raceDistance) {
                'marathon', '42.2k' => 0.35,
                'half', '21.1k' => 0.45,
                default => 0.60,
            },
            'repair_floor_profile' => $useConservativeRepairProfile ? 'conservative' : 'standard',
            'easy_floor_ratio' => $useConservativeRepairProfile ? 0.40 : 0.50,
            'easy_min_km' => $useConservativeRepairProfile ? 1.5 : 2.0,
            'long_floor_ratio' => $useConservativeRepairProfile ? 0.67 : 0.85,
            'long_min_km' => $useConservativeRepairProfile ? 5.0 : 6.0,
            'tempo_floor_ratio' => $useConservativeRepairProfile ? 0.60 : 0.75,
            'tempo_min_km' => $useConservativeRepairProfile ? 2.5 : 3.0,
            'complex_floor_ratio' => $useConservativeRepairProfile ? 0.55 : 0.70,
            'complex_min_km' => $useConservativeRepairProfile ? 3.5 : 6.0,
            'recovery_weeks' => [],
            'weekly_volume_targets_km' => [],
            'long_run_targets_km' => [],
            'start_volume_km' => null,
            'peak_volume_km' => null,
            'easy_build_min_km' => $useExplicitEasyFloor ? round(max($requestedEasyMinKm, 6.0), 1) : null,
            'easy_recovery_min_km' => $useExplicitEasyFloor ? round(max(6.0, min($requestedEasyMinKm, $requestedEasyMinKm - 2.0)), 1) : null,
            'easy_taper_min_km' => $useExplicitEasyFloor ? round(max(4.0, min(8.0, $requestedEasyMinKm - 2.0)), 1) : null,
        ];

        if (!$macrocycle || !is_array($macrocycle)) {
            return $policy;
        }

        $policy['recovery_weeks'] = array_map('intval', $macrocycle['recovery_weeks'] ?? []);
        $policy['weekly_volume_targets_km'] = $this->buildWeeklyVolumeTargets($macrocycle, $goalType, $raceDistance);
        $policy['long_run_targets_km'] = array_map(
            static fn($km): float => round((float) $km, 1),
            $macrocycle['long_run']['by_week'] ?? []
        );
        $policy['start_volume_km'] = isset($macrocycle['start_volume_km']) ? (float) $macrocycle['start_volume_km'] : null;
        $policy['peak_volume_km'] = isset($macrocycle['peak_volume_km']) ? (float) $macrocycle['peak_volume_km'] : null;

        return $policy;
    }

    private function buildWeeklyVolumeTargets(array $macrocycle, string $goalType, string $raceDistance): array {
        $totalWeeks = (int) ($macrocycle['total_weeks'] ?? 0);
        $startVolume = isset($macrocycle['start_volume_km']) ? (float) $macrocycle['start_volume_km'] : 0.0;
        $peakVolume = isset($macrocycle['peak_volume_km']) ? (float) $macrocycle['peak_volume_km'] : $startVolume;
        if ($totalWeeks < 1 || $startVolume <= 0 || $peakVolume <= 0) {
            return [];
        }

        $targets = [];
        $recoveryWeeks = array_map('intval', $macrocycle['recovery_weeks'] ?? []);
        $peakWeek = max(1, min($totalWeeks, (int) ($macrocycle['long_run']['peak_week'] ?? $totalWeeks)));
        $taperFromWeek = $totalWeeks + 1;
        foreach (($macrocycle['phases'] ?? []) as $phase) {
            if (($phase['name'] ?? null) === 'taper') {
                $taperFromWeek = (int) ($phase['weeks_from'] ?? ($totalWeeks + 1));
                break;
            }
        }

        $taperWeeks = $taperFromWeek <= $totalWeeks ? ($totalWeeks - $taperFromWeek + 1) : 0;
        $taperRatios = $this->resolveTaperRatios($goalType, $raceDistance, $taperWeeks);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            if ($week >= $taperFromWeek && $taperWeeks > 0) {
                $taperIndex = $week - $taperFromWeek;
                $ratio = $taperRatios[min($taperIndex, count($taperRatios) - 1)] ?? end($taperRatios);
                $target = $peakVolume * $ratio;
            } else {
                $progress = $peakWeek <= 1 ? 1.0 : min(1.0, ($week - 1) / max(1, $peakWeek - 1));
                $curve = pow($progress, 0.92);
                $target = $startVolume + (($peakVolume - $startVolume) * $curve);
                if ($week > $peakWeek) {
                    $target = $peakVolume * max(0.92, 1.0 - (0.04 * ($week - $peakWeek)));
                }
            }

            if (in_array($week, $recoveryWeeks, true)) {
                $target *= 0.82;
            }

            $targets[$week] = round(max(1.0, $target), 1);
        }

        return $targets;
    }

    private function resolveTaperRatios(string $goalType, string $raceDistance, int $taperWeeks): array {
        if ($taperWeeks < 1) {
            return [];
        }

        if (!in_array($goalType, ['race', 'time_improvement'], true)) {
            return array_fill(0, $taperWeeks, 0.90);
        }

        $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        if ($isLongRace) {
            return match ($taperWeeks) {
                1 => [0.55],
                2 => [0.75, 0.55],
                default => [0.82, 0.68, 0.52],
            };
        }

        return match ($taperWeeks) {
            1 => [0.70],
            2 => [0.85, 0.70],
            default => [0.90, 0.78, 0.66],
        };
    }

    private function formatVdotSourceLabel(?string $source): string {
        return match ($source) {
            'benchmark_override' => 'явный ориентир формы от пользователя',
            'last_race' => 'свежий забег/контрольная',
            'best_result' => 'лучшие свежие тренировки',
            'last_race_stale' => 'устаревший результат забега',
            'last_race_undated' => 'последний забег без даты',
            'easy_pace' => 'оценка по лёгкому темпу',
            'target_time' => 'оценка по целевому времени',
            default => 'нет надёжного источника',
        };
    }

    private function getDaysSinceLastWorkout(int $userId): ?int {
        $maxTs = null;

        $stmt = $this->db->prepare("SELECT MAX(training_date) AS last_date FROM workout_log WHERE user_id = ? AND is_completed = 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['last_date'])) {
                $maxTs = strtotime($row['last_date']);
            }
        }

        $stmt = $this->db->prepare("SELECT MAX(DATE(start_time)) AS last_date FROM workouts WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['last_date'])) {
                $autoTs = strtotime($row['last_date']);
                if ($autoTs && ($maxTs === null || $autoTs > $maxTs)) {
                    $maxTs = $autoTs;
                }
            }
        }

        if ($maxTs === null) {
            return null;
        }

        return max(0, (int) floor((time() - $maxTs) / 86400));
    }

    private function computeAgeYears($birthYear): ?int {
        if ($birthYear === null || $birthYear === '') {
            return null;
        }

        $year = (int) $birthYear;
        $currentYear = (int) gmdate('Y');
        if ($year < 1900 || $year > $currentYear) {
            return null;
        }

        return $currentYear - $year;
    }

    private function detectSpecialPopulationFlags(?int $ageYears, string $healthNotes, ?int $daysSinceLastWorkout, string $vdotConfidence): array {
        $flags = [];
        $notes = mb_strtolower($healthNotes);

        if ($ageYears !== null && $ageYears >= 65) {
            $flags[] = 'older_adult_65_plus';
        }

        if ($daysSinceLastWorkout !== null && $daysSinceLastWorkout > 14) {
            $flags[] = 'return_after_break';
        }

        if ($vdotConfidence === 'low') {
            $flags[] = 'low_confidence_vdot';
        }

        if ($notes !== '') {
            if (preg_match('/беремен|беременн|послеродов|postpartum|pregnan/u', $notes)) {
                $flags[] = 'pregnant_or_postpartum';
            }

            if (preg_match('/травм|перелом|болит|боль|колен|ахилл|голен|shin|injur|stress fracture|plantar|hamstring/u', $notes)) {
                $flags[] = 'return_after_injury';
            }

            if (preg_match('/астм|диабет|гиперт|давлен|сердц|аритм|хрон|thyroid|autoimmune|copd/u', $notes)) {
                $flags[] = 'chronic_condition_flag';
            }
        }

        return array_values(array_unique($flags));
    }

    private function computeGoalPaceSec(array $user): ?int {
        $targetDistKm = $this->parseDistanceKm($user['race_distance'] ?? null, null);
        $targetTimeSec = $this->parseTimeSec($user['race_target_time'] ?? $user['target_marathon_time'] ?? null);
        if ($targetDistKm > 0 && $targetTimeSec > 0) {
            return (int) round($targetTimeSec / $targetDistKm);
        }
        return null;
    }

    private function parseDistanceKm(?string $distance, ?string $distanceKm): float {
        if ($distanceKm !== null && $distanceKm !== '' && (float) $distanceKm > 0) {
            return (float) $distanceKm;
        }
        if (!$distance) {
            return 0;
        }

        $map = [
            '1k' => 1.0,
            '1500m' => 1.5,
            '1_mile' => 1.60934,
            '3k' => 3.0,
            '5k' => 5.0,
            '10k' => 10.0,
            'half' => 21.0975,
            '21.1k' => 21.0975,
            'marathon' => 42.195,
            '42.2k' => 42.195,
            '50k' => 50.0,
            '100k' => 100.0,
        ];

        return $map[$distance] ?? 0;
    }

    private function parseTimeSec(?string $time): int {
        if (!$time) {
            return 0;
        }

        $parts = array_map('intval', explode(':', trim($time)));
        if (count($parts) === 3) {
            if ($parts[0] > 20 && $parts[1] < 60 && $parts[2] < 60) {
                $asMinSec = $parts[0] * 60 + $parts[1];
                if ($asMinSec < 7200) {
                    return $asMinSec;
                }
            }
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }
        return (int) $time;
    }
}
