<?php
/**
 * ControlWorkoutBuilder — контрольные тренировки.
 *
 * Контрольная тренировка проводится перед разгрузочной неделей.
 * Цель — оценить текущий уровень формы.
 * Дистанция зависит от целевой дистанции забега.
 */

require_once __DIR__ . '/WarmupCooldownHelper.php';

class ControlWorkoutBuilder
{
    /**
     * Контрольные дистанции по целевому забегу.
     */
    private const CONTROL_DISTANCES = [
        '5k'       => 3.0,    // 3 км контрольный
        '10k'      => 5.0,    // 5 км контрольный
        'half'     => 8.0,    // 8 км контрольный
        '21.1k'    => 8.0,
        'marathon'  => 10.0,   // 10 км контрольный
        '42.2k'    => 10.0,
    ];

    /**
     * Построить контрольную тренировку.
     *
     * @param string $raceDistance Целевая дистанция забега
     * @param array  $paceRules   Темпы из TrainingStateBuilder
     * @return array
     */
    public static function build(string $raceDistance, array $paceRules): array
    {
        $controlKm = self::CONTROL_DISTANCES[$raceDistance] ?? 5.0;

        $warmup = WarmupCooldownHelper::warmup($raceDistance);
        $cooldown = WarmupCooldownHelper::cooldown($raceDistance);
        $totalKm = round($warmup + $controlKm + $cooldown, 1);

        // Контрольный темп — между threshold и interval (~90% VO2max)
        $tempoPace = $paceRules['tempo_sec'] ?? 300;
        $intervalPace = $paceRules['interval_sec'] ?? 280;
        $controlPaceSec = (int) round(($tempoPace + $intervalPace) / 2);

        return [
            'warmup_km' => $warmup,
            'cooldown_km' => $cooldown,
            'control_km' => $controlKm,
            'total_km' => $totalKm,
            'pace_sec' => $controlPaceSec,
        ];
    }
}
