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
require_once __DIR__ . '/config/RateLimiter.php';
require_once __DIR__ . '/services/EmailVerificationService.php';
require_once __DIR__ . '/services/RegistrationService.php';
require_once __DIR__ . '/services/RegisterApiService.php';
require_once __DIR__ . '/../api/session_init.php';
// auth.php не подключаем: для регистрации не нужен, session_start() только в конце для автологина

if (!function_exists('planrunEnsureSessionStarted')) {
    function planrunEnsureSessionStarted() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        @session_start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        session_save_path(sys_get_temp_dir());
        @session_start();

        return session_status() === PHP_SESSION_ACTIVE;
    }
}

if (!function_exists('planrunGetRegisterApiService')) {
    function planrunGetRegisterApiService() {
        $db = getDBConnection();
        if (!$db) {
            return null;
        }
        return new RegisterApiService($db);
    }
}

if (!function_exists('planrunRespondJson')) {
    function planrunRespondJson($payload, $statusCode = 200) {
        http_response_code((int) $statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('planrunAutoLoginRegisteredUser')) {
    function planrunAutoLoginRegisteredUser(array $result, $fallbackUsername = '') {
        $userId = (int) ($result['user']['id'] ?? 0);
        planrunEnsureSessionStarted();
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = (string) ($result['user']['username'] ?? $fallbackUsername);
        $_SESSION['login_time'] = time();
    }
}

// Валидация поля
if (isset($_GET['action']) && $_GET['action'] === 'validate_field') {
    $field = (string) ($_GET['field'] ?? '');
    $value = (string) ($_GET['value'] ?? '');

    $registerApiService = planrunGetRegisterApiService();
    if (!$registerApiService) {
        planrunRespondJson(['valid' => false, 'message' => 'Ошибка подключения к БД']);
    }

    try {
        planrunRespondJson($registerApiService->validateField($field, $value));
    } catch (Throwable $e) {
        planrunRespondJson(['valid' => false, 'message' => 'Ошибка валидации']);
    }
}

// Регистрация
// Матрица обязательных полей (conditional required):
// - self: только username, password, email, gender; experience_level = NULL; дата начала опциональна (дефолт сегодня).
// - ai/both + health: goal_type, training_start_date, health_program; при custom — health_plan_weeks.
// - ai/both + race: goal_type, training_start_date, (race_date ИЛИ target_marathon_date).
// - ai/both + time_improvement: goal_type, training_start_date, (target_marathon_date ИЛИ race_date).
// - ai/both + weight_loss: goal_type, training_start_date, weight_goal_kg, weight_goal_date.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // ——— Отправка кода подтверждения на email ———
    if (($input['action'] ?? '') === 'send_verification_code') {
        $registerApiService = planrunGetRegisterApiService();
        if (!$registerApiService) {
            planrunRespondJson(['success' => false, 'error' => 'Ошибка подключения к БД']);
        }

        try {
            $result = $registerApiService->sendVerificationCode(
                $input['email'] ?? '',
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
            planrunRespondJson($result);
        } catch (Exception $e) {
            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = strpos($e->getMessage(), 'лимит') !== false ? 429 : 500;
            }
            planrunRespondJson(['success' => false, 'error' => $e->getMessage()], $statusCode);
        } catch (Throwable $e) {
            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }
            planrunRespondJson(['success' => false, 'error' => $e->getMessage()], $statusCode);
        }
    }
    
    // Получаем данные (пароль trim для консистентности с логином — иначе пробелы при вводе ломают повторный вход)
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
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
    
    // Режим тренировок — определяем сразу, чтобы условно требовать поля (conditional required fields)
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
    
    if ($trainingMode === 'self') {
        $goalType = 'health';
    }
    
    // Опыт: для режима «самостоятельно» поле не показывается и не обязательно (сохраняем NULL)
    $allowedExperienceLevels = ['novice', 'beginner', 'intermediate', 'advanced', 'expert'];
    if ($trainingMode === 'self') {
        $experienceLevel = null;
    } else {
        $experienceLevel = isset($input['experience_level']) ? trim((string)$input['experience_level']) : '';
        if ($experienceLevel === '' || !in_array($experienceLevel, $allowedExperienceLevels, true)) {
            $experienceLevel = 'beginner';
        }
    }
    
    $weeklyBaseKm = !empty($input['weekly_base_km']) ? (float)$input['weekly_base_km'] : null;
    $sessionsPerWeek = !empty($input['sessions_per_week']) ? (int)$input['sessions_per_week'] : null;
    
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
    
    // Комфортный темп: фронт присылает easy_pace_sec в секундах на км (180–600), easy_pace_min — строка MM:SS для отображения
    $easyPaceSec = null;
    if (!empty($input['easy_pace_sec'])) {
        $sec = (int)$input['easy_pace_sec'];
        if ($sec >= 180 && $sec <= 600) {
            $easyPaceSec = $sec;
        }
    }
    if ($easyPaceSec === null && !empty($input['easy_pace_min'])) {
        $parts = is_string($input['easy_pace_min']) ? explode(':', trim($input['easy_pace_min'])) : [];
        if (count($parts) === 2) {
            $min = (int)$parts[0];
            $secPart = (int)$parts[1];
            if ($min >= 3 && $min <= 12 && $secPart >= 0 && $secPart < 60) {
                $easyPaceSec = $min * 60 + $secPart;
            }
        }
    }
    
    $isFirstRaceAtDistance = null;
    if (isset($input['is_first_race_at_distance']) || isset($input['is_first_race'])) {
        $val = $input['is_first_race_at_distance'] ?? $input['is_first_race'];
        $isFirstRaceAtDistance = $val === '1' || $val === true || $val === 1 ? 1 : 0;
    }
    
    // Последний результат
    $allowedLastRaceDistances = ['5k', '10k', 'half', 'marathon', 'other'];
    $lastRaceDistance = !empty($input['last_race_distance']) && in_array($input['last_race_distance'], $allowedLastRaceDistances)
        ? $input['last_race_distance'] : null;
    $lastRaceDistanceKm = (!empty($input['last_race_distance_km']) && $lastRaceDistance === 'other')
        ? (float)$input['last_race_distance_km'] : null;
    $lastRaceTime = !empty($input['last_race_time']) ? $input['last_race_time'] : null;
    $lastRaceDate = null;
    if (!empty($input['last_race_date'])) {
        $d = trim((string)$input['last_race_date']);
        $lastRaceDate = (strlen($d) === 7 && preg_match('/^\d{4}-\d{2}$/', $d)) ? $d . '-01' : $d;
    }

    $allowedBenchmarkDistances = ['5k', '10k', 'half', 'marathon', 'other'];
    $planningBenchmarkDistance = !empty($input['planning_benchmark_distance']) && in_array($input['planning_benchmark_distance'], $allowedBenchmarkDistances, true)
        ? $input['planning_benchmark_distance'] : null;
    $planningBenchmarkDistanceKm = (!empty($input['planning_benchmark_distance_km']) && $planningBenchmarkDistance === 'other')
        ? (float)$input['planning_benchmark_distance_km'] : null;
    $planningBenchmarkTime = !empty($input['planning_benchmark_time']) ? $input['planning_benchmark_time'] : null;
    $planningBenchmarkDate = null;
    if (!empty($input['planning_benchmark_date'])) {
        $d = trim((string)$input['planning_benchmark_date']);
        $planningBenchmarkDate = (strlen($d) === 7 && preg_match('/^\d{4}-\d{2}$/', $d)) ? $d . '-01' : $d;
    }
    $allowedBenchmarkTypes = ['race', 'control', 'hard_workout', 'easy_workout'];
    $planningBenchmarkType = !empty($input['planning_benchmark_type']) && in_array($input['planning_benchmark_type'], $allowedBenchmarkTypes, true)
        ? $input['planning_benchmark_type'] : null;
    $allowedBenchmarkEfforts = ['max', 'hard', 'steady', 'easy'];
    $planningBenchmarkEffort = !empty($input['planning_benchmark_effort']) && in_array($input['planning_benchmark_effort'], $allowedBenchmarkEfforts, true)
        ? $input['planning_benchmark_effort'] : null;

    if ($planningBenchmarkDistance === null && $lastRaceDistance !== null) {
        $planningBenchmarkDistance = $lastRaceDistance;
        $planningBenchmarkDistanceKm = $lastRaceDistanceKm;
        $planningBenchmarkTime = $lastRaceTime;
        $planningBenchmarkDate = $lastRaceDate;
    }
    
    // Валидация
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Имя пользователя и пароль обязательны'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Пароль должен быть не менее 6 символов'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'error' => 'Email обязателен'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный формат email'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ——— Минимальная регистрация (только логин, email, пароль) ———
    $registerMinimal = !empty($input['register_minimal']) || (isset($input['register_minimal']) && $input['register_minimal'] === true);
    if ($registerMinimal) {
        $db = getDBConnection();
        if (!$db) {
            echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $registrationService = new RegistrationService($db);
            $result = $registrationService->registerMinimal($input);
            if (empty($result['success'])) {
                planrunRespondJson($result);
            }
        } catch (Throwable $e) {
            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }
            planrunRespondJson(['success' => false, 'error' => $e->getMessage(), 'code_required' => true], $statusCode);
        }
        planrunAutoLoginRegisteredUser($result);
        // JWT для native-клиента выдаём отдельным запросом на /login после успешной регистрации.
        // Так регистрация не зависит от refresh_tokens/KeyStore-специфики и быстрее возвращает успех.
        planrunRespondJson($result);
    }
    
    // ——— Полная регистрация (ниже) ———
    
    // Для режима 'self' gender не обязателен, для остальных - обязателен
    if ($trainingMode !== 'self' && $gender === null) {
        echo json_encode(['success' => false, 'error' => 'Пожалуйста, выберите пол'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Для режима 'self' устанавливаем gender по умолчанию если не указан
    if ($trainingMode === 'self' && $gender === null) {
        $gender = 'male';
    }
    
    // Дополнительная валидация обязательных полей только для режимов с планом (AI/тренер)
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
        
        // Валидация в зависимости от типа цели (только нужные поля для выбранной цели)
        if ($goalType === 'race') {
            if (empty($raceDate) && empty($targetMarathonDate)) {
                echo json_encode(['success' => false, 'error' => 'Укажите дату забега или целевую дату'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($goalType === 'time_improvement') {
            if (empty($targetMarathonDate) && empty($raceDate)) {
                echo json_encode(['success' => false, 'error' => 'Укажите дату марафона или дату забега'], JSON_UNESCAPED_UNICODE);
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
                $currentRunningLevel = 'basic';
            }
        }
    }
    
    // Для режима «самостоятельно» — безопасные значения полей, которые не показываются (избегаем NOT NULL/truncate)
    if ($trainingMode === 'self') {
        if ($isFirstRaceAtDistance === null) {
            $isFirstRaceAtDistance = 0;
        }
        if ($currentRunningLevel === null) {
            $currentRunningLevel = 'basic';
        }
    }
    
    $db = getDBConnection();
    if (!$db) {
        echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $registrationService = new RegistrationService($db);
    $result = $registrationService->registerFull([
        'username' => $username,
        'password' => $password,
        'email' => $email,
        'goal_type' => $goalType,
        'race_distance' => !empty($raceDistance) ? $raceDistance : null,
        'race_date' => !empty($raceDate) ? $raceDate : null,
        'race_target_time' => !empty($raceTargetTime) ? $raceTargetTime : null,
        'target_marathon_date' => !empty($targetMarathonDate) ? $targetMarathonDate : null,
        'target_marathon_time' => !empty($targetMarathonTime) ? $targetMarathonTime : null,
        'training_start_date' => !empty($trainingStartDate) ? $trainingStartDate : null,
        'gender' => $gender,
        'birth_year' => $birthYear,
        'height_cm' => $heightCm,
        'weight_kg' => $weightKg,
        'experience_level' => $experienceLevel,
        'weekly_base_km' => $weeklyBaseKm,
        'sessions_per_week' => $sessionsPerWeek,
        'preferred_days' => !empty($preferredDaysJson) && $preferredDaysJson !== '[]' ? $preferredDaysJson : null,
        'preferred_ofp_days' => !empty($preferredOfpDaysJson) && $preferredOfpDaysJson !== '[]' ? $preferredOfpDaysJson : null,
        'has_treadmill' => $hasTreadmill,
        'ofp_preference' => $ofpPreference,
        'training_time_pref' => $trainingTimePref,
        'health_notes' => $healthNotes,
        'device_type' => $deviceType,
        'weight_goal_kg' => $weightGoalKg,
        'weight_goal_date' => $weightGoalDate,
        'health_program' => $healthProgram,
        'health_plan_weeks' => $healthPlanWeeks,
        'current_running_level' => $currentRunningLevel,
        'running_experience' => $runningExperience,
        'easy_pace_sec' => $easyPaceSec,
        'is_first_race_at_distance' => $isFirstRaceAtDistance,
        'last_race_distance' => $lastRaceDistance,
        'last_race_distance_km' => $lastRaceDistanceKm,
        'last_race_time' => $lastRaceTime,
        'last_race_date' => $lastRaceDate,
        'planning_benchmark_distance' => $planningBenchmarkDistance,
        'planning_benchmark_distance_km' => $planningBenchmarkDistanceKm,
        'planning_benchmark_time' => $planningBenchmarkTime,
        'planning_benchmark_date' => $planningBenchmarkDate,
        'planning_benchmark_type' => $planningBenchmarkType,
        'planning_benchmark_effort' => $planningBenchmarkEffort,
        'training_mode' => $trainingMode,
    ]);
    if (empty($result['success'])) {
        planrunRespondJson($result);
    }
    planrunAutoLoginRegisteredUser($result, $username);
    planrunRespondJson($result);
    } catch (Throwable $e) {
        error_log('register_api.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $detail = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        planrunRespondJson([
            'success' => false,
            'error' => $detail
        ]);
    }
} else {
    planrunRespondJson(['success' => false, 'error' => 'Метод не поддерживается']);
}
