<?php
/**
 * Rate Limiting для защиты API от злоупотреблений
 * 
 * Использует кеш для хранения счетчиков запросов
 */

require_once __DIR__ . '/../cache_config.php';

class RateLimiter {
    /**
     * Проверка лимита запросов
     * 
     * @param string $key Уникальный ключ (например, "api_user_1")
     * @param int $maxRequests Максимальное количество запросов
     * @param int $window Окно времени в секундах
     * @return bool true если лимит не превышен, false если превышен
     * @throws Exception Если лимит превышен
     */
    public static function check($key, $maxRequests = 60, $window = 60) {
        try {
            $cache = getCache();
        } catch (Exception $e) {
            // Если кеш не работает, пропускаем rate limiting
            return true;
        }
        
        $cacheKey = "rate_limit_{$key}";
        
        // Получаем текущий счетчик
        try {
            $data = $cache->get($cacheKey);
        } catch (Exception $e) {
            // Если не удалось получить из кеша, пропускаем проверку
            return true;
        }
        
        if ($data === null) {
            // Первый запрос в окне
            $data = [
                'count' => 1,
                'reset_at' => time() + $window
            ];
            try {
                $cache->set($cacheKey, $data, $window);
            } catch (Exception $e) {
                // Если не удалось сохранить, пропускаем
            }
            return true;
        }
        
        // Проверяем, не истекло ли окно
        if (time() >= $data['reset_at']) {
            // Окно истекло, начинаем заново
            $data = [
                'count' => 1,
                'reset_at' => time() + $window
            ];
            try {
                $cache->set($cacheKey, $data, $window);
            } catch (Exception $e) {
                // Если не удалось сохранить, пропускаем
            }
            return true;
        }
        
        // Увеличиваем счетчик
        $data['count']++;
        
        if ($data['count'] > $maxRequests) {
            // Лимит превышен
            $remaining = $data['reset_at'] - time();
            throw new Exception("Превышен лимит запросов. Попробуйте через {$remaining} секунд.");
        }
        
        // Сохраняем обновленный счетчик
        $remainingTtl = $data['reset_at'] - time();
        try {
            $cache->set($cacheKey, $data, $remainingTtl);
        } catch (Exception $e) {
            // Если не удалось сохранить, продолжаем работу
        }
        
        return true;
    }
    
    /**
     * Получить информацию о текущем лимите
     * 
     * @param string $key Ключ лимита
     * @return array|null Информация о лимите или null если нет данных
     */
    public static function getInfo($key) {
        $cache = getCache();
        $cacheKey = "rate_limit_{$key}";
        $data = $cache->get($cacheKey);
        
        if ($data === null) {
            return null;
        }
        
        return [
            'count' => $data['count'],
            'reset_at' => $data['reset_at'],
            'remaining' => max(0, $data['reset_at'] - time())
        ];
    }
    
    /**
     * Сброс лимита для ключа
     * 
     * @param string $key Ключ лимита
     */
    public static function reset($key) {
        $cache = getCache();
        $cacheKey = "rate_limit_{$key}";
        $cache->delete($cacheKey);
    }
    
    /**
     * Предустановленные лимиты для разных типов операций
     */
    public static function checkApiLimit($userId, $action = 'default') {
        $limits = [
            'default' => ['max' => 100, 'window' => 60],      // 100 запросов в минуту
            'plan_generation' => ['max' => 5, 'window' => 3600],  // 5 запросов в час
            'adaptation' => ['max' => 3, 'window' => 3600],      // 3 запроса в час (было 1 в день)
            'upload' => ['max' => 20, 'window' => 60],         // 20 загрузок в минуту
            'login' => ['max' => 5, 'window' => 300],          // 5 попыток входа в 5 минут
        ];
        
        $limit = $limits[$action] ?? $limits['default'];
        $key = "api_user_{$userId}_action_{$action}";
        
        return self::check($key, $limit['max'], $limit['window']);
    }
}

