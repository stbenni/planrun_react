#!/usr/bin/env php
<?php
/**
 * Dry-run: собирает prompt для recalculate БЕЗ вызова LLM,
 * чтобы посмотреть какой контекст уйдёт в DeepSeek (включая plan_history_rollup,
 * key_workouts, intermediate_races и т.п.).
 *
 * Usage: php scripts/dry_run_recalculate_prompt.php <user_id>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/planrun_ai/planrun_ai_config.php';
require_once $baseDir . '/planrun_ai/plan_generator.php';
require_once $baseDir . '/planrun_ai/prompt_builder.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/dry_run_recalculate_prompt.php <user_id>\n");
    exit(1);
}

// Перехватываем callAIAPI — заменим заглушкой чтобы не было реального LLM-вызова.
if (!function_exists('callAIAPI_overridden')) {
    eval('function callAIAPI_overridden($prompt, $user, $retries, $userId) {
        echo "═════════════════════════════════════════════════════\n";
        echo "PROMPT для пользователя {$userId}, длина: " . strlen($prompt) . " симв.\n";
        echo "═════════════════════════════════════════════════════\n";
        echo $prompt . "\n";
        echo "═════════════════════════════════════════════════════\n";
        echo "END OF PROMPT\n";
        echo "═════════════════════════════════════════════════════\n";
        exit(0);
    }');
}

// Принудительно перенаправляем callAIAPI в нашу заглушку.
// Поскольку функция callAIAPI определена через require, нельзя её просто override.
// Альтернатива: моделируем через uopz если есть, или просто через захват SIGTERM в скрипте.

// Простейший способ: перехватим через прокси-функцию.
// Поскольку recalculatePlanViaPlanRunAI напрямую зовёт callAIAPI(), нам нужно либо
// модифицировать функцию (плохо), либо обернуть в стрейтеджи. Я просто перенесу
// логику buildRecalculationPrompt + buildRecalcContextBlock здесь.

// Симуляция: достаём user данные, строим recalcContext как в plan_generator.php и вызываем buildRecalculationPrompt.
$db = getDBConnection();

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { echo "User not found\n"; exit(1); }

if (!empty($user['preferred_days'])) {
    $user['preferred_days'] = json_decode($user['preferred_days'], true) ?: [];
} else {
    $user['preferred_days'] = [];
}
if (!empty($user['preferred_ofp_days'])) {
    $user['preferred_ofp_days'] = json_decode($user['preferred_ofp_days'], true) ?: [];
} else {
    $user['preferred_ofp_days'] = [];
}

$goalType = $user['goal_type'] ?? 'health';
$cutoffDate = (new DateTime())->modify('monday this week')->format('Y-m-d');

require_once $baseDir . '/repositories/WeekRepository.php';
$weekRepo = new WeekRepository($db);
$keptWeeks = $weekRepo->getMaxWeekNumberBefore($userId, $cutoffDate);

$totalPlanWeeks = function_exists('getSuggestedPlanWeeks') ? (getSuggestedPlanWeeks($user, $goalType) ?? 12) : 12;
$weeksToGenerate = max(1, $totalPlanWeeks - $keptWeeks);

$goalDate = $user['race_date'] ?? $user['target_marathon_date'] ?? $user['weight_goal_date'] ?? null;
if ($goalDate) {
    $goalTs = strtotime($goalDate);
    $cutoffTs = strtotime($cutoffDate);
    if ($goalTs > $cutoffTs) {
        $weeksToGoal = (int) max(1, ceil(($goalTs - $cutoffTs) / (7 * 86400)));
        $weeksToGenerate = $weeksToGoal;
    }
}
$weeksToGenerate = min($weeksToGenerate, 30);

require_once $baseDir . '/services/ChatContextBuilder.php';
require_once $baseDir . '/services/WorkoutAnalysisRepository.php';
$ctxBuilder = new ChatContextBuilder($db);
$repo = new WorkoutAnalysisRepository($db);

// Minimal context (для preview, не идентичен полному, но достаточно для проверки plan_history полей)
$recalcContext = [
    'days_since_last_workout' => 2,
    'detraining_factor' => 1.0,
    'current_week_number' => 2,
    'total_plan_weeks' => $totalPlanWeeks,
    'kept_weeks' => $keptWeeks,
    'weeks_to_generate' => $weeksToGenerate,
    'compliance_2w' => ['planned' => 6, 'completed' => 5, 'pct' => 83],
    'avg_weekly_km_4w' => 28,
    'avg_pace_4w' => '5:20',
    'avg_hr_4w' => 168,
    'avg_rating_4w' => 5,
    'recent_workouts' => [],
    'new_start_date' => $cutoffDate,
    'user_reason' => 'dry-run preview',
    'kept_weeks_summary' => [],
    'max_planned_long_km' => 0,
    'max_planned_volume_km' => 0,
    'best_actual_long_km' => 42.69,
    'current_phase' => null,
    'acwr' => $ctxBuilder->calculateACWR($userId),
    'last_plan_weeks' => [],
    'plan_history_lines' => $repo->getSummaryLinesForActivePlan($userId),
    'plan_history_rollup' => $repo->getWeeklyRollupForActivePlan($userId),
    'plan_key_workouts' => $repo->getKeyWorkoutSummaryForActivePlan($userId),
];

$prompt = buildRecalculationPrompt($user, $goalType, $recalcContext);
echo "PROMPT LENGTH: " . strlen($prompt) . " chars\n\n";

echo $prompt;
