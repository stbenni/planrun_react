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
     * Получить неделю по номеру
     */
    public function getWeekByWeekNumber($userId, $weekNumber) {
        $sql = "SELECT * FROM training_plan_weeks WHERE user_id = ? AND week_number = ? LIMIT 1";
        return $this->fetchOne($sql, [$userId, (int)$weekNumber], 'ii');
    }

    /**
     * Получить неделю по дате (start_date <= date <= start_date+6)
     */
    public function getWeekByDate($userId, $date) {
        $sql = "SELECT * FROM training_plan_weeks 
                WHERE user_id = ? AND start_date <= ? AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ?
                ORDER BY start_date DESC LIMIT 1";
        return $this->fetchOne($sql, [$userId, $date, $date], 'iss');
    }

    /**
     * Получить неделю по дате понедельника (start_date)
     */
    public function getWeekByStartDate($userId, $startDate) {
        $sql = "SELECT * FROM training_plan_weeks WHERE user_id = ? AND start_date = ? LIMIT 1";
        return $this->fetchOne($sql, [$userId, $startDate], 'is');
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

    /**
     * Обновить день тренировки по id.
     * Пустое описание не затирает существующее (важно для ОФП/СБУ, где контент в description).
     */
    public function updateTrainingDayById($dayId, $userId, $data) {
        $sql = "UPDATE training_plan_days SET type = ?, description = COALESCE(NULLIF(?, ''), description), is_key_workout = ? WHERE id = ? AND user_id = ?";
        $type = $data['type'] ?? '';
        $description = isset($data['description']) ? (string) $data['description'] : '';
        $isKey = isset($data['is_key_workout']) ? (int) (bool) $data['is_key_workout'] : 0;
        return $this->execute($sql, [$type, $description, $isKey, $dayId, $userId], 'ssiii');
    }

    /**
     * Удалить день тренировки по id
     */
    public function deleteTrainingDayById($dayId, $userId) {
        $sql = "DELETE FROM training_plan_days WHERE id = ? AND user_id = ?";
        return $this->execute($sql, [$dayId, $userId], 'ii');
    }

    /**
     * Получить все дни тренировок на дату (несколько записей на дату разрешены)
     */
    public function getDaysByDate($date, $userId) {
        $sql = "SELECT * FROM training_plan_days WHERE user_id = ? AND date = ? ORDER BY id";
        return $this->fetchAll($sql, [$userId, $date], 'is');
    }

    /**
     * Получить все плановые дни недели
     */
    public function getDaysByWeekId($userId, $weekId) {
        $sql = "SELECT id, day_of_week, type, description, date, is_key_workout 
                FROM training_plan_days 
                WHERE user_id = ? AND week_id = ? 
                ORDER BY day_of_week, id";
        return $this->fetchAll($sql, [$userId, (int)$weekId], 'ii');
    }
}
