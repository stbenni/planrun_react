<?php
/**
 * Построитель промптов для генерации планов тренировок.
 *
 * Три НЕЗАВИСИМЫХ промпта для трёх сценариев:
 *   1. buildTrainingPlanPrompt()  — первичная генерация (новый пользователь)
 *   2. buildRecalculationPrompt() — пересчёт текущего плана (середина цикла)
 *   3. buildNextPlanPrompt()      — новый план после завершения предыдущего
 *
 * Каждый промпт строится из общих блоков-хелперов, но имеет собственную роль,
 * контекст и задачу. ИИ с первой строки понимает, что от него требуется.
 */

// ════════════════════════════════════════════════════════════════
// Утилиты (вычисления, не промпт)
// ════════════════════════════════════════════════════════════════

function computeRaceDayPosition(?string $startDateStr, ?string $raceDateStr): ?array {
    if (!$startDateStr || !$raceDateStr) return null;
    try {
        $start = new DateTime($startDateStr);
        $race = new DateTime($raceDateStr);
    } catch (Exception $e) {
        return null;
    }
    if ($race <= $start) return null;

    $dow = (int) $start->format('N');
    $weekStart = clone $start;
    if ($dow > 1) {
        $weekStart->modify('-' . ($dow - 1) . ' days');
    }

    $diff = (int) $weekStart->diff($race)->days;
    $weekNum = (int) floor($diff / 7) + 1;
    $dayIndex = $diff % 7;

    $dayNames = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
    return [
        'week' => $weekNum,
        'dayIndex' => $dayIndex,
        'dayName' => $dayNames[$dayIndex] ?? '',
    ];
}

function getSuggestedPlanWeeks($userData, $goalType) {
    $start = !empty($userData['training_start_date']) ? strtotime($userData['training_start_date']) : null;
    if (!$start) {
        return null;
    }
    if ($goalType === 'health') {
        if (!empty($userData['health_plan_weeks'])) {
            return (int) $userData['health_plan_weeks'];
        }
        if (!empty($userData['health_program'])) {
            $map = ['start_running' => 8, 'couch_to_5k' => 10, 'regular_running' => 12, 'custom' => 12];
            return $map[$userData['health_program']] ?? 12;
        }
        return 12;
    }
    if ($goalType === 'weight_loss' && !empty($userData['weight_goal_date'])) {
        $end = strtotime($userData['weight_goal_date']);
        if ($end > $start) {
            return (int) max(1, ceil(($end - $start) / (7 * 86400)));
        }
    }
    if (($goalType === 'race' || $goalType === 'time_improvement')) {
        $endStr = $userData['race_date'] ?? $userData['target_marathon_date'] ?? null;
        if ($endStr) {
            $end = strtotime($endStr);
            if ($end > $start) {
                return (int) max(1, ceil(($end - $start) / (7 * 86400)));
            }
        }
    }
    return null;
}

function calculatePaceZones($userData) {
    $easySec = null;

    if (!empty($userData['easy_pace_sec'])) {
        $easySec = (int) $userData['easy_pace_sec'];
    } elseif (!empty($userData['race_target_time']) && !empty($userData['race_distance'])) {
        $distKm = ['5k' => 5, '10k' => 10, 'half' => 21.1, '21.1k' => 21.1, 'marathon' => 42.195, '42.2k' => 42.195];
        $km = $distKm[$userData['race_distance']] ?? null;
        if ($km) {
            $parts = explode(':', $userData['race_target_time']);
            $totalSec = 0;
            if (count($parts) === 3) {
                $totalSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
            } elseif (count($parts) === 2) {
                $totalSec = (int)$parts[0] * 60 + (int)$parts[1];
            }
            if ($totalSec > 0) {
                $racePace = $totalSec / $km;
                $adders = ['5k' => 90, '10k' => 75, 'half' => 55, '21.1k' => 55, 'marathon' => 35, '42.2k' => 35];
                $easySec = (int) round($racePace + ($adders[$userData['race_distance']] ?? 60));
            }
        }
    }

    if (!$easySec || $easySec < 180 || $easySec > 600) {
        return null;
    }

    return [
        'easy'     => $easySec,
        'long'     => $easySec + 15,
        'tempo'    => $easySec - 45,
        'interval' => $easySec - 65,
        'recovery' => $easySec + 25,
    ];
}

function formatPace($sec) {
    $m = (int) floor($sec / 60);
    $s = (int) ($sec % 60);
    return $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}

function calculateDetrainingFactor(int $daysSince): float {
    if ($daysSince <= 3) return 1.0;
    if ($daysSince <= 7) return 0.95;
    if ($daysSince <= 14) return 0.85;
    if ($daysSince <= 21) return 0.75;
    return max(0.5, 1.0 - $daysSince * 0.015);
}

// ════════════════════════════════════════════════════════════════
// VDOT-калькулятор (Jack Daniels' Running Formula)
// ════════════════════════════════════════════════════════════════

/**
 * Oxygen cost of running at velocity v (meters/min).
 * Daniels formula: VO2 = -4.60 + 0.182258*v + 0.000104*v^2
 */
function _vdotOxygenCost(float $vMetersPerMin): float {
    return -4.60 + 0.182258 * $vMetersPerMin + 0.000104 * $vMetersPerMin * $vMetersPerMin;
}

/**
 * Fraction of VO2max sustainable for t minutes.
 * Daniels: %VO2max = 0.8 + 0.1894393*e^(-0.012778*t) + 0.2989558*e^(-0.1932605*t)
 */
function _vdotFractionVO2max(float $tMinutes): float {
    return 0.8 + 0.1894393 * exp(-0.012778 * $tMinutes)
             + 0.2989558 * exp(-0.1932605 * $tMinutes);
}

/**
 * Estimate VDOT from a race result.
 * @param float $distanceKm  Race distance in km
 * @param int   $timeSec     Finish time in seconds
 * @return float VDOT value (typically 30-85)
 */
function estimateVDOT(float $distanceKm, int $timeSec): float {
    $distMeters = $distanceKm * 1000;
    $tMin = $timeSec / 60.0;
    $velocity = $distMeters / $tMin;
    $vo2 = _vdotOxygenCost($velocity);
    $pct = _vdotFractionVO2max($tMin);
    if ($pct <= 0) return 30.0;
    return max(20.0, min(85.0, $vo2 / $pct));
}

/**
 * Predict race time at a target distance given a VDOT.
 * Uses bisection to find time where estimateVDOT(dist, time) == vdot.
 * @return int Predicted time in seconds
 */
function predictRaceTime(float $vdot, float $targetDistKm): int {
    $distMeters = $targetDistKm * 1000;
    // Bounds: 2 min/km (world record pace) to 12 min/km (walking)
    $lo = (int) ($targetDistKm * 2 * 60);
    $hi = (int) ($targetDistKm * 12 * 60);

    for ($i = 0; $i < 50; $i++) {
        $mid = ($lo + $hi) / 2;
        $tMin = $mid / 60.0;
        $v = $distMeters / $tMin;
        $vo2 = _vdotOxygenCost($v);
        $pct = _vdotFractionVO2max($tMin);
        $estVdot = ($pct > 0) ? $vo2 / $pct : 0;

        if ($estVdot > $vdot) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
        if ($hi - $lo < 1) break;
    }
    return (int) round(($lo + $hi) / 2);
}

/**
 * Get training paces from VDOT (sec/km for each zone).
 * Based on Daniels' tables approximated via formulas.
 */
function getTrainingPaces(float $vdot): array {
    // VO2max pace = velocity at 100% VO2max
    // Easy: 59-74% VO2max, Marathon: 75-84%, Threshold: 83-88%, Interval: 95-100%, Rep: short fast
    $paces = [];
    $targets = [
        'easy_slow'  => 0.62,
        'easy_fast'  => 0.70,
        'marathon'   => 0.79,
        'threshold'  => 0.86,
        'interval'   => 0.975,
        'repetition' => 1.05,
    ];

    foreach ($targets as $zone => $pctVO2) {
        $targetVO2 = $vdot * $pctVO2;
        // Inverse of oxygen cost: solve -4.60 + 0.182258*v + 0.000104*v^2 = targetVO2
        $a = 0.000104;
        $b = 0.182258;
        $c = -4.60 - $targetVO2;
        $disc = $b * $b - 4 * $a * $c;
        if ($disc < 0) {
            $paces[$zone] = 600;
            continue;
        }
        $v = (-$b + sqrt($disc)) / (2 * $a);
        $secPerKm = ($v > 0) ? 1000.0 / $v * 60 : 600;
        $paces[$zone] = max(150, min(600, (int) round($secPerKm)));
    }

    return [
        'easy'       => [$paces['easy_slow'], $paces['easy_fast']],
        'marathon'   => $paces['marathon'],
        'threshold'  => $paces['threshold'],
        'interval'   => $paces['interval'],
        'repetition' => $paces['repetition'],
    ];
}

/**
 * Format seconds as M:SS pace string.
 */
function formatPaceSec(int $sec): string {
    return (int) floor($sec / 60) . ':' . str_pad((string) ($sec % 60), 2, '0', STR_PAD_LEFT);
}

/**
 * Format seconds as H:MM:SS or M:SS time string.
 */
function formatTimeSec(int $sec): string {
    $h = (int) floor($sec / 3600);
    $m = (int) floor(($sec % 3600) / 60);
    $s = $sec % 60;
    if ($h > 0) {
        return $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
    }
    return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
}

/**
 * Predict race times for all standard distances given VDOT.
 */
function predictAllRaceTimes(float $vdot): array {
    $dists = ['5k' => 5.0, '10k' => 10.0, 'half' => 21.0975, 'marathon' => 42.195];
    $results = [];
    foreach ($dists as $label => $km) {
        $sec = predictRaceTime($vdot, $km);
        $results[$label] = [
            'seconds' => $sec,
            'formatted' => formatTimeSec($sec),
            'pace_sec' => (int) round($sec / $km),
            'pace_formatted' => formatPaceSec((int) round($sec / $km)),
        ];
    }
    return $results;
}

// ════════════════════════════════════════════════════════════════
// Оценка реалистичности цели (Goal Realism Assessment)
// ════════════════════════════════════════════════════════════════

