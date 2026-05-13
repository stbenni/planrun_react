#!/usr/bin/env php
<?php
/**
 * Тест post-workout analysis: вызывает WorkoutService::createPostWorkoutAnalysisMessage
 * через рефлексию для существующего workout id.
 *
 * Usage: php scripts/test_post_workout_analysis.php <user_id> <workout_id> <yyyy-mm-dd>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WorkoutService.php';

$userId = (int) ($argv[1] ?? 0);
$workoutId = (int) ($argv[2] ?? 0);
$date = (string) ($argv[3] ?? '');
if ($userId <= 0 || $workoutId <= 0 || $date === '') {
    fwrite(STDERR, "Usage: php scripts/test_post_workout_analysis.php <user_id> <workout_id> <yyyy-mm-dd>\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$service = new WorkoutService($db);
$ref = new ReflectionClass($service);
$method = $ref->getMethod('createPostWorkoutAnalysisMessage');
$method->setAccessible(true);

echo "Calling createPostWorkoutAnalysisMessage(user={$userId}, date={$date}, workout, id={$workoutId})…\n";
$messageId = $method->invoke($service, $userId, $date, 'workout', $workoutId);
echo "message_id = {$messageId}\n";
