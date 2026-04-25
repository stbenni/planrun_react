<?php

require_once __DIR__ . '/StatsService.php';
require_once __DIR__ . '/PostWorkoutFollowupService.php';
require_once __DIR__ . '/AthleteSignalsService.php';
require_once __DIR__ . '/../planrun_ai/prompt_builder.php';
require_once __DIR__ . '/../repositories/WorkoutRepository.php';

class TrainingStateBuilder {
    private mysqli $db;
    private StatsService $statsService;
    private PostWorkoutFollowupService $postWorkoutFollowupService;
    private AthleteSignalsService $athleteSignalsService;
    private ?WorkoutRepository $workoutRepo = null;

    public function __construct(mysqli $db) {
        $this->db = $db;
        $this->statsService = new StatsService($db);
        $this->postWorkoutFollowupService = new PostWorkoutFollowupService($db);
        $this->athleteSignalsService = new AthleteSignalsService($db);
    }

    public function buildForUserId(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT
                id, goal_type, race_distance, race_date, race_target_time,
                target_marathon_date, target_marathon_time, training_start_date,
                experience_level, weekly_base_km, easy_pace_sec,
                last_race_distance, last_race_distance_km, last_race_time, last_race_date
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
        if ($planningBenchmarkDistKm > 0 && $planningBenchmarkTimeSec > 0) {
            $vdot = estimateVDOT($planningBenchmarkDistKm, $planningBenchmarkTimeSec);
            $vdotSource = 'benchmark_override';
            $vdotSourceDetail = 'явный ориентир текущей формы из причины пересчёта';
            $vdotWeeksOld = 0.0;
            $sourceDistanceKm = $planningBenchmarkDistKm;
            $sourceTimeSec = $planningBenchmarkTimeSec;
        }

        $lastRaceDistKm = $this->parseDistanceKm($user['last_race_distance'] ?? null, $user['last_race_distance_km'] ?? null);
        $lastRaceTimeSec = $this->parseTimeSec($user['last_race_time'] ?? null);
        $lastRaceDate = $user['last_race_date'] ?? null;
        $lastRaceWeeksOld = null;
        if ($lastRaceDate) {
            $lastRaceWeeksOld = (time() - strtotime($lastRaceDate)) / (7 * 86400);
        }

        if (!$vdot && $lastRaceDistKm > 0 && $lastRaceTimeSec > 0 && $lastRaceWeeksOld !== null && $lastRaceWeeksOld <= 8) {
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
            } elseif ($lastRaceDistKm > 0 && $lastRaceTimeSec > 0) {
                $vdot = estimateVDOT($lastRaceDistKm, $lastRaceTimeSec);
                $vdotSource = 'last_race_stale';
                $vdotSourceDetail = 'устаревший race/control fallback';
                $vdotWeeksOld = $lastRaceWeeksOld;
                $sourceDistanceKm = $lastRaceDistKm;
                $sourceTimeSec = $lastRaceTimeSec;
            }
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
        $athleteSignals = $userId > 0 ? $this->getRecentAthleteSignals($userId) : [];
        $feedbackAnalytics = is_array($athleteSignals['feedback'] ?? null) ? $athleteSignals['feedback'] : [];
        $expLevelForDetraining = $user['experience_level'] ?? 'intermediate';
        $detrainingFactor = $daysSinceLastWorkout !== null ? calculateDetrainingFactor($daysSinceLastWorkout, $expLevelForDetraining) : null;
        $reportedWeeklyBaseKm = isset($user['weekly_base_km']) ? (float) $user['weekly_base_km'] : 0.0;
        $effectiveWeeklyBaseKm = $this->resolveEffectiveWeeklyBaseKm($reportedWeeklyBaseKm, $daysSinceLastWorkout, $detrainingFactor);
        $vdotConfidence = $this->computeVdotConfidence($vdotSource, $vdotWeeksOld, $daysSinceLastWorkout);
        $readiness = $this->computeReadiness($daysSinceLastWorkout, $vdotConfidence, $feedbackAnalytics, $athleteSignals);
        $specialPopulationFlags = $this->detectSpecialPopulationFlags($ageYears, $healthNotes, $daysSinceLastWorkout, $vdotConfidence, $feedbackAnalytics, $athleteSignals);
        $effectiveUser = $user;
        $effectiveUser['weekly_base_km'] = $effectiveWeeklyBaseKm;
        $loadPolicy = $this->buildLoadPolicy($effectiveUser, $goalType, $readiness, $specialPopulationFlags, $weeksToGoal, $feedbackAnalytics, $athleteSignals);

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
            'weekly_base_km' => $effectiveWeeklyBaseKm,
            'reported_weekly_base_km' => $reportedWeeklyBaseKm > 0 ? round($reportedWeeklyBaseKm, 1) : null,
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
            'feedback_analytics' => $feedbackAnalytics,
            'athlete_signals' => $athleteSignals,
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

        $startTs = !empty($user['training_start_date'])
            ? strtotime((string) $user['training_start_date'])
            : time();
        if (!$startTs) {
            $startTs = time();
        }

        return max(0, (int) ceil(($goalTs - $startTs) / (7 * 86400)));
    }

