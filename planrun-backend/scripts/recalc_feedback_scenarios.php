#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../services/PlanGenerationProcessorService.php';
require_once __DIR__ . '/../planrun_ai/skeleton/PlanSkeletonGenerator.php';
require_once __DIR__ . '/../services/PostWorkoutFollowupService.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';

function parseCliArgs(array $argv): array {
    $args = [
        'user_id' => null,
        'username' => 'st_benni',
    ];

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0] ?? '';
        $value = $parts[1] ?? '';
        if ($key !== '') {
            $args[$key] = $value;
        }
    }

    return $args;
}

function resolveUser(mysqli $db, ?string $userIdArg, string $username): array {
    if ($userIdArg !== null && $userIdArg !== '') {
        $userId = (int) $userIdArg;
        $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить запрос пользователя по id');
        }
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $db->prepare("SELECT id, username FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить запрос пользователя по username');
        }
        $stmt->bind_param('s', $username);
    }

    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new RuntimeException('Пользователь не найден');
    }

    return [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
    ];
}

function fetchRecentRunningSources(mysqli $db, int $userId, int $limit = 8): array {
    $sources = [];

    $stmt = $db->prepare(
        "SELECT 'workout' AS source_kind,
                w.id AS source_id,
                DATE(COALESCE(w.end_time, w.start_time)) AS workout_date,
                w.distance_km,
                LOWER(COALESCE(NULLIF(TRIM(w.activity_type), ''), 'running')) AS activity_type
         FROM workouts w
         LEFT JOIN post_workout_followups f
           ON f.user_id = w.user_id AND f.source_kind = 'workout' AND f.source_id = w.id
         WHERE w.user_id = ?
           AND LOWER(COALESCE(NULLIF(TRIM(w.activity_type), ''), 'running')) IN ('running', 'trail running', 'treadmill')
           AND f.id IS NULL
         ORDER BY COALESCE(w.end_time, w.start_time) DESC
         LIMIT ?"
    );
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sources[] = [
                'source_kind' => (string) $row['source_kind'],
                'source_id' => (int) $row['source_id'],
                'workout_date' => (string) $row['workout_date'],
                'distance_km' => isset($row['distance_km']) ? (float) $row['distance_km'] : 0.0,
                'activity_type' => (string) $row['activity_type'],
            ];
        }
        $stmt->close();
    }

    return $sources;
}

function fetchRecentWorkoutContext(mysqli $db, int $userId): array {
    $items = [];

    $stmt = $db->prepare(
        "SELECT id,
                DATE(COALESCE(end_time, start_time)) AS workout_date,
                distance_km,
                duration_minutes,
                LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type
         FROM workouts
         WHERE user_id = ?
         ORDER BY COALESCE(end_time, start_time) DESC
         LIMIT 8"
    );
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'id' => (int) $row['id'],
                'date' => (string) $row['workout_date'],
                'distance_km' => isset($row['distance_km']) ? (float) $row['distance_km'] : 0.0,
                'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : 0,
                'activity_type' => (string) $row['activity_type'],
            ];
        }
        $stmt->close();
    }

    return $items;
}

function fetchVolumeContext(mysqli $db, int $userId): array {
    $fourWeeksAgo = (new DateTimeImmutable('now'))->modify('-28 days')->format('Y-m-d');
    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $result = [
        'all_workouts_km_4w' => 0.0,
        'running_workouts_km_4w' => 0.0,
        'walking_workouts_km_4w' => 0.0,
    ];

    $stmt = $db->prepare(
        "SELECT distance_km,
                LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type
         FROM workouts
         WHERE user_id = ?
           AND DATE(start_time) >= ?
           AND DATE(start_time) <= ?"
    );
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $fourWeeksAgo, $today);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $distance = isset($row['distance_km']) ? (float) $row['distance_km'] : 0.0;
            $activityType = (string) ($row['activity_type'] ?? 'running');
            $result['all_workouts_km_4w'] += $distance;
            if (in_array($activityType, ['running', 'trail running', 'treadmill'], true)) {
                $result['running_workouts_km_4w'] += $distance;
            }
            if ($activityType === 'walking') {
                $result['walking_workouts_km_4w'] += $distance;
            }
        }
        $stmt->close();
    }

    foreach ($result as $key => $value) {
        $result[$key] = round($value, 1);
    }

    return $result;
}

