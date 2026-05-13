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
require_once __DIR__ . '/AiPlanGenerationEventLogger.php';
require_once __DIR__ . '/PlanQualityGate.php';

class PlanGenerationProcessorService extends BaseService {
    public function process(int $userId, string $jobType = 'generate', array $payload = [], ?int $jobId = null): array {
        if ($userId < 1) {
            throw new InvalidArgumentException('Не указан user_id', 400);
        }

        $observability = new AiObservabilityService($this->db);
        $traceId = $observability->createTraceId('plan_generation');
        $startedAt = microtime(true);
        $obsStatus = 'ok';
        $obsPayload = ['job_type' => $jobType];
        if ($jobId !== null && $jobId > 0) {
            $obsPayload['job_id'] = $jobId;
        }

        // PR6 / Phase D.1: structured event logger для plan-generation observability.
        $planEventLogger = new AiPlanGenerationEventLogger($this->db);
        $planEventTrainingState = [];
        $planEventLogged = false;

        try {
            // PR7 / Phase D.3: единственный production-путь — llm_planner (DeepSeek V4).
            // USE_SKELETON_GENERATOR полностью удалён вместе с _legacy/skeleton/.
            // Если PLAN_GENERATION_MODE не задан, идём через прямые legacy-функции
            // (generatePlanViaPlanRunAI / recalculatePlanViaPlanRunAI), которые сами
            // используют DeepSeek через старый pipeline.
            $generationMode = strtolower(trim((string) env('PLAN_GENERATION_MODE', '')));
            $useLlmPlanner = $generationMode === 'llm_planner';

            $userReason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
            $userGoals = isset($payload['goals']) ? trim((string) $payload['goals']) : null;
            $isRecalculate = $jobType === 'recalculate';
            $isNextPlan = $jobType === 'next_plan';

            $mode = $isNextPlan ? 'НОВЫЙ ПЛАН' : ($isRecalculate ? 'ПЕРЕСЧЁТ' : 'ГЕНЕРАЦИЯ');
            $this->logInfo("Начало {$mode} плана", [
                'user_id' => $userId,
                'job_type' => $jobType,
                'generation_mode' => $useLlmPlanner ? 'llm_planner' : 'legacy',
            ]);

            // Не sync'аем users.race_target_time перед generation: это user intent,
            // не подменяем под текущую форму. Planner и critique получают
            // effective_target_time через training_state.pace_strategy.

            if ($useLlmPlanner) {
                $result = $this->processViaLlmPlanner($userId, $jobType, $payload, $traceId);
                $planData = $result['plan'];
                $cutoffDate = $result['cutoff_date'] ?? null;
                $keptWeeks = $result['kept_weeks'] ?? null;
                $mutableFromDate = $result['mutable_from_date'] ?? null;
                $generatedStartDate = $result['start_date'] ?? null;
                $trainingState = is_array($result['training_state'] ?? null) ? $result['training_state'] : null;
                $llmUsage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
                if (is_array($trainingState)) {
                    $planEventTrainingState = $trainingState;
                }
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

            // Self-critique pass — независимый LLM-вызов ревьюит план как opposing coach.
            // Если найдёт critical/moderate проблемы → второй вызов revisePlanWithCritique
            // переделывает план. Работает для всех путей (llm_planner, legacy generate/recalculate/next).
            $planData = $this->applyPlanCritique($planData, $userId, $mode, $trainingState ?? null);

            // Safety-net: revision-pass иногда удаляет/изменяет manual race-дни. Повторно
            // принудительно ставим intermediate-races из training_state после critique.
            if (is_array($trainingState ?? null) && !empty($trainingState['intermediate_races'])) {
                $planData = $this->ensureIntermediateRacesInPlan($planData, (array) $trainingState['intermediate_races']);
            }

            $planData = $this->attachGenerationExplanation($userId, $jobType, $payload, $planData, $trainingState ?? null);

            // Загружаем preferences пользователя для enforcement расписания в нормализаторе
            $userPreferences = $this->loadUserPreferences($userId);

            $startDate = null;
            $alignedStartDate = null;
            if (!empty($planData['_generation_metadata']['schedule_anchor_date'])) {
                $alignedStartDate = (string) $planData['_generation_metadata']['schedule_anchor_date'];
            }
            // LLM planner plans already went through PlanQualityGate → normalizeTrainingPlan;
            // skip re-normalization to avoid double processing (long→easy in race weeks, etc.)
            $skipNormalization = $useLlmPlanner;

            if ($isNextPlan) {
                $startDate = is_string($generatedStartDate) && $generatedStartDate !== ''
                    ? $generatedStartDate
                    : (new DateTime())->modify('monday this week')->format('Y-m-d');
                saveTrainingPlan($this->db, $userId, $planData, $startDate, $userPreferences, $skipNormalization);
                $this->getUserRepository()->update($userId, ['training_start_date' => $startDate]);
            } elseif ($isRecalculate) {
                saveRecalculatedPlan(
                    $this->db,
                    $userId,
                    $planData,
                    $cutoffDate,
                    $userPreferences,
                    isset($mutableFromDate) ? (string) $mutableFromDate : null,
                    $skipNormalization
                );
            } else {
                $userRepo = $this->getUserRepository();
                $currentTrainingStartDate = $userRepo->getField($userId, 'training_start_date') ?? null;
                $startDate = $alignedStartDate ?: ($currentTrainingStartDate ?? date('Y-m-d'));
                saveTrainingPlan($this->db, $userId, $planData, $startDate, $userPreferences, $skipNormalization);
                if ($alignedStartDate !== null && $alignedStartDate !== $currentTrainingStartDate) {
                    $userRepo->update($userId, ['training_start_date' => $alignedStartDate]);
                }
            }

            $this->syncLatestTrainingPlanSnapshot($userId, $startDate, $planData);
            $reviewStartDate = $startDate ?? ($cutoffDate ?? date('Y-m-d'));
            // PR9: пробрасываем realismContext (severity, predicted vs goal time, pace_strategy)
            // в plan_review — чтобы AI-сообщение в чате честно объяснило, под какой таргет
            // план реально готовит, если цель не реалистична.
            $realismContext = $this->buildRealismContextForReview(
                is_array($trainingState ?? null) ? $trainingState : null
            );
            $this->appendPlanReview($userId, $planData, $reviewStartDate, $mode, $realismContext);
            $this->persistPlanSummary($userId, $planData);
            // users.race_target_time не sync'аем — это user intent, остаётся как ввёл атлет.
            // effective_target_time выводится в plan_review (отдельный канал коммуникации).

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
            $obsPayload['generator'] = $planData['_generation_metadata']['generator'] ?? 'legacy';
            $obsPayload['explanation_summary'] = $planData['_generation_metadata']['explanation']['summary'] ?? null;

            // PR6 / Phase D.1: записываем success-событие только для llm_planner production-пути.
            if ($useLlmPlanner) {
                $generationMetadata = is_array($planData['_generation_metadata'] ?? null)
                    ? (array) $planData['_generation_metadata']
                    : [];
                $planEventLogger->recordSuccess(
                    $userId,
                    $jobType,
                    $generationMetadata,
                    $planEventTrainingState,
                    (int) round((microtime(true) - $startedAt) * 1000),
                    $traceId,
                    $llmUsage ?? []
                );
                $planEventLogged = true;
            }

            return $resultPayload;
        } catch (Throwable $e) {
            $obsStatus = 'error';
            $obsPayload['error'] = $e->getMessage();

            // PR6 / Phase D.1: записываем failure-событие только если ещё не записывали и
            // только для llm_planner-пути (legacy/skeleton не отслеживаются в новой таблице).
            if (!$planEventLogged) {
                $generationMode = strtolower(trim((string) env('PLAN_GENERATION_MODE', '')));
                if ($generationMode === 'llm_planner') {
                    try {
                        $planEventLogger->recordFailure(
                            $userId,
                            $jobType,
                            $e,
                            [],
                            $planEventTrainingState,
                            (int) round((microtime(true) - $startedAt) * 1000),
                            $traceId
                        );
                    } catch (Throwable $logErr) {
                        $this->logError('Не удалось записать failure-событие плана', [
                            'user_id' => $userId,
                            'error' => $logErr->getMessage(),
                        ]);
                    }
                }
            }

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
     * Production путь генерации (Phase A): DeepSeek V4 single-pass через LlmGateway.
     *
     * См. `docs/PLANS-AI-V2.md` раздел 0a (философия trust the model)
     * и `.cursor/rules/plans-ai-v2.mdc`.
     */
    private function processViaLlmPlanner(int $userId, string $jobType, array $payload, ?string $traceId = null): array {
        require_once __DIR__ . '/../planrun_ai/llm_planner/DeepSeekPlanPlanner.php';

        if ($jobType === 'recalculate') {
            $payload = $this->enrichRecalculatePayload($userId, $payload);
        } elseif ($jobType === 'next_plan') {
            $payload = $this->enrichNextPlanPayload($userId, $payload);
        }

        $planner = new DeepSeekPlanPlanner($this->db);
        $planner->setObservabilityContext($traceId, $userId, 'plan_generation');
        $plannerResult = $planner->generate($userId, $jobType, $payload);
        $plan = (array) ($plannerResult['plan'] ?? []);
        $user = (array) ($plannerResult['user'] ?? []);
        $state = (array) ($plannerResult['training_state'] ?? []);
        $startDate = (string) ($plannerResult['start_date'] ?? ($payload['cutoff_date'] ?? date('Y-m-d')));
        // Phase A.2 (PR2): planner_strategy всегда 'single_pass'; staged ветка удалена.
        $plannerStrategy = 'single_pass';
        // P0.3: auto-режим выбирает strict/permissive по cohort риска (см. resolveQualityGateMode).
        $qualityGateModeConfig = strtolower(trim((string) env('PLAN_LLM_QUALITY_GATE_MODE', 'auto')));
        if (!in_array($qualityGateModeConfig, ['auto', 'strict', 'permissive'], true)) {
            $qualityGateModeConfig = 'auto';
        }
        [$qualityGateMode, $qualityGateModeReason] = $this->resolveQualityGateMode($qualityGateModeConfig, $user, $state);
        // P0.1: дефолт включён, чтобы DeepSeek-план не сохранялся с опасной длительной/перекосом по объёму.
        $hardSafetyRepairsEnabled = $this->envBool('PLAN_LLM_HARD_SAFETY_REPAIRS', true);
        $raceWeekCapRepairsEnabled = $this->envBool('PLAN_LLM_RACE_WEEK_CAP_REPAIRS', true);

        if (empty($plan['weeks'])) {
            throw new RuntimeException('DeepSeek planner вернул пустой план', 500);
        }

        $plan = $this->enforceRaceDayConsistency($plan, $state, $user, $startDate, $raceWeekCapRepairsEnabled);
        $hardSafetyRepairs = [];
        if ($hardSafetyRepairsEnabled) {
            [$plan, $hardSafetyRepairs] = $this->applySinglePassHardSafetyRepairs($plan, $state, $startDate);
            $plan['_generation_metadata']['macro_plan'] = $this->buildMacroPlanMetadataFromWeeks((array) ($plan['weeks'] ?? []));
        }
        $qualityMacroPlan = $plan['_generation_metadata']['macro_plan'] ?? ($plannerResult['macro_plan'] ?? null);
        $qualityGate = new PlanQualityGate();
        $qualityContext = [
            'goal_type' => $user['goal_type'] ?? null,
            'preferred_days' => $this->decodeWeekdayPreferenceField($user['preferred_days'] ?? null),
            'user_preferences' => $this->loadUserPreferences((int) ($user['id'] ?? $userId)),
            'planning_scenario' => $state['planning_scenario'] ?? null,
            'macro_plan' => $qualityMacroPlan,
            'planner_strategy' => $plannerStrategy,
            'planner_hard_rules' => is_array($plannerResult['planner_context']['hard_rules'] ?? null)
                ? $plannerResult['planner_context']['hard_rules']
                : null,
            // P0.1: при включённых hard safety repairs пропускаем deterministic repairs внутри gate
            // тоже, т.к. они дублируют наши правки (применяются заново); при выключенных оставляем
            // прежнее поведение (тоже без repairs, чтобы не делать незаметных правок).
            'disable_repairs' => !$hardSafetyRepairsEnabled,
            'blocking_policy' => $qualityGateMode,
        ];
        $qualityGateResult = $qualityGate->evaluate($plan, $startDate, $state, $qualityContext);

        // Phase A.4 (PR2): repair-loop через LLM удалён.
        // Hard safety repairs (детерминированные) уже применены выше; targeted retry — Phase C.2.
        $repairAttempts = 0;

        if ($qualityGateResult['should_block_save'] ?? false) {
            throw new RuntimeException(
                'DeepSeek planner plan не прошёл final quality gate: ' . $this->buildQualityGateFailureMessage((array) ($qualityGateResult['issues'] ?? [])),
                500
            );
        }

        $finalPlan = is_array($qualityGateResult['normalized_plan'] ?? null)
            ? (array) $qualityGateResult['normalized_plan']
            : $plan;
        $finalMetadata = array_merge(
            (array) ($plan['_generation_metadata'] ?? []),
            [
                'generator' => 'DeepSeekPlanPlanner',
                'generation_mode' => 'llm_planner',
                'llm_repair_attempts' => $repairAttempts,
                'hard_safety_repairs' => $hardSafetyRepairs,
                'hard_safety_repairs_enabled' => $hardSafetyRepairsEnabled,
                'race_week_cap_repairs_enabled' => $raceWeekCapRepairsEnabled,
                'quality_gate' => [
                    'status' => $qualityGateResult['status'] ?? 'ok',
                    'mode' => $qualityGateResult['blocking_policy'] ?? $qualityGateMode,
                    'mode_config' => $qualityGateModeConfig,
                    'mode_reason' => $qualityGateModeReason,
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
        $finalMetadata['macro_plan'] = $this->buildMacroPlanMetadataFromWeeks((array) ($finalPlan['weeks'] ?? []));
        $finalPlan['_generation_metadata'] = $finalMetadata;

        $result = [
            'plan' => $finalPlan,
            'training_state' => $state,
            'usage' => is_array($plannerResult['usage'] ?? null) ? $plannerResult['usage'] : [],
        ];

        if ($jobType === 'recalculate') {
            $result['cutoff_date'] = $payload['cutoff_date'] ?? (new DateTime())->modify('monday this week')->format('Y-m-d');
            $result['kept_weeks'] = $payload['kept_weeks'] ?? 0;
            $result['mutable_from_date'] = $payload['mutable_from_date'] ?? null;
        } elseif ($jobType === 'next_plan') {
            $result['start_date'] = $payload['cutoff_date'] ?? $startDate;
        } else {
            $result['start_date'] = $startDate;
        }

        return $result;
    }

    /**
     * Phase A.6 (PR3): «medical-only» пороги.
     *
     * - `$lateLongMaxKm = 32.0` — медицина (длительная >32 км в последние 21 день до марафона —
     *   риск перетренированности и травмы).
     * - `$longShareCap` поднят до 0.60 (было 0.45): в реальной практике pro/опытные бегуны иногда
     *   делают long ≈ 50% недельного объёма в peak-неделях. 60% — реальный медицинский потолок,
     *   за которым растут травмы. До 60% оставляем DeepSeek решать самому.
     * - Volume spike repair не реализован — сам DeepSeek знает прогрессию (см. Phase A.5
     *   слим hard_rules).
     */
    private function applySinglePassHardSafetyRepairs(array $plan, array $state, string $startDate): array {
        $raceDistance = (string) ($state['race_distance'] ?? '');
        $raceDate = (string) ($state['race_date'] ?? '');
        if ($raceDate === '' || !in_array($raceDistance, ['marathon', '42.2k'], true)) {
            return [$plan, []];
        }

        try {
            $start = new DateTimeImmutable($startDate);
            $race = new DateTimeImmutable($raceDate);
        } catch (Throwable $e) {
            return [$plan, []];
        }

        $lateLongMaxKm = 32.0;
        // Phase A.6 (PR3): дефолт поднят до 0.60. Если load_policy задаёт более низкий — игнорируем
        // эту «эстетику», берём 0.60 как медицинский потолок. Если load_policy задаёт >0.60 —
        // ограничиваем 0.65 (предохранитель против совсем экстремальных значений).
        $longShareCap = isset($state['load_policy']['long_share_cap'])
            ? (float) $state['load_policy']['long_share_cap']
            : 0.60;
        $longShareCap = max(0.60, min(0.65, $longShareCap));
        $repairs = [];
        if (!isset($plan['weeks']) || !is_array($plan['weeks'])) {
            return [$plan, $repairs];
        }

        $weeks = &$plan['weeks'];
        foreach ($weeks as $weekIndex => &$week) {
            if (!is_array($week)) {
                continue;
            }

            $weekNumber = (int) ($week['week_number'] ?? ($weekIndex + 1));
            $weekStart = $start->modify('+' . (($weekNumber - 1) * 7) . ' days');
            $changedWeek = false;
            if (!isset($week['days']) || !is_array($week['days'])) {
                continue;
            }

            $days = &$week['days'];
            foreach ($days as $dayIndex => &$day) {
                if (!is_array($day) || normalizeTrainingType($day['type'] ?? null) !== 'long') {
                    continue;
                }

                $distance = $this->resolvePlanDayDistanceKm($day);
                if ($distance <= $lateLongMaxKm) {
                    continue;
                }

                $dayOfWeek = isset($day['day_of_week']) ? max(1, min(7, (int) $day['day_of_week'])) : ((int) $dayIndex + 1);
                $date = !empty($day['date'])
                    ? new DateTimeImmutable((string) $day['date'])
                    : $weekStart->modify('+' . ($dayOfWeek - 1) . ' days');
                $daysToRace = (int) $date->diff($race)->format('%r%a');
                if ($daysToRace <= 0 || $daysToRace > 21) {
                    continue;
                }

                $oldDistance = round($distance, 1);
                $day['distance_km'] = $lateLongMaxKm;
                if (!empty($day['pace'])) {
                    $paceSec = parsePaceToSeconds($day['pace']);
                    if ($paceSec !== null) {
                        $day['duration_minutes'] = (int) round(($lateLongMaxKm * $paceSec) / 60);
                    }
                }
                $day['notes'] = trim((string) ($day['notes'] ?? ''));
                if ($day['notes'] === '') {
                    $day['notes'] = 'Снижено до 32 км: длительная попадает в последние 21 день перед марафоном.';
                }

                $changedWeek = true;
                $repairs[] = [
                    'code' => 'cap_late_marathon_long_run',
                    'week_number' => $weekNumber,
                    'date' => $date->format('Y-m-d'),
                    'from_km' => $oldDistance,
                    'to_km' => $lateLongMaxKm,
                    'days_to_race' => $daysToRace,
                ];
            }
            unset($day);

            $longDayIndex = null;
            $longDistance = 0.0;
            $weekTotal = $this->sumWeekDistances($days);
            foreach ($days as $dayIndex => $day) {
                if (is_array($day) && normalizeTrainingType($day['type'] ?? null) === 'long') {
                    $candidateDistance = $this->resolvePlanDayDistanceKm($day);
                    if ($candidateDistance > $longDistance) {
                        $longDistance = $candidateDistance;
                        $longDayIndex = $dayIndex;
                    }
                }
            }

            if ($longDayIndex !== null && $weekTotal > 0.0 && ($longDistance / $weekTotal) > ($longShareCap + 0.005)) {
                $otherVolume = max(0.0, $weekTotal - $longDistance);
                if ($otherVolume > 0.0) {
                    $maxLongByShare = floor((($otherVolume * $longShareCap) / (1 - $longShareCap)) * 10) / 10;
                    $newDistance = max(1.0, min($longDistance, $maxLongByShare));
                    if ($newDistance < ($longDistance - 0.05)) {
                        $oldDistance = round($longDistance, 1);
                        $days[$longDayIndex]['distance_km'] = round($newDistance, 1);
                        if (!empty($days[$longDayIndex]['pace'])) {
                            $days[$longDayIndex]['duration_minutes'] = calculateDurationMinutes(
                                (float) $days[$longDayIndex]['distance_km'],
                                (string) $days[$longDayIndex]['pace']
                            );
                        }
                        $days[$longDayIndex]['notes'] = trim((string) ($days[$longDayIndex]['notes'] ?? ''));
                        if ($days[$longDayIndex]['notes'] === '') {
                            $days[$longDayIndex]['notes'] = 'Снижено, чтобы длительная не занимала слишком большую долю недели.';
                        }
                        if (function_exists('updateSimpleRunDayAfterDistanceChange')) {
                            $days[$longDayIndex] = updateSimpleRunDayAfterDistanceChange($days[$longDayIndex]);
                        }
                        $changedWeek = true;
                        $repairs[] = [
                            'code' => 'cap_long_run_week_share',
                            'week_number' => $weekNumber,
                            'from_km' => $oldDistance,
                            'to_km' => round((float) $days[$longDayIndex]['distance_km'], 1),
                            'old_share' => round($longDistance / $weekTotal, 3),
                            'share_cap' => $longShareCap,
                        ];
                    }
                }
            }

            if ($changedWeek) {
                $week['target_volume_km'] = $this->sumWeekDistances((array) ($week['days'] ?? []));
            }
        }
        unset($week);

        if ($repairs !== []) {
            $plan['_generation_metadata']['macro_plan'] = $this->buildMacroPlanMetadataFromWeeks((array) ($plan['weeks'] ?? []));
        }

        return [$plan, $repairs];
    }

    private function buildMacroPlanMetadataFromWeeks(array $weeks): array {
        $macroWeeks = [];
        foreach ($weeks as $week) {
            if (!is_array($week)) {
                continue;
            }

            $longRunKm = 0.0;
            foreach ((array) ($week['days'] ?? []) as $day) {
                if (is_array($day) && normalizeTrainingType($day['type'] ?? null) === 'long') {
                    $longRunKm = max($longRunKm, $this->resolvePlanDayDistanceKm($day));
                }
            }

            $macroWeeks[] = [
                'week' => (int) ($week['week_number'] ?? 0),
                'phase' => (string) ($week['phase'] ?? 'build'),
                'target_volume_km' => $this->sumWeekDistances((array) ($week['days'] ?? [])),
                'long_run_km' => round($longRunKm, 1),
                'quality_focus' => 'См. детальный календарь недели.',
                'risk_note' => (string) ($week['macro_adjustment_reason'] ?? ''),
            ];
        }

        return ['weeks' => $macroWeeks];
    }

    private function sumWeekDistances(array $days): float {
        $sum = 0.0;
        foreach ($days as $day) {
            if (is_array($day)) {
                $sum += $this->resolvePlanDayDistanceKm($day);
            }
        }

        return round($sum, 1);
    }

    private function resolvePlanDayDistanceKm(array $day): float {
        if (isset($day['distance_km']) && $day['distance_km'] !== null && is_numeric($day['distance_km'])) {
            return round(max(0.0, (float) $day['distance_km']), 1);
        }

        $type = normalizeTrainingType($day['type'] ?? null);
        if ($type === 'interval' && function_exists('calculateIntervalTotalKm')) {
            return round(max(0.0, calculateIntervalTotalKm($day)), 1);
        }
        if ($type === 'fartlek' && function_exists('calculateFartlekTotalKm')) {
            return round(max(0.0, calculateFartlekTotalKm($day)), 1);
        }

        $description = trim((string) ($day['description'] ?? ''));
        if ($description !== '' && preg_match('/(\d+(?:[.,]\d+)?)\s*(?:км|km)\b/iu', $description, $match)) {
            return round(max(0.0, (float) str_replace(',', '.', $match[1])), 1);
        }

        return 0.0;
    }

    private function envBool(string $key, bool $default): bool {
        $raw = env($key, $default ? '1' : '0');
        $value = strtolower(trim((string) $raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * P0.3: Резолв quality-gate mode для llm_planner.
     *
     * - 'strict' / 'permissive' в env используются как есть.
     * - 'auto' (или любое неизвестное значение) включает strict для рисковых когорт:
     *    - race_distance ∈ {half, marathon, 21.1k, 42.2k} — длинные старты с риском.
     *    - special_population_flags содержит pregnant_or_postpartum / return_after_injury /
     *      recent_pain_signal / recent_illness_signal.
     *    - planning_scenario.flags содержит pain_protective / illness_protective /
     *      return_after_injury / return_after_break / overload_recovery.
     *    - goal_realism.severity == 'major' (assessGoalRealism verdict='unrealistic').
     *
     * Возвращает [effectiveMode, reason] — чтобы записать в metadata.
     *
     * @return array{0: string, 1: string}
     */
    /**
     * Auto-режим quality gate (P0.3, философия «trust the model»).
     *
     * По умолчанию мы доверяем DeepSeek с богатым FACTS_JSON и не блокируем план
     * на «эстетических» расхождениях. `strict` (блокирующий) включается только в
     * когортах с реальным риском травмы / здоровья / явной нереалистичности цели:
     *  - special_population_flags: беременность, return_after_injury, pain/illness signal;
     *  - planning_scenario.flags: pain_protective / illness_protective / return_after_injury;
     *  - goal_realism.severity = 'major' (явный голевой mismatch).
     *
     * Сами по себе marathon / half / overload_recovery / return_after_break не
     * включают strict: это нормальная высокая нагрузка, и DeepSeek получает по
     * ним достаточный контекст в FACTS_JSON. Они идут в permissive с warnings.
     */
    private function resolveQualityGateMode(string $configMode, array $user, array $state): array
    {
        if ($configMode === 'strict' || $configMode === 'permissive') {
            return [$configMode, 'env_explicit'];
        }

        $specialFlags = array_map('strval', (array) ($state['special_population_flags'] ?? []));
        $strictFlags = array_intersect($specialFlags, [
            'pregnant_or_postpartum',
            'return_after_injury',
            'recent_pain_signal',
            'recent_illness_signal',
        ]);
        if ($strictFlags !== []) {
            return ['strict', 'auto_special_flag_' . implode(',', $strictFlags)];
        }

        $scenario = is_array($state['planning_scenario'] ?? null) ? $state['planning_scenario'] : [];
        $scenarioFlags = array_map('strval', (array) ($scenario['flags'] ?? []));
        $strictScenarioFlags = array_intersect($scenarioFlags, [
            'pain_protective',
            'illness_protective',
            'return_after_injury',
        ]);
        if ($strictScenarioFlags !== []) {
            return ['strict', 'auto_scenario_' . implode(',', $strictScenarioFlags)];
        }

        $realism = is_array($state['goal_realism'] ?? null) ? $state['goal_realism'] : [];
        $severity = (string) ($realism['severity'] ?? 'none');
        if ($severity === 'major') {
            return ['strict', 'auto_goal_unrealistic'];
        }

        return ['permissive', 'auto_default_permissive'];
    }

    // PR7 / Phase D.3: метод processViaSkeleton удалён вместе с _legacy/skeleton/ и
    // env USE_SKELETON_GENERATOR. Production-путь теперь только PLAN_GENERATION_MODE=llm_planner
    // (DeepSeek V4). Если PLAN_GENERATION_MODE пустой, идём через legacy generatePlanViaPlanRunAI
    // / recalculatePlanViaPlanRunAI / generateNextPlanViaPlanRunAI (они сами зовут DeepSeek
    // через старую обёртку, в production это не должно встречаться).

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

    private function enforceRaceDayConsistency(array $plan, array $trainingState, array $user, ?string $startDate = null, bool $capRaceWeekSupplementary = false): array {
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
        $raceDate = (string) ($trainingState['race_date'] ?? ($user['race_date'] ?? ($user['target_marathon_date'] ?? '')));
        $intermediateRaceDates = array_column($trainingState['intermediate_races'] ?? [], 'date');

        if ($startDate !== null && $raceDate !== '') {
            $plan = $this->placeRaceOnCalendarDate($plan, $startDate, $raceDate, $raceDistanceKm, $goalPace, $intermediateRaceDates);
            if ($capRaceWeekSupplementary) {
                $plan = $this->capRaceWeekSupplementaryVolume($plan, $trainingState);
            }
        }

        $weeks = $plan['weeks'] ?? [];
        foreach ($weeks as &$week) {
            $days = $week['days'] ?? [];
            foreach ($days as &$day) {
                if (normalizeTrainingType($day['type'] ?? null) !== 'race') {
                    continue;
                }

                $dayDate = $day['date'] ?? null;
                if ($dayDate !== null && in_array($dayDate, $intermediateRaceDates, true)) {
                    $day['is_key_workout'] = true;
                    if (function_exists('rebuildNormalizedDayArtifacts')) {
                        $day = rebuildNormalizedDayArtifacts($day);
                    }
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

        // Safety-net: убедиться, что КАЖДЫЙ intermediate race из state присутствует в плане.
        // Если DeepSeek забыл вернуть промежуточный забег — мы его принудительно ставим, иначе
        // он пропадёт из БД при save и следующий пересчёт уже не будет о нём знать
        // (state читает race-дни из training_plan_days).
        if (!empty($trainingState['intermediate_races'])) {
            $plan = $this->ensureIntermediateRacesInPlan($plan, (array) $trainingState['intermediate_races']);
        }

        return $plan;
    }

    /**
     * Гарантирует наличие intermediate-races в плане. Для каждой даты из state.intermediate_races
     * проверяет, есть ли день с type='race'. Если нет — находит соответствующий день в нужной
     * неделе и ставит туда race с дистанцией/описанием из state. Идемпотентен.
     */
    private function ensureIntermediateRacesInPlan(array $plan, array $intermediateRaces): array {
        if (empty($intermediateRaces) || empty($plan['weeks'])) {
            return $plan;
        }

        $weeks = $plan['weeks'];
        foreach ($intermediateRaces as $race) {
            $raceDate = (string) ($race['date'] ?? '');
            if ($raceDate === '') continue;

            $expectedKm = isset($race['distance_km']) && $race['distance_km'] !== null ? (float) $race['distance_km'] : 0.0;
            $expectedDesc = isset($race['description']) ? (string) $race['description'] : '';

            // Уже есть race на эту дату в плане? Проверяем, что дистанция совпадает с user input.
            // AI может ставить race-день на правильную дату, но «переписать» дистанцию (15→8 км).
            // Это unauthorized: distance_km — manual user input, защищаем.
            $alreadyPresent = false;
            $distanceMismatch = false;
            foreach ($weeks as $week) {
                foreach (($week['days'] ?? []) as $day) {
                    if (($day['date'] ?? '') !== $raceDate) continue;
                    if (($day['type'] ?? '') !== 'race') continue;
                    $alreadyPresent = true;
                    if ($expectedKm > 0) {
                        $actualKm = (float) ($day['distance_km'] ?? 0);
                        if (abs($actualKm - $expectedKm) > 0.6) {
                            $distanceMismatch = true;
                            error_log(sprintf(
                                'ensureIntermediateRacesInPlan: distance mismatch on %s — user %.1f km, plan %.1f km. Force-fix.',
                                $raceDate,
                                $expectedKm,
                                $actualKm
                            ));
                        }
                    }
                    break 2;
                }
            }
            if ($alreadyPresent && !$distanceMismatch) continue;

            $distanceKm = $expectedKm;
            $description = $expectedDesc;

            // Force-place: найти день с этой датой и переписать в race.
            // ВАЖНО: foreach должен бежать по reference на $week['days'], иначе
            // изменения уйдут в копию (PHP array semantics).
            $forced = false;
            foreach ($weeks as $weekIdx => &$week) {
                if (!isset($week['days']) || !is_array($week['days'])) continue;
                $days = &$week['days'];
                foreach ($days as $dayIdx => &$day) {
                    if (($day['date'] ?? '') !== $raceDate) continue;
                    $day['type'] = 'race';
                    if ($distanceKm > 0) {
                        $day['distance_km'] = round($distanceKm, 1);
                    }
                    if ($description !== '') {
                        $day['notes'] = $description;
                    }
                    $day['is_key_workout'] = true;
                    $day['subtype'] = null;
                    if (function_exists('rebuildNormalizedDayArtifacts')) {
                        $day = rebuildNormalizedDayArtifacts($day);
                    }
                    $forced = true;
                    error_log(sprintf(
                        'ensureIntermediateRacesInPlan: forced race %s (%.1f km) — DeepSeek не вернул промежуточный забег',
                        $raceDate,
                        $distanceKm
                    ));
                    break;
                }
                unset($day);
                unset($days);
                if ($forced) break;
            }
            unset($week);
        }

        $plan['weeks'] = $weeks;

        // PR-C (coaching prompt v4): protectAroundRaceDays() удалён.
        // Race-week protocol теперь передаётся модели как семантические маркеры race_proximity
        // в calendar_weeks (DeepSeekPlanPlanner::buildCalendarWeeks). Тренер-модель применяет
        // базовую физиологию сама, без post-processing safety-net'ов.

        return $plan;
    }

    private function capRaceWeekSupplementaryVolume(array $plan, array $trainingState): array {
        $weeks = array_values((array) ($plan['weeks'] ?? []));
        $raceWeekIndex = null;
        foreach ($weeks as $index => $week) {
            foreach ((array) ($week['days'] ?? []) as $day) {
                if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                    $raceWeekIndex = $index;
                    break 2;
                }
            }
        }

        if ($raceWeekIndex === null || $raceWeekIndex < 1) {
            return $plan;
        }

        $weekBeforeVolume = calculateNormalizedWeekVolume((array) ($weeks[$raceWeekIndex - 1]['days'] ?? []));
        if ($weekBeforeVolume <= 0.0 || !function_exists('resolveRaceWeekSupplementaryCap')) {
            return $plan;
        }

        $raceWeekDays = (array) ($weeks[$raceWeekIndex]['days'] ?? []);
        $supplementaryVolume = 0.0;
        foreach ($raceWeekDays as $day) {
            if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                continue;
            }
            $supplementaryVolume += (float) ($day['distance_km'] ?? 0.0);
        }

        $cap = resolveRaceWeekSupplementaryCap($weekBeforeVolume, $trainingState);
        if ($supplementaryVolume <= ($cap + 0.2) || $supplementaryVolume <= 0.0) {
            return $plan;
        }

        $ratio = $cap / $supplementaryVolume;
        foreach ($raceWeekDays as &$day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            if ($type === 'race' || $type === 'rest' || empty($day['distance_km'])) {
                continue;
            }

            $day['distance_km'] = round(max(0.0, (float) $day['distance_km'] * $ratio), 1);
            if (!empty($day['pace'])) {
                $day['duration_minutes'] = calculateDurationMinutes((float) $day['distance_km'], (string) $day['pace']);
            }
            if (function_exists('updateSimpleRunDayAfterDistanceChange')) {
                $day = updateSimpleRunDayAfterDistanceChange($day);
            }
        }
        unset($day);

        $weeks[$raceWeekIndex]['days'] = $raceWeekDays;
        $weeks[$raceWeekIndex]['actual_volume_km'] = calculateNormalizedWeekVolume($raceWeekDays);
        $weeks[$raceWeekIndex]['total_volume'] = $weeks[$raceWeekIndex]['actual_volume_km'];
        $plan['weeks'] = $weeks;
        return $plan;
    }

    private function placeRaceOnCalendarDate(array $plan, string $startDate, string $raceDate, float $raceDistanceKm, ?string $goalPace, array $intermediateRaceDates = []): array {
        try {
            $start = new DateTimeImmutable($startDate);
            $race = new DateTimeImmutable($raceDate);
        } catch (Throwable $e) {
            return $plan;
        }

        $diffDays = (int) $start->diff($race)->format('%r%a');
        if ($diffDays < 0) {
            return $plan;
        }

        $targetWeekNumber = intdiv($diffDays, 7) + 1;
        $targetDayOfWeek = (int) $race->format('N');
        $weeks = $plan['weeks'] ?? [];
        $racePlaced = false;
        foreach ($weeks as $weekIndex => &$week) {
            $weekNumber = (int) ($week['week_number'] ?? ($weekIndex + 1));
            $days = (array) ($week['days'] ?? []);
            foreach ($days as $dayIndex => &$day) {
                $dayOfWeek = (int) ($day['day_of_week'] ?? ($dayIndex + 1));
                $date = $start->modify('+' . (($weekNumber - 1) * 7 + ($dayOfWeek - 1)) . ' days')->format('Y-m-d');
                $day['date'] = $date;

                if ($weekNumber === $targetWeekNumber && $dayOfWeek === $targetDayOfWeek) {
                    $day['type'] = 'race';
                    $day['distance_km'] = round($raceDistanceKm, 1);
                    $day['pace'] = $goalPace;
                    $day['duration_minutes'] = $goalPace !== null
                        ? calculateDurationMinutes((float) $day['distance_km'], $goalPace)
                        : ($day['duration_minutes'] ?? null);
                    $day['is_key_workout'] = true;
                    $day['subtype'] = null;
                    $day['warmup_km'] = null;
                    $day['cooldown_km'] = null;
                    $day['tempo_km'] = null;
                    $day['notes'] = $day['notes'] ?? 'Главный старт';
                    if (function_exists('rebuildNormalizedDayArtifacts')) {
                        $day = rebuildNormalizedDayArtifacts($day);
                    }
                    $racePlaced = true;
                    continue;
                }

                if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                    if (in_array($date, $intermediateRaceDates, true)) {
                        continue;
                    }
                    $day['type'] = 'rest';
                    $day['distance_km'] = 0.0;
                    $day['duration_minutes'] = null;
                    $day['pace'] = null;
                    $day['is_key_workout'] = false;
                    $day['subtype'] = null;
                    $day['notes'] = null;
                    $day['exercises'] = [];
                    if (function_exists('rebuildNormalizedDayArtifacts')) {
                        $day = rebuildNormalizedDayArtifacts($day);
                    }
                }
            }
            unset($day);

            $week['days'] = $days;
            $week['actual_volume_km'] = calculateNormalizedWeekVolume($days);
            $week['total_volume'] = $week['actual_volume_km'];
        }
        unset($week);

        // Safety net: if DeepSeek returned a plan that doesn't cover the race date
        // (horizon too short or race day silently dropped), force the race onto the
        // last week so the user always gets a race day in their plan.
        if (!$racePlaced && !empty($weeks)) {
            $lastWeekIndex = count($weeks) - 1;
            $lastWeek = &$weeks[$lastWeekIndex];
            $lastWeekNumber = (int) ($lastWeek['week_number'] ?? ($lastWeekIndex + 1));
            $forcedDayOfWeek = $targetDayOfWeek;
            $lastDays = (array) ($lastWeek['days'] ?? []);
            $forcedDate = $start->modify('+' . (($lastWeekNumber - 1) * 7 + ($forcedDayOfWeek - 1)) . ' days')->format('Y-m-d');
            foreach ($lastDays as &$day) {
                $dayOfWeek = (int) ($day['day_of_week'] ?? 0);
                if ($dayOfWeek !== $forcedDayOfWeek) {
                    continue;
                }
                $day['date'] = $forcedDate;
                $day['type'] = 'race';
                $day['distance_km'] = round($raceDistanceKm, 1);
                $day['pace'] = $goalPace;
                $day['duration_minutes'] = $goalPace !== null
                    ? calculateDurationMinutes((float) $day['distance_km'], $goalPace)
                    : null;
                $day['is_key_workout'] = true;
                $day['subtype'] = null;
                $day['warmup_km'] = null;
                $day['cooldown_km'] = null;
                $day['tempo_km'] = null;
                $day['notes'] = $day['notes'] ?? 'Главный старт (safety-net placement)';
                if (function_exists('rebuildNormalizedDayArtifacts')) {
                    $day = rebuildNormalizedDayArtifacts($day);
                }
                $racePlaced = true;
                error_log(sprintf(
                    'placeRaceOnCalendarDate: race day fell outside plan horizon (target_week=%d, plan_weeks=%d) — force-placed on last week (week=%d, dow=%d, date=%s)',
                    $targetWeekNumber,
                    count($weeks),
                    $lastWeekNumber,
                    $forcedDayOfWeek,
                    $forcedDate
                ));
                break;
            }
            unset($day);
            $lastWeek['days'] = $lastDays;
            $lastWeek['actual_volume_km'] = calculateNormalizedWeekVolume($lastDays);
            $lastWeek['total_volume'] = $lastWeek['actual_volume_km'];
            unset($lastWeek);
        }

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

    // Phase A.1 (PR2): buildExpectedSkeletonContract удалён — использовался только в
    // processViaSkeleton для проверки контракта между числовым скелетом и LLM-обогащением.
    // В llm_planner-режиме нет числового скелета, поэтому метод стал мёртвым.

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

    /**
     * Сохраняет plan_summary и risk_review (включая «цель нереалистичная»/рекомендации) в users,
     * чтобы их видел чат при любом запросе о плане — а не только в результате job сразу
     * после recalculate.
     */
    /**
     * Self-critique pass: независимый LLM-вызов ревьюит план, при необходимости
     * запускает revision. Возвращает planData с metadata.critique. Никогда не бросает —
     * при любой ошибке возвращает исходный planData.
     */
    private function applyPlanCritique(array $planData, int $userId, string $mode, ?array $trainingState): array {
        if ((int) env('PLAN_CRITIQUE_ENABLED', 1) !== 1) {
            return $planData;
        }

        try {
            require_once __DIR__ . '/../planrun_ai/plan_critique_generator.php';
            require_once __DIR__ . '/WorkoutAnalysisRepository.php';
            require_once __DIR__ . '/ChatContextBuilder.php';

            // Загружаем user для критики
            $userStmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            if (!$userStmt) return $planData;
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if (!$user) return $planData;

            $repo = new WorkoutAnalysisRepository($this->db);
            $ctx = new ChatContextBuilder($this->db);

            $context = [
                'new_start_date' => date('Y-m-d', strtotime('monday this week')),
                'plan_history_rollup' => $repo->getWeeklyRollupForActivePlan($userId),
                'plan_key_workouts' => $repo->getKeyWorkoutSummaryForActivePlan($userId),
                'acwr' => $ctx->calculateACWR($userId),
            ];

            $critique = runPlanSelfCritique($planData, $user, $context, $userId);
            if (!is_array($critique)) {
                return $planData;
            }

            $this->logInfo("Plan critique completed", [
                'user_id' => $userId,
                'mode' => $mode,
                'severity' => $critique['severity'] ?? '?',
                'should_revise' => !empty($critique['should_revise']),
                'issues_count' => count($critique['issues'] ?? []),
            ]);

            if (!empty($critique['should_revise'])) {
                $revised = revisePlanWithCritique($planData, $critique, $user, $context, $userId, $mode);
                if (is_array($revised) && !empty($revised['weeks'])) {
                    $planData = $revised;
                    $critique['_revised'] = true;
                    $this->logInfo("Plan revised based on critique", [
                        'user_id' => $userId,
                        'mode' => $mode,
                    ]);
                }
            }

            if (!isset($planData['_generation_metadata']) || !is_array($planData['_generation_metadata'])) {
                $planData['_generation_metadata'] = [];
            }
            $planData['_generation_metadata']['critique'] = $critique;
        } catch (Throwable $e) {
            $this->logError('Plan critique pass failed', [
                'user_id' => $userId,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);
        }

        return $planData;
    }

    private function persistPlanSummary(int $userId, array $planData): void {
        $meta = is_array($planData['_generation_metadata'] ?? null) ? $planData['_generation_metadata'] : [];
        $summary = isset($meta['plan_summary']) ? trim((string) $meta['plan_summary']) : '';
        $riskReview = is_array($meta['risk_review'] ?? null) ? $meta['risk_review'] : null;
        $critique = is_array($meta['critique'] ?? null) ? $meta['critique'] : null;

        if ($summary === '' && empty($riskReview) && empty($critique)) {
            return;
        }

        // Compose: risk_review (от LLM-планировщика) + critique (от self-review pass) в один JSON.
        $combined = [];
        if (!empty($riskReview)) {
            $combined['risk_review'] = $riskReview;
        }
        if (!empty($critique)) {
            $combined['critique'] = $critique;
        }
        $riskJson = !empty($combined) ? json_encode($combined, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $this->db->prepare(
            "UPDATE users SET last_plan_summary = ?, last_plan_risk_review_json = ?, last_plan_generated_at = NOW() WHERE id = ?"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssi', $summary, $riskJson, $userId);
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
                ? $this->formatTrainingPlanSnapshotTargetTime((string) $user['race_target_time'])
                : (!empty($user['target_marathon_time']) ? $this->formatTrainingPlanSnapshotTargetTime((string) $user['target_marathon_time']) : null);
            return [$planDate, $targetTime];
        }

        if ($goalType === 'weight_loss') {
            $planDate = !empty($user['weight_goal_date'])
                ? (string) $user['weight_goal_date']
                : (!empty($user['target_marathon_date']) ? (string) $user['target_marathon_date'] : null);
            return [$planDate, null];
        }

        $planDate = !empty($user['target_marathon_date']) ? (string) $user['target_marathon_date'] : null;
        $targetTime = !empty($user['target_marathon_time']) ? $this->formatTrainingPlanSnapshotTargetTime((string) $user['target_marathon_time']) : null;
        return [$planDate, $targetTime];
    }

    private function formatTrainingPlanSnapshotTargetTime(?string $rawTime): ?string {
        $time = trim((string) $rawTime);
        if ($time === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $time, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];

            return $hours > 0
                ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
                : sprintf('%02d:%02d', $minutes, $seconds);
        }

        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $time, $matches)) {
            return sprintf('%d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return $time;
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

    private function appendPlanReview(
        int $userId,
        array $planData,
        string $reviewStartDate,
        string $mode,
        ?array $realismContext = null
    ): void {
        try {
            require_once __DIR__ . '/../planrun_ai/plan_review_generator.php';
            require_once __DIR__ . '/ChatService.php';
            $review = generatePlanReview($planData, $reviewStartDate, $mode, $realismContext);
            if ($review === null || $review === '') {
                $review = $this->buildFallbackPlanReview($planData, $reviewStartDate, $mode, $realismContext);
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

    private function buildFallbackPlanReview(
        array $planData,
        string $reviewStartDate,
        string $mode,
        ?array $realismContext = null
    ): string {
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

        // PR9: при moderate/major severity — добавляем сухой факт, что план готовит к
        // другому таргету, чем в профиле. Без интерпретации и тренерского обоснования —
        // это работа LLM-review (см. generatePlanReview + buildRealismFactsForReview).
        // Здесь только данные: цель в профиле / прогноз / таргет плана.
        $realismFact = $this->renderRealismFactLineForFallback($realismContext);
        if ($realismFact !== '') {
            $parts[] = $realismFact;
        }

        $parts[] = 'Проверь календарь. Если нужно ещё скорректировать структуру, напиши это в пересчёте или в чате.';

        return implode(' ', $parts);
    }

    /**
     * PR9: вытаскивает из training_state компактный контекст для plan_review:
     *   severity, gap_pct, goal_target_time, predicted_target_time, effective_target_time,
     *   race_distance_label.
     * Возвращает null, если у пользователя goal не race-типа или нет таргета.
     */
    /**
     * Pre-flight: оцениваем цель до запуска планировщика. Если formula-based goal-realism
     * (TrainingStateBuilder) считает цель нереалистичной — синхронизируем users.race_target_time
     * на effective_target_time. После этого вызов планера и calculatePaceZones будут единогласно
     * работать с одной целью, а MP-блоки получат правильный темп.
     */
    private function preflightSyncTargetIfUnrealistic(int $userId): void {
        try {
            require_once __DIR__ . '/TrainingStateBuilder.php';
            $state = (new TrainingStateBuilder($this->db))->buildForUserId($userId);
            $strategy = is_array($state['pace_strategy'] ?? null) ? $state['pace_strategy'] : null;
            if (!$strategy) return;

            $realismContext = [
                'severity' => (string) ($strategy['severity'] ?? 'none'),
                'goal_target_time' => $strategy['goal_target_time'] ?? null,
                'effective_target_time' => $strategy['effective_target_time'] ?? null,
            ];
            $this->syncRaceTargetTimeIfAdjusted($userId, $realismContext);
        } catch (Throwable $e) {
            $this->logError('preflightSyncTargetIfUnrealistic failed (non-fatal)', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Синхронизирует users.race_target_time с effective_target_time, если AI скорректировал цель.
     * Это убирает рассогласование «у юзера в БД 3:15, AI планирует под 3:25», на которое
     * critique-pass начинает ругаться при последующих recalc'ах.
     */
    private function syncRaceTargetTimeIfAdjusted(int $userId, ?array $realismContext): void {
        if (!is_array($realismContext)) return;
        $effective = trim((string) ($realismContext['effective_target_time'] ?? ''));
        $goal = trim((string) ($realismContext['goal_target_time'] ?? ''));
        if ($effective === '' || $goal === '' || $effective === $goal) return;

        $stmt = $this->db->prepare("UPDATE users SET race_target_time = ? WHERE id = ?");
        if (!$stmt) return;
        $stmt->bind_param('si', $effective, $userId);
        $stmt->execute();
        $stmt->close();

        $this->logInfo('race_target_time synced to AI-adjusted target', [
            'user_id' => $userId,
            'from' => $goal,
            'to' => $effective,
        ]);
    }

    private function buildRealismContextForReview(?array $trainingState): ?array {
        if (!is_array($trainingState)) {
            return null;
        }
        $strategy = is_array($trainingState['pace_strategy'] ?? null) ? $trainingState['pace_strategy'] : null;
        if (!$strategy) {
            return null;
        }

        $distanceLabels = [
            '5k' => '5 км',
            '10k' => '10 км',
            'half' => 'полумарафон',
            '21.1k' => 'полумарафон',
            'marathon' => 'марафон',
            '42.2k' => 'марафон',
        ];
        $distance = (string) ($strategy['race_distance'] ?? '');
        $distanceLabel = $distanceLabels[$distance] ?? ($distance !== '' ? $distance : null);

        return [
            'severity' => (string) ($strategy['severity'] ?? 'none'),
            'mode' => (string) ($strategy['mode'] ?? 'goal_target'),
            'gap_pct' => $strategy['gap_pct'] ?? null,
            'goal_target_time' => $strategy['goal_target_time'] ?? null,
            'goal_target_pace' => $strategy['goal_target_pace'] ?? null,
            'predicted_target_time' => $strategy['predicted_target_time'] ?? null,
            'effective_target_time' => $strategy['effective_target_time'] ?? null,
            'effective_target_pace' => $strategy['effective_target_pace'] ?? null,
            'race_distance' => $distance !== '' ? $distance : null,
            'race_distance_label' => $distanceLabel,
            'current_vdot' => $trainingState['vdot'] ?? null,
        ];
    }

    /**
     * PR9: fallback используется только если LLM-review не сработал (network/timeout).
     * Сюда кладём *только сухой факт* про таргет — без тренерского обоснования и без
     * фраз типа «темпы подтягиваются к цели» (это интерпретация, её даёт LLM-review).
     * Возвращает '' если severity=none или контекст пуст.
     */
    private function renderRealismFactLineForFallback(?array $realism): string {
        if (!is_array($realism)) {
            return '';
        }
        $severity = (string) ($realism['severity'] ?? 'none');
        if ($severity !== 'major' && $severity !== 'moderate') {
            return '';
        }

        $goal = (string) ($realism['goal_target_time'] ?? '');
        $effective = (string) ($realism['effective_target_time'] ?? '');
        $distLabel = (string) ($realism['race_distance_label'] ?? '');
        if ($goal === '' || $effective === '' || $goal === $effective) {
            return '';
        }

        $distPart = $distLabel !== '' ? " {$distLabel}" : '';
        return "Цель в профиле:{$distPart} {$goal}; план рассчитан на реалистичный таргет {$effective}.";
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
