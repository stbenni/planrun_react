<?php
/**
 * Репозиторий для работы с заметками к дням и неделям плана
 */

require_once __DIR__ . '/BaseRepository.php';

class NoteRepository extends BaseRepository {

    /**
     * Получить заметки к дню
     */
    public function getDayNotes($userId, $date) {
        $sql = "SELECT n.id, n.author_id, n.content, n.created_at, n.updated_at,
                       u.username AS author_username, u.avatar_path AS author_avatar
                FROM plan_day_notes n
                JOIN users u ON n.author_id = u.id
                WHERE n.user_id = ? AND n.date = ?
                ORDER BY n.created_at ASC";
        return $this->fetchAll($sql, [$userId, $date], 'is');
    }

    /**
     * Добавить заметку к дню
     */
    public function addDayNote($userId, $authorId, $date, $content) {
        $sql = "INSERT INTO plan_day_notes (user_id, author_id, date, content) VALUES (?, ?, ?, ?)";
        return $this->execute($sql, [$userId, $authorId, $date, $content], 'iiss');
    }

    /**
     * Обновить заметку к дню
     */
    public function updateDayNote($noteId, $authorId, $content) {
        $sql = "UPDATE plan_day_notes SET content = ? WHERE id = ? AND author_id = ?";
        return $this->execute($sql, [$content, $noteId, $authorId], 'sii');
    }

    /**
     * Удалить заметку к дню (только автор)
     */
    public function deleteDayNote($noteId, $authorId) {
        $sql = "DELETE FROM plan_day_notes WHERE id = ? AND author_id = ?";
        return $this->execute($sql, [$noteId, $authorId], 'ii');
    }

    /**
     * Получить заметки к неделе
     */
    public function getWeekNotes($userId, $weekStart) {
        $sql = "SELECT n.id, n.author_id, n.content, n.created_at, n.updated_at,
                       u.username AS author_username, u.avatar_path AS author_avatar
                FROM plan_week_notes n
                JOIN users u ON n.author_id = u.id
                WHERE n.user_id = ? AND n.week_start = ?
                ORDER BY n.created_at ASC";
        return $this->fetchAll($sql, [$userId, $weekStart], 'is');
    }

    /**
     * Добавить заметку к неделе
     */
    public function addWeekNote($userId, $authorId, $weekStart, $content) {
        $sql = "INSERT INTO plan_week_notes (user_id, author_id, week_start, content) VALUES (?, ?, ?, ?)";
        return $this->execute($sql, [$userId, $authorId, $weekStart, $content], 'iiss');
    }

    /**
     * Обновить заметку к неделе
     */
    public function updateWeekNote($noteId, $authorId, $content) {
        $sql = "UPDATE plan_week_notes SET content = ? WHERE id = ? AND author_id = ?";
        return $this->execute($sql, [$content, $noteId, $authorId], 'sii');
    }

    /**
     * Удалить заметку к неделе (только автор)
     */
    public function deleteWeekNote($noteId, $authorId) {
        $sql = "DELETE FROM plan_week_notes WHERE id = ? AND author_id = ?";
        return $this->execute($sql, [$noteId, $authorId], 'ii');
    }

    /**
     * Получить количество заметок по датам (для отображения индикаторов в календаре)
     */
    public function getDayNoteCounts($userId, $startDate, $endDate) {
        $sql = "SELECT date, COUNT(*) as count FROM plan_day_notes
                WHERE user_id = ? AND date >= ? AND date <= ?
                GROUP BY date";
        return $this->fetchAll($sql, [$userId, $startDate, $endDate], 'iss');
    }

    /**
     * Получить количество заметок по неделям
     */
    public function getWeekNoteCounts($userId, $startDate, $endDate) {
        $sql = "SELECT week_start, COUNT(*) as count FROM plan_week_notes
                WHERE user_id = ? AND week_start >= ? AND week_start <= ?
                GROUP BY week_start";
        return $this->fetchAll($sql, [$userId, $startDate, $endDate], 'iss');
    }
}
