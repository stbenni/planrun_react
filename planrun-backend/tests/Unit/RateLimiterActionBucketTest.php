<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../config/RateLimiter.php';

class RateLimiterActionBucketTest extends TestCase {
    public function test_plan_notifications_use_default_bucket(): void {
        $this->assertSame('default', \RateLimiter::resolveApiActionBucket('get_plan_notifications'));
    }

    public function test_plan_generation_actions_use_plan_generation_bucket(): void {
        $this->assertSame('plan_generation', \RateLimiter::resolveApiActionBucket('recalculate_plan'));
        $this->assertSame('plan_generation', \RateLimiter::resolveApiActionBucket('generate_next_plan'));
    }

    public function test_chat_send_actions_use_chat_bucket(): void {
        $this->assertSame('chat', \RateLimiter::resolveApiActionBucket('chat_send_message'));
        $this->assertSame('chat', \RateLimiter::resolveApiActionBucket('chat_send_message_stream'));
    }

    public function test_adaptation_action_uses_adaptation_bucket(): void {
        $this->assertSame('adaptation', \RateLimiter::resolveApiActionBucket('run_weekly_adaptation'));
    }
}
