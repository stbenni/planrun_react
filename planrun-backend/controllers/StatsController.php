<?php
/**
 * Контроллер для работы со статистикой
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../services/StatsService.php';

class StatsController extends BaseController {
    
    protected $statsService;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->statsService = new StatsService($db);
    }
    
    /**
     * Получить статистику
     * GET /api_v2.php?action=stats
     */
    public function stats() {
        try {
            $data = $this->statsService->getStats($this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Получить сводку всех тренировок
     * GET /api_v2.php?action=get_all_workouts_summary
     */
    public function getAllWorkoutsSummary() {
        try {
            $data = $this->statsService->getAllWorkoutsSummary($this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Получить список всех тренировок (каждая отдельно, без группировки по дню)
     * GET /api_v2.php?action=get_all_workouts_list&limit=500
     */
    public function getAllWorkoutsList() {
        try {
            $limit = (int)($this->getParam('limit') ?: 500);
            $limit = min(max($limit, 1), 1000);
            $data = $this->statsService->getAllWorkoutsList($this->calendarUserId, $limit);
            $this->returnSuccess(['workouts' => $data]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Подготовить недельный анализ
     * GET /api_v2.php?action=prepare_weekly_analysis&week=1
     */
    public function prepareWeeklyAnalysis() {
        try {
            $userId = $this->getParam('user_id', $this->calendarUserId);
            $weekNumber = $this->getParam('week') ? (int)$this->getParam('week') : null;

            $analysis = $this->statsService->prepareWeeklyAnalysis($userId, $weekNumber);
            $this->returnSuccess($analysis);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Прогноз результатов на забег (VDOT + Riegel + тренировочные зоны)
     * GET /api_v2.php?action=race_prediction
     */
    public function racePrediction() {
        try {
            require_once __DIR__ . '/../planrun_ai/prompt_builder.php';
            require_once __DIR__ . '/../user_functions.php';
            require_once __DIR__ . '/../services/TrainingStateBuilder.php';

            $userId = $this->calendarUserId;
            $user = getUserData($userId, 'id, goal_type, race_date, race_distance, race_target_time, '
                . 'last_race_distance, last_race_distance_km, last_race_time, last_race_date, '
                . 'easy_pace_sec, weekly_base_km, experience_level');

            if (!$user) {
                $this->returnError('Пользователь не найден', 404);
                return;
            }

            $trainingState = (new TrainingStateBuilder($this->db))->buildForUser($user);
            $vdot = isset($trainingState['vdot']) ? (float) $trainingState['vdot'] : null;
            $vdotSource = $trainingState['vdot_source'] ?? null;
            $vdotSourceDetail = $trainingState['vdot_source_detail'] ?? null;
            $lastDistKm = isset($trainingState['source_distance_km']) ? (float) $trainingState['source_distance_km'] : 0;
            $lastTimeSec = isset($trainingState['source_time_sec']) ? (int) $trainingState['source_time_sec'] : 0;

            if (!$vdot) {
                $this->returnSuccess([
                    'available' => false,
                    'message' => 'Недостаточно данных для расчёта VDOT. Укажите результат забега/контрольной, добавьте тренировки с дистанцией и временем, или легкий темп в профиле.'
                ]);
                return;
            }

            $predictions = predictAllRaceTimes($vdot);
            $formattedPaces = $trainingState['formatted_training_paces'] ?? null;
            if (!$formattedPaces) {
                $paces = getTrainingPaces($vdot);
                $formattedPaces = [
                    'easy' => formatPaceSec($paces['easy'][0]) . ' – ' . formatPaceSec($paces['easy'][1]),
                    'marathon' => formatPaceSec($paces['marathon']),
                    'threshold' => formatPaceSec($paces['threshold']),
                    'interval' => formatPaceSec($paces['interval']),
                    'repetition' => formatPaceSec($paces['repetition']),
                ];
            }

            // Riegel-прогноз (для сравнения)
            $riegelPredictions = null;
            if ($lastDistKm > 0 && $lastTimeSec > 0) {
                $riegelPredictions = $this->riegelPredictAll($lastDistKm, $lastTimeSec);
            }

            // Цель пользователя
            $goal = null;
            if ($user['goal_type'] === 'race' && !empty($user['race_date'])) {
                $daysToRace = (int)((strtotime($user['race_date']) - time()) / 86400);
                $goal = [
                    'race_date' => $user['race_date'],
                    'race_distance' => $user['race_distance'],
                    'race_target_time' => $user['race_target_time'],
                    'days_to_race' => max(0, $daysToRace),
                    'weeks_to_race' => $trainingState['weeks_to_goal'] ?? max(0, (int)ceil($daysToRace / 7)),
                ];
            }

            $this->returnSuccess([
                'available' => true,
                'vdot' => round($vdot, 1),
                'vdot_source' => $vdotSource,
                'vdot_source_detail' => $vdotSourceDetail,
                'vdot_confidence' => $trainingState['vdot_confidence'] ?? null,
                'predictions' => $predictions,
                'riegel_predictions' => $riegelPredictions,
                'training_paces' => $formattedPaces,
                'goal' => $goal,
            ]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Riegel formula: T2 = T1 * (D2/D1)^1.06
     */
    private function riegelPredictAll(float $knownDistKm, int $knownTimeSec): array {
        $dists = ['5k' => 5.0, '10k' => 10.0, 'half' => 21.0975, 'marathon' => 42.195];
        $results = [];
        foreach ($dists as $label => $km) {
            $sec = (int)round($knownTimeSec * pow($km / $knownDistKm, 1.06));
            $results[$label] = [
                'seconds' => $sec,
                'formatted' => formatTimeSec($sec),
            ];
        }
        return $results;
    }

}
