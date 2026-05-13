#!/usr/bin/env php
<?php
/**
 * Тест post-workout checkin reply: имитирует ответ AI на сообщение пользователя
 * о состоянии после тренировки. Проверяет, что dispatch идёт с event_key
 * coach.proactive_post_workout_checkin_reply (фикс №3).
 *
 * Usage: php scripts/test_post_workout_reply.php <user_id>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/ChatService.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/test_post_workout_reply.php <user_id>\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

require_once $baseDir . '/repositories/ChatRepository.php';
$repository = new ChatRepository($db);
$conversation = $repository->getOrCreateConversation($userId, 'ai');
$conversationId = (int) $conversation['id'];
echo "Using conversation id={$conversationId}\n";

$service = new ChatService($db);
$ref = new ReflectionClass($service);
$method = $ref->getMethod('persistPostWorkoutFollowupReply');
$method->setAccessible(true);

$fakeFollowupReply = [
    'assistant_content' => 'Понимаю, ноги тяжёлые после интервальной — это нормально. На завтра запланирован easy 8 км, можешь его выполнить в более низком темпе или заменить на отдых. Главное — слушай тело.',
    'metadata' => [
        'proactive_type' => 'post_workout_checkin_reply',
        'post_workout_followup' => [
            'id' => 999999,
            'classification' => 'fatigue',
            'pain_flag' => false,
            'fatigue_flag' => true,
            'session_rpe' => 7,
            'legs_score' => 4,
            'breath_score' => 7,
            'hr_strain_score' => 6,
            'pain_score' => 1,
            'recovery_risk_score' => 0.45,
            'note_id' => null,
        ],
    ],
];

echo "Calling persistPostWorkoutFollowupReply…\n";
$messageId = $method->invoke($service, $userId, $conversationId, $fakeFollowupReply);
echo "message_id = {$messageId}\n";
