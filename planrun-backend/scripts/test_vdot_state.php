<?php
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../services/TrainingStateBuilder.php';

$db = getDBConnection();
$builder = new TrainingStateBuilder($db);
$state = $builder->buildForUserId(1);

echo "vdot                  = " . ($state['vdot'] ?? 'null') . "\n";
echo "vdot_source           = " . ($state['vdot_source'] ?? 'null') . "\n";
echo "vdot_source_detail    = " . ($state['vdot_source_detail'] ?? 'null') . "\n";
echo "vdot_weeks_old        = " . ($state['vdot_weeks_old'] ?? 'null') . "\n";
echo "vdot_confidence       = " . ($state['vdot_confidence'] ?? 'null') . "\n";
echo "source_distance_km    = " . ($state['source_distance_km'] ?? 'null') . "\n";
echo "source_time_sec       = " . ($state['source_time_sec'] ?? 'null') . "\n";
echo "race_target_time      = " . ($state['race_target_time'] ?? 'null') . "\n";
echo "pace_strategy.effective_target_time = " . ($state['pace_strategy']['effective_target_time'] ?? 'null') . "\n";
echo "pace_strategy.goal_target_time      = " . ($state['pace_strategy']['goal_target_time'] ?? 'null') . "\n";
echo "pace_strategy.severity               = " . ($state['pace_strategy']['severity'] ?? 'null') . "\n";
echo "pace_strategy.gap_pct                = " . ($state['pace_strategy']['gap_pct'] ?? 'null') . "\n";
echo "\n";
echo "Training paces:\n";
if (!empty($state['formatted_training_paces'])) {
    foreach ($state['formatted_training_paces'] as $key => $val) {
        echo "  {$key}: {$val}\n";
    }
}
