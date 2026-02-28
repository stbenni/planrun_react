<?php
/**
 * Сервис для работы со статистикой
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../query_helpers.php';
require_once __DIR__ . '/../calendar_access.php';
require_once __DIR__ . '/../prepare_weekly_analysis.php';
require_once __DIR__ . '/../repositories/StatsRepository.php';

class StatsService extends BaseService {
    
    protected $repository;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new StatsRepository($db);
    }
    
    /**
     * Получить статистику тренировок
     * 
     * @param int $userId ID пользователя
     * @return array Статистика (total, completed, percentage)
     * @throws Exception
     */
    public function getStats($userId) {
        try {
            // Используем репозиторий
            $total = $this->repository->getTotalDays($userId);
            
            // Получаем выполненные дни
            $completedDaysSet = getCompletedDaysKeys($this->db, $userId);
            
            // Также учитываем тренировки из workouts через репозиторий
            $workoutDates = $this->repository->getWorkoutDates($userId);
            
            // Для каждой тренировки из workouts проверяем, попадает ли она в план
            foreach ($workoutDates as $workoutDate) {
                $trainingDay = findTrainingDay($workoutDate, $userId);
                if ($trainingDay) {
                    $dayKey = $trainingDay['training_date'] . '-' . $trainingDay['week_number'] . '-' . $trainingDay['day_name'];
                    $completedDaysSet[$dayKey] = true;
                }
            }
            
            $completed = count($completedDaysSet);
            $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
            
            return [
                'total' => $total,
                'completed' => $completed,
                'percentage' => $percentage
            ];
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки статистики: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить сводку всех тренировок
     * 
     * @param int $userId ID пользователя
     * @return array Сводка тренировок по датам
     * @throws Exception
     */
    public function getAllWorkoutsSummary($userId) {
        try {
            require_once __DIR__ . '/../calendar_access.php';
            
            // Используем репозиторий
            $rows = $this->repository->getWorkoutsSummary($userId);
            
            $summary = [];
            foreach ($rows as $row) {
                $workoutUrl = null;
                if ($row['first_workout_id']) {
                    $workoutUrl = getWorkoutDetailsUrl($row['first_workout_id'], $userId);
                }
                
                $summary[$row['workout_date']] = [
                    'count' => (int)$row['workout_count'],
                    'distance' => $row['total_distance'] ? round((float)$row['total_distance'], 2) : null,
                    'duration' => $row['total_duration'] ? (int)$row['total_duration'] : null,
                    'duration_seconds' => !empty($row['total_duration_seconds']) ? (int)$row['total_duration_seconds'] : null,
                    'pace' => $row['avg_pace'],
                    'hr' => $row['avg_hr'] ? round((float)$row['avg_hr']) : null,
                    'workout_url' => $workoutUrl,
                    'activity_type' => $row['activity_type'] ?? 'running'
                ];
            }
            
            return $summary;
        } catch (Exception $e) {
            $this->throwException('Ошибка загрузки сводки тренировок: ' . $e->getMessage(), 500, [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Получить список всех тренировок (каждая отдельно, без группировки по дню)
     * Объединяет workout_log (ручные) и workouts (Strava, импорт)
     *
     * @param int $userId ID пользователя
     * @param int $limit Максимум записей (по умолчанию 500)
     * @return array Массив тренировок, отсортированных по дате/времени (новые сверху)
     */
    public function getAllWorkoutsList($userId, $limit = 500) {
        require_once __DIR__ . '/../calendar_access.php';

        $list = [];

        // Ручные тренировки из workout_log
        $logStmt = $this->db->prepare("
            SELECT wl.id, wl.training_date, wl.distance_km, wl.result_time, wl.pace,
                   wl.duration_minutes, wl.avg_heart_rate, at.name as activity_type_name
            FROM workout_log wl
            LEFT JOIN activity_types at ON wl.activity_type_id = at.id
            WHERE wl.user_id = ? AND wl.is_completed = 1
            ORDER BY wl.training_date DESC
            LIMIT ?
        ");
        $logStmt->bind_param("ii", $userId, $limit);
        $logStmt->execute();
        $logResult = $logStmt->get_result();
        while ($row = $logResult->fetch_assoc()) {
            $date = $row['training_date'];
            $list[] = [
                'id' => 'log_' . $row['id'],
                'date' => $date,
                'start_time' => $date . 'T12:00:00',
                'distance_km' => $row['distance_km'] ? (float)$row['distance_km'] : null,
                'duration_minutes' => $row['duration_minutes'] ? (int)$row['duration_minutes'] : null,
                'duration_seconds' => null,
                'avg_pace' => $row['pace'],
                'activity_type' => strtolower(trim($row['activity_type_name'] ?? 'running')),
                'is_manual' => true,
            ];
        }
        $logStmt->close();

        // Автоматические тренировки из workouts
        $autoStmt = $this->db->prepare("
            SELECT id, activity_type, start_time, end_time, duration_minutes, duration_seconds,
                   distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain, source
            FROM workouts
            WHERE user_id = ?
            ORDER BY start_time DESC
            LIMIT ?
        ");
        $autoStmt->bind_param("ii", $userId, $limit);
        $autoStmt->execute();
        $autoResult = $autoStmt->get_result();
        while ($row = $autoResult->fetch_assoc()) {
            $startTime = $row['start_time'];
            $date = date('Y-m-d', strtotime($startTime));
            $isoTime = date('Y-m-d\TH:i:s', strtotime($startTime));
            $list[] = [
                'id' => (int)$row['id'],
                'date' => $date,
                'start_time' => $isoTime,
                'distance_km' => $row['distance_km'] ? (float)$row['distance_km'] : null,
                'duration_minutes' => $row['duration_minutes'] ? (int)$row['duration_minutes'] : null,
                'duration_seconds' => !empty($row['duration_seconds']) ? (int)$row['duration_seconds'] : null,
                'avg_pace' => $row['avg_pace'],
                'avg_heart_rate' => $row['avg_heart_rate'] ? (int)$row['avg_heart_rate'] : null,
                'max_heart_rate' => $row['max_heart_rate'] ? (int)$row['max_heart_rate'] : null,
                'elevation_gain' => $row['elevation_gain'] ? (int)$row['elevation_gain'] : null,
                'source' => $row['source'],
                'activity_type' => strtolower(trim($row['activity_type'] ?? 'running')),
                'is_manual' => false,
            ];
        }
        $autoStmt->close();

        // Сортируем по start_time (новые сверху)
        usort($list, function ($a, $b) {
            $ta = strtotime($a['start_time']);
            $tb = strtotime($b['start_time']);
            return $tb <=> $ta;
        });

        return array_slice($list, 0, $limit);
    }
    
    /**
     * Подготовить недельный анализ
     * 
     * @param int $userId ID пользователя
     * @param int|null $weekNumber Номер недели (опционально)
     * @return array Недельный анализ
     * @throws Exception
     */
    public function prepareWeeklyAnalysis($userId, $weekNumber = null) {
        try {
            $analysis = prepareWeeklyAnalysis($userId, $weekNumber);
            return $analysis;
        } catch (Exception $e) {
            $this->throwException('Ошибка подготовки недельного анализа: ' . $e->getMessage(), 400, [
                'user_id' => $userId,
                'week' => $weekNumber,
                'error' => $e->getMessage()
            ]);
        }
    }
}
