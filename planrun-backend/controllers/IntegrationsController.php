<?php
/**
 * Контроллер интеграций (Huawei, Garmin, Strava и др.)
 */
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../providers/WorkoutImportProvider.php';
require_once __DIR__ . '/../providers/HuaweiHealthProvider.php';
require_once __DIR__ . '/../providers/StravaProvider.php';
require_once __DIR__ . '/../providers/PolarProvider.php';
require_once __DIR__ . '/../services/WorkoutService.php';

class IntegrationsController extends BaseController {
    private static $providers = ['huawei' => HuaweiHealthProvider::class, 'strava' => StravaProvider::class, 'polar' => PolarProvider::class];

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
        $state = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $state;
        $_SESSION['integration_state'] = $state;
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
        $this->returnSuccess(['integrations' => $status]);
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
}
