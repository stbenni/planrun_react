<?php
/**
 * Создание пустого календаря для режима самостоятельных тренировок
 */

require_once __DIR__ . '/../db_config.php';

/**
 * Создает пустой календарь тренировок
 * 
 * @param int $userId ID пользователя
 * @param string $startDate Дата начала (YYYY-MM-DD)
 * @param string|null $endDate Дата окончания (YYYY-MM-DD) или null
 * @return void
 * @throws Exception
 */
function createEmptyPlan($userId, $startDate, $endDate = null) {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Ошибка подключения к БД");
    }
    
    $start = new DateTime($startDate);
    
    // Если endDate не указана, создаем план на 12 недель
    if ($endDate) {
        $end = new DateTime($endDate);
        $weeks = ceil(($end->getTimestamp() - $start->getTimestamp()) / (7 * 24 * 60 * 60));
    } else {
        $weeks = 12;
    }
    
    // Удаляем старый план
    $deleteDaysStmt = $db->prepare("DELETE FROM training_plan_days WHERE user_id = ?");
    $deleteDaysStmt->bind_param('i', $userId);
    $deleteDaysStmt->execute();
    $deleteDaysStmt->close();
    
    $deleteWeeksStmt = $db->prepare("DELETE FROM training_plan_weeks WHERE user_id = ?");
    $deleteWeeksStmt->bind_param('i', $userId);
    $deleteWeeksStmt->execute();
    $deleteWeeksStmt->close();
    
    // Находим понедельник
    $dayOfWeek = (int)$start->format('N'); // 1 = Пн, 7 = Вс
    if ($dayOfWeek > 1) {
        $start->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    
    // Создаем пустые недели
    for ($weekNum = 1; $weekNum <= $weeks; $weekNum++) {
        $weekStart = clone $start;
        $weekStart->modify('+' . (($weekNum - 1) * 7) . ' days');
        $weekStartStr = $weekStart->format('Y-m-d');
        
        // Вставляем неделю
        $insertWeekStmt = $db->prepare("
            INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume)
            VALUES (?, ?, ?, 0)
        ");
        $insertWeekStmt->bind_param('iis', $userId, $weekNum, $weekStartStr);
        $insertWeekStmt->execute();
        $weekId = $db->insert_id;
        $insertWeekStmt->close();
        
        if (!$weekId) {
            throw new Exception("Ошибка создания недели {$weekNum}");
        }
        
        // Создаем пустые дни: type='free' = «свободный день» (добавить тренировку), не «отдых»
        for ($dayNum = 1; $dayNum <= 7; $dayNum++) {
            $dayDate = clone $weekStart;
            $dayDate->modify('+' . ($dayNum - 1) . ' days');
            $dayDateStr = $dayDate->format('Y-m-d');
            
            $insertDayStmt = $db->prepare("
                INSERT INTO training_plan_days 
                (user_id, week_id, day_of_week, type, description, is_key_workout, date)
                VALUES (?, ?, ?, 'free', '', 0, ?)
            ");
            $insertDayStmt->bind_param('iiis', $userId, $weekId, $dayNum, $dayDateStr);
            $insertDayStmt->execute();
            $insertDayStmt->close();
        }
    }
    
    error_log("createEmptyPlan: Пустой календарь создан для пользователя {$userId}, недель: {$weeks}");
}
