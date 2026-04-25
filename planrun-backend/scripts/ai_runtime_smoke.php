<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../services/AthleteSignalsService.php';
require_once __DIR__ . '/../services/PostWorkoutFollowupService.php';
require_once __DIR__ . '/../services/PlanExplanationService.php';
require_once __DIR__ . '/../services/AiObservabilityService.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';

$db = getDBConnection();
$db->begin_transaction();

$results = [];
$exitCode = 0;

try {
    $suffix = bin2hex(random_bytes(4));
    $username = 'ai_smoke_' . $suffix;
    $slug = $username;
    $email = $username . '@example.com';
    $password = password_hash('secret123', PASSWORD_DEFAULT);
    $onboardingCompleted = 1;
    $trainingMode = 'self';
    $goalType = 'race';
    $gender = 'male';

    $userStmt = $db->prepare(
        'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $userStmt->bind_param('ssssisss', $username, $slug, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender);
    $userStmt->execute();
    $userId = (int) $db->insert_id;
    $userStmt->close();

    $today = gmdate('Y-m-d');
    $weekStart = gmdate('Y-m-d', strtotime('monday this week'));

    $workoutStmt = $db->prepare(
        "INSERT INTO workout_log
            (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
         VALUES (?, ?, 1, 'sat', 1, 1, 1, 8.0, 45, NOW())"
    );
    $workoutStmt->bind_param('is', $userId, $today);
    $workoutStmt->execute();
    $workoutLogId = (int) $db->insert_id;
    $workoutStmt->close();

    $workoutStmt = $db->prepare(
        "INSERT INTO workout_log
            (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
         VALUES (?, ?, 1, 'sat', 1, 1, 1, 5.0, 29, NOW())"
    );
    $workoutStmt->bind_param('is', $userId, $today);
    $workoutStmt->execute();
    $snoozeWorkoutLogId = (int) $db->insert_id;
    $workoutStmt->close();

    $chatRepo = new ChatRepository($db);
    $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
    $followupMessageId = $chatRepo->addMessage((int) $conversation['id'], 'ai', null, 'Как ощущения после тренировки?');

    $followupStmt = $db->prepare(
        "INSERT INTO post_workout_followups
            (user_id, source_kind, source_id, workout_date, followup_message_id, status, classification, pain_flag, fatigue_flag, session_rpe, legs_score, breath_score, hr_strain_score, pain_score, recovery_risk_score, due_at, sent_at, responded_at)
         VALUES (?, 'workout_log', ?, ?, ?, 'completed', 'fatigue', 0, 1, 8, 8, 7, 7, 1, 0.66, NOW(), NOW(), NOW())"
    );
    $followupStmt->bind_param('iisi', $userId, $workoutLogId, $today, $followupMessageId);
    $followupStmt->execute();
    $followupStmt->close();

    $dayNoteStmt = $db->prepare(
        "INSERT INTO plan_day_notes (user_id, author_id, date, content) VALUES (?, ?, ?, ?)"
    );
    $dayNoteContent = 'Плохо спал и был стресс после рабочей недели.';
    $dayNoteStmt->bind_param('iiss', $userId, $userId, $today, $dayNoteContent);
    $dayNoteStmt->execute();
    $dayNoteStmt->close();

    $weekNoteStmt = $db->prepare(
        "INSERT INTO plan_week_notes (user_id, author_id, week_start, content) VALUES (?, ?, ?, ?)"
    );
    $weekNoteContent = 'Командировка и перелёт нарушили режим восстановления.';
    $weekNoteStmt->bind_param('iiss', $userId, $userId, $weekStart, $weekNoteContent);
    $weekNoteStmt->execute();
    $weekNoteStmt->close();

    $signals = (new AthleteSignalsService($db))->getSignalsBetween($userId, $weekStart, $today);
    $results['athlete_signals'] = [
        'ok' => (int) ($signals['note_sleep_count'] ?? 0) === 1
            && (int) ($signals['note_travel_count'] ?? 0) === 1
            && (int) ($signals['feedback']['total_responses'] ?? 0) === 1,
        'overall_risk_level' => $signals['overall_risk_level'] ?? null,
        'prompt_summary' => $signals['prompt_summary'] ?? null,
    ];

    $sentFollowupStmt = $db->prepare(
        "INSERT INTO post_workout_followups
            (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
         VALUES (?, 'workout_log', ?, ?, ?, 'sent', NOW(), NOW())"
    );
    $sentFollowupStmt->bind_param('iisi', $userId, $snoozeWorkoutLogId, $today, $followupMessageId);
    $sentFollowupStmt->execute();
    $snoozeFollowupId = (int) $db->insert_id;
    $sentFollowupStmt->close();

    $snoozed = (new PostWorkoutFollowupService($db))->snoozeFollowup($userId, $snoozeFollowupId, '30m');
    $results['snooze'] = [
        'ok' => is_array($snoozed) && !empty($snoozed['is_snoozed']) && empty($snoozed['is_ready']),
        'due_at_iso' => $snoozed['due_at_iso'] ?? null,
    ];

    $planData = [
        'weeks' => [[
            'days' => [
                ['type' => 'easy', 'planned_km' => 8.0],
                ['type' => 'tempo', 'planned_km' => 10.0],
                ['type' => 'rest', 'planned_km' => 0.0],
                ['type' => 'easy', 'planned_km' => 7.0],
                ['type' => 'interval', 'planned_km' => 9.0],
                ['type' => 'rest', 'planned_km' => 0.0],
                ['type' => 'long', 'planned_km' => 16.0],
            ],
        ]],
    ];
    $state = [
        'readiness' => 'normal',
        'vdot' => 42.1,
        'vdot_source_label' => 'лучшие свежие тренировки',
        'athlete_signals' => $signals,
    ];
    $explanation = (new PlanExplanationService($db))->buildExplanation($userId, 'recalculate', ['reason' => 'после тяжёлой недели'], $planData, $state);
    $results['plan_explanation'] = [
        'ok' => str_contains((string) ($explanation['summary'] ?? ''), 'Пересчёт сделан'),
        'summary' => $explanation['summary'] ?? null,
    ];

    $observability = new AiObservabilityService($db);
    $traceId = $observability->createTraceId('smoke');
    $observability->logEvent('smoke', 'ai_runtime_smoke', 'ok', ['checks' => array_keys($results)], $userId, $traceId, 25);
    $countStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM ai_runtime_events WHERE trace_id = ?");
    $countStmt->bind_param('s', $traceId);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();
    $results['observability'] = [
        'ok' => ((int) ($countRow['cnt'] ?? 0)) >= 1,
        'trace_id' => $traceId,
    ];

    foreach ($results as $result) {
        if (empty($result['ok'])) {
            $exitCode = 1;
            break;
        }
    }
} catch (Throwable $e) {
    $exitCode = 1;
    $results['fatal'] = [
        'ok' => false,
        'error' => $e->getMessage(),
    ];
} finally {
    $db->rollback();
}

echo json_encode([
    'ok' => $exitCode === 0,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($exitCode);
