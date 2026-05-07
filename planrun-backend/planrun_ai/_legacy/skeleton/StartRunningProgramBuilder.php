<?php
/**
 * StartRunningProgramBuilder — фиксированные программы для начинающих.
 *
 * Генерирует полностью детерминированные планы (без LLM):
 * - start_running: 8 недель, от бег/ходьба до 20 мин непрерывного бега
 * - couch_to_5k: 10 недель, от бег/ходьба до 5 км непрерывного бега
 * - regular_running: простой план easy + long для поддержания формы
 */

class StartRunningProgramBuilder
{
    /**
     * Программа "Начни бегать" — 8 недель, 3 тренировки.
     * Каждый элемент: [duration_minutes, description]
     */
    private const START_RUNNING = [
        1 => ['dur' => 24, 'desc' => 'Чередование: бег 1 мин / ходьба 2 мин × 8 повторов'],
        2 => ['dur' => 25, 'desc' => 'Чередование: бег 2 мин / ходьба 2 мин × 6 повторов'],
        3 => ['dur' => 24, 'desc' => 'Чередование: бег 3 мин / ходьба 1.5 мин × 5 повторов'],
        4 => ['dur' => 24, 'desc' => 'Чередование: бег 4 мин / ходьба 1 мин × 5 повторов'],
        5 => ['dur' => 25, 'desc' => 'Чередование: бег 5 мин / ходьба 1 мин × 4 повтора'],
        6 => ['dur' => 27, 'desc' => 'Чередование: бег 8 мин / ходьба 1 мин × 3 повтора'],
        7 => ['dur' => 25, 'desc' => 'Бег 12 мин, ходьба 1 мин, бег 12 мин'],
        8 => ['dur' => 20, 'desc' => 'Непрерывный бег 20 минут'],
    ];

    /**
     * Программа "С дивана до 5 км" — 10 недель, 3 тренировки.
     */
    private const COUCH_TO_5K = [
        1  => ['dur' => 24, 'desc' => 'Чередование: бег 1 мин / ходьба 2 мин × 8 повторов'],
        2  => ['dur' => 25, 'desc' => 'Чередование: бег 2 мин / ходьба 2 мин × 6 повторов'],
        3  => ['dur' => 24, 'desc' => 'Чередование: бег 3 мин / ходьба 1.5 мин × 5 повторов'],
        4  => ['dur' => 24, 'desc' => 'Чередование: бег 4 мин / ходьба 1 мин × 5 повторов'],
        5  => ['dur' => 25, 'desc' => 'Чередование: бег 5 мин / ходьба 1 мин × 4 повтора'],
        6  => ['dur' => 27, 'desc' => 'Чередование: бег 8 мин / ходьба 1 мин × 3 повтора'],
        7  => ['dur' => 25, 'desc' => 'Бег 12 мин, ходьба 1 мин, бег 12 мин'],
        8  => ['dur' => 20, 'desc' => 'Непрерывный бег 20 минут'],
        9  => ['dur' => 25, 'desc' => 'Непрерывный бег 25 минут'],
        10 => ['dur' => 30, 'desc' => 'Непрерывный бег 30 минут (~5 км)'],
    ];

    /**
     * Дни тренировок по умолчанию для 3 тренировок.
     */
    private const DEFAULT_DAYS = [1, 3, 5]; // Пн, Ср, Пт (индексы 0-6)

    /**
     * Построить план для начинающих.
     *
     * @param array $user  Данные пользователя
     * @param array $state TrainingState
     * @return array План в формате {weeks: [...], _skip_llm: false, _metadata: {...}}
     */
    public static function build(array $user, array $state): array
    {
        $program = ($user['health_program'] ?? 'start_running');

        $table = match ($program) {
            'couch_to_5k' => self::COUCH_TO_5K,
            default => self::START_RUNNING,
        };

        $preferredDays = self::resolveTrainingDays($user);
        $easyPaceSec = $state['pace_rules']['easy_min_sec']
            ?? ($user['easy_pace_sec'] ?? 420); // fallback 7:00/км

        $weeks = [];
        foreach ($table as $weekNum => $weekData) {
            $days = [];
            for ($d = 0; $d < 7; $d++) {
                if (in_array($d, $preferredDays, true)) {
                    // Оценка дистанции: duration * (pace в км/мин)
                    // Для бег/ходьба средний темп ~8:00/км
                    $avgPaceSec = $weekNum <= 6 ? ($easyPaceSec + 60) : $easyPaceSec;
                    $estimatedKm = round($weekData['dur'] / ($avgPaceSec / 60), 1);

                    $days[] = [
                        'day_of_week' => $d + 1,
                        'type' => 'easy',
                        'distance_km' => $estimatedKm,
                        'pace' => self::formatPace($avgPaceSec),
                        'duration_minutes' => $weekData['dur'],
                        'is_key_workout' => false,
                        'notes' => $weekData['desc'],
                    ];
                } else {
                    $days[] = [
                        'day_of_week' => $d + 1,
                        'type' => 'rest',
                        'distance_km' => 0,
                        'pace' => null,
                        'duration_minutes' => 0,
                        'is_key_workout' => false,
                        'notes' => null,
                    ];
                }
            }

            $totalKm = array_sum(array_column($days, 'distance_km'));
            $weeks[] = [
                'week_number' => $weekNum,
                'phase' => $weekNum <= 3 ? 'adaptation' : 'development',
                'phase_label' => $weekNum <= 3 ? 'Адаптация' : 'Развитие',
                'is_recovery' => false,
                'target_volume_km' => round($totalKm, 1),
                'actual_volume_km' => round($totalKm, 1),
                'days' => $days,
            ];
        }

        return [
            'weeks' => $weeks,
            '_skip_llm' => false, // LLM добавит мотивационные notes
            '_metadata' => [
                'program' => $program,
                'goal_type' => 'health',
                'total_weeks' => count($weeks),
                'generated_at' => date('Y-m-d H:i:s'),
                'generator' => 'StartRunningProgramBuilder',
            ],
        ];
    }

    /**
     * Проверить, является ли это фиксированной программой.
     */
    public static function isFixedProgram(array $user): bool
    {
        $goalType = $user['goal_type'] ?? '';
        $program = $user['health_program'] ?? '';

        return $goalType === 'health' && in_array($program, ['start_running', 'couch_to_5k'], true);
    }

    private static function resolveTrainingDays(array $user): array
    {
        $preferred = $user['preferred_days'] ?? [];
        if (!empty($preferred) && is_array($preferred)) {
            $dayMap = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];
            $result = [];
            foreach ($preferred as $day) {
                if (isset($dayMap[$day])) {
                    $result[] = $dayMap[$day];
                }
            }
            if (count($result) >= 3) {
                return array_slice($result, 0, 3);
            }
        }
        return self::DEFAULT_DAYS;
    }

    private static function formatPace(int $seconds): string
    {
        $min = (int) floor($seconds / 60);
        $sec = $seconds % 60;
        return $min . ':' . str_pad((string) $sec, 2, '0', STR_PAD_LEFT);
    }
}
