<?php

/**
 * Shared OpenAI-compatible LLM request helpers.
 *
 * Keeps DeepSeek/OpenAI-compatible differences out of feature services:
 * auth headers and thinking-mode payload shape.
 */

class LlmGatewayRequestException extends RuntimeException
{
    private int $httpStatus;
    private bool $retryable;
    private ?int $retryAfterSeconds;
    private ?string $responseBody;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        bool $retryable = false,
        ?int $retryAfterSeconds = null,
        ?string $responseBody = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->retryable = $retryable;
        $this->retryAfterSeconds = $retryAfterSeconds;
        $this->responseBody = $responseBody;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}

class LlmGateway
{
    private const LIMITER_TABLE = 'llm_gateway_locks';

    private static bool $limiterTableReady = false;

    public static function provider(?string $baseUrl = null): string
    {
        $provider = strtolower(trim((string) env('LLM_PROVIDER', env('PLAN_LLM_PROVIDER', ''))));
        if ($provider !== '') {
            return $provider;
        }

        return $baseUrl !== null && stripos($baseUrl, 'deepseek') !== false
            ? 'deepseek'
            : 'openai-compatible';
    }

    public static function apiKey(?string $purpose = null): string
    {
        $keys = self::apiKeys($purpose);
        if ($keys === []) {
            return '';
        }

        return $keys[random_int(0, count($keys) - 1)];
    }

