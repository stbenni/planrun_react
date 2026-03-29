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
        $a = $this->enrichActivityWithStreamsAndLaps($a, $activityId, $accessToken);
        $workouts = $this->mapStravaActivitiesToWorkouts([$a]);
        return $workouts[0] ?? null;
    }

    private function enrichWithDetails(array $activities, string $accessToken): array {
        $limit = 30; // ~30 activities × 3 API calls ≈ 45s, fits in 60s timeout
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
                    $a['workout_type'] = $detail['workout_type'] ?? $a['workout_type'] ?? null;
                    $a['laps'] = $detail['laps'] ?? $a['laps'] ?? null;
                }
            }
            $a = $this->enrichActivityWithStreamsAndLaps($a, (int)$id, $accessToken);
            $enriched[] = $a;
            usleep(100000); // 100ms between requests to stay within Strava rate limits
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
        $url = 'https://www.strava.com/api/v3/activities/' . $activityId . '/streams?keys=time,heartrate,altitude,velocity_smooth,distance,cadence,latlng';
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
        $latlngData = $byType['latlng'] ?? [];
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
            $lat = isset($latlngData[$i]) ? (float)$latlngData[$i][0] : null;
            $lng = isset($latlngData[$i]) ? (float)$latlngData[$i][1] : null;
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
                'latitude' => $lat,
                'longitude' => $lng,
            ];
        }
        return $points;
    }

    private function enrichActivityWithStreamsAndLaps(array $activity, int $activityId, string $accessToken): array {
        $activity['strava_streams'] = $this->fetchActivityStreams($activityId, $accessToken, $activity['start_date_local'] ?? null);
        $laps = $this->normalizeActivityLaps($activity['laps'] ?? null);
        if ($laps === null) {
            $laps = $this->fetchActivityLaps($activityId, $accessToken);
        }
        if (!empty($laps)) {
            $activity['strava_laps'] = $laps;
        }
        return $activity;
    }

    private function fetchActivityLaps(int $activityId, string $accessToken): ?array {
        if ($activityId <= 0) {
            return null;
        }
        $url = 'https://www.strava.com/api/v3/activities/' . $activityId . '/laps';
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]));
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            return null;
        }
        $laps = json_decode($response, true);
        return $this->normalizeActivityLaps($laps);
    }

    private function normalizeActivityLaps($laps): ?array {
        if (!is_array($laps) || empty($laps)) {
            return null;
        }
        $normalized = [];
        $position = 1;
        foreach ($laps as $lap) {
            if (!is_array($lap)) {
                continue;
            }
            $distanceM = isset($lap['distance']) ? (float)$lap['distance'] : 0.0;
            $distanceKm = $distanceM > 0 ? round($distanceM / 1000, 3) : null;
            $elapsedSeconds = isset($lap['elapsed_time']) ? (int)$lap['elapsed_time'] : null;
            $movingSeconds = isset($lap['moving_time']) ? (int)$lap['moving_time'] : null;
            $paceSeconds = null;
            if ($distanceM > 0 && $movingSeconds !== null && $movingSeconds > 0) {
                $paceSeconds = (int)round($movingSeconds / ($distanceM / 1000));
            } elseif (isset($lap['average_speed']) && (float)$lap['average_speed'] > 0) {
                $paceSeconds = (int)round(1000 / (float)$lap['average_speed']);
            }
            $normalized[] = [
                'lap_index' => isset($lap['lap_index']) ? max(1, (int)$lap['lap_index']) : $position,
                'name' => isset($lap['name']) && trim((string)$lap['name']) !== '' ? trim((string)$lap['name']) : ('Lap ' . $position),
                'start_time' => !empty($lap['start_date_local'])
                    ? date('Y-m-d H:i:s', strtotime((string)$lap['start_date_local']))
                    : (!empty($lap['start_date']) ? date('Y-m-d H:i:s', strtotime((string)$lap['start_date'])) : null),
                'elapsed_seconds' => $elapsedSeconds !== null && $elapsedSeconds > 0 ? $elapsedSeconds : null,
                'moving_seconds' => $movingSeconds !== null && $movingSeconds > 0
                    ? $movingSeconds
                    : ($elapsedSeconds !== null && $elapsedSeconds > 0 ? $elapsedSeconds : null),
                'distance_km' => $distanceKm,
                'average_speed' => isset($lap['average_speed']) ? round((float)$lap['average_speed'], 3) : null,
                'max_speed' => isset($lap['max_speed']) ? round((float)$lap['max_speed'], 3) : null,
                'avg_pace' => $paceSeconds !== null ? sprintf('%d:%02d', (int)floor($paceSeconds / 60), (int)($paceSeconds % 60)) : null,
                'pace_seconds_per_km' => $paceSeconds,
                'avg_heart_rate' => isset($lap['average_heartrate']) ? (int)round((float)$lap['average_heartrate']) : null,
                'max_heart_rate' => isset($lap['max_heartrate']) ? (int)round((float)$lap['max_heartrate']) : null,
                'elevation_gain' => isset($lap['total_elevation_gain']) ? round((float)$lap['total_elevation_gain'], 2) : null,
                'cadence' => isset($lap['average_cadence']) ? (int)round((float)$lap['average_cadence']) : null,
                'start_index' => isset($lap['start_index']) ? (int)$lap['start_index'] : null,
                'end_index' => isset($lap['end_index']) ? (int)$lap['end_index'] : null,
            ];
            $position++;
        }
        if (empty($normalized)) {
            return null;
        }
        usort($normalized, static function (array $left, array $right): int {
            return ($left['lap_index'] ?? 0) <=> ($right['lap_index'] ?? 0);
        });
        return array_values($normalized);
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
                'laps' => $a['strava_laps'] ?? null,
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

    /**
     * Проверить и при необходимости восстановить webhook-подписку Strava для приложения.
     *
     * @return array{
     *   ok: bool,
     *   changed: bool,
     *   subscription_id: ?int,
     *   callback_url: ?string,
     *   deleted_ids: int[],
     *   error: ?string,
     *   http_code: ?int,
     *   response: ?string
     * }
     */
    public function ensureWebhookSubscription(): array {
        $result = [
            'ok' => false,
            'changed' => false,
            'subscription_id' => null,
            'callback_url' => null,
            'deleted_ids' => [],
            'error' => null,
            'http_code' => null,
            'response' => null,
        ];

        $callbackUrl = trim((string)((function_exists('env') ? env('STRAVA_WEBHOOK_CALLBACK_URL', '') : '') ?: ''));
        $verifyToken = trim((string)((function_exists('env') ? env('STRAVA_WEBHOOK_VERIFY_TOKEN', 'planrun_verify') : '') ?: 'planrun_verify'));

        if ($this->clientId === '' || $this->clientSecret === '' || $callbackUrl === '') {
            $result['error'] = 'STRAVA_CLIENT_ID, STRAVA_CLIENT_SECRET and STRAVA_WEBHOOK_CALLBACK_URL are required';
            return $result;
        }

        $result['callback_url'] = $callbackUrl;

        $listResult = $this->listWebhookSubscriptions();
        if (!$listResult['ok']) {
            $result['error'] = $listResult['error'] ?? 'Unable to list webhook subscriptions';
            $result['http_code'] = $listResult['http_code'] ?? null;
            $result['response'] = $listResult['response'] ?? null;
            return $result;
        }

        $subscriptions = $listResult['subscriptions'];
        foreach ($subscriptions as $subscription) {
            $subscriptionId = isset($subscription['id']) ? (int)$subscription['id'] : 0;
            $subscriptionCallback = trim((string)($subscription['callback_url'] ?? ''));
            if ($subscriptionId > 0 && $subscriptionCallback === $callbackUrl) {
                $result['ok'] = true;
                $result['subscription_id'] = $subscriptionId;
                return $result;
            }
        }

        foreach ($subscriptions as $subscription) {
            $subscriptionId = isset($subscription['id']) ? (int)$subscription['id'] : 0;
            if ($subscriptionId <= 0) {
                continue;
            }
            $deleteResult = $this->deleteWebhookSubscription($subscriptionId);
            if (!$deleteResult['ok']) {
                $result['error'] = $deleteResult['error'];
                $result['http_code'] = $deleteResult['http_code'];
                $result['response'] = $deleteResult['response'];
                return $result;
            }
            $result['deleted_ids'][] = $subscriptionId;
        }

        $createResult = $this->createWebhookSubscription($callbackUrl, $verifyToken);
        if (!$createResult['ok']) {
            $result['error'] = $createResult['error'];
            $result['http_code'] = $createResult['http_code'];
            $result['response'] = $createResult['response'];
            return $result;
        }

        $result['ok'] = true;
        $result['changed'] = true;
        $result['subscription_id'] = $createResult['subscription_id'];
        return $result;
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

    private function listWebhookSubscriptions(): array {
        $url = 'https://www.strava.com/api/v3/push_subscriptions?' . http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $apiResult = $this->callWebhookApi($url);
        if (!$apiResult['ok']) {
            return $apiResult;
        }

        $subscriptions = json_decode($apiResult['response'], true);
        if (!is_array($subscriptions)) {
            return [
                'ok' => false,
                'subscriptions' => [],
                'error' => 'Invalid JSON while listing push subscriptions',
                'http_code' => $apiResult['http_code'],
                'response' => $apiResult['response'],
            ];
        }

        return [
            'ok' => true,
            'subscriptions' => $subscriptions,
            'error' => null,
            'http_code' => $apiResult['http_code'],
            'response' => $apiResult['response'],
        ];
    }

    private function deleteWebhookSubscription(int $subscriptionId): array {
        $url = 'https://www.strava.com/api/v3/push_subscriptions/' . $subscriptionId . '?' . http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $apiResult = $this->callWebhookApi($url, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
        ]);

        if (!$apiResult['ok'] && (int)($apiResult['http_code'] ?? 0) !== 204) {
            return $apiResult;
        }

        return [
            'ok' => true,
            'error' => null,
            'http_code' => $apiResult['http_code'],
            'response' => $apiResult['response'],
        ];
    }

    private function createWebhookSubscription(string $callbackUrl, string $verifyToken): array {
        $apiResult = $this->callWebhookApi('https://www.strava.com/api/v3/push_subscriptions', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ], [200, 201]);

        if (!$apiResult['ok']) {
            return $apiResult;
        }

        $data = json_decode($apiResult['response'], true);
        $subscriptionId = isset($data['id']) ? (int)$data['id'] : 0;
        if ($subscriptionId <= 0) {
            return [
                'ok' => false,
                'subscription_id' => null,
                'error' => 'Push subscription created but response does not contain id',
                'http_code' => $apiResult['http_code'],
                'response' => $apiResult['response'],
            ];
        }

        return [
            'ok' => true,
            'subscription_id' => $subscriptionId,
            'error' => null,
            'http_code' => $apiResult['http_code'],
            'response' => $apiResult['response'],
        ];
    }

    private function callWebhookApi(string $url, array $extraOpts = [], array $successHttpCodes = [200, 204]): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->getCurlOpts($extraOpts));
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return [
                'ok' => false,
                'error' => $curlErr,
                'http_code' => $httpCode,
                'response' => $response ?: null,
            ];
        }

        if (!in_array($httpCode, $successHttpCodes, true)) {
            $error = null;
            $data = json_decode($response ?: '', true);
            if (is_array($data)) {
                $error = $data['message'] ?? ($data['errors'][0]['message'] ?? null);
            }
            if ($error === null) {
                $error = 'Strava webhook API request failed';
            }
            return [
                'ok' => false,
                'error' => $error,
                'http_code' => $httpCode,
                'response' => $response ?: null,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'http_code' => $httpCode,
            'response' => $response ?: null,
        ];
    }
}
