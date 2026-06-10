<?php
/**
 * Единый производитель уведомлений: пишет in-app строку в plan_notifications
 * (со свёрткой по ref_key) И доставляет через NotificationDispatcher.
 * Через него идут план/тренерские события (PlanNotificationService) и чат (ChatService).
 */

require_once __DIR__ . '/NotificationDispatcher.php';

class NotificationService {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * @param int    $userId
     * @param string $eventKey  ключ события (для доставки и таксономии)
     * @param string $title     заголовок для push/доставки
     * @param string $body      текст
     * @param array  $opts      [
     *   'type'            => stored type (по умолчанию = $eventKey),
     *   'ref_key'         => ключ свёртки (напр. "chat:123"); если задан — UPSERT,
     *   'category'        => явная категория для фида (chat→ai|coach),
     *   'link'            => куда вести,
     *   'metadata'        => доп. данные для фида,
     *   'dispatch'        => bool (по умолчанию true) — слать ли доставку,
     *   'dispatch_options'=> опции для NotificationDispatcher,
     * ]
     */
    public function create(int $userId, string $eventKey, string $title, string $body, array $opts = []): void {
        if ($userId <= 0 || $eventKey === '') {
            return;
        }

        $storedType = isset($opts['type']) && $opts['type'] !== '' ? (string) $opts['type'] : $eventKey;
        $refKey = isset($opts['ref_key']) && $opts['ref_key'] !== '' ? (string) $opts['ref_key'] : null;
        $metadata = is_array($opts['metadata'] ?? null) ? $opts['metadata'] : [];
        if (!empty($opts['link'])) {
            $metadata['link'] = (string) $opts['link'];
        }
        if (!empty($opts['category'])) {
            $metadata['category'] = (string) $opts['category'];
        }
        $metaJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;

        $this->storeRow($userId, $storedType, $body, $metaJson, $refKey);

        if (($opts['dispatch'] ?? true) !== false) {
            try {
                $dispatchOptions = is_array($opts['dispatch_options'] ?? null) ? $opts['dispatch_options'] : [];
                if (!empty($opts['link'])) {
                    $dispatchOptions['link'] = (string) $opts['link'];
                }
                (new NotificationDispatcher($this->db))->dispatchToUser($userId, $eventKey, $title, $body, $dispatchOptions);
            } catch (\Throwable $e) {
                // Внешняя доставка не должна ломать in-app запись.
            }
        }
    }

    /** Пометить прочитанной свёрнутую нотификацию по ref_key (напр. при открытии чата). */
    public function markReadByRefKey(int $userId, string $refKey): void {
        if ($userId <= 0 || $refKey === '') {
            return;
        }
        $stmt = $this->db->prepare("UPDATE plan_notifications SET read_at = NOW() WHERE user_id = ? AND ref_key = ? AND read_at IS NULL");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('is', $userId, $refKey);
        $stmt->execute();
        $stmt->close();
    }

    private function storeRow(int $userId, string $type, string $message, ?string $metaJson, ?string $refKey): void {
        if ($refKey !== null) {
            $sql = "INSERT INTO plan_notifications (user_id, type, message, metadata, ref_key)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        type = VALUES(type),
                        message = VALUES(message),
                        metadata = VALUES(metadata),
                        created_at = NOW(),
                        read_at = NULL";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('issss', $userId, $type, $message, $metaJson, $refKey);
        } else {
            $sql = "INSERT INTO plan_notifications (user_id, type, message, metadata) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('isss', $userId, $type, $message, $metaJson);
        }
        $stmt->execute();
        $stmt->close();
    }
}
