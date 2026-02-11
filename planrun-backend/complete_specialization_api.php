<?php
/**
 * API завершения специализации (второй этап регистрации).
 * Вызывается после минимальной регистрации: обновляет профиль, создаёт план.
 * Требует авторизации (сессия).
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
            header("Access-Control-Allow-Methods: POST, OPTIONS");
        }
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
        }
        http_response_code(204);
        exit(0);
    }
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$username = $_SESSION['username'] ?? null;
if (!$userId || !$username) {
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $allowedGoalTypes = ['health', 'race', 'weight_loss', 'time_improvement'];
    $goalType = $input['goal_type'] ?? 'health';
    if (!in_array($goalType, $allowedGoalTypes)) {
        $goalType = 'health';
    }

    $raceDate = !empty($input['race_date']) ? $input['race_date'] : null;
    $raceDistance = !empty($input['race_distance']) ? $input['race_distance'] : null;
    $raceTargetTime = !empty($input['race_target_time']) ? $input['race_target_time'] : null;
    $targetMarathonDate = !empty($input['target_marathon_date']) ? $input['target_marathon_date'] : null;
    $targetMarathonTime = !empty($input['target_marathon_time']) ? $input['target_marathon_time'] : null;
    $trainingStartDate = !empty($input['training_start_date']) ? $input['training_start_date'] : null;

    $allowedGenders = ['male', 'female'];
    $gender = null;
    if (!empty($input['gender']) && in_array($input['gender'], $allowedGenders, true)) {
        $gender = $input['gender'];
    }

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

    $allowedExperienceLevels = ['novice', 'beginner', 'intermediate', 'advanced', 'expert'];
    if ($trainingMode === 'self') {
        $experienceLevel = null;
    } else {
        $experienceLevel = isset($input['experience_level']) ? trim((string)$input['experience_level']) : '';
        if ($experienceLevel === '' || !in_array($experienceLevel, $allowedExperienceLevels, true)) {
            $experienceLevel = 'beginner';
        }
    }

    $birthYear = !empty($input['birth_year']) ? (int)$input['birth_year'] : null;
    $heightCm = !empty($input['height_cm']) ? (int)$input['height_cm'] : null;
    $weightKg = !empty($input['weight_kg']) ? (float)$input['weight_kg'] : null;
    $weeklyBaseKm = !empty($input['weekly_base_km']) ? (float)$input['weekly_base_km'] : null;
    $sessionsPerWeek = !empty($input['sessions_per_week']) ? (int)$input['sessions_per_week'] : null;

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

    $trainingTimePref = !empty($input['training_time_pref']) && in_array($input['training_time_pref'], ['morning', 'day', 'evening']) ? $input['training_time_pref'] : null;
    $hasTreadmill = isset($input['has_treadmill']) ? 1 : 0;
    $ofpPreference = !empty($input['ofp_preference']) && in_array($input['ofp_preference'], ['gym', 'home', 'both', 'group_classes', 'online']) ? $input['ofp_preference'] : null;
    $healthNotes = !empty($input['health_notes']) ? trim($input['health_notes']) : null;
    $deviceType = !empty($input['device_type']) ? trim($input['device_type']) : null;
    $weightGoalKg = !empty($input['weight_goal_kg']) ? (float)$input['weight_goal_kg'] : null;
    $weightGoalDate = !empty($input['weight_goal_date']) ? $input['weight_goal_date'] : null;
    $healthProgram = !empty($input['health_program']) && in_array($input['health_program'], ['start_running', 'couch_to_5k', 'regular_running', 'custom']) ? $input['health_program'] : null;
    $healthPlanWeeks = !empty($input['health_plan_weeks']) ? (int)$input['health_plan_weeks'] : null;
    $currentRunningLevel = !empty($input['current_running_level']) && in_array($input['current_running_level'], ['zero', 'basic', 'comfortable']) ? $input['current_running_level'] : null;

    $allowedRunningExperience = ['less_3m', '3_6m', '6_12m', '1_2y', 'more_2y'];
    $runningExperience = !empty($input['running_experience']) && in_array($input['running_experience'], $allowedRunningExperience) ? $input['running_experience'] : null;

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

    $allowedLastRaceDistances = ['5k', '10k', 'half', 'marathon', 'other'];
    $lastRaceDistance = !empty($input['last_race_distance']) && in_array($input['last_race_distance'], $allowedLastRaceDistances) ? $input['last_race_distance'] : null;
    $lastRaceDistanceKm = (!empty($input['last_race_distance_km']) && $lastRaceDistance === 'other') ? (float)$input['last_race_distance_km'] : null;
    $lastRaceTime = !empty($input['last_race_time']) ? $input['last_race_time'] : null;
    $lastRaceDate = null;
    if (!empty($input['last_race_date'])) {
        $d = trim((string)$input['last_race_date']);
        $lastRaceDate = (strlen($d) === 7 && preg_match('/^\d{4}-\d{2}$/', $d)) ? $d . '-01' : $d;
    }

    if ($trainingMode !== 'self' && $gender === null) {
        echo json_encode(['success' => false, 'error' => 'Пожалуйста, выберите пол'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($trainingMode === 'self' && $gender === null) {
        $gender = 'male';
    }

    if ($trainingMode !== 'self') {
        if (empty($goalType) || !in_array($goalType, $allowedGoalTypes, true)) {
            echo json_encode(['success' => false, 'error' => 'Тип цели обязателен'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (empty($trainingStartDate)) {
            echo json_encode(['success' => false, 'error' => 'Дата начала тренировок обязательна'], JSON_UNESCAPED_UNICODE);
            exit;
        }
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

    if ($trainingMode === 'self') {
        if ($isFirstRaceAtDistance === null) {
            $isFirstRaceAtDistance = 0;
        }
        if ($currentRunningLevel === null) {
            $currentRunningLevel = 'basic';
        }
    }

    $targetMarathonDate = !empty($targetMarathonDate) ? $targetMarathonDate : null;
    $targetMarathonTime = !empty($targetMarathonTime) ? $targetMarathonTime : null;
    $raceDistance = !empty($raceDistance) ? $raceDistance : null;
    $raceDate = !empty($raceDate) ? $raceDate : null;
    $raceTargetTime = !empty($raceTargetTime) ? $raceTargetTime : null;
    $trainingStartDate = !empty($trainingStartDate) ? $trainingStartDate : null;
    $preferredDaysJson = !empty($preferredDaysJson) && $preferredDaysJson !== '[]' ? $preferredDaysJson : null;
    $preferredOfpDaysJson = !empty($preferredOfpDaysJson) && $preferredOfpDaysJson !== '[]' ? $preferredOfpDaysJson : null;

    $db = getDBConnection();
    if (!$db) {
        echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userData = [
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
        'training_mode' => ['value' => $trainingMode, 'type' => 's'],
        'onboarding_completed' => ['value' => 1, 'type' => 'i']
    ];

    $setParts = [];
    foreach (array_keys($userData) as $field) {
        $setParts[] = "`$field` = ?";
    }
    $types = '';
    foreach ($userData as $info) {
        $types .= $info['type'];
    }
    $types .= 'i';
    $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Ошибка подготовки запроса: ' . $db->error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $bindValues = [$types];
    foreach ($userData as $field => $info) {
        $bindValues[] = $userData[$field]['value'];
    }
    $bindValues[] = $userId;
    $refs = [];
    foreach ($bindValues as $k => $v) {
        $refs[$k] = &$bindValues[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Ошибка обновления: ' . $stmt->error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->close();

    clearUserCache($userId);

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

    $checkPlan = $db->prepare('SELECT id FROM user_training_plans WHERE user_id = ? LIMIT 1');
    $checkPlan->bind_param('i', $userId);
    $checkPlan->execute();
    $hasPlan = $checkPlan->get_result()->fetch_assoc();
    $checkPlan->close();
    if (!$hasPlan) {
        $planStmt = $db->prepare('INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active) VALUES (?, CURDATE(), ?, ?, FALSE)');
        if ($planStmt) {
            $planStmt->bind_param('iss', $userId, $planDate, $planTime);
            $planStmt->execute();
            $planStmt->close();
        }
    }

    $planGenerationMessage = null;
    if ($trainingMode === 'self') {
        // Для «самостоятельно» не создаём недели/дни — календарь остаётся пустым, тренировки навешиваются на даты
        $planGenerationMessage = 'Календарь готов. Добавляйте тренировки на любую дату.';
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

    echo json_encode([
        'success' => true,
        'message' => 'Специализация сохранена',
        'plan_message' => $planGenerationMessage,
        'onboarding_completed' => 1
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('complete_specialization_api.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
