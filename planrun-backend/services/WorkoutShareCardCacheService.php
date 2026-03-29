<?php
/**
 * Кэш и очередь фоновой генерации карточек шаринга тренировок.
 */

require_once __DIR__ . '/BaseService.php';

class WorkoutShareCardCacheService extends BaseService {
    public const JOBS_TABLE = 'workout_share_jobs';
    public const CARDS_TABLE = 'workout_share_cards';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const KIND_WORKOUT = 'workout';
    public const KIND_MANUAL = 'manual';

    public const TEMPLATE_ROUTE = 'route';
    public const TEMPLATE_MINIMAL = 'minimal';
    public const RENDERER_VERSION = 'v3-playwright';
    public const RENDERER_VERSION_CLIENT = 'v4-client-html2canvas';
    private const MIN_VALID_IMAGE_WIDTH = 200;
    private const MIN_VALID_IMAGE_HEIGHT = 200;

    private array $tableExistsCache = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function refreshQueuedCardsForWorkout(int $userId, int $workoutId, string $workoutKind = self::KIND_WORKOUT): array {
        if ($userId <= 0 || $workoutId <= 0 || !$this->isInfrastructureAvailable()) {
            return [];
        }

        $workoutKind = $this->normalizeWorkoutKind($workoutKind);
        $templates = $this->detectTemplatesForWorkout($userId, $workoutId, $workoutKind);
        if (empty($templates)) {
            $this->deleteWorkoutAssets($userId, $workoutId, $workoutKind);
            return [];
        }

        $this->deleteWorkoutAssets($userId, $workoutId, $workoutKind);
        $results = [];
        foreach ($templates as $template) {
            $results[] = $this->enqueue($userId, $workoutId, $workoutKind, $template);
        }

        return $results;
    }

