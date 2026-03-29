<?php
/**
 * Провайдер Huawei Health Kit REST API
 */
require_once __DIR__ . '/WorkoutImportProvider.php';

class HuaweiHealthProvider implements WorkoutImportProvider {
    private $db;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $scopes;

    public function __construct($db) {
        $this->db = $db;
        $this->clientId = (function_exists('env') ? env('HUAWEI_HEALTH_CLIENT_ID', '') : '') ?: '';
        $this->clientSecret = (function_exists('env') ? env('HUAWEI_HEALTH_CLIENT_SECRET', '') : '') ?: '';
        $this->redirectUri = $this->normalizeRedirectUri((function_exists('env') ? env('HUAWEI_HEALTH_REDIRECT_URI', '') : '') ?: '');
        $this->scopes = (function_exists('env') ? env('HUAWEI_HEALTH_SCOPES', '') : '') ?: 'https://www.huawei.com/healthkit/activity.read https://www.huawei.com/healthkit/historydata.open.month';
    }

    public function getProviderId(): string {
        return 'huawei';
    }

    public function getOAuthUrl(string $state): ?string {
        if (!$this->clientId || !$this->clientSecret || !$this->redirectUri) {
            return null;
        }
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes,
            'state' => $state,
            'access_type' => 'offline',
        ];
        return 'https://oauth-login.cloud.huawei.com/oauth2/v3/authorize?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $state): array {
        $userId = getCurrentUserId();
        if (!$userId) {
            throw new Exception('Требуется авторизация');
        }
        if (!$this->clientId || !$this->clientSecret || !$this->redirectUri) {
            throw new Exception('Huawei Health не настроен: проверьте client_id, client_secret и redirect_uri');
        }
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
        ]);
        $ch = curl_init('https://oauth-login.cloud.huawei.com/oauth2/v3/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            $this->logWarning('Huawei token exchange failed', [
                'user_id' => $userId,
                'http_code' => $httpCode,
                'curl_error' => $curlError ?: null,
                'response' => is_string($response) ? substr($response, 0, 500) : null,
                'redirect_uri' => $this->redirectUri,
            ]);
            throw new Exception($this->buildErrorMessage('Ошибка получения токена Huawei', $httpCode, $response, $curlError));
        }
        $expiresAt = isset($data['expires_in']) ? date('Y-m-d H:i:s', time() + (int)$data['expires_in']) : null;
        $this->saveTokens($userId, $data['access_token'], $data['refresh_token'] ?? null, $expiresAt);
        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => $expiresAt,
        ];
    }

    public function refreshToken(int $userId): bool {
        $row = $this->getTokenRow($userId);
        if (!$row || empty($row['refresh_token']) || !$this->clientId || !$this->clientSecret) {
            return false;
        }
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $ch = curl_init('https://oauth-login.cloud.huawei.com/oauth2/v3/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            $this->logWarning('Huawei refresh token failed', [
                'user_id' => $userId,
                'http_code' => $httpCode,
                'curl_error' => $curlError ?: null,
                'response' => is_string($response) ? substr($response, 0, 500) : null,
            ]);
            return false;
        }
        $expiresAt = isset($data['expires_in']) ? date('Y-m-d H:i:s', time() + (int)$data['expires_in']) : null;
        $this->saveTokens($userId, $data['access_token'], $data['refresh_token'] ?? $row['refresh_token'], $expiresAt);
        return true;
    }

    public function fetchWorkouts(int $userId, string $startDate, string $endDate): array {
        $row = $this->getTokenRow($userId);
        if (!$row) {
            return [];
        }
        $accessToken = $row['access_token'];
        $expiresAt = $row['expires_at'];
        if ($expiresAt && strtotime($expiresAt) < time() + 60) {
            if (!$this->refreshToken($userId)) {
                throw new Exception('Не удалось обновить токен Huawei Health');
            }
            $row = $this->getTokenRow($userId);
            if (!$row || empty($row['access_token'])) {
                throw new Exception('Токен Huawei Health отсутствует после refresh');
            }
            $accessToken = $row['access_token'];
        }
        $startTs = strtotime($startDate . ' 00:00:00') * 1000;
        $endTs = strtotime($endDate . ' 23:59:59') * 1000;
        if ($startTs <= 0 || $endTs <= 0) {
            throw new Exception('Некорректный период синхронизации Huawei Health');
        }
        $payload = [
            'polymerizeWith' => [
                ['dataTypeName' => 'com.huawei.continuous.activity.summary'],
            ],
            'startTime' => $startTs,
            'endTime' => $endTs,
        ];
        $ch = curl_init('https://health-api.cloud.huawei.com/healthkit/v1/sampleSet:polymerize');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError !== '') {
            $this->logWarning('Huawei workout sync curl failed', [
                'user_id' => $userId,
                'error' => $curlError,
            ]);
            throw new Exception('Huawei Health API недоступен: ' . $curlError);
        }
        if ($httpCode !== 200) {
            $this->logWarning('Huawei workout sync failed', [
                'user_id' => $userId,
                'http_code' => $httpCode,
                'response' => is_string($response) ? substr($response, 0, 500) : null,
            ]);
            throw new Exception($this->buildErrorMessage('Ошибка ответа Huawei Health API', $httpCode, $response));
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Huawei Health вернул некорректный JSON');
        }
        return $this->mapHuaweiResponseToWorkouts($data);
    }

    private function mapHuaweiResponseToWorkouts(array $data): array {
        $workouts = [];
        if (empty($data['sampleSets'])) {
            return $workouts;
        }
        foreach ($data['sampleSets'] ?? [] as $set) {
            $samples = $set['samples'] ?? [];
            foreach ($samples as $s) {
                $startTime = isset($s['startTime']) ? date('Y-m-d H:i:s', (int)($s['startTime'] / 1000)) : null;
                $endTime = isset($s['endTime']) ? date('Y-m-d H:i:s', (int)($s['endTime'] / 1000)) : $startTime;
                $fieldValues = $s['fieldValues'] ?? [];
                $distance = null;
                $duration = null;
                $durationSeconds = null;
                $activityType = 'running';
                foreach ($fieldValues as $fv) {
                    $field = $fv['fieldName'] ?? '';
                    $value = $fv['value'] ?? 0;
                    if ($field === 'distance') {
                        $distance = (float)$value / 1000;
                    } elseif ($field === 'duration' || $field === 'exercise_duration') {
                        $duration = (int)round($value / 60);
                        $durationSeconds = (int)$value;
                    } elseif ($field === 'activity_type') {
                        $activityType = $this->mapActivityType($value);
                    }
                }
                if ($startTime) {
                    $avgPace = $this->paceFromDistanceAndDuration($distance, $durationSeconds, $activityType);
                    $workouts[] = [
                        'activity_type' => $activityType,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'duration_minutes' => $duration,
                        'duration_seconds' => $durationSeconds,
                        'distance_km' => $distance,
                        'avg_pace' => $avgPace,
                        'external_id' => $s['id'] ?? ($startTime . '-' . ($distance ?? 0)),
                    ];
                }
            }
        }
        return $workouts;
    }

    private function paceFromDistanceAndDuration(?float $distanceKm, ?int $durationSeconds, string $activityType): ?string {
        if ($distanceKm === null || $distanceKm <= 0 || $durationSeconds === null || $durationSeconds <= 0) {
            return null;
        }
        if (!in_array($activityType, ['running', 'walking', 'hiking'], true)) {
            return null;
        }
        $secondsPerKm = (int)round($durationSeconds / $distanceKm);
        return sprintf('%d:%02d', (int)floor($secondsPerKm / 60), (int)($secondsPerKm % 60));
    }

    private function mapActivityType($value): string {
        $map = [
            1 => 'running', 2 => 'walking', 3 => 'cycling', 4 => 'swimming',
            5 => 'hiking', 6 => 'other',
        ];
        return $map[(int)$value] ?? 'running';
    }

    private function normalizeRedirectUri(string $uri): string {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            return $uri;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['provider'] = $this->getProviderId();
        $queryString = http_build_query($query);

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path
            . ($queryString !== '' ? '?' . $queryString : '')
            . $fragment;
    }

    private function buildErrorMessage(string $fallback, int $httpCode, $response, string $curlError = ''): string {
        if ($httpCode > 0) {
            $fallback .= ' (HTTP ' . $httpCode . ')';
        }
        if ($curlError !== '') {
            return $fallback . ': ' . $curlError;
        }
        $data = json_decode((string)$response, true);
        if (is_array($data)) {
            $details = $data['error_description'] ?? $data['error'] ?? $data['message'] ?? null;
            if (is_string($details) && trim($details) !== '') {
                return $fallback . ': ' . trim($details);
            }
        }
        $responseText = trim((string)$response);
        if ($responseText !== '' && strlen($responseText) <= 300) {
            return $fallback . ': ' . $responseText;
        }
        return $fallback;
    }

    private function logWarning(string $message, array $context = []): void {
        if (!file_exists(__DIR__ . '/../config/Logger.php')) {
            return;
        }
        require_once __DIR__ . '/../config/Logger.php';
        \Logger::warning($message, $context);
    }

    public function isConnected(int $userId): bool {
        return $this->getTokenRow($userId) !== null;
    }

    public function disconnect(int $userId): void {
        $stmt = $this->db->prepare("DELETE FROM integration_tokens WHERE user_id = ? AND provider = ?");
        $provider = $this->getProviderId();
        $stmt->bind_param("is", $userId, $provider);
        $stmt->execute();
        $stmt->close();
    }

    private function getTokenRow(int $userId): ?array {
        $stmt = $this->db->prepare("SELECT access_token, refresh_token, expires_at FROM integration_tokens WHERE user_id = ? AND provider = ?");
        $provider = $this->getProviderId();
        $stmt->bind_param("is", $userId, $provider);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }

    private function saveTokens(int $userId, string $accessToken, ?string $refreshToken, ?string $expiresAt): void {
        $provider = $this->getProviderId();
        $stmt = $this->db->prepare("
            INSERT INTO integration_tokens (user_id, provider, access_token, refresh_token, expires_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at), updated_at = NOW()
        ");
        $stmt->bind_param("issss", $userId, $provider, $accessToken, $refreshToken, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }
}
