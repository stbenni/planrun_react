<?php
/**
 * TempoProgressionBuilder — прогрессия темповых (пороговых) тренировок.
 *
 * Возвращает структуру темповой тренировки (warmup, tempo_km, cooldown, total_km)
 * для данной недели/фазы. Темп = Threshold pace (86% VO2max по Daniels).
 *
 * Прогрессия зависит от целевой дистанции:
 * - Марафон: 5-12 км порогового бега (длинные темповые — ключ к марафону)
 * - Полумарафон: 4-10 км
 * - 10к: 3-7 км
 * - 5к: 3-6 км
 */

require_once __DIR__ . '/WarmupCooldownHelper.php';

class TempoProgressionBuilder
{
    /**
     * Прогрессия темповой работы (км в пороговом темпе) по дистанции.
     * Ключ = порядковый номер темповой тренировки (1-based).
     */
    private const TEMPO_BY_DISTANCE = [
        // Марафон: длинные темповые 5-12 км (25-60 мин T-pace)
        'marathon' => [
            1  => 5.0,   // ~25 мин
            2  => 6.0,   // ~30 мин
            3  => 7.0,   // ~35 мин
            4  => 7.0,
            5  => 8.0,   // ~40 мин
            6  => 8.0,
            7  => 10.0,  // ~50 мин
            8  => 10.0,
            9  => 12.0,  // ~60 мин (пиковый)
            10 => 12.0,
        ],
        // Полумарафон: 4-10 км (20-50 мин)
        'half' => [
            1  => 4.0,
            2  => 5.0,
            3  => 5.0,
            4  => 6.0,
            5  => 6.0,
            6  => 7.0,
            7  => 8.0,
            8  => 8.0,
            9  => 10.0,
            10 => 10.0,
        ],
        // 10к: 3-7 км (15-35 мин)
        '10k' => [
            1  => 3.0,
            2  => 4.0,
            3  => 4.0,
            4  => 5.0,
            5  => 5.0,
            6  => 6.0,
            7  => 6.0,
            8  => 7.0,
            9  => 7.0,
            10 => 7.0,
        ],
        // 5к: 3-6 км (15-30 мин)
        '5k' => [
            1  => 3.0,
            2  => 3.0,
            3  => 4.0,
            4  => 4.0,
            5  => 5.0,
            6  => 5.0,
            7  => 5.0,
            8  => 6.0,
            9  => 6.0,
            10 => 6.0,
        ],
    ];

    private const TAPER_TEMPO_KM = [
        'marathon' => 4.0,
        'half'     => 3.0,
        '10k'      => 3.0,
        '5k'       => 2.0,
    ];

    /**
     * Построить темповую тренировку.
     *
     * @param string $phase        Фаза ('base','build','peak','taper')
     * @param int    $tempoNumber  Порядковый номер темповой тренировки (1-based)
     * @param array  $paceRules    Темпы из TrainingStateBuilder
     * @param string $raceDistance Дистанция забега ('5k','10k','half','marathon')
     * @return array|null          null если в данной фазе нет темповых
     */
    public static function build(
        string $phase,
        int    $tempoNumber,
        array  $paceRules,
        string $raceDistance = 'half'
    ): ?array {

        if ($phase === 'base' || $phase === 'pre_base' || $phase === 'adaptation') {
            return null;
        }

        $distKey = self::normalizeDistance($raceDistance);

        if ($phase === 'taper') {
            $tempoKm = self::TAPER_TEMPO_KM[$distKey] ?? 3.0;
        } else {
            $progression = self::TEMPO_BY_DISTANCE[$distKey] ?? self::TEMPO_BY_DISTANCE['half'];
            $count = count($progression);
            if ($tempoNumber > $count) {
                // Overflow: циклируем по последним 4 записям (пиковые значения)
                $cycleBase = max(1, $count - 3);
                $idx = $cycleBase + (($tempoNumber - $count - 1) % 4);
            } else {
                $idx = $tempoNumber;
            }
            $tempoKm = $progression[$idx] ?? 5.0;
        }

        $warmup = WarmupCooldownHelper::warmup($raceDistance);
        $cooldown = WarmupCooldownHelper::cooldown($raceDistance);
        $totalKm = round($warmup + $tempoKm + $cooldown, 1);
        $tempoPaceSec = $paceRules['tempo_sec'] ?? 300;

        return [
            'warmup_km' => $warmup,
            'cooldown_km' => $cooldown,
            'tempo_km' => $tempoKm,
            'total_km' => $totalKm,
            'tempo_pace_sec' => $tempoPaceSec,
            'tempo_duration_min' => (int) round($tempoKm * $tempoPaceSec / 60),
        ];
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
