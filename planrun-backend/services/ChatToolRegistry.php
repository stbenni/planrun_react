<?php
/**
 * Реестр и исполнитель инструментов (tools) AI-чата.
 * Определяет доступные tools и выполняет их по имени.
 */

require_once __DIR__ . '/DateResolver.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class ChatToolRegistry {

    private $db;
    private ChatContextBuilder $contextBuilder;

    public function __construct($db, ChatContextBuilder $contextBuilder) {
        $this->db = $db;
        $this->contextBuilder = $contextBuilder;
    }

    public function getChatTools(): array {
        return [
            $this->toolDef('get_plan', 'Получить актуальный план тренировок на неделю из БД.', [
                'week_number' => ['type' => 'integer', 'description' => 'Номер недели (например 13)'],
                'date' => ['type' => 'string', 'description' => 'Дата Y-m-d — план на неделю, содержащую эту дату'],
            ]),
            $this->toolDef('get_workouts', 'Получить историю выполненных тренировок за период.', [
                'date_from' => ['type' => 'string', 'description' => 'Начало периода Y-m-d'],
                'date_to' => ['type' => 'string', 'description' => 'Конец периода Y-m-d'],
            ], ['date_from', 'date_to']),
            $this->toolDef('get_day_details', 'Получить полные детали конкретного дня: план + упражнения + фактический результат.', [
                'date' => ['type' => 'string', 'description' => 'Дата Y-m-d'],
            ], ['date']),
            $this->toolDef('update_training_day', 'Изменить запланированную тренировку на конкретную дату. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'date' => ['type' => 'string', 'description' => 'Дата тренировки Y-m-d'],
                'type' => ['type' => 'string', 'description' => 'Новый тип', 'enum' => self::ALLOWED_TYPES],
                'description' => ['type' => 'string', 'description' => 'Описание тренировки'],
            ], ['date', 'type']),
            $this->toolDef('swap_training_days', 'Поменять местами тренировки на двух датах. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'date1' => ['type' => 'string', 'description' => 'Первая дата Y-m-d'],
                'date2' => ['type' => 'string', 'description' => 'Вторая дата Y-m-d'],
            ], ['date1', 'date2']),
            $this->toolDef('delete_training_day', 'Удалить запланированную тренировку на дату. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'date' => ['type' => 'string', 'description' => 'Дата тренировки Y-m-d'],
            ], ['date']),
            $this->toolDef('move_training_day', 'Перенести тренировку с одной даты на другую. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'source_date' => ['type' => 'string', 'description' => 'Исходная дата Y-m-d'],
                'target_date' => ['type' => 'string', 'description' => 'Целевая дата Y-m-d'],
            ], ['source_date', 'target_date']),
            $this->toolDef('recalculate_plan', 'Пересчитать весь план тренировок. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'reason' => ['type' => 'string', 'description' => 'Краткое описание причины пересчёта'],
            ]),
            $this->toolDef('generate_next_plan', 'Создать новый план после завершения предыдущего. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'goals' => ['type' => 'string', 'description' => 'Пожелания к новому плану'],
            ]),
            $this->toolDef('log_workout', 'Записать результат выполненной тренировки. ОБЯЗАТЕЛЬНО подтверди данные.', [
                'date' => ['type' => 'string', 'description' => 'Дата тренировки Y-m-d'],
                'distance_km' => ['type' => 'number', 'description' => 'Дистанция в километрах'],
                'duration_minutes' => ['type' => 'number', 'description' => 'Продолжительность в минутах'],
                'avg_heart_rate' => ['type' => 'integer', 'description' => 'Средний пульс'],
                'rating' => ['type' => 'integer', 'description' => 'Ощущение 1-5'],
                'notes' => ['type' => 'string', 'description' => 'Заметки к тренировке'],
            ], ['date', 'distance_km']),
            $this->toolDef('get_stats', 'Статистика тренировок: объёмы, выполнение плана, динамика.', [
                'period' => ['type' => 'string', 'description' => 'Период: week/month/plan/all', 'enum' => ['week', 'month', 'plan', 'all']],
            ]),
            $this->toolDef('race_prediction', 'Прогноз времени на забег по текущей форме (VDOT).', [
                'distance' => ['type' => 'string', 'description' => 'Дистанция: 5k/10k/half/marathon', 'enum' => ['5k', '10k', 'half', 'marathon']],
            ]),
            $this->toolDef('get_profile', 'Получить профиль пользователя.', []),
            $this->toolDef('update_profile', 'Обновить данные профиля. ОБЯЗАТЕЛЬНО подтверди.', [
                'field' => ['type' => 'string', 'description' => 'Поле для обновления', 'enum' => self::PROFILE_FIELDS],
                'value' => ['type' => 'string', 'description' => 'Новое значение'],
            ], ['field', 'value']),
            $this->toolDef('get_training_load', 'Анализ тренировочной нагрузки: ATL/CTL/TSB и ACWR.', []),
            $this->toolDef('add_training_day', 'Добавить новую тренировку на дату. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'date' => ['type' => 'string', 'description' => 'Дата Y-m-d'],
                'type' => ['type' => 'string', 'description' => 'Тип тренировки', 'enum' => self::ALLOWED_TYPES],
                'description' => ['type' => 'string', 'description' => 'Описание тренировки'],
            ], ['date', 'type']),
            $this->toolDef('copy_day', 'Скопировать тренировку с одной даты на другую. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'source_date' => ['type' => 'string', 'description' => 'Дата-источник Y-m-d'],
                'target_date' => ['type' => 'string', 'description' => 'Целевая дата Y-m-d'],
            ], ['source_date', 'target_date']),
            $this->toolDef('get_date', 'Преобразовать текстовую дату (завтра, в среду) в Y-m-d.', [
                'phrase' => ['type' => 'string', 'description' => 'Текстовая дата (завтра, в пятницу, 15 марта)'],
            ], ['phrase']),
        ];
    }

    public function executeTool(string $name, string $argsJson, ?int $userId): string {
        $args = json_decode($argsJson, true);
        if ($args === null && $argsJson !== '' && $argsJson !== '{}' && $argsJson !== 'null') {
            Logger::warning('Tool args JSON parse error', [
                'tool' => $name, 'args_raw' => mb_substr($argsJson, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);
            $args = [];
        }
        if (!is_array($args)) $args = [];

        $this->resolveNaturalDateArgs($args, $userId);

        Logger::debug('executeTool', ['tool' => $name, 'args' => $args, 'userId' => $userId]);

        $dispatch = [
            'get_date' => fn() => $this->executeGetDate($args, $userId),
            'get_plan' => fn() => $this->executeGetPlan($args, $userId),
            'get_workouts' => fn() => $this->executeGetWorkouts($args, $userId),
            'get_day_details' => fn() => $this->executeGetDayDetails($args, $userId),
            'update_training_day' => fn() => $this->executeUpdateTrainingDay($args, $userId),
            'swap_training_days' => fn() => $this->executeSwapTrainingDays($args, $userId),
            'delete_training_day' => fn() => $this->executeDeleteTrainingDay($args, $userId),
            'move_training_day' => fn() => $this->executeMoveTrainingDay($args, $userId),
            'recalculate_plan' => fn() => $this->executeRecalculatePlan($args, $userId),
            'generate_next_plan' => fn() => $this->executeGenerateNextPlan($args, $userId),
            'log_workout' => fn() => $this->executeLogWorkout($args, $userId),
            'get_stats' => fn() => $this->executeGetStats($args, $userId),
            'race_prediction' => fn() => $this->executeRacePrediction($args, $userId),
            'get_profile' => fn() => $this->executeGetProfile($args, $userId),
            'update_profile' => fn() => $this->executeUpdateProfile($args, $userId),
            'get_training_load' => fn() => $this->executeGetTrainingLoad($args, $userId),
            'add_training_day' => fn() => $this->executeAddTrainingDay($args, $userId),
            'copy_day' => fn() => $this->executeCopyDay($args, $userId),
        ];

        if (isset($dispatch[$name])) {
            return $dispatch[$name]();
        }
        return json_encode(['error' => 'unknown_tool']);
    }

    // ── Constants ──

    private const ALLOWED_TYPES = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'rest', 'other', 'sbu', 'race', 'free'];

    private const PROFILE_FIELDS = [
        'weight_kg', 'height_cm', 'goal_type', 'experience_level',
        'sessions_per_week', 'easy_pace_sec', 'weekly_base_km',
        'race_distance', 'race_target_time', 'race_date', 'training_time_pref'
    ];

    private const TYPE_RU = [
        'easy' => 'Лёгкий бег', 'long' => 'Длительный бег', 'tempo' => 'Темповый бег',
        'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'control' => 'Контрольный забег',
        'rest' => 'Отдых', 'other' => 'ОФП', 'sbu' => 'СБУ', 'race' => 'Забег', 'free' => 'Свободная'
    ];

    // ── Tool helper ──

    private function toolDef(string $name, string $description, array $properties, array $required = []): array {
        $params = ['type' => 'object', 'properties' => (object) $properties, 'required' => $required];
        return ['type' => 'function', 'function' => compact('name', 'description', 'parameters')];
    }

    private function requireUser(?int $userId): ?string {
        return $userId ? null : json_encode(['error' => 'user_required']);
    }

    private function validateDate(string $date): bool {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    private function formatDateRu(string $date): string {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt ? $dt->format('d.m.Y') : $date;
    }

    private function getUserTz(?int $userId): DateTimeZone {
        $tzName = $userId ? getUserTimezone($userId) : 'Europe/Moscow';
        try { return new DateTimeZone($tzName); } catch (Exception $e) { return new DateTimeZone('Europe/Moscow'); }
    }

    /**
     * Auto-resolve natural language dates ("завтра", "в среду") in date-type args.
     * If value doesn't match Y-m-d format, try DateResolver.
     */
    private function resolveNaturalDateArgs(array &$args, ?int $userId): void {
        $dateKeys = ['date', 'date1', 'date2', 'source_date', 'target_date', 'date_from', 'date_to'];
        $resolver = new DateResolver();
        $tz = $this->getUserTz($userId);
        $today = new DateTime('now', $tz);
        $today->setTime(0, 0, 0);

        foreach ($dateKeys as $key) {
            if (!isset($args[$key]) || $args[$key] === '') continue;
            $val = trim($args[$key]);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) continue;
            $resolved = $resolver->resolveFromText($val, $today);
            if ($resolved !== null) {
                Logger::debug('Resolved natural date in tool arg', ['key' => $key, 'raw' => $val, 'resolved' => $resolved]);
                $args[$key] = $resolved;
            }
        }
    }

    // ── DB helpers ──

    public function getDayPlanDataByDate(int $userId, string $date): ?array {
        $stmt = $this->db->prepare(
            "SELECT d.id, d.type, d.description FROM training_plan_days d
             JOIN training_plan_weeks w ON d.week_id = w.id
             WHERE w.user_id = ? AND d.date = ?
             ORDER BY d.id DESC LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('is', $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? ['id' => (int) $row['id'], 'type' => $row['type'] ?? 'rest', 'description' => $row['description'] ?? ''] : null;
    }

    public function findDayIdByDate(int $userId, string $date): ?int {
        $stmt = $this->db->prepare(
            "SELECT d.id FROM training_plan_days d
             JOIN training_plan_weeks w ON d.week_id = w.id
             WHERE w.user_id = ? AND d.date = ?
             ORDER BY d.id DESC LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('is', $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    // ── Tool implementations ──

    private function executeGetDate(array $args, ?int $userId): string {
        $phrase = $args['phrase'] ?? '';
        if ($phrase === '') return json_encode(['date' => null, 'error' => 'empty_phrase']);
        $tz = $this->getUserTz($userId);
        $today = new DateTime('now', $tz);
        $today->setTime(0, 0, 0);
        $date = (new DateResolver())->resolveFromText($phrase, $today);
        return json_encode(['date' => $date]);
    }

    private function executeGetPlan(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        require_once __DIR__ . '/../repositories/WeekRepository.php';
        $repo = new WeekRepository($this->db);

        if (!empty($args['week_number'])) {
            $week = $repo->getWeekByWeekNumber($userId, (int) $args['week_number']);
        } elseif (!empty($args['date'])) {
            $week = $repo->getWeekByDate($userId, $args['date']);
        } else {
            $today = (new DateTime('now', $this->getUserTz($userId)))->format('Y-m-d');
            $week = $repo->getWeekByDate($userId, $today);
        }
        if (!$week) return json_encode(['error' => 'week_not_found', 'message' => 'Неделя не найдена в плане']);

        $dayLabels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $byDay = [];
        foreach ($repo->getDaysByWeekId($userId, (int) $week['id']) as $d) {
            $byDay[] = [
                'day' => $dayLabels[(int) $d['day_of_week']] ?? (string) $d['day_of_week'],
                'date' => $d['date'] ?? null,
                'type' => self::TYPE_RU[$d['type'] ?? 'rest'] ?? ($d['type'] ?? 'rest'),
                'description' => trim($d['description'] ?? ''),
            ];
        }
        return json_encode([
            'week_number' => (int) $week['week_number'],
            'start_date' => $week['start_date'] ?? null,
            'days' => $byDay,
            'total_volume' => $week['total_volume'] ?? null,
        ]);
    }

    private function executeGetWorkouts(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $dateFrom = $args['date_from'] ?? '';
        $dateTo = $args['date_to'] ?? '';
        if (!$this->validateDate($dateFrom) || !$this->validateDate($dateTo)) {
            return json_encode(['error' => 'invalid_dates', 'message' => 'Формат дат: Y-m-d']);
        }
        $limit = 100;
        $workouts = $this->contextBuilder->getWorkoutsHistory($userId, $dateFrom, $dateTo, $limit);
        if (empty($workouts)) return json_encode(['workouts' => [], 'message' => 'Нет выполненных тренировок за период']);

        $ratingLabels = [1 => 'очень тяжело', 2 => 'тяжело', 3 => 'нормально', 4 => 'хорошо', 5 => 'отлично'];
        $formatted = [];
        $totalKm = 0;
        foreach ($workouts as $w) {
            $entry = ['date' => $w['date'], 'type' => isset($w['plan_type']) ? (self::TYPE_RU[$w['plan_type']] ?? $w['plan_type']) : null, 'is_key_workout' => !empty($w['is_key_workout'])];
            if (!empty($w['distance_km'])) { $entry['distance_km'] = (float) $w['distance_km']; $totalKm += (float) $w['distance_km']; }
            if (!empty($w['result_time']) && $w['result_time'] !== '0:00:00') $entry['time'] = $w['result_time'];
            if (!empty($w['pace']) && $w['pace'] !== '0:00') $entry['pace'] = $w['pace'];
            if (!empty($w['avg_heart_rate'])) $entry['avg_hr'] = (int) $w['avg_heart_rate'];
            if (!empty($w['rating'])) $entry['feeling'] = $ratingLabels[(int) $w['rating']] ?? (int) $w['rating'];
            $notes = trim($w['notes'] ?? '');
            if ($notes !== '') $entry['notes'] = mb_strlen($notes) > 300 ? mb_substr($notes, 0, 297) . '…' : $notes;
            $formatted[] = $entry;
        }

        $result = ['period' => "{$dateFrom} — {$dateTo}", 'total_workouts' => count($formatted), 'total_km' => round($totalKm, 1), 'workouts' => $formatted];
        if (count($formatted) >= $limit) $result['note'] = 'Показаны последние ' . $limit . ' тренировок за период.';
        return json_encode($result);
    }

    private function executeGetDayDetails(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);

        $details = $this->contextBuilder->getDayDetails($userId, $date);
        $result = ['date' => $date];
        $result['plan'] = $details['plan'] ?: null;
        if (!$details['plan']) $result['plan_message'] = 'На этот день нет запланированной тренировки';

        if (!empty($details['exercises'])) {
            $exFormatted = [];
            foreach ($details['exercises'] as $ex) {
                $e = ['category' => $ex['category'], 'name' => $ex['name']];
                foreach (['sets', 'reps', 'distance_m', 'duration_sec'] as $k) if (!empty($ex[$k])) $e[$k] = (int) $ex[$k];
                if (!empty($ex['weight_kg'])) $e['weight_kg'] = (float) $ex['weight_kg'];
                if (!empty($ex['pace'])) $e['pace'] = $ex['pace'];
                $exFormatted[] = $e;
            }
            $result['exercises'] = $exFormatted;
        }

        $formatWorkout = function (array $w): array {
            $out = ['completed' => true];
            if (!empty($w['distance_km'])) $out['distance_km'] = (float) $w['distance_km'];
            if (!empty($w['result_time']) && $w['result_time'] !== '0:00:00') $out['time'] = $w['result_time'];
            if (!empty($w['pace']) && $w['pace'] !== '0:00') $out['pace'] = $w['pace'];
            if (!empty($w['avg_heart_rate'])) $out['avg_hr'] = (int) $w['avg_heart_rate'];
            if (!empty($w['max_heart_rate'])) $out['max_hr'] = (int) $w['max_heart_rate'];
            if (!empty($w['avg_cadence'])) $out['cadence'] = (int) $w['avg_cadence'];
            if (!empty($w['elevation_gain'])) $out['elevation_m'] = (int) $w['elevation_gain'];
            if (!empty($w['calories'])) $out['calories'] = (int) $w['calories'];
            if (!empty($w['activity_type'])) $out['activity_type'] = $w['activity_type'];
            if (!empty($w['source'])) $out['source'] = $w['source'];
            if (!empty($w['rating'])) {
                $labels = [1 => 'очень тяжело', 2 => 'тяжело', 3 => 'нормально', 4 => 'хорошо', 5 => 'отлично'];
                $out['feeling'] = $labels[(int) $w['rating']] ?? (int) $w['rating'];
            }
            $notes = trim($w['notes'] ?? '');
            if ($notes !== '') $out['notes'] = mb_strlen($notes) > 500 ? mb_substr($notes, 0, 497) . '…' : $notes;
            return $out;
        };

        if (!empty($details['workouts']) && count($details['workouts']) > 1) {
            $cnt = count($details['workouts']);
            $result['workouts_count'] = $cnt;
            $result['workouts_note'] = "За этот день {$cnt} тренировки. Расскажи пользователю обо ВСЕХ.";
            $result['workouts'] = array_map($formatWorkout, $details['workouts']);
            unset($result['workout']);
        } elseif ($details['workout']) {
            $result['workout'] = $formatWorkout($details['workout']);
        } else {
            $result['workout'] = null;
            $result['workout_message'] = 'Тренировка не выполнена';
        }
        return json_encode($result);
    }

    private function executeUpdateTrainingDay(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        $type = $args['type'] ?? null;
        if (!$type || !in_array($type, self::ALLOWED_TYPES, true)) {
            return json_encode(['error' => 'invalid_type', 'message' => 'Допустимые типы: ' . implode(', ', self::ALLOWED_TYPES)]);
        }
        $dayId = $this->findDayIdByDate($userId, $date);
        if (!$dayId) return json_encode(['error' => 'no_plan_for_date', 'message' => "На дату {$date} нет запланированной тренировки. Используй add_training_day."]);

        try {
            require_once __DIR__ . '/WeekService.php';
            $data = ['type' => $type];
            if (isset($args['description'])) $data['description'] = $args['description'];
            if ($type === 'rest') { $data['description'] = $data['description'] ?? 'Отдых'; $data['is_key_workout'] = 0; }
            (new WeekService($this->db))->updateTrainingDayById($dayId, $userId, $data);
            return json_encode(['success' => true, 'message' => "Тренировка на {$this->formatDateRu($date)} изменена на «" . (self::TYPE_RU[$type] ?? $type) . "»"]);
        } catch (Exception $e) {
            return json_encode(['error' => 'update_failed', 'message' => 'Не удалось обновить: ' . $e->getMessage()]);
        }
    }

    private function executeDeleteTrainingDay(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        $dayId = $this->findDayIdByDate($userId, $date);
        if (!$dayId) return json_encode(['error' => 'no_plan_for_date', 'message' => "На дату {$date} нет запланированной тренировки"]);

        try {
            require_once __DIR__ . '/WeekService.php';
            (new WeekService($this->db))->deleteTrainingDayById($dayId, $userId);
            return json_encode(['success' => true, 'message' => "Тренировка на {$this->formatDateRu($date)} удалена"]);
        } catch (Exception $e) {
            return json_encode(['error' => 'delete_failed', 'message' => 'Не удалось удалить: ' . $e->getMessage()]);
        }
    }

    private function executeMoveTrainingDay(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $src = $args['source_date'] ?? '';
        $tgt = $args['target_date'] ?? '';
        if (!$this->validateDate($src) || !$this->validateDate($tgt)) return json_encode(['error' => 'invalid_dates', 'message' => 'Формат дат: Y-m-d']);
        if ($src === $tgt) return json_encode(['error' => 'same_dates', 'message' => 'Исходная и целевая даты совпадают']);
        $srcId = $this->findDayIdByDate($userId, $src);
        if (!$srcId) return json_encode(['error' => 'no_plan_for_date', 'message' => "На дату {$src} нет запланированной тренировки"]);

        try {
            require_once __DIR__ . '/WeekService.php';
            $ws = new WeekService($this->db);
            $tgtId = $this->findDayIdByDate($userId, $tgt);
            if ($tgtId) $ws->deleteTrainingDayById($tgtId, $userId);
            $ws->copyDay($src, $tgt, $userId);
            $ws->deleteTrainingDayById($srcId, $userId);
            return json_encode(['success' => true, 'message' => "Тренировка перенесена с {$this->formatDateRu($src)} на {$this->formatDateRu($tgt)}"]);
        } catch (Exception $e) {
            return json_encode(['error' => 'move_failed', 'message' => 'Не удалось перенести: ' . $e->getMessage()]);
        }
    }

    private function executeSwapTrainingDays(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $d1 = $args['date1'] ?? '';
        $d2 = $args['date2'] ?? '';
        if (!$this->validateDate($d1) || !$this->validateDate($d2)) return json_encode(['error' => 'invalid_dates', 'message' => 'Формат дат: Y-m-d']);
        if ($d1 === $d2) return json_encode(['error' => 'same_dates', 'message' => 'Даты должны отличаться']);

        $day1 = $this->getDayPlanDataByDate($userId, $d1);
        $day2 = $this->getDayPlanDataByDate($userId, $d2);
        if (!$day1) return json_encode(['error' => 'no_plan_for_date', 'message' => "На дату {$d1} нет запланированной тренировки"]);
        if (!$day2) return json_encode(['error' => 'no_plan_for_date', 'message' => "На дату {$d2} нет запланированной тренировки"]);

        try {
            require_once __DIR__ . '/WeekService.php';
            $ws = new WeekService($this->db);
            $ws->updateTrainingDayById($day1['id'], $userId, ['type' => $day2['type'], 'description' => $day2['description'] ?? '']);
            $ws->updateTrainingDayById($day2['id'], $userId, ['type' => $day1['type'], 'description' => $day1['description'] ?? '']);
            $t1 = self::TYPE_RU[$day1['type']] ?? $day1['type'];
            $t2 = self::TYPE_RU[$day2['type']] ?? $day2['type'];
            return json_encode(['success' => true, 'message' => "Тренировки поменяны местами: {$this->formatDateRu($d1)} — {$t2}, {$this->formatDateRu($d2)} — {$t1}"]);
        } catch (Exception $e) {
            return json_encode(['error' => 'swap_failed', 'message' => 'Не удалось поменять местами: ' . $e->getMessage()]);
        }
    }

    private function executeRecalculatePlan(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        try {
            require_once __DIR__ . '/TrainingPlanService.php';
            $result = (new TrainingPlanService($this->db))->recalculatePlan($userId, !empty($args['reason']) ? trim($args['reason']) : null);
            return json_encode(['success' => true, 'message' => 'Пересчёт плана запущен. Новый план будет готов через 3-5 минут.', 'pid' => $result['pid'] ?? null]);
        } catch (Exception $e) {
            return json_encode(['error' => 'recalculate_failed', 'message' => 'Не удалось запустить пересчёт: ' . $e->getMessage()]);
        }
    }

    private function executeGenerateNextPlan(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        try {
            require_once __DIR__ . '/TrainingPlanService.php';
            $result = (new TrainingPlanService($this->db))->generateNextPlan($userId, !empty($args['goals']) ? trim($args['goals']) : null);
            return json_encode(['success' => true, 'message' => 'Генерация нового плана запущена. План будет готов через 3-5 минут.', 'pid' => $result['pid'] ?? null]);
        } catch (Exception $e) {
            return json_encode(['error' => 'generate_next_failed', 'message' => 'Не удалось запустить генерацию: ' . $e->getMessage()]);
        }
    }

    private function executeLogWorkout(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        $distanceKm = (float) ($args['distance_km'] ?? 0);
        if ($distanceKm <= 0 || $distanceKm > 300) return json_encode(['error' => 'invalid_distance', 'message' => 'Дистанция должна быть от 0.1 до 300 км']);

        $durationMin = isset($args['duration_minutes']) ? (float) $args['duration_minutes'] : null;
        $avgHr = isset($args['avg_heart_rate']) ? (int) $args['avg_heart_rate'] : null;
        $rating = isset($args['rating']) ? (int) $args['rating'] : null;
        $notes = isset($args['notes']) ? trim($args['notes']) : null;
        if ($rating !== null && ($rating < 1 || $rating > 5)) $rating = null;

        try {
            $dateObj = new DateTime($date);
            $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
            $dayName = $dayNames[(int) $dateObj->format('N')];

            $weekNumber = 1;
            $ws = $this->db->prepare("SELECT week_number FROM training_plan_weeks WHERE user_id = ? AND start_date <= ? AND DATE_ADD(start_date, INTERVAL 6 DAY) >= ? ORDER BY start_date DESC LIMIT 1");
            $ws->bind_param('iss', $userId, $date, $date);
            $ws->execute();
            $wr = $ws->get_result()->fetch_assoc();
            $ws->close();
            if ($wr) $weekNumber = (int) $wr['week_number'];

            $resultTime = null;
            if ($durationMin) {
                $ts = (int) round($durationMin * 60);
                $resultTime = sprintf('%d:%02d:%02d', intdiv($ts, 3600), intdiv($ts % 3600, 60), $ts % 60);
            }

            require_once __DIR__ . '/WorkoutService.php';
            (new WorkoutService($this->db))->saveResult([
                'date' => $date, 'week' => $weekNumber, 'day' => $dayName,
                'activity_type_id' => 1, 'is_successful' => true,
                'result_distance' => $distanceKm, 'result_time' => $resultTime,
                'duration_minutes' => $durationMin ? (int) round($durationMin) : null,
                'avg_heart_rate' => $avgHr, 'rating' => $rating,
                'notes' => $notes ? ($notes . ' [из чата]') : '[из чата]',
            ], $userId);

            $summary = "Тренировка на {$this->formatDateRu($date)} записана: {$distanceKm} км";
            if ($resultTime) {
                $summary .= ", время {$resultTime}";
                if ($distanceKm > 0) {
                    $ps = ($durationMin * 60) / $distanceKm;
                    $summary .= sprintf(', темп %d:%02d мин/км', intdiv((int) $ps, 60), ((int) $ps) % 60);
                }
            }
            return json_encode(['success' => true, 'message' => $summary]);
        } catch (Exception $e) {
            return json_encode(['error' => 'log_failed', 'message' => 'Не удалось записать тренировку: ' . $e->getMessage()]);
        }
    }

    private function executeGetStats(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $period = $args['period'] ?? 'plan';

        try {
            require_once __DIR__ . '/../training_utils.php';
            require_once __DIR__ . '/StatsService.php';
            $ss = new StatsService($this->db);
            $stats = $ss->getStats($userId);
            $all = $ss->getAllWorkoutsList($userId, 500);

            $dateFrom = null;
            if ($period === 'week') $dateFrom = date('Y-m-d', strtotime('monday this week'));
            elseif ($period === 'month') $dateFrom = date('Y-m-d', strtotime('-30 days'));

            $filtered = $dateFrom ? array_values(array_filter($all, fn($w) => ($w['date'] ?? '') >= $dateFrom && ($w['date'] ?? '') <= date('Y-m-d'))) : $all;

            $totalKm = 0; $totalSec = 0; $weekly = [];
            foreach ($filtered as $w) {
                $km = (float) ($w['distance_km'] ?? 0); $totalKm += $km;
                $dm = (int) ($w['duration_minutes'] ?? 0); $ds = (int) ($w['duration_seconds'] ?? 0);
                $totalSec += $dm > 0 ? $dm * 60 : $ds;
                $d = $w['date'] ?? '';
                if ($d) { $wk = date('Y-W', strtotime($d)); $weekly[$wk] = ($weekly[$wk] ?? 0) + $km; }
            }

            $avgPace = ($totalKm > 0 && $totalSec > 0) ? sprintf('%d:%02d', intdiv((int)($totalSec / $totalKm), 60), ((int)($totalSec / $totalKm)) % 60) : null;
            $result = [
                'period' => $period,
                'plan_completion' => ['total_planned_days' => $stats['total'] ?? 0, 'completed_days' => $stats['completed'] ?? 0, 'completion_percent' => $stats['percentage'] ?? 0],
                'volume' => ['total_km' => round($totalKm, 1), 'total_workouts' => count($filtered), 'total_hours' => round($totalSec / 3600, 1), 'avg_pace' => $avgPace],
            ];
            if (count($weekly) > 1) $result['weekly_trend_km'] = array_map(fn($v) => round($v, 1), array_slice($weekly, -4, null, true));
            return json_encode($result);
        } catch (Exception $e) {
            return json_encode(['error' => 'stats_failed', 'message' => 'Не удалось получить статистику: ' . $e->getMessage()]);
        }
    }

    private function executeRacePrediction(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        try {
            require_once __DIR__ . '/StatsService.php';
            $vdotData = (new StatsService($this->db))->getBestResultForVdot($userId);
            if (empty($vdotData['vdot'])) return json_encode(['error' => 'no_data', 'message' => 'Недостаточно данных для прогноза.']);

            $vdot = (float) $vdotData['vdot'];
            $predictions = $this->calculateVdotPredictions($vdot);
            $distMap = ['5k' => '5 км', '10k' => '10 км', 'half' => 'Полумарафон (21.1 км)', 'marathon' => 'Марафон (42.2 км)'];
            $result = ['vdot' => round($vdot, 1), 'based_on' => $vdotData['vdot_source_detail'] ?? null];

            $distance = $args['distance'] ?? null;
            if ($distance && isset($predictions[$distance])) {
                $result['prediction'] = ['distance' => $distMap[$distance] ?? $distance, 'time' => $predictions[$distance]];
            } else {
                $result['predictions'] = [];
                foreach ($predictions as $dist => $time) $result['predictions'][] = ['distance' => $distMap[$dist] ?? $dist, 'time' => $time];
            }
            $result['pace_zones'] = $this->calculateVdotPaceZones($vdot);
            return json_encode($result);
        } catch (Exception $e) {
            return json_encode(['error' => 'prediction_failed', 'message' => 'Не удалось рассчитать прогноз: ' . $e->getMessage()]);
        }
    }

    private function executeGetProfile(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        try {
            require_once __DIR__ . '/UserProfileService.php';
            $profile = (new UserProfileService($this->db))->getProfile($userId);
            if (!$profile) return json_encode(['error' => 'not_found', 'message' => 'Профиль не найден']);

            $genderRu = ['male' => 'мужской', 'female' => 'женский'];
            $levelRu = ['beginner' => 'начинающий', 'novice' => 'продвинутый начинающий', 'intermediate' => 'средний', 'advanced' => 'продвинутый', 'expert' => 'эксперт'];
            $goalRu = ['race' => 'подготовка к забегу', 'time_improvement' => 'улучшение времени', 'weight_loss' => 'похудение', 'health' => 'здоровье и форма', 'distance' => 'увеличение дистанции'];

            $result = [];
            if (!empty($profile['username'])) $result['name'] = $profile['username'];
            if (!empty($profile['gender'])) $result['gender'] = $genderRu[$profile['gender']] ?? $profile['gender'];
            if (!empty($profile['birth_year'])) $result['birth_year'] = (int) $profile['birth_year'];
            if (!empty($profile['weight_kg'])) $result['weight_kg'] = (float) $profile['weight_kg'];
            if (!empty($profile['height_cm'])) $result['height_cm'] = (int) $profile['height_cm'];
            if (!empty($profile['experience_level'])) $result['running_level'] = $levelRu[$profile['experience_level']] ?? $profile['experience_level'];
            if (!empty($profile['goal_type'])) $result['goal'] = $goalRu[$profile['goal_type']] ?? $profile['goal_type'];
            if (!empty($profile['sessions_per_week'])) $result['days_per_week'] = (int) $profile['sessions_per_week'];
            if (!empty($profile['training_time_pref'])) $result['preferred_time'] = $profile['training_time_pref'];
            if (!empty($profile['weekly_base_km'])) $result['weekly_base_km'] = (float) $profile['weekly_base_km'];
            if (!empty($profile['easy_pace_sec'])) {
                $result['easy_pace'] = sprintf('%d:%02d', intdiv((int) $profile['easy_pace_sec'], 60), ((int) $profile['easy_pace_sec']) % 60);
            }
            if (!empty($profile['timezone'])) $result['timezone'] = $profile['timezone'];
            if (!empty($profile['race_distance'])) {
                $result['race'] = ['distance' => $profile['race_distance'], 'target_time' => $profile['race_target_time'] ?? null, 'date' => $profile['race_date'] ?? null];
            }

            $intStmt = $this->db->prepare("SELECT provider FROM integration_tokens WHERE user_id = ?");
            if ($intStmt) {
                $intStmt->bind_param('i', $userId);
                $intStmt->execute();
                $integrations = [];
                while ($row = $intStmt->get_result()->fetch_assoc()) $integrations[] = $row['provider'];
                $intStmt->close();
                if (!empty($integrations)) $result['connected_integrations'] = $integrations;
            }
            return json_encode($result);
        } catch (Exception $e) {
            return json_encode(['error' => 'profile_failed', 'message' => 'Не удалось получить профиль: ' . $e->getMessage()]);
        }
    }

    private function executeUpdateProfile(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $field = $args['field'] ?? '';
        $value = $args['value'] ?? '';
        if (!in_array($field, self::PROFILE_FIELDS, true)) return json_encode(['error' => 'invalid_field', 'message' => 'Недопустимое поле: ' . $field]);
        $labels = ['weight_kg' => 'Вес', 'height_cm' => 'Рост', 'goal_type' => 'Цель', 'experience_level' => 'Уровень', 'sessions_per_week' => 'Дней в неделю', 'easy_pace_sec' => 'Лёгкий темп', 'weekly_base_km' => 'Базовый недельный объём', 'race_distance' => 'Дистанция забега', 'race_target_time' => 'Целевое время', 'race_date' => 'Дата забега', 'training_time_pref' => 'Время для бега'];

        try {
            require_once __DIR__ . '/UserProfileService.php';
            (new UserProfileService($this->db))->updateProfile($userId, [$field => $value]);
            return json_encode(['success' => true, 'message' => ($labels[$field] ?? $field) . " обновлено: {$value}"]);
        } catch (Exception $e) {
            return json_encode(['error' => 'update_failed', 'message' => 'Не удалось обновить профиль: ' . $e->getMessage()]);
        }
    }

    private function executeGetTrainingLoad(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        try {
            require_once __DIR__ . '/TrainingLoadService.php';
            $load = (new TrainingLoadService($this->db))->getTrainingLoad($userId);
            if (empty($load) || !($load['available'] ?? false)) return json_encode(['error' => 'no_data', 'message' => 'Недостаточно данных для анализа нагрузки.']);

            $cur = $load['current'] ?? [];
            $tsb = $cur['tsb'] ?? null;
            $status = 'нет данных';
            if ($tsb !== null) {
                if ($tsb > 25) $status = 'высокая свежесть (возможна детренированность)';
                elseif ($tsb > 5) $status = 'свежий, хорошая форма';
                elseif ($tsb >= -10) $status = 'оптимальная нагрузка';
                elseif ($tsb >= -30) $status = 'накопленная усталость';
                else $status = 'перетренированность, нужен отдых';
            }

            $acwr = $this->contextBuilder->calculateACWR($userId);
            $result = ['atl' => isset($cur['atl']) ? round($cur['atl'], 1) : null, 'ctl' => isset($cur['ctl']) ? round($cur['ctl'], 1) : null, 'tsb' => $tsb !== null ? round($tsb, 1) : null, 'status' => $status, 'days_with_data' => $load['days_with_data'] ?? 0];
            if ($acwr && isset($acwr['acwr'])) {
                $zoneRu = ['low' => 'недогруз', 'optimal' => 'оптимально', 'caution' => 'повышенная', 'danger' => 'опасная', 'unknown' => 'нет данных'];
                $result['acwr'] = round($acwr['acwr'], 2);
                $result['acwr_status'] = $zoneRu[$acwr['zone'] ?? 'unknown'] ?? 'нет данных';
            }
            if (!empty($load['recent_workouts'])) {
                $result['recent_trimp'] = array_map(fn($rw) => ['date' => $rw['date'] ?? null, 'trimp' => isset($rw['trimp']) ? round($rw['trimp'], 0) : null], array_slice($load['recent_workouts'], 0, 7));
            }
            return json_encode($result);
        } catch (Exception $e) {
            return json_encode(['error' => 'load_failed', 'message' => 'Не удалось получить данные нагрузки: ' . $e->getMessage()]);
        }
    }

    private function executeAddTrainingDay(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        $type = $args['type'] ?? '';
        if (!in_array($type, self::ALLOWED_TYPES, true)) return json_encode(['error' => 'invalid_type', 'message' => 'Допустимые типы: ' . implode(', ', self::ALLOWED_TYPES)]);

        if ($this->findDayIdByDate($userId, $date)) return json_encode(['error' => 'day_exists', 'message' => "На {$date} уже есть тренировка. Используй update_training_day для изменения."]);

        try {
            require_once __DIR__ . '/WeekService.php';
            (new WeekService($this->db))->addTrainingDayByDate(['date' => $date, 'type' => $type, 'description' => $args['description'] ?? null], $userId);
            return json_encode(['success' => true, 'message' => "Тренировка «" . (self::TYPE_RU[$type] ?? $type) . "» добавлена на {$this->formatDateRu($date)}"]);
        } catch (Exception $e) {
            return json_encode(['error' => 'add_failed', 'message' => 'Не удалось добавить тренировку: ' . $e->getMessage()]);
        }
    }

    private function executeCopyDay(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $src = $args['source_date'] ?? '';
        $tgt = $args['target_date'] ?? '';
        if (!$this->validateDate($src) || !$this->validateDate($tgt)) return json_encode(['error' => 'invalid_dates', 'message' => 'Формат дат: Y-m-d']);
        if ($src === $tgt) return json_encode(['error' => 'same_dates', 'message' => 'Даты должны отличаться']);
        if (!$this->findDayIdByDate($userId, $src)) return json_encode(['error' => 'no_plan_for_date', 'message' => "На дату {$src} нет запланированной тренировки"]);

        try {
            require_once __DIR__ . '/WeekService.php';
            (new WeekService($this->db))->copyDay($src, $tgt, $userId);
            return json_encode(['success' => true, 'message' => "Тренировка скопирована с {$this->formatDateRu($src)} на {$this->formatDateRu($tgt)}"]);
        } catch (Exception $e) {
            return json_encode(['error' => 'copy_failed', 'message' => 'Не удалось скопировать: ' . $e->getMessage()]);
        }
    }

    // ── VDOT calculations ──

    public function calculateVdotPredictions(float $vdot): array {
        $distances = ['5k' => 5.0, '10k' => 10.0, 'half' => 21.0975, 'marathon' => 42.195];
        $pctVo2 = ['5k' => 0.97, '10k' => 0.93, 'half' => 0.85, 'marathon' => 0.79];
        $results = [];
        foreach ($distances as $key => $distKm) {
            $vo2 = $vdot * $pctVo2[$key];
            $a = 0.000104; $b = 0.182258; $c = -4.60 - $vo2;
            $disc = $b * $b - 4 * $a * $c;
            if ($disc < 0) { $results[$key] = 'N/A'; continue; }
            $speed = (-$b + sqrt($disc)) / (2 * $a);
            if ($speed <= 0) { $results[$key] = 'N/A'; continue; }
            $results[$key] = $this->formatSeconds((int) round(($distKm * 1000 / $speed) * 60));
        }
        return $results;
    }

    public function calculateVdotPaceZones(float $vdot): array {
        $zones = [
            'easy' => ['label' => 'Лёгкий', 'pct' => 0.65], 'tempo' => ['label' => 'Темповый', 'pct' => 0.85],
            'threshold' => ['label' => 'Пороговый', 'pct' => 0.88], 'interval' => ['label' => 'Интервальный', 'pct' => 0.97],
            'repetition' => ['label' => 'Повторный', 'pct' => 1.05],
        ];
        $result = [];
        foreach ($zones as $zone) {
            $vo2 = $vdot * $zone['pct'];
            $disc = 0.182258 * 0.182258 - 4 * 0.000104 * (-4.60 - $vo2);
            if ($disc < 0) continue;
            $speed = (-0.182258 + sqrt($disc)) / (2 * 0.000104);
            if ($speed <= 0) continue;
            $ps = 1000 / $speed * 60;
            $result[] = ['zone' => $zone['label'], 'pace' => sprintf('%d:%02d', intdiv((int) $ps, 60), ((int) $ps) % 60)];
        }
        return $result;
    }

    public function formatSeconds(int $totalSec): string {
        $h = intdiv($totalSec, 3600); $m = intdiv($totalSec % 3600, 60); $s = $totalSec % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
