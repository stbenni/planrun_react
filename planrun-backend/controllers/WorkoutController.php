<?php
/**
 * Контроллер для работы с тренировками и результатами
 *
 * Тонкий контроллер: auth/permissions + извлечение параметров + делегация в WorkoutService.
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../workout_types.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../services/WorkoutService.php';
require_once __DIR__ . '/../services/WorkoutShareMapService.php';
require_once __DIR__ . '/../services/WorkoutShareCardCacheService.php';
require_once __DIR__ . '/../services/WorkoutShareCardService.php';

class WorkoutController extends BaseController {

    private ?WorkoutService $service = null;
    private ?WorkoutShareMapService $shareMapService = null;
    private ?WorkoutShareCardCacheService $shareCardCache = null;
    private ?WorkoutShareCardService $shareCardService = null;

    private function workoutService(): WorkoutService {
        if (!$this->service) {
            $this->service = new WorkoutService($this->db);
        }
        return $this->service;
    }

    private function workoutShareMapService(): WorkoutShareMapService {
        if (!$this->shareMapService) {
            $this->shareMapService = new WorkoutShareMapService();
        }
        return $this->shareMapService;
    }

    private function workoutShareCardCacheService(): WorkoutShareCardCacheService {
        if (!$this->shareCardCache) {
            $this->shareCardCache = new WorkoutShareCardCacheService($this->db);
        }
        return $this->shareCardCache;
    }

    private function workoutShareCardService(): WorkoutShareCardService {
        if (!$this->shareCardService) {
            $this->shareCardService = new WorkoutShareCardService($this->db);
        }
        return $this->shareCardService;
    }

    private function resolveShareWorkoutKind(): string {
        $workoutKind = trim((string) ($this->getParam('workout_kind') ?: ''));
        if ($workoutKind !== '') {
            return $workoutKind;
        }

        $isManual = $this->getParam('is_manual');
        if ($isManual === null) {
            return WorkoutShareCardCacheService::KIND_WORKOUT;
        }

        return filter_var($isManual, FILTER_VALIDATE_BOOLEAN)
            ? WorkoutShareCardCacheService::KIND_MANUAL
            : WorkoutShareCardCacheService::KIND_WORKOUT;
    }

    private function outputShareCardResponse(array $card, string $template, bool $cacheHit): void {
        $contentType = (string) ($card['contentType'] ?? $card['mime_type'] ?? 'image/png');
        $fileName = (string) ($card['fileName'] ?? $card['file_name'] ?? 'planrun-workout-share.png');
        $mapProvider = (string) ($card['mapProvider'] ?? $card['map_provider'] ?? '');
        $body = (string) ($card['body'] ?? '');

        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('X-PlanRun-Share-Template: ' . $template);
        header('X-PlanRun-Share-Cache: ' . ($cacheHit ? 'hit' : 'miss'));
        if ($mapProvider !== '') {
            header('X-PlanRun-Map-Provider: ' . $mapProvider);
        }
        echo $body;
        exit;
    }

    private function normalizeShareTemplate(string $template): string {
        $normalized = trim(mb_strtolower($template));
        if (in_array($normalized, [
            WorkoutShareCardCacheService::TEMPLATE_ROUTE,
            WorkoutShareCardCacheService::TEMPLATE_MINIMAL,
        ], true)) {
            return $normalized;
        }

        return WorkoutShareCardCacheService::TEMPLATE_ROUTE;
    }

    private function shareWorkoutExists(int $workoutId, string $workoutKind, int $userId): bool {
        if ($workoutId <= 0 || $userId <= 0) {
            return false;
        }

        $normalizedKind = trim(mb_strtolower($workoutKind));
        if ($normalizedKind === WorkoutShareCardCacheService::KIND_MANUAL) {
            $stmt = $this->db->prepare('SELECT id FROM workout_log WHERE id = ? AND user_id = ? LIMIT 1');
        } else {
            $stmt = $this->db->prepare('SELECT id FROM workouts WHERE id = ? AND user_id = ? LIMIT 1');
        }

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $workoutId, $userId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }

    /**
     * @return array{body: string, contentType: string}
     */
    private function decodeUploadedShareCard(string $dataUrl): array {
        $normalized = trim($dataUrl);
        if (!str_starts_with($normalized, 'data:')) {
            throw new InvalidArgumentException('Неверный формат изображения карточки.');
        }

        $commaPos = strpos($normalized, ',');
        if ($commaPos === false) {
            throw new InvalidArgumentException('Неверный формат изображения карточки.');
        }

        $meta = substr($normalized, 5, $commaPos - 5);
        $encoded = substr($normalized, $commaPos + 1);
        if (!str_contains($meta, ';base64')) {
            throw new InvalidArgumentException('Карточка должна быть передана в base64.');
        }

        [$contentType] = explode(';', $meta, 2);
        if (!in_array($contentType, ['image/png', 'image/jpeg'], true)) {
            throw new InvalidArgumentException('Поддерживаются только PNG и JPEG карточки.');
        }

        $body = base64_decode(str_replace(["\r", "\n", ' '], '', $encoded), true);
        if ($body === false || $body === '') {
            throw new InvalidArgumentException('Не удалось декодировать изображение карточки.');
        }

        if (strlen($body) > 12 * 1024 * 1024) {
            throw new InvalidArgumentException('Изображение карточки слишком большое.');
        }

        return [
            'body' => $body,
            'contentType' => $contentType,
        ];
    }

    /**
     * GET data_version — лёгкий endpoint для polling
     */
    public function dataVersion() {
        try {
            $version = $this->workoutService()->getDataVersion((int)$this->calendarUserId);
            $this->returnSuccess(['version' => $version]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_day — получить день тренировки
     */
    public function getDay() {
        $date = $this->getParam('date');
        if (!$date) {
            $this->returnError('Параметр date обязателен');
            return;
        }
        try {
            $data = $this->workoutService()->getDay($date, $this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST save_result — сохранить результат тренировки
     */
    public function saveResult() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();

        $data = $this->getJsonBody();
        if (!$data) {
            $this->returnError('Данные не переданы');
            return;
        }
        if (!isset($data['date']) || !isset($data['week']) || !isset($data['day'])) {
            $this->returnError('Недостаточно данных: требуется date, week, day');
            return;
        }
        if (!isset($data['activity_type_id'])) {
            $data['activity_type_id'] = 1;
        }

        try {
            $result = $this->workoutService()->saveResult($data, $this->calendarUserId);
            $this->notifyCoachesResultLogged($data['date'] ?? null);
            $this->workoutService()->checkVdotUpdateAfterResult($data, $this->calendarUserId);
            // Пересчёт целевого HR для будущих дней после нового результата
            try {
                require_once __DIR__ . '/../services/UserProfileService.php';
                (new UserProfileService($this->db))->recalculateHrTargetsForFutureDays($this->calendarUserId);
            } catch (Throwable $e) { /* non-critical */ }
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_result — получить результат тренировки
     */
    public function getResult() {
        $date = $this->getParam('date');
        if (!$date) {
            $this->returnError('Параметр date обязателен');
            return;
        }
        try {
            $result = $this->workoutService()->getWorkoutResultByDate($date, $this->calendarUserId);
            $this->returnSuccess(['result' => $result]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST upload_workout — загрузить тренировку из GPX/TCX/FIT файла
     */
    public function uploadWorkout() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->returnError('Файл не загружен или произошла ошибка');
            return;
        }
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['gpx', 'tcx', 'fit'])) {
            $this->returnError('Допустимы только файлы GPX, TCX и FIT');
            return;
        }
        if ($file['size'] > 20 * 1024 * 1024) { // FIT файлы могут быть больше
            $this->returnError('Размер файла превышает 20MB');
            return;
        }

        $date = $_POST['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->returnError('Неверный формат даты');
            return;
        }

        // FIT парсится через FitParser, GPX/TCX — через GpxTcxParser
        require_once __DIR__ . '/../utils/GpxTcxParser.php';
        $workout = GpxTcxParser::parse($file['tmp_name'], $date);
        if (!$workout || !$workout['start_time']) {
            $this->returnError('Не удалось распарсить файл. Проверьте формат.');
            return;
        }

        $source = ($ext === 'fit') ? 'fit' : 'gpx';
        $result = $this->workoutService()->importWorkouts($this->currentUserId, [$workout], $source);
        $this->returnSuccess([
            'message' => 'Тренировка загружена',
            'imported' => $result['imported'],
            'workout' => $workout,
        ]);
    }

    /**
     * GET get_all_results — получить все результаты
     */
    public function getAllResults() {
        try {
            $data = $this->workoutService()->getAllResults($this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST delete_workout — удалить тренировку
     */
    public function deleteWorkout() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();

        $data = $this->getJsonBody();
        if (!$data || !isset($data['workout_id'])) {
            $this->returnError('Не указан ID тренировки');
            return;
        }

        try {
            $workoutId = (int)$data['workout_id'];
            $isManual = isset($data['is_manual']) ? (bool)$data['is_manual'] : false;
            $result = $this->workoutService()->deleteWorkout($workoutId, $isManual, $this->calendarUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST save — сохранить прогресс тренировки
     */
    public function save() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();

        $data = $this->getJsonBody();
        if (!$data) {
            $this->returnError('Данные не переданы');
            return;
        }

        try {
            $result = $this->workoutService()->saveProgress($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST reset — сбросить прогресс
     */
    public function reset() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();

        try {
            $result = $this->workoutService()->resetProgress($this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_workout_timeline — получить timeline данные тренировки
     */
    public function getWorkoutTimeline() {
        $workoutId = $this->getParam('workout_id');
        if (!$workoutId) {
            $this->returnError('Параметр workout_id обязателен');
            return;
        }

        try {
            $timelinePayload = $this->workoutService()->getWorkoutTimeline((int)$workoutId, $this->currentUserId);
            if (is_array($timelinePayload) && array_key_exists('timeline', $timelinePayload)) {
                $this->returnSuccess($timelinePayload);
                return;
            }
            $this->returnSuccess(['timeline' => $timelinePayload]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET analyze_workout_ai — AI-анализ тренировки с кешированием
     */
    public function analyzeWorkoutAi() {
        $date = $this->getParam('date');
        $workoutIndex = (int) ($this->getParam('workout_index') ?? 0);
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->returnError('Параметр date обязателен (формат Y-m-d)');
            return;
        }

        try {
            require_once __DIR__ . '/../cache_config.php';
            $cacheKey = "ai_analysis_{$this->currentUserId}_{$date}_{$workoutIndex}";
            $cached = Cache::get($cacheKey);
            if ($cached) {
                $this->returnSuccess(json_decode($cached, true));
                return;
            }

            require_once __DIR__ . '/../services/ChatToolRegistry.php';
            require_once __DIR__ . '/../services/ChatContextBuilder.php';
            $ctx = new \ChatContextBuilder($this->db);
            $registry = new \ChatToolRegistry($this->db, $ctx);
            $resultJson = $registry->executeTool('analyze_workout', json_encode([
                'date' => $date, 'workout_index' => $workoutIndex,
            ]), $this->currentUserId);

            $data = json_decode($resultJson, true);
            if (isset($data['error'])) {
                $this->returnError($data['message'] ?? 'Не удалось проанализировать тренировку');
                return;
            }

            // Generate AI narrative using structured prompt builder
            require_once __DIR__ . '/../services/ProactiveCoachService.php';
            $coach = new \ProactiveCoachService($this->db);
            $prompt = $coach->buildWorkoutAnalysisPrompt($data);
            // Override for detailed version (5-8 sentences instead of 3-5)
            $prompt = str_replace('КРАТКИЙ (3-5 предложений)', 'подробный (5-8 предложений)', $prompt);
            $narrative = '';
            try {
                $ref = new \ReflectionMethod($coach, 'callLlmSimple');
                $ref->setAccessible(true);
                $narrative = $ref->invoke($coach, $prompt);
            } catch (\Throwable $e) {}

            $data['ai_narrative'] = $narrative;
            Cache::set($cacheKey, json_encode($data), 86400);
            $this->returnSuccess($data);
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_workout_share_map — получить статическую карту маршрута для шаринга
     */
    public function getWorkoutShareMap() {
        $workoutId = (int) $this->getParam('workout_id');
        if ($workoutId <= 0) {
            $this->returnError('Параметр workout_id обязателен');
            return;
        }

        $width = max(240, min(1280, (int) ($this->getParam('width') ?: 364)));
        $height = max(160, min(1280, (int) ($this->getParam('height') ?: 236)));
        $scale = max(1, min(2, (int) ($this->getParam('scale') ?: 2)));

        try {
            $timelinePayload = $this->workoutService()->getWorkoutTimeline($workoutId, $this->currentUserId);
            $timeline = is_array($timelinePayload) && isset($timelinePayload['timeline']) && is_array($timelinePayload['timeline'])
                ? $timelinePayload['timeline']
                : [];
            $image = $this->workoutShareMapService()->render($timeline, $width, $height, $scale);

            http_response_code(200);
            header('Content-Type: ' . ($image['contentType'] ?? 'image/png'));
            header('Cache-Control: private, max-age=3600');
            header('X-PlanRun-Map-Provider: ' . ($image['provider'] ?? 'unknown'));
            echo $image['body'];
            exit;
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET generate_workout_share_card — получить готовую PNG-карточку для шаринга
     */
    public function generateWorkoutShareCard() {
        $workoutId = (int) $this->getParam('workout_id');
        if ($workoutId <= 0) {
            $this->returnError('Параметр workout_id обязателен');
            return;
        }

        $template = (string) ($this->getParam('template') ?: 'route');
        $workoutKind = $this->resolveShareWorkoutKind();
        $cacheOnly = filter_var($this->getParam('cache_only'), FILTER_VALIDATE_BOOLEAN);
        $preferredRenderer = trim((string) ($this->getParam('preferred_renderer') ?: ''));
        $preferredRendererVersion = $preferredRenderer === 'client'
            ? WorkoutShareCardCacheService::RENDERER_VERSION_CLIENT
            : null;

        try {
            $cache = $this->workoutShareCardCacheService();
            $cached = $cache->getCachedCard(
                (int) $this->currentUserId,
                $workoutId,
                $workoutKind,
                $template,
                $preferredRendererVersion
            );
            if ($cached) {
                $this->outputShareCardResponse($cached, $template, true);
            }

            if ($cacheOnly) {
                http_response_code(204);
                header('Cache-Control: no-store');
                return;
            }

            $card = $this->workoutShareCardService()->render($workoutId, (int) $this->currentUserId, $template, $workoutKind);
            if ($cache->isInfrastructureAvailable()) {
                try {
                    $cache->storeRenderedCard((int) $this->currentUserId, $workoutId, $workoutKind, $template, $card);
                } catch (Throwable $cacheError) {
                    Logger::warning('Workout share card cache store failed', [
                        'user_id' => (int) $this->currentUserId,
                        'workout_id' => $workoutId,
                        'workout_kind' => $workoutKind,
                        'template' => $template,
                        'error' => $cacheError->getMessage(),
                    ]);
                }
            }

            $this->outputShareCardResponse($card, $template, false);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST store_workout_share_card — сохранить клиентски сгенерированную PNG-карточку в backend-кэш
     */
    public function storeWorkoutShareCard() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();

        $data = $this->getJsonBody();
        if (!$data) {
            $this->returnError('Данные не переданы');
            return;
        }

        $workoutId = (int) ($data['workout_id'] ?? 0);
        if ($workoutId <= 0) {
            $this->returnError('Параметр workout_id обязателен');
            return;
        }

        $template = $this->normalizeShareTemplate((string) ($data['template'] ?? 'route'));
        $workoutKind = $this->resolveShareWorkoutKind();
        $imageDataUrl = (string) ($data['image_data_url'] ?? '');
        if ($imageDataUrl === '') {
            $this->returnError('Параметр image_data_url обязателен');
            return;
        }

        if ($template === WorkoutShareCardCacheService::TEMPLATE_ROUTE
            && $workoutKind === WorkoutShareCardCacheService::KIND_MANUAL) {
            $this->returnError('Для ручной тренировки нельзя сохранить маршрутную карточку.');
            return;
        }

        if (!$this->shareWorkoutExists($workoutId, $workoutKind, (int) $this->currentUserId)) {
            $this->returnError('Тренировка не найдена', 404);
            return;
        }

        try {
            $cache = $this->workoutShareCardCacheService();
            if (!$cache->isInfrastructureAvailable()) {
                $this->returnError('Кэш карточек шаринга недоступен', 503);
                return;
            }

            $decoded = $this->decodeUploadedShareCard($imageDataUrl);
            $mapProvider = trim((string) ($data['map_provider'] ?? '')) ?: null;
            $fileName = trim((string) ($data['file_name'] ?? '')) ?: sprintf(
                'planrun-workout-%d-%s.png',
                $workoutId,
                $template
            );

            $stored = $cache->storeRenderedCard(
                (int) $this->currentUserId,
                $workoutId,
                $workoutKind,
                $template,
                [
                    'body' => $decoded['body'],
                    'contentType' => $decoded['contentType'],
                    'fileName' => $fileName,
                    'mapProvider' => $mapProvider,
                    'rendererVersion' => WorkoutShareCardCacheService::RENDERER_VERSION_CLIENT,
                ]
            );
            $cache->clearPendingJobsForCard((int) $this->currentUserId, $workoutId, $workoutKind, $template);

            $this->returnSuccess([
                'stored' => true,
                'template' => $template,
                'workout_id' => $workoutId,
                'workout_kind' => $workoutKind,
                'file_name' => $stored['file_name'] ?? null,
                'file_size' => $stored['file_size'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->returnError($e->getMessage(), 422);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
