<?php
/**
 * Валидатор для недель плана
 */

require_once __DIR__ . '/BaseValidator.php';

class WeekValidator extends BaseValidator {
    
    /**
     * Валидация данных для добавления недели
     */
    public function validateAddWeek($data) {
        $this->errors = [];
        
        $this->validateRequired($data['week_number'] ?? null, 'week_number');
        $this->validateRequired($data['start_date'] ?? null, 'start_date');
        
        if (isset($data['week_number'])) {
            $this->validateType($data['week_number'], 'int', 'week_number');
            $this->validateRange($data['week_number'], 1, 1000, 'week_number');
        }
        
        if (isset($data['start_date'])) {
            $this->validateDate($data['start_date'], 'Y-m-d');
        }
        
        return !$this->hasErrors();
    }
    
    /**
     * Валидация данных для удаления недели
     */
    public function validateDeleteWeek($data) {
        $this->errors = [];
        
        $this->validateRequired($data['week_id'] ?? null, 'week_id');
        
        if (isset($data['week_id'])) {
            $this->validateType($data['week_id'], 'int', 'week_id');
        }
        
        return !$this->hasErrors();
    }
    
    /**
     * Валидация данных для добавления дня тренировки
     */
    public function validateAddTrainingDay($data) {
        $this->errors = [];
        
        $this->validateRequired($data['week_id'] ?? null, 'week_id');
        $this->validateRequired($data['day_of_week'] ?? null, 'day_of_week');
        $this->validateRequired($data['type'] ?? null, 'type');
        
        if (isset($data['week_id'])) {
            $this->validateType($data['week_id'], 'int', 'week_id');
        }
        
        if (isset($data['day_of_week'])) {
            $this->validateType($data['day_of_week'], 'int', 'day_of_week');
            $this->validateRange($data['day_of_week'], 1, 7, 'day_of_week');
        }
        
        if (isset($data['type'])) {
            $validTypes = ['rest', 'easy', 'long', 'tempo', 'interval', 'fartlek', 'marathon', 'control', 'race', 'other', 'free', 'sbu'];
            if (!in_array($data['type'], $validTypes)) {
                $this->addError('type', "Тип должен быть одним из: " . implode(', ', $validTypes));
            }
        }
        
        if (isset($data['date'])) {
            $this->validateDate($data['date'], 'Y-m-d');
        }
        
        return !$this->hasErrors();
    }

    /**
     * Валидация для добавления дня тренировки по дате (без week_id / day_of_week)
     */
    public function validateAddTrainingDayByDate($data) {
        $this->errors = [];
        
        $this->validateRequired($data['date'] ?? null, 'date');
        $this->validateRequired($data['type'] ?? null, 'type');
        
        if (isset($data['date'])) {
            $this->validateDate($data['date'], 'Y-m-d');
        }
        
        if (isset($data['type'])) {
            $validTypes = ['rest', 'easy', 'long', 'tempo', 'interval', 'fartlek', 'marathon', 'control', 'race', 'other', 'free', 'sbu'];
            if (!in_array($data['type'], $validTypes)) {
                $this->addError('type', "Тип должен быть одним из: " . implode(', ', $validTypes));
            }
        }
        
        return !$this->hasErrors();
    }

    /**
     * Валидация для обновления дня тренировки по id
     */
    public function validateUpdateTrainingDay($data) {
        $this->errors = [];
        $this->validateRequired($data['day_id'] ?? null, 'day_id');
        $this->validateRequired($data['type'] ?? null, 'type');
        if (isset($data['day_id'])) {
            $this->validateType($data['day_id'], 'int', 'day_id');
        }
        if (isset($data['type'])) {
            $validTypes = ['rest', 'easy', 'long', 'tempo', 'interval', 'fartlek', 'marathon', 'control', 'race', 'other', 'free', 'sbu'];
            if (!in_array($data['type'], $validTypes)) {
                $this->addError('type', "Тип должен быть одним из: " . implode(', ', $validTypes));
            }
        }
        return !$this->hasErrors();
    }
}
