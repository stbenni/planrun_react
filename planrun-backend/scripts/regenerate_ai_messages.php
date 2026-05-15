#!/usr/bin/env php
<?php
/**
 * Принудительная регенерация всех AI-сообщений для пользователя.
 * Сбрасывает cooldown и заново вызывает:
 *  - daily_briefing
 *  - weekly_digest
 *  - workout_analysis для последних N тренировок (workout_log + workouts)
 *
 * Использование:
 *   php scripts/regenerate_ai_messages.php <user_id> [N_workouts]
 *
 * По умолчанию обновляет 10 последних тренировок.
 */

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../db_config.php';

$userId = (int) ($argv[1] ?? 0);
$nWorkouts = (int) ($argv[2] ?? 10);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/regenerate_ai_messages.php <user_id> [n_workouts]\n");
    exit(1);
}

$db = getDBConnection();

echo "Regenerating AI messages for user_id={$userId} (last {$nWorkouts} workouts)\n";
echo "================================================================\n\n";

// ── 1. Сбрасываем cooldown ──
$db->query("DELETE FROM proactive_coach_log WHERE user_id = {$userId} AND event_type IN ('daily_briefing', 'weekly_digest', 'distance_record', 'vdot_improvement', 'volume_record', 'consistency_streak', 'overload', 'overload_warning', 'race_approaching', 'low_compliance', 'goal_achievable', 'pause')");
echo "[1/4] Cooldown reset for user_id={$userId}\n\n";

// ── 2. Daily briefing ──
echo "[2/4] Daily briefing...\n";
try {
    require_once __DIR__ . '/../services/ProactiveCoachService.php';
    $svc = new ProactiveCoachService($db);

    // ProactiveCoachService::processDailyBriefings перебирает всех активных пользователей,
    // но для конкретного юзера нет публичного метода — вызовем напрямую через reflection.
    // Проще: временно пометим всех других как inactive… или используем processDailyBriefings и убедимся что cooldown сброшен только у этого юзера.

    $stats = $svc->processDailyBriefings();
    echo "  Daily briefing stats: sent={$stats['sent']}, skipped={$stats['skipped']}, errors={$stats['errors']}\n\n";
} catch (Throwable $e) {
    echo "  ERROR: {$e->getMessage()}\n\n";
}

// ── 3. Weekly digest ──
echo "[3/4] Weekly digest...\n";
try {
    $svc = new ProactiveCoachService($db);
    $stats = $svc->processWeeklyDigests();
    echo "  Weekly digest stats: sent={$stats['sent']}, skipped={$stats['skipped']}, errors={$stats['errors']}\n\n";
} catch (Throwable $e) {
    echo "  ERROR: {$e->getMessage()}\n\n";
}

// ── 4. Workout analyses ──
echo "[4/4] Workout analyses (last {$nWorkouts}):\n";

// Берём последние тренировки из workout_log (manual) и workouts (auto)
$rows = [];

