<?php
/**
 * Репозиторий для закрытых уведомлений (синхронизация между устройствами)
 */

require_once __DIR__ . '/BaseRepository.php';

class NotificationRepository extends BaseRepository {

    /**
     * Получить список ID закрытых уведомлений для пользователя
     * @return string[]
     */
    public function getDismissedIds(int $userId): array {
        $rows = $this->fetchAll(
            "SELECT notification_id FROM notification_dismissals WHERE user_id = ? ORDER BY dismissed_at DESC",
            [$userId],
            'i'
        );
        return array_column($rows, 'notification_id');
    }

    /**
     * Отметить уведомление как закрытое
     */
    public function dismiss(int $userId, string $notificationId): void {
        $this->execute(
            "INSERT IGNORE INTO notification_dismissals (user_id, notification_id) VALUES (?, ?)",
            [$userId, $notificationId],
            'is'
        );
    }
}