    public static function apiKeys(?string $purpose = null): array
    {
        $purpose = strtolower(trim((string) $purpose));
        $envValues = [];

        if ($purpose !== '') {
            $upperPurpose = strtoupper($purpose);
            if ($purpose === 'plan') {
                $envValues[] = env('PLAN_LLM_API_KEYS', '');
                $envValues[] = env('PLAN_LLM_API_KEY', '');
            } elseif ($purpose === 'chat') {
                $envValues[] = env('LLM_CHAT_API_KEYS', '');
                $envValues[] = env('LLM_CHAT_API_KEY', '');
            }

            $envValues[] = env('LLM_' . $upperPurpose . '_API_KEYS', '');
            $envValues[] = env('LLM_' . $upperPurpose . '_API_KEY', '');
        }

        $envValues[] = env('LLM_CHAT_API_KEYS', '');
        $envValues[] = env('LLM_CHAT_API_KEY', '');
        $envValues[] = env('PLAN_LLM_API_KEYS', '');
        $envValues[] = env('PLAN_LLM_API_KEY', '');
        $envValues[] = env('DEEPSEEK_API_KEYS', '');
        $envValues[] = env('DEEPSEEK_API_KEY', '');

        $keys = [];
        foreach ($envValues as $value) {
            foreach (self::splitApiKeys((string) $value) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    public static function headers(?string $baseUrl = null, ?string $apiKey = null, ?string $purpose = null): array
    {
        $headers = ['Content-Type: application/json'];
        $apiKey = $apiKey !== null ? trim($apiKey) : self::apiKey($purpose);
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        return $headers;
    }

    public static function apiKeyFingerprint(string $apiKey): ?string
    {
        $apiKey = trim($apiKey);
        return $apiKey !== '' ? substr(hash('sha256', $apiKey), 0, 12) : null;
    }

    private static function splitApiKeys(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', $value) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $key = trim((string) $part);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private static function selectApiKey(array $apiKeyPool): string
    {
        if ($apiKeyPool === []) {
            return '';
        }

        return (string) $apiKeyPool[random_int(0, count($apiKeyPool) - 1)];
    }

    public static function withThinkingMode(array $payload, ?string $baseUrl = null, bool $enableThinking = false): array
    {
        if (self::provider($baseUrl) === 'deepseek') {
            unset($payload['chat_template_kwargs']);
            $payload['thinking'] = ['type' => $enableThinking ? 'enabled' : 'disabled'];
            if ($enableThinking) {
                $effort = trim((string) env('LLM_REASONING_EFFORT', 'high'));
                if ($effort !== '') {
                    $payload['reasoning_effort'] = $effort;
                }
            }
            return $payload;
        }

        unset($payload['thinking'], $payload['reasoning_effort']);
        $payload['chat_template_kwargs'] = ['enable_thinking' => $enableThinking];
        return $payload;
    }

    public static function requestChatCompletion(string $baseUrl, array $payload, array $options = []): array
    {
        return self::requestJson($baseUrl, '/chat/completions', $payload, $options);
    }

    public static function requestJson(string $baseUrl, string $path, array $payload, array $options = []): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $url = $baseUrl . '/' . ltrim($path, '/');
        $feature = trim((string) ($options['feature'] ?? 'LLM request'));
        $model = trim((string) ($payload['model'] ?? ''));
        $timeoutSeconds = self::optionInt($options, 'timeout', 120, 1, 900);
        $connectTimeoutSeconds = self::optionInt($options, 'connect_timeout', 10, 1, 120);
        $maxAttempts = self::optionInt($options, 'max_attempts', self::envInt('LLM_GATEWAY_MAX_ATTEMPTS', 2, 1, 5), 1, 8);
        $purpose = strtolower(trim((string) ($options['purpose'] ?? '')));
        $explicitApiKey = array_key_exists('api_key', $options);
        $apiKeyPool = $explicitApiKey
            ? [trim((string) $options['api_key'])]
            : self::apiKeys($purpose);
        if ($apiKeyPool === []) {
            $apiKeyPool = [''];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new InvalidArgumentException($feature . ': failed to encode request payload');
        }

        $lastException = null;
        $startedAt = microtime(true);
        $attemptsUsed = 0;
        $limiterLease = null;
        $limiterMeta = [];
        try {
            $limiterLease = self::acquireConcurrencyLease(array_merge($options, [
                'purpose' => $purpose,
                'feature' => $feature,
                'model' => $model,
                'timeout' => $timeoutSeconds,
                'max_attempts' => $maxAttempts,
            ]));
            $limiterMeta = self::leaseObservabilityPayload($limiterLease);
        } catch (LlmGatewayRequestException $e) {
            self::logRequestEvent($options, 'error', [
                'feature' => $feature,
                'model' => $model,
                'provider' => self::provider($baseUrl),
                'http_status' => $e->getHttpStatus(),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'retry_count' => 0,
                'retryable' => $e->isRetryable(),
                'retry_after_seconds' => $e->getRetryAfterSeconds(),
                'limiter_rejected' => true,
                'error' => $e->getMessage(),
            ], self::durationMs($startedAt));
            throw $e;
        }

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $attemptsUsed = $attempt;
                $apiKey = self::selectApiKey($apiKeyPool);
                $requestMeta = [
                    'api_key_pool_size' => count(array_filter($apiKeyPool, static fn(string $key): bool => $key !== '')),
                    'api_key_fingerprint' => self::apiKeyFingerprint($apiKey),
                ] + $limiterMeta;
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => self::headers($baseUrl, $apiKey, $purpose),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_TIMEOUT => $timeoutSeconds,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
                    // TCP keepalive for long reasoner requests (4-5min) to keep proxies happy.
                    // Without this, intermediate Cloudflare/nginx may close idle TCP and the
                    // request fails even though DeepSeek is still computing.
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_TCP_KEEPIDLE => 30,
                    CURLOPT_TCP_KEEPINTVL => 15,
                ]);

                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $curlError = curl_error($ch);
                curl_close($ch);

                $rawHeaders = is_string($response) ? substr($response, 0, $headerSize) : '';
                $responseBody = is_string($response) ? substr($response, $headerSize) : '';

                if ($response === false || $curlError !== '') {
                    $retryAfter = self::backoffSeconds($attempt, $options);
                    $lastException = new LlmGatewayRequestException(
                        $feature . ': connection error: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'),
                        0,
                        true,
                        $retryAfter
                    );
                    if ($attempt < $maxAttempts) {
                        self::sleepBeforeRetry($retryAfter);
                        continue;
                    }
                    self::logRequestEvent($options, 'error', [
                        'feature' => $feature,
                        'model' => $model,
                        'provider' => self::provider($baseUrl),
                        'http_status' => 0,
                        'attempts' => $attemptsUsed,
                        'max_attempts' => $maxAttempts,
                        'retry_count' => max(0, $attemptsUsed - 1),
                        'retryable' => true,
                        'retry_after_seconds' => $retryAfter,
                        'error' => $lastException->getMessage(),
                    ] + $requestMeta, self::durationMs($startedAt));
                    throw $lastException;
                }

                if ($httpCode !== 200) {
                    $retryable = self::isRetryableHttpStatus($httpCode);
                    $retryAfter = self::parseRetryAfter($rawHeaders) ?? self::backoffSeconds($attempt, $options);
                    $message = $feature . " HTTP {$httpCode}: " . mb_substr(trim($responseBody), 0, 500, 'UTF-8');
                    $lastException = new LlmGatewayRequestException($message, $httpCode, $retryable, $retryAfter, $responseBody);
                    if ($retryable && $attempt < $maxAttempts) {
                        self::sleepBeforeRetry($retryAfter);
                        continue;
                    }
                    self::logRequestEvent($options, 'error', [
                        'feature' => $feature,
                        'model' => $model,
                        'provider' => self::provider($baseUrl),
                        'http_status' => $httpCode,
                        'attempts' => $attemptsUsed,
                        'max_attempts' => $maxAttempts,
                        'retry_count' => max(0, $attemptsUsed - 1),
                        'retryable' => $retryable,
                        'retry_after_seconds' => $retryAfter,
                        'error' => $message,
                    ] + $requestMeta, self::durationMs($startedAt));
                    throw $lastException;
                }

                $decoded = json_decode(trim($responseBody), true);
                if (!is_array($decoded)) {
                    $exception = new LlmGatewayRequestException(
                        $feature . ': invalid JSON response: ' . mb_substr(trim($responseBody), 0, 500, 'UTF-8'),
                        $httpCode,
                        false,
                        null,
                        $responseBody
                    );
                    self::logRequestEvent($options, 'error', [
                        'feature' => $feature,
                        'model' => $model,
                        'provider' => self::provider($baseUrl),
                        'http_status' => $httpCode,
                        'attempts' => $attemptsUsed,
                        'max_attempts' => $maxAttempts,
                        'retry_count' => max(0, $attemptsUsed - 1),
                        'retryable' => false,
                        'error' => $exception->getMessage(),
                    ] + $requestMeta, self::durationMs($startedAt));
                    throw $exception;
                }

                $choice = (array) ($decoded['choices'][0] ?? []);
                self::logRequestEvent($options, 'ok', array_merge([
                    'feature' => $feature,
                    'model' => $model,
                    'provider' => self::provider($baseUrl),
                    'http_status' => $httpCode,
                    'attempts' => $attemptsUsed,
                    'max_attempts' => $maxAttempts,
                    'retry_count' => max(0, $attemptsUsed - 1),
                    'finish_reason' => $choice['finish_reason'] ?? null,
                ] + $requestMeta, self::extractUsageMetrics($decoded)), self::durationMs($startedAt));

                return $decoded;
            }

