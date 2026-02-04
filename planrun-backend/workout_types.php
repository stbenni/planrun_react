<?php
/**
 * Функции для работы с типами тренировок
 * Обновлено: добавлена поддержка различных типов тренировок
 * 
 * Используется для:
 * - Получения списка доступных типов тренировок
 * - Определения типа тренировки по ID
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/cache_config.php';
require_once __DIR__ . '/config/Logger.php';

/**
 * Получить все активные типы тренировок
 * С поддержкой кеширования (типы меняются редко)
 * 
 * @param bool $useCache Использовать ли кеш (по умолчанию true)
 * @return array Массив типов тренировок с полями: id, name, icon, color
 */
function getActivityTypes($useCache = true) {
    // Пытаемся получить из кеша (кеш на 1 час - типы меняются редко)
    if ($useCache) {
        $cached = Cache::get('activity_types');
        if ($cached !== null) {
            return $cached;
        }
    }
    
    $db = getDBConnection();
    if (!$db) return [];
    
    try {
        $stmt = $db->prepare("SELECT id, name, icon, color FROM activity_types WHERE is_active = TRUE ORDER BY sort_order, name");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $types = [];
        while ($row = $result->fetch_assoc()) {
            $types[] = $row;
        }
        $stmt->close();
        
        // Кешируем результат (1 час)
        if ($useCache) {
            Cache::set('activity_types', $types, 3600);
        }
        
        return $types;
    } catch (Exception $e) {
        Logger::error("Error loading activity types", ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Получить тип тренировки по ID
 */
function getActivityType($id) {
    $db = getDBConnection();
    if (!$db) return null;
    
    $stmt = $db->prepare("SELECT * FROM activity_types WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $type = $result->fetch_assoc();
    $stmt->close();
    
    return $type;
}









