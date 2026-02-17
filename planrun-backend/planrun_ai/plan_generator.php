<?php
/**
 * Генератор планов тренировок через PlanRun AI
 * Использует локальную LLM (Qwen3 14B) с RAG для создания персональных планов
 */

require_once __DIR__ . '/planrun_ai_integration.php';
require_once __DIR__ . '/prompt_builder.php';
require_once __DIR__ . '/../db_config.php';

/**
 * Проверка доступности PlanRun AI системы
 */
function isPlanRunAIConfigured() {
    require_once __DIR__ . '/planrun_ai_config.php';
    return USE_PLANRUN_AI && isPlanRunAIAvailable();
}

/**
 * Генерация плана тренировок через PlanRun AI
 * 
 * @param int $userId ID пользователя
 * @return array План тренировок в формате PlanRun
 * @throws Exception
 */
function generatePlanViaPlanRunAI($userId) {
    if (!isPlanRunAIConfigured()) {
        throw new Exception("PlanRun AI система недоступна. Проверьте, что сервис запущен на порту 8000.");
    }
    
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Ошибка подключения к БД");
    }
    
    // Получаем данные пользователя
    $stmt = $db->prepare("
        SELECT 
            id, username, goal_type, race_distance, race_date, race_target_time,
            target_marathon_date, target_marathon_time, training_start_date,
            gender, birth_year, height_cm, weight_kg, experience_level,
            weekly_base_km, sessions_per_week, preferred_days, preferred_ofp_days,
            has_treadmill, ofp_preference, training_time_pref, health_notes,
            weight_goal_kg, weight_goal_date, health_program, health_plan_weeks,
            current_running_level, running_experience, easy_pace_sec,
            is_first_race_at_distance, last_race_distance, last_race_distance_km,
            last_race_time, last_race_date, device_type
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        throw new Exception("Пользователь не найден");
    }
    
    // Декодируем JSON поля
    if (!empty($user['preferred_days'])) {
        $user['preferred_days'] = json_decode($user['preferred_days'], true) ?: [];
    } else {
        $user['preferred_days'] = [];
    }
    
    if (!empty($user['preferred_ofp_days'])) {
        $user['preferred_ofp_days'] = json_decode($user['preferred_ofp_days'], true) ?: [];
    } else {
        $user['preferred_ofp_days'] = [];
    }
    
    // Определяем goal_type
    $goalType = $user['goal_type'] ?? 'health';
    
    // Строим промпт
    $prompt = buildTrainingPlanPrompt($user, $goalType);
    
    error_log("PlanRun AI Generator: Промпт построен для пользователя {$userId}, длина: " . strlen($prompt) . " символов");
    
    // Вызываем PlanRun AI API
    try {
        $response = callAIAPI($prompt, $user, 3, $userId);
        error_log("PlanRun AI Generator: Получен ответ от PlanRun AI API, длина: " . strlen($response) . " символов");
        
        // Парсим JSON ответ
        $planData = json_decode($response, true);
        
        if (!$planData || !isset($planData['weeks'])) {
            error_log("PlanRun AI Generator: Неверный формат ответа. Ответ: " . substr($response, 0, 500));
            throw new Exception("Неверный формат ответа от PlanRun AI API");
        }
        
        return $planData;
        
    } catch (Exception $e) {
        error_log("PlanRun AI Generator: Ошибка при генерации плана: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Парсинг ответа от PlanRun AI API
 * 
 * @param string $response JSON ответ от PlanRun AI API
 * @return array Распарсенный план
 */
function parsePlanRunAIResponse($response) {
    // PlanRun AI API уже возвращает валидный JSON, просто парсим
    $plan = json_decode($response, true);
    
    if (!$plan) {
        throw new Exception("Не удалось распарсить ответ от PlanRun AI API");
    }
    
    return $plan;
}
