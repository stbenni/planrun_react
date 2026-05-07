<?php

require_once dirname(__DIR__) . '/plan_normalizer.php';

/**
 * Pace validator — проверяет, что заданный темп соответствует VDOT-derived
 * paceRules из training_state.
 *
 * Покрытие (P0.4):
 *  - easy / long — диапазоны easy_min_sec..easy_max_sec, long_min_sec..long_max_sec.
 *  - tempo (без subtype) — около tempo_sec.
 *  - tempo subtype=race_pace — около race_pace_sec / goal pace (через
 *    resolveGoalSpecificTempoPaceTargetSec для длинных гонок, fallback —
 *    paceRules['race_pace_sec']).
 *  - interval — interval_pace около interval_sec.
 *  - fartlek — pace каждого segment с типом, требующим скорости (fast / tempo /
 *    interval / race_pace) около соответствующего target. Recovery / easy
 *    сегменты пропускаются.
 */
function collectPaceValidationIssues(array $normalizedPlan, array $trainingState = [], array $context = []): array {
    $issues = [];
    $paceRules = $trainingState['pace_rules'] ?? null;
    if (!$paceRules) {
        return $issues;
    }

    foreach (($normalizedPlan['weeks'] ?? []) as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        foreach (($week['days'] ?? []) as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $date = $day['date'] ?? 'unknown-date';
            $paceSec = parsePaceToSeconds($day['pace'] ?? null);

            if ($type === 'easy' && $paceSec !== null) {
                $issues = array_merge($issues, _paceCheckEasy($paceSec, $paceRules, $weekNumber, $date, $day));
                continue;
            }

            if ($type === 'long' && $paceSec !== null) {
                $issues = array_merge($issues, _paceCheckLong($paceSec, $paceRules, $weekNumber, $date, $day));
                continue;
            }

            if ($type === 'tempo' && $paceSec !== null) {
                $issues = array_merge($issues, _paceCheckTempo($paceSec, $paceRules, $weekNumber, $date, $day, $trainingState));
                // не continue — tempo может также иметь segments в редких случаях, но обычно нет
            }

            if ($type === 'interval') {
                $issues = array_merge($issues, _paceCheckInterval($day, $paceRules, $weekNumber, $date));
                continue;
            }

            if ($type === 'fartlek') {
                $issues = array_merge($issues, _paceCheckFartlek($day, $paceRules, $weekNumber, $date));
                continue;
            }
        }
    }

    return $issues;
}

function _paceCheckEasy(int $paceSec, array $paceRules, int $weekNumber, string $date, array $day): array {
    $min = max(150, (int) ($paceRules['easy_min_sec'] ?? 0) - 15);
    $max = min(600, (int) ($paceRules['easy_max_sec'] ?? 600) + 20);
    if ($min === 0 || $max === 0 || $paceSec >= $min && $paceSec <= $max) {
        return [];
    }

    $delta = $paceSec < $min ? ($min - $paceSec) : ($paceSec - $max);
    return [[
        'severity' => $delta > 25 ? 'error' : 'warning',
        'code' => 'easy_pace_out_of_range',
        'week_number' => $weekNumber,
        'date' => $date,
        'message' => "Неделя {$weekNumber}, {$date}: easy pace {$day['pace']} вне рекомендованного коридора " . validatorFormatPaceSec($min) . "–" . validatorFormatPaceSec($max) . ".",
    ]];
}

function _paceCheckLong(int $paceSec, array $paceRules, int $weekNumber, string $date, array $day): array {
    $min = max(150, (int) ($paceRules['long_min_sec'] ?? 0) - 15);
    $max = min(600, (int) ($paceRules['long_max_sec'] ?? 600) + 20);
    if ($min === 0 || $max === 0 || ($paceSec >= $min && $paceSec <= $max)) {
        return [];
    }

    $delta = $paceSec < $min ? ($min - $paceSec) : ($paceSec - $max);
    return [[
        'severity' => $delta > 25 ? 'error' : 'warning',
        'code' => 'long_pace_out_of_range',
        'week_number' => $weekNumber,
        'date' => $date,
        'message' => "Неделя {$weekNumber}, {$date}: long pace {$day['pace']} вне рекомендованного коридора " . validatorFormatPaceSec($min) . "–" . validatorFormatPaceSec($max) . ".",
    ]];
}

