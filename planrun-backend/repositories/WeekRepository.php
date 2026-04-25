<?php
/**
 * WeekRepository — канонический доступ к training_plan_weeks и training_plan_days.
 *
 * ВСЕ чтения/записи в training_plan_weeks и training_plan_days ДОЛЖНЫ идти через этот класс.
 *
 * Примеры:
 *   $repo = new WeekRepository($db);
 *   $week = $repo->getWeekByDate($userId, '2026-04-07');
 *   $max  = $repo->getMaxWeekNumberBefore($userId, '2026-04-07');
 *   $day  = $repo->getDayByDate('2026-04-07', $userId);
 */

require_once __DIR__ . '/BaseRepository.php';

class WeekRepository extends BaseRepository {

    /**
     * Получить неделю по ID
     */
    public function getWeekById($weekId, $userId) {
        $sql = "SELECT * FROM training_plan_weeks WHERE id = ? AND user_id = ?";
        return $this->fetchOne($sql, [$weekId, $userId], 'ii');
    }

    /**
     * Получить максимальный номер недели для пользователя (все недели).
     */
    public function getMaxWeekNumber($userId) {
        $sql = "SELECT MAX(week_number) as max_week FROM training_plan_weeks WHERE user_id = ?";
        $result = $this->fetchOne($sql, [$userId], 'i');
        return (int)($result['max_week'] ?? 0);
    }

    /**
     * Максимальный номер недели ДО указанной даты.
     * Заменяет дубли в PlanGenerationProcessorService, plan_generator, plan_saver.
     */
    public function getMaxWeekNumberBefore(int $userId, string $beforeDate): int {
        $result = $this->fetchOne(
            "SELECT MAX(week_number) AS max_wn FROM training_plan_weeks WHERE user_id = ? AND start_date < ?",
            [$userId, $beforeDate], 'is'
        );
        return (int) ($result['max_wn'] ?? 0);
    }

    /**
     * Диапазон дат текущего плана пользователя.
     */
    public function getPlanDateRange(int $userId): array {
        $row = $this->fetchOne(
            "SELECT MIN(start_date) AS min_start_date,
                    MAX(start_date) AS max_start_date,
                    COUNT(*) AS weeks_count
             FROM training_plan_weeks
             WHERE user_id = ?",
            [$userId],
            'i'
        );

        return [
            'min_start_date' => $row['min_start_date'] ?? null,
            'max_start_date' => $row['max_start_date'] ?? null,
            'weeks_count' => (int) ($row['weeks_count'] ?? 0),
        ];
    }

    /**
     * Последние недели плана с опциональным исключением race-weeks.
     */
    public function getRecentWeekSummaries(
        int $userId,
        int $limit = 4,
        ?string $beforeDate = null,
        bool $excludeRaceWeeks = false
    ): array {
        $limit = max(1, $limit);
        $where = 'w.user_id = ?';
        $params = [$userId];
        $types = 'i';

        if (is_string($beforeDate) && $beforeDate !== '') {
            $where .= ' AND w.start_date < ?';
            $params[] = $beforeDate;
            $types .= 's';
        }

        $having = $excludeRaceWeeks ? 'HAVING race_days = 0' : '';
        $params[] = $limit;
        $types .= 'i';

        return $this->fetchAll(
            "SELECT w.id,
                    w.week_number,
                    w.start_date,
                    w.total_volume,
                    COALESCE(SUM(CASE WHEN d.type = 'race' THEN 1 ELSE 0 END), 0) AS race_days
             FROM training_plan_weeks w
             LEFT JOIN training_plan_days d
               ON d.week_id = w.id
              AND d.user_id = w.user_id
             WHERE {$where}
             GROUP BY w.id
             {$having}
             ORDER BY w.start_date DESC
             LIMIT ?",
            $params,
            $types
        );
    }

    /**
     * Номер недели по дате (без загрузки всей записи).
     * Заменяет дубли в WorkoutService, ChatToolRegistry.
     */
    public function getWeekNumberByDate(int $userId, string $date): ?int {
        $row = $this->fetchOne(
            "SELECT week_number FROM training_plan_weeks
             WHERE user_id = ? AND start_date <= ? AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ?
             ORDER BY start_date DESC LIMIT 1",
            [$userId, $date, $date], 'iss'
        );
        return $row ? (int) $row['week_number'] : null;
    }

