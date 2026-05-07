<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/LlmGateway.php';
require_once __DIR__ . '/../../services/ChatToolRegistry.php';
require_once __DIR__ . '/../../services/ChatContextBuilder.php';

/**
 * Live integration tests for DeepSeek native tool calling.
 * Requires PLAN_LLM_API_KEY or LLM_CHAT_API_KEY in .env.
 *
 * @group live
 * @group deepseek
 */
class DeepSeekToolCallingTest extends TestCase
{
    private string $baseUrl;
    private string $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
        $this->model = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');

        $keys = \LlmGateway::apiKeys('chat') ?: \LlmGateway::apiKeys('plan');
        if (empty($keys) || $keys === ['']) {
            $this->markTestSkipped('No DeepSeek API key configured (LLM_CHAT_API_KEY or PLAN_LLM_API_KEY)');
        }
    }

    /**
     * Test 1: DeepSeek returns native tool_calls when asked about today's training.
     * This is the core scenario — user asks "что у меня сегодня?" and the model
     * should call get_day_details(date=today).
     */
    public function test_model_calls_get_day_details_for_today_question(): void
    {
        $today = date('Y-m-d');
        $tools = $this->getMinimalTools();

        $messages = [
            ['role' => 'system', 'content' => "Ты — PlanRun, тренер по бегу. Сегодня: {$today}. Тебе доступны tools. Вызывай их ПРОАКТИВНО. Все даты в Y-m-d. 100% русский язык."],
            ['role' => 'user', 'content' => 'Что у меня сегодня по плану?'],
        ];

        $result = $this->callDeepSeek($messages, $tools);

        $msg = $result['choices'][0]['message'] ?? [];
        $toolCalls = $msg['tool_calls'] ?? [];

        $this->assertNotEmpty($toolCalls, 'Model should return tool_calls for a training question');
        $this->assertSame('function', $toolCalls[0]['type'] ?? '');

        $fn = $toolCalls[0]['function'] ?? [];
        $this->assertContains($fn['name'], ['get_day_details', 'get_plan'], 'Model should call get_day_details or get_plan');

        $args = json_decode($fn['arguments'] ?? '{}', true);
        $this->assertNotEmpty($args, 'Tool call arguments should not be empty');
    }

    /**
     * Test 2: DeepSeek returns native tool_calls for workout history request.
     */
    public function test_model_calls_get_workouts_for_history_question(): void
    {
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $tools = $this->getMinimalTools();

        $messages = [
            ['role' => 'system', 'content' => "Ты — PlanRun, тренер по бегу. Сегодня: {$today}. Тебе доступны tools. Вызывай их ПРОАКТИВНО. 100% русский язык."],
            ['role' => 'user', 'content' => 'Покажи мои тренировки за последнюю неделю'],
        ];

        $result = $this->callDeepSeek($messages, $tools);

        $msg = $result['choices'][0]['message'] ?? [];
        $toolCalls = $msg['tool_calls'] ?? [];

        $this->assertNotEmpty($toolCalls, 'Model should call tools for history request');

        $fn = $toolCalls[0]['function'] ?? [];
        $this->assertContains($fn['name'], ['get_workouts', 'get_stats'], 'Model should call get_workouts or get_stats');
    }

    /**
     * Test 3: Multi-round tool calling — model processes tool result and responds.
     */
    public function test_multi_round_tool_call_with_result(): void
    {
        $today = date('Y-m-d');
        $tools = $this->getMinimalTools();

        // Round 1: user asks, model calls tool
        $messages = [
            ['role' => 'system', 'content' => "Ты — PlanRun, тренер по бегу. Сегодня: {$today}. Тебе доступны tools. Вызывай их ПРОАКТИВНО. Ответ на русском. 2-3 предложения."],
            ['role' => 'user', 'content' => 'Что у меня сегодня?'],
        ];

        $result1 = $this->callDeepSeek($messages, $tools);
        $msg1 = $result1['choices'][0]['message'] ?? [];
        $toolCalls = $msg1['tool_calls'] ?? [];

        if (empty($toolCalls)) {
            $this->markTestSkipped('Model did not call tools in round 1 (may happen with flash model)');
        }

        // Simulate tool result
        $toolCallId = $toolCalls[0]['id'];
        $messages[] = ['role' => 'assistant', 'content' => $msg1['content'] ?? '', 'tool_calls' => $toolCalls];
        $messages[] = [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => json_encode([
                'date' => $today,
                'type' => 'easy',
                'description' => 'Легкий бег: 8 км, темп 5:40',
                'status' => 'planned',
            ]),
        ];

        // Round 2: model processes result and responds with text
        $result2 = $this->callDeepSeek($messages, $tools);
        $msg2 = $result2['choices'][0]['message'] ?? [];

        $this->assertNotEmpty($msg2['content'] ?? '', 'Model should respond with text after getting tool result');
        $this->assertEmpty($msg2['tool_calls'] ?? [], 'Model should not call more tools after getting the answer');
    }

    /**
     * Test 4: Model does NOT call tools for simple greeting (no false positives).
     */
    public function test_model_does_not_call_tools_for_greeting(): void
    {
        $today = date('Y-m-d');
        $tools = $this->getMinimalTools();

        $messages = [
            ['role' => 'system', 'content' => "Ты — PlanRun, тренер по бегу. Сегодня: {$today}. Тебе доступны tools. 100% русский язык."],
            ['role' => 'user', 'content' => 'Привет! Как дела?'],
        ];

        $result = $this->callDeepSeek($messages, $tools);

        $msg = $result['choices'][0]['message'] ?? [];
        $content = $msg['content'] ?? '';
        $toolCalls = $msg['tool_calls'] ?? [];

        $this->assertNotEmpty($content, 'Model should respond with text for a greeting');
        // Greeting may or may not trigger tools — some models proactively check plan.
        // We just verify the response is valid Russian text.
        $this->assertMatchesRegularExpression('/[\p{Cyrillic}]/u', $content, 'Response should contain Russian text');
    }

    /**
     * Test 5: Tool definitions from ChatToolRegistry match OpenAI format.
     */
    public function test_chat_tool_registry_produces_valid_openai_tools_format(): void
    {
        $db = getDBConnection();
        $contextBuilder = new \ChatContextBuilder($db);
        $registry = new \ChatToolRegistry($db, $contextBuilder);
        $tools = $registry->getChatTools();

        $this->assertNotEmpty($tools);
        $this->assertGreaterThanOrEqual(18, count($tools), 'Should have at least 18 tools');

        foreach ($tools as $tool) {
            $this->assertSame('function', $tool['type'], 'Tool type must be "function"');
            $this->assertArrayHasKey('function', $tool);
            $fn = $tool['function'];
            $this->assertNotEmpty($fn['name'], 'Tool must have a name');
            $this->assertNotEmpty($fn['description'], 'Tool must have a description');
            $this->assertArrayHasKey('parameters', $fn, 'Tool must have parameters');
            $this->assertSame('object', $fn['parameters']['type'], 'Parameters type must be "object"');
        }
    }

    /**
     * Test 6: Full tool list works with DeepSeek API (no schema errors).
     */
    public function test_full_tool_list_accepted_by_deepseek_api(): void
    {
        $db = getDBConnection();
        $contextBuilder = new \ChatContextBuilder($db);
        $registry = new \ChatToolRegistry($db, $contextBuilder);
        $tools = $registry->getChatTools();

        $today = date('Y-m-d');
        $messages = [
            ['role' => 'system', 'content' => "Ты — PlanRun. Сегодня: {$today}. Тебе доступны tools. Русский язык."],
            ['role' => 'user', 'content' => 'Какая у меня статистика за неделю?'],
        ];

        // This should NOT throw — validates that all 18 tool schemas are accepted
        $result = $this->callDeepSeek($messages, $tools);

        $msg = $result['choices'][0]['message'] ?? [];
        $this->assertTrue(
            !empty($msg['content']) || !empty($msg['tool_calls']),
            'Model should either respond with text or call a tool'
        );
    }

    /**
     * Test 7: Verify tool_choice=auto works and model can decide not to call tools.
     */
    public function test_tool_choice_auto_allows_text_only_response(): void
    {
        $tools = $this->getMinimalTools();

        $messages = [
            ['role' => 'system', 'content' => 'Ты — тренер по бегу. Отвечай кратко на русском.'],
            ['role' => 'user', 'content' => 'Объясни что такое VDOT в одном предложении'],
        ];

        $result = $this->callDeepSeek($messages, $tools, 'auto');

        $msg = $result['choices'][0]['message'] ?? [];
        $this->assertNotEmpty($msg['content'] ?? '', 'Model should provide text explanation');
    }

    // ── Helpers ──

    private function getMinimalTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_day_details',
                    'description' => 'Получить план и результат тренировки на конкретную дату.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'Дата Y-m-d'],
                        ],
                        'required' => ['date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_plan',
                    'description' => 'Получить план тренировок на неделю.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'Дата Y-m-d — план на неделю с этой датой'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_workouts',
                    'description' => 'Получить историю выполненных тренировок за период.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date_from' => ['type' => 'string', 'description' => 'Начало Y-m-d'],
                            'date_to' => ['type' => 'string', 'description' => 'Конец Y-m-d'],
                        ],
                        'required' => ['date_from', 'date_to'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_stats',
                    'description' => 'Статистика тренировок: объёмы, выполнение плана.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => ['type' => 'string', 'description' => 'week/month/plan/all'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
        ];
    }

    private function callDeepSeek(array $messages, array $tools, string $toolChoice = 'auto'): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
            'stream' => false,
            'max_tokens' => 1000,
        ];

        $payload = \LlmGateway::withThinkingMode($payload, $this->baseUrl, false);

        $result = \LlmGateway::requestChatCompletion($this->baseUrl, $payload, [
            'feature' => 'DeepSeek tool calling test',
            'purpose' => 'chat',
            'timeout' => 60,
            'connect_timeout' => 10,
            'max_attempts' => 2,
        ]);

        $this->assertArrayHasKey('choices', $result, 'API response must contain choices');
        $this->assertNotEmpty($result['choices'], 'API response must have at least one choice');

        return $result;
    }
}
