<?php
/**
 * Suunto Cloud API (apizone.suunto.com) — OAuth 2.0 и импорт тренировок.
 *
 * Особенности Suunto (отличия от COROS/Strava):
 *  - OAuth: авторизация https://cloudapi-oauth.suunto.com/oauth/authorize,
 *    токен https://cloudapi-oauth.suunto.com/oauth/token, обмен через Basic auth
 *    (client_id:client_secret). access_token — JWT (срок ~24ч), в нём claim "user"
 *    = имя аккаунта Suunto App (используем как external_athlete_id).
 *  - REST API: база https://cloudapi.suunto.com. Заголовки запроса:
 *      Authorization: <JWT>            (БЕЗ префикса "Bearer"!)
 *      Ocp-Apim-Subscription-Key: <subscription_key>   (ключ приложения, из .env)
 *  - Список тренировок: GET /v2/workouts?since=<ms>&until=<ms>
 *    Одна тренировка: GET /v2/workout/{workoutKey}
 *
 * activityId — числовой код вида спорта Suunto App (столбец «Sport id» из Activities.pdf:
 * https://aspartnercontent.blob.core.windows.net/apizone/docs/Activities.pdf). Базовая таблица
 * id->тип зашита в loadActivityMap(); точечно переопределяется через SUUNTO_ACTIVITY_MAP_JSON,
 * неизвестные id -> SUUNTO_ACTIVITY_DEFAULT (по умолчанию 'other' = ОФП).
 */
require_once __DIR__ . '/WorkoutImportProvider.php';

class SuuntoProvider implements WorkoutImportProvider {
    private $db;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $subscriptionKey;
    private $authUrl;
    private $tokenUrl;
    private $apiBase;
    private $scopes;
    private $activityDefault;
    /** @var array<string,string> id => наш тип активности */
    private $activityMap;

    public function __construct($db) {
        $this->db = $db;
        $this->clientId = (string)$this->env('SUUNTO_CLIENT_ID', '');
        $this->clientSecret = (string)$this->env('SUUNTO_CLIENT_SECRET', '');
        $this->redirectUri = (string)$this->env('SUUNTO_REDIRECT_URI', '');
        $this->subscriptionKey = (string)$this->env('SUUNTO_SUBSCRIPTION_KEY', '');
        $this->authUrl = (string)($this->env('SUUNTO_OAUTH_AUTH_URL', '') ?: 'https://cloudapi-oauth.suunto.com/oauth/authorize');
        $this->tokenUrl = (string)($this->env('SUUNTO_OAUTH_TOKEN_URL', '') ?: 'https://cloudapi-oauth.suunto.com/oauth/token');
        $this->apiBase = rtrim((string)($this->env('SUUNTO_API_BASE', '') ?: 'https://cloudapi.suunto.com'), '/');
        $this->scopes = trim((string)$this->env('SUUNTO_OAUTH_SCOPES', ''));
        $this->activityDefault = trim((string)($this->env('SUUNTO_ACTIVITY_DEFAULT', '') ?: 'other'));
        $this->activityMap = $this->loadActivityMap();
    }

    private function env(string $key, string $default): string {
        $v = function_exists('env') ? env($key, $default) : $default;
        return $v === null ? $default : (string)$v;
    }

    public function getProviderId(): string {
        return 'suunto';
    }

