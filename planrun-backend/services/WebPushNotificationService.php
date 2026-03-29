<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../config/env_loader.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class WebPushNotificationService extends BaseService {
    private string $publicKey;
    private string $privateKey;
    private string $subject;

    public function __construct($db) {
        parent::__construct($db);
        $this->publicKey = trim((string) env('WEB_PUSH_VAPID_PUBLIC_KEY', ''));
        $this->privateKey = trim((string) env('WEB_PUSH_VAPID_PRIVATE_KEY', ''));
        $this->subject = trim((string) env('WEB_PUSH_VAPID_SUBJECT', ''));

        if ($this->subject === '') {
            $appUrl = rtrim((string) env('APP_URL', ''), '/');
            $this->subject = $appUrl !== '' ? $appUrl : 'mailto:info@planrun.ru';
        }
    }

    public static function createVapidKeys(): array {
        return VAPID::createVapidKeys();
    }

    public function isConfigured(): bool {
        return $this->publicKey !== '' && $this->privateKey !== '';
    }

    public function getPublicKey(): string {
        return $this->isConfigured() ? $this->publicKey : '';
    }

    public function registerSubscription(int $userId, array $subscription, ?string $userAgent = null): bool {
        if ($userId <= 0) {
            return false;
        }

        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? $subscription['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? $subscription['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }

        $safeUserAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
        $stmt = $this->db->prepare("INSERT INTO web_push_subscriptions (
                user_id, endpoint, p256dh, auth, user_agent, last_seen_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                user_agent = VALUES(user_agent),
                last_seen_at = NOW()");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('issss', $userId, $endpoint, $p256dh, $auth, $safeUserAgent);
        $stmt->execute();
        $ok = !$stmt->error;
        $stmt->close();
        return $ok;
    }

    public function unregisterSubscription(int $userId, ?string $endpoint = null): int {
        if ($userId <= 0) {
            return 0;
        }

        if ($endpoint !== null && trim($endpoint) !== '') {
            $endpoint = trim($endpoint);
            $stmt = $this->db->prepare("DELETE FROM web_push_subscriptions WHERE user_id = ? AND endpoint = ?");
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('is', $userId, $endpoint);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return max(0, (int) $affected);
        }

        $stmt = $this->db->prepare("DELETE FROM web_push_subscriptions WHERE user_id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return max(0, (int) $affected);
    }

    public function sendToUser(int $userId, string $title, string $body, array $data = []): bool {
        if (!$this->isConfigured()) {
            return false;
        }

        $subscriptions = $this->getUserSubscriptions($userId);
        if (empty($subscriptions)) {
            return false;
        }

        return $this->sendToSubscriptions($subscriptions, $userId, $title, $body, $data);
    }

    public function sendToEndpoint(int $userId, string $endpoint, string $title, string $body, array $data = []): bool {
        if (!$this->isConfigured()) {
            return false;
        }

        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return false;
        }

        $stmt = $this->db->prepare("SELECT endpoint, p256dh, auth FROM web_push_subscriptions WHERE user_id = ? AND endpoint = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $userId, $endpoint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        return $this->sendToSubscriptions([$row], $userId, $title, $body, $data);
    }

    public function getSubscriptionCount(int $userId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM web_push_subscriptions WHERE user_id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0);
    }

    private function sendToSubscriptions(array $subscriptions, int $userId, string $title, string $body, array $data = []): bool {
        if (empty($subscriptions)) {
            return false;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/favicon-96x96.png',
            'badge' => '/favicon-96x96.png',
            'data' => $data + [
                'link' => $data['link'] ?? '/',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || $payload === '') {
            return false;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ],
        ]);
        $webPush->setReuseVAPIDHeaders(true);

        $sent = false;
        foreach ($subscriptions as $subscriptionRow) {
            try {
                $subscription = Subscription::create([
                    'endpoint' => $subscriptionRow['endpoint'],
                    'publicKey' => $subscriptionRow['p256dh'],
                    'authToken' => $subscriptionRow['auth'],
                ]);
                $report = $webPush->sendOneNotification($subscription, $payload);
                if ($report->isSuccess()) {
                    $sent = true;
                    continue;
                }

                if ($report->isSubscriptionExpired()) {
                    $this->deleteByEndpoint((string) $subscriptionRow['endpoint']);
                }

                Logger::warning('Web push delivery failed', [
                    'user_id' => $userId,
                    'endpoint' => (string) $subscriptionRow['endpoint'],
                    'reason' => $report->getReason(),
                ]);
            } catch (\Throwable $e) {
                Logger::warning('Web push send exception', [
                    'user_id' => $userId,
                    'endpoint' => (string) ($subscriptionRow['endpoint'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    private function getUserSubscriptions(int $userId): array {
        $stmt = $this->db->prepare("SELECT endpoint, p256dh, auth FROM web_push_subscriptions WHERE user_id = ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function deleteByEndpoint(string $endpoint): void {
        if ($endpoint === '') {
            return;
        }
        $stmt = $this->db->prepare("DELETE FROM web_push_subscriptions WHERE endpoint = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $endpoint);
        $stmt->execute();
        $stmt->close();
    }
}