function _paceCheckTempo(int $paceSec, array $paceRules, int $weekNumber, string $date, array $day, array $trainingState): array {
    $isRacePaceSubtype = (string) ($day['subtype'] ?? '') === 'race_pace';
    $goalSpecificTarget = resolveGoalSpecificTempoPaceTargetSec($day, $trainingState, $weekNumber);

    // Если это race_pace — целевой темп равен race_pace_sec, fallback на goal_pace.
    if ($isRacePaceSubtype) {
        $racePaceTarget = $goalSpecificTarget
            ?: (isset($paceRules['race_pace_sec']) ? (int) $paceRules['race_pace_sec'] : 0);
        if ($racePaceTarget <= 0) {
            return [];
        }

        $tolerance = 20;
        if (abs($paceSec - $racePaceTarget) <= $tolerance) {
            return [];
        }

        $delta = abs($paceSec - $racePaceTarget);
        return [[
            'severity' => $delta > ($tolerance + 15) ? 'error' : 'warning',
            'code' => 'race_pace_tempo_out_of_range',
            'week_number' => $weekNumber,
            'date' => $date,
            'message' => "Неделя {$weekNumber}, {$date}: tempo с subtype=race_pace, темп {$day['pace']} слишком далеко от целевого " . validatorFormatPaceSec($racePaceTarget) . ".",
        ]];
    }

    // Обычный tempo.
    $target = $goalSpecificTarget ?: (int) ($paceRules['tempo_sec'] ?? 0);
    $tolerance = (int) ($paceRules['tempo_tolerance_sec'] ?? 10) + 5;
    if ($goalSpecificTarget) {
        $tolerance = max($tolerance, 20);
    }
    if ($target <= 0 || abs($paceSec - $target) <= $tolerance) {
        return [];
    }

    $delta = abs($paceSec - $target);
    return [[
        'severity' => $delta > ($tolerance + 15) ? 'error' : 'warning',
        'code' => 'tempo_pace_out_of_range',
        'week_number' => $weekNumber,
        'date' => $date,
        'message' => "Неделя {$weekNumber}, {$date}: tempo pace {$day['pace']} слишком далеко от целевого " . validatorFormatPaceSec($target) . ".",
    ]];
}

function _paceCheckInterval(array $day, array $paceRules, int $weekNumber, string $date): array {
    $intervalPace = parsePaceToSeconds($day['interval_pace'] ?? null);
    if ($intervalPace === null) {
        return [];
    }

    $target = (int) ($paceRules['interval_sec'] ?? 0);
    if ($target <= 0) {
        return [];
    }

    $tolerance = (int) ($paceRules['interval_tolerance_sec'] ?? 8) + 7; // запас 15s
    if (abs($intervalPace - $target) <= $tolerance) {
        return [];
    }

    $delta = abs($intervalPace - $target);
    return [[
        'severity' => $delta > ($tolerance + 15) ? 'error' : 'warning',
        'code' => 'interval_pace_out_of_range',
        'week_number' => $weekNumber,
        'date' => $date,
        'message' => "Неделя {$weekNumber}, {$date}: interval pace {$day['interval_pace']} слишком далеко от целевого " . validatorFormatPaceSec($target) . " (VDOT-derived).",
    ]];
}

function _paceCheckFartlek(array $day, array $paceRules, int $weekNumber, string $date): array {
    $segments = (array) ($day['segments'] ?? []);
    if ($segments === []) {
        return [];
    }

    $issues = [];
    foreach ($segments as $idx => $segment) {
        if (!is_array($segment)) {
            continue;
        }

        $pace = parsePaceToSeconds($segment['pace'] ?? null);
        if ($pace === null) {
            continue;
        }

        $segmentType = strtolower((string) ($segment['type'] ?? $segment['effort'] ?? 'fast'));
        // Recovery и easy сегменты не трогаем — у них свой режим.
        if (in_array($segmentType, ['recovery', 'rest', 'easy', 'jog', 'walk'], true)) {
            continue;
        }

        $segmentSubtype = strtolower((string) ($segment['subtype'] ?? ''));
        $isRacePace = $segmentSubtype === 'race_pace';

        $target = 0;
        $tolerance = 12;
        $code = 'fartlek_segment_pace_out_of_range';

        if ($isRacePace) {
            $target = (int) ($paceRules['race_pace_sec'] ?? 0);
            $tolerance = 18;
        } elseif (in_array($segmentType, ['interval', 'vo2', 'repetition'], true)) {
            $target = (int) ($paceRules['interval_sec'] ?? 0);
            $tolerance = (int) ($paceRules['interval_tolerance_sec'] ?? 8) + 7;
        } elseif (in_array($segmentType, ['tempo', 'threshold'], true)) {
            $target = (int) ($paceRules['tempo_sec'] ?? 0);
            $tolerance = (int) ($paceRules['tempo_tolerance_sec'] ?? 10) + 5;
        } else {
            // fast / generic: должен быть быстрее tempo, но не быстрее repetition.
            // Берём interval как ориентир, но допускаем щедрый tolerance.
            $target = (int) ($paceRules['tempo_sec'] ?? 0);
            $tolerance = 25;
        }

        if ($target <= 0) {
            continue;
        }

        if (abs($pace - $target) <= $tolerance) {
            continue;
        }

        $delta = abs($pace - $target);
        $segmentLabel = "сегмент #" . ((int) $idx + 1) . " ({$segmentType}" . ($isRacePace ? ', race_pace' : '') . ")";
        $issues[] = [
            'severity' => $delta > ($tolerance + 18) ? 'error' : 'warning',
            'code' => $code,
            'week_number' => $weekNumber,
            'date' => $date,
            'message' => "Неделя {$weekNumber}, {$date}: fartlek {$segmentLabel} pace {$segment['pace']} слишком далеко от целевого " . validatorFormatPaceSec($target) . ".",
        ];
    }

    return $issues;
}
