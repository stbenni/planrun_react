<?php
/**
 * API для регистрации нового пользователя
 * Полная версия со всеми полями
 * Адаптировано под БД sv
 */

header('Content-Type: application/json; charset=utf-8');

if (!defined('API_CORS_SENT') || !API_CORS_SENT) {
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
            header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        }
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
        }
        http_response_code(204);
        exit(0);
    }
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth.php';

// Валидация поля
if (isset($_GET['action']) && $_GET['action'] === 'validate_field') {
    $field = $_GET['field'] ?? '';
    $value = $_GET['value'] ?? '';
    $result = ['valid' => true, 'message' => ''];
    
    $db = getDBConnection();
    if (!$db) {
        echo json_encode(['valid' => false, 'message' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    switch ($field) {
        case 'username':
            if (empty($value)) {
                $result = ['valid' => false, 'message' => 'Имя пользователя обязательно'];
            } elseif (strlen($value) < 3) {
                $result = ['valid' => false, 'message' => 'Имя пользователя должно быть не менее 3 символов'];
            } elseif (strlen($value) > 50) {
                $result = ['valid' => false, 'message' => 'Имя пользователя должно быть не более 50 символов'];
            } elseif (!preg_match('/^[a-zA-Z0-9_а-яА-ЯёЁ\s-]+$/u', $value)) {
                $result = ['valid' => false, 'message' => 'Имя пользователя может содержать только буквы, цифры, пробелы, дефисы и подчеркивания'];
            } else {
                $checkStmt = $db->prepare('SELECT id FROM users WHERE username = ?');
                $checkStmt->bind_param('s', $value);
                $checkStmt->execute();
                if ($checkStmt->get_result()->fetch_assoc()) {
                    $result = ['valid' => false, 'message' => 'Это имя пользователя уже занято'];
                }
                $checkStmt->close();
            }
            break;
            
        case 'email':
            if (!empty($value)) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $result = ['valid' => false, 'message' => 'Некорректный формат email'];
                } else {
                    $checkStmt = $db->prepare('SELECT id FROM users WHERE email = ? AND email IS NOT NULL AND email != ""');
                    $checkStmt->bind_param('s', $value);
                    $checkStmt->execute();
                    if ($checkStmt->get_result()->fetch_assoc()) {
                        $result = ['valid' => false, 'message' => 'Этот email уже используется'];
                    }
                    $checkStmt->close();
                }
            }
            break;
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Регистрация
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Получаем данные
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $email = trim($input['email'] ?? '');
    
    // Цель и даты
    $goalType = $input['goal_type'] ?? 'health';
    $allowedGoalTypes = ['health', 'race', 'weight_loss', 'time_improvement'];
    if (!in_array($goalType, $allowedGoalTypes)) {
        $goalType = 'health';
    }
    
    // Для забега
    $raceDate = !empty($input['race_date']) ? $input['race_date'] : null;
    $raceDistance = !empty($input['race_distance']) ? $input['race_distance'] : null;
    $raceTargetTime = !empty($input['race_target_time']) ? $input['race_target_time'] : null;
    
    // Для марафона/цели
    $targetMarathonDate = !empty($input['target_marathon_date']) ? $input['target_marathon_date'] : null;
    $targetMarathonTime = !empty($input['target_marathon_time']) ? $input['target_marathon_time'] : null;
    
    // Дата начала тренировок
    $trainingStartDate = !empty($input['training_start_date']) ? $input['training_start_date'] : null;
    
    // Профиль
    $allowedGenders = ['male', 'female'];
    $gender = null;
    if (!empty($input['gender']) && in_array($input['gender'], $allowedGenders, true)) {
        $gender = $input['gender'];
    }
    
    $birthYear = !empty($input['birth_year']) ? (int)$input['birth_year'] : null;
    $heightCm = !empty($input['height_cm']) ? (int)$input['height_cm'] : null;
    $weightKg = !empty($input['weight_kg']) ? (float)$input['weight_kg'] : null;
    
    // Опыт
    $allowedExperienceLevels = ['beginner', 'intermediate', 'advanced'];
    $experienceLevel = $input['experience_level'] ?? 'beginner';
    if (!in_array($experienceLevel, $allowedExperienceLevels, true)) {
        $experienceLevel = 'beginner';
    }
    
    $weeklyBaseKm = !empty($input['weekly_base_km']) ? (float)$input['weekly_base_km'] : null;
    $sessionsPerWeek = !empty($input['sessions_per_week']) ? (int)$input['sessions_per_week'] : null;
    
    // Режим тренировок
    $trainingModeInput = $input['training_mode'] ?? 'ai';
    $allowedTrainingModes = ['ai', 'coach', 'both', 'self'];
    if (!in_array($trainingModeInput, $allowedTrainingModes, true)) {
        $trainingMode = 'ai';
    } else {
        $trainingMode = $trainingModeInput;
    }
    if ($trainingMode === 'coach') {
        $trainingMode = 'ai';
    }
    
    // Предпочтения
    $preferredDays = $input['preferred_days'] ?? [];
    if (!is_array($preferredDays)) {
        $preferredDays = [];
    }
    $preferredDaysJson = !empty($preferredDays) ? json_encode(array_values($preferredDays), JSON_UNESCAPED_UNICODE) : null;
    
    $preferredOfpDays = $input['preferred_ofp_days'] ?? [];
    if (!is_array($preferredOfpDays)) {
        $preferredOfpDays = [];
    }
    $preferredOfpDaysJson = !empty($preferredOfpDays) ? json_encode(array_values($preferredOfpDays), JSON_UNESCAPED_UNICODE) : null;
    
    $trainingTimePref = !empty($input['training_time_pref']) && in_array($input['training_time_pref'], ['morning', 'day', 'evening']) 
        ? $input['training_time_pref'] : null;
    $hasTreadmill = isset($input['has_treadmill']) ? 1 : 0;
    
    // Предпочтения по ОФП
    $ofpPreference = !empty($input['ofp_preference']) && in_array($input['ofp_preference'], ['gym', 'home', 'both', 'group_classes', 'online']) 
        ? $input['ofp_preference'] : null;
    
    // Дополнительно
    $healthNotes = !empty($input['health_notes']) ? trim($input['health_notes']) : null;
    $deviceType = !empty($input['device_type']) ? trim($input['device_type']) : null;
    $weightGoalKg = !empty($input['weight_goal_kg']) ? (float)$input['weight_goal_kg'] : null;
    $weightGoalDate = !empty($input['weight_goal_date']) ? $input['weight_goal_date'] : null;
    
    // Поля для программы "Здоровье"
    $healthProgram = !empty($input['health_program']) && in_array($input['health_program'], ['start_running', 'couch_to_5k', 'regular_running', 'custom']) 
        ? $input['health_program'] : null;
    $healthPlanWeeks = !empty($input['health_plan_weeks']) ? (int)$input['health_plan_weeks'] : null;
    $currentRunningLevel = !empty($input['current_running_level']) && in_array($input['current_running_level'], ['zero', 'basic', 'comfortable'])
        ? $input['current_running_level'] : null;
    
    // Расширенный профиль бегуна
    $allowedRunningExperience = ['less_3m', '3_6m', '6_12m', '1_2y', 'more_2y'];
    $runningExperience = !empty($input['running_experience']) && in_array($input['running_experience'], $allowedRunningExperience)
        ? $input['running_experience'] : null;
    
    // Комфортный темп
    $easyPaceSec = null;
    if (!empty($input['easy_pace_min'])) {
        $easyPaceMin = (int)$input['easy_pace_min'];
        $easyPaceSecPart = !empty($input['easy_pace_sec']) ? (int)$input['easy_pace_sec'] : 0;
        if ($easyPaceMin >= 3 && $easyPaceMin <= 12) {
            $easyPaceSec = $easyPaceMin * 60 + min(59, max(0, $easyPaceSecPart));
        }
    }
    
    $isFirstRaceAtDistance = null;
    if (isset($input['is_first_race'])) {
        $isFirstRaceAtDistance = $input['is_first_race'] === '1' || $input['is_first_race'] === true || $input['is_first_race'] === 1 ? 1 : 0;
    }
    
    // Последний результат
    $allowedLastRaceDistances = ['5k', '10k', 'half', 'marathon', 'other'];
    $lastRaceDistance = !empty($input['last_race_distance']) && in_array($input['last_race_distance'], $allowedLastRaceDistances)
        ? $input['last_race_distance'] : null;
    $lastRaceDistanceKm = (!empty($input['last_race_distance_km']) && $lastRaceDistance === 'other')
        ? (float)$input['last_race_distance_km'] : null;
    $lastRaceTime = !empty($input['last_race_time']) ? $input['last_race_time'] : null;
    $lastRaceDate = !empty($input['last_race_date']) ? $input['last_race_date'] . '-01' : null;
    
    // Валидация
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Имя пользователя и пароль обязательны'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Пароль должен быть не менее 6 символов'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Для режима 'self' gender не обязателен, для остальных - обязателен
    if ($trainingMode !== 'self' && $gender === null) {
        echo json_encode(['success' => false, 'error' => 'Пожалуйста, выберите пол'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Для режима 'self' устанавливаем gender по умолчанию если не указан
    if ($trainingMode === 'self' && $gender === null) {
        $gender = 'male';
    }
    
    // Дополнительная валидация обязательных полей в зависимости от режима и цели
    if ($trainingMode !== 'self') {
        // Проверка типа цели
        if (empty($goalType) || !in_array($goalType, $allowedGoalTypes, true)) {
            echo json_encode(['success' => false, 'error' => 'Тип цели обязателен'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Проверка даты начала тренировок
        if (empty($trainingStartDate)) {
            echo json_encode(['success' => false, 'error' => 'Дата начала тренировок обязательна'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Валидация в зависимости от типа цели
        if ($goalType === 'race' || $goalType === 'time_improvement') {
            if (empty($raceDate) && empty($targetMarathonDate)) {
                echo json_encode(['success' => false, 'error' => 'Дата забега или целевая дата обязательна для подготовки к забегу'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($goalType === 'weight_loss') {
            if (empty($weightGoalKg)) {
                echo json_encode(['success' => false, 'error' => 'Целевой вес обязателен'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (empty($weightGoalDate)) {
                echo json_encode(['success' => false, 'error' => 'Дата достижения цели обязательна'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($goalType === 'health') {
            if (empty($healthProgram)) {
                echo json_encode(['success' => false, 'error' => 'Программа тренировок обязательна'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($healthProgram === 'custom' && empty($healthPlanWeeks)) {
                echo json_encode(['success' => false, 'error' => 'Срок плана обязателен для своей программы'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (empty($currentRunningLevel)) {
                echo json_encode(['success' => false, 'error' => 'Текущий уровень бега обязателен'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        // Устанавливаем experience_level по умолчанию если не указан
        if (empty($experienceLevel) || !in_array($experienceLevel, $allowedExperienceLevels, true)) {
            $experienceLevel = 'beginner';
        }
    }
    
    $db = getDBConnection();
    if (!$db) {
        echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Проверка уникальности
    $checkStmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $checkStmt->bind_param('s', $username);
    $checkStmt->execute();
    if ($checkStmt->get_result()->fetch_assoc()) {
        $checkStmt->close();
        echo json_encode(['success' => false, 'error' => 'Пользователь с таким именем уже существует'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $checkStmt->close();
    
    // Генерируем slug
    $usernameSlug = mb_strtolower($username, 'UTF-8');
    $usernameSlug = preg_replace('/[^a-z0-9_]/', '_', $usernameSlug);
    $usernameSlug = preg_replace('/_+/', '_', $usernameSlug);
    $usernameSlug = trim($usernameSlug, '_');
    
    // Проверяем уникальность slug
    $checkSlugStmt = $db->prepare('SELECT id FROM users WHERE username_slug = ?');
    $checkSlugStmt->bind_param('s', $usernameSlug);
    $checkSlugStmt->execute();
    $counter = 1;
    $originalSlug = $usernameSlug;
    while ($checkSlugStmt->get_result()->fetch_assoc()) {
        $usernameSlug = $originalSlug . '_' . $counter;
        $checkSlugStmt->close();
        $checkSlugStmt = $db->prepare('SELECT id FROM users WHERE username_slug = ?');
        $checkSlugStmt->bind_param('s', $usernameSlug);
        $checkSlugStmt->execute();
        $counter++;
    }
    $checkSlugStmt->close();
    
    // Подготавливаем данные для вставки
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $role = 'user';
    
    // Нормализуем NULL значения
    $email = !empty($email) ? $email : null;
    $targetMarathonDate = !empty($targetMarathonDate) ? $targetMarathonDate : null;
    $targetMarathonTime = !empty($targetMarathonTime) ? $targetMarathonTime : null;
    $raceDistance = !empty($raceDistance) ? $raceDistance : null;
    $raceDate = !empty($raceDate) ? $raceDate : null;
    $raceTargetTime = !empty($raceTargetTime) ? $raceTargetTime : null;
    $trainingStartDate = !empty($trainingStartDate) ? $trainingStartDate : null;
    $preferredDaysJson = !empty($preferredDaysJson) && $preferredDaysJson !== '[]' ? $preferredDaysJson : null;
    $preferredOfpDaysJson = !empty($preferredOfpDaysJson) && $preferredOfpDaysJson !== '[]' ? $preferredOfpDaysJson : null;
    
    // Создаем массив данных с явным маппингом полей
    $userData = [
        'username' => ['value' => $username, 'type' => 's'],
        'username_slug' => ['value' => $usernameSlug, 'type' => 's'],
        'password' => ['value' => $hashedPassword, 'type' => 's'],
        'email' => ['value' => $email, 'type' => 's'],
        'role' => ['value' => $role, 'type' => 's'],
        'goal_type' => ['value' => $goalType, 'type' => 's'],
        'race_distance' => ['value' => $raceDistance, 'type' => 's'],
        'race_date' => ['value' => $raceDate, 'type' => 's'],
        'race_target_time' => ['value' => $raceTargetTime, 'type' => 's'],
        'target_marathon_date' => ['value' => $targetMarathonDate, 'type' => 's'],
        'target_marathon_time' => ['value' => $targetMarathonTime, 'type' => 's'],
        'training_start_date' => ['value' => $trainingStartDate, 'type' => 's'],
        'gender' => ['value' => $gender, 'type' => 's'],
        'birth_year' => ['value' => $birthYear, 'type' => 'i'],
        'height_cm' => ['value' => $heightCm, 'type' => 'i'],
        'weight_kg' => ['value' => $weightKg, 'type' => 'd'],
        'experience_level' => ['value' => $experienceLevel, 'type' => 's'],
        'weekly_base_km' => ['value' => $weeklyBaseKm, 'type' => 'd'],
        'sessions_per_week' => ['value' => $sessionsPerWeek, 'type' => 'i'],
        'preferred_days' => ['value' => $preferredDaysJson, 'type' => 's'],
        'preferred_ofp_days' => ['value' => $preferredOfpDaysJson, 'type' => 's'],
        'has_treadmill' => ['value' => $hasTreadmill, 'type' => 'i'],
        'ofp_preference' => ['value' => $ofpPreference, 'type' => 's'],
        'training_time_pref' => ['value' => $trainingTimePref, 'type' => 's'],
        'health_notes' => ['value' => $healthNotes, 'type' => 's'],
        'device_type' => ['value' => $deviceType, 'type' => 's'],
        'weight_goal_kg' => ['value' => $weightGoalKg, 'type' => 'd'],
        'weight_goal_date' => ['value' => $weightGoalDate, 'type' => 's'],
        'health_program' => ['value' => $healthProgram, 'type' => 's'],
        'health_plan_weeks' => ['value' => $healthPlanWeeks, 'type' => 'i'],
        'current_running_level' => ['value' => $currentRunningLevel, 'type' => 's'],
        'running_experience' => ['value' => $runningExperience, 'type' => 's'],
        'easy_pace_sec' => ['value' => $easyPaceSec, 'type' => 'i'],
        'is_first_race_at_distance' => ['value' => $isFirstRaceAtDistance, 'type' => 'i'],
        'last_race_distance' => ['value' => $lastRaceDistance, 'type' => 's'],
        'last_race_distance_km' => ['value' => $lastRaceDistanceKm, 'type' => 'd'],
        'last_race_time' => ['value' => $lastRaceTime, 'type' => 's'],
        'last_race_date' => ['value' => $lastRaceDate, 'type' => 's'],
        'training_mode' => ['value' => $trainingMode, 'type' => 's']
    ];
    
    // Строим SQL запрос динамически
    $fields = array_keys($userData);
    $placeholders = array_fill(0, count($fields), '?');
    $types = '';
    
    foreach ($userData as $field => $info) {
        $types .= $info['type'];
    }
    
    $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Ошибка подготовки запроса: ' . $db->error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Безопасный bind_param через call_user_func_array
    $bindParams = [$types];
    foreach ($userData as $field => $info) {
        $bindParams[] = &$userData[$field]['value'];
    }
    
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Ошибка выполнения запроса: ' . $stmt->error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userId = $db->insert_id;
    $stmt->close();
    
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'Не удалось получить ID нового пользователя'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Создаем запись плана
    $planDate = null;
    $planTime = null;
    
    if ($goalType === 'race' || $goalType === 'time_improvement') {
        if (!empty($raceDate)) {
            $planDate = $raceDate;
            $planTime = $raceTargetTime ?: $targetMarathonTime;
        } elseif (!empty($targetMarathonDate)) {
            $planDate = $targetMarathonDate;
            $planTime = $targetMarathonTime;
        }
    } elseif ($goalType === 'weight_loss') {
        $planDate = $weightGoalDate ?: $targetMarathonDate;
        $planTime = null;
    } elseif ($goalType === 'health') {
        $planDate = $targetMarathonDate;
        $planTime = $targetMarathonTime;
    }
    
    $planStmt = $db->prepare('
        INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active)
        VALUES (?, CURDATE(), ?, ?, FALSE)
    ');
    if ($planStmt) {
        $planStmt->bind_param('iss', $userId, $planDate, $planTime);
        $planStmt->execute();
        $planStmt->close();
    }
    
    // Генерируем план в зависимости от режима
    $planGenerationMessage = null;
    if ($trainingMode === 'self') {
        require_once __DIR__ . '/planrun_ai/create_empty_plan.php';
        try {
            $endDate = null;
            if ($goalType === 'race' || $goalType === 'time_improvement') {
                $endDate = $raceDate ?: $targetMarathonDate;
            } elseif (!empty($targetMarathonDate)) {
                $endDate = $targetMarathonDate;
            }
            createEmptyPlan($userId, $trainingStartDate, $endDate);
            $planGenerationMessage = 'Пустой календарь создан! Теперь вы можете добавлять тренировки вручную.';
        } catch (Exception $e) {
            error_log("Ошибка создания пустого календаря: " . $e->getMessage());
            $planGenerationMessage = 'Календарь будет создан автоматически.';
        }
    } elseif ($trainingMode === 'ai' || $trainingMode === 'both') {
        require_once __DIR__ . '/planrun_ai/planrun_ai_config.php';
        if (isPlanRunAIAvailable()) {
            try {
                $scriptPath = __DIR__ . '/planrun_ai/generate_plan_async.php';
                $logDir = __DIR__ . '/logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0777, true);
                }
                $logFile = is_dir($logDir) && is_writable($logDir) 
                    ? $logDir . '/plan_generation_' . $userId . '_' . time() . '.log'
                    : '/tmp/plan_generation_' . $userId . '_' . time() . '.log';
                
                $phpPath = '/usr/bin/php';
                if (!file_exists($phpPath)) {
                    $phpPath = trim(shell_exec('which php 2>/dev/null') ?: 'php');
                }
                
                $command = "cd " . escapeshellarg(__DIR__) . " && nohup " . escapeshellarg($phpPath) . " " . escapeshellarg($scriptPath) . " " . (int)$userId . " >> " . escapeshellarg($logFile) . " 2>&1 & echo \$!";
                exec($command, $output, $returnVar);
                
                $planGenerationMessage = 'План тренировок генерируется через PlanRun AI. Это займет 3-5 минут.';
            } catch (Exception $e) {
                error_log("Ошибка запуска генерации плана: " . $e->getMessage());
                $planGenerationMessage = 'План будет сгенерирован автоматически.';
            }
        } else {
            $planGenerationMessage = 'PlanRun AI система недоступна. Проверьте, что сервис запущен на порту 8000.';
        }
    }
    
    // Автологин
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['login_time'] = time();
    
    echo json_encode([
        'success' => true,
        'message' => 'Регистрация успешна',
        'plan_message' => $planGenerationMessage,
        'user' => ['id' => $userId, 'username' => $username, 'email' => $email]
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
}
