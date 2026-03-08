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

            $userId = $this->calendarUserId;
            $user = getUserData($userId, 'id, goal_type, race_date, race_distance, race_target_time, '
                . 'last_race_distance, last_race_distance_km, last_race_time, last_race_date, '
                . 'easy_pace_sec, weekly_base_km, experience_level');

            if (!$user) {
                $this->returnError('Пользователь не найден', 404);
                return;
            }

            $vdot = null;
            $vdotSource = null;
            $vdotSourceDetail = null;
            $lastDistKm = 0;
            $lastTimeSec = 0;

            // 1. VDOT из последнего забега/контрольной (если не старше 8 недель)
            $lastRaceDist = $this->parseDistanceKm($user['last_race_distance'] ?? null, $user['last_race_distance_km'] ?? null);
            $lastRaceTime = $this->parseTimeSec($user['last_race_time'] ?? null);
            $lastRaceDate = $user['last_race_date'] ?? null;
            $lastRaceWeeksAgo = $lastRaceDate ? (time() - strtotime($lastRaceDate)) / (7 * 86400) : 999;

            if ($lastRaceDist > 0 && $lastRaceTime > 0 && $lastRaceWeeksAgo <= 8) {
                $vdot = estimateVDOT($lastRaceDist, $lastRaceTime);
                $vdotSource = 'last_race';
                $lastDistKm = $lastRaceDist;
                $lastTimeSec = $lastRaceTime;
            }

            // 2. Если last_race устарел или пуст — VDOT из тренировок (взвешенное по давности и близости к цели)
            if (!$vdot || $lastRaceWeeksAgo > 8) {
                $targetDistKm = $this->parseDistanceKm($user['race_distance'] ?? null, null);
                $best = $this->statsService->getBestResultForVdot($userId, 6, $targetDistKm > 0 ? $targetDistKm : null);
                if ($best) {
                    $vdot = $best['vdot'];
                    $lastDistKm = $best['distance_km'];
                    $lastTimeSec = $best['time_sec'];
                    $vdotSource = 'best_result';
                    $vdotSourceDetail = $best['vdot_source_detail'] ?? null;
                } elseif ($lastRaceDist > 0 && $lastRaceTime > 0) {
                    // fallback на устаревший last_race, если нет тренировок
                    $vdot = estimateVDOT($lastRaceDist, $lastRaceTime);
                    $vdotSource = 'last_race';
                    $lastDistKm = $lastRaceDist;
                    $lastTimeSec = $lastRaceTime;
                }
            }

            // 3. Если нет — попробуем из easy pace
            if (!$vdot && !empty($user['easy_pace_sec'])) {
                $easyPaceSec = (int)$user['easy_pace_sec'];
                if ($easyPaceSec >= 240 && $easyPaceSec <= 540) {
                    $easyV = 1000.0 / ($easyPaceSec / 60.0);
                    $easyVO2 = _vdotOxygenCost($easyV);
                    $vdot = max(20, min(85, round($easyVO2 / 0.65, 1)));
                    $vdotSource = 'easy_pace';
                }
            }

            // 4. Если нет — попробуем из целевого времени забега
            if (!$vdot && !empty($user['race_target_time']) && !empty($user['race_distance'])) {
                $targetDistKm = $this->parseDistanceKm($user['race_distance'], null);
                $targetTimeSec = $this->parseTimeSec($user['race_target_time']);
                if ($targetDistKm > 0 && $targetTimeSec > 0) {
                    $vdot = estimateVDOT($targetDistKm, $targetTimeSec);
                    $vdotSource = 'target_time';
                }
            }

            if (!$vdot) {
                $this->returnSuccess([
                    'available' => false,
                    'message' => 'Недостаточно данных для расчёта VDOT. Укажите результат забега/контрольной, добавьте тренировки с дистанцией и временем, или легкий темп в профиле.'
                ]);
                return;
            }

            $predictions = predictAllRaceTimes($vdot);
            $paces = getTrainingPaces($vdot);

            // Форматируем зоны
            $formattedPaces = [
                'easy' => formatPaceSec($paces['easy'][0]) . ' – ' . formatPaceSec($paces['easy'][1]),
                'marathon' => formatPaceSec($paces['marathon']),
                'threshold' => formatPaceSec($paces['threshold']),
                'interval' => formatPaceSec($paces['interval']),
                'repetition' => formatPaceSec($paces['repetition']),
            ];

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
                    'weeks_to_race' => max(0, (int)ceil($daysToRace / 7)),
                ];
            }

            $this->returnSuccess([
                'available' => true,
                'vdot' => round($vdot, 1),
                'vdot_source' => $vdotSource,
                'vdot_source_detail' => $vdotSourceDetail,
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

    private function parseDistanceKm(?string $distance, ?string $distanceKm): float {
        if ($distanceKm && (float)$distanceKm > 0) {
            return (float)$distanceKm;
        }
        if (!$distance) return 0;
        $map = [
            '1k' => 1, '1500m' => 1.5, '1_mile' => 1.60934,
            '3k' => 3, '5k' => 5, '10k' => 10,
            'half' => 21.0975, 'marathon' => 42.195,
            '50k' => 50, '100k' => 100,
        ];
        return $map[$distance] ?? 0;
    }

    private function parseTimeSec(?string $time): int {
        if (!$time) return 0;
        $parts = array_map('intval', explode(':', trim($time)));
        if (count($parts) === 3) {
            $asHms = $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
            // "22:00:00" часто ошибочно означает 22 мин — если часы >20, пробуем MM:SS
            if ($parts[0] > 20 && $parts[1] < 60 && $parts[2] < 60) {
                $asMinSec = $parts[0] * 60 + $parts[1];
                if ($asMinSec < 7200) return $asMinSec; // до 2 ч — разумно для 5–10 км
            }
            return $asHms;
        }
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }
        return (int)$time;
    }
}
