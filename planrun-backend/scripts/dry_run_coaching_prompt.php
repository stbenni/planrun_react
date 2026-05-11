#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * PR8 (Phase E coaching prompt v4) dry-run smoke-test.
 *
 * Собирает FACTS_JSON и coaching-промпт для одного пользователя БЕЗ вызова DeepSeek API.
 * Печатает ключевые проверки:
 *   - размеры промптов (system + user)
 *   - выбор модели (resolveModelSelection — должна быть thinking_always)
 *   - наличие race_proximity маркеров на критичных датах
 *   - recent_compliance_summary
 *   - load_policy.peak_volume_floor_km
 *
 * Usage:
 *   php scripts/dry_run_coaching_prompt.php --user=st_benni
 *   php scripts/dry_run_coaching_prompt.php --user=1 --job-type=recalculate
 *   php scripts/dry_run_coaching_prompt.php --user=st_benni --job-type=recalculate --cutoff-date=2026-05-08
 */

set_time_limit(0);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/planrun_ai/llm_planner/DeepSeekPlanPlanner.php';

function dryParseArgs(array $argv): array {
    $args = [
        'user' => 'st_benni',
        'job-type' => 'recalculate',
        'cutoff-date' => '',
        'show-prompt' => '0',
        'show-facts' => '0',
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

function dryResolveUser(mysqli $db, string $userArg): array {
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

$args = dryParseArgs($argv);
$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$user = dryResolveUser($db, (string) $args['user']);
if ($user === []) {
    fwrite(STDERR, "User not found: {$args['user']}\n");
    exit(1);
}
$userId = (int) $user['id'];

echo "=== PR8 / Phase E coaching prompt v4 — DRY RUN ===\n";
echo "User: {$user['username']} (id={$userId})\n";
echo "  goal_type={$user['goal_type']}, race_distance=" . ($user['race_distance'] ?? '-') . "\n";
echo "  race_date=" . ($user['race_date'] ?? '-') . "\n";
echo "  weekly_base_km=" . ($user['weekly_base_km'] ?? '-') . ", sessions_per_week=" . ($user['sessions_per_week'] ?? '-') . "\n";
echo "  job_type={$args['job-type']}\n";
echo "  PLAN_LLM_THINKING_ALWAYS=" . (env('PLAN_LLM_THINKING_ALWAYS', '1')) . "\n";
echo "\n";

$payload = [];
if ($args['job-type'] === 'recalculate') {
    $payload['cutoff_date'] = $args['cutoff-date'] !== ''
        ? (string) $args['cutoff-date']
        : (new DateTimeImmutable('now'))->format('Y-m-d');
    $payload['reason'] = 'phase_e_dry_run_smoke_test';
}

$planner = new DeepSeekPlanPlanner($db);
$ref = new ReflectionClass($planner);

// 1. Сбор state + context через приватные методы
$loadUser = $ref->getMethod('loadUser');
$loadUser->setAccessible(true);
$fullUser = $loadUser->invoke($planner, $userId);

$buildPlanningUser = $ref->getMethod('buildPlanningUser');
$buildPlanningUser->setAccessible(true);
$planningUser = $buildPlanningUser->invoke($planner, $fullUser, $args['job-type'], $payload);

$resolveStartDate = $ref->getMethod('resolveStartDate');
$resolveStartDate->setAccessible(true);
$startDate = $resolveStartDate->invoke($planner, $fullUser, $args['job-type'], $payload);

$state = (new TrainingStateBuilder($db))->buildForUser(
    $planningUser,
    $args['job-type'] === 'generate' ? 'generate' : $args['job-type'],
    $payload
);

$resolveWeeksCount = $ref->getMethod('resolveWeeksCount');
$resolveWeeksCount->setAccessible(true);
$weeksCount = $resolveWeeksCount->invoke($planner, $fullUser, $state, $args['job-type'], $startDate, $payload);

$buildPlannerContext = $ref->getMethod('buildPlannerContext');
$buildPlannerContext->setAccessible(true);
$context = $buildPlannerContext->invoke($planner, $fullUser, $state, $payload, $args['job-type'], $startDate, $weeksCount);

echo "=== STATE / CONTEXT ===\n";
echo "  start_date={$startDate}, weeks_count={$weeksCount}\n";
echo "  vdot={$state['vdot']} (source={$state['vdot_source']}, conf={$state['vdot_confidence']})\n";
echo "  readiness={$state['readiness']}\n";
echo "  weeks_to_goal=" . ($state['weeks_to_goal'] ?? '-') . "\n";
echo "  goal_pace=" . ($state['goal_pace'] ?? '-') . "\n";

if (!empty($state['intermediate_races'])) {
    echo "  intermediate_races: ";
    foreach ($state['intermediate_races'] as $ir) {
        echo "[{$ir['date']} " . ($ir['distance'] ?? '?') . "] ";
    }
    echo "\n";
}
echo "\n";

// 2. PR-A: recent_compliance_summary + peak_volume_floor_km
echo "=== PR-A: COACH SUMMARIES ===\n";
echo "  recent_compliance_summary:\n";
echo "    " . ($state['recent_compliance_summary'] ?? '<отсутствует>') . "\n";
$peakFloor = $state['load_policy']['peak_volume_floor_km'] ?? null;
echo "  load_policy.peak_volume_floor_km: " . ($peakFloor !== null ? "{$peakFloor} км" : '<отсутствует>') . "\n";

if (!empty($state['recent_compliance'])) {
    echo "  recent_compliance (last 4 weeks):\n";
    foreach ($state['recent_compliance'] as $w) {
        echo sprintf(
            "    %s..%s: planned=%d, completed=%d, actual=%.1f км, key=%d/%d\n",
            $w['week_start'] ?? '?',
            $w['week_end'] ?? '?',
            (int) ($w['planned_count'] ?? 0),
            (int) ($w['completed_count'] ?? 0),
            (float) ($w['actual_km'] ?? 0),
            (int) ($w['key_workout_completed'] ?? 0),
            (int) ($w['key_workout_planned'] ?? 0)
        );
    }
}
echo "\n";

// 3. PR-B: race_proximity маркеры
echo "=== PR-B: RACE_PROXIMITY СЕМАНТИКА ===\n";
$calendarWeeks = $context['calendar_weeks'] ?? [];
$proximityCounts = [];
$flaggedDays = [];
foreach ($calendarWeeks as $week) {
    foreach ((array) ($week['days'] ?? []) as $day) {
        $rp = $day['race_proximity'] ?? null;
        if ($rp !== null) {
            $proximityCounts[$rp] = ($proximityCounts[$rp] ?? 0) + 1;
            $flaggedDays[] = $day;
        }
    }
}
echo "  Marker counts: " . json_encode($proximityCounts) . "\n";
echo "  Flagged days:\n";
foreach ($flaggedDays as $d) {
    echo sprintf(
        "    %s (dow=%d) days_to_race=%s race=%s intermediate=%s -> %s\n",
        $d['date'] ?? '?',
        (int) ($d['day_of_week'] ?? 0),
        var_export($d['days_to_race'] ?? null, true),
        var_export($d['is_race_date'] ?? false, true),
        var_export($d['is_intermediate_race'] ?? false, true),
        $d['race_proximity']
    );
}
echo "\n";

// PR9: pace_strategy — мост к цели
echo "=== PR9: PACE_STRATEGY (мост к цели) ===\n";
$strategy = $state['pace_strategy'] ?? null;
if (is_array($strategy)) {
    echo "  mode: {$strategy['mode']}\n";
    echo "  severity: {$strategy['severity']}\n";
    echo "  goal_target_time: " . ($strategy['goal_target_time'] ?? '-') . "\n";
    echo "  predicted_target_time: " . ($strategy['predicted_target_time'] ?? '-') . "\n";
    echo "  effective_target_time: " . ($strategy['effective_target_time'] ?? '-') . "\n";
    echo "  effective_target_pace: " . ($strategy['effective_target_pace'] ?? '-') . "\n";
    echo "  gap_pct: " . var_export($strategy['gap_pct'] ?? null, true) . "\n";
    echo "  goal_paces (для effective target):\n";
    foreach (($strategy['goal_paces'] ?? []) as $k => $v) {
        echo "    {$k}: {$v}\n";
    }
    echo "  current_paces (текущая форма):\n";
    foreach (($strategy['current_paces'] ?? []) as $k => $v) {
        echo "    {$k}: {$v}\n";
    }
} else {
    echo "  <отсутствует — goal не race-типа или нет race_target_time>\n";
}
echo "\n";

// 4. PR-C: модель и thinking
echo "=== PR-C: MODEL SELECTION (thinking_always) ===\n";
$selection = $planner->resolveModelSelection($state);
echo "  model={$selection['model']}\n";
echo "  enable_thinking=" . var_export($selection['enable_thinking'], true) . "\n";
echo "  reason={$selection['reason']}\n";
echo "  complexity_score={$selection['score']}\n";
echo "  timeout_seconds={$selection['timeout_seconds']}\n";
echo "\n";

// 5. PR-C: размеры промптов
echo "=== PR-C: PROMPT SIZE (coaching v4) ===\n";
$buildSystemPrompt = $ref->getMethod('buildSystemPrompt');
$buildSystemPrompt->setAccessible(true);
$systemPrompt = $buildSystemPrompt->invoke($planner);

$buildFullPlanPrompt = $ref->getMethod('buildFullPlanPrompt');
$buildFullPlanPrompt->setAccessible(true);
$fullPlanPrompt = $buildFullPlanPrompt->invoke($planner, $context);

$systemLen = mb_strlen($systemPrompt);
$fullLen = mb_strlen($fullPlanPrompt);
$factsJsonStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$factsLen = mb_strlen((string) $factsJsonStr);
$promptWithoutFacts = preg_replace('/FACTS_JSON:\s*.*?\nСегодня:.+$/s', '', $fullPlanPrompt) ?? '';
$promptShellLen = mb_strlen($promptWithoutFacts);

echo "  system prompt: {$systemLen} chars\n";
echo "  full user prompt: {$fullLen} chars (≈" . (int) round($fullLen / 4) . " tokens)\n";
echo "    coaching shell (without FACTS_JSON): {$promptShellLen} chars\n";
echo "    FACTS_JSON: {$factsLen} chars\n";
$promptVersion = (string) env('PLAN_LLM_PROMPT_VERSION', '');
echo "  prompt_version (in metadata): deepseek_llm_planner_v4_coaching\n";
echo "\n";

// 6. Sanity: проверяем что prose-инструкции отсутствуют, формат — есть
echo "=== PR-C: PROMPT SANITY ===\n";
$sanityChecks = [
    'формат plan_summary' => str_contains($fullPlanPrompt, 'plan_summary'),
    'calendar_weeks упомянут' => str_contains($fullPlanPrompt, 'calendar_weeks'),
    'race_proximity упомянут' => str_contains($fullPlanPrompt, 'race_proximity'),
    'recovery weeks упомянут' => str_contains($fullPlanPrompt, 'Recovery weeks'),
    'long_run_safety упомянут' => str_contains($fullPlanPrompt, 'long_run_safety'),
    'Нет prose про "compliance 60-89%"' => !str_contains($fullPlanPrompt, 'compliance 60-89'),
    'Нет prose про SANITY-FLOOR' => !str_contains($fullPlanPrompt, 'SANITY-FLOOR'),
    'Нет prose про "MAX(recent_compliance.actual_km)"' => !str_contains($fullPlanPrompt, 'MAX(recent_compliance.actual_km)'),
    'Нет prose про "Климат и сезон"' => !str_contains($fullPlanPrompt, 'Климат и сезон'),
    'Нет prose про "Сценарии и goal_realism"' => !str_contains($fullPlanPrompt, 'Сценарии и goal_realism'),
    'Системный промпт компактный (≤1500 chars)' => $systemLen <= 1500,
    'Coaching shell компактен (≤6000 chars без FACTS_JSON)' => $promptShellLen <= 6000,
    // PR9: pace_strategy и MP-runs hard-rule
    'pace_strategy упомянут в hard-rules' => str_contains($fullPlanPrompt, 'pace_strategy'),
    'goal_paces упомянут в hard-rules' => str_contains($fullPlanPrompt, 'goal_paces'),
    'Marathon-specific rule про MP-runs' => str_contains($fullPlanPrompt, 'Marathon-specific'),
];
$failed = 0;
foreach ($sanityChecks as $name => $ok) {
    echo "  " . ($ok ? 'OK   ' : 'FAIL ') . "{$name}\n";
    if (!$ok) $failed++;
}
echo "\n";

// 7. Опционально печатаем промпт целиком
if ($args['show-prompt'] === '1') {
    echo "=== SYSTEM PROMPT ===\n{$systemPrompt}\n\n";
    echo "=== FULL PLAN PROMPT ===\n{$fullPlanPrompt}\n\n";
}
if ($args['show-facts'] === '1') {
    echo "=== FACTS_JSON ===\n{$factsJsonStr}\n\n";
}

echo "=== РЕЗУЛЬТАТ ===\n";
if ($failed > 0) {
    echo "  FAILED: {$failed} проверка/проверки не прошли. Pipeline нужно править.\n";
    exit(1);
}
echo "  OK: pipeline coaching prompt v4 собирается корректно для {$user['username']}.\n";
echo "  Чтобы реально проверить план — regen через UI или live_recalculate_batch.php.\n";
exit(0);
