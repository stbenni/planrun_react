<?php
/**
 * Интеграция PlanRun AI с PlanRun
 * Использует локальную LLM (Qwen3 14B) с RAG для генерации планов
 */

require_once __DIR__ . '/planrun_ai_config.php';

/**
 * Генерация плана через PlanRun AI API
 *
 * @param string $prompt Промпт для генерации плана
 * @param array $userData Данные пользователя
 * @param int $maxRetries Количество попыток при ошибке
 * @param int|null $userId ID пользователя (при наличии AI загрузит свежие данные из MySQL)
 * @return string JSON ответ с планом
 * @throws Exception
 */
function callPlanRunAIAPI($prompt, $userData, $maxRetries = 3, $userId = null) {
    if (!USE_PLANRUN_AI) {
        throw new Exception("PlanRun AI отключен в конфигурации");
    }
    
    // Проверка доступности API
    if (!isPlanRunAIAvailable()) {
        throw new Exception("PlanRun AI API недоступен. Проверьте, что сервис запущен на порту 8000");
    }
    
    $retryDelay = 1;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            if ($attempt > 1) {
                error_log("callPlanRunAIAPI: Попытка $attempt/$maxRetries после задержки $retryDelay сек");
                sleep($retryDelay);
                $retryDelay *= 2;
            }
            
            // Определяем goal_type из userData
            $goalType = $userData['goal_type'] ?? 'health';
            
            // Подготовка запроса
            $requestData = [
                'user_data' => $userData,
                'user_id' => $userId,
                'goal_type' => $goalType,
                'include_knowledge' => true, // Использовать RAG
                'temperature' => 0.3,
                'max_tokens' => 24000, // Увеличено до 24k для локальной LLM
                'base_prompt' => $prompt // Передаем промпт как базовый
            ];
            
            error_log("callPlanRunAIAPI: Отправка запроса к PlanRun AI API...");
            
            $ch = curl_init(PLANRUN_AI_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestData),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => PLANRUN_AI_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false, // Локальный сервер
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                throw new Exception("Ошибка cURL: $error");
            }
            
            if ($httpCode !== 200) {
                $errorData = json_decode($response, true);
                $errorMsg = $errorData['detail'] ?? $errorData['error'] ?? "HTTP $httpCode";
                throw new Exception("PlanRun AI API Error: HTTP $httpCode - $errorMsg");
            }
            
            $result = json_decode($response, true);
            
            if (!isset($result['plan'])) {
                throw new Exception('Неверный формат ответа от PlanRun AI API');
            }
            
            // Логируем использование RAG
            if (isset($result['used_rag']) && $result['used_rag']) {
                error_log("callPlanRunAIAPI: RAG использован, источников: " . count($result['sources'] ?? []));
            }
            
            // Возвращаем план в формате JSON строки
            return json_encode($result['plan'], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Retry только для временных ошибок
            $isRetryable = strpos($errorMessage, 'timeout') !== false ||
                          strpos($errorMessage, 'Connection') !== false ||
                          ($httpCode >= 500 && $httpCode < 600);
            
            if ($isRetryable && $attempt < $maxRetries) {
                error_log("callPlanRunAIAPI: Временная ошибка, повтор через $retryDelay сек");
                continue;
            }
            
            // Постоянные ошибки или последняя попытка
            error_log("callPlanRunAIAPI: Ошибка после $attempt попыток: $errorMessage");
            throw $e;
        }
    }
    
    throw new Exception("Не удалось получить ответ от PlanRun AI API после $maxRetries попыток");
}

/**
 * Единственная функция для вызова AI API - использует только локальную LLM (PlanRun AI)
 *
 * @param string $prompt Промпт
 * @param array $userData Данные пользователя
 * @param int $maxRetries Попытки при ошибке
 * @param int|null $userId ID пользователя (AI загрузит данные из MySQL при наличии)
 */
function callAIAPI($prompt, $userData, $maxRetries = 3, $userId = null) {
    // Используем только локальную LLM через PlanRun AI API
    if (!USE_PLANRUN_AI) {
        throw new Exception("PlanRun AI отключен. Локальная LLM должна быть включена в конфигурации.");
    }
    
    if (!isPlanRunAIAvailable()) {
        throw new Exception("PlanRun AI API недоступен. Проверьте, что сервис запущен на порту 8000.");
    }
    
    error_log("callAIAPI: Используем локальную LLM через PlanRun AI API");
    return callPlanRunAIAPI($prompt, $userData, $maxRetries, $userId);
}
