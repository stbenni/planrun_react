#!/usr/bin/env php
<?php

/**
 * Batch-eval для генерации планов.
 *
 * Примеры:
 *   php scripts/eval_plan_generation.php --user-ids=1,2,3
 *   php scripts/eval_plan_generation.php --user-ids=1,2 --mode=full
 *   php scripts/eval_plan_generation.php --fixture=synthetic
 *   php scripts/eval_plan_generation.php --fixture=synthetic --case-names=novice_couch_to_5k_three_days,first_half_low_base
 *   php scripts/eval_plan_generation.php --user-ids=1 --save-dir=tmp/eval_artifacts
 *
 * Режимы:
 *   first-pass — один raw AI pass + normalizer/validator без corrective/full save path
 *   full       — штатный generatePlanViaPlanAI() / synthetic full pipeline с corrective metadata
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/planrun_ai/plan_generator.php';
require_once $baseDir . '/planrun_ai/prompt_builder.php';

function evalUsage(): void {
    $script = basename(__FILE__);
    echo <<<TXT
Usage:
  php scripts/{$script} --user-ids=1,2,3 [--mode=first-pass|full] [--save-dir=tmp/eval_artifacts]
  php scripts/{$script} --fixture=synthetic [--case-names=name1,name2] [--mode=first-pass|full] [--save-dir=tmp/eval_artifacts]

TXT;
}

function evalParseUserIds(string $raw): array {
    $ids = array_filter(array_map('trim', explode(',', $raw)), static fn(string $v): bool => $v !== '');
    $ids = array_map('intval', $ids);
    $ids = array_values(array_unique(array_filter($ids, static fn(int $v): bool => $v > 0)));
    return $ids;
}

function evalFetchUser(mysqli $db, int $userId): ?array {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function evalLoadFixtureCases(string $fixtureName, ?string $caseNamesRaw = null): array {
    $fixtures = [
        'synthetic' => dirname(__DIR__) . '/tests/Fixtures/synthetic_plan_eval_cases.php',
    ];

    if (!isset($fixtures[$fixtureName])) {
        throw new RuntimeException("Unknown fixture: {$fixtureName}");
    }

    $cases = require $fixtures[$fixtureName];
    if (!is_array($cases)) {
        throw new RuntimeException("Fixture {$fixtureName} did not return an array");
    }

    if ($caseNamesRaw === null || trim($caseNamesRaw) === '') {
        return array_values($cases);
    }

    $wanted = array_values(array_filter(array_map('trim', explode(',', $caseNamesRaw)), static fn(string $v): bool => $v !== ''));
    $wantedMap = array_fill_keys($wanted, true);
    $filtered = array_values(array_filter(
        $cases,
        static fn(array $case): bool => isset($wantedMap[$case['name'] ?? ''])
    ));

    if (count($filtered) !== count($wanted)) {
        $found = array_map(static fn(array $case): string => (string) ($case['name'] ?? ''), $filtered);
        $missing = array_values(array_diff($wanted, $found));
        throw new RuntimeException('Unknown fixture case(s): ' . implode(', ', $missing));
    }

    return $filtered;
}

function evalHydrateSyntheticUser(mysqli $db, array $case): array {
    $user = $case['user'] ?? [];
    $goalType = $case['goal_type'] ?? ($user['goal_type'] ?? 'health');
    $user['goal_type'] = $goalType;
    $user = hydratePlanGenerationUserState($db, $user);
    $user = attachPlanSkeleton($user, $goalType, $case['options'] ?? []);
    return $user;
}

function evalSummarizeIssues(array $issues): array {
    $issueCodes = [];
    foreach ($issues as $issue) {
        $code = (string) ($issue['code'] ?? 'unknown');
        $issueCodes[$code] = ($issueCodes[$code] ?? 0) + 1;
    }

    return [
        'issue_score' => scoreValidationIssues($issues),
        'issue_count' => count($issues),
        'issue_codes' => array_keys($issueCodes),
        'issue_code_counts' => $issueCodes,
    ];
}

function evalBuildFirstPassArtifact(mysqli $db, int $userId): array {
    $user = evalFetchUser($db, $userId);
    if (!$user) {
        throw new RuntimeException("User {$userId} not found");
    }

    $user = hydratePlanGenerationUserState($db, $user);
    $goalType = $user['goal_type'] ?? 'health';
    $user = attachPlanSkeleton($user, $goalType);
    $prompt = buildTrainingPlanPrompt($user, $goalType);
    $raw = callAIAPI($prompt, $user, 1, $userId);
    $plan = decodeGeneratedPlanResponse($raw, $user, "eval user {$userId}");
    $normalized = normalizeGeneratedPlanForValidation($plan, $user, (string) $user['training_start_date'], 0);
    $issues = collectNormalizedPlanValidationIssues($normalized, $user['training_state'] ?? [], buildPlanValidationContext($user));

    $issueSummary = evalSummarizeIssues($issues);

    return [
        'user' => [
            'id' => $userId,
            'username' => $user['username'] ?? null,
            'goal_type' => $goalType,
            'race_distance' => $user['race_distance'] ?? null,
        ],
        'summary' => [
            'mode' => 'first-pass',
            'prompt_chars' => strlen($prompt),
            'weeks' => count($plan['weeks'] ?? []),
            'issue_score' => $issueSummary['issue_score'],
            'issue_count' => $issueSummary['issue_count'],
            'issue_codes' => $issueSummary['issue_codes'],
            'issue_code_counts' => $issueSummary['issue_code_counts'],
        ],
        'issues' => $issues,
        'normalized_plan' => $normalized,
        'plan' => $plan,
    ];
}

function evalBuildFullArtifact(int $userId): array {
    $plan = generatePlanViaPlanRunAI($userId);
    $meta = $plan['_generation_metadata'] ?? [];

    return [
        'user' => [
            'id' => $userId,
        ],
        'summary' => [
            'mode' => 'full',
            'weeks' => count($plan['weeks'] ?? []),
            'issue_score' => $meta['final_issue_score'] ?? null,
            'issue_count' => count($meta['final_validation_errors'] ?? []),
            'corrective_used' => $meta['corrective_regeneration_used'] ?? null,
            'repair_count' => $meta['repair_count'] ?? null,
        ],
        'generation_metadata' => $meta,
        'plan' => $plan,
    ];
}

function evalBuildSyntheticFirstPassArtifact(mysqli $db, array $case): array {
    $user = evalHydrateSyntheticUser($db, $case);
    $goalType = $user['goal_type'] ?? 'health';
    $prompt = buildTrainingPlanPrompt($user, $goalType);
    $raw = callAIAPI($prompt, $user, 1, null);
    $plan = decodeGeneratedPlanResponse($raw, $user, 'eval synthetic ' . ($case['name'] ?? 'case'));
    $normalized = normalizeGeneratedPlanForValidation($plan, $user, (string) $user['training_start_date'], 0);
    $issues = collectNormalizedPlanValidationIssues($normalized, $user['training_state'] ?? [], buildPlanValidationContext($user));
    $issueSummary = evalSummarizeIssues($issues);

    return [
        'case' => [
            'name' => $case['name'] ?? 'unnamed_case',
            'goal_type' => $goalType,
        ],
        'user' => [
            'id' => null,
            'username' => $user['username'] ?? null,
            'goal_type' => $goalType,
            'race_distance' => $user['race_distance'] ?? null,
        ],
        'summary' => [
            'mode' => 'first-pass',
            'prompt_chars' => strlen($prompt),
            'weeks' => count($plan['weeks'] ?? []),
            'issue_score' => $issueSummary['issue_score'],
            'issue_count' => $issueSummary['issue_count'],
            'issue_codes' => $issueSummary['issue_codes'],
            'issue_code_counts' => $issueSummary['issue_code_counts'],
        ],
        'issues' => $issues,
        'normalized_plan' => $normalized,
        'plan' => $plan,
    ];
}

function evalBuildSyntheticFullArtifact(mysqli $db, array $case): array {
    $user = evalHydrateSyntheticUser($db, $case);
    $goalType = $user['goal_type'] ?? 'health';
    $prompt = buildTrainingPlanPrompt($user, $goalType);
    $raw = callAIAPI($prompt, $user, 1, null);
    $plan = decodeGeneratedPlanResponse($raw, $user, 'eval synthetic full ' . ($case['name'] ?? 'case'));
    $finalPlan = maybeApplyCorrectiveRegenerationToPlan(
        $plan,
        $user,
        $prompt,
        (string) $user['training_start_date'],
        0,
        null,
        null,
        'Synthetic Eval',
        'generate'
    );

    $normalized = normalizeGeneratedPlanForValidation($finalPlan, $user, (string) $user['training_start_date'], 0);
    $issues = collectNormalizedPlanValidationIssues($normalized, $user['training_state'] ?? [], buildPlanValidationContext($user));
    $issueSummary = evalSummarizeIssues($issues);

    return [
        'case' => [
            'name' => $case['name'] ?? 'unnamed_case',
            'goal_type' => $goalType,
        ],
        'user' => [
            'id' => null,
            'username' => $user['username'] ?? null,
            'goal_type' => $goalType,
            'race_distance' => $user['race_distance'] ?? null,
        ],
        'summary' => [
            'mode' => 'full',
            'prompt_chars' => strlen($prompt),
            'weeks' => count($finalPlan['weeks'] ?? []),
            'issue_score' => $issueSummary['issue_score'],
            'issue_count' => $issueSummary['issue_count'],
            'issue_codes' => $issueSummary['issue_codes'],
            'issue_code_counts' => $issueSummary['issue_code_counts'],
            'corrective_used' => $finalPlan['_generation_metadata']['corrective_regeneration_used'] ?? null,
            'repair_count' => $finalPlan['_generation_metadata']['repair_count'] ?? null,
        ],
        'issues' => $issues,
        'normalized_plan' => $normalized,
        'generation_metadata' => $finalPlan['_generation_metadata'] ?? [],
        'plan' => $finalPlan,
    ];
}

$options = getopt('', ['user-ids::', 'fixture::', 'case-names::', 'mode::', 'save-dir::']);
$userIdsRaw = $options['user-ids'] ?? '';
$fixtureName = $options['fixture'] ?? '';
if ($userIdsRaw === '' && $fixtureName === '') {
    evalUsage();
    exit(1);
}

$mode = $options['mode'] ?? 'first-pass';
if (!in_array($mode, ['first-pass', 'full'], true)) {
    fwrite(STDERR, "Unsupported mode: {$mode}\n");
    exit(1);
}

$saveDir = $options['save-dir'] ?? ($baseDir . '/tmp/eval_artifacts');
if (!is_dir($saveDir) && !mkdir($saveDir, 0775, true) && !is_dir($saveDir)) {
    fwrite(STDERR, "Cannot create save dir: {$saveDir}\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$userIds = [];
$fixtureCases = [];
if ($fixtureName !== '') {
    $fixtureCases = evalLoadFixtureCases($fixtureName, $options['case-names'] ?? null);
    if (empty($fixtureCases)) {
        fwrite(STDERR, "No fixture cases selected\n");
        exit(1);
    }
} else {
    $userIds = evalParseUserIds($userIdsRaw);
    if (empty($userIds)) {
        fwrite(STDERR, "No valid user IDs provided\n");
        exit(1);
    }
}

$results = [];
if (!empty($fixtureCases)) {
    foreach ($fixtureCases as $case) {
        $caseName = (string) ($case['name'] ?? 'unnamed_case');
        $startedAt = microtime(true);

        try {
            $artifact = $mode === 'full'
                ? evalBuildSyntheticFullArtifact($db, $case)
                : evalBuildSyntheticFirstPassArtifact($db, $case);

            $elapsedSec = round(microtime(true) - $startedAt, 2);
            $artifact['summary']['elapsed_sec'] = $elapsedSec;
            $artifact['summary']['status'] = 'ok';

            $results[] = [
                'case_name' => $caseName,
                'status' => 'ok',
                'elapsed_sec' => $elapsedSec,
                'weeks' => $artifact['summary']['weeks'] ?? null,
                'issue_score' => $artifact['summary']['issue_score'] ?? null,
                'issue_count' => $artifact['summary']['issue_count'] ?? null,
                'issue_codes' => $artifact['summary']['issue_codes'] ?? [],
            ];

            file_put_contents(
                rtrim($saveDir, '/') . "/case_{$caseName}.json",
                json_encode($artifact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            echo "[OK] case={$caseName} mode={$mode} elapsed={$elapsedSec}s issues=" . ($artifact['summary']['issue_count'] ?? 0) . " score=" . ($artifact['summary']['issue_score'] ?? 'n/a') . PHP_EOL;
        } catch (Throwable $e) {
            $elapsedSec = round(microtime(true) - $startedAt, 2);
            $errorArtifact = [
                'case' => ['name' => $caseName],
                'summary' => [
                    'mode' => $mode,
                    'elapsed_sec' => $elapsedSec,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ],
            ];

            $results[] = [
                'case_name' => $caseName,
                'status' => 'error',
                'elapsed_sec' => $elapsedSec,
                'weeks' => null,
                'issue_score' => null,
                'issue_count' => null,
                'issue_codes' => [],
                'error' => $e->getMessage(),
            ];

            file_put_contents(
                rtrim($saveDir, '/') . "/case_{$caseName}.json",
                json_encode($errorArtifact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            echo "[ERR] case={$caseName} mode={$mode} elapsed={$elapsedSec}s error=" . $e->getMessage() . PHP_EOL;
        }
    }
} else {
foreach ($userIds as $userId) {
    $startedAt = microtime(true);
    $artifact = null;

    try {
        $artifact = $mode === 'full'
            ? evalBuildFullArtifact($userId)
            : evalBuildFirstPassArtifact($db, $userId);

        $elapsedSec = round(microtime(true) - $startedAt, 2);
        $artifact['summary']['elapsed_sec'] = $elapsedSec;
        $artifact['summary']['status'] = 'ok';
        $artifact['user']['id'] = $userId;

        $results[] = [
            'user_id' => $userId,
            'status' => 'ok',
            'elapsed_sec' => $elapsedSec,
            'weeks' => $artifact['summary']['weeks'] ?? null,
            'issue_score' => $artifact['summary']['issue_score'] ?? null,
            'issue_count' => $artifact['summary']['issue_count'] ?? null,
            'issue_codes' => $artifact['summary']['issue_codes'] ?? [],
        ];

        file_put_contents(
            rtrim($saveDir, '/') . "/user_{$userId}.json",
            json_encode($artifact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        echo "[OK] user={$userId} mode={$mode} elapsed={$elapsedSec}s issues=" . ($artifact['summary']['issue_count'] ?? 0) . " score=" . ($artifact['summary']['issue_score'] ?? 'n/a') . PHP_EOL;
    } catch (Throwable $e) {
        $elapsedSec = round(microtime(true) - $startedAt, 2);
        $errorArtifact = [
            'user' => ['id' => $userId],
            'summary' => [
                'mode' => $mode,
                'elapsed_sec' => $elapsedSec,
                'status' => 'error',
                'error' => $e->getMessage(),
            ],
        ];

        $results[] = [
            'user_id' => $userId,
            'status' => 'error',
            'elapsed_sec' => $elapsedSec,
            'weeks' => null,
            'issue_score' => null,
            'issue_count' => null,
            'issue_codes' => [],
            'error' => $e->getMessage(),
        ];

        file_put_contents(
            rtrim($saveDir, '/') . "/user_{$userId}.json",
            json_encode($errorArtifact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        echo "[ERR] user={$userId} mode={$mode} elapsed={$elapsedSec}s error=" . $e->getMessage() . PHP_EOL;
    }
}
}

$summaryPath = rtrim($saveDir, '/') . '/summary.json';
file_put_contents(
    $summaryPath,
    json_encode(
        [
            'generated_at_utc' => gmdate('c'),
            'mode' => $mode,
            'user_ids' => $userIds,
            'fixture' => $fixtureName !== '' ? $fixtureName : null,
            'case_names' => !empty($fixtureCases) ? array_map(static fn(array $case): string => (string) ($case['name'] ?? 'unnamed_case'), $fixtureCases) : [],
            'results' => $results,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    )
);

echo PHP_EOL . "Summary saved to {$summaryPath}" . PHP_EOL;
