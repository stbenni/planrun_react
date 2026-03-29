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
            // Явный scope из документации AccessLink (иначе часть клиентов в админке ломается на экране согласия)
            'scope' => 'accesslink.read_all',
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
        if ($httpCode !== 200 || !is_array($data) || !isset($data['access_token'])) {
            $msg = 'Ошибка получения токена Polar';
            if ($httpCode) {
                $msg .= " (HTTP $httpCode)";
            }
            if (is_array($data) && !empty($data['error'])) {
                $err = (string) $data['error'];
                $desc = isset($data['error_description']) ? trim((string) $data['error_description']) : '';
                $msg = $desc !== '' ? ($err . ': ' . $desc) : $err;
            } elseif (is_string($response) && $response !== '' && strlen($response) < 500) {
                $msg .= ': ' . $response;
            }
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
        $latBySec = [];
        $lngBySec = [];
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
            if (isset($r['latitude']) && isset($r['longitude'])) {
                $latBySec[$sec] = (float)$r['latitude'];
                $lngBySec[$sec] = (float)$r['longitude'];
            }
        }
        $allSec = array_unique(array_merge(array_keys($hrBySec), array_keys($altBySec), array_keys($latBySec)));
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
                'latitude' => $latBySec[$sec] ?? null,
                'longitude' => $lngBySec[$sec] ?? null,
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

    // --- Partner webhook (AccessLink Basic auth: client_id + client_secret) ---

    private function getWebhookSecretStoragePath(): string {
        return dirname(__DIR__) . '/storage/polar_webhook_secret.txt';
    }

    /**
     * Секрет подписи webhook (из ответа create). Сначала .env, затем файл storage (после первого create).
     */
    public function loadWebhookSignatureSecret(): string {
        $fromEnv = trim((string)((function_exists('env') ? env('POLAR_WEBHOOK_SIGNATURE_SECRET', '') : '') ?: ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        $path = $this->getWebhookSecretStoragePath();
        if (is_readable($path)) {
            $v = trim((string)file_get_contents($path));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    public function saveWebhookSignatureSecret(string $secret): bool {
        $secret = trim($secret);
        if ($secret === '') {
            return false;
        }
        $dir = dirname($this->getWebhookSecretStoragePath());
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true)) {
                return false;
            }
        }
        $path = $this->getWebhookSecretStoragePath();
        return @file_put_contents($path, $secret, LOCK_EX) !== false;
    }

    /**
     * Проверка Polar-Webhook-Signature (HMAC-SHA256 тела, hex в заголовке).
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHex): bool {
        $secret = $this->loadWebhookSignatureSecret();
        if ($secret === '' || $signatureHex === null || $signatureHex === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $signatureHex = strtolower(preg_replace('/\s+/', '', $signatureHex));
        return hash_equals($expected, $signatureHex);
    }

    /**
     * @return array{ok:bool,httpCode:int,body:?string,error:?string}
     */
    private function partnerApiRequest(string $method, string $path, ?string $jsonBody = null): array {
        $out = ['ok' => false, 'httpCode' => 0, 'body' => null, 'error' => null];
        if ($this->clientId === '' || $this->clientSecret === '') {
            $out['error'] = 'POLAR_CLIENT_ID / POLAR_CLIENT_SECRET required';
            return $out;
        }
        $url = $this->baseUrl . $path;
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
        ];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $jsonBody;
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $out['httpCode'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            $out['error'] = $err;
            return $out;
        }
        $out['body'] = $body === false ? null : (string)$body;
        $out['ok'] = $out['httpCode'] >= 200 && $out['httpCode'] < 300;
        return $out;
    }

    /**
     * @return ?array{id?:string,url?:string,events?:mixed}
     */
    public function getPartnerWebhookData(): ?array {
        $resp = $this->partnerApiRequest('GET', '/v3/webhooks');
        if ($resp['httpCode'] === 404 || $resp['httpCode'] === 204) {
            return null;
        }
        if ($resp['httpCode'] !== 200 || $resp['body'] === null || $resp['body'] === '') {
            return null;
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j)) {
            return null;
        }
        $data = $j['data'] ?? $j;
        if (!is_array($data)) {
            return null;
        }
        // Polar может вернуть один объект или массив объектов
        if (isset($data['url'])) {
            return $data;
        }
        if (isset($data[0]) && is_array($data[0]) && isset($data[0]['url'])) {
            return $data[0];
        }
        return null;
    }

    public function deletePartnerWebhook(string $webhookId): bool {
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            return false;
        }
        $path = '/v3/webhooks/' . rawurlencode($webhookId);
        $resp = $this->partnerApiRequest('DELETE', $path);
        return $resp['httpCode'] === 200 || $resp['httpCode'] === 204;
    }

    /**
     * @return array{ok:bool,id?:string,signature_secret_key?:string,error?:string,httpCode?:int,body?:?string}
     */
    public function createPartnerWebhook(string $callbackUrl): array {
        $payload = json_encode([
            'url' => $callbackUrl,
            'events' => ['EXERCISE'],
        ]);
        $resp = $this->partnerApiRequest('POST', '/v3/webhooks', $payload);
        $result = ['ok' => false, 'httpCode' => $resp['httpCode'], 'body' => $resp['body']];
        if (!$resp['ok']) {
            $result['error'] = $resp['error'] ?? ('HTTP ' . $resp['httpCode']);
            if (is_string($resp['body']) && strlen($resp['body']) < 400) {
                $result['error'] .= ': ' . $resp['body'];
            }
            return $result;
        }
        $j = json_decode((string)$resp['body'], true);
        $data = is_array($j) && isset($j['data']) && is_array($j['data']) ? $j['data'] : (is_array($j) ? $j : []);
        $result['ok'] = true;
        $result['id'] = isset($data['id']) ? (string)$data['id'] : null;
        $result['signature_secret_key'] = isset($data['signature_secret_key']) ? (string)$data['signature_secret_key'] : '';
        return $result;
    }

    /**
     * Нормализует список событий webhook в массив строк.
     */
    private static function normalizeWebhookEvents($events): array {
        if ($events === null) {
            return [];
        }
        if (is_string($events)) {
            return [strtoupper($events)];
        }
        if (!is_array($events)) {
            return [];
        }
        $flat = [];
        foreach ($events as $e) {
            if (is_string($e)) {
                $flat[] = strtoupper($e);
            }
        }
        return $flat;
    }

    /**
     * Создать/восстановить webhook EXERCISE на POLAR_WEBHOOK_CALLBACK_URL (один на приложение AccessLink).
     *
     * @return array{ok:bool,changed?:bool,webhook_id?:?string,error?:string,signature_saved?:bool}
     */
    public function ensureWebhookSubscription(): array {
        $callbackUrl = trim((string)((function_exists('env') ? env('POLAR_WEBHOOK_CALLBACK_URL', '') : '') ?: ''));
        if ($callbackUrl === '') {
            return ['ok' => false, 'error' => 'POLAR_WEBHOOK_CALLBACK_URL is required for Polar webhooks'];
        }

        $existing = $this->getPartnerWebhookData();
        if (is_array($existing) && !empty($existing['url'])) {
            $url = trim((string)$existing['url']);
            $ev = self::normalizeWebhookEvents($existing['events'] ?? []);
            if ($url === $callbackUrl && in_array('EXERCISE', $ev, true)) {
                return ['ok' => true, 'changed' => false, 'webhook_id' => isset($existing['id']) ? (string)$existing['id'] : null];
            }
            $wid = isset($existing['id']) ? (string)$existing['id'] : '';
            if ($wid !== '' && !$this->deletePartnerWebhook($wid)) {
                return ['ok' => false, 'error' => 'Failed to delete existing Polar webhook before recreate'];
            }
        }

        $created = $this->createPartnerWebhook($callbackUrl);
        if (!$created['ok']) {
            $http = (int)($created['httpCode'] ?? 0);
            $body = (string)($created['body'] ?? '');
            if ($http === 409 || stripos($body, 'WebhookExistException') !== false || stripos($body, 'already exists') !== false) {
                // Webhook уже зарегистрирован (GET мог не распарситься или гонка)
                return ['ok' => true, 'changed' => false, 'webhook_id' => null];
            }
            return ['ok' => false, 'error' => $created['error'] ?? 'create failed'];
        }
        $secret = $created['signature_secret_key'] ?? '';
        $saved = false;
        if ($secret !== '') {
            $saved = $this->saveWebhookSignatureSecret($secret);
        }
        return [
            'ok' => true,
            'changed' => true,
            'webhook_id' => $created['id'] ?? null,
            'signature_saved' => $saved,
        ];
    }

    /**
     * Загрузить одну тренировку по URL из webhook (GET с токеном пользователя).
     */
    public function fetchSingleExerciseByUrl(int $userId, string $exerciseUrl): ?array {
        $row = $this->getTokenRow($userId);
        if (!$row || empty($row['access_token'])) {
            return null;
        }
        $url = trim($exerciseUrl);
        if ($url === '') {
            return null;
        }
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'route=true&samples=true';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $row['access_token'],
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !is_string($response)) {
            return null;
        }
        $ex = json_decode($response, true);
        if (!is_array($ex)) {
            return null;
        }
        return $this->mapExerciseToWorkout($ex);
    }
}
