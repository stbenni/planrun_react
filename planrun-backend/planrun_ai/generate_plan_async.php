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
$isRecalculate = in_array('--recalculate', $argv ?? [], true);
$isNextPlan = in_array('--next-plan', $argv ?? [], true);

$userReason = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--reason-file=') === 0) {
        $reasonFile = substr($arg, strlen('--reason-file='));
        if (file_exists($reasonFile)) {
            $userReason = trim(file_get_contents($reasonFile));
            @unlink($reasonFile);
        }
        break;
    }
}

$userGoals = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--goals-file=') === 0) {
        $goalsFile = substr($arg, strlen('--goals-file='));
        if (file_exists($goalsFile)) {
            $userGoals = trim(file_get_contents($goalsFile));
            @unlink($goalsFile);
        }
        break;
    }
}

if (!$userId) {
    error_log("generate_plan_async.php: Не указан user_id");
    exit(1);
}

$mode = $isNextPlan ? 'НОВЫЙ ПЛАН' : ($isRecalculate ? 'ПЕРЕСЧЁТ' : 'ГЕНЕРАЦИЯ');
$extraInfo = $userReason ? " (причина: " . mb_substr($userReason, 0, 100) . ")" : ($userGoals ? " (цели: " . mb_substr($userGoals, 0, 100) . ")" : "");
error_log("generate_plan_async.php: Начало {$mode} плана для пользователя {$userId}{$extraInfo}");

try {
    if ($isNextPlan) {
        $planData = generateNextPlanViaPlanRunAI($userId, $userGoals);
    } elseif ($isRecalculate) {
        $result = recalculatePlanViaPlanRunAI($userId, $userReason);
        $planData = $result['plan'];
        $cutoffDate = $result['cutoff_date'];
        $keptWeeks = $result['kept_weeks'];
    } else {
        $planData = generatePlanViaPlanRunAI($userId);
    }
    
    if (!$planData || !isset($planData['weeks'])) {
        throw new Exception("План не содержит данных о неделях");
    }
    
    error_log("generate_plan_async.php: План сгенерирован ({$mode}), недель: " . count($planData['weeks']));
    
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Ошибка подключения к БД");
    }
    
    if ($isNextPlan) {
        $startDate = (new DateTime())->modify('monday this week')->format('Y-m-d');
        saveTrainingPlan($db, $userId, $planData, $startDate);
        $updateStmt = $db->prepare("UPDATE users SET training_start_date = ? WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param('si', $startDate, $userId);
            $updateStmt->execute();
            $updateStmt->close();
        }
        error_log("generate_plan_async.php: Новый план сохранён, start_date={$startDate}, недель: " . count($planData['weeks']));
    } elseif ($isRecalculate) {
        saveRecalculatedPlan($db, $userId, $planData, $cutoffDate);
        error_log("generate_plan_async.php: Сохранено прошлых недель: {$keptWeeks}, новых: " . count($planData['weeks']));
    } else {
        $stmt = $db->prepare("SELECT training_start_date FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        $startDate = $user['training_start_date'] ?? date('Y-m-d');
        saveTrainingPlan($db, $userId, $planData, $startDate);
    }
    
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
    
    error_log("generate_plan_async.php: План успешно сохранен и активирован ({$mode}) для пользователя {$userId}");
    
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
