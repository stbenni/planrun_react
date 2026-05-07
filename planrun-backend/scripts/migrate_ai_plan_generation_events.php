#!/usr/bin/env php
<?php
/**
 * Миграция (PR6 / Phase D.1): таблица ai_plan_generation_events для observability
 * генерации планов DeepSeek.
 *
 * Структура:
 *  - id, created_at
 *  - user_id, job_type (generate|recalculate|next_plan), surface (по умолчанию plan_generation)
 *  - cohort: производное поле (healthy / return_after_injury / pregnant_or_postpartum / illness / pain / unrealistic_goal)
 *  - model, model_selection_reason (default | simple_scenario_with_minor_risks | complex_scenario)
 *  - complexity_score, enable_thinking
 *  - planner_strategy (всегда single_pass — резерв на будущее)
 *  - duration_ms, prompt_token_count, completion_token_count, total_token_count
 *  - gate_mode (auto|strict|permissive), gate_status (ok|warnings|blocked|skipped), gate_resolved_mode
 *  - retries (счётчик targeted retry — пока 0)
 *  - issue_codes JSON, applied_repair_codes JSON, normalizer_warning_codes JSON
 *  - status (success|failure), error_code, error_message
 *  - prompt_version
 *  - trace_id
 *  - JSON metadata snapshot (произвольные поля, в т.ч. plan_summary, risk_review)
 *
 * Запуск: php scripts/migrate_ai_plan_generation_events.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

function tableExists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['cnt'] > 0;
}

if (tableExists($db, 'ai_plan_generation_events')) {
    echo "SKIP: ai_plan_generation_events already exists\n";
    exit(0);
}

$sql = <<<SQL
CREATE TABLE ai_plan_generation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT UNSIGNED NOT NULL,
    job_type VARCHAR(32) NOT NULL,
    surface VARCHAR(64) NOT NULL DEFAULT 'plan_generation',
    cohort VARCHAR(64) NOT NULL DEFAULT 'healthy',

    model VARCHAR(64) NOT NULL DEFAULT '',
    model_selection_reason VARCHAR(64) NOT NULL DEFAULT 'default',
    complexity_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    enable_thinking TINYINT(1) NOT NULL DEFAULT 0,
    planner_strategy VARCHAR(32) NOT NULL DEFAULT 'single_pass',

    duration_ms INT UNSIGNED NULL,
    prompt_tokens INT UNSIGNED NULL,
    completion_tokens INT UNSIGNED NULL,
    total_tokens INT UNSIGNED NULL,

    gate_mode VARCHAR(16) NULL,
    gate_resolved_mode VARCHAR(16) NULL,
    gate_status VARCHAR(16) NULL,
    retries TINYINT UNSIGNED NOT NULL DEFAULT 0,

    issue_codes JSON NULL,
    applied_repair_codes JSON NULL,
    normalizer_warning_codes JSON NULL,

    status VARCHAR(16) NOT NULL DEFAULT 'success',
    error_code VARCHAR(64) NULL,
    error_message TEXT NULL,

    prompt_version VARCHAR(64) NULL,
    trace_id VARCHAR(64) NULL,

    metadata JSON NULL,

    PRIMARY KEY (id),
    KEY idx_aipge_user_created (user_id, created_at),
    KEY idx_aipge_cohort_created (cohort, created_at),
    KEY idx_aipge_status_created (status, created_at),
    KEY idx_aipge_model_created (model, created_at),
    KEY idx_aipge_trace (trace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$db->query($sql)) {
    fwrite(STDERR, "Error creating ai_plan_generation_events: " . $db->error . "\n");
    exit(1);
}

echo "OK: created ai_plan_generation_events\n";
echo "ai_plan_generation_events migration done.\n";
