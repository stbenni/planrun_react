#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seeds completed workouts for the synthetic live50 users and runs live
 * recalculation through PlanGenerationProcessorService.
 *
 * Usage:
 *   php scripts/live_recalculate_batch.php --prefix=live50_20260424
 *   php scripts/live_recalculate_batch.php --scenario-mode=mixed
 *   php scripts/live_recalculate_batch.php --limit=5 --dry-run=1
 *   php scripts/live_recalculate_batch.php --skip-seed=1 --cutoff-date=2026-05-25
 */

set_time_limit(0);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PlanGenerationProcessorService.php';

function liveRecalcParseArgs(array $argv): array
{
    $args = [
        'prefix' => 'live50_20260424',
        'limit' => '50',
        'completed-weeks' => '4',
        'cutoff-date' => '',
        'save-dir' => dirname(__DIR__) . '/tmp/live_plan_generation',
        'fast-llm-fallback' => '1',
        'scenario-mode' => 'deterministic',
        'skip-seed' => '0',
        'skip-recalculate' => '0',
        'dry-run' => '0',
    ];

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0] ?? '';
        $value = $parts[1] ?? '1';
        if ($key !== '') {
            $args[$key] = $value;
        }
    }

    return $args;
}

function liveRecalcBool(mixed $value): bool
{
    return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
}

function liveRecalcSetEnv(string $key, string $value): void
{
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

function liveRecalcDateAddWeeks(string $date, int $weeks): string
{
    return (new DateTimeImmutable($date))->modify("+{$weeks} weeks")->format('Y-m-d');
}

function liveRecalcFetchUsers(mysqli $db, string $prefix, int $limit): array
{
    $like = $prefix . '%';
    $stmt = $db->prepare(
        'SELECT *
         FROM users
         WHERE username LIKE ?
         ORDER BY id ASC
         LIMIT ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare users failed: ' . $db->error);
    }

    $stmt->bind_param('si', $like, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function liveRecalcCaseCode(array $user): string
{
    $username = (string) ($user['username'] ?? '');
    if (preg_match('/live50_\d{8}_(\d{2})_(.+)$/', $username, $matches)) {
        return (string) $matches[2];
    }

    return $username;
}

function liveRecalcFetchPlanDays(mysqli $db, int $userId, string $fromDate, string $cutoffDate): array
{
    $stmt = $db->prepare(
        "SELECT w.week_number,
                d.day_of_week,
                d.date,
                d.type,
                d.description,
                d.is_key_workout,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.distance_m ELSE 0 END), 0) AS distance_m,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.duration_sec ELSE 0 END), 0) AS duration_sec,
                MAX(CASE WHEN e.category = 'run' THEN e.pace END) AS pace,
                COUNT(CASE WHEN e.category IN ('ofp', 'sbu') THEN 1 END) AS strength_items
         FROM training_plan_days d
         INNER JOIN training_plan_weeks w ON w.id = d.week_id AND w.user_id = d.user_id
         LEFT JOIN training_day_exercises e ON e.plan_day_id = d.id AND e.user_id = d.user_id
         WHERE d.user_id = ?
           AND d.date >= ?
           AND d.date < ?
           AND d.type <> 'rest'
         GROUP BY d.id, w.week_number, d.day_of_week, d.date, d.type, d.description, d.is_key_workout
         ORDER BY d.date ASC, d.day_of_week ASC"
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare plan days failed: ' . $db->error);
    }

    $stmt->bind_param('iss', $userId, $fromDate, $cutoffDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function liveRecalcFetchSavedPlan(mysqli $db, int $userId): array
{
    $weeksStmt = $db->prepare(
        'SELECT id, week_number, start_date, total_volume
         FROM training_plan_weeks
         WHERE user_id = ?
         ORDER BY week_number ASC, start_date ASC'
    );
    if (!$weeksStmt) {
        throw new RuntimeException('Prepare saved weeks failed: ' . $db->error);
    }

    $weeksStmt->bind_param('i', $userId);
    $weeksStmt->execute();
    $weekRows = $weeksStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $weeksStmt->close();

    $weeks = [];
    foreach ($weekRows as $week) {
        $weekId = (int) $week['id'];
        $weeks[$weekId] = [
            'id' => $weekId,
            'week_number' => (int) $week['week_number'],
            'start_date' => (string) $week['start_date'],
            'total_volume' => round((float) $week['total_volume'], 1),
            'days' => [],
        ];
    }

    if ($weeks === []) {
        return [];
    }

    $dayStmt = $db->prepare(
        "SELECT d.id,
                d.week_id,
                d.day_of_week,
                d.date,
                d.type,
                d.description,
                d.is_key_workout,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.distance_m ELSE 0 END), 0) AS distance_m,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.duration_sec ELSE 0 END), 0) AS duration_sec,
                MAX(CASE WHEN e.category = 'run' THEN e.pace END) AS pace
         FROM training_plan_days d
         LEFT JOIN training_day_exercises e ON e.plan_day_id = d.id AND e.user_id = d.user_id
         WHERE d.user_id = ?
         GROUP BY d.id, d.week_id, d.day_of_week, d.date, d.type, d.description, d.is_key_workout
         ORDER BY d.date ASC, d.day_of_week ASC"
    );
    if (!$dayStmt) {
        throw new RuntimeException('Prepare saved days failed: ' . $db->error);
    }

    $dayStmt->bind_param('i', $userId);
    $dayStmt->execute();
    $dayRows = $dayStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dayStmt->close();

    foreach ($dayRows as $day) {
        $weekId = (int) $day['week_id'];
        if (!isset($weeks[$weekId])) {
            continue;
        }
        $weeks[$weekId]['days'][] = [
            'day_of_week' => (int) $day['day_of_week'],
            'date' => (string) $day['date'],
            'type' => (string) $day['type'],
            'description' => trim((string) $day['description']),
            'is_key_workout' => !empty($day['is_key_workout']),
            'distance_km' => round(((int) $day['distance_m']) / 1000, 1),
            'duration_sec' => (int) $day['duration_sec'],
            'pace' => $day['pace'] !== null ? (string) $day['pace'] : null,
        ];
    }

    return array_values($weeks);
}

