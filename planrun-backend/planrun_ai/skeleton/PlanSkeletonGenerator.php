<?php
/**
 * PlanSkeletonGenerator — главный оркестратор генерации числового скелета плана.
 *
 * Собирает данные из:
 *  - TrainingStateBuilder (VDOT, темпы, load_policy)
 *  - PlanSkeletonBuilder (типы дней по дням недели)
 *  - computeMacrocycle() (фазы, прогрессия длительной)
 *
 * И генерирует полный числовой план (все недели, все дни, все числа)
 * БЕЗ вызова LLM.
 */

require_once __DIR__ . '/VolumeDistributor.php';
require_once __DIR__ . '/IntervalProgressionBuilder.php';
require_once __DIR__ . '/TempoProgressionBuilder.php';
require_once __DIR__ . '/RacePaceProgressionBuilder.php';
require_once __DIR__ . '/FartlekBuilder.php';
require_once __DIR__ . '/ControlWorkoutBuilder.php';
require_once __DIR__ . '/../../services/PlanSkeletonBuilder.php';
require_once __DIR__ . '/../../services/PlanScenarioResolver.php';
require_once __DIR__ . '/../prompt_builder.php';

class PlanSkeletonGenerator
{
    private $db;
    private ?array $lastUser = null;
    private ?array $lastState = null;
    private ?array $lastGoalRealism = null;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Генерация полного числового скелета плана.
     *
     * @param int    $userId  ID пользователя
     * @param string $mode    'generate' | 'recalculate' | 'next_plan'
     * @param array  $payload Дополнительные данные (для recalculate/next_plan)
     * @return array Полный план {weeks: [...], _metadata: {...}}
     */
    public function generate(int $userId, string $mode = 'generate', array $payload = []): array
    {
        $user = $this->loadUser($userId);
        $this->lastUser = $user;

        $goalType = $user['goal_type'] ?? 'health';
        $scenarioResolver = new PlanScenarioResolver();
        $scheduleAnchorDate = $scenarioResolver->resolveScheduleAnchorDate($user, $mode, $payload);
        $planningUser = $user;
        if ($scheduleAnchorDate !== null) {
            $planningUser['training_start_date'] = $scheduleAnchorDate;
        }

        $completedGoalContext = null;
        if (in_array($mode, ['recalculate', 'next_plan'], true)) {
            $completedGoalContext = $this->resolveCompletedRaceGoalContext($planningUser, $scheduleAnchorDate);
            if ($completedGoalContext !== null) {
                $planningUser = $this->buildPostGoalRecoveryUser($planningUser, $scheduleAnchorDate);
                $goalType = 'health';
            }
        }

        // TrainingState: VDOT, темпы, load_policy, readiness
        require_once __DIR__ . '/../../services/TrainingStateBuilder.php';
        $stateBuilder = new TrainingStateBuilder($this->db);
        $state = $stateBuilder->buildForUser($planningUser);
        $this->lastState = $state;

        // Для recalculate — скорректировать state
        if ($mode === 'recalculate') {
            $state = $this->adjustStateForRecalculation($state, $planningUser, $payload);
        } elseif ($mode === 'next_plan') {
            $state = $this->adjustStateForNextPlan($state, $planningUser, $payload);
        }
        if ($completedGoalContext !== null) {
            $state = $this->applyCompletedGoalRecoveryState($state, $completedGoalContext);
        }

        $scenario = $scenarioResolver->resolve($planningUser, $state, $mode, $payload);
        if ($completedGoalContext !== null) {
            $scenario = $this->applyCompletedGoalRecoveryScenario($scenario);
        }
        if (!empty($scenario['effective_weeks_to_goal'])) {
            $state['weeks_to_goal'] = (int) $scenario['effective_weeks_to_goal'];
        }
        $state['planning_scenario'] = $scenario;
        $continuationContext = $this->resolveContinuationContext($mode, $payload);
        $progressionCounters = $this->resolveProgressionCounters($mode, $payload, $continuationContext);
        $this->lastState = $state;

        $paceRules = $state['pace_rules'] ?? [];
        $loadPolicy = $state['load_policy'] ?? [];
        $raceDistance = $planningUser['race_distance'] ?? $state['race_distance'] ?? '';
        $raceDistanceKm = $this->getDistanceKm($raceDistance);

        // Macrocycle: фазы, прогрессия длительной
        // При recalculate — сдвигаем training_start_date и weekly_base_km
        $macroUser = $planningUser;
        if (!empty($state['weekly_base_km'])) {
            $macroUser['weekly_base_km'] = $state['weekly_base_km'];
        }
        if ($mode === 'recalculate' || $mode === 'next_plan') {
            $macroUser['training_start_date'] = $payload['cutoff_date']
                ?? (new DateTime())->modify('monday this week')->format('Y-m-d');
        } elseif ($scheduleAnchorDate !== null) {
            $macroUser['training_start_date'] = $scheduleAnchorDate;
        }
        $macrocycle = in_array($goalType, ['race', 'time_improvement'], true)
            ? computeMacrocycle($macroUser, $goalType)
            : computeHealthMacrocycle($macroUser, $goalType);

        $hasPhaseContinuation = $this->hasPhaseContinuation($continuationContext);

        // Пересобрать volume targets в loadPolicy из пересчитанного macrocycle
        if ($macrocycle && ($mode === 'recalculate' || $mode === 'next_plan') && !$hasPhaseContinuation) {
            $loadPolicy = $state['load_policy'] ?? [];
            $loadPolicy['recovery_weeks'] = array_map('intval', $macrocycle['recovery_weeks'] ?? []);
            $loadPolicy['long_run_targets_km'] = array_map(
                static fn($km): float => round((float) $km, 1),
                $macrocycle['long_run']['by_week'] ?? []
            );
            $loadPolicy['start_volume_km'] = isset($macrocycle['start_volume_km']) ? (float) $macrocycle['start_volume_km'] : null;
            $loadPolicy['peak_volume_km'] = isset($macrocycle['peak_volume_km']) ? (float) $macrocycle['peak_volume_km'] : null;
            $loadPolicy['weekly_volume_targets_km'] = $this->rebuildWeeklyVolumeTargets(
                $macrocycle,
                $goalType,
                $user['race_distance'] ?? '',
                $loadPolicy
            );
            $state['load_policy'] = $loadPolicy;
            // weeks_to_goal из macrocycle (считается от cutoff_date, а не от time())
            if (!empty($macrocycle['total_weeks'])) {
                $state['weeks_to_goal'] = (int) $macrocycle['total_weeks'];
            }
        }

        if ($hasPhaseContinuation) {
            $loadPolicy = $this->buildContinuationLoadPolicy(
                $state['load_policy'] ?? [],
                $state,
                $continuationContext,
                $raceDistance
            );
            $state['load_policy'] = $loadPolicy;
            if (!empty($loadPolicy['weekly_volume_targets_km'])) {
                $state['weeks_to_goal'] = count($loadPolicy['weekly_volume_targets_km']);
            }
            $this->lastState = $state;
        }

        if (
            in_array($goalType, ['race', 'time_improvement'], true)
            && !empty($state['weeks_to_goal'])
            && (int) $state['weeks_to_goal'] <= 3
            && (
                empty($loadPolicy['weekly_volume_targets_km'])
                || empty($loadPolicy['long_run_targets_km'])
            )
        ) {
            $loadPolicy = $this->buildShortRunwayLoadPolicy($state, $user, $raceDistance, $raceDistanceKm);
            $state['load_policy'] = $loadPolicy;
            $this->lastState = $state;
        }

        // Skeleton: типы дней по дням недели
        $skeletonBuilder = new PlanSkeletonBuilder();
        $skeletonOptions = $this->buildSkeletonOptions($mode, $payload, $state);
        $skeletonUser = array_merge($planningUser, ['training_state' => $state]);
        // При recalculate — подменяем training_start_date для правильного расчёта фаз
        if ($mode === 'recalculate' || $mode === 'next_plan') {
            $skeletonUser['training_start_date'] = $payload['cutoff_date']
                ?? (new DateTime())->modify('monday this week')->format('Y-m-d');
        } elseif ($scheduleAnchorDate !== null) {
            $skeletonUser['training_start_date'] = $scheduleAnchorDate;
        }
        $skeleton = $skeletonBuilder->build(
            $skeletonUser,
            $goalType,
            $skeletonOptions
        );

        if (empty($skeleton['weeks'])) {
            return ['weeks' => [], '_metadata' => ['error' => 'empty_skeleton']];
        }

        // Оценка реалистичности цели (для race/time_improvement)
        $goalRealismUser = array_merge($planningUser, [
            'training_state' => $state,
            'weekly_base_km' => $state['weekly_base_km'] ?? ($planningUser['weekly_base_km'] ?? null),
        ]);
        $goalRealism = assessGoalRealism($goalRealismUser);
        $this->lastGoalRealism = $goalRealism;
        $state['goal_realism'] = $goalRealism;
        $this->lastState = $state;

        // Генерируем полный числовой план
        return $this->buildFullPlan(
            $skeleton,
            $state,
            $macrocycle,
            $goalType,
            $raceDistance,
            $raceDistanceKm,
            $scheduleAnchorDate,
            $progressionCounters
        );
    }