function assessGoalRealism(array $userData): array {
    $goalType = $userData['goal_type'] ?? 'health';
    if (!in_array($goalType, ['race', 'time_improvement'])) {
        return ['verdict' => 'realistic', 'messages' => [], 'vdot' => null, 'predictions' => null, 'training_paces' => null];
    }

    $dist = $userData['race_distance'] ?? '';
    $distKm = ['5k' => 5.0, '10k' => 10.0, 'half' => 21.0975, '21.1k' => 21.0975, 'marathon' => 42.195, '42.2k' => 42.195];
    $targetKm = $distKm[$dist] ?? null;
    $distLabels = ['5k' => '5 км', '10k' => '10 км', 'half' => 'полумарафон', '21.1k' => 'полумарафон', 'marathon' => 'марафон', '42.2k' => 'марафон'];
    $distLabel = $distLabels[$dist] ?? $dist;

    $weeklyKm = (float) ($userData['weekly_base_km'] ?? 0);
    $sessions = (int) ($userData['sessions_per_week'] ?? 3);
    $expLevel = $userData['experience_level'] ?? 'novice';
    $isNovice = in_array($expLevel, ['novice', 'beginner']);

    $totalWeeks = getSuggestedPlanWeeks($userData, $goalType);

    $messages = [];
    $severities = [];

    // ── Check 1: Enough weeks ──
    $minWeeks = [
        '5k'       => ['novice' => 6,  'exp' => 4],
        '10k'      => ['novice' => 8,  'exp' => 6],
        'half'     => ['novice' => 12, 'exp' => 8],
        '21.1k'    => ['novice' => 12, 'exp' => 8],
        'marathon'  => ['novice' => 18, 'exp' => 12],
        '42.2k'    => ['novice' => 18, 'exp' => 12],
    ];
    $reqWeeks = $minWeeks[$dist][$isNovice ? 'novice' : 'exp'] ?? 8;
    $recWeeks = $minWeeks[$dist]['novice'] ?? 12;

    if ($totalWeeks !== null && $totalWeeks < $reqWeeks) {
        $suggestedDate = date('Y-m-d', strtotime(($userData['training_start_date'] ?? 'now') . " +{$recWeeks} weeks"));
        $shorterDist = null;
        $shorterLabel = null;
        $distOrder = ['5k', '10k', 'half', 'marathon'];
        $curIdx = array_search($dist, $distOrder);
        if ($curIdx !== false && $curIdx > 0) {
            $shorterDist = $distOrder[$curIdx - 1];
            $shorterLabel = $distLabels[$shorterDist] ?? $shorterDist;
        }

        $sev = $totalWeeks < ($reqWeeks * 0.6) ? 'unrealistic' : 'challenging';
        $severities[] = $sev;
        $msg = [
            'type' => $sev === 'unrealistic' ? 'error' : 'warning',
            'text' => "Для подготовки к дистанции «{$distLabel}» рекомендуется минимум {$reqWeeks} недель" . ($isNovice ? ' (для вашего уровня)' : '') . ". У вас {$totalWeeks}.",
            'suggestions' => [],
        ];
        $msg['suggestions'][] = ['text' => "Перенести забег на {$suggestedDate} ({$recWeeks} нед.)", 'action' => ['field' => 'race_date', 'value' => $suggestedDate]];
        if ($shorterDist) {
            $msg['suggestions'][] = ['text' => "Выбрать {$shorterLabel}", 'action' => ['field' => 'race_distance', 'value' => $shorterDist]];
        }
        $messages[] = $msg;
    }

    // ── Check 2: Enough base volume ──
    $minKm = [
        '5k'       => ['rec' => 0,  'abs' => 0],
        '10k'      => ['rec' => 15, 'abs' => 5],
        'half'     => ['rec' => 20, 'abs' => 10],
        '21.1k'    => ['rec' => 20, 'abs' => 10],
        'marathon'  => ['rec' => 30, 'abs' => 15],
        '42.2k'    => ['rec' => 30, 'abs' => 15],
    ];
    $absMin = $minKm[$dist]['abs'] ?? 0;
    $recMin = $minKm[$dist]['rec'] ?? 0;

    if ($weeklyKm < $absMin) {
        $severities[] = 'unrealistic';
        $messages[] = [
            'type' => 'error',
            'text' => "Ваш текущий объём ({$weeklyKm} км/нед) слишком мал для {$distLabel}. Минимум {$absMin} км/нед, рекомендуется {$recMin}+.",
            'suggestions' => [
                ['text' => "Сначала набрать базу {$recMin} км/нед (4-8 недель лёгкого бега)", 'action' => null],
            ],
        ];
    } elseif ($weeklyKm < $recMin) {
        $severities[] = 'challenging';
        $messages[] = [
            'type' => 'warning',
            'text' => "Ваш объём ({$weeklyKm} км/нед) ниже рекомендуемого для {$distLabel} ({$recMin}+ км/нед). Подготовка возможна, но с повышенным риском.",
            'suggestions' => [],
        ];
    }

    // ── Check 3: Enough sessions ──
    $minSess = ['5k' => [3,3], '10k' => [3,3], 'half' => [4,3], '21.1k' => [4,3], 'marathon' => [4,3], '42.2k' => [4,3]];
    $recSess = $minSess[$dist][0] ?? 3;
    $absSess = $minSess[$dist][1] ?? 3;

    if ($sessions < $absSess) {
        $severities[] = 'challenging';
        $messages[] = [
            'type' => 'warning',
            'text' => "Для {$distLabel} рекомендуется {$recSess} тренировки в неделю. У вас {$sessions}.",
            'suggestions' => [
                ['text' => "Добавить ещё 1 тренировочный день", 'action' => ['field' => 'sessions_hint', 'value' => $recSess]],
            ],
        ];
    } elseif ($sessions < $recSess && in_array($dist, ['marathon', '42.2k', 'half', '21.1k'])) {
        $severities[] = 'challenging';
        $messages[] = [
            'type' => 'warning',
            'text' => "Для {$distLabel} лучше иметь {$recSess} тренировки в неделю. У вас {$sessions} — справитесь, но плотнее.",
            'suggestions' => [
                ['text' => "Добавить ещё 1 день", 'action' => ['field' => 'sessions_hint', 'value' => $recSess]],
            ],
        ];
    }

    // ── Check 4: Target time (VDOT-powered) ──
    $vdot = null;
    $predictions = null;
    $trainingPaces = null;
    $vdotSource = null;

    // Try to get VDOT from last race
    $lastDist = $userData['last_race_distance'] ?? null;
    $lastTime = $userData['last_race_time'] ?? null;
    $lastDistKm = null;

    if ($lastDist && $lastTime) {
        if ($lastDist === 'other') {
            $lastDistKm = (float) ($userData['last_race_distance_km'] ?? 0);
        } else {
            $lastDistKm = $distKm[$lastDist] ?? null;
        }
        if ($lastDistKm && $lastDistKm > 0) {
            $parts = explode(':', $lastTime);
            $lastTimeSec = 0;
            if (count($parts) === 3) {
                $lastTimeSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
            } elseif (count($parts) === 2) {
                $lastTimeSec = (int)$parts[0] * 60 + (int)$parts[1];
            }
            if ($lastTimeSec > 0) {
                $vdot = estimateVDOT($lastDistKm, $lastTimeSec);
                $vdotSource = $distLabels[$lastDist] ?? "{$lastDistKm} км";
            }
        }
    }

    // Fallback: estimate from easy pace
    if (!$vdot) {
        $easySec = null;
        if (!empty($userData['easy_pace_sec'])) {
            $easySec = (int) $userData['easy_pace_sec'];
        }
        if ($easySec && $easySec >= 180 && $easySec <= 600) {
            // Easy pace ≈ 65-70% VO2max → reverse to get VDOT
            $easyVelocity = 1000.0 / $easySec * 60;
            $easyVO2 = _vdotOxygenCost($easyVelocity);
            $vdot = $easyVO2 / 0.68;
            $vdotSource = 'лёгкий темп';
        }
    }

    if ($vdot) {
        $predictions = predictAllRaceTimes($vdot);
        $trainingPaces = getTrainingPaces($vdot);

        // Compare target time with prediction
        $targetTimeStr = $userData['race_target_time'] ?? null;
        if ($targetTimeStr && $targetKm) {
            $tParts = explode(':', $targetTimeStr);
            $targetSec = 0;
            if (count($tParts) === 3) {
                $targetSec = (int)$tParts[0] * 3600 + (int)$tParts[1] * 60 + (int)$tParts[2];
            } elseif (count($tParts) === 2) {
                $targetSec = (int)$tParts[0] * 60 + (int)$tParts[1];
            }

            if ($targetSec > 0) {
                $predictedSec = predictRaceTime($vdot, $targetKm);
                $diff = ($predictedSec - $targetSec) / $predictedSec * 100;

                $predictedStr = formatTimeSec($predictedSec);
                $targetStr = formatTimeSec($targetSec);

                if ($diff > 15) {
                    $severities[] = 'unrealistic';
                    $messages[] = [
                        'type' => 'error',
                        'text' => "Ваш целевой результат ({$targetStr}) на " . round($diff) . "% быстрее прогноза ({$predictedStr}, на основе {$vdotSource}). Это нереалистично за один цикл подготовки.",
                        'suggestions' => [
                            ['text' => "Поставить реалистичную цель: {$predictedStr}", 'action' => ['field' => 'race_target_time', 'value' => formatTimeSec($predictedSec)]],
                        ],
                    ];
                } elseif ($diff > 5) {
                    $severities[] = 'challenging';
                    $messages[] = [
                        'type' => 'warning',
                        'text' => "Ваш целевой результат ({$targetStr}) на " . round($diff) . "% быстрее прогноза ({$predictedStr}). Амбициозно, но достижимо при правильной подготовке.",
                        'suggestions' => [],
                    ];
                } elseif ($diff >= -5) {
                    $messages[] = [
                        'type' => 'success',
                        'text' => "Ваш целевой результат ({$targetStr}) реалистичен! Прогноз на основе {$vdotSource}: {$predictedStr}.",
                        'suggestions' => [],
                    ];
                } else {
                    $messages[] = [
                        'type' => 'success',
                        'text' => "Ваш целевой результат ({$targetStr}) консервативен. По текущей форме вы способны на {$predictedStr}.",
                        'suggestions' => [],
                    ];
                }
            }
        }
    }

    // ── Check 5: computeMacrocycle warnings ──
    $mc = computeMacrocycle($userData, $goalType);
    if ($mc && !empty($mc['warnings'])) {
        foreach ($mc['warnings'] as $w) {
            $severities[] = 'challenging';
            $messages[] = ['type' => 'warning', 'text' => $w, 'suggestions' => []];
        }
    }

    // ── Verdict ──
    $unrealisticCount = count(array_filter($severities, fn($s) => $s === 'unrealistic'));
    $challengingCount = count(array_filter($severities, fn($s) => $s === 'challenging'));

    if ($unrealisticCount > 0) {
        $verdict = 'unrealistic';
    } elseif ($challengingCount >= 2) {
        $verdict = 'unrealistic';
    } elseif ($challengingCount === 1) {
        $verdict = 'challenging';
    } else {
        $verdict = 'realistic';
    }

    // ── Format training paces for display ──
    $pacesFormatted = null;
    if ($trainingPaces) {
        $pacesFormatted = [
            'easy' => formatPaceSec($trainingPaces['easy'][0]) . ' – ' . formatPaceSec($trainingPaces['easy'][1]),
            'marathon' => formatPaceSec($trainingPaces['marathon']),
            'threshold' => formatPaceSec($trainingPaces['threshold']),
            'interval' => formatPaceSec($trainingPaces['interval']),
        ];
    }

    // ── Format predictions for display ──
    // Exclude the source distance (circular prediction) and map keys to labels
    $vdotSourceDist = $lastDist ?: null;
    $predsFormatted = null;
    if ($predictions) {
        $predsFormatted = [];
        $distKeyMap = ['5k' => '5k', '10k' => '10k', 'half' => 'half', '21.1k' => 'half', 'marathon' => 'marathon', '42.2k' => 'marathon'];
        $sourceKey = $vdotSourceDist ? ($distKeyMap[$vdotSourceDist] ?? null) : null;
        foreach ($predictions as $d => $p) {
            if ($d === $sourceKey) continue;
            $predsFormatted[$d] = $p['formatted'];
        }
    }

    // ── Info: plan summary (if realistic enough) ──
    if ($mc && $verdict !== 'unrealistic') {
        $phaseNames = array_map(fn($p) => $p['label'], $mc['phases']);
        $messages[] = [
            'type' => 'info',
            'text' => "План: {$mc['total_weeks']} недель (" . implode(' → ', $phaseNames) . "). Пиковая длительная: {$mc['long_run']['peak_km']} км. Объём: {$mc['start_volume_km']}→{$mc['peak_volume_km']} км/нед.",
            'suggestions' => [],
        ];
    }

    return [
        'verdict' => $verdict,
        'messages' => $messages,
        'vdot' => $vdot ? round($vdot, 1) : null,
        'vdot_source' => $vdotSource,
        'predictions' => $predsFormatted,
        'training_paces' => $pacesFormatted,
        'recommended_weeks' => $totalWeeks && $totalWeeks < $reqWeeks ? $recWeeks : null,
        'recommended_distance' => null,
        'recommended_sessions' => $sessions < $recSess ? $recSess : null,
    ];
}

// ════════════════════════════════════════════════════════════════
// Универсальный калькулятор макроцикла
// (Daniels, Pfitzinger, Hansons — адаптированные пропорции)
// ════════════════════════════════════════════════════════════════

/**
 * Справочник параметров по дистанции.
 * base/build/peak/taper — доли от общего числа недель для «стандартной» подготовки (≥16 нед).
 * long_min/long_peak — диапазон длительной (км).
 * long_peak_before_race — за сколько недель до забега ставить пиковую длительную.
 * focus — основная тренировочная направленность интенсивного периода.
 */
