#!/usr/bin/env php
<?php
/**
 * Отправка уведомлений о сегодняшней и завтрашней тренировке.
 * Запуск по cron каждую минуту: * * * * * php /path/to/planrun-backend/scripts/push_workout_reminders.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/load_training_plan.php';
require_once $baseDir . '/services/NotificationDispatcher.php';
require_once $baseDir . '/services/NotificationSettingsService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$restTypes = ['rest', 'free'];
$settings = new NotificationSettingsService($db);
$dispatcher = new NotificationDispatcher($db);

$result = $db->query("SELECT DISTINCT
        u.id AS user_id,
        COALESCE(u.timezone, 'Europe/Moscow') AS timezone
    FROM users u
    LEFT JOIN push_tokens p ON p.user_id = u.id
    WHERE p.user_id IS NOT NULL
       OR COALESCE(u.telegram_id, 0) > 0
       OR COALESCE(u.email, '') <> ''");
if (!$result) {
    exit(0);
}
$sent = 0;

function planrunExtractWorkoutSummary(array $weeksData, string $targetDate, string $timezone, array $restTypes): ?string {
    $dayOfWeek = (int) (new DateTime($targetDate, new DateTimeZone($timezone)))->format('N');
    $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $dayName = $dayNames[$dayOfWeek] ?? 'mon';
    $targetDt = new DateTime($targetDate);

    foreach ($weeksData as $week) {
        if (empty($week['start_date'])) {
            continue;
        }

        $weekStart = new DateTime($week['start_date']);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');
        if ($targetDt < $weekStart || $targetDt > $weekEnd) {
            continue;
        }

        $days = $week['days'] ?? [];
        $dayData = $days[$dayName] ?? null;
        if ($dayData === null) {
            return null;
        }

        $items = is_array($dayData) ? $dayData : [$dayData];
        $descriptions = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if (in_array($type, $restTypes, true)) {
                continue;
            }
            $text = trim($item['text'] ?? '');
            $descriptions[] = $text !== '' ? $text : $type;
        }

        if (empty($descriptions)) {
            return null;
        }

        $body = implode('; ', array_slice($descriptions, 0, 2));
        if (mb_strlen($body) > 80) {
            $body = mb_substr($body, 0, 77) . '...';
        }
        return $body;
    }

    return null;
}

while ($row = $result->fetch_assoc()) {
    $userId = (int)$row['user_id'];
    $tz = $row['timezone'] ?: 'Europe/Moscow';
    try {
        $userNow = new DateTime('now', new DateTimeZone($tz));
    } catch (Exception $e) {
        $userNow = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    }

    $todaySchedule = $settings->getWorkoutReminderSchedule($userId, 'today');
    $tomorrowSchedule = $settings->getWorkoutReminderSchedule($userId, 'tomorrow');
    $dueScopes = [];

    if ($settings->hasAnyDeliverableChannel($userId, 'workout.reminder.today', true)
        && (int) $userNow->format('G') === (int) $todaySchedule['hour']
        && (int) $userNow->format('i') === (int) $todaySchedule['minute']) {
        $dueScopes[] = 'today';
    }

    if ($settings->hasAnyDeliverableChannel($userId, 'workout.reminder.tomorrow', true)
        && (int) $userNow->format('G') === (int) $tomorrowSchedule['hour']
        && (int) $userNow->format('i') === (int) $tomorrowSchedule['minute']) {
        $dueScopes[] = 'tomorrow';
    }

    if (empty($dueScopes)) {
        continue;
    }

    $plan = loadTrainingPlanForUser($userId, false);
    $weeksData = $plan['weeks_data'] ?? [];
    if (empty($weeksData)) {
        continue;
    }

    foreach ($dueScopes as $scope) {
        $targetDate = $scope === 'today'
            ? $userNow->format('Y-m-d')
            : (clone $userNow)->modify('+1 day')->format('Y-m-d');
        $body = planrunExtractWorkoutSummary($weeksData, $targetDate, $tz, $restTypes);
        if ($body === null) {
            continue;
        }

        $eventKey = $scope === 'today' ? 'workout.reminder.today' : 'workout.reminder.tomorrow';
        $title = $scope === 'today' ? 'Сегодня тренировка' : 'Завтра: тренировка';
        $entityType = 'workout_reminder';
        $entityId = $scope . ':' . $targetDate;

        if (!$settings->acquireDispatchGuard($userId, $eventKey, $entityType, $entityId)) {
            continue;
        }

        try {
            $results = $dispatcher->dispatchToUser($userId, $eventKey, $title, $body, [
                'link' => '/calendar?date=' . $targetDate,
                'workout_date' => $targetDate,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'push_data' => [
                    'type' => 'workout',
                    'date' => $targetDate,
                    'link' => '/calendar?date=' . $targetDate,
                ],
                'email_action_label' => 'Открыть тренировку',
            ]);
            if (in_array(true, $results, true)) {
                $settings->markDispatchGuardSent($userId, $eventKey, $entityType, $entityId);
                $sent++;
            } elseif (in_array('deferred', $results, true)) {
                $settings->markDispatchGuardSent($userId, $eventKey, $entityType, $entityId);
            } else {
                $settings->releaseDispatchGuard($userId, $eventKey, $entityType, $entityId);
            }
        } catch (Throwable $e) {
            $settings->releaseDispatchGuard($userId, $eventKey, $entityType, $entityId);
            error_log('[Notifications] Workout reminder dispatch failed: ' . $e->getMessage());
        }
    }
}

if (php_sapi_name() === 'cli' && $sent > 0) {
    echo "Sent $sent workout reminders\n";
}
