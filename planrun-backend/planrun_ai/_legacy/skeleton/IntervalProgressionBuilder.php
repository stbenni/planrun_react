<?php
/**
 * IntervalProgressionBuilder — прогрессия интервальных тренировок по фазам.
 *
 * Возвращает конкретную структуру интервальной тренировки (reps, interval_m,
 * rest_m, warmup_km, cooldown_km, total_km) для данной недели/фазы/дистанции.
 *
 * Таблицы прогрессии основаны на принципах Jack Daniels Running Formula:
 * - Марафон: длинные отрезки 1000-2000м (развитие выносливости на VO2max)
 * - Полумарафон: средние отрезки 800-1600м
 * - 10к: средние/короткие 600-1000м
 * - 5к: короткие отрезки 400-800м (скоростная выносливость)
 */

require_once __DIR__ . '/WarmupCooldownHelper.php';

class IntervalProgressionBuilder
{
    /**
     * Прогрессия для марафона (длинные отрезки 1000-2000м).
     * Марафонцам нужны длинные интервалы для развития VO2max-выносливости.
     * Ключ = номер интервальной тренировки по порядку (1-based).
     */
    private const MARATHON_PROGRESSION = [
        // build early: 1000м отрезки
        1  => ['reps' => 4, 'interval_m' => 1000, 'rest_m' => 400],
        2  => ['reps' => 5, 'interval_m' => 1000, 'rest_m' => 400],
        // build mid: переход к 1200-1600м
        3  => ['reps' => 4, 'interval_m' => 1200, 'rest_m' => 400],
        4  => ['reps' => 3, 'interval_m' => 1600, 'rest_m' => 600],
        5  => ['reps' => 4, 'interval_m' => 1200, 'rest_m' => 400],
        // build late: 1600-2000м
        6  => ['reps' => 4, 'interval_m' => 1600, 'rest_m' => 600],
        7  => ['reps' => 3, 'interval_m' => 2000, 'rest_m' => 600],
        // peak: длинные отрезки
        8  => ['reps' => 4, 'interval_m' => 1600, 'rest_m' => 600],
        9  => ['reps' => 3, 'interval_m' => 2000, 'rest_m' => 600],
        10 => ['reps' => 5, 'interval_m' => 1200, 'rest_m' => 400],
    ];

    /**
     * Прогрессия для полумарафона (800-1600м).
     */
    private const HALF_PROGRESSION = [
        // build
        1  => ['reps' => 4, 'interval_m' => 800,  'rest_m' => 400],
        2  => ['reps' => 5, 'interval_m' => 800,  'rest_m' => 400],
        3  => ['reps' => 4, 'interval_m' => 1000, 'rest_m' => 400],
        4  => ['reps' => 5, 'interval_m' => 1000, 'rest_m' => 400],
        5  => ['reps' => 3, 'interval_m' => 1600, 'rest_m' => 600],
        6  => ['reps' => 4, 'interval_m' => 1200, 'rest_m' => 400],
        7  => ['reps' => 5, 'interval_m' => 1000, 'rest_m' => 400],
        // peak
        8  => ['reps' => 4, 'interval_m' => 1600, 'rest_m' => 600],
        9  => ['reps' => 3, 'interval_m' => 2000, 'rest_m' => 600],
        10 => ['reps' => 5, 'interval_m' => 1200, 'rest_m' => 400],
    ];

    /**
     * Прогрессия для 10к (600-1000м).
     */
    private const TEN_K_PROGRESSION = [
        // build
        1  => ['reps' => 6, 'interval_m' => 600,  'rest_m' => 400],
        2  => ['reps' => 5, 'interval_m' => 800,  'rest_m' => 400],
        3  => ['reps' => 6, 'interval_m' => 800,  'rest_m' => 400],
        4  => ['reps' => 4, 'interval_m' => 1000, 'rest_m' => 400],
        5  => ['reps' => 5, 'interval_m' => 1000, 'rest_m' => 400],
        6  => ['reps' => 3, 'interval_m' => 1200, 'rest_m' => 400],
        7  => ['reps' => 6, 'interval_m' => 800,  'rest_m' => 400],
        // peak
        8  => ['reps' => 5, 'interval_m' => 1000, 'rest_m' => 400],
        9  => ['reps' => 4, 'interval_m' => 1200, 'rest_m' => 400],
        10 => ['reps' => 6, 'interval_m' => 800,  'rest_m' => 400],
    ];