    /**
     * Получить последнего загруженного пользователя.
     */
    public function getLastUser(): ?array
    {
        return $this->lastUser;
    }

    /**
     * Получить последний state.
     */
    public function getLastState(): ?array
    {
        return $this->lastState;
    }

    public function getLastGoalRealism(): ?array
    {
        return $this->lastGoalRealism;
    }

    private function buildFullPlan(
        array  $skeleton,
        array  $state,
        ?array $macrocycle,
        string $goalType,
        string $raceDistance,
        float  $raceDistanceKm,
        ?string $scheduleAnchorDate = null,
        array $progressionCounters = []
    ): array {

        $paceRules = $state['pace_rules'] ?? [];
        $loadPolicy = $state['load_policy'] ?? [];
        $longShareCap = (float) ($loadPolicy['long_share_cap'] ?? 0.45);
        $volumeTargets = $loadPolicy['weekly_volume_targets_km'] ?? [];
        $longTargets = $loadPolicy['long_run_targets_km'] ?? [];
        $recoveryWeeks = $loadPolicy['recovery_weeks'] ?? [];

        // Счётчики прогрессии для каждого типа ключевой тренировки
        $intervalCount = max(0, (int) ($progressionCounters['interval_count'] ?? 0));
        $tempoCount = max(0, (int) ($progressionCounters['tempo_count'] ?? 0));
        $fartlekCount = max(0, (int) ($progressionCounters['fartlek_count'] ?? 0));
        $racePaceCount = max(
            0,
            (int) ($progressionCounters['race_pace_count'] ?? intdiv($tempoCount, 2))
        );

        $weeks = [];

        foreach ($skeleton['weeks'] as $skeletonWeek) {
            $weekNum = $skeletonWeek['week_number'];
            $phase = $skeletonWeek['phase_name'] ?? 'base';
            $phaseLabel = $skeletonWeek['phase_label'] ?? '';
            $isRecovery = in_array($weekNum, $recoveryWeeks, true);
            $weekTuneUpEvent = $this->resolveWeekTuneUpEvent($state, $weekNum);

            $targetVolume = $volumeTargets[$weekNum] ?? 0.0;
            $longTarget = $longTargets[$weekNum] ?? 0.0;

            // Определить weekInPhase (номер недели внутри текущей фазы)
            $weekInPhase = isset($skeletonWeek['phase_week_index'])
                ? (int) $skeletonWeek['phase_week_index']
                : $this->getWeekInPhase($weekNum, $macrocycle);

            // Построить детали ключевых тренировок
            $workoutDetails = $this->buildWorkoutDetails(
                $skeletonWeek['days'],
                $phase,
                $raceDistance,
                $paceRules,
                $intervalCount,
                $tempoCount,
                $fartlekCount,
                $racePaceCount,
                $weekTuneUpEvent
            );

            // Распределить объём по дням
            $distributed = VolumeDistributor::distribute(
                dayTypes: $skeletonWeek['days'],
                targetVolumeKm: (float) $targetVolume,
                longTargetKm: (float) $longTarget,
                paceRules: $paceRules,
                loadPolicy: $loadPolicy,
                phase: $phase,
                isRecovery: $isRecovery,
                weekInPhase: $weekInPhase,
                workoutDetails: $workoutDetails,
                raceDistanceKm: $raceDistanceKm
            );

            $weeks[] = [
                'week_number' => $weekNum,
                'phase' => $phase,
                'phase_label' => $phaseLabel,
                'is_recovery' => $isRecovery,
                'target_volume_km' => $distributed['target_volume_km'],
                'actual_volume_km' => $distributed['actual_volume_km'],
                'days' => $distributed['days'],
            ];
        }

        return [
            'weeks' => $weeks,
            '_metadata' => [
                'vdot' => $state['vdot'] ?? null,
                'vdot_confidence' => $state['vdot_confidence'] ?? null,
                'start_volume_km' => $loadPolicy['start_volume_km'] ?? null,
                'peak_volume_km' => $loadPolicy['peak_volume_km'] ?? null,
                'experience_level' => $state['experience_level'] ?? null,
                'race_distance' => $raceDistance,
                'goal_type' => $goalType,
                'total_weeks' => count($weeks),
                'generated_at' => date('Y-m-d H:i:s'),
                'generator' => 'PlanSkeletonGenerator',
                'goal_realism' => $this->lastGoalRealism['verdict'] ?? 'realistic',
                'goal_realism_messages' => $this->lastGoalRealism['messages'] ?? [],
                'schedule_anchor_date' => $scheduleAnchorDate,
                'planning_scenario' => $state['planning_scenario'] ?? null,
                'progression_counters_start' => $progressionCounters,
                'adaptation_type' => $state['adaptation_context']['type'] ?? null,
                'completed_goal_context' => $state['completed_goal_context'] ?? null,
                'policy_version' => 'scenario_v1',
            ],
        ];
    }

    private function resolveCompletedRaceGoalContext(array $user, ?string $scheduleAnchorDate): ?array
    {
        $goalType = (string) ($user['goal_type'] ?? 'health');
        if (!in_array($goalType, ['race', 'time_improvement'], true) || $scheduleAnchorDate === null) {
            return null;
        }

        $raceDate = (string) ($user['race_date'] ?? $user['target_marathon_date'] ?? '');
        if ($raceDate === '') {
            return null;
        }

        try {
            $race = new DateTimeImmutable($raceDate);
            $anchor = new DateTimeImmutable($scheduleAnchorDate);
        } catch (Throwable) {
            return null;
        }

        if ($race >= $anchor) {
            return null;
        }

        return [
            'type' => 'post_goal_recovery',
            'completed_goal_type' => $goalType,
            'race_distance' => (string) ($user['race_distance'] ?? ''),
            'race_date' => $race->format('Y-m-d'),
            'anchor_date' => $anchor->format('Y-m-d'),
        ];
    }

    private function buildPostGoalRecoveryUser(array $user, ?string $scheduleAnchorDate): array
    {
        $recoveryUser = $user;
        $recoveryUser['goal_type'] = 'health';
        $recoveryUser['health_program'] = 'regular_running';
        $recoveryUser['health_plan_weeks'] = 4;
        if ($scheduleAnchorDate !== null) {
            $recoveryUser['training_start_date'] = $scheduleAnchorDate;
        }

        return $recoveryUser;
    }

