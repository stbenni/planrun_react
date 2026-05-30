#!/usr/bin/env php
<?php
/**
 * Race countdown — in-app уведомления о приближении старта на ключевых отметках.
 * Запуск (cron, раз в день): php scripts/push_race_countdown.php
 * Тест: php scripts/push_race_countdown.php --user=1 --force
 *
 * Пишет в plan_notifications (type=race_countdown). Push не шлёт (только in-app).
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PlanNotificationService.php';

$opts = getopt('', ['user::', 'force']);
$onlyUser = isset($opts['user']) ? (int) $opts['user'] : null;
$force = isset($opts['force']);

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "no db\n"); exit(1); }

// Отметки в днях до старта, на которых шлём напоминание.
const MILESTONES = [42, 28, 21, 14, 7, 3, 1];

$DISTANCE_GEN = [
    '5k' => 'забега на 5 км', '10k' => 'забега на 10 км',
    'half' => 'полумарафона', 'half_marathon' => 'полумарафона',
    'marathon' => 'марафона', 'ultra' => 'ультрамарафона',
];

function pluralWeeks(int $n): string {
    $m10 = $n % 10; $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) return 'неделя';
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) return 'недели';
    return 'недель';
}
function pluralDays(int $n): string {
    $m10 = $n % 10; $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) return 'день';
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) return 'дня';
    return 'дней';
}

$svc = new PlanNotificationService($db);
$today = new DateTime('today');

$sql = "SELECT id, race_date, race_distance FROM users
        WHERE race_date IS NOT NULL AND race_date >= CURDATE()";
$params = [];
if ($onlyUser) { $sql .= " AND id = ?"; }
$stmt = $db->prepare($sql);
if ($onlyUser) { $stmt->bind_param('i', $onlyUser); }
$stmt->execute();
$users = $stmt->get_result();

$sent = 0;
while ($u = $users->fetch_assoc()) {
    $uid = (int) $u['id'];
    $raceDate = DateTime::createFromFormat('Y-m-d', $u['race_date']);
    if (!$raceDate) continue;
    $raceDate->setTime(0, 0, 0);
    $daysLeft = (int) $today->diff($raceDate)->format('%a');

    $isMilestone = in_array($daysLeft, MILESTONES, true);
    if (!$isMilestone && !$force) continue;
    if ($daysLeft <= 0) continue;

    // Дедуп: не дублируем ту же отметку за последние 3 дня.
    if (!$force) {
        $chk = $db->prepare("SELECT 1 FROM plan_notifications
            WHERE user_id = ? AND type = 'race_countdown'
              AND JSON_EXTRACT(metadata, '$.milestone') = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) LIMIT 1");
        $chk->bind_param('ii', $uid, $daysLeft);
        $chk->execute();
        if ($chk->get_result()->fetch_row()) { $chk->close(); continue; }
        $chk->close();
    }

    $distLabel = $DISTANCE_GEN[$u['race_distance']] ?? 'старта';
    if ($daysLeft === 1) {
        $title = 'Завтра старт!';
    } elseif ($daysLeft <= 6) {
        $title = "{$daysLeft} " . pluralDays($daysLeft) . ' до старта';
    } else {
        $weeks = (int) round($daysLeft / 7);
        $title = "{$weeks} " . pluralWeeks($weeks) . ' до ' . $distLabel;
    }
    $body = 'Старт ' . $raceDate->format('d.m.Y') . '. Проверь план на эту неделю.';

    $svc->notify($uid, 'race_countdown', $title, [
        'title' => $title,
        'body' => $body,
        'milestone' => $daysLeft,
        'link' => '/calendar',
        'action_label' => 'План недели →',
    ]);
    $sent++;
    echo "user #{$uid}: {$daysLeft} дн. → \"{$title}\"\n";
}

echo "Готово. Отправлено: {$sent}\n";
