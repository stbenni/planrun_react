<?php
/**
 * Health check endpoint for PlanRun.
 *
 * GET /api/health.php — returns service status, PHP version, DB connectivity, uptime.
 * Used by:
 *   - Nginx/load balancer health checks
 *   - COROS API application (Service Status Check URL)
 *   - Monitoring and alerting
 */
header('Content-Type: application/json; charset=utf-8');

$status = [
    'ok'      => true,
    'service' => 'planrun',
    'version' => '1.9',
    'php'     => PHP_VERSION,
    'time'    => gmdate('c'),
];

// DB connectivity check (optional, fail-soft)
try {
    require_once __DIR__ . '/../planrun-backend/config/env_loader.php';
    require_once __DIR__ . '/../planrun-backend/db_config.php';
    $db = getDBConnection();
    if ($db) {
        $r = $db->query('SELECT 1');
        $status['db'] = $r ? 'ok' : 'error';
        if ($r) $r->free();
    } else {
        $status['db'] = 'unavailable';
    }
} catch (Throwable $e) {
    $status['db'] = 'error';
}

http_response_code($status['ok'] ? 200 : 503);
echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
