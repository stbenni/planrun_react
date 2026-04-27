<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PostWorkoutFollowupService.php';
require_once __DIR__ . '/../../services/ChatService.php';
require_once __DIR__ . '/../../repositories/ChatRepository.php';

class PostWorkoutFollowupServiceTest extends TestCase {
    private $db;
    private ?\PostWorkoutFollowupService $service = null;

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->service = new \PostWorkoutFollowupService($this->db);
        $this->service->ensureSchema();
        $this->db->begin_transaction();
    }

    protected function tearDown(): void {
        if ($this->db instanceof \mysqli) {
            $this->db->rollback();
        }
        parent::tearDown();
    }

    public function test_scheduleForWorkout_createsPendingFollowupForRecentManualWorkout(): void {
        $userId = 1;
        $today = date('Y-m-d');

        $insert = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 10.5, 58, NOW())"
        );
        $insert->bind_param('is', $userId, $today);
        $insert->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insert->close();

        $scheduled = $this->service->scheduleForWorkout($userId, $today, 'workout_log', $workoutLogId, 321);

        $this->assertTrue($scheduled);

        $stmt = $this->db->prepare(
            "SELECT status, analysis_message_id, workout_date
             FROM post_workout_followups
             WHERE user_id = ? AND source_kind = 'workout_log' AND source_id = ?"
        );
        $stmt->bind_param('ii', $userId, $workoutLogId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('321', (string) $row['analysis_message_id']);
        $this->assertSame($today, $row['workout_date']);
    }

    public function test_tryHandleUserReply_savesDayNoteAndCompletesFollowup(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $insertWorkout = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 8.2, 46, NOW())"
        );
        $insertWorkout->bind_param('is', $userId, $today);
        $insertWorkout->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insertWorkout->close();

        $chatRepo = new \ChatRepository($this->db);
        $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
        $followupMessageId = $chatRepo->addMessage(
            (int) $conversation['id'],
            'ai',
            null,
            'Как ощущения после тренировки?',
            ['proactive_type' => 'post_workout_checkin']
        );
        $userReply = 'Нормально, но тяжесть 8/10, ноги 8/10, дыхание 8/10, пульс 10/10, боль 0/10, ноги немного забились';
        $userMessageId = $chatRepo->addMessage((int) $conversation['id'], 'user', $userId, $userReply);

        $insertFollowup = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
             VALUES (?, 'workout_log', ?, ?, ?, 'sent', NOW(), NOW())"
        );
        $insertFollowup->bind_param('iisi', $userId, $workoutLogId, $today, $followupMessageId);
        $insertFollowup->execute();
        $followupId = (int) $this->db->insert_id;
        $insertFollowup->close();

        $result = $this->service->tryHandleUserReply($userId, (int) $conversation['id'], $userMessageId, $userReply);

        $this->assertIsArray($result);
        $assistantContent = mb_strtolower((string) ($result['assistant_content'] ?? ''));
        $this->assertTrue(
            str_contains($assistantContent, 'сохранил') || str_contains($assistantContent, 'отметил'),
            'Ответ тренера должен подтвердить, что самочувствие зафиксировано.'
        );

        $noteStmt = $this->db->prepare(
            "SELECT content
             FROM plan_day_notes
             WHERE user_id = ? AND author_id = ? AND date = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $noteStmt->bind_param('iis', $userId, $userId, $today);
        $noteStmt->execute();
        $note = $noteStmt->get_result()->fetch_assoc();
        $noteStmt->close();

        $this->assertNotNull($note);
        $this->assertStringContainsString('Самочувствие после тренировки', (string) $note['content']);
        $this->assertStringContainsString('ноги немного забились', (string) $note['content']);
        $this->assertStringContainsString('тяжесть 8/10', (string) $note['content']);
        $this->assertStringContainsString('пульс 10/10', (string) $note['content']);

        $statusStmt = $this->db->prepare(
            "SELECT status, note_id, response_message_id
             FROM post_workout_followups
             WHERE id = ?"
        );
        $statusStmt->bind_param('i', $followupId);
        $statusStmt->execute();
        $statusRow = $statusStmt->get_result()->fetch_assoc();
        $statusStmt->close();

        $this->assertSame('completed', $statusRow['status']);
        $this->assertSame((string) $userMessageId, (string) $statusRow['response_message_id']);
        $this->assertNotEmpty($statusRow['note_id']);

        $analyticsStmt = $this->db->prepare(
            "SELECT classification, pain_flag, fatigue_flag, session_rpe, legs_score, breath_score, hr_strain_score, pain_score, recovery_risk_score
             FROM post_workout_followups
             WHERE id = ?"
        );
        $analyticsStmt->bind_param('i', $followupId);
        $analyticsStmt->execute();
        $analyticsRow = $analyticsStmt->get_result()->fetch_assoc();
        $analyticsStmt->close();

        $this->assertSame('fatigue', $analyticsRow['classification']);
        $this->assertSame('0', (string) $analyticsRow['pain_flag']);
        $this->assertSame('1', (string) $analyticsRow['fatigue_flag']);
        $this->assertSame('8', (string) $analyticsRow['session_rpe']);
        $this->assertSame('8', (string) $analyticsRow['legs_score']);
        $this->assertSame('8', (string) $analyticsRow['breath_score']);
        $this->assertSame('10', (string) $analyticsRow['hr_strain_score']);
        $this->assertSame('0', (string) $analyticsRow['pain_score']);
        $this->assertGreaterThan(0.5, (float) $analyticsRow['recovery_risk_score']);

        $summary = $this->service->getRecentFeedbackAnalytics($userId, 14, $today);
        $this->assertSame(1, $summary['total_responses']);
        $this->assertSame(1, $summary['fatigue_count']);
        $this->assertSame(0, $summary['pain_count']);
        $this->assertTrue($summary['has_recent_fatigue']);
        $this->assertGreaterThanOrEqual(8.0, (float) $summary['recent_session_rpe_avg']);
        $this->assertSame('moderate', $summary['risk_level']);
    }

    public function test_chatService_routesFirstReplyToPostWorkoutFollowupWithoutLlm(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $insertWorkout = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 9.1, 51, NOW())"
        );
        $insertWorkout->bind_param('is', $userId, $today);
        $insertWorkout->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insertWorkout->close();

        $chatRepo = new \ChatRepository($this->db);
        $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
        $followupMessageId = $chatRepo->addMessage(
            (int) $conversation['id'],
            'ai',
            null,
            'Как ощущения после тренировки?',
            ['proactive_type' => 'post_workout_checkin']
        );

        $insertFollowup = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
             VALUES (?, 'workout_log', ?, ?, ?, 'sent', NOW(), NOW())"
        );
        $insertFollowup->bind_param('iisi', $userId, $workoutLogId, $today, $followupMessageId);
        $insertFollowup->execute();
        $followupId = (int) $this->db->insert_id;
        $insertFollowup->close();

        $chatService = new \ChatService($this->db);
        $response = $chatService->sendMessageAndGetResponse(
            $userId,
            'Очень тяжело, ноги забились. Тяжесть 7/10, ноги 8/10, дыхание 6/10, пульс 7/10, боль 0/10'
        );

        $assistantContent = mb_strtolower((string) ($response['content'] ?? ''));
        $this->assertTrue(
            str_contains($assistantContent, 'сохранил') || str_contains($assistantContent, 'отметил'),
            'Ответ должен быть локальным post-workout ответом, а не обычным LLM-чатом.'
        );

        $followupStmt = $this->db->prepare(
            "SELECT status, response_message_id, note_id, classification
             FROM post_workout_followups
             WHERE id = ?"
        );
        $followupStmt->bind_param('i', $followupId);
        $followupStmt->execute();
        $followupRow = $followupStmt->get_result()->fetch_assoc();
        $followupStmt->close();

        $this->assertSame('completed', $followupRow['status']);
        $this->assertNotEmpty($followupRow['response_message_id']);
        $this->assertNotEmpty($followupRow['note_id']);
        $this->assertSame('fatigue', $followupRow['classification']);

        $messageStmt = $this->db->prepare(
            "SELECT metadata
             FROM chat_messages
             WHERE id = ? AND sender_type = 'ai'
             LIMIT 1"
        );
        $messageId = (int) ($response['message_id'] ?? 0);
        $messageStmt->bind_param('i', $messageId);
        $messageStmt->execute();
        $messageRow = $messageStmt->get_result()->fetch_assoc();
        $messageStmt->close();

        $this->assertStringContainsString('post_workout_checkin_reply', (string) ($messageRow['metadata'] ?? ''));
    }

    public function test_getRecentFeedbackAnalytics_computesStructuredMetricDeltasFromBaseline(): void {
        $userId = $this->createTestUser();
        $this->insertCompletedFollowup($userId, 7001, date('Y-m-d'), 'fatigue', 0, 1, 8, 8, 8, 8, 1, 0.62);
        $this->insertCompletedFollowup($userId, 7002, date('Y-m-d', strtotime('-1 day')), 'fatigue', 0, 1, 8, 8, 8, 8, 1, 0.60);
        $this->insertCompletedFollowup($userId, 7003, date('Y-m-d', strtotime('-2 day')), 'fatigue', 0, 1, 7, 8, 6, 8, 1, 0.58);
        $this->insertCompletedFollowup($userId, 7004, date('Y-m-d', strtotime('-8 day')), 'good', 0, 0, 5, 4, 4, 4, 0, 0.20);
        $this->insertCompletedFollowup($userId, 7005, date('Y-m-d', strtotime('-10 day')), 'good', 0, 0, 4, 4, 4, 4, 0, 0.18);

        $summary = $this->service->getRecentFeedbackAnalytics($userId, 14, date('Y-m-d'));

        $this->assertSame(5, $summary['total_responses']);
        $this->assertGreaterThan(7.5, (float) $summary['recent_session_rpe_avg']);
        $this->assertGreaterThan(2.5, (float) $summary['session_rpe_delta']);
        $this->assertGreaterThan(0.75, (float) $summary['subjective_load_delta']);
        $this->assertSame('moderate', $summary['risk_level']);
    }

    public function test_getPendingCheckinState_returnsReadySentFollowupPayload(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $insert = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 12.4, 68, NOW())"
        );
        $insert->bind_param('is', $userId, $today);
        $insert->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insert->close();

        $chatRepo = new \ChatRepository($this->db);
        $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
        $followupMessageId = $chatRepo->addMessage(
            (int) $conversation['id'],
            'ai',
            null,
            'Как ощущения после сегодняшней пробежки?'
        );

        $stmt = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
             VALUES (?, 'workout_log', ?, ?, ?, 'sent', NOW(), NOW())"
        );
        $stmt->bind_param('iisi', $userId, $workoutLogId, $today, $followupMessageId);
        $stmt->execute();
        $stmt->close();

        $payload = $this->service->getPendingCheckinState($userId);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['is_ready']);
        $this->assertSame('sent', $payload['status']);
        $this->assertSame($today, $payload['workout_date']);
        $this->assertSame($followupMessageId, (int) $payload['message_id']);
        $this->assertStringContainsString('12.4 км', (string) $payload['subtitle']);
        $this->assertTrue(
            str_contains((string) $payload['prompt'], 'Как ты') || str_contains((string) $payload['prompt'], 'Как ощущения'),
            'Follow-up prompt should sound like a natural coach check-in.'
        );
    }

    public function test_getPendingCheckinState_returnsUpcomingPendingFollowupBeforeDueTime(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');
        $dueAt = date('Y-m-d H:i:s', time() + 1800);

        $insert = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 9.1, 51, NOW())"
        );
        $insert->bind_param('is', $userId, $today);
        $insert->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insert->close();

        $stmt = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, status, due_at)
             VALUES (?, 'workout_log', ?, ?, 'pending', ?)"
        );
        $stmt->bind_param('iiss', $userId, $workoutLogId, $today, $dueAt);
        $stmt->execute();
        $stmt->close();

        $payload = $this->service->getPendingCheckinState($userId);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['is_ready']);
        $this->assertSame('pending', $payload['status']);
        $this->assertSame($dueAt, $payload['due_at']);
        $this->assertNotEmpty($payload['due_at_iso']);
        $this->assertTrue(
            str_contains((string) $payload['prompt'], 'Как ты') || str_contains((string) $payload['prompt'], 'Как ощущения'),
            'Upcoming follow-up prompt should preserve a natural recovery check-in.'
        );
    }

    public function test_scheduleForWorkout_skipsWalkingActivities(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');
        $referenceTime = $today . ' 12:00:00';

        $insert = $this->db->prepare(
            "INSERT INTO workouts
                (user_id, source, activity_type, start_time, end_time, duration_minutes, distance_km)
             VALUES (?, 'strava', 'walking', ?, ?, 54, 5.2)"
        );
        $insert->bind_param('iss', $userId, $referenceTime, $referenceTime);
        $insert->execute();
        $workoutId = (int) $this->db->insert_id;
        $insert->close();

        $scheduled = $this->service->scheduleForWorkout($userId, $today, 'workout', $workoutId, 777);

        $this->assertFalse($scheduled);

        $stmt = $this->db->prepare(
            "SELECT id
             FROM post_workout_followups
             WHERE user_id = ? AND source_kind = 'workout' AND source_id = ?"
        );
        $stmt->bind_param('ii', $userId, $workoutId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertFalse((bool) $row);
    }

    public function test_scheduleForWorkout_supersedesOlderActiveFollowupsForSameUser(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $oldWorkout = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'thu', 1, 1, 1, 8.0, 45, NOW())"
        );
        $oldWorkout->bind_param('is', $userId, $yesterday);
        $oldWorkout->execute();
        $oldWorkoutLogId = (int) $this->db->insert_id;
        $oldWorkout->close();

        $newWorkout = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 12.0, 66, NOW())"
        );
        $newWorkout->bind_param('is', $userId, $today);
        $newWorkout->execute();
        $newWorkoutLogId = (int) $this->db->insert_id;
        $newWorkout->close();

        $chatRepo = new \ChatRepository($this->db);
        $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
        $oldFollowupMessageId = $chatRepo->addMessage((int) $conversation['id'], 'ai', null, 'Старый follow-up');

        $oldFollowup = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
             VALUES (?, 'workout_log', ?, ?, ?, 'sent', NOW(), NOW())"
        );
        $oldFollowup->bind_param('iisi', $userId, $oldWorkoutLogId, $yesterday, $oldFollowupMessageId);
        $oldFollowup->execute();
        $oldFollowupId = (int) $this->db->insert_id;
        $oldFollowup->close();

        $scheduled = $this->service->scheduleForWorkout($userId, $today, 'workout_log', $newWorkoutLogId, 555);

        $this->assertTrue($scheduled);

        $statusStmt = $this->db->prepare(
            "SELECT status
             FROM post_workout_followups
             WHERE id = ?"
        );
        $statusStmt->bind_param('i', $oldFollowupId);
        $statusStmt->execute();
        $oldRow = $statusStmt->get_result()->fetch_assoc();
        $statusStmt->close();

        $this->assertSame('skipped', $oldRow['status']);

        $activateStmt = $this->db->prepare(
            "UPDATE post_workout_followups
             SET due_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             WHERE user_id = ? AND source_kind = 'workout_log' AND source_id = ?"
        );
        $activateStmt->bind_param('ii', $userId, $newWorkoutLogId);
        $activateStmt->execute();
        $activateStmt->close();

        $payload = $this->service->getPendingCheckinState($userId);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['is_ready']);
        $this->assertSame($newWorkoutLogId, (int) $payload['source_id']);
        $this->assertStringContainsString('12', (string) $payload['subtitle']);
    }

    public function test_snoozeFollowup_persists_snoozed_until_and_hides_modal_until_due(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $insert = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 6.5, 38, NOW())"
        );
        $insert->bind_param('is', $userId, $today);
        $insert->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insert->close();

        $chatRepo = new \ChatRepository($this->db);
        $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
        $followupMessageId = $chatRepo->addMessage((int) $conversation['id'], 'ai', null, 'Как ощущения после тренировки?');

        $stmt = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
             VALUES (?, 'workout_log', ?, ?, ?, 'sent', NOW(), NOW())"
        );
        $stmt->bind_param('iisi', $userId, $workoutLogId, $today, $followupMessageId);
        $stmt->execute();
        $followupId = (int) $this->db->insert_id;
        $stmt->close();

        $payload = $this->service->snoozeFollowup($userId, $followupId, '30m');

        $this->assertIsArray($payload);
        $this->assertFalse($payload['is_ready']);
        $this->assertTrue($payload['is_snoozed']);
        $this->assertSame(1, (int) $payload['snooze_count']);
        $this->assertNotEmpty($payload['snoozed_until_iso']);

        $pending = $this->service->getPendingCheckinState($userId);
        $this->assertIsArray($pending);
        $this->assertFalse($pending['is_ready']);
        $this->assertTrue($pending['is_snoozed']);
    }

    public function test_tryHandleUserReply_treatsLocalizedOverloadWithoutPainAsFatigueSignal(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $insertWorkout = $this->db->prepare(
            "INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, 1, 'fri', 1, 1, 1, 10.0, 57, NOW())"
        );
        $insertWorkout->bind_param('is', $userId, $today);
        $insertWorkout->execute();
        $workoutLogId = (int) $this->db->insert_id;
        $insertWorkout->close();

        $chatRepo = new \ChatRepository($this->db);
        $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
        $followupMessageId = $chatRepo->addMessage(
            (int) $conversation['id'],
            'ai',
            null,
            'Как ощущения после тренировки?'
        );
        $userReply = 'Тяжесть 6/10, ноги 6/10, дыхание 6/10, пульс 6/10, боль 0/10. после бега в карбоновых кроссовках уставший очень голеностоп';
        $userMessageId = $chatRepo->addMessage((int) $conversation['id'], 'user', $userId, $userReply);

        $insertFollowup = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, followup_message_id, status, due_at, sent_at)
             VALUES (?, 'workout_log', ?, ?, ?, 'sent', NOW(), NOW())"
        );
        $insertFollowup->bind_param('iisi', $userId, $workoutLogId, $today, $followupMessageId);
        $insertFollowup->execute();
        $followupId = (int) $this->db->insert_id;
        $insertFollowup->close();

        $this->service->tryHandleUserReply($userId, (int) $conversation['id'], $userMessageId, $userReply);

        $stmt = $this->db->prepare(
            "SELECT classification, pain_flag, fatigue_flag, pain_score
             FROM post_workout_followups
             WHERE id = ?"
        );
        $stmt->bind_param('i', $followupId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertSame('fatigue', $row['classification']);
        $this->assertSame('0', (string) $row['pain_flag']);
        $this->assertSame('1', (string) $row['fatigue_flag']);
        $this->assertSame('0', (string) $row['pain_score']);
    }

    private function insertCompletedFollowup(
        int $userId,
        int $sourceId,
        string $workoutDate,
        string $classification,
        int $painFlag,
        int $fatigueFlag,
        int $sessionRpe,
        int $legsScore,
        int $breathScore,
        int $hrStrainScore,
        int $painScore,
        float $riskScore
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, classification, pain_flag, fatigue_flag, session_rpe, legs_score, breath_score, hr_strain_score, pain_score, recovery_risk_score, status, due_at, sent_at, responded_at)
             VALUES (?, 'workout_log', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW(), NOW())"
        );
        $stmt->bind_param(
            'iissiiiiiiid',
            $userId,
            $sourceId,
            $workoutDate,
            $classification,
            $painFlag,
            $fatigueFlag,
            $sessionRpe,
            $legsScore,
            $breathScore,
            $hrStrainScore,
            $painScore,
            $riskScore
        );
        $stmt->execute();
        $stmt->close();
    }

    private function createTestUser(): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'followup_struct_' . $suffix;
        $slug = $username;
        $email = $username . '@example.com';
        $password = password_hash('secret123', PASSWORD_DEFAULT);
        $trainingMode = 'self';
        $goalType = 'race';
        $gender = 'male';
        $onboardingCompleted = 1;

        $stmt = $this->db->prepare(
            'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssisss', $username, $slug, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        $stmt->close();

        return $userId;
    }
}
