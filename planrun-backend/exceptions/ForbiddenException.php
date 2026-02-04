<?php
/**
 * Исключение для ошибок доступа
 */

require_once __DIR__ . '/AppException.php';

class ForbiddenException extends AppException {
    public function __construct($message = "Доступ запрещен", $code = 403, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        $this->statusCode = 403;
    }
}
