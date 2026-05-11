#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * PR8 (Phase E coaching prompt v4) live regression for a single user.
 *
 * Запускает РЕАЛЬНУЮ генерацию плана через DeepSeek (PLAN_GENERATION_MODE=llm_planner)
 * для одного пользователя, печатает race-week срез и сохраняет полный результат в файл.
 *
 * Чтобы НЕ затронуть существующий план в БД, используем DeepSeekPlanPlanner::generate()
 * напрямую (он только генерирует weeks_data, БД не трогает).
 *
 * Usage:
 *   php scripts/live_generate_one_user.php --user=st_benni --job-type=recalculate
 *   php scripts/live_generate_one_user.php --user=1 --job-type=generate
 *   php scripts/live_generate_one_user.php --user=st_benni --cutoff-date=2026-05-08
 */

set_time_limit(0);
ini_set('memory_limit', '1024M');

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/planrun_ai/llm_planner/DeepSeekPlanPlanner.php';

function liveGenParseArgs(array $argv): array {
    $args = [
        'user' => 'st_benni',
        'job-type' => 'recalculate',
        'cutoff-date' => '',
        'save-dir' => '',
    ];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) continue;
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0] ?? '';
        $value = $parts[1] ?? '1';
        if ($key !== '') $args[$key] = $value;
    }
    return $args;
}

function liveGenResolveUser(mysqli $db, string $userArg): array {
    $stmt = $db->prepare(
        ctype_digit($userArg)
            ? 'SELECT * FROM users WHERE id = ? LIMIT 1'
            : 'SELECT * FROM users WHERE username = ? OR username_slug = ? LIMIT 1'
    );
    if (ctype_digit($userArg)) {
        $userId = (int) $userArg;
        $stmt->bind_param('i', $userId);
    } else {
        $stmt->bind_param('ss', $userArg, $userArg);
    }
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $user;
}

$args = liveGenParseArgs($argv);
$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$user = liveGenResolveUser($db, (string) $args['user']);
if ($user === []) {
    fwrite(STDERR, "User not found: {$args['user']}\n");
    exit(1);
}
$userId = (int) $user['id'];

$payload = [];
if ($args['job-type'] === 'recalculate') {
    $payload['cutoff_date'] = $args['cutoff-date'] !== ''
        ? (string) $args['cutoff-date']
        : (new DateTimeImmutable('now'))->format('Y-m-d');
    $payload['reason'] = 'pr8_phase_e_live_test';
}

$saveDir = (string) ($args['save-dir'] ?? '');
if ($saveDir === '') {
    $saveDir = $baseDir . '/tmp/pr8_live_generation';
}
if (!is_dir($saveDir) && !mkdir($saveDir, 0775, true) && !is_dir($saveDir)) {
    fwrite(STDERR, "Cannot create save dir: {$saveDir}\n");
    exit(1);
}

$timestamp = (new DateTimeImmutable('now'))->format('Ymd_His');
$outFile = sprintf('%s/%s_%s_%s.json', $saveDir, $user['username'], $args['job-type'], $timestamp);

echo "=== PR8 LIVE: DeepSeek generation ===\n";
echo "User: {$user['username']} (id={$userId})\n";
echo "  goal_type={$user['goal_type']}, race_distance=" . ($user['race_distance'] ?? '-') . "\n";
echo "  race_date=" . ($user['race_date'] ?? '-') . "\n";
echo "  job_type={$args['job-type']}, cutoff_date=" . ($payload['cutoff_date'] ?? '-') . "\n";
echo "  PLAN_LLM_MODEL=" . env('PLAN_LLM_MODEL', 'deepseek-chat') . "\n";
echo "  PLAN_LLM_REASONER_MODEL=" . env('PLAN_LLM_REASONER_MODEL', 'deepseek-reasoner') . "\n";
echo "  PLAN_LLM_THINKING_ALWAYS=" . env('PLAN_LLM_THINKING_ALWAYS', '1') . "\n";
echo "  PLAN_LLM_BASE_URL=" . env('PLAN_LLM_BASE_URL', 'https://api.deepseek.com') . "\n";
echo "\n";

$planner = new DeepSeekPlanPlanner($db);
$started = microtime(true);
echo "Calling DeepSeek (это может занять 60-300 секунд для reasoner+thinking)...\n";

try {
    $result = $planner->generate($userId, $args['job-type'], $payload);
} catch (Throwable $e) {
    $duration = microtime(true) - $started;
    echo sprintf("\n!!! DeepSeek FAILED after %.1fs: %s\n", $duration, $e->getMessage());
    echo "Trace (first 1500 chars):\n";
    echo substr($e->getTraceAsString(), 0, 1500) . "\n";
    exit(2);
}

