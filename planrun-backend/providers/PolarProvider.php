<?php
/**
 * Провайдер Polar AccessLink API v3
 * OAuth 2.0, exercises endpoint
 * Регистрация: https://admin.polaraccesslink.com
 */
require_once __DIR__ . '/WorkoutImportProvider.php';

class PolarProvider implements WorkoutImportProvider {
    private $db;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $baseUrl = 'https://www.polaraccesslink.com';

    public function __construct($db) {
        $this->db = $db;
        $this->clientId = (function_exists('env') ? env('POLAR_CLIENT_ID', '') : '') ?: '';
        $this->clientSecret = (function_exists('env') ? env('POLAR_CLIENT_SECRET', '') : '') ?: '';
        $this->redirectUri = (function_exists('env') ? env('POLAR_REDIRECT_URI', '') : '') ?: '';
    }

    public function getProviderId(): string {
        return 'polar';
    }

    public function getOAuthUrl(string $state): ?string {
        if (!$this->clientId || !$this->redirectUri) {
            return null;
        }
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ];
        return 'https://flow.polar.com/oauth2/authorization?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $state): array {
        $userId = getCurrentUserId();
        if (!$userId) {
            throw new Exception('Требуется авторизация');
        }
        $auth = base64_encode($this->clientId . ':' . $this->clientSecret);
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);
        $ch = curl_init('https://polarremote.com/v2/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json;charset=UTF-8',
                'Authorization: Basic ' . $auth,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            $msg = $data['error'] ?? 'Ошибка получения токена Polar';
            throw new Exception($msg);
        }
        $accessToken = $data['access_token'];
        $polarUserId = isset($data['x_user_id']) ? (string)$data['x_user_id'] : null;
        $this->registerUser($userId, $accessToken);
        $this->saveTokens($userId, $accessToken, $polarUserId);
        return [
            'access_token' => $accessToken,
            'refresh_token' => null,
            'expires_at' => null,
        ];
    }

    private function registerUser(int $userId, string $accessToken): void {
        $payload = json_encode(['member-id' => (string)$userId]);
        $ch = curl_init($this->baseUrl . '/v3/users');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 409) {
            return;
        }
    }

    public function refreshToken(int $userId): bool {
        return $this->isConnected($userId);
    }

    public function fetchWorkouts(int $userId, string $startDate, string $endDate): array {
        $row = $this->getTokenRow($userId);
        if (!$row) {
            return [];
        }
        $url = $this->baseUrl . '/v3/exercises?route=true&samples=true';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $row['access_token'],
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            return [];
        }
        $exercises = json_decode($response, true);
        if (!is_array($exercises)) {
            return [];
        }
        $startTs = strtotime($startDate . ' 00:00:00');
        $endTs = strtotime($endDate . ' 23:59:59');
        $workouts = [];
        foreach ($exercises as $ex) {
            $startTime = $ex['start_time'] ?? null;
            if (!$startTime) continue;
            $exTs = strtotime($startTime);
            if ($exTs < $startTs || $exTs > $endTs) continue;
            $workouts[] = $this->mapExerciseToWorkout($ex);
        }
        return $workouts;
    }

    private function mapExerciseToWorkout(array $ex): array {
        $startTime = $ex['start_time'] ?? null;
        $durationIso = $ex['duration'] ?? 'PT0M';
        $durationSec = $this->parseIsoDuration($durationIso);
        $durationMinutes = $durationSec > 0 ? (int)round($durationSec / 60) : null;
        $startTs = $startTime ? strtotime($startTime) : 0;
        $endTime = $durationSec > 0 ? date('Y-m-d H:i:s', $startTs + $durationSec) : date('Y-m-d H:i:s', $startTs);
        $startTimeStr = date('Y-m-d H:i:s', $startTs);
        $distanceM = (float)($ex['distance'] ?? 0);
        $distanceKm = $distanceM > 0 ? round($distanceM / 1000, 3) : null;
        $sport = $ex['sport'] ?? 'OTHER';
        $activityType = $this->mapSportType($sport);
        $avgPace = ($distanceKm > 0 && $durationMinutes > 0 && in_array($activityType, ['running', 'walking', 'hiking']))
            ? $this->paceFromKmAndMinutes($distanceKm, $durationMinutes) : null;
        $hr = $ex['heart_rate'] ?? [];
        $avgHeartRate = isset($hr['average']) ? (int)$hr['average'] : null;
        $maxHeartRate = isset($hr['maximum']) ? (int)$hr['maximum'] : null;
        $elevationGain = null;
        $route = $ex['route'] ?? [];
        if (!empty($route)) {
            $altitudes = [];
            foreach ($route as $p) {
                if (isset($p['altitude'])) {
                    $altitudes[] = (float)$p['altitude'];
                }
            }
            if (count($altitudes) > 1) {
                $gain = 0;
                for ($i = 1; $i < count($altitudes); $i++) {
                    if ($altitudes[$i] > $altitudes[$i - 1]) {
                        $gain += (int)round($altitudes[$i] - $altitudes[$i - 1]);
                    }
                }
                $elevationGain = $gain > 0 ? $gain : null;
            }
        }
        $timeline = $this->buildTimeline($ex, $startTs);
        return [
            'activity_type' => $activityType,
            'start_time' => $startTimeStr,
            'end_time' => $endTime,
            'duration_minutes' => $durationMinutes,
            'duration_seconds' => $durationSec > 0 ? $durationSec : null,
            'distance_km' => $distanceKm,
            'avg_pace' => $avgPace,
            'avg_heart_rate' => $avgHeartRate,
            'max_heart_rate' => $maxHeartRate,
            'elevation_gain' => $elevationGain,
            'external_id' => 'polar_' . ($ex['id'] ?? $startTimeStr . '-' . ($distanceKm ?? 0)),
            'timeline' => $timeline,
        ];
    }

    private function parseIsoDuration(string $iso): int {
        $sec = 0;
        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?/i', $iso, $m)) {
            $sec = ((int)($m[1] ?? 0)) * 3600 + ((int)($m[2] ?? 0)) * 60 + (int)floor((float)($m[3] ?? 0));
        }
        return $sec;
    }

    private function mapSportType(string $sport): string {
        $s = strtolower($sport);
        if (strpos($s, 'run') !== false) return 'running';
        if (strpos($s, 'cycl') !== false || strpos($s, 'bike') !== false) return 'cycling';
        if (strpos($s, 'swim') !== false) return 'swimming';
        if (strpos($s, 'walk') !== false) return 'walking';
        if (strpos($s, 'hike') !== false) return 'hiking';
        return 'running';
    }

    private function paceFromKmAndMinutes(float $km, int $minutes): string {
        if ($km <= 0) return null;
        $paceMinPerKm = $minutes / $km;
        $m = (int)floor($paceMinPerKm);
        $s = (int)round(($paceMinPerKm - $m) * 60);
        if ($s >= 60) { $s = 0; $m++; }
        return sprintf('%d:%02d', $m, $s);
    }

    private function buildTimeline(array $ex, int $startTs): ?array {
        $samples = $ex['samples'] ?? [];
        $route = $ex['route'] ?? [];
        $hrBySec = [];
        $altBySec = [];
        foreach ($samples as $s) {
            $rate = (int)($s['recording-rate'] ?? 1);
            $type = (string)($s['sample-type'] ?? '');
            $data = isset($s['data']) ? explode(',', $s['data']) : [];
            if ($type === '1' && $rate > 0) {
                foreach ($data as $i => $v) {
                    $hrBySec[$i * $rate] = (int)trim($v);
                }
            }
        }
        foreach ($route as $r) {
            $timeStr = $r['time'] ?? '';
            $sec = $this->parseIsoDuration(strpos($timeStr, 'PT') === 0 ? $timeStr : 'PT' . $timeStr);
            if (isset($r['altitude'])) {
                $altBySec[$sec] = (float)$r['altitude'];
            }
        }
        $allSec = array_unique(array_merge(array_keys($hrBySec), array_keys($altBySec)));
        if (empty($allSec)) return null;
        sort($allSec);
        $total = count($allSec);
        $step = max(1, (int)floor($total / 500));
        $indices = [];
        for ($i = 0; $i < $total; $i += $step) {
            $indices[] = $i;
        }
        if ($total > 1 && end($indices) !== $total - 1) {
            $indices[] = $total - 1;
        }
        $points = [];
        foreach ($indices as $i) {
            $sec = $allSec[$i];
            $points[] = [
                'timestamp' => date('Y-m-d H:i:s', $startTs + $sec),
                'heart_rate' => $hrBySec[$sec] ?? null,
                'pace' => null,
                'altitude' => $altBySec[$sec] ?? null,
                'distance' => null,
                'cadence' => null,
            ];
        }
        return $points;
    }

    public function isConnected(int $userId): bool {
        return $this->getTokenRow($userId) !== null;
    }

    public function disconnect(int $userId): void {
        $row = $this->getTokenRow($userId);
        if ($row && !empty($row['external_athlete_id'])) {
            $ch = curl_init($this->baseUrl . '/v3/users/' . $row['external_athlete_id']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $row['access_token']],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
        $stmt = $this->db->prepare("DELETE FROM integration_tokens WHERE user_id = ? AND provider = ?");
        $stmt->bind_param("is", $userId, $this->getProviderId());
        $stmt->execute();
        $stmt->close();
    }

    private function getTokenRow(int $userId): ?array {
        $stmt = $this->db->prepare("SELECT access_token, external_athlete_id FROM integration_tokens WHERE user_id = ? AND provider = ?");
        $stmt->bind_param("is", $userId, $this->getProviderId());
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    private function saveTokens(int $userId, string $accessToken, ?string $polarUserId): void {
        $stmt = $this->db->prepare("
            INSERT INTO integration_tokens (user_id, provider, external_athlete_id, access_token, refresh_token, expires_at)
            VALUES (?, ?, ?, ?, NULL, NULL)
            ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), external_athlete_id = COALESCE(VALUES(external_athlete_id), external_athlete_id), updated_at = NOW()
        ");
        $stmt->bind_param("isss", $userId, $this->getProviderId(), $polarUserId, $accessToken);
        $stmt->execute();
        $stmt->close();
    }
}
