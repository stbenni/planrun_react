<?php
/**
 * Сервис для фиксации фактически выполненных упражнений (ОФП/СБУ).
 *
 * Используется:
 *  - UI «отметить выполнение» на ОФП-дне → markCompleted()
 *  - AI: WorkoutBuilderService::computePersonalizedWeight использует getLastExecuted
 *  - AI: ofp_enricher передаёт history в LLM (контекст для подбора нагрузки)
 */

require_once __DIR__ . '/BaseService.php';

class ExecutedExerciseService extends BaseService {

    /**
     * Сохраняет фактическое выполнение упражнений за один тренировочный день.
     * Удаляет предыдущие записи для plan_day_id если они есть (re-mark).
     */
    public function markCompleted(int $userId, int $planDayId, string $executedDate, array $exercises): array {
        $this->ensureSchema();

        // Verify plan_day принадлежит юзеру
        $stmt = $this->db->prepare(
            "SELECT id FROM training_plan_days
             WHERE id = ? AND user_id = ? LIMIT 1"
        );
        if (!$stmt) return ['saved' => 0, 'error' => 'db_prepare_failed'];
        $stmt->bind_param('ii', $planDayId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return ['saved' => 0, 'error' => 'plan_day_not_found'];

        // Очищаем предыдущие отметки за этот день
        $clear = $this->db->prepare(
            "DELETE FROM executed_exercises WHERE user_id = ? AND plan_day_id = ?"
        );
        if ($clear) {
            $clear->bind_param('ii', $userId, $planDayId);
            $clear->execute();
            $clear->close();
        }

        $saved = 0;
        $stmt = $this->db->prepare(
            "INSERT INTO executed_exercises
                (user_id, plan_day_id, exercise_id, exercise_name, category,
                 planned_sets, planned_reps, planned_weight_kg, planned_duration_sec, planned_distance_m,
                 executed_sets, executed_reps, executed_weight_kg, executed_duration_sec, executed_distance_m,
                 rpe, notes, executed_date)
             VALUES (?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?)"
        );
        if (!$stmt) return ['saved' => 0, 'error' => 'db_prepare_failed'];

        foreach ($exercises as $ex) {
            if (!is_array($ex)) continue;
            $name = trim((string) ($ex['exercise_name'] ?? $ex['name'] ?? ''));
            $category = (string) ($ex['category'] ?? 'ofp');
            if (!in_array($category, ['run', 'ofp', 'sbu'], true)) $category = 'ofp';
            if ($name === '') continue;

            $exerciseId = isset($ex['exercise_id']) && $ex['exercise_id'] !== null ? (int) $ex['exercise_id'] : null;
            $plannedSets = isset($ex['planned_sets']) ? (int) $ex['planned_sets'] : null;
            $plannedReps = isset($ex['planned_reps']) ? (int) $ex['planned_reps'] : null;
            $plannedWeight = isset($ex['planned_weight_kg']) ? (float) $ex['planned_weight_kg'] : null;
            $plannedDuration = isset($ex['planned_duration_sec']) ? (int) $ex['planned_duration_sec'] : null;
            $plannedDistance = isset($ex['planned_distance_m']) ? (int) $ex['planned_distance_m'] : null;

            $executedSets = isset($ex['executed_sets']) ? (int) $ex['executed_sets'] : null;
            $executedReps = isset($ex['executed_reps']) ? (int) $ex['executed_reps'] : null;
            $executedWeight = isset($ex['executed_weight_kg']) ? (float) $ex['executed_weight_kg'] : null;
            $executedDuration = isset($ex['executed_duration_sec']) ? (int) $ex['executed_duration_sec'] : null;
            $executedDistance = isset($ex['executed_distance_m']) ? (int) $ex['executed_distance_m'] : null;

            $rpe = isset($ex['rpe']) ? (int) $ex['rpe'] : null;
            $notes = isset($ex['notes']) ? (string) $ex['notes'] : null;

            $stmt->bind_param(
                'iiissiidiiiidiiiss',
                $userId, $planDayId, $exerciseId, $name, $category,
                $plannedSets, $plannedReps, $plannedWeight, $plannedDuration, $plannedDistance,
                $executedSets, $executedReps, $executedWeight, $executedDuration, $executedDistance,
                $rpe, $notes, $executedDate
            );
            if ($stmt->execute()) {
                $saved++;
            }
        }
        $stmt->close();

        return ['saved' => $saved];
    }

    /**
     * Последние выполнения упражнения для атлета.
     * Используется AI для подбора weight (progressive overload).
     *
     * @return array{exercise_name: string, last_weight_kg: ?float, last_sets: ?int,
     *               last_reps: ?int, last_executed_date: ?string, history: array[]}
     */
    public function getLastExecuted(int $userId, string $exerciseName, ?int $exerciseId = null, int $lookbackWeeks = 12): ?array {
        $this->ensureSchema();

        $sinceDate = date('Y-m-d', strtotime("-{$lookbackWeeks} weeks"));

        if ($exerciseId !== null) {
            $stmt = $this->db->prepare(
                "SELECT executed_date, executed_sets, executed_reps, executed_weight_kg,
                        executed_duration_sec, rpe
                 FROM executed_exercises
                 WHERE user_id = ? AND exercise_id = ?
                   AND executed_date >= ?
                   AND executed_weight_kg IS NOT NULL
                 ORDER BY executed_date DESC LIMIT 5"
            );
            if (!$stmt) return null;
            $stmt->bind_param('iis', $userId, $exerciseId, $sinceDate);
        } else {
            $stmt = $this->db->prepare(
                "SELECT executed_date, executed_sets, executed_reps, executed_weight_kg,
                        executed_duration_sec, rpe
                 FROM executed_exercises
                 WHERE user_id = ? AND LOWER(exercise_name) = LOWER(?)
                   AND executed_date >= ?
                   AND executed_weight_kg IS NOT NULL
                 ORDER BY executed_date DESC LIMIT 5"
            );
            if (!$stmt) return null;
            $stmt->bind_param('iss', $userId, $exerciseName, $sinceDate);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        $stmt->close();

        if (empty($history)) return null;

        $last = $history[0];
        return [
            'exercise_name' => $exerciseName,
            'last_weight_kg' => $last['executed_weight_kg'] !== null ? (float) $last['executed_weight_kg'] : null,
            'last_sets' => $last['executed_sets'] !== null ? (int) $last['executed_sets'] : null,
            'last_reps' => $last['executed_reps'] !== null ? (int) $last['executed_reps'] : null,
            'last_executed_date' => $last['executed_date'],
            'history' => $history,
        ];
    }

    /**
     * Все executed за период (для AI-prompt context).
     * Возвращает aggregated: per exercise — last & best.
     */
    public function getRecentHistoryForUser(int $userId, int $lookbackWeeks = 8): array {
        $this->ensureSchema();
        $sinceDate = date('Y-m-d', strtotime("-{$lookbackWeeks} weeks"));

        $stmt = $this->db->prepare(
            "SELECT exercise_name, category,
                    MAX(executed_weight_kg) AS max_weight,
                    MAX(executed_date) AS last_date,
                    COUNT(*) AS times
             FROM executed_exercises
             WHERE user_id = ? AND executed_date >= ?
             GROUP BY exercise_name, category
             ORDER BY last_date DESC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('is', $userId, $sinceDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Карта дат → массив категорий с непустым executed_exercises (ofp/sbu).
     * Используется фронтом для подсветки выполненных ОФП/СБУ-дней в календаре.
     * @return array<string, string[]> { 'YYYY-MM-DD' => ['ofp','sbu'] }
     */
    public function getCompletedDatesByCategory(int $userId, int $lookbackWeeks = 26): array {
        $this->ensureSchema();
        $sinceDate = date('Y-m-d', strtotime("-{$lookbackWeeks} weeks"));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT executed_date, category
             FROM executed_exercises
             WHERE user_id = ? AND executed_date >= ?
             ORDER BY executed_date DESC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('is', $userId, $sinceDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $byDate = [];
        while ($row = $result->fetch_assoc()) {
            $date = (string) $row['executed_date'];
            $cat = (string) $row['category'];
            if (!isset($byDate[$date])) $byDate[$date] = [];
            if (!in_array($cat, $byDate[$date], true)) $byDate[$date][] = $cat;
        }
        $stmt->close();
        return $byDate;
    }

    public function getByPlanDay(int $userId, int $planDayId): array {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            "SELECT * FROM executed_exercises WHERE user_id = ? AND plan_day_id = ? ORDER BY id ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('ii', $userId, $planDayId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private function ensureSchema(): void {
        @$this->db->query("CREATE TABLE IF NOT EXISTS executed_exercises (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            plan_day_id INT UNSIGNED NULL DEFAULT NULL,
            exercise_id INT UNSIGNED NULL DEFAULT NULL,
            exercise_name VARCHAR(255) NOT NULL,
            category ENUM('run', 'ofp', 'sbu') NOT NULL,
            planned_sets SMALLINT NULL DEFAULT NULL,
            planned_reps SMALLINT NULL DEFAULT NULL,
            planned_weight_kg DECIMAL(6,2) NULL DEFAULT NULL,
            planned_duration_sec INT NULL DEFAULT NULL,
            planned_distance_m INT NULL DEFAULT NULL,
            executed_sets SMALLINT NULL DEFAULT NULL,
            executed_reps SMALLINT NULL DEFAULT NULL,
            executed_weight_kg DECIMAL(6,2) NULL DEFAULT NULL,
            executed_duration_sec INT NULL DEFAULT NULL,
            executed_distance_m INT NULL DEFAULT NULL,
            rpe TINYINT NULL DEFAULT NULL,
            notes TEXT NULL DEFAULT NULL,
            executed_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, executed_date),
            INDEX idx_user_exercise_id (user_id, exercise_id, executed_date),
            INDEX idx_user_exercise_name (user_id, exercise_name, executed_date),
            INDEX idx_plan_day (plan_day_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
