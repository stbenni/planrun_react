#!/usr/bin/env php
<?php
/**
 * Dry-run для WeeklyPlanAdaptationService — собирает данные, зовёт LLM, валидирует патч,
 * но НЕ применяет изменения и НЕ шлёт уведомление.
 *
 * Использование:
 *   php scripts/dry_run_weekly_adaptation.php <user_id>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WeeklyPlanAdaptationService.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/dry_run_weekly_adaptation.php <user_id>\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

// Используем рефлексию, чтобы дёрнуть приватные методы поэтапно.
$service = new WeeklyPlanAdaptationService($db);
$ref = new ReflectionClass($service);

$loadUser = $ref->getMethod('loadUser');
$loadUser->setAccessible(true);
$user = $loadUser->invoke($service, $userId);
if (!$user) {
    fwrite(STDERR, "User {$userId} not found\n");
    exit(1);
}
echo "USER: id={$user['id']} username={$user['username']} tz={$user['timezone']}\n";
if (!empty($user['race_date'])) {
    echo "  race: {$user['race_date']} ({$user['race_distance']}) target={$user['race_target_time']}\n";
}

$collect = $ref->getMethod('collectInputs');
$collect->setAccessible(true);
$inputs = $collect->invoke($service, $userId, $user);

echo "\nWEEK PLAN (" . count($inputs['week']['planned']) . " days), ACTUAL (" . count($inputs['week']['actual']) . " workouts)\n";
echo "  ACWR: " . round((float) ($inputs['acwr']['acwr'] ?? 0), 2) . " zone=" . ($inputs['acwr']['zone'] ?? 'n/a') . "\n";
echo "  Compliance: " . ($inputs['compliance']['completed'] ?? 0) . "/" . ($inputs['compliance']['planned'] ?? 0) . "\n";
echo "  Feedback rows: " . count($inputs['feedback']) . "\n";
echo "  Next week days: " . count($inputs['next_week_days']) . "\n";

if (empty($inputs['next_week_days'])) {
    echo "\nNo next-week plan — would skip.\n";
    exit(0);
}

$buildPrompt = $ref->getMethod('buildPrompt');
$buildPrompt->setAccessible(true);
$prompt = $buildPrompt->invoke($service, $inputs);
echo "\n--- PROMPT preview (first 1500 chars) ---\n";
echo mb_substr($prompt, 0, 1500) . "\n--- end ---\n";

echo "\nCalling LLM (this may take 5-30s)…\n";
$callLlm = $ref->getMethod('callLlm');
$callLlm->setAccessible(true);
$llmResponse = $callLlm->invoke($service, $userId, $inputs);

if ($llmResponse === null) {
    echo "LLM returned null. Aborting.\n";
    exit(1);
}

echo "\nLLM RESPONSE:\n" . json_encode($llmResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$changes = is_array($llmResponse['changes'] ?? null) ? $llmResponse['changes'] : [];
if (empty($changes)) {
    echo "\nNo changes proposed. Reason: " . ($llmResponse['no_changes_reason'] ?? '(none)') . "\n";
    exit(0);
}

$validate = $ref->getMethod('validatePatch');
$validate->setAccessible(true);
$validation = $validate->invoke($service, $changes, $inputs);

echo "\nVALIDATION: " . ($validation['valid'] ? 'PASS' : 'FAIL') . "\n";
if (!$validation['valid']) {
    echo "  Reason: " . ($validation['reason'] ?? 'n/a') . "\n";
    exit(0);
}

echo "  Accepted changes (" . count($validation['filtered_changes']) . "):\n";
foreach ($validation['filtered_changes'] as $c) {
    echo "    {$c['date']}: {$c['old_type']} → {$c['new_type']}\n";
    echo "      new_desc: " . mb_substr($c['new_description'], 0, 120) . "\n";
}

echo "\nDRY-RUN complete. No changes applied, no notification sent.\n";
