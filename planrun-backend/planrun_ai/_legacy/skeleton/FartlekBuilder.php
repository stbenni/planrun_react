<?php
/**
 * FartlekBuilder — генерация фартлек-тренировок.
 *
 * Используется в base-фазе (подготовка к интервалам) и для health/weight_loss целей.
 * Фартлек = чередование быстрых и медленных отрезков в свободной форме.
 *
 * Прогрессия зависит от дистанции:
 * - 5к: короткие быстрые отрезки (200-400м) с коротким отдыхом
 * - 10к: средние отрезки (300-600м)
 * - Полумарафон: длинные отрезки (400-1000м)
 * - Марафон: длинные устойчивые отрезки (600-1200м)
 */

require_once __DIR__ . '/WarmupCooldownHelper.php';

class FartlekBuilder
{
    /**
     * 5к: короткие быстрые — развитие скорости и нейромышечной координации.
     */
    private const FIVE_K_PROGRESSION = [
        1 => ['reps' => 8,  'distance_m' => 200, 'recovery_m' => 200],
        2 => ['reps' => 6,  'distance_m' => 300, 'recovery_m' => 200],
        3 => ['reps' => 8,  'distance_m' => 300, 'recovery_m' => 200],
        4 => ['reps' => 6,  'distance_m' => 400, 'recovery_m' => 200],
        5 => ['reps' => 8,  'distance_m' => 400, 'recovery_m' => 200],
        6 => ['reps' => 6,  'distance_m' => 400, 'recovery_m' => 300],
        7 => ['reps' => 8,  'distance_m' => 300, 'recovery_m' => 200],
        8 => ['reps' => 6,  'distance_m' => 400, 'recovery_m' => 200],
    ];

    /**
     * 10к: средние отрезки — развитие скоростной выносливости.
     */
    private const TEN_K_PROGRESSION = [
        1 => ['reps' => 6, 'distance_m' => 300, 'recovery_m' => 300],
        2 => ['reps' => 5, 'distance_m' => 400, 'recovery_m' => 300],
        3 => ['reps' => 6, 'distance_m' => 400, 'recovery_m' => 300],
        4 => ['reps' => 5, 'distance_m' => 500, 'recovery_m' => 300],
        5 => ['reps' => 6, 'distance_m' => 500, 'recovery_m' => 300],
        6 => ['reps' => 5, 'distance_m' => 600, 'recovery_m' => 400],
        7 => ['reps' => 6, 'distance_m' => 600, 'recovery_m' => 300],
        8 => ['reps' => 5, 'distance_m' => 500, 'recovery_m' => 300],
    ];

    /**
     * Полумарафон: длинные отрезки — аэробная мощность.
     */
    private const HALF_PROGRESSION = [
        1 => ['reps' => 5, 'distance_m' => 400, 'recovery_m' => 400],
        2 => ['reps' => 4, 'distance_m' => 600, 'recovery_m' => 400],
        3 => ['reps' => 5, 'distance_m' => 600, 'recovery_m' => 400],
        4 => ['reps' => 4, 'distance_m' => 800, 'recovery_m' => 400],
        5 => ['reps' => 5, 'distance_m' => 800, 'recovery_m' => 400],
        6 => ['reps' => 4, 'distance_m' => 1000, 'recovery_m' => 400],
        7 => ['reps' => 5, 'distance_m' => 800, 'recovery_m' => 400],
        8 => ['reps' => 4, 'distance_m' => 1000, 'recovery_m' => 400],
    ];

    /**
     * Марафон: длинные устойчивые отрезки — развитие выносливости.
     */
    private const MARATHON_PROGRESSION = [
        1 => ['reps' => 4, 'distance_m' => 600,  'recovery_m' => 400],
        2 => ['reps' => 4, 'distance_m' => 800,  'recovery_m' => 400],
        3 => ['reps' => 5, 'distance_m' => 800,  'recovery_m' => 400],
        4 => ['reps' => 4, 'distance_m' => 1000, 'recovery_m' => 400],
        5 => ['reps' => 5, 'distance_m' => 1000, 'recovery_m' => 400],
        6 => ['reps' => 4, 'distance_m' => 1200, 'recovery_m' => 600],
        7 => ['reps' => 5, 'distance_m' => 1000, 'recovery_m' => 400],
        8 => ['reps' => 4, 'distance_m' => 1200, 'recovery_m' => 600],
    ];

    /**
     * Построить фартлек-тренировку.
     *
     * @param int    $fartlekNumber Порядковый номер фартлека (1-based)
     * @param array  $paceRules     Темпы из TrainingStateBuilder
     * @param string $raceDistance  Дистанция забега ('5k','10k','half','marathon')
     * @return array
     */
    public static function build(int $fartlekNumber, array $paceRules, string $raceDistance = 'half'): array
    {
        $distKey = self::normalizeDistance($raceDistance);
        $progression = self::getProgression($distKey);

        // Overflow: циклируем по последним 4 записям (5-8)
        $count = count($progression);
        if ($fartlekNumber > $count) {
            $cycleBase = max(1, $count - 3);
            $idx = $cycleBase + (($fartlekNumber - $count - 1) % 4);
        } else {
            $idx = $fartlekNumber;
        }
        $template = $progression[$idx] ?? $progression[1];

        $reps = $template['reps'];
        $distM = $template['distance_m'];
        $recM = $template['recovery_m'];

        $warmup = WarmupCooldownHelper::warmup($raceDistance);
        $cooldown = WarmupCooldownHelper::cooldown($raceDistance);
        $segmentsKm = ($reps * ($distM + $recM)) / 1000.0;
        $totalKm = round($warmup + $segmentsKm + $cooldown, 1);

        return [
            'warmup_km' => $warmup,
            'cooldown_km' => $cooldown,
            'segments' => [
                [
                    'reps' => $reps,
                    'distance_m' => $distM,
                    'recovery_m' => $recM,
                    'pace' => 'fast',
                    'recovery_type' => 'jog',
                ],
            ],
            'total_km' => $totalKm,
            'fast_pace_sec' => $paceRules['interval_sec'] ?? 280,
            'recovery_pace_sec' => $paceRules['easy_max_sec'] ?? ($paceRules['easy_min_sec'] ?? 340) + 20,
        ];
    }

    private static function getProgression(string $distKey): array
    {
        return match ($distKey) {
            'marathon' => self::MARATHON_PROGRESSION,
            'half'     => self::HALF_PROGRESSION,
            '10k'      => self::TEN_K_PROGRESSION,
            '5k'       => self::FIVE_K_PROGRESSION,
            default    => self::TEN_K_PROGRESSION,
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
        return $map[strtolower($dist)] ?? '10k';
    }
}
