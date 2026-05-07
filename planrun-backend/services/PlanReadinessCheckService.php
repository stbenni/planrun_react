<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/PostWorkoutFollowupService.php';

class PlanReadinessCheckService extends BaseService {
    private const TABLE = 'plan_readiness_checkins';
    private const STATUS_PENDING = 'pending';
    private const STATUS_ANSWERED = 'answered';
    private const STATUS_DISMISSED = 'dismissed';
    private const CHECK_STALE_PAIN = 'stale_pain_signal';

    public function ensureSchema(): void {
        $sql = "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            check_type VARCHAR(50) NOT NULL DEFAULT 'stale_pain_signal',
            job_type VARCHAR(32) NULL DEFAULT NULL,
            payload_json TEXT NULL DEFAULT NULL,
            source_date DATE NULL DEFAULT NULL,
            source_summary TEXT NULL DEFAULT NULL,
            source_pain_score TINYINT UNSIGNED NULL DEFAULT NULL,
            subsequent_run_count INT NOT NULL DEFAULT 0,
            question TEXT NOT NULL,
            answer_text TEXT NULL DEFAULT NULL,
            current_pain_score TINYINT UNSIGNED NULL DEFAULT NULL,
            pain_worsened_after_runs TINYINT(1) NULL DEFAULT NULL,
            technique_changed TINYINT(1) NULL DEFAULT NULL,
            interpretation VARCHAR(32) NULL DEFAULT NULL,
            valid_until DATE NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            answered_at DATETIME NULL DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_plan_readiness_user_status (user_id, status),
            INDEX idx_plan_readiness_user_source (user_id, check_type, source_date),
            INDEX idx_plan_readiness_valid (user_id, status, valid_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->db->query($sql)) {
            throw new RuntimeException('Не удалось подготовить readiness-check таблицу', 500);
        }
    }

    public function maybeCreatePendingCheck(int $userId, string $jobType = 'recalculate', array $payload = []): ?array {
        if ($userId <= 0) {
            return null;
        }

        $this->ensureSchema();
        $this->dismissExpiredPendingChecks($userId);

        $painSignal = $this->findLatestStalePainSignal($userId);
        if ($painSignal === null) {
            return null;
        }

        if ($this->hasValidAnswerForSource($userId, (string) $painSignal['workout_date'])) {
            return null;
        }

        $existing = $this->findPendingCheckForSource($userId, (string) $painSignal['workout_date']);
        if ($existing !== null) {
            return $this->toPublicCheckPayload($existing);
        }

        $sourceDate = (string) $painSignal['workout_date'];
        $painScore = $this->nullableInt($painSignal['pain_score'] ?? null);
        $summary = $this->buildPainSignalSummary($painSignal);
        $question = 'Как сейчас состояние после болевого сигнала от ' . $this->formatDateRu($sourceDate) .
            ': боль/дискомфорт 0-10, усиливалась ли боль после последних пробежек и менялась ли техника бега?';
        $payloadJson = $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $checkType = self::CHECK_STALE_PAIN;
        $status = self::STATUS_PENDING;
        $subsequentRunCount = (int) ($painSignal['subsequent_run_count'] ?? 0);

        $stmt = $this->db->prepare(
            "INSERT INTO " . self::TABLE . "
                (user_id, status, check_type, job_type, payload_json, source_date, source_summary, source_pain_score, subsequent_run_count, question)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось создать readiness-check', 500);
        }
        $stmt->bind_param(
            'issssssiis',
            $userId,
            $status,
            $checkType,
            $jobType,
            $payloadJson,
            $sourceDate,
            $summary,
            $painScore,
            $subsequentRunCount,
            $question
        );
        $stmt->execute();
        $checkId = (int) $this->db->insert_id;
        $stmt->close();

        $check = $this->findCheckById($userId, $checkId);
        return $check !== null ? $this->toPublicCheckPayload($check) : null;
    }

