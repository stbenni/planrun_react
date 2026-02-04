<?php
/**
 * Исключение для ошибок авторизации
 */

require_once __DIR__ . '/AppException.php';

class UnauthorizedException extends AppException {
    public function __construct($message = "Требуется авторизация", $code = 401, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        $this->statusCode = 401;
    }
}
