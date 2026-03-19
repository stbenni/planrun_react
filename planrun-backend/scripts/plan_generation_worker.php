#!/usr/bin/env php
<?php
/**
 * Worker очереди генерации плана.
 * Запуск:
 *   php scripts/plan_generation_worker.php --once
 *   php scripts/plan_generation_worker.php --daemon --sleep=10
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PlanGenerationQueueService.php';
require_once $baseDir . '/services/PlanGenerationProcessorService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$queue = new PlanGenerationQueueService($db);
$processor = new PlanGenerationProcessorService($db);

$args = $argv ?? [];
$daemon = in_array('--daemon', $args, true);
$once = in_array('--once', $args, true) || !$daemon;
$sleepSeconds = 10;

foreach ($args as $arg) {
    if (strpos($arg, '--sleep=') === 0) {
        $sleepSeconds = max(1, (int) substr($arg, strlen('--sleep=')));
    }
}

do {
    $job = $queue->reserveNextJob();
    if (!$job) {
        if ($once) {
            exit(0);
        }
        sleep($sleepSeconds);
        continue;
    }

    $jobId = (int) $job['id'];
    $userId = (int) $job['user_id'];
    $jobType = (string) $job['job_type'];
    $attempts = (int) $job['attempts'];
    $maxAttempts = (int) $job['max_attempts'];
    $payload = [];
    if (!empty($job['payload_json'])) {
        $payload = json_decode((string) $job['payload_json'], true) ?: [];
    }

    try {
        $result = $processor->process($userId, $jobType, $payload);
        $queue->markCompleted($jobId, $result);
        fwrite(STDOUT, "OK job={$jobId} user={$userId} type={$jobType}\n");
    } catch (Throwable $e) {
        $errorMessage = 'Ошибка генерации плана: ' . $e->getMessage();
        $processor->persistFailure($userId, $errorMessage);
        $queue->markFailed($jobId, $errorMessage, $attempts, $maxAttempts);
        fwrite(STDERR, "FAIL job={$jobId} user={$userId} type={$jobType}: {$errorMessage}\n");
        if ($once) {
            exit(1);
        }
    }
} while (!$once);
