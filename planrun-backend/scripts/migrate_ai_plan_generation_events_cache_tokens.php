#!/usr/bin/env php
<?php
/**
 * Миграция: добавляет колонки prompt_cache_hit_tokens и prompt_cache_miss_tokens
 * в ai_plan_generation_events для отслеживания эффективности DeepSeek context cache
 * (90% скидка на cache-hit tokens).
 *
 * Запуск: php scripts/migrate_ai_plan_generation_events_cache_tokens.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

function columnExists(mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['cnt'] > 0;
}

$columnsToAdd = [
    'prompt_cache_hit_tokens' => 'INT NULL DEFAULT NULL COMMENT "DeepSeek context cache: tokens served from cache (90% cheaper)"',
    'prompt_cache_miss_tokens' => 'INT NULL DEFAULT NULL COMMENT "DeepSeek context cache: tokens that were a cache miss (full price)"',
];

foreach ($columnsToAdd as $col => $def) {
    if (columnExists($db, 'ai_plan_generation_events', $col)) {
        echo "SKIP: column {$col} already exists\n";
        continue;
    }
    $sql = "ALTER TABLE ai_plan_generation_events ADD COLUMN {$col} {$def} AFTER total_tokens";
    if ($db->query($sql)) {
        echo "ADDED: {$col}\n";
    } else {
        fwrite(STDERR, "FAILED to add {$col}: " . $db->error . "\n");
        exit(1);
    }
}

echo "DONE\n";