    private function applyCompletedGoalRecoveryState(array $state, array $completedGoalContext): array
    {
        $state['goal_type'] = 'health';
        $state['weeks_to_goal'] = 4;
        $state['completed_goal_context'] = $completedGoalContext;

        $loadPolicy = is_array($state['load_policy'] ?? null) ? $state['load_policy'] : [];
        $loadPolicy['force_initial_recovery_week'] = true;
        $loadPolicy['quality_mode'] = 'simplified';
        $loadPolicy['quality_delay_weeks'] = max((int) ($loadPolicy['quality_delay_weeks'] ?? 0), 2);
        $loadPolicy['allowed_growth_ratio'] = min((float) ($loadPolicy['allowed_growth_ratio'] ?? 1.10), 1.06);
        $loadPolicy['recovery_cutback_ratio'] = min((float) ($loadPolicy['recovery_cutback_ratio'] ?? 0.88), 0.82);
        $loadPolicy['feedback_guard_level'] = 'post_goal_recovery';
        $state['load_policy'] = $loadPolicy;

        return $state;
    }

    private function applyCompletedGoalRecoveryScenario(array $scenario): array
    {
        $flags = array_values(array_unique(array_merge(
            array_map('strval', (array) ($scenario['flags'] ?? [])),
            ['post_goal_recovery']
        )));
        $policyDecisions = array_values(array_unique(array_merge(
            array_map('strval', (array) ($scenario['policy_decisions'] ?? [])),
            ['completed_goal_recovery_plan']
        )));

        $scenario['primary'] = 'post_goal_recovery';
        $scenario['flags'] = $flags;
        $scenario['effective_weeks_to_goal'] = 4;
        $scenario['policy_decisions'] = $policyDecisions;

        return $scenario;
    }

    /**
     * Построить детали ключевых тренировок для недели.
     * Побочный эффект: инкрементирует счётчики.
     */
    private function buildWorkoutDetails(
        array  $dayTypes,
        string $phase,
        string $raceDistance,
        array  $paceRules,
        int   &$intervalCount,
        int   &$tempoCount,
        int   &$fartlekCount,
        int   &$racePaceCount,
        ?array $weekTuneUpEvent = null
    ): array {

        $details = [];

        foreach ($dayTypes as $type) {
            switch ($type) {
                case 'interval':
                    if (!isset($details['interval'])) {
                        $intervalCount++;
                        $result = IntervalProgressionBuilder::build($phase, $intervalCount, $raceDistance, $paceRules);
                        if ($result) {
                            $details['interval'] = $result;
                        }
                    }
                    break;

                case 'tempo':
                    if (!isset($details['tempo'])) {
                        $tempoCount++;
                        // Чередование: чётные tempo = race-pace, нечётные = threshold
                        $useRacePace = ($tempoCount % 2 === 0)
                            && !in_array($phase, ['base', 'pre_base', 'adaptation', 'taper'], true);

                        if ($useRacePace) {
                            $racePaceCount++;
                            $result = RacePaceProgressionBuilder::build($phase, $racePaceCount, $raceDistance, $paceRules);
                        } else {
                            $result = TempoProgressionBuilder::build($phase, $tempoCount, $paceRules, $raceDistance);
                        }

                        if ($result) {
                            $details['tempo'] = $result;
                        }
                    }
                    break;

                case 'fartlek':
                    if (!isset($details['fartlek'])) {
                        $fartlekCount++;
                        $details['fartlek'] = FartlekBuilder::build($fartlekCount, $paceRules, $raceDistance);
                    }
                    break;

                case 'control':
                    if (!isset($details['control'])) {
                        if ($weekTuneUpEvent !== null) {
                            $details['control'] = $this->buildTuneUpControlDetails($weekTuneUpEvent, $raceDistance, $paceRules);
                        } else {
                            $details['control'] = ControlWorkoutBuilder::build($raceDistance, $paceRules);
                        }
                    }
                    break;
            }
        }

        return $details;
    }

    private function resolveWeekTuneUpEvent(array $state, int $weekNum): ?array
    {
        $event = $state['planning_scenario']['tune_up_event'] ?? null;
        if (!is_array($event)) {
            return null;
        }

        return ((int) ($event['week'] ?? 0) === $weekNum) ? $event : null;
    }

    private function buildTuneUpControlDetails(array $event, string $raceDistance, array $paceRules): array
    {
        $distanceKm = max(1.0, (float) ($event['distance_km'] ?? 0.0));
        $warmup = 1.5;
        $cooldown = 1.0;
        if ($distanceKm <= 5.0) {
            $warmup = 1.0;
            $cooldown = 0.8;
        } elseif ($distanceKm >= 15.0) {
            $warmup = 1.5;
            $cooldown = 1.5;
        }

        $controlKm = max(1.0, round($distanceKm - $warmup - $cooldown, 1));
        $targetPaceSec = isset($event['pace_sec']) && (int) $event['pace_sec'] > 0
            ? (int) $event['pace_sec']
            : (int) ($paceRules['marathon_sec'] ?? $paceRules['race_pace_sec'] ?? $paceRules['tempo_sec'] ?? 300);

        return [
            'warmup_km' => $warmup,
            'cooldown_km' => $cooldown,
            'control_km' => $controlKm,
            'total_km' => round($warmup + $controlKm + $cooldown, 1),
            'pace_sec' => $targetPaceSec,
            'event_date' => $event['date'] ?? null,
            'event_type' => $event['forced_type'] ?? 'control',
            'event_distance_km' => $distanceKm,
            'race_distance_context' => $raceDistance,
        ];
    }

    private function getWeekInPhase(int $weekNum, ?array $macrocycle): int
    {
        if (!$macrocycle || empty($macrocycle['phases'])) {
            return $weekNum;
        }

        foreach ($macrocycle['phases'] as $phase) {
            $from = (int) ($phase['weeks_from'] ?? 0);
            $to = (int) ($phase['weeks_to'] ?? 0);
            if ($weekNum >= $from && $weekNum <= $to) {
                return $weekNum - $from + 1;
            }
        }

        return $weekNum;
    }

    private function buildSkeletonOptions(string $mode, array $payload, array $state): array
    {
        $options = [];

        if (!empty($state['planning_scenario']['schedule_anchor_date'])) {
            $options['start_date'] = (string) $state['planning_scenario']['schedule_anchor_date'];
        }

        if (!empty($state['weeks_to_goal'])) {
            $options['weeks'] = $state['weeks_to_goal'];
        }

        $continuationContext = $this->resolveContinuationContext($mode, $payload);
        $currentPhase = is_array($continuationContext['current_phase'] ?? null)
            ? $continuationContext['current_phase']
            : (is_array($payload['current_phase'] ?? null) ? $payload['current_phase'] : null);
        if ($mode === 'recalculate' && $currentPhase !== null) {
            $options['current_phase'] = $currentPhase;
            $options['kept_weeks'] = (int) ($continuationContext['kept_weeks'] ?? ($payload['kept_weeks'] ?? 0));
        }

        return $options;
    }

    private function resolveContinuationContext(string $mode, array $payload): ?array
    {
        if (is_array($payload['continuation_context'] ?? null)) {
            return $payload['continuation_context'];
        }

        if ($mode === 'recalculate' && is_array($payload['current_phase'] ?? null)) {
            return [
                'mode' => 'recalculate',
                'anchor_date' => (string) ($payload['cutoff_date'] ?? ''),
                'kept_weeks' => (int) ($payload['kept_weeks'] ?? 0),
                'current_phase' => $payload['current_phase'],
                'actual_weekly_km_4w' => isset($payload['actual_weekly_km_4w']) ? (float) $payload['actual_weekly_km_4w'] : null,
                'progression_counters' => is_array($payload['progression_counters'] ?? null) ? $payload['progression_counters'] : [],
            ];
        }

        if ($mode === 'next_plan' && (!empty($payload['last_plan_avg_km']) || !empty($payload['cutoff_date']))) {
            return [
                'mode' => 'next_plan',
                'anchor_date' => (string) ($payload['cutoff_date'] ?? ''),
                'last_plan_avg_km' => isset($payload['last_plan_avg_km']) ? (float) $payload['last_plan_avg_km'] : null,
                'recent_plan_weeks' => is_array($payload['recent_plan_weeks'] ?? null) ? $payload['recent_plan_weeks'] : [],
            ];
        }

        return null;
    }

