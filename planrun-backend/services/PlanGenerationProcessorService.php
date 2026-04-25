<?php
/**
 * Исполнение задач генерации плана из очереди.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../planrun_ai/plan_generator.php';
require_once __DIR__ . '/../planrun_ai/plan_saver.php';
require_once __DIR__ . '/../training_utils.php';
require_once __DIR__ . '/../repositories/WeekRepository.php';
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/TrainingStateBuilder.php';
require_once __DIR__ . '/PlanExplanationService.php';
require_once __DIR__ . '/AiObservabilityService.php';
require_once __DIR__ . '/PlanQualityGate.php';

class PlanGenerationProcessorService extends BaseService {
    public function process(int $userId, string $jobType = 'generate', array $payload = []): array {
        if ($userId < 1) {
            throw new InvalidArgumentException('Не указан user_id', 400);
        }

        $observability = new AiObservabilityService($this->db);
        $traceId = $observability->createTraceId('plan_generation');
        $startedAt = microtime(true);
        $obsStatus = 'ok';
        $obsPayload = ['job_type' => $jobType];

        try {
            $useSkeletonGenerator = (bool) (env('USE_SKELETON_GENERATOR', '0'));

            $userReason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
            $userGoals = isset($payload['goals']) ? trim((string) $payload['goals']) : null;
            $isRecalculate = $jobType === 'recalculate';
            $isNextPlan = $jobType === 'next_plan';

            $mode = $isNextPlan ? 'НОВЫЙ ПЛАН' : ($isRecalculate ? 'ПЕРЕСЧЁТ' : 'ГЕНЕРАЦИЯ');
            $this->logInfo("Начало {$mode} плана", [
                'user_id' => $userId,
                'job_type' => $jobType,
                'skeleton_generator' => $useSkeletonGenerator,
            ]);

            if ($useSkeletonGenerator) {
                $result = $this->processViaSkeleton($userId, $jobType, $payload);
                $planData = $result['plan'];
                $cutoffDate = $result['cutoff_date'] ?? null;
                $keptWeeks = $result['kept_weeks'] ?? null;
                $mutableFromDate = $result['mutable_from_date'] ?? null;
                $generatedStartDate = $result['start_date'] ?? null;
                $trainingState = is_array($result['training_state'] ?? null) ? $result['training_state'] : null;
            } elseif ($isNextPlan) {
                $planData = generateNextPlanViaPlanRunAI($userId, $userGoals);
                $generatedStartDate = null;
                $trainingState = null;
                $mutableFromDate = null;
            } elseif ($isRecalculate) {
                $result = recalculatePlanViaPlanRunAI($userId, $userReason);
                $planData = $result['plan'];
                $cutoffDate = $result['cutoff_date'];
                $keptWeeks = $result['kept_weeks'];
                $generatedStartDate = null;
                $trainingState = null;
                $mutableFromDate = null;
            } else {
                $planData = generatePlanViaPlanRunAI($userId);
                $generatedStartDate = null;
                $trainingState = null;
                $mutableFromDate = null;
            }

            if (!$planData || !isset($planData['weeks']) || empty($planData['weeks'])) {
                throw new RuntimeException('План не содержит данных о неделях', 500);
            }

            $planData = $this->attachGenerationExplanation($userId, $jobType, $payload, $planData, $trainingState ?? null);

            // Загружаем preferences пользователя для enforcement расписания в нормализаторе
            $userPreferences = $this->loadUserPreferences($userId);

            $startDate = null;
            $alignedStartDate = null;
            if (!empty($planData['_generation_metadata']['schedule_anchor_date'])) {
                $alignedStartDate = (string) $planData['_generation_metadata']['schedule_anchor_date'];
            }
            if ($isNextPlan) {
                $startDate = is_string($generatedStartDate) && $generatedStartDate !== ''
                    ? $generatedStartDate
                    : (new DateTime())->modify('monday this week')->format('Y-m-d');
                saveTrainingPlan($this->db, $userId, $planData, $startDate, $userPreferences);
                $this->getUserRepository()->update($userId, ['training_start_date' => $startDate]);
            } elseif ($isRecalculate) {
                saveRecalculatedPlan(
                    $this->db,
                    $userId,
                    $planData,
                    $cutoffDate,
                    $userPreferences,
                    isset($mutableFromDate) ? (string) $mutableFromDate : null
                );
            } else {
                $userRepo = $this->getUserRepository();
                $currentTrainingStartDate = $userRepo->getField($userId, 'training_start_date') ?? null;
                $startDate = $alignedStartDate ?: ($currentTrainingStartDate ?? date('Y-m-d'));
                saveTrainingPlan($this->db, $userId, $planData, $startDate, $userPreferences);
                if ($alignedStartDate !== null && $alignedStartDate !== $currentTrainingStartDate) {
                    $userRepo->update($userId, ['training_start_date' => $alignedStartDate]);
                }
            }

            $this->syncLatestTrainingPlanSnapshot($userId, $startDate, $planData);
            $reviewStartDate = $startDate ?? ($cutoffDate ?? date('Y-m-d'));
            $this->appendPlanReview($userId, $planData, $reviewStartDate, $mode);

            $resultPayload = [
                'user_id' => $userId,
                'job_type' => $jobType,
                'weeks_count' => count($planData['weeks']),
            ];
            if (!empty($planData['_generation_metadata']) && is_array($planData['_generation_metadata'])) {
                $resultPayload['generation_metadata'] = $planData['_generation_metadata'];
            }
            if (isset($keptWeeks)) {
                $resultPayload['kept_weeks'] = $keptWeeks;
            }
            if (isset($cutoffDate)) {
                $resultPayload['cutoff_date'] = $cutoffDate;
            }
            if ($startDate) {
                $resultPayload['start_date'] = $startDate;
            }

            $this->logInfo("Завершено {$mode} плана", [
                'user_id' => $userId,
                'job_type' => $jobType,
                'weeks_count' => count($planData['weeks']),
                'repair_count' => $planData['_generation_metadata']['repair_count'] ?? 0,
                'prompt_version' => $planData['_generation_metadata']['prompt_version'] ?? null,
                'policy_version' => $planData['_generation_metadata']['policy_version'] ?? null,
                'vdot_source' => $planData['_generation_metadata']['vdot_source'] ?? null,
                'validation_errors_count' => count($planData['_generation_metadata']['final_validation_errors'] ?? []),
            ]);

            $obsPayload['weeks_count'] = $resultPayload['weeks_count'];
            $obsPayload['generator'] = $planData['_generation_metadata']['generator'] ?? ($useSkeletonGenerator ? 'PlanSkeletonGenerator' : 'legacy');
            $obsPayload['explanation_summary'] = $planData['_generation_metadata']['explanation']['summary'] ?? null;
            return $resultPayload;
        } catch (Throwable $e) {
            $obsStatus = 'error';
            $obsPayload['error'] = $e->getMessage();
            throw $e;
        } finally {
            $observability->logEvent(
                'plan_generation',
                'process',
                $obsStatus,
                $obsPayload,
                $userId,
                $traceId,
                (int) round((microtime(true) - $startedAt) * 1000)
            );
        }
    }

    /**
     * Новый путь генерации: PlanSkeletonGenerator + LLM-обогащение + LLM-ревью.
     */
    private function processViaSkeleton(int $userId, string $jobType, array $payload): array {
        require_once __DIR__ . '/../planrun_ai/skeleton/PlanSkeletonGenerator.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/LLMEnricher.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/SkeletonValidator.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/LLMReviewer.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/PlanAutoFixer.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/StartRunningProgramBuilder.php';

        // Шаг 0: Для recalculate — собрать реальные данные тренировок из БД
        if ($jobType === 'recalculate') {
            $payload = $this->enrichRecalculatePayload($userId, $payload);
        } elseif ($jobType === 'next_plan') {
            $payload = $this->enrichNextPlanPayload($userId, $payload);
        }

        // Шаг 1: Генерация числового скелета (без LLM)
        $generator = new PlanSkeletonGenerator($this->db);
        $skeleton = $generator->generate($userId, $jobType, $payload);

        if (empty($skeleton['weeks'])) {
            throw new RuntimeException('Скелет плана пуст', 500);
        }

        $user = $generator->getLastUser();
        $state = $generator->getLastState();
        $paceRules = $state['pace_rules'] ?? [];
        $loadPolicy = $state['load_policy'] ?? [];

        $this->logInfo('Скелет сгенерирован', [
            'user_id' => $userId,
            'weeks' => count($skeleton['weeks']),
            'vdot' => $state['vdot'] ?? null,
        ]);

        // Шаг 2: LLM-обогащение (notes, structure)
        $enricher = new LLMEnricher();
        $enrichContext = [
            'reason' => $payload['reason'] ?? null,
            'goals' => $payload['goals'] ?? null,
            'job_type' => $jobType,
        ];
        $enriched = $enricher->enrich($skeleton, $user, $state, $enrichContext);

        // Шаг 3: Алгоритмическая валидация — LLM не сломала числа
        $validationErrors = SkeletonValidator::validateAgainstOriginal($skeleton, $enriched);
        if (!empty($validationErrors)) {
            $this->logInfo('LLM-обогащение сломало числа, используем fallback', [
                'errors_count' => count($validationErrors),
            ]);
            $enriched = SkeletonValidator::addAlgorithmicNotes($skeleton);
        }

        // Шаг 4: LLM-ревью (проверка логики)
        $reviewer = new LLMReviewer();
        $review = $reviewer->review($enriched, $user, $state);

        $maxIterations = 2;
        $iteration = 0;

        while ($review['status'] === 'has_issues' && !empty($review['issues']) && $iteration < $maxIterations) {
            $iteration++;
            $this->logInfo("LLM-ревью нашло ошибки, автофикс (итерация {$iteration})", [
                'issues_count' => count($review['issues']),
            ]);

            // Шаг 5: Автофикс
            $fixResult = PlanAutoFixer::fix($enriched, $review['issues'], $paceRules, $loadPolicy);
            $enriched = $fixResult['plan'];

            if ($fixResult['fixes_applied'] === 0) {
                break;
            }

            // Повторное ревью
            if ($iteration < $maxIterations) {
                $review = $reviewer->review($enriched, $user, $state);
            }
        }

        // Шаг 6: Финальная алгоритмическая валидация + автоисправление
        $consistencyErrors = SkeletonValidator::validateConsistency($enriched, $paceRules, $state);
        if (!empty($consistencyErrors)) {
            $this->logInfo('Финальная валидация нашла ошибки, исправляем', [
                'errors' => array_map(fn($e) => $e['description'] ?? $e['type'], $consistencyErrors),
            ]);
            $fixResult = PlanAutoFixer::fix($enriched, $consistencyErrors, $paceRules, $loadPolicy);
            $enriched = $fixResult['plan'];

            // Повторная проверка после исправлений
            $remainingErrors = SkeletonValidator::validateConsistency($enriched, $paceRules, $state);
            if (!empty($remainingErrors)) {
                $this->logInfo('Остались неисправленные ошибки после финальной валидации', [
                    'errors' => array_map(fn($e) => $e['description'] ?? $e['type'], $remainingErrors),
                ]);
            }
            $consistencyErrors = $remainingErrors;
        }

        $enriched = $this->enforceRaceDayConsistency($enriched, $state, $user);

        $qualityGateStartDate = $jobType === 'recalculate'
            ? (string) ($payload['cutoff_date'] ?? date('Y-m-d'))
            : (string) (($skeleton['_metadata']['schedule_anchor_date'] ?? $user['training_start_date'] ?? date('Y-m-d')));
        $qualityGate = new PlanQualityGate();
        $qualityGateResult = $qualityGate->evaluate($enriched, $qualityGateStartDate, $state, [
            'goal_type' => $user['goal_type'] ?? null,
            'preferred_days' => $this->decodeWeekdayPreferenceField($user['preferred_days'] ?? null),
            'user_preferences' => $this->loadUserPreferences((int) ($user['id'] ?? $userId)),
            'expected_skeleton' => $this->buildExpectedSkeletonContract($skeleton),
            'planning_scenario' => $state['planning_scenario'] ?? null,
        ]);
        if ($qualityGateResult['should_block_save'] ?? false) {
            throw new RuntimeException(
                'План не прошёл final quality gate: ' . $this->buildQualityGateFailureMessage((array) ($qualityGateResult['issues'] ?? [])),
                500
            );
        }

        $finalPlan = is_array($qualityGateResult['normalized_plan'] ?? null)
            ? (array) $qualityGateResult['normalized_plan']
            : $enriched;

        $finalPlan['_generation_metadata'] = array_merge(
            $skeleton['_metadata'] ?? [],
            [
                'llm_review_iterations' => $iteration,
                'llm_review_final_status' => $review['status'] ?? 'ok',
                'consistency_errors' => count($consistencyErrors),
                'generator' => 'PlanSkeletonGenerator',
                'quality_gate' => [
                    'status' => $qualityGateResult['status'] ?? 'ok',
                    'score' => (int) ($qualityGateResult['score'] ?? 0),
                    'repairs_applied' => !empty($qualityGateResult['repairs_applied']),
                    'issue_codes' => array_values(array_map(
                        static fn(array $issue): string => (string) ($issue['code'] ?? 'unknown_issue'),
                        (array) ($qualityGateResult['issues'] ?? [])
                    )),
                    'normalizer_warnings' => array_values((array) ($qualityGateResult['normalizer_warnings'] ?? [])),
                ],
            ]
        );

        $result = [
            'plan' => $finalPlan,
            'training_state' => $state,
        ];

        // Для recalculate — передаём cutoff данные
        if ($jobType === 'recalculate') {
            $result['cutoff_date'] = $payload['cutoff_date'] ?? (new DateTime())->modify('monday this week')->format('Y-m-d');
            $result['kept_weeks'] = $payload['kept_weeks'] ?? 0;
            $result['mutable_from_date'] = $payload['mutable_from_date'] ?? null;
        } elseif ($jobType === 'next_plan') {
            $result['start_date'] = $payload['cutoff_date']
                ?? ($skeleton['_metadata']['schedule_anchor_date'] ?? (new DateTime())->modify('monday this week')->format('Y-m-d'));
        }

        return $result;
    }

    private function buildQualityGateFailureMessage(array $issues): string {
        $blockingIssues = array_values(array_filter(
            $issues,
            static fn(array $issue): bool => (string) ($issue['severity'] ?? 'warning') === 'error'
        ));
        $issuesForMessage = $blockingIssues !== [] ? $blockingIssues : array_values($issues);
        $messages = array_map(
            static fn(array $issue): string => (string) ($issue['message'] ?? ($issue['code'] ?? 'quality_gate_error')),
            array_slice($issuesForMessage, 0, 3)
        );

        return $messages !== [] ? implode(' | ', $messages) : 'quality_gate_error';
    }

    private function enforceRaceDayConsistency(array $plan, array $trainingState, array $user): array {
        $raceDistance = (string) ($trainingState['race_distance'] ?? ($user['race_distance'] ?? ''));
        $raceDistanceKm = $this->resolveRaceDistanceKm($raceDistance);
        if ($raceDistanceKm <= 0) {
            return $plan;
        }

        $goalPaceSec = null;
        if (!empty($trainingState['goal_pace_sec'])) {
            $goalPaceSec = (int) $trainingState['goal_pace_sec'];
        } elseif (!empty($trainingState['goal_pace'])) {
            $goalPaceSec = parsePaceToSeconds((string) $trainingState['goal_pace']);
        } elseif (!empty($trainingState['training_paces']['marathon'])) {
            $goalPaceSec = (int) $trainingState['training_paces']['marathon'];
        }
        $goalPace = $goalPaceSec !== null && $goalPaceSec > 0 ? formatPaceFromSec($goalPaceSec) : null;

        $weeks = $plan['weeks'] ?? [];
        foreach ($weeks as &$week) {
            $days = $week['days'] ?? [];
            foreach ($days as &$day) {
                if (normalizeTrainingType($day['type'] ?? null) !== 'race') {
                    continue;
                }

                $day['distance_km'] = round($raceDistanceKm, 1);
                if ($goalPace !== null) {
                    $day['pace'] = $goalPace;
                    $day['duration_minutes'] = calculateDurationMinutes((float) $day['distance_km'], $goalPace);
                }
                $day['is_key_workout'] = true;
                if (function_exists('rebuildNormalizedDayArtifacts')) {
                    $day = rebuildNormalizedDayArtifacts($day);
                }
            }
            unset($day);

            $week['days'] = $days;
            $week['actual_volume_km'] = calculateNormalizedWeekVolume($days);
            if (isset($week['target_volume_km']) && (float) $week['target_volume_km'] < (float) $raceDistanceKm) {
                $week['target_volume_km'] = calculateNormalizedWeekVolume($days);
            }
        }
        unset($week);

        $plan['weeks'] = $weeks;
        return $plan;
    }

    private function resolveRaceDistanceKm(string $raceDistance): float {
        return match ($raceDistance) {
            '5k' => 5.0,
            '10k' => 10.0,
            'half', '21.1k' => 21.1,
            'marathon', '42.2k' => 42.2,
            default => 0.0,
        };
    }

    /**
     * Собрать реальные данные тренировок для recalculate.
     * Аналогично тому, что делает recalculatePlanViaPlanRunAI:
     * cutoff_date, kept_weeks, avg_weekly_km_4w, fresh_vdot.
     */
    private function enrichRecalculatePayload(int $userId, array $payload): array
    {
        $planningUser = $this->loadPlanningUserProfile($userId);
        $goalType = (string) ($planningUser['goal_type'] ?? 'health');

        // cutoff_date — понедельник текущей недели, но не раньше старта уже сохранённого плана
        if (empty($payload['cutoff_date'])) {
            $payload['cutoff_date'] = $this->resolveDefaultRecalculateCutoffDate($userId, $planningUser);
        } else {
            $payload['cutoff_date'] = $this->alignDateToMonday((string) $payload['cutoff_date']) ?? (string) $payload['cutoff_date'];
        }
        $cutoffDate = (string) $payload['cutoff_date'];

        // kept_weeks — сколько недель плана до cutoff
        if (!isset($payload['kept_weeks'])) {
            $weekRepo = $this->getWeekRepository();
            $payload['kept_weeks'] = $weekRepo->getMaxWeekNumberBefore($userId, $cutoffDate);
        }

        if (
            empty($payload['current_phase'])
            && !empty($planningUser)
            && function_exists('detectCurrentPhase')
        ) {
            $payload['current_phase'] = detectCurrentPhase($planningUser, $goalType, (int) ($payload['kept_weeks'] ?? 0));
        }

        // actual_weekly_km_4w — средний фактический объём за последние 4 недели
        if (empty($payload['actual_weekly_km_4w'])) {
            $fourWeeksAgo = (new DateTime())->modify('-28 days')->format('Y-m-d');
            $today = (new DateTime())->format('Y-m-d');

            // Из workout_log (ручные)
            $weekKms = [];
            $stmt = $this->db->prepare("
                SELECT wl.training_date,
                       wl.distance_km,
                       LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type
                FROM workout_log wl
                LEFT JOIN activity_types at ON at.id = wl.activity_type_id
                WHERE user_id = ? AND is_completed = 1
                  AND training_date >= ? AND training_date <= ?
            ");
            if ($stmt) {
                $stmt->bind_param('iss', $userId, $fourWeeksAgo, $today);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $dist = (float) ($r['distance_km'] ?? 0);
                    if ($dist > 0 && $this->isRunningRelevantManualActivity((string) ($r['activity_type'] ?? ''))) {
                        $weekKey = date('Y-W', strtotime($r['training_date']));
                        $weekKms[$weekKey] = ($weekKms[$weekKey] ?? 0) + $dist;
                    }
                }
                $stmt->close();
            }

            // Из workouts (автоматические с часов)
            $stmt = $this->db->prepare("
                SELECT DATE(start_time) AS workout_date,
                       distance_km,
                       LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type
                FROM workouts
                WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
            ");
            if ($stmt) {
                $stmt->bind_param('iss', $userId, $fourWeeksAgo, $today);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $dist = (float) ($r['distance_km'] ?? 0);
                    if ($dist > 0 && $this->isRunningRelevantImportedActivity((string) ($r['activity_type'] ?? ''))) {
                        $weekKey = date('Y-W', strtotime($r['workout_date']));
                        $weekKms[$weekKey] = ($weekKms[$weekKey] ?? 0) + $dist;
                    }
                }
                $stmt->close();
            }

            $weekCount = count($weekKms);
            if ($weekCount > 0) {
                $payload['actual_weekly_km_4w'] = round(array_sum($weekKms) / $weekCount, 1);
            }
        }

        // fresh_vdot — свежий VDOT из лучших результатов (уже считается в TrainingStateBuilder)
        // Не дублируем: TrainingStateBuilder.buildForUser() сам найдёт best_result/last_race

        if (empty($payload['mutable_from_date'])) {
            $today = (new DateTime())->format('Y-m-d');
            $hasRunningWorkoutToday = $this->hasRunningWorkoutOnDate($userId, $today);
            $mutableFromDate = resolveRecalculationCutoffDateValue($today, $hasRunningWorkoutToday);
            if ($mutableFromDate < $cutoffDate) {
                $mutableFromDate = $cutoffDate;
            }
            $payload['mutable_from_date'] = $mutableFromDate;
        }

        if (empty($payload['progression_counters'])) {
            $payload['progression_counters'] = $this->buildCompletedProgressionCounters($userId, $cutoffDate);
        }

        $payload['continuation_context'] = $this->buildRecalculateContinuationContext($payload);

        return $payload;
    }

    private function enrichNextPlanPayload(int $userId, array $payload): array
    {
        if (empty($payload['cutoff_date'])) {
            $payload['cutoff_date'] = (new DateTime())->modify('monday this week')->format('Y-m-d');
        } else {
            $payload['cutoff_date'] = $this->alignDateToMonday((string) $payload['cutoff_date']) ?? (string) $payload['cutoff_date'];
        }

        if (empty($payload['last_plan_avg_km'])) {
            $weekRepo = $this->getWeekRepository();
            $beforeDate = (string) $payload['cutoff_date'];

            $recentWeeks = $weekRepo->getRecentWeekSummaries($userId, 4, $beforeDate, true);
            if (empty($recentWeeks)) {
                $recentWeeks = $weekRepo->getRecentWeekSummaries($userId, 4, $beforeDate, false);
            }

            $volumes = array_values(array_filter(array_map(
                static fn(array $week): float => round((float) ($week['total_volume'] ?? 0.0), 1),
                $recentWeeks
            ), static fn(float $volume): bool => $volume > 0.0));

            if ($volumes !== []) {
                $payload['last_plan_avg_km'] = round(array_sum($volumes) / count($volumes), 1);
            }

            if ($recentWeeks !== []) {
                $payload['recent_plan_weeks'] = array_map(
                    static function (array $week): array {
                        return [
                            'week_number' => (int) ($week['week_number'] ?? 0),
                            'start_date' => (string) ($week['start_date'] ?? ''),
                            'total_volume' => round((float) ($week['total_volume'] ?? 0.0), 1),
                            'race_days' => (int) ($week['race_days'] ?? 0),
                        ];
                    },
                    array_values(array_reverse($recentWeeks))
                );
            }
        }

        $payload['continuation_context'] = $this->buildNextPlanContinuationContext($payload);

        return $payload;
    }

    private function resolveDefaultRecalculateCutoffDate(int $userId, array $planningUser): string
    {
        $baseline = (new DateTimeImmutable('now'))->modify('monday this week')->format('Y-m-d');
        $range = $this->getWeekRepository()->getPlanDateRange($userId);

        $candidates = [$baseline];
        $planStartDate = $this->alignDateToMonday((string) ($range['min_start_date'] ?? ''));
        if ($planStartDate !== null) {
            $candidates[] = $planStartDate;
        } elseif (!empty($planningUser['training_start_date'])) {
            $userStartDate = $this->alignDateToMonday((string) $planningUser['training_start_date']);
            if ($userStartDate !== null) {
                $candidates[] = $userStartDate;
            }
        }

        usort(
            $candidates,
            static fn(string $left, string $right): int => strcmp($left, $right)
        );

        return (string) end($candidates);
    }

    private function buildRecalculateContinuationContext(array $payload): array
    {
        return [
            'mode' => 'recalculate',
            'anchor_date' => (string) ($payload['cutoff_date'] ?? ''),
            'kept_weeks' => (int) ($payload['kept_weeks'] ?? 0),
            'current_phase' => is_array($payload['current_phase'] ?? null) ? $payload['current_phase'] : null,
            'actual_weekly_km_4w' => isset($payload['actual_weekly_km_4w']) ? (float) $payload['actual_weekly_km_4w'] : null,
            'progression_counters' => is_array($payload['progression_counters'] ?? null) ? $payload['progression_counters'] : [],
        ];
    }

    private function buildNextPlanContinuationContext(array $payload): array
    {
        return [
            'mode' => 'next_plan',
            'anchor_date' => (string) ($payload['cutoff_date'] ?? ''),
            'last_plan_avg_km' => isset($payload['last_plan_avg_km']) ? (float) $payload['last_plan_avg_km'] : null,
            'recent_plan_weeks' => is_array($payload['recent_plan_weeks'] ?? null) ? $payload['recent_plan_weeks'] : [],
        ];
    }

    private function buildCompletedProgressionCounters(int $userId, string $cutoffDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT type, date
             FROM training_plan_days
             WHERE user_id = ?
               AND date < ?
               AND type IN ('tempo', 'interval', 'fartlek', 'control')
             ORDER BY date ASC, day_of_week ASC"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('is', $userId, $cutoffDate);
        $stmt->execute();
        $res = $stmt->get_result();

        $plannedDays = [];
        $minDate = null;
        $maxDate = null;
        while ($row = $res->fetch_assoc()) {
            $date = (string) ($row['date'] ?? '');
            $type = (string) ($row['type'] ?? '');
            if ($date === '' || $type === '') {
                continue;
            }
            $plannedDays[] = ['date' => $date, 'type' => $type];
            $minDate = $minDate === null || strcmp($date, $minDate) < 0 ? $date : $minDate;
            $maxDate = $maxDate === null || strcmp($date, $maxDate) > 0 ? $date : $maxDate;
        }
        $stmt->close();

        if ($plannedDays === [] || $minDate === null || $maxDate === null) {
            return [];
        }

        $completedDates = $this->loadCompletedRunningDateSet($userId, $minDate, $maxDate);
        if ($completedDates === []) {
            return [];
        }

        $counters = [
            'tempo_count' => 0,
            'interval_count' => 0,
            'fartlek_count' => 0,
            'control_count' => 0,
            'completed_key_days' => 0,
        ];

        foreach ($plannedDays as $plannedDay) {
            $date = (string) ($plannedDay['date'] ?? '');
            if (!isset($completedDates[$date])) {
                continue;
            }

            $type = (string) ($plannedDay['type'] ?? '');
            switch ($type) {
                case 'tempo':
                    $counters['tempo_count']++;
                    $counters['completed_key_days']++;
                    break;
                case 'interval':
                    $counters['interval_count']++;
                    $counters['completed_key_days']++;
                    break;
                case 'fartlek':
                    $counters['fartlek_count']++;
                    $counters['completed_key_days']++;
                    break;
                case 'control':
                    $counters['control_count']++;
                    $counters['completed_key_days']++;
                    break;
            }
        }

        $counters['race_pace_count'] = intdiv((int) $counters['tempo_count'], 2);

        return $counters;
    }

    private function loadCompletedRunningDateSet(int $userId, string $fromDate, string $toDate): array
    {
        $dates = [];

        $stmt = $this->db->prepare(
            "SELECT wl.training_date,
                    LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type
             FROM workout_log wl
             LEFT JOIN activity_types at ON at.id = wl.activity_type_id
             WHERE wl.user_id = ? AND wl.is_completed = 1
               AND wl.training_date >= ? AND wl.training_date <= ?"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $fromDate, $toDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $activityType = (string) ($row['activity_type'] ?? '');
                if (!$this->isRunningRelevantManualActivity($activityType)) {
                    continue;
                }
                $date = (string) ($row['training_date'] ?? '');
                if ($date !== '') {
                    $dates[$date] = true;
                }
            }
            $stmt->close();
        }

        $stmt = $this->db->prepare(
            "SELECT DATE(start_time) AS workout_date,
                    LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type
             FROM workouts
             WHERE user_id = ?
               AND DATE(start_time) >= ? AND DATE(start_time) <= ?"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $fromDate, $toDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $activityType = (string) ($row['activity_type'] ?? '');
                if (!$this->isRunningRelevantImportedActivity($activityType)) {
                    continue;
                }
                $date = (string) ($row['workout_date'] ?? '');
                if ($date !== '') {
                    $dates[$date] = true;
                }
            }
            $stmt->close();
        }

        return $dates;
    }

    private function loadPlanningUserProfile(int $userId): array
    {
        $user = $this->getUserRepository()->getForPlanning($userId);
        if (!is_array($user)) {
            return [];
        }

        foreach (['preferred_days', 'preferred_ofp_days'] as $field) {
            if (!empty($user[$field]) && is_string($user[$field])) {
                $decoded = json_decode($user[$field], true);
                $user[$field] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($user[$field] ?? null)) {
                $user[$field] = [];
            }
        }

        return $user;
    }

    private function alignDateToMonday(string $date): ?string
    {
        $value = trim($date);
        if ($value === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }

        $dayOfWeek = (int) $dt->format('N');
        if ($dayOfWeek === 1) {
            return $dt->format('Y-m-d');
        }

        return $dt->modify('-' . ($dayOfWeek - 1) . ' days')->format('Y-m-d');
    }

    private function hasRunningWorkoutOnDate(int $userId, string $date): bool
    {
        $stmt = $this->db->prepare("
            SELECT wl.distance_km,
                   LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type,
                   'manual' AS source,
                   NULL AS plan_type
            FROM workout_log wl
            LEFT JOIN activity_types at ON at.id = wl.activity_type_id
            WHERE wl.user_id = ? AND wl.is_completed = 1 AND wl.training_date = ?
        ");
        if ($stmt) {
            $stmt->bind_param('is', $userId, $date);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (isRunningRelevantWorkoutEntry([
                    'distance_km' => (float) ($row['distance_km'] ?? 0.0),
                    'activity_type' => (string) ($row['activity_type'] ?? ''),
                    'source' => 'manual',
                    'plan_type' => '',
                ])) {
                    $stmt->close();
                    return true;
                }
            }
            $stmt->close();
        }

        $stmt = $this->db->prepare("
            SELECT distance_km,
                   LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type,
                   'strava' AS source,
                   NULL AS plan_type
            FROM workouts
            WHERE user_id = ? AND DATE(start_time) = ?
        ");
        if ($stmt) {
            $stmt->bind_param('is', $userId, $date);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (isRunningRelevantWorkoutEntry([
                    'distance_km' => (float) ($row['distance_km'] ?? 0.0),
                    'activity_type' => (string) ($row['activity_type'] ?? ''),
                    'source' => (string) ($row['source'] ?? 'strava'),
                    'plan_type' => '',
                ])) {
                    $stmt->close();
                    return true;
                }
            }
            $stmt->close();
        }

        return false;
    }

    private function isRunningRelevantManualActivity(string $activityType): bool {
        $normalized = trim(mb_strtolower($activityType, 'UTF-8'));
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, ['running', 'run', 'trail running', 'treadmill'], true);
    }

    private function isRunningRelevantImportedActivity(string $activityType): bool {
        $normalized = trim(mb_strtolower($activityType, 'UTF-8'));
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, ['running', 'run', 'trail running', 'treadmill'], true);
    }

    public function persistFailure(int $userId, string $message): void {
        $latestId = $this->findLatestTrainingPlanSnapshotId($userId);
        if ($latestId === null) {
            $this->createTrainingPlanSnapshot($userId, null, null, null, $message, null, false);
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE user_training_plans 
            SET is_active = FALSE,
                error_message = ?
            WHERE id = ?
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $message, $latestId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Загружает предпочтения пользователя (preferred_days, preferred_ofp_days)
     * для передачи в нормализатор плана.
     */
    private function loadUserPreferences(int $userId): ?array {
        $userRepo = $this->getUserRepository();
        $prefDaysRaw = $userRepo->getField($userId, 'preferred_days');
        $ofpDaysRaw = $userRepo->getField($userId, 'preferred_ofp_days');

        $prefDays = $this->decodeWeekdayPreferenceField($prefDaysRaw);
        $ofpDays = $this->decodeWeekdayPreferenceField($ofpDaysRaw);

        // Если preferred_days пустой — пользователь не указал конкретные дни, нет ограничений
        if (empty($prefDays)) {
            return null;
        }

        return [
            'preferred_days' => $prefDays,
            'preferred_ofp_days' => $ofpDays,
        ];
    }

    private function decodeWeekdayPreferenceField(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw), static fn(string $value): bool => $value !== ''));
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded), static fn(string $value): bool => $value !== ''));
    }

    private function buildExpectedSkeletonContract(array $skeleton): array
    {
        $contractWeeks = [];
        foreach (($skeleton['weeks'] ?? []) as $week) {
            $contractWeeks[] = [
                'week_number' => (int) ($week['week_number'] ?? 0),
                'phase' => isset($week['phase']) ? (string) $week['phase'] : null,
                'is_recovery' => !empty($week['is_recovery']),
                'days' => array_map(
                    static fn(array $day): string => (string) ($day['type'] ?? 'rest'),
                    (array) ($week['days'] ?? [])
                ),
            ];
        }

        return ['weeks' => $contractWeeks];
    }

    private function getUserRepository(): UserRepository {
        return new UserRepository($this->db);
    }

    private function getWeekRepository(): WeekRepository {
        return new WeekRepository($this->db);
    }

    private function activateLatestPlan(int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE user_training_plans 
            SET is_active = TRUE 
            WHERE user_id = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function syncLatestTrainingPlanSnapshot(int $userId, ?string $startDate, array $planData): void {
        $user = $this->getUserRepository()->getById($userId, false);
        if (!$user) {
            return;
        }

        $latestId = $this->findLatestTrainingPlanSnapshotId($userId);
        $resolvedStartDate = $this->resolveTrainingPlanSnapshotStartDate($user, $startDate);
        [$planDate, $targetTime] = $this->resolveTrainingPlanSnapshotTargets($user);
        $planDescription = $this->resolveTrainingPlanSnapshotDescription($planData);

        if ($latestId === null) {
            $createdId = $this->createTrainingPlanSnapshot($userId, $resolvedStartDate, $planDate, $targetTime, null, $planDescription, true);
            if ($createdId !== null) {
                $this->deactivateOtherTrainingPlanSnapshots($userId, $createdId);
            }
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE user_training_plans
            SET start_date = ?,
                marathon_date = ?,
                target_time = ?,
                is_active = TRUE,
                error_message = NULL,
                plan_description = ?
            WHERE id = ?
        ");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('ssssi', $resolvedStartDate, $planDate, $targetTime, $planDescription, $latestId);
        $stmt->execute();
        $stmt->close();
        $this->deactivateOtherTrainingPlanSnapshots($userId, $latestId);
    }

    private function findLatestTrainingPlanSnapshotId(int $userId): ?int {
        $stmt = $this->db->prepare('SELECT id FROM user_training_plans WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row ? (int) $row['id'] : null;
    }

    private function resolveTrainingPlanSnapshotStartDate(array $user, ?string $startDate): string {
        if (is_string($startDate) && $startDate !== '') {
            return $startDate;
        }

        if (!empty($user['training_start_date'])) {
            return (string) $user['training_start_date'];
        }

        return date('Y-m-d');
    }

    private function resolveTrainingPlanSnapshotTargets(array $user): array {
        $goalType = (string) ($user['goal_type'] ?? 'health');

        if (in_array($goalType, ['race', 'time_improvement'], true)) {
            $planDate = !empty($user['race_date'])
                ? (string) $user['race_date']
                : (!empty($user['target_marathon_date']) ? (string) $user['target_marathon_date'] : null);
            $targetTime = !empty($user['race_target_time'])
                ? substr((string) $user['race_target_time'], -5)
                : (!empty($user['target_marathon_time']) ? substr((string) $user['target_marathon_time'], -5) : null);
            return [$planDate, $targetTime];
        }

        if ($goalType === 'weight_loss') {
            $planDate = !empty($user['weight_goal_date'])
                ? (string) $user['weight_goal_date']
                : (!empty($user['target_marathon_date']) ? (string) $user['target_marathon_date'] : null);
            return [$planDate, null];
        }

        $planDate = !empty($user['target_marathon_date']) ? (string) $user['target_marathon_date'] : null;
        $targetTime = !empty($user['target_marathon_time']) ? substr((string) $user['target_marathon_time'], -5) : null;
        return [$planDate, $targetTime];
    }

    private function resolveTrainingPlanSnapshotDescription(array $planData): ?string {
        $summary = trim((string) ($planData['_generation_metadata']['explanation']['summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        return null;
    }

    private function createTrainingPlanSnapshot(
        int $userId,
        ?string $startDate,
        ?string $planDate,
        ?string $targetTime,
        ?string $errorMessage,
        ?string $planDescription,
        bool $isActive
    ): ?int {
        $stmt = $this->db->prepare('
            INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active, error_message, plan_description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        if (!$stmt) {
            return null;
        }

        $active = $isActive ? 1 : 0;
        $resolvedStartDate = $startDate ?: date('Y-m-d');
        $stmt->bind_param('isssiss', $userId, $resolvedStartDate, $planDate, $targetTime, $active, $errorMessage, $planDescription);
        $stmt->execute();
        $snapshotId = (int) $this->db->insert_id;
        $stmt->close();

        return $snapshotId > 0 ? $snapshotId : null;
    }

    private function deactivateOtherTrainingPlanSnapshots(int $userId, int $activePlanId): void {
        $stmt = $this->db->prepare(
            'UPDATE user_training_plans SET is_active = FALSE WHERE user_id = ? AND id <> ? AND is_active = TRUE'
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('ii', $userId, $activePlanId);
        $stmt->execute();
        $stmt->close();
    }

    private function appendPlanReview(int $userId, array $planData, string $reviewStartDate, string $mode): void {
        try {
            require_once __DIR__ . '/../planrun_ai/plan_review_generator.php';
            require_once __DIR__ . '/ChatService.php';
            $review = generatePlanReview($planData, $reviewStartDate, $mode);
            if ($review === null || $review === '') {
                $review = $this->buildFallbackPlanReview($planData, $reviewStartDate, $mode);
            }

            $explanationSummary = trim((string) ($planData['_generation_metadata']['explanation']['summary'] ?? ''));
            if ($explanationSummary !== '') {
                $review = trim($review !== '' ? ($review . "\n\nКоротко почему: " . $explanationSummary) : ('Коротко почему: ' . $explanationSummary));
            }

            if ($review === null || $review === '') {
                return;
            }

            $chatService = new ChatService($this->db);
            $eventKey = match ($mode) {
                'ПЕРЕСЧЁТ' => 'plan.recalculated',
                'НОВЫЙ ПЛАН' => 'plan.next_generated',
                default => 'plan.generated',
            };
            $title = match ($mode) {
                'ПЕРЕСЧЁТ' => 'План пересчитан',
                'НОВЫЙ ПЛАН' => 'Следующий план готов',
                default => 'План сгенерирован',
            };
            $chatService->addAIMessageToUser($userId, $review, [
                'event_key' => $eventKey,
                'title' => $title,
                'link' => '/chat',
            ]);
        } catch (Throwable $reviewEx) {
            $this->logError('Рецензия плана не добавлена', [
                'user_id' => $userId,
                'error' => $reviewEx->getMessage(),
            ]);
        }
    }

    private function buildFallbackPlanReview(array $planData, string $reviewStartDate, string $mode): string {
        $summaryPrefix = match ($mode) {
            'ПЕРЕСЧЁТ' => 'План успешно пересчитан.',
            'НОВЫЙ ПЛАН' => 'Новый план успешно сформирован.',
            default => 'План успешно сгенерирован.',
        };

        $weeksCount = count($planData['weeks'] ?? []);
        $longDayLabel = null;
        $restLabels = [];
        $dayLabels = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];

        try {
            require_once __DIR__ . '/../planrun_ai/plan_normalizer.php';
            $normalized = normalizeTrainingPlan($planData, $reviewStartDate);
            $firstWeekDays = $normalized['weeks'][0]['days'] ?? [];
            foreach ($firstWeekDays as $index => $day) {
                $type = normalizeTrainingType($day['type'] ?? null);
                if ($type === 'long' && $longDayLabel === null) {
                    $longDayLabel = $dayLabels[$index] ?? null;
                }
                if ($type === 'rest') {
                    $restLabels[] = $dayLabels[$index] ?? ('День ' . ($index + 1));
                }
            }
        } catch (Throwable $normalizationError) {
            // Fallback message should still be delivered even if normalization failed.
        }

        $parts = [
            $summaryPrefix,
            "Я обновил календарь начиная с недели от {$reviewStartDate}.",
        ];

        if ($weeksCount > 0) {
            $parts[] = "В обновлённой части плана {$weeksCount} " . $this->formatWeeksLabel($weeksCount) . '.';
        }
        if ($longDayLabel !== null) {
            $parts[] = "Длительная сейчас стоит на {$longDayLabel}.";
        }
        if (!empty($restLabels)) {
            $parts[] = 'Дни отдыха на первой обновлённой неделе: ' . implode(', ', $restLabels) . '.';
        }

        $parts[] = 'Проверь календарь. Если нужно ещё скорректировать структуру, напиши это в пересчёте или в чате.';

        return implode(' ', $parts);
    }

    private function formatWeeksLabel(int $weeksCount): string {
        $mod100 = $weeksCount % 100;
        $mod10 = $weeksCount % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'недель';
        }
        return match ($mod10) {
            1 => 'неделя',
            2, 3, 4 => 'недели',
            default => 'недель',
        };
    }

    private function attachGenerationExplanation(int $userId, string $jobType, array $payload, array $planData, ?array $trainingState = null): array {
        try {
            $service = new PlanExplanationService($this->db);
            $explanation = $service->buildExplanation($userId, $jobType, $payload, $planData, $trainingState);
            $metadata = is_array($planData['_generation_metadata'] ?? null) ? $planData['_generation_metadata'] : [];
            $metadata['explanation'] = $explanation;
            $planData['_generation_metadata'] = $metadata;
        } catch (Throwable $e) {
            $this->logInfo('Не удалось собрать explanation metadata для плана', [
                'user_id' => $userId,
                'job_type' => $jobType,
                'error' => $e->getMessage(),
            ]);
        }

        return $planData;
    }
}