    /**
     * Прогрессия для 5к (400-800м).
     */
    private const FIVE_K_PROGRESSION = [
        // build
        1  => ['reps' => 6,  'interval_m' => 400, 'rest_m' => 400],
        2  => ['reps' => 8,  'interval_m' => 400, 'rest_m' => 400],
        3  => ['reps' => 5,  'interval_m' => 600, 'rest_m' => 400],
        4  => ['reps' => 6,  'interval_m' => 600, 'rest_m' => 400],
        5  => ['reps' => 5,  'interval_m' => 800, 'rest_m' => 400],
        6  => ['reps' => 4,  'interval_m' => 1000, 'rest_m' => 400],
        7  => ['reps' => 6,  'interval_m' => 800, 'rest_m' => 400],
        // peak
        8  => ['reps' => 6,  'interval_m' => 800,  'rest_m' => 400],
        9  => ['reps' => 5,  'interval_m' => 1000, 'rest_m' => 400],
        10 => ['reps' => 8,  'interval_m' => 600,  'rest_m' => 400],
    ];

    /**
     * Тапер (укороченные интервалы) по дистанции.
     */
    private const TAPER = [
        'marathon' => ['reps' => 3, 'interval_m' => 1000, 'rest_m' => 400],
        'half'     => ['reps' => 3, 'interval_m' => 800,  'rest_m' => 400],
        '10k'      => ['reps' => 4, 'interval_m' => 600,  'rest_m' => 400],
        '5k'       => ['reps' => 4, 'interval_m' => 400,  'rest_m' => 400],
    ];

    /**
     * Построить интервальную тренировку для данной недели.
     *
     * @param string $phase          Фаза ('base','build','peak','taper')
     * @param int    $intervalNumber Порядковый номер интервальной тренировки в плане (1-based)
     * @param string $raceDistance   Дистанция забега ('5k','10k','half','marathon')
     * @param array  $paceRules      Темпы из TrainingStateBuilder
     * @return array|null            null если в данной фазе нет интервалов
     */
    public static function build(
        string $phase,
        int    $intervalNumber,
        string $raceDistance,
        array  $paceRules
    ): ?array {

        if ($phase === 'base' || $phase === 'pre_base' || $phase === 'adaptation') {
            return null;
        }

        $distKey = self::normalizeDistance($raceDistance);

        if ($phase === 'taper') {
            $template = self::TAPER[$distKey] ?? self::TAPER['half'];
        } else {
            $progression = self::getProgression($distKey);
            $count = count($progression);
            if ($intervalNumber > $count) {
                // Overflow: циклируем по последним 4 записям (пиковые)
                $cycleBase = max(1, $count - 3);
                $idx = $cycleBase + (($intervalNumber - $count - 1) % 4);
            } else {
                $idx = $intervalNumber;
            }
            $template = $progression[$idx] ?? end($progression);
        }

        $reps = $template['reps'];
        $intervalM = $template['interval_m'];
        $restM = $template['rest_m'];
        $restType = $intervalM <= 600 ? 'walk' : 'jog';

        $warmup = WarmupCooldownHelper::warmup($raceDistance);
        $cooldown = WarmupCooldownHelper::cooldown($raceDistance);
        $workKm = ($reps * $intervalM) / 1000.0;
        $restKm = ($reps * $restM) / 1000.0;
        $totalKm = round($warmup + $workKm + $restKm + $cooldown, 1);

        return [
            'reps' => $reps,
            'interval_m' => $intervalM,
            'rest_m' => $restM,
            'rest_type' => $restType,
            'warmup_km' => $warmup,
            'cooldown_km' => $cooldown,
            'work_km' => round($workKm, 1),
            'rest_km' => round($restKm, 1),
            'total_km' => $totalKm,
            'interval_pace_sec' => $paceRules['interval_sec'] ?? 280,
        ];
    }

    private static function getProgression(string $distKey): array
    {
        return match ($distKey) {
            'marathon' => self::MARATHON_PROGRESSION,
            'half'     => self::HALF_PROGRESSION,
            '10k'      => self::TEN_K_PROGRESSION,
            '5k'       => self::FIVE_K_PROGRESSION,
            default    => self::HALF_PROGRESSION,
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
