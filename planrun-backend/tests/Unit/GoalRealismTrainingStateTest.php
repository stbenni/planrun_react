<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/prompt_builder.php';

class GoalRealismTrainingStateTest extends TestCase {
    private function joinedMessages(array $messages): string {
        return implode("\n", array_map(static fn(array $message): string => $message['text'] ?? '', $messages));
    }

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

    public function test_assessGoalRealism_marks_timeline_as_primary_constraint(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'half',
            'race_target_time' => '1:40:00',
            'race_date' => '2026-04-29',
            'training_start_date' => '2026-04-01',
            'weekly_base_km' => 30,
            'sessions_per_week' => 4,
            'experience_level' => 'advanced',
            'training_state' => [
                'vdot' => 40.4,
                'vdot_source_label' => 'свежий забег/контрольная',
                'training_paces' => getTrainingPaces(40.4),
            ],
        ];

        $result = assessGoalRealism($user);

        $this->assertSame('unrealistic', $result['verdict']);
        $this->assertSame('timeline', $result['primary_constraint']);
        $this->assertStringContainsString('полноценном цикле подготовки достижимо', $result['messages'][1]['text']);
    }

    public function test_assessGoalRealism_allows_short_5k_bridge_after_recent_longer_race(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => '5k',
            'race_target_time' => '0:20:30',
            'race_date' => '2026-04-22',
            'training_start_date' => '2026-04-01',
            'weekly_base_km' => 32,
            'sessions_per_week' => 4,
            'experience_level' => 'intermediate',
            'last_race_distance' => '10k',
            'last_race_time' => '0:43:30',
            'last_race_date' => '2026-03-20',
        ];

        $result = assessGoalRealism($user);
        $messages = $this->joinedMessages($result['messages']);

        $this->assertSame('bridge_from_longer', $result['timeline_mode']);
        $this->assertNotSame('timeline', $result['primary_constraint']);
        $this->assertStringContainsString('короткую специфическую подводку', $messages);
    }

    public function test_assessGoalRealism_allows_short_10k_refresh_block_after_recent_10k(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_target_time' => '0:44:00',
            'race_date' => '2026-04-29',
            'training_start_date' => '2026-04-01',
            'weekly_base_km' => 35,
            'sessions_per_week' => 4,
            'experience_level' => 'intermediate',
            'last_race_distance' => '10k',
            'last_race_time' => '0:46:00',
            'last_race_date' => '2026-03-08',
        ];

        $result = assessGoalRealism($user);
        $messages = $this->joinedMessages($result['messages']);

        $this->assertSame('same_distance_refresh', $result['timeline_mode']);
        $this->assertNotSame('timeline', $result['primary_constraint']);
        $this->assertStringContainsString('подводящий блок', $messages);
    }

    public function test_assessGoalRealism_treats_recent_half_as_short_refresh_not_timeline_failure(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'half',
            'race_target_time' => '1:40:00',
            'race_date' => '2026-04-29',
            'training_start_date' => '2026-04-01',
            'weekly_base_km' => 30,
            'sessions_per_week' => 4,
            'experience_level' => 'advanced',
            'last_race_distance' => 'half',
            'last_race_time' => '1:50:00',
            'last_race_date' => '2026-03-01',
        ];

        $result = assessGoalRealism($user);
        $messages = $this->joinedMessages($result['messages']);

        $this->assertSame('challenging', $result['verdict']);
        $this->assertSame('target_time', $result['primary_constraint']);
        $this->assertSame('same_distance_refresh', $result['timeline_mode']);
        $this->assertStringContainsString('короткий подводящий блок', $messages);
        $this->assertStringNotContainsString('НЕДОСТАТОЧНО ВРЕМЕНИ', $messages);
    }

    public function test_assessGoalRealism_uses_planning_benchmark_for_timeline_refresh(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'half',
            'race_target_time' => '1:40:00',
            'race_date' => '2026-04-29',
            'training_start_date' => '2026-04-01',
            'weekly_base_km' => 30,
            'sessions_per_week' => 4,
            'experience_level' => 'advanced',
            'planning_benchmark_distance' => 'half',
            'planning_benchmark_time' => '1:50:00',
            'planning_benchmark_date' => '2026-03-01',
            'planning_benchmark_type' => 'control',
            'planning_benchmark_effort' => 'hard',
            'training_state' => [
                'vdot' => 40.4,
                'vdot_source_label' => 'явный ориентир формы от пользователя',
                'training_paces' => getTrainingPaces(40.4),
            ],
        ];

        $result = assessGoalRealism($user);
        $messages = $this->joinedMessages($result['messages']);

        $this->assertSame('challenging', $result['verdict']);
        $this->assertSame('target_time', $result['primary_constraint']);
        $this->assertSame('same_distance_refresh', $result['timeline_mode']);
        $this->assertStringContainsString('короткий подводящий блок', $messages);
    }

    public function test_assessGoalRealism_allows_short_marathon_refresh_block_after_recent_marathon(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_target_time' => '3:35:00',
            'race_date' => '2026-05-13',
            'training_start_date' => '2026-04-01',
            'weekly_base_km' => 55,
            'sessions_per_week' => 5,
            'experience_level' => 'advanced',
            'last_race_distance' => 'marathon',
            'last_race_time' => '3:42:00',
            'last_race_date' => '2026-02-15',
        ];

        $result = assessGoalRealism($user);
        $messages = $this->joinedMessages($result['messages']);

        $this->assertSame('same_distance_refresh', $result['timeline_mode']);
        $this->assertNotSame('timeline', $result['primary_constraint']);
        $this->assertStringContainsString('короткий подводящий блок', $messages);
    }

    public function test_assessGoalRealism_keeps_first_half_strict_with_short_timeline(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => 'half',
            'race_target_time' => '1:50:00',
            'race_date' => '2026-04-29',
            'training_start_date' => '2026-04-01',
            'weekly_base_km' => 30,
            'sessions_per_week' => 4,
            'experience_level' => 'advanced',
            'is_first_race_at_distance' => 1,
        ];

        $result = assessGoalRealism($user);
        $messages = $this->joinedMessages($result['messages']);

        $this->assertSame('unrealistic', $result['verdict']);
        $this->assertSame('timeline', $result['primary_constraint']);
        $this->assertSame('standard', $result['timeline_mode']);
        $this->assertStringContainsString('рекомендуется минимум 8 недель', $messages);
    }
}
