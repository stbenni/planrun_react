<?php
/**
 * Валидатор для упражнений
 */

require_once __DIR__ . '/BaseValidator.php';

class ExerciseValidator extends BaseValidator {
    
    /**
     * Валидация данных для добавления упражнения
     */
    public function validateAddExercise($data) {
        $this->errors = [];
        
        $this->validateRequired($data['plan_day_id'] ?? null, 'plan_day_id');
        $this->validateRequired($data['category'] ?? null, 'category');
        $this->validateRequired($data['name'] ?? null, 'name');
        
        if (isset($data['plan_day_id'])) {
            $this->validateType($data['plan_day_id'], 'int', 'plan_day_id');
        }
        
        if (isset($data['category'])) {
            $validCategories = ['run', 'strength', 'cardio', 'flexibility', 'other'];
            if (!in_array($data['category'], $validCategories)) {
                $this->addError('category', "Категория должна быть одной из: " . implode(', ', $validCategories));
            }
        }
        
        if (isset($data['name'])) {
            $this->validateLength($data['name'], 1, 255, 'name');
        }
        
        return !$this->hasErrors();
    }
    
    /**
     * Валидация данных для обновления упражнения
     */
    public function validateUpdateExercise($data) {
        $this->errors = [];
        
        $this->validateRequired($data['exercise_id'] ?? null, 'exercise_id');
        
        if (isset($data['exercise_id'])) {
            $this->validateType($data['exercise_id'], 'int', 'exercise_id');
        }
        
        if (isset($data['category'])) {
            $validCategories = ['run', 'strength', 'cardio', 'flexibility', 'other'];
            if (!in_array($data['category'], $validCategories)) {
                $this->addError('category', "Категория должна быть одной из: " . implode(', ', $validCategories));
            }
        }
        
        return !$this->hasErrors();
    }
    
    /**
     * Валидация данных для удаления упражнения
     */
    public function validateDeleteExercise($data) {
        $this->errors = [];
        
        $this->validateRequired($data['exercise_id'] ?? null, 'exercise_id');
        
        if (isset($data['exercise_id'])) {
            $this->validateType($data['exercise_id'], 'int', 'exercise_id');
        }
        
        return !$this->hasErrors();
    }
    
    /**
     * Валидация данных для изменения порядка упражнений
     */
    public function validateReorderExercises($data) {
        $this->errors = [];
        
        $this->validateRequired($data['plan_day_id'] ?? null, 'plan_day_id');
        $this->validateRequired($data['exercise_ids'] ?? null, 'exercise_ids');
        
        if (isset($data['plan_day_id'])) {
            $this->validateType($data['plan_day_id'], 'int', 'plan_day_id');
        }
        
        if (isset($data['exercise_ids'])) {
            $this->validateType($data['exercise_ids'], 'array', 'exercise_ids');
        }
        
        return !$this->hasErrors();
    }
}
