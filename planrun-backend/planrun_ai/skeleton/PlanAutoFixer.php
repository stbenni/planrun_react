<?php
/**
 * PlanAutoFixer — автоматическое исправление ошибок найденных LLM-ревью.
 *
 * Обрабатывает каждую issue по типу и пересчитывает проблемные элементы.
 */

class PlanAutoFixer
{
    /**
     * Исправить ошибки в плане.
     *
     * @param array $plan      План {weeks: [...]}
     * @param array $issues    Массив ошибок из LLMReviewer
     * @param array $paceRules Темпы из TrainingStateBuilder
     * @param array $loadPolicy Load policy
     * @return array {plan: {...}, fixes_applied: int}
     */
    public static function fix(array $plan, array $issues, array $paceRules, array $loadPolicy): array
    {
        $fixesApplied = 0;

        foreach ($issues as $issue) {
            $type = $issue['type'] ?? '';
            $weekNum = $issue['week'] ?? null;
            $dayNum = $issue['day_of_week'] ?? null;

            switch ($type) {
                case 'pace_logic':
                    if ($weekNum !== null && $dayNum !== null) {
                        $plan = self::fixPaceLogic($plan, $weekNum, $dayNum, $paceRules);
                        $fixesApplied++;
                    }
                    break;

                case 'volume_jump':
                    if ($weekNum !== null) {
                        $plan = self::fixVolumeJump($plan, $weekNum, $loadPolicy);
                        $fixesApplied++;
                    }
                    break;

                case 'consecutive_key':
                    if ($weekNum !== null && $dayNum !== null) {
                        $plan = self::fixConsecutiveKey($plan, $weekNum, $dayNum, $paceRules);
                        $fixesApplied++;
                    }
                    break;

                case 'missing_recovery':
                    if ($weekNum !== null) {
                        $plan = self::fixMissingRecovery($plan, $weekNum, $loadPolicy);
                        $fixesApplied++;
                    }
                    break;

                case 'health_concern':
                    if ($weekNum !== null && $dayNum !== null) {
                        $plan = self::fixHealthConcern($plan, $weekNum, $dayNum, $paceRules);
                        $fixesApplied++;
                    }
                    break;

                case 'too_aggressive':
                    if ($weekNum !== null) {
                        $plan = self::fixTooAggressive($plan, $weekNum, $loadPolicy);
                        $fixesApplied++;
                    }
                    break;

                case 'interval_pace_logic':
                case 'tempo_pace_logic':
                case 'easy_pace_logic':
                case 'long_pace_logic':
                    // Алиасы от LLM — обрабатываем как pace_logic
                    if ($weekNum !== null && $dayNum !== null) {
                        $plan = self::fixPaceLogic($plan, $weekNum, $dayNum, $paceRules);
                        $fixesApplied++;
                    }
                    break;

                case 'type_mismatch':
                    // LLM считает что тип дня не соответствует содержимому — пересчитываем pace
                    if ($weekNum !== null && $dayNum !== null) {
                        $plan = self::fixPaceLogic($plan, $weekNum, $dayNum, $paceRules);
                        $fixesApplied++;
                    }
                    break;

                case 'taper_violation':
                    // Слишком интенсивная тренировка в taper — обрабатываем как too_aggressive
                    if ($weekNum !== null) {
                        $plan = self::fixTooAggressive($plan, $weekNum, $loadPolicy);
                        $fixesApplied++;
                    }
                    break;

                case 'long_run_decrease':
                    // LLM заметила уменьшение длительной — допустимо для recovery/taper, игнорируем
                    break;

                default:
                    error_log("PlanAutoFixer: unknown issue type '{$type}'");
                    break;
            }
        }

        return ['plan' => $plan, 'fixes_applied' => $fixesApplied];
    }

    /**
     * Исправить логику темпов: пересчитать pace из VDOT.
     */
    private static function fixPaceLogic(array $plan, int $weekNum, int $dayNum, array $paceRules): array
    {
        $weekIdx = self::findWeekIndex($plan, $weekNum);
        if ($weekIdx === null) return $plan;

        foreach ($plan['weeks'][$weekIdx]['days'] as &$day) {
            if (($day['day_of_week'] ?? 0) !== $dayNum) continue;

            $type = $day['type'] ?? 'easy';
            switch ($type) {
                case 'easy':
                    $day['pace'] = self::formatPace($paceRules['easy_min_sec'] ?? 340);
                    break;
                case 'long':
                    $day['pace'] = self::formatPace($paceRules['long_min_sec'] ?? 360);
                    break;
                case 'tempo':
                    // Race-pace tempo — не трогаем, у него свой целевой темп
                    if (($day['subtype'] ?? '') === 'race_pace') {
                        break;
                    }
                    $day['pace'] = self::formatPace($paceRules['tempo_sec'] ?? 300);
                    break;
                case 'interval':
                    $day['interval_pace'] = self::formatPace($paceRules['interval_sec'] ?? 280);
                    break;
            }
            break;
        }
        unset($day);

        return $plan;
    }

