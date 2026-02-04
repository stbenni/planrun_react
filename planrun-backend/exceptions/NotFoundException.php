<?php
/**
 * Исключение для ресурсов, которые не найдены
 */

require_once __DIR__ . '/AppException.php';

class NotFoundException extends AppException {
    public function __construct($message = "Ресурс не найден", $code = 404, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        $this->statusCode = 404;
    }
}
