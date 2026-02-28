#!/usr/bin/env php
<?php
/**
 * Миграция: добавить external_athlete_id в integration_tokens для маппинга Strava owner_id → user_id
 * Запуск: php scripts/migrate_integration_tokens_athlete_id.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$r = $db->query("SHOW COLUMNS FROM integration_tokens LIKE 'external_athlete_id'");
if ($r && $r->num_rows > 0) {
    echo "Column external_athlete_id already exists.\n";
    exit(0);
}

if ($db->query("ALTER TABLE integration_tokens ADD COLUMN external_athlete_id VARCHAR(64) NULL AFTER provider")) {
    echo "OK: added external_athlete_id\n";
} else {
    fwrite(STDERR, "Error: " . $db->error . "\n");
    exit(1);
}

if ($db->query("CREATE INDEX idx_strava_athlete ON integration_tokens (provider, external_athlete_id)")) {
    echo "OK: added index idx_strava_athlete\n";
} else {
    echo "Note: index may already exist\n";
}

echo "Migration done.\n";