    public function submitAnswer(int $userId, int $checkId, array $answer): array {
        if ($userId <= 0 || $checkId <= 0) {
            $this->throwException('Некорректный readiness-check', 400);
        }

        $this->ensureSchema();
        $check = $this->findCheckById($userId, $checkId);
        if ($check === null) {
            $this->throwException('Readiness-check не найден', 404);
        }

        $answerText = trim(mb_substr((string) ($answer['answer_text'] ?? $answer['text'] ?? ''), 0, 1000));
        $painScore = $this->resolvePainScore($answer['current_pain_score'] ?? $answer['pain_score'] ?? null, $answerText);
        if ($painScore === null) {
            $this->throwException('Укажите боль или дискомфорт по шкале 0-10', 422);
        }

        $worsened = $this->normalizeBool($answer['pain_worsened_after_runs'] ?? $answer['worsened_after_runs'] ?? null);
        $techniqueChanged = $this->normalizeBool($answer['technique_changed'] ?? null);
        if ($worsened === null || $techniqueChanged === null) {
            $this->throwException('Укажите, усиливалась ли боль и менялась ли техника бега', 422);
        }

        $interpretation = $this->interpretAnswer($painScore, $worsened, $techniqueChanged);
        $validDays = in_array($interpretation, ['clear', 'mild_clear'], true) ? 10 : 5;
        $validUntil = gmdate('Y-m-d', strtotime("+{$validDays} days"));
        $status = self::STATUS_ANSWERED;

        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . "
             SET status = ?, answer_text = ?, current_pain_score = ?, pain_worsened_after_runs = ?,
                 technique_changed = ?, interpretation = ?, valid_until = ?, answered_at = NOW()
             WHERE id = ? AND user_id = ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось сохранить readiness-check ответ', 500);
        }
        $worsenedInt = $worsened ? 1 : 0;
        $techniqueInt = $techniqueChanged ? 1 : 0;
        $stmt->bind_param(
            'ssiiissii',
            $status,
            $answerText,
            $painScore,
            $worsenedInt,
            $techniqueInt,
            $interpretation,
            $validUntil,
            $checkId,
            $userId
        );
        $stmt->execute();
        $stmt->close();