            $fallback = $lastException ?? new LlmGatewayRequestException($feature . ': request failed', 0, true, self::backoffSeconds(1, $options));
            self::logRequestEvent($options, 'error', [
                'feature' => $feature,
                'model' => $model,
                'provider' => self::provider($baseUrl),
                'http_status' => $fallback instanceof LlmGatewayRequestException ? $fallback->getHttpStatus() : 0,
                'attempts' => $attemptsUsed,
                'max_attempts' => $maxAttempts,
                'retry_count' => max(0, $attemptsUsed - 1),
                'retryable' => $fallback instanceof LlmGatewayRequestException ? $fallback->isRetryable() : true,
                'retry_after_seconds' => $fallback instanceof LlmGatewayRequestException ? $fallback->getRetryAfterSeconds() : null,
                'error' => $fallback->getMessage(),
            ] + $limiterMeta, self::durationMs($startedAt));
            throw $fallback;
        } finally {
            self::releaseConcurrencyLease($limiterLease);
        }
    }

    public static function acquireConcurrencyLease(array $options): ?array
    {
        $db = $options['db'] ?? null;
        if (!$db instanceof mysqli) {
            return null;
        }

        $limits = self::resolveConcurrencyLimits($options);
        if ($limits === []) {
            return null;
        }

        self::ensureLimiterTable($db);

        $ownerToken = bin2hex(random_bytes(16));
        $purpose = strtolower(trim((string) ($options['purpose'] ?? '')));
        $feature = mb_substr(trim((string) ($options['feature'] ?? 'LLM request')), 0, 96, 'UTF-8');
        $ttlSeconds = self::resolveLimiterTtlSeconds($options);
        $waitSeconds = self::resolveLimiterWaitSeconds($options);
        $startedAt = microtime(true);
        $rows = [];

        try {
            foreach ($limits as $pool => $limit) {
                $rows[] = self::acquirePoolLease($db, $pool, $limit, $ownerToken, $purpose, $feature, $ttlSeconds, $waitSeconds);
            }
        } catch (Throwable $e) {
            self::releaseConcurrencyLease(['db' => $db, 'rows' => $rows]);
            if ($e instanceof LlmGatewayRequestException) {
                throw $e;
            }
            throw new LlmGatewayRequestException('LLM concurrency limiter failed: ' . $e->getMessage(), 500, false, null, null, $e);
        }

        return [
            'db' => $db,
            'rows' => $rows,
            'purpose' => $purpose,
            'wait_ms' => self::durationMs($startedAt),
            'ttl_seconds' => $ttlSeconds,
            'limit_pools' => array_map(
                static fn(array $row): array => [
                    'pool' => $row['pool'],
                    'limit' => $row['limit'],
                    'active_before_acquire' => $row['active_before_acquire'],
                ],
                $rows
            ),
        ];
    }

    public static function releaseConcurrencyLease(?array $lease): void
    {
        if (!is_array($lease) || !($lease['db'] ?? null) instanceof mysqli || empty($lease['rows'])) {
            return;
        }

        $db = $lease['db'];
        foreach ((array) $lease['rows'] as $row) {
            $id = (int) ($row['id'] ?? 0);
            $ownerToken = (string) ($row['owner_token'] ?? '');
            if ($id < 1 || $ownerToken === '') {
                continue;
            }

            try {
                $stmt = $db->prepare('DELETE FROM ' . self::LIMITER_TABLE . ' WHERE id = ? AND owner_token = ?');
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('is', $id, $ownerToken);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable) {
            }
        }
    }

    public static function describeConcurrencyLease(?array $lease): array
    {
        return self::leaseObservabilityPayload($lease);
    }

    private static function resolveConcurrencyLimits(array $options): array
    {
        if (array_key_exists('limit_concurrency', $options) && !$options['limit_concurrency']) {
            return [];
        }

        $limits = [];
        $globalLimit = self::envInt('LLM_GATEWAY_GLOBAL_MAX_CONCURRENT', 0, 0, 1000);
        if ($globalLimit > 0) {
            $limits['global'] = $globalLimit;
        }

        $purpose = strtolower(trim((string) ($options['purpose'] ?? '')));
        if ($purpose !== '') {
            $purposeLimit = self::resolvePurposeConcurrencyLimit($purpose);
            if ($purposeLimit > 0) {
                $limits['purpose:' . self::sanitizeLimiterPool($purpose)] = $purposeLimit;
            }
        }

        return $limits;
    }

    private static function resolvePurposeConcurrencyLimit(string $purpose): int
    {
        $purpose = strtolower(trim($purpose));
        if ($purpose === '') {
            return 0;
        }

        $aliases = match ($purpose) {
            'plan' => ['PLAN_LLM_MAX_CONCURRENT', 'LLM_GATEWAY_PLAN_MAX_CONCURRENT'],
            'chat' => ['LLM_CHAT_MAX_CONCURRENT', 'LLM_GATEWAY_CHAT_MAX_CONCURRENT'],
            default => ['LLM_GATEWAY_' . strtoupper($purpose) . '_MAX_CONCURRENT'],
        };

        foreach ($aliases as $key) {
            $value = self::envInt($key, 0, 0, 1000);
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    private static function resolveLimiterWaitSeconds(array $options): int
    {
        if (isset($options['limit_wait_seconds']) && is_numeric($options['limit_wait_seconds'])) {
            return max(0, min(120, (int) $options['limit_wait_seconds']));
        }

        $purpose = strtolower(trim((string) ($options['purpose'] ?? '')));
        $purposeKey = match ($purpose) {
            'plan' => 'PLAN_LLM_LIMIT_WAIT_SECONDS',
            'chat' => 'LLM_CHAT_LIMIT_WAIT_SECONDS',
            default => '',
        };
        if ($purposeKey !== '') {
            $purposeWait = self::envInt($purposeKey, 0, 0, 120);
            if ($purposeWait > 0) {
                return $purposeWait;
            }
        }

        return self::envInt('LLM_GATEWAY_LIMIT_WAIT_SECONDS', 15, 0, 120);
    }

    private static function resolveLimiterTtlSeconds(array $options): int
    {
        if (isset($options['limit_ttl_seconds']) && is_numeric($options['limit_ttl_seconds'])) {
            return max(10, min(1800, (int) $options['limit_ttl_seconds']));
        }

        $configured = self::envInt('LLM_GATEWAY_LIMIT_TTL_SECONDS', 0, 0, 1800);
        if ($configured > 0) {
            return $configured;
        }

        $timeout = isset($options['timeout']) && is_numeric($options['timeout']) ? (int) $options['timeout'] : 120;
        $maxAttempts = isset($options['max_attempts']) && is_numeric($options['max_attempts']) ? (int) $options['max_attempts'] : 1;
        return max(30, min(1800, ($timeout * max(1, $maxAttempts)) + 60));
    }

    private static function acquirePoolLease(
        mysqli $db,
        string $pool,
        int $limit,
        string $ownerToken,
        string $purpose,
        string $feature,
        int $ttlSeconds,
        int $waitSeconds
    ): array {
        $pool = self::sanitizeLimiterPool($pool);
        $deadline = microtime(true) + $waitSeconds;
        $lockName = 'planrun_llm_' . substr(sha1($pool), 0, 40);

        do {
            if (!self::acquireMysqlNamedLock($db, $lockName, $waitSeconds > 0 ? 1 : 0)) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                usleep(200000);
                continue;
            }

            try {
                self::deleteExpiredPoolLeases($db, $pool);
                $activeCount = self::countActivePoolLeases($db, $pool);
                if ($activeCount < $limit) {
                    $rowId = self::insertPoolLease($db, $pool, $ownerToken, $purpose, $feature, $ttlSeconds, $limit, $activeCount);
                    return [
                        'id' => $rowId,
                        'pool' => $pool,
                        'owner_token' => $ownerToken,
                        'limit' => $limit,
                        'active_before_acquire' => $activeCount,
                    ];
                }
            } finally {
                self::releaseMysqlNamedLock($db, $lockName);
            }

            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(250000);
        } while (true);

        $retryAfter = max(3, min(120, $waitSeconds + 5));
        throw new LlmGatewayRequestException(
            "LLM concurrency limit is busy for {$pool} (max {$limit})",
            429,
            true,
            $retryAfter
        );
    }

    private static function ensureLimiterTable(mysqli $db): void
    {
        if (self::$limiterTableReady) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS " . self::LIMITER_TABLE . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pool VARCHAR(80) NOT NULL,
            owner_token CHAR(32) NOT NULL,
            purpose VARCHAR(32) NOT NULL DEFAULT '',
            feature VARCHAR(96) NULL DEFAULT NULL,
            metadata_json TEXT NULL,
            acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uk_llm_gateway_lock_owner (pool, owner_token),
            INDEX idx_llm_gateway_locks_pool_expires (pool, expires_at),
            INDEX idx_llm_gateway_locks_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$db->query($sql)) {
            throw new RuntimeException('Failed to create LLM limiter table: ' . $db->error);
        }

        self::$limiterTableReady = true;
    }

    private static function acquireMysqlNamedLock(mysqli $db, string $lockName, int $waitSeconds): bool
    {
        $stmt = $db->prepare('SELECT GET_LOCK(?, ?) AS lock_status');
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare GET_LOCK');
        }
        $stmt->bind_param('si', $lockName, $waitSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return (int) ($row['lock_status'] ?? 0) === 1;
    }

    private static function releaseMysqlNamedLock(mysqli $db, string $lockName): void
    {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
        }
    }

    private static function deleteExpiredPoolLeases(mysqli $db, string $pool): void
    {
        $stmt = $db->prepare('DELETE FROM ' . self::LIMITER_TABLE . ' WHERE pool = ? AND expires_at <= NOW()');
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare expired lease cleanup');
        }
        $stmt->bind_param('s', $pool);
        $stmt->execute();
        $stmt->close();
    }

    private static function countActivePoolLeases(mysqli $db, string $pool): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM ' . self::LIMITER_TABLE . ' WHERE pool = ? AND expires_at > NOW()');
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare active lease count');
        }
        $stmt->bind_param('s', $pool);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return (int) ($row['cnt'] ?? 0);
    }

    private static function insertPoolLease(
        mysqli $db,
        string $pool,
        string $ownerToken,
        string $purpose,
        string $feature,
        int $ttlSeconds,
        int $limit,
        int $activeBeforeAcquire
    ): int {
        $metadata = json_encode([
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'limit' => $limit,
            'active_before_acquire' => $activeBeforeAcquire,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $db->prepare(
            'INSERT INTO ' . self::LIMITER_TABLE . ' (pool, owner_token, purpose, feature, metadata_json, expires_at) ' .
            'VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare lease insert');
        }
        $stmt->bind_param('sssssi', $pool, $ownerToken, $purpose, $feature, $metadata, $ttlSeconds);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Failed to insert lease: ' . $error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    }

    private static function leaseObservabilityPayload(?array $lease): array
    {
        if (!is_array($lease) || empty($lease['limit_pools'])) {
            return ['limiter_enabled' => false];
        }

        return [
            'limiter_enabled' => true,
            'limiter_wait_ms' => (int) ($lease['wait_ms'] ?? 0),
            'limiter_ttl_seconds' => (int) ($lease['ttl_seconds'] ?? 0),
            'limiter_pools' => $lease['limit_pools'],
        ];
    }

    private static function sanitizeLimiterPool(string $pool): string
    {
        $pool = strtolower(trim($pool));
        $pool = preg_replace('/[^a-z0-9:_-]+/', '_', $pool) ?: 'default';
        return mb_substr($pool, 0, 80, 'UTF-8');
    }

    public static function isRetryableThrowable(Throwable $e): bool
    {
        return $e instanceof LlmGatewayRequestException && $e->isRetryable();
    }

    public static function queueRetryDelaySeconds(Throwable $e, int $attempts = 1): int
    {
        $attempts = max(1, $attempts);
        if ($e instanceof LlmGatewayRequestException && $e->getRetryAfterSeconds() !== null) {
            return max(5, min(900, $e->getRetryAfterSeconds()));
        }

        $base = self::envInt('LLM_QUEUE_RETRY_DELAY_SECONDS', 60, 5, 900);
        if ($e instanceof LlmGatewayRequestException) {
            $status = $e->getHttpStatus();
            if ($status === 429) {
                $base = self::envInt('LLM_QUEUE_RATE_LIMIT_RETRY_SECONDS', 120, 10, 1800);
            } elseif (in_array($status, [500, 502, 503, 504, 529], true)) {
                $base = self::envInt('LLM_QUEUE_OVERLOAD_RETRY_SECONDS', 90, 10, 1800);
            }
        }

        $delay = min(1800, $base * $attempts);
        return $delay + random_int(0, min(30, max(1, (int) floor($delay / 3))));
    }

    private static function isRetryableHttpStatus(int $httpStatus): bool
    {
        return in_array($httpStatus, [408, 409, 425, 429, 500, 502, 503, 504, 529], true);
    }

    private static function parseRetryAfter(string $headers): ?int
    {
        if (!preg_match('/^retry-after:\s*(.+)$/mi', $headers, $matches)) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? ''));
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return max(1, (int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return max(1, $timestamp - time());
    }

    private static function backoffSeconds(int $attempt, array $options): int
    {
        $baseMs = self::optionInt($options, 'retry_base_delay_ms', self::envInt('LLM_GATEWAY_RETRY_BASE_DELAY_MS', 700, 100, 10000), 1, 60000);
        $capMs = self::optionInt($options, 'retry_max_delay_ms', self::envInt('LLM_GATEWAY_RETRY_MAX_DELAY_MS', 8000, 100, 120000), 1, 300000);
        $jitterMs = self::optionInt($options, 'retry_jitter_ms', self::envInt('LLM_GATEWAY_RETRY_JITTER_MS', 400, 0, 10000), 0, 60000);
        $delayMs = min($capMs, $baseMs * (2 ** max(0, $attempt - 1)));
        if ($jitterMs > 0) {
            $delayMs += random_int(0, $jitterMs);
        }
        return max(1, (int) ceil($delayMs / 1000));
    }

    private static function sleepBeforeRetry(int $seconds): void
    {
        usleep(max(1, min(30, $seconds)) * 1000000);
    }

    private static function optionInt(array $options, string $key, int $default, int $min, int $max): int
    {
        $value = isset($options[$key]) ? (int) $options[$key] : $default;
        return max($min, min($max, $value));
    }

    private static function envInt(string $key, int $default, int $min, int $max): int
    {
        $value = function_exists('env') ? env($key, $default) : getenv($key);
        $intValue = is_numeric($value) ? (int) $value : $default;
        return max($min, min($max, $intValue));
    }

    private static function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private static function extractUsageMetrics(array $response): array
    {
        $usage = is_array($response['usage'] ?? null) ? (array) $response['usage'] : [];
        $metrics = ['usage' => $usage];
        foreach ([
            'prompt_tokens',
            'completion_tokens',
            'total_tokens',
            'prompt_cache_hit_tokens',
            'prompt_cache_miss_tokens',
            'cached_tokens',
        ] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $metrics[$key] = (int) $usage[$key];
            }
        }
        return $metrics;
    }

    private static function logRequestEvent(array $options, string $status, array $payload, int $durationMs): void
    {
        $db = $options['db'] ?? null;
        if (!is_object($db)) {
            return;
        }

        try {
            require_once __DIR__ . '/AiObservabilityService.php';
            $surface = trim((string) ($options['surface'] ?? 'llm'));
            $eventType = trim((string) ($options['event_type'] ?? 'llm_request'));
            $traceId = isset($options['trace_id']) ? trim((string) $options['trace_id']) : null;
            $userId = isset($options['user_id']) && is_numeric($options['user_id']) ? (int) $options['user_id'] : null;
            (new AiObservabilityService($db))->logEvent(
                $surface !== '' ? $surface : 'llm',
                $eventType !== '' ? $eventType : 'llm_request',
                $status,
                self::sanitizeObservabilityPayload($payload),
                $userId,
                $traceId !== '' ? $traceId : null,
                $durationMs
            );
        } catch (Throwable) {
        }
    }

    private static function sanitizeObservabilityPayload(array $payload): array
    {
        unset($payload['api_key'], $payload['messages'], $payload['prompt'], $payload['response_body']);
        if (isset($payload['error'])) {
            $payload['error'] = mb_substr((string) $payload['error'], 0, 700, 'UTF-8');
        }
        return $payload;
    }
}