function getDistanceSpec(string $dist): array {
    $specs = [
        '5k' => [
            'base_pct' => 0.25, 'build_pct' => 0.40, 'peak_pct' => 0.20, 'taper_pct' => 0.15,
            'long_min' => 8, 'long_peak' => 12, 'long_peak_before_race' => 2,
            'focus' => 'VO2max / интервалы (400-1000 м)',
            'intervals' => '6-10x400 м или 4-6x800 м или 3-5x1000 м',
            'tempo' => '2-4 км непрерывного бега в темповом',
            'long_desc' => '8-12 км в лёгком темпе',
            'fartlek' => '30-40 мин с ускорениями 1-2 мин через 2-3 мин трусцой',
            'control_dist' => '1-3 км',
        ],
        '10k' => [
            'base_pct' => 0.25, 'build_pct' => 0.35, 'peak_pct' => 0.25, 'taper_pct' => 0.15,
            'long_min' => 10, 'long_peak' => 16, 'long_peak_before_race' => 2,
            'focus' => 'лактатный порог / темповый бег',
            'intervals' => '5-8x1000 м или 3-5x2000 м (отдых 400-800 м)',
            'tempo' => '4-6 км в темповом темпе',
            'long_desc' => '12-16 км в лёгком темпе',
            'fartlek' => '40-50 мин с ускорениями 2-3 мин через 2-3 мин трусцой',
            'control_dist' => '3-5 км',
        ],
        'half' => [
            'base_pct' => 0.25, 'build_pct' => 0.35, 'peak_pct' => 0.20, 'taper_pct' => 0.20,
            'long_min' => 14, 'long_peak' => 22, 'long_peak_before_race' => 3,
            'focus' => 'темп + длительные с прогрессией',
            'intervals' => '4-6x1600-2000 м (отдых 600 м трусцой)',
            'tempo' => '6-10 км в темповом темпе',
            'long_desc' => '16-22 км в лёгком темпе; последние 3-5 км можно в целевом темпе',
            'fartlek' => '45-55 мин с ускорениями 3-4 мин через 2-3 мин',
            'control_dist' => '5-10 км',
        ],
        'marathon' => [
            'base_pct' => 0.20, 'build_pct' => 0.40, 'peak_pct' => 0.20, 'taper_pct' => 0.20,
            'long_min' => 16, 'long_peak' => 33, 'long_peak_before_race' => 3,
            'focus' => 'марафонский темп + длительные 28-35 км',
            'intervals' => '4-6x1600-2000 м (отдых 800 м трусцой)',
            'tempo' => '8-12 км в темповом темпе',
            'long_desc' => '22-35 км в лёгком/марафонском темпе',
            'fartlek' => '50-60 мин с ускорениями 3-5 мин через 2-3 мин',
            'control_dist' => '10-15 км в целевом марафонском темпе или 10 км на результат',
            'marathon_pace' => '10-16 км в целевом марафонском темпе (внутри длительной или отдельно)',
        ],
    ];
    $aliases = ['21.1k' => 'half', '42.2k' => 'marathon'];
    $key = $aliases[$dist] ?? $dist;
    return $specs[$key] ?? $specs['10k'];
}

/**
 * Расчёт универсального макроцикла.
 *
 * Возвращает структурированный массив: фазы, прогрессия длительной,
 * объёмы, recovery weeks, спецификации тренировок.
 */
function computeMacrocycle(array $userData, string $goalType): ?array {
    if (!in_array($goalType, ['race', 'time_improvement'])) {
        return null;
    }

    $dist = $userData['race_distance'] ?? '';
    $totalWeeks = getSuggestedPlanWeeks($userData, $goalType);
    if (!$totalWeeks || $totalWeeks < 4) return null;

    $weeklyKm = !empty($userData['weekly_base_km']) ? (float) $userData['weekly_base_km'] : 0;
    $sessions = (int) ($userData['sessions_per_week'] ?? 3);
    $expLevel = $userData['experience_level'] ?? 'novice';
    $isNovice = in_array($expLevel, ['novice', 'beginner']);
    $hasBase = $weeklyKm >= 25;
    $spec = getDistanceSpec($dist);

    // ── Расчёт длительностей фаз ──
    $preBaseW = 0;
    if ($totalWeeks < 8) {
        $taperW = max(1, min(2, (int) round($totalWeeks * 0.20)));
        $baseW = 0;
        $peakW = 0;
        $buildW = $totalWeeks - $taperW;
    } elseif ($totalWeeks > 24) {
        // Длинный план: выделяем «серьёзный» блок 18-24 нед, остаток — pre-base
        $seriousWeeks = min(24, max(18, (int) round($totalWeeks * 0.55)));
        $preBaseW = $totalWeeks - $seriousWeeks;
        $taperW = max(2, min(3, (int) round($seriousWeeks * $spec['taper_pct'])));
        $remaining = $seriousWeeks - $taperW;
        $baseW = max(2, (int) round($remaining * $spec['base_pct']));
        $peakW = max(2, (int) round($remaining * $spec['peak_pct']));
        $buildW = $remaining - $baseW - $peakW;
    } else {
        $taperW = max(2, min(3, (int) round($totalWeeks * $spec['taper_pct'])));
        $remaining = $totalWeeks - $taperW;

        if ($hasBase && $totalWeeks <= 12) {
            $baseW = $isNovice ? 2 : max(1, min(2, (int) round($remaining * 0.12)));
            $peakW = max(1, min(2, (int) round($remaining * 0.15)));
            $buildW = $remaining - $baseW - $peakW;
        } elseif ($isNovice) {
            $baseW = max(3, (int) round($remaining * ($spec['base_pct'] + 0.08)));
            $peakW = max(1, (int) round($remaining * $spec['peak_pct']));
            $buildW = $remaining - $baseW - $peakW;
        } else {
            $baseW = max(2, (int) round($remaining * $spec['base_pct']));
            $peakW = max(1, (int) round($remaining * $spec['peak_pct']));
            $buildW = $remaining - $baseW - $peakW;
        }
    }
    $buildW = max(2, min($buildW, 12));

    // ── Recovery weeks (каждые 3-4 нед, не в taper) ──
    $recoveryWeeks = [];
    $trainWeeks = $totalWeeks - $taperW;
    $cycle = $isNovice ? 3 : 4;
    for ($w = $cycle; $w <= $trainWeeks; $w += $cycle) {
        if ($w < $trainWeeks) {
            $recoveryWeeks[] = $w;
        }
    }

    // ── Прогрессия длительной ──
    $warnings = [];

    // longStart привязан к реальной физической форме
    if ($weeklyKm >= 25) {
        $longStart = max($spec['long_min'], round($weeklyKm * 0.55));
    } elseif ($weeklyKm > 0) {
        $longStart = max(3, round($weeklyKm * 0.55));
    } else {
        $longStart = 3;
    }

    $longPeak = $spec['long_peak'];
    if (in_array($dist, ['marathon', '42.2k'])) {
        if ($totalWeeks < 14) {
            $longPeak = max(28, min($longPeak, 30));
        }
    }
    if ($totalWeeks < 8) {
        $longPeak = min($longPeak, (int) round($longStart + $totalWeeks * 2.5));
    }
    // Если база выше пиковой (опытный бегун, короткий план), выровнять
    if ($longStart > $longPeak) {
        $longStart = $longPeak;
    }

    // Проверка: хватит ли времени для безопасного достижения пиковой
    $safeIncrement = 2.5;
    $minWeeksNeeded = ($longPeak - $longStart) / $safeIncrement;
    $trainWeeksAvailable = $totalWeeks - $taperW;
    $distLabelsWarn = ['marathon' => 'марафону', '42.2k' => 'марафону', 'half' => 'полумарафону', '21.1k' => 'полумарафону', '10k' => '10 км', '5k' => '5 км'];
    $distLbl = $distLabelsWarn[$dist] ?? 'забегу';

    if ($minWeeksNeeded > $trainWeeksAvailable * 0.9) {
        $warnings[] = "НЕДОСТАТОЧНО ВРЕМЕНИ для безопасной подготовки к {$distLbl}. Прогрессия длительной ({$longStart}→{$longPeak} км) требует минимум " . ((int) ceil($minWeeksNeeded + $taperW + 2)) . " недель. У пользователя {$totalWeeks} недель. Снижай пиковую длительную и ОБЯЗАТЕЛЬНО предупреди бегуна о риске травмы.";
        $longPeak = min($longPeak, (int) round($longStart + $trainWeeksAvailable * $safeIncrement));
    }

    if ($weeklyKm < 15 && in_array($dist, ['marathon', '42.2k']) && $totalWeeks < 16) {
        $warnings[] = "КРАЙНЕ НЕРЕАЛИСТИЧНАЯ ЦЕЛЬ: марафон при базе {$weeklyKm} км/нед за {$totalWeeks} недель. Безопасная подготовка к марафону требует минимум 16-20 недель при базе 25+ км/нед. Предложи бегуну более короткую дистанцию или сдвинуть дату забега.";
    } elseif ($weeklyKm < 10 && in_array($dist, ['half', '21.1k']) && $totalWeeks < 10) {
        $warnings[] = "НЕРЕАЛИСТИЧНАЯ ЦЕЛЬ: полумарафон при базе {$weeklyKm} км/нед за {$totalWeeks} недель. Рекомендуй 10-12 недель при базе 15+ км/нед.";
    }

    $peakWeekNum = $totalWeeks - $taperW - $spec['long_peak_before_race'] + 1;
    $peakWeekNum = max($preBaseW + $baseW + $buildW, min($totalWeeks - $taperW, $peakWeekNum));

    // Расчёт длительной по неделям (инкремент только по не-recovery неделям)
    $trainingWeeksBeforePeak = 0;
    for ($w = 1; $w < $peakWeekNum; $w++) {
        if (!in_array($w, $recoveryWeeks)) {
            $trainingWeeksBeforePeak++;
        }
    }
    $increment = $trainingWeeksBeforePeak > 0
        ? ($longPeak - $longStart) / $trainingWeeksBeforePeak
        : 0;
    $increment = min(3.0, $increment);

    $longRunByWeek = [];
    $trainingIdx = 0;
    for ($w = 1; $w <= $totalWeeks; $w++) {
        if ($w <= $peakWeekNum) {
            if (in_array($w, $recoveryWeeks)) {
                $progressKm = $longStart + $trainingIdx * $increment;
                $km = $progressKm * 0.80;
            } else {
                $km = $longStart + $trainingIdx * $increment;
                $km = min($km, $longPeak);
                $trainingIdx++;
            }
        } elseif ($w <= $totalWeeks - $taperW) {
            $weeksAfterPeak = $w - $peakWeekNum;
            $km = $longPeak - $weeksAfterPeak * 3;
            $km = max($longStart, $km);
            if (in_array($w, $recoveryWeeks)) {
                $km = $km * 0.80;
            }
        } else {
            $taperIdx = $w - ($totalWeeks - $taperW);
            $km = max(8, $longPeak * (0.55 - 0.15 * ($taperIdx - 1)));
        }

        $longRunByWeek[$w] = round($km);
    }

    // ── Объёмы ──
    if ($weeklyKm > 0) {
        $startVolume = round($weeklyKm * ($hasBase ? 1.05 : 1.0));
    } else {
        $startVolume = round($longStart * $sessions * 0.9);
    }
    $peakVolume = round($startVolume * ($isNovice ? 1.35 : 1.55));
    $peakVolume = max($peakVolume, (int) round($longPeak * 1.4));
    $startVolume = max($startVolume, (int) round($longStart * 1.5));

    // ── Ключевые тренировки по фазам ──
    $maxKeyBase = $isNovice ? 0 : 1;
    $maxKeyBuild = min($sessions <= 3 ? 1 : 2, 2);
    $maxKeyPeak = min($sessions <= 3 ? 2 : 3, 3);

    // ── Фазы ──
    $phases = [];
    $weekCursor = 1;

    if ($preBaseW > 0) {
        $phases[] = [
            'name' => 'pre_base',
            'label' => 'Общая подготовка',
            'weeks_from' => $weekCursor,
            'weeks_to' => $weekCursor + $preBaseW - 1,
            'max_key_workouts' => 0,
            'description' => "Лёгкий бег, ОФП, наработка регулярности. Прирост объёма до 10%/нед. Без темповых/интервалов. Длительная — в лёгком темпе.",
        ];
        $weekCursor += $preBaseW;
    }

    if ($baseW > 0) {
        $phases[] = [
            'name' => 'base',
            'label' => 'Базовый',
            'weeks_from' => $weekCursor,
            'weeks_to' => $weekCursor + $baseW - 1,
            'max_key_workouts' => $maxKeyBase,
            'description' => $hasBase && !$isNovice
                ? "Ввод в план. Лёгкий бег + длительная. " . ($maxKeyBase > 0 ? "Допустим 1 фартлек со 2-й недели." : "Без интенсивности.")
                : "Аэробный объём. Лёгкий бег + длительная. Без темповых/интервалов. Прирост объёма до 10%/нед.",
        ];
        $weekCursor += $baseW;
    }

    $phases[] = [
        'name' => 'build',
        'label' => 'Развивающий',
        'weeks_from' => $weekCursor,
        'weeks_to' => $weekCursor + $buildW - 1,
        'max_key_workouts' => $maxKeyBuild,
        'description' => "{$maxKeyBuild} ключевые/нед (темп/интервалы). Длительная растёт. Объём +5-10%/нед.",
    ];
    $weekCursor += $buildW;

    if ($peakW > 0) {
        $phases[] = [
            'name' => 'peak',
            'label' => 'Пиковый',
            'weeks_from' => $weekCursor,
            'weeks_to' => $weekCursor + $peakW - 1,
            'max_key_workouts' => $maxKeyPeak,
            'description' => "Максимальная интенсивность. {$maxKeyPeak} ключевых/нед. Объём стабилен. Пиковая длительная.",
        ];
        $weekCursor += $peakW;
    }

    $phases[] = [
        'name' => 'taper',
        'label' => 'Подводка',
        'weeks_from' => $weekCursor,
        'weeks_to' => $totalWeeks,
        'max_key_workouts' => 1,
        'description' => "Объём -40-60% от пикового. 1 короткая интенсивная/нед. Последняя неделя — совсем лёгкая.",
    ];

    return [
        'total_weeks' => $totalWeeks,
        'distance' => $dist,
        'start_volume_km' => $startVolume,
        'peak_volume_km' => $peakVolume,
        'phases' => $phases,
        'recovery_weeks' => $recoveryWeeks,
        'long_run' => [
            'start_km' => (int) $longStart,
            'peak_km' => (int) $longPeak,
            'peak_week' => $peakWeekNum,
            'by_week' => $longRunByWeek,
        ],
        'spec' => $spec,
        'is_novice' => $isNovice,
        'has_base' => $hasBase,
        'weekly_base_km' => $weeklyKm,
        'sessions' => $sessions,
        'warnings' => $warnings,
    ];
}

