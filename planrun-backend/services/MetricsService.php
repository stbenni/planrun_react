<?php
/**
 * MetricsService — единая точка входа для расчётных метрик бегуна.
 *
 * Делегирует в соответствующие калькуляторы, но предоставляет ОДИН
 * стабильный API вместо 5 разных мест расчёта.
 *
 * Примеры:
 *   $metrics = new MetricsService($db);
 *   $vdot    = $metrics->getVdot(42);                    // {vdot, source, ...}
 *   $time    = $metrics->predictRaceTime(50.0, 21.1);    // секунды
 *   $paces   = $metrics->getTrainingPaces(50.0);         // easy, threshold, ...
 *   $acwr    = $metrics->calculateACWR(42);               // {acwr, zone, ...}
 *   $compl   = $metrics->getCompliance(42, 14);           // {planned, completed, missed}
 *   $weekKm  = $metrics->getWeeklyKm(42);                 // float
 */

require_once __DIR__ . '/../repositories/WorkoutRepository.php';
require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

class MetricsService {
    private $db;
    private ?WorkoutRepository $workoutRepo = null;

    public function __construct($db) {
        $this->db = $db;
    }

    private function workoutRepo(): WorkoutRepository {
        if (!$this->workoutRepo) {
            $this->workoutRepo = new WorkoutRepository($this->db);
        }
        return $this->workoutRepo;
    }

    // ══════════════════════════════════════════════
    //  VDOT
    // ══════════════════════════════════════════════

    /**
     * Вычислить VDOT по результату забега.
     * Каноническая обёртка для estimateVDOT() из prompt_builder.php.
     *
     * @param float $distanceKm  Дистанция в км
     * @param int   $timeSec     Финишное время в секундах
     * @return float VDOT (20-85)
     */
    public function estimateVdot(float $distanceKm, int $timeSec): float {
        return estimateVDOT($distanceKm, $timeSec);
    }

    /**
     * Получить текущий VDOT пользователя с приоритетной системой источников.
     * Делегирует в TrainingStateBuilder::build() — единственная авторитетная реализация.
     *
     * Приоритет источников:
     *   1. benchmark_override — явный ориентир из причины пересчёта
     *   2. last_race — свежий (<=8 недель) результат забега
     *   3. best_result — лучший training effort за 6 недель (StatsService)
     *   4. last_race_stale — устаревший забег
     *   5. easy_pace — оценка по комфортному темпу
     *   6. target_time — цель ×0.92
     *
     * @return array{vdot: float|null, source: string|null, detail: string|null}
     */
    public function getVdot(int $userId): array {
        require_once __DIR__ . '/TrainingStateBuilder.php';
        require_once __DIR__ . '/StatsService.php';

        $statsService = new StatsService($this->db);
        $builder = new TrainingStateBuilder($this->db, $statsService);

        // Получаем user данные
        require_once __DIR__ . '/../repositories/UserRepository.php';
        $userRepo = new \UserRepository($this->db);
        $user = $userRepo->getForPlanning($userId);
        if (!$user) {
            return ['vdot' => null, 'source' => null, 'detail' => null];
        }

        $state = $builder->build($user, $userId);

        return [
            'vdot' => $state['vdot'] ?? null,
            'source' => $state['vdot_source'] ?? null,
            'detail' => $state['vdot_source_detail'] ?? null,
        ];
    }

    // ══════════════════════════════════════════════
    //  Race prediction & paces
    // ══════════════════════════════════════════════

    /**
     * Предсказать время на дистанцию по VDOT (секунды).
     * ЕДИНСТВЕННАЯ реализация — через prompt_builder::predictRaceTime().
     * Удалить дубль в WeeklyAdaptationEngine при миграции.
     */
    public function predictRaceTime(float $vdot, float $targetDistKm): int {
        return predictRaceTime($vdot, $targetDistKm);
    }

    /**
     * Тренировочные темпы по зонам (sec/km).
     * ЕДИНСТВЕННАЯ реализация — prompt_builder::getTrainingPaces().
     *
     * @return array{easy: int[], marathon: int, threshold: int, interval: int, repetition: int}
     */
    public function getTrainingPaces(float $vdot): array {
        return getTrainingPaces($vdot);
    }

