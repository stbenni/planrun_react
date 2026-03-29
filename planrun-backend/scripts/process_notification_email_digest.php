#!/usr/bin/env php
<?php
/**
 * Ежедневный email-дайджест.
 * Запуск по cron каждую минуту:
 * * * * * php /path/to/planrun-backend/scripts/process_notification_email_digest.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/NotificationSettingsService.php';
require_once $baseDir . '/services/EmailNotificationService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$settings = new NotificationSettingsService($db);
$emailService = new EmailNotificationService($db);
$userIds = $settings->reserveDueEmailDigestUsers(25);

$processedUsers = 0;
$sentDigests = 0;
$failedDigests = 0;
$skippedDigests = 0;
$deferredDigests = 0;

foreach ($userIds as $userId) {
    $items = $settings->reserveDueEmailDigestItemsForUser((int) $userId);
    if (empty($items)) {
        continue;
    }

    $processedUsers++;
    $itemIds = array_map(static fn(array $item) => (int) ($item['id'] ?? 0), $items);

    try {
        $emailSettings = $settings->getSettings((int) $userId);
        $emailChannel = $emailSettings['channels']['email'] ?? [];
        if (empty($emailChannel['enabled']) || empty($emailChannel['available'])) {
            $settings->markEmailDigestItemsCompleted($itemIds, 'skipped');
            $settings->logDelivery((int) $userId, 'system.email_digest', 'email', 'skipped', [
                'title' => 'Ежедневный дайджест PlanRun',
                'body' => 'Email-канал отключён или недоступен.',
                'error_text' => 'email_channel_unavailable',
            ]);
            $skippedDigests++;
            continue;
        }

        if ($settings->isInQuietHours((int) $userId)) {
            $deliverAfter = $settings->getQuietHoursResumeAt((int) $userId) ?: gmdate('Y-m-d H:i:s');
            $settings->rescheduleEmailDigestItems($itemIds, $deliverAfter, 'quiet_hours');
            $deferredDigests++;
            continue;
        }

        $sent = $emailService->sendDailyDigestToUser((int) $userId, $items);
        if ($sent) {
            $settings->markEmailDigestItemsCompleted($itemIds, 'sent');
            $settings->logDelivery((int) $userId, 'system.email_digest', 'email', 'sent', [
                'title' => 'Ежедневный дайджест PlanRun',
                'body' => 'Событий в письме: ' . count($items),
            ]);
            $sentDigests++;
            continue;
        }

        $settings->markEmailDigestItemsCompleted($itemIds, 'failed', 'digest_send_failed');
        $settings->logDelivery((int) $userId, 'system.email_digest', 'email', 'failed', [
            'title' => 'Ежедневный дайджест PlanRun',
            'body' => 'Событий в письме: ' . count($items),
            'error_text' => 'digest_send_failed',
        ]);
        $failedDigests++;
    } catch (Throwable $e) {
        $settings->markEmailDigestItemsCompleted($itemIds, 'failed', $e->getMessage());
        $settings->logDelivery((int) $userId, 'system.email_digest', 'email', 'failed', [
            'title' => 'Ежедневный дайджест PlanRun',
            'body' => 'Событий в письме: ' . count($items),
            'error_text' => $e->getMessage(),
        ]);
        $failedDigests++;
        error_log('[Notifications] Email digest failed: ' . $e->getMessage());
    }
}

if (php_sapi_name() === 'cli' && $processedUsers > 0) {
    echo sprintf(
        "Processed %d digest users (sent=%d deferred=%d skipped=%d failed=%d)\n",
        $processedUsers,
        $sentDigests,
        $deferredDigests,
        $skippedDigests,
        $failedDigests
    );
}
