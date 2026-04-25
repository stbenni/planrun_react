<?php
/**
 * WorkoutRepository — единственный канонический способ читать тренировочные данные.
 *
 * Объединяет доступ к двум таблицам:
 *   - workout_log  — ручные отметки выполнения (с rating, week_number, day_name)
 *   - workouts     — импортированные тренировки (Strava, Polar, COROS, Garmin)
 *
 * ВАЖНО: При подсчёте объёмов используется дедупликация — если на дату есть
 * запись в workout_log (is_completed=1), тренировка из workouts НЕ учитывается
 * повторно. Все методы *WithDedup уже делают это.
 *
 * Примеры использования:
 *   $repo = new WorkoutRepository($db);
 *   $km   = $repo->getWeeklyKm(42, '2026-04-07', '2026-04-13');
 *   $date = $repo->getLastTrainingDate(42);
 *   $all  = $repo->getAllActivitiesForDateRange(42, '2026-04-01', '2026-04-07');
 */

require_once __DIR__ . '/BaseRepository.php';

class WorkoutRepository extends BaseRepository {

    // ══════════════════════════════════════════════
    //  Существующие методы (обратная совместимость)
    // ══════════════════════════════════════════════

    /**
     * Все отмеченные результаты (workout_log) пользователя.
     */
    public function getAllResults(int $userId): array {
        return $this->fetchAll(
            "SELECT wl.training_date, wl.week_number, wl.day_name, wl.result_time,
                    wl.distance_km AS result_distance, wl.pace AS result_pace,
                    wl.notes, wl.created_at AS completed_at,
                    LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type
             FROM workout_log wl
             LEFT JOIN activity_types at ON wl.activity_type_id = at.id
             WHERE wl.user_id = ? AND wl.is_completed = 1
             ORDER BY wl.training_date DESC
             LIMIT 1000",
            [$userId], 'i'
        );
    }

    /**
     * Результат ручной отметки за конкретный день.
     */
    public function getResultByDate(int $userId, string $date, int $weekNumber, string $dayName): ?array {
        return $this->fetchOne(
            "SELECT result_time, distance_km AS result_distance, pace AS result_pace, notes
             FROM workout_log
             WHERE user_id = ? AND training_date = ? AND week_number = ? AND day_name = ?",
            [$userId, $date, $weekNumber, $dayName], 'isis'
        );
    }

    /**
     * Импортированные тренировки (workouts) за временной диапазон.
     */
    public function getWorkoutsByDate(int $userId, string $dateStart, string $dateEnd): array {
        return $this->fetchAll(
            "SELECT id, user_id, activity_type, source, start_time,
                    duration_minutes, duration_seconds, distance_km,
                    avg_pace, avg_heart_rate, max_heart_rate, elevation_gain
             FROM workouts
             WHERE user_id = ? AND start_time >= ? AND start_time <= ?
             ORDER BY start_time ASC LIMIT 20",
            [$userId, $dateStart, $dateEnd], 'iss'
        );
    }

    // ══════════════════════════════════════════════
    //  Недельный объём (с дедупликацией)
    // ══════════════════════════════════════════════

    /**
     * Суммарный километраж за период с дедупликацией workout_log + workouts.
     *
     * Заменяет дублирующиеся запросы в:
     *   ChatContextBuilder::getLoadTrend, GoalProgressService::getWeekStats,
     *   weekly_ai_review.php, PlanGenerationProcessorService
     *
     * @param string $from  YYYY-MM-DD (inclusive)
     * @param string $to    YYYY-MM-DD (inclusive)
     */
    public function getKmForPeriod(int $userId, string $from, string $to): float {
        $km = 0.0;

        // 1. workout_log (ручные)
        $row = $this->fetchOne(
            "SELECT COALESCE(SUM(distance_km), 0) AS km
             FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?",
            [$userId, $from, $to], 'iss'
        );
        $km += (float) ($row['km'] ?? 0);

        // 2. workouts (автоматические) — только те даты, где нет workout_log
        $row2 = $this->fetchOne(
            "SELECT COALESCE(SUM(distance_km), 0) AS km
             FROM workouts
             WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM workout_log wl
                   WHERE wl.user_id = workouts.user_id
                     AND wl.training_date = DATE(workouts.start_time)
                     AND wl.is_completed = 1
               )",
            [$userId, $from, $to], 'iss'
        );
        $km += (float) ($row2['km'] ?? 0);

