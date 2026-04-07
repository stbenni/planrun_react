<?php
/**
 * Backfill: заполнить target_hr_min/max для всех будущих дней планов.
 * Запуск: php planrun-backend/scripts/backfill_hr_targets.php
 */
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../services/UserProfileService.php';

$db = getDBConnection();
if (!$db) { echo "DB connection failed\n"; exit(1); }

$stmt = $db->query("SELECT DISTINCT user_id FROM training_plan_days WHERE date >= CURDATE() AND (target_hr_min IS NULL OR target_hr_min = 0)");
$users = [];
while ($row = $stmt->fetch_assoc()) {
    $users[] = (int) $row['user_id'];
}
$stmt->close();

echo "Users with empty HR targets: " . count($users) . "\n";

$totalUpdated = 0;
foreach ($users as $userId) {
    try {
        $ups = new UserProfileService($db);
        $updated = $ups->recalculateHrTargetsForFutureDays($userId);
        if ($updated > 0) {
            echo "  user_id={$userId}: updated {$updated} days\n";
            $totalUpdated += $updated;
        }
    } catch (Throwable $e) {
        echo "  user_id={$userId}: ERROR " . $e->getMessage() . "\n";
    }
}

echo "Done. Total updated: {$totalUpdated}\n";
