<?php
/**
 * Сервис чата — оркестратор.
 * Делегирует: tools → ChatToolRegistry, промпт → ChatPromptBuilder,
 * подтверждения → ChatConfirmationHandler, sanitize/action → ChatActionParser.
 * Сам: LLM-вызовы, streaming, messaging CRUD, push-уведомления.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';
require_once __DIR__ . '/ChatContextBuilder.php';
require_once __DIR__ . '/ChatToolRegistry.php';
require_once __DIR__ . '/ChatPromptBuilder.php';
require_once __DIR__ . '/ChatConfirmationHandler.php';
require_once __DIR__ . '/ChatActionParser.php';
require_once __DIR__ . '/ChatMemoryManager.php';
require_once __DIR__ . '/DateResolver.php';
require_once __DIR__ . '/PushNotificationService.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class ChatService extends BaseService {

    private const DEFAULT_HISTORY_LIMIT = 100;
    private const DEFAULT_SUMMARIZE_THRESHOLD = 35;
    private const DEFAULT_RECENT_MESSAGES = 15;

    private const WRITE_TOOLS = [
        'log_workout', 'update_training_day', 'add_training_day',
        'delete_training_day', 'move_training_day', 'swap_training_days',
        'copy_day', 'recalculate_plan', 'generate_next_plan', 'update_profile',
        'report_health_issue',
    ];

    private $repository;
    private $contextBuilder;
    private string $llmBaseUrl;
    private string $llmModel;
    private int $historyLimit;

    private ChatToolRegistry $toolRegistry;
    private ChatPromptBuilder $promptBuilder;
    private ChatConfirmationHandler $confirmationHandler;
    private ChatActionParser $actionParser;
    private ChatMemoryManager $memoryManager;

    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new ChatRepository($db);
        $this->contextBuilder = new ChatContextBuilder($db);
        $this->llmBaseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $this->llmModel = env('LLM_CHAT_MODEL', 'qwen3-14b');
        $this->historyLimit = (int) env('CHAT_HISTORY_MESSAGES_LIMIT', self::DEFAULT_HISTORY_LIMIT);
        if ($this->historyLimit < 1) {
            $this->historyLimit = self::DEFAULT_HISTORY_LIMIT;
        }

        $this->toolRegistry = new ChatToolRegistry($db, $this->contextBuilder);
        $this->promptBuilder = new ChatPromptBuilder($db, $this->contextBuilder, $this->repository);
        $this->confirmationHandler = new ChatConfirmationHandler($db, $this->toolRegistry);
        $this->actionParser = new ChatActionParser($db, $this->toolRegistry, $this->confirmationHandler);
        $this->memoryManager = new ChatMemoryManager($db);
    }

    // ═══ Health ═══

    public function checkLlmHealth(): bool {
        $url = $this->llmBaseUrl . '/models';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($curlErr || $httpCode !== 200) {
            Logger::error('LLM health check failed', ['url' => $url, 'http_code' => $httpCode, 'error' => $curlErr ?: 'HTTP ' . $httpCode]);
            return false;
        }
        $data = json_decode($response, true);
        $models = $data['models'] ?? $data['data'] ?? [];
        if (empty($models)) {
            Logger::error('LLM health check: no models loaded', ['url' => $url, 'response' => substr($response, 0, 200)]);
            return false;
        }
        return true;
    }

    // ═══ History summarization ═══

    private function applyHistorySummarization(int $userId, int $conversationId, array &$history): void {
        if ((int) env('CHAT_SUMMARIZE_ENABLED', 1) !== 1) return;
        $threshold = (int) env('CHAT_SUMMARIZE_THRESHOLD', self::DEFAULT_SUMMARIZE_THRESHOLD);
        $recentCount = (int) env('CHAT_RECENT_MESSAGES', self::DEFAULT_RECENT_MESSAGES);
        if ($recentCount < 1) $recentCount = self::DEFAULT_RECENT_MESSAGES;
        $total = count($history);
        if ($total < $threshold) return;
        $olderCount = $total - $recentCount;
        if ($olderCount < 5) return;
        $summary = $this->summarizeOlderMessages(array_slice($history, 0, $olderCount), $userId);
        if ($summary !== '') {
            $this->contextBuilder->setHistorySummary($userId, $summary);
            $history = array_slice($history, $olderCount);
        }
    }

    private function summarizeOlderMessages(array $messages, int $userId): string {
        $text = '';
        foreach ($messages as $m) {
            $role = ($m['sender_type'] ?? '') === 'user' ? 'Пользователь' : 'Ассистент';
            $c = trim($m['content'] ?? '');
            if ($c !== '') $text .= "{$role}: {$c}\n\n";
        }
        if (mb_strlen($text) < 200) return '';

        $systemPrompt = "Ты — помощник для суммаризации диалога бегуна с AI-тренером. Сжато извлеки ключевую информацию из диалога ниже. Пиши ТОЛЬКО на русском. Формат (краткие пункты):\n\n" .
            "ЦЕЛИ/ЗАБЕГИ: цели по бегу, планы на забеги, целевые времена.\n" .
            "ТРАВМЫ/ОГРАНИЧЕНИЯ: травмы, боли, ограничения по здоровью.\n" .
            "ПРИВЫЧКИ: дни бега, предпочтения по темпу, погоде, времени.\n" .
            "РЕШЕНИЯ: что добавлено в план, какие тренировки запланированы, изменения.\n" .
            "ПРОЧЕЕ: другая важная информация о пользователе.\n\n" .
            "Не повторяй общие фразы. Только конкретика. До 500 символов.";

        $url = $this->llmBaseUrl . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->llmModel,
                'messages' => [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => "Суммаризируй диалог:\n\n" . mb_substr($text, 0, 12000)]],
                'stream' => false, 'max_tokens' => 800
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || $response === false) {
            Logger::warning('Chat summarization failed', ['http' => $httpCode, 'userId' => $userId]);
            return '';
        }
        return trim(json_decode($response, true)['choices'][0]['message']['content'] ?? '');
    }

    // ═══ AI messaging (non-streaming) ═══

    public function sendMessageAndGetResponse(int $userId, string $content): array {
        $this->blockedActions = [];
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $history = $this->repository->getMessagesAscending($conversation['id'], $this->historyLimit);

        $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);
        $this->applyHistorySummarization($userId, $conversation['id'], $history);

        $context = $this->contextBuilder->buildContextForUser($userId);
        $context = $this->promptBuilder->appendChatSearchSnippet($context, $conversation['id'], $content);
        $context = $this->promptBuilder->appendRagSnippet($context, $content);
        $messages = $this->promptBuilder->buildChatMessages($userId, $context, $history, $content);

        $toolsUsed = [];
        $pendingHandled = $this->confirmationHandler->tryExecutePendingAction($content, $history, $userId, $messages, $toolsUsed);
        if ($pendingHandled) {
            $response = $this->callLlm($messages, $userId);
        } else {
            $response = $this->callLlm($messages, $userId);
        }
        $fullContent = $this->actionParser->sanitizeResponse($response['content'] ?? '');
        $planWasUpdated = false;
        $fullContent = $this->actionParser->parseAndExecuteActions($fullContent, $userId, $history, $content, $planWasUpdated, $toolsUsed);

        if ($fullContent !== '') {
            $meta = ['model' => $this->llmModel, 'eval_count' => $response['usage']['total_tokens'] ?? null];
            if (!empty($this->blockedActions)) {
                $meta['pending_action'] = count($this->blockedActions) === 1
                    ? $this->blockedActions[0]
                    : $this->blockedActions;
            }
            $this->repository->addMessage($conversation['id'], 'ai', null, $fullContent, $meta);
            $this->repository->touchConversation($conversation['id']);
            if (connection_aborted()) {
                $this->sendChatPush($userId, 'Новое сообщение от AI-тренера', $fullContent, 'ai');
            }
        }

        return ['content' => $fullContent, 'message_id' => $this->db->insert_id ?? null];
    }

    // ═══ AI messaging (streaming) ═══

    public function streamResponse(int $userId, string $content): void {
        $this->blockedActions = [];
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $history = $this->repository->getMessagesAscending($conversation['id'], $this->historyLimit);

        $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);
        $this->applyHistorySummarization($userId, $conversation['id'], $history);

        $context = '';
        try {
            $context = $this->contextBuilder->buildContextForUser($userId);
        } catch (Throwable $e) {
            Logger::warning('ChatContextBuilder failed, using minimal context', ['error' => $e->getMessage()]);
            $context = "═══ ПРОФИЛЬ ═══\nДанные контекста временно недоступны.";
        }
        $context = $this->promptBuilder->appendChatSearchSnippet($context, $conversation['id'], $content);
        $context = $this->promptBuilder->appendRagSnippet($context, $content);
        $messages = $this->promptBuilder->buildChatMessages($userId, $context, $history, $content);

        $toolsUsed = [];
        $pendingHandled = $this->confirmationHandler->tryExecutePendingAction($content, $history, $userId, $messages, $toolsUsed);
        $swapHandled = false;
        $replaceRaceHandled = false;
        $genericUpdateHandled = false;
        if (!$pendingHandled) {
            $swapHandled = $this->confirmationHandler->tryHandleSwapConfirmation($content, $history, $userId, $messages, $toolsUsed);
        }
        if (!$pendingHandled && !$swapHandled) {
            $replaceRaceHandled = $this->confirmationHandler->tryHandleReplaceWithRaceConfirmation($content, $history, $userId, $messages, $toolsUsed);
        }
        if (!$pendingHandled && !$swapHandled && !$replaceRaceHandled) {
            $genericUpdateHandled = $this->confirmationHandler->tryHandleGenericUpdateConfirmation($content, $history, $userId, $messages, $toolsUsed);
        }
        if (!$pendingHandled && !$swapHandled && !$replaceRaceHandled && !$genericUpdateHandled) {
            if (!$this->checkLlmHealth()) {
                $this->repository->addMessage($conversation['id'], 'ai', null, 'Извини, LLM-сервер сейчас недоступен. Попробуй через минуту.');
                echo json_encode(['chunk' => 'Извини, LLM-сервер сейчас недоступен. Попробуй через минуту.']) . "\n";
                echo json_encode(['done' => true]) . "\n";
                flush();
                return;
            }
            $messages = $this->resolveToolCalls($messages, $userId, $toolsUsed);
        }

        // NDJSON control lines for plan-changing tools
        $planUpdatedSent = false;
        $planChangeTools = ['update_training_day', 'swap_training_days', 'delete_training_day', 'move_training_day', 'add_training_day', 'copy_day', 'log_workout'];
        if (array_intersect($planChangeTools, $toolsUsed)) {
            echo json_encode(['plan_updated' => true]) . "\n"; flush();
            $planUpdatedSent = true;
        }
        if (in_array('recalculate_plan', $toolsUsed, true)) {
            echo json_encode(['plan_recalculating' => true]) . "\n"; flush();
        }
        if (in_array('generate_next_plan', $toolsUsed, true)) {
            echo json_encode(['plan_generating_next' => true]) . "\n"; flush();
        }

        // Stream with think/action buffering
        $chunks = [];
        $thinkBuffer = '';
        $insideThink = false;
        $actionBuffer = '';
        $insideAction = false;

        $emitChunk = function (string $text) use (&$chunks) {
            if ($text === '') return;
            $chunks[] = $text;
            echo json_encode(['chunk' => $text]) . "\n";
            flush();
        };

        $this->callLlmStream($messages, function ($chunk) use (&$chunks, &$thinkBuffer, &$insideThink, &$actionBuffer, &$insideAction, $emitChunk) {
            $thinkBuffer .= $chunk;

            if ($insideThink) {
                if (preg_match('/\[\/THINK\]/i', $thinkBuffer) || preg_match('/<\/think>/i', $thinkBuffer)) {
                    $parts = preg_split('/(\[\/THINK\]|<\/think>)\s*/i', $thinkBuffer, 2);
                    $thinkBuffer = '';
                    $insideThink = false;
                    if (($parts[1] ?? '') !== '') $emitChunk($parts[1]);
                }
                return;
            }

            if (preg_match('/(\[THINK\]|<think>)/i', $thinkBuffer, $tm, PREG_OFFSET_CAPTURE)) {
                $before = substr($thinkBuffer, 0, $tm[0][1]);
                $insideThink = true;
                $thinkBuffer = substr($thinkBuffer, $tm[0][1] + strlen($tm[0][0]));
                if ($before !== '') $emitChunk($before);
                return;
            }

            foreach (['[THINK', '[think', '<think', '<THINK'] as $prefix) {
                $prefixLen = strlen($prefix);
                $bufferEnd = substr($thinkBuffer, -$prefixLen);
                for ($i = 1; $i <= $prefixLen; $i++) {
                    if (substr($bufferEnd, -$i) === substr($prefix, 0, $i)) {
                        $safe = substr($thinkBuffer, 0, -$i);
                        $thinkBuffer = substr($thinkBuffer, -$i);
                        if ($safe !== '' && $safe !== false) $emitChunk($safe);
                        return;
                    }
                }
            }

            $out = $thinkBuffer;
            $thinkBuffer = '';

            if ($insideAction) {
                $actionBuffer .= $out;
                if (strpos($actionBuffer, '-->') !== false) {
                    $parts = explode('-->', $actionBuffer, 2);
                    $insideAction = false;
                    $actionBuffer = '';
                    $after = trim($parts[1] ?? '');
                    if ($after !== '') $emitChunk($after);
                }
                return;
            }

            if (preg_match('/<!--\s*ACTION/i', $out)) {
                $pos = strpos($out, '<!--');
                $before = substr($out, 0, $pos);
                $rest = substr($out, $pos);
                if ($before !== '' && $before !== false) $emitChunk($before);
                if (strpos($rest, '-->') !== false) {
                    $parts = explode('-->', $rest, 2);
                    if (trim($parts[1] ?? '') !== '') $emitChunk(trim($parts[1]));
                } else {
                    $insideAction = true;
                    $actionBuffer = $rest;
                }
                return;
            }

            $emitChunk($out);
        });

        $fullContent = $this->actionParser->sanitizeResponse(implode('', $chunks));
        $planWasUpdated = false;
        $fullContent = $this->actionParser->parseAndExecuteActions($fullContent, $userId, $history, $content, $planWasUpdated, $toolsUsed);
        if ($planWasUpdated && !$planUpdatedSent) {
            echo json_encode(['plan_updated' => true]) . "\n";
            flush();
        }
        if ($fullContent !== '') {
            $this->repository->addMessage($conversation['id'], 'ai', null, $fullContent, ['model' => $this->llmModel]);
            $this->repository->touchConversation($conversation['id']);
            if (!empty($this->blockedActions)) {
                $this->storePendingAction($conversation['id'], $this->blockedActions);
            }
            if (connection_aborted()) {
                $this->sendChatPush($userId, 'Новое сообщение от AI-тренера', $fullContent, 'ai');
            }
        }

        $this->triggerMemoryExtraction($userId, $conversation['id']);
    }

    // ═══ Memory extraction ═══

    private function triggerMemoryExtraction(int $userId, int $conversationId): void {
        if ((int) env('CHAT_MEMORY_ENABLED', 1) !== 1) return;
        try {
            $recentMessages = $this->repository->getMessagesAscending($conversationId, 20);
            $this->memoryManager->extractAndSaveMemory($userId, $recentMessages);
        } catch (Throwable $e) {
            Logger::warning('Memory extraction failed (non-blocking)', ['error' => $e->getMessage(), 'userId' => $userId]);
        }
    }

    // ═══ Tool resolution ═══

    private const ACTION_NAMES_RU = [
        'log_workout' => 'записать тренировку', 'update_training_day' => 'изменить тренировку',
        'add_training_day' => 'добавить тренировку', 'delete_training_day' => 'удалить тренировку',
        'move_training_day' => 'перенести тренировку', 'swap_training_days' => 'поменять тренировки местами',
        'copy_day' => 'скопировать тренировку', 'recalculate_plan' => 'пересчитать план',
        'generate_next_plan' => 'сгенерировать новый план', 'update_profile' => 'обновить профиль',
        'report_health_issue' => 'зарегистрировать проблему со здоровьем',
    ];

    private function writeToolConfirmationStub(string $toolName, string $argsJson): string {
        $actionRu = self::ACTION_NAMES_RU[$toolName] ?? $toolName;
        $args = json_decode($argsJson, true) ?: [];
        return json_encode([
            'status' => 'BLOCKED',
            'reason' => "Действие «{$actionRu}» НЕ выполнено. Необходимо подтверждение пользователя.",
            'action' => $toolName,
            'args' => $args,
            'instruction' => "ДЕЙСТВИЕ ЗАБЛОКИРОВАНО. Ты ОБЯЗАН: "
                . "1) Описать пользователю что именно планируешь сделать (используй слова «записываю», «изменю», «перенесу» и т.п.). "
                . "2) Указать конкретные параметры: дату, дистанцию, темп, тип. "
                . "3) Закончить вопросом «Подтверждаешь?» или «Записать?». "
                . "НЕ говори что действие выполнено — оно НЕ ВЫПОЛНЕНО.",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Store pending action metadata in the last AI message for structured confirmation.
     */
    /**
     * @param array $actions [['tool' => name, 'args' => [...]]] or single action
     */
    private function storePendingAction(int $conversationId, array $actions): void {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, metadata FROM chat_messages WHERE conversation_id = ? AND sender_type = 'ai' ORDER BY id DESC LIMIT 1"
            );
            if (!$stmt) return;
            $stmt->bind_param('i', $conversationId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return;

            $existingMeta = json_decode($row['metadata'] ?? '{}', true) ?: [];
            $existingMeta['pending_action'] = count($actions) === 1 ? $actions[0] : $actions;
            $metaJson = json_encode($existingMeta, JSON_UNESCAPED_UNICODE);
            $upd = $this->db->prepare("UPDATE chat_messages SET metadata = ? WHERE id = ?");
            if ($upd) {
                $msgId = (int) $row['id'];
                $upd->bind_param('si', $metaJson, $msgId);
                $upd->execute();
                $upd->close();
            }
        } catch (Throwable $e) {
            Logger::warning('Failed to store pending action', ['error' => $e->getMessage()]);
        }
    }

    /** @var array Blocked write-tool actions [['tool' => name, 'args' => decoded_args], ...] */
    private array $blockedActions = [];

    private function resolveToolCalls(array $messages, int $userId, array &$toolsUsed = []): array {
        if ((int) env('CHAT_TOOLS_ENABLED', 1) !== 1) return $messages;
        $this->blockedActions = [];

        $tools = $this->toolRegistry->getChatTools();
        $maxToolRounds = 5;
        $toolMessages = $this->promptBuilder->buildToolResolutionMessages($messages);

        for ($round = 0; $round < $maxToolRounds; $round++) {
            try {
                $result = $this->callLlmDirect($toolMessages, $tools);
            } catch (Throwable $e) {
                Logger::warning('Tool resolution failed, streaming without tools', [
                    'error' => $e->getMessage(), 'round' => $round, 'tools_used_so_far' => $toolsUsed,
                ]);
                return $messages;
            }

            $msg = $result['message'] ?? null;
            $toolCalls = $msg['tool_calls'] ?? [];
            $contentText = $msg['content'] ?? '';

            Logger::debug('resolveToolCalls round', [
                'round' => $round, 'has_tool_calls' => !empty($toolCalls), 'tool_calls_count' => count($toolCalls),
                'content_preview' => mb_substr($contentText, 0, 200),
                'tool_names' => array_map(fn($tc) => $tc['function']['name'] ?? '?', $toolCalls),
            ]);

            if (empty($toolCalls)) {
                if (preg_match('/(\w+)\[ARGS\]\s*(\{[^}]+\})/i', $contentText, $textToolMatch)) {
                    $textToolName = $textToolMatch[1];
                    $textToolArgs = $textToolMatch[2];
                    Logger::info('resolveToolCalls: parsed text-based tool call', ['name' => $textToolName, 'args' => $textToolArgs, 'round' => $round]);

                    if (in_array($textToolName, self::WRITE_TOOLS, true)) {
                        Logger::info('Write tool blocked (text-based), returning confirmation stub', ['name' => $textToolName]);
                        $output = $this->writeToolConfirmationStub($textToolName, $textToolArgs);
                        $this->blockedActions[] = ['tool' => $textToolName, 'args' => json_decode($textToolArgs, true) ?: []];
                    } else {
                        echo json_encode(['tool_executing' => $textToolName]) . "\n"; flush();
                        $output = $this->toolRegistry->executeTool($textToolName, $textToolArgs, $userId);
                        $toolsUsed[] = $textToolName;
                    }
                    $fakeId = 'text-' . uniqid();
                    $toolCallMsg = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => $fakeId, 'type' => 'function', 'function' => ['name' => $textToolName, 'arguments' => $textToolArgs]]]];
                    $toolResultMsg = ['role' => 'tool', 'tool_call_id' => $fakeId, 'content' => $output];
                    $messages[] = $toolCallMsg;
                    $messages[] = $toolResultMsg;
                    $toolMessages[] = $toolCallMsg;
                    $toolMessages[] = $toolResultMsg;
                    continue;
                }
                return $messages;
            }

            $assistantMsg = ['role' => 'assistant', 'content' => $contentText, 'tool_calls' => $toolCalls];
            $messages[] = $assistantMsg;
            $toolMessages[] = $assistantMsg;

            foreach ($toolCalls as $tc) {
                $id = $tc['id'] ?? '';
                $fn = $tc['function'] ?? [];
                $name = $fn['name'] ?? '';
                $argsJson = $fn['arguments'] ?? '{}';

                if (in_array($name, self::WRITE_TOOLS, true)) {
                    Logger::info('Write tool blocked, returning confirmation stub', ['name' => $name, 'args' => $argsJson]);
                    $output = $this->writeToolConfirmationStub($name, $argsJson);
                    $this->blockedActions[] = ['tool' => $name, 'args' => json_decode($argsJson, true) ?: []];
                } else {
                    echo json_encode(['tool_executing' => $name]) . "\n"; flush();
                    $output = $this->toolRegistry->executeTool($name, $argsJson, $userId);
                    $toolsUsed[] = $name;
                }
                Logger::debug('Tool result', ['name' => $name, 'args' => $argsJson, 'output_preview' => mb_substr($output, 0, 200)]);
                $toolResultMsg = ['role' => 'tool', 'tool_call_id' => $id, 'content' => $output];
                $messages[] = $toolResultMsg;
                $toolMessages[] = $toolResultMsg;
            }
        }

        return $messages;
    }

    // ═══ LLM calling ═══

    private function callLlm(array $messages, ?int $userId = null): array {
        if ((int) env('CHAT_USE_PLANRUN_AI', 0) === 1) {
            return $this->callPlanRunAIChat($messages);
        }
        $tools = ((int) env('CHAT_TOOLS_ENABLED', 1) === 1) ? $this->toolRegistry->getChatTools() : null;
        $maxToolRounds = 5;
        $totalUsage = [];
        $this->blockedActions = [];

        try {
            $msg = null;
            for ($round = 0; $round <= $maxToolRounds; $round++) {
                $result = $this->callLlmDirect($messages, $tools);
                $msg = $result['message'] ?? null;
                if (isset($result['usage']) && !empty($result['usage'])) $totalUsage = $result['usage'];
                $toolCalls = $msg['tool_calls'] ?? [];
                if (empty($toolCalls)) return ['content' => $msg['content'] ?? '', 'usage' => $totalUsage];

                $messages[] = ['role' => 'assistant', 'content' => $msg['content'] ?? '', 'tool_calls' => $toolCalls];
                foreach ($toolCalls as $tc) {
                    $fn = $tc['function'] ?? [];
                    $name = $fn['name'] ?? '';
                    $argsJson = $fn['arguments'] ?? '{}';
                    if (in_array($name, self::WRITE_TOOLS, true)) {
                        $output = $this->writeToolConfirmationStub($name, $argsJson);
                        $this->blockedActions[] = ['tool' => $name, 'args' => json_decode($argsJson, true) ?: []];
                    } else {
                        $output = $this->toolRegistry->executeTool($name, $argsJson, $userId);
                    }
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'content' => $output];
                }
            }
            return ['content' => ($msg['content'] ?? ''), 'usage' => $totalUsage];
        } catch (Throwable $e) {
            if ((int) env('CHAT_FALLBACK_TO_PLANRUN_AI', 0) === 1) {
                Logger::warning('LLM direct call failed, using PlanRun AI fallback', ['error' => $e->getMessage()]);
                return $this->callPlanRunAIChat($messages);
            }
            throw $e;
        }
    }

    private function callLlmDirect(array $messages, ?array $tools = null): array {
        $url = $this->llmBaseUrl . '/chat/completions';
        $maxTokens = max((int) env('CHAT_MAX_TOKENS', 87000), 1);
        $payload = [
            'model' => $this->llmModel, 'messages' => $messages, 'stream' => false, 'max_tokens' => $maxTokens,
            'chat_template_kwargs' => ['enable_thinking' => false],
        ];
        if ($tools !== null && $tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        $body = json_encode($payload);
        $maxRetries = (int) env('LLM_MAX_RETRIES', 3);
        $retryableCodes = [500, 502, 503, 429];

        $response = false;
        $httpCode = 0;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                Logger::warning('LLM connection error, retry', ['attempt' => $attempt, 'max' => $maxRetries, 'error' => $curlErr]);
                if ($attempt < $maxRetries) { usleep($attempt * 500000); continue; }
                throw new Exception('LLM-сервер недоступен: ' . $curlErr);
            }
            if ($httpCode === 200 && $response !== false) break;
            if (in_array($httpCode, $retryableCodes) && $attempt < $maxRetries) {
                Logger::warning('LLM API error, retry', ['attempt' => $attempt, 'max' => $maxRetries, 'http_code' => $httpCode]);
                usleep($attempt * 500000); continue;
            }
            Logger::error('LLM API error', ['http_code' => $httpCode, 'response' => substr($response ?? '', 0, 500), 'attempt' => $attempt]);
            throw new Exception('LLM-сервер вернул ошибку ' . $httpCode . '.');
        }

        $data = json_decode($response, true);
        if (!$data) throw new Exception('Ошибка ответа LLM-сервера');
        $msg = $data['choices'][0]['message'] ?? [];
        $content = $msg['content'] ?? '';
        $content = preg_replace('/<think>[\s\S]*?<\/think>\s*/i', '', $content) ?? $content;
        $msg['content'] = $content;
        return ['content' => $content, 'message' => $msg, 'usage' => $data['usage'] ?? []];
    }

    private function callPlanRunAIChat(array $messages): array {
        $base = env('PLANRUN_AI_API_URL', 'http://127.0.0.1:8000/api/v1/generate-plan');
        $url = preg_replace('#/generate-plan$#', '/chat', $base);
        $maxTokens = max((int) env('CHAT_MAX_TOKENS', 87000), 1);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['messages' => $messages, 'stream' => false, 'max_tokens' => $maxTokens]), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 5]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($curlErr || $httpCode !== 200 || $response === false) throw new Exception('PlanRun AI недоступен. Запустите: systemctl start planrun-ai');
        $data = json_decode($response, true);
        if (!$data) throw new Exception('Ошибка ответа PlanRun AI');
        return ['content' => $data['content'] ?? '', 'usage' => $data['usage'] ?? []];
    }

    private function callLlmStream(array $messages, callable $onChunk): void {
        if ((int) env('CHAT_USE_PLANRUN_AI', 0) === 1) {
            $this->callPlanRunAIChatStream($messages, $onChunk);
            return;
        }
        try {
            $this->callLlmStreamDirect($messages, $onChunk);
        } catch (Throwable $e) {
            if ((int) env('CHAT_FALLBACK_TO_PLANRUN_AI', 0) === 1) {
                Logger::warning('LLM stream failed, using PlanRun AI fallback', ['error' => $e->getMessage()]);
                $this->callPlanRunAIChatStream($messages, $onChunk);
            } else {
                throw $e;
            }
        }
    }

    private function callLlmStreamDirect(array $messages, callable $onChunk): void {
        $url = $this->llmBaseUrl . '/chat/completions';
        $maxTokens = max((int) env('CHAT_MAX_TOKENS', 87000), 1);
        $body = json_encode(['model' => $this->llmModel, 'messages' => $messages, 'stream' => true, 'max_tokens' => $maxTokens]);
        $maxRetries = (int) env('LLM_MAX_RETRIES', 3);
        $retryableCodes = [500, 502, 503, 429];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $buffer = '';
            $chunksReceived = false;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk, &$buffer, &$chunksReceived) {
                    $buffer .= $data;
                    $lines = explode("\n", $buffer);
                    $buffer = array_pop($lines);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || strpos($line, 'data: ') !== 0) continue;
                        $json = substr($line, 6);
                        if (trim($json) === '[DONE]') continue;
                        $decoded = json_decode($json, true);
                        if (!$decoded || empty($decoded['choices'][0])) continue;
                        $content = $decoded['choices'][0]['delta']['content'] ?? '';
                        if ($content !== '') { $chunksReceived = true; $onChunk($content); }
                    }
                    return strlen($data);
                }
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if (!$curlErr && $httpCode === 200) return;
            if ($chunksReceived) { if ($curlErr) Logger::error('LLM stream interrupted', ['error' => $curlErr]); return; }

            $canRetry = $attempt < $maxRetries;
            if ($curlErr && $canRetry) { Logger::warning('LLM stream error, retry', ['attempt' => $attempt, 'error' => $curlErr]); usleep($attempt * 500000); continue; }
            if (in_array($httpCode, $retryableCodes) && $canRetry) { Logger::warning('LLM stream API error, retry', ['attempt' => $attempt, 'http_code' => $httpCode]); usleep($attempt * 500000); continue; }

            if ($curlErr) throw new Exception('LLM-сервер недоступен: ' . $curlErr);
            throw new Exception('LLM-сервер вернул ошибку ' . $httpCode . '.');
        }
    }

    private function callPlanRunAIChatStream(array $messages, callable $onChunk): void {
        $base = env('PLANRUN_AI_API_URL', 'http://127.0.0.1:8000/api/v1/generate-plan');
        $url = preg_replace('#/generate-plan$#', '/chat', $base);
        $maxTokens = max((int) env('CHAT_MAX_TOKENS', 87000), 1);
        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['messages' => $messages, 'stream' => true, 'max_tokens' => $maxTokens]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 180,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk, &$buffer) {
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $decoded = json_decode($line, true);
                    if ($decoded && isset($decoded['chunk']) && $decoded['chunk'] !== '') $onChunk($decoded['chunk']);
                }
                return strlen($data);
            }
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($curlErr || $httpCode !== 200) throw new Exception('PlanRun AI недоступен. Запустите: systemctl start planrun-ai');
    }

    // ═══ Messaging CRUD ═══

    public function getMessages(int $userId, string $type = 'ai', int $limit = 50, int $offset = 0): array {
        $conversation = $this->repository->getOrCreateConversation($userId, $type);
        $messages = ($type === 'admin') ? $this->repository->getAdminTabMessages($conversation['id'], $userId, $limit, $offset) : $this->repository->getMessages($conversation['id'], $limit, $offset);
        return ['conversation_id' => $conversation['id'], 'messages' => $messages];
    }

    public function clearAiChat(int $userId): void {
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $this->repository->deleteMessagesByConversation($conversation['id']);
        $this->contextBuilder->setHistorySummary($userId, '');
    }

    public function clearAdminTabDialog(int $userId): int {
        $conversation = $this->repository->getOrCreateConversation($userId, 'admin');
        return $this->repository->deleteAdminTabMessages($conversation['id'], $userId);
    }

    public function markAsRead(int $userId, int $conversationId): void {
        $conv = $this->repository->getConversationById($conversationId, $userId);
        if ($conv) $this->repository->markMessagesRead($conversationId);
    }

    public function sendUserMessageToAdmin(int $userId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($userId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);
        $senderUsername = $this->getUsernameById($userId);
        $this->notifyAdminsAboutUserMessage($userId, $senderUsername ?: 'пользователь', $content);
        return ['conversation_id' => $conversation['id'], 'message_id' => $messageId];
    }

    public function sendUserMessageToUser(int $senderUserId, int $targetUserId, string $content): array {
        if ($senderUserId === $targetUserId) throw new InvalidArgumentException('Нельзя отправить сообщение самому себе');
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'user', $senderUserId, $content);
        $this->repository->touchConversation($conversation['id']);
        $senderUsername = $this->getUsernameById($senderUserId);
        $this->sendChatPush($targetUserId, 'Новое сообщение от ' . ($senderUsername ?: 'пользователя'), $content, 'direct', ['sender_name' => $senderUsername ?: 'пользователя']);
        return ['conversation_id' => $conversation['id'], 'message_id' => $messageId];
    }

    public function sendAdminMessage(int $targetUserId, int $adminUserId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'admin', $adminUserId, $content);
        $this->repository->touchConversation($conversation['id']);
        $this->sendChatPush($targetUserId, 'Новое сообщение от администрации', $content, 'admin');
        return ['conversation_id' => $conversation['id'], 'message_id' => $messageId];
    }

    public function getDirectMessagesWithUser(int $currentUserId, int $targetUserId, int $limit = 50, int $offset = 0): array {
        $this->repository->markDirectDialogRead($currentUserId, $targetUserId);
        return ['messages' => $this->repository->getDirectMessagesBetweenUsers($currentUserId, $targetUserId, $limit, $offset), 'conversation_id' => null];
    }

    public function clearDirectDialog(int $currentUserId, int $targetUserId): int {
        if ($currentUserId === $targetUserId) throw new InvalidArgumentException('Нельзя очистить диалог с самим собой');
        return $this->repository->deleteDirectMessagesBetweenUsers($currentUserId, $targetUserId);
    }

    public function clearAdminConversation(int $targetUserId): int {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        return $this->repository->deleteMessagesByConversation($conversation['id']);
    }

    public function getAdminMessages(int $targetUserId, int $limit = 50, int $offset = 0): array {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $this->repository->markUserMessagesReadByAdmin($conversation['id']);
        return ['conversation_id' => $conversation['id'], 'messages' => $this->repository->getMessages($conversation['id'], $limit, $offset)];
    }

    public function getUsersWithAdminChat(): array { return $this->repository->getUsersWithAdminChat(); }
    public function getUsersWhoWroteToMe(int $userId): array { return $this->repository->getUsersWhoWroteToMe($userId); }
    public function getUnreadUserMessagesForAdmin(int $limit = 10): array { return $this->repository->getUnreadUserMessagesForAdmin($limit); }
    public function getAdminUnreadCount(): int { return $this->repository->getAdminUnreadCount(); }
    public function markAllAsRead(int $userId): void { $this->repository->markAllConversationsReadForUser($userId); }
    public function markAllAdminAsRead(): void { $this->repository->markAllAdminUserMessagesRead(); }

    public function markAdminConversationRead(int $targetUserId): void {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $this->repository->markUserMessagesReadByAdmin($conversation['id']);
    }

    public function addAIMessageToUser(int $userId, string $content, array $notificationOptions = []): array {
        $content = trim($content);
        if ($content === '') throw new InvalidArgumentException('Сообщение не может быть пустым');
        if (mb_strlen($content) > 4000) throw new InvalidArgumentException('Сообщение слишком длинное');
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $msgMeta = null;
        if (!empty($notificationOptions['proactive_type'])) {
            $msgMeta = ['proactive_type' => $notificationOptions['proactive_type']];
        }
        $messageId = $this->repository->addMessage($conversation['id'], 'ai', null, $content, $msgMeta);
        $this->repository->touchConversation($conversation['id']);
        $eventKey = trim((string) ($notificationOptions['event_key'] ?? 'chat.ai_message'));
        $title = trim((string) ($notificationOptions['title'] ?? 'Новое сообщение от AI-тренера'));
        $link = trim((string) ($notificationOptions['link'] ?? '/chat'));
        $dispatchOptions = $notificationOptions;
        unset($dispatchOptions['event_key'], $dispatchOptions['title'], $dispatchOptions['link']);
        $this->dispatchNotificationEvent($userId, $eventKey, $title !== '' ? $title : 'Новое сообщение от AI-тренера', $content, $link, $dispatchOptions);
        return ['message_id' => $messageId];
    }

    // ═══ Notifications (private) ═══

    private function sendChatPush(int $userId, string $title, string $body, string $type, array $notificationOptions = []): void {
        try {
            $eventKey = match ($type) { 'admin' => 'chat.admin_message', 'ai' => 'chat.ai_message', 'direct' => 'chat.direct_message', default => 'chat.direct_message' };
            $this->dispatchNotificationEvent($userId, $eventKey, $title, $body, '/chat', $notificationOptions);
        } catch (\Throwable $e) {
            error_log("sendChatPush failed user={$userId}: " . $e->getMessage());
        }
    }

    private function dispatchNotificationEvent(int $userId, string $eventKey, string $title, string $body, string $link = '/chat', array $notificationOptions = []): void {
        require_once __DIR__ . '/NotificationDispatcher.php';
        $truncated = mb_strlen($body) > 100 ? mb_substr($body, 0, 97) . '...' : $body;
        $pushData = is_array($notificationOptions['push_data'] ?? null) ? $notificationOptions['push_data'] : [];
        unset($notificationOptions['push_data']);
        (new NotificationDispatcher($this->db))->dispatchToUser($userId, $eventKey, $title, $truncated, array_merge($notificationOptions, [
            'link' => $link, 'push_data' => array_merge(['type' => 'chat', 'link' => $link], $pushData),
        ]));
    }

    private function notifyAdminsAboutUserMessage(int $senderUserId, string $senderName, string $content): void {
        try {
            require_once __DIR__ . '/NotificationDispatcher.php';
            $dispatcher = new NotificationDispatcher($this->db);
            $title = 'Новое сообщение от ' . $senderName;
            $truncated = mb_strlen($content) > 100 ? mb_substr($content, 0, 97) . '...' : $content;
            foreach ($this->getAdminUserIds() as $adminId) {
                if ($adminId === $senderUserId) continue;
                $dispatcher->dispatchToUser($adminId, 'admin.new_user_message', $title, $truncated, [
                    'link' => '/chat', 'sender_name' => $senderName, 'push_data' => ['type' => 'chat', 'link' => '/chat'],
                ]);
            }
        } catch (\Throwable $e) {
            error_log("notifyAdminsAboutUserMessage failed sender={$senderUserId}: " . $e->getMessage());
        }
    }

    private function getUsernameById(int $userId): ?string {
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['username'] ?? null;
    }

    private function getAdminUserIds(): array {
        $result = $this->db->query("SELECT id FROM users WHERE role = 'admin'");
        if (!$result) return [];
        $ids = [];
        while ($row = $result->fetch_assoc()) $ids[] = (int) ($row['id'] ?? 0);
        return array_values(array_filter($ids, fn($id) => $id > 0));
    }

    public function broadcastAdminMessage(int $adminUserId, string $content, ?array $userIds = null): array {
        if ($userIds === null) $userIds = $this->repository->getAllUserIdsForBroadcast($adminUserId);
        $userIds = array_map('intval', array_filter($userIds, fn($id) => $id > 0 && $id !== $adminUserId));
        $sent = 0;
        foreach ($userIds as $targetUserId) {
            $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
            $this->repository->addMessage($conversation['id'], 'admin', $adminUserId, $content);
            $this->repository->touchConversation($conversation['id']);
            $this->sendChatPush($targetUserId, 'Новое сообщение от администрации', $content, 'admin');
            $sent++;
        }
        return ['sent' => $sent];
    }
}
