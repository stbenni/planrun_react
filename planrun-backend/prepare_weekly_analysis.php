<?php
/**
 * Подготовка данных для еженедельного анализа нейросетью
 * Формирует структурированный JSON с планом, фактом и метриками
 * 
 * Использование:
 *   php prepare_weekly_analysis.php [user_id] [week_number]
 *   или через API: api.php?action=prepare_weekly_analysis&week=X&user_id=Y
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/load_training_plan.php';
require_once __DIR__ . '/user_functions.php';

/**
 * Подготовка данных недели для анализа
 */
function prepareWeeklyAnalysis($userId, $weekNumber = null) {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Ошибка подключения к БД');
    }
    
    // Если неделя не указана, берем текущую
    if ($weekNumber === null) {
        $weekNumber = getCurrentWeekNumber($userId, $db);
    }
    
    // Получаем данные пользователя
    $userStmt = $db->prepare("
        SELECT id, username, target_marathon_date, target_marathon_time, 
               birth_year, height_cm, weight_kg, experience_level, weekly_base_km,
               preferred_days, goal_type, race_date, race_target_time, race_distance
        FROM users 
        WHERE id = ?
    ");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }
    
    // Распарсим preferred_days (могут быть:
    // 1) массив дней ['mon','wed','fri']
    // 2) объект с типами тренировок {"run":["wed","fri","sun"],"ofp":["tue","thu","sat"]}
    $preferredDays = null;
    if (!empty($user['preferred_days'])) {
        $decoded = json_decode($user['preferred_days'], true);
        if (json_last_error() === JSON_ERROR_NONE && $decoded) {
            $preferredDays = $decoded;
        }
    }
    
    // Получаем план на неделю (БЕЗ фаз)
    $weekStmt = $db->prepare("
        SELECT tpw.id, tpw.week_number, tpw.start_date, tpw.total_volume
        FROM training_plan_weeks tpw
        WHERE tpw.user_id = ? AND tpw.week_number = ?
    ");
    $weekStmt->bind_param("ii", $userId, $weekNumber);
    $weekStmt->execute();
    $weekData = $weekStmt->get_result()->fetch_assoc();
    $weekStmt->close();
    
    if (!$weekData) {
        throw new Exception("Неделя $weekNumber не найдена в плане");
    }
    
    // Получаем план на каждый день недели
    $daysStmt = $db->prepare("
        SELECT day_of_week, type, description, date, is_key_workout
        FROM training_plan_days
        WHERE user_id = ? AND week_id = ?
        ORDER BY day_of_week
    ");
    $daysStmt->bind_param("ii", $userId, $weekData['id']);
    $daysStmt->execute();
    $daysResult = $daysStmt->get_result();
    $plannedDays = [];
    while ($day = $daysResult->fetch_assoc()) {
        $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $dayName = $dayNames[$day['day_of_week']];
        $plannedDays[$dayName] = [
            'date' => $day['date'],
            'type' => $day['type'],
            'description' => strip_tags($day['description']),
            'is_key_workout' => (bool)$day['is_key_workout']
        ];
    }
    $daysStmt->close();
    
    // Получаем фактические результаты за неделю из workout_log (ручные тренировки)
    $actualStmt = $db->prepare("
        SELECT wl.training_date, wl.day_name,
               wl.activity_type_id, at.name as activity_type_name,
               wl.is_successful, wl.result_time, wl.distance_km, wl.pace,
               wl.duration_minutes, wl.avg_heart_rate, wl.max_heart_rate,
               wl.avg_cadence, wl.elevation_gain, wl.calories, wl.notes
        FROM workout_log wl
        LEFT JOIN activity_types at ON wl.activity_type_id = at.id
        WHERE wl.user_id = ? AND wl.week_number = ?
        ORDER BY wl.training_date, wl.day_name
    ");
    $actualStmt->bind_param("ii", $userId, $weekNumber);
    $actualStmt->execute();
    $actualResult = $actualStmt->get_result();
    $actualDays = [];
    while ($actual = $actualResult->fetch_assoc()) {
        $dayName = $actual['day_name'];
        if (!isset($actualDays[$dayName])) {
            $actualDays[$dayName] = [];
        }
        $actualDays[$dayName][] = [
            'date' => $actual['training_date'],
            'activity_type' => $actual['activity_type_name'],
            'is_successful' => $actual['is_successful'] === 1,
            'result_time' => $actual['result_time'],
            'distance_km' => $actual['distance_km'] ? (float)$actual['distance_km'] : null,
            'pace' => $actual['pace'],
            'duration_minutes' => $actual['duration_minutes'],
            'avg_heart_rate' => $actual['avg_heart_rate'],
            'max_heart_rate' => $actual['max_heart_rate'],
            'avg_cadence' => $actual['avg_cadence'],
            'elevation_gain' => $actual['elevation_gain'],
            'calories' => $actual['calories'],
            'notes' => $actual['notes'],
            'source' => 'manual' // Ручная тренировка
        ];
    }
    $actualStmt->close();
    
    // Получаем автоматические тренировки из workouts и сопоставляем с днями недели
    $weekStart = $weekData['start_date'];
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $autoWorkoutsStmt = $db->prepare("
        SELECT id, activity_type, start_time, end_time, duration_minutes, distance_km,
               avg_pace, avg_heart_rate, max_heart_rate, elevation_gain
        FROM workouts
        WHERE user_id = ?
          AND DATE(start_time) BETWEEN ? AND ?
        ORDER BY start_time ASC
        LIMIT 50
    ");
    $autoWorkoutsStmt->bind_param("iss", $userId, $weekStart, $weekEnd);
    $autoWorkoutsStmt->execute();
    $autoResult = $autoWorkoutsStmt->get_result();
    
    // Маппинг дат на дни недели для этой недели
    $dateToDayName = [];
    $dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
        $dateToDayName[$date] = $dayNames[$i];
    }
    
    while ($auto = $autoResult->fetch_assoc()) {
        $workoutDate = date('Y-m-d', strtotime($auto['start_time']));
        $dayName = $dateToDayName[$workoutDate] ?? null;
        
        if ($dayName) {
            if (!isset($actualDays[$dayName])) {
                $actualDays[$dayName] = [];
            }
            $actualDays[$dayName][] = [
                'date' => $workoutDate,
                'activity_type' => $auto['activity_type'] ?? 'running',
                'is_successful' => true,
                'result_time' => null,
                'distance_km' => $auto['distance_km'] ? (float)$auto['distance_km'] : null,
                'pace' => $auto['avg_pace'],
                'duration_minutes' => $auto['duration_minutes'],
                'avg_heart_rate' => $auto['avg_heart_rate'],
                'max_heart_rate' => $auto['max_heart_rate'],
                'avg_cadence' => null,
                'elevation_gain' => $auto['elevation_gain'],
                'calories' => null,
                'notes' => null,
                'source' => 'automatic' // Автоматическая тренировка из GPX/TCX
            ];
        }
    }
    $autoWorkoutsStmt->close();
    
    // Формируем структуру для анализа
    $analysis = [
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'target_marathon_date' => $user['target_marathon_date'],
            'target_marathon_time' => $user['target_marathon_time'],
            'goal_type' => $user['goal_type'] ?? null,
            'race_date' => $user['race_date'] ?? null,
            'race_target_time' => $user['race_target_time'] ?? null,
            'race_distance' => $user['race_distance'] ?? null,
            'birth_year' => $user['birth_year'],
            'height_cm' => $user['height_cm'],
            'weight_kg' => $user['weight_kg'],
            'experience_level' => $user['experience_level'],
            'weekly_base_km' => $user['weekly_base_km'],
            // decoded JSON: либо массив дней, либо объект с ключами run/ofp и т.п.
            'preferred_days' => $preferredDays,
        ],
        'week' => [
            'number' => $weekNumber,
            'start_date' => $weekData['start_date'],
            'planned_volume_km' => $weekData['total_volume'] ? (float)$weekData['total_volume'] : null,
            'phase' => [
                'number' => 1,
                'name' => 'План тренировок',
                'goal' => ''
            ]
        ],
        'days' => []
    ];
    
    // Объединяем план и факт по дням
    $dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    foreach ($dayNames as $dayName) {
        $planned = $plannedDays[$dayName] ?? null;
        $actual = $actualDays[$dayName] ?? [];
        
        $dayAnalysis = [
            'day_name' => $dayName,
            'planned' => $planned,
            'actual' => $actual,
            'completed' => !empty($actual),
            'compliance' => null // Будет вычисляться нейросетью
        ];
        
        // Вычисляем базовое соответствие
        if ($planned && !empty($actual)) {
            $dayAnalysis['compliance'] = [
                'type_match' => $planned['type'] === 'other' || !empty($actual), // Для бега проверяем наличие
                'has_metrics' => !empty(array_filter($actual, function($a) {
                    return $a['distance_km'] !== null || $a['avg_heart_rate'] !== null;
                }))
            ];
        }
        
        $analysis['days'][] = $dayAnalysis;
    }
    
    // Вычисляем статистику недели
    $totalDistance = 0;
    $totalDuration = 0;
    $avgHeartRate = [];
    $totalCalories = 0;
    $completedDays = 0;
    
    foreach ($actualDays as $dayActuals) {
        foreach ($dayActuals as $actual) {
            if ($actual['distance_km']) {
                $totalDistance += $actual['distance_km'];
            }
            if ($actual['duration_minutes']) {
                $totalDuration += $actual['duration_minutes'];
            }
            if ($actual['avg_heart_rate']) {
                $avgHeartRate[] = $actual['avg_heart_rate'];
            }
            if ($actual['calories']) {
                $totalCalories += $actual['calories'];
            }
        }
        $completedDays++;
    }
    
    $analysis['statistics'] = [
        'actual_volume_km' => round($totalDistance, 2),
        'actual_duration_minutes' => $totalDuration,
        'avg_heart_rate' => !empty($avgHeartRate) ? round(array_sum($avgHeartRate) / count($avgHeartRate)) : null,
        'total_calories' => $totalCalories,
        'completed_days' => $completedDays,
        'planned_days' => count(array_filter($plannedDays)),
        'completion_rate' => count($plannedDays) > 0 ? round($completedDays / count($plannedDays) * 100, 1) : 0
    ];
    
    return $analysis;
}

/**
 * Получить номер текущей недели
 */
function getCurrentWeekNumber($userId, $db) {
    $today = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT week_number 
        FROM training_plan_weeks tpw
        WHERE tpw.user_id = ? AND ? BETWEEN tpw.start_date AND DATE_ADD(tpw.start_date, INTERVAL 6 DAY)
        LIMIT 1
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $week = $result->fetch_assoc();
    $stmt->close();
    
    return $week ? $week['week_number'] : 1;
}

/**
 * Подготовка данных ВСЕГО ПЛАНА для анализа
 * Загружает весь план (все фазы, все недели) и все выполненные тренировки
 * Используется для полной адаптации курса на основе всех выполненных тренировок
 * 
 * @param int $userId ID пользователя
 * @return array Структурированные данные для анализа всего плана
 */
function prepareFullPlanAnalysis($userId) {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Ошибка подключения к БД');
    }
    
    // Получаем ВСЕ данные пользователя для полного анализа используя getUserData()
    require_once __DIR__ . '/user_functions.php';
    $fields = 'id, username, email, timezone, target_marathon_date, target_marathon_time, 
               birth_year, height_cm, weight_kg, gender, experience_level, weekly_base_km,
               preferred_days, preferred_ofp_days, goal_type, race_date, race_target_time, race_distance,
               health_notes, training_start_date, training_mode, sessions_per_week,
               has_treadmill, ofp_preference, training_time_pref, device_type,
               weight_goal_kg, weight_goal_date, health_program, health_plan_weeks,
               current_running_level, last_race_distance, last_race_distance_km,
               last_race_time, last_race_date, is_first_race_at_distance,
               easy_pace_sec, running_experience';
    $user = getUserData($userId, $fields);
    
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }
    
    // Распарсим preferred_days (может быть объект {run: [], ofp: []} или массив)
    $preferredDays = null;
    if (!empty($user['preferred_days'])) {
        $decoded = json_decode($user['preferred_days'], true);
        if (json_last_error() === JSON_ERROR_NONE && $decoded) {
            // Если это новый формат с run/ofp
            if (is_array($decoded) && (isset($decoded['run']) || isset($decoded['ofp']))) {
            $preferredDays = $decoded;
            } else {
                // Старый формат - просто массив дней (для обратной совместимости)
                $preferredDays = ['run' => $decoded, 'ofp' => []];
            }
        }
    }
    
    // Также проверяем preferred_ofp_days (для совместимости)
    if (empty($preferredDays['ofp']) && !empty($user['preferred_ofp_days'])) {
        $ofpDecoded = json_decode($user['preferred_ofp_days'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($ofpDecoded)) {
            if (!$preferredDays) {
                $preferredDays = ['run' => [], 'ofp' => []];
            }
            $preferredDays['ofp'] = $ofpDecoded;
        }
    }
    
    // Загружаем ВЕСЬ план (БЕЗ фаз - только недели)
    $weeksStmt = $db->prepare("
        SELECT id, week_number, start_date, total_volume
        FROM training_plan_weeks
        WHERE user_id = ?
        ORDER BY week_number ASC
    ");
    $weeksStmt->bind_param("i", $userId);
    $weeksStmt->execute();
    $weeksResult = $weeksStmt->get_result();
    
    $fullPlan = [];
    $processedWeeks = [];
    
    // Обрабатываем все недели (без группировки по фазам)
    while ($week = $weeksResult->fetch_assoc()) {
        $weekId = $week['id'];
        $weekNumber = $week['week_number'];
        
        // Загружаем все дни этой недели
        $daysStmt = $db->prepare("
            SELECT id, day_of_week, type, description, date, is_key_workout
            FROM training_plan_days
            WHERE user_id = ? AND week_id = ?
            ORDER BY day_of_week
        ");
        $daysStmt->bind_param("ii", $userId, $weekId);
        $daysStmt->execute();
        $daysResult = $daysStmt->get_result();
        
        $days = [];
        $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        while ($day = $daysResult->fetch_assoc()) {
            $dayName = $dayNames[$day['day_of_week']];
            $dayId = $day['id'];
            
            // Загружаем exercises для этого дня
            $exercisesStmt = $db->prepare("
                SELECT category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index
                FROM training_day_exercises
                WHERE user_id = ? AND plan_day_id = ?
                ORDER BY order_index ASC
            ");
            $exercisesStmt->bind_param("ii", $userId, $dayId);
            $exercisesStmt->execute();
            $exercisesResult = $exercisesStmt->get_result();
            
            $exercises = [];
            while ($exercise = $exercisesResult->fetch_assoc()) {
                $exercises[] = [
                    'category' => $exercise['category'],
                    'name' => $exercise['name'],
                    'sets' => $exercise['sets'],
                    'reps' => $exercise['reps'],
                    'distance_m' => $exercise['distance_m'],
                    'duration_sec' => $exercise['duration_sec'],
                    'weight_kg' => $exercise['weight_kg'],
                    'pace' => $exercise['pace'],
                    'notes' => $exercise['notes'],
                    'order_index' => $exercise['order_index']
                ];
            }
            $exercisesStmt->close();
            
            $days[$dayName] = [
                'day_name' => $dayName,
                'date' => $day['date'],
                'type' => $day['type'],
                'description' => strip_tags($day['description']),
                'is_key_workout' => (bool)$day['is_key_workout'],
                'exercises' => $exercises
            ];
        }
        $daysStmt->close();
        
        $processedWeeks[] = [
            'week_number' => $weekNumber,
            'start_date' => $week['start_date'],
            'total_volume' => $week['total_volume'],
            'days' => $days
        ];
    }
    $weeksStmt->close();
    
    // Формируем виртуальную фазу для совместимости с UI
    if (!empty($processedWeeks)) {
        $fullPlan[] = [
            'phase_number' => 1,
            'name' => 'План тренировок',
            'period' => '',
            'weeks_count' => count($processedWeeks),
            'goal' => '',
            'weeks' => $processedWeeks
        ];
    }
    
    // Находим дату начала плана (самая ранняя start_date из всех недель)
    // ВАЖНО: Тренировки ДО этой даты не учитываются при анализе выполнения плана
    $planStartDate = null;
    
    // Используем processedWeeks напрямую (БЕЗ фаз)
    foreach ($processedWeeks as $week) {
        if (!empty($week['start_date'])) {
            if ($planStartDate === null || $week['start_date'] < $planStartDate) {
                $planStartDate = $week['start_date'];
            }
        }
    }
    
    // Если не нашли в processedWeeks, пробуем через fullPlan (для обратной совместимости)
    if (!$planStartDate) {
        foreach ($fullPlan as $phase) {
            if (isset($phase['weeks']) && is_array($phase['weeks'])) {
                foreach ($phase['weeks'] as $week) {
                    if (!empty($week['start_date'])) {
                        if ($planStartDate === null || $week['start_date'] < $planStartDate) {
                            $planStartDate = $week['start_date'];
                        }
                    }
                }
            }
        }
    }
    
    if (!$planStartDate) {
        throw new Exception('Не удалось определить дату начала плана. У пользователя нет недель в плане.');
    }
    
    // Загружаем ВСЕ тренировки (для анализа формы/состояния спортсмена)
    // Включая тренировки ДО начала плана - это важно для понимания текущей формы
    $allManualStmt = $db->prepare("
        SELECT wl.training_date, wl.week_number, wl.day_name,
               wl.activity_type_id, at.name as activity_type_name,
               wl.is_successful, wl.result_time, wl.distance_km, wl.pace,
               wl.duration_minutes, wl.avg_heart_rate, wl.max_heart_rate,
               wl.avg_cadence, wl.elevation_gain, wl.calories, wl.notes
        FROM workout_log wl
        LEFT JOIN activity_types at ON wl.activity_type_id = at.id
        WHERE wl.user_id = ? AND wl.is_completed = 1
        ORDER BY wl.training_date ASC
        LIMIT 2000
    ");
    $allManualStmt->bind_param("i", $userId);
    $allManualStmt->execute();
    $allManualResult = $allManualStmt->get_result();
    
    $allManualWorkouts = [];
    while ($workout = $allManualResult->fetch_assoc()) {
        $allManualWorkouts[] = [
            'date' => $workout['training_date'],
            'week_number' => $workout['week_number'],
            'day_name' => $workout['day_name'],
            'activity_type' => $workout['activity_type_name'],
            'is_successful' => $workout['is_successful'] === 1,
            'result_time' => $workout['result_time'],
            'distance_km' => $workout['distance_km'] ? (float)$workout['distance_km'] : null,
            'pace' => $workout['pace'],
            'duration_minutes' => $workout['duration_minutes'],
            'avg_heart_rate' => $workout['avg_heart_rate'],
            'max_heart_rate' => $workout['max_heart_rate'],
            'avg_cadence' => $workout['avg_cadence'],
            'elevation_gain' => $workout['elevation_gain'],
            'calories' => $workout['calories'],
            'notes' => $workout['notes'],
            'source' => 'manual'
        ];
    }
    $allManualStmt->close();
    
    // Загружаем ВСЕ автоматические тренировки (для анализа формы)
    $allAutoStmt = $db->prepare("
        SELECT id, activity_type, start_time, end_time, duration_minutes, distance_km,
               avg_pace, avg_heart_rate, max_heart_rate, elevation_gain
        FROM workouts
        WHERE user_id = ?
        ORDER BY start_time ASC
        LIMIT 2000
    ");
    $allAutoStmt->bind_param("i", $userId);
    $allAutoStmt->execute();
    $allAutoResult = $allAutoStmt->get_result();
    
    $allAutoWorkouts = [];
    while ($workout = $allAutoResult->fetch_assoc()) {
        $workoutDate = date('Y-m-d', strtotime($workout['start_time']));
        $allAutoWorkouts[] = [
            'id' => $workout['id'],
            'date' => $workoutDate,
            'activity_type' => $workout['activity_type'] ?? 'running',
            'is_successful' => true,
            'result_time' => null,
            'distance_km' => $workout['distance_km'] ? (float)$workout['distance_km'] : null,
            'pace' => $workout['avg_pace'],
            'duration_minutes' => $workout['duration_minutes'],
            'avg_heart_rate' => $workout['avg_heart_rate'],
            'max_heart_rate' => $workout['max_heart_rate'],
            'avg_cadence' => null,
            'elevation_gain' => $workout['elevation_gain'],
            'calories' => null,
            'notes' => null,
            'source' => 'automatic'
        ];
    }
    $allAutoStmt->close();
    
    // Объединяем все тренировки (для анализа формы/состояния)
    $allWorkouts = array_merge($allManualWorkouts, $allAutoWorkouts);
    
    // Фильтруем тренировки для подсчета выполнения плана (только с даты начала плана)
    $planWorkouts = array_filter($allWorkouts, function($workout) use ($planStartDate) {
        return $workout['date'] >= $planStartDate;
    });
    
    // Вычисляем статистику по тренировкам С ДАТЫ НАЧАЛА ПЛАНА (для подсчета выполнения)
    $totalDistance = 0;
    $totalDuration = 0;
    $avgHeartRate = [];
    $totalCalories = 0;
    $totalPlanWorkouts = count($planWorkouts);
    
    foreach ($planWorkouts as $workout) {
        if ($workout['distance_km']) {
            $totalDistance += $workout['distance_km'];
        }
        if ($workout['duration_minutes']) {
            $totalDuration += $workout['duration_minutes'];
        }
        if ($workout['avg_heart_rate']) {
            $avgHeartRate[] = $workout['avg_heart_rate'];
        }
        if ($workout['calories']) {
            $totalCalories += $workout['calories'];
        }
    }
    
    // Вычисляем статистику по ВСЕМ тренировкам (для анализа формы/состояния)
    $allTotalDistance = 0;
    $allTotalDuration = 0;
    $allAvgHeartRate = [];
    $allTotalCalories = 0;
    $allTotalWorkouts = count($allWorkouts);
    
    foreach ($allWorkouts as $workout) {
        if ($workout['distance_km']) {
            $allTotalDistance += $workout['distance_km'];
        }
        if ($workout['duration_minutes']) {
            $allTotalDuration += $workout['duration_minutes'];
        }
        if ($workout['avg_heart_rate']) {
            $allAvgHeartRate[] = $workout['avg_heart_rate'];
        }
        if ($workout['calories']) {
            $allTotalCalories += $workout['calories'];
        }
    }
    
    // Формируем итоговую структуру с ВСЕМИ параметрами пользователя
    $analysis = [
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'] ?? null,
            'timezone' => $user['timezone'] ?? 'Europe/Moscow',
            'target_marathon_date' => $user['target_marathon_date'] ?? null,
            'target_marathon_time' => $user['target_marathon_time'] ?? null,
            'goal_type' => $user['goal_type'] ?? 'health',
            'race_date' => $user['race_date'] ?? null,
            'race_target_time' => $user['race_target_time'] ?? null,
            'race_distance' => $user['race_distance'] ?? null,
            'birth_year' => $user['birth_year'] ?? null,
            'height_cm' => $user['height_cm'] ?? null,
            'weight_kg' => $user['weight_kg'] ?? null,
            'gender' => $user['gender'] ?? null,
            'experience_level' => $user['experience_level'] ?? 'beginner',
            'weekly_base_km' => $user['weekly_base_km'] ?? null,
            'sessions_per_week' => $user['sessions_per_week'] ?? null,
            'preferred_days' => $preferredDays,
            'health_notes' => $user['health_notes'] ?? null,
            'training_start_date' => $user['training_start_date'] ?? null,
            'training_mode' => $user['training_mode'] ?? 'ai',
            'has_treadmill' => !empty($user['has_treadmill']),
            'ofp_preference' => $user['ofp_preference'] ?? null,
            'training_time_pref' => $user['training_time_pref'] ?? null,
            'device_type' => $user['device_type'] ?? null,
            'weight_goal_kg' => $user['weight_goal_kg'] ?? null,
            'weight_goal_date' => $user['weight_goal_date'] ?? null,
            'health_program' => $user['health_program'] ?? null,
            'health_plan_weeks' => $user['health_plan_weeks'] ?? null,
            'current_running_level' => $user['current_running_level'] ?? null,
            'last_race_distance' => $user['last_race_distance'] ?? null,
            'last_race_distance_km' => $user['last_race_distance_km'] ?? null,
            'last_race_time' => $user['last_race_time'] ?? null,
            'last_race_date' => $user['last_race_date'] ?? null,
            'is_first_race_at_distance' => !empty($user['is_first_race_at_distance']),
            'easy_pace_sec' => $user['easy_pace_sec'] ?? null,
            'running_experience' => $user['running_experience'] ?? null,
        ],
        'plan_start_date' => $planStartDate,  // Дата начала плана (для фильтрации тренировок)
        'full_plan' => $fullPlan,  // Весь план: все фазы, все недели, все дни
        'all_workouts' => array_values($allWorkouts),  // ВСЕ тренировки (для анализа формы/состояния спортсмена)
        'plan_workouts' => array_values($planWorkouts),  // Только тренировки С ДАТЫ НАЧАЛА ПЛАНА (для подсчета выполнения)
        'overall_statistics' => [
            // Статистика по тренировкам С ДАТЫ НАЧАЛА ПЛАНА (для подсчета выполнения)
            'plan_workouts_count' => $totalPlanWorkouts,
            'plan_total_distance_km' => round($totalDistance, 2),
            'plan_total_duration_minutes' => $totalDuration,
            'plan_avg_heart_rate' => !empty($avgHeartRate) ? round(array_sum($avgHeartRate) / count($avgHeartRate)) : null,
            'plan_total_calories' => $totalCalories,
            // Статистика по ВСЕМ тренировкам (для анализа формы/состояния)
            'all_workouts_count' => $allTotalWorkouts,
            'all_total_distance_km' => round($allTotalDistance, 2),
            'all_total_duration_minutes' => $allTotalDuration,
            'all_avg_heart_rate' => !empty($allAvgHeartRate) ? round(array_sum($allAvgHeartRate) / count($allAvgHeartRate)) : null,
            'all_total_calories' => $allTotalCalories,
            'plan_start_date' => $planStartDate,
        ]
    ];
    
    return $analysis;
}

// Если запускается из командной строки
if (php_sapi_name() === 'cli') {
    $userId = isset($argv[1]) ? (int)$argv[1] : null;
    $weekNumber = isset($argv[2]) ? (int)$argv[2] : null;
    
    if (!$userId) {
        die("Использование: php prepare_weekly_analysis.php [user_id] [week_number]\n");
    }
    
    try {
        $analysis = prepareWeeklyAnalysis($userId, $weekNumber);
        echo json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        exit(1);
    }
}

