<?php

require_once __DIR__ . '/EmailVerificationService.php';
require_once __DIR__ . '/RegistrationService.php';
require_once __DIR__ . '/../config/RateLimiter.php';

class RegisterApiService {
    private $db;
    private $registrationService;
    private $emailVerificationService;

    public function __construct($db, $registrationService = null, $emailVerificationService = null) {
        $this->db = $db;
        $this->registrationService = $registrationService ?: new RegistrationService($db);
        $this->emailVerificationService = $emailVerificationService ?: new EmailVerificationService($db);
    }

    public function validateField($field, $value) {
        return $this->registrationService->validateField((string) $field, (string) $value);
    }

    public function sendVerificationCode($email, $ipAddress) {
        $emailForCode = trim((string) $email);
        if ($emailForCode === '') {
            throw new InvalidArgumentException('Введите email', 400);
        }

        if (!filter_var($emailForCode, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некорректный формат email', 400);
        }

        $emailKey = hash('sha256', $this->normalizeRateLimitValue($emailForCode));
        RateLimiter::check("register_code_ip_{$ipAddress}", 10, 900);
        RateLimiter::check("register_code_email_{$emailKey}", 3, 900);

        return $this->emailVerificationService->sendVerificationCode($emailForCode);
    }

    private function normalizeRateLimitValue($value) {
        $stringValue = trim((string) $value);
        return function_exists('mb_strtolower')
            ? mb_strtolower($stringValue, 'UTF-8')
            : strtolower($stringValue);
    }
}