    private function resolveProgressionCounters(string $mode, array $payload, ?array $continuationContext): array
    {
        $counters = [];

        if (is_array($continuationContext['progression_counters'] ?? null)) {
            $counters = $continuationContext['progression_counters'];
        } elseif (is_array($payload['progression_counters'] ?? null)) {
            $counters = $payload['progression_counters'];
        }

        if ($mode !== 'recalculate' || $counters === []) {
            return $counters;
        }

        return [
            'tempo_count' => max(0, (int) ($counters['tempo_count'] ?? 0)),
            'interval_count' => max(0, (int) ($counters['interval_count'] ?? 0)),
            'fartlek_count' => max(0, (int) ($counters['fartlek_count'] ?? 0)),
            'race_pace_count' => max(
                0,
                (int) ($counters['race_pace_count'] ?? intdiv((int) ($counters['tempo_count'] ?? 0), 2))
            ),
            'control_count' => max(0, (int) ($counters['control_count'] ?? 0)),
            'completed_key_days' => max(0, (int) ($counters['completed_key_days'] ?? 0)),
        ];
    }

    private function hasPhaseContinuation(?array $continuationContext): bool
    {
        return is_array($continuationContext)
            && ($continuationContext['mode'] ?? null) === 'recalculate'
            && is_array($continuationContext['current_phase'] ?? null);
    }

    private function buildContinuationLoadPolicy(
        array $basePolicy,
        array $state,
        array $continuationContext,
        string $raceDistance
    ): array {
        $currentPhase = is_array($continuationContext['current_phase'] ?? null)
            ? $continuationContext['current_phase']
            : [];
        $keptWeeks = (int) ($continuationContext['kept_weeks'] ?? 0);
        $phasePlan = $this->buildRelativePhasePlanFromCurrentPhase($currentPhase, $keptWeeks);
        if ($phasePlan === []) {
            return $basePolicy;
        }

        $recoveryWeeks = $this->mapAbsoluteWeeks(
            is_array($currentPhase['recovery_weeks'] ?? null) ? $currentPhase['recovery_weeks'] : [],
            $keptWeeks
        );
        $controlWeeks = $this->mapAbsoluteWeeks(
            is_array($currentPhase['control_weeks'] ?? null) ? $currentPhase['control_weeks'] : [],
            $keptWeeks
        );
        $mappedLongTargets = $this->mapAbsoluteLongTargets(
            is_array($currentPhase['long_run_progression'] ?? null) ? $currentPhase['long_run_progression'] : [],
            $keptWeeks
        );

        $weeklyTargets = $this->buildContinuationWeeklyVolumeTargets(
            $phasePlan,
            $recoveryWeeks,
            $basePolicy,
            $state,
            $currentPhase,
            $raceDistance
        );
        $longTargets = $this->buildContinuationLongTargets(
            $phasePlan,
            $weeklyTargets,
            $recoveryWeeks,
            $mappedLongTargets,
            $basePolicy
        );

        $policy = $basePolicy;
        $policy['weekly_volume_targets_km'] = $weeklyTargets;
        $policy['long_run_targets_km'] = $longTargets;
        $policy['recovery_weeks'] = $recoveryWeeks;
        $policy['control_weeks'] = $controlWeeks;
        $policy['start_volume_km'] = !empty($weeklyTargets) ? (float) reset($weeklyTargets) : null;
        $policy['peak_volume_km'] = !empty($weeklyTargets) ? (float) max($weeklyTargets) : null;

        return $policy;
    }

    private function buildRelativePhasePlanFromCurrentPhase(array $currentPhase, int $keptWeeks): array
    {
        $remainingPhases = is_array($currentPhase['remaining_phases'] ?? null)
            ? $currentPhase['remaining_phases']
            : [];
        if ($remainingPhases === []) {
            return [];
        }

        $phasePlan = [];
        $cursor = 1;
        $currentAbsoluteWeek = max(1, $keptWeeks + 1);

        foreach ($remainingPhases as $index => $phase) {
            $phaseFrom = (int) ($phase['weeks_from'] ?? 0);
            $phaseTo = (int) ($phase['weeks_to'] ?? 0);
            if ($phaseTo < $phaseFrom) {
                continue;
            }

            $startAbsoluteWeek = $index === 0
                ? max($phaseFrom, $currentAbsoluteWeek)
                : $phaseFrom;
            $phaseWeekIndex = max(1, $startAbsoluteWeek - $phaseFrom + 1);

            for ($absoluteWeek = $startAbsoluteWeek; $absoluteWeek <= $phaseTo; $absoluteWeek++, $cursor++, $phaseWeekIndex++) {
                $phasePlan[$cursor] = [
                    'name' => (string) ($phase['name'] ?? 'base'),
                    'label' => (string) ($phase['label'] ?? ''),
                    'phase_week_index' => $phaseWeekIndex,
                ];
            }
        }

        return $phasePlan;
    }

    private function mapAbsoluteWeeks(array $absoluteWeeks, int $keptWeeks): array
    {
        $mapped = [];
        foreach ($absoluteWeeks as $absoluteWeek) {
            $relativeWeek = (int) $absoluteWeek - $keptWeeks;
            if ($relativeWeek >= 1) {
                $mapped[] = $relativeWeek;
            }
        }

        $mapped = array_values(array_unique(array_map('intval', $mapped)));
        sort($mapped, SORT_NUMERIC);

        return $mapped;
    }

    private function mapAbsoluteLongTargets(array $absoluteLongTargets, int $keptWeeks): array
    {
        $mapped = [];
        foreach ($absoluteLongTargets as $absoluteWeek => $distanceKm) {
            $relativeWeek = (int) $absoluteWeek - $keptWeeks;
            if ($relativeWeek < 1) {
                continue;
            }
            $mapped[$relativeWeek] = round((float) $distanceKm, 1);
        }

        ksort($mapped, SORT_NUMERIC);
        return $mapped;
    }

    private function buildContinuationWeeklyVolumeTargets(
        array $phasePlan,
        array $recoveryWeeks,
        array $basePolicy,
        array $state,
        array $currentPhase,
        string $raceDistance
    ): array {
        if ($phasePlan === []) {
            return [];
        }

        $startVolume = isset($state['weekly_base_km']) && (float) $state['weekly_base_km'] > 0
            ? (float) $state['weekly_base_km']
            : (float) ($currentPhase['start_volume_km'] ?? ($basePolicy['start_volume_km'] ?? 0.0));
        $startVolume = round(max(1.0, $startVolume), 1);

        $peakCandidate = (float) ($currentPhase['peak_volume_km'] ?? ($basePolicy['peak_volume_km'] ?? $startVolume));
        $peakCandidate = max($startVolume, round($peakCandidate, 1));

        $allowedGrowthRatio = (float) ($basePolicy['allowed_growth_ratio'] ?? 1.10);
        if ($allowedGrowthRatio < 1.01) {
            $allowedGrowthRatio = 1.01;
        }
        $recoveryCutbackRatio = (float) ($basePolicy['recovery_cutback_ratio'] ?? 0.82);

        $normalPreTaperWeeks = array_values(array_filter(
            array_keys($phasePlan),
            static fn(int $week): bool => (($phasePlan[$week]['name'] ?? '') !== 'taper') && !in_array($week, $recoveryWeeks, true)
        ));
        $reachablePeak = $startVolume * pow($allowedGrowthRatio, max(0, count($normalPreTaperWeeks) - 1));
        $peakVolume = round(max($startVolume, min($peakCandidate, $reachablePeak)), 1);

        $weights = [];
        foreach ($normalPreTaperWeeks as $week) {
            $weights[$week] = $this->resolveContinuationPhaseWeight((string) ($phasePlan[$week]['name'] ?? 'build'));
        }
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            foreach ($normalPreTaperWeeks as $week) {
                $weights[$week] = 1.0;
            }
            $totalWeight = max(1.0, array_sum($weights));
        }

