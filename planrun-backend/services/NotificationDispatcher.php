<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/NotificationSettingsService.php';
require_once __DIR__ . '/PushNotificationService.php';
require_once __DIR__ . '/EmailNotificationService.php';
require_once __DIR__ . '/TelegramLoginService.php';
require_once __DIR__ . '/WebPushNotificationService.php';
require_once __DIR__ . '/NotificationTemplateService.php';

class NotificationDispatcher extends BaseService {
    private NotificationSettingsService $settingsService;
    private NotificationTemplateService $templateService;

    public function __construct($db) {
        parent::__construct($db);
        $this->settingsService = new NotificationSettingsService($db);
        $this->templateService = new NotificationTemplateService($db);
    }

    public function dispatchToUser(int $userId, string $eventKey, string $title, string $body, array $options = []): array {
        $prepared = $this->templateService->prepare($eventKey, $title, $body, $options);
        $title = $prepared['title'];
        $body = $prepared['body'];
        $options = is_array($prepared['options'] ?? null) ? $prepared['options'] : $options;

        $results = [
            'mobile_push' => false,
            'web_push' => false,
            'telegram' => false,
            'email' => false,
        ];

        $targetChannels = $options['target_channels'] ?? NotificationSettingsService::CHANNEL_KEYS;
        if (!is_array($targetChannels) || empty($targetChannels)) {
            $targetChannels = NotificationSettingsService::CHANNEL_KEYS;
        }

        foreach ($targetChannels as $channel) {
            if (!in_array($channel, NotificationSettingsService::CHANNEL_KEYS, true)) {
                continue;
            }
            $results[$channel] = $this->dispatchChannel($userId, $channel, $eventKey, $title, $body, $options);
        }

        return $results;
    }

    public function processQueuedDelivery(array $queuedDelivery): array {
        $userId = (int) ($queuedDelivery['user_id'] ?? 0);
        $eventKey = trim((string) ($queuedDelivery['event_key'] ?? ''));
        $channel = trim((string) ($queuedDelivery['channel'] ?? ''));
        $title = (string) ($queuedDelivery['title'] ?? '');
        $body = (string) ($queuedDelivery['body'] ?? '');

        if ($userId <= 0 || $eventKey === '' || !in_array($channel, NotificationSettingsService::CHANNEL_KEYS, true)) {
            return [
                'status' => 'failed',
                'error_text' => 'invalid_queue_item',
            ];
        }

        $guard = $this->settingsService->canDeliver($userId, $channel, $eventKey, false);
        if (empty($guard['allowed'])) {
            $reason = (string) ($guard['reason'] ?? 'guard_rejected');
            if ($reason === 'quiet_hours') {
                return [
                    'status' => 'deferred',
                    'deliver_after' => $this->settingsService->getQuietHoursResumeAt($userId) ?: gmdate('Y-m-d H:i:s'),
                    'error_text' => 'quiet_hours',
                ];
            }

            $this->settingsService->logDelivery($userId, $eventKey, $channel, 'skipped', [
                'title' => $title,
                'body' => $body,
                'entity_type' => (string) ($queuedDelivery['entity_type'] ?? ''),
                'entity_id' => (string) ($queuedDelivery['entity_id'] ?? ''),
                'error_text' => $reason,
            ]);

            return [
                'status' => 'skipped',
                'error_text' => $reason,
            ];
        }

        $result = $this->sendChannelNow($userId, $channel, $eventKey, $title, $body, [
            'link' => (string) ($queuedDelivery['link'] ?? ''),
            'push_data' => is_array($queuedDelivery['push_data'] ?? null) ? $queuedDelivery['push_data'] : [],
            'email_action_label' => (string) ($queuedDelivery['email_action_label'] ?? ''),
            'entity_type' => (string) ($queuedDelivery['entity_type'] ?? ''),
            'entity_id' => (string) ($queuedDelivery['entity_id'] ?? ''),
            'ignore_quiet_hours' => true,
        ]);

        return [
            'status' => $result ? 'sent' : 'failed',
            'error_text' => $result ? null : 'send_failed',
        ];
    }

