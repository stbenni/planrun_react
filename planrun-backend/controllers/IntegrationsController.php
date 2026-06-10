<?php
/**
 * Контроллер интеграций (Huawei, Garmin, Polar, COROS, Strava и др.)
 */
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../providers/WorkoutImportProvider.php';
require_once __DIR__ . '/../providers/HuaweiHealthProvider.php';
require_once __DIR__ . '/../providers/StravaProvider.php';
require_once __DIR__ . '/../providers/PolarProvider.php';
require_once __DIR__ . '/../providers/GarminProvider.php';
require_once __DIR__ . '/../providers/CorosProvider.php';
require_once __DIR__ . '/../providers/SuuntoProvider.php';
require_once __DIR__ . '/../services/WorkoutService.php';

class IntegrationsController extends BaseController {
    private static $providers = [
        'huawei' => HuaweiHealthProvider::class,
        'strava' => StravaProvider::class,
        'polar' => PolarProvider::class,
        'garmin' => GarminProvider::class,
        'coros' => CorosProvider::class,
        'suunto' => SuuntoProvider::class,
    ];

    private function getProvider(string $providerId): WorkoutImportProvider {
        $class = self::$providers[$providerId] ?? null;
        if (!$class) {
            $this->returnError('Неизвестный провайдер: ' . $providerId, 400);
        }
        return new $class($this->db);
    }

    /**
     * GET integration_oauth_url?provider=huawei
     */
    public function getOAuthUrl() {
        if (!$this->requireAuth()) return;
        $providerId = $this->getParam('provider');
        if (!$providerId) {
            $this->returnError('Параметр provider обязателен');
            return;
        }
        $provider = $this->getProvider($providerId);
        $fromApp = $this->getParam('from_app') === '1';

        // Формируем подписанный state с user_id (для мобильного OAuth через In-App Browser)
        $csrf = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrf;
        $_SESSION['integration_state'] = $csrf;

        $payload = base64_encode(json_encode([
            'csrf' => $csrf,
            'uid'  => $this->currentUserId,
            'ts'   => time(),
            'app'  => $fromApp ? 1 : 0,
        ]));
        $secret = env('JWT_SECRET_KEY', 'oauth-state-fallback-' . md5(__DIR__));
        $hmac = hash_hmac('sha256', $payload, $secret);
        $state = $payload . '.' . $hmac;

        $url = $provider->getOAuthUrl($state);
        if (!$url) {
            $this->returnError('Провайдер не настроен (отсутствуют client_id/redirect_uri)');
            return;
        }
        $this->returnSuccess(['auth_url' => $url]);
    }

    /**
     * GET integrations_status
     */
    public function getStatus() {
        if (!$this->requireAuth()) return;
        $status = [];
        foreach (array_keys(self::$providers) as $providerId) {
            $provider = $this->getProvider($providerId);
            $status[$providerId] = $provider->isConnected($this->currentUserId);
        }
        // Никогда не роняем статус интеграций из-за mirror-инфо (иначе пропадают все карточки)
        try {
            $mirror = $this->suuntoMirrorInfo($this->currentUserId);
        } catch (\Throwable $e) {
            $mirror = ['available' => false, 'enabled' => false];
        }
        $this->returnSuccess([
            'integrations' => $status,
            'suunto_mirror_available' => $mirror['available'],
            'suunto_mirror_enabled' => $mirror['enabled'],
        ]);
    }

