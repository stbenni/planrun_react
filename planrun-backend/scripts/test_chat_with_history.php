#!/usr/bin/env php
<?php
/**
 * Тест: задаём AI-чату вопрос и смотрим, использует ли он историю разборов.
 * Usage: php scripts/test_chat_with_history.php <user_id> "<question>"
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/LlmGateway.php';
require_once $baseDir . '/services/ChatContextBuilder.php';
require_once $baseDir . '/services/ChatPromptBuilder.php';
require_once $baseDir . '/repositories/ChatRepository.php';

$userId = (int) ($argv[1] ?? 0);
$question = trim((string) ($argv[2] ?? ''));
if ($userId <= 0 || $question === '') {
    fwrite(STDERR, "Usage: php scripts/test_chat_with_history.php <user_id> \"<question>\"\n");
    exit(1);
}

$db = getDBConnection();

$ctxBuilder = new ChatContextBuilder($db);
$promptBuilder = new ChatPromptBuilder($db, $ctxBuilder, new ChatRepository($db));

$context = $ctxBuilder->buildContextForUser($userId);
$messages = $promptBuilder->buildChatMessages($userId, $context, [], $question);

echo "Total system prompt size: " . strlen($messages[0]['content']) . " chars\n";
echo "Asking: {$question}\n\n";

$baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
$model = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');

$payload = LlmGateway::withThinkingMode([
    'model' => $model,
    'messages' => $messages,
    'stream' => false,
    'temperature' => 0.3,
    'max_tokens' => 600,
], $baseUrl, false);

try {
    $response = LlmGateway::requestChatCompletion($baseUrl, $payload, [
        'feature' => 'Chat test (history)',
        'purpose' => 'chat',
        'db' => $db,
        'surface' => 'test_chat_with_history',
        'event_type' => 'llm_request',
        'user_id' => $userId,
        'timeout' => 60,
        'connect_timeout' => 5,
        'max_attempts' => 1,
    ]);
    $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    echo "=== AI ANSWER ===\n{$content}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