        $targets = [];
        $progressWeight = 0.0;
        $previousNormalTarget = null;
        $previousEffectiveTarget = null;

        foreach ($phasePlan as $week => $phaseInfo) {
            $phaseName = (string) ($phaseInfo['name'] ?? 'build');
            if ($phaseName === 'taper') {
                continue;
            }

            if ($previousNormalTarget === null) {
                $target = $startVolume;
                $effectiveTarget = $target;
                if (in_array($week, $recoveryWeeks, true)) {
                    $effectiveTarget = round($target * $recoveryCutbackRatio, 1);
                } else {
                    $previousNormalTarget = $target;
                }
                $targets[$week] = $target;
                $previousEffectiveTarget = $effectiveTarget;
                continue;
            }

            if (in_array($week, $recoveryWeeks, true)) {
                $reference = $previousNormalTarget ?? $startVolume;
                $effectiveTarget = round(max(1.0, $reference * $recoveryCutbackRatio), 1);
                $targets[$week] = $recoveryCutbackRatio > 0
                    ? round($effectiveTarget / $recoveryCutbackRatio, 1)
                    : $effectiveTarget;
                $previousEffectiveTarget = $effectiveTarget;
                continue;
            }

            $progressWeight += $weights[$week] ?? 1.0;
            $progress = min(1.0, $progressWeight / $totalWeight);
            $target = round($startVolume + (($peakVolume - $startVolume) * $progress), 1);
            $referenceTarget = $previousEffectiveTarget ?? $previousNormalTarget ?? $startVolume;
            $maxAllowed = round($referenceTarget * $allowedGrowthRatio, 1);
            $target = min($target, max($startVolume, $maxAllowed));

            if ($phaseName === 'peak') {
                $peakFloor = round(max($startVolume, $peakVolume * 0.92), 1);
                $target = max(min($peakFloor, $maxAllowed), $target);
            }

            $targets[$week] = round(max(1.0, $target), 1);
            $previousEffectiveTarget = $targets[$week];
            $previousNormalTarget = $targets[$week];
        }

        $taperWeeks = array_values(array_filter(
            array_keys($phasePlan),
            static fn(int $week): bool => (($phasePlan[$week]['name'] ?? '') === 'taper')
        ));
        if ($taperWeeks !== []) {
            $taperRatios = $this->resolveTaperRatiosForRaceDistance($raceDistance, count($taperWeeks));
            $lastNormalTarget = $previousNormalTarget ?? $startVolume;
            foreach ($taperWeeks as $index => $week) {
                $ratio = $taperRatios[min($index, count($taperRatios) - 1)] ?? end($taperRatios);
                $target = round(min($lastNormalTarget, $peakVolume * (float) $ratio), 1);
                if ($index > 0) {
                    $target = min($target, (float) ($targets[$taperWeeks[$index - 1]] ?? $target));
                }
                $targets[$week] = round(max(1.0, $target), 1);
            }
        }