    /**
     * POST set_suunto_mirror { enabled: bool } — включить/выключить зеркалирование тренировок в Suunto.
     */
    public function setSuuntoMirror() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        $data = $this->getJsonBody();
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $info = $this->suuntoMirrorInfo($this->currentUserId);
        if ($enabled && !$info['available']) {
            $this->returnError('Зеркалирование в Suunto недоступно для этого аккаунта', 403);
            return;
        }
        $stmt = $this->db->prepare("UPDATE users SET suunto_mirror_enabled = ? WHERE id = ?");
        if (!$stmt) { $this->returnError('DB error', 500); return; }
        $stmt->bind_param('ii', $enabled, $this->currentUserId);
        $stmt->execute();
        $stmt->close();
        $this->returnSuccess(['enabled' => (bool)$enabled]);
    }

    /**
     * Доступность и состояние зеркалирования PlanRun → Suunto для юзера.
     * available = юзер в SUUNTO_MIRROR_USERS И Suunto подключён.
     * @return array{available:bool, enabled:bool}
     */
    private function suuntoMirrorInfo(int $userId): array {
        $allow = trim((string)env('SUUNTO_MIRROR_USERS', ''));
        if ($allow === '') return ['available' => false, 'enabled' => false];
        $users = array_values(array_filter(array_map('trim', explode(',', $allow)), fn($u) => $u !== ''));
        if (empty($users)) return ['available' => false, 'enabled' => false];

        $stmt = $this->db->prepare("SELECT username, suunto_mirror_enabled FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) return ['available' => false, 'enabled' => false];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return ['available' => false, 'enabled' => false];

        $inList = in_array((string)$row['username'], $users, true);
        $connected = $inList ? $this->getProvider('suunto')->isConnected($userId) : false;
        return [
            'available' => ($inList && $connected),
            'enabled' => ((int)($row['suunto_mirror_enabled'] ?? 0) === 1),
        ];
    }

    /**
     * POST sync_workouts { provider: 'huawei' }
     */
    public function syncWorkouts() {
        if (!$this->requireAuth() || !$this->requireEdit()) return;
        $this->checkCsrfToken();
        $data = $this->getJsonBody();
        $providerId = $data['provider'] ?? $this->getParam('provider');
        if (!$providerId) {
            $this->returnError('Параметр provider обязателен');
            return;
        }
        $provider = $this->getProvider($providerId);
        if (!$provider->isConnected($this->currentUserId)) {
            $this->returnError('Провайдер не подключен');
            return;
        }
        $startDate = $data['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
        $endDate = $data['end_date'] ?? date('Y-m-d');
        set_time_limit(120); // Sync may take a while with streams/GPS data
        try {
            $workouts = $provider->fetchWorkouts($this->currentUserId, $startDate, $endDate);
            $workoutService = new WorkoutService($this->db);
            $result = $workoutService->importWorkouts($this->currentUserId, $workouts, $providerId);
            $this->returnSuccess([
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'total_fetched' => count($workouts),
            ]);
        } catch (\Throwable $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error('Sync workouts error', [
                'provider' => $providerId,
                'user_id' => $this->currentUserId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->returnError('Ошибка синхронизации: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET strava_token_error — отладочная информация при ошибке обмена токена
     */
    public function getStravaTokenError() {
        if (!$this->requireAuth()) return;
        $data = $_SESSION['strava_token_error'] ?? null;
        if ($data) {
            unset($_SESSION['strava_token_error']);
        }
        $this->returnSuccess(['debug' => $data]);
    }

    /**
     * POST unlink_integration { provider: 'huawei' }
     */
    public function unlink() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();
        $data = $this->getJsonBody();
        $providerId = $data['provider'] ?? $this->getParam('provider');
        if (!$providerId) {
            $this->returnError('Параметр provider обязателен');
            return;
        }
        $provider = $this->getProvider($providerId);
        $provider->disconnect($this->currentUserId);
        $this->returnSuccess(['message' => 'Интеграция отключена']);
    }

    /**
     * POST health_connect_import { workouts: [...] }
     * Health Connect читается нативно на устройстве (Android), поэтому тренировки
     * приходят готовым массивом и импортируются той же цепочкой, что и провайдеры
     * (дедуп по external_id/времени + разбор тренера через WorkoutService).
     */
    public function importHealthConnect() {
        if (!$this->requireAuth() || !$this->requireEdit()) return;
        $this->checkCsrfToken();
        $data = $this->getJsonBody();
        $workouts = $data['workouts'] ?? null;
        if (!is_array($workouts)) {
            $this->returnError('Параметр workouts (массив) обязателен');
            return;
        }
        if (count($workouts) > 500) {
            $workouts = array_slice($workouts, 0, 500);
        }
        set_time_limit(120);
        try {
            $workoutService = new WorkoutService($this->db);
            $result = $workoutService->importWorkouts($this->currentUserId, $workouts, 'health_connect');
            $this->returnSuccess([
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'total_received' => count($workouts),
            ]);
        } catch (\Throwable $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error('Health Connect import error', [
                'user_id' => $this->currentUserId,
                'message' => $e->getMessage(),
            ]);
            $this->returnError('Ошибка импорта Health Connect: ' . $e->getMessage(), 500);
        }
    }
}