/**
 * Форматирует результат computeMacrocycle() в текст для промпта.
 */
function formatMacrocyclePrompt(array $mc): string {
    $out = '';
    $spec = $mc['spec'];
    $dist = $mc['distance'];
    $tw = $mc['total_weeks'];

    // Предупреждения о нереалистичных целях
    if (!empty($mc['warnings'])) {
        $out .= "⚠⚠⚠ ВНИМАНИЕ — ПРОБЛЕМЫ С ЦЕЛЬЮ ПОЛЬЗОВАТЕЛЯ ⚠⚠⚠\n";
        foreach ($mc['warnings'] as $warn) {
            $out .= "- {$warn}\n";
        }
        $out .= "При генерации плана ОБЯЗАТЕЛЬНО добавь в week_focus первой недели предупреждение о нереалистичности цели.\n";
        $out .= "Генерируй план максимально безопасный — лучше не достичь цели, чем получить травму.\n\n";
    }

    // Заголовок
    $label = '';
    if ($mc['has_base'] && $tw <= 12) {
        $label = ", ускоренная подготовка — бегун имеет базу {$mc['weekly_base_km']} км/нед";
    } elseif ($mc['is_novice']) {
        $label = ", новичок — удлинённый базовый период";
    }
    $out .= "МАКРОЦИКЛ ({$tw} недель{$label}):\n";

    // Фазы
    foreach ($mc['phases'] as $phase) {
        $wRange = $phase['weeks_from'] == $phase['weeks_to']
            ? "неделя {$phase['weeks_from']}"
            : "недели {$phase['weeks_from']}-{$phase['weeks_to']}";

        $longFirst = $mc['long_run']['by_week'][$phase['weeks_from']] ?? '?';
        $longLast = $mc['long_run']['by_week'][$phase['weeks_to']] ?? '?';
        $longRange = $longFirst == $longLast ? "{$longFirst} км" : "{$longFirst}→{$longLast} км";

        $out .= "- {$phase['label']} ({$wRange}): {$phase['description']} Длительная: {$longRange}. Ключевых: до {$phase['max_key_workouts']}/нед.\n";
    }

    // Recovery weeks
    if (!empty($mc['recovery_weeks'])) {
        $rw = implode(', ', $mc['recovery_weeks']);
        $out .= "Разгрузочные недели: {$rw} (объём -20%, убрать интенсивность, длительную сократить на 20%).\n";
    }

    // Прогрессия длительной
    $out .= "\nПРОГРЕССИЯ ДЛИТЕЛЬНОЙ ПО НЕДЕЛЯМ (следуй этим числам!):\n";
    $longParts = [];
    foreach ($mc['long_run']['by_week'] as $w => $km) {
        if ($w == $tw && in_array($dist, ['marathon', '42.2k', 'half', '21.1k', '5k', '10k'])) {
            $longParts[] = "нед{$w}: забег";
        } else {
            $longParts[] = "нед{$w}: {$km}";
        }
    }
    $out .= implode(' → ', $longParts) . " (км)\n";
    $out .= "ПИКОВАЯ ДЛИТЕЛЬНАЯ: {$mc['long_run']['peak_km']} км (неделя {$mc['long_run']['peak_week']}). ЭТО ОБЯЗАТЕЛЬНЫЙ МИНИМУМ.\n\n";

    // Объёмы
    $out .= "ОБЪЁМЫ: стартовый ~{$mc['start_volume_km']} км/нед → пиковый ~{$mc['peak_volume_km']} км/нед.\n";
    $out .= "Правило: прирост не более 10%/нед. 80% объёма в лёгком темпе, до 20% — ключевые тренировки.\n\n";

    // Тренировки по дистанции
    $distLabels = ['5k' => '5 КМ', '10k' => '10 КМ', 'half' => 'ПОЛУМАРАФОНА', '21.1k' => 'ПОЛУМАРАФОНА', 'marathon' => 'МАРАФОНА', '42.2k' => 'МАРАФОНА'];
    $distLabel = $distLabels[$dist] ?? 'ЗАБЕГА';
    $out .= "ТРЕНИРОВКИ ДЛЯ {$distLabel}:\n";
    $out .= "- Интервалы: {$spec['intervals']}.\n";
    $out .= "- Темповый: {$spec['tempo']}.\n";
    $out .= "- Длительная: {$spec['long_desc']}.\n";
    $out .= "- Фартлек: {$spec['fartlek']}.\n";
    if (!empty($spec['marathon_pace'])) {
        $out .= "- Марафонский темп: {$spec['marathon_pace']}.\n";
    }

    $out .= "\nКАЖДАЯ КЛЮЧЕВАЯ ТРЕНИРОВКА (темп, интервалы, фартлек) включает разминку (1.5-2 км) и заминку (1-1.5 км).\n";
    $out .= "distance_km для интервалов/фартлека считает код — не заполняй.\n\n";

    // Контрольные забеги
    $out .= "КОНТРОЛЬНЫЕ ЗАБЕГИ (type: \"control\"):\n";
    $out .= "Тест-забег на дистанцию короче целевой для замера прогресса. is_key_workout: true. pace: null.\n";
    $out .= "Ставить каждые 3-4 недели (перед разгрузочной). Не в первые 2-3 недели и не в последние 2.\n";
    $out .= "Дистанция контрольной: {$spec['control_dist']}.\n\n";

    // Новичок-модификации
    if ($mc['is_novice']) {
        $out .= "НОВИЧОК: интервалы вводить не ранее недели 4-5. Начинать с 3-4x400 м. Контрольные забеги — не ранее недели 4, дистанция 1-2 км.\n\n";
    }

    return $out;
}

// ════════════════════════════════════════════════════════════════
// Общие блоки промпта (хелперы)
// ════════════════════════════════════════════════════════════════

function buildUserInfoBlock($userData) {
    $block = "═══ ИНФОРМАЦИЯ О ПОЛЬЗОВАТЕЛЕ ═══\n\n";

    if (!empty($userData['gender'])) {
        $gender = $userData['gender'] === 'male' ? 'мужской' : 'женский';
        $block .= "Пол: {$gender}\n";
    }
    
    if (!empty($userData['birth_year'])) {
        $age = date('Y') - (int)$userData['birth_year'];
        $block .= "Возраст: ~{$age} лет\n";
    }
    
    if (!empty($userData['height_cm'])) {
        $block .= "Рост: {$userData['height_cm']} см\n";
    }
    
    if (!empty($userData['weight_kg'])) {
        $block .= "Вес: {$userData['weight_kg']} кг\n";
    }
    
    if (!empty($userData['experience_level'])) {
        $levelMap = [
            'novice' => 'Новичок (не бегает или менее 3 месяцев)',
            'beginner' => 'Начинающий (3-6 месяцев регулярного бега)',
            'intermediate' => 'Средний (6-12 месяцев регулярного бега)',
            'advanced' => 'Продвинутый (1-2 года регулярного бега)',
            'expert' => 'Опытный (более 2 лет регулярного бега)'
        ];
        $level = $levelMap[$userData['experience_level']] ?? $userData['experience_level'];
        $block .= "Уровень подготовки: {$level}\n";
    }
    
    if (!empty($userData['weekly_base_km'])) {
        $block .= "Текущий объем бега: {$userData['weekly_base_km']} км в неделю\n";
    }
    
    if (!empty($userData['sessions_per_week'])) {
        $block .= "Желаемое количество тренировок в неделю: {$userData['sessions_per_week']}\n";
    }

    return $block;
}