    /**
     * Будущие недели (start_date >= дата). Для plan_saver при пересчёте.
     */
    public function getFutureWeekIds(int $userId, string $fromDate): array {
        $rows = $this->fetchAll(
            "SELECT id FROM training_plan_weeks WHERE user_id = ? AND start_date >= ?",
            [$userId, $fromDate], 'is'
        );
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Количество запланированных тренировок (не отдых) за период.
     * Для compliance-расчётов.
     */
    public function getPlannedSessionCount(int $userId, string $from, string $to): int {
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS cnt FROM training_plan_days d
             JOIN training_plan_weeks w ON d.week_id = w.id
             WHERE w.user_id = ? AND d.date >= ? AND d.date <= ? AND d.type != 'rest'",
            [$userId, $from, $to], 'iss'
        );
        return (int) ($row['cnt'] ?? 0);
    }
    
    /**
     * Добавить неделю
     */
    public function addWeek($data, $userId) {
        $sql = "INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume)
                VALUES (?, ?, ?, ?)";
        
        $params = [
            $userId,
            $data['week_number'],
            $data['start_date'],
            $data['total_volume'] ?? null
        ];
        
        return $this->execute($sql, $params, 'iiss');
    }
    
    /**
     * Удалить неделю
     */
    public function deleteWeek($weekId, $userId) {
        $sql = "DELETE FROM training_plan_weeks WHERE id = ? AND user_id = ?";
        return $this->execute($sql, [$weekId, $userId], 'ii');
    }
    
    /**
     * Получить неделю по номеру
     */
    public function getWeekByWeekNumber($userId, $weekNumber) {
        $sql = "SELECT * FROM training_plan_weeks WHERE user_id = ? AND week_number = ? LIMIT 1";
        return $this->fetchOne($sql, [$userId, (int)$weekNumber], 'ii');
    }

    /**
     * Получить неделю по дате (start_date <= date <= start_date+6)
     */
    public function getWeekByDate($userId, $date) {
        $sql = "SELECT * FROM training_plan_weeks 
                WHERE user_id = ? AND start_date <= ? AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ?
                ORDER BY start_date DESC LIMIT 1";
        return $this->fetchOne($sql, [$userId, $date, $date], 'iss');
    }

    /**
     * Получить неделю по дате понедельника (start_date)
     */
    public function getWeekByStartDate($userId, $startDate) {
        $sql = "SELECT * FROM training_plan_weeks WHERE user_id = ? AND start_date = ? LIMIT 1";
        return $this->fetchOne($sql, [$userId, $startDate], 'is');
    }

    /**
     * Получить день тренировки по дате
     */
    public function getDayByDate($date, $userId) {
        $sql = "SELECT * FROM training_plan_days WHERE user_id = ? AND date = ? LIMIT 1";
        return $this->fetchOne($sql, [$userId, $date], 'is');
    }
    
    /**
     * Добавить день тренировки
     */
    public function addTrainingDay($data, $userId) {
        $sql = "INSERT INTO training_plan_days 
                (user_id, week_id, day_of_week, type, description, date, is_key_workout)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $userId,
            $data['week_id'],
            $data['day_of_week'],
            $data['type'],
            $data['description'] ?? null,
            $data['date'] ?? null,
            $data['is_key_workout'] ?? 0
        ];
        
        return $this->execute($sql, $params, 'iiisssi');
    }

    /**
     * Обновить день тренировки по id.
     * Пустое описание не затирает существующее (важно для ОФП/СБУ, где контент в description).
     */
    public function updateTrainingDayById($dayId, $userId, $data) {
        $sql = "UPDATE training_plan_days SET type = ?, description = COALESCE(NULLIF(?, ''), description), is_key_workout = ? WHERE id = ? AND user_id = ?";
        $type = $data['type'] ?? '';
        $description = isset($data['description']) ? (string) $data['description'] : '';
        $isKey = isset($data['is_key_workout']) ? (int) (bool) $data['is_key_workout'] : 0;
        return $this->execute($sql, [$type, $description, $isKey, $dayId, $userId], 'ssiii');
    }

    /**
     * Удалить день тренировки по id
     */
    public function deleteTrainingDayById($dayId, $userId) {
        $sql = "DELETE FROM training_plan_days WHERE id = ? AND user_id = ?";
        return $this->execute($sql, [$dayId, $userId], 'ii');
    }

    /**
     * Получить все дни тренировок на дату (несколько записей на дату разрешены)
     */
    public function getDaysByDate($date, $userId) {
        $sql = "SELECT * FROM training_plan_days WHERE user_id = ? AND date = ? ORDER BY id";
        return $this->fetchAll($sql, [$userId, $date], 'is');
    }

    /**
     * Получить все плановые дни недели
     */
    public function getDaysByWeekId($userId, $weekId) {
        $sql = "SELECT id, day_of_week, type, description, date, is_key_workout 
                FROM training_plan_days 
                WHERE user_id = ? AND week_id = ? 
                ORDER BY day_of_week, id";
        return $this->fetchAll($sql, [$userId, (int)$weekId], 'ii');
    }
}
