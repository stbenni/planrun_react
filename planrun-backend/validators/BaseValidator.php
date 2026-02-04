<?php
/**
 * Базовый класс для валидаторов
 */

class BaseValidator {
    
    protected $errors = [];
    
    /**
     * Добавить ошибку
     */
    protected function addError($field, $message) {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }
    
    /**
     * Проверить, есть ли ошибки
     */
    public function hasErrors() {
        return !empty($this->errors);
    }
    
    /**
     * Получить все ошибки
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Получить первую ошибку
     */
    public function getFirstError() {
        foreach ($this->errors as $field => $messages) {
            return $messages[0];
        }
        return null;
    }
    
    /**
     * Валидация обязательного поля
     */
    protected function validateRequired($value, $fieldName) {
        if (empty($value) && $value !== '0' && $value !== 0) {
            $this->addError($fieldName, "Поле '{$fieldName}' обязательно для заполнения");
            return false;
        }
        return true;
    }
    
    /**
     * Валидация типа данных
     */
    protected function validateType($value, $type, $fieldName) {
        $valid = false;
        switch ($type) {
            case 'int':
                $valid = is_numeric($value) && (int)$value == $value;
                break;
            case 'float':
                $valid = is_numeric($value);
                break;
            case 'string':
                $valid = is_string($value);
                break;
            case 'array':
                $valid = is_array($value);
                break;
            case 'date':
                $valid = $this->validateDate($value);
                break;
        }
        
        if (!$valid) {
            $this->addError($fieldName, "Поле '{$fieldName}' должно быть типа {$type}");
        }
        
        return $valid;
    }
    
    /**
     * Валидация даты
     */
    protected function validateDate($value, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $value);
        return $d && $d->format($format) === $value;
    }
    
    /**
     * Валидация диапазона
     */
    protected function validateRange($value, $min, $max, $fieldName) {
        if ($value < $min || $value > $max) {
            $this->addError($fieldName, "Поле '{$fieldName}' должно быть между {$min} и {$max}");
            return false;
        }
        return true;
    }
    
    /**
     * Валидация длины строки
     */
    protected function validateLength($value, $min, $max, $fieldName) {
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $this->addError($fieldName, "Поле '{$fieldName}' должно быть от {$min} до {$max} символов");
            return false;
        }
        return true;
    }
}