$stmt = $db->prepare("
    SELECT id, training_date, distance_km, result_time, duration_minutes, avg_heart_rate, max_heart_rate, notes,
           'workout_log' AS source_kind
    FROM workout_log
    WHERE user_id = ? AND is_completed = 1 AND distance_km IS NOT NULL
    ORDER BY training_date DESC, id DESC
    LIMIT ?
");
$stmt->bind_param('ii', $userId, $nWorkouts);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

$stmt = $db->prepare("
    SELECT id, DATE(start_time) AS training_date, distance_km, duration_seconds, duration_minutes,
           avg_heart_rate, max_heart_rate,
           NULL AS notes, NULL AS result_time,
           'workout' AS source_kind
    FROM workouts
    WHERE user_id = ? AND distance_km IS NOT NULL
    ORDER BY start_time DESC, id DESC
    LIMIT ?
");
$stmt->bind_param('ii', $userId, $nWorkouts);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

// Дополнительно: уже существующие анализы — обновим всех, не ограничиваясь свежими импортами
$stmt = $db->prepare("
    SELECT source_kind, source_id, workout_date,
           actual_distance_km, actual_duration_min, actual_avg_pace, actual_avg_hr, actual_max_hr,
           planned_type, planned_description, planned_is_key
    FROM workout_analyses
    WHERE user_id = ?
    ORDER BY workout_date DESC, id DESC
    LIMIT ?
");
$stmt->bind_param('ii', $userId, $nWorkouts);
$stmt->execute();
$result = $stmt->get_result();
$existing = [];
while ($r = $result->fetch_assoc()) {
    $existing[$r['source_kind'] . '|' . $r['source_id']] = $r;
}
$stmt->close();

// Сортируем по дате, берём верхние N уникальных
usort($rows, fn($a, $b) => strcmp($b['training_date'], $a['training_date']));
$rows = array_slice($rows, 0, $nWorkouts);

// Дополняем недостающие из existing
foreach ($existing as $key => $ex) {
    $found = false;
    foreach ($rows as $r) {
        if ($r['source_kind'] . '|' . $r['id'] === $key) { $found = true; break; }
    }
    if ($found) continue;
    $rows[] = [
        'id' => (int) $ex['source_id'],
        'source_kind' => (string) $ex['source_kind'],
        'training_date' => (string) $ex['workout_date'],
        'distance_km' => $ex['actual_distance_km'] !== null ? (float) $ex['actual_distance_km'] : null,
        'duration_minutes' => $ex['actual_duration_min'] !== null ? (int) $ex['actual_duration_min'] : null,
        'result_time' => null,
        'avg_heart_rate' => $ex['actual_avg_hr'] !== null ? (int) $ex['actual_avg_hr'] : null,
        'max_heart_rate' => $ex['actual_max_hr'] !== null ? (int) $ex['actual_max_hr'] : null,
        'notes' => null,
    ];
}

require_once __DIR__ . '/../services/WorkoutService.php';
$workoutSvc = new WorkoutService($db);

// Используем reflection: persistWorkoutAnalysis и generatePostWorkoutAnalysisText — private.
// Поэтому регенерируем через публичный метод, который запускает оба:
$reflection = new ReflectionClass(WorkoutService::class);
$regenMethod = null;
foreach ($reflection->getMethods() as $m) {
    if ($m->getName() === 'regenerateAnalysisForWorkout') {
        $regenMethod = $m;
        break;
    }
}

if ($regenMethod === null) {
    echo "  WorkoutService::regenerateAnalysisForWorkout() не существует. ";
    echo "  Использую прямой вызов через reflection на private методы.\n";
}

$generateText = $reflection->getMethod('generatePostWorkoutAnalysisText');
$generateText->setAccessible(true);
$persistAnalysis = $reflection->getMethod('persistWorkoutAnalysis');
$persistAnalysis->setAccessible(true);
$loadPlannedDay = null;
foreach ($reflection->getMethods() as $m) {
    if (in_array($m->getName(), ['fetchPlannedDayForDate', 'getPlannedDayForDate', 'loadPlannedDayForDate'], true)) {
        $loadPlannedDay = $m;
        break;
    }
}

// Простая структура summary для регенерации (без structure из lap-данных)
foreach ($rows as $r) {
    // Пропускаем ОФП/СБУ-дни (нет distance) — для них LLM-разбор беговой не имеет смысла
    if (empty($r['distance_km']) || (float) $r['distance_km'] <= 0) {
        echo "  [{$r['training_date']}] skipped (нет дистанции — ОФП/СБУ)\n";
        continue;
    }

    $summary = [
        'workout_date' => (string) $r['training_date'],
        'activity_type' => 'running',
        'distance_km' => (float) $r['distance_km'],
        'avg_heart_rate' => isset($r['avg_heart_rate']) ? (int) $r['avg_heart_rate'] : null,
        'max_heart_rate' => isset($r['max_heart_rate']) ? (int) $r['max_heart_rate'] : null,
        'notes' => (string) ($r['notes'] ?? ''),
    ];
    if (!empty($r['duration_seconds'])) {
        $summary['duration_seconds'] = (int) $r['duration_seconds'];
        $summary['duration_minutes'] = (int) round((int) $r['duration_seconds'] / 60);
    } elseif (!empty($r['duration_minutes'])) {
        $summary['duration_minutes'] = (int) $r['duration_minutes'];
    }
    if (!empty($r['result_time'])) {
        $summary['pace'] = (string) $r['result_time'];
    }
    if (!empty($summary['distance_km']) && !empty($summary['duration_minutes'])) {
        $paceSec = (int) round(($summary['duration_minutes'] * 60) / $summary['distance_km']);
        $m = intdiv($paceSec, 60);
        $s = $paceSec % 60;
        $summary['pace'] = sprintf('%d:%02d', $m, $s);
    }

    // Загружаем планируемый тип на эту дату (если есть)
    $planStmt = $db->prepare(
        "SELECT d.type, d.description, d.is_key_workout
         FROM training_plan_days d
         INNER JOIN training_plan_weeks w ON w.id = d.week_id
         WHERE w.user_id = ? AND d.date = ?
         LIMIT 1"
    );
    $planStmt->bind_param('is', $userId, $summary['workout_date']);
    $planStmt->execute();
    $planned = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();

    $plannedArr = null;
    if ($planned) {
        $plannedArr = [
            'type' => (string) ($planned['type'] ?? ''),
            'description' => (string) ($planned['description'] ?? ''),
            'is_key_workout' => !empty($planned['is_key_workout']),
        ];
    }

    try {
        $llmText = $generateText->invoke($workoutSvc, $userId, $summary, $plannedArr, null);
        if (trim($llmText) === '') {
            echo "  [{$summary['workout_date']}] empty LLM response, skipping\n";
            continue;
        }
        $persistAnalysis->invoke($workoutSvc, $userId, $r['source_kind'], (int) $r['id'], $summary, $plannedArr, null, $llmText);
        echo "  [{$summary['workout_date']}] regenerated ({$r['source_kind']}#{$r['id']}, " . mb_strlen($llmText) . " chars)\n";
    } catch (Throwable $e) {
        echo "  [{$summary['workout_date']}] ERROR: {$e->getMessage()}\n";
    }
}

echo "\nDone.\n";
