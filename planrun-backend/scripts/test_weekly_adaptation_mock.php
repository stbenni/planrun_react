#!/usr/bin/env php
<?php
/**
 * Тестовый прогон WeeklyPlanAdaptationService с замоканным LLM-ответом —
 * проверяет валидатор + apply + notification + audit без реального вызова DeepSeek.
 *
 * Usage: php scripts/test_weekly_adaptation_mock.php <user_id> <yyyy-mm-dd> <new_type> [new_desc]
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/WeeklyPlanAdaptationService.php';

$userId = (int) ($argv[1] ?? 0);
$date = (string) ($argv[2] ?? '');
$newType = (string) ($argv[3] ?? '');
$newDesc = (string) ($argv[4] ?? "Тестовое изменение от mock-теста: {$newType}");

if ($userId <= 0 || $date === '' || $newType === '') {
    fwrite(STDERR, "Usage: php scripts/test_weekly_adaptation_mock.php <user_id> <yyyy-mm-dd> <new_type> [new_desc]\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$service = new WeeklyPlanAdaptationService($db);
$ref = new ReflectionClass($service);

$loadUser = $ref->getMethod('loadUser'); $loadUser->setAccessible(true);
$collect  = $ref->getMethod('collectInputs'); $collect->setAccessible(true);
$validate = $ref->getMethod('validatePatch'); $validate->setAccessible(true);
$apply    = $ref->getMethod('applyPatch'); $apply->setAccessible(true);
$notify   = $ref->getMethod('notifyUser'); $notify->setAccessible(true);
$record   = $ref->getMethod('recordCooldown'); $record->setAccessible(true);

$user = $loadUser->invoke($service, $userId);
if (!$user) { fwrite(STDERR, "User not found\n"); exit(1); }

$inputs = $collect->invoke($service, $userId, $user);

$mockResponse = [
    'summary' => "Тест mock-адаптации для {$date}: меняем на {$newType}.",
    'changes' => [
        ['date' => $date, 'action' => 'change_type', 'new_type' => $newType, 'new_description' => $newDesc],
    ],
];

echo "=== MOCK LLM RESPONSE ===\n" . json_encode($mockResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$validation = $validate->invoke($service, $mockResponse['changes'], $inputs);
echo "=== VALIDATION: " . ($validation['valid'] ? 'PASS' : 'FAIL ' . ($validation['reason'] ?? '')) . " ===\n";
if (!$validation['valid']) exit(1);

$applied = $apply->invoke($service, $userId, $validation['filtered_changes']);
echo "=== APPLIED: {$applied} changes ===\n";

$notify->invoke($service, $userId, $mockResponse['summary']);
echo "=== NOTIFICATION dispatched ===\n";

$record->invoke($service, $userId);
echo "=== Cooldown recorded ===\n";

echo "\nDone.\n";
