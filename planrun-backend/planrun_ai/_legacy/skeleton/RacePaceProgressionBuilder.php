<?php
/**
 * RacePaceProgressionBuilder — тренировки в соревновательном темпе.
 *
 * Генерирует race-pace тренировки, специфичные для целевой дистанции:
 * - Марафон: MP-run (marathon pace) 8→16 км
 * - Полумарафон: HMP-run (half-marathon pace) 6→12 км
 * - 10к: 10k-pace run 4→8 км
 * - 5к: R-pace repeats 200→400м (быстрее I-pace, нейромышечная скорость)
 *
 * Race-pace тренировки чередуются с threshold-темповыми (T-pace):
 * нечётные tempo = T-pace, чётные tempo = race-pace.
 */

require_once __DIR__ . '/WarmupCooldownHelper.php';

class RacePaceProgressionBuilder
{
    /**
     * Марафон: MP-run 8→16 км в марафонском темпе.
     * Ключ = порядковый номер race-pace тренировки (1-based).
     */
    private const MARATHON_PROGRESSION = [
        1  => 8.0,
        2  => 10.0,
        3  => 10.0,
        4  => 12.0,
        5  => 12.0,
        6  => 14.0,
        7  => 14.0,
        8  => 16.0,
    ];

    /**
     * Полумарафон: HMP-run 6→12 км в темпе полумарафона.
     */
    private const HALF_PROGRESSION = [
        1  => 6.0,
        2  => 7.0,
        3  => 8.0,
        4  => 8.0,
        5  => 10.0,
        6  => 10.0,
        7  => 12.0,
        8  => 12.0,
    ];

    /**
     * 10к: 10k-pace run 4→8 км.
     */
    private const TEN_K_PROGRESSION = [
        1  => 4.0,
        2  => 5.0,
        3  => 5.0,
        4  => 6.0,
        5  => 6.0,
        6  => 7.0,
        7  => 8.0,
        8  => 8.0,
    ];

    /**
     * 5к: R-pace repeats (быстрее I-pace, развитие скорости).
     */
    private const FIVE_K_PROGRESSION = [
        1  => ['reps' => 6,  'interval_m' => 200, 'rest_m' => 200],
        2  => ['reps' => 8,  'interval_m' => 200, 'rest_m' => 200],
        3  => ['reps' => 6,  'interval_m' => 300, 'rest_m' => 300],
        4  => ['reps' => 8,  'interval_m' => 300, 'rest_m' => 300],
        5  => ['reps' => 6,  'interval_m' => 400, 'rest_m' => 400],
        6  => ['reps' => 8,  'interval_m' => 400, 'rest_m' => 400],
        7  => ['reps' => 6,  'interval_m' => 400, 'rest_m' => 400],
        8  => ['reps' => 8,  'interval_m' => 400, 'rest_m' => 400],
    ];

    private const TAPER = [
        'marathon' => 6.0,
        'half'     => 5.0,
        '10k'      => 3.0,
        '5k'       => ['reps' => 4, 'interval_m' => 200, 'rest_m' => 200],
    ];

    /**
     * Построить race-pace тренировку.
     *
     * @param string $phase          Фаза ('base','build','peak','taper')
     * @param int    $racePaceNumber Порядковый номер race-pace тренировки (1-based)
     * @param string $raceDistance   Дистанция забега ('5k','10k','half','marathon')
     * @param array  $paceRules      Темпы из TrainingStateBuilder
     * @return array|null            null если в данной фазе нет race-pace
     */
    public static function build(
        string $phase,
        int    $racePaceNumber,
        string $raceDistance,
        array  $paceRules
    ): ?array {

        if ($phase === 'base' || $phase === 'pre_base' || $phase === 'adaptation') {
            return null;
        }

        $distKey = self::normalizeDistance($raceDistance);

        // Для 5к — R-pace repeats (interval-подобная структура)
        if ($distKey === '5k') {
            return self::buildRepeats($phase, $racePaceNumber, $raceDistance, $paceRules);
        }

        // Для marathon/half/10k — continuous run at race pace
        return self::buildContinuous($phase, $racePaceNumber, $distKey, $raceDistance, $paceRules);
    }