function buildGoalBlock($userData, $goalType) {
    $block = "\n═══ ЦЕЛЬ ТРЕНИРОВОК ═══\n\n";
    
    switch ($goalType) {
        case 'race':
            $block .= "Цель: Подготовка к забегу\n";
            if (!empty($userData['race_distance'])) {
                $distanceMap = [
                    '5k' => '5 км',
                    '10k' => '10 км',
                    'half' => '21.1 км (полумарафон)',
                    'marathon' => '42.2 км (марафон)',
                    '21.1k' => '21.1 км (полумарафон)',
                    '42.2k' => '42.2 км (марафон)'
                ];
                $distance = $distanceMap[$userData['race_distance']] ?? $userData['race_distance'];
                $block .= "Дистанция забега: {$distance}\n";
            }
            if (!empty($userData['race_date'])) {
                $block .= "Дата забега: {$userData['race_date']}\n";
                $racePos = computeRaceDayPosition($userData['training_start_date'] ?? null, $userData['race_date']);
                if ($racePos) {
                    $block .= "ДЕНЬ ЗАБЕГА: неделя {$racePos['week']}, день индекс {$racePos['dayIndex']} ({$racePos['dayName']}). Поставь type: \"race\" именно на этот индекс.\n";
                }
            }
            if (!empty($userData['race_target_time'])) {
                $block .= "Целевое время: {$userData['race_target_time']}\n";
            }
            if (!empty($userData['is_first_race_at_distance'])) {
                $block .= "Это первый забег на эту дистанцию: " . ($userData['is_first_race_at_distance'] ? 'Да' : 'Нет') . "\n";
            }
            if (!empty($userData['last_race_time']) && !empty($userData['last_race_distance'])) {
                $block .= "Последний результат: {$userData['last_race_distance']} за {$userData['last_race_time']}\n";
            }
            if (!empty($userData['running_experience'])) {
                $expMap = [
                    'less_3m' => 'Менее 3 месяцев',
                    '3_6m' => '3-6 месяцев',
                    '6_12m' => '6-12 месяцев',
                    '1_2y' => '1-2 года',
                    'more_2y' => 'Более 2 лет'
                ];
                $exp = $expMap[$userData['running_experience']] ?? $userData['running_experience'];
                $block .= "Стаж регулярного бега: {$exp}\n";
            }
            if (!empty($userData['easy_pace_sec'])) {
                $paceMin = (int) floor($userData['easy_pace_sec'] / 60);
                $paceSec = (int) ($userData['easy_pace_sec'] % 60);
                $block .= "Комфортный темп: {$paceMin}:" . str_pad((string)$paceSec, 2, '0', STR_PAD_LEFT) . " /км\n";
            }
            if (!empty($userData['last_race_date'])) {
                $block .= "Дата последнего забега: {$userData['last_race_date']}\n";
            }
            if (!empty($userData['last_race_distance_km']) && $userData['last_race_distance'] === 'other') {
                $block .= "Последний забег: {$userData['last_race_distance_km']} км\n";
            }
            break;
            
        case 'weight_loss':
            $block .= "Цель: Снижение веса\n";
            if (!empty($userData['weight_goal_kg'])) {
                $currentWeight = $userData['weight_kg'] ?? 0;
                if ($currentWeight > 0) {
                    $diff = $currentWeight - $userData['weight_goal_kg'];
                    $block .= "Текущий вес: {$currentWeight} кг\n";
                    $block .= "Целевой вес: {$userData['weight_goal_kg']} кг (нужно сбросить {$diff} кг)\n";
                } else {
                    $block .= "Целевой вес: {$userData['weight_goal_kg']} кг\n";
                }
            }
            if (!empty($userData['weight_goal_date'])) {
                $block .= "К какой дате достичь цели: {$userData['weight_goal_date']}\n";
            }
            break;
            
        case 'time_improvement':
            $block .= "Цель: Улучшение времени на дистанции\n";
            if (!empty($userData['race_distance'])) {
                $distanceMap = [
                    '5k' => '5 км',
                    '10k' => '10 км',
                    'half' => '21.1 км (полумарафон)',
                    'marathon' => '42.2 км (марафон)',
                    '21.1k' => '21.1 км (полумарафон)',
                    '42.2k' => '42.2 км (марафон)'
                ];
                $distance = $distanceMap[$userData['race_distance']] ?? $userData['race_distance'];
                $block .= "Дистанция: {$distance}\n";
            }
            $targetTime = $userData['race_target_time'] ?? $userData['target_marathon_time'] ?? null;
            if (!empty($targetTime)) {
                $block .= "Целевое время: {$targetTime}\n";
            }
            $targetDate = $userData['race_date'] ?? $userData['target_marathon_date'] ?? null;
            if (!empty($targetDate)) {
                $block .= "Дата целевого забега: {$targetDate}\n";
                $racePos = computeRaceDayPosition($userData['training_start_date'] ?? null, $targetDate);
                if ($racePos) {
                    $block .= "ДЕНЬ ЗАБЕГА: неделя {$racePos['week']}, день индекс {$racePos['dayIndex']} ({$racePos['dayName']}). Поставь type: \"race\" именно на этот индекс.\n";
                }
            }
            if (!empty($userData['last_race_time'])) {
                $block .= "Текущее время: {$userData['last_race_time']}\n";
            }
            if (!empty($userData['running_experience'])) {
                $expMap = [
                    'less_3m' => 'Менее 3 месяцев',
                    '3_6m' => '3-6 месяцев',
                    '6_12m' => '6-12 месяцев',
                    '1_2y' => '1-2 года',
                    'more_2y' => 'Более 2 лет'
                ];
                $exp = $expMap[$userData['running_experience']] ?? $userData['running_experience'];
                $block .= "Стаж регулярного бега: {$exp}\n";
            }
            if (!empty($userData['easy_pace_sec'])) {
                $paceMin = (int) floor($userData['easy_pace_sec'] / 60);
                $paceSec = (int) ($userData['easy_pace_sec'] % 60);
                $block .= "Комфортный темп: {$paceMin}:" . str_pad((string)$paceSec, 2, '0', STR_PAD_LEFT) . " /км\n";
            }
            if (!empty($userData['last_race_date'])) {
                $block .= "Дата последнего забега: {$userData['last_race_date']}\n";
            }
            if (!empty($userData['last_race_distance_km']) && $userData['last_race_distance'] === 'other') {
                $block .= "Последний забег: {$userData['last_race_distance_km']} км\n";
            }
            break;
            
        case 'health':
        default:
            $block .= "Цель: Бег для здоровья и общего физического развития\n";
            if (!empty($userData['health_program'])) {
                $programMap = [
                    'start_running' => 'Начни бегать (8 недель)',
                    'couch_to_5k' => '5 км без остановки (10 недель)',
                    'regular_running' => 'Регулярный бег (12 недель)',
                    'custom' => 'Свой план'
                ];
                $program = $programMap[$userData['health_program']] ?? $userData['health_program'];
                $block .= "Программа: {$program}\n";
            }
            if (!empty($userData['current_running_level'])) {
                $levelMap = [
                    'zero' => 'Нет, начинаю с нуля',
                    'basic' => 'Да, но тяжело',
                    'comfortable' => 'Легко, могу больше'
                ];
                $level = $levelMap[$userData['current_running_level']] ?? $userData['current_running_level'];
                $block .= "Может пробежать 1 км без остановки: {$level}\n";
            }
            if (!empty($userData['health_plan_weeks'])) {
                $block .= "Срок плана: {$userData['health_plan_weeks']} недель\n";
            }
            break;
    }
    
    return $block;
}

function buildStartDateBlock($startDate, $suggestedWeeks) {
    $block = "";
    if ($startDate) {
        $block .= "\nДата начала тренировок: {$startDate}\n";
        $block .= "Первая неделя плана — та, в которую попадает эта дата (понедельник этой недели = начало недели 1).\n";
    }
    if ($suggestedWeeks !== null) {
        $block .= "Количество недель плана: {$suggestedWeeks}. Сформируй ровно столько недель.\n";
    }
    return $block;
    }

function buildPreferencesBlock($userData) {
    $block = "\n═══ ПРЕДПОЧТЕНИЯ ═══\n\n";
    
    if (!empty($userData['preferred_days']) && is_array($userData['preferred_days'])) {
        $dayLabels = [
            'mon' => 'Понедельник', 'tue' => 'Вторник', 'wed' => 'Среда',
            'thu' => 'Четверг', 'fri' => 'Пятница', 'sat' => 'Суббота', 'sun' => 'Воскресенье'
        ];
        $days = array_map(function($day) use ($dayLabels) {
            return $dayLabels[$day] ?? $day;
        }, $userData['preferred_days']);
        $block .= "Предпочитаемые дни для бега: " . implode(', ', $days) . "\n";
    }
    
    if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
        $dayLabels = [
            'mon' => 'Понедельник', 'tue' => 'Вторник', 'wed' => 'Среда',
            'thu' => 'Четверг', 'fri' => 'Пятница', 'sat' => 'Суббота', 'sun' => 'Воскресенье'
        ];
        $days = array_map(function($day) use ($dayLabels) {
            return $dayLabels[$day] ?? $day;
        }, $userData['preferred_ofp_days']);
        $block .= "Предпочитаемые дни для ОФП: " . implode(', ', $days) . "\n";
    } else {
        $block .= "Пользователь не планирует делать ОФП (выбрал «нет»). В плане не должно быть тренировок типа ОФП (type: other).\n";
    }
    
    if (!empty($userData['training_time_pref'])) {
        $timeMap = ['morning' => 'Утро', 'day' => 'День', 'evening' => 'Вечер'];
        $time = $timeMap[$userData['training_time_pref']] ?? $userData['training_time_pref'];
        $block .= "Предпочитаемое время тренировок: {$time}\n";
    }
    
    if (!empty($userData['has_treadmill'])) {
        $block .= "Есть доступ к беговой дорожке: Да\n";
    }
    
    if (!empty($userData['ofp_preference'])) {
        $ofpMap = [
            'gym' => 'В тренажерном зале', 'home' => 'Дома самостоятельно',
            'both' => 'И в зале, и дома', 'group_classes' => 'Групповые занятия',
            'online' => 'Онлайн-платформы'
        ];
        $ofp = $ofpMap[$userData['ofp_preference']] ?? $userData['ofp_preference'];
        $block .= "Где удобно делать ОФП: {$ofp}\n";
    }
    
    if (!empty($userData['health_notes'])) {
        $block .= "\nОграничения по здоровью: {$userData['health_notes']}\n";
    }

    return $block;
}

function buildPaceZonesBlock($userData) {
    $zones = calculatePaceZones($userData);
    if (!$zones) return "";

    $block = "\n═══ ТРЕНИРОВОЧНЫЕ ЗОНЫ (используй в полях pace / interval_pace) ═══\n\n";
    $block .= "Лёгкий бег (easy): " . formatPace($zones['easy']) . " /км — основной объём, разговорный темп, RPE 3-4\n";
    $block .= "Длительная (long): " . formatPace($zones['long']) . " /км — немного медленнее лёгкого, RPE 3-4\n";
    $block .= "Темповый (tempo): " . formatPace($zones['tempo']) . " /км — комфортно-тяжело, RPE 6-7\n";
    $block .= "Интервальный (interval): " . formatPace($zones['interval']) . " /км — тяжело, RPE 8-9, для отрезков 400м-2км\n";
    $block .= "Восстановительная трусца (между интервалами): " . formatPace($zones['recovery']) . " /км\n";
    $block .= "\nВАЖНО: Подставляй эти темпы в поля pace (для easy/long/tempo) и interval_pace (для interval). Не придумывай другие темпы.\n";

    return $block;
}