        return round($km, 1);
    }

    /**
     * Недельный объём (понедельник — воскресенье).
     * Обёртка над getKmForPeriod() для удобства.
     */
    public function getWeeklyKm(int $userId, string $monday, string $sunday): float {
        return $this->getKmForPeriod($userId, $monday, $sunday);
    }

    // ══════════════════════════════════════════════
    //  Недельная статистика (km, sessions, longest)
    // ══════════════════════════════════════════════

    /**
     * Подробная статистика за период: км, количество сессий, самый длинный забег.
     * Заменяет GoalProgressService::getWeekStats().
     *
     * @return array{km: float, sessions: int, longest_km: float}
     */
    public function getWeekStats(int $userId, string $from, string $to): array {
        $km = 0.0;
        $sessions = 0;
        $longestKm = 0.0;

        // workout_log
        $rows = $this->fetchAll(
            "SELECT distance_km FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date BETWEEN ? AND ?",
            [$userId, $from, $to], 'iss'
        );
        foreach ($rows as $r) {
            $d = (float) ($r['distance_km'] ?? 0);
            if ($d > 0) { $km += $d; $sessions++; $longestKm = max($longestKm, $d); }
        }

        // workouts (без дублей с workout_log)
        $rows2 = $this->fetchAll(
            "SELECT distance_km FROM workouts
             WHERE user_id = ? AND DATE(start_time) BETWEEN ? AND ?
               AND NOT EXISTS (
                   SELECT 1 FROM workout_log wl
                   WHERE wl.user_id = workouts.user_id
                     AND wl.training_date = DATE(workouts.start_time)
                     AND wl.is_completed = 1
               )",
            [$userId, $from, $to], 'iss'
        );
        foreach ($rows2 as $r) {
            $d = (float) ($r['distance_km'] ?? 0);
            if ($d > 0) { $km += $d; $sessions++; $longestKm = max($longestKm, $d); }
        }

        return [
            'km' => round($km, 1),
            'sessions' => $sessions,
            'longest_km' => round($longestKm, 1),
        ];
    }

    // ══════════════════════════════════════════════
    //  Средний недельный объём за N недель
    // ══════════════════════════════════════════════

    /**
     * Средний недельный километраж за последние $weeks недель.
     * Заменяет PlanGenerationProcessorService::actual_weekly_km_4w.
     *
     * @param int $weeks Количество недель (обычно 4)
     * @return float|null null если данных нет
     */
    public function getAvgWeeklyKm(int $userId, int $weeks = 4): ?float {
        $to = (new \DateTime())->format('Y-m-d');
        $from = (new \DateTime())->modify("-{$weeks} weeks")->format('Y-m-d');

        // Собираем дистанции по неделям (ISO week key)
        $weekKms = [];

        // workout_log
        $rows = $this->fetchAll(
            "SELECT training_date, distance_km FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?",
            [$userId, $from, $to], 'iss'
        );
        foreach ($rows as $r) {
            $d = (float) ($r['distance_km'] ?? 0);
            if ($d > 0) {
                $wk = date('o-W', strtotime($r['training_date']));
                $weekKms[$wk] = ($weekKms[$wk] ?? 0) + $d;
            }
        }

        // workouts (без дублей)
        $rows2 = $this->fetchAll(
            "SELECT DATE(start_time) AS workout_date, distance_km FROM workouts
             WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM workout_log wl
                   WHERE wl.user_id = workouts.user_id
                     AND wl.training_date = DATE(workouts.start_time)
                     AND wl.is_completed = 1
               )",
            [$userId, $from, $to], 'iss'
        );
        foreach ($rows2 as $r) {
            $d = (float) ($r['distance_km'] ?? 0);
            if ($d > 0) {
                $wk = date('o-W', strtotime($r['workout_date']));
                $weekKms[$wk] = ($weekKms[$wk] ?? 0) + $d;
            }
        }

        if (empty($weekKms)) return null;
        return round(array_sum($weekKms) / count($weekKms), 1);
    }

    // ══════════════════════════════════════════════
    //  Последняя тренировка
    // ══════════════════════════════════════════════

    /**
     * Дата последней тренировки (из обоих источников).
     * Заменяет TrainingStateBuilder::getDaysSinceLastWorkout(),
     *          ProactiveCoachService::detectPause().
     *
     * @return string|null YYYY-MM-DD или null
     */
    public function getLastTrainingDate(int $userId): ?string {
        $maxDate = null;

        $r1 = $this->fetchOne(
            "SELECT MAX(training_date) AS last_date FROM workout_log
             WHERE user_id = ? AND is_completed = 1",
            [$userId], 'i'
        );
        if (!empty($r1['last_date'])) {
            $maxDate = $r1['last_date'];
        }

        $r2 = $this->fetchOne(
            "SELECT MAX(DATE(start_time)) AS last_date FROM workouts WHERE user_id = ?",
            [$userId], 'i'
        );
        if (!empty($r2['last_date']) && ($maxDate === null || $r2['last_date'] > $maxDate)) {
            $maxDate = $r2['last_date'];
        }

        return $maxDate;
    }

    /**
     * Дней с последней тренировки. null если тренировок не было.
     */
    public function getDaysSinceLastWorkout(int $userId): ?int {
        $lastDate = $this->getLastTrainingDate($userId);
        if ($lastDate === null) return null;
        return (int) ((time() - strtotime($lastDate)) / 86400);
    }

    // ══════════════════════════════════════════════
    //  Все активности за диапазон дат (UNION с дедупликацией)
    // ══════════════════════════════════════════════

    /**
     * Все тренировки за период из обоих источников.
     * Используется для ACWR, load trend, weekly review.
     *
     * @return array[] Массив строк: date, distance_km, duration_minutes, rating, activity_type, source
     */
    public function getAllActivitiesForDateRange(int $userId, string $from, string $to): array {
        return $this->fetchAll(
            "(SELECT training_date AS date, distance_km, duration_minutes, rating,
                     LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) COLLATE utf8mb4_unicode_ci AS activity_type,
                     'manual' COLLATE utf8mb4_unicode_ci AS source
              FROM workout_log wl
              LEFT JOIN activity_types at ON at.id = wl.activity_type_id
              WHERE wl.user_id = ? AND wl.is_completed = 1
                AND wl.training_date >= ? AND wl.training_date <= ?)
             UNION ALL
             (SELECT DATE(start_time) AS date, distance_km, duration_minutes, NULL AS rating,
                     LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) COLLATE utf8mb4_unicode_ci AS activity_type,
                     COALESCE(source, 'import') COLLATE utf8mb4_unicode_ci AS source
              FROM workouts
              WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
                AND NOT EXISTS (
                    SELECT 1 FROM workout_log wl
                    WHERE wl.user_id = workouts.user_id
                      AND wl.training_date = DATE(workouts.start_time)
                      AND wl.is_completed = 1
                ))
             ORDER BY date DESC",
            [$userId, $from, $to, $userId, $from, $to], 'ississ'
        );
    }

    // ══════════════════════════════════════════════
    //  Дистанция на конкретную дату (для ProactiveCoach)
    // ══════════════════════════════════════════════

    /**
     * Суммарная дистанция за конкретную дату (оба источника).
     */
    public function getDistanceForDate(int $userId, string $date): float {
        return $this->getKmForPeriod($userId, $date, $date);
    }

    /**
     * Максимальная дистанция за одну тренировку до указанной даты.
     * Для ProactiveCoachService::detectVolumeRecord.
     */
    public function getMaxSingleRunKmBefore(int $userId, string $beforeDate): float {
        $max = 0.0;

        $r1 = $this->fetchOne(
            "SELECT MAX(distance_km) AS max_km FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date < ?",
            [$userId, $beforeDate], 'is'
        );
        $max = max($max, (float) ($r1['max_km'] ?? 0));

        $r2 = $this->fetchOne(
            "SELECT MAX(distance_km) AS max_km FROM workouts
             WHERE user_id = ? AND DATE(start_time) < ?",
            [$userId, $beforeDate], 'is'
        );
        $max = max($max, (float) ($r2['max_km'] ?? 0));

        return $max;
    }

    // ══════════════════════════════════════════════
    //  Compliance (plan vs actual)
    // ══════════════════════════════════════════════

    /**
     * Выполнение плана: запланировано vs выполнено за период.
     * Заменяет ChatContextBuilder::getWeeklyCompliance().
     *
     * @return array{planned: int, completed: int, missed: int}
     */
    public function getCompliance(int $userId, string $from, string $to): array {
        // Запланированные (кроме отдыха)
        $pRow = $this->fetchOne(
            "SELECT COUNT(*) AS cnt FROM training_plan_days d
             JOIN training_plan_weeks w ON d.week_id = w.id
             WHERE w.user_id = ? AND d.date >= ? AND d.date <= ? AND d.type != 'rest'",
            [$userId, $from, $to], 'iss'
        );
        $planned = (int) ($pRow['cnt'] ?? 0);

        // Выполнено: workout_log
        $cRow = $this->fetchOne(
            "SELECT COUNT(*) AS cnt FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?",
            [$userId, $from, $to], 'iss'
        );
        $completed = (int) ($cRow['cnt'] ?? 0);

        // + workouts (без дублей)
        $wRow = $this->fetchOne(
            "SELECT COUNT(DISTINCT DATE(start_time)) AS cnt FROM workouts
             WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM workout_log wl
                   WHERE wl.user_id = workouts.user_id
                     AND wl.training_date = DATE(workouts.start_time)
                     AND wl.is_completed = 1
               )",
            [$userId, $from, $to], 'iss'
        );
        $completed += (int) ($wRow['cnt'] ?? 0);

        return [
            'planned' => $planned,
            'completed' => $completed,
            'missed' => max(0, $planned - $completed),
        ];
    }
}
