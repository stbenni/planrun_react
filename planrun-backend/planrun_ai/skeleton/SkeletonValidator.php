<?php
/**
 * SkeletonValidator — алгоритмическая валидация плана.
 *
 * Проверяет что LLM не сломал числовые поля скелета,
 * а также что план внутренне непротиворечив.
 */

class SkeletonValidator
{
    private const TOLERANCE = 0.05; // ±5%

    /**
     * Проверить что обогащённый план не отклоняется от оригинала.
     *
     * @param array $original  Исходный скелет
     * @param array $enriched  Обогащённый план
     * @return array           Массив ошибок (пустой = всё ок)
     */
    public static function validateAgainstOriginal(array $original, array $enriched): array
    {
        $errors = [];

        $origWeeks = $original['weeks'] ?? [];
        $enrWeeks = $enriched['weeks'] ?? [];

        if (count($enrWeeks) !== count($origWeeks)) {
            $errors[] = [
                'type' => 'week_count_mismatch',
                'description' => 'Количество недель изменилось: ' . count($origWeeks) . ' → ' . count($enrWeeks),
            ];
            return $errors;
        }

        foreach ($origWeeks as $wi => $origWeek) {
            $enrWeek = $enrWeeks[$wi] ?? null;
            if (!$enrWeek) {
                $errors[] = ['type' => 'week_missing', 'week' => $wi + 1];
                continue;
            }

            $origDays = $origWeek['days'] ?? [];
            $enrDays = $enrWeek['days'] ?? [];

            foreach ($origDays as $di => $origDay) {
                $enrDay = $enrDays[$di] ?? null;
                if (!$enrDay) {
                    continue;
                }

                // Проверка типа
                if (($origDay['type'] ?? '') !== ($enrDay['type'] ?? '')) {
                    $errors[] = [
                        'type' => 'type_changed',
                        'week' => $origWeek['week_number'],
                        'day' => $origDay['day_of_week'] ?? $di + 1,
                        'original' => $origDay['type'],
                        'enriched' => $enrDay['type'],
                    ];
                }

                // Проверка дистанции (±5%)
                $origDist = (float) ($origDay['distance_km'] ?? 0);
                $enrDist = (float) ($enrDay['distance_km'] ?? 0);
                if ($origDist > 0 && abs($enrDist - $origDist) / $origDist > self::TOLERANCE) {
                    $errors[] = [
                        'type' => 'distance_changed',
                        'week' => $origWeek['week_number'],
                        'day' => $origDay['day_of_week'] ?? $di + 1,
                        'original' => $origDist,
                        'enriched' => $enrDist,
                    ];
                }

                // Проверка pace не изменился
                if (($origDay['pace'] ?? '') !== '' && ($enrDay['pace'] ?? '') !== '' &&
                    ($origDay['pace'] ?? '') !== ($enrDay['pace'] ?? '')) {
                    $errors[] = [
                        'type' => 'pace_changed',
                        'week' => $origWeek['week_number'],
                        'day' => $origDay['day_of_week'] ?? $di + 1,
                        'original' => $origDay['pace'],
                        'enriched' => $enrDay['pace'],
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Валидация внутренней непротиворечивости плана.
     *
     * @param array $plan      План {weeks: [...]}
     * @param array $paceRules Темпы из TrainingStateBuilder
     * @return array           Массив ошибок
     */
    public static function validateConsistency(array $plan, array $paceRules): array
    {
        $errors = [];
        $weeks = $plan['weeks'] ?? [];
        $prevNonRecoveryVolume = null;

        foreach ($weeks as $wi => $week) {
            $weekNum = $week['week_number'] ?? $wi + 1;
            $isRecovery = !empty($week['is_recovery']);
            $phase = $week['phase'] ?? '';
            $volume = (float) ($week['actual_volume_km'] ?? $week['target_volume_km'] ?? 0);

            // 1. Прогрессия объёмов: не более +15% (кроме recovery/taper)
            if ($prevNonRecoveryVolume !== null && !$isRecovery && $phase !== 'taper' && $volume > 0) {
                $growth = ($volume - $prevNonRecoveryVolume) / $prevNonRecoveryVolume;
                if ($growth > 0.15) {
                    $errors[] = [
                        'type' => 'volume_jump',
                        'week' => $weekNum,
                        'description' => "Рост объёма " . round($growth * 100) . "% ({$prevNonRecoveryVolume} → {$volume})",
                    ];
                }
            }

            if (!$isRecovery && $phase !== 'taper') {
                $prevNonRecoveryVolume = $volume;
            }

            // 2. Проверка дней
            $days = $week['days'] ?? [];
            $prevKeyDay = null;

            foreach ($days as $di => $day) {
                $dayNum = $day['day_of_week'] ?? $di + 1;
                $isKey = !empty($day['is_key_workout']);

                // Две ключевые подряд
                if ($isKey && $prevKeyDay !== null && $dayNum - $prevKeyDay === 1) {
                    $errors[] = [
                        'type' => 'consecutive_key',
                        'week' => $weekNum,
                        'day_of_week' => $dayNum,
                        'description' => "Две ключевые тренировки подряд (дни {$prevKeyDay} и {$dayNum})",
                    ];
                }

                if ($isKey) {
                    $prevKeyDay = $dayNum;
                }

                // Проверки pace logic по типу дня
                $dayType = $day['type'] ?? '';
                $tempoPaceSec = $paceRules['tempo_sec'] ?? 300;
                $intervalPaceSec = $paceRules['interval_sec'] ?? 280;
                $easyMaxSec = $paceRules['easy_max_sec'] ?? 380;
                $longMaxSec = $paceRules['long_max_sec'] ?? 400;

                if ($dayType === 'easy' && !empty($day['pace'])) {
                    $paceSec = self::parsePace($day['pace']);
                    if ($paceSec !== null && $paceSec < $tempoPaceSec) {
                        $errors[] = [
                            'type' => 'pace_logic',
                            'week' => $weekNum,
                            'day_of_week' => $dayNum,
                            'description' => "Easy pace {$day['pace']} быстрее tempo",
                        ];
                    }
                }

                if ($dayType === 'long' && !empty($day['pace'])) {
                    $paceSec = self::parsePace($day['pace']);
                    if ($paceSec !== null && $paceSec < $tempoPaceSec) {
                        $errors[] = [
                            'type' => 'pace_logic',
                            'week' => $weekNum,
                            'day_of_week' => $dayNum,
                            'description' => "Long pace {$day['pace']} быстрее tempo",
                        ];
                    }
                }

                if ($dayType === 'tempo' && !empty($day['pace'])) {
                    // Race-pace tempo имеет свой целевой темп (MP, HMP, 10k-pace),
                    // не валидируем его как threshold tempo
                    $isRacePace = ($day['subtype'] ?? '') === 'race_pace';
                    if (!$isRacePace) {
                        $paceSec = self::parsePace($day['pace']);
                        if ($paceSec !== null) {
                            $tolerance = $paceRules['tempo_tolerance_sec'] ?? 8;
                            if ($paceSec < $intervalPaceSec) {
                                $errors[] = [
                                    'type' => 'pace_logic',
                                    'week' => $weekNum,
                                    'day_of_week' => $dayNum,
                                    'description' => "Tempo pace {$day['pace']} быстрее interval",
                                ];
                            }
                            if ($paceSec > $easyMaxSec) {
                                $errors[] = [
                                    'type' => 'pace_logic',
                                    'week' => $weekNum,
                                    'day_of_week' => $dayNum,
                                    'description' => "Tempo pace {$day['pace']} медленнее easy",
                                ];
                            }
                        }
                    }
                }

                if ($dayType === 'interval' && !empty($day['interval_pace'])) {
                    $paceSec = self::parsePace($day['interval_pace']);
                    if ($paceSec !== null && $paceSec > $tempoPaceSec) {
                        $errors[] = [
                            'type' => 'pace_logic',
                            'week' => $weekNum,
                            'day_of_week' => $dayNum,
                            'description' => "Interval pace {$day['interval_pace']} медленнее tempo",
                        ];
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Fallback: добавить алгоритмические описания к скелету (без LLM).
     */
    public static function addAlgorithmicNotes(array $skeleton): array
    {
        foreach ($skeleton['weeks'] as &$week) {
            foreach ($week['days'] as &$day) {
                if (!empty($day['notes'])) {
                    continue;
                }

                $type = $day['type'] ?? 'rest';
                $dist = $day['distance_km'] ?? 0;
                $pace = $day['pace'] ?? '';

                switch ($type) {
                    case 'easy':
                        if ($dist > 0) {
                            $day['notes'] = "Лёгкий бег {$dist} км в темпе {$pace}";
                        }
                        break;
                    case 'long':
                        $day['notes'] = "Длительный бег {$dist} км в темпе {$pace}";
                        break;
                    case 'tempo':
                        $tempoKm = $day['tempo_km'] ?? round($dist - 3.5, 1);
                        $day['notes'] = "Разминка " . ($day['warmup_km'] ?? 2) . " км. Темповый бег {$tempoKm} км в темпе {$pace}. Заминка " . ($day['cooldown_km'] ?? 1.5) . " км";
                        break;
                    case 'interval':
                        $reps = $day['reps'] ?? 0;
                        $intM = $day['interval_m'] ?? 0;
                        $restM = $day['rest_m'] ?? 0;
                        $intPace = $day['interval_pace'] ?? '';
                        $day['notes'] = "Разминка " . ($day['warmup_km'] ?? 2) . " км. {$reps}×{$intM}м в темпе {$intPace}, пауза {$restM}м трусцой. Заминка " . ($day['cooldown_km'] ?? 1.5) . " км";
                        break;
                    case 'fartlek':
                        $day['notes'] = "Разминка " . ($day['warmup_km'] ?? 2) . " км. Фартлек. Заминка " . ($day['cooldown_km'] ?? 1.5) . " км";
                        break;
                    case 'control':
                        $day['notes'] = "Разминка " . ($day['warmup_km'] ?? 2) . " км. Контрольный бег в темпе {$pace}. Заминка " . ($day['cooldown_km'] ?? 1.5) . " км";
                        break;
                }
            }
            unset($day);
        }
        unset($week);

        return $skeleton;
    }

    private static function parsePace(?string $pace): ?int
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
