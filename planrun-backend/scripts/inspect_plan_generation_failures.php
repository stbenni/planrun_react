#!/usr/bin/env php
<?php
/**
 * Summarize failed plan generation jobs without mutating the queue.
 *
 * Usage:
 *   php scripts/inspect_plan_generation_failures.php
 *   php scripts/inspect_plan_generation_failures.php --limit=50
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$limit = 30;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, min(500, (int) substr($arg, strlen('--limit='))));
    }
}

$stmt = $db->prepare(
    "SELECT id, user_id, job_type, attempts, max_attempts, created_at, finished_at, last_error
     FROM plan_generation_jobs
     WHERE status = 'failed'
     ORDER BY id DESC
     LIMIT ?"
);
if (!$stmt) {
    fwrite(STDERR, "Failed to prepare query: {$db->error}\n");
    exit(1);
}

$stmt->bind_param('i', $limit);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['category'] = categorizeFailure((string) ($row['last_error'] ?? ''));
    $rows[] = $row;
}
$stmt->close();

$summary = [];
foreach ($rows as $row) {
    $category = (string) $row['category'];
    $summary[$category] = ($summary[$category] ?? 0) + 1;
}
ksort($summary);

echo json_encode([
    'limit' => $limit,
    'failed_jobs_returned' => count($rows),
    'summary' => $summary,
    'jobs' => array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'job_type' => (string) $row['job_type'],
            'category' => (string) $row['category'],
            'attempts' => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
            'created_at' => (string) $row['created_at'],
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'error_preview' => mb_substr((string) ($row['last_error'] ?? ''), 0, 320, 'UTF-8'),
        ];
    }, $rows),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

function categorizeFailure(string $error): string {
    $lower = mb_strtolower($error, 'UTF-8');
    if (str_contains($lower, 'пользователь не найден')) {
        return 'user_missing';
    }
    if (str_contains($lower, 'trim():') || str_contains($lower, 'typeerror') || str_contains($lower, 'argument #')) {
        return 'code_bug';
    }
    if (str_contains($lower, 'quality gate') || str_contains($lower, 'план не прошёл')) {
        return 'quality_gate';
    }
    if (str_contains($lower, 'http 429') || str_contains($lower, 'rate limit')) {
        return 'provider_rate_limit';
    }
    if (preg_match('/http 5\\d\\d/', $lower) || str_contains($lower, 'overload') || str_contains($lower, 'temporarily unavailable')) {
        return 'provider_overload';
    }
    if (str_contains($lower, 'invalid json') || str_contains($lower, 'response was truncated')) {
        return 'llm_output_format';
    }
    return 'other';
}
