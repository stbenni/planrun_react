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
     * Нормализуем имя activity_type в английский ключ для frontend (TYPE_NAMES).
     * Кириллические значения из таблицы activity_types ('Бег', 'ОФП', 'СБУ' и т.д.)
     * переводятся в стабильные английские ключи, совместимые с workouts.activity_type.
     */
    private static function normalizeActivityType(?string $raw): string {
        // mb_strtolower обязателен для кириллицы — strtolower() работает только с ASCII.
        $name = mb_strtolower(trim((string)($raw ?? '')), 'UTF-8');
        $map = [
            'бег' => 'running',
            'офп' => 'other',
            'сбу' => 'sbu',
            'отдых' => 'rest',
            'силовая тренировка' => 'other',
            'растяжка' => 'stretching',
            'йога' => 'yoga',
            'плавание' => 'swimming',
            'велосипед' => 'cycling',
        ];
        if ($name === '') return 'running';
        return $map[$name] ?? $name;
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
            
            // Объединяем workouts (Strava, импорт) и workout_log (ручные записи)
            $rowsWorkouts = $this->repository->getWorkoutsSummary($userId);
            $rowsLog = $this->repository->getWorkoutLogSummary($userId);
            
            $summary = [];
            $mergeRow = function ($row, $workoutUrl) {
                return [
                    'count' => (int)$row['workout_count'],
                    'distance' => $row['total_distance'] ? round((float)$row['total_distance'], 2) : null,
                    'duration' => $row['total_duration'] ? (int)$row['total_duration'] : null,
                    'duration_seconds' => !empty($row['total_duration_seconds']) ? (int)$row['total_duration_seconds'] : null,
                    'pace' => $row['avg_pace'],
                    'hr' => $row['avg_hr'] ? round((float)$row['avg_hr']) : null,
                    'workout_url' => $workoutUrl,
                    'activity_type' => $row['activity_type'] ?? 'running'
                ];
            };
            
            foreach ($rowsWorkouts as $row) {
                $workoutUrl = null;
                if ($row['first_workout_id']) {
                    $workoutUrl = getWorkoutDetailsUrl($row['first_workout_id'], $userId);
                }
                $summary[$row['workout_date']] = $mergeRow($row, $workoutUrl);
            }
            
            foreach ($rowsLog as $row) {
                $date = $row['workout_date'];
                $entry = $mergeRow($row, null);
                if (isset($summary[$date])) {
                    $existing = $summary[$date];
                    $dist = round((float)($existing['distance'] ?? 0) + (float)($entry['distance'] ?? 0), 2);
                    $dur = (int)($existing['duration'] ?? 0) + (int)($entry['duration'] ?? 0);
                    $durSec = (int)($existing['duration_seconds'] ?? 0) + (int)($entry['duration_seconds'] ?? 0);
                    $summary[$date] = [
                        'count' => $existing['count'] + $entry['count'],
                        'distance' => $dist > 0 ? $dist : null,
                        'duration' => $dur > 0 ? $dur : null,
                        'duration_seconds' => $durSec > 0 ? $durSec : null,
                        'pace' => $existing['pace'] ?: $entry['pace'],
                        'hr' => $existing['hr'] ?: $entry['hr'],
                        'workout_url' => $existing['workout_url'] ?: $entry['workout_url'],
                        'activity_type' => $existing['activity_type'] ?? $entry['activity_type']
                    ];
                } else {
                    $summary[$date] = $entry;
                }
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
        $autoByDate = [];

        // Автоматические тренировки из workouts (GPS/часы — приоритетный источник)
        $autoStmt = $this->db->prepare("
            SELECT id, activity_type, start_time, end_time, duration_minutes, duration_seconds,
                   distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain, source, detected_type
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
            $dist = $row['distance_km'] ? (float)$row['distance_km'] : null;
            if ($dist !== null) {
                $autoByDate[$date][] = $dist;
            }
            $list[] = [
                'id' => (int)$row['id'],
                'date' => $date,
                'start_time' => $isoTime,
                'distance_km' => $dist,
                'duration_minutes' => $row['duration_minutes'] ? (int)$row['duration_minutes'] : null,
                'duration_seconds' => !empty($row['duration_seconds']) ? (int)$row['duration_seconds'] : null,
                'avg_pace' => $row['avg_pace'],
                'avg_heart_rate' => $row['avg_heart_rate'] ? (int)$row['avg_heart_rate'] : null,
                'max_heart_rate' => $row['max_heart_rate'] ? (int)$row['max_heart_rate'] : null,
                'elevation_gain' => $row['elevation_gain'] ? (int)$row['elevation_gain'] : null,
                'source' => $row['source'],
                'activity_type' => strtolower(trim($row['activity_type'] ?? 'running')),
                'detected_type' => !empty($row['detected_type']) ? strtolower(trim($row['detected_type'])) : null,
                'is_manual' => false,
            ];
        }
        $autoStmt->close();

        // Ручные отметки из workout_log — пропускаем дубль, если за день уже есть
        // GPS-импорт того же забега (близкая дистанция). GPS-данные богаче (пульс/круги/тип).
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
            $dist = $row['distance_km'] ? (float)$row['distance_km'] : null;
            if ($dist !== null && !empty($autoByDate[$date])) {
                $tol = max(1.5, $dist * 0.1);
                $isDup = false;
                foreach ($autoByDate[$date] as $autoDist) {
                    if (abs($autoDist - $dist) <= $tol) {
                        $isDup = true;
                        break;
                    }
                }
                if ($isDup) {
                    continue;
                }
            }
            $list[] = [
                'id' => 'log_' . $row['id'],
                'date' => $date,
                'start_time' => $date . 'T12:00:00',
                'distance_km' => $dist,
                'duration_minutes' => $row['duration_minutes'] ? (int)$row['duration_minutes'] : null,
                'duration_seconds' => null,
                'avg_pace' => $row['pace'],
                'activity_type' => self::normalizeActivityType($row['activity_type_name']),
                'is_manual' => true,
            ];
        }
        $logStmt->close();

        // Сортируем по start_time (новые сверху)
        usort($list, function ($a, $b) {
            $ta = strtotime($a['start_time']);
            $tb = strtotime($b['start_time']);
            return $tb <=> $ta;
        });

        return array_slice($list, 0, $limit);
    }
    
    /**
     * Найти VDOT по реальным тренировкам (когда нет контрольных/забегов или last_race устарел).
     * Использует взвешенное среднее по топ-результатам с учётом давности (recency decay).
     * Окно: 6 недель. Дистанция: 2–25 км (надёжный диапазон для VDOT).
     * При targetDistKm учитывается близость дистанции: результаты ближе к цели (напр. полумарафон
     * при цели марафон) получают больший вес, т.к. темп 5к всегда быстрее полумарафона.
     *
     * @param int $userId ID пользователя
     * @param int $weeksWindow Окно в неделях (по умолчанию 6)
     * @param float|null $targetDistKm Целевая дистанция забега (5, 10, 21.1, 42.2) — для взвешивания по релевантности
     * @return array|null { distance_km, time_sec, vdot, vdot_source_detail } или null
     */
    public function getBestResultForVdot(int $userId, int $weeksWindow = 6, ?float $targetDistKm = null): ?array {
        require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

        $cutoff = date('Y-m-d', strtotime("-{$weeksWindow} weeks"));
        $candidates = [];

        $parsePaceToSec = function (?string $pace): ?int {
            if (!$pace || trim($pace) === '') return null;
            $parts = explode(':', trim($pace));
            if (count($parts) === 2) {
                return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
            }
            return null;
        };

        // workout_log: только бег (исключаем walking, hiking и др.)
        $logStmt = $this->db->prepare("
            SELECT wl.distance_km, wl.result_time, wl.training_date
            FROM workout_log wl
            LEFT JOIN activity_types at ON wl.activity_type_id = at.id
            WHERE wl.user_id = ? AND wl.is_completed = 1
              AND wl.training_date >= ?
              AND wl.distance_km >= 2 AND wl.distance_km <= 50 AND wl.distance_km IS NOT NULL
              AND wl.result_time IS NOT NULL AND TRIM(wl.result_time) != ''
              AND LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) IN ('running', 'run', 'trail running', 'treadmill', 'бег')
        ");
        $logStmt->bind_param("is", $userId, $cutoff);
        $logStmt->execute();
        $logResult = $logStmt->get_result();
        while ($row = $logResult->fetch_assoc()) {
            $dist = (float)$row['distance_km'];
            $timeStr = $row['result_time'];
            $parts = explode(':', $timeStr);
            $timeSec = count($parts) === 3
                ? (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2]
                : (count($parts) === 2 ? (int)$parts[0] * 60 + (int)$parts[1] : 0);
            if ($dist <= 0 || $timeSec <= 0) continue;
            $v = estimateVDOT($dist, $timeSec);
            if ($v >= 20 && $v <= 85) {
                $weeksAgo = (time() - strtotime($row['training_date'])) / (7 * 86400);
                $candidates[] = [
                    'distance_km' => $dist,
                    'time_sec' => $timeSec,
                    'vdot' => $v,
                    'weeks_ago' => $weeksAgo,
                ];
            }
        }
        $logStmt->close();

        // workouts: только бег (исключаем walking, hiking и др.)
        $autoStmt = $this->db->prepare("
            SELECT distance_km, duration_seconds, duration_minutes, avg_pace, start_time
            FROM workouts
            WHERE user_id = ? AND DATE(start_time) >= ?
              AND distance_km >= 2 AND distance_km <= 50 AND distance_km IS NOT NULL
              AND LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) IN ('running', 'run', 'trail running', 'treadmill', 'бег')
        ");
        $autoStmt->bind_param("is", $userId, $cutoff);
        $autoStmt->execute();
        $autoResult = $autoStmt->get_result();
        while ($row = $autoResult->fetch_assoc()) {
            $dist = (float)$row['distance_km'];
            $timeSec = null;
            if (!empty($row['duration_seconds']) && (int)$row['duration_seconds'] > 0) {
                $timeSec = (int)$row['duration_seconds'];
            } elseif (!empty($row['duration_minutes']) && (int)$row['duration_minutes'] > 0) {
                $timeSec = (int)$row['duration_minutes'] * 60;
            } elseif (!empty($row['avg_pace'])) {
                $paceSec = $parsePaceToSec($row['avg_pace']);
                if ($paceSec !== null && $paceSec > 0) {
                    $timeSec = (int)round($dist * $paceSec);
                }
            }
            if ($timeSec === null || $timeSec <= 0) continue;
            $v = estimateVDOT($dist, $timeSec);
            if ($v >= 20 && $v <= 85) {
                $date = date('Y-m-d', strtotime($row['start_time']));
                $weeksAgo = (time() - strtotime($date)) / (7 * 86400);
                $candidates[] = [
                    'distance_km' => $dist,
                    'time_sec' => $timeSec,
                    'vdot' => $v,
                    'weeks_ago' => $weeksAgo,
                ];
            }
        }
        $autoStmt->close();

        // Дедупликация: если workout_log и workouts содержат одну тренировку,
        // оставляем только одну (с лучшим VDOT) для каждой даты+дистанции
        $seen = [];
        $deduplicated = [];
        foreach ($candidates as $c) {
            $roundedDist = round($c['distance_km'], 1);
            $roundedWeeks = round($c['weeks_ago'], 2);
            $key = $roundedDist . '_' . $roundedWeeks;
            if (!isset($seen[$key]) || $c['vdot'] > $seen[$key]['vdot']) {
                $seen[$key] = $c;
            }
        }
        $candidates = array_values($seen);

        if (empty($candidates)) {
            return null;
        }

        // Лучший единичный результат (для возврата distance_km/time_sec)
        usort($candidates, fn($a, $b) => $b['vdot'] <=> $a['vdot']);
        $bestSingle = $candidates[0];

        // Отсекаем easy/recovery: оставляем только тренировки с VDOT ≥ 85% от лучшего
        $vdotThreshold = $bestSingle['vdot'] * 0.85;
        $hardEfforts = array_filter($candidates, fn($c) => $c['vdot'] >= $vdotThreshold);
        $hardEfforts = array_values($hardEfforts);

        // Сортируем quality efforts по свежести (недавние первые), берём топ-5
        usort($hardEfforts, fn($a, $b) => $a['weeks_ago'] <=> $b['weeks_ago']);
        $top = array_slice($hardEfforts, 0, 5);

        // Recency weight: 0.85^weeks_ago — недавние важнее
        // Distance relevance: при целевой дистанции — результаты ближе к ней важнее
        $weightedSum = 0;
        $weightTotal = 0;

        foreach ($top as $c) {
            $w = pow(0.85, $c['weeks_ago']);
            if ($targetDistKm > 0 && $targetDistKm <= 100) {
                $ratio = $c['distance_km'] / $targetDistKm;
                $distRelevance = 1.0 / (1.0 + abs(log($ratio, 2)));
                $w *= $distRelevance;
            }
            $weightedSum += $c['vdot'] * $w;
            $weightTotal += $w;
        }

        $avgVdot = $weightTotal > 0 ? $weightedSum / $weightTotal : $bestSingle['vdot'];
        $avgVdot = max(20, min(85, round($avgVdot, 1)));

        return [
            'distance_km' => $bestSingle['distance_km'],
            'time_sec' => $bestSingle['time_sec'],
            'vdot' => $avgVdot,
            'vdot_source_detail' => 'по ' . count($top) . ' качественным тренировкам из ' . count($candidates) . ' за ' . $weeksWindow . ' нед.',
        ];
    }

    /**
     * Phase B.4 (PR4): trajectory лучших результатов на ключевых дистанциях.
     *
     * Возвращает массив {distance_label, distance_km, time_sec, pace_sec, date, vdot}
     * по бакетам 5k / 10k / half / marathon за период `$weeksWindow` (default 52 нед).
     * Для каждой дистанции — лучший pace (минимальный time_sec). Сортировка по дате убыв.
     *
     * Используется DeepSeek чтобы видеть реальный прогресс спортсмена, а не только
     * последнюю гонку из user.last_race_*. Вычисляется через workout_log + workouts
     * с дедупликацией по дате+дистанции (берём лучший VDOT из обоих источников).
     */
    public function getBestRacesProgression(int $userId, int $weeksWindow = 52): array {
        require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

        $cutoff = date('Y-m-d', strtotime("-{$weeksWindow} weeks"));
        $candidates = [];

        $logStmt = $this->db->prepare("
            SELECT wl.distance_km, wl.result_time, wl.training_date, wl.duration_minutes
            FROM workout_log wl
            LEFT JOIN activity_types at ON wl.activity_type_id = at.id
            WHERE wl.user_id = ? AND wl.is_completed = 1
              AND wl.training_date >= ?
              AND wl.distance_km IS NOT NULL AND wl.distance_km >= 3 AND wl.distance_km <= 50
              AND LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) IN ('running', 'run', 'trail running', 'treadmill', 'бег')
        ");
        if ($logStmt) {
            $logStmt->bind_param("is", $userId, $cutoff);
            $logStmt->execute();
            $logResult = $logStmt->get_result();
            while ($row = $logResult->fetch_assoc()) {
                $dist = (float) $row['distance_km'];
                $timeStr = (string) ($row['result_time'] ?? '');
                $timeSec = 0;
                if ($timeStr !== '') {
                    $parts = explode(':', $timeStr);
                    $timeSec = count($parts) === 3
                        ? (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2]
                        : (count($parts) === 2 ? (int) $parts[0] * 60 + (int) $parts[1] : 0);
                }
                if ($timeSec <= 0 && !empty($row['duration_minutes'])) {
                    $timeSec = (int) $row['duration_minutes'] * 60;
                }
                if ($dist <= 0 || $timeSec <= 0) continue;
                $candidates[] = [
                    'distance_km' => $dist,
                    'time_sec' => $timeSec,
                    'date' => (string) $row['training_date'],
                    'source' => 'workout_log',
                ];
            }
            $logStmt->close();
        }

        $autoStmt = $this->db->prepare("
            SELECT distance_km, duration_seconds, duration_minutes, start_time
            FROM workouts
            WHERE user_id = ? AND DATE(start_time) >= ?
              AND distance_km IS NOT NULL AND distance_km >= 3 AND distance_km <= 50
              AND LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) IN ('running', 'run', 'trail running', 'treadmill', 'бег')
        ");
        if ($autoStmt) {
            $autoStmt->bind_param("is", $userId, $cutoff);
            $autoStmt->execute();
            $autoResult = $autoStmt->get_result();
            while ($row = $autoResult->fetch_assoc()) {
                $dist = (float) $row['distance_km'];
                $timeSec = 0;
                if (!empty($row['duration_seconds']) && (int) $row['duration_seconds'] > 0) {
                    $timeSec = (int) $row['duration_seconds'];
                } elseif (!empty($row['duration_minutes']) && (int) $row['duration_minutes'] > 0) {
                    $timeSec = (int) $row['duration_minutes'] * 60;
                }
                if ($dist <= 0 || $timeSec <= 0) continue;
                $candidates[] = [
                    'distance_km' => $dist,
                    'time_sec' => $timeSec,
                    'date' => date('Y-m-d', strtotime((string) $row['start_time'])),
                    'source' => 'workouts',
                ];
            }
            $autoStmt->close();
        }

        if (empty($candidates)) {
            return [];
        }

        // Бакеты по дистанциям (km min, km max, label, canonical_km).
        $buckets = [
            ['min' => 4.5,  'max' => 5.5,  'label' => '5k',       'canonical' => 5.0],
            ['min' => 8.5,  'max' => 11.5, 'label' => '10k',      'canonical' => 10.0],
            ['min' => 19.5, 'max' => 22.5, 'label' => 'half',     'canonical' => 21.0975],
            ['min' => 40.0, 'max' => 44.0, 'label' => 'marathon', 'canonical' => 42.195],
        ];

        $best = [];
        foreach ($candidates as $c) {
            foreach ($buckets as $b) {
                if ($c['distance_km'] >= $b['min'] && $c['distance_km'] <= $b['max']) {
                    $paceSec = (int) round($c['time_sec'] / $c['distance_km']);
                    $key = $b['label'];
                    if (!isset($best[$key]) || $paceSec < $best[$key]['pace_sec']) {
                        $vdot = (float) estimateVDOT($c['distance_km'], $c['time_sec']);
                        $best[$key] = [
                            'distance_label' => $b['label'],
                            'distance_km' => round($c['distance_km'], 2),
                            'time_sec' => $c['time_sec'],
                            'pace_sec' => $paceSec,
                            'date' => $c['date'],
                            'vdot' => round($vdot, 1),
                        ];
                    }
                    break;
                }
            }
        }

        if (empty($best)) {
            return [];
        }

        $result = array_values($best);
        usort($result, fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        return $result;
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
