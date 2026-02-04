<?php
/**
 * Репозиторий для работы с планами тренировок
 */

require_once __DIR__ . '/BaseRepository.php';

class TrainingPlanRepository extends BaseRepository {
    
    /**
     * Получить план пользователя
     */
    public function getPlanByUserId($userId) {
        $sql = "SELECT * FROM user_training_plans WHERE user_id = ?";
        return $this->fetchOne($sql, [$userId], 'i');
    }
    
    /**
     * Обновить сообщение об ошибке
     */
    public function updateErrorMessage($userId, $errorMessage) {
        $sql = "UPDATE user_training_plans SET error_message = ? WHERE user_id = ?";
        return $this->execute($sql, [$errorMessage, $userId], 'si');
    }
    
    /**
     * Очистить сообщение об ошибке
     */
    public function clearErrorMessage($userId) {
        $sql = "UPDATE user_training_plans SET error_message = NULL WHERE user_id = ?";
        return $this->execute($sql, [$userId], 'i');
    }
    
    /**
     * Получить недели плана
     */
    public function getWeeksByUserId($userId) {
        $sql = "SELECT * FROM training_plan_weeks WHERE user_id = ? ORDER BY week_number";
        return $this->fetchAll($sql, [$userId], 'i');
    }
    
    /**
     * Получить дни недели
     */
    public function getDaysByWeekId($weekId, $userId) {
        $sql = "SELECT * FROM training_plan_days WHERE user_id = ? AND week_id = ? ORDER BY day_of_week";
        return $this->fetchAll($sql, [$userId, $weekId], 'ii');
    }
}