$duration = microtime(true) - $started;
echo sprintf("OK: DeepSeek returned in %.1fs\n", $duration);
echo "\n";

$weeksData = (array) ($result['weeks_data'] ?? []);
$metadata = (array) ($result['_generation_metadata'] ?? []);

echo "=== METADATA ===\n";
echo "  prompt_version: " . ($metadata['prompt_version'] ?? '-') . "\n";
echo "  model: " . ($metadata['model'] ?? '-') . "\n";
echo "  enable_thinking: " . var_export($metadata['enable_thinking'] ?? null, true) . "\n";
echo "  model_selection_reason: " . ($metadata['model_selection_reason'] ?? '-') . "\n";
echo "  llm_duration_ms: " . ($metadata['llm_duration_ms'] ?? '-') . "\n";
echo "  prompt_tokens: " . ($metadata['prompt_tokens'] ?? '-') . "\n";
echo "  completion_tokens: " . ($metadata['completion_tokens'] ?? '-') . "\n";
echo "  reasoning_tokens: " . ($metadata['reasoning_tokens'] ?? '-') . "\n";
if (isset($metadata['quality_gate'])) {
    $qg = $metadata['quality_gate'];
    echo "  quality_gate.mode: " . ($qg['mode'] ?? '-') . "\n";
    echo "  quality_gate.passed: " . var_export($qg['passed'] ?? null, true) . "\n";
    echo "  quality_gate.issue_codes: " . json_encode($qg['issue_codes'] ?? []) . "\n";
}
echo "\n";

// Race-week срез: ищем дату 2026-05-15..2026-05-19 для st_benni
echo "=== RACE-WEEK FOCUS (2026-05-15 → 2026-05-20) ===\n";
$intermediateRaceDate = '2026-05-16';
$found = false;
foreach ($weeksData as $week) {
    $days = (array) ($week['days'] ?? []);
    foreach ($days as $day) {
        $date = (string) ($day['date'] ?? '');
        if ($date >= '2026-05-13' && $date <= '2026-05-21') {
            $found = true;
            $type = $day['type'] ?? $day['activity_type'] ?? '?';
            $desc = $day['description'] ?? '-';
            $km = $day['planned_distance_km'] ?? $day['distance_km'] ?? $day['target_km'] ?? '?';
            echo sprintf(
                "  W%d D%d %s: type=%s, km=%s\n    %s\n",
                (int) ($week['week_number'] ?? 0),
                (int) ($day['day_of_week'] ?? 0),
                $date,
                $type,
                is_numeric($km) ? round((float) $km, 1) : $km,
                mb_substr((string) $desc, 0, 200)
            );
        }
    }
}
if (!$found) {
    echo "  (race-week дни не найдены — план короче или другой start_date)\n";
}
echo "\n";

// Plan summary (если модель его вернула)
if (isset($result['plan_summary'])) {
    echo "=== PLAN SUMMARY ===\n";
    echo "  " . wordwrap((string) $result['plan_summary'], 100, "\n  ", true) . "\n\n";
}

// Все недели — общий обзор
echo "=== ALL WEEKS OVERVIEW ===\n";
foreach ($weeksData as $week) {
    $weekNum = (int) ($week['week_number'] ?? 0);
    $startDate = (string) ($week['start_date'] ?? '');
    $totalKm = 0.0;
    $longKm = 0.0;
    $keyTypes = [];
    foreach ((array) ($week['days'] ?? []) as $day) {
        $km = (float) ($day['planned_distance_km'] ?? $day['distance_km'] ?? $day['target_km'] ?? 0);
        $totalKm += $km;
        if (in_array(($day['type'] ?? ''), ['long'], true)) $longKm = max($longKm, $km);
        $type = (string) ($day['type'] ?? '');
        if (in_array($type, ['race', 'tempo', 'interval', 'fartlek', 'long', 'race_pace'], true)) {
            $keyTypes[] = $type;
        }
    }
    echo sprintf(
        "  W%d (%s) total=%.1f km, long=%.1f km, key=[%s]\n",
        $weekNum,
        $startDate,
        $totalKm,
        $longKm,
        implode(',', array_unique($keyTypes))
    );
}
echo "\n";

// Сохраняем полный результат
file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Full result saved to: {$outFile}\n";
echo sprintf("File size: %.1f KB\n", filesize($outFile) / 1024);
exit(0);
