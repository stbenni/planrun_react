#!/usr/bin/env php
<?php
/**
 * Отправка push-напоминаний о тренировках на завтра.
 * Запуск по cron каждую минуту: * * * * * php /path/to/planrun-backend/scripts/push_workout_reminders.php
 * Отправляет пользователям, у которых в их часовом поясе push_workout_hour и push_workout_minute совпадают с текущим временем.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/load_training_plan.php';
require_once $baseDir . '/services/PushNotificationService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$restTypes = ['rest', 'free'];

// Пользователи с включёнными напоминаниями и их настройки
$stmt = $db->query("SELECT DISTINCT p.user_id, COALESCE(u.timezone, 'Europe/Moscow') AS timezone,
    COALESCE(u.push_workout_hour, 20) AS push_hour, COALESCE(u.push_workout_minute, 0) AS push_minute
    FROM push_tokens p
    INNER JOIN users u ON u.id = p.user_id
    WHERE COALESCE(u.push_workouts_enabled, 1) = 1");
$result = $stmt ? $stmt->get_result() : null;
if (!$result) {
    exit(0);
}

$push = new PushNotificationService($db);
$sent = 0;

while ($row = $result->fetch_assoc()) {
    $userId = (int)$row['user_id'];
    $tz = $row['timezone'] ?: 'Europe/Moscow';
    $pushHour = (int)$row['push_hour'];
    $pushMinute = (int)$row['push_minute'];

    try {
        $userNow = new DateTime('now', new DateTimeZone($tz));
    } catch (Exception $e) {
        $userNow = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    }
    if ((int)$userNow->format('G') !== $pushHour || (int)$userNow->format('i') !== $pushMinute) {
        continue;
    }

    $tomorrow = (clone $userNow)->modify('+1 day')->format('Y-m-d');
    $dayOfWeek = (int)(new DateTime($tomorrow, new DateTimeZone($tz)))->format('N');
    $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $dayName = $dayNames[$dayOfWeek];
    $plan = loadTrainingPlanForUser($userId, false);
    $weeksData = $plan['weeks_data'] ?? [];
    if (empty($weeksData)) {
        continue;
    }

    $tomorrowDt = new DateTime($tomorrow);
    foreach ($weeksData as $week) {
        $weekStart = new DateTime($week['start_date']);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');
        if ($tomorrowDt >= $weekStart && $tomorrowDt <= $weekEnd) {
            $days = $week['days'] ?? [];
            $dayData = $days[$dayName] ?? null;
            if ($dayData === null) {
                break;
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
                break;
            }
            $body = implode('; ', array_slice($descriptions, 0, 2));
            if (mb_strlen($body) > 80) {
                $body = mb_substr($body, 0, 77) . '...';
            }
            $push->sendToUser($userId, 'Завтра: тренировка', $body, [
                'type' => 'workout',
                'date' => $tomorrow,
                'link' => '/calendar?date=' . $tomorrow
            ]);
            $sent++;
            break;
        }
    }
}

if (php_sapi_name() === 'cli' && $sent > 0) {
    echo "Sent $sent workout reminders\n";
}
