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

function getPromptWeekdayOrder(): array {
    return ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
}

function getPromptWeekdayPatterns(): array {
    return [
        'mon' => '(?:понедельн\w*|понед\w*|пн\b|monday|mon\b)',
        'tue' => '(?:вторн\w*|вт\b|tuesday|tue\b)',
        'wed' => '(?:сред\w*|ср\b|wednesday|wed\b)',
        'thu' => '(?:четверг\w*|четв\w*|чт\b|thursday|thu\b)',
        'fri' => '(?:пятниц\w*|пт\b|friday|fri\b)',
        'sat' => '(?:суббот\w*|сб\b|saturday|sat\b)',
        'sun' => '(?:воскресен\w*|воскресень\w*|воскр\w*|вс\b|sunday|sun\b)',
    ];
}

function sortPromptWeekdayKeys(array $days): array {
    $order = getPromptWeekdayOrder();
    $valid = [];
    foreach ($days as $day) {
        if (isset($order[$day])) {
            $valid[$day] = true;
        }
    }
    $keys = array_keys($valid);
    usort($keys, fn($a, $b) => $order[$a] <=> $order[$b]);
    return $keys;
}

function getPromptWeekdayLabel(string $day, bool $short = false): string {
    $shortMap = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
    $fullMap = ['mon' => 'Понедельник', 'tue' => 'Вторник', 'wed' => 'Среда', 'thu' => 'Четверг', 'fri' => 'Пятница', 'sat' => 'Суббота', 'sun' => 'Воскресенье'];
    return $short ? ($shortMap[$day] ?? $day) : ($fullMap[$day] ?? $day);
}

/**
 * Определяет предпочтительный день длительной пробежки.
 * Приоритет: выходные (сб/вс) если есть в preferred_days, иначе последний день тренировок.
 */
function getPreferredLongRunDayKey(array $userData): ?string {
    $preferred = $userData['preferred_days'] ?? [];
    if (!is_array($preferred) || empty($preferred)) {
        return null;
    }
    // Сортируем по порядку дней
    $sorted = sortPromptWeekdayKeys($preferred);
    if (empty($sorted)) return null;

    // Предпочитаем выходные для длительной
    foreach (['sun', 'sat'] as $weekend) {
        if (in_array($weekend, $sorted, true)) {
            return $weekend;
        }
    }
    // Если нет выходных — последний тренировочный день
    return end($sorted);
}

function extractScheduleOverridesFromReason(?string $reason): array {
    $reason = trim((string) $reason);
    if ($reason === '') {
        return [];
    }

    $text = mb_strtolower($reason, 'UTF-8');
    $dayPatterns = getPromptWeekdayPatterns();
    $overrides = [];

    // Ищем паттерны типа "длительная в воскресенье", "отдых в понедельник"
    $typeKeywords = [
        'long' => ['длительн\w*', 'длинн\w*', 'лонг\w*', 'long\s*run'],
        'rest' => ['отдых\w*', 'выходн\w*', 'rest'],
        'tempo' => ['темпов\w*', 'tempo'],
        'interval' => ['интервал\w*', 'interval'],
    ];

    foreach ($typeKeywords as $type => $keywords) {
        $keywordPattern = '(?:' . implode('|', $keywords) . ')';
        foreach ($dayPatterns as $dayKey => $dayPattern) {
            // "длительная в воскресенье" или "воскресенье — длительная"
            if (preg_match('/' . $keywordPattern . '[^\n\r,.!?;:]{0,40}' . $dayPattern . '/iu', $text)
                || preg_match('/' . $dayPattern . '[^\n\r,.!?;:]{0,40}' . $keywordPattern . '/iu', $text)) {
                $overrides[$type] = $dayKey;
            }
        }
    }

    return $overrides;
}

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

    // Фиксированные программы для начинающих — длительность определяется программой
    if ($goalType === 'health') {
        if (!empty($userData['health_plan_weeks'])) {
            return (int) $userData['health_plan_weeks'];
        }
        if (!empty($userData['health_program']) && in_array($userData['health_program'], ['start_running', 'couch_to_5k'], true)) {
            $map = ['start_running' => 8, 'couch_to_5k' => 10];
            return $map[$userData['health_program']];
        }
    }

    // Для всех целей: если есть конечная дата — считаем недели из разницы дат
    $endStr = $userData['weight_goal_date']
        ?? $userData['race_date']
        ?? $userData['target_marathon_date']
        ?? null;
    if (!empty($endStr)) {
        $end = strtotime($endStr);
        if ($end > $start) {
            return (int) ceil(($end - $start) / (7 * 86400));
        }
    }

    // Fallback: нет конечной даты — дефолт по типу цели
    $defaults = [
        'health' => 12,
        'weight_loss' => 12,
        'race' => null,
        'time_improvement' => null,
    ];
    return $defaults[$goalType] ?? null;
}