    private function computeVdotConfidence(?string $source, ?float $weeksOld, ?int $daysSinceLastWorkout): string {
        $confidence = match ($source) {
            'last_race' => 'high',
            'benchmark_override' => 'high',
            'best_result' => 'medium',
            'last_race_stale' => 'medium',
            'easy_pace' => 'low',
            'target_time' => 'low',
            default => 'low',
        };

        if ($weeksOld !== null && $weeksOld > 12 && $confidence === 'medium') {
            $confidence = 'low';
        }

        if ($daysSinceLastWorkout !== null && $daysSinceLastWorkout > 14) {
            $confidence = $confidence === 'high' ? 'medium' : 'low';
        }

        return $confidence;
    }

    private function computeReadiness(?int $daysSinceLastWorkout, string $vdotConfidence, array $feedbackAnalytics = [], array $athleteSignals = []): string {
        $baseReadiness = $this->computeBaseReadiness($daysSinceLastWorkout, $vdotConfidence);
        $painSignals = !empty($feedbackAnalytics['has_recent_pain']);
        $fatigueCount = (int) ($feedbackAnalytics['fatigue_flag_count'] ?? 0);
        $recentRisk = (float) ($feedbackAnalytics['recent_average_recovery_risk'] ?? 0.0);
        $maxRisk = (float) ($feedbackAnalytics['max_recovery_risk'] ?? 0.0);
        $recentPainScore = (float) ($feedbackAnalytics['recent_pain_score_avg'] ?? 0.0);
        $painScoreDelta = (float) ($feedbackAnalytics['pain_score_delta'] ?? 0.0);
        $recentSessionRpe = (float) ($feedbackAnalytics['recent_session_rpe_avg'] ?? 0.0);
        $sessionRpeDelta = (float) ($feedbackAnalytics['session_rpe_delta'] ?? 0.0);
        $subjectiveLoadDelta = (float) ($feedbackAnalytics['subjective_load_delta'] ?? 0.0);
        $recentLegsScore = (float) ($feedbackAnalytics['recent_legs_score_avg'] ?? 0.0);
        $recentBreathScore = (float) ($feedbackAnalytics['recent_breath_score_avg'] ?? 0.0);
        $recentHrStrainScore = (float) ($feedbackAnalytics['recent_hr_strain_score_avg'] ?? 0.0);
        $notePainSignals = !empty($athleteSignals['has_note_pain_signal']);
        $noteIllnessSignals = !empty($athleteSignals['has_note_illness_signal']);
        $noteSleepSignals = !empty($athleteSignals['has_note_sleep_signal']);
        $noteStressSignals = !empty($athleteSignals['has_note_stress_signal']);
        $noteTravelSignals = !empty($athleteSignals['has_note_travel_signal']);
        $noteRiskScore = (float) ($athleteSignals['note_risk_score'] ?? 0.0);

        if (
            $painSignals
            || $notePainSignals
            || $noteIllnessSignals
            || $recentRisk >= 0.75
            || $maxRisk >= 0.90
            || $recentPainScore >= 4.0
            || $painScoreDelta >= 2.0
            || $noteRiskScore >= 0.80
            || $fatigueCount >= 3
            || ($fatigueCount >= 2 && ($recentRisk >= 0.65 || $subjectiveLoadDelta >= 0.75 || $recentSessionRpe >= 8.0))
        ) {
            return 'low';
        }

        $moderateSignals = 0;
        if ($fatigueCount >= 1) {
            $moderateSignals++;
        }
        if ($recentRisk >= 0.45 || $maxRisk >= 0.65) {
            $moderateSignals++;
        }
        if ($subjectiveLoadDelta >= 0.45) {
            $moderateSignals++;
        }
        if ($sessionRpeDelta >= 1.0 || $recentSessionRpe >= 7.5) {
            $moderateSignals++;
        }
        if ($recentLegsScore >= 8.0 || $recentBreathScore >= 8.0 || $recentHrStrainScore >= 8.0) {
            $moderateSignals++;
        }
        if ($noteSleepSignals || $noteStressSignals || $noteTravelSignals) {
            $moderateSignals++;
        }
        // note_risk_score already represents note-only context; overall_risk_score can
        // duplicate the same subjective feedback that is counted above via recentRisk /
        // fatigue / RPE, which over-penalizes taper scenarios.
        if ($noteRiskScore >= 0.45) {
            $moderateSignals++;
        }

        if ($moderateSignals >= 3) {
            return 'low';
        }

        if ($moderateSignals >= 1) {
            return $this->downgradeReadiness($baseReadiness);
        }

        return $baseReadiness;
    }

