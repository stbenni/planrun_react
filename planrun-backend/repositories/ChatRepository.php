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
        $rows = $this->fetchAll(
            "SELECT m.id, m.conversation_id, m.sender_type, m.sender_id, m.content, m.created_at, m.read_at, m.metadata,
                    u.username AS sender_username, u.avatar_path AS sender_avatar_path
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id AND m.sender_type = 'user'
             WHERE m.conversation_id = ?
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?",
            [$conversationId, $limit, $offset],
            'iii'
        );
        return $rows;
    }

    /**
     * Сообщения между двумя пользователями (диалог «Написать»)
     * Сообщения хранятся в admin-чате получателя (того, кому писали)
     */
    public function getDirectMessagesBetweenUsers(int $currentUserId, int $targetUserId, int $limit = 50, int $offset = 0): array {
        $convCurrent = $this->getOrCreateConversation($currentUserId, 'admin');
        $convTarget = $this->getOrCreateConversation($targetUserId, 'admin');
        $convId = $convCurrent['id'];
        $check = $this->fetchOne(
            "SELECT conversation_id FROM chat_messages
             WHERE conversation_id IN (?, ?) AND (sender_id = ? OR sender_id = ? OR sender_type = 'admin')
             LIMIT 1",
            [$convCurrent['id'], $convTarget['id'], $currentUserId, $targetUserId],
            'iiii'
        );
        if ($check) {
            $convId = (int)$check['conversation_id'];
        } else {
            $convId = $convTarget['id'];
        }
        $rows = $this->fetchAll(
            "SELECT m.id, m.conversation_id, m.sender_type, m.sender_id, m.content, m.created_at, m.read_at, m.metadata,
                    u.username AS sender_username, u.avatar_path AS sender_avatar_path
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id AND m.sender_type = 'user'
             WHERE m.conversation_id = ? AND (m.sender_id = ? OR m.sender_id = ? OR m.sender_type = 'admin')
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?",
            [$convId, $currentUserId, $targetUserId, $limit, $offset],
            'iiiii'
        );
        return $rows;
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
     * Поиск по сообщениям диалога по ключевым словам (для подстановки релевантных фрагментов в контекст LLM).
     * Возвращает до $limit сообщений, в которых встречается любое из слов. Только этот диалог (conversation_id).
     */
    public function searchInChat(int $conversationId, array $keywords, int $limit = 8): array {
        $keywords = array_slice(array_unique(array_map('trim', $keywords)), 0, 10);
        $keywords = array_filter($keywords, function ($w) {
            return mb_strlen($w) >= 2;
        });
        if (empty($keywords)) {
            return [];
        }
        $placeholders = implode(' OR ', array_fill(0, count($keywords), 'content LIKE ?'));
        $types = 'i' . str_repeat('s', count($keywords));
        $params = [$conversationId];
        foreach ($keywords as $w) {
            $params[] = '%' . $w . '%';
        }
        $params[] = $limit;
        $sql = "SELECT id, sender_type, content, created_at 
                FROM chat_messages 
                WHERE conversation_id = ? AND ({$placeholders}) 
                ORDER BY created_at DESC 
                LIMIT ?";
        return $this->fetchAll($sql, $params, $types . 'i');
    }

    /**
     * Удалить все сообщения из разговора (очистка чата)
     */
    public function deleteMessagesByConversation(int $conversationId): void {
        $this->execute("DELETE FROM chat_messages WHERE conversation_id = ?", [$conversationId], 'i');
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
                 WHERE conversation_id = ? AND read_at IS NULL 
                 AND (sender_type != 'user' OR (sender_type = 'user' AND sender_id != ?))",
                [$conv['id'], $userId],
                'ii'
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
             WHERE c.type = 'admin' AND m.sender_type = 'user' AND m.sender_id = c.user_id
             GROUP BY c.user_id, u.id, u.username, u.email, u.avatar_path
             ORDER BY last_message_at DESC",
            [],
            ''
        );
        return $rows;
    }

    /**
     * Диалоги: пользователи, которые писали мне ИЛИ которым я писал (через «Написать»)
     */
    public function getUsersWhoWroteToMe(int $currentUserId): array {
        $rowsToMe = $this->fetchAll(
            "SELECT u.id AS user_id, u.username, u.username_slug, u.email, u.avatar_path, MAX(m.created_at) AS last_message_at
             FROM chat_conversations c
             INNER JOIN chat_messages m ON m.conversation_id = c.id
             INNER JOIN users u ON u.id = m.sender_id
             WHERE c.type = 'admin' AND c.user_id = ? AND m.sender_type = 'user' AND m.sender_id != ?
             GROUP BY m.sender_id, u.id, u.username, u.username_slug, u.email, u.avatar_path",
            [$currentUserId, $currentUserId],
            'ii'
        );
        $rowsFromMe = $this->fetchAll(
            "SELECT u.id AS user_id, u.username, u.username_slug, u.email, u.avatar_path, MAX(m.created_at) AS last_message_at
             FROM chat_conversations c
             INNER JOIN chat_messages m ON m.conversation_id = c.id
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.type = 'admin' AND m.sender_type = 'user' AND m.sender_id = ? AND c.user_id != ?
             GROUP BY c.user_id, u.id, u.username, u.username_slug, u.email, u.avatar_path",
            [$currentUserId, $currentUserId],
            'ii'
        );
        $byUser = [];
        foreach (array_merge($rowsToMe, $rowsFromMe) as $r) {
            $id = (int)$r['user_id'];
            $ts = strtotime($r['last_message_at'] ?? '0');
            if (!isset($byUser[$id]) || $ts > strtotime($byUser[$id]['last_message_at'] ?? '0')) {
                $byUser[$id] = $r;
            }
        }
        $merged = array_values($byUser);
        $unreadByPartner = $this->getUnreadCountsPerDirectDialogPartner($currentUserId);
        foreach ($merged as &$row) {
            $row['unread_count'] = (int)($unreadByPartner[(int)$row['user_id']] ?? 0);
        }
        unset($row);
        usort($merged, function ($a, $b) {
            return strcmp($b['last_message_at'] ?? '', $a['last_message_at'] ?? '');
        });
        return $merged;
    }

    /**
     * Непрочитанные сообщения по диалогам: сколько непрочитанных от каждого пользователя в моём admin-чате
     */
    public function getUnreadCountsPerDirectDialogPartner(int $currentUserId): array {
        $rows = $this->fetchAll(
            "SELECT m.sender_id, COUNT(*) AS cnt
             FROM chat_conversations c
             INNER JOIN chat_messages m ON m.conversation_id = c.id
             WHERE c.type = 'admin' AND c.user_id = ? AND m.sender_type = 'user' AND m.sender_id != ? AND m.read_at IS NULL
             GROUP BY m.sender_id",
            [$currentUserId, $currentUserId],
            'ii'
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['sender_id']] = (int)$r['cnt'];
        }
        return $result;
    }

    /**
     * Отметить сообщения от пользователя в моём admin-чате как прочитанные (при открытии диалога)
     */
    public function markDirectDialogRead(int $currentUserId, int $partnerUserId): void {
        $conv = $this->getOrCreateConversation($currentUserId, 'admin');
        $this->execute(
            "UPDATE chat_messages SET read_at = NOW()
             WHERE conversation_id = ? AND sender_type = 'user' AND sender_id = ? AND read_at IS NULL",
            [$conv['id'], $partnerUserId],
            'ii'
        );
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
             WHERE c.type = 'admin' AND m.sender_type = 'user' AND m.sender_id = c.user_id AND m.read_at IS NULL
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
             WHERE c.type = 'admin' AND m.sender_type = 'user' AND m.sender_id = c.user_id AND m.read_at IS NULL",
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
    public function markMessagesRead(int $conversationId, int $beforeId = 0, ?int $conversationOwnerId = null): void {
        $conv = $this->fetchOne("SELECT user_id FROM chat_conversations WHERE id = ?", [$conversationId], 'i');
        $ownerId = $conversationOwnerId ?? (int)(is_array($conv) && isset($conv['user_id']) ? $conv['user_id'] : 0);
        if ($beforeId > 0) {
            $this->execute(
                "UPDATE chat_messages SET read_at = NOW() 
                 WHERE conversation_id = ? AND id <= ? AND read_at IS NULL 
                 AND (sender_type != 'user' OR (sender_type = 'user' AND sender_id != ?))",
                [$conversationId, $beforeId, $ownerId],
                'iii'
            );
        } else {
            $this->execute(
                "UPDATE chat_messages SET read_at = NOW() 
                 WHERE conversation_id = ? AND read_at IS NULL 
                 AND (sender_type != 'user' OR (sender_type = 'user' AND sender_id != ?))",
                [$conversationId, $ownerId],
                'ii'
            );
        }
    }
}
