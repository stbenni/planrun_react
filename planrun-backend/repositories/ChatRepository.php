<?php
/**
 * Репозиторий для работы с чатом
 */

require_once __DIR__ . '/BaseRepository.php';

class ChatRepository extends BaseRepository {

    /**
     * Получить или создать разговор пользователя
     */
    public function getOrCreateConversation(int $userId, string $type = 'ai'): array {
        $row = $this->fetchOne(
            "SELECT id, user_id, type, created_at, updated_at FROM chat_conversations WHERE user_id = ? AND type = ?",
            [$userId, $type],
            'is'
        );
        if ($row) {
            return $row;
        }
        $this->execute(
            "INSERT INTO chat_conversations (user_id, type) VALUES (?, ?)",
            [$userId, $type],
            'is'
        );
        $id = $this->db->insert_id;
        return $this->fetchOne("SELECT id, user_id, type, created_at, updated_at FROM chat_conversations WHERE id = ?", [$id], 'i');
    }

    /**
     * Получить разговор по ID (с проверкой принадлежности пользователю)
     */
    public function getConversationById(int $conversationId, int $userId): ?array {
        return $this->fetchOne(
            "SELECT id, user_id, type, created_at, updated_at FROM chat_conversations WHERE id = ? AND user_id = ?",
            [$conversationId, $userId],
            'ii'
        );
    }

    /**
     * Получить последние сообщения разговора (в хронологическом порядке)
     */
    public function getMessages(int $conversationId, int $limit = 20, int $offset = 0): array {
        return $this->fetchAll(
            "SELECT id, conversation_id, sender_type, sender_id, content, created_at, read_at, metadata 
             FROM chat_messages 
             WHERE conversation_id = ? 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?",
            [$conversationId, $limit, $offset],
            'iii'
        );
    }

