<?php
/**
 * OfpProgressionBuilder — ОФП-упражнения с прогрессией.
 *
 * Генерирует набор упражнений общей физической подготовки
 * с прогрессией подходов/повторов по неделям.
 */

class OfpProgressionBuilder
{
    /**
     * Базовые упражнения для бегунов.
     */
    private const EXERCISES = [
        ['name' => 'Приседания',       'category' => 'legs',  'base_sets' => 3, 'base_reps' => 12],
        ['name' => 'Выпады',           'category' => 'legs',  'base_sets' => 3, 'base_reps' => 10],
        ['name' => 'Ягодичный мост',   'category' => 'glutes','base_sets' => 3, 'base_reps' => 15],
        ['name' => 'Планка',           'category' => 'core',  'base_sets' => 3, 'base_reps' => null, 'duration_sec' => 30],
        ['name' => 'Боковая планка',   'category' => 'core',  'base_sets' => 2, 'base_reps' => null, 'duration_sec' => 20],
        ['name' => 'Отжимания',        'category' => 'upper', 'base_sets' => 2, 'base_reps' => 10],
        ['name' => 'Подъём на носки',  'category' => 'calves','base_sets' => 3, 'base_reps' => 15],
        ['name' => 'Скручивания',      'category' => 'core',  'base_sets' => 3, 'base_reps' => 15],
    ];

    /**
     * Построить ОФП-тренировку для данной недели.
     *
     * @param int    $weekNumber    Номер недели (1-based)
     * @param string $preference    Предпочтение: 'light', 'moderate', 'full'
     * @param bool   $isRecovery    Разгрузочная неделя
     * @return array Массив упражнений
     */
    public static function build(int $weekNumber, string $preference = 'moderate', bool $isRecovery = false): array
    {
        $exerciseCount = match ($preference) {
            'light' => 4,
            'full' => 8,
            default => 6, // moderate
        };

        // Прогрессия: +1 подход каждые 4 недели, +2 повтора каждые 3 недели
        $setsBonus = (int) floor(($weekNumber - 1) / 4);
        $repsBonus = (int) floor(($weekNumber - 1) / 3) * 2;

        if ($isRecovery) {
            $setsBonus = max(0, $setsBonus - 1);
            $repsBonus = max(0, $repsBonus - 2);
        }

        $exercises = [];
        $selected = array_slice(self::EXERCISES, 0, $exerciseCount);

        foreach ($selected as $idx => $ex) {
            $sets = min($ex['base_sets'] + $setsBonus, 5);
            $reps = $ex['base_reps'] !== null ? min($ex['base_reps'] + $repsBonus, 25) : null;
            $durSec = isset($ex['duration_sec'])
                ? min($ex['duration_sec'] + $weekNumber * 5, 90)
                : null;

            $exercises[] = [
                'name' => $ex['name'],
                'category' => 'ofp',
                'sets' => $sets,
                'reps' => $reps,
                'duration_sec' => $durSec,
                'weight_kg' => null,
                'order_index' => $idx,
            ];
        }

        return $exercises;
    }
}