    /**
     * Continuous race-pace run (marathon, half, 10k).
     */
    private static function buildContinuous(
        string $phase,
        int    $racePaceNumber,
        string $distKey,
        string $raceDistance,
        array  $paceRules
    ): array {

        if ($phase === 'taper') {
            $workKm = self::TAPER[$distKey] ?? 5.0;
        } else {
            $progression = match ($distKey) {
                'marathon' => self::MARATHON_PROGRESSION,
                'half'     => self::HALF_PROGRESSION,
                '10k'      => self::TEN_K_PROGRESSION,
                default    => self::HALF_PROGRESSION,
            };
            $count = count($progression);
            if ($racePaceNumber > $count) {
                $cycleBase = max(1, $count - 3);
                $idx = $cycleBase + (($racePaceNumber - $count - 1) % 4);
            } else {
                $idx = $racePaceNumber;
            }
            $workKm = $progression[$idx] ?? 8.0;
        }

        $warmup = WarmupCooldownHelper::warmup($raceDistance);
        $cooldown = WarmupCooldownHelper::cooldown($raceDistance);
        $totalKm = round($warmup + $workKm + $cooldown, 1);
        $paceSec = self::getRacePaceSec($distKey, $paceRules);

        return [
            'subtype' => 'race_pace',
            'race_pace_label' => self::getLabel($distKey),
            'warmup_km' => $warmup,
            'cooldown_km' => $cooldown,
            'tempo_km' => $workKm,
            'total_km' => $totalKm,
            'tempo_pace_sec' => $paceSec,
            'tempo_duration_min' => (int) round($workKm * $paceSec / 60),
        ];
    }

    /**
     * R-pace repeats (5k specific).
     */
    private static function buildRepeats(
        string $phase,
        int    $racePaceNumber,
        string $raceDistance,
        array  $paceRules
    ): array {

        if ($phase === 'taper') {
            $template = self::TAPER['5k'];
        } else {
            $count = count(self::FIVE_K_PROGRESSION);
            if ($racePaceNumber > $count) {
                $cycleBase = max(1, $count - 3);
                $idx = $cycleBase + (($racePaceNumber - $count - 1) % 4);
            } else {
                $idx = $racePaceNumber;
            }
            $template = self::FIVE_K_PROGRESSION[$idx] ?? self::FIVE_K_PROGRESSION[1];
        }

        $reps = $template['reps'];
        $intervalM = $template['interval_m'];
        $restM = $template['rest_m'];

        $warmup = WarmupCooldownHelper::warmup($raceDistance);
        $cooldown = WarmupCooldownHelper::cooldown($raceDistance);
        $workKm = ($reps * $intervalM) / 1000.0;
        $restKm = ($reps * $restM) / 1000.0;
        $totalKm = round($warmup + $workKm + $restKm + $cooldown, 1);

        $repetitionSec = $paceRules['repetition_sec']
            ?? (($paceRules['interval_sec'] ?? 240) - 12);

        return [
            'subtype' => 'race_pace',
            'race_pace_label' => 'R-pace',
            'reps' => $reps,
            'interval_m' => $intervalM,
            'rest_m' => $restM,
            'rest_type' => 'walk',
            'warmup_km' => $warmup,
            'cooldown_km' => $cooldown,
            'work_km' => round($workKm, 1),
            'rest_km' => round($restKm, 1),
            'total_km' => $totalKm,
            'interval_pace_sec' => $repetitionSec,
            'tempo_pace_sec' => $repetitionSec,
        ];
    }

    /**
     * Получить race-pace для конкретной дистанции.
     */
    private static function getRacePaceSec(string $distKey, array $paceRules): int
    {
        return match ($distKey) {
            'marathon' => $paceRules['race_pace_sec']
                ?? $paceRules['marathon_sec']
                ?? (int) (($paceRules['tempo_sec'] ?? 265) * 1.12),
            'half'     => $paceRules['half_pace_sec']
                ?? (int) round(($paceRules['tempo_sec'] ?? 265) * 1.04),
            '10k'      => $paceRules['ten_k_pace_sec']
                ?? (int) round(($paceRules['tempo_sec'] ?? 265) * 0.97),
            default    => $paceRules['race_pace_sec'] ?? $paceRules['tempo_sec'] ?? 300,
        };
    }

    private static function getLabel(string $distKey): string
    {
        return match ($distKey) {
            'marathon' => 'MP-run',
            'half'     => 'HMP-run',
            '10k'      => '10k-pace',
            '5k'       => 'R-pace',
            default    => 'Race-pace',
        };
    }

    private static function normalizeDistance(string $dist): string
    {
        $map = [
            'marathon' => 'marathon', '42.2k' => 'marathon', '42k' => 'marathon',
            'half' => 'half', '21.1k' => 'half', '21k' => 'half',
            '10k' => '10k', '10' => '10k',
            '5k' => '5k', '5' => '5k',
        ];
        return $map[strtolower($dist)] ?? 'half';
    }
}
