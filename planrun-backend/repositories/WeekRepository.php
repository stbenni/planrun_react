<?php
/**
 * Репозиторий для работы с неделями плана
 */

require_once __DIR__ . '/BaseRepository.php';

class WeekRepository extends BaseRepository {
    
    /**
     * Получить неделю по ID
     */
    public function getWeekById($weekId, $userId) {
        $sql = "SELECT * FROM training_plan_weeks WHERE id = ? AND user_id = ?";
        return $this->fetchOne($sql, [$weekId, $userId], 'ii');
    }
    
    /**
     * Получить максимальный номер недели для пользователя
     */
    public function getMaxWeekNumber($userId) {
        $sql = "SELECT MAX(week_number) as max_week FROM training_plan_weeks WHERE user_id = ?";
        $result = $this->fetchOne($sql, [$userId], 'i');
        return (int)($result['max_week'] ?? 0);
    }
    
    /**
     * Добавить неделю
     */
    public function addWeek($data, $userId) {
        $sql = "INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume)
                VALUES (?, ?, ?, ?)";
        
        $params = [
            $userId,
            $data['week_number'],
            $data['start_date'],
            $data['total_volume'] ?? null
        ];
        
        return $this->execute($sql, $params, 'iiss');
    }
    
    /**
     * Удалить неделю
     */
    public function deleteWeek($weekId, $userId) {
        $sql = "DELETE FROM training_plan_weeks WHERE id = ? AND user_id = ?";
        return $this->execute($sql, [$weekId, $userId], 'ii');
    }
    
    /**
     * Получить день тренировки по дате
     */
    public function getDayByDate($date, $userId) {
        $sql = "SELECT * FROM training_plan_days WHERE user_id = ? AND date = ? LIMIT 1";
        return $this->fetchOne($sql, [$userId, $date], 'is');
    }
    
    /**
     * Добавить день тренировки
     */
    public function addTrainingDay($data, $userId) {
        $sql = "INSERT INTO training_plan_days 
                (user_id, week_id, day_of_week, type, description, date, is_key_workout)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $userId,
            $data['week_id'],
            $data['day_of_week'],
            $data['type'],
            $data['description'] ?? null,
            $data['date'] ?? null,
            $data['is_key_workout'] ?? 0
        ];
        
        return $this->execute($sql, $params, 'iiisssi');
    }
}
