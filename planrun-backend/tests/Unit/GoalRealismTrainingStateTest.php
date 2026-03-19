<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/prompt_builder.php';

class GoalRealismTrainingStateTest extends TestCase {
    public function test_assessGoalRealism_prefersTrainingStateVdot(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_target_time' => '0:45:00',
            'race_date' => '2026-05-11',
            'training_start_date' => '2026-03-09',
            'weekly_base_km' => 30,
            'sessions_per_week' => 4,
            'experience_level' => 'intermediate',
            'easy_pace_sec' => 390,
            'training_state' => [
                'vdot' => 48.0,
                'vdot_source_label' => 'лучшие свежие тренировки',
                'training_paces' => getTrainingPaces(48.0),
            ],
        ];

        $result = assessGoalRealism($user);

        $this->assertSame(48.0, $result['vdot']);
        $this->assertNotNull($result['training_paces']);
    }
}
