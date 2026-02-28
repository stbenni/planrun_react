<?php
/**
 * Провайдер Strava API v3
 * OAuth 2.0, endpoint /athlete/activities
 */
require_once __DIR__ . '/WorkoutImportProvider.php';

class StravaProvider implements WorkoutImportProvider {
    private $db;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $proxy;
    private $scopes = 'activity:read_all';

    public function __construct($db) {
        $this->db = $db;
        $this->clientId = (function_exists('env') ? env('STRAVA_CLIENT_ID', '') : '') ?: '';
        $this->clientSecret = (function_exists('env') ? env('STRAVA_CLIENT_SECRET', '') : '') ?: '';
        $this->redirectUri = (function_exists('env') ? env('STRAVA_REDIRECT_URI', '') : '') ?: '';
        $this->proxy = (function_exists('env') ? env('STRAVA_PROXY', '') : '') ?: null;
    }

    private function getCurlOpts(array $extra = []): array {
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($this->proxy) {
            $opts[CURLOPT_PROXY] = $this->proxy;
            $opts[CURLOPT_HTTPPROXYTUNNEL] = true;
            if (strpos($this->proxy, 'socks5') === 0) {
                $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
            }
        }
        return $opts + $extra;
    }

    public function getProviderId(): string {
        return 'strava';
    }

