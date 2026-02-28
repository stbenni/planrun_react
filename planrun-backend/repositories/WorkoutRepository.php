<?php
/**
 * Репозиторий для работы с тренировками
 */

require_once __DIR__ . '/BaseRepository.php';

class WorkoutRepository extends BaseRepository {
    
    /**
     * Получить все результаты тренировок пользователя
     */
    public function getAllResults($userId) {
        // Оптимизированный запрос: используем индекс по user_id и training_date
        $sql = "SELECT training_date, week_number, day_name, result_time, distance_km as result_distance, 
                pace as result_pace, notes, created_at as completed_at 
                FROM workout_log 
                WHERE user_id = ? AND (result_time IS NOT NULL OR notes IS NOT NULL) 
                ORDER BY training_date DESC
                LIMIT 1000";
        return $this->fetchAll($sql, [$userId], 'i');
    }
    
    /**
     * Получить результат тренировки за день
     */
    public function getResultByDate($userId, $date, $weekNumber, $dayName) {
        $sql = "SELECT result_time, distance_km as result_distance, pace as result_pace, notes 
                FROM workout_log 
                WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?";
        return $this->fetchOne($sql, [$userId, $date, $weekNumber, $dayName], 'isis');
    }
    
    /**
     * Получить тренировки за день
     */
    public function getWorkoutsByDate($userId, $dateStart, $dateEnd) {
        $sql = "SELECT id, user_id, activity_type, source, start_time, duration_minutes, duration_seconds, 
                distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain
                FROM workouts 
                WHERE user_id = ? AND start_time >= ? AND start_time <= ?
                ORDER BY start_time ASC
                LIMIT 20";
        return $this->fetchAll($sql, [$userId, $dateStart, $dateEnd], 'iss');
    }
}
