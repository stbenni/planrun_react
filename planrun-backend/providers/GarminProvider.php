<?php
/**
 * Garmin Connect Developer Program — OAuth 2.0 с PKCE + Wellness REST (импорт активностей).
 *
 * Требуется одобрение: https://developer.garmin.com/gc-developer-program/activity-api/
 * После доступа в портале укажите scope, evaluation/production base URL и путь к summary (см. .env.example).
 */
require_once __DIR__ . '/WorkoutImportProvider.php';

class GarminProvider implements WorkoutImportProvider {
    private $db;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $authUrl;
    private $tokenUrl;
    private $scopes;
    /** База Wellness REST (evaluation или production из портала Garmin) */
    private $wellnessBase;
    /** Относительный путь, например activityDetailsSummary (из документации к вашему проекту) */
    private $activityFetchPath;
    private $activityFetchMethod;

    public function __construct($db) {
        $this->db = $db;
        $this->clientId = (function_exists('env') ? env('GARMIN_CLIENT_ID', '') : '') ?: '';
        $this->clientSecret = (function_exists('env') ? env('GARMIN_CLIENT_SECRET', '') : '') ?: '';
        $this->redirectUri = (function_exists('env') ? env('GARMIN_REDIRECT_URI', '') : '') ?: '';
        $this->authUrl = (function_exists('env') ? env('GARMIN_OAUTH_AUTH_URL', 'https://connect.garmin.com/oauth2Confirm') : '') ?: 'https://connect.garmin.com/oauth2Confirm';
        $this->tokenUrl = (function_exists('env') ? env('GARMIN_OAUTH_TOKEN_URL', 'https://diauth.garmin.com/di-oauth2-service/oauth/token') : '') ?: 'https://diauth.garmin.com/di-oauth2-service/oauth/token';
        $this->scopes = trim((string)((function_exists('env') ? env('GARMIN_OAUTH_SCOPES', '') : '') ?: ''));
        $this->wellnessBase = rtrim((string)((function_exists('env') ? env('GARMIN_WELLNESS_API_BASE', 'https://apis.garmin.com/wellness-api/rest') : '') ?: 'https://apis.garmin.com/wellness-api/rest'), '/');
        $this->activityFetchPath = trim((string)((function_exists('env') ? env('GARMIN_ACTIVITY_FETCH_PATH', 'activityDetailsSummary') : '') ?: 'activityDetailsSummary'), '/');
        $this->activityFetchMethod = strtoupper(trim((string)((function_exists('env') ? env('GARMIN_ACTIVITY_FETCH_METHOD', 'GET') : '') ?: 'GET')));
    }

    public function getProviderId(): string {
        return 'garmin';
    }

    private static function base64UrlEncode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function pkceSessionKey(string $state): string {
        return 'planrun_garmin_pkce_' . hash('sha256', $state);
    }

    public function getOAuthUrl(string $state): ?string {
        if ($this->clientId === '' || $this->redirectUri === '') {
            return null;
        }
        $verifier = self::base64UrlEncode(random_bytes(32));
        if (strlen($verifier) < 43) {
            $verifier .= self::base64UrlEncode(random_bytes(16));
        }
        $verifier = substr($verifier, 0, 128);
        $_SESSION[$this->pkceSessionKey($state)] = $verifier;
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if ($this->scopes !== '') {
            $params['scope'] = $this->scopes;
        }
        return $this->authUrl . '?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $state): array {
        $userId = getCurrentUserId();
        if (!$userId) {
            throw new Exception('Требуется авторизация');
        }
        $key = $this->pkceSessionKey($state);
        $codeVerifier = $_SESSION[$key] ?? '';
        unset($_SESSION[$key]);
        if ($codeVerifier === '') {
            throw new Exception('Сессия OAuth устарела. Откройте подключение Garmin снова из настроек.');
        }
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || !is_array($data) || empty($data['access_token'])) {
            $msg = is_array($data) && !empty($data['error_description'])
                ? (string)$data['error_description']
                : (is_array($data) && !empty($data['error']) ? (string)$data['error'] : 'Ошибка токена Garmin');
            throw new Exception($msg);
        }
        $access = (string)$data['access_token'];
        $refresh = isset($data['refresh_token']) ? (string)$data['refresh_token'] : null;
        $expiresAt = null;
        if (!empty($data['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$data['expires_in']);
        }
        $garminUserId = $this->extractUserIdFromAccessToken($access);
        $this->saveTokens($userId, $access, $refresh, $expiresAt, $garminUserId);
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => $expiresAt,
        ];
    }

