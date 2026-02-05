<?php
/**
 * Минимальная проверка: Nginx + PHP-FPM отдают /api/*.php
 * Запрос: GET /api/health.php — должен вернуть JSON без 502
 */
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'php' => PHP_VERSION]);