    /**
     * Получить сообщения в прямом порядке (для контекста AI)
     */
    public function getMessagesAscending(int $conversationId, int $limit = 20): array {
        $rows = $this->fetchAll(
            "SELECT id, conversation_id, sender_type, sender_id, content, created_at 
             FROM chat_messages 
             WHERE conversation_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$conversationId, $limit],
            'ii'
        );
        return array_reverse($rows);
    }

    /**
     * Добавить сообщение
     */
    public function addMessage(int $conversationId, string $senderType, ?int $senderId, string $content, ?array $metadata = null): int {
        $metaJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : '{}';
        $sid = $senderId ?? 0;
        $this->execute(
            "INSERT INTO chat_messages (conversation_id, sender_type, sender_id, content, metadata) VALUES (?, ?, ?, ?, ?)",
            [$conversationId, $senderType, $sid, $content, $metaJson],
            'isiss'
        );
        return (int)$this->db->insert_id;
    }

    /**
     * Обновить updated_at разговора
     */
    public function touchConversation(int $conversationId): void {
        $this->execute(
            "UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?",
            [$conversationId],
            'i'
        );
    }

    /**
     * Универсальный счётчик непрочитанных сообщений по всем разговорам пользователя
     * Возвращает: ['total' => N, 'by_type' => ['admin' => X, 'ai' => Y, 'coach' => Z, ...]]
     * Поддерживает admin, ai, coach, direct (для будущих типов)
     */
    public function getUnreadCounts(int $userId): array {
        $convs = $this->fetchAll(
            "SELECT id, type FROM chat_conversations WHERE user_id = ?",
            [$userId],
            'i'
        );
        $byType = [];
        $total = 0;

        foreach ($convs as $conv) {
            $row = $this->fetchOne(
                "SELECT COUNT(*) as cnt FROM chat_messages 
                 WHERE conversation_id = ? AND sender_type != 'user' AND read_at IS NULL",
                [$conv['id']],
                'i'
            );
            $cnt = (int)($row['cnt'] ?? 0);
            $byType[$conv['type']] = $cnt;
            $total += $cnt;
        }

        return ['total' => $total, 'by_type' => $byType];
    }

    /**
     * ID всех пользователей для рассылки (кроме указанного, обычно отправителя)
     */
    public function getAllUserIdsForBroadcast(?int $excludeUserId = null): array {
        $sql = "SELECT id FROM users";
        $params = [];
        $types = '';
        if ($excludeUserId !== null && $excludeUserId > 0) {
            $sql .= " WHERE id != ?";
            $params = [$excludeUserId];
            $types = 'i';
        }
        $rows = $this->fetchAll($sql . " ORDER BY id", $params, $types);
        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Получить пользователей, которые написали в admin-чат (есть хотя бы одно сообщение от пользователя)
     * Массовые рассылки (sender_type='admin') не считаются началом диалога
     */
    public function getUsersWithAdminChat(): array {
        $rows = $this->fetchAll(
            "SELECT u.id AS user_id, u.username, u.email, u.avatar_path, MAX(m.created_at) AS last_message_at
             FROM chat_conversations c
             INNER JOIN chat_messages m ON m.conversation_id = c.id
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.type = 'admin' AND m.sender_type = 'user'
             GROUP BY c.user_id, u.id, u.username, u.email, u.avatar_path
             ORDER BY last_message_at DESC",
            [],
            ''
        );
        return $rows;
    }

    /**
     * Непрочитанные сообщения от пользователей в admin-чатах (для админа)
     * Возвращает последние N непрочитанных сообщений с user_id, username, content, created_at
     */
    public function getUnreadUserMessagesForAdmin(int $limit = 10): array {
        return $this->fetchAll(
            "SELECT m.id, m.conversation_id, m.content, m.created_at, c.user_id, u.username
             FROM chat_messages m
             INNER JOIN chat_conversations c ON c.id = m.conversation_id
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.type = 'admin' AND m.sender_type = 'user' AND m.read_at IS NULL
             ORDER BY m.created_at DESC
             LIMIT ?",
            [$limit],
            'i'
        );
    }

    /**
     * Счётчик непрочитанных сообщений от пользователей для админа
     */
    public function getAdminUnreadCount(): int {
        $row = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM chat_messages m
             INNER JOIN chat_conversations c ON c.id = m.conversation_id
             WHERE c.type = 'admin' AND m.sender_type = 'user' AND m.read_at IS NULL",
            [],
            ''
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Отметить сообщения пользователя как прочитанные (когда админ открыл чат)
     */
    public function markUserMessagesReadByAdmin(int $conversationId): void {
        $this->execute(
            "UPDATE chat_messages SET read_at = NOW() 
             WHERE conversation_id = ? AND sender_type = 'user' AND read_at IS NULL",
            [$conversationId],
            'i'
        );
    }

    /**
     * Отметить все входящие сообщения как прочитанные во всех разговорах пользователя
     */
    public function markAllConversationsReadForUser(int $userId): void {
        $convs = $this->fetchAll("SELECT id FROM chat_conversations WHERE user_id = ?", [$userId], 'i');
        foreach ($convs as $conv) {
            $this->markMessagesRead((int)$conv['id']);
        }
    }

    /**
     * Отметить все сообщения от пользователей во всех admin-чатах как прочитанные (для админа)
     */
    public function markAllAdminUserMessagesRead(): void {
        $convs = $this->fetchAll("SELECT id FROM chat_conversations WHERE type = 'admin'", [], '');
        foreach ($convs as $conv) {
            $this->markUserMessagesReadByAdmin((int)$conv['id']);
        }
    }

    /**
     * Отметить входящие сообщения как прочитанные (универсально для admin, ai, coach, direct)
     */
    public function markMessagesRead(int $conversationId, int $beforeId = 0): void {
        if ($beforeId > 0) {
            $this->execute(
                "UPDATE chat_messages SET read_at = NOW() 
                 WHERE conversation_id = ? AND id <= ? AND sender_type != 'user' AND read_at IS NULL",
                [$conversationId, $beforeId],
                'ii'
            );
        } else {
            $this->execute(
                "UPDATE chat_messages SET read_at = NOW() 
                 WHERE conversation_id = ? AND sender_type != 'user' AND read_at IS NULL",
                [$conversationId],
                'i'
            );
        }
    }
}
