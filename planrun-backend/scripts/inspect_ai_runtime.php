#!/usr/bin/env php
<?php
/**
 * Inspect AI runtime events.
 *
 * Usage:
 *   php scripts/inspect_ai_runtime.php --hours=24 --limit=50
 *   php scripts/inspect_ai_runtime.php --surface=plan_generation --status=error
 *   php scripts/inspect_ai_runtime.php --trace=plan_generation-abc123
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$args = parseArgs($argv ?? []);
$limit = max(1, min(500, (int) ($args['limit'] ?? 50)));
$hours = max(1, min(24 * 30, (int) ($args['hours'] ?? 24)));

$where = ['created_at >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)'];
if (!empty($args['surface'])) {
    $where[] = "surface = '" . $db->real_escape_string((string) $args['surface']) . "'";
}
if (!empty($args['status'])) {
    $where[] = "status = '" . $db->real_escape_string((string) $args['status']) . "'";
}
if (!empty($args['event_type'])) {
    $where[] = "event_type = '" . $db->real_escape_string((string) $args['event_type']) . "'";
}
if (!empty($args['trace'])) {
    $where[] = "trace_id = '" . $db->real_escape_string((string) $args['trace']) . "'";
}
if (isset($args['user_id']) && is_numeric($args['user_id'])) {
    $where[] = 'user_id = ' . (int) $args['user_id'];
}

$whereSql = implode(' AND ', $where);
$summaryRows = fetchAll($db, "
    SELECT surface, event_type, status, COUNT(*) AS cnt, AVG(duration_ms) AS avg_ms, MAX(duration_ms) AS max_ms
    FROM ai_runtime_events
    WHERE {$whereSql}
    GROUP BY surface, event_type, status
    ORDER BY cnt DESC, surface ASC, event_type ASC, status ASC
");

$events = fetchAll($db, "
    SELECT id, user_id, surface, event_type, status, trace_id, duration_ms, payload_json, created_at
    FROM ai_runtime_events
    WHERE {$whereSql}
    ORDER BY id DESC
    LIMIT {$limit}
");

$durations = [];
foreach ($events as $event) {
    if (isset($event['duration_ms']) && is_numeric($event['duration_ms'])) {
        $durations[] = (int) $event['duration_ms'];
    }
}
sort($durations);

echo json_encode([
    'filters' => [
        'hours' => $hours,
        'limit' => $limit,
        'surface' => $args['surface'] ?? null,
        'event_type' => $args['event_type'] ?? null,
        'status' => $args['status'] ?? null,
        'trace' => $args['trace'] ?? null,
        'user_id' => isset($args['user_id']) ? (int) $args['user_id'] : null,
    ],
    'totals' => [
        'events_returned' => count($events),
        'duration_p50_ms' => percentile($durations, 50),
        'duration_p95_ms' => percentile($durations, 95),
        'duration_max_ms' => $durations !== [] ? max($durations) : null,
    ],
    'summary' => array_map(static function (array $row): array {
        return [
            'surface' => (string) $row['surface'],
            'event_type' => (string) $row['event_type'],
            'status' => (string) $row['status'],
            'count' => (int) $row['cnt'],
            'avg_ms' => isset($row['avg_ms']) ? round((float) $row['avg_ms']) : null,
            'max_ms' => isset($row['max_ms']) ? (int) $row['max_ms'] : null,
        ];
    }, $summaryRows),
    'events' => array_map('formatEvent', $events),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function parseArgs(array $argv): array {
    $out = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $arg = substr($arg, 2);
        if (strpos($arg, '=') === false) {
            $out[$arg] = true;
            continue;
        }
        [$key, $value] = explode('=', $arg, 2);
        $out[$key] = $value;
    }
    return $out;
}

function fetchAll(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) {
        fwrite(STDERR, "Query failed: {$db->error}\n");
        exit(1);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function percentile(array $values, int $percentile): ?int {
    if ($values === []) {
        return null;
    }
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));
    return (int) $values[$index];
}

function formatEvent(array $row): array {
    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    return [
        'id' => (int) $row['id'],
        'created_at' => (string) $row['created_at'],
        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'surface' => (string) $row['surface'],
        'event_type' => (string) $row['event_type'],
        'status' => (string) $row['status'],
        'trace_id' => (string) $row['trace_id'],
        'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
        'payload' => compactPayload($payload),
    ];
}

function compactPayload(array $payload): array {
    $keys = [
        'feature',
        'model',
        'provider',
        'http_status',
        'attempts',
        'max_attempts',
        'retry_count',
        'finish_reason',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'prompt_cache_hit_tokens',
        'cached_tokens',
        'limiter_enabled',
        'limiter_wait_ms',
        'limiter_ttl_seconds',
        'limiter_rejected',
        'chunks_count',
        'chars_count',
        'job_id',
        'job_type',
        'weeks_count',
        'generator',
        'error',
    ];

    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $payload)) {
            $out[$key] = $payload[$key];
        }
    }
    if (isset($payload['limiter_pools'])) {
        $out['limiter_pools'] = $payload['limiter_pools'];
    }
    if (isset($payload['api_key_pool_size'])) {
        $out['api_key_pool_size'] = $payload['api_key_pool_size'];
    }
    if (isset($payload['api_key_fingerprint'])) {
        $out['api_key_fingerprint'] = $payload['api_key_fingerprint'];
    }
    return $out;
}
