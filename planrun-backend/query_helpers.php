<?php
/**
 * Централизованные SQL запросы для избежания дублирования
 * Создан: 15 декабря 2025
 */

require_once __DIR__ . '/db_config.php';

/**
 * Получить общее количество тренировочных дней (исключая отдых)
 * 
 * @param mysqli $db Подключение к БД
 * @param int $userId ID пользователя
 * @return int Количество тренировочных дней
 */
function getTotalTrainingDays($db, $userId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM training_plan_days tpd
        INNER JOIN training_plan_weeks tpw ON tpd.week_id = tpw.id
        WHERE tpd.user_id = ? 
        AND tpd.type != 'rest'
        AND tpw.user_id = ?
    ");
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['total'] ?? 0;
}

/**
 * Получить ключи завершенных дней из workout_log
 * 
 * @param mysqli $db Подключение к БД
 * @param int $userId ID пользователя
 * @return array Ассоциативный массив ['date-week-day' => true]
 */
function getCompletedDaysKeys($db, $userId) {
    $stmt = $db->prepare("
        SELECT DISTINCT CONCAT(training_date, '-', week_number, '-', day_name) as day_key
        FROM workout_log
        WHERE user_id = ? AND is_completed = 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $keys = [];
    while ($row = $result->fetch_assoc()) {
        $keys[$row['day_key']] = true;
    }
    $stmt->close();
    return $keys;
}

/**
 * Проверить, является ли пользователь тренером целевого пользователя
 * 
 * @param mysqli $db Подключение к БД
 * @param int $targetUserId ID целевого пользователя (спортсмена)
 * @param int $currentUserId ID текущего пользователя (возможно, тренера)
 * @return array|null ['can_edit' => bool, 'can_view' => bool] или null если не тренер
 */
function getUserCoachAccess($db, $targetUserId, $currentUserId) {
    $stmt = $db->prepare("
        SELECT can_edit, can_view 
        FROM user_coaches 
        WHERE user_id = ? AND coach_id = ?
    ");
    $stmt->bind_param("ii", $targetUserId, $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$result) {
        return null; // Не является тренером
    }
    
    return [
        'can_edit' => (bool)$result['can_edit'],
        'can_view' => (bool)$result['can_view']
    ];
}

/**
 * Проверить, является ли пользователь тренером (простая проверка)
 * 
 * @param mysqli $db Подключение к БД
 * @param int $targetUserId ID целевого пользователя
 * @param int $currentUserId ID текущего пользователя
 * @return bool true если является тренером
 */
function isUserCoach($db, $targetUserId, $currentUserId) {
    $stmt = $db->prepare("
        SELECT id 
        FROM user_coaches 
        WHERE user_id = ? AND coach_id = ?
    ");
    $stmt->bind_param("ii", $targetUserId, $currentUserId);
    $stmt->execute();
    $isCoach = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $isCoach;
}

/**
 * Парсинг и нормализация preferred_days из JSON
 * Поддерживает оба формата для обратной совместимости
 * 
 * @param string|null $json JSON строка из БД
 * @return array|null ['run' => array, 'ofp' => array] или null
 */
function parsePreferredDays($json) {
    if (empty($json)) {
        return null;
    }
    
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !$decoded) {
        return null;
    }
    
    // Проверяем формат
    if (isset($decoded['run']) || isset($decoded['ofp'])) {
        // Новый формат: {"run": [...], "ofp": [...]}
        return [
            'run' => $decoded['run'] ?? [],
            'ofp' => $decoded['ofp'] ?? []
        ];
    } else {
        // Старый формат: простой массив ["mon", "wed", "fri"]
        // Конвертируем в новый формат (считаем что это дни для бега)
        return [
            'run' => is_array($decoded) ? $decoded : [],
            'ofp' => []
        ];
    }
}

/**
 * Форматирование preferred_days для отображения
 * 
 * @param array|null $preferredDays ['run' => array, 'ofp' => array]
 * @return string Читаемая строка
 */
function formatPreferredDays($preferredDays) {
    if (!$preferredDays) {
        return 'Не указаны';
    }
    
    $dayNames = [
        'mon' => 'Пн',
        'tue' => 'Вт',
        'wed' => 'Ср',
        'thu' => 'Чт',
        'fri' => 'Пт',
        'sat' => 'Сб',
        'sun' => 'Вс'
    ];
    
    $result = [];
    
    if (!empty($preferredDays['run'])) {
        $runDays = array_map(function($day) use ($dayNames) {
            return $dayNames[$day] ?? $day;
        }, $preferredDays['run']);
        $result[] = 'Бег: ' . implode(', ', $runDays);
    }
    
    if (!empty($preferredDays['ofp'])) {
        $ofpDays = array_map(function($day) use ($dayNames) {
            return $dayNames[$day] ?? $day;
        }, $preferredDays['ofp']);
        $result[] = 'ОФП: ' . implode(', ', $ofpDays);
    }
    
    return !empty($result) ? implode('; ', $result) : 'Не указаны';
}

/**
 * Получить даты недели тренировочного плана
 * 
 * @param mysqli $db Подключение к БД
 * @param int $userId ID пользователя
 * @param int $weekNumber Номер недели
 * @return array|null ['start' => DateTime, 'end' => DateTime] или null
 */
function getWeekDates($db, $userId, $weekNumber) {
    $stmt = $db->prepare("
        SELECT start_date 
        FROM training_plan_weeks 
        WHERE user_id = ? AND week_number = ?
    ");
    $stmt->bind_param("ii", $userId, $weekNumber);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$result || !$result['start_date']) {
        return null;
    }
    
    try {
        $start = new DateTime($result['start_date']);
        $end = clone $start;
        $end->modify('+6 days');
        
        return [
            'start' => $start,
            'end' => $end
        ];
    } catch (Exception $e) {
        return null;
    }
}


