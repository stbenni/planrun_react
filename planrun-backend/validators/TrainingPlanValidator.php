<?php
/**
 * Валидатор для планов тренировок
 */

require_once __DIR__ . '/BaseValidator.php';

class TrainingPlanValidator extends BaseValidator {
    
    /**
     * Валидация данных для регенерации плана
     */
    public function validateRegeneratePlan($data) {
        $this->errors = [];
        
        // userId должен быть числом
        if (isset($data['user_id'])) {
            $this->validateType($data['user_id'], 'int', 'user_id');
        }
        
        return !$this->hasErrors();
    }
    
    /**
     * Валидация данных для проверки статуса
     */
    public function validateCheckStatus($data) {
        $this->errors = [];
        
        if (isset($data['user_id'])) {
            $this->validateType($data['user_id'], 'int', 'user_id');
        }
        
        return !$this->hasErrors();
    }
}