    public function enqueue(int $userId, int $workoutId, string $workoutKind, string $template, int $maxAttempts = 2): array {
        $this->assertInfrastructureAvailable();

        $workoutKind = $this->normalizeWorkoutKind($workoutKind);
        $template = $this->normalizeTemplate($template);

        $existing = $this->findActiveJob($userId, $workoutId, $workoutKind, $template);
        if ($existing) {
            return [
                'job_id' => (int) $existing['id'],
                'queued' => true,
                'deduplicated' => true,
                'status' => $existing['status'],
            ];
        }

        $status = self::STATUS_PENDING;
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::JOBS_TABLE . ' (user_id, workout_kind, workout_id, template, status, max_attempts) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось поставить задачу рендера шаринга в очередь', 500);
        }

        $stmt->bind_param('isissi', $userId, $workoutKind, $workoutId, $template, $status, $maxAttempts);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось сохранить задачу генерации карточки', 500);
        }

        $jobId = (int) $this->db->insert_id;
        $stmt->close();

        return [
            'job_id' => $jobId,
            'queued' => true,
            'deduplicated' => false,
            'status' => self::STATUS_PENDING,
        ];
    }

    public function reserveNextJob(): ?array {
        $this->assertInfrastructureAvailable();

        $result = $this->db->query(
            "SELECT * FROM " . self::JOBS_TABLE . " WHERE status = '" . self::STATUS_PENDING . "' AND available_at <= NOW() ORDER BY id ASC LIMIT 1"
        );
        if (!$result) {
            throw new RuntimeException('Не удалось прочитать очередь карточек шаринга', 500);
        }

        $job = $result->fetch_assoc();
        if (!$job) {
            return null;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . self::JOBS_TABLE . ' SET status = ?, started_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ? AND status = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось зарезервировать задачу карточки шаринга', 500);
        }

        $running = self::STATUS_RUNNING;
        $pending = self::STATUS_PENDING;
        $jobId = (int) $job['id'];
        $stmt->bind_param('sis', $running, $jobId, $pending);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            return null;
        }

        $job['status'] = self::STATUS_RUNNING;
        $job['attempts'] = ((int) $job['attempts']) + 1;
        return $job;
    }

    public function markCompleted(int $jobId, array $result = []): void {
        $resultJson = !empty($result) ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $this->db->prepare(
            'UPDATE ' . self::JOBS_TABLE . ' SET status = ?, result_json = ?, finished_at = NOW(), last_error = NULL WHERE id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось отметить задачу карточки завершённой', 500);
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
            ? 'UPDATE ' . self::JOBS_TABLE . ' SET status = ?, last_error = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), finished_at = NULL WHERE id = ?'
            : 'UPDATE ' . self::JOBS_TABLE . ' SET status = ?, last_error = ?, finished_at = NOW() WHERE id = ?';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Не удалось обновить статус ошибки задачи карточки', 500);
        }

        if ($shouldRetry) {
            $stmt->bind_param('ssii', $nextStatus, $errorMessage, $retryDelaySeconds, $jobId);
        } else {
            $stmt->bind_param('ssi', $nextStatus, $errorMessage, $jobId);
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedCard(int $userId, int $workoutId, string $workoutKind, string $template, ?string $preferredRendererVersion = null): ?array {
        if ($userId <= 0 || $workoutId <= 0 || !$this->tableExists(self::CARDS_TABLE)) {
            return null;
        }

        $workoutKind = $this->normalizeWorkoutKind($workoutKind);
        $template = $this->normalizeTemplate($template);
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::CARDS_TABLE . ' WHERE user_id = ? AND workout_kind = ? AND workout_id = ? AND template = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('isis', $userId, $workoutKind, $workoutId, $template);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        $rendererVersion = (string) ($row['renderer_version'] ?? '');
        $supportedRendererVersions = [
            self::RENDERER_VERSION,
            self::RENDERER_VERSION_CLIENT,
        ];
        if (!in_array($rendererVersion, $supportedRendererVersions, true)) {
            $this->deleteCardFiles($userId, $workoutId, $workoutKind);
            $this->deleteCardRecord($userId, $workoutId, $workoutKind, $template);
            return null;
        }

        if ($preferredRendererVersion !== null && $rendererVersion !== $preferredRendererVersion) {
            return null;
        }

        $absolutePath = $this->resolveStoragePath((string) $row['file_path']);
        if (!is_file($absolutePath)) {
            $this->deleteCardRecord($userId, $workoutId, $workoutKind, $template);
            return null;
        }

        $body = @file_get_contents($absolutePath);
        if (!$this->isValidRenderedImageBody($body)) {
            @unlink($absolutePath);
            $this->deleteCardRecord($userId, $workoutId, $workoutKind, $template);
            return null;
        }

        return [
            ...$row,
            'absolute_path' => $absolutePath,
            'body' => $body,
        ];
    }

    /**
     * @param array{body: string, contentType: string, fileName: string, mapProvider: ?string} $rendered
     * @return array<string, mixed>
     */
    public function storeRenderedCard(int $userId, int $workoutId, string $workoutKind, string $template, array $rendered): array {
        $this->assertInfrastructureAvailable();

        $workoutKind = $this->normalizeWorkoutKind($workoutKind);
        $template = $this->normalizeTemplate($template);
        $body = (string) ($rendered['body'] ?? '');
        if (!$this->isValidRenderedImageBody($body)) {
            throw new RuntimeException('Изображение карточки шаринга получилось некорректным.');
        }

        $relativePath = $this->buildRelativePath($userId, $workoutId, $workoutKind, $template);
        $absolutePath = $this->resolveStoragePath($relativePath);
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог для кэша шаринга.');
        }

        $tmpPrefix = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($absolutePath)) ?: 'share-card';
        $tmpPath = @tempnam($dir, $tmpPrefix . '.tmp.');
        if ($tmpPath === false) {
            throw new RuntimeException('Не удалось создать временный файл для PNG-карточки шаринга.');
        }

        if (@file_put_contents($tmpPath, $body, LOCK_EX) === false) {
            @unlink($tmpPath);
            throw new RuntimeException('Не удалось сохранить PNG-карточку шаринга.');
        }
        if (!@rename($tmpPath, $absolutePath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Не удалось атомарно обновить PNG-карточку шаринга.');
        }
        @chmod($absolutePath, 0664);

        $mimeType = (string) ($rendered['contentType'] ?? 'image/png');
        $fileName = (string) ($rendered['fileName'] ?? basename($relativePath));
        $fileSize = (int) filesize($absolutePath);
        $mapProvider = !empty($rendered['mapProvider']) ? (string) $rendered['mapProvider'] : null;

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::CARDS_TABLE . ' (user_id, workout_kind, workout_id, template, mime_type, file_path, file_name, file_size, map_provider, renderer_version, generated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                mime_type = VALUES(mime_type),
                file_path = VALUES(file_path),
                file_name = VALUES(file_name),
                file_size = VALUES(file_size),
                map_provider = VALUES(map_provider),
                renderer_version = VALUES(renderer_version),
                generated_at = NOW()'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось обновить запись кэша карточки.', 500);
        }

        $rendererVersion = !empty($rendered['rendererVersion'])
            ? (string) $rendered['rendererVersion']
            : self::RENDERER_VERSION;
        $stmt->bind_param(
            'isissssiss',
            $userId,
            $workoutKind,
            $workoutId,
            $template,
            $mimeType,
            $relativePath,
            $fileName,
            $fileSize,
            $mapProvider,
            $rendererVersion
        );
        $stmt->execute();
        $stmt->close();

        return [
            'file_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'map_provider' => $mapProvider,
            'renderer_version' => $rendererVersion,
        ];
    }

    private function isValidRenderedImageBody($body): bool {
        if (!is_string($body) || $body === '') {
            return false;
        }

        $imageSize = @getimagesizefromstring($body);
        if (!is_array($imageSize)) {
            return false;
        }

        $width = (int) ($imageSize[0] ?? 0);
        $height = (int) ($imageSize[1] ?? 0);
        if ($width < self::MIN_VALID_IMAGE_WIDTH || $height < self::MIN_VALID_IMAGE_HEIGHT) {
            return false;
        }

        $mimeType = (string) ($imageSize['mime'] ?? '');
        return in_array($mimeType, ['image/png', 'image/jpeg'], true);
    }

    public function deleteWorkoutAssets(int $userId, int $workoutId, string $workoutKind): void {
        if ($userId <= 0 || $workoutId <= 0 || !$this->isInfrastructureAvailable()) {
            return;
        }

        $workoutKind = $this->normalizeWorkoutKind($workoutKind);
        $this->deleteCardFiles($userId, $workoutId, $workoutKind);

        $stmt = $this->db->prepare(
            'DELETE FROM ' . self::CARDS_TABLE . ' WHERE user_id = ? AND workout_kind = ? AND workout_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('isi', $userId, $workoutKind, $workoutId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $this->db->prepare(
            'DELETE FROM ' . self::JOBS_TABLE . ' WHERE user_id = ? AND workout_kind = ? AND workout_id = ? AND status IN (?, ?)'
        );
        if ($stmt) {
            $pending = self::STATUS_PENDING;
            $running = self::STATUS_RUNNING;
            $stmt->bind_param('isiss', $userId, $workoutKind, $workoutId, $pending, $running);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function clearPendingJobsForCard(int $userId, int $workoutId, string $workoutKind, string $template): void {
        if ($userId <= 0 || $workoutId <= 0 || !$this->tableExists(self::JOBS_TABLE)) {
            return;
        }

        $workoutKind = $this->normalizeWorkoutKind($workoutKind);
        $template = $this->normalizeTemplate($template);
        $pending = self::STATUS_PENDING;
        $stmt = $this->db->prepare(
            'DELETE FROM ' . self::JOBS_TABLE . ' WHERE user_id = ? AND workout_kind = ? AND workout_id = ? AND template = ? AND status = ?'
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('isiss', $userId, $workoutKind, $workoutId, $template, $pending);
        $stmt->execute();
        $stmt->close();
    }

    public function isInfrastructureAvailable(): bool {
        return $this->tableExists(self::JOBS_TABLE) && $this->tableExists(self::CARDS_TABLE);
    }

    /**
     * @return string[]
     */
    private function detectTemplatesForWorkout(int $userId, int $workoutId, string $workoutKind): array {
        $workoutKind = $this->normalizeWorkoutKind($workoutKind);
        if ($workoutKind === self::KIND_MANUAL) {
            return [self::TEMPLATE_MINIMAL];
        }

        $templates = [self::TEMPLATE_MINIMAL];
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS cnt
            FROM workout_timeline
            WHERE workout_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL
        ");
        if (!$stmt) {
            return $templates;
        }

        $stmt->bind_param('i', $workoutId);
        $stmt->execute();
        $count = (int) (($stmt->get_result()->fetch_assoc()['cnt'] ?? 0));
        $stmt->close();

        if ($count >= 2) {
            array_unshift($templates, self::TEMPLATE_ROUTE);
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveJob(int $userId, int $workoutId, string $workoutKind, string $template): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, status FROM ' . self::JOBS_TABLE . ' WHERE user_id = ? AND workout_kind = ? AND workout_id = ? AND template = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }

        $pending = self::STATUS_PENDING;
        $running = self::STATUS_RUNNING;
        $stmt->bind_param('isisss', $userId, $workoutKind, $workoutId, $template, $pending, $running);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $job;
    }

    private function deleteCardRecord(int $userId, int $workoutId, string $workoutKind, string $template): void {
        if (!$this->tableExists(self::CARDS_TABLE)) {
            return;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM ' . self::CARDS_TABLE . ' WHERE user_id = ? AND workout_kind = ? AND workout_id = ? AND template = ?'
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('isis', $userId, $workoutKind, $workoutId, $template);
        $stmt->execute();
        $stmt->close();
    }

    private function deleteCardFiles(int $userId, int $workoutId, string $workoutKind): void {
        if (!$this->tableExists(self::CARDS_TABLE)) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT file_path FROM ' . self::CARDS_TABLE . ' WHERE user_id = ? AND workout_kind = ? AND workout_id = ?'
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('isi', $userId, $workoutKind, $workoutId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $absolutePath = $this->resolveStoragePath((string) ($row['file_path'] ?? ''));
            if ($absolutePath && is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
        $stmt->close();
    }

    private function buildRelativePath(int $userId, int $workoutId, string $workoutKind, string $template): string {
        return sprintf(
            'share-cards/user_%d/%s_%d_%s.png',
            $userId,
            $workoutKind,
            $workoutId,
            $template
        );
    }

    private function resolveStoragePath(string $relativePath): string {
        $relativePath = ltrim($relativePath, '/');
        return dirname(__DIR__) . '/storage/' . $relativePath;
    }

    private function normalizeTemplate(string $template): string {
        $normalized = trim(mb_strtolower($template));
        if ($normalized === self::TEMPLATE_MINIMAL) {
            return self::TEMPLATE_MINIMAL;
        }
        return self::TEMPLATE_ROUTE;
    }

    private function normalizeWorkoutKind(string $workoutKind): string {
        $normalized = trim(mb_strtolower($workoutKind));
        if (in_array($normalized, [self::KIND_MANUAL, 'log', 'workout_log'], true)) {
            return self::KIND_MANUAL;
        }
        return self::KIND_WORKOUT;
    }

    private function assertInfrastructureAvailable(): void {
        if (!$this->isInfrastructureAvailable()) {
            throw new RuntimeException(
                'Кэш карточек шаринга недоступен. Выполните php scripts/migrate_all.php или php scripts/migrate_workout_share_cards.php',
                503
            );
        }
    }

    private function tableExists(string $tableName): bool {
        if ($tableName === '') {
            return false;
        }
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return (bool) $this->tableExistsCache[$tableName];
        }

        $safeName = preg_replace('/[^a-z0-9_]/i', '', $tableName);
        if ($safeName === '') {
            $this->tableExistsCache[$tableName] = false;
            return false;
        }

        $result = $this->db->query("SHOW TABLES LIKE '" . $this->db->real_escape_string($safeName) . "'");
        $exists = $result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $this->tableExistsCache[$tableName] = $exists;
        return $exists;
    }
}
