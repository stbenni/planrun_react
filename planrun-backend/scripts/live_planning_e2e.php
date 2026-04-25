#!/usr/bin/env php
<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);

require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PlanGenerationProcessorService.php';
require_once $baseDir . '/services/AdaptationService.php';
require_once $baseDir . '/planrun_ai/skeleton/PlanSkeletonGenerator.php';

function parseCliArgs(array $argv): array
{
    $args = [
        'good-user-id' => '1797',
        'recovery-user-id' => '1798',
        'direct-cutoff-date' => '2026-04-27',
        'direct-seed-end-date' => '2026-04-23',
        'adaptation-seed-end-date' => '2026-04-26',
        'use-fast-llm-fallback' => '0',
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

function dbFetchAll(mysqli $db, string $sql, array $params = [], string $types = ''): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function dbFetchOne(mysqli $db, string $sql, array $params = [], string $types = ''): ?array
{
    $rows = dbFetchAll($db, $sql, $params, $types);
    return $rows[0] ?? null;
}

function clearExecutionData(mysqli $db, int $userId): void
{
    foreach (['post_workout_followups', 'workout_log', 'workouts'] as $table) {
        $stmt = $db->prepare("DELETE FROM {$table} WHERE user_id = ?");
        if (!$stmt) {
            throw new RuntimeException("Не удалось подготовить очистку {$table}");
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function resetScenarioUserProfile(mysqli $db, int $userId): void
{
    $preferredDays = json_encode(['mon', 'tue', 'thu', 'sat', 'sun'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare(
        "UPDATE users
         SET goal_type = 'race',
             race_distance = '10k',
             race_date = '2026-06-21',
             race_target_time = '00:45:00',
             training_start_date = '2026-03-23',
             weekly_base_km = 24.0,
             experience_level = 'intermediate',
             easy_pace_sec = 345,
             sessions_per_week = 5,
             preferred_days = ?,
             is_first_race_at_distance = 0,
             last_race_distance = '5k',
             last_race_distance_km = 5.0,
             last_race_time = '00:21:45',
             last_race_date = '2026-03-16'
         WHERE id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить reset user profile');
    }
    $stmt->bind_param('si', $preferredDays, $userId);
    $stmt->execute();
    $stmt->close();
}

function runGenerate(mysqli $db, int $userId): array
{
    $service = new PlanGenerationProcessorService($db);
    return $service->process($userId, 'generate', []);
}

function fetchPlanWindow(mysqli $db, int $userId, int $fromWeek = 6, int $toWeek = 8): array
{
    $weeks = dbFetchAll(
        $db,
        "SELECT week_number, start_date, total_volume, phase_id
         FROM training_plan_weeks
         WHERE user_id = ? AND week_number BETWEEN ? AND ?
         ORDER BY week_number ASC",
        [$userId, $fromWeek, $toWeek],
        'iii'
    );

    $days = dbFetchAll(
        $db,
        "SELECT w.week_number,
                d.date,
                d.type,
                d.description,
                d.is_key_workout
         FROM training_plan_weeks w
         JOIN training_plan_days d ON d.week_id = w.id
         WHERE w.user_id = ? AND w.week_number BETWEEN ? AND ?
         ORDER BY w.week_number ASC, d.date ASC",
        [$userId, $fromWeek, $toWeek],
        'iii'
    );

    $importantDays = array_values(array_filter(
        $days,
        static fn(array $day): bool => (int) ($day['is_key_workout'] ?? 0) === 1
            || in_array((string) ($day['type'] ?? ''), ['long', 'race'], true)
    ));

    return [
        'weeks' => array_map(
            static fn(array $week): array => [
                'week_number' => (int) ($week['week_number'] ?? 0),
                'start_date' => (string) ($week['start_date'] ?? ''),
                'total_volume' => round((float) ($week['total_volume'] ?? 0.0), 1),
                'phase_id' => isset($week['phase_id']) ? (int) $week['phase_id'] : null,
            ],
            $weeks
        ),
        'important_days' => array_map(
            static fn(array $day): array => [
                'week_number' => (int) ($day['week_number'] ?? 0),
                'date' => (string) ($day['date'] ?? ''),
                'type' => (string) ($day['type'] ?? ''),
                'description' => trim((string) ($day['description'] ?? '')),
            ],
            $importantDays
        ),
    ];
}

function fetchPlanRunDaysUntil(mysqli $db, int $userId, string $endDate): array
{
    return dbFetchAll(
        $db,
        "SELECT d.id,
                d.date,
                d.type,
                d.description,
                d.is_key_workout,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.distance_m ELSE 0 END), 0) AS distance_m,
                COALESCE(SUM(CASE WHEN e.category = 'run' THEN e.duration_sec ELSE 0 END), 0) AS duration_sec,
                MAX(CASE WHEN e.category = 'run' THEN e.pace END) AS pace
         FROM training_plan_days d
         LEFT JOIN training_day_exercises e ON e.plan_day_id = d.id
         WHERE d.user_id = ?
           AND d.date <= ?
           AND d.type IN ('easy', 'tempo', 'interval', 'long', 'race', 'fartlek', 'control')
         GROUP BY d.id, d.date, d.type, d.description, d.is_key_workout
         ORDER BY d.date ASC, d.day_of_week ASC",
        [$userId, $endDate],
        'is'
    );
}

function extractDistanceKm(string $text): ?float
{
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*км/u', $text, $match) === 1) {
        return (float) str_replace(',', '.', $match[1]);
    }
    return null;
}

function extractPace(string $text): ?string
{
    if (preg_match('/(\d{1,2}:\d{2})\s*мин\/км/u', $text, $match) === 1) {
        return $match[1];
    }
    if (preg_match('/темпе\s+(\d{1,2}:\d{2})/u', $text, $match) === 1) {
        return $match[1];
    }
    return null;
}

function paceToSeconds(?string $pace): ?int
{
    if ($pace === null || trim($pace) === '') {
        return null;
    }

    $parts = explode(':', trim($pace));
    if (count($parts) !== 2) {
        return null;
    }

    return ((int) $parts[0] * 60) + (int) $parts[1];
}

function buildWorkoutSpec(int $userId, array $planDay, array $overrides = []): array
{
    $distanceKm = round(((int) ($planDay['distance_m'] ?? 0)) / 1000, 2);
    if ($distanceKm <= 0.0) {
        $distanceKm = (float) ($overrides['distance_km'] ?? extractDistanceKm((string) ($planDay['description'] ?? '')) ?? 0.0);
    }

    $avgPace = (string) ($overrides['avg_pace'] ?? $planDay['pace'] ?? extractPace((string) ($planDay['description'] ?? '')) ?? '5:40');
    $durationSec = (int) ($overrides['duration_sec'] ?? $planDay['duration_sec'] ?? 0);
    if ($durationSec <= 0 && $distanceKm > 0.0) {
        $paceSec = paceToSeconds($avgPace) ?? 340;
        $durationSec = (int) round($distanceKm * $paceSec);
    }
    $durationMin = max(1, (int) round($durationSec / 60));

    $startTime = (string) ($planDay['date'] ?? '') . ' 07:00:00';
    $endTime = (new DateTimeImmutable($startTime))
        ->modify('+' . max(60, $durationSec) . ' seconds')
        ->format('Y-m-d H:i:s');

    return [
        'session_id' => sprintf('e2e-%d-%s', $userId, (string) ($planDay['date'] ?? '')),
        'activity_type' => 'running',
        'start_time' => $startTime,
        'end_time' => $endTime,
        'duration_minutes' => $durationMin,
        'duration_seconds' => max(60, $durationSec),
        'distance_km' => round($distanceKm, 2),
        'avg_pace' => $avgPace,
        'source' => 'e2e_test_live',
        'date' => (string) ($planDay['date'] ?? ''),
        'type' => (string) ($planDay['type'] ?? ''),
    ];
}

function insertWorkoutIfMissing(mysqli $db, int $userId, array $spec): int
{
    $existing = dbFetchOne(
        $db,
        "SELECT id
         FROM workouts
         WHERE user_id = ?
           AND DATE(start_time) = ?
           AND source = 'e2e_test_live'
         LIMIT 1",
        [$userId, $spec['date']],
        'is'
    );
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $stmt = $db->prepare(
        "INSERT INTO workouts
            (user_id, session_id, activity_type, start_time, end_time, duration_minutes, duration_seconds, distance_km, avg_pace, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить insert workouts');
    }

    $stmt->bind_param(
        'issssiidss',
        $userId,
        $spec['session_id'],
        $spec['activity_type'],
        $spec['start_time'],
        $spec['end_time'],
        $spec['duration_minutes'],
        $spec['duration_seconds'],
        $spec['distance_km'],
        $spec['avg_pace'],
        $spec['source']
    );
    $stmt->execute();
    $workoutId = (int) $db->insert_id;
    $stmt->close();

    return $workoutId;
}

function seedScenarioWorkouts(mysqli $db, int $userId, string $endDate, array $options = []): array
{
    $planDays = fetchPlanRunDaysUntil($db, $userId, $endDate);
    $skipDates = array_flip($options['skip_dates'] ?? []);
    $paceOverrides = is_array($options['pace_overrides'] ?? null) ? $options['pace_overrides'] : [];
    $dateOverrides = is_array($options['date_overrides'] ?? null) ? $options['date_overrides'] : [];

    $seeded = [];
    foreach ($planDays as $planDay) {
        $date = (string) ($planDay['date'] ?? '');
        if (isset($skipDates[$date])) {
            continue;
        }

        $override = $dateOverrides[$date] ?? [];
        $type = (string) ($planDay['type'] ?? '');
        if (!isset($override['avg_pace']) && isset($paceOverrides[$type])) {
            $override['avg_pace'] = $paceOverrides[$type];
        }

        $spec = buildWorkoutSpec($userId, $planDay, $override);
        if (($spec['distance_km'] ?? 0.0) <= 0.0) {
            continue;
        }

        $workoutId = insertWorkoutIfMissing($db, $userId, $spec);
        $seeded[] = [
            'workout_id' => $workoutId,
            'date' => $spec['date'],
            'type' => $spec['type'],
            'distance_km' => $spec['distance_km'],
            'avg_pace' => $spec['avg_pace'],
        ];
    }

    return $seeded;
}

function indexWorkoutsByDate(array $seeded): array
{
    $map = [];
    foreach ($seeded as $item) {
        $map[(string) $item['date']] = $item;
    }
    return $map;
}

function insertCompletedFollowup(mysqli $db, int $userId, int $workoutId, string $workoutDate, array $analytics): void
{
    $delete = $db->prepare(
        "DELETE FROM post_workout_followups
         WHERE user_id = ? AND source_kind = 'workout' AND source_id = ?"
    );
    if (!$delete) {
        throw new RuntimeException('Не удалось подготовить delete followup');
    }
    $delete->bind_param('ii', $userId, $workoutId);
    $delete->execute();
    $delete->close();

    $dueAt = $workoutDate . ' 20:00:00';
    $sentAt = $workoutDate . ' 20:05:00';
    $respondedAt = $workoutDate . ' 20:10:00';

    $stmt = $db->prepare(
        "INSERT INTO post_workout_followups
            (user_id, source_kind, source_id, workout_date, status, classification, pain_flag, fatigue_flag,
             session_rpe, legs_score, breath_score, hr_strain_score, pain_score, recovery_risk_score,
             due_at, sent_at, responded_at)
         VALUES (?, 'workout', ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить insert followup');
    }

    $classification = (string) ($analytics['classification'] ?? 'neutral');
    $painFlag = !empty($analytics['pain_flag']) ? 1 : 0;
    $fatigueFlag = !empty($analytics['fatigue_flag']) ? 1 : 0;
    $sessionRpe = (int) ($analytics['session_rpe'] ?? 0);
    $legsScore = (int) ($analytics['legs_score'] ?? 0);
    $breathScore = (int) ($analytics['breath_score'] ?? 0);
    $hrStrainScore = (int) ($analytics['hr_strain_score'] ?? 0);
    $painScore = (int) ($analytics['pain_score'] ?? 0);
    $recoveryRiskScore = (float) ($analytics['recovery_risk_score'] ?? 0.0);

    $stmt->bind_param(
        'iissiiiiiiidsss',
        $userId,
        $workoutId,
        $workoutDate,
        $classification,
        $painFlag,
        $fatigueFlag,
        $sessionRpe,
        $legsScore,
        $breathScore,
        $hrStrainScore,
        $painScore,
        $recoveryRiskScore,
        $dueAt,
        $sentAt,
        $respondedAt
    );
    $stmt->execute();
    $stmt->close();
}

function invokePrivate(object $object, string $methodName, array $args = []): mixed
{
    $method = new ReflectionMethod($object, $methodName);
    $method->setAccessible(true);
    return $method->invokeArgs($object, $args);
}

function previewRecalculate(mysqli $db, int $userId, array $payload): array
{
    $generator = new PlanSkeletonGenerator($db);
    $plan = $generator->generate($userId, 'recalculate', $payload);
    $state = $generator->getLastState() ?? [];

    return [
        'state' => summarizeState($state),
        'preview' => summarizeGeneratedPlan($plan),
    ];
}

function summarizeState(array $state): array
{
    $feedback = is_array($state['feedback_analytics'] ?? null) ? $state['feedback_analytics'] : [];
    $loadPolicy = is_array($state['load_policy'] ?? null) ? $state['load_policy'] : [];

    return [
        'readiness' => (string) ($state['readiness'] ?? ''),
        'weekly_base_km' => round((float) ($state['weekly_base_km'] ?? 0.0), 1),
        'vdot' => round((float) ($state['vdot'] ?? 0.0), 1),
        'allowed_growth_ratio' => round((float) ($loadPolicy['allowed_growth_ratio'] ?? 0.0), 2),
        'quality_mode' => (string) ($loadPolicy['quality_mode'] ?? ''),
        'force_initial_recovery_week' => !empty($loadPolicy['force_initial_recovery_week']),
        'special_population_flags' => array_values((array) ($state['special_population_flags'] ?? [])),
        'adaptation_context' => is_array($state['adaptation_context'] ?? null) ? $state['adaptation_context'] : null,
        'feedback_analytics' => [
            'total_responses' => (int) ($feedback['total_responses'] ?? 0),
            'fatigue_count' => (int) ($feedback['fatigue_count'] ?? 0),
            'pain_count' => (int) ($feedback['pain_count'] ?? 0),
            'risk_level' => (string) ($feedback['risk_level'] ?? 'low'),
            'recent_average_recovery_risk' => round((float) ($feedback['recent_average_recovery_risk'] ?? 0.0), 2),
            'recent_session_rpe_avg' => round((float) ($feedback['recent_session_rpe_avg'] ?? 0.0), 2),
            'session_rpe_delta' => round((float) ($feedback['session_rpe_delta'] ?? 0.0), 2),
            'recent_pain_score_avg' => round((float) ($feedback['recent_pain_score_avg'] ?? 0.0), 2),
            'pain_score_delta' => round((float) ($feedback['pain_score_delta'] ?? 0.0), 2),
        ],
    ];
}

function summarizeGeneratedPlan(array $plan): array
{
    $weeks = array_slice((array) ($plan['weeks'] ?? []), 0, 3);
    $summary = [];

    foreach ($weeks as $week) {
        $days = (array) ($week['days'] ?? []);
        $important = [];
        foreach ($days as $day) {
            $type = (string) ($day['type'] ?? '');
            if (!empty($day['is_key_workout']) || in_array($type, ['long', 'race'], true)) {
                $important[] = [
                    'date' => (string) ($day['date'] ?? ''),
                    'type' => $type,
                    'distance_km' => round((float) ($day['distance_km'] ?? 0.0), 1),
                    'subtype' => (string) ($day['subtype'] ?? ''),
                    'description' => trim((string) ($day['description'] ?? '')),
                ];
            }
        }

        $summary[] = [
            'week_number' => (int) ($week['week_number'] ?? 0),
            'phase' => (string) ($week['phase'] ?? ''),
            'target_volume_km' => round((float) ($week['target_volume_km'] ?? 0.0), 1),
            'important_days' => $important,
        ];
    }

    return $summary;
}

function buildDirectScenarioResult(mysqli $db, int $userId, string $label, array $config): array
{
    resetScenarioUserProfile($db, $userId);
    clearExecutionData($db, $userId);
    runGenerate($db, $userId);
    $before = fetchPlanWindow($db, $userId);

    $seeded = seedScenarioWorkouts($db, $userId, $config['seed_end_date'], [
        'skip_dates' => $config['skip_dates'] ?? [],
        'pace_overrides' => $config['pace_overrides'] ?? [],
        'date_overrides' => $config['date_overrides'] ?? [],
    ]);
    $seededByDate = indexWorkoutsByDate($seeded);

    foreach ((array) ($config['followups'] ?? []) as $followup) {
        $date = (string) ($followup['date'] ?? '');
        if ($date === '' || empty($seededByDate[$date]['workout_id'])) {
            continue;
        }

        insertCompletedFollowup(
            $db,
            $userId,
            (int) $seededByDate[$date]['workout_id'],
            $date,
            (array) ($followup['analytics'] ?? [])
        );
    }

    $processor = new PlanGenerationProcessorService($db);
    $payload = [
        'reason' => (string) ($config['reason'] ?? ''),
        'cutoff_date' => (string) ($config['cutoff_date'] ?? ''),
    ];
    $enrichedPayload = invokePrivate($processor, 'enrichRecalculatePayload', [$userId, $payload]);
    $preview = previewRecalculate($db, $userId, $enrichedPayload);
    $processResult = $processor->process($userId, 'recalculate', $payload);
    $after = fetchPlanWindow($db, $userId);

    return [
        'user_id' => $userId,
        'label' => $label,
        'mode' => 'direct_recalculate',
        'seeded_workouts' => $seeded,
        'recalculate_payload' => [
            'cutoff_date' => (string) ($enrichedPayload['cutoff_date'] ?? ''),
            'kept_weeks' => (int) ($enrichedPayload['kept_weeks'] ?? 0),
            'actual_weekly_km_4w' => round((float) ($enrichedPayload['actual_weekly_km_4w'] ?? 0.0), 1),
            'progression_counters' => (array) ($enrichedPayload['progression_counters'] ?? []),
            'current_phase' => $enrichedPayload['current_phase'] ?? null,
        ],
        'preview' => $preview,
        'process_result' => $processResult,
        'before' => $before,
        'after' => $after,
    ];
}

function buildAdaptationScenarioResult(mysqli $db, int $userId, string $label, array $config): array
{
    resetScenarioUserProfile($db, $userId);
    clearExecutionData($db, $userId);
    runGenerate($db, $userId);
    $before = fetchPlanWindow($db, $userId);

    $seeded = seedScenarioWorkouts($db, $userId, $config['seed_end_date'], [
        'skip_dates' => $config['skip_dates'] ?? [],
        'pace_overrides' => $config['pace_overrides'] ?? [],
        'date_overrides' => $config['date_overrides'] ?? [],
    ]);
    $seededByDate = indexWorkoutsByDate($seeded);

    foreach ((array) ($config['followups'] ?? []) as $followup) {
        $date = (string) ($followup['date'] ?? '');
        if ($date === '' || empty($seededByDate[$date]['workout_id'])) {
            continue;
        }

        insertCompletedFollowup(
            $db,
            $userId,
            (int) $seededByDate[$date]['workout_id'],
            $date,
            (array) ($followup['analytics'] ?? [])
        );
    }

    $service = new AdaptationService($db);
    $result = $service->runWeeklyAdaptation($userId);
    $after = fetchPlanWindow($db, $userId);

    return [
        'user_id' => $userId,
        'label' => $label,
        'mode' => 'weekly_adaptation',
        'seeded_workouts' => $seeded,
        'adaptation_result' => $result,
        'before' => $before,
        'after' => $after,
    ];
}

function renderWeeks(array $window): array
{
    $importantByWeek = [];
    foreach ((array) ($window['important_days'] ?? []) as $day) {
        $importantByWeek[(int) ($day['week_number'] ?? 0)][] = sprintf(
            '%s %s',
            (string) ($day['date'] ?? ''),
            (string) ($day['type'] ?? '')
        );
    }

    $lines = [];
    foreach ((array) ($window['weeks'] ?? []) as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $lines[] = sprintf(
            '| %d | %s | %.1f | %s |',
            $weekNumber,
            (string) ($week['start_date'] ?? ''),
            (float) ($week['total_volume'] ?? 0.0),
            implode(', ', $importantByWeek[$weekNumber] ?? [])
        );
    }

    return $lines;
}

function buildMarkdownReport(array $report): string
{
    $lines = [];
    $lines[] = '# Live Planning E2E';
    $lines[] = '';
    $lines[] = '- Generated at: ' . gmdate('Y-m-d H:i:s') . ' UTC';
    $lines[] = '- Current date in environment: 2026-04-23';
    $lines[] = '- LLM path: ' . (!empty($report['context']['use_fast_llm_fallback'])
        ? 'fast fallback to algorithmic mode for processor live tests'
        : 'real local LLM path without fallback');
    $lines[] = '';

    foreach ((array) ($report['direct_recalculate'] ?? []) as $scenario) {
        $lines[] = '## Direct Recalculate: ' . $scenario['label'];
        $lines[] = '';
        $lines[] = '- User: `' . $scenario['user_id'] . '`';
        if (!empty($scenario['error'])) {
            $lines[] = '- Error: ' . (string) $scenario['error'];
            $lines[] = '';
            continue;
        }
        $lines[] = '- Payload: cutoff=' . $scenario['recalculate_payload']['cutoff_date']
            . ', kept_weeks=' . $scenario['recalculate_payload']['kept_weeks']
            . ', actual_weekly_km_4w=' . $scenario['recalculate_payload']['actual_weekly_km_4w'];
        $lines[] = '- Progression counters: ' . json_encode($scenario['recalculate_payload']['progression_counters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '- Preview state: ' . json_encode($scenario['preview']['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '';
        $lines[] = '| Week | Start | Volume | Important Days |';
        $lines[] = '| --- | --- | ---: | --- |';
        foreach (renderWeeks((array) $scenario['before']) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = 'After recalculate:';
        $lines[] = '';
        $lines[] = '| Week | Start | Volume | Important Days |';
        $lines[] = '| --- | --- | ---: | --- |';
        foreach (renderWeeks((array) $scenario['after']) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
    }

    foreach ((array) ($report['weekly_adaptation'] ?? []) as $scenario) {
        $lines[] = '## Weekly Adaptation: ' . $scenario['label'];
        $lines[] = '';
        $adapt = (array) ($scenario['adaptation_result'] ?? []);
        $lines[] = '- User: `' . $scenario['user_id'] . '`';
        if (!empty($scenario['error'])) {
            $lines[] = '- Error: ' . (string) $scenario['error'];
            $lines[] = '';
            continue;
        }
        $lines[] = '- Result: adapted=' . (!empty($adapt['adapted']) ? 'true' : 'false')
            . ', adaptation_type=' . (string) ($adapt['adaptation_type'] ?? 'null');
        $lines[] = '- Triggers: ' . json_encode($adapt['triggers'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '- Metrics: ' . json_encode($adapt['metrics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '';
        $lines[] = '| Week | Start | Volume | Important Days |';
        $lines[] = '| --- | --- | ---: | --- |';
        foreach (renderWeeks((array) $scenario['before']) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = 'After weekly adaptation:';
        $lines[] = '';
        $lines[] = '| Week | Start | Volume | Important Days |';
        $lines[] = '| --- | --- | ---: | --- |';
        foreach (renderWeeks((array) $scenario['after']) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$args = parseCliArgs($argv);
if (($args['use-fast-llm-fallback'] ?? '1') === '1') {
    $_ENV['LLM_CHAT_BASE_URL'] = 'http://127.0.0.1:1/v1';
    $_SERVER['LLM_CHAT_BASE_URL'] = 'http://127.0.0.1:1/v1';
    putenv('LLM_CHAT_BASE_URL=http://127.0.0.1:1/v1');
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$goodUserId = (int) ($args['good-user-id'] ?? 1797);
$recoveryUserId = (int) ($args['recovery-user-id'] ?? 1798);
$directCutoffDate = (string) ($args['direct-cutoff-date'] ?? '2026-04-27');
$directSeedEndDate = (string) ($args['direct-seed-end-date'] ?? '2026-04-23');
$adaptationSeedEndDate = (string) ($args['adaptation-seed-end-date'] ?? '2026-04-26');

$report = [
    'context' => [
        'good_user_id' => $goodUserId,
        'recovery_user_id' => $recoveryUserId,
        'direct_cutoff_date' => $directCutoffDate,
        'direct_seed_end_date' => $directSeedEndDate,
        'adaptation_seed_end_date' => $adaptationSeedEndDate,
        'use_fast_llm_fallback' => ($args['use-fast-llm-fallback'] ?? '0') === '1',
    ],
    'direct_recalculate' => [],
    'weekly_adaptation' => [],
];

$directScenarios = [
    [
        'user_id' => $goodUserId,
        'label' => 'good_progression',
        'config' => [
            'reason' => 'актуализируй план по выполненным тренировкам',
            'cutoff_date' => $directCutoffDate,
            'seed_end_date' => $directSeedEndDate,
        ],
    ],
    [
        'user_id' => $recoveryUserId,
        'label' => 'recovery_pressure',
        'config' => [
            'reason' => 'последние тренировки даются тяжело, нужен более мягкий пересчёт',
            'cutoff_date' => $directCutoffDate,
            'seed_end_date' => $directSeedEndDate,
            'skip_dates' => ['2026-04-23'],
            'followups' => [
                [
                    'date' => '2026-04-19',
                    'analytics' => [
                        'classification' => 'pain',
                        'pain_flag' => true,
                        'fatigue_flag' => true,
                        'session_rpe' => 8,
                        'legs_score' => 8,
                        'breath_score' => 7,
                        'hr_strain_score' => 7,
                        'pain_score' => 6,
                        'recovery_risk_score' => 0.86,
                    ],
                ],
                [
                    'date' => '2026-04-21',
                    'analytics' => [
                        'classification' => 'fatigue',
                        'pain_flag' => false,
                        'fatigue_flag' => true,
                        'session_rpe' => 9,
                        'legs_score' => 9,
                        'breath_score' => 8,
                        'hr_strain_score' => 8,
                        'pain_score' => 2,
                        'recovery_risk_score' => 0.79,
                    ],
                ],
            ],
        ],
    ],
];

foreach ($directScenarios as $scenario) {
    try {
        $report['direct_recalculate'][] = buildDirectScenarioResult(
            $db,
            (int) $scenario['user_id'],
            (string) $scenario['label'],
            (array) $scenario['config']
        );
    } catch (Throwable $e) {
        $report['direct_recalculate'][] = [
            'user_id' => (int) $scenario['user_id'],
            'label' => (string) $scenario['label'],
            'mode' => 'direct_recalculate',
            'error' => $e->getMessage(),
        ];
    }
}

$adaptationScenarios = [
    [
        'user_id' => $goodUserId,
        'label' => 'good_progression_fast_paces',
        'config' => [
            'seed_end_date' => $adaptationSeedEndDate,
            'pace_overrides' => [
                'easy' => '5:05',
                'long' => '5:20',
                'tempo' => '4:25',
                'interval' => '4:05',
                'control' => '4:20',
            ],
        ],
    ],
    [
        'user_id' => $recoveryUserId,
        'label' => 'recovery_pressure_feedback',
        'config' => [
            'seed_end_date' => $adaptationSeedEndDate,
            'skip_dates' => ['2026-04-23'],
            'pace_overrides' => [
                'easy' => '6:00',
                'long' => '6:05',
                'tempo' => '4:55',
            ],
            'followups' => [
                [
                    'date' => '2026-04-19',
                    'analytics' => [
                        'classification' => 'pain',
                        'pain_flag' => true,
                        'fatigue_flag' => true,
                        'session_rpe' => 8,
                        'legs_score' => 8,
                        'breath_score' => 7,
                        'hr_strain_score' => 7,
                        'pain_score' => 6,
                        'recovery_risk_score' => 0.88,
                    ],
                ],
                [
                    'date' => '2026-04-21',
                    'analytics' => [
                        'classification' => 'fatigue',
                        'pain_flag' => false,
                        'fatigue_flag' => true,
                        'session_rpe' => 9,
                        'legs_score' => 9,
                        'breath_score' => 8,
                        'hr_strain_score' => 8,
                        'pain_score' => 2,
                        'recovery_risk_score' => 0.81,
                    ],
                ],
            ],
        ],
    ],
];

foreach ($adaptationScenarios as $scenario) {
    try {
        $report['weekly_adaptation'][] = buildAdaptationScenarioResult(
            $db,
            (int) $scenario['user_id'],
            (string) $scenario['label'],
            (array) $scenario['config']
        );
    } catch (Throwable $e) {
        $report['weekly_adaptation'][] = [
            'user_id' => (int) $scenario['user_id'],
            'label' => (string) $scenario['label'],
            'mode' => 'weekly_adaptation',
            'error' => $e->getMessage(),
        ];
    }
}

$timestamp = gmdate('Ymd_His');
$outDir = '/var/www/planrun/tmp/live_planning_e2e';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    throw new RuntimeException('Не удалось создать директорию отчёта: ' . $outDir);
}

$jsonPath = $outDir . '/live_planning_e2e_' . $timestamp . '.json';
$mdPath = $outDir . '/live_planning_e2e_' . $timestamp . '.md';

file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($mdPath, buildMarkdownReport($report));

echo json_encode([
    'json_report' => $jsonPath,
    'markdown_report' => $mdPath,
    'context' => $report['context'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
