<?php
/**
 * Загрузка тренировочного плана из БД
 * УПРОЩЁННАЯ версия БЕЗ фаз - загружаем недели напрямую по user_id
 * С поддержкой кеширования для оптимизации
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/user_functions.php';
require_once __DIR__ . '/cache_config.php';
require_once __DIR__ . '/config/Logger.php';

/**
 * Загружает тренировочный план для указанного пользователя
 * С поддержкой кеширования для оптимизации
 * 
 * @param int $userId ID пользователя
 * @param bool $useCache Использовать ли кеш (по умолчанию true)
 * @return array - структура с недельми (для совместимости обёрнута в phases[0])
 */
function loadTrainingPlanForUser($userId, $useCache = true) {
    // Пытаемся получить из кеша
    if ($useCache) {
        $cacheKey = "training_plan_{$userId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Logger::debug("Training plan loaded from cache", ['user_id' => $userId]);
            return $cached;
        }
    }
    
    $db = getDBConnection();

    // Загружаем недели напрямую по user_id (без фаз!)
    $weeksQuery = "SELECT * FROM training_plan_weeks WHERE user_id = ? ORDER BY week_number";
    $stmt = $db->prepare($weeksQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $weeksResult = $stmt->get_result();

    $weeks_data = [];
    $runningTypes = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'marathon', 'control', 'race'];
    $dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    
    while ($week = $weeksResult->fetch_assoc()) {
        $weekId = $week['id'];
        $weekNumber = $week['week_number'];
        
        // Загружаем дни для этой недели
        $daysQuery = "SELECT * FROM training_plan_days WHERE user_id = ? AND week_id = ? ORDER BY day_of_week";
        $dayStmt = $db->prepare($daysQuery);
        $dayStmt->bind_param("ii", $userId, $weekId);
        $dayStmt->execute();
        $daysResult = $dayStmt->get_result();
        
        $days = [
            'mon' => null,
            'tue' => null,
            'wed' => null,
            'thu' => null,
            'fri' => null,
            'sat' => null,
            'sun' => null
        ];
        
        // Пересчитываем total_volume с учетом упражнений
        $calculatedVolume = 0;
        
        while ($day = $daysResult->fetch_assoc()) {
            $dayOfWeek = $day['day_of_week'] - 1; // 1=Mon -> 0
            if ($dayOfWeek >= 0 && $dayOfWeek < 7) {
                $dayName = $dayNames[$dayOfWeek];
                $dayId = $day['id'];
                
                $days[$dayName] = [
                    'type' => $day['type'],
                    'text' => $day['description']
                ];
                
                if ($day['is_key_workout']) {
                    $days[$dayName]['key'] = true;
                }
                
                // Считаем дистанцию из exercises для беговых тренировок
                if (in_array($day['type'], $runningTypes)) {
                    $exercisesQuery = "SELECT distance_m FROM training_day_exercises 
                                       WHERE user_id = ? AND plan_day_id = ? AND category = 'run' AND distance_m IS NOT NULL";
                    $exercisesStmt = $db->prepare($exercisesQuery);
                    $exercisesStmt->bind_param("ii", $userId, $dayId);
                    $exercisesStmt->execute();
                    $exercisesResult = $exercisesStmt->get_result();
                    
                    while ($exercise = $exercisesResult->fetch_assoc()) {
                        if ($exercise['distance_m']) {
                            $calculatedVolume += (float)$exercise['distance_m'] / 1000;
                        }
                    }
                    $exercisesStmt->close();
                }
            }
        }
        $dayStmt->close();
        
        // Объём: вычисленный или из БД
        if ($calculatedVolume > 0) {
            $finalVolume = round($calculatedVolume, 1) . ' км';
        } elseif (!empty($week['total_volume']) && trim($week['total_volume']) !== '') {
            $finalVolume = $week['total_volume'];
        } else {
            $finalVolume = '';
        }
        
        $weeks_data[] = [
            'number' => $weekNumber,
            'start_date' => $week['start_date'],
            'total_volume' => $finalVolume,
            'days' => $days
        ];
    }
    $stmt->close();
    
    // Для совместимости с UI оборачиваем в phases[0]
    // UI пока ожидает структуру с фазами, но мы используем только одну "виртуальную" фазу
    $result = [
        'phases' => [[
            'id' => 1,
            'name' => 'План тренировок',
            'period' => '',
            'weeks' => count($weeks_data),
            'goal' => '',
            'weeks_data' => $weeks_data
        ]]
    ];
    
    // Кешируем результат (15 минут - план может изменяться)
    if ($useCache) {
        $cacheKey = "training_plan_{$userId}";
        Cache::set($cacheKey, $result, 900);
        Logger::debug("Training plan loaded from DB and cached", [
            'user_id' => $userId,
            'weeks_count' => count($weeks_data)
        ]);
    }
    
    return $result;
}

// Для обратной совместимости: если вызывается напрямую без параметра
if (!function_exists('getCurrentUserId')) {
    die('Error: user_functions.php not loaded');
}

return loadTrainingPlanForUser(getCurrentUserId());
