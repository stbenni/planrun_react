<?php
/**
 * Функции для работы с пользователями в многопользовательской системе
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/error_handler.php';
require_once __DIR__ . '/cache_config.php';
require_once __DIR__ . '/config/Logger.php';

/**
 * Получить данные пользователя по ID (оптимизированная версия с кешированием)
 * Загружает только необходимые поля вместо SELECT *
 * 
 * @param int $userId ID пользователя
 * @param string|null $fields Список полей для загрузки (null = по умолчанию)
 * @param bool $useCache Использовать ли кеш (по умолчанию true)
 * @return array|null Данные пользователя или null
 */
function getUserData($userId, $fields = null, $useCache = true) {
    if (empty($userId)) {
        return null;
    }
    
    // Формируем ключ кеша
    $cacheKey = $fields === null 
        ? "user_data_{$userId}" 
        : "user_data_{$userId}_" . md5($fields);
    
    // Пытаемся получить из кеша
    if ($useCache) {
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Logger::debug("User data loaded from cache", ['user_id' => $userId]);
            return $cached;
        }
    }
    
    $db = getDBConnection();
    
    // По умолчанию загружаем только часто используемые поля
    if ($fields === null) {
        $fields = 'id, username, email, role, goal_type, race_date, race_target_time, race_distance, 
                   target_marathon_date, target_marathon_time, training_start_date, 
                   weekly_base_km, experience_level, gender, birth_year, height_cm, weight_kg, 
                   timezone, telegram_id, created_at, updated_at, training_mode, 
                   ofp_preference, preferred_days, preferred_ofp_days, sessions_per_week, 
                   has_treadmill, training_time_pref, easy_pace_sec, 
                   running_experience, last_race_date, last_race_time, last_race_distance, 
                   last_race_distance_km, is_first_race_at_distance, weight_goal_kg, 
                   weight_goal_date, health_program, health_notes, current_running_level, 
                   health_plan_weeks, device_type, avatar_path, privacy_level';
    }
    
    try {
        $stmt = $db->prepare("SELECT $fields FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Кешируем результат (30 минут)
        if ($user && $useCache) {
            Cache::set($cacheKey, $user, 1800);
            Logger::debug("User data loaded from DB and cached", ['user_id' => $userId]);
        }
        
        return $user;
    } catch (Exception $e) {
        Logger::error("Error loading user data", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return null;
    }
}

/**
 * Получить текущего авторизованного пользователя
 * С кешированием в сессии для оптимизации
 */
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Проверяем кеш в сессии
    if (isset($_SESSION['user_cache']) && 
        isset($_SESSION['user_cache']['id']) && 
        $_SESSION['user_cache']['id'] === $userId) {
        // Если в кеше нет role, перезагружаем данные
        if (!isset($_SESSION['user_cache']['role'])) {
            unset($_SESSION['user_cache']);
        } else {
            return $_SESSION['user_cache'];
        }
    }
    
    // Загружаем из БД (без кеша, чтобы получить актуальные данные с role)
    $user = getUserData($userId, null, false);
    
    // Кешируем в сессии
    if ($user) {
        $_SESSION['user_cache'] = $user;
        $_SESSION['user_cache']['id'] = $userId;
    }
    
    return $user;
}

/**
 * Очистить кеш пользователя в сессии и в системе кеширования
 * Вызывать после обновления данных пользователя
 * 
 * @param int|null $userId ID пользователя (если null - очищает текущего)
 */
function clearUserCache($userId = null) {
    // Очищаем кеш в сессии
    unset($_SESSION['user_cache']);
    
    // Очищаем кеш в системе кеширования
    if ($userId === null) {
        $userId = getCurrentUserId();
    }
    
    if ($userId) {
        Cache::invalidate("user_data_{$userId}*");
        Logger::debug("User cache cleared", ['user_id' => $userId]);
    }
}

/**
 * Получить ID текущего пользователя
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null; // NULL если не авторизован
}

/**
 * Получить пользователя по Telegram ID
 */
function getUserByTelegramId($telegramId) {
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT id, username, email, goal_type, race_date, race_target_time, 
                                 race_distance, target_marathon_date, target_marathon_time, 
                                 training_start_date, weekly_base_km, experience_level, 
                                 gender, birth_year, height_cm, weight_kg, timezone, 
                                 telegram_id, created_at, updated_at, training_mode, 
                                 ofp_preference, preferred_days, sessions_per_week, 
                                 easy_pace_sec, running_experience, last_race_date, 
                                 last_race_time, weight_goal_kg, weight_goal_date, 
                                 health_program, current_running_level, health_plan_weeks 
                          FROM users WHERE telegram_id = ?');
    $stmt->bind_param('i', $telegramId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $user;
}

/**
 * Получить активный план пользователя
 */
function getUserActivePlan($userId) {
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT * FROM user_training_plans WHERE user_id = ? AND is_active = TRUE LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $plan;
}

/**
 * Получить часовой пояс пользователя
 */
function getUserTimezone($userId) {
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT timezone FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result['timezone'] ?? 'Europe/Moscow';
}

/**
 * Генерировать уникальный username_slug из username
 * 
 * @param string $username Имя пользователя
 * @param int|null $excludeUserId ID пользователя для исключения из проверки уникальности (для обновления)
 * @return string Уникальный slug
 */
function generateUsernameSlug($username, $excludeUserId = null) {
    $db = getDBConnection();
    
    // Генерируем slug
    $usernameSlug = mb_strtolower($username, 'UTF-8');
    $usernameSlug = preg_replace('/[^a-z0-9_]/', '_', $usernameSlug);
    $usernameSlug = preg_replace('/_+/', '_', $usernameSlug);
    $usernameSlug = trim($usernameSlug, '_');
    
    // Проверяем уникальность slug
    if ($excludeUserId !== null) {
        $checkStmt = $db->prepare('SELECT id FROM users WHERE username_slug = ? AND id != ?');
        $checkStmt->bind_param('si', $usernameSlug, $excludeUserId);
    } else {
        $checkStmt = $db->prepare('SELECT id FROM users WHERE username_slug = ?');
        $checkStmt->bind_param('s', $usernameSlug);
    }
    
    $checkStmt->execute();
    $counter = 1;
    $originalSlug = $usernameSlug;
    
    while ($checkStmt->get_result()->fetch_assoc()) {
        $usernameSlug = $originalSlug . '_' . $counter;
        $checkStmt->close();
        
        if ($excludeUserId !== null) {
            $checkStmt = $db->prepare('SELECT id FROM users WHERE username_slug = ? AND id != ?');
            $checkStmt->bind_param('si', $usernameSlug, $excludeUserId);
        } else {
            $checkStmt = $db->prepare('SELECT id FROM users WHERE username_slug = ?');
            $checkStmt->bind_param('s', $usernameSlug);
        }
        $checkStmt->execute();
        $counter++;
    }
    
    $checkStmt->close();
    
    return $usernameSlug;
}

