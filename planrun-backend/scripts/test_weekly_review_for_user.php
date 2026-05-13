#!/usr/bin/env php
<?php
/**
 * Тест weekly_ai_review для конкретного user_id с обходом фильтра «воскресенье 20:00 локально».
 * Реально вызывает DeepSeek и шлёт ChatService->addAIMessageToUser → notification_deliveries.
 *
 * Usage: php scripts/test_weekly_review_for_user.php <user_id>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/prepare_weekly_analysis.php';
require_once $baseDir . '/services/LlmGateway.php';
require_once $baseDir . '/services/ChatService.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/test_weekly_review_for_user.php <user_id>\n");
    exit(1);
}

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

try {
    $weekNumber = getCurrentWeekNumber($userId, $db);
    echo "Week number: {$weekNumber}\n";
    $analysis = prepareWeeklyAnalysis($userId, $weekNumber);
    $enrichment = collectReviewEnrichment($userId, $db);
    $reviewText = buildWeeklyReviewPromptData($analysis, $enrichment);

    echo "Calling DeepSeek for weekly review...\n";
    $review = generateWeeklyReview($reviewText, $analysis['user']['username'] ?? 'спортсмен');
    if (!$review) {
        fwrite(STDERR, "LLM returned empty\n");
        exit(1);
    }
    echo "\nLLM REVIEW:\n---\n{$review}\n---\n\n";

    $chatService = new ChatService($db);
    $result = $chatService->addAIMessageToUser($userId, $review, [
        'event_key' => 'plan.weekly_review',
        'title' => 'Еженедельный обзор готов',
        'link' => '/chat',
    ]);

    echo "Message saved id=" . ($result['message_id'] ?? '?') . "\n";
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
