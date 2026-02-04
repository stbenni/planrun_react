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
                    'phases' => [],
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
            
            // Загружаем план
            $planData = loadTrainingPlanForUser($userId);
            $hasPlan = !empty($planData) && isset($planData['phases']) && is_array($planData['phases']) && !empty($planData['phases']);
            
            // Дополнительная проверка: есть ли хотя бы одна фаза с неделями
            if ($hasPlan) {
                $hasWeeks = false;
                foreach ($planData['phases'] as $phase) {
                    if (isset($phase['weeks_data']) && is_array($phase['weeks_data']) && !empty($phase['weeks_data'])) {
                        $hasWeeks = true;
                        break;
                    }
                }
                $hasPlan = $hasWeeks;
            }
            
            return [
                'has_plan' => $hasPlan,
                'user_id' => $userId,
                'debug' => [
                    'plan_data_exists' => !empty($planData),
                    'phases_count' => isset($planData['phases']) ? count($planData['phases']) : 0,
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