function calculatePaceZones($userData) {
    $trainingState = is_array($userData['training_state'] ?? null) ? $userData['training_state'] : [];
    $paceRules = is_array($trainingState['pace_rules'] ?? null) ? $trainingState['pace_rules'] : [];
    if (!empty($paceRules['easy_min_sec']) && !empty($paceRules['easy_max_sec']) && !empty($paceRules['tempo_sec']) && !empty($paceRules['interval_sec'])) {
        return [
            'easy' => (int) $paceRules['easy_min_sec'],
            'easy_fast' => (int) $paceRules['easy_max_sec'],
            'long' => (int) ($paceRules['long_min_sec'] ?? $paceRules['easy_min_sec']),
            'marathon' => !empty($paceRules['marathon_sec']) ? (int) $paceRules['marathon_sec'] : null,
            'tempo' => (int) $paceRules['tempo_sec'],
            'interval' => (int) $paceRules['interval_sec'],
            'repetition' => !empty($paceRules['repetition_sec']) ? (int) $paceRules['repetition_sec'] : null,
            'recovery' => (int) ($paceRules['recovery_min_sec'] ?? ($paceRules['easy_max_sec'] + 15)),
            'vdot' => isset($trainingState['vdot']) ? round((float) $trainingState['vdot'], 1) : null,
            'source' => 'training_state',
        ];
    }

    // Попытка 1: VDOT-based пасы (точные формулы Daniels)
    $vdot = null;

    // Из последнего результата забега
    $lastDist = $userData['last_race_distance'] ?? null;
    $lastTime = $userData['last_race_time'] ?? null;
    $distKm = ['5k' => 5, '10k' => 10, 'half' => 21.0975, '21.1k' => 21.0975, 'marathon' => 42.195, '42.2k' => 42.195];

    if ($lastDist && $lastTime) {
        $km = $distKm[$lastDist] ?? (!empty($userData['last_race_distance_km']) ? (float)$userData['last_race_distance_km'] : null);
        if ($km) {
            $parts = explode(':', $lastTime);
            $totalSec = 0;
            if (count($parts) === 3) $totalSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
            elseif (count($parts) === 2) $totalSec = (int)$parts[0] * 60 + (int)$parts[1];
            if ($totalSec > 0) $vdot = estimateVDOT($km, $totalSec);
        }
    }

    // Из easy_pace через обратный расчёт (~66% VO2max)
    if (!$vdot && !empty($userData['easy_pace_sec'])) {
        $easySec = (int) $userData['easy_pace_sec'];
        if ($easySec >= 180 && $easySec <= 600) {
            $easyVelocity = 1000.0 / $easySec * 60;
            $easyVO2 = _vdotOxygenCost($easyVelocity);
            $vdot = $easyVO2 / 0.66;
        }
    }

    // Из целевого результата (менее точно — это ЦЕЛЬ, а не текущий уровень)
    if (!$vdot && !empty($userData['race_target_time']) && !empty($userData['race_distance'])) {
        $km = $distKm[$userData['race_distance']] ?? null;
        if ($km) {
            $parts = explode(':', $userData['race_target_time']);
            $totalSec = 0;
            if (count($parts) === 3) $totalSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
            elseif (count($parts) === 2) $totalSec = (int)$parts[0] * 60 + (int)$parts[1];
            if ($totalSec > 0) {
                $targetVdot = estimateVDOT($km, $totalSec);
                // Для плана берём 90-95% от целевого VDOT — тренируемся на текущем уровне
                $vdot = $targetVdot * 0.92;
            }
        }
    }

    // VDOT-based зоны (точные формулы Daniels)
    if ($vdot && $vdot >= 20 && $vdot <= 85) {
        $paces = getTrainingPaces($vdot);
        return [
            'easy'       => $paces['easy'][0], // медленный easy
            'easy_fast'  => $paces['easy'][1], // быстрый easy
            'long'       => $paces['easy'][0] + 10, // чуть медленнее лёгкого
            'marathon'   => $paces['marathon'],
            'tempo'      => $paces['threshold'],
            'interval'   => $paces['interval'],
            'repetition' => $paces['repetition'],
            'recovery'   => $paces['easy'][0] + 25,
            'vdot'       => round($vdot, 1),
            'source'     => 'vdot',
        ];
    }

    // Fallback: из easy_pace_sec (упрощённая формула)
    $easySec = null;
    if (!empty($userData['easy_pace_sec'])) {
        $easySec = (int) $userData['easy_pace_sec'];
    } elseif (!empty($userData['race_target_time']) && !empty($userData['race_distance'])) {
        $km = $distKm[$userData['race_distance']] ?? null;
        if ($km) {
            $parts = explode(':', $userData['race_target_time']);
            $totalSec = 0;
            if (count($parts) === 3) $totalSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
            elseif (count($parts) === 2) $totalSec = (int)$parts[0] * 60 + (int)$parts[1];
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
        'easy'       => $easySec,
        'long'       => $easySec + 15,
        'marathon'   => $easySec - 25,
        'tempo'      => $easySec - 45,
        'interval'   => $easySec - 65,
        'repetition' => $easySec - 80,
        'recovery'   => $easySec + 25,
        'source'     => 'fallback',
    ];
}

function formatPace($sec) {
    $m = (int) floor($sec / 60);
    $s = (int) ($sec % 60);
    return $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}

/**
 * Вычислить минимальную дистанцию easy-бега по уровню и объёму.
 * Новички и бегуны с малым объёмом могут бегать 3-4 км.
 */
function getMinEasyKm(array $userData): float {
    $expLevel = $userData['experience_level'] ?? 'novice';
    $weeklyKm = (float) ($userData['weekly_base_km'] ?? 0);
    $sessions = (int) ($userData['sessions_per_week'] ?? 3);

    if (in_array($expLevel, ['novice', 'beginner']) || $weeklyKm < 15) {
        return 3.0;
    }
    if ($expLevel === 'intermediate' || $weeklyKm < 30) {
        return 4.0;
    }
    // advanced, expert: min 5 km
    return 5.0;
}

/**
 * Оценка потери тренированности (detraining factor).
 * Опытные бегуны (>2 лет) теряют форму медленнее: аэробная база сохраняется дольше.
 * VO2max падает быстрее у новичков (~6-7% за 2 нед), у опытных (~3-4%).
 *
 * @param int $daysSince Дней с последней тренировки
 * @param string $experienceLevel Уровень: novice, beginner, intermediate, advanced, expert
 * @return float 0.0-1.0 (1.0 = полная форма)
 */
function calculateDetrainingFactor(int $daysSince, string $experienceLevel = 'intermediate'): float {
    // Опытные бегуны теряют форму медленнее (тренировочный стаж защищает)
    $isExperienced = in_array($experienceLevel, ['advanced', 'expert']);

    if ($daysSince <= 3) return 1.0;

    if ($isExperienced) {
        // Advanced/expert: медленный спад
        if ($daysSince <= 7)  return 0.97;
        if ($daysSince <= 14) return 0.90;
        if ($daysSince <= 21) return 0.82;
        if ($daysSince <= 28) return 0.75;
        return max(0.55, 1.0 - $daysSince * 0.012);
    }

    // Novice/beginner/intermediate: стандартный спад
    if ($daysSince <= 7)  return 0.95;
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
    $assessmentContext = (string) ($userData['_assessment_context'] ?? 'default');
    $isRegistrationContext = $assessmentContext === 'registration';
    $goalType = $userData['goal_type'] ?? 'health';
    if (!in_array($goalType, ['race', 'time_improvement'])) {
        $result = ['verdict' => 'realistic', 'messages' => [], 'vdot' => null, 'predictions' => null, 'training_paces' => null];
        return $isRegistrationContext ? softenGoalAssessmentForRegistration($result) : $result;
    }

    $dist = $userData['race_distance'] ?? '';
    $distKm = ['5k' => 5.0, '10k' => 10.0, 'half' => 21.0975, '21.1k' => 21.0975, 'marathon' => 42.195, '42.2k' => 42.195];
    $targetKm = $distKm[$dist] ?? null;
    $distLabels = ['5k' => '5 км', '10k' => '10 км', 'half' => 'полумарафон', '21.1k' => 'полумарафон', 'marathon' => 'марафон', '42.2k' => 'марафон'];
    $distLabel = $distLabels[$dist] ?? $dist;

    // Если дистанция не выбрана — не можем оценить
    if (!$dist || !$targetKm) {
        $result = ['verdict' => 'realistic', 'messages' => [], 'vdot' => null, 'predictions' => null, 'training_paces' => null];
        return $isRegistrationContext ? softenGoalAssessmentForRegistration($result) : $result;
    }

    $weeklyKm = (float) ($userData['weekly_base_km'] ?? 0);
    $sessions = (int) ($userData['sessions_per_week'] ?? 3);
    $expLevel = $userData['experience_level'] ?? 'novice';
    $isNovice = in_array($expLevel, ['novice', 'beginner']);

    // Если weekly_base_km не указан (0), но есть данные о забегах/темпе — оценить базу
    if ($weeklyKm <= 0) {
        $hasRaceHistory = !empty($userData['last_race_time']) && !empty($userData['last_race_distance']);
        $hasPace = !empty($userData['easy_pace_sec']) || !empty($userData['comfortable_pace']);
        if ($hasRaceHistory || $hasPace) {
            // Бегун указал результаты — значит бегает, оценим по сессиям и темпу
            $estSessions = max($sessions, 3);
            $estAvgRun = 6.0; // средняя пробежка 6 км для любителя
            if (!$isNovice) $estAvgRun = 8.0;
            $weeklyKm = round($estSessions * $estAvgRun, 1);
            $userData['weekly_base_km'] = $weeklyKm;
            $userData['_weekly_km_estimated'] = true;
        }
    }

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
        $suggestedWeeks = $reqWeeks; // предлагаем минимум для текущего уровня, не для novice
        $suggestedDate = date('Y-m-d', strtotime(($userData['training_start_date'] ?? 'now') . " +{$suggestedWeeks} weeks"));
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
        $msg['suggestions'][] = ['text' => "Перенести забег на {$suggestedDate} ({$suggestedWeeks} нед.)", 'action' => ['field' => 'race_date', 'value' => $suggestedDate]];
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

    $volumeNote = !empty($userData['_weekly_km_estimated']) ? " (оценка по вашим данным)" : "";
    if ($weeklyKm < $absMin) {
        $severities[] = 'unrealistic';
        $messages[] = [
            'type' => 'error',
            'text' => "Ваш текущий объём ({$weeklyKm} км/нед{$volumeNote}) слишком мал для {$distLabel}. Минимум {$absMin} км/нед, рекомендуется {$recMin}+.",
            'suggestions' => [
                ['text' => "Сначала набрать базу {$recMin} км/нед (4-8 недель лёгкого бега)", 'action' => null],
            ],
        ];
    } elseif ($weeklyKm < $recMin) {
        $severities[] = 'challenging';
        $messages[] = [
            'type' => 'warning',
            'text' => "Ваш объём ({$weeklyKm} км/нед{$volumeNote}) ниже рекомендуемого для {$distLabel} ({$recMin}+ км/нед). Подготовка возможна, но с повышенным риском.",
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

    // Prefer VDOT from training_state (computed by TrainingStateBuilder) if available
    if (!empty($userData['training_state']['vdot'])) {
        $vdot = (float) $userData['training_state']['vdot'];
        $vdotSource = $userData['training_state']['vdot_source_label'] ?? 'training state';
        if (!empty($userData['training_state']['training_paces'])) {
            $trainingPaces = $userData['training_state']['training_paces'];
        }
    }

    // Fallback: try to get VDOT from last race
    $lastDist = $userData['last_race_distance'] ?? null;
    $lastTime = $userData['last_race_time'] ?? null;
    $lastDistKm = null;

    if (!$vdot && $lastDist && $lastTime) {
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

    // ── Check 5: computeMacrocycle warnings (без дублей с Check 1/2) ──
    $mc = computeMacrocycle($userData, $goalType);
    if ($mc && !empty($mc['warnings'])) {
        // Собираем тексты уже добавленных сообщений для проверки дублей
        $existingTexts = array_map(fn($m) => mb_strtolower($m['text']), $messages);
        foreach ($mc['warnings'] as $w) {
            $wLower = mb_strtolower($w);
            // Пропускаем если предупреждение дублирует уже существующее
            $isDuplicate = false;
            foreach ($existingTexts as $et) {
                if (str_contains($wLower, 'недостаточно времени') && str_contains($et, 'рекомендуется минимум')) {
                    $isDuplicate = true;
                    break;
                }
                if (str_contains($wLower, 'нереалистичная цель') && (str_contains($et, 'слишком мал') || str_contains($et, 'рекомендуется минимум'))) {
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate) {
                $severities[] = 'challenging';
                $messages[] = ['type' => 'warning', 'text' => $w, 'suggestions' => []];
            }
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

    $result = [
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

    return $isRegistrationContext ? softenGoalAssessmentForRegistration($result) : $result;
}

function softenGoalAssessmentForRegistration(array $assessment): array {
    $originalVerdict = (string) ($assessment['verdict'] ?? 'realistic');
    $messages = array_map('softenGoalAssessmentMessageForRegistration', (array) ($assessment['messages'] ?? []));
    $softVerdict = match ($originalVerdict) {
        'unrealistic' => 'caution',
        default => $originalVerdict !== '' ? $originalVerdict : 'realistic',
    };

    if (in_array($softVerdict, ['challenging', 'caution'], true)) {
        array_unshift($messages, [
            'type' => 'info',
            'text' => 'Даже если цель выглядит слишком амбициозной, начать можно уже сейчас. Мы соберём более осторожный стартовый план и уточним его по первым тренировкам.',
            'suggestions' => [],
        ]);
    }

    $assessment['verdict_original'] = $originalVerdict;
    $assessment['verdict'] = $softVerdict;
    $assessment['messages'] = $messages;
    $assessment['assessment_mode'] = 'advisory';
    $assessment['blocks_registration'] = false;

    return $assessment;
}

function softenGoalAssessmentMessageForRegistration(array $message): array {
    $type = (string) ($message['type'] ?? 'info');
    $text = trim((string) ($message['text'] ?? ''));

    if ($text !== '') {
        $text = str_replace(
            [
                'Это нереалистично за один цикл подготовки.',
                'слишком мал для',
                'КРАЙНЕ НЕРЕАЛИСТИЧНАЯ ЦЕЛЬ:',
                'НЕРЕАЛИСТИЧНАЯ ЦЕЛЬ:',
                'ОБЯЗАТЕЛЬНО предупреди бегуна о риске травмы.',
            ],
            [
                'Это очень амбициозная цель для первого цикла подготовки.',
                'очень низкий для',
                'Очень амбициозная цель:',
                'Амбициозная цель:',
                'Для старта лучше выбрать более осторожный план и затем быстро уточнить его по факту.',
            ],
            $text
        );

        $text = preg_replace(
            '/Подготовка возможна, но с повышенным риском\./u',
            'Подготовка возможна, но стартовый план лучше сделать осторожнее.',
            $text
        ) ?? $text;
    }

    return [
        ...$message,
        'type' => $type === 'error' ? 'warning' : $type,
        'text' => $text,
        'suggestions' => is_array($message['suggestions'] ?? null) ? $message['suggestions'] : [],
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
    $isMarathon = in_array($dist, ['marathon', '42.2k'], true);
    $isFirstAtDistance = !empty($userData['is_first_race_at_distance']);
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

    // ── Контрольные тренировки (перед разгрузочными, не в первые 2-3 нед и не в подводке) ──
    $controlWeeks = [];
    foreach ($recoveryWeeks as $rw) {
        $controlW = $rw - 1;
        if ($controlW >= 3 && $controlW <= $trainWeeks - 1) {
            $controlWeeks[] = $controlW;
        }
    }
    $controlWeeks = array_values(array_filter(
        array_values(array_unique($controlWeeks)),
        static fn(int $week): bool => $week >= 5 && $week <= max(1, $trainWeeks - 4)
    ));
    if (in_array($dist, ['marathon', '42.2k'], true) && count($controlWeeks) > 2) {
        $controlWeeks = [$controlWeeks[0], $controlWeeks[count($controlWeeks) - 1]];
    }

    // ── Прогрессия длительной ──
    $warnings = [];

    // longStart привязан к реальной физической форме
    // Коэффициент 0.40-0.45 от недельного: длительная не должна занимать больше 40-45% объёма
    if ($weeklyKm >= 25) {
        $longRatio = $isMarathon ? 0.42 : 0.45;
        $longStart = max($spec['long_min'], round($weeklyKm * $longRatio));
    } elseif ($weeklyKm > 0) {
        $longStart = max(3, round($weeklyKm * 0.45));
    } else {
        $longStart = 3;
    }

    $longPeak = $spec['long_peak'];
    // Для марафона: пик длительной зависит от базы и горизонта, иначе 30+ км
    // при умеренной базе превращают план в травмоопасную гонку за объёмом.
    if ($isMarathon) {
        if ($weeklyKm >= 65 && $totalWeeks >= 18) {
            $marathonPeakCap = 35;
            $marathonPeakFloor = 30;
        } elseif ($weeklyKm >= 50 && $totalWeeks >= 18) {
            $marathonPeakCap = 33;
            $marathonPeakFloor = 28;
        } elseif ($weeklyKm >= 35 && $totalWeeks >= 18 && $sessions >= 4) {
            $marathonPeakCap = $isFirstAtDistance ? 30 : 32;
            $marathonPeakFloor = 26;
        } elseif ($weeklyKm >= 25 && $totalWeeks >= 16 && $sessions >= 4) {
            $marathonPeakCap = 28;
            $marathonPeakFloor = 24;
        } else {
            $marathonPeakCap = $weeklyKm >= 20 ? 24 : 20;
            $marathonPeakFloor = 0;
        }

        if ($totalWeeks < 14) {
            $marathonPeakCap = min($marathonPeakCap, $weeklyKm >= 50 ? 30 : 24);
            $marathonPeakFloor = 0;
        }

        $longPeak = min($longPeak, $marathonPeakCap);
        if ($marathonPeakFloor > 0) {
            $longPeak = max($longPeak, $marathonPeakFloor);
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

    if ($weeklyKm < 15 && $isMarathon && $totalWeeks < 16) {
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
    $peakMultiplier = $isNovice ? 1.35 : 1.55;
    $peakVolume = round($startVolume * $peakMultiplier);
    $peakVolume = max($peakVolume, (int) round($longPeak * 1.4));
    $startVolume = max($startVolume, (int) round($longStart * 1.5));

    if ($isMarathon) {
        $longShareCap = $sessions <= 3 ? 0.43 : 0.45;
        if ($isFirstAtDistance) {
            $longShareCap = min($longShareCap, 0.40);
        }
        if ($weeklyKm < 25) {
            $longShareCap = min($longShareCap, 0.43);
        }
        $peakVolume = max($peakVolume, (int) ceil($longPeak / $longShareCap));
    }

    // Ограничить peak реально достижимым: max +10%/неделю от start за build-недели
    $buildWeeksForGrowth = max(1, $buildW + $peakW); // недели для наращивания (без base/taper)
    $maxReachablePeak = round($startVolume * pow(1.10, $buildWeeksForGrowth));
    if ($peakVolume > $maxReachablePeak) {
        $peakVolume = $maxReachablePeak;
    }

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
        'control_weeks' => $controlWeeks,
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
 * Расчёт упрощённого макроцикла для целей health/weight_loss.
 *
 * Фазы: адаптация → развитие → поддержание.
 * Нет peak/taper (не нужна подводка к забегу).
 * НЕ вызывается для start_running/couch_to_5k (у них фиксированная структура).
 */
function computeHealthMacrocycle(array $userData, string $goalType): ?array {
    $totalWeeks = getSuggestedPlanWeeks($userData, $goalType);
    if (!$totalWeeks || $totalWeeks < 4) return null;

    $weeklyKm = !empty($userData['weekly_base_km']) ? (float) $userData['weekly_base_km'] : 0;
    $sessions = (int) ($userData['sessions_per_week'] ?? 3);
    $expLevel = $userData['experience_level'] ?? 'novice';
    $isNovice = in_array($expLevel, ['novice', 'beginner']);

    // ── Расчёт длительностей фаз ──
    $adaptW = $isNovice ? min(3, max(2, (int) round($totalWeeks * 0.20))) : min(2, max(1, (int) round($totalWeeks * 0.15)));
    $maintainW = min(3, max(2, (int) round($totalWeeks * 0.20)));
    $developW = max(2, $totalWeeks - $adaptW - $maintainW);

    // ── Recovery weeks ──
    $recoveryWeeks = [];
    $cycle = $isNovice ? 3 : 4;
    for ($w = $cycle; $w < $totalWeeks; $w += $cycle) {
        $recoveryWeeks[] = $w;
    }

    // ── Прогрессия длительной ──
    if ($weeklyKm >= 15) {
        $longStart = max(5, round($weeklyKm * 0.45));
    } elseif ($weeklyKm > 0) {
        $longStart = max(3, round($weeklyKm * 0.50));
    } else {
        $longStart = 3;
    }

    // Потолок длительной зависит от цели
    if ($goalType === 'weight_loss') {
        $longPeak = $isNovice ? min(8, $longStart + $totalWeeks) : min(15, (int) round($longStart * 2.0));
    } else {
        $longPeak = $isNovice ? min(10, $longStart + $totalWeeks) : min(12, (int) round($longStart * 1.8));
    }
    $longPeak = max($longPeak, $longStart + 2);

    // Прогрессия по неделям
    $trainWeeks = $totalWeeks;
    $trainingWeeks = 0;
    for ($w = 1; $w <= $trainWeeks; $w++) {
        if (!in_array($w, $recoveryWeeks)) {
            $trainingWeeks++;
        }
    }
    $increment = $trainingWeeks > 0 ? ($longPeak - $longStart) / $trainingWeeks : 0;
    $increment = min(2.0, $increment);

    $longRunByWeek = [];
    $trainIdx = 0;
    $developStart = $adaptW + 1;
    for ($w = 1; $w <= $totalWeeks; $w++) {
        if ($w <= $adaptW) {
            // Адаптация: фиксированная стартовая длительная
            $km = $longStart;
            if (in_array($w, $recoveryWeeks)) {
                $km = $longStart * 0.80;
            }
        } elseif ($w <= $totalWeeks - $maintainW) {
            // Развитие: прогрессия
            if (in_array($w, $recoveryWeeks)) {
                $km = ($longStart + $trainIdx * $increment) * 0.80;
            } else {
                $km = $longStart + $trainIdx * $increment;
                $km = min($km, $longPeak);
                $trainIdx++;
            }
        } else {
            // Поддержание: стабильный объём на уровне ~90% пикового
            $km = $longPeak * 0.90;
            if (in_array($w, $recoveryWeeks)) {
                $km = $longPeak * 0.70;
            }
        }
        $longRunByWeek[$w] = round($km);
    }

    // ── Объёмы ──
    $startVolume = $weeklyKm > 0 ? round($weeklyKm) : round($longStart * $sessions * 0.9);
    $healthPeakMultiplier = $isNovice ? 1.30 : 1.45;
    $peakVolume = round($startVolume * $healthPeakMultiplier);
    $peakVolume = max($peakVolume, (int) round($longPeak * 1.4));

    // Ограничить peak реально достижимым: max +10%/неделю за build-недели
    $healthBuildWeeks = max(1, $developW);
    $maxReachableHealthPeak = round($startVolume * pow(1.10, $healthBuildWeeks));
    if ($peakVolume > $maxReachableHealthPeak) {
        $peakVolume = $maxReachableHealthPeak;
    }

    // ── Фазы ──
    $phases = [];
    $weekCursor = 1;

    $phases[] = [
        'name' => 'adaptation',
        'label' => 'Адаптация',
        'weeks_from' => $weekCursor,
        'weeks_to' => $weekCursor + $adaptW - 1,
        'max_key_workouts' => 0,
        'description' => "Привыкание к нагрузке. Только лёгкий бег + 1 длительная. Без интенсивности. Прирост объёма до 10%/нед.",
    ];
    $weekCursor += $adaptW;

    $phases[] = [
        'name' => 'development',
        'label' => 'Развитие',
        'weeks_from' => $weekCursor,
        'weeks_to' => $weekCursor + $developW - 1,
        'max_key_workouts' => 0,
        'description' => "Рост объёма. Длительная растёт. Только лёгкий бег. Прирост до 10%/нед.",
    ];
    $weekCursor += $developW;

    $phases[] = [
        'name' => 'maintenance',
        'label' => 'Поддержание',
        'weeks_from' => $weekCursor,
        'weeks_to' => $totalWeeks,
        'max_key_workouts' => 0,
        'description' => "Стабильный объём (~90% пикового). Длительная стабильна. Только лёгкий бег. Цель — закрепить привычку и форму.",
    ];

    return [
        'total_weeks' => $totalWeeks,
        'distance' => null,
        'start_volume_km' => $startVolume,
        'peak_volume_km' => $peakVolume,
        'phases' => $phases,
        'recovery_weeks' => $recoveryWeeks,
        'control_weeks' => [],
        'long_run' => [
            'start_km' => (int) $longStart,
            'peak_km' => (int) $longPeak,
            'peak_week' => $totalWeeks - $maintainW,
            'by_week' => $longRunByWeek,
        ],
        'spec' => null,
        'is_novice' => $isNovice,
        'has_base' => $weeklyKm >= 15,
        'weekly_base_km' => $weeklyKm,
        'sessions' => $sessions,
        'warnings' => [],
    ];
}

/**
 * Форматирует результат computeHealthMacrocycle() в текст для промпта.
 */
function formatHealthMacrocyclePrompt(array $mc, string $goalType): string {
    $out = '';
    $tw = $mc['total_weeks'];
    $goalLabel = $goalType === 'weight_loss' ? 'снижение веса' : 'здоровье';

    $out .= "МАКРОЦИКЛ ({$tw} недель, цель: {$goalLabel}):\n";

    foreach ($mc['phases'] as $phase) {
        $out .= "- Нед. {$phase['weeks_from']}-{$phase['weeks_to']}: {$phase['label']}. {$phase['description']}\n";
    }
    $out .= "\n";

    // Прогрессия длительной
    $longParts = [];
    foreach ($mc['long_run']['by_week'] as $w => $km) {
        $longParts[] = "нед{$w}: {$km}";
    }
    $out .= "ПРОГРЕССИЯ ДЛИТЕЛЬНОЙ: " . implode(' → ', $longParts) . " (км)\n\n";

    // Объёмы
    $out .= "ОБЪЁМЫ: стартовый ~{$mc['start_volume_km']} км/нед → пиковый ~{$mc['peak_volume_km']} км/нед.\n";
    $out .= "Правило: прирост не более 10%/нед. Весь бег — в лёгком темпе (разговорный).\n\n";

    // Разгрузочные
    if (!empty($mc['recovery_weeks'])) {
        $rwStr = implode(', ', $mc['recovery_weeks']);
        $out .= "Разгрузочные недели: {$rwStr} (объём -20-30%, убрать интенсивность).\n\n";
    }

    if ($goalType === 'weight_loss') {
        $out .= "АКЦЕНТ ДЛЯ СНИЖЕНИЯ ВЕСА:\n";
        $out .= "- Приоритет — длительность (время > дистанция). Бег в аэробной зоне сжигает жир.\n";
        $out .= "- Только лёгкий бег. Без интервалов и фартлеков.\n";
        $out .= "- ОФП для сохранения мышечной массы.\n\n";
    }

    return $out;
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
        $out .= "При генерации плана учитывай эти предупреждения и генерируй максимально безопасный план.\n";
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
    if (!empty($mc['control_weeks'])) {
        $cwList = implode(', ', $mc['control_weeks']);
        $out .= "Ставить ИМЕННО в эти недели: {$cwList}.\n";
    } else {
        $out .= "Ставить каждые 3-4 недели (перед разгрузочной). Не в первые 2-3 недели и не в последние 2.\n";
    }
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

    // Новые поля (если доступны)
    if (!empty($userData['current_long_run_km'])) {
        $block .= "Текущая длительная пробежка: {$userData['current_long_run_km']} км\n";
    }
    if (!empty($userData['resting_hr'])) {
        $block .= "ЧСС покоя: {$userData['resting_hr']} уд/мин\n";
    }
    if (!empty($userData['max_hr'])) {
        $block .= "ЧСС макс: {$userData['max_hr']} уд/мин\n";
    } elseif (!empty($userData['birth_year'])) {
        $age = date('Y') - (int)$userData['birth_year'];
        $estimatedMaxHR = 220 - $age;
        $block .= "ЧСС макс (оценка 220-возраст): ~{$estimatedMaxHR} уд/мин\n";
    }
    if (!empty($userData['injury_history'])) {
        $block .= "\n⚠ ИСТОРИЯ ТРАВМ / ОГРАНИЧЕНИЯ:\n{$userData['injury_history']}\n";
        $block .= "→ Учитывай при составлении плана: избегай нагрузок, провоцирующих эти проблемы.\n";
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
                if ($userData['race_target_time'] === 'finish') {
                    $block .= "Цель по времени: просто финишировать (без целевого времени)\n";
                    $block .= "→ Приоритет — подготовить к преодолению дистанции целиком. Темп не важен, главное — добежать.\n";
                } else {
                    $block .= "Целевое время: {$userData['race_target_time']}\n";
                    // Рассчитываем целевой темп из времени и дистанции
                    $distanceKmMap = [
                        '5k' => 5, '10k' => 10, 'half' => 21.1, 'marathon' => 42.195,
                        '21.1k' => 21.1, '42.2k' => 42.195
                    ];
                    $raceKm = $distanceKmMap[$userData['race_distance']] ?? null;
                    if ($raceKm) {
                        $timeParts = explode(':', $userData['race_target_time']);
                        $totalMin = 0;
                        if (count($timeParts) === 3) {
                            $totalMin = (int)$timeParts[0] * 60 + (int)$timeParts[1] + (int)$timeParts[2] / 60;
                        } elseif (count($timeParts) === 2) {
                            $totalMin = (int)$timeParts[0] + (int)$timeParts[1] / 60;
                        }
                        if ($totalMin > 0) {
                            $paceMin = $totalMin / $raceKm;
                            $pm = (int) floor($paceMin);
                            $ps = (int) round(($paceMin - $pm) * 60);
                            $block .= "Целевой темп на забег: {$pm}:" . str_pad((string)$ps, 2, '0', STR_PAD_LEFT) . " /км\n";
                        }
                    }
                }
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
            // Комфортный темп показываем ТОЛЬКО если нет рассчитанных зон (иначе конфликт)
            $zones = calculatePaceZones($userData);
            if (!$zones && !empty($userData['easy_pace_sec'])) {
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
                // Рассчитываем целевой темп
                $distKmMap = [
                    '5k' => 5, '10k' => 10, 'half' => 21.1, 'marathon' => 42.195,
                    '21.1k' => 21.1, '42.2k' => 42.195
                ];
                $raceKm2 = $distKmMap[$userData['race_distance'] ?? ''] ?? null;
                if ($raceKm2) {
                    $tp = explode(':', $targetTime);
                    $tm = 0;
                    if (count($tp) === 3) $tm = (int)$tp[0] * 60 + (int)$tp[1] + (int)$tp[2] / 60;
                    elseif (count($tp) === 2) $tm = (int)$tp[0] + (int)$tp[1] / 60;
                    if ($tm > 0) {
                        $p = $tm / $raceKm2;
                        $block .= "Целевой темп на забег: " . (int)floor($p) . ":" . str_pad((string)(int)round(($p - floor($p)) * 60), 2, '0', STR_PAD_LEFT) . " /км\n";
                    }
                }
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
            $zones2 = calculatePaceZones($userData);
            if (!$zones2 && !empty($userData['easy_pace_sec'])) {
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

    $isVdot = ($zones['source'] ?? '') === 'vdot';
    $vdotLabel = $isVdot ? " (VDOT=" . ($zones['vdot'] ?? '?') . ", формулы Daniels)" : " (приблизительные)";

    $block = "\n═══ ТРЕНИРОВОЧНЫЕ ЗОНЫ{$vdotLabel} ═══\n\n";
    $block .= "E  — Лёгкий бег (easy/long): " . formatPace($zones['easy']) . " /км — RPE 3-4, разговорный темп\n";
    if (!empty($zones['easy_fast'])) {
        $block .= "     Диапазон easy: " . formatPace($zones['easy']) . " – " . formatPace($zones['easy_fast']) . " /км\n";
    }
    $block .= "     Длительная (long): " . formatPace($zones['long']) . " /км — нижняя граница easy\n";
    if (!empty($zones['marathon'])) {
        $block .= "M  — Марафонский темп: " . formatPace($zones['marathon']) . " /км — RPE 5-6, для MP-сегментов в длительной\n";
    }
    $block .= "T  — Пороговый/Темповый (tempo): " . formatPace($zones['tempo']) . " /км — RPE 6-7, комфортно-тяжело, 20-40 мин\n";
    $block .= "I  — Интервальный (interval): " . formatPace($zones['interval']) . " /км — RPE 8-9, отрезки 400м-2км, развивает VO2max\n";
    if (!empty($zones['repetition'])) {
        $block .= "R  — Повторный (repetition): " . formatPace($zones['repetition']) . " /км — RPE 9-10, отрезки 200-400м, развивает скорость и экономичность\n";
    }
    $block .= "Rec — Восстановительная трусца: " . formatPace($zones['recovery']) . " /км — между интервалами\n";

    $block .= "\n╔══ ПРАВИЛА ПРИМЕНЕНИЯ ЗОН ══╗\n";
    $block .= "• Поле pace для easy/long: используй E-темп (" . formatPace($zones['easy']) . ")\n";
    $block .= "• Поле pace для tempo: используй T-темп (" . formatPace($zones['tempo']) . ")\n";
    $block .= "• Поле interval_pace для interval: используй I-темп (" . formatPace($zones['interval']) . ")\n";
    if (!empty($zones['repetition'])) {
        $block .= "• Для коротких ускорений (200-400м, strides): используй R-темп (" . formatPace($zones['repetition']) . ")\n";
    }
    $block .= "• ЗАПРЕЩЕНО придумывать другие темпы. Используй ТОЛЬКО указанные зоны.\n";
    $block .= "╚════════════════════════════╝\n";

    return $block;
}

function formatPromptTimeForBenchmark(string $time, ?string $distanceKey = null): string {
    $parts = array_values(array_filter(explode(':', trim($time)), static fn(string $part): bool => $part !== ''));
    if (count($parts) === 3) {
        return str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . ':' . str_pad($parts[2], 2, '0', STR_PAD_LEFT);
    }
    if (count($parts) !== 2) {
        return $time;
    }

    if (in_array($distanceKey, ['half', 'marathon'], true)) {
        return (int) $parts[0] . ':' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . ':00';
    }

    return str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . ':00';
}

function extractPlanningBenchmarkFromReason(?string $reason): array {
    $reason = trim((string) $reason);
    if ($reason === '') {
        return [];
    }

    $text = mb_strtolower($reason, 'UTF-8');
    $distanceMap = [
        'half' => ['полумарафон', 'half', '21.1', '21,1'],
        'marathon' => ['марафон', '42.2', '42,2'],
        '10k' => ['10 км', '10км', '10k'],
        '5k' => ['5 км', '5км', '5k'],
    ];

    $distanceKey = null;
    foreach ($distanceMap as $candidate => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $distanceKey = $candidate;
                break 2;
            }
        }
    }

    if ($distanceKey === null || !preg_match('/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/u', $reason, $match)) {
        return [];
    }

    return [
        'planning_benchmark_distance' => $distanceKey,
        'planning_benchmark_time' => formatPromptTimeForBenchmark($match[1], $distanceKey),
    ];
}

function extractPlanningEasyFloorFromReason(?string $reason): ?float {
    $reason = trim((string) $reason);
    if ($reason === '') {
        return null;
    }

    if (preg_match('/easy[^\n\r,.!?;:]{0,40}(?:не\s+короче|от|минимум)\s+(\d+(?:[.,]\d+)?)\s*км/iu', $reason, $match)
        || preg_match('/(?:не\s+короче|минимум)\s+(\d+(?:[.,]\d+)?)\s*км[^\n\r,.!?;:]{0,30}easy/iu', $reason, $match)) {
        return round((float) str_replace(',', '.', $match[1]), 1);
    }

    return null;
}

function applyScheduleOverridesToUserData(array $userData, ?string $reason): array {
    $updated = $userData;
    $overrides = extractScheduleOverridesFromReason($reason);

    if (!empty($overrides['rest']) && !empty($updated['preferred_days']) && is_array($updated['preferred_days'])) {
        $updated['preferred_days'] = array_values(array_filter(
            $updated['preferred_days'],
            static fn(string $day): bool => $day !== $overrides['rest']
        ));
        $updated['sessions_per_week'] = count($updated['preferred_days']);
        $updated['schedule_reason_overrides']['rest_day'] = $overrides['rest'];
    }

    if (!empty($overrides['long'])) {
        $updated['preferred_long_day'] = $overrides['long'];
        $updated['schedule_reason_overrides']['long_day'] = $overrides['long'];
    }

    $benchmark = extractPlanningBenchmarkFromReason($reason);
    foreach ($benchmark as $key => $value) {
        $updated[$key] = $value;
    }

    $easyFloor = extractPlanningEasyFloorFromReason($reason);
    if ($easyFloor !== null) {
        $updated['planning_easy_min_km'] = $easyFloor;
    }

    return $updated;
}

function buildTrainingStateBlock(array $userData): string {
    $state = is_array($userData['training_state'] ?? null) ? $userData['training_state'] : [];
    if (empty($state)) {
        return '';
    }

    $lines = ["\n═══ TRAINING STATE ═══", ''];
    if (!empty($state['vdot'])) {
        $sourceLabel = (string) ($state['vdot_source_label'] ?? $state['vdot_source'] ?? 'training_state');
        $confidence = (string) ($state['vdot_confidence'] ?? 'unknown');
        $lines[] = "VDOT: " . round((float) $state['vdot'], 1) . " ({$sourceLabel}, confidence={$confidence})";
    }
    if (!empty($state['readiness'])) {
        $lines[] = "Readiness: {$state['readiness']}";
    }
    if (!empty($state['weeks_to_goal'])) {
        $lines[] = "Недель до цели: " . (int) $state['weeks_to_goal'];
    }
    $preferredLongDay = $userData['preferred_long_day'] ?? $state['preferred_long_day'] ?? getPreferredLongRunDayKey($userData);
    if ($preferredLongDay) {
        $lines[] = "Предпочтительный день длительной: " . getPromptWeekdayLabel((string) $preferredLongDay);
    }
    if (!empty($state['age_years'])) {
        $lines[] = "Возраст: " . (int) $state['age_years'];
    }

    $specialFlags = $state['special_population_flags'] ?? [];
    if (is_array($specialFlags) && !empty($specialFlags)) {
        $lines[] = "Special population flags: " . implode(', ', $specialFlags);
    }

    $feedbackAnalytics = is_array($state['feedback_analytics'] ?? null) ? $state['feedback_analytics'] : [];
    if (!empty($feedbackAnalytics['total_responses'])) {
        $riskLevel = (string) ($feedbackAnalytics['risk_level'] ?? 'low');
        $painCount = (int) ($feedbackAnalytics['pain_count'] ?? 0);
        $fatigueCount = (int) ($feedbackAnalytics['fatigue_count'] ?? 0);
        $recentRisk = round((float) ($feedbackAnalytics['recent_average_recovery_risk'] ?? 0.0), 2);
        $lines[] = "Recent post-workout feedback: responses={$feedbackAnalytics['total_responses']}, pain={$painCount}, fatigue={$fatigueCount}, recovery_risk={$recentRisk}, level={$riskLevel}";
        $recentRpe = round((float) ($feedbackAnalytics['recent_session_rpe_avg'] ?? 0.0), 1);
        $loadDelta = round((float) ($feedbackAnalytics['subjective_load_delta'] ?? 0.0), 2);
        $painScore = round((float) ($feedbackAnalytics['recent_pain_score_avg'] ?? 0.0), 1);
        if ($recentRpe > 0.0 || $loadDelta > 0.0 || $painScore > 0.0) {
            $lines[] = "Structured recovery signals: rpe={$recentRpe}, pain_score={$painScore}, load_delta={$loadDelta}";
        }
    }

    $athleteSignals = is_array($state['athlete_signals'] ?? null) ? $state['athlete_signals'] : [];
    $hasAthleteSignalContext = !empty($athleteSignals['total_notes_count']) || !empty($feedbackAnalytics['total_responses']) || !empty($athleteSignals['highlights']);
    if ($hasAthleteSignalContext && !empty($athleteSignals['overall_risk_level'])) {
        $lines[] = "Athlete signals overall: risk_level=" . (string) ($athleteSignals['overall_risk_level'] ?? 'low')
            . ", note_risk=" . round((float) ($athleteSignals['note_risk_score'] ?? 0.0), 2);

        $noteSignalParts = [];
        foreach ([
            'pain' => (int) ($athleteSignals['note_pain_count'] ?? 0),
            'fatigue' => (int) ($athleteSignals['note_fatigue_count'] ?? 0),
            'sleep' => (int) ($athleteSignals['note_sleep_count'] ?? 0),
            'illness' => (int) ($athleteSignals['note_illness_count'] ?? 0),
            'stress' => (int) ($athleteSignals['note_stress_count'] ?? 0),
            'travel' => (int) ($athleteSignals['note_travel_count'] ?? 0),
        ] as $label => $count) {
            if ($count > 0) {
                $noteSignalParts[] = $label . '=' . $count;
            }
        }
        if (!empty($noteSignalParts)) {
            $lines[] = "Signals from notes: " . implode(', ', $noteSignalParts);
        }

        $highlights = array_slice((array) ($athleteSignals['highlights'] ?? []), 0, 3);
        if (!empty($highlights)) {
            $lines[] = "Athlete signal highlights: " . implode(' | ', $highlights);
        }
    }

    $loadPolicy = is_array($state['load_policy'] ?? null) ? $state['load_policy'] : [];
    if (!empty($loadPolicy['allowed_growth_ratio'])) {
        $growthPct = max(0, (int) round(((float) $loadPolicy['allowed_growth_ratio'] - 1.0) * 100));
        $lines[] = "Safety envelope по объёму: рост недельного объёма обычно не выше ~{$growthPct}%";
        $lines[] = "Это мягкий коридор безопасности, а не требование делать одинаковые недели.";
    }
    if (!empty($loadPolicy['recovery_weeks']) && is_array($loadPolicy['recovery_weeks'])) {
        $lines[] = "Recovery weeks: " . implode(', ', array_map('intval', $loadPolicy['recovery_weeks']));
    }

    return implode("\n", $lines) . "\n";
}

function buildWeekSkeletonBlock(array $userData): string {
    $skeleton = $userData['plan_skeleton']['weeks'] ?? null;
    if (!is_array($skeleton) || empty($skeleton)) {
        return '';
    }

    $dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    $lines = ["\n═══ WEEK SKELETON ═══", ''];
    foreach ($skeleton as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $phaseLabel = trim((string) ($week['phase_label'] ?? ''));
        $days = $week['days'] ?? [];
        if (!is_array($days) || empty($days)) {
            continue;
        }

        $parts = [];
        foreach ($days as $index => $type) {
            $parts[] = ($dayLabels[$index] ?? ('Д' . ($index + 1))) . ' ' . trim(mb_strtolower((string) $type, 'UTF-8'));
        }
        $prefix = $weekNumber > 0 ? "Неделя {$weekNumber}" : 'Неделя';
        if ($phaseLabel !== '') {
            $prefix .= " ({$phaseLabel})";
        }
        $lines[] = $prefix . ': ' . implode(' | ', $parts);
    }

    return implode("\n", $lines) . "\n";
}

function buildWorkoutIntentBlock(array $userData, string $goalType, bool $isFlexibleRecalc = false): string {
    if (!in_array($goalType, ['race', 'time_improvement'], true)) {
        return '';
    }

    $raceDistance = (string) ($userData['race_distance'] ?? '');
    $lines = ["\n═══ WORKOUT INTENT ═══", ''];
    if (in_array($raceDistance, ['marathon', '42.2k'], true)) {
        $lines[] = "tempo для марафона не должен быть только пороговым: часть tempo-сессий должна быть goal_pace_specific.";
        $lines[] = "Марафонская работа в целевом темпе обычно должна быть type: tempo или частью long, а не control.";
        $lines[] = "Control для марафона используй редко: обычно 0-1 раза за специфический блок.";
    } else {
        $lines[] = "Определи intent каждой ключевой тренировки до выбора точной структуры.";
    }
    if ($isFlexibleRecalc) {
        $lines[] = "Конкретную структуру tempo/control/interval подбирай самостоятельно под текущую фазу и оставшееся время.";
    }

    $lines[] = '';
    $lines[] = "═══ QUALITY DAY CONTRACT ═══";
    $lines[] = "tempo => goal_pace_specific | threshold_support";
    $lines[] = "interval => vo2_support | speed_support";
    $lines[] = "control => benchmark | tune-up";
    $lines[] = "long => aerobic_support | race_specific_endurance";
    $lines[] = "Control используй только там, где intent прямо говорит о benchmark/tune-up.";

    return implode("\n", $lines) . "\n";
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
            // Макроцикл для regular_running и custom (не для фиксированных программ)
            if (!in_array($program, ['start_running', 'couch_to_5k'])) {
                $mc = computeHealthMacrocycle($userData, $goalType);
                if ($mc) {
                    $block .= "\n" . formatHealthMacrocyclePrompt($mc, $goalType);
                }
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
            $block .= "- 3-4 беговых дня в неделю.\n";
            $block .= "- ОСНОВНОЙ бег — в зоне максимального жиросжигания (60-70% HRmax, RPE 3-4). Это лёгкий разговорный темп.\n";
            if (!empty($userData['max_hr'])) {
                $fatBurnLow = (int) round($userData['max_hr'] * 0.60);
                $fatBurnHigh = (int) round($userData['max_hr'] * 0.70);
                $block .= "  Пульсовая зона жиросжигания для пользователя: {$fatBurnLow}–{$fatBurnHigh} уд/мин.\n";
            } elseif (!empty($userData['birth_year'])) {
                $age = date('Y') - (int)$userData['birth_year'];
                $estMax = 220 - $age;
                $fatBurnLow = (int) round($estMax * 0.60);
                $fatBurnHigh = (int) round($estMax * 0.70);
                $block .= "  Ориентировочная зона жиросжигания (220-возраст): {$fatBurnLow}–{$fatBurnHigh} уд/мин.\n";
            }
            $block .= "- 1 длительная пробежка в неделю (30-60 мин) — основной инструмент жиросжигания (долгая аэробная работа).\n";
            $block .= "- 1 тренировка с ускорениями (фартлек или короткие интервалы) со 2-3 недели. Интервалы сжигают меньше жира во время бега, но разгоняют метаболизм на 24-48 часов после (эффект EPOC). Чередуй с лёгким бегом.\n";
            $block .= "- Прирост объёма: до 10%/нед. Старт с текущего объёма" . ($weeklyKm > 0 ? " ({$weeklyKm} км/нед)" : "") . ".\n";
            if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
                $block .= "- ОФП 2 раза в неделю (в указанные дни): силовые на крупные группы мышц (приседания, выпады, тяги, планка). КРИТИЧНО для сохранения мышечной массы при дефиците калорий. Без силовых бегун теряет мышцы, а не только жир.\n";
            }
            $block .= "- Безопасная скорость снижения: не более 0.5-1 кг/нед. Питание — вне плана, но тренировки оптимизированы для дефицита калорий.\n";
            if ($isNovice) {
                $block .= "- Начинающий: старт с бег/ходьба, акцент на регулярность и длительность (время), а не скорость. Цель — 30 мин непрерывной активности.\n";
            }
            $mc = computeHealthMacrocycle($userData, $goalType);
            if ($mc) {
                $block .= "\n" . formatHealthMacrocyclePrompt($mc, $goalType);
            }
            break;

        default:
            $block .= "Общие принципы: прогрессия нагрузки, чередование нагрузки и отдыха, разнообразие (лёгкий бег, длительная, при необходимости темп/интервалы).\n";
    }

    // Общие правила и разгрузка — в buildKeyWorkoutsBlock и buildMandatoryRulesBlock, не дублируем.

    return $block;
}

function buildKeyWorkoutsBlock($userData) {
    $block = "\n═══ КЛЮЧЕВЫЕ ТРЕНИРОВКИ И ПРАВИЛА ПО ФАЗАМ ═══\n\n";

    $expLevel = $userData['experience_level'] ?? 'novice';
    $isExpBasic = in_array($expLevel, ['novice', 'beginner']);

    $block .= "╔══ ЖЁСТКИЕ ОГРАНИЧЕНИЯ ПО ФАЗАМ ══╗\n";
    $block .= "│ Фаза      │ Допустимые типы                            │ Ключевых/нед │\n";
    $block .= "│───────────│────────────────────────────────────────────│──────────────│\n";
    if ($isExpBasic) {
        $block .= "│ БАЗОВАЯ    │ easy, long, rest, other, sbu, free         │ 0-1 (long)   │\n";
        $block .= "│            │ ЗАПРЕЩЕНЫ: tempo, interval, fartlek, race  │              │\n";
    } else {
        $block .= "│ БАЗОВАЯ    │ easy, long, rest, other, sbu, free         │ 0-1 (long)   │\n";
        $block .= "│  (intermed │ + страйды 4-6×100м в конце easy (в notes)  │              │\n";
        $block .= "│  iate+)    │ + 1 короткий темповый (15 мин) к концу фазы│              │\n";
        $block .= "│            │ ЗАПРЕЩЕНЫ: interval, fartlek, race          │              │\n";
    }
    $block .= "│ РАЗВИВАЮЩАЯ│ ВСЕ типы допустимы                        │ 2-3          │\n";
    $block .= "│ ПИКОВАЯ    │ ВСЕ типы допустимы                        │ 2-3          │\n";
    $block .= "│ ПОДВОДКА   │ easy, long(сокращ.), tempo(сокращ.), rest  │ 1-2          │\n";
    $block .= "│            │ Объём интенсивных -50%, длительная -40%    │              │\n";
    $block .= "│ РАЗГРУЗКА  │ easy, long(сокращ.), rest, other(легче)    │ 0-1          │\n";
    $block .= "│            │ ЗАПРЕЩЕНЫ: tempo, interval, fartlek        │              │\n";
    $block .= "╚════════════════════════════════════════════════════════════════════════╝\n\n";

    $dist = $userData['race_distance'] ?? '';
    if (in_array($dist, ['marathon', '42.2k'])) {
        $block .= "ПОДВОДКА (TAPER) ДЛЯ МАРАФОНА — 3 недели:\n";
        $block .= "- Неделя -3: объём -20-25% от пиковой. Ключевые укорочены. Длительная 18-22 км.\n";
        $block .= "- Неделя -2: объём -35-40% от пиковой. Длительная 12-16 км. 1 лёгкий темповый (20 мин).\n";
        $block .= "- Неделя -1 (RACE WEEK): объём -50-60%. Easy 4-6 км × 3-4 дня. 1-2 дня rest.\n";
        $block .= "  За 2 дня до забега — rest. За день — лёгкий 3-4 км с 3-4 страйдами или rest.\n";
        $block .= "  Забег (race) — в день забега. Никаких интервалов/темповых.\n\n";
    } elseif (in_array($dist, ['half', '21.1k'])) {
        $block .= "ПОДВОДКА (TAPER) ДЛЯ ПОЛУМАРАФОНА — 2 недели:\n";
        $block .= "- Неделя -2: объём -30-35% от пиковой. Длительная 12-14 км. 1 короткий темповый (15 мин).\n";
        $block .= "- Неделя -1 (RACE WEEK): объём -40-50%. Easy 4-6 км × 3 дня. 1-2 дня rest.\n";
        $block .= "  За 2 дня до забега — rest. За день — лёгкий 3-4 км с 3-4 страйдами или rest.\n";
        $block .= "  Забег (race) — в день забега. Никаких интервалов/темповых.\n\n";
    } elseif (in_array($dist, ['10k'])) {
        $block .= "ПОДВОДКА (TAPER) ДЛЯ 10 КМ — 10-14 дней:\n";
        $block .= "- Неделя -2: объём -25-30% от пиковой. 1 короткий темповый (15 мин) или 3-4×1000м.\n";
        $block .= "- Неделя -1 (RACE WEEK): объём -35-40%. Easy 4-6 км × 2-3 дня. 1 день rest.\n";
        $block .= "  За день до забега — лёгкий 3-4 км с 3-4 страйдами или rest.\n";
        $block .= "  Забег (race) — в день забега.\n\n";
    } elseif (in_array($dist, ['5k'])) {
        $block .= "ПОДВОДКА (TAPER) ДЛЯ 5 КМ — 7-10 дней:\n";
        $block .= "- Неделя -1 (RACE WEEK): объём -30%. Easy 4-5 км × 2-3 дня. 1 короткий темповый или 3-4×400м за 3-4 дня до забега.\n";
        $block .= "  За день до забега — лёгкий 3-4 км с 3-4 страйдами или rest.\n";
        $block .= "  Забег (race) — в день забега.\n\n";
    } else {
        $block .= "ПОДВОДКА (TAPER) — последние 2-3 недели перед забегом:\n";
        $block .= "- Неделя -3: объём -20-25% от пиковой. Ключевые укорочены.\n";
        $block .= "- Неделя -2: объём -35-40% от пиковой. Длительная 12-16 км. 1 лёгкий темповый.\n";
        $block .= "- Неделя -1 (RACE WEEK): объём -50-60%. Easy 4-6 км × 3-4 дня. 1-2 дня rest.\n";
        $block .= "  За 2 дня до забега — rest. За день — лёгкий 3-4 км или rest.\n";
        $block .= "  Забег (race) — в последний день. Никаких интервалов/темповых.\n\n";
    }

    $block .= "ТИПЫ КЛЮЧЕВЫХ (is_key_workout = true):\n";
    $block .= "- long   — аэробная база, жировой обмен, ментальная выносливость\n";
    $block .= "- tempo  — лактатный порог (пороговый темп, 20-40 мин)\n";
    $block .= "- interval — МПК (быстрые отрезки 400м-2км)\n";
    $block .= "- fartlek — скоростная выносливость (структурированный)\n";
    $block .= "- control — тест-забег\n";
    $block .= "- race   — соревнование\n\n";

    $block .= "НЕ ключевые (is_key_workout = false): easy, rest, other, sbu, free\n\n";

    $sessions = (int)($userData['sessions_per_week'] ?? 3);
    $block .= "РАССТАНОВКА ПРИ {$sessions} ТРЕНИРОВКАХ В НЕДЕЛЮ:\n";
    if ($sessions <= 3) {
        $block .= "- 1-2 ключевые: long + 1 интенсивная (в развивающей/пиковой фазе)\n";
        $block .= "  Пример: Вт easy, Чт tempo/interval, Сб long\n";
    } elseif ($sessions == 4) {
        $block .= "- 2 ключевые: long + 1 интенсивная, 2 easy\n";
        $block .= "  Пример: Вт easy, Ср tempo, Пт easy, Сб long\n";
    } elseif ($sessions == 5) {
        $block .= "- 2-3 ключевые: long + 1-2 интенсивные, 2-3 easy\n";
        $block .= "  Пример: Пн easy, Вт interval, Чт easy, Пт tempo, Сб long\n";
    } else {
        $block .= "- 2-3 ключевые + easy между ними\n";
        $block .= "  Пример: Пн easy, Вт interval, Ср easy, Чт tempo, Пт easy, Сб long, Вс rest\n";
    }

    if ($sessions >= 5) {
        $block .= "\nRECOVERY RUN (восстановительный бег) — для {$sessions} тренировок/нед:\n";
        $block .= "- На следующий день после ключевой (interval/tempo/long) ставь УКОРОЧЕННЫЙ easy.\n";
        $block .= "  type: easy, но distance_km на 20-30% короче обычного easy. notes: \"Восстановительный бег\".\n";
        $block .= "  Темп — на 15-20 сек/км медленнее обычного easy (RPE 2-3, очень лёгко).\n";
        $block .= "- Это НЕ пропуск — это осознанное восстановление. Помогает вывести метаболиты и ускорить восстановление.\n\n";
    }

    $block .= "\n╔══ АБСОЛЮТНЫЕ ЗАПРЕТЫ ══╗\n";
    $block .= "│ 1. ДВЕ ключевые подряд — ЗАПРЕЩЕНО (минимум 1 day easy/rest между)  │\n";
    if ($isExpBasic) {
        $block .= "│ 2. Интервалы/темп в БАЗОВОЙ фазе — ЗАПРЕЩЕНО                        │\n";
    } else {
        $block .= "│ 2. Интервалы/фартлек в БАЗОВОЙ фазе — ЗАПРЕЩЕНО (короткий темп ОК)  │\n";
    }
    $block .= "│ 3. Интервалы/темп в РАЗГРУЗОЧНОЙ неделе — ЗАПРЕЩЕНО                  │\n";
    $block .= "│ 4. Длительная >33 км — ЗАПРЕЩЕНО                                      │\n";
    $block .= "│ 5. Более 3 ключевых в одну неделю — ЗАПРЕЩЕНО                        │\n";
    $minEasy = getMinEasyKm($userData);
    $block .= "│ 6. Easy < {$minEasy} км — ЗАПРЕЩЕНО (кроме race week: допустимо от 3 км)  │\n";
    $block .= "│ 7. Прирост длительной > 3 км за неделю — ЗАПРЕЩЕНО                   │\n";
    $block .= "│ 8. Tempo без distance_km и pace — ЗАПРЕЩЕНО (только структ. поля)    │\n";
    $block .= "│ 9. Description для бега — ЗАПРЕЩЕНО (код строит автоматически)        │\n";
    $block .= "╚═══════════════════════════════════════════════════════════════════════╝\n\n";

    $block .= "ПРОГРЕССИЯ КЛЮЧЕВЫХ ТРЕНИРОВОК ОТ НЕДЕЛИ К НЕДЕЛЕ:\n";
    $block .= "Интервалы и темповые ДОЛЖНЫ усложняться по ходу плана. Не повторяй одну и ту же тренировку неделями!\n\n";
    $block .= "Интервалы — 3 способа прогрессии (выбери подходящий для фазы):\n";
    $block .= "  a) Увеличение повторов: 4×1000м → 5×1000м → 6×1000м (каждые 2-3 нед.)\n";
    $block .= "  b) Увеличение длины отрезка: 5×800м → 4×1200м → 3×1600м (объём интенсивной работы ≈ const)\n";
    $block .= "  c) Сокращение отдыха: 5×1000м, отдых 400м → 5×1000м, отдых 200м\n";
    $block .= "  Суммарный объём интенсивной работы: от 3-4 км (начало) до 5-8 км (пик). Не более 10% недельного объёма.\n\n";
    $block .= "Темповые — прогрессия длительности темповой части:\n";
    $block .= "  - Начало: 15-20 мин → Пик: 30-40 мин (для полумарафона/марафона до 50 мин)\n";
    $block .= "  - Альтернатива: пороговые интервалы — 3×1600м в пороговом темпе с 1 мин отдыха → 4×1600м → 5×1600м\n";
    $block .= "  - Темп остаётся пороговым (не ускоряй!), растёт только объём в темпе\n\n";
    $block .= "Фартлек — прогрессия структуры:\n";
    $block .= "  - Начало: 6×200м быстро / 200м трусцой → 8×200м / 200м → 4×400м / 200м → 3×800м / 400м\n";
    $block .= "  - В развивающей фазе: длиннее быстрые отрезки, короче восстановление\n\n";

    // Марафонная/полумарафонная специфика
    $dist = $userData['race_distance'] ?? '';
    if (in_array($dist, ['marathon', '42.2k', 'half', '21.1k'])) {
        $block .= "СРЕДНЕ-ДЛИТЕЛЬНАЯ:\n";
        if ($sessions >= 5) {
            $block .= "- При 5+ тренировках: 1 средне-длительная в неделю (60-70% от длительной), тип easy.\n";
            $block .= "  Ставь в середине недели (Ср-Чт). is_key_workout: false.\n";
        }

        $block .= "\nСПЕЦИФИЧЕСКИЕ ТРЕНИРОВКИ:\n";
        if (in_array($dist, ['marathon', '42.2k'])) {
            $block .= "- МАРАФОНСКИЕ СЕГМЕНТЫ В ДЛИТЕЛЬНОЙ: в развивающей/пиковой фазе,\n";
            $block .= "  последние 4-8 км длительной — в марафонском темпе. Указывай в notes.\n";
        }
        $block .= "- ПРОГРЕССИВНАЯ ДЛИТЕЛЬНАЯ: длительная с ускорением. Первые 2/3 в лёгком темпе, последняя 1/3 ускоряясь до марафонского.\n";
        $block .= "  Тип: long. notes: \"Прогрессивная: первые N км легко, последние N км ускоряясь до марафонского темпа\".\n";
        $block .= "  Использовать в развивающей/пиковой фазе, чередуя с обычной длительной (не каждую неделю).\n";
        $block .= "- ПОРОГОВЫЕ ИНТЕРВАЛЫ: альтернатива непрерывному темповому бегу. 3-5 × 1600м в пороговом темпе с 60-90 сек отдыха.\n";
        $block .= "  Менее утомительны, чем 30+ мин непрерывного темпа. Хороши для начала развивающей фазы.\n";
        $block .= "  Тип: tempo. notes: \"Пороговые интервалы: N×1600м в пороговом темпе, отдых 60с\". distance_km = разминка + интервалы + заминка.\n";
        if (in_array($dist, ['marathon', '42.2k'])) {
            $block .= "- ГЕНЕРАЛЬНАЯ РЕПЕТИЦИЯ: за 3-4 недели до марафона, 10-15 км в марафонском темпе с гелями (имитация старта).\n";
            $block .= "  Тип: long или tempo. notes: \"Генеральная репетиция: 12 км в марафонском темпе, приём гелей на 5 и 10 км\".\n";
        }
        $block .= "\n";
    }

    return $block;
}

function buildMandatoryRulesBlock($userData) {
    $block = "\n═══ ОБЯЗАТЕЛЬНЫЕ ПРАВИЛА (соблюдай строго) ═══\n\n";
    $block .= "1. Расписание по дням:\n";
    $ruDayLabels = ['mon'=>'Пн','tue'=>'Вт','wed'=>'Ср','thu'=>'Чт','fri'=>'Пт','sat'=>'Сб','sun'=>'Вс'];
    if (!empty($userData['preferred_days']) && is_array($userData['preferred_days'])) {
        $daysList = implode(', ', array_map(fn($d) => $ruDayLabels[$d] ?? $d, $userData['preferred_days']));
        $allDayCodesR = ['mon','tue','wed','thu','fri','sat','sun'];
        $ofpDaysR = $userData['preferred_ofp_days'] ?? [];
        $restDaysR = array_diff($allDayCodesR, $userData['preferred_days'], is_array($ofpDaysR) ? $ofpDaysR : []);
        $restDaysListR = implode(', ', array_map(fn($d) => $ruDayLabels[$d] ?? $d, $restDaysR));
        $block .= "   — Беговые тренировки ставить ТОЛЬКО в эти дни недели: {$daysList}.\n";
        $block .= "   — ВЫХОДНЫЕ ДНИ (ОБЯЗАТЕЛЬНО type: rest): {$restDaysListR}. В эти дни ЗАПРЕЩЁН бег!\n";
    } else {
        $block .= "   — Количество беговых дней в неделю: " . ($userData['sessions_per_week'] ?? 3) . ". Остальные дни — rest" . (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days']) ? " или ОФП по предпочтениям" : "") . ".\n";
    }
    if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
        $ofpList = implode(', ', array_map(fn($d) => $ruDayLabels[$d] ?? $d, $userData['preferred_ofp_days']));
        $block .= "   — ОФП (type: other) ставить ТОЛЬКО в эти дни: {$ofpList}.\n";
    } else {
        $block .= "   — ОФП в плане не включать (пользователь выбрал «не делать ОФП»). Дни без бега — только type: rest.\n";
    }
    $block .= "2. Объём и сложность — по уровню подготовки и weekly_base_km. ВАЖНО: для подготовки к забегу длительная должна достигать указанного минимума (см. ПИКОВАЯ ДЛИТЕЛЬНАЯ выше). Не занижай нагрузку — план должен РЕАЛЬНО подготовить к дистанции.\n";
    $block .= "3. Даты НЕ нужны — код вычислит их автоматически из start_date и номера недели.\n";
    $block .= "4. В каждой неделе ровно 7 дней: порядок понедельник (индекс 0), вторник (1), …, воскресенье (6). День без тренировки — type: \"rest\", все поля null.\n";
    $block .= "5. ЯЗЫК: Все notes и описания — ТОЛЬКО на русском. Запрещены: T-pace, M-pace, E-pace, I-pace, R-pace, cruise intervals, progressive long, dress rehearsal, medium-long, recovery run. Замены: пороговый темп, марафонский темп, лёгкий темп, быстрый темп, пороговые интервалы, прогрессивная длительная, генеральная репетиция, средне-длительная, восстановительный бег.\n\n";

    // Правила минимальных дистанций
    $block .= "╔══ МИНИМАЛЬНЫЕ ДИСТАНЦИИ И СТРУКТУРА ТРЕНИРОВОК ══╗\n";
    $block .= "│                                                                         │\n";
    $minEasyR = getMinEasyKm($userData);
    $block .= "│ EASY (лёгкий бег):                                                      │\n";
    $block .= "│  - МИНИМУМ {$minEasyR} км (для уровня пользователя). Пробежки короче — бессмысленны.│\n";
    $block .= "│  - Типичная дистанция: 8-12 км для марафонца, 6-10 км для полумарафонца, │\n";
    $block .= "│    3-5 км для начинающих (weekly_base < 15 км).                           │\n";
    $block .= "│  - Easy = 15-25% недельного объёма. При 50 км/нед → easy 8-12 км.        │\n";
    $block .= "│  - Easy темп: ВСЕГДА медленнее темпового на 1:00-1:30/км.                │\n";
    $block .= "│  - Все easy в одной неделе должны быть ПОХОЖЕЙ дистанции (±2 км).        │\n";
    $block .= "│                                                                         │\n";
    $block .= "│ TEMPO (темповый бег):                                                   │\n";
    $block .= "│  - ОБЯЗАТЕЛЬНО заполнять distance_km и pace структурированными полями.   │\n";
    $block .= "│  - distance_km = вся дистанция включая разминку и заминку.               │\n";
    $block .= "│  - pace = целевой темп темповой части.                                   │\n";
    $block .= "│  - Разминку и заминку описывать в notes (напр. \"Разминка 2 км, заминка 1.5 км\"). │\n";
    $block .= "│  - МИНИМУМ 6 км (включая разминку/заминку). Темповая часть: 3-12 км.     │\n";
    $block .= "│  - НИКОГДА не писать description текстом! Только distance_km + pace + notes. │\n";
    $block .= "│                                                                         │\n";
    $block .= "│ LONG (длительный бег):                                                  │\n";
    $block .= "│  - Прирост длительной: не более +2-3 км/нед (или +10%).                 │\n";
    $block .= "│  - Каждые 3-4 недели — разгрузочная длительная (на 30% короче пиковой).  │\n";
    $block .= "│  - Максимум: 33 км для марафона, 18-21 км для полумарафона.              │\n";
    $block .= "│                                                                         │\n";
    $block .= "│ RACE WEEK (последняя неделя перед забегом):                              │\n";
    $block .= "│  - Объём -50-60% от пиковой недели.                                     │\n";
    $block .= "│  - Easy: 3-6 км (допустимо ниже обычного минимума в race week).          │\n";
    $block .= "│  - Максимум 3-4 беговые тренировки + день забега.                       │\n";
    $block .= "│  - За 2 дня до забега — отдых (rest). За 1 день — лёгкий 3-4 км или rest.│\n";
    $block .= "│  - НИКАКИХ интервалов, темповых. Можно: 3-4 × 100м страйды (в notes).   │\n";
    $block .= "╚═════════════════════════════════════════════════════════════════════════╝\n\n";

    return $block;
}

function buildFormatResponseBlock($userData = null) {
    $block = "═══ ФОРМАТ ОТВЕТА (только этот JSON) ═══\n\n";
    $block .= "Верни один JSON-объект с ключом \"weeks\". В каждой неделе массив \"days\" из ровно 7 элементов (пн … вс).\n";
    $block .= "Тип дня (type): easy, long, tempo, interval, fartlek, control, rest, other (ОФП), sbu, race, free.\n\n";

    $block .= "КРИТИЧНО: Каждый день — объект со ВСЕМИ полями ниже. Неиспользуемые = null. Пропуск полей запрещён!\n\n";

    $block .= "ПОЛНЫЙ ШАБЛОН ДНЯ (все поля):\n";
    $block .= "{\"type\":\"...\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $block .= "ПРИМЕРЫ:\n\n";

    // Основные примеры бега — всегда показываем
    $block .= "easy: {\"type\":\"easy\",\"distance_km\":8,\"pace\":\"6:00\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $block .= "long: {\"type\":\"long\",\"distance_km\":20,\"pace\":\"6:20\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "tempo (distance_km = ВСЯ дистанция с разминкой и заминкой, pace = темп темповой части):\n";
    $block .= "{\"type\":\"tempo\",\"distance_km\":10,\"pace\":\"4:45\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":\"Разминка 2 км, темповая часть 6 км, заминка 2 км\",\"is_key_workout\":true}\n\n";

    $block .= "interval (distance_km=null — код посчитает!):\n";
    $block .= "{\"type\":\"interval\",\"distance_km\":null,\"pace\":null,\"warmup_km\":2,\"cooldown_km\":1.5,\"reps\":5,\"interval_m\":1000,\"interval_pace\":\"4:20\",\"rest_m\":400,\"rest_type\":\"jog\",\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "fartlek (distance_km=null — код посчитает!):\n";
    $block .= "{\"type\":\"fartlek\",\"distance_km\":null,\"pace\":null,\"warmup_km\":2,\"cooldown_km\":1.5,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":[{\"reps\":6,\"distance_m\":200,\"pace\":\"4:30\",\"recovery_m\":200,\"recovery_type\":\"jog\"}],\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $block .= "rest: {\"type\":\"rest\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    // ОФП и СБУ — только если пользователь их включил
    $hasOfp = !empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days']);
    if ($hasOfp) {
        $block .= "other (ОФП): {\"type\":\"other\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":[{\"name\":\"Приседания\",\"sets\":3,\"reps\":10,\"weight_kg\":null,\"distance_m\":null,\"duration_min\":null}],\"duration_minutes\":30,\"notes\":null,\"is_key_workout\":false}\n\n";

        $block .= "sbu: {\"type\":\"sbu\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":[{\"name\":\"Бег с высоким подниманием бедра\",\"sets\":null,\"reps\":null,\"weight_kg\":null,\"distance_m\":30,\"duration_min\":null}],\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";
    }

    $block .= "ПРАВИЛА ФОРМАТА:\n";
    $block .= "- НЕ генерируй поле \"description\" — код построит автоматически.\n";
    $block .= "- distance_km: для easy/long/tempo/control/race — ОБЯЗАТЕЛЬНО. Для interval/fartlek — null (код посчитает).\n";
    $block .= "- pace: для easy/long — E-темп из зон. Для tempo — T-темп. Для interval/fartlek/control/race — null.\n";
    $block .= "- Ответ: только JSON. Начинается с { и заканчивается }. Без комментариев.\n\n";

    $block .= "⚠️ ФИНАЛЬНАЯ ПРОВЕРКА ПЕРЕД ГЕНЕРАЦИЕЙ:\n";
    $minEasyF = getMinEasyKm($userData);
    $block .= "- Easy distance_km ≥ {$minEasyF} (для данного пользователя; race week допустимо от 3 км)\n";
    $block .= "- Tempo distance_km ≥ 6 (включая разминку/заминку)\n";
    $block .= "- Long НЕ растёт более чем на 3 км в неделю\n";
    $block .= "- Все easy в одной неделе примерно одинаковые (±2 км)\n";
    $block .= "- Темпы СТРОГО из зон (E/M/T/I/R), НЕ из комфортного темпа\n";

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
    $prompt .= buildTrainingStateBlock($userData);
    $prompt .= buildWeekSkeletonBlock($userData);
    $prompt .= buildPaceZonesBlock($userData);
    $prompt .= buildTrainingPrinciplesBlock($userData, $goalType);
    $prompt .= buildKeyWorkoutsBlock($userData);
    $prompt .= buildWorkoutIntentBlock($userData, $goalType);
    $prompt .= buildMandatoryRulesBlock($userData);

    $prompt .= "═══ ЗАДАЧА ═══\n\n";
    $prompt .= "Сформируй персональный план по данным пользователя и по блоку «ПРИНЦИПЫ И СТРУКТУРА ПЛАНА» выше. Учитывай предпочтения по дням, объём (weekly_base_km, sessions_per_week), уровень подготовки и ограничения по здоровью.\n";
    $prompt .= "Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.) — все поля в каждом дне, неиспользуемые = null.\n";
    $prompt .= "НЕ генерируй поле \"description\" — код построит его автоматически. НЕ считай distance_km и duration_minutes для интервалов/фартлеков — код посчитает.\n\n";

    $prompt .= buildFormatResponseBlock($userData ?? $modifiedUser ?? null);

    return $prompt;
}

// ════════════════════════════════════════════════════════════════
// 1b. ЧАСТИЧНАЯ ГЕНЕРАЦИЯ (сплит длинного плана на чанки ≤16 нед)
// ════════════════════════════════════════════════════════════════

/**
 * Определить, нужно ли разбивать план на несколько вызовов LLM.
 * Порог: >16 недель (или >30 — жёсткий лимит).
 *
 * @return array|null  Массив чанков [{week_from, week_to, phase_label, start_date}] или null (не нужно)
 */
function computePlanChunks($userData, $goalType): ?array {
    $totalWeeks = getSuggestedPlanWeeks($userData, $goalType);
    if (!$totalWeeks || $totalWeeks <= 16) {
        return null; // Помещается в один вызов
    }

    // Жёсткий лимит — 30 недель
    $totalWeeks = min($totalWeeks, 30);

    $startDate = $userData['training_start_date'] ?? date('Y-m-d');

    // Попытка разбить по фазам макроцикла (race/time_improvement)
    if (in_array($goalType, ['race', 'time_improvement'])) {
        $mc = computeMacrocycle($userData, $goalType);
        if ($mc && !empty($mc['phases'])) {
            return _splitByMacrocyclePhases($mc['phases'], $totalWeeks, $startDate);
        }
    }

    // Для health/weight_loss — равномерный сплит пополам
    $firstHalf = (int) ceil($totalWeeks / 2);
    $secondHalf = $totalWeeks - $firstHalf;

    $chunks = [];
    $chunks[] = [
        'week_from' => 1,
        'week_to' => $firstHalf,
        'weeks_count' => $firstHalf,
        'phase_label' => 'Часть 1 (недели 1–' . $firstHalf . ')',
        'start_date' => $startDate,
    ];
    $chunks[] = [
        'week_from' => $firstHalf + 1,
        'week_to' => $totalWeeks,
        'weeks_count' => $secondHalf,
        'phase_label' => 'Часть 2 (недели ' . ($firstHalf + 1) . '–' . $totalWeeks . ')',
        'start_date' => date('Y-m-d', strtotime($startDate . ' + ' . ($firstHalf * 7) . ' days')),
    ];

    return $chunks;
}

/**
 * Группирует фазы макроцикла в чанки ≤16 недель.
 */
function _splitByMacrocyclePhases(array $phases, int $totalWeeks, string $startDate): array {
    $chunks = [];
    $currentChunk = null;
    $maxChunkWeeks = 16;

    foreach ($phases as $phase) {
        $phaseWeeks = $phase['weeks_to'] - $phase['weeks_from'] + 1;

        if ($currentChunk === null) {
            $currentChunk = [
                'week_from' => $phase['weeks_from'],
                'week_to' => $phase['weeks_to'],
                'weeks_count' => $phaseWeeks,
                'phase_label' => $phase['label'],
                'phases' => [$phase],
            ];
        } elseif ($currentChunk['weeks_count'] + $phaseWeeks <= $maxChunkWeeks) {
            // Помещается в текущий чанк
            $currentChunk['week_to'] = $phase['weeks_to'];
            $currentChunk['weeks_count'] += $phaseWeeks;
            $currentChunk['phase_label'] .= ' + ' . $phase['label'];
            $currentChunk['phases'][] = $phase;
        } else {
            // Закрываем текущий чанк, начинаем новый
            $chunks[] = $currentChunk;
            $currentChunk = [
                'week_from' => $phase['weeks_from'],
                'week_to' => $phase['weeks_to'],
                'weeks_count' => $phaseWeeks,
                'phase_label' => $phase['label'],
                'phases' => [$phase],
            ];
        }
    }
    if ($currentChunk !== null) {
        $chunks[] = $currentChunk;
    }

    // Вычисляем start_date для каждого чанка
    foreach ($chunks as $i => &$chunk) {
        $offsetDays = ($chunk['week_from'] - 1) * 7;
        $chunk['start_date'] = date('Y-m-d', strtotime($startDate . ' + ' . $offsetDays . ' days'));
        unset($chunk['phases']); // Не нужно дальше
    }
    unset($chunk);

    return $chunks;
}

/**
 * Промпт для генерации ЧАСТИ длинного плана (чанк).
 *
 * Содержит полный контекст пользователя, принципы тренировок, макроцикл,
 * но задача ограничена конкретным диапазоном недель.
 *
 * @param array $userData      Данные пользователя
 * @param string $goalType     Тип цели
 * @param array $chunk         {week_from, week_to, weeks_count, phase_label, start_date}
 * @param int $totalWeeks      Общее количество недель плана
 * @param int $chunkIndex      Номер чанка (0-based)
 * @param int $totalChunks     Общее количество чанков
 * @param array|null $prevLastWeek  Последняя неделя предыдущего чанка (для преемственности)
 * @return string
 */
function buildPartialPlanPrompt($userData, $goalType, array $chunk, int $totalWeeks, int $chunkIndex, int $totalChunks, ?array $prevLastWeek = null): string {
    $prompt = "";

    $prompt .= "Ты опытный тренер по бегу. Строй план по данным пользователя и научно обоснованным принципам: прогрессия нагрузки, восстановление, периодизация (где уместно), распределение интенсивности.\n";
    $prompt .= "Отвечай ТОЛЬКО валидным JSON без комментариев и лишнего текста. Все решения опирай на указанные ниже данные пользователя и на принципы для выбранной цели.\n\n";

    $prompt .= buildUserInfoBlock($userData);
    $prompt .= buildGoalBlock($userData, $goalType);

    // Контекст сплита
    $prompt .= "\n═══ КОНТЕКСТ ГЕНЕРАЦИИ (ЧАСТЬ ПЛАНА) ═══\n\n";
    $prompt .= "Полный план: {$totalWeeks} недель. Ты генерируешь часть " . ($chunkIndex + 1) . " из {$totalChunks}.\n";
    $prompt .= "Текущий блок: недели {$chunk['week_from']}–{$chunk['week_to']} ({$chunk['weeks_count']} недель).\n";
    $prompt .= "Фаза(ы): {$chunk['phase_label']}.\n";
    $prompt .= "Дата начала этого блока: {$chunk['start_date']}.\n";
    $prompt .= "Количество недель для генерации: {$chunk['weeks_count']}. Сформируй ровно столько недель.\n";
    $prompt .= "Нумерация week_number: от 1 до {$chunk['weeks_count']} (относительная, не абсолютная).\n\n";

    // Если есть предыдущий чанк — передаём последнюю неделю для плавного перехода
    if ($prevLastWeek !== null && !empty($prevLastWeek['days'])) {
        $prompt .= "ПОСЛЕДНЯЯ НЕДЕЛЯ ПРЕДЫДУЩЕГО БЛОКА (для плавного перехода):\n";
        $prevSummary = [];
        foreach ($prevLastWeek['days'] as $di => $day) {
            $type = $day['type'] ?? 'rest';
            $dist = isset($day['distance_km']) && $day['distance_km'] ? $day['distance_km'] . ' км' : '';
            $prevSummary[] = "день " . ($di + 1) . ": {$type}" . ($dist ? " ({$dist})" : '');
        }
        $prompt .= implode(', ', $prevSummary) . "\n";
        // Посчитаем суммарный объём предыдущей недели
        $prevVolume = 0;
        foreach ($prevLastWeek['days'] as $day) {
            if (isset($day['distance_km']) && $day['distance_km']) {
                $prevVolume += (float) $day['distance_km'];
            }
        }
        if ($prevVolume > 0) {
            $prompt .= "Объём предыдущей недели: ~{$prevVolume} км.\n";
        }
        $prompt .= "Первая неделя текущего блока должна ПЛАВНО ПРОДОЛЖАТЬ прогрессию (без скачков объёма >10%).\n\n";
    }

    $prompt .= buildPreferencesBlock($userData);
    $prompt .= buildPaceZonesBlock($userData);
    $prompt .= buildTrainingPrinciplesBlock($userData, $goalType);
    $prompt .= buildKeyWorkoutsBlock($userData);
    $prompt .= buildMandatoryRulesBlock($userData);

    $prompt .= "═══ ЗАДАЧА ═══\n\n";
    $prompt .= "Сформируй {$chunk['weeks_count']} недель тренировочного плана (часть " . ($chunkIndex + 1) . " из {$totalChunks} общего плана на {$totalWeeks} недель).\n";
    $prompt .= "Эти недели соответствуют неделям {$chunk['week_from']}–{$chunk['week_to']} общего плана. Учитывай фазу макроцикла для этих недель.\n";
    $prompt .= "Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.) — все поля в каждом дне, неиспользуемые = null.\n";
    $prompt .= "НЕ генерируй поле \"description\" — код построит его автоматически. НЕ считай distance_km и duration_minutes для интервалов/фартлеков — код посчитает.\n\n";

    $prompt .= buildFormatResponseBlock($userData ?? $modifiedUser ?? null);

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
    $generationMode = (string) ($recalcContext['generation_mode'] ?? 'strict');

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
    if ($generationMode !== 'flexible') {
        $prompt .= buildTrainingStateBlock($modifiedUser);
        $prompt .= buildWeekSkeletonBlock($modifiedUser);
    }
    $prompt .= buildPaceZonesBlock($modifiedUser);

    // ── Текущее состояние (КЛЮЧЕВОЙ блок для пересчёта) ──
    $prompt .= "\n" . buildRecalcContextBlock($recalcContext, $origStartDate);

    // ── Принципы тренировок (для пересчёта — с оригинальным макроциклом) ──
    $prompt .= buildRecalcTrainingPrinciplesBlock($userData, $goalType, $recalcContext);
    $prompt .= buildKeyWorkoutsBlock($modifiedUser);
    $prompt .= buildWorkoutIntentBlock($modifiedUser, $goalType, $generationMode === 'flexible');
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

    $detrainingFactor = $recalcContext['detraining_factor'] ?? null;
    if ($detrainingFactor !== null && $detrainingFactor >= 0.95) {
        $prompt .= "1. Пауза минимальна — ПРОДОЛЖАЙ с текущего объёма и структуры.\n";
        $prompt .= "   - Первая неделя — обычная (не разгрузочная), соответствует текущей фазе макроцикла.\n";
        $prompt .= "   - Начальный объём = средний реальный объём за последние 4 недели.\n";
    } elseif ($detrainingFactor !== null && $detrainingFactor >= 0.85) {
        $prompt .= "1. Лёгкое снижение формы — первая неделя на 10-15% ниже последнего реального объёма.\n";
        $prompt .= "   - Можно сразу включить ключевые (облегчённые). Со 2-й недели — обычная структура.\n";
    } else {
        $prompt .= "1. Первые 1-2 недели — плавный возврат к нагрузке:\n";
        $prompt .= "   - Начальный объём = средний реальный объём за последние 4 недели × коэффициент формы.\n";
        $prompt .= "   - Первая неделя — только лёгкий бег (easy) и длительная (сокращённая).\n";
        $prompt .= "   - Вторая неделя — можно вернуть 1 ключевую тренировку (облегчённую).\n";
        $prompt .= "   - ПРИОРИТЕТ ВОЗВРАТА: сначала восстанавливай скоростную работу (интервалы, короткий темп) — VO2max теряется быстрее всего. Аэробная база сохраняется дольше.\n";
    }
    $prompt .= "2. Далее — стандартная прогрессия (до +10%/нед), возвращение к обычной структуре.\n";
    $prompt .= "3. Если до забега мало времени — сжать фазы, но НЕ форсировать нагрузку.\n";
    $prompt .= "4. Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.).\n";
    $prompt .= "5. НЕ генерируй \"description\" — код построит автоматически. Поле description ЗАПРЕЩЕНО.\n";
    $minEasyRecalc = getMinEasyKm($userData);
    $prompt .= "6. Easy бег: МИНИМУМ {$minEasyRecalc} км (кроме race week). Все easy в неделе — одинаковой дистанции (±2 км).\n";
    $prompt .= "7. Tempo: ОБЯЗАТЕЛЬНО distance_km + pace. Разминку/заминку — в notes.\n";
    $prompt .= "8. НЕ добавляй поле \"date\" в дни — код вычислит даты автоматически из start_date.\n";
    if ($generationMode === 'flexible') {
        $prompt .= "9. Конкретную структуру tempo/control/interval подбирай самостоятельно, но не нарушай фазовые ограничения и recovery-логику.\n";
    }

    // ── Критичное напоминание: расписание по дням (повтор для надёжности) ──
    $ruDayLabelsRecalc = ['mon'=>'Пн','tue'=>'Вт','wed'=>'Ср','thu'=>'Чт','fri'=>'Пт','sat'=>'Сб','sun'=>'Вс'];
    if (!empty($userData['preferred_days']) && is_array($userData['preferred_days'])) {
        $daysList = implode(', ', array_map(fn($d) => $ruDayLabelsRecalc[$d] ?? $d, $userData['preferred_days']));
        $sessionsCount = count($userData['preferred_days']);

        // Определяем выходные дни (все дни НЕ в preferred_days и НЕ в preferred_ofp_days)
        $allDayCodes = ['mon','tue','wed','thu','fri','sat','sun'];
        $ofpDays = $userData['preferred_ofp_days'] ?? [];
        $restDays = array_diff($allDayCodes, $userData['preferred_days'], is_array($ofpDays) ? $ofpDays : []);
        $restDaysList = implode(', ', array_map(fn($d) => $ruDayLabelsRecalc[$d] ?? $d, $restDays));

        $prompt .= "\n⚠️ КРИТИЧНО — РАСПИСАНИЕ (соблюдай СТРОГО при пересчёте!):\n";
        $prompt .= "- Беговые тренировки ТОЛЬКО в эти дни: {$daysList} ({$sessionsCount} дней).\n";
        $prompt .= "- ВЫХОДНЫЕ ДНИ (type: rest): {$restDaysList}. В эти дни ЗАПРЕЩЁН бег!\n";
        $prompt .= "- Порядок дней в массиве days: [Пн(0), Вт(1), Ср(2), Чт(3), Пт(4), Сб(5), Вс(6)].\n";
        $prompt .= "- Каждая неделя = ровно 7 дней. Выходные дни = {\"type\":\"rest\",...все null}.\n\n";
    }

    // ── Формат ──
    $prompt .= buildFormatResponseBlock($userData ?? $modifiedUser ?? null);

    return $prompt;
}

/**
 * Блок принципов тренировок для ПЕРЕСЧЁТА.
 *
 * В отличие от buildTrainingPrinciplesBlock(), использует оригинальный макроцикл
 * (от реального training_start_date), чтобы AI продолжал с текущей фазы,
 * а не строил макроцикл с нуля.
 */
function buildRecalcTrainingPrinciplesBlock($userData, $goalType, array $recalcContext) {
    $phase = $recalcContext['current_phase'] ?? null;

    // Для не-соревновательных целей или если фаза не определена — стандартный блок
    if (!$phase || !in_array($goalType, ['race', 'time_improvement'])) {
        return buildTrainingPrinciplesBlock($userData, $goalType);
    }

    $block = "\n═══ ПРИНЦИПЫ И СТРУКТУРА ПЛАНА (ПЕРЕСЧЁТ) ═══\n\n";

    $goalLabel = $goalType === 'race' ? 'подготовка к забегу' : 'улучшение результата';
    $block .= "Цель: {$goalLabel}.\n\n";

    $phaseLabel = (string) ($phase['phase_label'] ?? $phase['label'] ?? '');
    $weeksLeftInPhase = isset($phase['weeks_left_in_phase']) ? (int) $phase['weeks_left_in_phase'] : 0;
    $nextPhaseLabel = (string) ($phase['next_phase_label'] ?? '');

    $block .= "ВАЖНО: Это ПЕРЕСЧЁТ. Макроцикл уже начат. НЕ СТРОЙ периодизацию с нуля!\n";
    $block .= "Текущая фаза: " . ($phaseLabel !== '' ? $phaseLabel : 'не определена') . ".\n";
    if ($weeksLeftInPhase > 0) {
        $block .= "Осталось в текущей фазе: {$weeksLeftInPhase} нед.\n";
    }
    if ($nextPhaseLabel !== '') {
        $block .= "Следующая фаза: {$nextPhaseLabel}.\n";
    }
    $block .= "\n";

    // Оставшиеся фазы с описаниями
    if (!empty($phase['remaining_phases'])) {
        $block .= "СТРУКТУРА ОСТАВШИХСЯ ФАЗ (нумерация 1..N для генерации):\n";
        $weeksToGenerate = $recalcContext['weeks_to_generate'] ?? null;
        $weeksIntoPhase = $phase['weeks_into_phase'] ?? 0;
        $weekOffset = 0;
        foreach ($phase['remaining_phases'] as $i => $rp) {
            $phaseDuration = $rp['weeks_to'] - $rp['weeks_from'] + 1;
            // Первая фаза: вычитаем уже пройденные недели
            if ($i === 0 && $weeksIntoPhase > 0) {
                $phaseDuration -= $weeksIntoPhase;
            }
            $newFrom = $weekOffset + 1;
            $newTo = $weekOffset + $phaseDuration;
            if ($weeksToGenerate && $newTo > $weeksToGenerate) {
                $newTo = $weeksToGenerate;
                $phaseDuration = $newTo - $newFrom + 1;
            }
            if ($phaseDuration > 0) {
                $label = (string) ($rp['label'] ?? $rp['name'] ?? 'Фаза');
                $description = trim((string) ($rp['description'] ?? ''));
                $maxKeyWorkouts = isset($rp['max_key_workouts']) ? (int) $rp['max_key_workouts'] : 0;
                $block .= "- {$label} (нед. {$newFrom}-{$newTo})";
                if ($description !== '') {
                    $block .= ": {$description}";
                }
                $block .= " Ключевых: до {$maxKeyWorkouts}/нед.\n";
            }
            $weekOffset += $phaseDuration;
            if ($weeksToGenerate && $weekOffset >= $weeksToGenerate) break;
        }
        $block .= "\n";
    }

    // Прогрессия длительной (пересчитанная на новую нумерацию)
    if (!empty($phase['long_run_progression'])) {
        $block .= "ПРОГРЕССИЯ ДЛИТЕЛЬНОЙ ПО НЕДЕЛЯМ (следуй этим числам!):\n";
        $longParts = [];
        $newWeek = 1;
        $dist = $userData['race_distance'] ?? '';
        $weeksToGenerate = $recalcContext['weeks_to_generate'] ?? 999;
        foreach ($phase['long_run_progression'] as $origWeek => $km) {
            if ($newWeek > $weeksToGenerate) break;
            if ($newWeek == $weeksToGenerate && in_array($dist, ['marathon', '42.2k', 'half', '21.1k', '5k', '10k'])) {
                $longParts[] = "нед{$newWeek}: забег";
            } else {
                $longParts[] = "нед{$newWeek}: {$km}";
            }
            $newWeek++;
        }
        $block .= implode(' → ', $longParts) . " (км)\n\n";
    }

    // Объёмы
    if (!empty($phase['peak_volume_km'])) {
        $startVol = $recalcContext['avg_weekly_km_4w'] ?? $phase['start_volume_km'] ?? '?';
        $block .= "ОБЪЁМЫ: текущий реальный ~{$startVol} км/нед → пиковый ~{$phase['peak_volume_km']} км/нед.\n";
    }
    $block .= "Правило: прирост не более 10%/нед. 80% объёма в лёгком темпе, до 20% — ключевые тренировки.\n";
    $bestActualLong = $recalcContext['best_actual_long_km'] ?? 0;
    if ($bestActualLong > 0) {
        $block .= "Лучшая ФАКТИЧЕСКАЯ длительная: {$bestActualLong} км — продолжай прогрессию от этого значения.\n";
    }
    $block .= "\n";

    // Разгрузочные недели
    if (!empty($phase['recovery_weeks'])) {
        $keptWeeks = $recalcContext['kept_weeks'] ?? 0;
        $newRecovery = [];
        foreach ($phase['recovery_weeks'] as $rw) {
            $newW = $rw - $keptWeeks;
            if ($newW >= 1) {
                $newRecovery[] = $newW;
            }
        }
        if (!empty($newRecovery)) {
            $rw = implode(', ', $newRecovery);
            $block .= "Разгрузочные недели (по новой нумерации): {$rw} (объём -20%, убрать интенсивность).\n";
        }
    }

    // Контрольные тренировки (пересчитанная нумерация)
    if (!empty($phase['control_weeks'] ?? null)) {
        $keptWeeks = $recalcContext['kept_weeks'] ?? 0;
        $newControl = [];
        foreach ($phase['control_weeks'] as $cw) {
            $newW = $cw - $keptWeeks;
            if ($newW >= 1) {
                $newControl[] = $newW;
            }
        }
        if (!empty($newControl)) {
            $spec = getDistanceSpec($userData['race_distance'] ?? '');
            $controlDist = $spec['control_dist'] ?? '3-5 км';
            $cwList = implode(', ', $newControl);
            $block .= "Контрольные забеги (по новой нумерации): нед. {$cwList}. Дистанция: {$controlDist}. type: control, pace: null.\n";
        }
    }

    // Тренировки по дистанции (из spec)
    $zones = calculatePaceZones($userData);
    if ($zones) {
        $block .= "\nИспользуй рассчитанные зоны: interval_pace=\"" . formatPace($zones['interval']) . "\", темповый pace=\"" . formatPace($zones['tempo']) . "\".\n\n";
    }

    $spec = getDistanceSpec($userData['race_distance'] ?? '');
    if ($spec) {
        $distLabels = ['5k' => '5 КМ', '10k' => '10 КМ', 'half' => 'ПОЛУМАРАФОНА', '21.1k' => 'ПОЛУМАРАФОНА', 'marathon' => 'МАРАФОНА', '42.2k' => 'МАРАФОНА'];
        $distLabel = $distLabels[$userData['race_distance'] ?? ''] ?? 'ЗАБЕГА';
        $block .= "ТРЕНИРОВКИ ДЛЯ {$distLabel}:\n";
        $block .= "- Интервалы: {$spec['intervals']}.\n";
        $block .= "- Темповый: {$spec['tempo']}.\n";
        $block .= "- Длительная: {$spec['long_desc']}.\n";
        $block .= "- Фартлек: {$spec['fartlek']}.\n";
        if (!empty($spec['marathon_pace'])) {
            $block .= "- Марафонский темп: {$spec['marathon_pace']}.\n";
        }
        $block .= "\n";
    }

    $block .= "distance_km для interval/fartlek: null (код посчитает). Для tempo/easy/long: ОБЯЗАТЕЛЬНО заполнять.\n\n";

    return $block;
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
        if ($avgRating >= 9) $ratingLabel = 'очень тяжело';
        elseif ($avgRating >= 7) $ratingLabel = 'тяжело';
        elseif ($avgRating >= 5) $ratingLabel = 'рабоче';
        elseif ($avgRating >= 3) $ratingLabel = 'легко';
        else $ratingLabel = 'очень легко';
        $lines[] = "Средняя субъективная тяжесть: {$avgRating}/10 ({$ratingLabel})";
    }

    // ACWR
    $acwr = $ctx['acwr'] ?? null;
    if ($acwr && $acwr['acwr'] !== null) {
        $acwrVal = $acwr['acwr'];
        $zoneLabels = [
            'low' => 'недогрузка — можно увеличивать объём',
            'optimal' => 'оптимально — безопасная зона',
            'caution' => 'ВНИМАНИЕ — не увеличивай нагрузку',
            'danger' => 'ОПАСНО — СНИЗЬ нагрузку, добавь день отдыха',
        ];
        $lines[] = "ACWR (нагрузка): {$acwrVal} — " . ($zoneLabels[$acwr['zone']] ?? '');
        if ($acwr['zone'] === 'danger') {
            $lines[] = "┃ ⚠ ACWR > 1.5 — ЗАПРЕЩЕНО увеличивать объём/интенсивность. Добавь extra отдых.";
        } elseif ($acwr['zone'] === 'caution') {
            $lines[] = "┃ ⚠ ACWR 1.3-1.5 — НЕ повышай нагрузку на этой неделе. Стабилизируй.";
        }
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
            $rating = !empty($w['rating']) ? "тяжесть {$w['rating']}/10" : '';
            $parts = array_filter([$date, $type, $dist, $pace, $rating]);
            $lines[] = "  - " . implode(', ', $parts);
            $shown++;
        }
    }

    // --- Структура сохранённых недель (агрегированная) ---
    $keptSummary = $ctx['kept_weeks_summary'] ?? [];
    if (!empty($keptSummary)) {
        $lines[] = "\n═══ ПРОЙДЕННЫЕ НЕДЕЛИ (НЕ генерируй заново!) ═══\n";
        $lines[] = "Прошлые {$keptWeeks} недель сохранены.";

        // Агрегируем по группам ~4 нед для компактности
        $chunkSize = max(3, (int) ceil(count($keptSummary) / 5));
        $chunks = array_chunk($keptSummary, $chunkSize);
        foreach ($chunks as $chunk) {
            $firstW = $chunk[0]['week'];
            $lastW = end($chunk)['week'];
            $vols = array_column($chunk, 'volume');
            $vols = array_filter($vols, fn($v) => $v > 0);
            $volMin = !empty($vols) ? round(min($vols), 0) : '?';
            $volMax = !empty($vols) ? round(max($vols), 0) : '?';
            // Собираем уникальные типы тренировок
            $allTypes = [];
            foreach ($chunk as $ws) {
                foreach (explode(',', $ws['types'] ?? '') as $t) {
                    $t = trim($t);
                    if ($t !== '' && $t !== 'rest') $allTypes[$t] = true;
                }
            }
            $typesStr = !empty($allTypes) ? implode(', ', array_keys($allTypes)) : 'rest';
            $lines[] = "  Нед. {$firstW}-{$lastW}: объём {$volMin}→{$volMax} км/нед, типы: {$typesStr}";
        }

        $maxLong = $ctx['max_planned_long_km'] ?? 0;
        $maxVol = $ctx['max_planned_volume_km'] ?? 0;
        $bestActual = $ctx['best_actual_long_km'] ?? 0;
        if ($maxLong > 0) $lines[] = "Макс. запланированная длительная: {$maxLong} км";
        if ($bestActual > 0) $lines[] = "Лучшая ФАКТИЧЕСКАЯ длительная: {$bestActual} км (продолжай прогрессию от этого числа)";
        if ($maxVol > 0) $lines[] = "Макс. недельный объём: {$maxVol} км";
    }

    // --- Последние недели ПЛАНА (детальная структура для продолжения) ---
    $lastPlanWeeks = $ctx['last_plan_weeks'] ?? [];
    if (!empty($lastPlanWeeks)) {
        $dayNames = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $lines[] = "\n═══ ПОСЛЕДНИЕ НЕДЕЛИ ПЛАНА (ПРОДОЛЖАЙ ОТ ЭТОЙ СТРУКТУРЫ!) ═══\n";
        $lines[] = "Ниже — детальная структура последних запланированных недель. ПРОДОЛЖАЙ с такими же дистанциями easy, темпами и структурой. Не начинай заново!\n";
        foreach ($lastPlanWeeks as $lpw) {
            $wn = $lpw['week_number'];
            $vol = $lpw['total_volume'] ?? '?';
            $lines[] = "Неделя {$wn} (объём ~{$vol} км):";
            foreach ($lpw['days'] as $d) {
                $dn = $dayNames[$d['day']] ?? '?';
                $type = $d['type'];
                $desc = $d['desc'] ?? '';
                // Компактно: убираем слишком длинные описания
                if (mb_strlen($desc) > 60) {
                    $desc = mb_substr($desc, 0, 57) . '...';
                }
                $desc = str_replace("\n", ' | ', $desc);
                if ($type === 'rest') {
                    $lines[] = "  {$dn}: отдых";
                } else {
                    $lines[] = "  {$dn}: {$type}" . ($desc ? " — {$desc}" : "");
                }
            }
        }
        $lines[] = "";
    }

    // --- Текущая фаза макроцикла (краткое резюме, детали — в блоке принципов) ---
    $phase = $ctx['current_phase'] ?? null;
    if ($phase) {
        $phaseLabel = (string) ($phase['phase_label'] ?? $phase['label'] ?? 'не определена');
        $weeksLeft = isset($phase['weeks_left_in_phase']) ? (int) $phase['weeks_left_in_phase'] : 0;
        $lines[] = "\nТЕКУЩАЯ ФАЗА МАКРОЦИКЛА: {$phaseLabel} (осталось {$weeksLeft} нед.)";
        $lines[] = "ВАЖНО: ПРОДОЛЖАЙ С ТЕКУЩЕЙ ФАЗЫ, не начинай макроцикл с нуля! Детали фаз — в блоке ПРИНЦИПЫ ниже.";
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
    $prompt .= "7. НЕ генерируй \"description\" — код построит автоматически.\n";
    $prompt .= "8. НЕ добавляй поле \"date\" в дни — код вычислит даты автоматически.\n";

    // ── Критичное напоминание: расписание по дням ──
    $ruDayLabelsNext = ['mon'=>'Пн','tue'=>'Вт','wed'=>'Ср','thu'=>'Чт','fri'=>'Пт','sat'=>'Сб','sun'=>'Вс'];
    if (!empty($userData['preferred_days']) && is_array($userData['preferred_days'])) {
        $daysList = implode(', ', array_map(fn($d) => $ruDayLabelsNext[$d] ?? $d, $userData['preferred_days']));
        $allDayCodes = ['mon','tue','wed','thu','fri','sat','sun'];
        $ofpDays = $userData['preferred_ofp_days'] ?? [];
        $restDays = array_diff($allDayCodes, $userData['preferred_days'], is_array($ofpDays) ? $ofpDays : []);
        $restDaysList = implode(', ', array_map(fn($d) => $ruDayLabelsNext[$d] ?? $d, $restDays));

        $prompt .= "\n⚠️ РАСПИСАНИЕ (соблюдай СТРОГО!):\n";
        $prompt .= "- Бег ТОЛЬКО: {$daysList}. ВЫХОДНЫЕ (rest): {$restDaysList}.\n";
        $prompt .= "- Каждая неделя = 7 дней [Пн(0)…Вс(6)]. Выходные = {\"type\":\"rest\",...}.\n\n";
    }

    // ── Формат ──
    $prompt .= buildFormatResponseBlock($userData ?? $modifiedUser ?? null);

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
        if ($avgRating >= 9) $ratingLabel = 'очень тяжело';
        elseif ($avgRating >= 7) $ratingLabel = 'тяжело';
        elseif ($avgRating >= 5) $ratingLabel = 'рабоче';
        elseif ($avgRating >= 3) $ratingLabel = 'легко';
        else $ratingLabel = 'очень легко';
        $lines[] = "  Средняя субъективная тяжесть: {$avgRating}/10 ({$ratingLabel})";
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
                !empty($kw['rating']) ? "тяжесть {$kw['rating']}/10" : '',
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
            $rating = !empty($w['rating']) ? "тяжесть {$w['rating']}/10" : '';
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
