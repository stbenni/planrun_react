<?php

require_once __DIR__ . '/../config/env_loader.php';

class TelegramLoginService {
    private $db;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $scopes;
    private string $stateSecret;
    private string $botToken;
    private string $botUsername;
    private ?string $proxy;
    private static bool $externalBotConfigLoaded = false;

    public function __construct($db) {
        $this->db = $db;
        $this->clientId = trim((string) env('TELEGRAM_LOGIN_CLIENT_ID', ''));
        $this->clientSecret = trim((string) env('TELEGRAM_LOGIN_CLIENT_SECRET', ''));
        $this->redirectUri = trim((string) env('TELEGRAM_LOGIN_REDIRECT_URI', ''));
        $scopes = trim((string) env('TELEGRAM_LOGIN_SCOPES', 'openid profile telegram:bot_access'));
        $this->scopes = str_contains(' ' . $scopes . ' ', ' openid ')
            ? $scopes
            : trim('openid ' . $scopes);
        $this->stateSecret = (string) env('JWT_SECRET_KEY', 'telegram-login-state-fallback-' . md5(__DIR__));
        $this->botToken = $this->resolveBotToken();
        $this->botUsername = trim((string) env('TELEGRAM_BOT_USERNAME', 'running_cal_bot'));
        $proxy = trim((string) env('TELEGRAM_PROXY', ''));
        $this->proxy = $proxy !== '' ? $proxy : null;
    }

