#!/usr/bin/env php
<?php
/**
 * Тест WorkoutStructureAnalyzer для конкретного workout id.
 * Usage: php scripts/test_structure_analyzer.php <workout_id>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WorkoutStructureAnalyzer.php';

$id = (int) ($argv[1] ?? 0);
$userId = (int) ($argv[2] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php scripts/test_structure_analyzer.php <workout_id> [user_id]\n");
    exit(1);
}

$db = getDBConnection();
$analyzer = new WorkoutStructureAnalyzer($db);
$result = $analyzer->analyze($id, null, $userId > 0 ? $userId : null);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
