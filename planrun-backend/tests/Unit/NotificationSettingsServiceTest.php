<?php

namespace Tests\Unit;

use NotificationSettingsService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/NotificationSettingsService.php';

class NotificationSettingsServiceTest extends TestCase {

    public function test_aiCoachProactiveEventsAreRegistered(): void {
        $definitions = NotificationSettingsService::getEventDefinitions();
        $expectedEvents = [
            'coach.proactive_pause',
            'coach.proactive_overload',
            'coach.proactive_overload_warning',
            'coach.proactive_race_approaching',
            'coach.proactive_low_compliance',
            'coach.proactive_distance_record',
            'coach.proactive_vdot_improvement',
            'coach.proactive_volume_record',
            'coach.proactive_consistency_streak',
            'coach.proactive_goal_achievable',
            'coach.proactive_daily_briefing',
            'coach.proactive_weekly_digest',
            'coach.proactive_post_workout_checkin',
            'coach.proactive_post_workout_analysis',
            'coach.proactive_post_workout_checkin_reply',
            'coach.proactive_message',
        ];

        foreach ($expectedEvents as $eventKey) {
            $this->assertArrayHasKey($eventKey, $definitions);
            $this->assertSame('ai_coach', $definitions[$eventKey]['group_key'] ?? null);
            $this->assertContains('mobile_push', $definitions[$eventKey]['channels'] ?? []);
        }
    }
}