    private function computeBaseReadiness(?int $daysSinceLastWorkout, string $vdotConfidence): string {
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

    private function downgradeReadiness(string $readiness): string {
        return match ($readiness) {
            'high' => 'normal',
            'normal' => 'low',
            default => 'low',
        };
    }

    private function buildLoadPolicy(
        array $user,
        string $goalType,
        string $readiness,
        array $specialPopulationFlags = [],
        ?int $weeksToGoal = null,
        array $feedbackAnalytics = [],
        array $athleteSignals = []
    ): array {
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
        $sessionsPerWeek = (int) ($user['sessions_per_week'] ?? 0);
        $isLowBase = $weeklyBaseKm > 0 ? ($weeklyBaseKm <= 15.0) : ($sessionsPerWeek <= 4);
        $experienceLevel = strtolower((string) ($user['experience_level'] ?? ''));
        $isNoviceExperience = in_array($experienceLevel, ['novice', 'beginner'], true);
        $isFirstRaceAtDistance = !empty($user['is_first_race_at_distance']);
        $isFirstLongRace = $isFirstRaceAtDistance && in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        $requestedEasyMinKm = isset($user['planning_easy_min_km']) ? (float) $user['planning_easy_min_km'] : 0.0;
        $hasConservativeFlag = count(array_intersect($specialPopulationFlags, [
            'pregnant_or_postpartum',
            'return_after_injury',
            'return_after_break',
            'older_adult_65_plus',
            'chronic_condition_flag',
            'recent_pain_signal',
            'recent_fatigue_spike',
            'recent_illness_signal',
            'recent_sleep_signal',
            'recent_stress_signal',
            'recent_travel_signal',
        ])) > 0;
        $recentRisk = (float) ($feedbackAnalytics['recent_average_recovery_risk'] ?? 0.0);
        $fatigueCount = (int) ($feedbackAnalytics['fatigue_flag_count'] ?? 0);
        $subjectiveLoadDelta = (float) ($feedbackAnalytics['subjective_load_delta'] ?? 0.0);
        $sessionRpeDelta = (float) ($feedbackAnalytics['session_rpe_delta'] ?? 0.0);
        $recentSessionRpe = (float) ($feedbackAnalytics['recent_session_rpe_avg'] ?? 0.0);
        $highSubjectiveLoad = $this->hasHighSubjectiveLoad($feedbackAnalytics);
        $moderateSubjectiveLoad = $this->hasModerateSubjectiveLoad($feedbackAnalytics);
        $noteRiskScore = (float) ($athleteSignals['note_risk_score'] ?? 0.0);
        $hasIllnessSignal = in_array('recent_illness_signal', $specialPopulationFlags, true);
        $hasRecoveryStressSignal = !empty(array_intersect($specialPopulationFlags, [
            'recent_sleep_signal',
            'recent_stress_signal',
            'recent_travel_signal',
        ]));
        if ($hasIllnessSignal || in_array('recent_pain_signal', $specialPopulationFlags, true)) {
            $allowedGrowthRatio = min($allowedGrowthRatio, 1.05);
        } elseif ($highSubjectiveLoad || $fatigueCount >= 2 || $recentRisk >= 0.55 || $noteRiskScore >= 0.65) {
            $allowedGrowthRatio = min($allowedGrowthRatio, 1.06);
        } elseif (
            $moderateSubjectiveLoad
            || $hasRecoveryStressSignal
            || $fatigueCount >= 1
            || $recentRisk >= 0.45
            || $noteRiskScore >= 0.45
            || $subjectiveLoadDelta >= 0.45
            || $sessionRpeDelta >= 1.0
            || $recentSessionRpe >= 7.5
        ) {
            $allowedGrowthRatio = min($allowedGrowthRatio, 1.08);
        }
        $highFeedbackGuard = $highSubjectiveLoad
            || ($fatigueCount >= 2 && ($recentRisk >= 0.65 || $subjectiveLoadDelta >= 0.75 || $recentSessionRpe >= 8.0))
            || $recentRisk >= 0.70
            || $noteRiskScore >= 0.75;
        $moderateFeedbackGuard = $moderateSubjectiveLoad
            || $hasRecoveryStressSignal
            || $fatigueCount >= 1
            || $recentRisk >= 0.45
            || $noteRiskScore >= 0.45;
        $useConservativeRepairProfile = $readiness === 'low' && (
            $isLowBase
            || $isFirstLongRace
            || $goalType === 'weight_loss'
            || $hasConservativeFlag
        );
        $protectLowBaseNovice = $isLowBase
            && $isNoviceExperience
            && !$isLongRace
            && (
                $readiness === 'low'
                || $weeklyBaseKm <= 8.0
                || in_array('low_confidence_vdot', $specialPopulationFlags, true)
            );
        $useExplicitEasyFloor = !$useConservativeRepairProfile
            && in_array($goalType, ['race', 'time_improvement'], true)
            && in_array($raceDistance, ['marathon', '42.2k'], true)
            && $requestedEasyMinKm >= 6.0
            && ($weeksToGoal === null || $weeksToGoal <= 10);

        $longShareCap = $useConservativeRepairProfile || $protectLowBaseNovice
            ? 0.40
            : (($isLowBase || $sessionsPerWeek <= 3 || in_array($goalType, ['health', 'weight_loss'], true)) ? 0.43 : 0.45);
        $longMinKm = $protectLowBaseNovice ? 3.0 : ($useConservativeRepairProfile ? 4.0 : 5.0);

        $policy = [
            'allowed_growth_ratio' => $allowedGrowthRatio,
            'recovery_cutback_ratio' => $hasIllnessSignal
                ? 0.78
                : (in_array('recent_pain_signal', $specialPopulationFlags, true)
                ? 0.82
                : ($highSubjectiveLoad ? 0.84 : ($moderateSubjectiveLoad ? 0.86 : 0.88))),
            'race_week_ratio' => $isLongRace ? 0.85 : 1.00,
            'pre_race_taper_ratio' => $isLongRace ? 0.92 : 1.00,
            'race_week_supplementary_ratio' => $protectLowBaseNovice ? 0.30 : match ($raceDistance) {
                'marathon', '42.2k' => 0.35,
                'half', '21.1k' => 0.45,
                default => 0.60,
            },
            'protect_low_base_novice' => $protectLowBaseNovice,
            'pre_threshold_volume_km' => $protectLowBaseNovice ? 8.0 : 0.0,
            'pre_threshold_absolute_growth_km' => $protectLowBaseNovice ? 1.5 : 0.0,
            'repair_floor_profile' => $useConservativeRepairProfile ? 'conservative' : 'standard',
            'easy_floor_ratio' => $useConservativeRepairProfile ? 0.40 : 0.50,
            'easy_min_km' => $useConservativeRepairProfile ? 1.5 : 2.0,
            'long_floor_ratio' => $useConservativeRepairProfile ? 0.67 : 0.85,
            'long_min_km' => $longMinKm,
            'long_share_cap' => $longShareCap,
            'min_long_over_easy_km' => $protectLowBaseNovice ? 0.8 : 0.5,
            'tempo_floor_ratio' => $useConservativeRepairProfile ? 0.60 : 0.75,
            'tempo_min_km' => $useConservativeRepairProfile ? 2.5 : 3.0,
            'complex_floor_ratio' => $useConservativeRepairProfile ? 0.55 : 0.70,
            'complex_min_km' => $useConservativeRepairProfile ? 3.5 : 6.0,
            'quality_delay_weeks' => $protectLowBaseNovice ? 4 : 0,
            'quality_session_min_km' => $protectLowBaseNovice ? 4.5 : ($useConservativeRepairProfile ? 4.0 : 5.5),
            'quality_workout_share_cap' => $protectLowBaseNovice ? 0.38 : ($useConservativeRepairProfile ? 0.44 : 0.50),
            'race_week_run_day_cap' => $protectLowBaseNovice ? 3 : 0,
            'post_goal_race_run_day_cap' => $protectLowBaseNovice ? 2 : 3,
            'recovery_weeks' => [],
            'weekly_volume_targets_km' => [],
            'long_run_targets_km' => [],
            'start_volume_km' => null,
            'peak_volume_km' => null,
            'easy_build_min_km' => $useExplicitEasyFloor ? round(max($requestedEasyMinKm, 6.0), 1) : null,
            'easy_recovery_min_km' => $useExplicitEasyFloor ? round(max(6.0, min($requestedEasyMinKm, $requestedEasyMinKm - 2.0)), 1) : null,
            'easy_taper_min_km' => $useExplicitEasyFloor ? round(max(4.0, min(8.0, $requestedEasyMinKm - 2.0)), 1) : null,
            'feedback_guard_level' => $hasIllnessSignal
                ? 'illness_protective'
                : (in_array('recent_pain_signal', $specialPopulationFlags, true)
                ? 'pain_protective'
                : ($highFeedbackGuard ? 'fatigue_high' : ($moderateFeedbackGuard ? 'fatigue_moderate' : 'neutral'))),
        ];

        if (!$macrocycle || !is_array($macrocycle)) {
            return $policy;
        }

        $policy['recovery_weeks'] = array_map('intval', $macrocycle['recovery_weeks'] ?? []);
        $policy['weekly_volume_targets_km'] = $this->buildWeeklyVolumeTargets(
            $macrocycle,
            $goalType,
            $raceDistance,
            $allowedGrowthRatio,
            (float) $policy['recovery_cutback_ratio']
        );
        $policy['long_run_targets_km'] = array_map(
            static fn($km): float => round((float) $km, 1),
            $macrocycle['long_run']['by_week'] ?? []
        );
        $policy['start_volume_km'] = isset($macrocycle['start_volume_km']) ? (float) $macrocycle['start_volume_km'] : null;
        $policy['peak_volume_km'] = isset($macrocycle['peak_volume_km']) ? (float) $macrocycle['peak_volume_km'] : null;

        return $policy;
    }

    private function buildWeeklyVolumeTargets(
        array $macrocycle,
        string $goalType,
        string $raceDistance,
        float $allowedGrowthRatio = 1.10,
        float $recoveryCutbackRatio = 0.88
    ): array {
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
                $target *= $recoveryCutbackRatio;
            }

            $targets[$week] = round(max(1.0, $target), 1);
        }