function liveRecalcSummarizePlan(array $weeks, string $cutoffDate): array
{
    $keptWeeks = [];
    $futureWeeks = [];
    $raceDays = [];
    $futurePeakLong = 0.0;
    $futureLongShareMax = 0.0;
    $futureWeekContexts = [];

    foreach ($weeks as $week) {
        $startDate = (string) ($week['start_date'] ?? '');
        $isFutureWeek = $startDate === '' || strcmp($startDate, $cutoffDate) >= 0;
        if (!$isFutureWeek) {
            $keptWeeks[] = $week;
        } else {
            $futureWeeks[] = $week;
        }

        $volume = (float) ($week['total_volume'] ?? 0.0);
        $futureHasRace = false;
        $futureRaceDistanceKm = 0.0;
        foreach ((array) ($week['days'] ?? []) as $day) {
            $type = (string) ($day['type'] ?? '');
            if ($type === 'race') {
                $futureHasRace = $isFutureWeek;
                $futureRaceDistanceKm = max($futureRaceDistanceKm, (float) ($day['distance_km'] ?? 0.0));
                $raceDays[] = [
                    'date' => (string) ($day['date'] ?? ''),
                    'week_number' => (int) ($week['week_number'] ?? 0),
                    'distance_km' => (float) ($day['distance_km'] ?? 0.0),
                ];
            }
            if ($type === 'long' && $isFutureWeek) {
                $distance = (float) ($day['distance_km'] ?? 0.0);
                $futurePeakLong = max($futurePeakLong, $distance);
                if ($volume > 0.0) {
                    $futureLongShareMax = max($futureLongShareMax, $distance / $volume);
                }
            }
        }

        if ($isFutureWeek) {
            $futureWeekContexts[] = [
                'week_number' => (int) ($week['week_number'] ?? 0),
                'start_date' => $startDate,
                'volume_km' => round($volume, 1),
                'has_race' => $futureHasRace,
                'race_distance_km' => round($futureRaceDistanceKm, 1),
            ];
        }
    }

    $futureVolumes = array_values(array_map(
        static fn(array $week): float => round((float) ($week['total_volume'] ?? 0.0), 1),
        $futureWeeks
    ));

    return [
        'weeks_count' => count($weeks),
        'kept_weeks_count' => count($keptWeeks),
        'future_weeks_count' => count($futureWeeks),
        'first_start_date' => (string) ($weeks[0]['start_date'] ?? ''),
        'last_start_date' => (string) ($weeks[count($weeks) - 1]['start_date'] ?? ''),
        'first_future_week_number' => (int) ($futureWeeks[0]['week_number'] ?? 0),
        'first_future_start_date' => (string) ($futureWeeks[0]['start_date'] ?? ''),
        'first_future_volume_km' => round((float) ($futureWeeks[0]['total_volume'] ?? 0.0), 1),
        'second_future_volume_km' => round((float) ($futureWeeks[1]['total_volume'] ?? 0.0), 1),
        'max_future_volume_km' => $futureVolumes !== [] ? round(max($futureVolumes), 1) : 0.0,
        'future_peak_long_km' => round($futurePeakLong, 1),
        'future_long_share_max' => round($futureLongShareMax, 2),
        'future_volumes_km' => $futureVolumes,
        'future_week_contexts' => $futureWeekContexts,
        'race_days' => $raceDays,
    ];
}

function liveRecalcDayName(int $dayOfWeek): string
{
    return [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'][$dayOfWeek] ?? 'day';
}

function liveRecalcRunTypes(): array
{
    return ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race', 'walking'];
}

function liveRecalcActivityTypeId(string $type, float $distanceKm): int
{
    if ($type === 'walking') {
        return 10;
    }
    if ($type === 'sbu') {
        return 9;
    }
    if ($distanceKm > 0.0 || in_array($type, liveRecalcRunTypes(), true)) {
        return 1;
    }

    return 2;
}

function liveRecalcParsePaceSec(?string $pace): ?int
{
    $pace = trim((string) $pace);
    if ($pace === '' || !preg_match('/^(\d{1,2}):(\d{2})$/', $pace, $matches)) {
        return null;
    }

    return ((int) $matches[1] * 60) + (int) $matches[2];
}

function liveRecalcFormatPace(int $seconds): string
{
    $seconds = max(1, $seconds);
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function liveRecalcFormatDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;

    return $hours > 0
        ? sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining)
        : sprintf('%02d:%02d', $minutes, $remaining);
}

function liveRecalcFallbackDistanceKm(string $type, int $weekNumber): float
{
    return match ($type) {
        'walking' => 2.0 + min(1.0, $weekNumber * 0.2),
        'easy' => 3.0 + min(4.0, $weekNumber * 0.5),
        'long' => 5.0 + min(8.0, $weekNumber * 1.0),
        'tempo', 'fartlek', 'control' => 5.0 + min(5.0, $weekNumber * 0.7),
        'interval' => 6.0 + min(5.0, $weekNumber * 0.7),
        'race' => 5.0,
        default => 0.0,
    };
}

