<?php
/**
 * Проверка доступа к календарю пользователя
 * Система персональных URL как ВКонтакте
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/user_functions.php';
require_once __DIR__ . '/query_helpers.php';

/**
 * Определяет чей календарь просматривается и права доступа
 * 
 * @return array ['user_id' => int, 'can_edit' => bool, 'can_view' => bool, 'is_owner' => bool]
 */
function getCalendarAccess() {
    $db = getDBConnection();
    $currentUserId = getCurrentUserId();
    
    // Проверяем URL: через GET параметры (от .htaccess)
    $view = $_GET['view'] ?? '';
    
    if ($view === 'user' && isset($_GET['slug'])) {
        // Формат: /@st_benni или /st_benni
        $usernameSlug = $_GET['slug'];
        $token = $_GET['token'] ?? null; // Токен для доступа по ссылке
        
        $stmt = $db->prepare("SELECT id, privacy_level, public_token FROM users WHERE username_slug = ?");
        $stmt->bind_param("s", $usernameSlug);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $targetUserId = $row['id'];
            $privacyLevel = $row['privacy_level'] ?? 'link';
            
            // Проверяем уровень конфиденциальности
            if ($privacyLevel === 'private') {
                // Приватный - только тренер или владелец
                if ($targetUserId != $currentUserId) {
                    // Проверяем, является ли текущий пользователь тренером
                    // Используем централизованную функцию из query_helpers.php
                    if ($currentUserId) {
                        if (!isUserCoach($db, $targetUserId, $currentUserId)) {
                            return ['error' => 'Календарь приватный. Доступ имеют только тренеры.'];
                        }
                    } else {
                        return ['error' => 'Календарь приватный. <a href="login.php">Войдите</a> как тренер для доступа.'];
                    }
                }
            } elseif ($privacyLevel === 'link') {
                // Доступно по ссылке - нужен токен
                if ($targetUserId != $currentUserId) {
                    if (!$token || $token !== $row['public_token']) {
                        return ['error' => 'Для доступа к этому календарю нужна специальная ссылка с токеном.'];
                    }
                }
            }
            // 'public' - доступно всем, проверка не нужна
            
        } else {
            return ['error' => 'Пользователь не найден'];
        }
        
    } elseif ($view === 'user_id' && isset($_GET['id'])) {
        // Формат: /user/123
        $targetUserId = (int)$_GET['id'];
        
    } else {
        // Нет параметров - свой календарь
        $targetUserId = $currentUserId;
        
        // Если не авторизован и нет параметров - это ошибка
        if (!$currentUserId) {
            return ['error' => 'Требуется авторизация'];
        }
    }
    
    // Проверяем права доступа
    $isOwner = ($targetUserId === $currentUserId);
    $canEdit = $isOwner;
    $canView = true; // По умолчанию можем просматривать (через персональную ссылку)
    
    // Проверяем, является ли текущий пользователь тренером целевого пользователя
    // Используем централизованную функцию из query_helpers.php
    if (!$isOwner && $currentUserId) {
        $coachAccess = getUserCoachAccess($db, $targetUserId, $currentUserId);
        
        if ($coachAccess) {
            $canEdit = $coachAccess['can_edit'];
            $canView = $coachAccess['can_view'];
        }
    }
    
    // Если не авторизован - можно только просматривать, редактировать нельзя
    if (!$currentUserId) {
        $canEdit = false;
    }
    
    return [
        'user_id' => $targetUserId,
        'can_edit' => $canEdit,
        'can_view' => $canView,
        'is_owner' => $isOwner,
        'is_coach' => (!$isOwner && $canEdit)
    ];
}

/**
 * Получить информацию о пользователе календаря
 */
function getCalendarUser($userId) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT id, username, username_slug, email, privacy_level, public_token, avatar_path, telegram_id, telegram_link_code, telegram_link_code_expires FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Генерировать персональный URL календаря
 * Возвращает относительный путь: /@username_slug
 */
function getUserCalendarUrl($userId) {
    $user = getCalendarUser($userId);
    if (!$user) return null;
    
    // Если username_slug отсутствует, генерируем его из username
    if (empty($user['username_slug']) && !empty($user['username'])) {
        $db = getDBConnection();
        $slug = mb_strtolower($user['username'], 'UTF-8');
        $slug = preg_replace('/[^a-z0-9_]/', '_', $slug);
        $slug = preg_replace('/_+/', '_', $slug);
        $slug = trim($slug, '_');
        
        // Проверяем уникальность
        $checkStmt = $db->prepare("SELECT id FROM users WHERE username_slug = ? AND id != ?");
        $checkStmt->bind_param("si", $slug, $userId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($existing) {
            // Если занят, добавляем число
            $counter = 1;
            $originalSlug = $slug;
            do {
                $slug = $originalSlug . '_' . $counter;
                $checkStmt = $db->prepare("SELECT id FROM users WHERE username_slug = ? AND id != ?");
                $checkStmt->bind_param("si", $slug, $userId);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();
                $counter++;
            } while ($existing);
        }
        
        // Сохраняем в БД
        $updateStmt = $db->prepare("UPDATE users SET username_slug = ? WHERE id = ?");
        $updateStmt->bind_param("si", $slug, $userId);
        $updateStmt->execute();
        $updateStmt->close();
        
        $user['username_slug'] = $slug;
    }
    
    if (empty($user['username_slug'])) {
        return null;
    }
    
    return '/@' . $user['username_slug'];
}

/**
 * Генерировать красивый URL для деталей тренировки
 * Формат: @username/YYYY-MM-DD/workout-id
 * Относительный путь без привязки к домену
 */
function getWorkoutDetailsUrl($workoutId, $userId = null) {
    $db = getDBConnection();
    
    // Если userId не передан, получаем из тренировки
    if ($userId === null) {
        $stmt = $db->prepare("SELECT user_id, start_time FROM workouts WHERE id = ?");
        $stmt->bind_param("i", $workoutId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$result) {
            // Fallback на старый формат
            return 'workout_details.php?id=' . $workoutId;
        }
        
        $userId = $result['user_id'];
        $workoutDate = date('Y-m-d', strtotime($result['start_time']));
    } else {
        // Получаем дату тренировки
        $stmt = $db->prepare("SELECT start_time FROM workouts WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $workoutId, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$result) {
            return 'workout_details.php?id=' . $workoutId;
        }
        
        $workoutDate = date('Y-m-d', strtotime($result['start_time']));
    }
    
    // Получаем username пользователя
    $user = getCalendarUser($userId);
    if (!$user) {
        return 'workout_details.php?id=' . $workoutId;
    }
    
    return '@' . $user['username_slug'] . '/' . $workoutDate . '/' . $workoutId;
}