function buildTrainingPrinciplesBlock($userData, $goalType) {
    $block = "\n═══ ПРИНЦИПЫ И СТРУКТУРА ПЛАНА ═══\n\n";

    $expLevel = $userData['experience_level'] ?? 'novice';
    $weeklyKm = !empty($userData['weekly_base_km']) ? (float) $userData['weekly_base_km'] : 0;
    $isNovice = in_array($expLevel, ['novice', 'beginner']);
    $zones = calculatePaceZones($userData);

    switch ($goalType) {
        case 'health':
            $program = $userData['health_program'] ?? '';
            $block .= "Цель: здоровье и регулярная активность.\n\n";
            if ($program === 'start_running') {
                $block .= "Программа «Начни бегать» (8 недель):\n";
                $block .= "- 3 беговых дня, между ними — отдых. Темп не указывать — только по ощущениям (RPE 3-4, можно разговаривать).\n";
                $block .= "- Недели 1-2: бег 1 мин / ходьба 2 мин, повторить 8 раз (24 мин).\n";
                $block .= "- Недели 3-4: бег 3 мин / ходьба 2 мин × 5 (25 мин).\n";
                $block .= "- Недели 5-6: бег 5 мин / ходьба 1 мин × 4 (24 мин).\n";
                $block .= "- Недели 7-8: непрерывный бег 15-20 мин.\n";
                $block .= "- Каждая тренировка начинается с 5 мин ходьбы (разминка). Тип: easy. distance_km: null. duration_minutes: суммарное время. notes: краткое описание интервалов бег/ходьба.\n";
            } elseif ($program === 'couch_to_5k') {
                $block .= "Программа «С дивана до 5 км» (10 недель):\n";
                $block .= "- 3 тренировки в неделю. Темп не указывать — только по ощущениям.\n";
                $block .= "- Недели 1-2: бег 1-1.5 мин / ходьба 2 мин, 8-10 повторов.\n";
                $block .= "- Недели 3-4: бег 3-5 мин / ходьба 1.5-3 мин.\n";
                $block .= "- Недели 5-6: непрерывный бег 20 мин.\n";
                $block .= "- Недели 7-8: бег 25 мин.\n";
                $block .= "- Недели 9-10: бег 30 мин (≈5 км).\n";
                $block .= "- Тип: easy. distance_km: null. duration_minutes: суммарное время (обязательно!). notes: краткое описание интервалов бег/ходьба.\n";
            } elseif ($program === 'regular_running') {
                $block .= "Программа «Регулярный бег» (12 недель):\n";
                $block .= "- 3-4 лёгких пробежки в неделю. Одна чуть длиннее (длительная).\n";
                $block .= "- Старт: " . ($weeklyKm > 0 ? "{$weeklyKm} км/нед" : "10-15 км/нед") . ", прирост до 10% в неделю.\n";
                $block .= "- Все пробежки в лёгком темпе (разговорный, RPE 3-4).\n";
                $block .= "- Неделя 4, 8 — разгрузочные (объём -20%).\n";
            } else {
                $block .= "Свой план:\n";
                $block .= "- 3-4 беговых дня, прогрессия плавная (до 10%/нед).\n";
                $runLevel = $userData['current_running_level'] ?? '';
                if ($runLevel === 'zero') {
                    $block .= "- Начинающий с нуля: старт с чередования бег/ходьба, как «Начни бегать».\n";
                } elseif ($runLevel === 'basic') {
                    $block .= "- Бегает с трудом: короткие отрезки 2-3 км, акцент на регулярность, не на объём.\n";
                } else {
                    $block .= "- Бегает комфортно: непрерывный бег, можно добавить одну длительную.\n";
                }
            }
            if ($isNovice) {
                $block .= "\nДля начинающих: НЕ указывай темп в мин/км — только по ощущениям. Писать «в комфортном темпе» или «темп разговорный».\n";
            }
            break;

        case 'race':
        case 'time_improvement':
            $goalLabel = $goalType === 'race' ? 'подготовка к забегу' : 'улучшение результата';
            $block .= "Цель: {$goalLabel}.\n\n";

            $mc = computeMacrocycle($userData, $goalType);
            if ($mc) {
                $block .= formatMacrocyclePrompt($mc);
            } else {
                $dist = $userData['race_distance'] ?? '';
            $totalWeeks = getSuggestedPlanWeeks($userData, $goalType);
                $block .= "План на " . ($totalWeeks ?: '?') . " недель. Стандартная периодизация: базовый → развивающий → подводка.\n";
                $block .= "80% объёма в лёгком темпе, до 20% — ключевые тренировки.\n\n";
            }

            if ($zones) {
                $block .= "Используй рассчитанные зоны: interval_pace=\"" . formatPace($zones['interval']) . "\", темповый pace=\"" . formatPace($zones['tempo']) . "\".\n\n";
            }
            break;

        case 'weight_loss':
            $block .= "Цель: снижение веса к указанной дате.\n\n";
            $block .= "ПРИНЦИПЫ:\n";
            $block .= "- 3-4 беговых дня в неделю. Весь бег — в лёгком темпе (жиросжигание в аэробной зоне).\n";
            $block .= "- 1 длительная пробежка в неделю (30-60 мин) — основной инструмент.\n";
            $block .= "- 1 тренировка с ускорениями (фартлек или короткие интервалы) для метаболизма, но не обязательно в первые 2-3 недели.\n";
            $block .= "- Прирост объёма: до 10%/нед. Старт с текущего объёма" . ($weeklyKm > 0 ? " ({$weeklyKm} км/нед)" : "") . ".\n";
            if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
                $block .= "- ОФП 2 раза в неделю (в указанные дни): круговые тренировки, акцент на крупные мышечные группы для сохранения мышечной массы.\n";
            }
            $block .= "- Безопасная скорость: не более 0.5-1 кг/нед. Питание — вне плана, но тренировки оптимизированы для дефицита калорий.\n";
            if ($isNovice) {
                $block .= "- Начинающий: старт с бег/ходьба, акцент на регулярность и длительность (время), а не скорость.\n";
            }
            break;

        default:
            $block .= "Общие принципы: прогрессия нагрузки, чередование нагрузки и отдыха, разнообразие (лёгкий бег, длительная, при необходимости темп/интервалы).\n";
    }

    $block .= "\nРАЗГРУЗОЧНАЯ НЕДЕЛЯ (каждые 3-4 недели):\n";
    $block .= "- Объём снижен на 20-30% от предыдущей недели.\n";
    $block .= "- Все тренировки — лёгкий бег. Убрать интервалы и темповые. Длительную сократить на 30%.\n";
    $block .= "- Если есть ОФП — оставить, но облегчить (меньше подходов).\n\n";

    $block .= "ОБЩИЕ ПРАВИЛА:\n";
    $block .= "- Прирост недельного км: не более 10%.\n";
    $block .= "- 80% объёма — лёгкий бег, до 20% — ключевые тренировки (принцип 80/20).\n";
    $block .= "- Длительная — в конце недели (суббота/воскресенье), если пользователь выбрал эти дни.\n";

    return $block;
}

function buildKeyWorkoutsBlock($userData) {
    $block = "\n═══ КЛЮЧЕВЫЕ ТРЕНИРОВКИ (is_key_workout) ═══\n\n";
    $block .= "Ключевая тренировка — та, которая даёт основной тренировочный стимул в неделе. Именно эти тренировки продвигают бегуна к цели, всё остальное (лёгкий бег, отдых) — поддержка восстановления между ними.\n\n";

    $block .= "ТИПЫ КЛЮЧЕВЫХ ТРЕНИРОВОК:\n";
    $block .= "- Темповый бег (tempo) — развивает лактатный порог, учит тело работать на высокой интенсивности дольше.\n";
    $block .= "- Интервалы (interval) — развивают МПК (VO2max), скорость и экономичность бега.\n";
    $block .= "- Фартлек (fartlek) — развивает умение переключать темп, скоростную выносливость. Структурированный фартлек — ключевая, лёгкий игровой — нет.\n";
    $block .= "- Длительная (long) — развивает аэробную базу, жировой обмен, ментальную выносливость. ЭТО ТОЖЕ КЛЮЧЕВАЯ ТРЕНИРОВКА.\n";
    $block .= "- Забег (race) — пиковая нагрузка, всегда ключевая.\n\n";

    $block .= "НЕ ЯВЛЯЮТСЯ КЛЮЧЕВЫМИ:\n";
    $block .= "- Лёгкий бег (easy) — восстановительный бег.\n";
    $block .= "- ОФП (other), СБУ (sbu) — вспомогательные.\n";
    $block .= "- Отдых (rest).\n\n";

    $block .= "ПРАВИЛА РАССТАНОВКИ:\n";
    $sessions = (int)($userData['sessions_per_week'] ?? 3);
    if ($sessions <= 3) {
        $block .= "- При {$sessions} тренировках в неделю: 1-2 ключевые (длительная + 1 интенсивная в интенсивном периоде).\n";
    } elseif ($sessions <= 5) {
        $block .= "- При {$sessions} тренировках в неделю: 2-3 ключевые (длительная + 1-2 интенсивные).\n";
    } else {
        $block .= "- При {$sessions} тренировках в неделю: 2-3 ключевые (длительная + 1-2 интенсивные), остальное — лёгкий бег.\n";
    }
    $block .= "- Между двумя ключевыми — минимум 1 день лёгкого бега или отдыха. НИКОГДА две ключевые подряд.\n";
    $block .= "- В разгрузочную неделю — 0-1 ключевая (только сокращённая длительная), убрать интенсивность.\n";
    $block .= "- В базовый период — только длительная как ключевая, без темпа/интервалов.\n";
    $block .= "- В подводку — сохранять 1-2 короткие интенсивные, но со сниженным объёмом.\n\n";

    $block .= "Для каждого дня ставь поле \"is_key_workout\": true/false. Это важно для визуального выделения в приложении.\n";

    return $block;
}

