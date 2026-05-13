<?php
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../services/WorkoutBuilderService.php';

$db = getDBConnection();
$builder = new WorkoutBuilderService($db);

$scenarios = [
    ['Зал, новичок, 60кг',          'gym',  60, 'novice'],
    ['Зал, intermediate, 66кг',     'gym',  66, 'intermediate'],
    ['Зал, expert, 66кг (st_benni)','gym',  66, 'expert'],
    ['Зал, expert, 80кг',           'gym',  80, 'expert'],
    ['Дом, novice, 60кг',           'home', 60, 'novice'],
    ['Дом, intermediate, 70кг',     'home', 70, 'intermediate'],
    ['Дом, expert, 75кг',           'home', 75, 'expert'],
    ['Без bodyweight (defaults)',   'gym',  null, null],
];

foreach ($scenarios as $sc) {
    [$title, $pref, $bw, $exp] = $sc;
    echo "═══ {$title} ═══\n";
    $session = $builder->buildOfpSession($pref, $bw, $exp);
    foreach ($session as $ex) {
        $w = $ex['weight_kg'] !== null ? sprintf('%.1f кг', $ex['weight_kg']) : '—';
        $sr = $ex['sets'] && $ex['reps']
            ? "{$ex['sets']}×{$ex['reps']}"
            : ($ex['duration_sec'] ? "{$ex['duration_sec']}сек" : '');
        $src = $ex['weight_source'] ?? '—';
        echo sprintf("  %-32s %-8s @ %-10s [%s]\n", $ex['name'], $sr, $w, $src);
    }
    echo "\n";
}

echo "═══ СБУ (одна сессия — не зависит от тренажёрки) ═══\n";
$sbu = $builder->buildSbuSession();
foreach ($sbu as $ex) {
    $dist = $ex['distance_m'] ? $ex['distance_m'] . ' м' : '—';
    $sets = $ex['sets'] ? "{$ex['sets']}×" : '';
    echo sprintf("  %-40s %s%s\n", $ex['name'], $sets, $dist);
}