        $updated = $this->findCheckById($userId, $checkId);
        return [
            'saved' => true,
            'interpretation' => $interpretation,
            'can_generate_more_effective_plan' => in_array($interpretation, ['clear', 'mild_clear'], true),
            'readiness_check' => $updated !== null ? $this->toPublicCheckPayload($updated) : null,
        ];
    }

    public function getLatestValidAnswer(int $userId): ?array {
        if ($userId <= 0) {
            return null;
        }

        $this->ensureSchema();
        $status = self::STATUS_ANSWERED;
        $stmt = $this->db->prepare(
            "SELECT *
             FROM " . self::TABLE . "
             WHERE user_id = ?
               AND status = ?
               AND (valid_until IS NULL OR valid_until >= CURDATE())
             ORDER BY answered_at DESC, id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $userId, $status);
        $stmt->execute();
        $answer = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($answer === null) {
            return null;
        }

        $latestPain = $this->findLatestPainSignal($userId, 21);
        if ($latestPain !== null && !empty($answer['source_date']) && (string) $latestPain['workout_date'] > (string) $answer['source_date']) {
            return null;
        }

        return $answer;
    }

    private function findLatestStalePainSignal(int $userId): ?array {
        $pain = $this->findLatestPainSignal($userId, $this->envInt('PLAN_READINESS_CHECK_PAIN_LOOKBACK_DAYS', 21, 7, 60));
        if ($pain === null || empty($pain['workout_date'])) {
            return null;
        }

        $sourceDate = (string) $pain['workout_date'];
        $ageDays = $this->daysSince($sourceDate);
        if ($ageDays === null || $ageDays < $this->envInt('PLAN_READINESS_CHECK_MIN_PAIN_AGE_DAYS', 7, 1, 30)) {
            return null;
        }

        $subsequentRunCount = $this->countRunsAfterDate($userId, $sourceDate);
        if ($subsequentRunCount < 1) {
            return null;
        }

        $pain['days_since_signal'] = $ageDays;
        $pain['subsequent_run_count'] = $subsequentRunCount;
        return $pain;
    }

    private function findLatestPainSignal(int $userId, int $lookbackDays): ?array {
        (new PostWorkoutFollowupService($this->db))->ensureSchema();

        $stmt = $this->db->prepare(
            "SELECT f.id, f.workout_date, f.responded_at, f.classification, f.pain_score,
                    f.recovery_risk_score, cm.content AS response_content
             FROM post_workout_followups f
             LEFT JOIN chat_messages cm ON cm.id = f.response_message_id
             WHERE f.user_id = ?
               AND f.status = 'completed'
               AND f.pain_flag = 1
               AND f.workout_date BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND CURDATE()
             ORDER BY f.workout_date DESC, f.responded_at DESC, f.id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $userId, $lookbackDays);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function countRunsAfterDate(int $userId, string $sourceDate): int {
        $count = 0;

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c
             FROM workouts
             WHERE user_id = ?
               AND activity_type = 'running'
               AND DATE(start_time) > ?
               AND DATE(start_time) <= CURDATE()
               AND COALESCE(distance_km, 0) > 0"
        );
        if ($stmt) {
            $stmt->bind_param('is', $userId, $sourceDate);
            if ($stmt->execute()) {
                $count += (int) (($stmt->get_result()->fetch_assoc()['c'] ?? 0));
            }
            $stmt->close();
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c
             FROM workout_log
             WHERE user_id = ?
               AND training_date > ?
               AND training_date <= CURDATE()
               AND is_completed = 1
               AND COALESCE(distance_km, 0) > 0"
        );
        if ($stmt) {
            $stmt->bind_param('is', $userId, $sourceDate);
            if ($stmt->execute()) {
                $count += (int) (($stmt->get_result()->fetch_assoc()['c'] ?? 0));
            }
            $stmt->close();
        }

        return $count;
    }

    private function hasValidAnswerForSource(int $userId, string $sourceDate): bool {
        $status = self::STATUS_ANSWERED;
        $checkType = self::CHECK_STALE_PAIN;
        $stmt = $this->db->prepare(
            "SELECT id
             FROM " . self::TABLE . "
             WHERE user_id = ?
               AND check_type = ?
               AND status = ?
               AND source_date >= ?
               AND (valid_until IS NULL OR valid_until >= CURDATE())
             ORDER BY answered_at DESC, id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isss', $userId, $checkType, $status, $sourceDate);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function findPendingCheckForSource(int $userId, string $sourceDate): ?array {
        $status = self::STATUS_PENDING;
        $checkType = self::CHECK_STALE_PAIN;
        $stmt = $this->db->prepare(
            "SELECT *
             FROM " . self::TABLE . "
             WHERE user_id = ?
               AND check_type = ?
               AND status = ?
               AND source_date = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
             ORDER BY id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('isss', $userId, $checkType, $status, $sourceDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function findCheckById(int $userId, int $checkId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM " . self::TABLE . " WHERE id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $checkId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function dismissExpiredPendingChecks(int $userId): void {
        $pending = self::STATUS_PENDING;
        $dismissed = self::STATUS_DISMISSED;
        $stmt = $this->db->prepare(
            "UPDATE " . self::TABLE . "
             SET status = ?
             WHERE user_id = ?
               AND status = ?
               AND created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('sis', $dismissed, $userId, $pending);
        $stmt->execute();
        $stmt->close();
    }

    private function toPublicCheckPayload(array $check): array {
        $sourceDate = (string) ($check['source_date'] ?? '');
        return [
            'id' => (int) ($check['id'] ?? 0),
            'status' => (string) ($check['status'] ?? self::STATUS_PENDING),
            'check_type' => (string) ($check['check_type'] ?? self::CHECK_STALE_PAIN),
            'job_type' => $check['job_type'] ?? null,
            'question' => (string) ($check['question'] ?? ''),
            'source' => [
                'date' => $sourceDate,
                'date_label' => $sourceDate !== '' ? $this->formatDateRu($sourceDate) : null,
                'days_ago' => $sourceDate !== '' ? $this->daysSince($sourceDate) : null,
                'summary' => $check['source_summary'] ?? null,
                'pain_score' => $this->nullableInt($check['source_pain_score'] ?? null),
                'subsequent_run_count' => (int) ($check['subsequent_run_count'] ?? 0),
            ],
            'answer' => [
                'current_pain_score' => $this->nullableInt($check['current_pain_score'] ?? null),
                'pain_worsened_after_runs' => isset($check['pain_worsened_after_runs']) ? ((int) $check['pain_worsened_after_runs'] === 1) : null,
                'technique_changed' => isset($check['technique_changed']) ? ((int) $check['technique_changed'] === 1) : null,
                'interpretation' => $check['interpretation'] ?? null,
                'valid_until' => $check['valid_until'] ?? null,
            ],
        ];
    }

    private function buildPainSignalSummary(array $painSignal): string {
        $parts = [];
        $painScore = $this->nullableInt($painSignal['pain_score'] ?? null);
        if ($painScore !== null) {
            $parts[] = "боль {$painScore}/10";
        }
        $risk = isset($painSignal['recovery_risk_score']) ? (float) $painSignal['recovery_risk_score'] : 0.0;
        if ($risk > 0.0) {
            $parts[] = 'риск восстановления ' . round($risk, 2);
        }
        $content = trim((string) ($painSignal['response_content'] ?? ''));
        if ($content !== '') {
            $parts[] = mb_substr(str_replace(["\r", "\n"], ' ', $content), 0, 180);
        }
        return implode('; ', $parts);
    }

    private function resolvePainScore($raw, string $answerText): ?int {
        if (is_numeric($raw)) {
            return max(0, min(10, (int) $raw));
        }
        if ($answerText !== '' && preg_match('/(?:боль|дискомфорт|pain)\D*(10|[0-9])/ui', $answerText, $matches)) {
            return max(0, min(10, (int) $matches[1]));
        }
        if ($answerText !== '' && preg_match('/\b(10|[0-9])\s*\/\s*10\b/u', $answerText, $matches)) {
            return max(0, min(10, (int) $matches[1]));
        }
        return null;
    }

    private function interpretAnswer(int $painScore, bool $worsened, bool $techniqueChanged): string {
        if ($painScore <= 1 && !$worsened && !$techniqueChanged) {
            return 'clear';
        }
        if ($painScore <= 2 && !$worsened && !$techniqueChanged) {
            return 'mild_clear';
        }
        return 'protective';
    }

    private function normalizeBool($value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'да', 'y'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'нет', 'n'], true)) {
            return false;
        }
        return null;
    }

    private function nullableInt($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function daysSince(string $date): ?int {
        try {
            $source = new DateTimeImmutable($date);
            $today = new DateTimeImmutable(gmdate('Y-m-d'));
            return max(0, (int) $source->diff($today)->format('%r%a'));
        } catch (Throwable $e) {
            return null;
        }
    }

    private function formatDateRu(string $date): string {
        try {
            return (new DateTimeImmutable($date))->format('d.m.Y');
        } catch (Throwable $e) {
            return $date;
        }
    }

    private function envInt(string $key, int $default, int $min, int $max): int {
        $value = filter_var(env($key, $default), FILTER_VALIDATE_INT);
        if ($value === false) {
            $value = $default;
        }
        return max($min, min($max, (int) $value));
    }
}
