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
                    SUM(duration_seconds) as total_duration_seconds,
                    SUBSTRING_INDEX(GROUP_CONCAT(avg_pace ORDER BY start_time ASC, id ASC), ',', 1) as avg_pace,
                    AVG(avg_heart_rate) as avg_hr,
                    MIN(id) as first_workout_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(TRIM(activity_type), ''), 'running') ORDER BY start_time ASC, id ASC), ',', 1) as activity_type
                FROM workouts
                WHERE user_id = ?
                GROUP BY DATE(start_time)";
        return $this->fetchAll($sql, [$userId], 'i');
    }

    /**
     * Получить сводку ручных тренировок по дням из workout_log
     * Структура совместима с getWorkoutsSummary для слияния в StatsService
     */
    public function getWorkoutLogSummary($userId) {
        $sql = "SELECT 
                    wl.training_date as workout_date,
                    COUNT(*) as workout_count,
                    SUM(wl.distance_km) as total_distance,
                    SUM(wl.duration_minutes) as total_duration,
                    NULL as total_duration_seconds,
                    SUBSTRING_INDEX(GROUP_CONCAT(wl.pace ORDER BY wl.id ASC), ',', 1) as avg_pace,
                    AVG(wl.avg_heart_rate) as avg_hr,
                    MIN(wl.id) as first_workout_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(TRIM(LOWER(at.name)), ''), 'running') ORDER BY wl.id ASC), ',', 1) as activity_type
                FROM workout_log wl
                LEFT JOIN activity_types at ON wl.activity_type_id = at.id
                WHERE wl.user_id = ? AND wl.is_completed = 1
                GROUP BY wl.training_date";
        return $this->fetchAll($sql, [$userId], 'i');
    }
}