    public function getOAuthUrl(string $state): ?string {
        if (!$this->clientId || !$this->redirectUri) {
            return null;
        }
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes,
            'state' => $state,
            'approval_prompt' => 'auto',
        ];
        return 'https://www.strava.com/oauth/authorize?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $state): array {
        $userId = getCurrentUserId();
        if (!$userId) {
            throw new Exception('Требуется авторизация');
        }
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];
        if ($this->redirectUri) {
            $params['redirect_uri'] = $this->redirectUri;
        }
        $body = http_build_query($params);
        $ch = curl_init('https://www.strava.com/api/v3/oauth/token');
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            $msg = $data['message'] ?? $data['error'] ?? 'Ошибка получения токена Strava';
            if (!empty($data['errors']) && is_array($data['errors'])) {
                $errParts = [];
                foreach ($data['errors'] as $e) {
                    $errParts[] = ($e['field'] ?? '') . ': ' . ($e['code'] ?? $e['resource'] ?? '');
                }
                $msg .= ' (' . implode(', ', $errParts) . ')';
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['strava_token_error'] = [
                    'http_code' => $httpCode,
                    'response' => $response,
                    'redirect_uri_used' => $this->redirectUri,
                ];
            }
            throw new Exception($msg);
        }
        $expiresAt = isset($data['expires_at']) ? date('Y-m-d H:i:s', (int)$data['expires_at']) : null;
        $athleteId = isset($data['athlete']['id']) ? (string)$data['athlete']['id'] : null;
        $this->saveTokens($userId, $data['access_token'], $data['refresh_token'] ?? null, $expiresAt, $athleteId);
        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => $expiresAt,
        ];
    }

    public function refreshToken(int $userId): bool {
        $row = $this->getTokenRow($userId);
        if (!$row || empty($row['refresh_token'])) {
            return false;
        }
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
        ]);
        $ch = curl_init('https://www.strava.com/api/v3/oauth/token');
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            if (file_exists(__DIR__ . '/../config/Logger.php')) {
                require_once __DIR__ . '/../config/Logger.php';
                \Logger::warning('Strava refresh token failed', [
                    'user_id' => $userId,
                    'http_code' => $httpCode,
                    'curl_error' => $curlErr ?: null,
                    'response' => substr($response ?: '', 0, 500),
                ]);
            }
            return false;
        }
        $expiresAt = isset($data['expires_at']) ? date('Y-m-d H:i:s', (int)$data['expires_at']) : null;
        $athleteId = isset($data['athlete']['id']) ? (string)$data['athlete']['id'] : null;
        $this->saveTokens($userId, $data['access_token'], $data['refresh_token'] ?? $row['refresh_token'], $expiresAt, $athleteId);
        return true;
    }

    public function fetchWorkouts(int $userId, string $startDate, string $endDate): array {
        $row = $this->getTokenRow($userId);
        if (!$row) {
            return [];
        }
        $accessToken = $row['access_token'];
        $expiresAt = $row['expires_at'];
        // Strava token ~6ч. Обновляем заранее при остатке < 5 мин.
        if ($expiresAt && strtotime($expiresAt) < time() + 300) {
            if (!$this->refreshToken($userId)) {
                return [];
            }
            $row = $this->getTokenRow($userId);
            $accessToken = $row['access_token'];
        }
        $after = strtotime($startDate . ' 00:00:00');
        $before = strtotime($endDate . ' 23:59:59');
        $allActivities = [];
        $page = 1;
        $perPage = 200;
        do {
            $url = 'https://www.strava.com/api/v3/athlete/activities?' . http_build_query([
                'after' => $after,
                'before' => $before,
                'page' => $page,
                'per_page' => $perPage,
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, $this->getCurlOpts([
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            ]));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode !== 200) {
                break;
            }
            $activities = json_decode($response, true);
            if (!is_array($activities) || empty($activities)) {
                break;
            }
            $allActivities = array_merge($allActivities, $activities);
            if (count($activities) < $perPage) {
                break;
            }
            $page++;
        } while (true);
        $enriched = $this->enrichWithDetails($allActivities, $accessToken);
        return $this->mapStravaActivitiesToWorkouts($enriched);
    }

    /**
     * Получить одну тренировку по Strava activity ID (для webhook)
     * @param int $activityId Strava activity ID
     * @param int $userId PlanRun user_id
     * @param callable|null $onError (httpCode, response, message) — при ошибке
     * @return array|null Нормализованный workout или null
     */
    public function fetchSingleActivity(int $activityId, int $userId, ?callable $onError = null): ?array {
        $row = $this->getTokenRow($userId);
        if (!$row) {
            if ($onError) $onError(0, '', 'no token');
            return null;
        }
        $accessToken = $row['access_token'];
        $expiresAt = $row['expires_at'] ?? null;
        // Strava token ~6ч. Обновляем заранее при остатке < 5 мин.
        if ($expiresAt && strtotime($expiresAt) < time() + 300) {
            if (!$this->refreshToken($userId)) {
                if ($onError) $onError(401, '', 'token expired, refresh failed');
                return null;
            }
            $row = $this->getTokenRow($userId);
            $accessToken = $row['access_token'];
        }
        $result = $this->fetchActivityById($activityId, $accessToken, $userId, $onError);
        if ($result !== null) {
            return $result;
        }
        // Повтор при 401 (обновить токен и retry) или 5xx
        $lastHttpCode = $GLOBALS['_strava_last_http'] ?? 0;
        if ($lastHttpCode === 401 && $this->refreshToken($userId)) {
            $row = $this->getTokenRow($userId);
            return $this->fetchActivityById($activityId, $row['access_token'], $userId, $onError);
        }
        if ($lastHttpCode >= 500 && $lastHttpCode < 600) {
            usleep(1000000);
            return $this->fetchActivityById($activityId, $accessToken, $userId, $onError);
        }
        return null;
    }

    private function fetchActivityById(int $activityId, string $accessToken, int $userId, ?callable $onError): ?array {
        $url = 'https://www.strava.com/api/v3/activities/' . $activityId;
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]));
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $GLOBALS['_strava_last_http'] = $httpCode;
        $GLOBALS['_strava_last_response'] = $response;
        if ($httpCode !== 200) {
            $msg = $curlErr ?: (json_decode($response, true)['message'] ?? substr($response ?: '', 0, 100));
            if ($onError) $onError($httpCode, $response, $msg);
            return null;
        }
        $a = json_decode($response, true);
        if (!$a || !isset($a['id'])) {
            if ($onError) $onError(200, $response, 'invalid json or missing id');
            return null;
        }
        $a['strava_streams'] = $this->fetchActivityStreams($activityId, $accessToken, $a['start_date_local'] ?? null);
        $workouts = $this->mapStravaActivitiesToWorkouts([$a]);
        return $workouts[0] ?? null;
    }

    private function enrichWithDetails(array $activities, string $accessToken): array {
        $limit = 50;
        $enriched = [];
        foreach (array_slice($activities, 0, $limit) as $a) {
            $id = $a['id'] ?? null;
            if (!$id) {
                $enriched[] = $a;
                continue;
            }
            $url = 'https://www.strava.com/api/v3/activities/' . $id;
            $ch = curl_init($url);
            curl_setopt_array($ch, $this->getCurlOpts([
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            ]));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200) {
                $detail = json_decode($response, true);
                if ($detail) {
                    $a['average_heartrate'] = $detail['average_heartrate'] ?? $a['average_heartrate'] ?? null;
                    $a['max_heartrate'] = $detail['max_heartrate'] ?? $a['max_heartrate'] ?? null;
                    $a['total_elevation_gain'] = $detail['total_elevation_gain'] ?? $a['total_elevation_gain'] ?? null;
                    $a['average_speed'] = $detail['average_speed'] ?? $a['average_speed'] ?? null;
                    $a['sport_type'] = $detail['sport_type'] ?? $a['sport_type'] ?? null;
                    $a['type'] = $detail['type'] ?? $a['type'] ?? null;
                }
            }
            $a['strava_streams'] = $this->fetchActivityStreams($id, $accessToken, $a['start_date_local'] ?? null);
            $enriched[] = $a;
            usleep(250000);
        }
        foreach (array_slice($activities, $limit) as $a) {
            $enriched[] = $a;
        }
        return $enriched;
    }

    private function fetchActivityStreams(?int $activityId, string $accessToken, ?string $startDateLocal): ?array {
        if (!$activityId || !$startDateLocal) {
            return null;
        }
        $url = 'https://www.strava.com/api/v3/activities/' . $activityId . '/streams?keys=time,heartrate,altitude,velocity_smooth,distance,cadence';
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            return null;
        }
        $streams = json_decode($response, true);
        if (!is_array($streams)) {
            return null;
        }
        $byType = [];
        foreach ($streams as $s) {
            if (isset($s['type'], $s['data'])) {
                $byType[$s['type']] = $s['data'];
            }
        }
        $timeData = $byType['time'] ?? [];
        if (empty($timeData)) {
            return null;
        }
        $startTs = strtotime($startDateLocal);
        $hrData = $byType['heartrate'] ?? [];
        $altData = $byType['altitude'] ?? [];
        $velData = $byType['velocity_smooth'] ?? [];
        $distData = $byType['distance'] ?? [];
        $cadData = $byType['cadence'] ?? [];
        $points = [];
        $count = count($timeData);
        $step = max(1, (int)floor($count / 500));
        $indices = [];
        for ($i = 0; $i < $count; $i += $step) {
            $indices[] = $i;
        }
        if ($count > 1 && end($indices) !== $count - 1) {
            $indices[] = $count - 1;
        }
        foreach ($indices as $i) {
            $sec = (int)($timeData[$i] ?? 0);
            $timestamp = date('Y-m-d H:i:s', $startTs + $sec);
            $hr = isset($hrData[$i]) ? (int)$hrData[$i] : null;
            $alt = isset($altData[$i]) ? (float)$altData[$i] : null;
            $dist = isset($distData[$i]) ? (float)($distData[$i] / 1000) : null;
            $cad = isset($cadData[$i]) ? (int)$cadData[$i] : null;
            $pace = null;
            if (isset($velData[$i]) && $velData[$i] > 0) {
                $secPerKm = 1000 / $velData[$i];
                $pace = sprintf('%d:%02d', (int)floor($secPerKm / 60), (int)($secPerKm % 60));
            }
            $points[] = [
                'timestamp' => $timestamp,
                'heart_rate' => $hr,
                'pace' => $pace,
                'altitude' => $alt,
                'distance' => $dist,
                'cadence' => $cad,
            ];
        }
        return $points;
    }

    private function mapStravaActivitiesToWorkouts(array $activities): array {
        $workouts = [];
        foreach ($activities as $a) {
            $startDateLocal = $a['start_date_local'] ?? $a['start_date'] ?? null;
            if (!$startDateLocal) {
                continue;
            }
            $startTime = date('Y-m-d H:i:s', strtotime($startDateLocal));
            $elapsedSeconds = (int)($a['elapsed_time'] ?? 0);
            $durationMinutes = $elapsedSeconds > 0 ? (int)round($elapsedSeconds / 60) : null;
            $durationSeconds = $elapsedSeconds > 0 ? $elapsedSeconds : null;
            $endTime = $elapsedSeconds > 0 ? date('Y-m-d H:i:s', strtotime($startDateLocal) + $elapsedSeconds) : $startTime;
            $distanceM = (float)($a['distance'] ?? 0);
            $distanceKm = $distanceM > 0 ? round($distanceM / 1000, 3) : null;
            $sportTypeRaw = $a['sport_type'] ?? $a['type'] ?? null;
            $activityType = $this->mapSportType(is_string($sportTypeRaw) ? $sportTypeRaw : 'Run');
            $avgPace = $this->paceFromSpeed($a['average_speed'] ?? null, $activityType);
            $elevationGain = isset($a['total_elevation_gain']) ? (int)round((float)$a['total_elevation_gain']) : null;
            $avgHeartRate = isset($a['average_heartrate']) ? (int)round((float)$a['average_heartrate']) : null;
            $maxHeartRate = isset($a['max_heartrate']) ? (int)round((float)$a['max_heartrate']) : null;
            $workouts[] = [
                'activity_type' => $activityType,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'duration_seconds' => $durationSeconds,
                'distance_km' => $distanceKm,
                'avg_pace' => $avgPace,
                'avg_heart_rate' => $avgHeartRate,
                'max_heart_rate' => $maxHeartRate,
                'elevation_gain' => $elevationGain,
                'external_id' => 'strava_' . ($a['id'] ?? $startTime . '-' . ($distanceKm ?? 0)),
                'timeline' => $a['strava_streams'] ?? null,
            ];
        }
        return $workouts;
    }

    private function paceFromSpeed(?float $speedMps, string $activityType): ?string {
        if ($speedMps === null || $speedMps <= 0) {
            return null;
        }
        if ($activityType !== 'running' && $activityType !== 'walking' && $activityType !== 'hiking') {
            return null;
        }
        $secPerKm = 1000 / $speedMps;
        $min = (int)floor($secPerKm / 60);
        $sec = (int)($secPerKm % 60);
        return sprintf('%d:%02d', $min, $sec);
    }

    private function mapSportType(string $sportType): string {
        $s = strtolower(trim($sportType));
        if ($s === '') {
            return 'running';
        }
        if (strpos($s, 'run') !== false || $s === 'virtualrun' || $s === 'treadmillrun') {
            return 'running';
        }
        if (strpos($s, 'ride') !== false || strpos($s, 'cycle') !== false || strpos($s, 'bike') !== false) {
            return 'cycling';
        }
        if (strpos($s, 'swim') !== false) {
            return 'swimming';
        }
        if (strpos($s, 'walk') !== false) {
            return 'walking';
        }
        if (strpos($s, 'hike') !== false) {
            return 'hiking';
        }
        return 'running';
    }

    public function isConnected(int $userId): bool {
        return $this->getTokenRow($userId) !== null;
    }

    /**
     * Проверка и восстановление интеграции для одного пользователя.
     * Вызывается после привязки Strava и из strava_daily_health_check.php.
     * Заполняет external_athlete_id если пуст, обновляет токен если истёк.
     *
     * @return array{athlete_id_fixed: bool, token_refreshed: bool, error: ?string}
     */
    public function ensureIntegrationHealthy(int $userId): array {
        $result = ['athlete_id_fixed' => false, 'token_refreshed' => false, 'error' => null];
        $stmt = $this->db->prepare("SELECT access_token, refresh_token, expires_at, external_athlete_id FROM integration_tokens WHERE user_id = ? AND provider = ?");
        $provider = $this->getProviderId();
        $stmt->bind_param("is", $userId, $provider);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $result;
        }
        $expiresAt = $row['expires_at'] ?? null;
        $athleteId = trim($row['external_athlete_id'] ?? '');
        $expiresTs = $expiresAt ? strtotime($expiresAt) : 0;
        // Strava access token живёт ~6 часов. Обновляем заранее, когда осталось < 4 часов.
        $expiresIn4h = $expiresTs > 0 && $expiresTs < time() + 14400;

        if ($expiresTs < time() + 60 || $expiresIn4h) {
            if ($this->refreshToken($userId)) {
                $result['token_refreshed'] = true;
            } else {
                $result['error'] = 'refresh token failed';
            }
            return $result;
        }
        if ($athleteId !== '') {
            return $result;
        }
        $accessToken = $row['access_token'] ?? '';
        if (!$accessToken) {
            $result['error'] = 'no access_token';
            return $result;
        }
        $ch = curl_init('https://www.strava.com/api/v3/athlete');
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200) {
            $athlete = json_decode($response, true);
            if (isset($athlete['id'])) {
                $aid = (string)$athlete['id'];
                $up = $this->db->prepare("UPDATE integration_tokens SET external_athlete_id = ? WHERE user_id = ? AND provider = ?");
                $up->bind_param("sis", $aid, $userId, $provider);
                if ($up->execute()) {
                    $result['athlete_id_fixed'] = true;
                }
                $up->close();
            }
        } else {
            $result['error'] = "/athlete HTTP $httpCode";
        }
        return $result;
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

    private function saveTokens(int $userId, string $accessToken, ?string $refreshToken, ?string $expiresAt, ?string $athleteId = null): void {
        $provider = $this->getProviderId();
        if ($athleteId) {
            $del = $this->db->prepare("DELETE FROM integration_tokens WHERE provider = ? AND external_athlete_id = ? AND user_id != ?");
            $del->bind_param("ssi", $provider, $athleteId, $userId);
            $del->execute();
            $del->close();
        }
        $stmt = $this->db->prepare("
            INSERT INTO integration_tokens (user_id, provider, external_athlete_id, access_token, refresh_token, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at), external_athlete_id = COALESCE(VALUES(external_athlete_id), external_athlete_id), updated_at = NOW()
        ");
        $stmt->bind_param("isssss", $userId, $provider, $athleteId, $accessToken, $refreshToken, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }
}
