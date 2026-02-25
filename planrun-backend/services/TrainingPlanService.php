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

class TrainingPlanService extends BaseService {
    
    protected $repository;
    protected $validator;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new TrainingPlanRepository($db);
        $this->validator = new TrainingPlanValidator();
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
            
            // Если есть ошибка генерации, возвращаем её
            if ($planCheckRow && !empty($planCheckRow['error_message'])) {
                return [
                    'has_plan' => false,
                    'error' => $planCheckRow['error_message'],
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
                    'user_id' => $userId
                ];
            }
            
            // Загружаем план
            $planData = loadTrainingPlanForUser($userId, false);
            $weeksData = isset($planData['weeks_data']) && is_array($planData['weeks_data']) ? $planData['weeks_data'] : [];
            $hasPlan = !empty($weeksData);

            return [
                'has_plan' => $hasPlan,
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
        require_once __DIR__ . '/../planrun_ai/planrun_ai_config.php';
        
        // Валидация
        if (!$this->validator->validateRegeneratePlan(['user_id' => $userId])) {
            $this->throwValidationException(
                'Ошибка валидации',
                $this->validator->getErrors()
            );
        }
        
        if (!isPlanRunAIAvailable()) {
            $this->throwException('PlanRun AI система недоступна. Проверьте, что сервис запущен на порту 8000', 503);
        }
        
        // Очищаем старую ошибку через репозиторий
        $this->repository->clearErrorMessage($userId);
        
        // Запускаем генерацию в фоне
        $scriptPath = __DIR__ . '/../planrun_ai/generate_plan_async.php';
        $logFile = '/tmp/plan_generation_' . $userId . '_' . time() . '.log';
        
        $phpPath = '/usr/bin/php';
        if (!file_exists($phpPath)) {
            $phpPath = trim(shell_exec('which php 2>/dev/null') ?: 'php');
        }
        
        $command = "cd " . escapeshellarg(__DIR__ . '/..') . " && nohup " . escapeshellarg($phpPath) . " " . escapeshellarg($scriptPath) . " " . (int)$userId . " >> " . escapeshellarg($logFile) . " 2>&1 & echo \$!";
        
        $output = [];
        exec($command, $output, $returnVar);
        $pid = !empty($output) ? trim($output[0]) : null;
        
        if (empty($pid)) {
            $result = shell_exec($command);
            if ($result) {
                $pid = trim($result);
            }
        }
        
        // Устанавливаем сообщение в сессию
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'План тренировок генерируется через PlanRun AI. Это займет 3-5 минут. Обновите страницу через несколько минут.';
        
        $this->logInfo("Повторная генерация плана", [
            'user_id' => $userId,
            'pid' => $pid ?: 'не определен'
        ]);
        
        return [
            'message' => 'Генерация плана запущена',
            'pid' => $pid
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
        require_once __DIR__ . '/../planrun_ai/planrun_ai_config.php';
        require_once __DIR__ . '/../planrun_ai/plan_generator.php';
        
        if (!isPlanRunAIAvailable()) {
            $this->throwException('PlanRun AI система недоступна. Проверьте, что сервис запущен на порту 8000', 503);
        }
        
        // Очищаем старую ошибку через репозиторий
        $this->repository->clearErrorMessage($userId);
        
        // Запускаем перегенерацию в фоне
        $scriptPath = __DIR__ . '/../planrun_ai/generate_plan_async.php';
        $logDir = __DIR__ . '/../logs';
        $logFile = '/tmp/plan_regeneration_' . $userId . '_' . time() . '.log';
        
        if (is_dir($logDir) && is_writable($logDir)) {
            $logFile = $logDir . '/plan_regeneration_' . $userId . '_' . time() . '.log';
        } elseif (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
            if (is_dir($logDir) && is_writable($logDir)) {
                $logFile = $logDir . '/plan_regeneration_' . $userId . '_' . time() . '.log';
            }
        }
        
        $phpPath = '/usr/bin/php';
        if (!file_exists($phpPath)) {
            $phpPath = trim(shell_exec('which php 2>/dev/null') ?: 'php');
        }
        
        $command = "cd " . escapeshellarg(__DIR__ . '/..') . " && nohup " . escapeshellarg($phpPath) . " " . escapeshellarg($scriptPath) . " " . (int)$userId . " >> " . escapeshellarg($logFile) . " 2>&1 & echo \$!";
        
        $output = [];
        exec($command, $output, $returnVar);
        $pid = !empty($output) ? trim($output[0]) : null;
        
        if (empty($pid)) {
            $result = shell_exec($command);
            if ($result) {
                $pid = trim($result);
            }
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'План пересчитывается с учетом всех ваших тренировок и прогресса. Это займет 3-5 минут. Обновите страницу через несколько минут.';
        
        $this->logInfo("Перегенерация плана с прогрессом", [
            'user_id' => $userId,
            'pid' => $pid ?: 'не определен'
        ]);
        
        return [
            'message' => 'Перегенерация плана запущена. Учитываются все ваши тренировки и прогресс.',
            'pid' => $pid
        ];
    }
    
    /**
     * Пересчитать план с учётом истории, пропусков и текущей формы.
     * Будущие тренировки пересчитываются, прошлые (workout_log) сохраняются.
     */
    public function recalculatePlan($userId, $reason = null) {
        require_once __DIR__ . '/../planrun_ai/planrun_ai_config.php';
        require_once __DIR__ . '/../planrun_ai/plan_generator.php';
        
        if (!isPlanRunAIAvailable()) {
            $this->throwException('PlanRun AI система недоступна. Проверьте, что сервис запущен на порту 8000', 503);
        }
        
        $this->repository->clearErrorMessage($userId);
        
        $deactivateStmt = $this->db->prepare(
            "UPDATE user_training_plans SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE"
        );
        if ($deactivateStmt) {
            $deactivateStmt->bind_param('i', $userId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }
        
        $reasonFile = null;
        if ($reason !== null && $reason !== '') {
            $reasonFile = sys_get_temp_dir() . '/recalc_reason_' . $userId . '_' . time() . '.txt';
            file_put_contents($reasonFile, $reason);
        }
        
        $scriptPath = __DIR__ . '/../planrun_ai/generate_plan_async.php';
        $logDir = __DIR__ . '/../logs';
        $logFile = '/tmp/plan_recalculate_' . $userId . '_' . time() . '.log';
        
        if (is_dir($logDir) && is_writable($logDir)) {
            $logFile = $logDir . '/plan_recalculate_' . $userId . '_' . time() . '.log';
        } elseif (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
            if (is_dir($logDir) && is_writable($logDir)) {
                $logFile = $logDir . '/plan_recalculate_' . $userId . '_' . time() . '.log';
            }
        }
        
        $phpPath = '/usr/bin/php';
        if (!file_exists($phpPath)) {
            $phpPath = trim(shell_exec('which php 2>/dev/null') ?: 'php');
        }
        
        $reasonArg = $reasonFile ? " --reason-file=" . escapeshellarg($reasonFile) : "";
        $command = "cd " . escapeshellarg(__DIR__ . '/..') . " && nohup " . escapeshellarg($phpPath) . " " . escapeshellarg($scriptPath) . " " . (int)$userId . " --recalculate" . $reasonArg . " >> " . escapeshellarg($logFile) . " 2>&1 & echo \$!";
        
        $output = [];
        exec($command, $output, $returnVar);
        $pid = !empty($output) ? trim($output[0]) : null;
        
        if (empty($pid)) {
            $result = shell_exec($command);
            if ($result) {
                $pid = trim($result);
            }
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'План пересчитывается с учётом вашего прогресса и текущей формы. Это займёт 3-5 минут.';
        
        $this->logInfo("Пересчёт плана (recalculate)", [
            'user_id' => $userId,
            'pid' => $pid ?: 'не определен',
            'has_reason' => !empty($reason)
        ]);
        
        return [
            'message' => 'Пересчёт плана запущен. Учитываются ваши тренировки, пропуски и текущая форма.',
            'pid' => $pid
        ];
    }

    /**
     * Генерация нового плана после завершения предыдущего.
     * Собирает полную историю тренировок и передаёт AI для правильной прогрессии.
     */
    public function generateNextPlan($userId, $goals = null) {
        require_once __DIR__ . '/../planrun_ai/planrun_ai_config.php';
        require_once __DIR__ . '/../planrun_ai/plan_generator.php';

        if (!isPlanRunAIAvailable()) {
            $this->throwException('PlanRun AI система недоступна. Проверьте, что сервис запущен на порту 8000', 503);
        }

        $this->repository->clearErrorMessage($userId);

        $deactivateStmt = $this->db->prepare(
            "UPDATE user_training_plans SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE"
        );
        if ($deactivateStmt) {
            $deactivateStmt->bind_param('i', $userId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }

        $goalsFile = null;
        if ($goals !== null && $goals !== '') {
            $goalsFile = sys_get_temp_dir() . '/next_plan_goals_' . $userId . '_' . time() . '.txt';
            file_put_contents($goalsFile, $goals);
        }

        $scriptPath = __DIR__ . '/../planrun_ai/generate_plan_async.php';
        $logDir = __DIR__ . '/../logs';
        $logFile = '/tmp/plan_next_' . $userId . '_' . time() . '.log';

        if (is_dir($logDir) && is_writable($logDir)) {
            $logFile = $logDir . '/plan_next_' . $userId . '_' . time() . '.log';
        } elseif (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
            if (is_dir($logDir) && is_writable($logDir)) {
                $logFile = $logDir . '/plan_next_' . $userId . '_' . time() . '.log';
            }
        }

        $phpPath = '/usr/bin/php';
        if (!file_exists($phpPath)) {
            $phpPath = trim(shell_exec('which php 2>/dev/null') ?: 'php');
        }

        $goalsArg = $goalsFile ? " --goals-file=" . escapeshellarg($goalsFile) : "";
        $command = "cd " . escapeshellarg(__DIR__ . '/..') . " && nohup " . escapeshellarg($phpPath) . " " . escapeshellarg($scriptPath) . " " . (int)$userId . " --next-plan" . $goalsArg . " >> " . escapeshellarg($logFile) . " 2>&1 & echo \$!";

        $output = [];
        exec($command, $output, $returnVar);
        $pid = !empty($output) ? trim($output[0]) : null;

        if (empty($pid)) {
            $result = shell_exec($command);
            if ($result) {
                $pid = trim($result);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['plan_generation_message'] = 'Новый план генерируется с учётом всех ваших прошлых тренировок. Это займёт 3-5 минут.';

        $this->logInfo("Генерация нового плана (next_plan)", [
            'user_id' => $userId,
            'pid' => $pid ?: 'не определен',
            'has_goals' => !empty($goals)
        ]);

        return [
            'message' => 'Генерация нового плана запущена. Учитываются все ваши достижения из предыдущего плана.',
            'pid' => $pid
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
