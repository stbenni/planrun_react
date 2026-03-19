<?php
/**
 * Сервис для работы с планами тренировок
 * Содержит бизнес-логику работы с планами
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../load_training_plan.php';
require_once __DIR__ . '/../user_functions.php';
require_once __DIR__ . '/../repositories/TrainingPlanRepository.php';
require_once __DIR__ . '/../validators/TrainingPlanValidator.php';
require_once __DIR__ . '/PlanGenerationQueueService.php';

class TrainingPlanService extends BaseService {
    
    protected $repository;
    protected $validator;
    protected $queueService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new TrainingPlanRepository($db);
        $this->validator = new TrainingPlanValidator();
        $this->queueService = new PlanGenerationQueueService($db);
    }
    
    /**
     * Загрузить план тренировок для пользователя
     * 
     * @param int $userId ID пользователя
     * @param bool $useCache Использовать ли кеш
     * @return array План тренировок с phases
     * @throws Exception
     */
    public function loadPlan($userId, $useCache = true) {
        try {
            $planData = loadTrainingPlanForUser($userId, $useCache);
            
            if (empty($planData)) {
                return [
                    'weeks_data' => [],
                    'has_plan' => false
                ];
            }
            
            return $planData;
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки плана: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Проверить статус плана тренировок
     * 
     * @param int $userId ID пользователя
     * @return array Статус плана
     * @throws Exception
     */
    public function checkPlanStatus($userId) {
        try {
            // Валидация
            if (!$this->validator->validateCheckStatus(['user_id' => $userId])) {
                $this->throwValidationException(
                    'Ошибка валидации',
                    $this->validator->getErrors()
                );
            }
            
            // Используем репозиторий
            $planCheckRow = $this->repository->getPlanByUserId($userId);
            $activeQueueJob = null;
            $latestQueueJob = null;
            $latestGeneration = null;
            try {
                if ($this->queueService->isQueueAvailable()) {
                    $activeQueueJob = $this->queueService->findLatestActiveJobForUser((int) $userId);
                    $latestQueueJob = $this->queueService->findLatestJobForUser((int) $userId);
                    $latestGeneration = $this->extractGenerationDiagnostics($latestQueueJob);
                }
            } catch (Throwable $queueError) {
                $this->logError('Не удалось загрузить статус очереди генерации плана', [
                    'user_id' => $userId,
                    'error' => $queueError->getMessage(),
                ]);
            }
            
            // Если есть ошибка генерации, возвращаем её
            if ($planCheckRow && !empty($planCheckRow['error_message'])) {
                return [
                    'has_plan' => false,
                    'error' => $planCheckRow['error_message'],
                    'latest_generation' => $latestGeneration,
                    'user_id' => $userId
                ];
            }
            
            // План деактивирован (идёт пересчёт) — ждём
            if ($planCheckRow && !$planCheckRow['is_active']) {
                $planData = loadTrainingPlanForUser($userId, false);
                $weeksData = isset($planData['weeks_data']) && is_array($planData['weeks_data']) ? $planData['weeks_data'] : [];
                $hasWeeksInDb = !empty($weeksData);

                return [
                    'has_plan' => false,
                    'generating' => true,
                    'has_old_plan' => $hasWeeksInDb,
                    'job_id' => $activeQueueJob['id'] ?? null,
                    'job_type' => $activeQueueJob['job_type'] ?? null,
                    'queue_status' => $activeQueueJob['status'] ?? null,
                    'latest_generation' => $latestGeneration,
                    'user_id' => $userId
                ];
            }

            if ($activeQueueJob) {
                return [
                    'has_plan' => false,
                    'generating' => true,
                    'has_old_plan' => false,
                    'job_id' => (int) $activeQueueJob['id'],
                    'job_type' => $activeQueueJob['job_type'] ?? null,
                    'queue_status' => $activeQueueJob['status'] ?? null,
                    'latest_generation' => $latestGeneration,
                    'user_id' => $userId
                ];
            }
            
            // Загружаем план
            $planData = loadTrainingPlanForUser($userId, false);
            $weeksData = isset($planData['weeks_data']) && is_array($planData['weeks_data']) ? $planData['weeks_data'] : [];
            $hasPlan = !empty($weeksData);

            return [
                'has_plan' => $hasPlan,
                'latest_generation' => $latestGeneration,
                'user_id' => $userId,
                'debug' => [
                    'plan_data_exists' => !empty($planData),
                    'weeks_count' => count($weeksData),
                    'is_active' => $planCheckRow ? (bool)$planCheckRow['is_active'] : false
                ]
            ];
        } catch (Exception $e) {
            $this->throwException('Ошибка проверки статуса плана: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Регенерировать план тренировок
     * 
     * @param int $userId ID пользователя
     * @return array Результат запуска генерации
     * @throws Exception
     */
    public function regeneratePlan($userId) {
        // Валидация
        if (!$this->validator->validateRegeneratePlan(['user_id' => $userId])) {
            $this->throwValidationException(
                'Ошибка валидации',
                $this->validator->getErrors()
            );
        }

        // Очищаем старую ошибку через репозиторий
        $this->repository->clearErrorMessage($userId);
        $queueResult = $this->queueService->enqueue((int) $userId, 'generate');
        
        // Устанавливаем сообщение в сессию
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'План тренировок генерируется через PlanRun AI. Это займет 3-5 минут. Обновите страницу через несколько минут.';
        
        $this->logInfo("Повторная генерация плана", [
            'user_id' => $userId,
            'job_id' => $queueResult['job_id'] ?? null
        ]);
        
        return [
            'message' => 'Генерация плана запущена',
            'job_id' => $queueResult['job_id'] ?? null,
            'queued' => true
        ];
    }
    
    /**
     * Регенерировать план с учетом прогресса
     * 
     * @param int $userId ID пользователя
     * @return array Результат запуска генерации
     * @throws Exception
     */
    public function regeneratePlanWithProgress($userId) {
        // Очищаем старую ошибку через репозиторий
        $this->repository->clearErrorMessage($userId);
        $deactivateStmt = $this->db->prepare(
            "UPDATE user_training_plans SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE"
        );
        if ($deactivateStmt) {
            $deactivateStmt->bind_param('i', $userId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }

        $queueResult = $this->queueService->enqueue((int) $userId, 'recalculate');
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'План пересчитывается с учетом всех ваших тренировок и прогресса. Это займет 3-5 минут. Обновите страницу через несколько минут.';
        
        $this->logInfo("Перегенерация плана с прогрессом", [
            'user_id' => $userId,
            'job_id' => $queueResult['job_id'] ?? null
        ]);
        
        return [
            'message' => 'Перегенерация плана запущена. Учитываются все ваши тренировки и прогресс.',
            'job_id' => $queueResult['job_id'] ?? null,
            'queued' => true
        ];
    }

    private function extractGenerationDiagnostics(?array $job): ?array {
        if (!$job) {
            return null;
        }

        $diagnostics = [
            'job_id' => isset($job['id']) ? (int) $job['id'] : null,
            'job_type' => $job['job_type'] ?? null,
            'status' => $job['status'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
            'started_at' => $job['started_at'] ?? null,
        ];

        if (!empty($job['result_json'])) {
            $result = json_decode((string) $job['result_json'], true);
            if (!empty($result['generation_metadata']) && is_array($result['generation_metadata'])) {
                $diagnostics['generation_metadata'] = $result['generation_metadata'];
            }
        }

        if (!empty($job['last_error'])) {
            $diagnostics['last_error'] = $job['last_error'];
        }

        return $diagnostics;
    }
    
    /**
     * Пересчитать план с учётом истории, пропусков и текущей формы.
     * Будущие тренировки пересчитываются, прошлые (workout_log) сохраняются.
     */
    public function recalculatePlan($userId, $reason = null) {
        $this->repository->clearErrorMessage($userId);
        
        $deactivateStmt = $this->db->prepare(
            "UPDATE user_training_plans SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE"
        );
        if ($deactivateStmt) {
            $deactivateStmt->bind_param('i', $userId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }
        
        $queueResult = $this->queueService->enqueue((int) $userId, 'recalculate', [
            'reason' => $reason,
        ]);
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'План пересчитывается с учётом вашего прогресса и текущей формы. Это займёт 3-5 минут.';
        
        $this->logInfo("Пересчёт плана (recalculate)", [
            'user_id' => $userId,
            'job_id' => $queueResult['job_id'] ?? null,
            'has_reason' => !empty($reason)
        ]);
        
        return [
            'message' => 'Пересчёт плана запущен. Учитываются ваши тренировки, пропуски и текущая форма.',
            'job_id' => $queueResult['job_id'] ?? null,
            'queued' => true
        ];
    }

    /**
     * Генерация нового плана после завершения предыдущего.
     * Собирает полную историю тренировок и передаёт AI для правильной прогрессии.
     */
    public function generateNextPlan($userId, $goals = null) {
        $this->repository->clearErrorMessage($userId);

        $deactivateStmt = $this->db->prepare(
            "UPDATE user_training_plans SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE"
        );
        if ($deactivateStmt) {
            $deactivateStmt->bind_param('i', $userId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }

        $queueResult = $this->queueService->enqueue((int) $userId, 'next_plan', [
            'goals' => $goals,
        ]);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'Новый план генерируется с учётом всех ваших прошлых тренировок. Это займёт 3-5 минут.';

        $this->logInfo("Генерация нового плана (next_plan)", [
            'user_id' => $userId,
            'job_id' => $queueResult['job_id'] ?? null,
            'has_goals' => !empty($goals)
        ]);

        return [
            'message' => 'Генерация нового плана запущена. Учитываются все ваши достижения из предыдущего плана.',
            'job_id' => $queueResult['job_id'] ?? null,
            'queued' => true
        ];
    }

    /**
     * Восстановить план из состояния «generating» (после таймаута или краша async).
     */
    public function reactivatePlan($userId) {
        $stmt = $this->db->prepare(
            "UPDATE user_training_plans SET is_active = TRUE, error_message = NULL WHERE user_id = ? AND is_active = FALSE"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");
    }

    /**
     * Удалить план тренировок (сгенерированный ИИ).
     * Удаляет weeks, days, exercises. Результаты тренировок (workout_log) сохраняются.
     *
     * @param int $userId ID пользователя
     * @return void
     */
    public function clearPlan($userId) {
        $this->repository->clearErrorMessage($userId);

        $stmt = $this->db->prepare(
            "DELETE FROM training_day_exercises WHERE user_id = ? AND plan_day_id IN (SELECT id FROM training_plan_days WHERE user_id = ?)"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $userId, $userId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $this->db->prepare("DELETE FROM training_plan_days WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $this->db->prepare("DELETE FROM training_plan_weeks WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }

        require_once __DIR__ . '/../cache_config.php';
        Cache::delete("training_plan_{$userId}");

        $this->logInfo('План тренировок удалён', ['user_id' => $userId]);
    }

    /**
     * Очистить сообщение о генерации плана
     * 
     * @return void
     */
    public function clearPlanGenerationMessage() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['plan_generation_message'])) {
            unset($_SESSION['plan_generation_message']);
        }
    }
}