    private function extractUserIdFromAccessToken(string $jwt): ?string {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }
        $payload = $parts[1];
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $p = json_decode($json, true);
        if (!is_array($p)) {
            return null;
        }
        foreach (['userId', 'user_id', 'sub', 'customerId'] as $k) {
            if (!empty($p[$k])) {
                return (string)$p[$k];
            }
        }
        return null;
    }

    public function refreshToken(int $userId): bool {
        $row = $this->getTokenRow($userId);
        if (!$row || empty($row['refresh_token'])) {
            return false;
        }
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $row['refresh_token'],
        ]);
        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || !is_array($data) || empty($data['access_token'])) {
            return false;
        }
        $access = (string)$data['access_token'];
        $refresh = isset($data['refresh_token']) ? (string)$data['refresh_token'] : $row['refresh_token'];
        $expiresAt = null;
        if (!empty($data['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$data['expires_in']);
        }
        $garminUserId = $this->extractUserIdFromAccessToken($access) ?? $row['external_athlete_id'];
        $this->saveTokens($userId, $access, $refresh, $expiresAt, $garminUserId);
        return true;
    }

    public function fetchWorkouts(int $userId, string $startDate, string $endDate): array {
        if ($this->activityFetchPath === '') {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Garmin fetch skipped: GARMIN_ACTIVITY_FETCH_PATH empty');
            return [];
        }
        $row = $this->getTokenRow($userId);
        if (!$row) {
            return [];
        }
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time() + 60) {
            $this->refreshToken($userId);
            $row = $this->getTokenRow($userId) ?? $row;
        }
        $startSec = strtotime($startDate . ' 00:00:00 UTC');
        $endSec = strtotime($endDate . ' 23:59:59 UTC');
        if ($startSec === false || $endSec === false) {
            return [];
        }
        $url = $this->wellnessBase . '/' . $this->activityFetchPath;
        $query = [
            'uploadStartTimeInSeconds' => $startSec,
            'uploadEndTimeInSeconds' => $endSec,
        ];
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $row['access_token'],
        ];
        $ch = curl_init();
        if ($this->activityFetchMethod === 'POST') {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($query),
                CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json']),
            ]);
        } else {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url . '?' . http_build_query($query),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => $headers,
            ]);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !is_string($response)) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Garmin activity fetch failed', ['http' => $httpCode, 'snippet' => substr((string)$response, 0, 200)]);
            return [];
        }
        $json = json_decode($response, true);
        $list = $this->extractActivityList($json);
        $workouts = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $w = $this->mapGarminActivityToWorkout($item);
            if ($w !== null) {
                $workouts[] = $w;
            }
        }
        return $workouts;
    }

    /**
     * @param mixed $json
     * @return array<int, array>
     */
    private function extractActivityList($json): array {
        if (!is_array($json)) {
            return [];
        }
        foreach (['activityDetailsSummaryList', 'activitySummaryList', 'activities', 'activitySummaries', 'data'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                $inner = $json[$k];
                if (isset($inner[0]) || empty($inner)) {
                    return $inner;
                }
                if (is_array($inner)) {
                    return array_values($inner);
                }
            }
        }
        if (isset($json[0]) && is_array($json[0])) {
            return $json;
        }
        return [];
    }

    /**
     * @return ?array Normalized workout for WorkoutService::importWorkouts
     */
    private function mapGarminActivityToWorkout(array $a): ?array {
        $id = $a['activityId'] ?? $a['summaryId'] ?? $a['activitySummaryId'] ?? $a['summary_id'] ?? null;
        $startSec = $a['startTimeInSeconds'] ?? $a['startTimestampInSeconds'] ?? null;
        if ($startSec === null && !empty($a['startTimeGMT'])) {
            $startSec = strtotime((string)$a['startTimeGMT'] . ' UTC');
        }
        if ($startSec === null) {
            return null;
        }
        $durationSec = isset($a['durationInSeconds']) ? (int)$a['durationInSeconds'] : (isset($a['elapsedDurationInSeconds']) ? (int)$a['elapsedDurationInSeconds'] : null);
        if ($durationSec === null || $durationSec <= 0) {
            $durationSec = 0;
        }
        $durationMinutes = $durationSec > 0 ? (int)round($durationSec / 60) : null;
        $startTs = (int)$startSec;
        $startTimeStr = gmdate('Y-m-d H:i:s', $startTs);
        $endTime = $durationSec > 0 ? gmdate('Y-m-d H:i:s', $startTs + $durationSec) : $startTimeStr;
        $distM = isset($a['distanceInMeters']) ? (float)$a['distanceInMeters'] : (isset($a['totalDistanceInMeters']) ? (float)$a['totalDistanceInMeters'] : 0.0);
        $distanceKm = $distM > 0 ? round($distM / 1000, 3) : null;
        $typeRaw = $a['activityType'] ?? $a['type'] ?? '';
        if (is_array($typeRaw) && isset($typeRaw['typeKey'])) {
            $typeRaw = (string)$typeRaw['typeKey'];
        }
        $activityType = $this->mapGarminActivityType((string)$typeRaw);
        $avgPace = ($distanceKm > 0 && $durationMinutes > 0 && in_array($activityType, ['running', 'walking', 'hiking'], true))
            ? $this->paceFromKmAndMinutes($distanceKm, $durationMinutes) : null;
        $avgHr = isset($a['averageHeartRateInBeatsPerMinute']) ? (int)$a['averageHeartRateInBeatsPerMinute']
            : (isset($a['averageHR']) ? (int)$a['averageHR'] : null);
        $maxHr = isset($a['maxHeartRateInBeatsPerMinute']) ? (int)$a['maxHeartRateInBeatsPerMinute']
            : (isset($a['maxHR']) ? (int)$a['maxHR'] : null);
        $elev = isset($a['totalElevationGainInMeters']) ? (int)round((float)$a['totalElevationGainInMeters']) : null;
        $ext = 'garmin_' . ($id !== null ? (string)$id : $startTimeStr);
        return [
            'activity_type' => $activityType,
            'start_time' => $startTimeStr,
            'end_time' => $endTime,
            'duration_minutes' => $durationMinutes,
            'duration_seconds' => $durationSec > 0 ? $durationSec : null,
            'distance_km' => $distanceKm,
            'avg_pace' => $avgPace,
            'avg_heart_rate' => $avgHr,
            'max_heart_rate' => $maxHr,
            'elevation_gain' => $elev,
            'external_id' => $ext,
            'timeline' => null,
        ];
    }

    private function mapGarminActivityType(string $t): string {
        $u = strtoupper($t);
        if (strpos($u, 'RUN') !== false) {
            return 'running';
        }
        if (strpos($u, 'CYCL') !== false || strpos($u, 'BIKE') !== false) {
            return 'cycling';
        }
        if (strpos($u, 'SWIM') !== false) {
            return 'swimming';
        }
        if (strpos($u, 'WALK') !== false) {
            return 'walking';
        }
        if (strpos($u, 'HIKE') !== false) {
            return 'hiking';
        }
        return 'running';
    }

    private function paceFromKmAndMinutes(float $km, int $minutes): string {
        if ($km <= 0) {
            return null;
        }
        $paceMinPerKm = $minutes / $km;
        $m = (int)floor($paceMinPerKm);
        $s = (int)round(($paceMinPerKm - $m) * 60);
        if ($s >= 60) {
            $s = 0;
            $m++;
        }
        return sprintf('%d:%02d', $m, $s);
    }

    public function isConnected(int $userId): bool {
        return $this->getTokenRow($userId) !== null;
    }

    public function disconnect(int $userId): void {
        $stmt = $this->db->prepare('DELETE FROM integration_tokens WHERE user_id = ? AND provider = ?');
        $p = $this->getProviderId();
        $stmt->bind_param('is', $userId, $p);
        $stmt->execute();
        $stmt->close();
    }

    private function getTokenRow(int $userId): ?array {
        $stmt = $this->db->prepare('SELECT access_token, refresh_token, expires_at, external_athlete_id FROM integration_tokens WHERE user_id = ? AND provider = ?');
        $p = $this->getProviderId();
        $stmt->bind_param('is', $userId, $p);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function saveTokens(int $userId, string $accessToken, ?string $refreshToken, ?string $expiresAt, ?string $garminUserId): void {
        $stmt = $this->db->prepare('
            INSERT INTO integration_tokens (user_id, provider, external_athlete_id, access_token, refresh_token, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                expires_at = VALUES(expires_at),
                external_athlete_id = COALESCE(VALUES(external_athlete_id), external_athlete_id),
                updated_at = NOW()
        ');
        $pid = $this->getProviderId();
        $stmt->bind_param('isssss', $userId, $pid, $garminUserId, $accessToken, $refreshToken, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }
}
