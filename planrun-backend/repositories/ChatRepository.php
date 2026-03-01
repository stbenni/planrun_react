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
     * A→B хранится в admin-конверсации B (sender_id=A), B→A — в admin-конверсации A (sender_id=B).
     * Запрашиваем ОБЕ конверсации, чтобы собрать полный диалог.
     */
    public function getDirectMessagesBetweenUsers(int $currentUserId, int $targetUserId, int $limit = 50, int $offset = 0): array {
        $convCurrent = $this->getOrCreateConversation($currentUserId, 'admin');
        $convTarget = $this->getOrCreateConversation($targetUserId, 'admin');

        $rows = $this->fetchAll(
            "SELECT m.id, m.conversation_id, m.sender_type, m.sender_id, m.content, m.created_at, m.read_at, m.metadata,
                    u.username AS sender_username, u.avatar_path AS sender_avatar_path
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id AND m.sender_type = 'user'
             WHERE m.sender_type = 'user' AND (
                 (m.conversation_id = ? AND m.sender_id = ?)
                 OR
                 (m.conversation_id = ? AND m.sender_id = ?)
             )
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?",
            [$convTarget['id'], $currentUserId, $convCurrent['id'], $targetUserId, $limit, $offset],
            'iiiiii'
        );
        return $rows;
    }

    /**
     * Сообщения для вкладки «От администрации»: только admin→user и user→admin (свои).
     * Исключаются direct-сообщения от других пользователей.
     */
    public function getAdminTabMessages(int $conversationId, int $userId, int $limit = 20, int $offset = 0): array {
        return $this->fetchAll(
            "SELECT m.id, m.conversation_id, m.sender_type, m.sender_id, m.content, m.created_at, m.read_at, m.metadata,
                    u.username AS sender_username, u.avatar_path AS sender_avatar_path
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id AND m.sender_type = 'user'
             WHERE m.conversation_id = ? AND (m.sender_type = 'admin' OR (m.sender_type = 'user' AND m.sender_id = ?))
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?",
            [$conversationId, $userId, $limit, $offset],
            'iiii'
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
        $rows = $this->fetchAll(
            "SELECT
                CASE
                    WHEN c.type = 'ai' THEN 'ai'
                    WHEN c.type = 'admin' AND m.sender_type = 'admin' THEN 'admin'
                    WHEN c.type = 'admin' AND m.sender_type = 'user' AND m.sender_id != ? THEN 'direct'
                    ELSE c.type
                END AS msg_type,
                COUNT(m.id) AS cnt
             FROM chat_conversations c
             INNER JOIN chat_messages m ON m.conversation_id = c.id
             WHERE c.user_id = ? AND m.read_at IS NULL
               AND NOT (m.sender_type = 'user' AND m.sender_id = ?)
             GROUP BY msg_type",
            [$userId, $userId, $userId],
            'iii'
        );
        $byType = [];
        $total = 0;
        foreach ($rows as $row) {
            $cnt = (int)($row['cnt'] ?? 0);
            $byType[$row['msg_type']] = $cnt;
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
     * Удалить все direct-сообщения между двумя пользователями.
     * Удаляет: сообщения currentUser→target (в конверсации target) и target→currentUser (в конверсации currentUser).
     */
    public function deleteDirectMessagesBetweenUsers(int $currentUserId, int $targetUserId): int {
        $convCurrent = $this->getOrCreateConversation($currentUserId, 'admin');
        $convTarget = $this->getOrCreateConversation($targetUserId, 'admin');

        $stmt = $this->db->prepare(
            "DELETE FROM chat_messages
             WHERE sender_type = 'user' AND (
                 (conversation_id = ? AND sender_id = ?)
                 OR
                 (conversation_id = ? AND sender_id = ?)
             )"
        );
        $convTargetId = (int)$convTarget['id'];
        $convCurrentId = (int)$convCurrent['id'];
        $stmt->bind_param('iiii', $convTargetId, $currentUserId, $convCurrentId, $targetUserId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
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
