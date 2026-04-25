<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/NoteRepository.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class PostWorkoutFollowupService extends BaseService {
    private static bool $schemaEnsured = false;

    private const STATUS_PENDING = 'pending';
    private const STATUS_SENT = 'sent';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_SKIPPED = 'skipped';
    private const STATUS_EXPIRED = 'expired';

    private const SOURCE_WORKOUT = 'workout';
    private const SOURCE_WORKOUT_LOG = 'workout_log';

    private const DEFAULT_DELAY_MINUTES = 15;
    private const DEFAULT_MAX_AGE_HOURS = 8;
    private const DEFAULT_REPLY_WINDOW_HOURS = 36;
    private const DEFAULT_ANALYTICS_LOOKBACK_DAYS = 14;
    private const DEFAULT_SNOOZE_EVENING_HOUR = 19;
    private const DEFAULT_SNOOZE_TOMORROW_HOUR = 9;

    public function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS post_workout_followups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            source_kind VARCHAR(24) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            workout_date DATE NOT NULL,
            analysis_message_id BIGINT UNSIGNED NULL DEFAULT NULL,
            followup_message_id BIGINT UNSIGNED NULL DEFAULT NULL,
            response_message_id BIGINT UNSIGNED NULL DEFAULT NULL,
            note_id INT UNSIGNED NULL DEFAULT NULL,
            classification VARCHAR(16) NULL DEFAULT NULL,
            pain_flag TINYINT(1) NOT NULL DEFAULT 0,
            fatigue_flag TINYINT(1) NOT NULL DEFAULT 0,
            session_rpe TINYINT UNSIGNED NULL DEFAULT NULL,
            legs_score TINYINT UNSIGNED NULL DEFAULT NULL,
            breath_score TINYINT UNSIGNED NULL DEFAULT NULL,
            hr_strain_score TINYINT UNSIGNED NULL DEFAULT NULL,
            pain_score TINYINT UNSIGNED NULL DEFAULT NULL,
            recovery_risk_score DECIMAL(4,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            due_at DATETIME NOT NULL,
            snoozed_until DATETIME NULL DEFAULT NULL,
            snooze_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sent_at DATETIME NULL DEFAULT NULL,
            responded_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_post_workout_followup_source (user_id, source_kind, source_id),
            INDEX idx_post_workout_followup_due (status, due_at),
            INDEX idx_post_workout_followup_reply (user_id, status, sent_at),
            INDEX idx_post_workout_followup_workout_date (user_id, workout_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->db->query($sql)) {
            $this->throwException('Не удалось подготовить таблицу post_workout_followups', 500, [
                'error' => $this->db->error,
            ]);
        }

        $this->ensureColumnExists('classification', "classification VARCHAR(16) NULL DEFAULT NULL AFTER note_id");
        $this->ensureColumnExists('pain_flag', "pain_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER classification");
        $this->ensureColumnExists('fatigue_flag', "fatigue_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER pain_flag");
        $this->ensureColumnExists('session_rpe', "session_rpe TINYINT UNSIGNED NULL DEFAULT NULL AFTER fatigue_flag");
        $this->ensureColumnExists('legs_score', "legs_score TINYINT UNSIGNED NULL DEFAULT NULL AFTER session_rpe");
        $this->ensureColumnExists('breath_score', "breath_score TINYINT UNSIGNED NULL DEFAULT NULL AFTER legs_score");
        $this->ensureColumnExists('hr_strain_score', "hr_strain_score TINYINT UNSIGNED NULL DEFAULT NULL AFTER breath_score");
        $this->ensureColumnExists('pain_score', "pain_score TINYINT UNSIGNED NULL DEFAULT NULL AFTER hr_strain_score");
        $this->ensureColumnExists('recovery_risk_score', "recovery_risk_score DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER pain_score");
        $this->ensureColumnExists('snoozed_until', "snoozed_until DATETIME NULL DEFAULT NULL AFTER due_at");
        $this->ensureColumnExists('snooze_count', "snooze_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER snoozed_until");

        self::$schemaEnsured = true;
    }

    public function getRecentFeedbackAnalytics(int $userId, int $days = self::DEFAULT_ANALYTICS_LOOKBACK_DAYS, ?string $endDate = null): array {
        $windowDays = max(1, $days);
        $effectiveEndDate = $this->isValidDate((string) $endDate) ? (string) $endDate : gmdate('Y-m-d');
        $end = DateTime::createFromFormat('Y-m-d', $effectiveEndDate) ?: new DateTime('now');
        $start = (clone $end)->modify('-' . max(0, $windowDays - 1) . ' days');

        return $this->getFeedbackAnalyticsBetween(
            $userId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );
    }

    public function getFeedbackAnalyticsBetween(int $userId, string $startDate, string $endDate): array {
        $this->ensureSchema();

        if ($userId <= 0 || !$this->isValidDate($startDate) || !$this->isValidDate($endDate) || $startDate > $endDate) {
            return $this->buildEmptyFeedbackAnalytics($startDate, $endDate);
        }

        $stmt = $this->db->prepare(
            "SELECT f.id,
                    f.workout_date,
                    f.responded_at,
                    f.classification,
                    f.pain_flag,
                    f.fatigue_flag,
                    f.session_rpe,
                    f.legs_score,
                    f.breath_score,
                    f.hr_strain_score,
                    f.pain_score,
                    f.recovery_risk_score,
                    cm.content AS response_content
             FROM post_workout_followups f
             LEFT JOIN chat_messages cm ON cm.id = f.response_message_id
             WHERE f.user_id = ?
               AND f.status = ?
               AND f.workout_date BETWEEN ? AND ?
             ORDER BY f.workout_date DESC, f.responded_at DESC, f.id DESC"
        );
        if (!$stmt) {
            return $this->buildEmptyFeedbackAnalytics($startDate, $endDate);
        }

        $status = self::STATUS_COMPLETED;
        $stmt->bind_param('isss', $userId, $status, $startDate, $endDate);
        if (!$stmt->execute()) {
            $stmt->close();
            return $this->buildEmptyFeedbackAnalytics($startDate, $endDate);
        }

        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->hydrateFeedbackAnalyticsRow($row);
        }
        $stmt->close();

        return $this->buildFeedbackAnalyticsSummary($rows, $startDate, $endDate);
    }

    public function getPendingCheckinState(int $userId): ?array {
        $this->ensureSchema();
        $this->expireStaleSentFollowups();

        if ($userId <= 0) {
            return null;
        }

        $active = $this->getLatestAwaitingReply($userId);
        if ($active !== null) {
            $summary = $this->getWorkoutSummary($userId, (string) ($active['source_kind'] ?? ''), (int) ($active['source_id'] ?? 0));
            if ($summary !== null && $this->shouldScheduleForSummary($userId, $summary)) {
                return $this->buildClientFollowupPayload($active, $this->isFollowupVisibleNow($active), $summary);
            }
        }

        $pending = $this->getNextPendingForUser($userId);
        if ($pending === null) {
            return null;
        }

        $summary = $this->getWorkoutSummary($userId, (string) ($pending['source_kind'] ?? ''), (int) ($pending['source_id'] ?? 0));
        if ($summary === null || !$this->shouldScheduleForSummary($userId, $summary)) {
            $this->markFollowupStatus((int) ($pending['id'] ?? 0), self::STATUS_SKIPPED);
            return null;
        }

        if ($this->isFollowupDue($pending)) {
            $activated = $this->dispatchFollowupMessage($pending, $summary, false);
            if ($activated !== null) {
                return $this->buildClientFollowupPayload($activated, true, $summary);
            }
            return null;
        }

        return $this->buildClientFollowupPayload($pending, false, $summary);
    }

    public function scheduleForWorkout(
        int $userId,
        string $workoutDate,
        string $sourceKind,
        int $sourceId,
        ?int $analysisMessageId = null
    ): bool {
        $this->ensureSchema();

        if ($userId <= 0 || $sourceId <= 0) {
            return false;
        }

        if (!in_array($sourceKind, [self::SOURCE_WORKOUT, self::SOURCE_WORKOUT_LOG], true)) {
            return false;
        }

        $summary = $this->getWorkoutSummary($userId, $sourceKind, $sourceId);
        if ($summary === null) {
            return false;
        }

        $effectiveWorkoutDate = trim((string) ($summary['workout_date'] ?? $workoutDate));
        if (!$this->isValidDate($effectiveWorkoutDate) || !$this->shouldScheduleForSummary($userId, $summary)) {
            return false;
        }

        $existing = $this->getFollowupBySource($userId, $sourceKind, $sourceId);
        if ($existing !== null && in_array((string) ($existing['status'] ?? ''), [
            self::STATUS_PENDING,
            self::STATUS_SENT,
            self::STATUS_COMPLETED,
        ], true)) {
            return false;
        }

        $dueAt = (new DateTime('now'))->modify('+' . $this->getDelayMinutes() . ' minutes')->format('Y-m-d H:i:s');
        $this->supersedeOtherActiveFollowups($userId, $sourceKind, $sourceId);

        if ($existing !== null) {
            $stmt = $this->db->prepare(
                "UPDATE post_workout_followups
                 SET workout_date = ?, analysis_message_id = ?, followup_message_id = NULL, response_message_id = NULL,
                     note_id = NULL, status = ?, due_at = ?, snoozed_until = NULL, snooze_count = 0, sent_at = NULL, responded_at = NULL
                 WHERE id = ?"
            );
            if (!$stmt) {
                return false;
            }
            $status = self::STATUS_PENDING;
            $id = (int) $existing['id'];
            $stmt->bind_param('sissi', $effectiveWorkoutDate, $analysisMessageId, $status, $dueAt, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO post_workout_followups
                (user_id, source_kind, source_id, workout_date, analysis_message_id, status, due_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }

        $status = self::STATUS_PENDING;
        $stmt->bind_param('isisiss', $userId, $sourceKind, $sourceId, $effectiveWorkoutDate, $analysisMessageId, $status, $dueAt);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function snoozeFollowup(int $userId, int $followupId, string $preset = '30m'): ?array {
        $this->ensureSchema();
        $this->expireStaleSentFollowups();

        if ($userId <= 0 || $followupId <= 0) {
            return null;
        }

        $followup = $this->getFollowupByIdForUser($userId, $followupId);
        if ($followup === null) {
            return null;
        }

        $summary = $this->getWorkoutSummary($userId, (string) ($followup['source_kind'] ?? ''), (int) ($followup['source_id'] ?? 0));
        if ($summary === null || !$this->shouldScheduleForSummary($userId, $summary)) {
            return null;
        }

        $snoozedUntil = $this->resolveSnoozeUntil($userId, $preset);
        if ($snoozedUntil === null) {
            return null;
        }

        $snoozedUtc = $snoozedUntil->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "UPDATE post_workout_followups
             SET due_at = ?, snoozed_until = ?, snooze_count = snooze_count + 1
             WHERE id = ? AND user_id = ?"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ssii', $snoozedUtc, $snoozedUtc, $followupId, $userId);
        $stmt->execute();
        $stmt->close();

        $updated = $this->getFollowupByIdForUser($userId, $followupId);
        if ($updated === null) {
            return null;
        }

        return $this->buildClientFollowupPayload($updated, false, $summary);
    }

    public function processDueFollowups(int $limit = 50): array {
        $this->ensureSchema();
        $stats = ['sent' => 0, 'skipped' => 0, 'expired' => 0, 'errors' => 0];
        $stats['expired'] = $this->expireStaleSentFollowups();

        $stmt = $this->db->prepare(
            "SELECT id, user_id, source_kind, source_id, workout_date, analysis_message_id
             FROM post_workout_followups
             WHERE status = ? AND due_at <= NOW()
             ORDER BY due_at ASC
             LIMIT ?"
        );
        if (!$stmt) {
            return $stats;
        }

        $status = self::STATUS_PENDING;
        $stmt->bind_param('si', $status, $limit);
        if (!$stmt->execute()) {
            $stmt->close();
            return $stats;
        }

        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        foreach ($rows as $row) {
            $followupId = (int) ($row['id'] ?? 0);
            $userId = (int) ($row['user_id'] ?? 0);
            $sourceKind = (string) ($row['source_kind'] ?? '');
            $sourceId = (int) ($row['source_id'] ?? 0);

            try {
                $summary = $this->getWorkoutSummary($userId, $sourceKind, $sourceId);
                if ($summary === null || !$this->shouldScheduleForSummary($userId, $summary)) {
                    $this->markFollowupStatus($followupId, self::STATUS_SKIPPED);
                    $stats['skipped']++;
                    continue;
                }

                $message = $this->buildFollowupPrompt($userId, $summary);
                if ($message === '') {
                    $this->markFollowupStatus($followupId, self::STATUS_SKIPPED);
                    $stats['skipped']++;
                    continue;
                }

                if ($this->dispatchFollowupMessage($row, $summary, true) !== null) {
                    $stats['sent']++;
                    continue;
                }

                $stats['errors']++;
            } catch (Throwable $e) {
                Logger::warning('PostWorkoutFollowup: processing failed', [
                    'followupId' => $followupId,
                    'userId' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    public function tryHandleUserReply(int $userId, int $conversationId, int $userMessageId, string $content): ?array {
        $this->ensureSchema();
        $this->expireStaleSentFollowups();

        if (!$this->isLikelyFeedbackResponse($content)) {
            return null;
        }

        $followup = $this->getLatestAwaitingReply($userId);
        if ($followup === null) {
            return null;
        }

        $followupMessageId = (int) ($followup['followup_message_id'] ?? 0);
        if ($followupMessageId <= 0 || !$this->isFirstUserReplyAfterFollowup($conversationId, $followupMessageId, $userMessageId)) {
            return null;
        }

        $summary = $this->getWorkoutSummary($userId, (string) $followup['source_kind'], (int) $followup['source_id']);
        $feedbackAnalysis = $this->analyzeFeedback($content);
        $storedNote = $this->buildStoredNoteContent($content, $feedbackAnalysis);
        $noteRepo = new NoteRepository($this->db);
        $noteResult = $noteRepo->addDayNote($userId, $userId, (string) $followup['workout_date'], $storedNote);
        $noteId = (int) ($noteResult['insert_id'] ?? 0);

        $this->appendFeedbackToWorkoutLogIfPossible($followup, $content);

        $classification = $feedbackAnalysis['classification'];
        $assistantContent = $this->buildCoachReply($userId, (string) $followup['workout_date'], $classification, $summary);

        $stmt = $this->db->prepare(
            "UPDATE post_workout_followups
             SET status = ?, response_message_id = ?, note_id = ?, classification = ?, pain_flag = ?, fatigue_flag = ?,
                 session_rpe = ?, legs_score = ?, breath_score = ?, hr_strain_score = ?, pain_score = ?, recovery_risk_score = ?, responded_at = NOW()
             WHERE id = ?"
        );
        if ($stmt) {
            $completedStatus = self::STATUS_COMPLETED;
            $followupId = (int) $followup['id'];
            $painFlag = (int) ($feedbackAnalysis['pain_flag'] ? 1 : 0);
            $fatigueFlag = (int) ($feedbackAnalysis['fatigue_flag'] ? 1 : 0);
            $sessionRpe = $this->nullableInt($feedbackAnalysis['session_rpe'] ?? null);
            $legsScore = $this->nullableInt($feedbackAnalysis['legs_score'] ?? null);
            $breathScore = $this->nullableInt($feedbackAnalysis['breath_score'] ?? null);
            $hrStrainScore = $this->nullableInt($feedbackAnalysis['hr_strain_score'] ?? null);
            $painScore = $this->nullableInt($feedbackAnalysis['pain_score'] ?? null);
            $recoveryRiskScore = (float) ($feedbackAnalysis['recovery_risk_score'] ?? 0.0);
            $stmt->bind_param(
                'siisiiiiiiidi',
                $completedStatus,
                $userMessageId,
                $noteId,
                $classification,
                $painFlag,
                $fatigueFlag,
                $sessionRpe,
                $legsScore,
                $breathScore,
                $hrStrainScore,
                $painScore,
                $recoveryRiskScore,
                $followupId
            );
            $stmt->execute();
            $stmt->close();
        }

        return [
            'assistant_content' => $assistantContent,
            'metadata' => [
                'proactive_type' => 'post_workout_checkin_reply',
                'post_workout_followup' => [
                    'id' => (int) $followup['id'],
                    'classification' => $classification,
                    'pain_flag' => (bool) ($feedbackAnalysis['pain_flag'] ?? false),
                    'fatigue_flag' => (bool) ($feedbackAnalysis['fatigue_flag'] ?? false),
                    'session_rpe' => $feedbackAnalysis['session_rpe'] ?? null,
                    'legs_score' => $feedbackAnalysis['legs_score'] ?? null,
                    'breath_score' => $feedbackAnalysis['breath_score'] ?? null,
                    'hr_strain_score' => $feedbackAnalysis['hr_strain_score'] ?? null,
                    'pain_score' => $feedbackAnalysis['pain_score'] ?? null,
                    'recovery_risk_score' => round((float) ($feedbackAnalysis['recovery_risk_score'] ?? 0.0), 2),
                    'note_id' => $noteId,
                ],
            ],
        ];
    }

    private function getFollowupBySource(int $userId, string $sourceKind, int $sourceId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, status
             FROM post_workout_followups
             WHERE user_id = ? AND source_kind = ? AND source_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('isi', $userId, $sourceKind, $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function getLatestAwaitingReply(int $userId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, user_id, source_kind, source_id, workout_date, analysis_message_id, followup_message_id, due_at, snoozed_until, snooze_count, status, sent_at
             FROM post_workout_followups
             WHERE user_id = ? AND status = ?
             ORDER BY sent_at DESC, id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $status = self::STATUS_SENT;
        $stmt->bind_param('is', $userId, $status);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function getNextPendingForUser(int $userId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, user_id, source_kind, source_id, workout_date, analysis_message_id, followup_message_id, due_at, snoozed_until, snooze_count, status, sent_at
             FROM post_workout_followups
             WHERE user_id = ? AND status = ?
             ORDER BY due_at ASC, id ASC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $status = self::STATUS_PENDING;
        $stmt->bind_param('is', $userId, $status);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function supersedeOtherActiveFollowups(int $userId, string $sourceKind, int $sourceId): void {
        if ($userId <= 0 || $sourceId <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE post_workout_followups
             SET status = ?, snoozed_until = NULL
             WHERE user_id = ?
               AND status IN (?, ?)
               AND NOT (source_kind = ? AND source_id = ?)"
        );
        if (!$stmt) {
            return;
        }

        $superseded = self::STATUS_SKIPPED;
        $pending = self::STATUS_PENDING;
        $sent = self::STATUS_SENT;
        $stmt->bind_param('sisssi', $superseded, $userId, $pending, $sent, $sourceKind, $sourceId);
        $stmt->execute();
        $stmt->close();
    }

    private function markFollowupStatus(int $followupId, string $status): void {
        $stmt = $this->db->prepare("UPDATE post_workout_followups SET status = ? WHERE id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $status, $followupId);
        $stmt->execute();
        $stmt->close();
    }

    private function expireStaleSentFollowups(): int {
        $cutoff = (new DateTime('now'))->modify('-' . $this->getReplyWindowHours() . ' hours')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "UPDATE post_workout_followups
             SET status = ?
             WHERE status = ? AND sent_at IS NOT NULL AND sent_at < ?"
        );
        if (!$stmt) {
            return 0;
        }

        $expired = self::STATUS_EXPIRED;
        $sent = self::STATUS_SENT;
        $stmt->bind_param('sss', $expired, $sent, $cutoff);
        $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();
        return max(0, $affected);
    }

    private function isFirstUserReplyAfterFollowup(int $conversationId, int $followupMessageId, int $userMessageId): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt
             FROM chat_messages
             WHERE conversation_id = ?
               AND sender_type = 'user'
               AND id > ?
               AND id < ?"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $conversationId, $followupMessageId, $userMessageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ((int) ($row['cnt'] ?? 0)) === 0;
    }

    private function getWorkoutSummary(int $userId, string $sourceKind, int $sourceId): ?array {
        if ($sourceKind === self::SOURCE_WORKOUT) {
            $stmt = $this->db->prepare(
                "SELECT id,
                        DATE(COALESCE(end_time, start_time)) AS workout_date,
                        COALESCE(end_time, start_time) AS reference_time,
                        distance_km,
                        duration_minutes,
                        LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) AS activity_type
                 FROM workouts
                 WHERE id = ? AND user_id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('ii', $sourceId, $userId);
        } else {
            $stmt = $this->db->prepare(
                "SELECT wl.id,
                        wl.training_date AS workout_date,
                        wl.updated_at AS reference_time,
                        wl.distance_km,
                        wl.duration_minutes,
                        LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type
                 FROM workout_log wl
                 LEFT JOIN activity_types at ON at.id = wl.activity_type_id
                 WHERE wl.id = ? AND wl.user_id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('ii', $sourceId, $userId);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function shouldScheduleForSummary(int $userId, array $summary): bool {
        $workoutDate = trim((string) ($summary['workout_date'] ?? ''));
        if (!$this->isValidDate($workoutDate)) {
            return false;
        }

        $activityType = strtolower(trim((string) ($summary['activity_type'] ?? 'running')));
        if (in_array($activityType, ['walking', 'walk'], true)) {
            return false;
        }

        $tz = $this->getUserTimezone($userId);
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        if ($workoutDate !== $today) {
            return false;
        }

        $referenceTime = trim((string) ($summary['reference_time'] ?? ''));
        if ($referenceTime !== '') {
            try {
                $reference = new DateTime($referenceTime);
                $now = new DateTime('now');
                $hours = abs($now->getTimestamp() - $reference->getTimestamp()) / 3600;
                if ($hours > $this->getMaxAgeHours()) {
                    return false;
                }
            } catch (Throwable $e) {
                Logger::debug('PostWorkoutFollowup: invalid reference time', ['reference_time' => $referenceTime]);
            }
        }

        return true;
    }

    private function buildFollowupPrompt(int $userId, array $summary): string {
        $workoutDate = (string) ($summary['workout_date'] ?? '');
        $distanceKm = isset($summary['distance_km']) ? (float) $summary['distance_km'] : 0.0;
        $activityType = $this->getActivityTypeRu((string) ($summary['activity_type'] ?? 'running'));
        $tz = $this->getUserTimezone($userId);
        $today = (new DateTime('now', $tz))->format('Y-m-d');

        $subject = $workoutDate === $today
            ? "после сегодняшней {$activityType}"
            : 'после тренировки';

        if ($distanceKm > 0) {
            $subject .= sprintf(' на %.1f км', $distanceKm);
        }

        return "Как ты {$subject}? Можно просто коротко текстом. Если удобно, ответь в формате: тяжесть 1-10, ноги 1-10, дыхание 1-10, пульс 1-10, боль 0-10. Если была боль или ощущение перегруза, обязательно напиши об этом.";
    }

    private function buildStoredNoteContent(string $content, array $feedbackAnalysis = []): string {
        $summaryParts = [];
        if (isset($feedbackAnalysis['session_rpe'])) {
            $summaryParts[] = 'тяжесть ' . (int) $feedbackAnalysis['session_rpe'] . '/10';
        }
        if (isset($feedbackAnalysis['legs_score'])) {
            $summaryParts[] = 'ноги ' . (int) $feedbackAnalysis['legs_score'] . '/10';
        }
        if (isset($feedbackAnalysis['breath_score'])) {
            $summaryParts[] = 'дыхание ' . (int) $feedbackAnalysis['breath_score'] . '/10';
        }
        if (isset($feedbackAnalysis['hr_strain_score'])) {
            $summaryParts[] = 'пульс ' . (int) $feedbackAnalysis['hr_strain_score'] . '/10';
        }
        if (isset($feedbackAnalysis['pain_score'])) {
            $summaryParts[] = 'боль ' . (int) $feedbackAnalysis['pain_score'] . '/10';
        }

        $structured = !empty($summaryParts) ? ' [' . implode(', ', $summaryParts) . ']' : '';
        return 'Самочувствие после тренировки: ' . trim($content) . $structured;
    }

    private function appendFeedbackToWorkoutLogIfPossible(array $followup, string $content): void {
        if (($followup['source_kind'] ?? '') !== self::SOURCE_WORKOUT_LOG) {
            return;
        }

        $sourceId = (int) ($followup['source_id'] ?? 0);
        if ($sourceId <= 0) {
            return;
        }

        $taggedFeedback = '[самочувствие после тренировки] ' . trim($content);
        $stmt = $this->db->prepare("SELECT notes FROM workout_log WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $existingNotes = trim((string) ($row['notes'] ?? ''));
        if ($existingNotes !== '' && mb_stripos($existingNotes, $taggedFeedback) !== false) {
            return;
        }

        $newNotes = $existingNotes === '' ? $taggedFeedback : ($existingNotes . "\n\n" . $taggedFeedback);
        $update = $this->db->prepare("UPDATE workout_log SET notes = ?, updated_at = NOW() WHERE id = ?");
        if (!$update) {
            return;
        }
        $update->bind_param('si', $newNotes, $sourceId);
        $update->execute();
        $update->close();
    }

    private function classifyFeedback(string $content): string {
        return (string) ($this->analyzeFeedback($content)['classification'] ?? 'neutral');
    }

    private function analyzeFeedback(string $content): array {
        $normalized = mb_strtolower(trim($content));
        $explicitPainScore = $this->extractStructuredScore($normalized, ['боль', 'pain'], 10, 0);
        $painVerbDetected = (bool) preg_match('/болит|забол|тянет|прострел|ноет|ломит|щем|режет|дискомфорт|судорог|головокруж|тошн|травм|отек|опух/u', $normalized);
        $bodyPartMention = (bool) preg_match('/колен|ахилл|икр|голен|стоп|спин|поясниц/u', $normalized);
        $injuryKeywordDetected = $painVerbDetected || (
            $bodyPartMention
            && (bool) preg_match('/бол|тян|прострел|дискомфорт|судорог|травм|забил|забит|неприят/u', $normalized)
        );
        $painFlag = $injuryKeywordDetected || (bool) preg_match('/\b(?:боль|pain)\b/u', $normalized);
        if ($explicitPainScore !== null) {
            if ($explicitPainScore <= 0) {
                $painFlag = $injuryKeywordDetected && $painVerbDetected;
            } elseif ($explicitPainScore === 1) {
                $painFlag = $injuryKeywordDetected && $painVerbDetected;
            } else {
                $painFlag = true;
            }
        }
        $localizedDiscomfortDetected = !$painFlag && $bodyPartMention && (bool) preg_match(
            '/перегруз|перегруж|неприят|дискомфорт|натерт|натёр|забил|забит|зажат|устал|усталый|уставш|напряж|чувствуется|отдает|отдаёт/u',
            $normalized
        );
        $fatigueFlag = (bool) preg_match('/очень\s+тяж|тяжел|тяжко|разбит|устал|усталость|забил|забились|на\s+пределе|еле|задох|дыхание\s+тяж|пульс\s+высок|перегруз|не\s+восстанов/u', $normalized) || $localizedDiscomfortDetected;
        $positiveSignal = (bool) preg_match('/отлич|хорош|норм|нормально|легко|комфорт|свеж|все\s+ок|всё\s+ок|ok\b/u', $normalized);

        if ($painFlag) {
            $classification = 'pain';
        } elseif ($localizedDiscomfortDetected) {
            $classification = 'fatigue';
        } elseif ($fatigueFlag) {
            $classification = 'fatigue';
        } elseif ($positiveSignal) {
            $classification = 'good';
        } else {
            $classification = 'neutral';
        }

        $recoveryRisk = 0.25;
        if ($classification === 'good') {
            $recoveryRisk = 0.0;
        } elseif ($classification === 'fatigue') {
            $recoveryRisk = 0.62;
            if (preg_match('/очень|еле|на\s+пределе|не\s+восстанов|перегруз|пульс\s+высок|дыхание\s+тяж/u', $normalized)) {
                $recoveryRisk = 0.74;
            }
        } elseif ($classification === 'pain') {
            $recoveryRisk = 0.84;
            if (preg_match('/сильн|остр|прострел|не\s+могу|хром/u', $normalized)) {
                $recoveryRisk = 0.95;
            }
        }

        $sessionRpe = $this->resolveSessionRpe($normalized, $classification);
        $legsScore = $this->resolveTenPointScore(
            $normalized,
            ['ноги', 'икры', 'икронож', 'квадры', 'бедра'],
            $classification
        );
        $breathScore = $this->resolveTenPointScore(
            $normalized,
            ['дыхание', 'дышалось', 'одышка'],
            $classification
        );
        $hrStrainScore = $this->resolveTenPointScore(
            $normalized,
            ['пульс', 'чсс', 'сердце'],
            $classification
        );
        $painScore = $explicitPainScore ?? $this->resolvePainScore($normalized, $classification, $painFlag);

        $structuredLoad = array_filter([$legsScore, $breathScore, $hrStrainScore], static fn($value): bool => $value !== null);
        $loadComponent = !empty($structuredLoad) ? (array_sum($structuredLoad) / (count($structuredLoad) * 10.0)) : 0.0;
        $rpeComponent = $sessionRpe !== null ? ($sessionRpe / 10.0) : 0.0;
        $painComponent = $painScore !== null ? ($painScore / 10.0) : 0.0;
        $recoveryRisk = round(max($recoveryRisk, min(1.0, ($rpeComponent * 0.35) + ($loadComponent * 0.40) + ($painComponent * 0.25))), 2);

        return [
            'classification' => $classification,
            'pain_flag' => $painFlag,
            'fatigue_flag' => $fatigueFlag,
            'session_rpe' => $sessionRpe,
            'legs_score' => $legsScore,
            'breath_score' => $breathScore,
            'hr_strain_score' => $hrStrainScore,
            'pain_score' => $painScore,
            'recovery_risk_score' => round($recoveryRisk, 2),
        ];
    }

    private function isLikelyFeedbackResponse(string $content): bool {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '' || mb_strlen($normalized) > 1000) {
            return false;
        }

        if (preg_match('/\b(какой|какая|когда|сколько|покажи|перенес|перенеси|добавь|удали|замени|обнови|пересчитай|сгенерируй|что\s+у\s+меня|какой\s+план)\b/u', $normalized) && str_contains($normalized, '?')) {
            return false;
        }

        if (preg_match('/боль|болит|тянет|тяжел|тяжко|устал|усталость|разбит|легко|комфорт|норм|хорош|отлич|ок\b|ноги|икры|колено|ахилл|дыхани|пульс|перегруз|забил|забились|свеж/u', $normalized)) {
            return true;
        }

        return (bool) preg_match('/^(все\s+ок|всё\s+ок|ок|окей|норм|нормально|хорошо|отлично|терпимо|тяжело|легко)$/u', $normalized);
    }

    private function buildCoachReply(int $userId, string $workoutDate, string $classification, ?array $summary): string {
        $nextDay = $this->getNextPlannedDay($userId, $workoutDate);
        $nextAdvice = $this->buildNextDayAdvice($nextDay);

        return match ($classification) {
            'pain' => trim("Спасибо, я сохранил это в заметки к дню. Если сейчас есть боль, лучше не терпеть и не пытаться продавить следующую нагрузку. {$nextAdvice} Если хочешь, я помогу упростить или перенести ближайшую тренировку."),
            'fatigue' => trim("Спасибо, отметил самочувствие. Похоже, после тренировки накопилась усталость или локальная перегрузка, так что сейчас разумнее сделать акцент на восстановлении. {$nextAdvice} Если хочешь, я могу подстроить ближайший день плана."),
            'good' => trim("Отлично, самочувствие сохранил. Это хороший знак: похоже, текущая нагрузка переносится нормально. {$nextAdvice}"),
            default => trim("Спасибо, самочувствие сохранил. Если позже появится усталость, боль или ощущение перегруза, напиши мне в чат. {$nextAdvice}"),
        };
    }

    private function getNextPlannedDay(int $userId, string $workoutDate): ?array {
        $stmt = $this->db->prepare(
            "SELECT d.date, d.type, d.description
             FROM training_plan_days d
             INNER JOIN training_plan_weeks w ON w.id = d.week_id
             WHERE w.user_id = ? AND d.date > ?
             ORDER BY d.date ASC, d.id ASC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $userId, $workoutDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function buildNextDayAdvice(?array $nextDay): string {
        if ($nextDay === null) {
            return 'Если почувствуешь, что восстановление идёт не так, как хотелось бы, напиши, и мы спокойно подумаем, как скорректировать нагрузку.';
        }

        $date = (string) ($nextDay['date'] ?? '');
        $type = (string) ($nextDay['type'] ?? '');
        $typeRu = $this->getDayTypeRu($type);

        if (in_array($type, ['rest', 'free'], true)) {
            return "Следующий день по плану — {$this->formatDateRu($date)}: {$typeRu}. Постарайся использовать его именно для восстановления.";
        }

        return "Следующая тренировка по плану — {$this->formatDateRu($date)}: {$typeRu}. Если самочувствие к ней не выровняется, лучше не форсировать.";
    }

    private function isFollowupDue(array $followup): bool {
        $dueAt = trim((string) ($followup['due_at'] ?? ''));
        if ($dueAt === '') {
            return true;
        }

        try {
            $due = new DateTime($dueAt);
            $now = new DateTime('now');
            return $due <= $now;
        } catch (Throwable $e) {
            return true;
        }
    }

    private function isFollowupVisibleNow(array $followup): bool {
        $snoozedUntil = trim((string) ($followup['snoozed_until'] ?? ''));
        if ($snoozedUntil === '') {
            return true;
        }

        try {
            $until = new DateTime($snoozedUntil, new DateTimeZone('UTC'));
            $now = new DateTime('now', new DateTimeZone('UTC'));
            return $until <= $now;
        } catch (Throwable $e) {
            return true;
        }
    }

    private function dispatchFollowupMessage(array $followup, array $summary, bool $withNotifications = true): ?array {
        $followupId = (int) ($followup['id'] ?? 0);
        $userId = (int) ($followup['user_id'] ?? 0);
        if ($followupId <= 0 || $userId <= 0) {
            return null;
        }

        $message = $this->buildFollowupPrompt($userId, $summary);
        if ($message === '') {
            $this->markFollowupStatus($followupId, self::STATUS_SKIPPED);
            return null;
        }

        $messageId = $withNotifications
            ? $this->createNotifiedFollowupMessage($userId, $message)
            : $this->createInAppFollowupMessage($userId, $message);

        if ($messageId <= 0) {
            return null;
        }

        $update = $this->db->prepare(
            "UPDATE post_workout_followups
             SET status = ?, sent_at = NOW(), followup_message_id = ?, snoozed_until = NULL
             WHERE id = ?"
        );
        if ($update) {
            $sentStatus = self::STATUS_SENT;
            $update->bind_param('sii', $sentStatus, $messageId, $followupId);
            $update->execute();
            $update->close();
        }

        $followup['status'] = self::STATUS_SENT;
        $followup['followup_message_id'] = $messageId;
        $followup['prompt'] = $message;
        $followup['sent_at'] = gmdate('Y-m-d H:i:s');

        return $followup;
    }

    private function createNotifiedFollowupMessage(int $userId, string $message): int {
        require_once __DIR__ . '/ChatService.php';
        $chatService = new ChatService($this->db);
        $resultMessage = $chatService->addAIMessageToUser($userId, $message, [
            'event_key' => 'coach.proactive_post_workout_checkin',
            'title' => 'Вопрос после тренировки',
            'link' => '/chat',
            'proactive_type' => 'post_workout_checkin',
            'push_data' => ['post_workout_followup' => true],
        ]);

        return (int) ($resultMessage['message_id'] ?? 0);
    }

    private function createInAppFollowupMessage(int $userId, string $message): int {
        $chatRepo = new ChatRepository($this->db);
        $conversation = $chatRepo->getOrCreateConversation($userId, 'ai');
        $messageId = $chatRepo->addMessage(
            (int) ($conversation['id'] ?? 0),
            'ai',
            null,
            $message,
            ['proactive_type' => 'post_workout_checkin']
        );
        $chatRepo->touchConversation((int) ($conversation['id'] ?? 0));
        return (int) $messageId;
    }

    private function buildClientFollowupPayload(array $followup, bool $isReady, ?array $summary = null): array {
        $userId = (int) ($followup['user_id'] ?? 0);
        $workoutDate = (string) ($followup['workout_date'] ?? '');
        $messageId = (int) ($followup['followup_message_id'] ?? 0);
        $summaryData = $summary !== null ? [
            'activity_type' => (string) ($summary['activity_type'] ?? ''),
            'activity_type_label' => $this->getActivityTypeRu((string) ($summary['activity_type'] ?? 'running')),
            'distance_km' => isset($summary['distance_km']) ? round((float) $summary['distance_km'], 1) : null,
            'duration_minutes' => isset($summary['duration_minutes']) ? (int) round((float) $summary['duration_minutes']) : null,
        ] : null;

        return [
            'id' => (int) ($followup['id'] ?? 0),
            'status' => (string) ($followup['status'] ?? ($isReady ? self::STATUS_SENT : self::STATUS_PENDING)),
            'is_ready' => $isReady,
            'due_at' => (string) ($followup['due_at'] ?? ''),
            'due_at_iso' => $this->formatDateTimeIso((string) ($followup['due_at'] ?? '')),
            'snoozed_until' => (string) ($followup['snoozed_until'] ?? ''),
            'snoozed_until_iso' => $this->formatDateTimeIso((string) ($followup['snoozed_until'] ?? '')),
            'is_snoozed' => !$isReady && trim((string) ($followup['snoozed_until'] ?? '')) !== '',
            'snooze_count' => (int) ($followup['snooze_count'] ?? 0),
            'sent_at' => (string) ($followup['sent_at'] ?? ''),
            'sent_at_iso' => $this->formatDateTimeIso((string) ($followup['sent_at'] ?? '')),
            'workout_date' => $workoutDate,
            'workout_date_label' => $this->formatDateRu($workoutDate),
            'source_kind' => (string) ($followup['source_kind'] ?? ''),
            'source_id' => (int) ($followup['source_id'] ?? 0),
            'message_id' => $messageId,
            'title' => 'Как прошла тренировка?',
            'subtitle' => $this->buildClientFollowupSubtitle($summaryData, $workoutDate),
            'prompt' => trim((string) ($followup['prompt'] ?? '')) !== ''
                ? trim((string) $followup['prompt'])
                : ($this->getFollowupMessageContent($messageId) ?: ($summary !== null ? $this->buildFollowupPrompt($userId, $summary) : 'Как ощущения после тренировки?')),
            'workout_summary' => $summaryData,
            'snooze_presets' => [
                ['id' => '30m', 'label' => 'Через 30 минут'],
                ['id' => 'evening', 'label' => 'Сегодня вечером'],
                ['id' => 'tomorrow_morning', 'label' => 'Завтра утром'],
            ],
        ];
    }

    private function buildClientFollowupSubtitle(?array $summary, string $workoutDate): string {
        $parts = [];

        if (is_array($summary)) {
            $activityLabel = trim((string) ($summary['activity_type_label'] ?? ''));
            if ($activityLabel !== '') {
                $parts[] = 'После ' . $activityLabel;
            }

            $distanceKm = $summary['distance_km'] ?? null;
            if ($distanceKm !== null && (float) $distanceKm > 0) {
                $parts[] = sprintf('%.1f км', (float) $distanceKm);
            }
        }

        if ($this->isValidDate($workoutDate)) {
            $parts[] = $this->formatDateRu($workoutDate);
        }

        return $parts !== [] ? implode(' · ', $parts) : 'Займёт меньше минуты и поможет точнее адаптировать план.';
    }

    private function getFollowupMessageContent(int $messageId): ?string {
        if ($messageId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT content FROM chat_messages WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $content = trim((string) ($row['content'] ?? ''));
        return $content !== '' ? $content : null;
    }

    private function getDayTypeRu(string $type): string {
        return match ($type) {
            'easy' => 'лёгкий бег',
            'long' => 'длительный бег',
            'tempo' => 'темповый бег',
            'interval' => 'интервалы',
            'fartlek' => 'фартлек',
            'control' => 'контрольный забег',
            'rest' => 'отдых',
            'other', 'ofp' => 'ОФП',
            'sbu' => 'СБУ',
            'race' => 'забег',
            'free' => 'свободный день',
            'walking' => 'ходьба',
            default => $type !== '' ? $type : 'тренировка',
        };
    }

    private function getActivityTypeRu(string $type): string {
        return match (mb_strtolower(trim($type))) {
            'running' => 'пробежки',
            'trail running' => 'трейловой тренировки',
            'treadmill' => 'тренировки на дорожке',
            'walking' => 'ходьбы',
            'hiking' => 'похода',
            'cycling' => 'велотренировки',
            'swimming' => 'плавания',
            default => 'тренировки',
        };
    }

    private function getDelayMinutes(): int {
        $value = (int) env('POST_WORKOUT_FOLLOWUP_DELAY_MINUTES', self::DEFAULT_DELAY_MINUTES);
        return $value > 0 ? $value : self::DEFAULT_DELAY_MINUTES;
    }

    private function getMaxAgeHours(): int {
        $value = (int) env('POST_WORKOUT_FOLLOWUP_MAX_AGE_HOURS', self::DEFAULT_MAX_AGE_HOURS);
        return $value > 0 ? $value : self::DEFAULT_MAX_AGE_HOURS;
    }

    private function getReplyWindowHours(): int {
        $value = (int) env('POST_WORKOUT_FOLLOWUP_REPLY_WINDOW_HOURS', self::DEFAULT_REPLY_WINDOW_HOURS);
        return $value > 0 ? $value : self::DEFAULT_REPLY_WINDOW_HOURS;
    }

    private function getSnoozeEveningHour(): int {
        $value = (int) env('POST_WORKOUT_FOLLOWUP_SNOOZE_EVENING_HOUR', self::DEFAULT_SNOOZE_EVENING_HOUR);
        return max(16, min(23, $value));
    }

    private function getSnoozeTomorrowHour(): int {
        $value = (int) env('POST_WORKOUT_FOLLOWUP_SNOOZE_TOMORROW_HOUR', self::DEFAULT_SNOOZE_TOMORROW_HOUR);
        return max(6, min(12, $value));
    }

    private function getUserTimezone(int $userId): DateTimeZone {
        try {
            return new DateTimeZone(getUserTimezone($userId));
        } catch (Throwable $e) {
            return new DateTimeZone('Europe/Moscow');
        }
    }

    private function formatDateRu(string $date): string {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt ? $dt->format('d.m.Y') : $date;
    }

    private function formatDateTimeIso(string $dateTime): ?string {
        $value = trim($dateTime);
        if ($value === '') {
            return null;
        }

        try {
            $dt = new DateTime($value, new DateTimeZone('UTC'));
            return $dt->format(DateTime::ATOM);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function isValidDate(string $date): bool {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    private function getFollowupByIdForUser(int $userId, int $followupId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, user_id, source_kind, source_id, workout_date, analysis_message_id, followup_message_id,
                    due_at, snoozed_until, snooze_count, status, sent_at
             FROM post_workout_followups
             WHERE id = ? AND user_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ii', $followupId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function resolveSnoozeUntil(int $userId, string $preset): ?DateTimeImmutable {
        $tz = $this->getUserTimezone($userId);
        $nowLocal = new DateTimeImmutable('now', $tz);

        return match (trim($preset)) {
            '30m' => $nowLocal->modify('+30 minutes'),
            'evening' => $this->resolveEveningSnoozeTime($nowLocal),
            'tomorrow_morning' => $nowLocal
                ->setTime($this->getSnoozeTomorrowHour(), 0)
                ->modify('+1 day'),
            default => null,
        };
    }

    private function resolveEveningSnoozeTime(DateTimeImmutable $nowLocal): DateTimeImmutable {
        $candidate = $nowLocal->setTime($this->getSnoozeEveningHour(), 0);
        if ($candidate <= $nowLocal) {
            return $candidate->modify('+1 day');
        }
        return $candidate;
    }

    private function ensureColumnExists(string $column, string $definition): void {
        $escapedColumn = $this->db->real_escape_string($column);
        $check = $this->db->query("SHOW COLUMNS FROM post_workout_followups LIKE '{$escapedColumn}'");
        if ($check instanceof mysqli_result) {
            $exists = $check->num_rows > 0;
            $check->free();
            if ($exists) {
                return;
            }
        }

        $sql = "ALTER TABLE post_workout_followups ADD COLUMN {$definition}";
        if (!$this->db->query($sql)) {
            $this->throwException('Не удалось обновить схему post_workout_followups', 500, [
                'column' => $column,
                'error' => $this->db->error,
            ]);
        }
    }

    private function hydrateFeedbackAnalyticsRow(array $row): array {
        $responseContent = trim((string) ($row['response_content'] ?? ''));
        $derived = $responseContent !== '' ? $this->analyzeFeedback($responseContent) : [
            'classification' => 'neutral',
            'pain_flag' => false,
            'fatigue_flag' => false,
            'recovery_risk_score' => 0.25,
        ];

        $storedClassification = trim((string) ($row['classification'] ?? ''));
        $storedPainFlag = isset($row['pain_flag']) ? ((int) $row['pain_flag'] === 1) : false;
        $storedFatigueFlag = isset($row['fatigue_flag']) ? ((int) $row['fatigue_flag'] === 1) : false;
        $storedSessionRpe = $this->nullableInt($row['session_rpe'] ?? null);
        $storedLegsScore = $this->nullableInt($row['legs_score'] ?? null);
        $storedBreathScore = $this->nullableInt($row['breath_score'] ?? null);
        $storedHrStrainScore = $this->nullableInt($row['hr_strain_score'] ?? null);
        $storedPainScore = $this->nullableInt($row['pain_score'] ?? null);
        $storedRiskScore = isset($row['recovery_risk_score']) ? (float) $row['recovery_risk_score'] : 0.0;
        $hasStoredAnalytics = $storedClassification !== ''
            || $storedPainFlag
            || $storedFatigueFlag
            || $storedSessionRpe !== null
            || $storedLegsScore !== null
            || $storedBreathScore !== null
            || $storedHrStrainScore !== null
            || $storedPainScore !== null
            || $storedRiskScore > 0.0;

        return [
            'workout_date' => (string) ($row['workout_date'] ?? ''),
            'classification' => $hasStoredAnalytics ? ($storedClassification !== '' ? $storedClassification : 'neutral') : (string) $derived['classification'],
            'pain_flag' => $hasStoredAnalytics ? $storedPainFlag : (bool) $derived['pain_flag'],
            'fatigue_flag' => $hasStoredAnalytics ? $storedFatigueFlag : (bool) $derived['fatigue_flag'],
            'session_rpe' => $hasStoredAnalytics ? $storedSessionRpe : $this->nullableInt($derived['session_rpe'] ?? null),
            'legs_score' => $hasStoredAnalytics ? $storedLegsScore : $this->nullableInt($derived['legs_score'] ?? null),
            'breath_score' => $hasStoredAnalytics ? $storedBreathScore : $this->nullableInt($derived['breath_score'] ?? null),
            'hr_strain_score' => $hasStoredAnalytics ? $storedHrStrainScore : $this->nullableInt($derived['hr_strain_score'] ?? null),
            'pain_score' => $hasStoredAnalytics ? $storedPainScore : $this->nullableInt($derived['pain_score'] ?? null),
            'recovery_risk_score' => round(max(0.0, min(1.0, $hasStoredAnalytics ? $storedRiskScore : (float) $derived['recovery_risk_score'])), 2),
            'response_content' => $responseContent,
        ];
    }

    private function buildFeedbackAnalyticsSummary(array $rows, string $startDate, string $endDate): array {
        $summary = $this->buildEmptyFeedbackAnalytics($startDate, $endDate);
        if (empty($rows)) {
            return $summary;
        }

        $riskScores = [];
        $sessionRpeScores = [];
        $legsScores = [];
        $breathScores = [];
        $hrStrainScores = [];
        $painScores = [];
        foreach ($rows as $index => $row) {
            $classification = (string) ($row['classification'] ?? 'neutral');
            if (isset($summary[$classification . '_count'])) {
                $summary[$classification . '_count']++;
            }

            if (!empty($row['pain_flag'])) {
                $summary['pain_flag_count']++;
            }
            if (!empty($row['fatigue_flag'])) {
                $summary['fatigue_flag_count']++;
            }

            $risk = round((float) ($row['recovery_risk_score'] ?? 0.0), 2);
            $riskScores[] = $risk;
            $summary['max_recovery_risk'] = max($summary['max_recovery_risk'], $risk);
            if (($value = $this->nullableInt($row['session_rpe'] ?? null)) !== null) {
                $sessionRpeScores[] = $value;
            }
            if (($value = $this->nullableInt($row['legs_score'] ?? null)) !== null) {
                $legsScores[] = $value;
            }
            if (($value = $this->nullableInt($row['breath_score'] ?? null)) !== null) {
                $breathScores[] = $value;
            }
            if (($value = $this->nullableInt($row['hr_strain_score'] ?? null)) !== null) {
                $hrStrainScores[] = $value;
            }
            if (($value = $this->nullableInt($row['pain_score'] ?? null)) !== null) {
                $painScores[] = $value;
            }

            if ($index === 0) {
                $summary['latest_classification'] = $classification;
                $summary['latest_recovery_risk'] = $risk;
                $summary['latest_workout_date'] = (string) ($row['workout_date'] ?? '');
            }
        }

        $summary['total_responses'] = count($rows);
        $summary['has_recent_pain'] = $summary['pain_flag_count'] > 0;
        $summary['has_recent_fatigue'] = $summary['fatigue_flag_count'] > 0;
        $summary['average_recovery_risk'] = round(array_sum($riskScores) / count($riskScores), 2);
        $summary['recent_average_recovery_risk'] = round(array_sum(array_slice($riskScores, 0, 3)) / min(3, count($riskScores)), 2);
        $summary = array_merge($summary, $this->buildStructuredMetricSummary('session_rpe', $sessionRpeScores, 3));
        $summary = array_merge($summary, $this->buildStructuredMetricSummary('legs_score', $legsScores, 3));
        $summary = array_merge($summary, $this->buildStructuredMetricSummary('breath_score', $breathScores, 3));
        $summary = array_merge($summary, $this->buildStructuredMetricSummary('hr_strain_score', $hrStrainScores, 3));
        $summary = array_merge($summary, $this->buildStructuredMetricSummary('pain_score', $painScores, 3));
        $summary['subjective_load_delta'] = round(max(0.0, (
            (float) ($summary['session_rpe_delta'] ?? 0.0) / 2.5
            + ((float) ($summary['legs_score_delta'] ?? 0.0) / 2.0)
            + ((float) ($summary['breath_score_delta'] ?? 0.0) / 2.0)
            + ((float) ($summary['hr_strain_score_delta'] ?? 0.0) / 2.0)
        ) / 4), 2);
        $summary['risk_level'] = $this->resolveRiskLevel($summary);

        return $summary;
    }

    private function buildEmptyFeedbackAnalytics(string $startDate, string $endDate): array {
        $windowDays = 0;
        if ($this->isValidDate($startDate) && $this->isValidDate($endDate) && $startDate <= $endDate) {
            try {
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);
                $windowDays = max(1, (int) $start->diff($end)->days + 1);
            } catch (Throwable $e) {
                $windowDays = 0;
            }
        }

        return [
            'window_start' => $startDate,
            'window_end' => $endDate,
            'window_days' => $windowDays,
            'total_responses' => 0,
            'good_count' => 0,
            'neutral_count' => 0,
            'fatigue_count' => 0,
            'pain_count' => 0,
            'pain_flag_count' => 0,
            'fatigue_flag_count' => 0,
            'average_recovery_risk' => 0.0,
            'recent_average_recovery_risk' => 0.0,
            'max_recovery_risk' => 0.0,
            'recent_session_rpe_avg' => 0.0,
            'baseline_session_rpe_avg' => 0.0,
            'session_rpe_delta' => 0.0,
            'recent_legs_score_avg' => 0.0,
            'baseline_legs_score_avg' => 0.0,
            'legs_score_delta' => 0.0,
            'recent_breath_score_avg' => 0.0,
            'baseline_breath_score_avg' => 0.0,
            'breath_score_delta' => 0.0,
            'recent_hr_strain_score_avg' => 0.0,
            'baseline_hr_strain_score_avg' => 0.0,
            'hr_strain_score_delta' => 0.0,
            'recent_pain_score_avg' => 0.0,
            'baseline_pain_score_avg' => 0.0,
            'pain_score_delta' => 0.0,
            'subjective_load_delta' => 0.0,
            'latest_classification' => null,
            'latest_recovery_risk' => 0.0,
            'latest_workout_date' => null,
            'has_recent_pain' => false,
            'has_recent_fatigue' => false,
            'risk_level' => 'low',
        ];
    }

    private function resolveRiskLevel(array $summary): string {
        if (
            !empty($summary['has_recent_pain'])
            || (float) ($summary['recent_average_recovery_risk'] ?? 0.0) >= 0.75
            || (float) ($summary['recent_pain_score_avg'] ?? 0.0) >= 4.0
            || (float) ($summary['pain_score_delta'] ?? 0.0) >= 2.0
        ) {
            return 'high';
        }

        if (
            (int) ($summary['fatigue_flag_count'] ?? 0) >= 2
            || (float) ($summary['recent_average_recovery_risk'] ?? 0.0) >= 0.45
            || (float) ($summary['subjective_load_delta'] ?? 0.0) >= 0.75
            || (float) ($summary['session_rpe_delta'] ?? 0.0) >= 1.0
        ) {
            return 'moderate';
        }

        return 'low';
    }

    private function resolveSessionRpe(string $normalized, string $classification): ?int {
        $parsed = $this->extractStructuredScore($normalized, ['тяжесть', 'rpe', 'нагрузка', 'общая'], 10, 1);
        if ($parsed !== null) {
            return $parsed;
        }

        return match ($classification) {
            'good' => 4,
            'fatigue' => preg_match('/очень|еле|на\s+пределе/u', $normalized) ? 9 : 8,
            'pain' => 7,
            default => 6,
        };
    }

    private function resolveTenPointScore(string $normalized, array $labels, string $classification): ?int {
        $parsed = $this->extractStructuredScore($normalized, $labels, 10, 1);
        if ($parsed !== null) {
            return $parsed;
        }

        $hasMild = (bool) preg_match('/немного|слегка|чуть|терпимо/u', $normalized);
        $hasSevere = (bool) preg_match('/очень|сильно|еле|на\s+пределе/u', $normalized);

        return match ($classification) {
            'good' => 4,
            'fatigue' => $hasSevere ? 10 : ($hasMild ? 6 : 8),
            'pain' => 6,
            default => 6,
        };
    }

    private function resolvePainScore(string $normalized, string $classification, bool $painFlag): ?int {
        $parsed = $this->extractStructuredScore($normalized, ['боль', 'pain'], 10, 0);
        if ($parsed !== null) {
            return $parsed;
        }

        if ($painFlag) {
            if (preg_match('/сильн|остр|прострел|не\s+могу|хром/u', $normalized)) {
                return 8;
            }
            if (preg_match('/немного|слегка|чуть/u', $normalized)) {
                return 3;
            }
            return 6;
        }

        return $classification === 'good' ? 0 : 1;
    }

    private function extractStructuredScore(string $normalized, array $labels, int $targetScale, int $minValue): ?int {
        foreach ($labels as $label) {
            $pattern = '/(?:^|[,\.;\s])' . preg_quote($label, '/') . '\s*[:=\-]?\s*(\d{1,2})(?:\s*\/\s*(\d{1,2}))?/u';
            if (preg_match($pattern, $normalized, $matches) === 1) {
                $raw = (int) ($matches[1] ?? 0);
                $scale = isset($matches[2]) ? (int) $matches[2] : $targetScale;
                return $this->normalizeStructuredScore($raw, $scale, $targetScale, $minValue);
            }
        }

        return null;
    }

    private function normalizeStructuredScore(int $raw, int $sourceScale, int $targetScale, int $minValue): int {
        if ($sourceScale <= 0) {
            $sourceScale = $targetScale;
        }
        $raw = max($minValue, min($sourceScale, $raw));
        if ($sourceScale === $targetScale) {
            return $raw;
        }

        $normalized = (int) round(($raw / $sourceScale) * $targetScale);
        return max($minValue, min($targetScale, $normalized));
    }

    private function buildStructuredMetricSummary(string $prefix, array $values, int $recentWindow): array {
        if (empty($values)) {
            return [
                'recent_' . $prefix . '_avg' => 0.0,
                'baseline_' . $prefix . '_avg' => 0.0,
                $prefix . '_delta' => 0.0,
            ];
        }

        $recent = array_slice($values, 0, $recentWindow);
        $baselinePool = count($values) > $recentWindow ? array_slice($values, $recentWindow) : $values;
        $recentAvg = round(array_sum($recent) / count($recent), 2);
        $baselineAvg = round(array_sum($baselinePool) / count($baselinePool), 2);

        return [
            'recent_' . $prefix . '_avg' => $recentAvg,
            'baseline_' . $prefix . '_avg' => $baselineAvg,
            $prefix . '_delta' => round($recentAvg - $baselineAvg, 2),
        ];
    }

    private function nullableInt(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