    /**
     * Исправить скачок объёма: ограничить рост до allowed_growth_ratio.
     * Уменьшает все типы дней пропорционально (не только easy).
     */
    private static function fixVolumeJump(array $plan, int $weekNum, array $loadPolicy): array
    {
        $weekIdx = self::findWeekIndex($plan, $weekNum);
        if ($weekIdx === null || $weekIdx === 0) return $plan;

        $prevVolume = (float) ($plan['weeks'][$weekIdx - 1]['target_volume_km'] ?? 0);
        if ($prevVolume <= 0) return $plan;

        $maxAllowed = round($prevVolume * ($loadPolicy['allowed_growth_ratio'] ?? 1.10), 1);

        $currentVolume = (float) ($plan['weeks'][$weekIdx]['target_volume_km'] ?? 0);
        if ($currentVolume <= $maxAllowed) return $plan;

        $ratio = $maxAllowed / $currentVolume;

        foreach ($plan['weeks'][$weekIdx]['days'] as &$day) {
            $type = $day['type'] ?? 'rest';
            if ($type === 'rest') continue;

            $day['distance_km'] = round(($day['distance_km'] ?? 0) * $ratio, 1);
        }
        unset($day);

        $plan['weeks'][$weekIdx]['target_volume_km'] = $maxAllowed;
        $plan['weeks'][$weekIdx]['actual_volume_km'] = $maxAllowed;

        return $plan;
    }

    /**
     * Исправить две ключевые подряд: заменить вторую на easy.
     */
    private static function fixConsecutiveKey(array $plan, int $weekNum, int $dayNum, array $paceRules = []): array
    {
        $weekIdx = self::findWeekIndex($plan, $weekNum);
        if ($weekIdx === null) return $plan;

        foreach ($plan['weeks'][$weekIdx]['days'] as &$day) {
            if (($day['day_of_week'] ?? 0) !== $dayNum) continue;

            $day['type'] = 'easy';
            $day['is_key_workout'] = false;
            $day['pace'] = self::formatPace($paceRules['easy_min_sec'] ?? 340);
            $day['reps'] = null;
            $day['interval_m'] = null;
            $day['rest_m'] = null;
            $day['interval_pace'] = null;
            $day['tempo_km'] = null;
            $day['warmup_km'] = null;
            $day['cooldown_km'] = null;
            $day['notes'] = null;
            break;
        }
        unset($day);

        return $plan;
    }

    /**
     * Исправить отсутствие снижения в recovery week.
     */
    private static function fixMissingRecovery(array $plan, int $weekNum, array $loadPolicy): array
    {
        $weekIdx = self::findWeekIndex($plan, $weekNum);
        if ($weekIdx === null) return $plan;

        $ratio = $loadPolicy['recovery_cutback_ratio'] ?? 0.88;

        foreach ($plan['weeks'][$weekIdx]['days'] as &$day) {
            if (($day['type'] ?? 'rest') !== 'rest') {
                $day['distance_km'] = round(($day['distance_km'] ?? 0) * $ratio, 1);
            }
        }
        unset($day);

        $plan['weeks'][$weekIdx]['is_recovery'] = true;

        return $plan;
    }

    /**
     * Исправить health concern: заменить interval на tempo.
     */
    private static function fixHealthConcern(array $plan, int $weekNum, int $dayNum, array $paceRules): array
    {
        $weekIdx = self::findWeekIndex($plan, $weekNum);
        if ($weekIdx === null) return $plan;

        foreach ($plan['weeks'][$weekIdx]['days'] as &$day) {
            if (($day['day_of_week'] ?? 0) !== $dayNum) continue;

            if (($day['type'] ?? '') === 'interval') {
                $day['type'] = 'tempo';
                $day['pace'] = self::formatPace($paceRules['tempo_sec'] ?? 300);
                $day['reps'] = null;
                $day['interval_m'] = null;
                $day['rest_m'] = null;
                $day['interval_pace'] = null;
                $warmup = $day['warmup_km'] ?? 2.0;
                $cooldown = $day['cooldown_km'] ?? 1.5;
                $day['tempo_km'] = round(($day['distance_km'] ?? 0) - $warmup - $cooldown, 1);
            }
            break;
        }
        unset($day);

        return $plan;
    }

    /**
     * Исправить слишком агрессивную прогрессию.
     */
    private static function fixTooAggressive(array $plan, int $weekNum, array $loadPolicy): array
    {
        // Снижаем growth_ratio для проблемной недели
        return self::fixVolumeJump($plan, $weekNum, array_merge($loadPolicy, [
            'allowed_growth_ratio' => 1.08, // более мягкий рост
        ]));
    }

    private static function findWeekIndex(array $plan, int $weekNum): ?int
    {
        foreach ($plan['weeks'] as $idx => $week) {
            if (($week['week_number'] ?? 0) === $weekNum) {
                return $idx;
            }
        }
        return null;
    }

    private static function formatPace(int $seconds): string
    {
        $min = (int) floor($seconds / 60);
        $sec = $seconds % 60;
        return $min . ':' . str_pad((string) $sec, 2, '0', STR_PAD_LEFT);
    }
}