function liveRecalcSeedProfile(array $user, string $scenarioMode = 'deterministic', int $index = 0): array
{
    if ($scenarioMode === 'mixed') {
        $scenarioIndex = $index % 5;
        $profile = match ($scenarioIndex) {
            0 => [
                'factor' => 1.00,
                'scenario' => 'on_track',
                'skip_types' => [],
                'skip_after_week' => 99,
                'adaptation_type' => null,
            ],
            1 => [
                'factor' => 0.78,
                'scenario' => 'volume_down',
                'skip_types' => [],
                'skip_after_week' => 99,
                'adaptation_type' => 'volume_down',
            ],
            2 => [
                'factor' => 0.95,
                'scenario' => 'missed_key',
                'skip_types' => ['tempo', 'interval', 'fartlek', 'control'],
                'skip_after_week' => 1,
                'adaptation_type' => 'simplify_key',
            ],
            3 => [
                'factor' => 1.12,
                'scenario' => 'overload_ok',
                'skip_types' => [],
                'skip_after_week' => 99,
                'adaptation_type' => 'volume_up',
            ],
            default => [
                'factor' => 0.62,
                'scenario' => 'pain_recovery',
                'skip_types' => ['tempo', 'interval', 'fartlek', 'control', 'long'],
                'skip_after_week' => 2,
                'adaptation_type' => 'insert_recovery',
            ],
        };
    } else {
        $profile = null;
    }

    $bucket = ((int) ($user['id'] ?? 0)) % 10;
    $factor = match ($bucket) {
        1 => 0.96,
        2 => 1.00,
        3 => 1.04,
        4 => 0.91,
        5 => 0.86,
        6 => 1.08,
        7 => 1.12,
        8 => 0.97,
        9 => 1.02,
        default => 0.89,
    };

    $notes = mb_strtolower((string) ($user['health_notes'] ?? ''), 'UTF-8');
    $isConservative = str_contains($notes, 'травм')
        || str_contains($notes, 'ахилл')
        || str_contains($notes, 'колен')
        || str_contains($notes, 'поясниц')
        || str_contains($notes, 'сон')
        || str_contains($notes, 'стресс')
        || str_contains($notes, 'послерод')
        || (int) ($user['birth_year'] ?? 3000) <= 1966;

    if ($isConservative) {
        $factor = min($factor, 0.92);
    }

    if ($profile !== null) {
        $profile['conservative'] = $isConservative;
        if ($isConservative && (float) ($profile['factor'] ?? 1.0) > 0.95) {
            $profile['factor'] = 0.92;
        }

        return $profile;
    }

    $scenario = 'on_track';
    if ($factor <= 0.90) {
        $scenario = 'volume_down';
    } elseif ($factor < 0.97) {
        $scenario = 'slightly_down';
    } elseif ($factor >= 1.08) {
        $scenario = 'volume_up';
    }

    return [
        'factor' => round($factor, 2),
        'scenario' => $scenario,
        'conservative' => $isConservative,
        'skip_types' => [],
        'skip_after_week' => 99,
        'adaptation_type' => null,
    ];
}

function liveRecalcReasonForSeedProfile(array $seedProfile): string
{
    return match ($seedProfile['scenario']) {
        'volume_down' => 'Live recalc test: все тренировки отмечены выполненными, фактический объем ниже плана, нагрузка ощущалась тяжело.',
        'slightly_down' => 'Live recalc test: все тренировки выполнены, фактический объем чуть ниже планового, нужен аккуратный пересчет.',
        'missed_key' => 'Live recalc test: легкие тренировки выполнены, но часть ключевых работ пропущена, нужна более простая структура.',
        'overload_ok', 'volume_up' => 'Live recalc test: все тренировки выполнены уверенно, объем дается легко, можно аккуратно прибавить.',
        'pain_recovery' => 'Live recalc test: появились болевые ощущения и усталость, часть длительных и интенсивных работ пропущена, нужен восстановительный пересчет.',
        default => 'Live recalc test: все тренировки выполнены близко к плану.',
    };
}

function liveRecalcShouldSkipDayForSeed(array $day, array $seedProfile): bool
{
    $skipTypes = array_map('strval', (array) ($seedProfile['skip_types'] ?? []));
    if ($skipTypes === []) {
        return false;
    }

    $type = (string) ($day['type'] ?? '');
    $weekNumber = (int) ($day['week_number'] ?? 0);
    $skipAfterWeek = (int) ($seedProfile['skip_after_week'] ?? 99);

    return in_array($type, $skipTypes, true) && $weekNumber >= $skipAfterWeek;
}

function liveRecalcBuildWorkoutRow(array $user, array $day, array $seedProfile): array
{
    $type = (string) ($day['type'] ?? '');
    $weekNumber = (int) ($day['week_number'] ?? 1);
    $plannedDistanceKm = round(((int) ($day['distance_m'] ?? 0)) / 1000, 2);
    $isRun = in_array($type, liveRecalcRunTypes(), true);

    if ($plannedDistanceKm <= 0.0 && $isRun) {
        $plannedDistanceKm = liveRecalcFallbackDistanceKm($type, $weekNumber);
    }

    $factor = (float) ($seedProfile['factor'] ?? 1.0);
    $actualDistanceKm = $isRun ? round($plannedDistanceKm * $factor, 2) : 0.0;
    if ($type === 'race' && $plannedDistanceKm > 0.0) {
        $actualDistanceKm = $plannedDistanceKm;
    }
    if ($isRun && $plannedDistanceKm > 0.0) {
        $actualDistanceKm = max(0.3, $actualDistanceKm);
    }

    $paceSec = liveRecalcParsePaceSec($day['pace'] ?? null);
    if ($paceSec === null) {
        $paceSec = match ($type) {
            'walking' => 540,
            'tempo', 'control', 'race' => 330,
            'interval' => 300,
            'long' => 390,
            default => 375,
        };
    }

    if ($isRun) {
        $paceOffset = match ($seedProfile['scenario']) {
            'volume_down' => 18,
            'slightly_down' => 8,
            'volume_up' => -7,
            default => 0,
        };
        $paceSec = max(180, $paceSec + $paceOffset);
    }

    $durationSec = (int) ($day['duration_sec'] ?? 0);
    if ($isRun && $actualDistanceKm > 0.0) {
        $durationSec = (int) round($actualDistanceKm * $paceSec);
    } elseif ($durationSec <= 0) {
        $durationSec = match ($type) {
            'sbu' => 20 * 60,
            'free', 'other' => 35 * 60,
            default => 30 * 60,
        };
    }

    $durationMinutes = max(1, (int) round($durationSec / 60));
    $weightKg = (float) ($user['weight_kg'] ?? 70.0);
    $activityTypeId = liveRecalcActivityTypeId($type, $actualDistanceKm);
    $rating = match ($seedProfile['scenario']) {
        'volume_down' => 3,
        'volume_up' => 5,
        default => 4,
    };

    $hrBase = match ($type) {
        'walking' => 110,
        'long' => 138,
        'tempo', 'control' => 156,
        'interval' => 164,
        'fartlek' => 150,
        'race' => 168,
        'sbu', 'free', 'other' => 118,
        default => 132,
    };
    $hrJitter = ((int) ($user['id'] ?? 0)) % 5;
    $avgHeartRate = $hrBase + $hrJitter;
    $maxHeartRate = $avgHeartRate + ($isRun ? 18 : 10);
    $avgCadence = $isRun ? (164 + (((int) ($user['id'] ?? 0)) % 9)) : 0;
    $elevationGain = $isRun ? (int) round($actualDistanceKm * (4 + (((int) ($user['id'] ?? 0)) % 4))) : 0;
    $calories = $isRun
        ? (int) round($actualDistanceKm * max(45.0, $weightKg) * 1.02)
        : (int) round($durationMinutes * 5.0);

    return [
        'date' => (string) ($day['date'] ?? ''),
        'week_number' => $weekNumber,
        'day_name' => liveRecalcDayName((int) ($day['day_of_week'] ?? 0)),
        'activity_type_id' => $activityTypeId,
        'distance_km' => $actualDistanceKm,
        'pace' => $isRun ? liveRecalcFormatPace($paceSec) : null,
        'duration_minutes' => $durationMinutes,
        'result_time' => $isRun ? liveRecalcFormatDuration($durationSec) : null,
        'rating' => $rating,
        'notes' => sprintf(
            'live_recalc_seed:%s; planned_type=%s; planned_km=%.2f; factor=%.2f',
            gmdate('Ymd'),
            $type,
            $plannedDistanceKm,
            $factor
        ),
        'avg_heart_rate' => $avgHeartRate,
        'max_heart_rate' => $maxHeartRate,
        'avg_cadence' => $avgCadence,
        'elevation_gain' => $elevationGain,
        'calories' => $calories,
        'is_run' => $isRun,
        'planned_km' => $plannedDistanceKm,
        'type' => $type,
    ];
}