        $lastNormalTarget = null;
        for ($week = 1; $week <= $totalWeeks; $week++) {
            if (!isset($targets[$week])) {
                continue;
            }

            $isRecovery = in_array($week, $recoveryWeeks, true);
            $isTaper = $week >= $taperFromWeek;
            if (!$isRecovery && !$isTaper && $lastNormalTarget !== null) {
                $maxAllowed = round($lastNormalTarget * $allowedGrowthRatio, 1);
                if ($targets[$week] > $maxAllowed) {
                    $targets[$week] = $maxAllowed;
                }
            }

            if (!$isRecovery && !$isTaper) {
                $lastNormalTarget = $targets[$week];
            }
        }

        for ($week = 1; $week <= $totalWeeks; $week++) {
            if (!in_array($week, $recoveryWeeks, true) || $week <= 1) {
                continue;
            }

            $prevNormal = null;
            for ($prevWeek = $week - 1; $prevWeek >= 1; $prevWeek--) {
                if (!in_array($prevWeek, $recoveryWeeks, true)) {
                    $prevNormal = $targets[$prevWeek] ?? null;
                    break;
                }
            }

            if ($prevNormal !== null) {
                $targets[$week] = round((float) $prevNormal * $recoveryCutbackRatio, 1);
            }
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
            'easy_pace' => 'оценка по лёгкому темпу',
            'target_time' => 'оценка по целевому времени',
            default => 'нет надёжного источника',
        };
    }

    private function workoutRepo(): WorkoutRepository {
        return $this->workoutRepo ??= new WorkoutRepository($this->db);
    }

    private function resolveEffectiveWeeklyBaseKm(
        float $reportedWeeklyBaseKm,
        ?int $daysSinceLastWorkout,
        ?float $detrainingFactor
    ): float {
        if ($reportedWeeklyBaseKm <= 0) {
            return 0.0;
        }

        if ($daysSinceLastWorkout === null || $daysSinceLastWorkout <= 7) {
            return round($reportedWeeklyBaseKm, 1);
        }

        $ceilingRatio = match (true) {
            $daysSinceLastWorkout >= 28 => 0.60,
            $daysSinceLastWorkout >= 21 => 0.75,
            $daysSinceLastWorkout >= 14 => 0.85,
            default => 1.00,
        };

        $factorRatio = $detrainingFactor !== null
            ? max(0.50, min(1.00, $detrainingFactor))
            : 1.00;

        return round($reportedWeeklyBaseKm * min($ceilingRatio, $factorRatio), 1);
    }

    private function getDaysSinceLastWorkout(int $userId): ?int {
        return $this->workoutRepo()->getDaysSinceLastWorkout($userId);
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

    private function detectSpecialPopulationFlags(
        ?int $ageYears,
        string $healthNotes,
        ?int $daysSinceLastWorkout,
        string $vdotConfidence,
        array $feedbackAnalytics = [],
        array $athleteSignals = []
    ): array {
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

        if (!empty($feedbackAnalytics['has_recent_pain'])) {
            $flags[] = 'recent_pain_signal';
        }
        if ($this->hasHighSubjectiveLoad($feedbackAnalytics)) {
            $flags[] = 'recent_fatigue_spike';
        }
        if (!empty($athleteSignals['has_note_pain_signal'])) {
            $flags[] = 'recent_pain_signal';
        }
        if (!empty($athleteSignals['has_note_illness_signal'])) {
            $flags[] = 'recent_illness_signal';
        }
        if (!empty($athleteSignals['has_note_sleep_signal'])) {
            $flags[] = 'recent_sleep_signal';
        }
        if (!empty($athleteSignals['has_note_stress_signal'])) {
            $flags[] = 'recent_stress_signal';
        }
        if (!empty($athleteSignals['has_note_travel_signal'])) {
            $flags[] = 'recent_travel_signal';
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

    private function hasHighSubjectiveLoad(array $feedbackAnalytics): bool {
        return
            (float) ($feedbackAnalytics['subjective_load_delta'] ?? 0.0) >= 0.75
            || (
                (float) ($feedbackAnalytics['recent_session_rpe_avg'] ?? 0.0) >= 7.5
                && (
                    (float) ($feedbackAnalytics['session_rpe_delta'] ?? 0.0) >= 0.75
                    || (float) ($feedbackAnalytics['recent_average_recovery_risk'] ?? 0.0) >= 0.55
                )
            )
            || (float) ($feedbackAnalytics['recent_legs_score_avg'] ?? 0.0) >= 8.0
            || (float) ($feedbackAnalytics['recent_breath_score_avg'] ?? 0.0) >= 8.0
            || (float) ($feedbackAnalytics['recent_hr_strain_score_avg'] ?? 0.0) >= 8.0;
    }

    private function hasModerateSubjectiveLoad(array $feedbackAnalytics): bool {
        return $this->hasHighSubjectiveLoad($feedbackAnalytics)
            || (float) ($feedbackAnalytics['subjective_load_delta'] ?? 0.0) >= 0.45
            || (float) ($feedbackAnalytics['session_rpe_delta'] ?? 0.0) >= 0.75
            || (float) ($feedbackAnalytics['recent_session_rpe_avg'] ?? 0.0) >= 7.0;
    }

    private function getRecentFeedbackAnalytics(int $userId): array {
        try {
            return $this->postWorkoutFollowupService->getRecentFeedbackAnalytics($userId, 14);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function getRecentAthleteSignals(int $userId): array {
        try {
            return $this->athleteSignalsService->getRecentSignalsSummary($userId, 14);
        } catch (Throwable $e) {
            return [
                'feedback' => $this->getRecentFeedbackAnalytics($userId),
            ];
        }
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
