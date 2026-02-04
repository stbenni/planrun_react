<?php
/**
 * Утилиты для работы с тренировочным планом
 */

/**
 * Определяет неделю и день недели по дате тренировки
 * Возвращает массив с week_number и day_name или null если не найдено
 */
function findTrainingDay($workoutDate, $userId = null) {
    require_once __DIR__ . '/load_training_plan.php';
    require_once __DIR__ . '/user_functions.php';
    
    if ($userId === null) {
        $userId = getCurrentUserId() ?: 1;
    }
    
    $trainingData = loadTrainingPlanForUser($userId);
    
    $workoutDateTime = new DateTime($workoutDate);
    $dayOfWeek = (int)$workoutDateTime->format('N'); // 1 = Пн, 7 = Вс
    
    // Маппинг номера дня на название
    $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $dayName = $dayNames[$dayOfWeek];
    
    // Ищем неделю, в которую попадает дата
    foreach ($trainingData['phases'] as $phase) {
        foreach ($phase['weeks_data'] as $week) {
            $weekStart = new DateTime($week['start_date']);
            $weekEnd = clone $weekStart;
            $weekEnd->modify('+6 days');
            
            // Проверяем, попадает ли дата в эту неделю
            if ($workoutDateTime >= $weekStart && $workoutDateTime <= $weekEnd) {
                return [
                    'week_number' => $week['number'],
                    'day_name' => $dayName,
                    'training_date' => $workoutDate
                ];
            }
        }
    }
    
    return null;
}

/**
 * Автоматически привязывает тренировку к календарю
 * ВАЖНО: Автоматические тренировки из workouts НЕ создают записи в workout_log
 * workout_log используется ТОЛЬКО для ручных тренировок
 * 
 * @return array|false Массив с week_number, day_name, training_date или false если дата вне плана
 */
function linkWorkoutToCalendar($db, $workoutId, $workout, $userId = null) {
    // Если user_id не передан, пытаемся получить из сессии
    if ($userId === null) {
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;
    }
    // Извлекаем дату из start_time
    $workoutDate = date('Y-m-d', strtotime($workout['start_time']));
    
    // Находим соответствующий день в плане (из БД)
    $trainingDay = findTrainingDay($workoutDate, $userId);
    
    if (!$trainingDay) {
        // Дата не попадает ни в одну неделю плана
        return false;
    }
    
    // Автоматические тренировки из workouts НЕ создают записи в workout_log
    // workout_log используется ТОЛЬКО для ручных тренировок
    // Автоматические тренировки отображаются напрямую из workouts по дате
    
    return $trainingDay;
}

/**
 * Форматирует длительность в минутах в строку вида "1:23:45" или "45:30"
 */
function formatDuration($minutes) {
    $totalSeconds = $minutes * 60;
    $hours = floor($totalSeconds / 3600);
    $mins = floor(($totalSeconds % 3600) / 60);
    $secs = $totalSeconds % 60;
    
    if ($hours > 0) {
        return sprintf("%d:%02d:%02d", $hours, $mins, $secs);
    } else {
        return sprintf("%d:%02d", $mins, $secs);
    }
}

