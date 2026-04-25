#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runs live next_plan generation for synthetic users after their current saved
 * plan is considered completed.
 *
 * Usage:
 *   php scripts/live_next_plan_batch.php --prefix=live50_20260424 --limit=50
 *   php scripts/live_next_plan_batch.php --limit=5
 */

set_time_limit(0);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PlanGenerationProcessorService.php';

function liveNextParseArgs(array $argv): array
{
    $args = [
        'prefix' => 'live50_20260424',
        'limit' => '50',
        'save-dir' => dirname(__DIR__) . '/tmp/live_plan_generation',
        'fast-llm-fallback' => '1',
    ];

    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0] ?? '';
        $value = $parts[1] ?? '1';
        if ($key !== '') {
            $args[$key] = $value;
        }
    }

    return $args;
}

function liveNextBool(mixed $value): bool
{
    return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
}

function liveNextSetEnv(string $key, string $value): void
{
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

function liveNextFetchUsers(mysqli $db, string $prefix, int $limit): array
{
    $like = $prefix . '%';
    $stmt = $db->prepare(
        'SELECT *
         FROM users
         WHERE username LIKE ?
         ORDER BY id ASC
         LIMIT ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare users failed: ' . $db->error);
    }

    $stmt->bind_param('si', $like, $limit);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $users;
}

function liveNextFetchPlanWeeks(mysqli $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT week_number, start_date, total_volume
         FROM training_plan_weeks
         WHERE user_id = ?
         ORDER BY start_date ASC, week_number ASC'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare weeks failed: ' . $db->error);
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $weeks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $weeks;
}

function liveNextCaseCode(array $user): string
{
    $username = (string) ($user['username'] ?? '');
    $prefix = 'live50_20260424_';
    return str_starts_with($username, $prefix) ? substr($username, strlen($prefix)) : $username;
}

function liveNextMondayAfter(string $date): string
{
    return (new DateTimeImmutable($date))->modify('+1 week')->format('Y-m-d');
}

function liveNextBuildPayload(array $user, array $beforeWeeks): array
{
    $lastWeek = $beforeWeeks[count($beforeWeeks) - 1] ?? [];
    $lastStart = (string) ($lastWeek['start_date'] ?? (new DateTimeImmutable('now'))->format('Y-m-d'));
    $cutoffDate = liveNextMondayAfter($lastStart);
    $recent = array_slice($beforeWeeks, -4);
    $volumes = array_values(array_filter(array_map(
        static fn(array $week): float => round((float) ($week['total_volume'] ?? 0.0), 1),
        $recent
    ), static fn(float $volume): bool => $volume > 0.0));
    $lastAvg = $volumes !== [] ? round(array_sum($volumes) / count($volumes), 1) : 0.0;

    return [
        'cutoff_date' => $cutoffDate,
        'last_plan_avg_km' => $lastAvg,
        'goals' => 'Новый блок после завершения предыдущего плана: сохранить устойчивость, без резкого роста объема.',
    ];
}

function liveNextSummarizeWeeks(array $weeks, string $cutoffDate): array
{
    $raceDays = [];
    foreach ($weeks as $week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $startDate = (string) ($week['start_date'] ?? '');
        if ($startDate === '' || strcmp($startDate, $cutoffDate) < 0) {
            continue;
        }
    }

    return [
        'weeks_count' => count($weeks),
        'first_start_date' => (string) ($weeks[0]['start_date'] ?? ''),
        'last_start_date' => (string) ($weeks[count($weeks) - 1]['start_date'] ?? ''),
        'first_volume_km' => round((float) ($weeks[0]['total_volume'] ?? 0.0), 1),
        'max_volume_km' => $weeks !== [] ? round(max(array_map(
            static fn(array $week): float => (float) ($week['total_volume'] ?? 0.0),
            $weeks
        )), 1) : 0.0,
        'race_days' => $raceDays,
    ];
}

function liveNextIssue(string $severity, string $code, string $message, array $context = []): array
{
    return [
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
        'context' => $context,
    ];
}

