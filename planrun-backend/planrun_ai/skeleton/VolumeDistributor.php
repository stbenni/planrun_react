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
        // Динамический минимум easy: 5% от недельного объёма, но не менее load_policy floor
        $policyEasyMin = (float) ($loadPolicy['easy_min_km'] ?? 2.0);
        $easyMinKm = max($policyEasyMin, round($targetVolumeKm * 0.05, 1));

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
                    $tempoDetails = $workoutDetails['tempo'] ?? null;
                    $km = $tempoDetails ? self::calculateTotalKm($tempoDetails) : 6.5;
                    $km = round($km * $recoveryRatio, 1);
                    $dayDistances[$i] = $km;
                    $dayData[$i] = array_merge($dayData[$i], self::applyTempoDetails($tempoDetails, $paceRules, $km));
                    $fixedKm += $km;
                    break;

                case 'interval':
                    $intervalDetails = $workoutDetails['interval'] ?? null;
                    $km = $intervalDetails ? self::calculateTotalKm($intervalDetails) : 8.0;
                    $km = round($km * $recoveryRatio, 1);
                    $dayDistances[$i] = $km;
                    $dayData[$i] = array_merge($dayData[$i], self::applyIntervalDetails($intervalDetails, $paceRules, $km));
                    $fixedKm += $km;
                    break;

                case 'fartlek':
                    $fartlekDetails = $workoutDetails['fartlek'] ?? null;
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
            $easyKm = round($remainingKm / $easyCount, 1);
            $easyKm = max($easyMinKm, $easyKm);

            // Если easy слишком большой (>60% недельного) — ограничить
            $maxEasyKm = round($effectiveTarget * 0.25, 1);
            if ($easyKm > $maxEasyKm && $maxEasyKm > $easyMinKm) {
                $easyKm = $maxEasyKm;
            }

            $easyPace = self::formatPace($paceRules['easy_min_sec'] ?? 340);

            for ($i = 0; $i < 7; $i++) {
                if ($dayTypes[$i] === 'easy') {
                    $dayData[$i]['distance_km'] = $easyKm;
                    $dayData[$i]['pace'] = $easyPace;
                    $dayDistances[$i] = $easyKm;
                }
            }
        }

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