    public function isBotConfigured(): bool {
        return $this->botToken !== '';
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
            if (!is_file($path)) {
                continue;
            }

            require_once $path;
            return;
        }
    }

    private function getCurlOpts(array $extra = []): array {
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
        ];
        if ($this->proxy) {
            $opts[CURLOPT_PROXY] = $this->proxy;
            $opts[CURLOPT_HTTPPROXYTUNNEL] = true;
            if (strpos($this->proxy, 'socks5') === 0) {
                $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
            }
        }
        return $opts + $extra;
    }

    public function isConfigured(): bool {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    public function createAuthorizationUrl(int $userId, bool $fromApp = false): string {
        if (!$this->isConfigured()) {
            throw new Exception('Telegram Login не настроен. Заполните TELEGRAM_LOGIN_CLIENT_ID, TELEGRAM_LOGIN_CLIENT_SECRET и TELEGRAM_LOGIN_REDIRECT_URI.');
        }

        $this->cleanupStaleFlows();

        $flowId = bin2hex(random_bytes(16));
        $codeVerifier = $this->base64UrlEncode(random_bytes(64));
        $createdAt = time();

        $this->saveFlow([
            'id' => $flowId,
            'uid' => $userId,
            'app' => $fromApp ? 1 : 0,
            'created_at' => $createdAt,
            'code_verifier' => $codeVerifier,
        ]);

        $payload = base64_encode(json_encode([
            'flow' => $flowId,
            'uid' => $userId,
            'ts' => $createdAt,
            'app' => $fromApp ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE));
        $hmac = hash_hmac('sha256', $payload, $this->stateSecret);
        $state = $payload . '.' . $hmac;

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes ?: 'openid profile telegram:bot_access',
            'state' => $state,
            'code_challenge' => $this->buildCodeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ];

        return 'https://oauth.telegram.org/auth?' . http_build_query($params);
    }

    public function getFlowFromState(string $state): array {
        if ($state === '') {
            throw new Exception('Пустой state Telegram Login');
        }

        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            throw new Exception('Некорректный state Telegram Login');
        }

        [$payload, $hmac] = $parts;
        $expectedHmac = hash_hmac('sha256', $payload, $this->stateSecret);
        if (!hash_equals($expectedHmac, $hmac)) {
            throw new Exception('Подпись state Telegram Login не прошла проверку');
        }

        $data = json_decode(base64_decode($payload, true) ?: '', true);
        if (!is_array($data) || empty($data['flow']) || empty($data['uid']) || empty($data['ts'])) {
            throw new Exception('State Telegram Login поврежден');
        }

        if (time() - (int) $data['ts'] > 600) {
            throw new Exception('Сессия Telegram Login истекла. Попробуйте снова.');
        }

        $flow = $this->loadFlow((string) $data['flow']);
        if (!$flow) {
            throw new Exception('Сессия Telegram Login не найдена. Попробуйте снова.');
        }

        if ((int) ($flow['uid'] ?? 0) !== (int) $data['uid']) {
            throw new Exception('Telegram Login не прошел проверку пользователя');
        }

        return [
            'flow_id' => (string) $data['flow'],
            'uid' => (int) $data['uid'],
            'app' => !empty($data['app']) || !empty($flow['app']),
            'code_verifier' => (string) ($flow['code_verifier'] ?? ''),
        ];
    }

    public function deleteFlow(string $flowId): void {
        $path = $this->getFlowPath($flowId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function exchangeCodeForTokens(string $code, string $codeVerifier): array {
        if ($code === '' || $codeVerifier === '') {
            throw new Exception('Недостаточно данных для завершения Telegram Login');
        }

        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $codeVerifier,
        ]);

        $ch = curl_init('https://oauth.telegram.org/token');
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]));

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        if ($httpCode !== 200 || !is_array($data) || empty($data['id_token'])) {
            $message = $data['error_description'] ?? $data['error'] ?? $curlError ?: 'Ошибка обмена кода Telegram Login';
            throw new Exception($message);
        }

        return $data;
    }

    public function validateIdToken(string $idToken): array {
        if ($idToken === '') {
            throw new Exception('Пустой id_token Telegram Login');
        }

        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
        }

        if (!class_exists(\Firebase\JWT\JWT::class) || !class_exists(\Firebase\JWT\JWK::class)) {
            throw new Exception('JWT-библиотека для Telegram Login не найдена');
        }

        $jwks = $this->getJwks();
        $keys = \Firebase\JWT\JWK::parseKeySet($jwks);
        $decoded = (array) \Firebase\JWT\JWT::decode($idToken, $keys);

        $issuer = (string) ($decoded['iss'] ?? '');
        if ($issuer !== 'https://oauth.telegram.org') {
            throw new Exception('Некорректный issuer Telegram Login');
        }

        $audience = $decoded['aud'] ?? null;
        $expectedAudience = (string) $this->clientId;
        $audienceMatches = is_array($audience)
            ? in_array($expectedAudience, array_map('strval', $audience), true)
            : (string) $audience === $expectedAudience;

        if (!$audienceMatches) {
            throw new Exception('Некорректный audience Telegram Login');
        }

        if (!isset($decoded['id']) || !preg_match('/^\d+$/', (string) $decoded['id'])) {
            throw new Exception('Telegram Login не вернул числовой user id');
        }

        return $decoded;
    }

    public function linkTelegramAccount(int $userId, array $claims): array {
        $telegramId = (int) $claims['id'];

        $checkStmt = $this->db->prepare('SELECT id FROM users WHERE telegram_id = ? AND id != ?');
        $checkStmt->bind_param('ii', $telegramId, $userId);
        $checkStmt->execute();
        $alreadyLinked = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();

        if ($alreadyLinked) {
            throw new Exception('Этот Telegram уже привязан к другому аккаунту.');
        }

        $stmt = $this->db->prepare("
            UPDATE users
            SET telegram_id = ?,
                telegram_link_code = NULL,
                telegram_link_code_expires = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('ii', $telegramId, $userId);
        $stmt->execute();
        $stmt->close();

        require_once __DIR__ . '/../user_functions.php';
        clearUserCache($userId);

        return [
            'telegram_id' => $telegramId,
        ];
    }

    public function sendWelcomeMessageIfConfigured(int $telegramId, ?string $displayName = null): void {
        if ($telegramId <= 0 || $this->botToken === '') {
            return;
        }

        $name = trim((string) $displayName);
        $safeName = htmlspecialchars($name !== '' ? $name : 'друг', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $botUrl = 'https://t.me/' . rawurlencode($this->botUsername !== '' ? $this->botUsername : 'running_cal_bot');

        $message = "✅ <b>Telegram подключён к аккаунту PlanRun</b>\n\n";
        $message .= "Привет, <b>{$safeName}</b>! Теперь бот может писать вам первым.\n\n";
        $message .= "Отправьте сюда GPX/TCX файл или откройте бота: {$botUrl}";

        $payload = http_build_query([
            'chat_id' => $telegramId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        $ch = curl_init('https://api.telegram.org/bot' . $this->botToken . '/sendMessage');
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]));
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        if ($httpCode >= 400 || !is_array($data) || empty($data['ok'])) {
            $this->logWarning('Не удалось отправить welcome-сообщение после Telegram Login', [
                'telegram_id' => $telegramId,
                'http_code' => $httpCode,
                'curl_error' => $curlError ?: null,
                'response' => is_string($response) ? substr($response, 0, 500) : null,
            ]);
        }
    }

    public function sendMessageIfConfigured(int $telegramId, string $title, string $body, array $options = []): bool {
        if ($telegramId <= 0 || $this->botToken === '') {
            return false;
        }

        $safeTitle = htmlspecialchars(trim($title), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBody = htmlspecialchars(trim($body), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rawLink = trim((string) ($options['link'] ?? ''));
        $appUrl = rtrim((string) env('APP_URL', ''), '/');
        $link = '';
        if ($rawLink !== '') {
            $link = preg_match('#^https?://#i', $rawLink)
                ? $rawLink
                : ($appUrl !== '' ? $appUrl . $rawLink : $rawLink);
        }

        $message = '<b>' . $safeTitle . '</b>';
        if ($safeBody !== '') {
            $message .= "\n\n" . $safeBody;
        }
        if ($link !== '') {
            $message .= "\n\n" . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $payload = http_build_query([
            'chat_id' => $telegramId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        $ch = curl_init('https://api.telegram.org/bot' . $this->botToken . '/sendMessage');
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]));
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        if ($httpCode >= 400 || !is_array($data) || empty($data['ok'])) {
            $this->logWarning('Не удалось отправить Telegram-уведомление', [
                'telegram_id' => $telegramId,
                'http_code' => $httpCode,
                'curl_error' => $curlError ?: null,
                'response' => is_string($response) ? substr($response, 0, 500) : null,
            ]);
            return false;
        }

        return true;
    }

    private function getJwks(): array {
        $cachePath = $this->getCacheDir() . '/telegram_login_jwks.json';
        if (is_file($cachePath) && (time() - (int) filemtime($cachePath) < 3600)) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached) && !empty($cached['keys'])) {
                return $cached;
            }
        }

        $ch = curl_init('https://oauth.telegram.org/.well-known/jwks.json');
        curl_setopt_array($ch, $this->getCurlOpts([
            CURLOPT_TIMEOUT => 20,
        ]));
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        if ($httpCode !== 200 || !is_array($data) || empty($data['keys'])) {
            throw new Exception('Не удалось получить JWKS Telegram Login' . ($curlError ? ': ' . $curlError : ''));
        }

        @file_put_contents($cachePath, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $data;
    }

    private function saveFlow(array $data): void {
        $path = $this->getFlowPath((string) $data['id']);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        if (@file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('Не удалось создать сессию Telegram Login');
        }
    }

    private function loadFlow(string $flowId): ?array {
        $path = $this->getFlowPath($flowId);
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function cleanupStaleFlows(): void {
        $dir = $this->getFlowDir();
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/flow_*.json') ?: [] as $file) {
            if (time() - (int) filemtime($file) > 3600) {
                @unlink($file);
            }
        }
    }

    private function getFlowDir(): string {
        return $this->getCacheDir() . '/telegram_login_flows';
    }

    private function getFlowPath(string $flowId): string {
        return $this->getFlowDir() . '/flow_' . preg_replace('/[^a-f0-9]/i', '', $flowId) . '.json';
    }

    private function getCacheDir(): string {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/planrun_oidc';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    private function buildCodeChallenge(string $codeVerifier): string {
        return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function logWarning(string $message, array $context = []): void {
        $loggerPath = __DIR__ . '/../config/Logger.php';
        if (is_file($loggerPath)) {
            require_once $loggerPath;
            if (class_exists('Logger')) {
                Logger::warning($message, $context);
            }
        }
    }
}
