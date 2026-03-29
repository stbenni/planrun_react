#!/usr/bin/env php
<?php
/**
 * Worker очереди фоновой генерации карточек шаринга.
 *
 * Запуск:
 *   php scripts/workout_share_worker.php --once
 *   php scripts/workout_share_worker.php --drain
 *   php scripts/workout_share_worker.php --daemon --sleep=10
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WorkoutShareCardCacheService.php';
require_once $baseDir . '/services/WorkoutShareCardService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$cache = new WorkoutShareCardCacheService($db);
$renderer = new WorkoutShareCardService($db);

$args = $argv ?? [];
$daemon = in_array('--daemon', $args, true);
$drain = in_array('--drain', $args, true);
$once = in_array('--once', $args, true) || (!$daemon && !$drain);
$sleepSeconds = 10;

foreach ($args as $arg) {
    if (strpos($arg, '--sleep=') === 0) {
        $sleepSeconds = max(1, (int) substr($arg, strlen('--sleep=')));
    }
}

do {
    $job = $cache->reserveNextJob();
    if (!$job) {
        if ($once || $drain) {
            exit(0);
        }
        sleep($sleepSeconds);
        continue;
    }

    $jobId = (int) ($job['id'] ?? 0);
    $userId = (int) ($job['user_id'] ?? 0);
    $workoutId = (int) ($job['workout_id'] ?? 0);
    $workoutKind = (string) ($job['workout_kind'] ?? WorkoutShareCardCacheService::KIND_WORKOUT);
    $template = (string) ($job['template'] ?? WorkoutShareCardCacheService::TEMPLATE_ROUTE);
    $attempts = (int) ($job['attempts'] ?? 1);
    $maxAttempts = (int) ($job['max_attempts'] ?? 2);

    try {
        $alreadyCached = $cache->getCachedCard($userId, $workoutId, $workoutKind, $template);
        if ($alreadyCached) {
            $cache->markCompleted($jobId, [
                'template' => $template,
                'workout_kind' => $workoutKind,
                'workout_id' => $workoutId,
                'skipped' => true,
                'reason' => 'cache_exists',
            ]);
            fwrite(STDOUT, "SKIP job={$jobId} user={$userId} kind={$workoutKind} workout={$workoutId} template={$template} reason=cache_exists\n");
            continue;
        }

        $rendered = $renderer->render($workoutId, $userId, $template, $workoutKind);
        $alreadyCached = $cache->getCachedCard($userId, $workoutId, $workoutKind, $template);
        if ($alreadyCached) {
            $cache->markCompleted($jobId, [
                'template' => $template,
                'workout_kind' => $workoutKind,
                'workout_id' => $workoutId,
                'skipped' => true,
                'reason' => 'cache_exists_after_render',
            ]);
            fwrite(STDOUT, "SKIP job={$jobId} user={$userId} kind={$workoutKind} workout={$workoutId} template={$template} reason=cache_exists_after_render\n");
            continue;
        }
        $stored = $cache->storeRenderedCard($userId, $workoutId, $workoutKind, $template, $rendered);
        $cache->markCompleted($jobId, [
            'template' => $template,
            'workout_kind' => $workoutKind,
            'workout_id' => $workoutId,
            'file_name' => $stored['file_name'] ?? null,
            'file_size' => $stored['file_size'] ?? null,
            'map_provider' => $stored['map_provider'] ?? null,
        ]);
        fwrite(STDOUT, "OK job={$jobId} user={$userId} kind={$workoutKind} workout={$workoutId} template={$template}\n");
    } catch (Throwable $e) {
        $message = 'Ошибка рендера карточки шаринга: ' . $e->getMessage();
        $cache->markFailed($jobId, $message, $attempts, $maxAttempts);
        fwrite(STDERR, "FAIL job={$jobId} user={$userId} kind={$workoutKind} workout={$workoutId} template={$template}: {$message}\n");
        if ($once) {
            exit(1);
        }
    }
} while ($daemon || $drain);