    private function getUserTelegramId(int $userId): int {
        $stmt = $this->db->prepare("SELECT telegram_id FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['telegram_id'] ?? 0);
    }

    private function dispatchChannel(int $userId, string $channel, string $eventKey, string $title, string $body, array $options) {
        $ignoreQuietHours = !empty($options['ignore_quiet_hours']);
        $skipQueue = !empty($options['skip_queue']);

        if ($channel === 'email' && $this->shouldUseEmailDigest($userId, $eventKey, $options)) {
            $emailGuard = $this->settingsService->canDeliver($userId, 'email', $eventKey, true);
            if (!empty($emailGuard['allowed'])) {
                $this->settingsService->queueEmailDigestItem($userId, $eventKey, $title, $body, [
                    'link' => (string) ($options['link'] ?? ''),
                    'entity_type' => (string) ($options['entity_type'] ?? ''),
                    'entity_id' => (string) ($options['entity_id'] ?? ''),
                ]);
                $this->settingsService->logDelivery($userId, $eventKey, 'email', 'digest', [
                    'title' => $title,
                    'body' => $body,
                    'entity_type' => (string) ($options['entity_type'] ?? ''),
                    'entity_id' => (string) ($options['entity_id'] ?? ''),
                    'error_text' => 'daily_digest',
                ]);
                return 'digest';
            }
            return false;
        }

        $guard = $this->settingsService->canDeliver($userId, $channel, $eventKey, $ignoreQuietHours);

        if (!empty($guard['allowed'])) {
            return $this->sendChannelNow($userId, $channel, $eventKey, $title, $body, $options);
        }

        $reason = (string) ($guard['reason'] ?? 'guard_rejected');
        if ($reason === 'quiet_hours' && !$ignoreQuietHours) {
            if ($skipQueue) {
                return 'deferred';
            }

            $deliverAfter = $this->settingsService->getQuietHoursResumeAt($userId);
            if ($deliverAfter !== null) {
                $this->settingsService->queueDelivery($userId, $eventKey, $channel, $title, $body, [
                    'link' => (string) ($options['link'] ?? ''),
                    'push_data' => is_array($options['push_data'] ?? null) ? $options['push_data'] : [],
                    'email_action_label' => (string) ($options['email_action_label'] ?? ''),
                    'entity_type' => (string) ($options['entity_type'] ?? ''),
                    'entity_id' => (string) ($options['entity_id'] ?? ''),
                ], $deliverAfter);
                $this->settingsService->logDelivery($userId, $eventKey, $channel, 'deferred', [
                    'title' => $title,
                    'body' => $body,
                    'entity_type' => (string) ($options['entity_type'] ?? ''),
                    'entity_id' => (string) ($options['entity_id'] ?? ''),
                    'error_text' => 'quiet_hours',
                ]);
                return 'deferred';
            }
        }

        return false;
    }

    private function shouldUseEmailDigest(int $userId, string $eventKey, array $options): bool {
        if (($options['force_instant_email'] ?? false) === true) {
            return false;
        }

        if (!empty($options['ignore_quiet_hours'])) {
            return false;
        }

        if (strpos($eventKey, 'system.') === 0) {
            return false;
        }

        return $this->settingsService->getEmailDigestMode($userId) === 'daily';
    }

    private function sendChannelNow(int $userId, string $channel, string $eventKey, string $title, string $body, array $options): bool {
        $link = trim((string) ($options['link'] ?? ''));
        $pushData = is_array($options['push_data'] ?? null) ? $options['push_data'] : [];
        $entityType = trim((string) ($options['entity_type'] ?? ''));
        $entityId = trim((string) ($options['entity_id'] ?? ''));
        $ignoreQuietHours = !empty($options['ignore_quiet_hours']);
        $deliveryContext = [
            'title' => $title,
            'body' => $body,
            'entity_type' => $entityType !== '' ? $entityType : null,
            'entity_id' => $entityId !== '' ? $entityId : null,
        ];

        if ($channel === 'mobile_push') {
            $push = new PushNotificationService($this->db);
            return $push->sendToUser($userId, $title, $body, array_merge($pushData, [
                'event_key' => $eventKey,
                'link' => $link,
                'ignore_quiet_hours' => $ignoreQuietHours ? 1 : 0,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]));
        }

        if ($channel === 'web_push') {
            $webPush = new WebPushNotificationService($this->db);
            $webPushOk = $webPush->sendToUser($userId, $title, $body, $pushData + [
                'link' => $link,
                'event_key' => $eventKey,
            ]);
            $this->settingsService->logDelivery($userId, $eventKey, 'web_push', $webPushOk ? 'sent' : 'failed', $deliveryContext + [
                'error_text' => $webPushOk ? null : 'web_push_send_failed',
            ]);
            return $webPushOk;
        }

        if ($channel === 'telegram') {
            $telegram = new TelegramLoginService($this->db);
            $telegramOk = $telegram->sendMessageIfConfigured($this->getUserTelegramId($userId), $title, $body, ['link' => $link]);
            $this->settingsService->logDelivery($userId, $eventKey, 'telegram', $telegramOk ? 'sent' : 'failed', $deliveryContext + [
                'error_text' => $telegramOk ? null : 'telegram_send_failed',
            ]);
            return $telegramOk;
        }

        if ($channel === 'email') {
            $email = new EmailNotificationService($this->db);
            $emailOk = $email->sendToUser($userId, $title, $body, [
                'link' => $link,
                'action_label' => trim((string) ($options['email_action_label'] ?? 'Открыть в PlanRun')),
            ]);
            $this->settingsService->logDelivery($userId, $eventKey, 'email', $emailOk ? 'sent' : 'failed', $deliveryContext + [
                'error_text' => $emailOk ? null : 'email_send_failed',
            ]);
            return $emailOk;
        }

        return false;
    }
}