function liveRecalcDeleteSeededLogs(mysqli $db, int $userId, string $fromDate, string $cutoffDate): int
{
    $stmt = $db->prepare(
        "DELETE FROM workout_log
         WHERE user_id = ?
           AND training_date >= ?
           AND training_date < ?
           AND notes LIKE 'live_recalc_seed:%'"
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare delete seeded logs failed: ' . $db->error);
    }

    $stmt->bind_param('iss', $userId, $fromDate, $cutoffDate);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return max(0, $affected);
}

function liveRecalcInsertWorkoutLog(mysqli $db, int $userId, array $row): int
{
    $stmt = $db->prepare(
        'INSERT INTO workout_log
            (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful,
             result_time, distance_km, pace, duration_minutes, rating, notes,
             avg_heart_rate, max_heart_rate, avg_cadence, elevation_gain, calories)
         VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare insert workout_log failed: ' . $db->error);
    }

    $date = (string) $row['date'];
    $weekNumber = (int) $row['week_number'];
    $dayName = (string) $row['day_name'];
    $activityTypeId = (int) $row['activity_type_id'];
    $resultTime = $row['result_time'] !== null ? (string) $row['result_time'] : null;
    $distanceKm = (float) $row['distance_km'];
    $pace = $row['pace'] !== null ? (string) $row['pace'] : null;
    $durationMinutes = (int) $row['duration_minutes'];
    $rating = (int) $row['rating'];
    $notes = (string) $row['notes'];
    $avgHeartRate = (int) $row['avg_heart_rate'];
    $maxHeartRate = (int) $row['max_heart_rate'];
    $avgCadence = (int) $row['avg_cadence'];
    $elevationGain = (int) $row['elevation_gain'];
    $calories = (int) $row['calories'];

    $stmt->bind_param(
        'isisisdsiisiiiii',
        $userId,
        $date,
        $weekNumber,
        $dayName,
        $activityTypeId,
        $resultTime,
        $distanceKm,
        $pace,
        $durationMinutes,
        $rating,
        $notes,
        $avgHeartRate,
        $maxHeartRate,
        $avgCadence,
        $elevationGain,
        $calories
    );
    $stmt->execute();
    $insertId = (int) $db->insert_id;
    $stmt->close();

    return $insertId;
}

function liveRecalcSeedCompletedWorkouts(
    mysqli $db,
    array $user,
    string $fromDate,
    string $cutoffDate,
    bool $dryRun,
    string $scenarioMode = 'deterministic',
    int $index = 0
): array {
    $userId = (int) ($user['id'] ?? 0);
    $planDays = liveRecalcFetchPlanDays($db, $userId, $fromDate, $cutoffDate);
    $seedProfile = liveRecalcSeedProfile($user, $scenarioMode, $index);

    $summary = [
        'plan_days' => count($planDays),
        'deleted_seed_rows' => 0,
        'inserted_rows' => 0,
        'skipped_plan_days' => 0,
        'skipped_key_days' => 0,
        'run_rows' => 0,
        'other_rows' => 0,
        'planned_km' => 0.0,
        'skipped_planned_km' => 0.0,
        'actual_km' => 0.0,
        'actual_weekly_km_4w' => 0.0,
        'factor' => $seedProfile['factor'],
        'scenario' => $seedProfile['scenario'],
        'conservative' => $seedProfile['conservative'],
        'scenario_mode' => $scenarioMode,
        'adaptation_type' => $seedProfile['adaptation_type'] ?? null,
    ];

    if (!$dryRun) {
        $summary['deleted_seed_rows'] = liveRecalcDeleteSeededLogs($db, $userId, $fromDate, $cutoffDate);
    }

    foreach ($planDays as $day) {
        $row = liveRecalcBuildWorkoutRow($user, $day, $seedProfile);
        $summary['planned_km'] += (float) $row['planned_km'];

        if (liveRecalcShouldSkipDayForSeed($day, $seedProfile)) {
            $summary['skipped_plan_days']++;
            $summary['skipped_planned_km'] += (float) $row['planned_km'];
            if (in_array((string) ($day['type'] ?? ''), ['tempo', 'interval', 'fartlek', 'control'], true)) {
                $summary['skipped_key_days']++;
            }
            continue;
        }

        $summary['actual_km'] += (float) $row['distance_km'];
        if (!empty($row['is_run'])) {
            $summary['run_rows']++;
        } else {
            $summary['other_rows']++;
        }

        if (!$dryRun) {
            liveRecalcInsertWorkoutLog($db, $userId, $row);
            $summary['inserted_rows']++;
        }
    }

    $summary['planned_km'] = round((float) $summary['planned_km'], 1);
    $summary['skipped_planned_km'] = round((float) $summary['skipped_planned_km'], 1);
    $summary['actual_km'] = round((float) $summary['actual_km'], 1);
    $summary['actual_weekly_km_4w'] = round(((float) $summary['actual_km']) / 4.0, 1);

    return $summary;
}