        ksort($targets, SORT_NUMERIC);
        return $targets;
    }

    private function buildContinuationLongTargets(
        array $phasePlan,
        array $weeklyTargets,
        array $recoveryWeeks,
        array $mappedLongTargets,
        array $basePolicy
    ): array {
        if ($phasePlan === [] || $weeklyTargets === []) {
            return [];
        }

        $longShareCap = (float) ($basePolicy['long_share_cap'] ?? 0.45);
        $recoveryCutbackRatio = (float) ($basePolicy['recovery_cutback_ratio'] ?? 0.82);
        $longTargets = [];
        $previousLongTarget = null;
        $previousNormalLongTarget = null;

        foreach ($phasePlan as $week => $phaseInfo) {
            $phaseName = (string) ($phaseInfo['name'] ?? 'build');
            $weeklyTarget = (float) ($weeklyTargets[$week] ?? 0.0);
            $candidate = isset($mappedLongTargets[$week])
                ? (float) $mappedLongTargets[$week]
                : round($weeklyTarget * ($phaseName === 'taper' ? 0.25 : 0.38), 1);

            if ($longShareCap > 0 && $weeklyTarget > 0) {
                $candidate = min($candidate, round($weeklyTarget * $longShareCap, 1));
            }

            if (in_array($week, $recoveryWeeks, true) && $previousNormalLongTarget !== null) {
                $candidate = min($candidate, round($previousNormalLongTarget * $recoveryCutbackRatio, 1));
            }

            if ($phaseName === 'taper' && $previousLongTarget !== null) {
                $candidate = min($candidate, $previousLongTarget);
            }

            $candidate = round(max(0.0, $candidate), 1);
            $longTargets[$week] = $candidate;
            $previousLongTarget = $candidate;

            if (!in_array($week, $recoveryWeeks, true) && $phaseName !== 'taper') {
                $previousNormalLongTarget = $candidate;
            }
        }

        ksort($longTargets, SORT_NUMERIC);
        return $longTargets;
    }

    private function resolveContinuationPhaseWeight(string $phaseName): float
    {
        return match ($phaseName) {
            'pre_base', 'adaptation' => 0.55,
            'base' => 0.70,
            'build', 'development', 'maintenance' => 1.00,
            'peak' => 0.35,
            default => 0.80,
        };
    }

    private function resolveTaperRatiosForRaceDistance(string $raceDistance, int $taperWeeks): array
    {
        if ($taperWeeks < 1) {
            return [];
        }

        $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        return $isLongRace ? match ($taperWeeks) {
            1 => [0.55],
            2 => [0.75, 0.55],
            default => [0.82, 0.68, 0.52],
        } : match ($taperWeeks) {
            1 => [0.70],
            2 => [0.85, 0.70],
            default => [0.90, 0.78, 0.66],
        };
    }

    private function adjustStateForRecalculation(array $state, array $user, array $payload): array
    {
        // Актуализировать weekly_base_km по реальным объёмам
        if (!empty($payload['actual_weekly_km_4w'])) {
            $state['weekly_base_km'] = (float) $payload['actual_weekly_km_4w'];
        }

        // Пересчитать VDOT если есть свежие результаты
        if (!empty($payload['fresh_vdot'])) {
            $state['vdot'] = (float) $payload['fresh_vdot'];
            $paces = getTrainingPaces($state['vdot']);
            if ($paces) {
                $state['training_paces'] = $paces;
                $state['pace_rules'] = $this->buildPaceRules($paces, $state);
            }
        }

        // Анализ причины пересчёта — корректировка load_policy
        $reason = mb_strtolower($this->stringifyPayloadText($payload['reason'] ?? ''), 'UTF-8');
        if ($reason !== '') {
            $breakKeywords = ['перерыв', 'не занимал', 'не бегал', 'пропуск', 'не тренировал', 'пауза'];
            $injuryKeywords = ['травм', 'болел', 'болезн', 'простуд', 'операци', 'восстановлен'];
            $easyKeywords = ['тяжёл', 'тяжел', 'сложн', 'устал', 'перетрен', 'много'];
            $moreKeywords = ['увеличить', 'больше', 'добавить', 'мало', 'недостаточно', 'прибавить'];

            $hasBreak = $this->matchesAny($reason, $breakKeywords);
            $hasInjury = $this->matchesAny($reason, $injuryKeywords);
            $wantsEasier = $this->matchesAny($reason, $easyKeywords);
            $wantsMore = $this->matchesAny($reason, $moreKeywords);

            if ($hasInjury || $hasBreak) {
                // Снижаем стартовый объём на 20%, консервативный рост
                $state['weekly_base_km'] = round(($state['weekly_base_km'] ?? 30) * 0.80, 1);
                $state['load_policy']['allowed_growth_ratio'] = min(
                    $state['load_policy']['allowed_growth_ratio'] ?? 1.10,
                    1.05
                );
            } elseif ($wantsEasier) {
                // Снижаем стартовый объём на 10%
                $state['weekly_base_km'] = round(($state['weekly_base_km'] ?? 30) * 0.90, 1);
            } elseif ($wantsMore) {
                // Поднимаем стартовый объём на 5%, но не более +10%
                $state['weekly_base_km'] = round(($state['weekly_base_km'] ?? 30) * 1.05, 1);
            }
        }

        $state = $this->applyAdaptationContextToState($state, $payload);

        return $state;
    }

    private function applyAdaptationContextToState(array $state, array $payload): array
    {
        $adaptationType = trim((string) ($payload['adaptation_type'] ?? ''));
        if ($adaptationType === '') {
            return $state;
        }

        $metrics = is_array($payload['adaptation_metrics'] ?? null)
            ? $payload['adaptation_metrics']
            : [];
        $loadPolicy = is_array($state['load_policy'] ?? null) ? $state['load_policy'] : [];

        switch ($adaptationType) {
            case 'volume_down':
                $loadPolicy['quality_mode'] = 'simplified';
                $loadPolicy['quality_delay_weeks'] = max((int) ($loadPolicy['quality_delay_weeks'] ?? 0), 1);
                $loadPolicy['quality_workout_share_cap'] = min((float) ($loadPolicy['quality_workout_share_cap'] ?? 0.50), 0.42);
                $loadPolicy['allowed_growth_ratio'] = min((float) ($loadPolicy['allowed_growth_ratio'] ?? 1.10), 1.05);
                $loadPolicy['feedback_guard_level'] = 'fatigue_moderate';
                break;

            case 'volume_down_significant':
                $loadPolicy['quality_mode'] = 'simplified';
                $loadPolicy['force_initial_recovery_week'] = true;
                $loadPolicy['quality_delay_weeks'] = max((int) ($loadPolicy['quality_delay_weeks'] ?? 0), 1);
                $loadPolicy['quality_workout_share_cap'] = min((float) ($loadPolicy['quality_workout_share_cap'] ?? 0.50), 0.38);
                $loadPolicy['allowed_growth_ratio'] = min((float) ($loadPolicy['allowed_growth_ratio'] ?? 1.10), 1.04);
                $loadPolicy['recovery_cutback_ratio'] = min((float) ($loadPolicy['recovery_cutback_ratio'] ?? 0.88), 0.82);
                $loadPolicy['feedback_guard_level'] = 'fatigue_high';
                if (!empty($metrics['actual_volume_km'])) {
                    $state['weekly_base_km'] = min(
                        (float) ($state['weekly_base_km'] ?? (float) $metrics['actual_volume_km']),
                        round((float) $metrics['actual_volume_km'] * 0.95, 1)
                    );
                }
                break;

            case 'simplify_key':
                $loadPolicy['quality_mode'] = 'simplified';
                $loadPolicy['quality_delay_weeks'] = max((int) ($loadPolicy['quality_delay_weeks'] ?? 0), 1);
                $loadPolicy['quality_workout_share_cap'] = min((float) ($loadPolicy['quality_workout_share_cap'] ?? 0.50), 0.40);
                $loadPolicy['allowed_growth_ratio'] = min((float) ($loadPolicy['allowed_growth_ratio'] ?? 1.10), 1.06);
                break;

            case 'insert_recovery':
                $sessions = max(1, (int) ($state['sessions_per_week'] ?? 3));
                $initialRecoveryRunDayCap = $sessions >= 4 ? 3 : $sessions;
                $loadPolicy['quality_mode'] = 'simplified';
                $loadPolicy['force_initial_recovery_week'] = true;
                $loadPolicy['initial_recovery_run_day_cap'] = min(
                    (int) ($loadPolicy['initial_recovery_run_day_cap'] ?? $initialRecoveryRunDayCap),
                    $initialRecoveryRunDayCap
                );
                $loadPolicy['quality_delay_weeks'] = max((int) ($loadPolicy['quality_delay_weeks'] ?? 0), 1);
                $loadPolicy['quality_workout_share_cap'] = min((float) ($loadPolicy['quality_workout_share_cap'] ?? 0.50), 0.38);
                $loadPolicy['allowed_growth_ratio'] = min((float) ($loadPolicy['allowed_growth_ratio'] ?? 1.10), 1.04);
                $loadPolicy['recovery_cutback_ratio'] = min((float) ($loadPolicy['recovery_cutback_ratio'] ?? 0.88), 0.80);
                $loadPolicy['feedback_guard_level'] = 'fatigue_high';
                if (!empty($metrics['actual_volume_km'])) {
                    $state['weekly_base_km'] = min(
                        (float) ($state['weekly_base_km'] ?? (float) $metrics['actual_volume_km']),
                        round((float) $metrics['actual_volume_km'] * 0.95, 1)
                    );
                }
                break;

            case 'volume_up':
                $loadPolicy['allowed_growth_ratio'] = min(
                    1.12,
                    max((float) ($loadPolicy['allowed_growth_ratio'] ?? 1.10), 1.10)
                );
                $loadPolicy['quality_mode'] = 'normal';
                break;

            case 'vdot_adjust_down':
                $state = $this->applyVdotAdjustment($state, $metrics, -1);
                break;

            case 'vdot_adjust_up':
                $state = $this->applyVdotAdjustment($state, $metrics, 1);
                break;
        }

        $state['load_policy'] = $loadPolicy;
        $state['adaptation_context'] = [
            'type' => $adaptationType,
            'metrics' => $metrics,
        ];

        return $state;
    }

    private function applyVdotAdjustment(array $state, array $metrics, int $direction): array
    {
        $currentVdot = isset($state['vdot']) ? (float) $state['vdot'] : 0.0;
        if ($currentVdot <= 0.0 || $direction === 0) {
            return $state;
        }

        $delta = 0.5;
        $actualEasyPaceSec = isset($metrics['avg_actual_easy_pace_sec']) ? (float) $metrics['avg_actual_easy_pace_sec'] : 0.0;
        $plannedEasyMinSec = isset($state['pace_rules']['easy_min_sec']) ? (float) $state['pace_rules']['easy_min_sec'] : 0.0;
        $plannedEasyMaxSec = isset($state['pace_rules']['easy_max_sec']) ? (float) $state['pace_rules']['easy_max_sec'] : 0.0;
        $plannedEasyMidSec = ($plannedEasyMinSec > 0.0 && $plannedEasyMaxSec > 0.0)
            ? (($plannedEasyMinSec + $plannedEasyMaxSec) / 2.0)
            : 0.0;

        if ($actualEasyPaceSec > 0.0 && $plannedEasyMidSec > 0.0) {
            $ratio = $actualEasyPaceSec / $plannedEasyMidSec;
            if ($direction < 0) {
                $delta = $ratio >= 1.12 ? 1.5 : ($ratio >= 1.08 ? 1.0 : 0.5);
            } else {
                $delta = $ratio <= 0.92 ? 1.5 : ($ratio <= 0.96 ? 1.0 : 0.5);
            }
        }

        $newVdot = round(max(20.0, min(85.0, $currentVdot + ($direction * $delta))), 1);
        if ($newVdot === $currentVdot) {
            return $state;
        }

        $trainingPaces = getTrainingPaces($newVdot);
        if (!$trainingPaces) {
            return $state;
        }

        $state['vdot'] = $newVdot;
        $state['training_paces'] = $trainingPaces;
        $state['pace_rules'] = $this->buildPaceRules($trainingPaces, $state, $newVdot);

        return $state;
    }

    private function stringifyPayloadText($raw): string
    {
        if (is_array($raw)) {
            $parts = [];
            array_walk_recursive(
                $raw,
                static function ($value) use (&$parts): void {
                    if (is_scalar($value) || $value === null) {
                        $text = trim((string) $value);
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                }
            );

            return implode(' ', array_values(array_unique($parts)));
        }

        return trim((string) $raw);
    }

    private function buildShortRunwayLoadPolicy(array $state, array $user, string $raceDistance, float $raceDistanceKm): array
    {
        $loadPolicy = $state['load_policy'] ?? [];
        $longShareCap = (float) ($loadPolicy['long_share_cap'] ?? 0.45);
        $weeks = max(1, min(3, (int) ($state['weeks_to_goal'] ?? 0)));
        $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        $readiness = (string) ($state['readiness'] ?? 'normal');
        $guardLevel = (string) ($loadPolicy['feedback_guard_level'] ?? 'neutral');
        $isHighCaution = $readiness === 'low' || in_array($guardLevel, ['fatigue_high', 'pain_protective', 'illness_protective'], true);
        $baseVolume = (float) ($state['weekly_base_km'] ?? ($user['weekly_base_km'] ?? 0.0));
        $planningScenario = is_array($state['planning_scenario'] ?? null) ? $state['planning_scenario'] : [];
        $scenarioFlags = array_map('strval', (array) ($planningScenario['flags'] ?? []));
        $tuneUpEvent = is_array($planningScenario['tune_up_event'] ?? null) ? $planningScenario['tune_up_event'] : null;
        $hasBRaceBeforeARace = in_array('b_race_before_a_race', $scenarioFlags, true) && $tuneUpEvent !== null;

        if ($baseVolume <= 0) {
            $sessions = max(3, (int) ($user['sessions_per_week'] ?? 4));
            $baseVolume = $isLongRace ? max(18.0, $sessions * 5.0) : max(12.0, $sessions * 4.0);
        }

        $weeklyTargets = [];
        $longTargets = [];
        $supplementaryRatio = (float) ($loadPolicy['race_week_supplementary_ratio'] ?? ($isLongRace ? 0.35 : 0.55));

        if ($weeks === 1) {
            $supplementary = max($isLongRace ? 6.0 : 4.0, round($baseVolume * ($isHighCaution ? 0.15 : 0.22), 1));
            $weeklyTargets[1] = round(max($raceDistanceKm, $raceDistanceKm + $supplementary), 1);
            $longTargets[1] = 0.0;
        } elseif ($weeks === 2) {
            $preRaceRatio = $isLongRace
                ? ($isHighCaution ? 0.50 : 0.60)
                : ($isHighCaution ? 0.55 : 0.68);
            $weeklyTargets[1] = round(max($isLongRace ? 22.0 : 14.0, $baseVolume * $preRaceRatio), 1);
            $longTargets[1] = round(min($isLongRace ? 18.0 : 12.0, max($isLongRace ? 12.0 : 8.0, $weeklyTargets[1] * $longShareCap)), 1);

            $supplementary = max($isLongRace ? 6.0 : 4.0, round($weeklyTargets[1] * $supplementaryRatio, 1));
            $weeklyTargets[2] = round(max($raceDistanceKm, $raceDistanceKm + $supplementary), 1);
            $longTargets[2] = 0.0;
        } else {
            $farRatio = $isLongRace
                ? ($isHighCaution ? 0.68 : 0.78)
                : ($isHighCaution ? 0.72 : 0.82);
            $preRaceRatio = $isLongRace
                ? ($isHighCaution ? 0.52 : 0.62)
                : ($isHighCaution ? 0.58 : 0.70);

            $weeklyTargets[1] = round(max($isLongRace ? 28.0 : 18.0, $baseVolume * $farRatio), 1);
            $longTargets[1] = round(min($isLongRace ? 22.0 : 14.0, max($isLongRace ? 14.0 : 9.0, $weeklyTargets[1] * $longShareCap)), 1);

            $weeklyTargets[2] = round(max($isLongRace ? 20.0 : 12.0, $weeklyTargets[1] * $preRaceRatio), 1);
            $longTargets[2] = round(min($isLongRace ? 14.0 : 10.0, max($isLongRace ? 10.0 : 6.0, $weeklyTargets[2] * $longShareCap)), 1);

            $supplementary = max($isLongRace ? 6.0 : 4.0, round($weeklyTargets[2] * $supplementaryRatio, 1));
            $weeklyTargets[3] = round(max($raceDistanceKm, $raceDistanceKm + $supplementary), 1);
            $longTargets[3] = 0.0;
        }

        if ($hasBRaceBeforeARace) {
            $tuneUpWeek = max(1, min($weeks, (int) ($tuneUpEvent['week'] ?? 0)));
            $tuneUpDistanceKm = max(1.0, (float) ($tuneUpEvent['distance_km'] ?? 0.0));
            $mainRaceWeek = $weeks;

            if ($tuneUpWeek >= 1 && isset($weeklyTargets[$tuneUpWeek])) {
                $tuneUpSupportKm = $this->resolveTuneUpWeekSupportKm(
                    $baseVolume,
                    $tuneUpEvent,
                    $isLongRace,
                    $isHighCaution
                );
                $weeklyTargets[$tuneUpWeek] = round(
                    max($weeklyTargets[$tuneUpWeek], $tuneUpDistanceKm + $tuneUpSupportKm),
                    1
                );
                $longTargets[$tuneUpWeek] = 0.0;
            }

            if (isset($weeklyTargets[$mainRaceWeek])) {
                $raceWeekSupportKm = $this->resolvePrimaryRaceWeekSupportKm(
                    $baseVolume,
                    $isLongRace,
                    $isHighCaution,
                    $hasBRaceBeforeARace
                );
                $weeklyTargets[$mainRaceWeek] = round(
                    max($weeklyTargets[$mainRaceWeek], $raceDistanceKm + $raceWeekSupportKm),
                    1
                );
                $longTargets[$mainRaceWeek] = 0.0;
            }
        }

        $loadPolicy['weekly_volume_targets_km'] = $weeklyTargets;
        $loadPolicy['long_run_targets_km'] = $longTargets;
        $loadPolicy['recovery_weeks'] = [];
        $loadPolicy['start_volume_km'] = $weeklyTargets[1] ?? null;
        $loadPolicy['peak_volume_km'] = max($weeklyTargets ?: [0.0]);

        return $loadPolicy;
    }

    private function resolveTuneUpWeekSupportKm(
        float $baseVolume,
        array $tuneUpEvent,
        bool $isLongRace,
        bool $isHighCaution
    ): float {
        $tuneUpDistanceKm = max(1.0, (float) ($tuneUpEvent['distance_km'] ?? 0.0));
        $isLongTuneUp = $tuneUpDistanceKm >= 15.0;

        if ($isLongRace && $isLongTuneUp) {
            $candidate = $baseVolume * ($isHighCaution ? 0.24 : 0.32);
            $minSupport = $isHighCaution ? 9.0 : 12.0;
            $maxSupport = $isHighCaution ? 13.0 : 16.0;
            return round(min($maxSupport, max($minSupport, $candidate)), 1);
        }

        $candidate = $baseVolume * ($isHighCaution ? 0.18 : 0.24);
        $minSupport = $isHighCaution ? 6.0 : 8.0;
        $maxSupport = $isHighCaution ? 9.0 : 12.0;
        return round(min($maxSupport, max($minSupport, $candidate)), 1);
    }

    private function resolvePrimaryRaceWeekSupportKm(
        float $baseVolume,
        bool $isLongRace,
        bool $isHighCaution,
        bool $hasBRaceBeforeARace = false
    ): float {
        if ($isLongRace) {
            if ($hasBRaceBeforeARace && !$isHighCaution) {
                $candidate = $baseVolume * 0.36;
                $minSupport = 12.0;
                $maxSupport = 16.0;
            } else {
                $candidate = $baseVolume * ($isHighCaution ? 0.18 : 0.30);
                $minSupport = $isHighCaution ? 6.0 : 10.0;
                $maxSupport = $isHighCaution ? 10.0 : 14.0;
            }
            return round(min($maxSupport, max($minSupport, $candidate)), 1);
        }

        $candidate = $baseVolume * ($isHighCaution ? 0.14 : 0.22);
        $minSupport = $isHighCaution ? 4.0 : 6.0;
        $maxSupport = $isHighCaution ? 7.0 : 10.0;
        return round(min($maxSupport, max($minSupport, $candidate)), 1);
    }

    private function matchesAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (preg_match($this->buildKeywordPattern($kw), $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private function buildKeywordPattern(string $keyword): string
    {
        $tokens = preg_split('/\s+/u', trim($keyword)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

        if (empty($tokens)) {
            return '/^$/u';
        }

        $escapedTokens = array_map(
            static fn(string $token): string => preg_quote($token, '/'),
            $tokens
        );

        $lastToken = array_pop($escapedTokens);
        $prefix = !empty($escapedTokens) ? implode('\s+', $escapedTokens) . '\s+' : '';

        return '/(?<!\pL)' . $prefix . $lastToken . '\pL*/u';
    }

    /**
     * Пересобрать weekly_volume_targets_km из macrocycle (для recalculate/next_plan).
     */
    private function rebuildWeeklyVolumeTargets(
        array $macrocycle,
        string $goalType,
        string $raceDistance,
        array $basePolicy = []
    ): array
    {
        $totalWeeks = (int) ($macrocycle['total_weeks'] ?? 0);
        $startVolume = isset($macrocycle['start_volume_km']) ? (float) $macrocycle['start_volume_km'] : 0.0;
        $peakVolume = isset($macrocycle['peak_volume_km']) ? (float) $macrocycle['peak_volume_km'] : $startVolume;
        if ($totalWeeks < 1 || $startVolume <= 0 || $peakVolume <= 0) {
            return [];
        }

        $targets = [];
        $recoveryWeeks = array_map('intval', $macrocycle['recovery_weeks'] ?? []);
        $allowedGrowthRatio = (float) ($basePolicy['allowed_growth_ratio'] ?? 1.10);
        $recoveryCutbackRatio = (float) ($basePolicy['recovery_cutback_ratio'] ?? 0.82);
        $peakWeek = max(1, min($totalWeeks, (int) ($macrocycle['long_run']['peak_week'] ?? $totalWeeks)));

        $taperFromWeek = $totalWeeks + 1;
        foreach (($macrocycle['phases'] ?? []) as $phase) {
            if (($phase['name'] ?? null) === 'taper') {
                $taperFromWeek = (int) ($phase['weeks_from'] ?? ($totalWeeks + 1));
                break;
            }
        }

        $taperWeeks = $taperFromWeek <= $totalWeeks ? ($totalWeeks - $taperFromWeek + 1) : 0;
        $taperRatios = $this->resolveTaperRatiosForRaceDistance($raceDistance, $taperWeeks);

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

        // Post-processing: cap growth between consecutive non-recovery weeks.
        $lastNormalTarget = null;
        for ($week = 1; $week <= $totalWeeks; $week++) {
            if (!isset($targets[$week])) continue;
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

        // Recalculate recovery weeks based on capped normal targets
        for ($week = 1; $week <= $totalWeeks; $week++) {
            if (in_array($week, $recoveryWeeks, true) && $week > 1) {
                // Recovery = policy cutback from the previous normal week.
                $prevNormal = null;
                for ($p = $week - 1; $p >= 1; $p--) {
                    if (!in_array($p, $recoveryWeeks, true)) {
                        $prevNormal = $targets[$p];
                        break;
                    }
                }
                if ($prevNormal !== null) {
                    $targets[$week] = round($prevNormal * $recoveryCutbackRatio, 1);
                }
            }
        }

        return $targets;
    }

    private function adjustStateForNextPlan(array $state, array $user, array $payload): array
    {
        // Для нового плана — стартовый объём из реальных данных
        if (!empty($payload['last_plan_avg_km'])) {
            $state['weekly_base_km'] = (float) $payload['last_plan_avg_km'];
        }

        // Новые цели
        if (!empty($payload['new_goal_type'])) {
            $state['goal_type'] = $payload['new_goal_type'];
        }
        if (!empty($payload['new_race_distance'])) {
            $state['race_distance'] = $payload['new_race_distance'];
        }

        return $state;
    }

    private function buildPaceRules(array $paces, array $state): array
    {
        // Реконструируем pace_rules из training_paces
        // Формат paces: ['easy' => [slowSec, fastSec], 'marathon' => sec, 'threshold' => sec, ...]
        $easyPaces = $paces['easy'] ?? [360, 340];
        $marathonSec = isset($paces['marathon']) ? (int) $paces['marathon'] : null;
        // goal_pace_sec из state (рассчитан из целевого времени пользователя)
        $racePaceSec = $state['goal_pace_sec'] ?? $marathonSec;

        $tempoSec = (int) ($paces['threshold'] ?? 300);
        $intervalSec = (int) ($paces['interval'] ?? 280);
        $repetitionSec = isset($paces['repetition']) ? (int) $paces['repetition'] : ($intervalSec - 12);

        // HMP и 10k pace: предпочитаем VDOT-предсказание
        $vdot = $state['vdot'] ?? null;
        if ($vdot !== null && function_exists('predictRaceTime')) {
            $halfTimeSec = predictRaceTime($vdot, 21.0975);
            $halfPaceSec = (int) round($halfTimeSec / 21.0975);
            $tenKTimeSec = predictRaceTime($vdot, 10.0);
            $tenKPaceSec = (int) round($tenKTimeSec / 10.0);
        } else {
            $halfPaceSec = $marathonSec
                ? (int) round($tempoSec + ($marathonSec - $tempoSec) * 0.35)
                : (int) round($tempoSec * 1.04);
            $tenKPaceSec = (int) round($tempoSec - ($tempoSec - $intervalSec) * 0.20);
        }

        return [
            'easy_min_sec' => (int) min($easyPaces),  // быстрая граница
            'easy_max_sec' => (int) max($easyPaces),  // медленная граница
            'long_min_sec' => (int) max($easyPaces),
            'long_max_sec' => (int) (max($easyPaces) + 15),
            'tempo_sec' => $tempoSec,
            'tempo_tolerance_sec' => 10,
            'interval_sec' => $intervalSec,
            'interval_tolerance_sec' => 10,
            'recovery_min_sec' => (int) max($easyPaces) + 20,
            'recovery_max_sec' => (int) max($easyPaces) + 40,
            'marathon_sec' => $marathonSec,
            'race_pace_sec' => $racePaceSec,
            'repetition_sec' => $repetitionSec,
            'half_pace_sec' => $halfPaceSec,
            'ten_k_pace_sec' => $tenKPaceSec,
        ];
    }

    private function getDistanceKm(string $distance): float
    {
        return match ($distance) {
            '5k' => 5.0,
            '10k' => 10.0,
            'half', '21.1k' => 21.0975,
            'marathon', '42.2k' => 42.195,
            default => 10.0, // fallback на 10k если дистанция не указана
        };
    }

    private function loadUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, goal_type, race_distance, race_date, race_target_time,
                   target_marathon_date, target_marathon_time, training_start_date,
                   gender, birth_year, height_cm, weight_kg, experience_level,
                   weekly_base_km, sessions_per_week, preferred_days, preferred_ofp_days,
                   has_treadmill, ofp_preference, training_time_pref, health_notes,
                   weight_goal_kg, weight_goal_date, health_program, health_plan_weeks,
                   current_running_level, running_experience, easy_pace_sec,
                   is_first_race_at_distance, last_race_distance, last_race_distance_km,
                   last_race_time, last_race_date, device_type
            FROM users WHERE id = ?
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            throw new \RuntimeException("User $userId not found");
        }

        // Декодировать JSON-поля
        foreach (['preferred_days', 'preferred_ofp_days'] as $field) {
            if (!empty($user[$field]) && is_string($user[$field])) {
                $decoded = json_decode($user[$field], true);
                $user[$field] = is_array($decoded) ? $decoded : [];
            } else {
                $user[$field] = [];
            }
        }

        return $user;
    }
}
