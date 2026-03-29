#!/usr/bin/env php
<?php
/**
 * Доставка уведомлений, отложенных из-за quiet hours.
 * Запуск по cron каждую минуту:
 * * * * * php /path/to/planrun-backend/scripts/process_notification_delivery_queue.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/NotificationSettingsService.php';
require_once $baseDir . '/services/NotificationDispatcher.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$settings = new NotificationSettingsService($db);
$dispatcher = new NotificationDispatcher($db);
$items = $settings->reserveDueQueuedDeliveries(50);

$processed = 0;
$sent = 0;
$deferred = 0;
$failed = 0;
$skipped = 0;

foreach ($items as $item) {
    $processed++;
    $queueId = (int) ($item['id'] ?? 0);
    if ($queueId <= 0) {
        continue;
    }

    try {
        $result = $dispatcher->processQueuedDelivery($item);
        $status = (string) ($result['status'] ?? 'failed');
        $errorText = isset($result['error_text']) ? (string) $result['error_text'] : null;

        if ($status === 'sent') {
            $settings->markQueuedDeliveryCompleted($queueId, 'sent');
            $sent++;
            continue;
        }

        if ($status === 'deferred') {
            $deliverAfter = (string) ($result['deliver_after'] ?? '');
            if ($deliverAfter === '') {
                $deliverAfter = $settings->getQuietHoursResumeAt((int) ($item['user_id'] ?? 0)) ?: gmdate('Y-m-d H:i:s');
            }
            $settings->rescheduleQueuedDelivery($queueId, $deliverAfter, $errorText ?: 'quiet_hours');
            $deferred++;
            continue;
        }

        if ($status === 'skipped') {
            $settings->markQueuedDeliveryCompleted($queueId, 'skipped', $errorText);
            $skipped++;
            continue;
        }

        $settings->markQueuedDeliveryCompleted($queueId, 'failed', $errorText ?: 'send_failed');
        $failed++;
    } catch (Throwable $e) {
        $settings->markQueuedDeliveryCompleted($queueId, 'failed', $e->getMessage());
        $failed++;
        error_log('[Notifications] Queued delivery failed: ' . $e->getMessage());
    }
}

if (php_sapi_name() === 'cli' && $processed > 0) {
    echo sprintf(
        "Processed %d queued notifications (sent=%d deferred=%d skipped=%d failed=%d)\n",
        $processed,
        $sent,
        $deferred,
        $skipped,
        $failed
    );
}
