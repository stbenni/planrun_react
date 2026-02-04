<?php
/**
 * Исключение для ошибок валидации
 */

require_once __DIR__ . '/AppException.php';

class ValidationException extends AppException {
    protected $validationErrors = [];
    
    public function __construct($message = "", $validationErrors = [], $code = 400, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->validationErrors = $validationErrors;
        $this->statusCode = 400;
    }
    
    public function getValidationErrors() {
        return $this->validationErrors;
    }
    
    public function toArray() {
        $result = parent::toArray();
        $result['validation_errors'] = $this->validationErrors;
        return $result;
    }
}
