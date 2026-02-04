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
                    'pace' => $row['avg_pace'],
                    'hr' => $row['avg_hr'] ? round((float)$row['avg_hr']) : null,
                    'workout_url' => $workoutUrl
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