    // ══════════════════════════════════════════════
    //  ACWR (Acute:Chronic Workload Ratio)
    // ══════════════════════════════════════════════

    /**
     * ACWR за последние 28 дней.
     * Перенесено из ChatContextBuilder::calculateACWR() — ЕДИНСТВЕННАЯ реализация.
     *
     * @return array{acwr: float|null, acute: float, chronic: float, zone: string}
     */
    public function calculateACWR(int $userId): array {
        $today = date('Y-m-d');
        $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
        $twentyEightDaysAgo = date('Y-m-d', strtotime('-28 days'));

        $activities = $this->workoutRepo()->getAllActivitiesForDateRange($userId, $twentyEightDaysAgo, $today);

        $acuteLoad = 0;
        $chronicLoad = 0;

        foreach ($activities as $row) {
            if (!$this->isRunningLoadRelevantActivity((string) ($row['activity_type'] ?? ''))) {
                continue;
            }

            $date = $row['date'];
            $dist = (float) ($row['distance_km'] ?? 0);
            $dur = (int) ($row['duration_minutes'] ?? 0);
            $rating = $row['rating'] !== null ? (int) $row['rating'] : null;

            // Субъективная тяжесть 1-10: 1/10 → factor 0.2, 10/10 → factor 1.0.
            if ($dur > 0 && $rating !== null && $rating >= 1 && $rating <= 10) {
                $intensityFactor = 0.2 + ((($rating - 1) / 9) * 0.8);
                $load = $dur * $intensityFactor;
            } elseif ($dist > 0) {
                $load = $dist * 6; // proxy: ~6 min/km
            } else {
                continue;
            }

            $chronicLoad += $load;
            if ($date >= $sevenDaysAgo) {
                $acuteLoad += $load;
            }
        }

        $chronicWeekly = $chronicLoad / 4;
        $acwr = $chronicWeekly > 0 ? round($acuteLoad / $chronicWeekly, 2) : null;

        $zone = 'unknown';
        if ($acwr !== null) {
            if ($acwr < 0.8) $zone = 'low';
            elseif ($acwr <= 1.3) $zone = 'optimal';
            elseif ($acwr <= 1.5) $zone = 'caution';
            else $zone = 'danger';
        }

        return [
            'acwr' => $acwr,
            'acute' => round($acuteLoad, 1),
            'chronic' => round($chronicWeekly, 1),
            'zone' => $zone,
        ];
    }

    private function isRunningLoadRelevantActivity(string $activityType): bool {
        $normalized = mb_strtolower(trim($activityType));
        return in_array($normalized, ['running', 'trail running', 'treadmill', 'бег'], true);
    }

    // ══════════════════════════════════════════════
    //  Compliance & Volume
    // ══════════════════════════════════════════════

    /**
     * Compliance за последние N дней (по умолчанию 14).
     *
     * @return array{planned: int, completed: int, missed: int, pct: int}
     */
    public function getCompliance(int $userId, int $days = 14): array {
        $from = date('Y-m-d', strtotime("-{$days} days"));
        $to = date('Y-m-d');

        $result = $this->workoutRepo()->getCompliance($userId, $from, $to);
        $result['pct'] = $result['planned'] > 0
            ? (int) round(($result['completed'] / $result['planned']) * 100)
            : 0;
        return $result;
    }

    /**
     * Текущий недельный километраж (пн-вс текущей недели).
     */
    public function getWeeklyKm(int $userId): float {
        $monday = date('Y-m-d', strtotime('monday this week'));
        $sunday = date('Y-m-d', strtotime('sunday this week'));
        return $this->workoutRepo()->getWeeklyKm($userId, $monday, $sunday);
    }

    /**
     * Средний недельный километраж за последние N недель.
     */
    public function getAvgWeeklyKm(int $userId, int $weeks = 4): ?float {
        return $this->workoutRepo()->getAvgWeeklyKm($userId, $weeks);
    }

    /**
     * Дней с последней тренировки.
     */
    public function getDaysSinceLastWorkout(int $userId): ?int {
        return $this->workoutRepo()->getDaysSinceLastWorkout($userId);
    }
}
