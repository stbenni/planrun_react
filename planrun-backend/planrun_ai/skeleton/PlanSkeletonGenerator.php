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

        // TrainingState: VDOT, темпы, load_policy, readiness
        require_once __DIR__ . '/../../services/TrainingStateBuilder.php';
        $stateBuilder = new TrainingStateBuilder($this->db);
        $state = $stateBuilder->buildForUser($user);
        $this->lastState = $state;

        // Для recalculate — скорректировать state
        if ($mode === 'recalculate') {
            $state = $this->adjustStateForRecalculation($state, $user, $payload);
        } elseif ($mode === 'next_plan') {
            $state = $this->adjustStateForNextPlan($state, $user, $payload);
        }
        $this->lastState = $state;

        $paceRules = $state['pace_rules'] ?? [];
        $loadPolicy = $state['load_policy'] ?? [];

        // Macrocycle: фазы, прогрессия длительной
        // При recalculate — сдвигаем training_start_date и weekly_base_km
        $macroUser = $user;
        if ($mode === 'recalculate' || $mode === 'next_plan') {
            $macroUser['training_start_date'] = $payload['cutoff_date']
                ?? (new DateTime())->modify('monday this week')->format('Y-m-d');
            // Передаём скорректированный weekly_base_km из state в macrocycle
            if (!empty($state['weekly_base_km'])) {
                $macroUser['weekly_base_km'] = $state['weekly_base_km'];
            }
        }
        $macrocycle = in_array($goalType, ['race', 'time_improvement'], true)
            ? computeMacrocycle($macroUser, $goalType)
            : computeHealthMacrocycle($macroUser, $goalType);

        // Пересобрать volume targets в loadPolicy из пересчитанного macrocycle
        if ($macrocycle && ($mode === 'recalculate' || $mode === 'next_plan')) {
            $loadPolicy = $state['load_policy'] ?? [];
            $loadPolicy['recovery_weeks'] = array_map('intval', $macrocycle['recovery_weeks'] ?? []);
            $loadPolicy['long_run_targets_km'] = array_map(
                static fn($km): float => round((float) $km, 1),
                $macrocycle['long_run']['by_week'] ?? []
            );
            $loadPolicy['start_volume_km'] = isset($macrocycle['start_volume_km']) ? (float) $macrocycle['start_volume_km'] : null;
            $loadPolicy['peak_volume_km'] = isset($macrocycle['peak_volume_km']) ? (float) $macrocycle['peak_volume_km'] : null;
            $loadPolicy['weekly_volume_targets_km'] = $this->rebuildWeeklyVolumeTargets($macrocycle, $goalType, $user['race_distance'] ?? '');
            $state['load_policy'] = $loadPolicy;
        }

        // Skeleton: типы дней по дням недели
        $skeletonBuilder = new PlanSkeletonBuilder();
        $skeletonOptions = $this->buildSkeletonOptions($mode, $payload, $state);
        $skeletonUser = array_merge($user, ['training_state' => $state]);
        // При recalculate — подменяем training_start_date для правильного расчёта фаз
        if ($mode === 'recalculate' || $mode === 'next_plan') {
            $skeletonUser['training_start_date'] = $payload['cutoff_date']
                ?? (new DateTime())->modify('monday this week')->format('Y-m-d');
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
        $goalRealism = assessGoalRealism($user);
        $this->lastGoalRealism = $goalRealism;

        // Генерируем полный числовой план
        $raceDistance = $user['race_distance'] ?? $state['race_distance'] ?? '';
        $raceDistanceKm = $this->getDistanceKm($raceDistance);

        return $this->buildFullPlan(
            $skeleton,
            $state,
            $macrocycle,
            $goalType,
            $raceDistance,
            $raceDistanceKm
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
        float  $raceDistanceKm
    ): array {

        $paceRules = $state['pace_rules'] ?? [];
        $loadPolicy = $state['load_policy'] ?? [];
        $volumeTargets = $loadPolicy['weekly_volume_targets_km'] ?? [];
        $longTargets = $loadPolicy['long_run_targets_km'] ?? [];
        $recoveryWeeks = $loadPolicy['recovery_weeks'] ?? [];

        // Счётчики прогрессии для каждого типа ключевой тренировки
        $intervalCount = 0;
        $tempoCount = 0;
        $fartlekCount = 0;
        $racePaceCount = 0;

        $weeks = [];

        foreach ($skeleton['weeks'] as $skeletonWeek) {
            $weekNum = $skeletonWeek['week_number'];
            $phase = $skeletonWeek['phase_name'] ?? 'base';
            $phaseLabel = $skeletonWeek['phase_label'] ?? '';
            $isRecovery = in_array($weekNum, $recoveryWeeks, true);

            $targetVolume = $volumeTargets[$weekNum] ?? 0.0;
            $longTarget = $longTargets[$weekNum] ?? 0.0;

            // Определить weekInPhase (номер недели внутри текущей фазы)
            $weekInPhase = $this->getWeekInPhase($weekNum, $macrocycle);

            // Построить детали ключевых тренировок
            $workoutDetails = $this->buildWorkoutDetails(
                $skeletonWeek['days'],
                $phase,
                $raceDistance,
                $paceRules,
                $intervalCount,
                $tempoCount,
                $fartlekCount,
                $racePaceCount
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
            ],
        ];
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
        int   &$racePaceCount
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
                        $details['control'] = ControlWorkoutBuilder::build($raceDistance, $paceRules);
                    }
                    break;
            }
        }

        return $details;
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

        if (!empty($state['weeks_to_goal'])) {
            $options['weeks'] = $state['weeks_to_goal'];
        }

        if ($mode === 'recalculate' && !empty($payload['current_phase'])) {
            $options['current_phase'] = $payload['current_phase'];
            $options['kept_weeks'] = $payload['kept_weeks'] ?? 0;
        }

        return $options;
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
        $reason = mb_strtolower(trim($payload['reason'] ?? ''));
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

        return $state;
    }

    private function matchesAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Пересобрать weekly_volume_targets_km из macrocycle (для recalculate/next_plan).
     */
    private function rebuildWeeklyVolumeTargets(array $macrocycle, string $goalType, string $raceDistance): array
    {
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
        $isLongRace = in_array($raceDistance, ['half', '21.1k', 'marathon', '42.2k'], true);
        $taperRatios = $taperWeeks > 0 ? ($isLongRace ? match ($taperWeeks) {
            1 => [0.55], 2 => [0.75, 0.55], default => [0.82, 0.68, 0.52],
        } : match ($taperWeeks) {
            1 => [0.70], 2 => [0.85, 0.70], default => [0.90, 0.78, 0.66],
        }) : [];

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

        // Post-processing: cap growth between consecutive non-recovery weeks at 10%
        $lastNormalTarget = null;
        for ($week = 1; $week <= $totalWeeks; $week++) {
            if (!isset($targets[$week])) continue;
            $isRecovery = in_array($week, $recoveryWeeks, true);
            $isTaper = $week >= $taperFromWeek;

            if (!$isRecovery && !$isTaper && $lastNormalTarget !== null) {
                $maxAllowed = round($lastNormalTarget * 1.10, 1);
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
                // Recovery = 82% of what this week WOULD have been (the previous normal week + growth)
                $prevNormal = null;
                for ($p = $week - 1; $p >= 1; $p--) {
                    if (!in_array($p, $recoveryWeeks, true)) {
                        $prevNormal = $targets[$p];
                        break;
                    }
                }
                if ($prevNormal !== null) {
                    $targets[$week] = round($prevNormal * 0.82, 1);
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
