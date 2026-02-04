<?php
/**
 * Репозиторий для работы с упражнениями
 */

require_once __DIR__ . '/BaseRepository.php';

class ExerciseRepository extends BaseRepository {
    
    /**
     * Получить упражнения дня
     */
    public function getExercisesByDayId($planDayId, $userId) {
        $sql = "SELECT id, exercise_id, category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index
                FROM training_day_exercises
                WHERE user_id = ? AND plan_day_id = ?
                ORDER BY order_index ASC, id ASC";
        return $this->fetchAll($sql, [$userId, $planDayId], 'ii');
    }
    
    /**
     * Добавить упражнение
     */
    public function addExercise($data, $userId) {
        $sql = "INSERT INTO training_day_exercises 
                (user_id, plan_day_id, exercise_id, category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $userId,
            $data['plan_day_id'],
            $data['exercise_id'] ?? null,
            $data['category'],
            $data['name'],
            $data['sets'] ?? null,
            $data['reps'] ?? null,
            $data['distance_m'] ?? null,
            $data['duration_sec'] ?? null,
            $data['weight_kg'] ?? null,
            $data['pace'] ?? null,
            $data['notes'] ?? null,
            $data['order_index'] ?? 0
        ];
        
        return $this->execute($sql, $params, 'iiisssiiisssi');
    }
    
    /**
     * Обновить упражнение
     */
    public function updateExercise($exerciseId, $data, $userId) {
        $sql = "UPDATE training_day_exercises 
                SET category = ?, name = ?, sets = ?, reps = ?, distance_m = ?, duration_sec = ?, weight_kg = ?, pace = ?, notes = ?, order_index = ?
                WHERE id = ? AND user_id = ?";
        
        $params = [
            $data['category'],
            $data['name'],
            $data['sets'] ?? null,
            $data['reps'] ?? null,
            $data['distance_m'] ?? null,
            $data['duration_sec'] ?? null,
            $data['weight_kg'] ?? null,
            $data['pace'] ?? null,
            $data['notes'] ?? null,
            $data['order_index'] ?? 0,
            $exerciseId,
            $userId
        ];
        
        return $this->execute($sql, $params, 'ssssiiisssii');
    }
    
    /**
     * Удалить упражнение
     */
    public function deleteExercise($exerciseId, $userId) {
        $sql = "DELETE FROM training_day_exercises WHERE id = ? AND user_id = ?";
        return $this->execute($sql, [$exerciseId, $userId], 'ii');
    }
    
    /**
     * Получить библиотеку упражнений
     */
    public function getExerciseLibrary() {
        $sql = "SELECT * FROM exercise_library ORDER BY category, name";
        return $this->fetchAll($sql, [], '');
    }
}
