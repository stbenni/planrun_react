<?php
/**
 * Конфигурация для PlanRun AI
 * Локальная LLM с RAG для генерации планов тренировок
 * 
 * ВАЖНО: Конфигурация загружается из .env файла
 */

// Загружаем переменные окружения
require_once __DIR__ . '/../config/env_loader.php';

// URL PlanRun AI API
define('PLANRUN_AI_API_URL', env('PLANRUN_AI_API_URL', 'http://localhost:8000/api/v1/generate-plan'));

// Таймаут для запросов (секунды)
define('PLANRUN_AI_TIMEOUT', (int)env('PLANRUN_AI_TIMEOUT', 300));

// Использовать PlanRun AI или нет (можно переключать)
define('USE_PLANRUN_AI', env('USE_PLANRUN_AI', 'true') === 'true' || env('USE_PLANRUN_AI', true) === true);

/**
 * Проверка доступности PlanRun AI API
 */
function isPlanRunAIAvailable() {
    $ch = curl_init(PLANRUN_AI_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200 || $httpCode === 405; // 405 = Method Not Allowed (но сервер работает)
}
