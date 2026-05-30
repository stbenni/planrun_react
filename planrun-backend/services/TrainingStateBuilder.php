<?php

require_once __DIR__ . '/StatsService.php';
require_once __DIR__ . '/PostWorkoutFollowupService.php';
require_once __DIR__ . '/AthleteSignalsService.php';
require_once __DIR__ . '/PlanReadinessCheckService.php';
require_once __DIR__ . '/PlanScenarioResolver.php';
require_once __DIR__ . '/../planrun_ai/prompt_builder.php';
require_once __DIR__ . '/../repositories/WorkoutRepository.php';

class TrainingStateBuilder {
    private mysqli $db;
    private StatsService $statsService;
    private PostWorkoutFollowupService $postWorkoutFollowupService;
    private AthleteSignalsService $athleteSignalsService;
    private ?WorkoutRepository $workoutRepo = null;
    private ?PlanScenarioResolver $scenarioResolver = null;

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
                training_start_date,
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

    /**
     * Расширенная сигнатура: $mode и $payload используются для вычисления planning_scenario и goal_realism
     * (P0.2 — чтобы поля были доступны и в режиме llm_planner, а не только в skeleton-first).
     * Дефолтные значения сохраняют обратную совместимость с прежним вызовом buildForUser($user).
     */
    public function buildForUser(array $user, string $mode = 'generate', array $payload = []): array {
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
        $targetTimeSec = $this->parseTimeSec($user['race_target_time'] ?? null);
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

        // Сначала пытаемся найти ЛУЧШИЙ результат за расширенное окно (12 нед.) —
        // это honest current fitness ceiling (если атлет недавно бежал HM 1:35 — это его forma,
        // даже если последний марафон 3:43 показывает marathon-specific endurance gap).
        $bestResult = null;
        if (!$vdot && $userId > 0) {
            $bestResult = $this->statsService->getBestResultForVdot($userId, 12, $targetDistKm > 0 ? $targetDistKm : null);
        }

        $lastRaceVdot = ($lastRaceDistKm > 0 && $lastRaceTimeSec > 0)
            ? estimateVDOT($lastRaceDistKm, $lastRaceTimeSec)
            : null;

        if (!$vdot) {
            // Декомпозируем: берём max между last_race и best_result, но с приоритетом
            // на best_result если он значимо выше (атлет в хорошей форме на коротких).
            $bestVdot = $bestResult ? (float) $bestResult['vdot'] : null;

            if ($bestVdot !== null && $lastRaceVdot !== null && $bestVdot >= $lastRaceVdot + 0.5) {
                $vdot = $bestVdot;
                $vdotSource = 'best_result';
                $vdotSourceDetail = $bestResult['vdot_source_detail'] ?? 'лучший результат за 12 недель';
                $sourceDistanceKm = isset($bestResult['distance_km']) ? (float) $bestResult['distance_km'] : null;
                $sourceTimeSec = isset($bestResult['time_sec']) ? (int) $bestResult['time_sec'] : null;
            } elseif ($lastRaceVdot !== null && $lastRaceWeeksOld !== null && $lastRaceWeeksOld <= 8) {
                $vdot = $lastRaceVdot;
                $vdotSource = 'last_race';
                $vdotSourceDetail = 'свежий результат забега/контрольной';
                $vdotWeeksOld = $lastRaceWeeksOld;
                $sourceDistanceKm = $lastRaceDistKm;
                $sourceTimeSec = $lastRaceTimeSec;
            } elseif ($bestVdot !== null) {
                $vdot = $bestVdot;
                $vdotSource = 'best_result';
                $vdotSourceDetail = $bestResult['vdot_source_detail'] ?? 'лучший результат за 12 недель';
                $sourceDistanceKm = isset($bestResult['distance_km']) ? (float) $bestResult['distance_km'] : null;
                $sourceTimeSec = isset($bestResult['time_sec']) ? (int) $bestResult['time_sec'] : null;
            } elseif ($lastRaceVdot !== null) {
                $vdot = $lastRaceVdot;
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

        $mainRaceDate = $user['race_date'] ?? null;
        $intermediateRaces = $userId > 0 ? $this->getIntermediateRaces($userId, $mainRaceDate) : [];

        $daysSinceLastWorkout = $userId > 0 ? $this->getDaysSinceLastWorkout($userId) : null;
        $athleteSignals = $userId > 0 ? $this->getRecentAthleteSignals($userId) : [];
        $feedbackAnalytics = is_array($athleteSignals['feedback'] ?? null) ? $athleteSignals['feedback'] : [];
        $planReadinessCheck = $userId > 0 ? $this->getLatestPlanReadinessCheckAnswer($userId) : null;
        if ($planReadinessCheck !== null) {
            [$feedbackAnalytics, $athleteSignals] = $this->applyPlanReadinessCheckAnswer(
                $feedbackAnalytics,
                $athleteSignals,
                $planReadinessCheck
            );
        }
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

        $state = [
            'goal_type' => $goalType,
            'race_distance' => $user['race_distance'] ?? null,
            'race_date' => $user['race_date'] ?? null,
            'race_target_time' => $user['race_target_time'] ?? null,
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
            'plan_readiness_check' => $this->compactPlanReadinessCheckAnswer($planReadinessCheck),
            'return_to_run_state' => in_array('return_after_break', $specialPopulationFlags, true) || in_array('return_after_injury', $specialPopulationFlags, true)
                ? 'conservative'
                : null,
            'intermediate_races' => $intermediateRaces,
        ];

        // P0.2: planning_scenario и goal_realism теперь доступны независимо от пути генерации
        // (skeleton-first уже вычисляет их сам, llm_planner раньше получал null).
        // Управляется feature flag PLANRUN_AI_STATE_SCENARIO (default 1).
        if ($this->isScenarioFeatureEnabled()) {
            $state['planning_scenario'] = $this->resolvePlanningScenario($user, $state, $mode, $payload);
            $state['goal_realism'] = $this->resolveGoalRealism($user, $state);
        }

        // PR9: pace_strategy — мост к цели.
        // Если goal_realism.severity = major → план готовит к VDOT-предикту (predicted),
        // иначе к самой цели. Темпы tempo/interval тянутся к goal_paces, чтобы AI
        // не тренировал в темпе текущей формы при амбициозной цели.
        $paceStrategy = $this->buildPaceStrategy($user, $state);
        if ($paceStrategy !== null) {
            $state['pace_strategy'] = $paceStrategy;
        }

        // Phase B.1 (PR3): recent_compliance — последние ISO-недели для recalc/next_plan.
        // PR-D итерация 4: окно расширено с 4 до 8 недель, чтобы модель видела
        // полную базу формы (для marathon prep это критично — historical peak в W-8...W-5 часто
        // намного выше recent W-3...W-1 после race recovery). Для post-marathon spans 8 недель
        // покрывают обычно: marathon week + recovery + 2-3 typical training weeks + early build.
        // Phase B.2 (PR3): recent_workouts_detailed — последние 14 дней с RPE/HR/pace.
        // Включаем только если у пользователя есть ID и данные за период.
        if ($userId > 0 && $this->isRecentContextFeatureEnabled()) {
            $recentCompliance = $this->buildRecentCompliance($userId, 8);
            if (!empty($recentCompliance)) {
                $state['recent_compliance'] = $recentCompliance;
                // PR-A (coaching prompt v4): тренерский саммари вместо enum signal.
                // Тренер прочтёт факты + одну фразу и сам решит как реагировать; никаких
                // готовых рекомендаций ("снизь peak на 15%") — это работа модели.
                $state['recent_compliance_summary'] = $this->buildRecentComplianceSummary(
                    $recentCompliance,
                    $reportedWeeklyBaseKm
                );
                // PR-A: peak_volume_floor_km как hint в load_policy. Тренер видит «реальный потолок
                // формы ~X км» и сам соотносит с целевым peak. Outlier-неделя (race/тест >130% медианы)
                // исключается, чтобы одна экстремальная неделя не задирала floor.
                $peakFloor = $this->computePeakVolumeFloorKm($recentCompliance, $reportedWeeklyBaseKm);
                if ($peakFloor !== null && isset($state['load_policy']) && is_array($state['load_policy'])) {
                    $state['load_policy']['peak_volume_floor_km'] = $peakFloor;
                }
                // PR-D итерация 4: historical_peak_weekly_km — реальный максимум объёма за окно
                // (8 нед.). Полезен когда «recent average» низкий (post-race recovery), но
                // human реально умеет делать 90+ км. Без этого модель занижает peak.
                $historicalPeak = 0.0;
                foreach ($recentCompliance as $w) {
                    $km = (float) ($w['actual_km'] ?? 0.0);
                    if ($km > $historicalPeak) {
                        $historicalPeak = $km;
                    }
                }
                if ($historicalPeak > 0.1 && isset($state['load_policy']) && is_array($state['load_policy'])) {
                    $state['load_policy']['historical_peak_weekly_km'] = round($historicalPeak, 1);
                }
                // PR9b: pace_strategy уже построен выше; после расчёта peak_floor дублируем якорь
                // объёма рядом с темповым режимом — модель не должна резать км из‑за
                // realistic_target (это ортогональные оси: темп цели vs выносливость по неделям).
                if (isset($state['pace_strategy']) && is_array($state['pace_strategy'])) {
                    $lp = $state['load_policy'] ?? [];
                    if (is_array($lp)) {
                        if (isset($lp['peak_volume_floor_km']) && $lp['peak_volume_floor_km'] !== null) {
                            $state['pace_strategy']['peak_volume_anchor_km'] = (float) $lp['peak_volume_floor_km'];
                        }
                        if (isset($lp['historical_peak_weekly_km']) && $lp['historical_peak_weekly_km'] !== null) {
                            $state['pace_strategy']['historical_peak_weekly_km'] = (float) $lp['historical_peak_weekly_km'];
                        }
                    }
                }
            }
            $recentWorkouts = $this->buildRecentWorkoutsDetailed($userId, 14);
            if (!empty($recentWorkouts)) {
                $state['recent_workouts_detailed'] = $recentWorkouts;
            }
        }

        // Phase B.3 (PR4): season/climate context — month и hemisphere для климат-aware planning.
        // Phase B.4 (PR4): best_races_progression — top результаты по дистанциям за 12 мес.
        $startDateForContext = (string) ($user['training_start_date'] ?? $user['plan_start_date'] ?? '');
        $raceDateForContext = (string) ($state['race_date'] ?? '');
        $climate = $this->buildClimateContext($user, $startDateForContext, $raceDateForContext);
        if (!empty($climate)) {
            $state['season'] = $climate;
        }

        if ($userId > 0 && $this->isRecentContextFeatureEnabled()) {
            $bestRaces = $this->buildBestRacesProgression($userId);
            if (!empty($bestRaces)) {
                $state['best_races'] = $bestRaces;

                // Phase B.5 (PR4): расширяем goal_realism полем previous_attempts_at_distance
                // — DeepSeek увидит, был ли уже опыт на этой дистанции и какой реалистичный шаг.
                if (is_array($state['goal_realism'] ?? null)) {
                    $state['goal_realism']['best_races_at_target_distance'] = $this->matchBestRacesToTargetDistance(
                        $bestRaces,
                        (string) ($user['race_distance'] ?? '')
                    );
                }
            }
        }

        return $state;
    }

    /**
     * Phase B.3 (PR4): простой климатический контекст для FACTS_JSON.
     * Возвращает:
     *   - current_month (1..12), current_month_name (en lower)
     *   - race_month (если race_date есть)
     *   - northern_hemisphere (бул) — определяется по timezone (Europe/Asia/America = north).
     *   - season_phase (en): early_spring | spring | summer | autumn | winter — для northern hemisphere.
     *
     * Для «trust the model»: не передаём `expected_temp_c` — это hardcode без реальных данных
     * локации пользователя; DeepSeek сам понимает, что в августе жарко.
     */
    private function buildClimateContext(array $user, string $startDate, string $raceDate): array {
        $tz = (string) ($user['timezone'] ?? '');
        $northern = $this->isNorthernHemisphere($tz);

        $startStr = $startDate !== '' ? $startDate : (new DateTimeImmutable('now'))->format('Y-m-d');
        try {
            $start = new DateTimeImmutable($startStr);
        } catch (Throwable $e) {
            $start = new DateTimeImmutable('now');
        }
        $startMonth = (int) $start->format('n');

        $raceMonth = null;
        if ($raceDate !== '') {
            try {
                $raceMonth = (int) (new DateTimeImmutable($raceDate))->format('n');
            } catch (Throwable $e) {
                $raceMonth = null;
            }
        }

        $monthNames = [1=>'january',2=>'february',3=>'march',4=>'april',5=>'may',6=>'june',7=>'july',8=>'august',9=>'september',10=>'october',11=>'november',12=>'december'];

        return [
            'current_month' => $startMonth,
            'current_month_name' => $monthNames[$startMonth] ?? null,
            'race_month' => $raceMonth,
            'race_month_name' => $raceMonth !== null ? ($monthNames[$raceMonth] ?? null) : null,
            'northern_hemisphere' => $northern,
            'season_phase' => $this->resolveSeasonPhase($startMonth, $northern),
            'race_season_phase' => $raceMonth !== null ? $this->resolveSeasonPhase($raceMonth, $northern) : null,
            'timezone' => $tz !== '' ? $tz : null,
        ];
    }

    private function isNorthernHemisphere(string $timezone): bool {
        if ($timezone === '') {
            return true;
        }
        $tz = strtolower($timezone);
        if (str_contains($tz, 'australia/') || str_contains($tz, 'antarctica/')
            || str_contains($tz, 'pacific/auckland') || str_contains($tz, 'pacific/fiji')
            || str_contains($tz, 'america/argentina') || str_contains($tz, 'america/sao_paulo')
            || str_contains($tz, 'america/santiago') || str_contains($tz, 'africa/johannesburg')) {
            return false;
        }
        return true;
    }

    private function resolveSeasonPhase(int $month, bool $northern): string {
        // Northern hemisphere mapping; for southern — flip 6 months.
        if (!$northern) {
            $month = (($month - 1 + 6) % 12) + 1;
        }
        return match ($month) {
            12, 1, 2 => 'winter',
            3 => 'early_spring',
            4, 5 => 'spring',
            6, 7, 8 => 'summer',
            9, 10 => 'autumn',
            11 => 'late_autumn',
            default => 'unknown',
        };
    }

    /**
     * Phase B.4 (PR4): top результаты по бакетам 5k/10k/half/marathon за 52 нед.
     * Использует StatsService::getBestRacesProgression. При сбое возвращает [].
     */
    private function buildBestRacesProgression(int $userId): array {
        try {
            $rows = $this->statsService->getBestRacesProgression($userId, 52);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($rows) ? $rows : [];
    }

    /**
     * Phase B.5 (PR4): сужаем best_races до целевой дистанции, чтобы DeepSeek
     * мог сравнить goal_realism.recommended_target_time с историческим лучшим результатом.
     * Возвращает массив с одним-двумя элементами либо [].
     */
    private function matchBestRacesToTargetDistance(array $bestRaces, string $raceDistance): array {
        $raceDistance = strtolower(trim($raceDistance));
        if ($raceDistance === '') {
            return [];
        }
        $aliasMap = [
            '5k' => '5k', '5km' => '5k',
            '10k' => '10k', '10km' => '10k',
            'half' => 'half', '21.1k' => 'half', '21k' => 'half', 'half_marathon' => 'half', 'half-marathon' => 'half',
            'marathon' => 'marathon', '42.2k' => 'marathon', 'full_marathon' => 'marathon',
        ];
        $label = $aliasMap[$raceDistance] ?? null;
        if ($label === null) {
            return [];
        }
        $matched = array_values(array_filter($bestRaces, fn($r) => ($r['distance_label'] ?? null) === $label));
        return $matched;
    }

    /**
     * Phase B (PR3): feature flag для recent_compliance/recent_workouts_detailed.
     * По умолчанию включено; отключается через PLANRUN_AI_STATE_RECENT_CONTEXT=0.
     */
    private function isRecentContextFeatureEnabled(): bool {
        $raw = function_exists('env') ? env('PLANRUN_AI_STATE_RECENT_CONTEXT', '1') : '1';
        $value = strtolower(trim((string) $raw));
        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Phase B.1 (PR3): compliance за последние 4 ISO-недели для FACTS_JSON.
     * Возвращает массив (старая → свежая) с полями:
     *   week_start, week_end, planned_count, completed_count, actual_km,
     *   key_workout_planned, key_workout_completed, compliance_ratio,
     *   key_workout_completion_pct, skipped_count.
     *
     * DeepSeek по этому массиву видит, как реально тренировался спортсмен:
     * пропускал ли key workouts, не успевает ли по объёму, не перебирает ли.
     */
    private function buildRecentCompliance(int $userId, int $weeks = 4): array {
        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        $monday = $now->modify('monday this week')->format('Y-m-d');
        $earliestMonday = (new DateTimeImmutable($monday))->modify('-' . ($weeks - 1) . ' weeks')->format('Y-m-d');

        try {
            $repo = $this->workoutRepo();
        } catch (Throwable $e) {
            return [];
        }

        $result = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $weekStart = (new DateTimeImmutable($monday))->modify('-' . $i . ' weeks');
            $weekEnd = $weekStart->modify('+6 days');
            $weekStartStr = $weekStart->format('Y-m-d');
            $weekEndStr = $weekEnd->format('Y-m-d');

            // Не включаем будущие даты для текущей (последней) недели.
            $effectiveEnd = $weekEndStr > $today ? $today : $weekEndStr;

            try {
                $compliance = $repo->getDetailedCompliance($userId, $weekStartStr, $effectiveEnd);
            } catch (Throwable $e) {
                continue;
            }

            $planned = (int) ($compliance['planned_count'] ?? 0);
            $completed = (int) ($compliance['completed_count'] ?? 0);
            $keyPlanned = (int) ($compliance['key_workout_planned'] ?? 0);
            $keyDone = (int) ($compliance['key_workout_completed'] ?? 0);
            $actualKm = (float) ($compliance['actual_km'] ?? 0.0);

            $complianceRatio = $planned > 0 ? round($completed / $planned, 2) : null;
            $keyPct = $keyPlanned > 0 ? round($keyDone / $keyPlanned, 2) : null;
            $skipped = max(0, $planned - $completed);

            // Пропускаем неделю, в которой не было ни плана, ни активности — нечего показывать DeepSeek.
            if ($planned === 0 && $completed === 0 && $actualKm <= 0.0) {
                continue;
            }

            $result[] = [
                'week_start' => $weekStartStr,
                'week_end' => $weekEndStr,
                'planned_count' => $planned,
                'completed_count' => $completed,
                'skipped_count' => $skipped,
                'actual_km' => $actualKm,
                'key_workout_planned' => $keyPlanned,
                'key_workout_completed' => $keyDone,
                'compliance_ratio' => $complianceRatio,
                'key_workout_completion_pct' => $keyPct,
                'is_current_week' => ($weekStartStr === $monday),
            ];
        }

        return $result;
    }

    /**
     * PR-A (coaching prompt v4): короткая русская фраза по фактам последних 4 недель.
     *
     * Цель — дать тренеру (модели) одну строчку, которую он прочтёт и поймёт ситуацию,
     * без enum signal/recommendation. «Запланировано X км / Y тренировок, выполнено Z км /
     * W тренировок, ключевых N из M. Тенденция …». Всё остальное модель дорассуждает сама.
     *
     * @param array $weeks Массив недель из buildRecentCompliance (старая → свежая).
     * @param float $reportedWeeklyBaseKm Заявленный базовый объём.
     * @return string Русская фраза или пустая строка, если данных недостаточно.
     */
    private function buildRecentComplianceSummary(array $weeks, float $reportedWeeklyBaseKm): string {
        if (empty($weeks)) {
            return '';
        }

        $weeksCount = count($weeks);
        $weeksWithPlan = [];
        $weeksWithoutPlan = [];
        $weeklyKm = [];
        $totalActualKm = 0.0;
        foreach ($weeks as $w) {
            $planned = (int) ($w['planned_count'] ?? 0);
            $actualKm = (float) ($w['actual_km'] ?? 0.0);
            $weeklyKm[] = $actualKm;
            $totalActualKm += $actualKm;
            if ($planned > 0) {
                $weeksWithPlan[] = $w;
            } else {
                $weeksWithoutPlan[] = $w;
            }
        }
        $avgKm = $weeksCount > 0 ? $totalActualKm / $weeksCount : 0.0;
        $totalActualKm = round($totalActualKm, 1);

        $parts = [];

        // 1. Сегмент «с планом»: compliance + ключевые
        if (!empty($weeksWithPlan)) {
            $plannedTotal = 0;
            $completedTotal = 0;
            $keyPlanned = 0;
            $keyCompleted = 0;
            $kmInPlanned = 0.0;
            foreach ($weeksWithPlan as $w) {
                $plannedTotal += (int) ($w['planned_count'] ?? 0);
                $completedTotal += (int) ($w['completed_count'] ?? 0);
                $keyPlanned += (int) ($w['key_workout_planned'] ?? 0);
                $keyCompleted += (int) ($w['key_workout_completed'] ?? 0);
                $kmInPlanned += (float) ($w['actual_km'] ?? 0.0);
            }
            $countPlanned = count($weeksWithPlan);
            $parts[] = sprintf(
                'В %s с планом запланировано %s, выполнено %s (%s км).',
                $this->ruWeeks($countPlanned),
                $this->ruWorkouts($plannedTotal),
                $completedTotal,
                $this->formatKm($kmInPlanned)
            );
            if ($keyPlanned > 0) {
                $parts[] = sprintf(
                    'Ключевых выполнено %d из %d.',
                    $keyCompleted,
                    $keyPlanned
                );
            }
        }

        // 2. Сегмент «без плана»: реальный объём
        if (!empty($weeksWithoutPlan)) {
            $kmWithoutPlan = 0.0;
            $completedWithoutPlan = 0;
            foreach ($weeksWithoutPlan as $w) {
                $kmWithoutPlan += (float) ($w['actual_km'] ?? 0.0);
                $completedWithoutPlan += (int) ($w['completed_count'] ?? 0);
            }
            $countWithoutPlan = count($weeksWithoutPlan);
            if ($kmWithoutPlan <= 0.1 && $completedWithoutPlan === 0) {
                $parts[] = sprintf('В %s без плана активности не зафиксировано.', $this->ruWeeks($countWithoutPlan));
            } else {
                $avgWithoutPlan = $countWithoutPlan > 0 ? $kmWithoutPlan / $countWithoutPlan : 0.0;
                $parts[] = sprintf(
                    'В %s без плана выполнено %s, %s км (в среднем %s км/нед).',
                    $this->ruWeeks($countWithoutPlan),
                    $this->ruWorkouts($completedWithoutPlan),
                    $this->formatKm($kmWithoutPlan),
                    $this->formatKm($avgWithoutPlan)
                );
            }
        }

        // 3. Если все 4 недели одного типа — добавим короткий header
        if (empty($weeksWithPlan) || empty($weeksWithoutPlan)) {
            array_unshift($parts, sprintf('За %s:', $this->ruWeeks($weeksCount)));
        } else {
            array_unshift($parts, sprintf('За %s (mix):', $this->ruWeeks($weeksCount)));
        }

        // 4. Тенденция объёма (сравнение второй половины периода с первой)
        if ($weeksCount >= 3) {
            $half = (int) floor($weeksCount / 2);
            $earlier = array_slice($weeklyKm, 0, $half);
            $later = array_slice($weeklyKm, $weeksCount - $half);
            $earlierAvg = $earlier ? array_sum($earlier) / count($earlier) : 0.0;
            $laterAvg = $later ? array_sum($later) / count($later) : 0.0;
            if ($earlierAvg > 1.0) {
                $delta = ($laterAvg - $earlierAvg) / $earlierAvg;
                if ($delta >= 0.20) {
                    $parts[] = 'Объём растёт.';
                } elseif ($delta <= -0.20) {
                    $parts[] = 'Объём снижается последние недели.';
                }
            }
        }

        // 5. Historical peak — самая объёмная неделя в окне. Полезна когда low avg
        // объясняется post-race recovery, а не реальным упадком формы.
        $maxKm = !empty($weeklyKm) ? max($weeklyKm) : 0.0;
        if ($maxKm > 1.0 && $weeksCount >= 4) {
            $parts[] = sprintf('Максимум недели в окне — %s км.', $this->formatKm($maxKm));
        }

        // 6. Сравнение с заявленной базой — без алармизма; даём числа, тренер сам интерпретирует.
        if ($reportedWeeklyBaseKm > 1.0 && $avgKm > 0.1) {
            $ratio = $avgKm / $reportedWeeklyBaseKm;
            $maxRatio = $maxKm > 0.1 ? $maxKm / $reportedWeeklyBaseKm : 0.0;
            if ($ratio >= 1.20) {
                $parts[] = sprintf(
                    'Средний фактический объём (%s км) выше заявленной базы (%s км).',
                    $this->formatKm($avgKm),
                    $this->formatKm($reportedWeeklyBaseKm)
                );
            } elseif ($ratio <= 0.50 && $maxRatio >= 0.80) {
                // Низкий avg, но historical peak подтверждает заявленную базу — это значит
                // recovery после race, а не реальный спад формы.
                $parts[] = sprintf(
                    'Средний (%s км) ниже базы (%s км), но в окне есть неделя ≈%s км — заявленная база подтверждается, низкий avg = post-race recovery.',
                    $this->formatKm($avgKm),
                    $this->formatKm($reportedWeeklyBaseKm),
                    $this->formatKm($maxKm)
                );
            } elseif ($ratio <= 0.50) {
                $parts[] = sprintf(
                    'Средний фактический объём (%s км) ниже заявленной базы (%s км) — стоит понять, временный ли это спад (восстановление, болезнь, отпуск) или новая норма.',
                    $this->formatKm($avgKm),
                    $this->formatKm($reportedWeeklyBaseKm)
                );
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Корректное склонение слова «неделя»: 1 неделю / 2-4 недели / 5+ недель.
     */
    private function ruWeeks(int $n): string {
        $n = abs($n);
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return $n . ' недель';
        }
        $mod10 = $n % 10;
        if ($mod10 === 1) return $n . ' неделю';
        if ($mod10 >= 2 && $mod10 <= 4) return $n . ' недели';
        return $n . ' недель';
    }

    /**
     * Корректное склонение слова «тренировка»: 1 тренировка / 2-4 тренировки / 5+ тренировок.
     */
    private function ruWorkouts(int $n): string {
        $n = abs($n);
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return $n . ' тренировок';
        }
        $mod10 = $n % 10;
        if ($mod10 === 1) return $n . ' тренировка';
        if ($mod10 >= 2 && $mod10 <= 4) return $n . ' тренировки';
        return $n . ' тренировок';
    }

    /**
     * PR-A (coaching prompt v4): peak_volume_floor_km — реальный потолок формы по фактам.
     *
     * Берёт MAX(actual_km), median(actual_km), reported_weekly_base_km × 0.85 и возвращает
     * максимум. Цель — не позволить плану «зашейпиться» ниже реального уровня бегуна.
     *
     * Outlier-неделя (одиночное значение >130% медианы при ≥3 неделях данных) исключается:
     * это race/контрольная, она искажает потолок повседневной формы.
     *
     * @param array $weeks Массив недель из buildRecentCompliance.
     * @param float $reportedWeeklyBaseKm
     * @return float|null Округлённый до 1 знака floor или null если данных недостаточно.
     */
    private function computePeakVolumeFloorKm(array $weeks, float $reportedWeeklyBaseKm): ?float {
        if (empty($weeks) && $reportedWeeklyBaseKm <= 0.0) {
            return null;
        }

        $values = [];
        foreach ($weeks as $w) {
            $km = (float) ($w['actual_km'] ?? 0.0);
            if ($km > 0.0) {
                $values[] = $km;
            }
        }

        // Если есть ≥3 недель — отбрасываем outlier (race/control week, искажает потолок)
        if (count($values) >= 3) {
            $sorted = $values;
            sort($sorted, SORT_NUMERIC);
            $medianRaw = $this->medianOfSorted($sorted);
            if ($medianRaw > 0.0) {
                $values = array_values(array_filter(
                    $values,
                    static fn(float $v): bool => $v <= ($medianRaw * 1.30)
                ));
            }
        }

        $maxVal = $values ? max($values) : 0.0;

        $sortedClean = $values;
        sort($sortedClean, SORT_NUMERIC);
        $medianClean = $sortedClean ? $this->medianOfSorted($sortedClean) : 0.0;

        $baseFloor = $reportedWeeklyBaseKm > 0.0 ? $reportedWeeklyBaseKm * 0.85 : 0.0;

        $floor = max($maxVal, $medianClean, $baseFloor);
        if ($floor <= 0.0) {
            return null;
        }
        return round($floor, 1);
    }

    private function medianOfSorted(array $sortedValues): float {
        $n = count($sortedValues);
        if ($n === 0) {
            return 0.0;
        }
        if ($n % 2 === 1) {
            return (float) $sortedValues[(int) (($n - 1) / 2)];
        }
        $mid = (int) ($n / 2);
        return ((float) $sortedValues[$mid - 1] + (float) $sortedValues[$mid]) / 2.0;
    }

    private function formatKm(float $km): string {
        if (abs($km - round($km)) < 0.05) {
            return (string) (int) round($km);
        }
        return number_format($km, 1, '.', '');
    }

    /**
     * Phase B.2 (PR3): recent_workouts_detailed для FACTS_JSON.
     * Последние N дней (default 14) с типом, дистанцией, темпом, HR, RPE, заметками.
     * DeepSeek видит фактическую усталость: pace deviation, HR drift, RPE rise.
     */
    private function buildRecentWorkoutsDetailed(int $userId, int $days = 14): array {
        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $from = (new DateTimeImmutable('now'))->modify('-' . max(1, $days) . ' days')->format('Y-m-d');

        try {
            $repo = $this->workoutRepo();
            $rows = $repo->getRecentDetailedWorkouts($userId, $from, $today);
        } catch (Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $distanceKm = isset($row['distance_km']) ? (float) $row['distance_km'] : 0.0;
            $duration = isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null;

            $paceSec = null;
            if ($distanceKm > 0 && $duration !== null && $duration > 0) {
                $paceSec = (int) round(($duration * 60.0) / $distanceKm);
            }
            $paceFormatted = null;
            if ($paceSec !== null && $paceSec > 0 && function_exists('formatPaceSec')) {
                $paceFormatted = formatPaceSec($paceSec);
            } elseif (!empty($row['pace'])) {
                $paceFormatted = (string) $row['pace'];
            }

            $hr = isset($row['avg_heart_rate']) && $row['avg_heart_rate'] !== null
                ? (int) $row['avg_heart_rate']
                : null;
            $rpe = isset($row['rating']) && $row['rating'] !== null && $row['rating'] !== ''
                ? (int) $row['rating']
                : null;
            $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
            if (mb_strlen($notes) > 200) {
                $notes = mb_substr($notes, 0, 200) . '…';
            }

            $entry = [
                'date' => (string) ($row['date'] ?? ''),
                'type' => (string) ($row['type'] ?? 'running'),
                'is_key_workout' => !empty($row['is_key_workout']),
                'distance_km' => $distanceKm > 0 ? round($distanceKm, 2) : null,
                'duration_minutes' => $duration,
                'pace_sec' => $paceSec,
                'pace' => $paceFormatted,
                'hr_avg' => $hr,
                'rpe' => $rpe,
                'source' => (string) ($row['source'] ?? 'manual'),
            ];
            if ($notes !== '') {
                $entry['notes'] = $notes;
            }
            $result[] = $entry;
        }

        return $result;
    }

    private function isScenarioFeatureEnabled(): bool {
        $raw = function_exists('env') ? env('PLANRUN_AI_STATE_SCENARIO', '1') : '1';
        $value = strtolower(trim((string) $raw));
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return true;
    }

    private function resolvePlanningScenario(array $user, array $state, string $mode, array $payload): ?array {
        try {
            $resolver = $this->scenarioResolver ??= new PlanScenarioResolver();
            return $resolver->resolve($user, $state, $mode, $payload);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Возвращает компактную выжимку assessGoalRealism для llm_planner и quality gate.
     * Только для goal_type ∈ {race, time_improvement} — иначе null, чтобы не добавлять
     * в FACTS_JSON шум.
     */
    private function resolveGoalRealism(array $user, array $state): ?array {
        $goalType = (string) ($state['goal_type'] ?? $user['goal_type'] ?? '');
        if (!in_array($goalType, ['race', 'time_improvement'], true)) {
            return null;
        }

        try {
            $userData = $user;
            $userData['training_state'] = [
                'vdot' => $state['vdot'] ?? null,
                'vdot_source_label' => $state['vdot_source_label'] ?? null,
                'training_paces' => $state['training_paces'] ?? null,
            ];
            $assessment = assessGoalRealism($userData);
        } catch (Throwable $e) {
            return null;
        }

        if (!is_array($assessment)) {
            return null;
        }

        $verdict = (string) ($assessment['verdict'] ?? 'realistic');
        $severity = match ($verdict) {
            'unrealistic' => 'major',
            'challenging', 'caution' => 'moderate',
            default => 'none',
        };

        $issueMessages = [];
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
            $issueMessages[] = $text;
            if (count($issueMessages) >= 3) {
                break;
            }
        }

        $recommendedTargetTime = null;
        foreach ((array) ($assessment['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            foreach ((array) ($message['suggestions'] ?? []) as $suggestion) {
                if (!is_array($suggestion)) {
                    continue;
                }
                $action = $suggestion['action'] ?? null;
                if (is_array($action) && (string) ($action['field'] ?? '') === 'race_target_time') {
                    $recommendedTargetTime = (string) ($action['value'] ?? '');
                    break 2;
                }
            }
        }

        return [
            'verdict' => $verdict,
            'severity' => $severity,
            'issue_count' => count((array) ($assessment['messages'] ?? [])),
            'issues' => $issueMessages,
            'recommended_target_time' => $recommendedTargetTime !== '' ? $recommendedTargetTime : null,
            'recommended_weeks' => $assessment['recommended_weeks'] ?? null,
            'recommended_sessions' => $assessment['recommended_sessions'] ?? null,
            'predictions' => $assessment['predictions'] ?? null,
            'vdot' => $assessment['vdot'] ?? null,
        ];
    }

    private function getIntermediateRaces(int $userId, ?string $mainRaceDate): array {
        $sql = "
            SELECT d.date, d.description, MAX(tde.distance_m) / 1000 AS distance_km
            FROM training_plan_days d
            JOIN training_plan_weeks w ON d.week_id = w.id
            LEFT JOIN training_day_exercises tde ON tde.plan_day_id = d.id AND tde.category = 'run'
            WHERE d.user_id = ? AND w.user_id = ? AND d.type = 'race' AND d.date >= CURDATE()
        ";
        $params = [$userId, $userId];
        $types = 'ii';

        if ($mainRaceDate !== null && $mainRaceDate !== '') {
            $sql .= " AND d.date != ?";
            $params[] = $mainRaceDate;
            $types .= 's';
        }

        $sql .= " GROUP BY d.id, d.date, d.description ORDER BY d.date ASC LIMIT 10";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $races = [];
        while ($row = $result->fetch_assoc()) {
            $races[] = [
                'date' => $row['date'],
                'description' => $row['description'] ?? null,
                'distance_km' => $row['distance_km'] !== null ? (float) $row['distance_km'] : null,
            ];
        }
        $stmt->close();
        return $races;
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
            $goalDate = $user['race_date'] ?? null;
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
        $isModerateBaseFirstMarathon = $isFirstRaceAtDistance
            && in_array($raceDistance, ['marathon', '42.2k'], true)
            && $weeklyBaseKm >= 30.0
            && $sessionsPerWeek >= 4;
        $firstLongRaceNeedsConservativeProfile = $isFirstLongRace && !$isModerateBaseFirstMarathon;
        $useConservativeRepairProfile = $readiness === 'low' && (
            $isLowBase
            || $firstLongRaceNeedsConservativeProfile
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
        if ($isModerateBaseFirstMarathon) {
            $longShareCap = max($longShareCap, 0.42);
        }
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

    private function getLatestPlanReadinessCheckAnswer(int $userId): ?array {
        try {
            return (new PlanReadinessCheckService($this->db))->getLatestValidAnswer($userId);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function applyPlanReadinessCheckAnswer(array $feedbackAnalytics, array $athleteSignals, array $answer): array {
        $interpretation = (string) ($answer['interpretation'] ?? '');
        if (!in_array($interpretation, ['clear', 'mild_clear'], true)) {
            return [$feedbackAnalytics, $athleteSignals];
        }

        $riskCap = $interpretation === 'clear' ? 0.30 : 0.45;
        foreach (['average_recovery_risk', 'recent_average_recovery_risk', 'max_recovery_risk', 'latest_recovery_risk'] as $key) {
            if (isset($feedbackAnalytics[$key])) {
                $feedbackAnalytics[$key] = round(min((float) $feedbackAnalytics[$key], $riskCap), 2);
            }
        }

        $painScore = isset($answer['current_pain_score']) ? (int) $answer['current_pain_score'] : 0;
        $feedbackAnalytics['pain_count'] = 0;
        $feedbackAnalytics['pain_flag_count'] = 0;
        $feedbackAnalytics['has_recent_pain'] = false;
        $feedbackAnalytics['recent_pain_score_avg'] = min((float) ($feedbackAnalytics['recent_pain_score_avg'] ?? 0.0), (float) $painScore);
        $feedbackAnalytics['pain_score_delta'] = 0.0;
        if (($feedbackAnalytics['latest_classification'] ?? null) === 'pain') {
            $feedbackAnalytics['latest_classification'] = 'neutral';
        }
        $feedbackAnalytics['risk_level'] = $interpretation === 'clear' ? 'low' : 'moderate';
        $feedbackAnalytics['plan_readiness_check_applied'] = true;

        $athleteSignals['feedback'] = $feedbackAnalytics;
        if (isset($athleteSignals['overall_risk_score'])) {
            $athleteSignals['overall_risk_score'] = round(min((float) $athleteSignals['overall_risk_score'], $riskCap), 2);
        }
        if (empty($athleteSignals['has_note_pain_signal'])) {
            $athleteSignals['overall_risk_level'] = $interpretation === 'clear' ? 'low' : 'moderate';
            $athleteSignals['planning_biases'] = array_values(array_filter(
                (array) ($athleteSignals['planning_biases'] ?? []),
                static fn($bias): bool => (string) $bias !== 'protect_injury'
            ));
            $athleteSignals['highlights'] = array_values(array_filter(
                (array) ($athleteSignals['highlights'] ?? []),
                static fn($highlight): bool => !str_contains(mb_strtolower((string) $highlight), 'болев')
            ));
        }
        $athleteSignals['plan_readiness_check_applied'] = true;
        $athleteSignals['prompt_summary'] = trim((string) ($athleteSignals['prompt_summary'] ?? '') . '; readiness-check: текущая боль ' . $painScore . '/10, сигнал уточнён');

        return [$feedbackAnalytics, $athleteSignals];
    }

    private function compactPlanReadinessCheckAnswer(?array $answer): ?array {
        if ($answer === null) {
            return null;
        }

        return [
            'id' => isset($answer['id']) ? (int) $answer['id'] : null,
            'source_date' => $answer['source_date'] ?? null,
            'current_pain_score' => isset($answer['current_pain_score']) ? (int) $answer['current_pain_score'] : null,
            'pain_worsened_after_runs' => isset($answer['pain_worsened_after_runs']) ? ((int) $answer['pain_worsened_after_runs'] === 1) : null,
            'technique_changed' => isset($answer['technique_changed']) ? ((int) $answer['technique_changed'] === 1) : null,
            'interpretation' => $answer['interpretation'] ?? null,
            'valid_until' => $answer['valid_until'] ?? null,
        ];
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
        $targetTimeSec = $this->parseTimeSec($user['race_target_time'] ?? null);
        if ($targetDistKm > 0 && $targetTimeSec > 0) {
            return (int) round($targetTimeSec / $targetDistKm);
        }
        return null;
    }

    /**
     * PR9: «мост к цели». Если у пользователя есть race-цель, считаем темпы Daniels
     * как для current VDOT (по реальной форме), так и для target VDOT (по цели).
     * Решаем под какой таргет план реально готовит:
     *   - severity=major (verdict=unrealistic, gap>15%) → готовим к predicted (VDOT-realistic).
     *   - severity=moderate/none → готовим к самой цели; tempo/interval при этом тянутся
     *     к goal_paces, чтобы был мост, а не работа в темпе текущего уровня.
     *
     * Возвращает блок для FACTS_JSON.training_state.pace_strategy + рекомендацию для
     * plan_review (effective_target_time/predicted_target_time/gap_pct).
     */
    private function buildPaceStrategy(array $user, array $state): ?array {
        $goalType = (string) ($state['goal_type'] ?? $user['goal_type'] ?? '');
        if (!in_array($goalType, ['race', 'time_improvement'], true)) {
            return null;
        }

        $targetDistKm = $this->parseDistanceKm($user['race_distance'] ?? null, null);
        if ($targetDistKm <= 0) {
            return null;
        }

        $goalTimeSec = $this->parseTimeSec($user['race_target_time'] ?? null);
        $goalPaceSec = $goalTimeSec > 0 ? (int) round($goalTimeSec / $targetDistKm) : null;

        $currentVdot = isset($state['vdot']) ? (float) $state['vdot'] : 0.0;
        $currentPaces = is_array($state['training_paces'] ?? null) ? $state['training_paces'] : null;

        $goalRealism = is_array($state['goal_realism'] ?? null) ? $state['goal_realism'] : [];
        $severity = (string) ($goalRealism['severity'] ?? 'none');

        $predictedTargetSec = null;
        if ($currentVdot > 0) {
            $predictedTargetSec = predictRaceTime($currentVdot, $targetDistKm);
        }

        $gapPct = null;
        if ($predictedTargetSec !== null && $predictedTargetSec > 0 && $goalTimeSec > 0) {
            $gapPct = round(($predictedTargetSec - $goalTimeSec) / $predictedTargetSec * 100, 1);
        }

        // Projection улучшения формы за период подготовки.
        // Daniels rates: novice ~0.3 VDOT/week, intermediate ~0.18, advanced/expert ~0.10.
        // Это даёт stretch_vdot к дате старта — реалистичная цель плана.
        $weeksToGoal = isset($state['weeks_to_goal']) ? (int) $state['weeks_to_goal'] : 0;
        $stretchTargetSec = null;
        $stretchVdot = null;
        if ($currentVdot > 0 && $weeksToGoal >= 3) {
            $expLevel = strtolower((string) ($user['experience_level'] ?? 'intermediate'));
            $rate = match (true) {
                str_contains($expLevel, 'novice') || str_contains($expLevel, 'beginner') => 0.30,
                str_contains($expLevel, 'advanced') || str_contains($expLevel, 'expert') => 0.12,
                default => 0.18, // intermediate
            };
            // Cap: за 12 нед. максимум ~3 пункта (физиологический предел).
            $maxGain = min(3.0, $weeksToGoal * $rate);
            $stretchVdot = min(85.0, $currentVdot + $maxGain);
            $stretchTargetSec = predictRaceTime($stretchVdot, $targetDistKm);
        }

        $mode = 'goal_target';
        $effectiveTimeSec = $goalTimeSec > 0 ? $goalTimeSec : $predictedTargetSec;

        if ($severity === 'major' && $predictedTargetSec !== null && $predictedTargetSec > 0) {
            // Если есть прогноз с учётом тренинга — берём его как «реалистично-амбициозную» цель.
            // Иначе fallback на predicted current state.
            if ($stretchTargetSec !== null && $stretchTargetSec > 0) {
                // Если goal быстрее stretch — оставляем stretch (агрессивно но в пределах физиологии)
                // Если goal медленнее stretch — оставляем goal (атлет не хочет stretching)
                $effectiveTimeSec = $goalTimeSec > 0 && $goalTimeSec > $stretchTargetSec
                    ? $goalTimeSec
                    : $stretchTargetSec;
                $mode = 'stretch_target';
            } else {
                $effectiveTimeSec = $predictedTargetSec;
                $mode = 'realistic_target';
            }
        }

        if (!$effectiveTimeSec) {
            return null;
        }

        $effectivePaceSec = (int) round($effectiveTimeSec / $targetDistKm);
        $effectiveVdot = estimateVDOT($targetDistKm, $effectiveTimeSec);
        $goalPaces = getTrainingPaces($effectiveVdot);
        $goalEasyRange = array_map('intval', $goalPaces['easy']);
        sort($goalEasyRange, SORT_NUMERIC);

        $formattedGoalPaces = [
            'easy'       => formatPaceSec($goalEasyRange[0]) . ' – ' . formatPaceSec($goalEasyRange[1]),
            'marathon'   => formatPaceSec((int) $goalPaces['marathon']),
            'threshold'  => formatPaceSec((int) $goalPaces['threshold']),
            'interval'   => formatPaceSec((int) $goalPaces['interval']),
            'repetition' => formatPaceSec((int) $goalPaces['repetition']),
        ];

        $formattedCurrentPaces = null;
        if (is_array($currentPaces)) {
            $curEasy = array_map('intval', $currentPaces['easy']);
            sort($curEasy, SORT_NUMERIC);
            $formattedCurrentPaces = [
                'easy'       => formatPaceSec($curEasy[0]) . ' – ' . formatPaceSec($curEasy[1]),
                'marathon'   => formatPaceSec((int) $currentPaces['marathon']),
                'threshold'  => formatPaceSec((int) $currentPaces['threshold']),
                'interval'   => formatPaceSec((int) $currentPaces['interval']),
                'repetition' => formatPaceSec((int) $currentPaces['repetition']),
            ];
        }

        return [
            'mode' => $mode,
            'effective_target_time' => formatTimeSec((int) $effectiveTimeSec),
            'effective_target_pace' => formatPaceSec($effectivePaceSec),
            'effective_target_vdot' => round($effectiveVdot, 1),
            'goal_target_time' => $goalTimeSec > 0 ? formatTimeSec($goalTimeSec) : null,
            'goal_target_pace' => $goalPaceSec ? formatPaceSec($goalPaceSec) : null,
            'predicted_target_time' => $predictedTargetSec !== null ? formatTimeSec($predictedTargetSec) : null,
            'stretch_target_time' => $stretchTargetSec !== null ? formatTimeSec((int) $stretchTargetSec) : null,
            'stretch_target_vdot' => $stretchVdot !== null ? round($stretchVdot, 1) : null,
            'weeks_to_goal' => $weeksToGoal > 0 ? $weeksToGoal : null,
            'gap_pct' => $gapPct,
            'severity' => $severity !== '' ? $severity : 'none',
            'goal_paces' => $formattedGoalPaces,
            'current_paces' => $formattedCurrentPaces,
            'race_distance' => $user['race_distance'] ?? null,
        ];
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
