#!/usr/bin/env php
<?php
/**
 * Удаление просроченных refresh-токенов из таблицы refresh_tokens.
 * Cron: раз в сутки — 0 3 * * * php /path/to/planrun-backend/scripts/cleanup_expired_refresh_tokens.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();

$result = $db->query("SELECT COUNT(*) AS cnt FROM refresh_tokens WHERE expires_at < NOW()");
$row = $result->fetch_assoc();
$expiredCount = (int) ($row['cnt'] ?? 0);

if ($expiredCount === 0) {
    echo date('Y-m-d H:i:s') . " — нет просроченных refresh-токенов\n";
    $db->close();
    exit(0);
}

$stmt = $db->prepare("DELETE FROM refresh_tokens WHERE expires_at < NOW()");
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();

echo date('Y-m-d H:i:s') . " — удалено просроченных refresh-токенов: $deleted\n";

$db->close();
