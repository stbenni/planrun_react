<?php
/**
 * API v2 - Рефакторинг api.php на контроллеры
 * 
 * ОСНОВНОЙ API - полностью заменил старый api.php
 * Все действия мигрированы на контроллеры
 */

// Публичная раздача аватара — до любых заголовков и require, иначе ответ уходит с Content-Type: application/json и картинка на сайте не показывается
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($action === 'get_avatar' && $method === 'GET') {
    require_once __DIR__ . '/services/AvatarService.php';
    $file = $_GET['file'] ?? '';
    $variant = $_GET['variant'] ?? 'full';
    if (AvatarService::serveRequestedAvatar($file, $variant)) {
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// CORS: при вызове через api_wrapper CORS уже отправлен (cors.php)
if (!defined('API_WRAPPER_CORS_SENT') || !API_WRAPPER_CORS_SENT) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($origin, PHP_URL_HOST);
    $currentHostClean = preg_replace('/^www\./', '', $currentHost);
    $originHostClean = preg_replace('/^www\./', '', $originHost ?? '');
    $isSameDomain = $originHost && $currentHostClean && (
        $originHostClean === $currentHostClean
        || strpos($originHostClean, '.' . $currentHostClean) !== false
        || strpos($currentHostClean, '.' . $originHostClean) !== false
        || strpos($origin, 'localhost') !== false
        || strpos($origin, '127.0.0.1') !== false
        || strpos($origin, '192.168.') !== false
    );
    if ($isSameDomain) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($isSameDomain) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
        }
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        }
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
        }
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit(0);
    }
}

// Загружаем зависимости
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/config/Logger.php';
require_once __DIR__ . '/config/error_handler.php';
require_once __DIR__ . '/cache_config.php';

// Регистрируем обработчики ошибок
ErrorHandler::register();

// Подключаем контроллеры
require_once __DIR__ . '/controllers/BaseController.php';
require_once __DIR__ . '/controllers/TrainingPlanController.php';
require_once __DIR__ . '/controllers/WorkoutController.php';
require_once __DIR__ . '/controllers/StatsController.php';
require_once __DIR__ . '/controllers/ExerciseController.php';
require_once __DIR__ . '/controllers/WeekController.php';
require_once __DIR__ . '/controllers/AdaptationController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/ChatController.php';
require_once __DIR__ . '/controllers/IntegrationsController.php';
require_once __DIR__ . '/controllers/PushController.php';
require_once __DIR__ . '/controllers/CoachController.php';
require_once __DIR__ . '/controllers/NoteController.php';