    /**
     * Таблица activityId (= Suunto App Sport id, столбец «Sport id» в Activities.pdf) -> наш тип.
     * Базовая карта зашита из официального Activities.pdf; SUUNTO_ACTIVITY_MAP_JSON точечно
     * переопределяет записи. Всё, чего нет в карте, попадает в SUUNTO_ACTIVITY_DEFAULT
     * ('other' = ОФП) — чтобы не засорять беговую статистику чужими видами спорта.
     * @return array<string,string>
     */
    private function loadActivityMap(): array {
        // Только endurance-виды, которые трекаем явно; остальное -> default ('other').
        $map = [
            '0' => 'walking',   // Walking
            '1' => 'running',   // Running
            '2' => 'cycling',   // Cycling
            '10' => 'cycling',  // Mountain biking
            '11' => 'hiking',   // Hiking
            '21' => 'swimming', // Swimming
            '22' => 'running',  // Trail running
            '24' => 'walking',  // Nordic walking
            '52' => 'cycling',  // Indoor cycling
            '53' => 'running',  // Treadmill
            '59' => 'running',  // Track and field
            '60' => 'running',  // Orienteering
            '70' => 'hiking',   // Trekking
            '85' => 'swimming', // Open water swimming
            '90' => 'swimming', // Snorkeling
            '99' => 'cycling',  // Gravel cycling
            '103' => 'running', // Track running
            '105' => 'cycling', // E-biking
            '106' => 'cycling', // E-mtb
            '109' => 'cycling', // Hand cycling
            '114' => 'cycling', // Cyclocross
            '115' => 'running', // Vertical running
        ];
        $json = $this->env('SUUNTO_ACTIVITY_MAP_JSON', '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $type) {
                    $map[(string)$id] = (string)$type;
                }
            }
        }
        return $map;
    }

    public function getOAuthUrl(string $state): ?string {
        if ($this->clientId === '' || $this->redirectUri === '') {
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
        $sep = (strpos($this->authUrl, '?') !== false) ? '&' : '?';
        return $this->authUrl . $sep . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code, string $state): array {
        $userId = getCurrentUserId();
        if (!$userId) {
            throw new Exception('Требуется авторизация');
        }
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new Exception('SUUNTO_CLIENT_ID/SUUNTO_CLIENT_SECRET не заданы в .env');
        }
        $data = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);
        if (!is_array($data) || empty($data['access_token'])) {
            $msg = 'Ошибка получения токена Suunto';
            if (is_array($data)) {
                $msg = (string)($data['error_description'] ?? $data['message'] ?? $data['error'] ?? $msg);
            }
            throw new Exception($msg);
        }
        $access = (string)$data['access_token'];
        $refresh = isset($data['refresh_token']) ? (string)$data['refresh_token'] : null;
        $expiresAt = !empty($data['expires_in']) ? date('Y-m-d H:i:s', time() + (int)$data['expires_in']) : null;
        $username = $this->extractUsername($access, $data);
        $this->saveTokens($userId, $access, $refresh, $expiresAt, $username);
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => $expiresAt,
        ];
    }

    public function refreshToken(int $userId): bool {
        $row = $this->getTokenRow($userId);
        if (!$row || empty($row['refresh_token'])) {
            return false;
        }
        $data = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
        ]);
        if (!is_array($data) || empty($data['access_token'])) {
            return false;
        }
        $access = (string)$data['access_token'];
        $refresh = isset($data['refresh_token']) ? (string)$data['refresh_token'] : $row['refresh_token'];
        $expiresAt = !empty($data['expires_in']) ? date('Y-m-d H:i:s', time() + (int)$data['expires_in']) : null;
        $username = $this->extractUsername($access, $data) ?? $row['external_athlete_id'];
        $this->saveTokens($userId, $access, $refresh, $expiresAt, $username);
        return true;
    }

    /**
     * POST на token endpoint с Basic-аутентификацией приложения.
     * @param array<string,string> $body
     * @return array<string,mixed>|null
     */
    private function tokenRequest(array $body): ?array {
        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || !is_array($data)) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Suunto token request failed', ['http' => $httpCode, 'snippet' => substr((string)$response, 0, 200)]);
            return is_array($data) ? $data : null;
        }
        return $data;
    }

    /**
     * Имя пользователя Suunto: claim "user" из JWT access_token (либо поле в ответе).
     * @param array<string,mixed> $tokenResponse
     */
    private function extractUsername(string $accessToken, array $tokenResponse): ?string {
        foreach (['user', 'username', 'sub'] as $k) {
            if (!empty($tokenResponse[$k])) {
                return (string)$tokenResponse[$k];
            }
        }
        $claims = $this->decodeJwtPayload($accessToken);
        if (is_array($claims)) {
            foreach (['user', 'username', 'sub'] as $k) {
                if (!empty($claims[$k])) {
                    return (string)$claims[$k];
                }
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJwtPayload(string $jwt): ?array {
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
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function fetchWorkouts(int $userId, string $startDate, string $endDate): array {
        if ($this->subscriptionKey === '') {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Suunto fetch skipped: SUUNTO_SUBSCRIPTION_KEY empty');
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
        $sinceMs = (int)(strtotime($startDate . ' 00:00:00 UTC') * 1000);
        $untilMs = (int)((strtotime($endDate . ' 23:59:59 UTC')) * 1000);
        $url = $this->apiBase . '/v2/workouts?' . http_build_query([
            'since' => $sinceMs,
            'until' => $untilMs,
            'limit' => 200,
        ]);
        $json = $this->apiGet($url, (string)$row['access_token']);
        if ($json === null) {
            return [];
        }
        $list = $this->extractWorkoutList($json);
        // Сколько тренировок за синхру дотягивать полным треком (FIT); остальное — сводка без таймлайна.
        $fitLimit = (int)($this->env('SUUNTO_SYNC_FIT_LIMIT', '') ?: '25');
        $enriched = 0;
        $workouts = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string)($item['workoutKey'] ?? '');
            $full = null;
            if ($key !== '' && $enriched < $fitLimit) {
                $full = $this->fetchWorkoutFit($userId, $key, $item); // GPS + пульс + таймлайн из FIT
                if ($full !== null) {
                    $enriched++;
                }
            }
            $w = $full ?? $this->mapSuuntoWorkout($item);
            if ($w !== null) {
                $workouts[] = $w;
            }
        }
        return $workouts;
    }

    /**
     * GET одной тренировки по workoutKey — используется webhook'ом как фолбэк.
     * @return array<string,mixed>|null канонический формат тренировки
     */
    public function fetchWorkoutByKey(int $userId, string $workoutKey): ?array {
        if ($this->subscriptionKey === '' || $workoutKey === '') {
            return null;
        }
        $row = $this->getTokenRow($userId);
        if (!$row) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time() + 60) {
            $this->refreshToken($userId);
            $row = $this->getTokenRow($userId) ?? $row;
        }
        // Эндпоинта одиночной тренировки /v2/workout/{key} нет (APIM → OperationNotFound/401).
        // Берём список /v2/workouts (работает) и матчим по workoutKey — новая тренировка в начале.
        $url = $this->apiBase . '/v2/workouts?limit=30';
        $json = $this->apiGet($url, (string)$row['access_token']);
        $items = is_array($json) ? ($json['payload'] ?? null) : null;
        if (!is_array($items)) {
            return null;
        }
        foreach ($items as $item) {
            if (is_array($item) && (string)($item['workoutKey'] ?? '') === $workoutKey) {
                return $this->mapSuuntoWorkout($item);
            }
        }
        return null;
    }

    /**
     * Полные данные тренировки через FIT-экспорт: GPS-трек + пульс-стрим + таймлайн + круги.
     * Качаем GET /v2/workout/exportFit/{key} и парсим существующим FitParser (источник 'fit').
     * Детальный поток сэмплов Suunto отдаёт ТОЛЬКО в FIT — в JSON только сводка.
     * @param array<string,mixed> $summary элемент сводки (webhook/список) — для activityId/avgPace/даты-фолбэка
     * @return array<string,mixed>|null канонический формат с timeline+laps, либо null при ошибке
     */
    public function fetchWorkoutFit(int $userId, string $workoutKey, array $summary = []): ?array {
        if ($this->subscriptionKey === '' || $workoutKey === '') {
            return null;
        }
        $row = $this->getTokenRow($userId);
        if (!$row) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time() + 60) {
            $this->refreshToken($userId);
            $row = $this->getTokenRow($userId) ?? $row;
        }
        $url = $this->apiBase . '/v2/workout/exportFit/' . rawurlencode($workoutKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $row['access_token'],
                'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // Сигнатура FIT: байты 8..11 == ".FIT"
        if ($code !== 200 || !is_string($body) || strlen($body) < 16 || substr($body, 8, 4) !== '.FIT') {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Suunto FIT export failed', ['http' => $code, 'key' => $workoutKey, 'bytes' => strlen((string)$body)]);
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'suunto_') . '.fit';
        if (@file_put_contents($tmp, $body) === false) {
            return null;
        }
        $parsed = null;
        try {
            require_once __DIR__ . '/../utils/FitParser.php';
            $date = (isset($summary['startTime']) && is_numeric($summary['startTime']))
                ? gmdate('Y-m-d', (int)round((float)$summary['startTime'] / 1000)) : null;
            $parsed = FitParser::parse($tmp, $date);
        } catch (\Throwable $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Suunto FIT parse failed', ['key' => $workoutKey, 'msg' => $e->getMessage()]);
            $parsed = null;
        } finally {
            @unlink($tmp);
        }
        if (!is_array($parsed) || empty($parsed['start_time'])) {
            return null;
        }
        // Suunto-специфика поверх результата FIT: стабильный external_id, наш тип спорта, темп с часов.
        $parsed['external_id'] = 'suunto_' . $workoutKey;
        if (isset($summary['activityId'])) {
            $parsed['activity_type'] = $this->mapActivityId($summary['activityId']);
        }
        if (in_array($parsed['activity_type'], ['running', 'walking', 'hiking'], true)) {
            $sumPace = $this->paceFromSpeedFields($summary);
            if ($sumPace !== null) {
                $parsed['avg_pace'] = $sumPace;
            }
        }
        return $parsed;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function apiGet(string $url, string $accessToken): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: ' . $accessToken,
                'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !is_string($response)) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Suunto API GET failed', ['http' => $httpCode, 'url' => $url, 'snippet' => substr((string)$response, 0, 200)]);
            return null;
        }
        $json = json_decode($response, true);
        return is_array($json) ? $json : null;
    }

    /**
     * @param mixed $json
     * @return array<int, mixed>
     */
    private function extractWorkoutList($json): array {
        if (!is_array($json)) {
            return [];
        }
        foreach (['payload', 'data', 'workouts', 'items', 'results'] as $k) {
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
     * Маппинг тренировки Suunto (формат из webhook "workout" и из /v2/workouts) в канонический.
     * @param array<string,mixed> $w
     * @return array<string,mixed>|null
     */
    public function mapSuuntoWorkout(array $w): ?array {
        $key = $w['workoutKey'] ?? $w['workoutId'] ?? $w['id'] ?? null;
        // startTime в epoch-миллисекундах (UTC)
        $startMs = $w['startTime'] ?? $w['startTimeMs'] ?? null;
        if ($startMs === null || !is_numeric($startMs)) {
            return null;
        }
        $startSec = (int)round((float)$startMs / 1000);
        if ($startSec <= 0) {
            return null;
        }
        $durationSec = 0;
        foreach (['totalTime', 'duration', 'movingTime'] as $dk) {
            if (isset($w[$dk]) && is_numeric($w[$dk])) {
                $durationSec = (int)round((float)$w[$dk]);
                break;
            }
        }
        if ($durationSec < 0) {
            $durationSec = 0;
        }
        $durationMinutes = $durationSec > 0 ? (int)max(1, round($durationSec / 60)) : null;
        $startTimeStr = gmdate('Y-m-d H:i:s', $startSec);
        $endTime = $durationSec > 0 ? gmdate('Y-m-d H:i:s', $startSec + $durationSec) : $startTimeStr;

        // distance: метры -> км
        $distanceKm = null;
        foreach (['totalDistance', 'distance'] as $dk) {
            if (isset($w[$dk]) && is_numeric($w[$dk])) {
                $distanceKm = round((float)$w[$dk] / 1000, 3);
                break;
            }
        }

        $activityType = $this->mapActivityId($w['activityId'] ?? null);
        // Темп (min/km): готовый avgPace/avgSpeed Suunto (как на часах), иначе из дистанции и длительности.
        $avgPace = null;
        if (in_array($activityType, ['running', 'walking', 'hiking'], true)) {
            $avgPace = $this->paceFromSpeedFields($w);
            if ($avgPace === null && $distanceKm !== null && $distanceKm > 0 && $durationSec > 0) {
                $avgPace = $this->paceFromMinPerKm(($durationSec / 60.0) / $distanceKm);
            }
        }

        $avgHr = null;
        $maxHr = null;
        if (isset($w['hrdata']) && is_array($w['hrdata'])) {
            $avgHr = isset($w['hrdata']['workoutAvgHR']) ? (int)round((float)$w['hrdata']['workoutAvgHR']) : null;
            $maxHr = isset($w['hrdata']['workoutMaxHR']) ? (int)round((float)$w['hrdata']['workoutMaxHR']) : null;
        }
        if ($avgHr === null && isset($w['avgHr']) && is_numeric($w['avgHr'])) {
            $avgHr = (int)round((float)$w['avgHr']);
        }
        if ($maxHr === null && isset($w['maxHr']) && is_numeric($w['maxHr'])) {
            $maxHr = (int)round((float)$w['maxHr']);
        }

        $elev = null;
        foreach (['totalAscent', 'ascent', 'elevationGain'] as $ek) {
            if (isset($w[$ek]) && is_numeric($w[$ek])) {
                $elev = (int)round((float)$w[$ek]);
                break;
            }
        }

        $ext = 'suunto_' . ($key !== null ? (string)$key : $startTimeStr);
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

    /**
     * @param mixed $activityId
     */
    private function mapActivityId($activityId): string {
        if ($activityId === null) {
            return $this->activityDefault;
        }
        $id = (string)$activityId;
        if (isset($this->activityMap[$id]) && $this->activityMap[$id] !== '') {
            return $this->activityMap[$id];
        }
        return $this->activityDefault;
    }

    /** Темп (min:sec/km) из полей сводки Suunto: avgPace (min/km) или avgSpeed (m/s). */
    private function paceFromSpeedFields(array $w): ?string {
        if (isset($w['avgPace']) && is_numeric($w['avgPace']) && (float)$w['avgPace'] > 0) {
            return $this->paceFromMinPerKm((float)$w['avgPace']);
        }
        if (isset($w['avgSpeed']) && is_numeric($w['avgSpeed']) && (float)$w['avgSpeed'] > 0) {
            return $this->paceFromMinPerKm(1000.0 / ((float)$w['avgSpeed'] * 60.0));
        }
        return null;
    }

    private function paceFromMinPerKm(float $minPerKm): ?string {
        if ($minPerKm <= 0 || !is_finite($minPerKm)) {
            return null;
        }
        $m = (int)floor($minPerKm);
        $s = (int)round(($minPerKm - $m) * 60);
        if ($s >= 60) {
            $s = 0;
            $m++;
        }
        return sprintf('%d:%02d', $m, $s);
    }

    /**
     * Заливает тренировку PlanRun в Suunto-аккаунт пользователя (Workout Upload API):
     * собрать FIT → POST /v2/upload/ → PUT в blob → poll /v2/upload/{id}.
     * @return array{status:string, workoutKey:?string, message:string} status: PROCESSED|SKIPPED|ERROR
     */
    public function uploadWorkout(int $userId, int $workoutId): array {
        $fail = fn(string $m): array => ['status' => 'ERROR', 'workoutKey' => null, 'message' => $m];
        if ($this->subscriptionKey === '') return $fail('SUUNTO_SUBSCRIPTION_KEY пуст');
        $row = $this->getTokenRow($userId);
        if (!$row) return $fail('Suunto не подключён');
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time() + 60) {
            $this->refreshToken($userId);
            $row = $this->getTokenRow($userId) ?? $row;
        }
        $token = (string)$row['access_token'];

        require_once __DIR__ . '/../services/SuuntoFitBuilder.php';
        $fitPath = (new SuuntoFitBuilder($this->db))->buildFitFile($userId, $workoutId);
        if ($fitPath === null) return $fail('Не удалось собрать FIT');

        try {
            $notify = $this->env('SUUNTO_UPLOAD_NOTIFY', '0') === '1';
            $initBody = json_encode(['description' => 'PlanRun', 'comment' => '', 'notifyUser' => $notify, 'privacy' => 'DEFAULT']);
            $init = $this->apiJson('POST', $this->apiBase . '/v2/upload/', $token, $initBody);
            if (!is_array($init) || empty($init['url']) || empty($init['id'])) {
                return $fail('init upload failed');
            }
            $id = (string)$init['id'];

            $putHeaders = ['Content-Type: application/octet-stream'];
            foreach (($init['headers'] ?? []) as $k => $v) { $putHeaders[] = $k . ': ' . $v; }
            $ch = curl_init((string)$init['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => file_get_contents($fitPath),
                CURLOPT_HTTPHEADER => $putHeaders,
            ]);
            curl_exec($ch);
            $putCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($putCode < 200 || $putCode >= 300) {
                return $fail('PUT failed http ' . $putCode);
            }

            for ($i = 0; $i < 20; $i++) {
                sleep(2);
                $st = $this->apiJson('GET', $this->apiBase . '/v2/upload/' . rawurlencode($id), $token, null);
                $status = is_array($st) ? (string)($st['status'] ?? '') : '';
                if ($status === 'PROCESSED') {
                    return ['status' => 'PROCESSED', 'workoutKey' => (string)($st['workoutKey'] ?? ''), 'message' => ''];
                }
                if ($status === 'ERROR') {
                    $msg = (string)($st['message'] ?? 'ERROR');
                    // дубль — для нас это успех (уже есть в Suunto), повторять не нужно
                    if (stripos($msg, 'exist') !== false) {
                        return ['status' => 'SKIPPED', 'workoutKey' => (string)($st['workoutKey'] ?? ''), 'message' => $msg];
                    }
                    return ['status' => 'ERROR', 'workoutKey' => null, 'message' => $msg];
                }
            }
            return $fail('timeout polling status');
        } finally {
            @unlink($fitPath);
        }
    }

    /** @return array<string,mixed>|null */
    private function apiJson(string $method, string $url, string $token, ?string $body): ?array {
        $headers = [
            'Accept: application/json',
            'Authorization: ' . $token,
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
        ];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        curl_close($ch);
        $j = json_decode((string)$resp, true);
        return is_array($j) ? $j : null;
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
