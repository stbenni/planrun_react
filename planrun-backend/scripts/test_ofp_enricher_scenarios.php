<?php
/**
 * Прямой тест ofp_enricher на разных load-сценариях.
 * Запускает LLM #4 с разными синтетическими неделями и показывает что подбирает.
 */
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../planrun_ai/ofp_enricher.php';

$db = getDBConnection();

$userStmt = $db->prepare("SELECT * FROM users WHERE id=1");
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!is_string($user['preferred_ofp_days'])) {
    $user['preferred_ofp_days'] = json_encode($user['preferred_ofp_days']);
}

$libStmt = $db->prepare(
    "SELECT id, category, name, default_sets, default_reps, default_distance_m,
            default_duration_sec, default_weight_kg
     FROM exercise_library WHERE is_active = 1"
);
$libStmt->execute();
$res = $libStmt->get_result();
$lib = [];
while ($r = $res->fetch_assoc()) $lib[] = $r;
$libStmt->close();

// 4 сценария с разной нагрузкой
$scenarios = [
    'HIGH-LOAD week (peak)' => [
        ['date' => '2026-06-09', 'type' => 'rest'],  // Tue (preferred OFP)
        ['date' => '2026-06-10', 'type' => 'tempo', 'distance_km' => 18],
        ['date' => '2026-06-11', 'type' => 'rest'],  // Thu
        ['date' => '2026-06-12', 'type' => 'easy', 'distance_km' => 14],
        ['date' => '2026-06-13', 'type' => 'rest'],  // Sat
        ['date' => '2026-06-14', 'type' => 'long', 'distance_km' => 32],
    ],
    'MEDIUM-LOAD (build)' => [
        ['date' => '2026-05-26', 'type' => 'rest'],  // Tue
        ['date' => '2026-05-27', 'type' => 'tempo', 'distance_km' => 14],
        ['date' => '2026-05-28', 'type' => 'rest'],  // Thu
        ['date' => '2026-05-29', 'type' => 'easy', 'distance_km' => 8],
        ['date' => '2026-05-30', 'type' => 'rest'],  // Sat
        ['date' => '2026-05-31', 'type' => 'long', 'distance_km' => 22],
    ],
    'LOW-LOAD (recovery после race)' => [
        ['date' => '2026-05-19', 'type' => 'rest'],  // Tue
        ['date' => '2026-05-20', 'type' => 'easy', 'distance_km' => 8],
        ['date' => '2026-05-21', 'type' => 'rest'],  // Thu
        ['date' => '2026-05-22', 'type' => 'easy', 'distance_km' => 6],
        ['date' => '2026-05-23', 'type' => 'rest'],  // Sat
        ['date' => '2026-05-24', 'type' => 'easy', 'distance_km' => 10],
    ],
    'RACE-WEEK (taper)' => [
        ['date' => '2026-06-30', 'type' => 'rest'],  // Tue
        ['date' => '2026-07-01', 'type' => 'easy', 'distance_km' => 5],
        ['date' => '2026-07-02', 'type' => 'rest'],  // Thu
        ['date' => '2026-07-03', 'type' => 'easy', 'distance_km' => 3],
        ['date' => '2026-07-04', 'type' => 'race', 'distance_km' => 42.2],
    ],
];

foreach ($scenarios as $label => $days) {
    echo "═══ {$label} ═══\n";
    $planData = ['weeks' => [['days' => $days]]];
    $sessions = enrichPlanWithOfp($planData, $user, $lib, 1);
    if (empty($sessions)) {
        echo "(enricher вернул пусто — нет targets либо LLM не сработал)\n\n";
        continue;
    }
    foreach ($sessions as $date => $exercises) {
        $dow = date('l', strtotime($date));
        echo "  📅 {$date} ({$dow}):\n";
        foreach ($exercises as $ex) {
            $sr = $ex['sets'] && $ex['reps']
                ? "{$ex['sets']}×{$ex['reps']}"
                : ($ex['duration_sec'] ? "{$ex['duration_sec']}сек" : '');
            $w = $ex['weight_kg'] !== null ? sprintf('%.1f кг', $ex['weight_kg']) : '—';
            $notes = isset($ex['notes']) && $ex['notes'] !== '' ? " | {$ex['notes']}" : '';
            echo sprintf("     %-35s %-8s @ %-10s%s\n", $ex['name'], $sr, $w, $notes);
        }
        echo "\n";
    }
}
