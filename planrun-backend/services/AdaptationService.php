<?php
/**
 * Сервис для адаптации плана тренировок
 */

require_once __DIR__ . '/BaseService.php';

class AdaptationService extends BaseService {
    
    /**
     * Запустить недельную адаптацию
     * 
     * @param int $userId ID пользователя
     * @return array Результат адаптации
     * @throws Exception
     */
    public function runWeeklyAdaptation($userId) {
        try {
            require_once __DIR__ . '/../user_functions.php';
            require_once __DIR__ . '/../calendar_access.php';
            
            // Проверяем права доступа
            $access = getCalendarAccess();
            if (isset($access['error'])) {
                $this->throwException($access['error'], 403);
            }
            
            // Только администратор может запускать адаптацию
            $currentUser = getCurrentUser();
            if (!$currentUser || !isset($currentUser['role']) || $currentUser['role'] !== 'admin') {
                $this->throwException('Только администратор может запускать адаптацию плана', 403);
            }
            
            // TODO: Создать weekly_adaptation через PlanRun AI
            // Пока возвращаем ошибку, что не реализовано
            $this->throwException('Еженедельная адаптация пока не реализована через PlanRun AI', 501);
        } catch (Exception $e) {
            $this->throwException('Ошибка запуска адаптации: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