function liveNextEvaluate(array $user, array $payload, array $beforeWeeks, array $afterWeeks, ?array $result, ?string $error): array
{
    $issues = [];
    if ($error !== null) {
        $issues[] = liveNextIssue('error', 'next_plan_failed', $error);
    }

    $cutoffDate = (string) ($payload['cutoff_date'] ?? '');
    $afterSummary = liveNextSummarizeWeeks($afterWeeks, $cutoffDate);
    $startDate = (string) ($result['start_date'] ?? $afterSummary['first_start_date'] ?? '');
    if ($startDate !== '' && $cutoffDate !== '' && $startDate !== $cutoffDate) {
        $issues[] = liveNextIssue('error', 'next_plan_wrong_anchor', 'Новый план стартует не с ожидаемой даты.', [
            'expected' => $cutoffDate,
            'actual' => $startDate,
        ]);
    }

    if ((int) ($afterSummary['weeks_count'] ?? 0) < 4) {
        $issues[] = liveNextIssue('error', 'next_plan_too_short', 'Новый план содержит меньше 4 недель.', [
            'weeks_count' => $afterSummary['weeks_count'] ?? 0,
        ]);
    }

    $lastAvg = (float) ($payload['last_plan_avg_km'] ?? 0.0);
    $firstVolume = (float) ($afterSummary['first_volume_km'] ?? 0.0);
    if ($lastAvg > 0.0 && $firstVolume > ($lastAvg * 1.25 + 2.0)) {
        $issues[] = liveNextIssue('warning', 'next_plan_starts_above_recent_load', 'Новый план начинается заметно выше среднего завершенного блока.', [
            'last_plan_avg_km' => $lastAvg,
            'first_volume_km' => $firstVolume,
        ]);
    }

    $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
    $codes = [];
    foreach ($issues as $issue) {
        $severity = (string) ($issue['severity'] ?? 'info');
        $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        $code = (string) ($issue['code'] ?? 'unknown');
        $codes[$code] = ($codes[$code] ?? 0) + 1;
    }

    return [
        'issues' => $issues,
        'summary' => [
            'issue_counts' => $counts,
            'issue_code_counts' => $codes,
            'before_weeks' => count($beforeWeeks),
            'after_weeks' => $afterSummary['weeks_count'] ?? 0,
            'last_plan_avg_km' => $lastAvg,
            'first_volume_km' => $firstVolume,
        ],
    ];
}

function liveNextBuildMarkdown(array $report): string
{
    $lines = [];
    $lines[] = '# Live Next Plan Batch';
    $lines[] = '';
    $lines[] = '- Generated at: ' . gmdate('Y-m-d H:i:s') . ' UTC';
    $lines[] = '- Prefix: `' . ($report['context']['prefix'] ?? '') . '`';
    $lines[] = '- Users: ' . count($report['users']);
    $lines[] = '- Live LLM path: ' . (!empty($report['context']['fast_llm_fallback']) ? 'fallback/algorithmic' : 'configured LLM');
    $lines[] = '';

    $summary = $report['summary'];
    $lines[] = '## Summary';
    $lines[] = '';
    $lines[] = '- Next plan ok: ' . $summary['next_plan_ok'];
    $lines[] = '- Next plan failed: ' . $summary['next_plan_failed'];
    $lines[] = '- Trainer issues: errors=' . $summary['issue_counts']['error'] . ', warnings=' . $summary['issue_counts']['warning'] . ', info=' . $summary['issue_counts']['info'];
    $lines[] = '';

    $lines[] = '## Users';
    $lines[] = '';
    $lines[] = '| # | User ID | Case | Cutoff | Weeks | Avg before | First km | E/W/I |';
    $lines[] = '| ---: | ---: | --- | --- | ---: | ---: | ---: | --- |';
    foreach ($report['users'] as $i => $item) {
        $eval = $item['evaluation']['summary'] ?? [];
        $counts = $eval['issue_counts'] ?? ['error' => 0, 'warning' => 0, 'info' => 0];
        $lines[] = sprintf(
            '| %d | %d | `%s` | `%s` | %d | %.1f | %.1f | %d/%d/%d |',
            $i + 1,
            (int) $item['user_id'],
            (string) ($item['case_code'] ?? ''),
            (string) ($item['payload']['cutoff_date'] ?? ''),
            (int) ($eval['after_weeks'] ?? 0),
            (float) ($eval['last_plan_avg_km'] ?? 0.0),
            (float) ($eval['first_volume_km'] ?? 0.0),
            (int) ($counts['error'] ?? 0),
            (int) ($counts['warning'] ?? 0),
            (int) ($counts['info'] ?? 0)
        );
    }
    $lines[] = '';

    $lines[] = '## Problems';
    $lines[] = '';
    $hasProblems = false;
    foreach ($report['users'] as $item) {
        $issues = (array) ($item['evaluation']['issues'] ?? []);
        if ($issues === []) {
            continue;
        }
        $hasProblems = true;
        $lines[] = '### ' . ($item['case_code'] ?? '') . ' (`user_id=' . $item['user_id'] . '`)';
        $lines[] = '';
        foreach ($issues as $issue) {
            $lines[] = '- ' . strtoupper((string) $issue['severity']) . ' `' . $issue['code'] . '`: ' . $issue['message'];
        }
        $lines[] = '';
    }
    if (!$hasProblems) {
        $lines[] = 'No next_plan issues were found.';
        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$args = liveNextParseArgs($argv);
$prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) ($args['prefix'] ?? 'live50_20260424'));
$limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
$saveDir = (string) ($args['save-dir'] ?? ($baseDir . '/tmp/live_plan_generation'));
$fastFallback = liveNextBool($args['fast-llm-fallback'] ?? '1');