function buildMandatoryRulesBlock($userData) {
    $block = "\n═══ ОБЯЗАТЕЛЬНЫЕ ПРАВИЛА (соблюдай строго) ═══\n\n";
    $block .= "1. Расписание по дням:\n";
    if (!empty($userData['preferred_days']) && is_array($userData['preferred_days'])) {
        $daysList = implode(', ', $userData['preferred_days']);
        $block .= "   — Беговые тренировки ставить ТОЛЬКО в эти дни недели: {$daysList}. В остальные дни — отдых (rest)" . (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days']) ? " или ОФП в указанные дни" : "") . ".\n";
    } else {
        $block .= "   — Количество беговых дней в неделю: " . ($userData['sessions_per_week'] ?? 3) . ". Остальные дни — rest" . (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days']) ? " или ОФП по предпочтениям" : "") . ".\n";
    }
    if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
        $ofpList = implode(', ', $userData['preferred_ofp_days']);
        $block .= "   — ОФП (type: other) ставить ТОЛЬКО в эти дни: {$ofpList}.\n";
    } else {
        $block .= "   — ОФП в плане не включать (пользователь выбрал «не делать ОФП»). Дни без бега — только type: rest.\n";
    }
    $block .= "2. Объём и сложность — по уровню подготовки и weekly_base_km. ВАЖНО: для подготовки к забегу длительная должна достигать указанного минимума (см. ПИКОВАЯ ДЛИТЕЛЬНАЯ выше). Не занижай нагрузку — план должен РЕАЛЬНО подготовить к дистанции.\n";
    $block .= "3. Даты НЕ нужны — код вычислит их автоматически из start_date и номера недели.\n";
    $block .= "4. В каждой неделе ровно 7 дней: порядок понедельник (индекс 0), вторник (1), …, воскресенье (6). День без тренировки — type: \"rest\", все поля null.\n\n";

    return $block;
}

function buildFormatResponseBlock() {
    $block = "═══ ФОРМАТ ОТВЕТА (только этот JSON) ═══\n\n";
    $block .= "Верни один JSON-объект с ключом \"weeks\". В каждой неделе массив \"days\" из ровно 7 элементов (пн … вс).\n";
    $block .= "Тип дня (type): easy, long, tempo, interval, fartlek, control, rest, other (ОФП), sbu, race, free.\n\n";

    $block .= "КРИТИЧНО: Каждый день — объект со ВСЕМИ полями ниже. Неиспользуемые = null. Пропуск полей запрещён!\n\n";

    $block .= "ПОЛНЫЙ ШАБЛОН ДНЯ (все поля):\n";
    $block .= "{\n";
    $block .= "  \"type\": \"...\",\n";
    $block .= "  \"distance_km\": null,\n";
    $block .= "  \"pace\": null,\n";
    $block .= "  \"warmup_km\": null,\n";
    $block .= "  \"cooldown_km\": null,\n";
    $block .= "  \"reps\": null,\n";
    $block .= "  \"interval_m\": null,\n";
    $block .= "  \"interval_pace\": null,\n";
    $block .= "  \"rest_m\": null,\n";
    $block .= "  \"rest_type\": null,\n";
    $block .= "  \"segments\": null,\n";
    $block .= "  \"exercises\": null,\n";
    $block .= "  \"duration_minutes\": null,\n";
    $block .= "  \"notes\": null,\n";
    $block .= "  \"is_key_workout\": false\n";
    $block .= "}\n\n";

    $block .= "ПРИМЕРЫ ПО ТИПАМ (все поля всегда присутствуют):\n\n";

    $block .= "1) Лёгкий бег (easy):\n";
    $block .= "{\"type\":\"easy\",\"distance_km\":8,\"pace\":\"6:00\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $block .= "2) Длительная (long):\n";
    $block .= "{\"type\":\"long\",\"distance_km\":15,\"pace\":\"6:30\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "3) Темповый (tempo):\n";
    $block .= "{\"type\":\"tempo\",\"distance_km\":6,\"pace\":\"5:00\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "4) Контрольный забег (control) — pace всегда null:\n";
    $block .= "{\"type\":\"control\",\"distance_km\":3,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "5) Забег (race):\n";
    $block .= "{\"type\":\"race\",\"distance_km\":21.1,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "6) Интервалы (interval):\n";
    $block .= "{\"type\":\"interval\",\"distance_km\":null,\"pace\":null,\"warmup_km\":2,\"cooldown_km\":1.5,\"reps\":5,\"interval_m\":1000,\"interval_pace\":\"4:20\",\"rest_m\":400,\"rest_type\":\"jog\",\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "7) Фартлек (fartlek):\n";
    $block .= "{\"type\":\"fartlek\",\"distance_km\":null,\"pace\":null,\"warmup_km\":2,\"cooldown_km\":1.5,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":[{\"reps\":6,\"distance_m\":200,\"pace\":\"4:30\",\"recovery_m\":200,\"recovery_type\":\"jog\"}],\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "8) ОФП (other):\n";
    $block .= "{\"type\":\"other\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":[{\"name\":\"Приседания\",\"sets\":3,\"reps\":10,\"weight_kg\":20,\"distance_m\":null,\"duration_min\":null},{\"name\":\"Планка\",\"sets\":null,\"reps\":null,\"weight_kg\":null,\"distance_m\":null,\"duration_min\":1}],\"duration_minutes\":30,\"notes\":null,\"is_key_workout\":false}\n\n";

    $block .= "9) СБУ (sbu):\n";
    $block .= "{\"type\":\"sbu\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":[{\"name\":\"Бег с высоким подниманием бедра\",\"sets\":null,\"reps\":null,\"weight_kg\":null,\"distance_m\":30,\"duration_min\":null}],\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $block .= "10) Отдых (rest):\n";
    $block .= "{\"type\":\"rest\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $block .= "11) Свободный день (free):\n";
    $block .= "{\"type\":\"free\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $block .= "ПРАВИЛА:\n";
    $block .= "- НЕ генерируй поле \"description\" — код построит его автоматически из структурированных полей.\n";
    $block .= "- distance_km для interval и fartlek: null (код посчитает из warmup + reps × distance + cooldown).\n";
    $block .= "- duration_minutes для interval и fartlek: null (код посчитает).\n";
    $block .= "- Дата НЕ нужна — рассчитается автоматически.\n";
    $block .= "- Не добавляй комментарии и текст вне JSON. Ответ должен начинаться с { и заканчиваться }.\n";

    return $block;
}

// ════════════════════════════════════════════════════════════════
// 1. ПЕРВИЧНАЯ ГЕНЕРАЦИЯ ПЛАНА (новый пользователь, план с нуля)
// ════════════════════════════════════════════════════════════════

/**
 * Промпт для создания НОВОГО плана тренировок с нуля.
 * Используется при первой регистрации / специализации.
 */
function buildTrainingPlanPrompt($userData, $goalType = 'health') {
    $prompt = "";

    $prompt .= "Ты опытный тренер по бегу. Строй план по данным пользователя и научно обоснованным принципам: прогрессия нагрузки, восстановление, периодизация (где уместно), распределение интенсивности.\n";
    $prompt .= "Отвечай ТОЛЬКО валидным JSON без комментариев и лишнего текста. Все решения опирай на указанные ниже данные пользователя и на принципы для выбранной цели.\n\n";

    $prompt .= buildUserInfoBlock($userData);
    $prompt .= buildGoalBlock($userData, $goalType);

    $startDate = $userData['training_start_date'] ?? null;
    $suggestedWeeks = getSuggestedPlanWeeks($userData, $goalType);
    $prompt .= buildStartDateBlock($startDate, $suggestedWeeks);

    $prompt .= buildPreferencesBlock($userData);
    $prompt .= buildPaceZonesBlock($userData);
    $prompt .= buildTrainingPrinciplesBlock($userData, $goalType);
    $prompt .= buildKeyWorkoutsBlock($userData);
    $prompt .= buildMandatoryRulesBlock($userData);

    $prompt .= "═══ ЗАДАЧА ═══\n\n";
    $prompt .= "Сформируй персональный план по данным пользователя и по блоку «ПРИНЦИПЫ И СТРУКТУРА ПЛАНА» выше. Учитывай предпочтения по дням, объём (weekly_base_km, sessions_per_week), уровень подготовки и ограничения по здоровью.\n";
    $prompt .= "Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.) — все поля в каждом дне, неиспользуемые = null.\n";
    $prompt .= "НЕ генерируй поле \"description\" — код построит его автоматически. НЕ считай distance_km и duration_minutes для интервалов/фартлеков — код посчитает.\n\n";

    $prompt .= buildFormatResponseBlock();

    return $prompt;
}

// ════════════════════════════════════════════════════════════════
// 2. ПЕРЕСЧЁТ ПЛАНА (коррекция текущего, середина цикла)
// ════════════════════════════════════════════════════════════════

/**
 * Промпт для ПЕРЕСЧЁТА текущего плана.
 * Сохранённые недели остаются, генерируется только продолжение.
 * Учитывает историю тренировок, detraining, текущую форму.
 *
 * НЕЗАВИСИМЫЙ промпт — НЕ вызывает buildTrainingPlanPrompt().
 */
function buildRecalculationPrompt($userData, $goalType, array $recalcContext) {
    $origStartDate = $userData['training_start_date'] ?? null;
    $newStartDate = $recalcContext['new_start_date'] ?? date('Y-m-d', strtotime('monday this week'));
    $keptWeeks = $recalcContext['kept_weeks'] ?? 0;
    $weeksToGenerate = $recalcContext['weeks_to_generate'] ?? null;

    $modifiedUser = $userData;
    $modifiedUser['training_start_date'] = $newStartDate;
    if ($weeksToGenerate !== null) {
        $modifiedUser['health_plan_weeks'] = $weeksToGenerate;
    }

    $prompt = "";

    // ── Роль: с первых строк ясно, что это ПЕРЕСЧЁТ ──
    $prompt .= "Ты опытный тренер по бегу. Твоя задача — ПЕРЕСЧИТАТЬ существующий план тренировок.\n";
    $prompt .= "Пользователь УЖЕ тренируется по плану. Первые {$keptWeeks} недель СОХРАНЕНЫ — ты генерируешь только ПРОДОЛЖЕНИЕ (оставшуюся часть).\n";
    $prompt .= "Это НЕ новый план с нуля. Ты должен учесть реальное текущее состояние бегуна: его объёмы, темпы, самочувствие, паузы.\n";
    $prompt .= "Отвечай ТОЛЬКО валидным JSON без комментариев и лишнего текста.\n\n";

    // ── Данные пользователя ──
    $prompt .= buildUserInfoBlock($modifiedUser);
    $prompt .= buildGoalBlock($modifiedUser, $goalType);

    $suggestedWeeks = $weeksToGenerate;
    $prompt .= buildStartDateBlock($newStartDate, $suggestedWeeks);

    $prompt .= buildPreferencesBlock($modifiedUser);
    $prompt .= buildPaceZonesBlock($modifiedUser);

    // ── Текущее состояние (КЛЮЧЕВОЙ блок для пересчёта) ──
    $prompt .= "\n" . buildRecalcContextBlock($recalcContext, $origStartDate);

    // ── Принципы тренировок (типы, структура) ──
    $prompt .= buildTrainingPrinciplesBlock($modifiedUser, $goalType);
    $prompt .= buildKeyWorkoutsBlock($modifiedUser);
    $prompt .= buildMandatoryRulesBlock($modifiedUser);

    // ── Задача: пересчёт-специфичная ──
    $prompt .= "═══ ЗАДАЧА: ПЕРЕСЧЁТ ПЛАНА ═══\n\n";
    $prompt .= "Это КОРРЕКЦИЯ существующего плана, а не генерация с нуля.\n";
    $prompt .= "Первые {$keptWeeks} недель плана СОХРАНЕНЫ — ты генерируешь только ПРОДОЛЖЕНИЕ.\n";
    if ($weeksToGenerate !== null) {
        $prompt .= "Сгенерируй ровно {$weeksToGenerate} недель (нумерация week_number от 1 до {$weeksToGenerate}).\n";
    }
    $prompt .= "Дата начала первой генерируемой недели: {$newStartDate}.\n\n";
    $prompt .= "Учитывай ТЕКУЩЕЕ СОСТОЯНИЕ из блока выше: реальные объёмы, темпы, самочувствие.\n\n";
    $prompt .= "ПРИНЦИПЫ ПЕРЕСЧЁТА:\n";
    $prompt .= "1. Первые 1-2 недели — плавный возврат к нагрузке:\n";
    $prompt .= "   - Начальный объём = средний реальный объём за последние 4 недели × коэффициент формы.\n";
    $prompt .= "   - Первая неделя — только лёгкий бег (easy) и длительная (сокращённая).\n";
    $prompt .= "   - Вторая неделя — можно вернуть 1 ключевую тренировку (облегчённую).\n";
    $prompt .= "2. Далее — стандартная прогрессия (до +10%/нед), возвращение к обычной структуре.\n";
    $prompt .= "3. Если до забега мало времени — сжать фазы, но НЕ форсировать нагрузку.\n";
    $prompt .= "4. Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.).\n";
    $prompt .= "5. НЕ генерируй \"description\" — код построит автоматически.\n\n";

    // ── Формат ──
    $prompt .= buildFormatResponseBlock();

    return $prompt;
}

/**
 * Формирует блок «ТЕКУЩЕЕ СОСТОЯНИЕ» для промпта пересчёта.
 */
function buildRecalcContextBlock(array $ctx, ?string $origStartDate): string {
    $lines = ["═══ ТЕКУЩЕЕ СОСТОЯНИЕ (для пересчёта) ═══\n"];
    $lines[] = "Это пересчёт существующего плана. Пользователь уже тренировался, ниже — реальные данные.\n";

    $daysSince = $ctx['days_since_last_workout'] ?? null;
    if ($daysSince !== null) {
        $lines[] = "Дней с последней тренировки: {$daysSince}";
    }

    $factor = $ctx['detraining_factor'] ?? null;
    if ($factor !== null) {
        $pct = (int) round($factor * 100);
        $lines[] = "Оценка текущей формы: ~{$pct}% от пиковой";
        if ($pct >= 95) {
            $lines[] = "→ Пауза минимальная, можно продолжать почти с того же объёма.";
        } elseif ($pct >= 85) {
            $lines[] = "→ Лёгкое снижение формы. Первая неделя — на 10-15% ниже последнего реального объёма, потом возврат.";
        } elseif ($pct >= 75) {
            $lines[] = "→ Заметная потеря формы. Первые 2 недели — плавный возврат, начать с 70-80% от последнего реального объёма.";
        } else {
            $lines[] = "→ Серьёзная потеря формы. Нужен мягкий старт: первые 2-3 недели как базовый период, лёгкий бег, постепенное наращивание.";
        }
    }

    $compliance = $ctx['compliance_2w'] ?? null;
    if ($compliance) {
        $lines[] = "\nВыполнение плана за 2 недели: {$compliance['completed']}/{$compliance['planned']} (" . ($compliance['pct'] ?? 0) . "%)";
    }

    $avgKm = $ctx['avg_weekly_km_4w'] ?? null;
    if ($avgKm !== null && $avgKm > 0) {
        $lines[] = "Средний реальный объём за 4 недели: {$avgKm} км/нед (НАЧИНАЙ ПЕРВУЮ НЕДЕЛЮ ОТ ЭТОГО ЗНАЧЕНИЯ × коэффициент формы, не от weekly_base_km из профиля)";
    } elseif ($avgKm !== null) {
        $lines[] = "За последние 4 недели тренировок НЕ БЫЛО. Используй weekly_base_km из профиля как ориентир, но начни с 50-60% от этого объёма.";
    }

    $avgPace = $ctx['avg_pace_4w'] ?? null;
    if ($avgPace !== null) {
        $lines[] = "Средний реальный темп за 4 недели: {$avgPace} /км";
    }

    $avgHr = $ctx['avg_hr_4w'] ?? null;
    if ($avgHr !== null && $avgHr > 0) {
        $lines[] = "Средний пульс за 4 недели: {$avgHr} уд/мин";
    }

    $avgRating = $ctx['avg_rating_4w'] ?? null;
    if ($avgRating !== null) {
        $ratingLabel = $avgRating >= 4 ? 'хорошо' : ($avgRating >= 3 ? 'нормально' : 'тяжело');
        $lines[] = "Среднее самочувствие: {$avgRating}/5 ({$ratingLabel})";
    }

    $keptWeeks = $ctx['kept_weeks'] ?? 0;
    $weeksToGen = $ctx['weeks_to_generate'] ?? null;
    if ($keptWeeks > 0 || $weeksToGen !== null) {
        $lines[] = "\nПлан: первые {$keptWeeks} нед. сохранены, генерировать {$weeksToGen} новых.";
    }

    $weeksRemaining = $ctx['weeks_remaining_to_goal'] ?? null;
    if ($weeksRemaining !== null) {
        $lines[] = "Недель до цели (забега/дедлайна): {$weeksRemaining}";
        if ($weeksRemaining <= 4) {
            $lines[] = "⚠ МАЛО ВРЕМЕНИ — сжатый план, без форсирования. Приоритет: поддержание формы, подводка.";
        } elseif ($weeksRemaining <= 8) {
            $lines[] = "Умеренный запас времени — можно наверстать, но аккуратно.";
        }
    }

    $recent = $ctx['recent_workouts'] ?? [];
    if (!empty($recent)) {
        $lines[] = "\nПоследние тренировки (факт):";
        $shown = 0;
        foreach ($recent as $w) {
            if ($shown >= 8) break;
            $date = $w['date'] ?? '';
            $type = $w['plan_type'] ?? 'тренировка';
            $dist = !empty($w['distance_km']) ? "{$w['distance_km']} км" : '';
            $pace = !empty($w['pace']) && $w['pace'] !== '0:00' ? "темп {$w['pace']}" : '';
            $rating = !empty($w['rating']) ? "ощущение {$w['rating']}/5" : '';
            $parts = array_filter([$date, $type, $dist, $pace, $rating]);
            $lines[] = "  - " . implode(', ', $parts);
            $shown++;
        }
    }

    $userReason = $ctx['user_reason'] ?? null;
    if ($userReason !== null && $userReason !== '') {
        $lines[] = "\n═══ ПРИЧИНА ПЕРЕСЧЁТА (от пользователя) ═══\n";
        $lines[] = $userReason;
        $lines[] = "\nУЧТИ ЭТУ ПРИЧИНУ при составлении плана. Если пользователь упоминает травму — исключи нагрузку на проблемную зону, снизь интенсивность, добавь восстановительные тренировки. Если устал — начни мягче. Если хочет больше — можно прибавить, но не более +10%/нед.\n";
    }

    if ($origStartDate) {
        $lines[] = "\nОригинальная дата старта плана: {$origStartDate}";
    }
    $lines[] = "Новая дата старта плана: {$ctx['new_start_date']}";
    $lines[] = "Первая неделя нового плана — от этого понедельника. Нумерация недель начинается с 1.\n";

    return implode("\n", $lines);
}

// ════════════════════════════════════════════════════════════════
// 3. НОВЫЙ ПЛАН ПОСЛЕ ЗАВЕРШЕНИЯ ПРЕДЫДУЩЕГО (продолжение цикла)
// ════════════════════════════════════════════════════════════════

/**
 * Промпт для генерации НОВОГО плана после завершения предыдущего.
 * Учитывает полную историю достижений предыдущего цикла.
 *
 * НЕЗАВИСИМЫЙ промпт — НЕ вызывает buildTrainingPlanPrompt().
 */
function buildNextPlanPrompt($userData, $goalType, array $nextPlanContext) {
    $newStartDate = $nextPlanContext['new_start_date'] ?? date('Y-m-d', strtotime('monday this week'));
    $newPlanWeeks = $nextPlanContext['new_plan_weeks'] ?? 12;

    $modifiedUser = $userData;
    $modifiedUser['training_start_date'] = $newStartDate;
    $modifiedUser['health_plan_weeks'] = $newPlanWeeks;

    $prompt = "";

    // ── Роль: с первых строк ясно, что это НОВЫЙ ПЛАН после завершённого ──
    $prompt .= "Ты опытный тренер по бегу. Твоя задача — построить НОВЫЙ план тренировок для бегуна, который ЗАВЕРШИЛ предыдущий тренировочный цикл.\n";
    $prompt .= "Это НЕ план для новичка — у пользователя есть серьёзная тренировочная база из предыдущего плана.\n";
    $prompt .= "Стартовый объём и темпы определяй по РЕАЛЬНЫМ данным из блока «ИСТОРИЯ ПРЕДЫДУЩЕГО ПЛАНА», а НЕ по полю weekly_base_km из профиля.\n";
    $prompt .= "Отвечай ТОЛЬКО валидным JSON без комментариев и лишнего текста.\n\n";

    // ── Данные пользователя ──
    $prompt .= buildUserInfoBlock($modifiedUser);
    $prompt .= buildGoalBlock($modifiedUser, $goalType);
    $prompt .= buildStartDateBlock($newStartDate, $newPlanWeeks);
    $prompt .= buildPreferencesBlock($modifiedUser);
    $prompt .= buildPaceZonesBlock($modifiedUser);

    // ── История предыдущего плана (КЛЮЧЕВОЙ блок для нового плана) ──
    $prompt .= "\n" . buildPreviousPlanHistoryBlock($nextPlanContext);

    // ── Принципы тренировок (типы, структура) ──
    $prompt .= buildTrainingPrinciplesBlock($modifiedUser, $goalType);
    $prompt .= buildKeyWorkoutsBlock($modifiedUser);
    $prompt .= buildMandatoryRulesBlock($modifiedUser);

    // ── Задача: новый-план-специфичная ──
    $prompt .= "═══ ЗАДАЧА: НОВЫЙ ПЛАН (ПРОДОЛЖЕНИЕ ТРЕНИРОВОЧНОГО ЦИКЛА) ═══\n\n";
    $prompt .= "Пользователь ЗАВЕРШИЛ предыдущий план и хочет новый.\n";
    $prompt .= "Сгенерируй ровно {$newPlanWeeks} недель (нумерация week_number от 1 до {$newPlanWeeks}).\n";
    $prompt .= "Дата начала первой недели: {$newStartDate}.\n\n";
    $prompt .= "ПРИНЦИПЫ НОВОГО ПЛАНА:\n";
    $prompt .= "1. СТАРТОВЫЙ ОБЪЁМ = средний объём за последние 4 недели предыдущего плана (см. блок ИСТОРИЯ).\n";
    $prompt .= "   НЕ начинай с нуля и НЕ начинай с weekly_base_km из профиля — начинай с РЕАЛЬНОГО текущего уровня.\n";
    $prompt .= "2. Первая неделя — разгрузочная (recovery): 80-85% от последнего реального объёма.\n";
    $prompt .= "   Это переходный микроцикл между планами.\n";
    $prompt .= "3. Со 2-й недели — стандартная прогрессия (+5-10% в неделю), с разгрузочными неделями каждую 4-ю.\n";
    $prompt .= "4. Если пиковый объём предыдущего плана > weekly_base_km, обнови ориентир на пиковый.\n";
    $prompt .= "5. Учти лучшие результаты ключевых тренировок для выбора темпов.\n";
    $prompt .= "6. Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.).\n";
    $prompt .= "7. НЕ генерируй \"description\" — код построит автоматически.\n\n";

    // ── Формат ──
    $prompt .= buildFormatResponseBlock();

    return $prompt;
}

/**
 * Формирует блок «ИСТОРИЯ ПРЕДЫДУЩЕГО ПЛАНА» для промпта нового плана.
 */
function buildPreviousPlanHistoryBlock(array $ctx): string {
    $lines = ["═══ ИСТОРИЯ ПРЕДЫДУЩЕГО ПЛАНА ═══\n"];
    $lines[] = "Пользователь завершил предыдущий тренировочный план. Ниже — его реальные достижения.\n";

    $oldStart = $ctx['old_plan_start'] ?? null;
    $oldWeeks = $ctx['old_plan_weeks'] ?? 0;
    if ($oldStart) {
        $lines[] = "Предыдущий план: старт {$oldStart}, длительность {$oldWeeks} нед.";
    }

    $totalWorkouts = $ctx['total_workouts'] ?? 0;
    $totalKm = $ctx['total_distance_km'] ?? 0;
    $lines[] = "Выполнено тренировок: {$totalWorkouts}";
    if ($totalKm > 0) {
        $lines[] = "Суммарный набег: {$totalKm} км";
    }

    $avgKm = $ctx['avg_weekly_km'] ?? 0;
    $peakKm = $ctx['peak_weekly_km'] ?? 0;
    if ($avgKm > 0) {
        $lines[] = "\nСредний недельный объём: {$avgKm} км";
    }
    if ($peakKm > 0) {
        $lines[] = "Пиковый недельный объём: {$peakKm} км";
    }

    $firstQ = $ctx['first_quarter_avg_km'] ?? 0;
    $lastQ = $ctx['last_quarter_avg_km'] ?? 0;
    if ($firstQ > 0 && $lastQ > 0) {
        $growth = $lastQ > $firstQ ? round(($lastQ - $firstQ) / $firstQ * 100) : 0;
        $lines[] = "Прогрессия объёмов: начало плана ~{$firstQ} км/нед → конец ~{$lastQ} км/нед";
        if ($growth > 0) {
            $lines[] = "  Рост за план: +{$growth}%";
        }
    }

    $bestLong = $ctx['best_long_run_km'] ?? 0;
    $bestTempo = $ctx['best_tempo_pace'] ?? null;
    $bestInterval = $ctx['best_interval_pace'] ?? null;
    $avgPace = $ctx['avg_pace'] ?? null;

    if ($bestLong > 0 || $bestTempo || $bestInterval || $avgPace) {
        $lines[] = "\nЛучшие показатели за план:";
        if ($bestLong > 0) {
            $lines[] = "  Длительный бег (макс): {$bestLong} км";
        }
        if ($bestTempo) {
            $lines[] = "  Темповый бег (лучший темп): {$bestTempo} /км";
        }
        if ($bestInterval) {
            $lines[] = "  Интервалы (лучший темп): {$bestInterval} /км";
        }
        if ($avgPace) {
            $lines[] = "  Средний темп за план: {$avgPace} /км";
        }
    }

    $avgHr = $ctx['avg_hr'] ?? null;
    if ($avgHr) {
        $lines[] = "  Средний пульс: {$avgHr} уд/мин";
    }

    $avgRating = $ctx['avg_rating'] ?? null;
    if ($avgRating !== null) {
        $ratingLabel = $avgRating >= 4 ? 'хорошо' : ($avgRating >= 3 ? 'нормально' : 'тяжело');
        $lines[] = "  Среднее самочувствие: {$avgRating}/5 ({$ratingLabel})";
    }

    $compliance = $ctx['compliance'] ?? null;
    if ($compliance && $compliance['planned'] > 0) {
        $lines[] = "\nВыполнение плана (последние 2 нед.): {$compliance['completed']}/{$compliance['planned']} ({$compliance['pct']}%)";
    }

    $keyResults = $ctx['key_workout_results'] ?? [];
    if (!empty($keyResults)) {
        $lines[] = "\nКлючевые тренировки (факт):";
        foreach ($keyResults as $kw) {
            $parts = array_filter([
                $kw['date'],
                $kw['type'],
                !empty($kw['distance_km']) ? "{$kw['distance_km']} км" : '',
                !empty($kw['pace']) ? "темп {$kw['pace']}" : '',
                !empty($kw['rating']) ? "ощущение {$kw['rating']}/5" : '',
            ]);
            $lines[] = "  - " . implode(', ', $parts);
        }
    }

    $recent4wAvgKm = $ctx['recent_4w_avg_km'] ?? 0;
    $recent4wAvgPace = $ctx['recent_4w_avg_pace'] ?? null;
    if ($recent4wAvgKm > 0) {
        $lines[] = "\n═══ ТЕКУЩАЯ ФОРМА (последние 4 недели) ═══";
        $lines[] = "Средний объём: {$recent4wAvgKm} км/нед";
        if ($recent4wAvgPace) {
            $lines[] = "Средний темп: {$recent4wAvgPace} /км";
        }
        $lines[] = "ПЕРВАЯ НЕДЕЛЯ НОВОГО ПЛАНА ДОЛЖНА СТАРТОВАТЬ ОТ ЭТОГО ОБЪЁМА (с лёгким снижением ~15% для recovery).";
    }

    $recent = $ctx['recent_workouts'] ?? [];
    if (!empty($recent)) {
        $lines[] = "\nПоследние тренировки:";
        $shown = 0;
        foreach ($recent as $w) {
            if ($shown >= 8) break;
            $date = $w['date'] ?? '';
            $type = $w['plan_type'] ?? 'тренировка';
            $dist = !empty($w['distance_km']) ? "{$w['distance_km']} км" : '';
            $pace = !empty($w['pace']) && $w['pace'] !== '0:00' ? "темп {$w['pace']}" : '';
            $rating = !empty($w['rating']) ? "ощущение {$w['rating']}/5" : '';
            $parts = array_filter([$date, $type, $dist, $pace, $rating]);
            $lines[] = "  - " . implode(', ', $parts);
            $shown++;
        }
    }

    $userGoals = $ctx['user_goals'] ?? null;
    if ($userGoals !== null && $userGoals !== '') {
        $lines[] = "\n═══ ПОЖЕЛАНИЯ ПОЛЬЗОВАТЕЛЯ К НОВОМУ ПЛАНУ ═══\n";
        $lines[] = $userGoals;
        $lines[] = "\nУЧТИ ЭТИ ПОЖЕЛАНИЯ при построении нового плана.\n";
    }

    $lines[] = "\nДата начала нового плана: {$ctx['new_start_date']}";
    $lines[] = "Количество недель: {$ctx['new_plan_weeks']}";
    $lines[] = "Нумерация недель начинается с 1.\n";

    return implode("\n", $lines);
}
