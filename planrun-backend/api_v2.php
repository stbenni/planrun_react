<?php
/**
 * API v2 - Рефакторинг api.php на контроллеры
 * 
 * ОСНОВНОЙ API - полностью заменил старый api.php
 * Все действия мигрированы на контроллеры
 */

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
require_once __DIR__ . '/config/RateLimiter.php';
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

try {
    $db = getDBConnection();
    if (!$db) {
        ErrorHandler::returnJsonError('Ошибка подключения к базе данных', 500);
    }
    
    // Получаем действие и метод
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
    
    // Rate limiting (для авторизованных пользователей)
    if (isAuthenticated()) {
        $currentUserId = getCurrentUserId();
        if ($currentUserId) {
            try {
                $rateLimitAction = 'default';
                if (strpos($action, 'plan') !== false) {
                    $rateLimitAction = 'plan_generation';
                }
                if (strpos($action, 'chat_send') !== false) {
                    $rateLimitAction = 'chat';
                }
                RateLimiter::checkApiLimit($currentUserId, $rateLimitAction);
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Превышен лимит') !== false) {
                    Logger::warning('Rate limit exceeded', [
                        'user_id' => $currentUserId,
                        'action' => $action
                    ]);
                    ErrorHandler::returnJsonError($e->getMessage(), 429);
                }
            }
        }
    }
    
    // Маршрутизация на контроллеры
    switch ($action) {
        // TrainingPlanController
        case 'load':
            $controller = new TrainingPlanController($db);
            $controller->load();
            break;
            
        case 'check_plan_status':
            $controller = new TrainingPlanController($db);
            $controller->checkStatus();
            break;
            
        case 'regenerate_plan':
            $controller = new TrainingPlanController($db);
            $controller->regeneratePlan();
            break;
            
        case 'regenerate_plan_with_progress':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new TrainingPlanController($db);
            $controller->regeneratePlanWithProgress();
            break;
            
        case 'clear_plan_generation_message':
            $controller = new TrainingPlanController($db);
            $controller->clearPlanGenerationMessage();
            break;
            
        // WorkoutController
        case 'get_day':
            $controller = new WorkoutController($db);
            $controller->getDay();
            break;
            
        case 'get_workout_timeline':
            $controller = new WorkoutController($db);
            $controller->getWorkoutTimeline();
            break;
            
        case 'save_result':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WorkoutController($db);
            $controller->saveResult();
            break;
            
        case 'get_result':
            $controller = new WorkoutController($db);
            $controller->getResult();
            break;
            
        case 'get_all_results':
            $controller = new WorkoutController($db);
            $controller->getAllResults();
            break;
            
        case 'delete_workout':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WorkoutController($db);
            $controller->deleteWorkout();
            break;
            
        case 'save':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WorkoutController($db);
            $controller->save();
            break;
            
        case 'reset':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WorkoutController($db);
            $controller->reset();
            break;
            
        // StatsController
        case 'stats':
            $controller = new StatsController($db);
            $controller->stats();
            break;
            
        case 'get_all_workouts_summary':
            $controller = new StatsController($db);
            $controller->getAllWorkoutsSummary();
            break;
            
        case 'prepare_weekly_analysis':
            $controller = new StatsController($db);
            $controller->prepareWeeklyAnalysis();
            break;
            
        // ExerciseController
        case 'add_day_exercise':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ExerciseController($db);
            $controller->addDayExercise();
            break;
            
        case 'update_day_exercise':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ExerciseController($db);
            $controller->updateDayExercise();
            break;
            
        case 'delete_day_exercise':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ExerciseController($db);
            $controller->deleteDayExercise();
            break;
            
        case 'reorder_day_exercises':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ExerciseController($db);
            $controller->reorderDayExercises();
            break;
            
        case 'list_exercise_library':
            $controller = new ExerciseController($db);
            $controller->listExerciseLibrary();
            break;
            
        // WeekController
        case 'add_week':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WeekController($db);
            $controller->addWeek();
            break;
            
        case 'delete_week':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WeekController($db);
            $controller->deleteWeek();
            break;
            
        case 'add_training_day':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WeekController($db);
            $controller->addTrainingDay();
            break;

        case 'add_training_day_by_date':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WeekController($db);
            $controller->addTrainingDayByDate();
            break;

        case 'update_training_day':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WeekController($db);
            $controller->updateTrainingDay();
            break;

        case 'delete_training_day':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new WeekController($db);
            $controller->deleteTrainingDay();
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
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->removeAvatar();
            break;
            
        case 'get_avatar':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->getAvatar();
            break;
            
        case 'update_privacy':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->updatePrivacy();
            break;
            
        case 'notifications_dismissed':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->getNotificationsDismissed();
            break;

        case 'notifications_dismiss':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->dismissNotification();
            break;

        case 'unlink_telegram':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new UserController($db);
            $controller->unlinkTelegram();
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
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AuthController($db);
            $controller->login();
            break;
            
        case 'logout':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AuthController($db);
            $controller->logout();
            break;
            
        case 'refresh_token':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AuthController($db);
            $controller->refreshToken();
            break;
            
        case 'check_auth':
            $controller = new AuthController($db);
            $controller->checkAuth();
            break;

        // AdminController
        case 'admin_list_users':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AdminController($db);
            $controller->listUsers();
            break;

        case 'admin_get_user':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AdminController($db);
            $controller->getUser();
            break;

        case 'admin_update_user':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AdminController($db);
            $controller->updateUser();
            break;

        case 'admin_get_settings':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AdminController($db);
            $controller->getSettings();
            break;

        case 'admin_update_settings':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new AdminController($db);
            $controller->updateSettings();
            break;

        case 'request_password_reset':
            $controller = new AuthController($db);
            $controller->requestPasswordReset();
            break;

        case 'confirm_password_reset':
            $controller = new AuthController($db);
            $controller->confirmPasswordReset();
            break;

        // ChatController
        case 'chat_get_messages':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->getMessages();
            break;

        case 'chat_send_message':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->sendMessage();
            break;

        case 'chat_send_message_stream':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->sendMessageStream();
            break;

        case 'chat_clear_ai':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->clearAiChat();
            break;

        case 'chat_mark_all_read':

        case 'chat_admin_mark_all_read':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->markAdminAllRead();
            break;

        case 'chat_mark_read':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->markRead();
            break;

        case 'chat_send_message_to_admin':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->sendMessageToAdmin();
            break;

        case 'chat_admin_send_message':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->sendAdminMessage();
            break;

        case 'chat_admin_chat_users':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->getAdminChatUsers();
            break;

        case 'chat_admin_unread_notifications':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->getAdminUnreadNotifications();
            break;

        case 'chat_admin_broadcast':
            if ($method !== 'POST') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->broadcastAdminMessage();
            break;

        case 'chat_admin_get_messages':
            if ($method !== 'GET') {
                ErrorHandler::returnJsonError('Метод не поддерживается', 405);
            }
            $controller = new ChatController($db);
            $controller->getAdminMessages();
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
