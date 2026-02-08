<?php
/**
 * Сервис чата: отправка сообщений, вызов Ollama, streaming
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';
require_once __DIR__ . '/ChatContextBuilder.php';
require_once __DIR__ . '/../config/Logger.php';

class ChatService extends BaseService {

    private $repository;
    private $contextBuilder;
    private $ollamaBaseUrl;
    private $ollamaModel;

    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new ChatRepository($db);
        $this->contextBuilder = new ChatContextBuilder($db);
        $this->ollamaBaseUrl = rtrim(env('OLLAMA_BASE_URL', 'http://localhost:11434'), '/');
        $this->ollamaModel = env('OLLAMA_CHAT_MODEL', 'deepseek-r1');
    }

    /**
     * Отправить сообщение пользователя и получить ответ от AI
     * Возвращает полный ответ (без streaming) для сохранения в БД
     */
    public function sendMessageAndGetResponse(int $userId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $history = $this->repository->getMessagesAscending($conversation['id'], 20);

        $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);

        $context = $this->contextBuilder->buildContextForUser($userId);
        $messages = $this->buildOllamaMessages($context, $history, $content);

        $response = $this->callOllama($messages);

        $fullContent = $response['content'] ?? '';
        if ($fullContent !== '') {
            $this->repository->addMessage($conversation['id'], 'ai', null, $fullContent, [
                'model' => $this->ollamaModel,
                'eval_count' => $response['eval_count'] ?? null
            ]);
            $this->repository->touchConversation($conversation['id']);
        }

        return [
            'content' => $fullContent,
            'message_id' => $this->db->insert_id ?? null
        ];
    }

    /**
     * Вызвать Ollama с streaming — выводит NDJSON в stdout
     */
    public function streamResponse(int $userId, string $content): void {
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $history = $this->repository->getMessagesAscending($conversation['id'], 20);

        $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);

        $context = '';
        try {
            $context = $this->contextBuilder->buildContextForUser($userId);
        } catch (Throwable $e) {
            Logger::warning('ChatContextBuilder failed, using minimal context', ['error' => $e->getMessage()]);
            $context = "═══ ПРОФИЛЬ ═══\nДанные контекста временно недоступны.";
        }

        $messages = $this->buildOllamaMessages($context, $history, $content);

        $chunks = [];
        $this->callOllamaStream($messages, function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
            echo json_encode(['chunk' => $chunk]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        });

        $fullContent = implode('', $chunks);
        if ($fullContent !== '') {
            $this->repository->addMessage($conversation['id'], 'ai', null, $fullContent, [
                'model' => $this->ollamaModel
            ]);
            $this->repository->touchConversation($conversation['id']);
        }
    }

    private function buildOllamaMessages(string $context, array $history, string $currentQuestion): array {
        $systemContent = "Ты персональный AI-тренер PlanRun. Отвечай на русском языке.\n";
        $systemContent .= "Ты знаешь профиль, план и статистику пользователя. Используй только данные из контекста.\n\n";
        $systemContent .= $context;

        $messages = [];

        $historyContent = "";
        foreach ($history as $m) {
            $role = $m['sender_type'] === 'user' ? 'user' : 'assistant';
            $historyContent .= ($role === 'user' ? "Пользователь: " : "Ассистент: ") . trim($m['content']) . "\n";
        }

        $userContent = $historyContent . "\nПользователь: " . trim($currentQuestion);

        $messages[] = ['role' => 'user', 'content' => $systemContent . "\n\n═══ ИСТОРИЯ РАЗГОВОРА ═══\n\n" . $userContent];

        return $messages;
    }

    private function callOllama(array $messages): array {
        $url = $this->ollamaBaseUrl . '/api/chat';
        $body = json_encode([
            'model' => $this->ollamaModel,
            'messages' => $messages,
            'stream' => false
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            Logger::error('Ollama API error', ['http_code' => $httpCode, 'response' => substr($response ?? '', 0, 500)]);
            $this->throwException('Сервис AI временно недоступен. Попробуйте позже.', 503);
        }

        $data = json_decode($response, true);
        if (!$data) {
            $this->throwException('Ошибка ответа AI', 500);
        }

        return [
            'content' => $data['message']['content'] ?? '',
            'eval_count' => $data['eval_count'] ?? null
        ];
    }

    private function callOllamaStream(array $messages, callable $onChunk): void {
        $url = $this->ollamaBaseUrl . '/api/chat';
        $body = json_encode([
            'model' => $this->ollamaModel,
            'messages' => $messages,
            'stream' => true
        ]);

        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk, &$buffer) {
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $decoded = json_decode($line, true);
                    if ($decoded && isset($decoded['message']['content'])) {
                        $onChunk($decoded['message']['content']);
                    }
                }
                return strlen($data);
            },
            CURLOPT_TIMEOUT => 180
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            Logger::error('Ollama stream connection error', ['error' => $curlErr, 'url' => $url]);
            throw new Exception('Не удалось подключиться к Ollama. Проверьте, что Ollama запущен (ollama serve) и OLLAMA_BASE_URL в .env указан верно.');
        }
        if ($httpCode !== 200) {
            Logger::error('Ollama stream API error', ['http_code' => $httpCode, 'url' => $url]);
            throw new Exception('Сервис AI вернул ошибку ' . $httpCode . '. Проверьте модель (OLLAMA_CHAT_MODEL) и что Ollama запущен.');
        }
    }

    /**
     * Получить сообщения разговора
     */
    public function getMessages(int $userId, string $type = 'ai', int $limit = 50, int $offset = 0): array {
        $conversation = $this->repository->getOrCreateConversation($userId, $type);
        $messages = $this->repository->getMessages($conversation['id'], $limit, $offset);
        return [
            'conversation_id' => $conversation['id'],
            'messages' => $messages
        ];
    }

    /**
     * Отметить сообщения как прочитанные (admin)
     */
    public function markAsRead(int $userId, int $conversationId): void {
        $conv = $this->repository->getConversationById($conversationId, $userId);
        if ($conv) {
            $this->repository->markMessagesRead($conversationId);
        }
    }

    /**
     * Пользователь: отправить сообщение администрации
     * Сообщение добавляется в admin-чат пользователя (админы увидят в админ-панели)
     */
    public function sendUserMessageToAdmin(int $userId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($userId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);
        return [
            'conversation_id' => $conversation['id'],
            'message_id' => $messageId
        ];
    }

    /**
     * Админ: отправить сообщение пользователю
     */
    public function sendAdminMessage(int $targetUserId, int $adminUserId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'admin', $adminUserId, $content);
        $this->repository->touchConversation($conversation['id']);
        return [
            'conversation_id' => $conversation['id'],
            'message_id' => $messageId
        ];
    }

    /**
     * Админ: получить сообщения пользователя (admin-чат)
     * При запросе сообщений помечает входящие от пользователя как прочитанные
     */
    public function getAdminMessages(int $targetUserId, int $limit = 50, int $offset = 0): array {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $this->repository->markUserMessagesReadByAdmin($conversation['id']);
        return $this->getMessages($targetUserId, 'admin', $limit, $offset);
    }

    /**
     * Админ: список пользователей, которые писали в admin-чат
     */
    public function getUsersWithAdminChat(): array {
        return $this->repository->getUsersWithAdminChat();
    }

    /**
     * Админ: непрочитанные сообщения от пользователей (для уведомлений)
     */
    public function getUnreadUserMessagesForAdmin(int $limit = 10): array {
        return $this->repository->getUnreadUserMessagesForAdmin($limit);
    }

    /**
     * Админ: счётчик непрочитанных сообщений от пользователей
     */
    public function getAdminUnreadCount(): int {
        return $this->repository->getAdminUnreadCount();
    }

    /**
     * Отметить все сообщения как прочитанные (для пользователя — во всех его чатах)
     */
    public function markAllAsRead(int $userId): void {
        $this->repository->markAllConversationsReadForUser($userId);
    }

    /**
     * Админ: отметить все сообщения от пользователей как прочитанные
     */
    public function markAllAdminAsRead(): void {
        $this->repository->markAllAdminUserMessagesRead();
    }

    /**
     * Админ: массовая рассылка сообщения пользователям
     * @param int $adminUserId ID админа (отправителя)
     * @param string $content Текст сообщения
     * @param array|null $userIds Список ID получателей или null = всем пользователям (кроме админа)
     * @return array ['sent' => N]
     */
    public function broadcastAdminMessage(int $adminUserId, string $content, ?array $userIds = null): array {
        if ($userIds === null) {
            $userIds = $this->repository->getAllUserIdsForBroadcast($adminUserId);
        }
        $userIds = array_map('intval', array_filter($userIds, fn($id) => $id > 0 && $id !== $adminUserId));
        $sent = 0;
        foreach ($userIds as $targetUserId) {
            $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
            $this->repository->addMessage($conversation['id'], 'admin', $adminUserId, $content);
            $this->repository->touchConversation($conversation['id']);
            $sent++;
        }
        return ['sent' => $sent];
    }
}
