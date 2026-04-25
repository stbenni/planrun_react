<?php
/**
 * Очередь задач генерации плана.
 */

require_once __DIR__ . '/BaseService.php';

class PlanGenerationQueueService extends BaseService {
    private const TABLE = 'plan_generation_jobs';
    private const STATUS_PENDING = 'pending';
    private const STATUS_RUNNING = 'running';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';

    public function enqueue(int $userId, string $jobType = 'generate', array $payload = [], int $maxAttempts = 3): array {
        $this->assertQueueTableAvailable();

        $jobType = trim($jobType) !== '' ? trim($jobType) : 'generate';
        $existing = $this->findActiveJobForUser($userId);
        if ($existing) {
            return [
                'job_id' => (int) $existing['id'],
                'queued' => true,
                'deduplicated' => true,
                'status' => $existing['status'],
                'job_type' => $existing['job_type'] ?? null,
            ];
        }

        $payloadJson = !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (user_id, job_type, status, payload_json, max_attempts) VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось поставить задачу в очередь', 500);
        }

        $status = self::STATUS_PENDING;
        $stmt->bind_param('isssi', $userId, $jobType, $status, $payloadJson, $maxAttempts);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось сохранить задачу генерации плана', 500);
        }

        $jobId = (int) $this->db->insert_id;
        $stmt->close();

        $this->logInfo('Plan generation job enqueued', [
            'job_id' => $jobId,
            'user_id' => $userId,
            'job_type' => $jobType,
        ]);

        return [
            'job_id' => $jobId,
            'queued' => true,
            'deduplicated' => false,
            'status' => self::STATUS_PENDING,
        ];
    }

    public function reserveNextJob(): ?array {
        $this->assertQueueTableAvailable();
        $this->recoverStaleRunningJobs();

        $result = $this->db->query(
            "SELECT * FROM " . self::TABLE . " WHERE status = '" . self::STATUS_PENDING . "' AND available_at <= NOW() ORDER BY id ASC LIMIT 1"
        );
        if (!$result) {
            throw new RuntimeException('Не удалось прочитать очередь задач', 500);
        }

        $job = $result->fetch_assoc();
        if (!$job) {
            return null;
        }

        $update = $this->db->prepare(
            'UPDATE ' . self::TABLE . ' SET status = ?, started_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ? AND status = ?'
        );
        if (!$update) {
            throw new RuntimeException('Не удалось зарезервировать задачу', 500);
        }

        $running = self::STATUS_RUNNING;
        $pending = self::STATUS_PENDING;
        $jobId = (int) $job['id'];
        $update->bind_param('sis', $running, $jobId, $pending);
        $update->execute();
        $affected = $update->affected_rows;
        $update->close();

        if ($affected < 1) {
            return null;
        }

        $job['status'] = self::STATUS_RUNNING;
        $job['attempts'] = ((int) $job['attempts']) + 1;
        return $job;
    }

    public function recoverStaleRunningJobs(?int $timeoutSeconds = null): array {
        $this->assertQueueTableAvailable();

        $timeoutSeconds = $timeoutSeconds ?? (int) env('PLAN_GENERATION_RUNNING_TIMEOUT_SECONDS', 1800);
        $timeoutSeconds = max(60, $timeoutSeconds);
        $cutoff = date('Y-m-d H:i:s', time() - $timeoutSeconds);

        $requeueMessage = 'Задача возвращена в очередь после таймаута выполнения';
        $failedMessage = 'Задача остановлена после таймаута выполнения';

        $pending = self::STATUS_PENDING;
        $running = self::STATUS_RUNNING;
        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . ' SET status = ?, available_at = NOW(), started_at = NULL, finished_at = NULL, last_error = ? ' .
            'WHERE status = ? AND started_at IS NOT NULL AND started_at < ? AND attempts < max_attempts'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось восстановить зависшие задачи очереди', 500);
        }
        $stmt->bind_param('ssss', $pending, $requeueMessage, $running, $cutoff);
        $stmt->execute();
        $requeued = max(0, (int) $stmt->affected_rows);
        $stmt->close();

        $failed = self::STATUS_FAILED;
        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . ' SET status = ?, finished_at = NOW(), last_error = ? ' .
            'WHERE status = ? AND started_at IS NOT NULL AND started_at < ? AND attempts >= max_attempts'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось закрыть зависшие задачи очереди', 500);
        }
        $stmt->bind_param('ssss', $failed, $failedMessage, $running, $cutoff);
        $stmt->execute();
        $failedCount = max(0, (int) $stmt->affected_rows);
        $stmt->close();

        if ($requeued > 0 || $failedCount > 0) {
            $this->logInfo('Recovered stale plan generation jobs', [
                'requeued' => $requeued,
                'failed' => $failedCount,
                'timeout_seconds' => $timeoutSeconds,
            ]);
        }

        return [
            'requeued' => $requeued,
            'failed' => $failedCount,
        ];
    }

    public function markCompleted(int $jobId, array $result = []): void {
        $resultJson = !empty($result) ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $this->db->prepare(
            'UPDATE ' . self::TABLE . ' SET status = ?, result_json = ?, finished_at = NOW(), last_error = NULL WHERE id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось отметить задачу завершённой', 500);
        }

        $status = self::STATUS_COMPLETED;
        $stmt->bind_param('ssi', $status, $resultJson, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function markFailed(int $jobId, string $errorMessage, int $attempts, int $maxAttempts, int $retryDelaySeconds = 300): void {
        $shouldRetry = $attempts < $maxAttempts;
        $nextStatus = $shouldRetry ? self::STATUS_PENDING : self::STATUS_FAILED;
        $sql = $shouldRetry
            ? 'UPDATE ' . self::TABLE . ' SET status = ?, last_error = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), finished_at = NULL WHERE id = ?'
            : 'UPDATE ' . self::TABLE . ' SET status = ?, last_error = ?, finished_at = NOW() WHERE id = ?';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Не удалось обновить статус ошибки задачи', 500);
        }

        if ($shouldRetry) {
            $stmt->bind_param('ssii', $nextStatus, $errorMessage, $retryDelaySeconds, $jobId);
        } else {
            $stmt->bind_param('ssi', $nextStatus, $errorMessage, $jobId);
        }
        $stmt->execute();
        $stmt->close();
    }

    public function getJobById(int $jobId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Не удалось загрузить задачу очереди', 500);
        }
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $job;
    }

    public function findLatestActiveJobForUser(int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось загрузить активную задачу очереди', 500);
        }

        $pending = self::STATUS_PENDING;
        $running = self::STATUS_RUNNING;
        $stmt->bind_param('iss', $userId, $pending, $running);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $job;
    }

    public function findLatestJobForUser(int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось загрузить последнюю задачу очереди', 500);
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $job;
    }

    public function isQueueAvailable(): bool {
        $check = @$this->db->query("SHOW TABLES LIKE '" . self::TABLE . "'");
        return (bool) ($check && $check->num_rows > 0);
    }

    private function findActiveJobForUser(int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, status, job_type FROM ' . self::TABLE . ' WHERE user_id = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось проверить очередь задач', 500);
        }

        $pending = self::STATUS_PENDING;
        $running = self::STATUS_RUNNING;
        $stmt->bind_param('iss', $userId, $pending, $running);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $job;
    }

    private function assertQueueTableAvailable(): void {
        if (!$this->isQueueAvailable()) {
            throw new RuntimeException(
                'Очередь генерации плана недоступна. Администратору нужно выполнить php scripts/migrate_all.php',
                503
            );
        }
    }
}
