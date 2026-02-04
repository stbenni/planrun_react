<?php
/**
 * Генерация текстовых описаний через PlanRun AI
 * Замена для generateTextFromExercises
 */

require_once __DIR__ . '/planrun_ai_integration.php';

/**
 * Генерация текстового описания тренировки из упражнений
 * 
 * @param array $exercises Массив упражнений
 * @param string $type Тип тренировки
 * @return string Текстовое описание
 */
function generateTextFromExercises($exercises, $type = 'easy') {
    if (!isPlanRunAIAvailable()) {
        // Fallback: простое описание без AI
        return generateSimpleDescription($exercises, $type);
    }
    
    // Строим промпт для генерации описания
    $prompt = "Создай краткое описание тренировки на основе упражнений:\n\n";
    $prompt .= "Тип тренировки: {$type}\n\n";
    $prompt .= "Упражнения:\n";
    
    foreach ($exercises as $exercise) {
        if (isset($exercise['distance_m'])) {
            $km = round($exercise['distance_m'] / 1000, 2);
            $prompt .= "- Бег {$km} км";
            if (isset($exercise['pace'])) {
                $prompt .= " в темпе {$exercise['pace']}";
            }
            $prompt .= "\n";
        }
        if (isset($exercise['duration_minutes'])) {
            $prompt .= "- Длительность: {$exercise['duration_minutes']} минут\n";
        }
    }
    
    $prompt .= "\nСоздай краткое описание тренировки (1-2 предложения).";
    
    try {
        $userData = ['goal_type' => 'health'];
        $response = callAIAPI($prompt, $userData, 1);
        
        // Парсим ответ (может быть JSON или просто текст)
        $result = json_decode($response, true);
        if (is_array($result) && isset($result['description'])) {
            return $result['description'];
        }
        
        // Если это просто текст
        if (is_string($response) && strlen($response) < 500) {
            return trim($response);
        }
        
        // Fallback
        return generateSimpleDescription($exercises, $type);
        
    } catch (Exception $e) {
        error_log("generateTextFromExercises: Ошибка: " . $e->getMessage());
        return generateSimpleDescription($exercises, $type);
    }
}

/**
 * Простое описание без AI (fallback)
 */
function generateSimpleDescription($exercises, $type) {
    $totalKm = 0;
    foreach ($exercises as $exercise) {
        if (isset($exercise['distance_m'])) {
            $totalKm += $exercise['distance_m'] / 1000;
        }
    }
    
    $typeNames = [
        'easy' => 'легкий бег',
        'interval' => 'интервальная тренировка',
        'tempo' => 'темповый бег',
        'long' => 'длинная пробежка',
        'fartlek' => 'фартлек',
        'rest' => 'отдых'
    ];
    
    $typeName = $typeNames[$type] ?? $type;
    
    if ($totalKm > 0) {
        return "{$typeName}, {$totalKm} км";
    }
    
    return $typeName;
}
