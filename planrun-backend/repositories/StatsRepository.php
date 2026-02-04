<?php
/**
 * Репозиторий для работы со статистикой
 */

require_once __DIR__ . '/BaseRepository.php';

class StatsRepository extends BaseRepository {
    
    /**
     * Получить общее количество дней в плане
     */
    public function getTotalDays($userId) {
        $sql = "SELECT COUNT(*) as total FROM training_plan_days WHERE user_id = ?";
        $result = $this->fetchOne($sql, [$userId], 'i');
        return (int)($result['total'] ?? 0);
    }
    
    /**
     * Получить даты тренировок из workouts
     */
    public function getWorkoutDates($userId) {
        $sql = "SELECT DISTINCT DATE(start_time) as workout_date
                FROM workouts
                WHERE user_id = ?";
        $results = $this->fetchAll($sql, [$userId], 'i');
        return array_column($results, 'workout_date');
    }
    
    /**
     * Получить сводку тренировок по дням
     */
    public function getWorkoutsSummary($userId) {
        // Используем темп из первой тренировки дня (через MIN(id)) вместо AVG, 
        // так как AVG не работает правильно со строками "MM:SS"
        // Оптимизированный запрос: используем GROUP_CONCAT для получения темпа первой тренировки
        // Это быстрее, чем подзапросы или JOIN
        $sql = "SELECT 
                    DATE(start_time) as workout_date,
                    COUNT(*) as workout_count,
                    SUM(distance_km) as total_distance,
                    SUM(duration_minutes) as total_duration,
                    SUBSTRING_INDEX(GROUP_CONCAT(avg_pace ORDER BY start_time ASC, id ASC), ',', 1) as avg_pace,
                    AVG(avg_heart_rate) as avg_hr,
                    MIN(id) as first_workout_id
                FROM workouts
                WHERE user_id = ?
                GROUP BY DATE(start_time)";
        return $this->fetchAll($sql, [$userId], 'i');
    }
}
