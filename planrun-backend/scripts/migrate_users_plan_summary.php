#!/usr/bin/env php
<?php
/**
 * Миграция: users.last_plan_summary, last_plan_risk_review_json, last_plan_generated_at.
 * Чтобы plan_summary и risk_review (включая «цель нереалистична» и рекомендации) были
 * видны не только в результате job'а сразу после recalculate, но и в чате при любом
 * запросе о плане.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

function colExists(mysqli $db, string $table, string $col): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $cnt > 0;
}

$cols = [
    'last_plan_summary' => 'TEXT NULL DEFAULT NULL COMMENT "DeepSeek plan_summary from latest generation/recalc"',
    'last_plan_risk_review_json' => 'JSON NULL DEFAULT NULL COMMENT "DeepSeek risk_review array from latest generation/recalc"',
    'last_plan_generated_at' => 'DATETIME NULL DEFAULT NULL COMMENT "When the latest plan was generated"',
];

foreach ($cols as $col => $def) {
    if (colExists($db, 'users', $col)) {
        echo "SKIP users.{$col}\n";
        continue;
    }
    if ($db->query("ALTER TABLE users ADD COLUMN {$col} {$def}")) {
        echo "ADDED users.{$col}\n";
    } else {
        fwrite(STDERR, "FAILED users.{$col}: " . $db->error . "\n");
        exit(1);
    }
}
echo "DONE\n";
