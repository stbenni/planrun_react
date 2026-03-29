<?php
/**
 * COROS Training Hub / Partner API — OAuth 2.0 и импорт активностей.
 *
 * После одобрения заявки заполните URL и пути из **API Reference Guide** (COROS).
 * Формат ответа списка активностей различается между версиями API — маппер ниже
 * покрывает типичные поля; при необходимости расширьте mapCorosActivityToWorkout().
 */
require_once __DIR__ . '/WorkoutImportProvider.php';

class CorosProvider implements WorkoutImportProvider {
    private $db;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $authUrl;
    private $tokenUrl;
    private $scopes;
    private $usePkce;
    private $tokenClientAuth;
    /** База REST API для списка/деталей активностей */
    private $apiBase;
    private $activityFetchPath;
    private $activityFetchMethod;
    /** Имя HTTP-заголовка с токеном (Authorization или например accesstoken) */
    private $accessHeaderName;
    /** Префикс значения: "Bearer " или пусто */
    private $accessHeaderPrefix;

    public function __construct($db) {
        $this->db = $db;
        $this->clientId = (string)((function_exists('env') ? env('COROS_CLIENT_ID', '') : '') ?: '');
        $this->clientSecret = (string)((function_exists('env') ? env('COROS_CLIENT_SECRET', '') : '') ?: '');
        $this->redirectUri = (string)((function_exists('env') ? env('COROS_REDIRECT_URI', '') : '') ?: '');
        $this->authUrl = (string)((function_exists('env') ? env('COROS_OAUTH_AUTH_URL', '') : '') ?: '');
        $this->tokenUrl = (string)((function_exists('env') ? env('COROS_OAUTH_TOKEN_URL', '') : '') ?: '');
        $this->scopes = trim((string)((function_exists('env') ? env('COROS_OAUTH_SCOPES', '') : '') ?: ''));
        $this->usePkce = ((function_exists('env') ? env('COROS_OAUTH_USE_PKCE', '0') : '0') ?: '0') === '1';
        $this->tokenClientAuth = strtolower(trim((string)((function_exists('env') ? env('COROS_TOKEN_CLIENT_AUTH', 'body') : '') ?: 'body')));
        $this->apiBase = rtrim((string)((function_exists('env') ? env('COROS_API_BASE', '') : '') ?: ''), '/');
        $this->activityFetchPath = trim((string)((function_exists('env') ? env('COROS_ACTIVITY_FETCH_PATH', '') : '') ?: ''), '/');
        $this->activityFetchMethod = strtoupper(trim((string)((function_exists('env') ? env('COROS_ACTIVITY_FETCH_METHOD', 'GET') : '') ?: 'GET')));
        $this->accessHeaderName = trim((string)((function_exists('env') ? env('COROS_API_ACCESS_HEADER', 'Authorization') : '') ?: 'Authorization'));
        $this->accessHeaderPrefix = (string)((function_exists('env') ? env('COROS_API_ACCESS_PREFIX', 'Bearer ') : '') ?: 'Bearer ');
    }

    public function getProviderId(): string {
        return 'coros';
    }

    private static function base64UrlEncode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function pkceSessionKey(string $state): string {
        return 'planrun_coros_pkce_' . hash('sha256', $state);
    }

