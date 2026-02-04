<?php
/**
 * Базовое исключение приложения
 */

class AppException extends Exception {
    protected $context = [];
    protected $statusCode = 500;
    
    public function __construct($message = "", $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
        if (is_numeric($code) && $code >= 400 && $code < 600) {
            $this->statusCode = (int)$code;
        }
    }
    
    public function getContext() {
        return $this->context;
    }
    
    public function getStatusCode() {
        return $this->statusCode;
    }
    
    public function toArray() {
        return [
            'error' => $this->getMessage(),
            'code' => $this->getCode(),
            'status_code' => $this->getStatusCode(),
            'context' => $this->context
        ];
    }
}