function createSyntheticFeedback(
    mysqli $db,
    int $userId,
    array $source,
    string $responseText,
    int $scenarioIndex
): array {
    $followupService = new PostWorkoutFollowupService($db);
    $followupService->ensureSchema();
    $chatRepository = new ChatRepository($db);
    $conversation = $chatRepository->getOrCreateConversation($userId, 'ai');

    $followupMessageId = $chatRepository->addMessage(
        (int) $conversation['id'],
        'ai',
        null,
        'Как ощущения после тренировки?',
        ['proactive_type' => 'post_workout_checkin', 'scenario' => $scenarioIndex]
    );

    $insert = $db->prepare(
        "INSERT INTO post_workout_followups
            (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
         VALUES (?, ?, ?, ?, ?, 'sent', NOW(), NOW())"
    );
    if (!$insert) {
        throw new RuntimeException('Не удалось создать synthetic followup row');
    }
    $insert->bind_param(
        'isisi',
        $userId,
        $source['source_kind'],
        $source['source_id'],
        $source['workout_date'],
        $followupMessageId
    );
    $insert->execute();
    $followupId = (int) $db->insert_id;
    $insert->close();

    $userMessageId = $chatRepository->addMessage((int) $conversation['id'], 'user', $userId, $responseText);
    $handled = $followupService->tryHandleUserReply($userId, (int) $conversation['id'], $userMessageId, $responseText);
    if ($handled === null) {
        throw new RuntimeException('Synthetic feedback was not handled by PostWorkoutFollowupService');
    }

    return [
        'followup_id' => $followupId,
        'followup_message_id' => $followupMessageId,
        'user_message_id' => $userMessageId,
        'source' => $source,
        'assistant_content' => (string) ($handled['assistant_content'] ?? ''),
    ];
}

function enrichRecalculatePayload(PlanGenerationProcessorService $service, int $userId, array $payload): array {
    $method = new ReflectionMethod($service, 'enrichRecalculatePayload');
    $method->setAccessible(true);
    /** @var array $enriched */
    $enriched = $method->invoke($service, $userId, $payload);
    return $enriched;
}

function summarizePlan(array $plan): array {
    $weeks = array_slice($plan['weeks'] ?? [], 0, 3);
    $summary = [];

    foreach ($weeks as $week) {
        $days = is_array($week['days'] ?? null) ? $week['days'] : [];
        $keyTypes = [];
        $longKm = null;
        $schedule = [];

        foreach ($days as $day) {
            $dayName = (string) ($day['day_name'] ?? '');
            $type = (string) ($day['type'] ?? 'rest');
            $distanceKm = isset($day['distance_km']) ? (float) $day['distance_km'] : 0.0;
            $schedule[] = sprintf('%s:%s%s', $dayName, $type, $distanceKm > 0 ? sprintf('(%.1f)', $distanceKm) : '');

            if (!empty($day['is_key_workout'])) {
                $keyTypes[] = $type;
            }
            if ($type === 'long') {
                $longKm = round($distanceKm, 1);
            }
        }

        $summary[] = [
            'week_number' => (int) ($week['week_number'] ?? 0),
            'phase' => (string) ($week['phase_label'] ?? $week['phase'] ?? ''),
            'target_volume_km' => round((float) ($week['target_volume_km'] ?? 0.0), 1),
            'long_km' => $longKm,
            'key_types' => array_values($keyTypes),
            'key_count' => count($keyTypes),
            'schedule' => $schedule,
        ];
    }

    return $summary;
}

function buildScenarioResult(array $scenario, array $payload, array $state, array $plan, array $syntheticFeedbacks): array {
    $feedbackSummary = is_array($state['feedback_analytics'] ?? null) ? $state['feedback_analytics'] : [];

    return [
        'id' => $scenario['id'],
        'title' => $scenario['title'],
        'reason' => $scenario['reason'],
        'feedback_texts' => $scenario['feedback_texts'],
        'synthetic_feedbacks' => array_map(
            static fn(array $item): array => [
                'source_kind' => (string) ($item['source']['source_kind'] ?? ''),
                'source_id' => (int) ($item['source']['source_id'] ?? 0),
                'workout_date' => (string) ($item['source']['workout_date'] ?? ''),
                'distance_km' => isset($item['source']['distance_km']) ? (float) $item['source']['distance_km'] : 0.0,
            ],
            $syntheticFeedbacks
        ),
        'payload' => [
            'cutoff_date' => (string) ($payload['cutoff_date'] ?? ''),
            'kept_weeks' => (int) ($payload['kept_weeks'] ?? 0),
            'actual_weekly_km_4w' => isset($payload['actual_weekly_km_4w']) ? (float) $payload['actual_weekly_km_4w'] : 0.0,
        ],
        'state' => [
            'readiness' => (string) ($state['readiness'] ?? ''),
            'weekly_base_km' => isset($state['weekly_base_km']) ? (float) $state['weekly_base_km'] : 0.0,
            'allowed_growth_ratio' => isset($state['load_policy']['allowed_growth_ratio']) ? (float) $state['load_policy']['allowed_growth_ratio'] : 0.0,
            'special_population_flags' => array_values($state['special_population_flags'] ?? []),
            'feedback_analytics' => [
                'total_responses' => (int) ($feedbackSummary['total_responses'] ?? 0),
                'good_count' => (int) ($feedbackSummary['good_count'] ?? 0),
                'fatigue_count' => (int) ($feedbackSummary['fatigue_count'] ?? 0),
                'pain_count' => (int) ($feedbackSummary['pain_count'] ?? 0),
                'risk_level' => (string) ($feedbackSummary['risk_level'] ?? 'low'),
                'recent_average_recovery_risk' => (float) ($feedbackSummary['recent_average_recovery_risk'] ?? 0.0),
                'recent_session_rpe_avg' => (float) ($feedbackSummary['recent_session_rpe_avg'] ?? 0.0),
                'session_rpe_delta' => (float) ($feedbackSummary['session_rpe_delta'] ?? 0.0),
                'recent_pain_score_avg' => (float) ($feedbackSummary['recent_pain_score_avg'] ?? 0.0),
                'pain_score_delta' => (float) ($feedbackSummary['pain_score_delta'] ?? 0.0),
                'subjective_load_delta' => (float) ($feedbackSummary['subjective_load_delta'] ?? 0.0),
            ],
        ],
        'plan_summary' => summarizePlan($plan),
    ];
}

function buildDeltaSummary(array $baseline, array $scenario): array {
    $baseState = $baseline['state'];
    $scenarioState = $scenario['state'];
    $baseWeeks = $baseline['plan_summary'];
    $scenarioWeeks = $scenario['plan_summary'];

    $firstBaseWeek = $baseWeeks[0] ?? [];
    $firstScenarioWeek = $scenarioWeeks[0] ?? [];

    return [
        'readiness_change' => sprintf('%s -> %s', $baseState['readiness'], $scenarioState['readiness']),
        'weekly_base_km_change' => round(((float) $scenarioState['weekly_base_km']) - ((float) $baseState['weekly_base_km']), 1),
        'allowed_growth_ratio_change' => round(((float) $scenarioState['allowed_growth_ratio']) - ((float) $baseState['allowed_growth_ratio']), 2),
        'week1_volume_change_km' => round(((float) ($firstScenarioWeek['target_volume_km'] ?? 0.0)) - ((float) ($firstBaseWeek['target_volume_km'] ?? 0.0)), 1),
        'week1_long_change_km' => round(((float) ($firstScenarioWeek['long_km'] ?? 0.0)) - ((float) ($firstBaseWeek['long_km'] ?? 0.0)), 1),
        'week1_key_types' => $firstScenarioWeek['key_types'] ?? [],
    ];
}

function buildMarkdownReport(array $context, array $results): string {
    $lines = [];
    $lines[] = '# Recalculate Feedback Scenarios';
    $lines[] = '';
    $lines[] = '- Generated at: ' . gmdate('Y-m-d H:i:s') . ' UTC';
    $lines[] = '- User: `' . $context['user']['username'] . '` (`' . $context['user']['id'] . '`)';
    $lines[] = '- Safety: all scenario writes were executed inside transactions and rolled back';
    $lines[] = '';
    $lines[] = '## Real Context';
    $lines[] = '';
    foreach ($context['recent_workouts'] as $item) {
        $lines[] = sprintf(
            '- %s: %s %.2f km, %d min',
            $item['date'],
            $item['activity_type'],
            (float) $item['distance_km'],
            (int) $item['duration_minutes']
        );
    }
    $lines[] = '';
    $lines[] = sprintf(
        '- 4-week workouts volume: all=%.1f km, running_only=%.1f km, walking=%.1f km',
        (float) $context['volume_context']['all_workouts_km_4w'],
        (float) $context['volume_context']['running_workouts_km_4w'],
        (float) $context['volume_context']['walking_workouts_km_4w']
    );
    $lines[] = '';

    $baseline = $results[0] ?? null;
    foreach ($results as $index => $result) {
        $lines[] = '## ' . $result['title'];
        $lines[] = '';
        $lines[] = '- Reason: ' . $result['reason'];
        if (!empty($result['feedback_texts'])) {
            foreach ($result['feedback_texts'] as $text) {
                $lines[] = '- Feedback: ' . $text;
            }
        } else {
            $lines[] = '- Feedback: none';
        }
        $lines[] = sprintf(
            '- State: readiness=%s, weekly_base_km=%.1f, allowed_growth=%.2f',
            $result['state']['readiness'],
            (float) $result['state']['weekly_base_km'],
            (float) $result['state']['allowed_growth_ratio']
        );
        $lines[] = '- Flags: ' . (!empty($result['state']['special_population_flags']) ? implode(', ', $result['state']['special_population_flags']) : 'none');
        $lines[] = sprintf(
            '- Feedback analytics: responses=%d, good=%d, fatigue=%d, pain=%d, risk=%s (%.2f)',
            (int) $result['state']['feedback_analytics']['total_responses'],
            (int) $result['state']['feedback_analytics']['good_count'],
            (int) $result['state']['feedback_analytics']['fatigue_count'],
            (int) $result['state']['feedback_analytics']['pain_count'],
            $result['state']['feedback_analytics']['risk_level'],
            (float) $result['state']['feedback_analytics']['recent_average_recovery_risk']
        );
        $lines[] = sprintf(
            '- Structured signals: rpe=%.2f (Δ%+.2f), pain_score=%.2f (Δ%+.2f), load_delta=%.2f',
            (float) $result['state']['feedback_analytics']['recent_session_rpe_avg'],
            (float) $result['state']['feedback_analytics']['session_rpe_delta'],
            (float) $result['state']['feedback_analytics']['recent_pain_score_avg'],
            (float) $result['state']['feedback_analytics']['pain_score_delta'],
            (float) $result['state']['feedback_analytics']['subjective_load_delta']
        );
        $lines[] = sprintf(
            '- Recalc payload: cutoff=%s, kept_weeks=%d, actual_weekly_km_4w=%.1f',
            $result['payload']['cutoff_date'],
            (int) $result['payload']['kept_weeks'],
            (float) $result['payload']['actual_weekly_km_4w']
        );

        if ($baseline !== null && $index > 0) {
            $delta = buildDeltaSummary($baseline, $result);
            $lines[] = sprintf(
                '- Delta vs baseline: readiness %s, weekly_base %+0.1f km, allowed_growth %+0.2f, week1_volume %+0.1f km, week1_long %+0.1f km',
                $delta['readiness_change'],
                (float) $delta['weekly_base_km_change'],
                (float) $delta['allowed_growth_ratio_change'],
                (float) $delta['week1_volume_change_km'],
                (float) $delta['week1_long_change_km']
            );
        }

        foreach ($result['plan_summary'] as $week) {
            $lines[] = sprintf(
                '- Week %d (%s): %.1f km, long=%s, key=%s',
                (int) $week['week_number'],
                $week['phase'],
                (float) $week['target_volume_km'],
                $week['long_km'] !== null ? sprintf('%.1f km', (float) $week['long_km']) : 'none',
                !empty($week['key_types']) ? implode('+', $week['key_types']) : 'none'
            );
            $lines[] = '  schedule: ' . implode(' | ', $week['schedule']);
        }
        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$args = parseCliArgs($argv);
$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$user = resolveUser($db, $args['user_id'], (string) $args['username']);
$recentSources = fetchRecentRunningSources($db, $user['id'], 8);
if (count($recentSources) < 2) {
    throw new RuntimeException('Недостаточно свежих беговых тренировок без followup для сценарного теста');
}

$recentContext = fetchRecentWorkoutContext($db, $user['id']);
$volumeContext = fetchVolumeContext($db, $user['id']);

$scenarios = [
    [
        'id' => 'baseline',
        'title' => 'Baseline Recalculate',
        'reason' => 'хочу актуализировать план по свежим тренировкам',
        'feedback_texts' => [],
    ],
    [
        'id' => 'reason_more',
        'title' => 'Reason Only: More Volume',
        'reason' => 'чувствую, что план слишком простой, можно немного добавить объем и пересчитать',
        'feedback_texts' => [],
    ],
    [
        'id' => 'fatigue_only',
        'title' => 'Feedback Only: Fatigue',
        'reason' => 'хочу актуализировать план по свежим тренировкам',
        'feedback_texts' => [
            'Тяжесть 8/10, ноги 8/10, дыхание 8/10, пульс 10/10, боль 0/10. Было тяжело, не успеваю восстановиться.',
        ],
    ],
    [
        'id' => 'pain_only',
        'title' => 'Feedback Only: Pain',
        'reason' => 'хочу актуализировать план по свежим тренировкам',
        'feedback_texts' => [
            'Тяжесть 7/10, ноги 6/10, дыхание 6/10, пульс 6/10, боль 6/10. Появилась боль в икре и тянет ахилл, лучше не форсировать следующую нагрузку.',
        ],
    ],
    [
        'id' => 'fatigue_plus_easy_reason',
        'title' => 'Fatigue + Easier Reason',
        'reason' => 'последние тренировки даются тяжело, хочу чуть упростить и пересчитать план',
        'feedback_texts' => [
            'Тяжесть 9/10, ноги 10/10, дыхание 8/10, пульс 10/10, боль 1/10. Очень тяжело, есть ощущение перегруза.',
        ],
    ],
    [
        'id' => 'break_plus_fatigue',
        'title' => 'Break + Fatigue Compound',
        'reason' => 'был небольшой перерыв и сейчас тяжело возвращаться, хочу более мягкий пересчет',
        'feedback_texts' => [
            'Тяжесть 8/10, ноги 8/10, дыхание 8/10, пульс 8/10, боль 0/10. Тяжело возвращаться и не успеваю восстановиться.',
            'Тяжесть 8/10, ноги 10/10, дыхание 8/10, пульс 10/10, боль 1/10. После следующей тренировки снова тяжело, чувствую общую усталость и перегруз.',
        ],
    ],
    [
        'id' => 'fatigue_spike_vs_baseline',
        'title' => 'Fatigue Spike Vs Personal Baseline',
        'reason' => 'последние тренировки заметно тяжелее обычного, хочу пересчитать план с учетом этого',
        'feedback_texts' => [
            'Тяжесть 8/10, ноги 8/10, дыхание 8/10, пульс 8/10, боль 0/10. Последние тренировки идут тяжело.',
            'Тяжесть 8/10, ноги 10/10, дыхание 8/10, пульс 10/10, боль 1/10. Снова тяжело, восстановление хуже обычного.',
            'Тяжесть 7/10, ноги 8/10, дыхание 8/10, пульс 8/10, боль 0/10. Ноги тяжёлые, нагрузка ощущается выше обычной.',
            'Тяжесть 4/10, ноги 4/10, дыхание 4/10, пульс 4/10, боль 0/10. Тогда всё было легко.',
            'Тяжесть 4/10, ноги 4/10, дыхание 4/10, пульс 4/10, боль 0/10. Обычная лёгкая тренировка без усталости.',
        ],
    ],
];

$results = [];
$processor = new PlanGenerationProcessorService($db);

foreach ($scenarios as $scenarioIndex => $scenario) {
    $db->begin_transaction();

    try {
        $syntheticFeedbacks = [];
        foreach ($scenario['feedback_texts'] as $feedbackIndex => $text) {
            $source = $recentSources[$feedbackIndex] ?? null;
            if ($source === null) {
                throw new RuntimeException('Недостаточно тренировок-источников для synthetic feedback');
            }
            $syntheticFeedbacks[] = createSyntheticFeedback(
                $db,
                $user['id'],
                $source,
                $text,
                ($scenarioIndex + 1) * 100 + $feedbackIndex
            );
        }

        $payload = enrichRecalculatePayload($processor, $user['id'], [
            'reason' => $scenario['reason'],
        ]);

        $generator = new PlanSkeletonGenerator($db);
        $plan = $generator->generate($user['id'], 'recalculate', $payload);
        $state = $generator->getLastState() ?? [];

        $results[] = buildScenarioResult($scenario, $payload, $state, $plan, $syntheticFeedbacks);
    } finally {
        $db->rollback();
    }
}

$timestamp = gmdate('Ymd_His');
$outDir = '/var/www/planrun/tmp/recalc_feedback_scenarios';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    throw new RuntimeException('Не удалось создать директорию для отчёта: ' . $outDir);
}

$context = [
    'user' => $user,
    'recent_workouts' => $recentContext,
    'recent_running_sources' => $recentSources,
    'volume_context' => $volumeContext,
];

$jsonPath = $outDir . '/recalc_feedback_scenarios_' . $timestamp . '.json';
$mdPath = $outDir . '/recalc_feedback_scenarios_' . $timestamp . '.md';

file_put_contents($jsonPath, json_encode([
    'context' => $context,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($mdPath, buildMarkdownReport($context, $results));

echo json_encode([
    'user' => $user,
    'json_report' => $jsonPath,
    'markdown_report' => $mdPath,
    'scenarios' => array_column($results, 'id'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