if (!function_exists('planrunRouteControllerAction')) {
    function planrunRouteControllerAction($db, $controllerClass, $controllerMethod, $requestMethod, $allowedMethods = null) {
        if ($allowedMethods !== null) {
            $allowed = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
            if (!in_array($requestMethod, $allowed, true)) {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
        }

        $controller = new $controllerClass($db);
        $controller->{$controllerMethod}();
        exit;
    }
}

try {
    $db = getDBConnection();
    if (!$db) {
        ErrorHandler::returnJsonError('Ошибка подключения к базе данных', 500);
    }
    
    // Получаем действие и метод (get_avatar уже обработан в начале файла)
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    // Публичные настройки сайта — без авторизации и без контроллера
    if ($action === 'get_site_settings' && $method === 'GET') {
        $defaults = [
            'site_name' => 'PlanRun',
            'site_description' => 'Персональный план беговых тренировок',
            'maintenance_mode' => '0',
            'registration_enabled' => '1',
            'contact_email' => '',
        ];
        $settings = $defaults;
        $tableExists = $db->query("SHOW TABLES LIKE 'site_settings'");
        if ($tableExists && $tableExists->num_rows > 0) {
            $res = $db->query("SELECT `key`, value FROM site_settings");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $settings[$row['key']] = $row['value'];
                }
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => ['settings' => $settings]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Оценка реалистичности цели — без авторизации (вызывается при регистрации)
    if ($action === 'assess_goal' && $method === 'POST') {
        require_once __DIR__ . '/planrun_ai/prompt_builder.php';
        require_once __DIR__ . '/services/TrainingStateBuilder.php';
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (isset($db) && $db instanceof mysqli) {
            $input['training_state'] = (new TrainingStateBuilder($db))->buildForUser($input);
        }
        $result = assessGoalRealism($input);
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Публичный профиль по slug — без авторизации, всегда возвращаем user + access
    if ($action === 'get_user_by_slug' && $method === 'GET') {
        require_once __DIR__ . '/auth.php';
        require_once __DIR__ . '/user_functions.php';
        require_once __DIR__ . '/query_helpers.php';

        $slug = trim($_GET['slug'] ?? '');
        $token = isset($_GET['token']) ? trim($_GET['token']) : null;

        if ($slug === '') {
            ErrorHandler::returnJsonError('Параметр slug обязателен', 400);
        }

        $stmt = $db->prepare("SELECT id, username, username_slug, email, avatar_path, privacy_level, public_token, goal_type, race_date, race_distance, race_target_time, target_marathon_date, target_marathon_time, training_mode, privacy_show_email, privacy_show_trainer, privacy_show_calendar, privacy_show_metrics, privacy_show_workouts, role, coach_bio, coach_specialization, coach_accepts, coach_prices_on_request, coach_experience_years, coach_philosophy FROM users WHERE username_slug = ?");
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            ErrorHandler::returnJsonError('Пользователь не найден', 404);
        }

        $targetUserId = (int)$row['id'];
        $privacyLevel = $row['privacy_level'] ?? 'link';
        $currentUserId = isAuthenticated() ? getCurrentUserId() : null;

        $canView = false;
        $canEdit = false;
        $isOwner = ($targetUserId === $currentUserId);
        $isCoach = false;

        if ($isOwner) {
            $canView = true;
            $canEdit = true;
        } elseif ($privacyLevel === 'public') {
            $canView = true;
        } elseif ($privacyLevel === 'private') {
            if ($currentUserId && isUserCoach($db, $targetUserId, $currentUserId)) {
                $coachAccess = getUserCoachAccess($db, $targetUserId, $currentUserId);
                $canView = $coachAccess['can_view'] ?? false;
                $canEdit = $coachAccess['can_edit'] ?? false;
                $isCoach = $canView || $canEdit;
            }
        } elseif ($privacyLevel === 'link') {
            if ($currentUserId && isUserCoach($db, $targetUserId, $currentUserId)) {
                $coachAccess = getUserCoachAccess($db, $targetUserId, $currentUserId);
                $canView = $coachAccess['can_view'] ?? false;
                $canEdit = $coachAccess['can_edit'] ?? false;
                $isCoach = $canView || $canEdit;
            } elseif ($token && $row['public_token'] && $token === $row['public_token']) {
                $canView = true;
            }
        }

        $userRole = $row['role'] ?? 'user';
        $user = [
            'id' => $targetUserId,
            'username' => $row['username'],
            'username_slug' => $row['username_slug'],
            'avatar_path' => $row['avatar_path'],
            'privacy_level' => $privacyLevel,
            'role' => $userRole,
        ];

        // Coach-поля для тренеров
        if (in_array($userRole, ['coach', 'admin'])) {
            $user['coach_bio'] = $row['coach_bio'];
            $user['coach_specialization'] = json_decode($row['coach_specialization'] ?? '[]', true) ?: [];
            $user['coach_accepts'] = (bool)($row['coach_accepts'] ?? 0);
            $user['coach_prices_on_request'] = (bool)($row['coach_prices_on_request'] ?? 0);
            $user['coach_experience_years'] = $row['coach_experience_years'] ? (int)$row['coach_experience_years'] : null;
            $user['coach_philosophy'] = $row['coach_philosophy'];

            // Pricing
            $tableCheck2 = $db->query("SHOW TABLES LIKE 'coach_pricing'");
            if ($tableCheck2 && $tableCheck2->num_rows > 0) {
                $pStmt = $db->prepare("SELECT type, label, price, currency, period FROM coach_pricing WHERE coach_id = ? ORDER BY sort_order");
                $pStmt->bind_param("i", $targetUserId);
                $pStmt->execute();
                $pRes = $pStmt->get_result();
                $pricing = [];
                while ($pRow = $pRes->fetch_assoc()) {
                    $pRow['price'] = $pRow['price'] !== null ? (float)$pRow['price'] : null;
                    $pricing[] = $pRow;
                }
                $pStmt->close();
                $user['pricing'] = $pricing;
            }
        }
        if ($isOwner || $canView) {
            if ($isOwner || (int)($row['privacy_show_email'] ?? 1) === 1) {
                $user['email'] = $row['email'];
            }
            $user['goal_type'] = $row['goal_type'] ?? null;
            $user['race_date'] = $row['race_date'] ?? null;
            $user['race_distance'] = $row['race_distance'] ?? null;
            $user['race_target_time'] = $row['race_target_time'] ?? null;
            $user['target_marathon_date'] = $row['target_marathon_date'] ?? null;
            $user['target_marathon_time'] = $row['target_marathon_time'] ?? null;
            $user['training_mode'] = $row['training_mode'] ?? 'ai';
            $user['privacy_show_email'] = (int)($row['privacy_show_email'] ?? 1);
            $user['privacy_show_trainer'] = (int)($row['privacy_show_trainer'] ?? 1);
            $user['privacy_show_calendar'] = (int)($row['privacy_show_calendar'] ?? 1);
            $user['privacy_show_metrics'] = (int)($row['privacy_show_metrics'] ?? 1);
            $user['privacy_show_workouts'] = (int)($row['privacy_show_workouts'] ?? 1);
        }

        $access = [
            'can_view' => $canView,
            'can_edit' => $canEdit,
            'is_owner' => $isOwner,
            'is_coach' => $isCoach,
        ];

        $coaches = [];
        if ($canView && ($isOwner || (int)($row['privacy_show_trainer'] ?? 1) === 1)) {
            $tableCheck = $db->query("SHOW TABLES LIKE 'user_coaches'");
            if ($tableCheck && $tableCheck->num_rows > 0) {
                $stmt = $db->prepare("
                    SELECT u.id, u.username, u.username_slug, u.avatar_path
                    FROM user_coaches uc
                    JOIN users u ON uc.coach_id = u.id
                    WHERE uc.user_id = ?
                ");
                $stmt->bind_param("i", $targetUserId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($rowCoach = $res->fetch_assoc()) {
                    $coaches[] = [
                        'id' => (int)$rowCoach['id'],
                        'username' => $rowCoach['username'],
                        'username_slug' => $rowCoach['username_slug'],
                        'avatar_path' => $rowCoach['avatar_path'],
                    ];
                }
                $stmt->close();
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => [
                'user' => $user,
                'access' => $access,
                'coaches' => $coaches,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Маршрутизация на контроллеры
    switch ($action) {
        // TrainingPlanController
        case 'load':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'load', $method);
            break;
            
        case 'check_plan_status':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'checkStatus', $method);
            break;
            
        case 'regenerate_plan':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'regeneratePlan', $method);
            break;
            
        case 'regenerate_plan_with_progress':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'regeneratePlanWithProgress', $method, 'POST');
            break;
            
        case 'recalculate_plan':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'recalculatePlan', $method, 'POST');
            break;

        case 'generate_next_plan':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'generateNextPlan', $method, 'POST');
            break;

        case 'reactivate_plan':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'reactivatePlan', $method, 'POST');
            break;

        case 'clear_plan':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'clearPlan', $method, 'POST');
            break;

        case 'clear_plan_generation_message':
            planrunRouteControllerAction($db, TrainingPlanController::class, 'clearPlanGenerationMessage', $method);
            break;
            
        // WorkoutController
        case 'get_day':
            planrunRouteControllerAction($db, WorkoutController::class, 'getDay', $method);
            break;
            
        case 'get_workout_timeline':
            planrunRouteControllerAction($db, WorkoutController::class, 'getWorkoutTimeline', $method);
            break;

        case 'get_workout_share_map':
            planrunRouteControllerAction($db, WorkoutController::class, 'getWorkoutShareMap', $method);
            break;

        case 'generate_workout_share_card':
            planrunRouteControllerAction($db, WorkoutController::class, 'generateWorkoutShareCard', $method);
            break;

        case 'store_workout_share_card':
            planrunRouteControllerAction($db, WorkoutController::class, 'storeWorkoutShareCard', $method, 'POST');
            break;
            
        case 'save_result':
            planrunRouteControllerAction($db, WorkoutController::class, 'saveResult', $method, 'POST');
            break;

        case 'upload_workout':
            planrunRouteControllerAction($db, WorkoutController::class, 'uploadWorkout', $method, 'POST');
            break;
            
        case 'get_result':
            planrunRouteControllerAction($db, WorkoutController::class, 'getResult', $method);
            break;
            
        case 'get_all_results':
            planrunRouteControllerAction($db, WorkoutController::class, 'getAllResults', $method);
            break;

        case 'data_version':
            planrunRouteControllerAction($db, WorkoutController::class, 'dataVersion', $method);
            break;

        case 'delete_workout':
            planrunRouteControllerAction($db, WorkoutController::class, 'deleteWorkout', $method, 'POST');
            break;
            
        case 'save':
            planrunRouteControllerAction($db, WorkoutController::class, 'save', $method, 'POST');
            break;
            
        case 'reset':
            planrunRouteControllerAction($db, WorkoutController::class, 'reset', $method, 'POST');
            break;
            
        // StatsController
        case 'stats':
            planrunRouteControllerAction($db, StatsController::class, 'stats', $method);
            break;
            
        case 'get_all_workouts_summary':
            planrunRouteControllerAction($db, StatsController::class, 'getAllWorkoutsSummary', $method);
            break;

        case 'get_all_workouts_list':
            planrunRouteControllerAction($db, StatsController::class, 'getAllWorkoutsList', $method, 'GET');
            break;
            
        case 'prepare_weekly_analysis':
            planrunRouteControllerAction($db, StatsController::class, 'prepareWeeklyAnalysis', $method);
            break;

        case 'race_prediction':
            planrunRouteControllerAction($db, StatsController::class, 'racePrediction', $method, 'GET');
            break;

        case 'training_load':
            planrunRouteControllerAction($db, StatsController::class, 'trainingLoad', $method, 'GET');
            break;

        // ExerciseController
        case 'add_day_exercise':
            planrunRouteControllerAction($db, ExerciseController::class, 'addDayExercise', $method, 'POST');
            break;
            
        case 'update_day_exercise':
            planrunRouteControllerAction($db, ExerciseController::class, 'updateDayExercise', $method, 'POST');
            break;
            
        case 'delete_day_exercise':
            planrunRouteControllerAction($db, ExerciseController::class, 'deleteDayExercise', $method, 'POST');
            break;
            
        case 'reorder_day_exercises':
            planrunRouteControllerAction($db, ExerciseController::class, 'reorderDayExercises', $method, 'POST');
            break;
            
        case 'list_exercise_library':
            planrunRouteControllerAction($db, ExerciseController::class, 'listExerciseLibrary', $method);
            break;
            
        // WeekController
        case 'add_week':
            planrunRouteControllerAction($db, WeekController::class, 'addWeek', $method, 'POST');
            break;
            
        case 'delete_week':
            planrunRouteControllerAction($db, WeekController::class, 'deleteWeek', $method, 'POST');
            break;
            
        case 'add_training_day':
            planrunRouteControllerAction($db, WeekController::class, 'addTrainingDay', $method, 'POST');
            break;
            
        case 'add_training_day_by_date':
            planrunRouteControllerAction($db, WeekController::class, 'addTrainingDayByDate', $method, 'POST');
            break;
            
        case 'update_training_day':
            planrunRouteControllerAction($db, WeekController::class, 'updateTrainingDay', $method, 'POST');
            break;
            
        case 'delete_training_day':
            planrunRouteControllerAction($db, WeekController::class, 'deleteTrainingDay', $method, 'POST');
            break;
            
        case 'copy_day':
            planrunRouteControllerAction($db, WeekController::class, 'copyDay', $method, 'POST');
            break;
            
        case 'copy_week':
            planrunRouteControllerAction($db, WeekController::class, 'copyWeek', $method, 'POST');
            break;

        // AdaptationController
        case 'run_weekly_adaptation':
            $controller = new AdaptationController($db);
            $controller->runWeeklyAdaptation();
            break;
            
        // UserController
        case 'get_profile':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->getProfile();
            break;
            
        case 'update_profile':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->updateProfile();
            break;

        case 'get_notification_settings':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->getNotificationSettings();
            break;

        case 'get_notification_delivery_log':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->getNotificationDeliveryLog();
            break;

        case 'update_notification_settings':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->updateNotificationSettings();
            break;
            
        case 'delete_user':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->deleteUser();
            break;
            
        case 'upload_avatar':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->uploadAvatar();
            break;
            
        case 'remove_avatar':
            planrunRouteControllerAction($db, UserController::class, 'removeAvatar', $method, 'POST');
            break;
            
        case 'get_avatar':
            // Обрабатывается выше (публичный блок без авторизации)
            ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            break;

        case 'update_privacy':
            planrunRouteControllerAction($db, UserController::class, 'updatePrivacy', $method, 'POST');
            break;
            
        case 'notifications_dismissed':
            planrunRouteControllerAction($db, UserController::class, 'getNotificationsDismissed', $method, 'GET');
            break;

        case 'notifications_dismiss':
            planrunRouteControllerAction($db, UserController::class, 'dismissNotification', $method, 'POST');
            break;

        case 'register_push_token':
            planrunRouteControllerAction($db, PushController::class, 'registerToken', $method, 'POST');
            break;

        case 'unregister_push_token':
            planrunRouteControllerAction($db, PushController::class, 'unregisterToken', $method, 'POST');
            break;

        case 'register_web_push_subscription':
            planrunRouteControllerAction($db, UserController::class, 'registerWebPushSubscription', $method, 'POST');
            break;

        case 'unregister_web_push_subscription':
            planrunRouteControllerAction($db, UserController::class, 'unregisterWebPushSubscription', $method, 'POST');
            break;

        case 'send_test_notification':
            planrunRouteControllerAction($db, UserController::class, 'sendTestNotification', $method, 'POST');
            break;

        case 'telegram_login_url':
            planrunRouteControllerAction($db, UserController::class, 'getTelegramLoginUrl', $method, 'GET');
            break;

        case 'generate_telegram_link_code':
            planrunRouteControllerAction($db, UserController::class, 'generateTelegramLinkCode', $method, 'POST');
            break;

        case 'unlink_telegram':
            planrunRouteControllerAction($db, UserController::class, 'unlinkTelegram', $method, 'POST');
            break;

        // IntegrationsController (Huawei, Garmin, Polar, COROS, Strava)
        case 'integration_oauth_url':
            planrunRouteControllerAction($db, IntegrationsController::class, 'getOAuthUrl', $method, 'GET');
            break;

        case 'integrations_status':
            planrunRouteControllerAction($db, IntegrationsController::class, 'getStatus', $method, 'GET');
            break;

        case 'sync_workouts':
            planrunRouteControllerAction($db, IntegrationsController::class, 'syncWorkouts', $method, 'POST');
            break;

        case 'unlink_integration':
            planrunRouteControllerAction($db, IntegrationsController::class, 'unlink', $method, 'POST');
            break;

        case 'strava_token_error':
            planrunRouteControllerAction($db, IntegrationsController::class, 'getStravaTokenError', $method, 'GET');
            break;
            
        // AuthController
        case 'get_csrf_token':
            // Генерируем CSRF токен для текущей сессии
            if (!isset($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'csrf_token' => $_SESSION['csrf_token']
            ], JSON_UNESCAPED_UNICODE);
            exit;
            break;
            
        case 'login':
            planrunRouteControllerAction($db, AuthController::class, 'login', $method, 'POST');
            break;
            
        case 'logout':
            planrunRouteControllerAction($db, AuthController::class, 'logout', $method, 'POST');
            break;
            
        case 'refresh_token':
            planrunRouteControllerAction($db, AuthController::class, 'refreshToken', $method, 'POST');
            break;
            
        case 'check_auth':
            planrunRouteControllerAction($db, AuthController::class, 'checkAuth', $method);
            break;

        // AdminController
        case 'admin_list_users':
            planrunRouteControllerAction($db, AdminController::class, 'listUsers', $method, 'GET');
            break;

        case 'admin_get_user':
            planrunRouteControllerAction($db, AdminController::class, 'getUser', $method, 'GET');
            break;

        case 'admin_update_user':
            planrunRouteControllerAction($db, AdminController::class, 'updateUser', $method, 'POST');
            break;

        case 'admin_get_settings':
            planrunRouteControllerAction($db, AdminController::class, 'getSettings', $method, 'GET');
            break;

        case 'admin_update_settings':
            planrunRouteControllerAction($db, AdminController::class, 'updateSettings', $method, 'POST');
            break;

        case 'admin_get_notification_templates':
            planrunRouteControllerAction($db, AdminController::class, 'getNotificationTemplates', $method, 'GET');
            break;

        case 'admin_update_notification_template':
            planrunRouteControllerAction($db, AdminController::class, 'updateNotificationTemplate', $method, 'POST');
            break;

        case 'admin_reset_notification_template':
            planrunRouteControllerAction($db, AdminController::class, 'resetNotificationTemplate', $method, 'POST');
            break;

        case 'request_password_reset':
            planrunRouteControllerAction($db, AuthController::class, 'requestPasswordReset', $method);
            break;

        case 'confirm_password_reset':
            planrunRouteControllerAction($db, AuthController::class, 'confirmPasswordReset', $method);
            break;

        // ChatController
        case 'chat_get_messages':
            planrunRouteControllerAction($db, ChatController::class, 'getMessages', $method, 'GET');
            break;

        case 'chat_send_message':
            planrunRouteControllerAction($db, ChatController::class, 'sendMessage', $method, 'POST');
            break;

        case 'chat_send_message_stream':
            planrunRouteControllerAction($db, ChatController::class, 'sendMessageStream', $method, 'POST');
            break;

        case 'chat_clear_ai':
            planrunRouteControllerAction($db, ChatController::class, 'clearAiChat', $method, 'POST');
            break;

        case 'chat_mark_all_read':
            planrunRouteControllerAction($db, ChatController::class, 'markAllRead', $method, 'POST');
            break;

        case 'chat_admin_mark_all_read':
            planrunRouteControllerAction($db, ChatController::class, 'markAdminAllRead', $method, 'POST');
            break;

        case 'chat_mark_read':
            planrunRouteControllerAction($db, ChatController::class, 'markRead', $method, 'POST');
            break;

        case 'chat_send_message_to_admin':
            planrunRouteControllerAction($db, ChatController::class, 'sendMessageToAdmin', $method, 'POST');
            break;

        case 'chat_get_direct_dialogs':
            planrunRouteControllerAction($db, ChatController::class, 'getDirectDialogs', $method, 'GET');
            break;

        case 'chat_get_direct_messages':
            planrunRouteControllerAction($db, ChatController::class, 'getDirectMessages', $method, 'GET');
            break;

        case 'chat_send_message_to_user':
            planrunRouteControllerAction($db, ChatController::class, 'sendMessageToUser', $method, 'POST');
            break;

        case 'chat_clear_direct_dialog':
            planrunRouteControllerAction($db, ChatController::class, 'clearDirectDialog', $method, 'POST');
            break;

        case 'chat_admin_send_message':
            planrunRouteControllerAction($db, ChatController::class, 'sendAdminMessage', $method, 'POST');
            break;

        case 'chat_admin_chat_users':
            planrunRouteControllerAction($db, ChatController::class, 'getAdminChatUsers', $method, 'GET');
            break;

        case 'chat_admin_unread_notifications':
            planrunRouteControllerAction($db, ChatController::class, 'getAdminUnreadNotifications', $method, 'GET');
            break;

        case 'chat_admin_broadcast':
            planrunRouteControllerAction($db, ChatController::class, 'broadcastAdminMessage', $method, 'POST');
            break;

        case 'chat_admin_get_messages':
            planrunRouteControllerAction($db, ChatController::class, 'getAdminMessages', $method, 'GET');
            break;

        case 'chat_admin_mark_conversation_read':
            planrunRouteControllerAction($db, ChatController::class, 'markAdminConversationRead', $method, 'POST');
            break;

        case 'chat_add_ai_message':
            planrunRouteControllerAction($db, ChatController::class, 'addAIMessage', $method, 'POST');
            break;

        // CoachController
        case 'list_coaches':
            planrunRouteControllerAction($db, CoachController::class, 'listCoaches', $method);
            break;

        case 'request_coach':
            planrunRouteControllerAction($db, CoachController::class, 'requestCoach', $method, 'POST');
            break;

        case 'coach_requests':
            planrunRouteControllerAction($db, CoachController::class, 'getCoachRequests', $method, 'GET');
            break;

        case 'accept_coach_request':
            planrunRouteControllerAction($db, CoachController::class, 'acceptCoachRequest', $method, 'POST');
            break;

        case 'reject_coach_request':
            planrunRouteControllerAction($db, CoachController::class, 'rejectCoachRequest', $method, 'POST');
            break;

        case 'get_my_coaches':
            planrunRouteControllerAction($db, CoachController::class, 'getMyCoaches', $method, 'GET');
            break;

        case 'remove_coach':
            planrunRouteControllerAction($db, CoachController::class, 'removeCoach', $method, 'POST');
            break;

        case 'apply_coach':
            planrunRouteControllerAction($db, CoachController::class, 'applyCoach', $method, 'POST');
            break;

        case 'coach_athletes':
            planrunRouteControllerAction($db, CoachController::class, 'getCoachAthletes', $method, 'GET');
            break;

        case 'get_coach_pricing':
            planrunRouteControllerAction($db, CoachController::class, 'getCoachPricing', $method, 'GET');
            break;

        case 'update_coach_pricing':
            planrunRouteControllerAction($db, CoachController::class, 'updateCoachPricing', $method, 'POST');
            break;

        // CoachController — группы атлетов
        case 'get_coach_groups':
            planrunRouteControllerAction($db, CoachController::class, 'getCoachGroups', $method, 'GET');
            break;

        case 'save_coach_group':
            planrunRouteControllerAction($db, CoachController::class, 'saveCoachGroup', $method, 'POST');
            break;

        case 'delete_coach_group':
            planrunRouteControllerAction($db, CoachController::class, 'deleteCoachGroup', $method, 'POST');
            break;

        case 'get_group_members':
            planrunRouteControllerAction($db, CoachController::class, 'getGroupMembers', $method, 'GET');
            break;

        case 'update_group_members':
            planrunRouteControllerAction($db, CoachController::class, 'updateGroupMembers', $method, 'POST');
            break;

        case 'get_athlete_groups':
            planrunRouteControllerAction($db, CoachController::class, 'getAthleteGroups', $method, 'GET');
            break;

        // AdminController — заявки тренеров
        case 'admin_coach_applications':
            planrunRouteControllerAction($db, AdminController::class, 'getCoachApplications', $method, 'GET');
            break;

        case 'admin_approve_coach':
            planrunRouteControllerAction($db, AdminController::class, 'approveCoachApplication', $method, 'POST');
            break;

        case 'admin_reject_coach':
            planrunRouteControllerAction($db, AdminController::class, 'rejectCoachApplication', $method, 'POST');
            break;

        // NoteController — заметки к дням и неделям
        case 'get_day_notes':
            planrunRouteControllerAction($db, NoteController::class, 'getDayNotes', $method, 'GET');
            break;

        case 'save_day_note':
            planrunRouteControllerAction($db, NoteController::class, 'saveDayNote', $method, 'POST');
            break;

        case 'delete_day_note':
            planrunRouteControllerAction($db, NoteController::class, 'deleteDayNote', $method, 'POST');
            break;

        case 'get_week_notes':
            planrunRouteControllerAction($db, NoteController::class, 'getWeekNotes', $method, 'GET');
            break;

        case 'save_week_note':
            planrunRouteControllerAction($db, NoteController::class, 'saveWeekNote', $method, 'POST');
            break;

        case 'delete_week_note':
            planrunRouteControllerAction($db, NoteController::class, 'deleteWeekNote', $method, 'POST');
            break;

        case 'get_note_counts':
            planrunRouteControllerAction($db, NoteController::class, 'getNoteCounts', $method, 'GET');
            break;

        case 'get_plan_notifications':
            planrunRouteControllerAction($db, NoteController::class, 'getPlanNotifications', $method, 'GET');
            break;

        case 'mark_plan_notification_read':
            planrunRouteControllerAction($db, NoteController::class, 'markPlanNotificationRead', $method, 'POST');
            break;

        default:
            // Действие не найдено в новом API
            ErrorHandler::returnJsonError('Действие не найдено: ' . htmlspecialchars($action), 404);
            break;
    }
    
} catch (Exception $e) {
    Logger::exception($e);
    ErrorHandler::returnJsonError('Внутренняя ошибка сервера', 500);
}