if ($fastFallback) {
    liveNextSetEnv('USE_SKELETON_GENERATOR', '1');
    liveNextSetEnv('LLM_CHAT_BASE_URL', 'http://127.0.0.1:1/v1');
    liveNextSetEnv('LLM_CHAT_API_KEY', 'live-next-fallback');
}

if (!is_dir($saveDir) && !mkdir($saveDir, 0775, true) && !is_dir($saveDir)) {
    throw new RuntimeException('Cannot create save dir: ' . $saveDir);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$users = liveNextFetchUsers($db, $prefix, $limit);
if ($users === []) {
    throw new RuntimeException('No users found for prefix ' . $prefix);
}

$processor = new PlanGenerationProcessorService($db);
$report = [
    'context' => [
        'prefix' => $prefix,
        'limit' => $limit,
        'fast_llm_fallback' => $fastFallback,
        'generated_at_utc' => gmdate('Y-m-d H:i:s'),
    ],
    'summary' => [
        'next_plan_ok' => 0,
        'next_plan_failed' => 0,
        'issue_counts' => ['error' => 0, 'warning' => 0, 'info' => 0],
        'top_issue_codes' => [],
    ],
    'users' => [],
];

foreach ($users as $index => $user) {
    $userId = (int) ($user['id'] ?? 0);
    $caseCode = liveNextCaseCode($user);
    $linePrefix = sprintf('[%02d/%02d] %s user_id=%d', $index + 1, count($users), $caseCode, $userId);
    fwrite(STDOUT, "{$linePrefix}: next_plan...\n");

    $beforeWeeks = liveNextFetchPlanWeeks($db, $userId);
    $payload = liveNextBuildPayload($user, $beforeWeeks);
    $result = null;
    $error = null;

    try {
        $started = microtime(true);
        $result = $processor->process($userId, 'next_plan', $payload);
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $report['summary']['next_plan_ok']++;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $result = ['ok' => false, 'error' => $error];
        $report['summary']['next_plan_failed']++;
        fwrite(STDERR, "{$linePrefix}: ERROR {$error}\n");
    }

    $afterWeeks = liveNextFetchPlanWeeks($db, $userId);
    $evaluation = liveNextEvaluate($user, $payload, $beforeWeeks, $afterWeeks, $result, $error);

    foreach (($evaluation['summary']['issue_counts'] ?? []) as $severity => $count) {
        $report['summary']['issue_counts'][$severity] = ($report['summary']['issue_counts'][$severity] ?? 0) + (int) $count;
    }
    foreach (($evaluation['summary']['issue_code_counts'] ?? []) as $code => $count) {
        $report['summary']['top_issue_codes'][$code] = ($report['summary']['top_issue_codes'][$code] ?? 0) + (int) $count;
    }

    $report['users'][] = [
        'user_id' => $userId,
        'username' => (string) ($user['username'] ?? ''),
        'case_code' => $caseCode,
        'payload' => $payload,
        'result' => $result,
        'evaluation' => $evaluation,
    ];
}

arsort($report['summary']['top_issue_codes']);
$artifactBase = $saveDir . '/' . $prefix . '_next_' . gmdate('Ymd_His');
$jsonPath = $artifactBase . '.json';
$mdPath = $artifactBase . '.md';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($mdPath, liveNextBuildMarkdown($report));

fwrite(STDOUT, "JSON: {$jsonPath}\n");
fwrite(STDOUT, "Markdown: {$mdPath}\n");
fwrite(STDOUT, "Summary: " . json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($report['summary']['next_plan_failed'] > 0 ? 1 : 0);
