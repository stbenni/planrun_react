<?php
/**
 * Telegram Mini App: валидация initData (HMAC по токену бота) и
 * разрешение/создание пользователя PlanRun по telegram_id.
 *
 * Спецификация валидации: https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/RegistrationService.php';

class TelegramMiniAppService {
    private mysqli $db;
    private string $botToken;
    private int $maxAgeSeconds;
    private static bool $externalBotConfigLoaded = false;

    public function __construct(mysqli $db) {
        $this->db = $db;
        $this->botToken = $this->resolveBotToken();
        $this->maxAgeSeconds = (int) env('TELEGRAM_MINIAPP_MAX_AGE_SECONDS', 86400);
        if ($this->maxAgeSeconds <= 0) {
            $this->maxAgeSeconds = 86400;
        }
    }

    public function isConfigured(): bool {
        return $this->botToken !== '';
    }

    /**
     * Проверяет подпись initData и возвращает массив данных Telegram-пользователя.
     * Бросает Exception(код 401) при любой ошибке валидации.
     *
     * @return array{id:int,first_name:?string,last_name:?string,username:?string,language_code:?string,photo_url:?string}
     */
    public function validateInitData(string $initData): array {
        if ($this->botToken === '') {
            throw new Exception('Telegram Mini App не настроен на сервере', 503);
        }
        if (trim($initData) === '') {
            throw new Exception('Пустой initData', 401);
        }

        $pairs = $this->parseInitData($initData);

        $hash = (string) ($pairs['hash'] ?? '');
        if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            throw new Exception('Некорректная подпись Telegram', 401);
        }

        // data_check_string строится из всех полей, кроме hash (спецификация Telegram).
        // Поле signature, если оно есть, остаётся в строке — оно входит в HMAC-проверку
        // по токену бота (отдельная Ed25519-проверка для третьих лиц здесь не используется).
        unset($pairs['hash']);
        ksort($pairs);

        $lines = [];
        foreach ($pairs as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $lines);

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($calculatedHash, strtolower($hash))) {
            throw new Exception('Подпись Telegram не прошла проверку', 401);
        }

        $authDate = (int) ($pairs['auth_date'] ?? 0);
        if ($authDate <= 0 || (time() - $authDate) > $this->maxAgeSeconds) {
            throw new Exception('Сессия Telegram истекла. Откройте приложение заново.', 401);
        }

        $userJson = (string) ($pairs['user'] ?? '');
        $user = $userJson !== '' ? json_decode($userJson, true) : null;
        if (!is_array($user) || empty($user['id']) || !preg_match('/^\d+$/', (string) $user['id'])) {
            throw new Exception('Telegram не передал данные пользователя', 401);
        }

        return [
            'id' => (int) $user['id'],
            'first_name' => isset($user['first_name']) ? (string) $user['first_name'] : null,
            'last_name' => isset($user['last_name']) ? (string) $user['last_name'] : null,
            'username' => isset($user['username']) ? (string) $user['username'] : null,
            'language_code' => isset($user['language_code']) ? (string) $user['language_code'] : null,
            'photo_url' => isset($user['photo_url']) ? (string) $user['photo_url'] : null,
        ];
    }

    /**
     * Находит пользователя по telegram_id или создаёт нового (авто-онбординг).
     *
     * @param array $tgUser Результат validateInitData()
     * @return array{user_id:int,username:string,is_new:bool,onboarding_completed:int}
     */
    public function resolveOrCreateUser(array $tgUser, ?string $timezone = null): array {
        $telegramId = (int) ($tgUser['id'] ?? 0);
        if ($telegramId <= 0) {
            throw new Exception('Некорректный Telegram-пользователь', 401);
        }

        $stmt = $this->db->prepare('SELECT id, username, COALESCE(onboarding_completed, 0) AS onboarding_completed FROM users WHERE telegram_id = ? LIMIT 1');
        $stmt->bind_param('i', $telegramId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return [
                'user_id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'is_new' => false,
                'onboarding_completed' => (int) $row['onboarding_completed'],
            ];
        }

        $registration = new RegistrationService($this->db);
        $created = $registration->registerFromTelegram($tgUser, $timezone);

        return [
            'user_id' => (int) $created['user_id'],
            'username' => (string) $created['username'],
            'is_new' => true,
            'onboarding_completed' => 0,
        ];
    }

    private function parseInitData(string $initData): array {
        $pairs = [];
        foreach (explode('&', $initData) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $eq = strpos($chunk, '=');
            if ($eq === false) {
                $pairs[urldecode($chunk)] = '';
                continue;
            }
            $key = urldecode(substr($chunk, 0, $eq));
            $value = urldecode(substr($chunk, $eq + 1));
            $pairs[$key] = $value;
        }
        return $pairs;
    }

    private function resolveBotToken(): string {
        $token = trim((string) env('TELEGRAM_BOT_TOKEN', ''));
        if ($token !== '') {
            return $token;
        }

        self::loadExternalBotConfig();

        if (defined('TELEGRAM_BOT_TOKEN')) {
            $externalToken = trim((string) constant('TELEGRAM_BOT_TOKEN'));
            if ($externalToken !== '') {
                return $externalToken;
            }
        }

        return '';
    }

    private static function loadExternalBotConfig(): void {
        if (self::$externalBotConfigLoaded) {
            return;
        }
        self::$externalBotConfigLoaded = true;

        $configuredPath = trim((string) env('TELEGRAM_BOT_CONFIG_PATH', ''));
        $candidatePaths = array_filter([
            $configuredPath,
            dirname(__DIR__, 3) . '/planrun-bot/bot/config.php',
        ]);

        foreach ($candidatePaths as $path) {
            if (is_file($path)) {
                require_once $path;
                return;
            }
        }
    }
}
