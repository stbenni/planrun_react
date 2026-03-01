<?php
/**
 * Контроллер чата: AI и сообщения от админов
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/ChatService.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../user_functions.php';

class ChatController extends BaseController {

    protected $chatService;

    public function __construct($db) {
        parent::__construct($db);
        $this->chatService = new ChatService($db);
    }

    protected function requireAdmin() {
        if (!$this->requireAuth()) return false;
        $user = getCurrentUser();
        if (!$user || ($user['role'] ?? '') !== UserRoles::ADMIN) {
            $this->returnError('Доступ запрещён. Требуется роль администратора.', 403);
            return false;
        }
        return true;
    }

    /**
     * Получить сообщения чата
     * GET chat_get_messages?type=ai|admin&limit=50&offset=0
     */
    public function getMessages() {
        if (!$this->requireAuth()) return;

        $type = $this->getParam('type', 'ai');
        if (!in_array($type, ['ai', 'admin'])) {
            $type = 'ai';
        }
        $limit = min(100, max(1, (int)($this->getParam('limit') ?: 50)));
        $offset = max(0, (int)($this->getParam('offset') ?: 0));

        try {
            $result = $this->chatService->getMessages($this->currentUserId, $type, $limit, $offset);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Отправить сообщение и получить ответ AI (без streaming)
     * POST chat_send_message { "content": "..." }
     */
    public function sendMessage() {
        if (!$this->requireAuth()) return;
        set_time_limit(300);

        $data = $this->getJsonBody();
        $content = trim($data['content'] ?? $data['message'] ?? '');

        if ($content === '') {
            $this->returnError('Сообщение не может быть пустым', 400);
            return;
        }

        if (mb_strlen($content) > 4000) {
            $this->returnError('Сообщение слишком длинное', 400);
            return;
        }

        try {
            $result = $this->chatService->sendMessageAndGetResponse($this->currentUserId, $content);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Отправить сообщение и получить streaming ответ
     * POST chat_send_message_stream { "content": "..." }
     */
    public function sendMessageStream() {
        if (!$this->requireAuth()) return;
        set_time_limit(300);
        ignore_user_abort(true); // ответ ИИ сохраняем в БД даже если пользователь ушёл со страницы

        $data = $this->getJsonBody();
        $content = trim($data['content'] ?? $data['message'] ?? '');

        if ($content === '') {
            $this->returnError('Сообщение не может быть пустым', 400);
            return;
        }

        if (mb_strlen($content) > 4000) {
            $this->returnError('Сообщение слишком длинное', 400);
            return;
        }

        try {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            while (ob_get_level()) ob_end_clean();

            $this->chatService->streamResponse($this->currentUserId, $content);
        } catch (Exception $e) {
            $errorMsg = $e->getMessage() ?: 'Ошибка чата';
            echo json_encode(['error' => $errorMsg]) . "\n";
            flush();
        }
    }

    /**
     * Очистить чат с AI
     * POST chat_clear_ai
     */
    public function clearAiChat() {
        if (!$this->requireAuth()) return;

        try {
            $this->chatService->clearAiChat($this->currentUserId);
            $this->returnSuccess(['ok' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Пользователь: отправить сообщение администрации
     * POST chat_send_message_to_admin { "content": "..." }
     */
    public function sendMessageToAdmin() {
        if (!$this->requireAuth()) return;

        $data = $this->getJsonBody();
        $content = trim($data['content'] ?? $data['message'] ?? '');

        if ($content === '') {
            $this->returnError('Сообщение не может быть пустым', 400);
            return;
        }

        if (mb_strlen($content) > 4000) {
            $this->returnError('Сообщение слишком длинное', 400);
            return;
        }

        try {
            $result = $this->chatService->sendUserMessageToAdmin($this->currentUserId, $content);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Сообщения между текущим пользователем и другим (диалог «Написать»)
     * GET chat_get_direct_messages?target_user_id=123&limit=50&offset=0
     */
    public function getDirectMessages() {
        if (!$this->requireAuth()) return;

        $targetUserId = (int)($this->getParam('target_user_id') ?: 0);
        $limit = min(100, max(1, (int)($this->getParam('limit') ?: 50)));
        $offset = max(0, (int)($this->getParam('offset') ?: 0));

        if ($targetUserId <= 0) {
            $this->returnError('Не указан ID пользователя', 400);
            return;
        }
        if ($this->currentUserId === $targetUserId) {
            $this->returnError('Нельзя загрузить диалог с самим собой', 400);
            return;
        }

        try {
            $result = $this->chatService->getDirectMessagesWithUser($this->currentUserId, $targetUserId, $limit, $offset);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Пользователь: отправить сообщение другому пользователю (от своего имени)
     * POST chat_send_message_to_user { "target_user_id": 123, "content": "..." }
     */
    public function sendMessageToUser() {
        if (!$this->requireAuth()) return;

        $data = $this->getJsonBody();
        $targetUserId = (int)($data['target_user_id'] ?? $data['user_id'] ?? 0);
        $content = trim($data['content'] ?? $data['message'] ?? '');

        if ($targetUserId <= 0) {
            $this->returnError('Не указан ID получателя', 400);
            return;
        }
        if ($content === '') {
            $this->returnError('Сообщение не может быть пустым', 400);
            return;
        }
        if (mb_strlen($content) > 4000) {
            $this->returnError('Сообщение слишком длинное', 400);
            return;
        }

        try {
            $result = $this->chatService->sendUserMessageToUser($this->currentUserId, $targetUserId, $content);
            $this->returnSuccess($result);
        } catch (InvalidArgumentException $e) {
            $this->returnError($e->getMessage(), 400);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Админ: отправить сообщение пользователю
     * POST chat_admin_send_message { "user_id": 123, "content": "..." }
     */
    public function sendAdminMessage() {
        if (!$this->requireAdmin()) return;

        $data = $this->getJsonBody();
        $targetUserId = (int)($data['user_id'] ?? $data['target_user_id'] ?? 0);
        $content = trim($data['content'] ?? $data['message'] ?? '');

        if ($targetUserId <= 0) {
            $this->returnError('Не указан ID пользователя', 400);
            return;
        }
        if ($content === '') {
            $this->returnError('Сообщение не может быть пустым', 400);
            return;
        }
        if (mb_strlen($content) > 4000) {
            $this->returnError('Сообщение слишком длинное', 400);
            return;
        }

        try {
            $result = $this->chatService->sendAdminMessage($targetUserId, $this->currentUserId, $content);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Очистить direct-диалог с пользователем
     * POST chat_clear_direct_dialog
     */
    public function clearDirectDialog() {
        if (!$this->requireAuth()) return;

        $data = $this->getJsonBody();
        $targetUserId = (int)($data['target_user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $this->returnError('Не указан ID пользователя', 400);
            return;
        }

        try {
            $deleted = $this->chatService->clearDirectDialog($this->currentUserId, $targetUserId);
            $this->returnSuccess(['deleted' => $deleted]);
        } catch (InvalidArgumentException $e) {
            $this->returnError($e->getMessage(), 400);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Список диалогов: пользователи, которые писали мне через «Написать»
     * GET chat_get_direct_dialogs
     */
    public function getDirectDialogs() {
        if (!$this->requireAuth()) return;

        try {
            $users = $this->chatService->getUsersWhoWroteToMe($this->currentUserId);
            $this->returnSuccess(['users' => $users]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Админ: список пользователей с активностью в admin-чате
     * GET chat_admin_chat_users
     */
    public function getAdminChatUsers() {
        if (!$this->requireAdmin()) return;

        try {
            $users = $this->chatService->getUsersWithAdminChat();
            $this->returnSuccess(['users' => $users]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Админ: непрочитанные сообщения от пользователей (для уведомлений)
     * GET chat_admin_unread_notifications?limit=10
     */
    public function getAdminUnreadNotifications() {
        if (!$this->requireAdmin()) return;

        $limit = min(20, max(1, (int)($this->getParam('limit') ?: 10)));
        try {
            $messages = $this->chatService->getUnreadUserMessagesForAdmin($limit);
            $this->returnSuccess(['messages' => $messages]);
        } catch (Exception $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error('chat_admin_unread_notifications failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->returnSuccess(['messages' => []]);
        }
    }

    /**
     * Админ: получить сообщения пользователя (admin-чат)
     * GET chat_admin_get_messages?user_id=123
     */
    public function getAdminMessages() {
        if (!$this->requireAdmin()) return;

        $targetUserId = (int)($this->getParam('user_id') ?? 0);
        if ($targetUserId <= 0) {
            $this->returnError('Не указан ID пользователя', 400);
            return;
        }
        $limit = min(100, max(1, (int)($this->getParam('limit') ?: 50)));
        $offset = max(0, (int)($this->getParam('offset') ?: 0));

        try {
            $result = $this->chatService->getAdminMessages($targetUserId, $limit, $offset);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Админ: массовая рассылка сообщения
     * POST chat_admin_broadcast { "content": "...", "user_ids": [1,2,3]? }
     * user_ids — опционально; если не указан, отправляется всем пользователям (кроме отправителя)
     */
    public function broadcastAdminMessage() {
        if (!$this->requireAdmin()) return;

        $data = $this->getJsonBody();
        $content = trim($data['content'] ?? $data['message'] ?? '');

        if ($content === '') {
            $this->returnError('Сообщение не может быть пустым', 400);
            return;
        }
        if (mb_strlen($content) > 4000) {
            $this->returnError('Сообщение слишком длинное', 400);
            return;
        }

        $userIds = null;
        if (isset($data['user_ids']) && is_array($data['user_ids'])) {
            $userIds = array_values(array_map('intval', array_filter($data['user_ids'])));
        }

        try {
            $result = $this->chatService->broadcastAdminMessage($this->currentUserId, $content, $userIds);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Отметить все сообщения как прочитанные во всех чатах
     * POST chat_mark_all_read
     */
    public function markAllRead() {
        if (!$this->requireAuth()) return;

        try {
            $this->chatService->markAllAsRead($this->currentUserId);
            $this->returnSuccess(['ok' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Админ: отметить все сообщения от пользователей как прочитанные
     * POST chat_admin_mark_all_read
     */
    public function markAdminAllRead() {
        if (!$this->requireAdmin()) return;

        try {
            $this->chatService->markAllAdminAsRead();
            $this->returnSuccess(['ok' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Отметить сообщения как прочитанные
     * POST chat_mark_read { "conversation_id": 123 }
     */
    public function markRead() {
        if (!$this->requireAuth()) return;

        $data = $this->getJsonBody();
        $conversationId = (int)($data['conversation_id'] ?? 0);

        if ($conversationId <= 0) {
            $this->returnError('Неверный ID разговора', 400);
            return;
        }

        try {
            $this->chatService->markAsRead($this->currentUserId, $conversationId);
            $this->returnSuccess(['ok' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Админ: отметить диалог с пользователем как прочитанный (при открытии чата с ним)
     * POST chat_admin_mark_conversation_read { "user_id": 123 }
     */
    public function markAdminConversationRead() {
        if (!$this->requireAdmin()) return;

        $data = $this->getJsonBody();
        $targetUserId = (int)($data['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            $this->returnError('Не указан ID пользователя', 400);
            return;
        }

        try {
            $this->chatService->markAdminConversationRead($targetUserId);
            $this->returnSuccess(['ok' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Добавить сообщение от AI пользователю (досыл, напоминание).
     * POST chat_add_ai_message { "user_id": 123, "content": "..." }
     * Только для администратора (или в будущем — вызов от AI-сервиса по ключу).
     */
    public function addAIMessage() {
        if (!$this->requireAdmin()) return;

        $data = $this->getJsonBody();
        $targetUserId = (int)($data['user_id'] ?? 0);
        $content = trim($data['content'] ?? $data['message'] ?? '');

        if ($targetUserId <= 0) {
            $this->returnError('Не указан ID пользователя', 400);
            return;
        }
        if ($content === '') {
            $this->returnError('Сообщение не может быть пустым', 400);
            return;
        }
        if (mb_strlen($content) > 4000) {
            $this->returnError('Сообщение слишком длинное', 400);
            return;
        }

        try {
            $result = $this->chatService->addAIMessageToUser($targetUserId, $content);
            $this->returnSuccess($result);
        } catch (\InvalidArgumentException $e) {
            $this->returnError($e->getMessage(), 400);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