    public function getOAuthUrl(string $state): ?string {
        if ($this->clientId === '' || $this->redirectUri === '' || $this->authUrl === '') {
            return null;
        }
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ];
        if ($this->scopes !== '') {
            $params['scope'] = $this->scopes;
        }
        if ($this->usePkce) {
            $verifier = self::base64UrlEncode(random_bytes(32));
            $verifier = substr($verifier, 0, 128);
            $_SESSION[$this->pkceSessionKey($state)] = $verifier;
            $params['code_challenge'] = self::base64UrlEncode(hash('sha256', $verifier, true));
            $params['code_challenge_method'] = 'S256';
        }
        $sep = (strpos($this->authUrl, '?') !== false) ? '&' : '?';
        return $this->authUrl . $sep . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $state): array {
        $userId = getCurrentUserId();
        if (!$userId) {
            throw new Exception('Требуется авторизация');
        }
        if ($this->tokenUrl === '') {
            throw new Exception('COROS_OAUTH_TOKEN_URL не задан в .env');
        }
        $bodyParams = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ];
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        if ($this->usePkce) {
            $key = $this->pkceSessionKey($state);
            $verifier = $_SESSION[$key] ?? '';
            unset($_SESSION[$key]);
            if ($verifier === '') {
                throw new Exception('Сессия OAuth устарела. Подключите COROS снова из настроек.');
            }
            $bodyParams['code_verifier'] = $verifier;
        }
        if ($this->tokenClientAuth === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
        } else {
            $bodyParams['client_id'] = $this->clientId;
            if ($this->clientSecret !== '') {
                $bodyParams['client_secret'] = $this->clientSecret;
            }
        }
        $body = http_build_query($bodyParams);
        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || !is_array($data) || empty($data['access_token'])) {
            $msg = 'Ошибка токена COROS';
            if (is_array($data)) {
                if (!empty($data['error_description'])) {
                    $msg = (string)$data['error_description'];
                } elseif (!empty($data['message'])) {
                    $msg = (string)$data['message'];
                } elseif (!empty($data['error'])) {
                    $msg = (string)$data['error'];
                }
            }
            throw new Exception($msg);
        }
        $access = (string)$data['access_token'];
        $refresh = isset($data['refresh_token']) ? (string)$data['refresh_token'] : null;
        $expiresAt = null;
        if (!empty($data['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$data['expires_in']);
        }
        $extId = $this->extractExternalUserId($access, $data);
        $this->saveTokens($userId, $access, $refresh, $expiresAt, $extId);
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param array<string,mixed> $tokenResponse
     */
    private function extractExternalUserId(string $accessToken, array $tokenResponse): ?string {
        foreach (['openId', 'open_id', 'user_id', 'userId', 'athlete_id', 'coros_user_id', 'sub'] as $k) {
            if (!empty($tokenResponse[$k])) {
                return (string)$tokenResponse[$k];
            }
        }
        $parts = explode('.', $accessToken);
        if (count($parts) >= 2) {
            $payload = $parts[1];
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $json = base64_decode(strtr($payload, '-_', '+/'), true);
            if ($json !== false) {
                $p = json_decode($json, true);
                if (is_array($p)) {
                    foreach (['openId', 'open_id', 'user_id', 'userId', 'sub'] as $k) {
                        if (!empty($p[$k])) {
                            return (string)$p[$k];
                        }
                    }
                }
            }
        }
        return null;
    }

    public function refreshToken(int $userId): bool {
        if ($this->tokenUrl === '') {
            return false;
        }
        $row = $this->getTokenRow($userId);
        if (!$row || empty($row['refresh_token'])) {
            return false;
        }
        $bodyParams = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
        ];
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        if ($this->tokenClientAuth === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
        } else {
            $bodyParams['client_id'] = $this->clientId;
            if ($this->clientSecret !== '') {
                $bodyParams['client_secret'] = $this->clientSecret;
            }
        }
        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($bodyParams),
            CURLOPT_HTTPHEADER => $headers,
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
        $extId = $this->extractExternalUserId($access, $data) ?? $row['external_athlete_id'];
        $this->saveTokens($userId, $access, $refresh, $expiresAt, $extId);
        return true;
    }

    public function fetchWorkouts(int $userId, string $startDate, string $endDate): array {
        if ($this->apiBase === '' || $this->activityFetchPath === '') {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('COROS fetch skipped: COROS_API_BASE or COROS_ACTIVITY_FETCH_PATH empty');
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
        $url = $this->apiBase . '/' . $this->activityFetchPath;
        $extra = [];
        $extraJson = (function_exists('env') ? env('COROS_ACTIVITY_EXTRA_QUERY_JSON', '') : '') ?: '';
        if ($extraJson !== '') {
            $decoded = json_decode($extraJson, true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }
        $query = array_merge([
            'startDate' => $startDate,
            'endDate' => $endDate,
        ], $extra);

        $authVal = $this->accessHeaderPrefix . $row['access_token'];
        if ($this->accessHeaderPrefix === '') {
            $authVal = $row['access_token'];
        }
        $headers = [
            'Accept: application/json',
            $this->accessHeaderName . ': ' . $authVal,
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
            Logger::warning('COROS activity fetch failed', ['http' => $httpCode, 'snippet' => substr((string)$response, 0, 200)]);
            return [];
        }
        $json = json_decode($response, true);
        $list = $this->extractActivityList($json);
        $workouts = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $w = $this->mapCorosActivityToWorkout($item);
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
        foreach (['data', 'activities', 'activityList', 'records', 'list', 'items', 'result'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                $inner = $json[$k];
                if (isset($inner[0]) || $inner === []) {
                    return $inner;
                }
            }
        }
        if (isset($json[0]) && is_array($json[0])) {
            return $json;
        }
        return [];
    }

    /**
     * @return ?array<string,mixed>
     */
    private function mapCorosActivityToWorkout(array $a): ?array {
        $id = $a['id'] ?? $a['activityId'] ?? $a['sportDataId'] ?? $a['uuid'] ?? null;
        $startSec = null;
        if (isset($a['startTimestamp'])) {
            $startSec = (int)$a['startTimestamp'];
            if ($startSec > 20000000000) {
                $startSec = (int)round($startSec / 1000);
            }
        } elseif (isset($a['startTime'])) {
            $st = $a['startTime'];
            if (is_numeric($st)) {
                $startSec = (int)$st;
                if ($startSec > 20000000000) {
                    $startSec = (int)round($startSec / 1000);
                }
            } else {
                $startSec = strtotime((string)$st . (preg_match('/Z|[+-]\d{2}:?\d{2}$/', (string)$st) ? '' : ' UTC'));
            }
        } elseif (!empty($a['startTimeGMT'])) {
            $startSec = strtotime((string)$a['startTimeGMT'] . ' UTC');
        }
        if ($startSec === null || $startSec === false) {
            return null;
        }
        $durationSec = null;
        foreach (['duration', 'movingTime', 'durationInSeconds', 'totalTime', 'timeDuration'] as $dk) {
            if (isset($a[$dk]) && is_numeric($a[$dk])) {
                $durationSec = (int)$a[$dk];
                if ($durationSec > 864000) {
                    $durationSec = (int)round($durationSec / 1000);
                }
                break;
            }
        }
        if ($durationSec === null || $durationSec <= 0) {
            $durationSec = 0;
        }
        $durationMinutes = $durationSec > 0 ? (int)max(1, round($durationSec / 60)) : null;
        $startTs = (int)$startSec;
        $startTimeStr = gmdate('Y-m-d H:i:s', $startTs);
        $endTime = $durationSec > 0 ? gmdate('Y-m-d H:i:s', $startTs + $durationSec) : $startTimeStr;
        $distanceKm = null;
        if (isset($a['distance'])) {
            $d = (float)$a['distance'];
            $distanceKm = $d > 200 ? round($d / 1000, 3) : round($d, 3);
        } elseif (isset($a['distanceMeter'])) {
            $distanceKm = round((float)$a['distanceMeter'] / 1000, 3);
        } elseif (isset($a['totalDistance'])) {
            $d = (float)$a['totalDistance'];
            $distanceKm = $d > 200 ? round($d / 1000, 3) : round($d, 3);
        }
        $typeRaw = $a['sportType'] ?? $a['activityType'] ?? $a['type'] ?? $a['mode'] ?? '';
        if (is_array($typeRaw)) {
            $typeRaw = (string)($typeRaw['name'] ?? $typeRaw['key'] ?? '');
        }
        $activityType = $this->mapCorosSportType((string)$typeRaw);
        $avgPace = ($distanceKm !== null && $distanceKm > 0 && $durationMinutes > 0 && in_array($activityType, ['running', 'walking', 'hiking'], true))
            ? $this->paceFromKmAndMinutes($distanceKm, $durationMinutes) : null;
        $avgHr = isset($a['avgHeartRate']) ? (int)$a['avgHeartRate'] : (isset($a['averageHeartRate']) ? (int)$a['averageHeartRate'] : null);
        $maxHr = isset($a['maxHeartRate']) ? (int)$a['maxHeartRate'] : null;
        $elev = isset($a['elevationGain']) ? (int)round((float)$a['elevationGain']) : (isset($a['cumulativeElevationGain']) ? (int)round((float)$a['cumulativeElevationGain']) : null);
        $ext = 'coros_' . ($id !== null ? (string)$id : $startTimeStr);
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

    private function mapCorosSportType(string $t): string {
        $u = strtoupper($t);
        if ($u === '' || strpos($u, 'RUN') !== false) {
            return 'running';
        }
        if (strpos($u, 'WALK') !== false) {
            return 'walking';
        }
        if (strpos($u, 'HIKE') !== false || strpos($u, 'TRAIL') !== false) {
            return 'hiking';
        }
        if (strpos($u, 'CYCLE') !== false || strpos($u, 'BIKE') !== false) {
            return 'cycling';
        }
        if (strpos($u, 'SWIM') !== false) {
            return 'swimming';
        }
        return 'running';
    }

    private function paceFromKmAndMinutes(float $km, int $minutes): ?string {
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

    private function saveTokens(int $userId, string $accessToken, ?string $refreshToken, ?string $expiresAt, ?string $externalId): void {
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
        $stmt->bind_param('isssss', $userId, $pid, $externalId, $accessToken, $refreshToken, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }
}
