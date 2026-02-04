<?php
/**
 * Асинхронная генерация плана через RAG
 * Запускается в фоне после регистрации пользователя
 * 
 * Использование: php generate_plan_async.php <user_id>
 */

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/plan_generator.php';
require_once __DIR__ . '/plan_saver.php';
require_once __DIR__ . '/../training_utils.php';

$userId = isset($argv[1]) ? (int)$argv[1] : 0;

if (!$userId) {
    error_log("generate_plan_async.php: Не указан user_id");
    exit(1);
}

error_log("generate_plan_async.php: Начало генерации плана для пользователя {$userId}");

try {
    // Генерируем план через PlanRun AI
    $planData = generatePlanViaPlanRunAI($userId);
    
    if (!$planData || !isset($planData['weeks'])) {
        throw new Exception("План не содержит данных о неделях");
    }
    
    error_log("generate_plan_async.php: План сгенерирован, недель: " . count($planData['weeks']));
    
    // Сохраняем план в БД
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Ошибка подключения к БД");
    }
    
    // Получаем дату начала тренировок
    $stmt = $db->prepare("SELECT training_start_date FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    $startDate = $user['training_start_date'] ?? date('Y-m-d');
    
    // Сохраняем план
    saveTrainingPlan($db, $userId, $planData, $startDate);
    
    // Активируем план
    $updateStmt = $db->prepare("
        UPDATE user_training_plans 
        SET is_active = TRUE 
        WHERE user_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $updateStmt->bind_param('i', $userId);
    $updateStmt->execute();
    $updateStmt->close();
    
    error_log("generate_plan_async.php: План успешно сохранен и активирован для пользователя {$userId}");
    
} catch (Exception $e) {
    error_log("generate_plan_async.php: ОШИБКА для пользователя {$userId}: " . $e->getMessage());
    error_log("generate_plan_async.php: Trace: " . $e->getTraceAsString());
    
    // Сохраняем статус ошибки в БД
    try {
        $db = getDBConnection();
        if ($db) {
            // Обновляем план, устанавливая флаг ошибки
            $errorStmt = $db->prepare("
                UPDATE user_training_plans 
                SET is_active = FALSE,
                    error_message = ?
                WHERE user_id = ? 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $errorMessage = "Ошибка генерации плана: " . $e->getMessage();
            $errorStmt->bind_param('si', $errorMessage, $userId);
            $errorStmt->execute();
            $errorStmt->close();
            
            error_log("generate_plan_async.php: Статус ошибки сохранен в БД для пользователя {$userId}");
        }
    } catch (Exception $dbError) {
        error_log("generate_plan_async.php: Ошибка сохранения статуса ошибки: " . $dbError->getMessage());
    }
    
    exit(1);
}
