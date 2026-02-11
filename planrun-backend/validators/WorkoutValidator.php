<?php
/**
 * Валидатор для тренировок
 */

require_once __DIR__ . '/BaseValidator.php';

class WorkoutValidator extends BaseValidator {
    
    /**
     * Валидация данных для получения дня
     */
    public function validateGetDay($data) {
        $this->errors = [];
        
        $this->validateRequired($data['date'] ?? null, 'date');
        if (isset($data['date'])) {
            $this->validateDate($data['date'], 'Y-m-d');
        }
        
        return !$this->hasErrors();
    }
    
    /**
     * Валидация данных для сохранения результата
     */
    public function validateSaveResult($data) {
        $this->errors = [];
        
        $this->validateRequired($data['date'] ?? null, 'date');
        $this->validateRequired($data['week'] ?? null, 'week');
        $this->validateRequired($data['day'] ?? null, 'day');
        if (isset($data['activity_type_id'])) {
            $this->validateType($data['activity_type_id'], 'int', 'activity_type_id');
        }
        
        if (isset($data['date'])) {
            $this->validateDate($data['date'], 'Y-m-d');
        }
        
        if (isset($data['week'])) {
            $this->validateType($data['week'], 'int', 'week');
            $this->validateRange($data['week'], 1, 1000, 'week');
        }
        
        return !$this->hasErrors();
    }
}