function liveRecalcFetchKeptWeeks(mysqli $db, int $userId, string $cutoffDate): int
{
    $stmt = $db->prepare(
        'SELECT COALESCE(MAX(week_number), 0) AS kept_weeks
         FROM training_plan_weeks
         WHERE user_id = ?
           AND start_date < ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare kept weeks failed: ' . $db->error);
    }

    $stmt->bind_param('is', $userId, $cutoffDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return (int) ($row['kept_weeks'] ?? 0);
}

function liveRecalcBuildPayload(mysqli $db, array $user, array $seed, string $cutoffDate): array
{
    $userId = (int) ($user['id'] ?? 0);
    $keptWeeks = liveRecalcFetchKeptWeeks($db, $userId, $cutoffDate);

    $payload = [
        'cutoff_date' => $cutoffDate,
        'mutable_from_date' => $cutoffDate,
        'kept_weeks' => $keptWeeks,
        'actual_weekly_km_4w' => (float) ($seed['actual_weekly_km_4w'] ?? 0.0),
        'reason' => liveRecalcReasonForSeedProfile($seed),
    ];

    if (!empty($seed['adaptation_type'])) {
        $payload['adaptation_type'] = (string) $seed['adaptation_type'];
        $payload['adaptation_metrics'] = [
            'actual_volume_km' => (float) ($seed['actual_weekly_km_4w'] ?? 0.0),
            'planned_volume_km' => round(((float) ($seed['planned_km'] ?? 0.0)) / 4.0, 1),
            'skipped_key_days' => (int) ($seed['skipped_key_days'] ?? 0),
            'skipped_plan_days' => (int) ($seed['skipped_plan_days'] ?? 0),
        ];
    }

    return $payload;
}

function liveRecalcIssue(string $severity, string $code, string $message, array $context = []): array
{
    return [
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
        'context' => $context,
    ];
}

function liveRecalcLongShareLimits(int $sessions, bool $isNovice, bool $isConservative): array
{
    if ($sessions <= 2) {
        return [0.60, 0.72];
    }

    if ($sessions === 3) {
        return [($isNovice || $isConservative) ? 0.43 : 0.45, 0.60];
    }

    return [($isNovice || $isConservative) ? 0.40 : 0.45, 0.55];
}

function liveRecalcIsLongShareMaterial(float $peakLongKm, int $sessions): bool
{
    if ($peakLongKm <= 0.0) {
        return false;
    }

    if ($sessions <= 2 && $peakLongKm <= 8.0) {
        return false;
    }

    return $peakLongKm >= 5.0;
}

function liveRecalcAllowsShortRaceLongShare(array $user, array $after, float $peakLongKm): bool
{
    $raceDistanceKm = liveRecalcUserRaceDistanceKm($user);
    if ($raceDistanceKm <= 0.0) {
        foreach ((array) ($after['race_days'] ?? []) as $raceDay) {
            $raceDistanceKm = max($raceDistanceKm, (float) ($raceDay['distance_km'] ?? 0.0));
        }
    }

    return $raceDistanceKm > 0.0
        && $raceDistanceKm <= 5.1
        && $peakLongKm <= 5.5
        && (float) ($after['max_future_volume_km'] ?? 0.0) <= 12.0;
}

function liveRecalcUserRaceDistanceKm(array $user): float
{
    $distance = strtolower(trim((string) ($user['race_distance'] ?? $user['target_marathon_distance'] ?? '')));
    return match ($distance) {
        '5k', '5', '5.0', '5 км' => 5.0,
        '10k', '10', '10.0', '10 км' => 10.0,
        'half', '21.1k', '21.1', 'полумарафон' => 21.1,
        'marathon', '42.2k', '42.2', 'марафон' => 42.2,
        default => is_numeric($distance) ? (float) $distance : 0.0,
    };
}

function liveRecalcRaceDate(array $user): string
{
    return (string) ($user['race_date'] ?? $user['target_marathon_date'] ?? '');
}

function liveRecalcEvaluate(
    array $user,
    array $seed,
    array $payload,
    array $before,
    array $after,
    ?array $recalcResult,
    ?string $recalcError,
    string $cutoffDate
): array {
    $issues = [];
    $goal = (string) ($user['goal_type'] ?? '');
    $experience = (string) ($user['experience_level'] ?? '');
    $isNovice = in_array($experience, ['novice', 'beginner'], true);
    $isConservative = !empty($seed['conservative']);
    $sessions = max(1, (int) ($user['sessions_per_week'] ?? 3));
    $actualWeekly = (float) ($payload['actual_weekly_km_4w'] ?? 0.0);

    if ($recalcError !== null) {
        $issues[] = liveRecalcIssue('error', 'recalculate_failed', $recalcError);
    }

    if ((int) ($seed['inserted_rows'] ?? 0) === 0 && (int) ($seed['plan_days'] ?? 0) > 0) {
        $issues[] = liveRecalcIssue('warning', 'seed_skipped_or_dry_run', 'Выполненные тренировки не были записаны в workout_log.');
    }

    $expectedKept = (int) ($payload['kept_weeks'] ?? 0);
    if (($after['kept_weeks_count'] ?? 0) < $expectedKept) {
        $issues[] = liveRecalcIssue('error', 'kept_weeks_lost', 'После пересчета сохранено меньше прошлых недель, чем ожидалось.', [
            'expected_kept_weeks' => $expectedKept,
            'actual_kept_weeks' => $after['kept_weeks_count'] ?? 0,
        ]);
    }

    $futureRaceDate = liveRecalcRaceDate($user);
    $goalStillAhead = $futureRaceDate !== '' && strcmp($futureRaceDate, $cutoffDate) >= 0;
    if ($goalStillAhead && (int) ($after['future_weeks_count'] ?? 0) < 1) {
        $issues[] = liveRecalcIssue('error', 'no_future_weeks_after_recalc', 'После пересчета нет будущих недель до целевой даты.');
    }

    $firstFutureStart = (string) ($after['first_future_start_date'] ?? '');
    if ($firstFutureStart !== '' && $firstFutureStart !== $cutoffDate) {
        $issues[] = liveRecalcIssue('error', 'future_plan_wrong_anchor', "Будущая часть плана начинается {$firstFutureStart}, а должна с {$cutoffDate}.", [
            'actual' => $firstFutureStart,
            'expected' => $cutoffDate,
        ]);
    }

    $firstFutureVolume = (float) ($after['first_future_volume_km'] ?? 0.0);
    if ($actualWeekly > 0.0 && $firstFutureVolume > 0.0) {
        $upperRatio = ($isNovice || $isConservative) ? 1.15 : 1.25;
        $lowerRatio = ($isNovice || $isConservative) ? 0.62 : 0.55;
        if ($firstFutureVolume > ($actualWeekly * $upperRatio + 2.0)) {
            $issues[] = liveRecalcIssue('warning', 'recalc_starts_above_actual_load', 'Первая новая неделя заметно выше фактического среднего объема.', [
                'actual_weekly_km_4w' => $actualWeekly,
                'first_future_volume_km' => $firstFutureVolume,
            ]);
        }
        if ($firstFutureVolume < ($actualWeekly * $lowerRatio) && ($goalStillAhead || $goal === 'health')) {
            $issues[] = liveRecalcIssue('warning', 'recalc_starts_too_low', 'Первая новая неделя слишком сильно падает относительно фактического объема.', [
                'actual_weekly_km_4w' => $actualWeekly,
                'first_future_volume_km' => $firstFutureVolume,
            ]);
        }
    }

    $volumes = (array) ($after['future_volumes_km'] ?? []);
    $futureWeekContexts = (array) ($after['future_week_contexts'] ?? []);
    $allowedGrowth = ($isNovice || $isConservative) ? 1.12 : 1.18;
    $minGrowthDeltaKm = ($isNovice || $isConservative) ? 1.8 : 2.5;
    $previous = $actualWeekly > 0.0 ? $actualWeekly : null;
    $lastNormalVolume = $actualWeekly > 0.0 ? $actualWeekly : null;
    $previousWasCutback = false;
    foreach ($volumes as $index => $volume) {
        $volume = (float) $volume;
        $weekContext = is_array($futureWeekContexts[$index] ?? null) ? $futureWeekContexts[$index] : [];
        if (!empty($weekContext['has_race'])) {
            $previous = $volume;
            $previousWasCutback = true;
            continue;
        }
        $reference = ($previousWasCutback && $lastNormalVolume !== null && $lastNormalVolume > 0.0)
            ? $lastNormalVolume
            : $previous;
        if (
            $reference !== null
            && $reference > 0.0
            && $volume > 0.0
            && ($volume / $reference) > $allowedGrowth
            && ($volume - $reference) > $minGrowthDeltaKm
        ) {
            $issues[] = liveRecalcIssue('warning', 'future_growth_high_after_recalc', 'После пересчета есть резкий рост недельного объема.', [
                'future_week_index' => $index + 1,
                'previous_km' => round($reference, 1),
                'volume_km' => round($volume, 1),
                'growth_ratio' => round($volume / $reference, 2),
                'delta_km' => round($volume - $reference, 1),
            ]);
            break;
        }
        if ($volume > 0.0) {
            $isCutback = $lastNormalVolume !== null && $lastNormalVolume > 0.0 && $volume < ($lastNormalVolume * 0.92);
            if (!$isCutback) {
                $lastNormalVolume = $volume;
            }
            $previousWasCutback = $isCutback;
            $previous = $volume;
        }
    }

    [$longShareLimit] = liveRecalcLongShareLimits($sessions, $isNovice, $isConservative);
    $futurePeakLongKm = (float) ($after['future_peak_long_km'] ?? 0.0);
    if (
        (float) ($after['future_long_share_max'] ?? 0.0) > ($longShareLimit + 0.04)
        && liveRecalcIsLongShareMaterial($futurePeakLongKm, $sessions)
        && !liveRecalcAllowsShortRaceLongShare($user, $after, $futurePeakLongKm)
    ) {
        $issues[] = liveRecalcIssue('warning', 'future_long_share_high_after_recalc', 'После пересчета длительная занимает слишком большую долю недели.', [
            'max_share' => $after['future_long_share_max'] ?? 0.0,
            'peak_long_km' => $futurePeakLongKm,
            'limit_with_tolerance' => round($longShareLimit + 0.04, 2),
        ]);
    }

    if (in_array($goal, ['race', 'time_improvement'], true) && $futureRaceDate !== '' && $goalStillAhead) {
        $raceDays = (array) ($after['race_days'] ?? []);
        $hasRaceOnDate = false;
        foreach ($raceDays as $raceDay) {
            if ((string) ($raceDay['date'] ?? '') === $futureRaceDate) {
                $hasRaceOnDate = true;
                break;
            }
        }
        if (!$hasRaceOnDate) {
            $issues[] = liveRecalcIssue('error', 'race_day_missing_after_recalc', "После пересчета race-день не найден на целевую дату {$futureRaceDate}.", [
                'race_date' => $futureRaceDate,
                'race_days' => $raceDays,
            ]);
        }
    }

    $progressionStart = $recalcResult['generation_metadata']['progression_counters_start'] ?? null;
    if (is_array($progressionStart) && !empty($progressionStart['completed_key_days'])) {
        $issues[] = liveRecalcIssue('info', 'progression_counters_used', 'Пересчет увидел выполненные ключевые тренировки и продолжил прогрессию.', [
            'progression_counters_start' => $progressionStart,
        ]);
    }

    $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
    $codes = [];
    foreach ($issues as $issue) {
        $severity = (string) ($issue['severity'] ?? 'info');
        $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        $code = (string) ($issue['code'] ?? 'unknown');
        $codes[$code] = ($codes[$code] ?? 0) + 1;
    }

    return [
        'issues' => $issues,
        'summary' => [
            'issue_counts' => $counts,
            'issue_code_counts' => $codes,
            'actual_weekly_km_4w' => $actualWeekly,
            'before_first_future_volume_km' => $before['first_future_volume_km'] ?? 0.0,
            'after_first_future_volume_km' => $after['first_future_volume_km'] ?? 0.0,
            'after_future_weeks' => $after['future_weeks_count'] ?? 0,
            'after_peak_long_km' => $after['future_peak_long_km'] ?? 0.0,
        ],
    ];
}

function liveRecalcBuildMarkdown(array $report): string
{
    $lines = [];
    $lines[] = '# Live Plan Recalculation Batch';
    $lines[] = '';
    $lines[] = '- Generated at: ' . gmdate('Y-m-d H:i:s') . ' UTC';
    $lines[] = '- Prefix: `' . ($report['context']['prefix'] ?? '') . '`';
    $lines[] = '- Completed window: `' . ($report['context']['from_date'] ?? '') . '` to `' . ($report['context']['cutoff_date'] ?? '') . '` exclusive';
    $lines[] = '- Users: ' . count($report['users']);
    $lines[] = '- Scenario mode: `' . ($report['context']['scenario_mode'] ?? 'deterministic') . '`';
    $lines[] = '- Live LLM path: ' . (!empty($report['context']['fast_llm_fallback']) ? 'fallback/algorithmic' : 'configured LLM');
    $lines[] = '';

    $summary = $report['summary'];
    $lines[] = '## Summary';
    $lines[] = '';
    $lines[] = '- Seed rows inserted: ' . $summary['seed_rows_inserted'];
    $lines[] = '- Seed plan days skipped: ' . ($summary['seed_rows_skipped'] ?? 0);
    $lines[] = '- Seed key days skipped: ' . ($summary['seed_key_rows_skipped'] ?? 0);
    $lines[] = '- Seed run km: ' . $summary['seed_run_km'];
    $lines[] = '- Recalculate ok: ' . $summary['recalculate_ok'];
    $lines[] = '- Recalculate failed: ' . $summary['recalculate_failed'];
    $lines[] = '- Trainer issues: errors=' . $summary['issue_counts']['error'] . ', warnings=' . $summary['issue_counts']['warning'] . ', info=' . $summary['issue_counts']['info'];
    $lines[] = '';

    $lines[] = '## Top Issue Codes';
    $lines[] = '';
    $lines[] = '| Code | Count |';
    $lines[] = '| --- | ---: |';
    foreach ($summary['top_issue_codes'] as $code => $count) {
        $lines[] = '| `' . $code . '` | ' . $count . ' |';
    }
    $lines[] = '';

    $lines[] = '## Users';
    $lines[] = '';
    $lines[] = '| # | User ID | Case | Scenario | Actual km/w | Kept | Before next | After next | Future weeks | E/W/I |';
    $lines[] = '| ---: | ---: | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |';
    foreach ($report['users'] as $i => $item) {
        $eval = $item['evaluation']['summary'] ?? [];
        $counts = $eval['issue_counts'] ?? ['error' => 0, 'warning' => 0, 'info' => 0];
        $lines[] = sprintf(
            '| %d | %d | `%s` | %s | %.1f | %d | %.1f | %.1f | %d | %d/%d/%d |',
            $i + 1,
            (int) $item['user_id'],
            (string) ($item['case_code'] ?? ''),
            (string) ($item['seed']['scenario'] ?? ''),
            (float) ($eval['actual_weekly_km_4w'] ?? 0.0),
            (int) ($item['payload']['kept_weeks'] ?? 0),
            (float) ($eval['before_first_future_volume_km'] ?? 0.0),
            (float) ($eval['after_first_future_volume_km'] ?? 0.0),
            (int) ($eval['after_future_weeks'] ?? 0),
            (int) ($counts['error'] ?? 0),
            (int) ($counts['warning'] ?? 0),
            (int) ($counts['info'] ?? 0)
        );
    }
    $lines[] = '';

    $lines[] = '## Coach Review: Problems To Fix';
    $lines[] = '';
    $hasProblems = false;
    foreach ($report['users'] as $item) {
        $issues = array_values(array_filter(
            (array) ($item['evaluation']['issues'] ?? []),
            static fn(array $issue): bool => in_array((string) ($issue['severity'] ?? ''), ['error', 'warning'], true)
        ));
        if ($issues === []) {
            continue;
        }
        $hasProblems = true;
        $lines[] = '### ' . ($item['case_code'] ?? '') . ' (`user_id=' . $item['user_id'] . '`)';
        $lines[] = '';
        foreach (array_slice($issues, 0, 8) as $issue) {
            $lines[] = '- ' . strtoupper((string) $issue['severity']) . ' `' . $issue['code'] . '`: ' . $issue['message'];
        }
        if (count($issues) > 8) {
            $lines[] = '- ...and ' . (count($issues) - 8) . ' more.';
        }
        $lines[] = '';
    }
    if (!$hasProblems) {
        $lines[] = 'No blocking coach issues were found in the recalculated plans.';
        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$args = liveRecalcParseArgs($argv);
$prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) ($args['prefix'] ?? 'live50_20260424'));
$limit = max(1, min(50, (int) ($args['limit'] ?? 50)));
$completedWeeks = max(1, min(12, (int) ($args['completed-weeks'] ?? 4)));
$saveDir = rtrim((string) ($args['save-dir'] ?? ($baseDir . '/tmp/live_plan_generation')), '/');
$dryRun = liveRecalcBool($args['dry-run'] ?? '0');
$skipSeed = liveRecalcBool($args['skip-seed'] ?? '0');
$skipRecalculate = liveRecalcBool($args['skip-recalculate'] ?? '0') || $dryRun;
$fastFallback = liveRecalcBool($args['fast-llm-fallback'] ?? '1');
$scenarioMode = in_array((string) ($args['scenario-mode'] ?? 'deterministic'), ['deterministic', 'mixed'], true)
    ? (string) $args['scenario-mode']
    : 'deterministic';

liveRecalcSetEnv('USE_SKELETON_GENERATOR', '1');
if ($fastFallback) {
    liveRecalcSetEnv('LLM_CHAT_BASE_URL', 'http://127.0.0.1:1/v1');
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$users = liveRecalcFetchUsers($db, $prefix, $limit);
if ($users === []) {
    fwrite(STDERR, "No users found for prefix {$prefix}\n");
    exit(1);
}

$fromDate = (string) ($users[0]['training_start_date'] ?? '');
if ($fromDate === '') {
    fwrite(STDERR, "First user has no training_start_date\n");
    exit(1);
}

$cutoffDate = trim((string) ($args['cutoff-date'] ?? ''));
if ($cutoffDate === '') {
    $cutoffDate = liveRecalcDateAddWeeks($fromDate, $completedWeeks);
}

if (!is_dir($saveDir) && !mkdir($saveDir, 0775, true) && !is_dir($saveDir)) {
    fwrite(STDERR, "Cannot create save dir: {$saveDir}\n");
    exit(1);
}

$processor = new PlanGenerationProcessorService($db);

$report = [
    'context' => [
        'prefix' => $prefix,
        'limit' => $limit,
        'from_date' => $fromDate,
        'cutoff_date' => $cutoffDate,
        'completed_weeks' => $completedWeeks,
        'fast_llm_fallback' => $fastFallback,
        'scenario_mode' => $scenarioMode,
        'skip_seed' => $skipSeed,
        'skip_recalculate' => $skipRecalculate,
        'dry_run' => $dryRun,
        'generated_at_utc' => gmdate('Y-m-d H:i:s'),
    ],
    'summary' => [
        'seed_rows_inserted' => 0,
        'seed_rows_skipped' => 0,
        'seed_key_rows_skipped' => 0,
        'seed_run_km' => 0.0,
        'recalculate_ok' => 0,
        'recalculate_failed' => 0,
        'issue_counts' => ['error' => 0, 'warning' => 0, 'info' => 0],
        'top_issue_codes' => [],
    ],
    'users' => [],
];

foreach ($users as $index => $user) {
    $userId = (int) ($user['id'] ?? 0);
    $caseCode = liveRecalcCaseCode($user);
    $linePrefix = sprintf('[%02d/%02d] %s user_id=%d', $index + 1, count($users), $caseCode, $userId);
    fwrite(STDOUT, "{$linePrefix}: seed completed workouts...\n");

    $beforePlan = liveRecalcFetchSavedPlan($db, $userId);
    $beforeSummary = liveRecalcSummarizePlan($beforePlan, $cutoffDate);
    $seed = [
        'plan_days' => 0,
        'deleted_seed_rows' => 0,
        'inserted_rows' => 0,
        'skipped_plan_days' => 0,
        'skipped_key_days' => 0,
        'run_rows' => 0,
        'other_rows' => 0,
        'planned_km' => 0.0,
        'skipped_planned_km' => 0.0,
        'actual_km' => 0.0,
        'actual_weekly_km_4w' => 0.0,
        'factor' => 1.0,
        'scenario' => 'skip_seed',
        'conservative' => false,
        'scenario_mode' => $scenarioMode,
        'adaptation_type' => null,
    ];

    try {
        if (!$skipSeed) {
            $seed = liveRecalcSeedCompletedWorkouts($db, $user, $fromDate, $cutoffDate, $dryRun, $scenarioMode, $index);
        }
        $report['summary']['seed_rows_inserted'] += (int) ($seed['inserted_rows'] ?? 0);
        $report['summary']['seed_rows_skipped'] += (int) ($seed['skipped_plan_days'] ?? 0);
        $report['summary']['seed_key_rows_skipped'] += (int) ($seed['skipped_key_days'] ?? 0);
        $report['summary']['seed_run_km'] = round(
            (float) $report['summary']['seed_run_km'] + (float) ($seed['actual_km'] ?? 0.0),
            1
        );

        $payload = liveRecalcBuildPayload($db, $user, $seed, $cutoffDate);
        $recalc = null;
        $recalcError = null;
        if (!$skipRecalculate) {
            fwrite(STDOUT, "{$linePrefix}: recalculate cutoff={$cutoffDate}, actual_weekly={$payload['actual_weekly_km_4w']}...\n");
            $started = microtime(true);
            $recalc = $processor->process($userId, 'recalculate', $payload);
            $recalc['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            $report['summary']['recalculate_ok']++;
        } else {
            $recalc = ['skipped' => true];
        }

        $afterPlan = liveRecalcFetchSavedPlan($db, $userId);
        $afterSummary = liveRecalcSummarizePlan($afterPlan, $cutoffDate);
        $evaluation = liveRecalcEvaluate($user, $seed, $payload, $beforeSummary, $afterSummary, $recalc, $recalcError, $cutoffDate);
    } catch (Throwable $e) {
        $recalcError = $e->getMessage();
        $report['summary']['recalculate_failed']++;
        fwrite(STDERR, "{$linePrefix}: ERROR {$recalcError}\n");

        $payload = liveRecalcBuildPayload($db, $user, $seed, $cutoffDate);
        $afterPlan = liveRecalcFetchSavedPlan($db, $userId);
        $afterSummary = liveRecalcSummarizePlan($afterPlan, $cutoffDate);
        $evaluation = liveRecalcEvaluate($user, $seed, $payload, $beforeSummary, $afterSummary, null, $recalcError, $cutoffDate);
        $recalc = ['ok' => false, 'error' => $recalcError];
    }

    foreach (($evaluation['summary']['issue_counts'] ?? []) as $severity => $count) {
        $report['summary']['issue_counts'][$severity] = ($report['summary']['issue_counts'][$severity] ?? 0) + (int) $count;
    }
    foreach (($evaluation['summary']['issue_code_counts'] ?? []) as $code => $count) {
        $report['summary']['top_issue_codes'][$code] = ($report['summary']['top_issue_codes'][$code] ?? 0) + (int) $count;
    }

    $report['users'][] = [
        'user_id' => $userId,
        'username' => (string) ($user['username'] ?? ''),
        'case_code' => $caseCode,
        'seed' => $seed,
        'payload' => $payload,
        'before' => $beforeSummary,
        'after' => $afterSummary,
        'recalculate' => $recalc,
        'evaluation' => $evaluation,
    ];
}

arsort($report['summary']['top_issue_codes']);
$report['summary']['top_issue_codes'] = array_slice($report['summary']['top_issue_codes'], 0, 20, true);

$artifactBase = $saveDir . '/' . $prefix . '_recalc_' . gmdate('Ymd_His');
$jsonPath = $artifactBase . '.json';
$mdPath = $artifactBase . '.md';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($mdPath, liveRecalcBuildMarkdown($report));

fwrite(STDOUT, "JSON: {$jsonPath}\n");
fwrite(STDOUT, "Markdown: {$mdPath}\n");
fwrite(STDOUT, "Summary: " . json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($report['summary']['recalculate_failed'] > 0 ? 1 : 0);
