<?php
/**
 * Сервис кодов подтверждения email при регистрации.
 * Хранение кодов, проверка попыток и отправка писем.
 */

require_once __DIR__ . '/BaseService.php';

class EmailVerificationService extends BaseService {
    private const TABLE = 'email_verification_codes';
    private const CODE_LENGTH = 6;
    private const EXPIRES_MINUTES = 10;
    private const MAX_ATTEMPTS = 3;

    public function __construct($db) {
        parent::__construct($db);
        if (!function_exists('env')) {
            require_once __DIR__ . '/../config/env_loader.php';
        }
    }

    public function sendVerificationCode(string $email): array {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некорректный формат email', 400);
        }

        $this->assertStorageReady();

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + self::EXPIRES_MINUTES * 60);

        $this->deleteCode($email);

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (email, code, attempts_left, expires_at) VALUES (?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить сохранение кода подтверждения', 500);
        }

        $attempts = self::MAX_ATTEMPTS;
        $stmt->bind_param('ssis', $email, $code, $attempts, $expiresAt);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Ошибка сохранения кода подтверждения', 500);
        }
        $stmt->close();

        $this->deliverVerificationCode($email, $code, self::EXPIRES_MINUTES);

        return [
            'success' => true,
            'message' => 'Код отправлен на указанный email',
        ];
    }

    public function verifyCode(string $email, string $code): array {
        $email = trim($email);
        $code = preg_replace('/\D/', '', $code);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Некорректный формат email',
                'code_required' => true,
            ];
        }

        if (strlen($code) !== self::CODE_LENGTH) {
            return [
                'success' => false,
                'error' => 'Введите 6-значный код из письма',
                'code_required' => true,
            ];
        }

        $this->assertStorageReady();

        $stmt = $this->db->prepare(
            'SELECT code, attempts_left, expires_at FROM ' . self::TABLE . ' WHERE email = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось проверить код подтверждения', 500);
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return [
                'success' => false,
                'error' => 'Сначала запросите код подтверждения на email',
                'code_required' => true,
            ];
        }

        if ((int) $row['attempts_left'] < 1) {
            $this->deleteCode($email);
            return [
                'success' => false,
                'error' => 'Исчерпаны попытки ввода кода. Запросите новый код.',
                'attempts_left' => 0,
                'code_required' => true,
            ];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->deleteCode($email);
            return [
                'success' => false,
                'error' => 'Время действия кода истекло. Запросите новый код.',
                'code_required' => true,
            ];
        }

        if ((string) $row['code'] !== $code) {
            $newAttempts = max(0, ((int) $row['attempts_left']) - 1);
            $upd = $this->db->prepare(
                'UPDATE ' . self::TABLE . ' SET attempts_left = ? WHERE email = ?'
            );
            if ($upd) {
                $upd->bind_param('is', $newAttempts, $email);
                $upd->execute();
                $upd->close();
            }

            return [
                'success' => false,
                'error' => 'Неверный код. Осталось попыток: ' . $newAttempts,
                'attempts_left' => $newAttempts,
                'code_required' => true,
            ];
        }

        $this->deleteCode($email);

        return ['success' => true];
    }

    private function assertStorageReady(): void {
        $result = @$this->db->query("SHOW TABLES LIKE '" . self::TABLE . "'");
        if (!$result || (int) $result->num_rows === 0) {
            throw new RuntimeException(
                'Сервис подтверждения email временно недоступен. Администратору нужно выполнить php scripts/migrate_all.php',
                503
            );
        }
    }

    private function deleteCode(string $email): void {
        $stmt = $this->db->prepare('DELETE FROM ' . self::TABLE . ' WHERE email = ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось очистить код подтверждения', 500);
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }

    private function deliverVerificationCode(string $email, string $code, int $expiresMinutes): void {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        try {
            if (is_file($autoload)) {
                require_once __DIR__ . '/EmailService.php';
                $emailService = new EmailService();
                $emailService->sendVerificationCode($email, $code, $expiresMinutes);
                return;
            }

            $fromEmail = env('MAIL_FROM_ADDRESS', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $fromName = env('MAIL_FROM_NAME', 'PlanRun');
            $subject = '=?UTF-8?B?' . base64_encode('Код подтверждения PlanRun') . '?=';
            $body = "Ваш код: $code\nДействителен $expiresMinutes минут.\nЕсли письмо попало в папку «Спам», откройте его оттуда — это мы.\n\n— PlanRun";
            $headers = "From: $fromName <$fromEmail>\r\nContent-Type: text/plain; charset=UTF-8\r\n";

            if (!@mail($email, $subject, $body, $headers)) {
                throw new RuntimeException('Не удалось отправить письмо. Попробуйте позже.');
            }
        } catch (Throwable $e) {
            $this->logError('Email verification send failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Не удалось отправить письмо. Попробуйте позже.', 500);
        }
    }
}
