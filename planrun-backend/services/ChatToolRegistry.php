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
                'activity_type' => ['type' => 'string', 'description' => 'Тип активности', 'enum' => ['running', 'walking', 'cycling', 'swimming', 'hiking', 'other', 'sbu']],
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
            // ── Phase 2: Deep analysis tools ──
            $this->toolDef('analyze_workout', 'Детальный разбор тренировки: сплиты по кругам, HR-зоны, сравнение с планом, анализ темпа.', [
                'date' => ['type' => 'string', 'description' => 'Дата тренировки Y-m-d'],
                'workout_index' => ['type' => 'integer', 'description' => 'Индекс тренировки если несколько за день (0 = первая)'],
            ], ['date']),
            $this->toolDef('get_training_trends', 'Тренды и прогресс: объёмы, темп, пульс по неделям, обнаружение паттернов (плато, перегруз).', [
                'weeks' => ['type' => 'integer', 'description' => 'Период анализа в неделях (по умолчанию 8, макс 52)'],
                'metric' => ['type' => 'string', 'description' => 'Метрика: volume/pace/heart_rate/all', 'enum' => ['volume', 'pace', 'heart_rate', 'all']],
            ]),
            $this->toolDef('compare_periods', 'Сравнение двух периодов тренировок side-by-side.', [
                'period1_from' => ['type' => 'string', 'description' => 'Начало первого периода Y-m-d'],
                'period1_to' => ['type' => 'string', 'description' => 'Конец первого периода Y-m-d'],
                'period2_from' => ['type' => 'string', 'description' => 'Начало второго периода Y-m-d'],
                'period2_to' => ['type' => 'string', 'description' => 'Конец второго периода Y-m-d'],
            ], ['period1_from', 'period1_to', 'period2_from', 'period2_to']),
            $this->toolDef('get_weekly_review', 'Еженедельный анализ: план vs факт по дням, ключевые тренировки, нагрузка, рекомендации.', [
                'week_offset' => ['type' => 'integer', 'description' => '0=текущая неделя, -1=прошлая и т.д.'],
            ]),
            $this->toolDef('get_goal_progress', 'Прогресс к цели: VDOT-история, trajectory к целевому времени, достигнутые вехи.', []),
            $this->toolDef('get_race_strategy', 'Стратегия на забег: пейсинг, зоны темпа, питание, разминка, статус тейпера.', [
                'distance' => ['type' => 'string', 'description' => 'Дистанция: 5k/10k/half/marathon (по умолчанию из профиля)', 'enum' => ['5k', '10k', 'half', 'marathon']],
            ]),
            $this->toolDef('explain_plan_logic', 'Объяснение логики плана: зачем именно этот тип тренировки, фаза, объём.', [
                'date' => ['type' => 'string', 'description' => 'Дата Y-m-d (по умолчанию сегодня)'],
                'scope' => ['type' => 'string', 'description' => 'Масштаб: day/week/phase', 'enum' => ['day', 'week', 'phase']],
            ]),
            $this->toolDef('report_health_issue', 'Зарегистрировать травму/болезнь и получить протокол возврата. ОБЯЗАТЕЛЬНО спроси подтверждение.', [
                'issue_type' => ['type' => 'string', 'description' => 'Тип: illness/injury/fatigue/other', 'enum' => ['illness', 'injury', 'fatigue', 'other']],
                'description' => ['type' => 'string', 'description' => 'Описание проблемы'],
                'severity' => ['type' => 'string', 'description' => 'Тяжесть: mild/moderate/severe', 'enum' => ['mild', 'moderate', 'severe']],
                'days_off' => ['type' => 'integer', 'description' => 'Ожидаемый перерыв в днях'],
                'affected_area' => ['type' => 'string', 'description' => 'Зона травмы (колено, ахилл и т.д.)'],
            ], ['issue_type', 'description', 'severity']),
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
            'analyze_workout' => fn() => $this->executeAnalyzeWorkout($args, $userId),
            'get_training_trends' => fn() => $this->executeGetTrainingTrends($args, $userId),
            'compare_periods' => fn() => $this->executeComparePeriods($args, $userId),
            'get_weekly_review' => fn() => $this->executeGetWeeklyReview($args, $userId),
            'get_goal_progress' => fn() => $this->executeGetGoalProgress($args, $userId),
            'get_race_strategy' => fn() => $this->executeGetRaceStrategy($args, $userId),
            'explain_plan_logic' => fn() => $this->executeExplainPlanLogic($args, $userId),
            'report_health_issue' => fn() => $this->executeReportHealthIssue($args, $userId),
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
        $parameters = ['type' => 'object', 'properties' => (object) $properties, 'required' => $required];
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
        $activityRu = ['running' => 'бег', 'walking' => 'ходьба', 'cycling' => 'велосипед', 'swimming' => 'плавание', 'hiking' => 'поход', 'other' => 'другое'];
        $formatted = [];
        $totalKm = 0;
        foreach ($workouts as $w) {
            $actType = mb_strtolower(trim($w['activity_type'] ?? 'running'));
            $entry = [
                'date' => $w['date'],
                'activity' => $activityRu[$actType] ?? $actType,
                'plan_type' => isset($w['plan_type']) ? (self::TYPE_RU[$w['plan_type']] ?? $w['plan_type']) : null,
                'is_key_workout' => !empty($w['is_key_workout']),
            ];
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
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private function executeGetDayDetails(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);

        $details = $this->contextBuilder->getDayDetails($userId, $date);
        $result = ['date' => $date];

        if ($details['plan']) {
            $result['ПЛАН_НА_ДЕНЬ'] = [
                '_hint' => 'Это ЗАПЛАНИРОВАННАЯ тренировка (что было задумано), НЕ факт выполнения.',
                'type' => $details['plan']['type_ru'] ?? $details['plan']['type'],
                'description' => $details['plan']['description'],
                'is_key_workout' => $details['plan']['is_key_workout'],
            ];
            if (!empty($details['exercises'])) {
                $exFormatted = [];
                foreach ($details['exercises'] as $ex) {
                    $e = ['name' => $ex['name']];
                    foreach (['sets', 'reps', 'distance_m', 'duration_sec'] as $k) if (!empty($ex[$k])) $e[$k] = (int) $ex[$k];
                    if (!empty($ex['pace'])) $e['pace'] = $ex['pace'];
                    $exFormatted[] = $e;
                }
                $result['ПЛАН_НА_ДЕНЬ']['planned_exercises'] = $exFormatted;
            }
        } else {
            $result['ПЛАН_НА_ДЕНЬ'] = null;
            $result['plan_message'] = 'На этот день нет запланированной тренировки';
        }

        $formatWorkout = function (array $w): array {
            $out = [];
            if (!empty($w['distance_km'])) $out['distance_km'] = (float) $w['distance_km'];
            if (!empty($w['result_time']) && $w['result_time'] !== '0:00:00') $out['time'] = $w['result_time'];
            if (!empty($w['pace']) && $w['pace'] !== '0:00') $out['pace'] = $w['pace'];
            if (!empty($w['duration_minutes'])) $out['duration_min'] = (int) $w['duration_minutes'];
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

        $allWorkouts = $details['workouts'] ?? ($details['workout'] ? [$details['workout']] : []);
        if (!empty($allWorkouts)) {
            $cnt = count($allWorkouts);
            $result['ВЫПОЛНЕННЫЕ_ТРЕНИРОВКИ'] = [
                '_hint' => "Это ФАКТИЧЕСКИ выполненные тренировки ({$cnt} шт). Отвечай на основе ЭТИХ данных.",
                'count' => $cnt,
                'items' => array_map($formatWorkout, $allWorkouts),
            ];
        } else {
            $result['ВЫПОЛНЕННЫЕ_ТРЕНИРОВКИ'] = null;
            $result['workout_message'] = 'Тренировка не выполнена (нет записей)';
        }

        $result['_instructions'] = 'При ответе пользователю: данные из ВЫПОЛНЕННЫЕ_ТРЕНИРОВКИ — это факт. Данные из ПЛАН_НА_ДЕНЬ — это план (что было запланировано). Не путай их. Не выдавай planned_exercises за выполненные.';
        return json_encode($result, JSON_UNESCAPED_UNICODE);
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

    private const ACTIVITY_TYPE_MAP = [
        'running' => 1, 'walking' => 2, 'cycling' => 3, 'swimming' => 4,
        'hiking' => 5, 'other' => 6, 'sbu' => 6,
    ];

    private function executeLogWorkout(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        $distanceKm = (float) ($args['distance_km'] ?? 0);
        if ($distanceKm <= 0 || $distanceKm > 300) return json_encode(['error' => 'invalid_distance', 'message' => 'Дистанция должна быть от 0.1 до 300 км']);

        $activityType = $args['activity_type'] ?? 'running';
        $activityTypeId = self::ACTIVITY_TYPE_MAP[$activityType] ?? 1;

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
                'activity_type_id' => $activityTypeId, 'is_successful' => true,
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

        $periodLabels = [
            'week' => 'эта неделя', 'month' => 'последние 30 дней',
            'plan' => 'весь план', 'all' => 'все данные',
        ];

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
                if ($d) {
                    $mondayTs = strtotime('monday this week', strtotime($d));
                    $sundayTs = $mondayTs + 6 * 86400;
                    $weekKey = date('d.m', $mondayTs) . '-' . date('d.m', $sundayTs);
                    $weekly[$weekKey] = ($weekly[$weekKey] ?? 0) + $km;
                }
            }

            $avgPace = ($totalKm > 0 && $totalSec > 0) ? sprintf('%d:%02d', intdiv((int)($totalSec / $totalKm), 60), ((int)($totalSec / $totalKm)) % 60) : null;
            $result = [
                'period' => $periodLabels[$period] ?? $period,
                'plan_completion_overall' => [
                    '_hint' => 'Это статистика за ВЕСЬ план (не за выбранный период).',
                    'total_planned_days' => $stats['total'] ?? 0,
                    'completed_days' => $stats['completed'] ?? 0,
                    'completion_percent' => $stats['percentage'] ?? 0,
                ],
                'volume' => ['total_km' => round($totalKm, 1), 'total_workouts' => count($filtered), 'total_hours' => round($totalSec / 3600, 1), 'avg_pace' => $avgPace],
            ];
            if (count($weekly) > 1) $result['weekly_trend_km'] = array_map(fn($v) => round($v, 1), array_slice($weekly, -4, null, true));
            return json_encode($result, JSON_UNESCAPED_UNICODE);
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
                $race = ['distance' => $profile['race_distance'], 'target_time' => $profile['race_target_time'] ?? null, 'date' => $profile['race_date'] ?? null];
                if (!empty($profile['race_date'])) {
                    $tz = $this->getUserTz($userId);
                    $today = new DateTime('now', $tz);
                    $today->setTime(0, 0, 0);
                    $raceDate = DateTime::createFromFormat('Y-m-d', $profile['race_date'], $tz);
                    if ($raceDate) {
                        $raceDate->setTime(0, 0, 0);
                        $race['days_until_race'] = max(0, (int) $today->diff($raceDate)->days);
                    }
                }
                $result['race'] = $race;
            }

            $intStmt = $this->db->prepare("SELECT provider FROM integration_tokens WHERE user_id = ?");
            if ($intStmt) {
                $intStmt->bind_param('i', $userId);
                $intStmt->execute();
                $intResult = $intStmt->get_result();
                $integrations = [];
                while ($row = $intResult->fetch_assoc()) $integrations[] = $row['provider'];
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

    // ── Phase 2: Deep analysis tool implementations ──

    private function executeAnalyzeWorkout(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? '';
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        $workoutIndex = max(0, (int) ($args['workout_index'] ?? 0));
        $directWorkoutId = isset($args['workout_id']) ? (int) $args['workout_id'] : null;

        try {
            $details = $this->contextBuilder->getDayDetails($userId, $date);
            $allWorkouts = $details['workouts'] ?? ($details['workout'] ? [$details['workout']] : []);
            if (empty($allWorkouts)) return json_encode(['error' => 'no_workout', 'message' => "Нет выполненных тренировок за {$date}"]);

            // Если передан конкретный workout_id — ищем его в списке тренировок дня
            if ($directWorkoutId !== null) {
                $found = false;
                foreach ($allWorkouts as $idx => $aw) {
                    if (((int) ($aw['id'] ?? 0)) === $directWorkoutId) {
                        $workoutIndex = $idx;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    // workout_id не найден в этом дне — fallback на индекс
                    $directWorkoutId = null;
                }
            }
            if ($workoutIndex >= count($allWorkouts)) $workoutIndex = 0;

            $w = $allWorkouts[$workoutIndex];
            $result = ['date' => $date, 'workout_index' => $workoutIndex, 'total_workouts_on_day' => count($allWorkouts)];

            $result['summary'] = [];
            if (!empty($w['distance_km'])) $result['summary']['distance_km'] = round((float) $w['distance_km'], 2);
            if (!empty($w['duration_minutes'])) $result['summary']['duration_min'] = (int) $w['duration_minutes'];
            if (!empty($w['pace']) && $w['pace'] !== '0:00') $result['summary']['avg_pace'] = $w['pace'];
            if (!empty($w['avg_heart_rate'])) $result['summary']['avg_hr'] = (int) $w['avg_heart_rate'];
            if (!empty($w['max_heart_rate'])) $result['summary']['max_hr'] = (int) $w['max_heart_rate'];
            if (!empty($w['elevation_gain'])) $result['summary']['elevation_m'] = (int) $w['elevation_gain'];
            if (!empty($w['avg_cadence'])) $result['summary']['cadence'] = (int) $w['avg_cadence'];
            if (!empty($w['calories'])) $result['summary']['calories'] = (int) $w['calories'];
            if (!empty($w['activity_type'])) $result['summary']['activity_type'] = $w['activity_type'];
            if (!empty($w['source'])) $result['summary']['source'] = $w['source'];

            // Laps and timeline from workouts table
            $workoutId = $directWorkoutId ?? $this->findWorkoutIdByDate($userId, $date, $workoutIndex);
            if ($workoutId) {
                require_once __DIR__ . '/WorkoutService.php';
                $timelineData = (new WorkoutService($this->db))->getWorkoutTimeline($workoutId, $userId);
                $laps = $timelineData['laps'] ?? [];
                if (!empty($laps)) {
                    $result['laps'] = array_map(function ($lap) {
                        $l = [];
                        if (!empty($lap['name'])) $l['name'] = $lap['name'];
                        if (!empty($lap['distance_km'])) $l['distance_km'] = round((float) $lap['distance_km'], 2);
                        if (!empty($lap['elapsed_seconds'])) $l['time_sec'] = (int) $lap['elapsed_seconds'];
                        if (!empty($lap['avg_pace'])) $l['avg_pace'] = $lap['avg_pace'];
                        if (!empty($lap['avg_heart_rate'])) $l['avg_hr'] = (int) $lap['avg_heart_rate'];
                        if (!empty($lap['max_heart_rate'])) $l['max_hr'] = (int) $lap['max_heart_rate'];
                        if (!empty($lap['elevation_gain'])) $l['elevation_m'] = round((float) $lap['elevation_gain'], 1);
                        return $l;
                    }, $laps);
                }

                // HR zones from timeline — используем физиологический max_hr, не пик тренировки
                $timeline = $timelineData['timeline'] ?? [];
                if (!empty($timeline)) {
                    $maxHr = $this->getUserMaxHr($userId);
                    if ($maxHr > 100) {
                        $result['hr_zones'] = $this->calculateHrZones($timeline, $maxHr);
                    }
                }

                // Pace analysis from timeline
                if (!empty($timeline)) {
                    $result['pace_analysis'] = $this->analyzePaceSplits($timeline);
                }
            }

            // Plan comparison
            if ($details['plan']) {
                $result['plan_comparison'] = [
                    'planned_type' => self::TYPE_RU[$details['plan']['type']] ?? $details['plan']['type'],
                    'planned_description' => $details['plan']['description'] ?? '',
                    'is_key_workout' => (bool) $details['plan']['is_key_workout'],
                ];
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'analyze_failed', 'message' => 'Не удалось проанализировать тренировку: ' . $e->getMessage()]);
        }
    }

    private function findWorkoutIdByDate(int $userId, string $date, int $index = 0, ?string $activityType = null): ?int {
        if ($activityType) {
            $stmt = $this->db->prepare(
                "SELECT id FROM workouts WHERE user_id = ? AND DATE(start_time) = ? AND activity_type = ? ORDER BY start_time ASC LIMIT 1 OFFSET ?"
            );
            if (!$stmt) return null;
            $stmt->bind_param('issi', $userId, $date, $activityType, $index);
        } else {
            // Приоритет: running первым, затем остальные по времени
            $stmt = $this->db->prepare(
                "SELECT id FROM workouts WHERE user_id = ? AND DATE(start_time) = ? ORDER BY (activity_type = 'running') DESC, start_time ASC LIMIT 1 OFFSET ?"
            );
            if (!$stmt) return null;
            $stmt->bind_param('isi', $userId, $date, $index);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Получить физиологический max_hr пользователя.
     * Приоритет: 1) из профиля, 2) реальный максимум из тренировок (бег), 3) формула 220 - возраст.
     */
    private function getUserMaxHr(int $userId): int {
        $stmt = $this->db->prepare("SELECT max_hr, birth_year FROM users WHERE id = ?");
        if (!$stmt) return 0;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return 0;

        $profileMaxHr = (!empty($row['max_hr']) && (int) $row['max_hr'] > 100) ? (int) $row['max_hr'] : null;

        // 1. Реальный максимум из беговых тренировок (P85, отсечка артефактов)
        $stmt2 = $this->db->prepare(
            "SELECT max_heart_rate FROM workouts
             WHERE user_id = ? AND max_heart_rate > 100 AND max_heart_rate < 230
               AND LOWER(COALESCE(activity_type, '')) IN ('running', 'trail running', 'treadmill')
             ORDER BY max_heart_rate DESC
             LIMIT 20"
        );
        if ($stmt2) {
            $stmt2->bind_param('i', $userId);
            $stmt2->execute();
            $res = $stmt2->get_result();
            $values = [];
            while ($r = $res->fetch_assoc()) {
                $values[] = (int) $r['max_heart_rate'];
            }
            $stmt2->close();

            if (count($values) >= 5) {
                $cutoff = max(1, (int) round(count($values) * 0.15));
                $realMax = $values[$cutoff];
                if ($realMax > 150) {
                    return $realMax;
                }
            }
        }

        // 2. Из профиля (ручной ввод — fallback если мало тренировок)
        if ($profileMaxHr) {
            return $profileMaxHr;
        }

        // 3. Формула: 220 - возраст
        if (!empty($row['birth_year'])) {
            $age = (int) date('Y') - (int) $row['birth_year'];
            return max(150, 220 - $age);
        }
        return 0;
    }

    private function calculateHrZones(array $timeline, int $maxHr): array {
        $zones = [0, 0, 0, 0, 0];
        $zoneNames = ['1 — восстановительная', '2 — аэробная', '3 — темповая', '4 — пороговая', '5 — максимальная'];
        $boundaries = [0.6, 0.7, 0.8, 0.9, 1.0];
        $totalPoints = 0;
        foreach ($timeline as $pt) {
            $hr = (int) ($pt['heart_rate'] ?? 0);
            if ($hr <= 0) continue;
            $pct = $hr / $maxHr;
            $totalPoints++;
            if ($pct < $boundaries[0]) $zones[0]++;
            elseif ($pct < $boundaries[1]) $zones[1]++;
            elseif ($pct < $boundaries[2]) $zones[2]++;
            elseif ($pct < $boundaries[3]) $zones[3]++;
            else $zones[4]++;
        }
        if ($totalPoints === 0) return [];
        $result = [];
        for ($i = 0; $i < 5; $i++) {
            $result[] = ['zone' => $zoneNames[$i], 'percent' => round(($zones[$i] / $totalPoints) * 100, 1)];
        }
        return $result;
    }

    private function analyzePaceSplits(array $timeline): array {
        if (count($timeline) < 2) return [];

        $points = [];
        foreach ($timeline as $pt) {
            $dist = (float) ($pt['distance'] ?? 0);
            $ts = $pt['timestamp'] ?? null;
            if ($dist <= 0 || $ts === null) continue;
            $points[] = [
                'dist_m' => $dist,
                'ts' => is_numeric($ts) ? (float) $ts : (float) strtotime($ts),
                'hr' => isset($pt['heart_rate']) && $pt['heart_rate'] > 0 ? (int) $pt['heart_rate'] : null,
            ];
        }
        if (count($points) < 2) return [];

        $splits = [];
        $nextKmBoundary = 1000;
        $segStart = $points[0];
        $segHrSum = 0;
        $segHrCount = 0;

        for ($i = 1; $i < count($points); $i++) {
            $p = $points[$i];
            if ($p['hr'] !== null) {
                $segHrSum += $p['hr'];
                $segHrCount++;
            }

            if ($p['dist_m'] >= $nextKmBoundary) {
                $distDelta = $p['dist_m'] - $segStart['dist_m'];
                $timeDelta = $p['ts'] - $segStart['ts'];
                if ($distDelta > 0 && $timeDelta > 0) {
                    $paceSecPerKm = ($timeDelta / $distDelta) * 1000;
                    $paceMin = (int) floor($paceSecPerKm / 60);
                    $paceSec = (int) round($paceSecPerKm % 60);
                    $split = [
                        'km' => (int) ($nextKmBoundary / 1000),
                        'pace' => sprintf('%d:%02d', $paceMin, $paceSec),
                        'pace_sec' => (int) round($paceSecPerKm),
                    ];
                    if ($segHrCount > 0) {
                        $split['avg_hr'] = (int) round($segHrSum / $segHrCount);
                    }
                    $splits[] = $split;
                }
                $segStart = $p;
                $segHrSum = 0;
                $segHrCount = 0;
                $nextKmBoundary += 1000;
            }
        }

        if (count($splits) < 2) return ['total_splits' => count($splits), 'splits' => $splits];

        $firstHalfCount = (int) floor(count($splits) / 2);
        $firstHalfAvg = array_sum(array_column(array_slice($splits, 0, $firstHalfCount), 'pace_sec')) / $firstHalfCount;
        $secondHalfAvg = array_sum(array_column(array_slice($splits, $firstHalfCount), 'pace_sec')) / (count($splits) - $firstHalfCount);
        $splitDiff = (int) round($secondHalfAvg - $firstHalfAvg);

        if ($splitDiff < -3) {
            $splitType = 'negative_split';
        } elseif ($splitDiff > 3) {
            $splitType = 'positive_split';
        } else {
            $splitType = 'even_split';
        }

        $fastestSplit = null;
        $slowestSplit = null;
        foreach ($splits as $s) {
            if ($fastestSplit === null || $s['pace_sec'] < $fastestSplit['pace_sec']) $fastestSplit = $s;
            if ($slowestSplit === null || $s['pace_sec'] > $slowestSplit['pace_sec']) $slowestSplit = $s;
        }

        return [
            'total_splits' => count($splits),
            'splits' => $splits,
            'split_type' => $splitType,
            'split_diff_sec' => $splitDiff,
            'fastest' => $fastestSplit ? ['km' => $fastestSplit['km'], 'pace' => $fastestSplit['pace']] : null,
            'slowest' => $slowestSplit ? ['km' => $slowestSplit['km'], 'pace' => $slowestSplit['pace']] : null,
        ];
    }

    private function executeGetTrainingTrends(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $weeks = min(52, max(2, (int) ($args['weeks'] ?? 8)));
        $metric = $args['metric'] ?? 'all';

        try {
            $tz = $this->getUserTz($userId);
            $now = new DateTime('now', $tz);
            $endDate = $now->format('Y-m-d');
            $startDate = (clone $now)->modify("-{$weeks} weeks")->format('Y-m-d');

            $workouts = $this->contextBuilder->getWorkoutsHistory($userId, $startDate, $endDate, 1000);
            $weeklyData = [];
            foreach ($workouts as $w) {
                $d = $w['date'] ?? '';
                if (!$d) continue;
                $mondayTs = strtotime('monday this week', strtotime($d));
                $weekKey = date('Y-m-d', $mondayTs);
                if (!isset($weeklyData[$weekKey])) {
                    $weeklyData[$weekKey] = ['week_start' => $weekKey, 'total_km' => 0, 'count' => 0, 'total_sec' => 0, 'hr_sum' => 0, 'hr_count' => 0, 'longest_km' => 0];
                }
                $km = (float) ($w['distance_km'] ?? 0);
                $weeklyData[$weekKey]['total_km'] += $km;
                $weeklyData[$weekKey]['count']++;
                if ($km > $weeklyData[$weekKey]['longest_km']) $weeklyData[$weekKey]['longest_km'] = $km;

                $dm = (int) ($w['duration_minutes'] ?? 0);
                if ($dm > 0) $weeklyData[$weekKey]['total_sec'] += $dm * 60;

                $hr = (int) ($w['avg_heart_rate'] ?? 0);
                if ($hr > 0) { $weeklyData[$weekKey]['hr_sum'] += $hr; $weeklyData[$weekKey]['hr_count']++; }
            }

            ksort($weeklyData);
            $formatted = [];
            foreach ($weeklyData as $wd) {
                $entry = ['week' => $wd['week_start'], 'total_km' => round($wd['total_km'], 1), 'workouts' => $wd['count'], 'longest_km' => round($wd['longest_km'], 1)];
                if ($wd['total_km'] > 0 && $wd['total_sec'] > 0) {
                    $ps = $wd['total_sec'] / $wd['total_km'];
                    $entry['avg_pace'] = sprintf('%d:%02d', intdiv((int) $ps, 60), ((int) $ps) % 60);
                }
                if ($wd['hr_count'] > 0) $entry['avg_hr'] = (int) round($wd['hr_sum'] / $wd['hr_count']);
                $formatted[] = $entry;
            }

            // Trend detection
            $trends = [];
            if (count($formatted) >= 3) {
                $vols = array_column($formatted, 'total_km');
                $trends['volume'] = $this->detectTrend($vols);
            }

            // Patterns
            $patterns = [];
            if (count($formatted) >= 3) {
                $vols = array_column($formatted, 'total_km');
                for ($i = 1; $i < count($vols); $i++) {
                    if ($vols[$i - 1] > 0 && (($vols[$i] - $vols[$i - 1]) / $vols[$i - 1]) > 0.15) {
                        $patterns[] = ['type' => 'volume_spike', 'week' => $formatted[$i]['week'], 'change_pct' => round((($vols[$i] - $vols[$i - 1]) / $vols[$i - 1]) * 100)];
                    }
                }
            }

            // VDOT history from snapshots
            $vdotHistory = [];
            try {
                require_once __DIR__ . '/GoalProgressService.php';
                $snapshots = (new GoalProgressService($this->db))->getRecentSnapshots($userId, $weeks);
                foreach ($snapshots as $s) {
                    if (!empty($s['vdot'])) {
                        $vdotHistory[] = ['date' => $s['snapshot_date'] ?? null, 'vdot' => round((float) $s['vdot'], 1)];
                    }
                }
            } catch (Throwable $e) {}

            $result = ['period_weeks' => $weeks, 'weekly_data' => $formatted, 'trends' => $trends, 'patterns' => $patterns];
            if (!empty($vdotHistory)) $result['vdot_history'] = $vdotHistory;
            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'trends_failed', 'message' => 'Не удалось получить тренды: ' . $e->getMessage()]);
        }
    }

    private function detectTrend(array $values): string {
        $n = count($values);
        if ($n < 3) return 'insufficient_data';
        $firstHalf = array_sum(array_slice($values, 0, (int) floor($n / 2))) / max(1, (int) floor($n / 2));
        $secondHalf = array_sum(array_slice($values, (int) floor($n / 2))) / max(1, $n - (int) floor($n / 2));
        if ($firstHalf == 0) return 'stable';
        $change = ($secondHalf - $firstHalf) / $firstHalf;
        if ($change > 0.1) return 'improving';
        if ($change < -0.1) return 'declining';
        return 'stable';
    }

    private function executeComparePeriods(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $p1From = $args['period1_from'] ?? '';
        $p1To = $args['period1_to'] ?? '';
        $p2From = $args['period2_from'] ?? '';
        $p2To = $args['period2_to'] ?? '';
        foreach ([$p1From, $p1To, $p2From, $p2To] as $d) {
            if (!$this->validateDate($d)) return json_encode(['error' => 'invalid_dates', 'message' => 'Все даты должны быть в формате Y-m-d']);
        }

        try {
            $getPeriodStats = function (string $from, string $to) use ($userId): array {
                $workouts = $this->contextBuilder->getWorkoutsHistory($userId, $from, $to, 500);
                $totalKm = 0; $totalSec = 0; $hrSum = 0; $hrCnt = 0; $bestPaceSec = PHP_INT_MAX; $longestKm = 0; $count = 0;
                foreach ($workouts as $w) {
                    $km = (float) ($w['distance_km'] ?? 0); $totalKm += $km; $count++;
                    $dm = (int) ($w['duration_minutes'] ?? 0); if ($dm > 0) $totalSec += $dm * 60;
                    $hr = (int) ($w['avg_heart_rate'] ?? 0); if ($hr > 0) { $hrSum += $hr; $hrCnt++; }
                    if ($km > $longestKm) $longestKm = $km;
                    if ($km > 0 && $dm > 0) {
                        $ps = ($dm * 60) / $km;
                        if ($ps < $bestPaceSec) $bestPaceSec = $ps;
                    }
                }
                $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
                $weeksNum = max(1, $days / 7);
                return [
                    'total_km' => round($totalKm, 1), 'workouts' => $count,
                    'avg_weekly_km' => round($totalKm / $weeksNum, 1),
                    'avg_pace' => ($totalKm > 0 && $totalSec > 0) ? sprintf('%d:%02d', intdiv((int)($totalSec/$totalKm), 60), ((int)($totalSec/$totalKm)) % 60) : null,
                    'avg_hr' => $hrCnt > 0 ? (int) round($hrSum / $hrCnt) : null,
                    'best_pace' => $bestPaceSec < PHP_INT_MAX ? sprintf('%d:%02d', intdiv((int) $bestPaceSec, 60), ((int) $bestPaceSec) % 60) : null,
                    'longest_km' => round($longestKm, 1),
                ];
            };

            $s1 = $getPeriodStats($p1From, $p1To);
            $s2 = $getPeriodStats($p2From, $p2To);

            $comparison = [];
            foreach (['total_km', 'workouts', 'avg_weekly_km', 'longest_km'] as $key) {
                $v1 = $s1[$key]; $v2 = $s2[$key];
                $delta = $v2 - $v1;
                $pctChange = $v1 > 0 ? round(($delta / $v1) * 100, 1) : null;
                $comparison[$key] = ['period1' => $v1, 'period2' => $v2, 'delta' => round($delta, 1), 'change_pct' => $pctChange];
            }

            return json_encode([
                'period1' => ['from' => $p1From, 'to' => $p1To, 'stats' => $s1],
                'period2' => ['from' => $p2From, 'to' => $p2To, 'stats' => $s2],
                'comparison' => $comparison,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'compare_failed', 'message' => 'Не удалось сравнить периоды: ' . $e->getMessage()]);
        }
    }

    private function executeGetWeeklyReview(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $offset = (int) ($args['week_offset'] ?? 0);

        try {
            $tz = $this->getUserTz($userId);
            $now = new DateTime('now', $tz);
            $monday = (clone $now)->modify('monday this week');
            if ($offset < 0) $monday->modify("{$offset} weeks");
            $sunday = (clone $monday)->modify('+6 days');
            $mondayStr = $monday->format('Y-m-d');
            $sundayStr = $sunday->format('Y-m-d');

            // Plan days
            $planned = [];
            $stmt = $this->db->prepare(
                "SELECT d.date, d.type, d.description, d.is_key_workout
                 FROM training_plan_days d JOIN training_plan_weeks w ON d.week_id = w.id
                 WHERE w.user_id = ? AND d.date >= ? AND d.date <= ? ORDER BY d.date"
            );
            if ($stmt) {
                $stmt->bind_param('iss', $userId, $mondayStr, $sundayStr);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $planned[$row['date']] = $row;
                $stmt->close();
            }

            // Actual workouts
            $workouts = $this->contextBuilder->getWorkoutsHistory($userId, $mondayStr, $sundayStr, 50);
            $actualByDate = [];
            foreach ($workouts as $w) {
                $d = $w['date'] ?? '';
                if (!isset($actualByDate[$d])) $actualByDate[$d] = [];
                $actualByDate[$d][] = $w;
            }

            $dayLabels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
            $planVsActual = [];
            $plannedKm = 0; $actualKm = 0; $plannedSessions = 0; $actualSessions = 0;
            $keyWorkoutsPlanned = 0; $keyWorkoutsDone = 0;
            $weekHrSum = 0; $weekHrCount = 0; $bestPaceSec = PHP_INT_MAX;
            $weekTrimp = 0;

            $cursor = clone $monday;
            for ($i = 0; $i < 7; $i++) {
                $dateStr = $cursor->format('Y-m-d');
                $dow = $dayLabels[(int) $cursor->format('N')] ?? '';
                $plan = $planned[$dateStr] ?? null;
                $actual = $actualByDate[$dateStr] ?? [];

                $entry = ['day' => $dow, 'date' => $dateStr];
                if ($plan) {
                    $entry['planned_type'] = self::TYPE_RU[$plan['type']] ?? $plan['type'];
                    if ($plan['type'] !== 'rest') $plannedSessions++;
                    if (!empty($plan['is_key_workout'])) $keyWorkoutsPlanned++;
                }
                if (!empty($actual)) {
                    // Отделяем беговые тренировки от прогулок
                    $runActivities = array_filter($actual, fn($a) => !in_array(strtolower($a['activity_type'] ?? 'running'), ['walking', 'hiking'], true));
                    $dayKm = 0;
                    $dayPaces = [];
                    // km считаем по всем активностям, но pace/HR — только по бегу
                    foreach ($actual as $a) {
                        $dayKm += (float) ($a['distance_km'] ?? 0);
                    }
                    foreach ($runActivities as $a) {
                        $hr = (int) ($a['avg_heart_rate'] ?? 0);
                        if ($hr > 0) { $weekHrSum += $hr; $weekHrCount++; }
                        $km = (float) ($a['distance_km'] ?? 0);
                        $dm = (int) ($a['duration_minutes'] ?? 0);
                        if ($km > 0 && $dm > 0) {
                            $ps = ($dm * 60) / $km;
                            if ($ps < $bestPaceSec) $bestPaceSec = $ps;
                            $dayPaces[] = $ps;
                        }
                    }
                    $entry['actual_km'] = round($dayKm, 1);
                    if (!empty($dayPaces)) {
                        $avgPs = array_sum($dayPaces) / count($dayPaces);
                        $entry['avg_pace'] = sprintf('%d:%02d', intdiv((int) $avgPs, 60), ((int) $avgPs) % 60);
                    }
                    $actualKm += $dayKm;
                    // Считаем сессию выполненной только если был бег (не только прогулка)
                    if (!empty($runActivities)) {
                        $actualSessions++;
                        $entry['status'] = 'done';
                    } else {
                        $entry['status'] = ($plan && $plan['type'] !== 'rest' && $dateStr <= $now->format('Y-m-d')) ? 'missed' : 'upcoming';
                        $entry['walking_only'] = true;
                    }
                    if ($plan && !empty($plan['is_key_workout']) && !empty($runActivities)) $keyWorkoutsDone++;
                } else {
                    $entry['status'] = ($plan && $plan['type'] !== 'rest' && $dateStr <= $now->format('Y-m-d')) ? 'missed' : 'upcoming';
                }
                $planVsActual[] = $entry;
                $cursor->modify('+1 day');
            }

            $completionPct = $plannedSessions > 0 ? round(($actualSessions / $plannedSessions) * 100) : 0;

            // TRIMP for week from workouts table
            $trimpStmt = $this->db->prepare(
                "SELECT COALESCE(SUM(trimp), 0) AS week_trimp FROM workouts
                 WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ? AND trimp IS NOT NULL"
            );
            if ($trimpStmt) {
                $trimpStmt->bind_param('iss', $userId, $mondayStr, $sundayStr);
                $trimpStmt->execute();
                $trRow = $trimpStmt->get_result()->fetch_assoc();
                $weekTrimp = round((float) ($trRow['week_trimp'] ?? 0), 1);
                $trimpStmt->close();
            }

            // HR drift for long runs (>10km) — difference in avg HR between first and second half
            $hrDrift = null;
            $longRunIds = [];
            $lrStmt = $this->db->prepare(
                "SELECT id FROM workouts WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
                 AND distance_km >= 10 AND avg_heart_rate > 0
                 ORDER BY distance_km DESC LIMIT 2"
            );
            if ($lrStmt) {
                $lrStmt->bind_param('iss', $userId, $mondayStr, $sundayStr);
                $lrStmt->execute();
                $lrRes = $lrStmt->get_result();
                while ($lrRow = $lrRes->fetch_assoc()) $longRunIds[] = (int) $lrRow['id'];
                $lrStmt->close();
            }
            if (!empty($longRunIds)) {
                require_once __DIR__ . '/WorkoutService.php';
                $ws = new WorkoutService($this->db);
                foreach ($longRunIds as $lrId) {
                    try {
                        $tlData = $ws->getWorkoutTimeline($lrId, $userId);
                        $tl = $tlData['timeline'] ?? [];
                        if (count($tl) >= 20) {
                            $half = (int) (count($tl) / 2);
                            $firstHalf = array_slice($tl, 0, $half);
                            $secondHalf = array_slice($tl, $half);
                            $avgFirst = $this->avgHrFromTimeline($firstHalf);
                            $avgSecond = $this->avgHrFromTimeline($secondHalf);
                            if ($avgFirst > 0 && $avgSecond > 0) {
                                $hrDrift = (int) round($avgSecond - $avgFirst);
                                break;
                            }
                        }
                    } catch (Throwable $e) {}
                }
            }

            // Intensity zone distribution across all workouts this week
            $zoneDistribution = null;
            $allWeekWorkoutIds = [];
            $awStmt = $this->db->prepare(
                "SELECT id, max_heart_rate FROM workouts WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
                 AND avg_heart_rate > 0 ORDER BY start_time ASC LIMIT 20"
            );
            if ($awStmt) {
                $awStmt->bind_param('iss', $userId, $mondayStr, $sundayStr);
                $awStmt->execute();
                $awRes = $awStmt->get_result();
                while ($awRow = $awRes->fetch_assoc()) {
                    if ((int) ($awRow['max_heart_rate'] ?? 0) > 100) {
                        $allWeekWorkoutIds[] = ['id' => (int) $awRow['id'], 'max_hr' => (int) $awRow['max_heart_rate']];
                    }
                }
                $awStmt->close();
            }
            if (!empty($allWeekWorkoutIds)) {
                $totalZones = [0, 0, 0, 0, 0];
                $totalPts = 0;
                if (!isset($ws)) {
                    require_once __DIR__ . '/WorkoutService.php';
                    $ws = new WorkoutService($this->db);
                }
                foreach ($allWeekWorkoutIds as $wInfo) {
                    try {
                        $tlData = $ws->getWorkoutTimeline($wInfo['id'], $userId);
                        $tl = $tlData['timeline'] ?? [];
                        $hrZones = $this->calculateHrZones($tl, $wInfo['max_hr']);
                        if (!empty($hrZones)) {
                            foreach ($hrZones as $idx => $z) {
                                $totalZones[$idx] += ($z['percent'] ?? 0);
                            }
                            $totalPts++;
                        }
                    } catch (Throwable $e) {}
                }
                if ($totalPts > 0) {
                    $zoneNames = ['1 — восстановительная', '2 — аэробная', '3 — темповая', '4 — пороговая', '5 — максимальная'];
                    $zoneDistribution = [];
                    for ($z = 0; $z < 5; $z++) {
                        $zoneDistribution[] = ['zone' => $zoneNames[$z], 'percent' => round($totalZones[$z] / $totalPts, 1)];
                    }
                }
            }

            // Load trend
            $loadData = null;
            try {
                require_once __DIR__ . '/TrainingLoadService.php';
                $tls = new TrainingLoadService($this->db);
                $load = $tls->getTrainingLoad($userId, 28);
                if (!empty($load['current'])) {
                    $loadData = ['atl' => round($load['current']['atl'] ?? 0, 1), 'ctl' => round($load['current']['ctl'] ?? 0, 1), 'tsb' => round($load['current']['tsb'] ?? 0, 1)];
                }
            } catch (Throwable $e) {}

            $qualityMetrics = [
                'week_trimp' => $weekTrimp,
                'avg_hr' => $weekHrCount > 0 ? (int) round($weekHrSum / $weekHrCount) : null,
                'best_pace' => $bestPaceSec < PHP_INT_MAX ? sprintf('%d:%02d', intdiv((int) $bestPaceSec, 60), ((int) $bestPaceSec) % 60) : null,
            ];
            if ($hrDrift !== null) $qualityMetrics['hr_drift_bpm'] = $hrDrift;
            if ($zoneDistribution !== null) $qualityMetrics['zone_distribution'] = $zoneDistribution;

            return json_encode([
                'week' => $mondayStr . ' — ' . $sundayStr,
                'plan_vs_actual' => $planVsActual,
                'summary' => [
                    'planned_sessions' => $plannedSessions, 'actual_sessions' => $actualSessions,
                    'actual_km' => round($actualKm, 1), 'completion_pct' => $completionPct,
                    'key_workouts' => "{$keyWorkoutsDone}/{$keyWorkoutsPlanned}",
                ],
                'quality' => $qualityMetrics,
                'load' => $loadData,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'review_failed', 'message' => 'Не удалось получить обзор недели: ' . $e->getMessage()]);
        }
    }

    private function avgHrFromTimeline(array $points): float {
        $sum = 0; $cnt = 0;
        foreach ($points as $pt) {
            $hr = (int) ($pt['heart_rate'] ?? 0);
            if ($hr > 0) { $sum += $hr; $cnt++; }
        }
        return $cnt > 0 ? $sum / $cnt : 0;
    }

    private function executeGetGoalProgress(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        try {
            require_once __DIR__ . '/UserProfileService.php';
            $profile = (new UserProfileService($this->db))->getProfile($userId);

            $result = ['goal' => null, 'current_fitness' => null, 'progress_history' => [], 'milestones' => [], 'trajectory' => 'unknown'];

            if (!empty($profile['race_distance'])) {
                $goal = ['distance' => $profile['race_distance'], 'target_time' => $profile['race_target_time'] ?? null, 'date' => $profile['race_date'] ?? null];
                if (!empty($profile['race_date'])) {
                    $tz = $this->getUserTz($userId);
                    $today = new DateTime('now', $tz); $today->setTime(0, 0, 0);
                    $raceDate = DateTime::createFromFormat('Y-m-d', $profile['race_date'], $tz);
                    if ($raceDate) { $raceDate->setTime(0, 0, 0); $goal['days_until_race'] = max(0, (int) $today->diff($raceDate)->days); }
                }
                $result['goal'] = $goal;
            } elseif (!empty($profile['goal_type'])) {
                $goalRu = ['race' => 'подготовка к забегу', 'time_improvement' => 'улучшение времени', 'weight_loss' => 'похудение', 'health' => 'здоровье'];
                $result['goal'] = ['type' => $goalRu[$profile['goal_type']] ?? $profile['goal_type']];
            }

            // Current VDOT
            require_once __DIR__ . '/StatsService.php';
            $vdotData = (new StatsService($this->db))->getBestResultForVdot($userId);
            if (!empty($vdotData['vdot'])) {
                $vdot = (float) $vdotData['vdot'];
                $predictions = $this->calculateVdotPredictions($vdot);
                $current = ['vdot' => round($vdot, 1), 'source' => $vdotData['vdot_source_detail'] ?? null];
                if (!empty($profile['race_distance'])) {
                    $distKey = $this->raceDistanceToKey($profile['race_distance']);
                    if ($distKey && isset($predictions[$distKey])) {
                        $current['predicted_time'] = $predictions[$distKey];
                        if (!empty($profile['race_target_time'])) {
                            $current['target_time'] = $profile['race_target_time'];
                        }
                    }
                }
                $result['current_fitness'] = $current;
            }

            // History from snapshots
            try {
                require_once __DIR__ . '/GoalProgressService.php';
                $gps = new GoalProgressService($this->db);
                $snapshots = $gps->getRecentSnapshots($userId, 12);
                foreach ($snapshots as $s) {
                    $entry = ['date' => $s['snapshot_date'] ?? null];
                    if (!empty($s['vdot'])) $entry['vdot'] = round((float) $s['vdot'], 1);
                    if (!empty($s['weekly_km'])) $entry['weekly_km'] = round((float) $s['weekly_km'], 1);
                    if (!empty($s['compliance_pct'])) $entry['compliance_pct'] = (int) $s['compliance_pct'];
                    $result['progress_history'][] = $entry;
                }

                $milestones = $gps->detectMilestones($userId);
                if (!empty($milestones)) {
                    $result['milestones'] = array_map(function ($m) {
                        $typeRu = ['vdot_improvement' => 'Улучшение VDOT', 'volume_record' => 'Рекорд объёма', 'consistency_streak' => 'Стабильные тренировки', 'goal_achievable' => 'Цель достижима'];
                        return ['type' => $typeRu[$m['type']] ?? $m['type'], 'data' => $m['data'] ?? []];
                    }, $milestones);
                }

                // Trajectory
                $summary = $gps->getProgressSummary($userId);
                if ($summary) {
                    $vdotTrend = $summary['vdot_trend_8w'] ?? $summary['vdot_trend'] ?? null;
                    if ($vdotTrend !== null) {
                        if ($vdotTrend > 0.5) $result['trajectory'] = 'ahead';
                        elseif ($vdotTrend > -0.5) $result['trajectory'] = 'on_track';
                        else $result['trajectory'] = 'behind';
                    }
                }
            } catch (Throwable $e) {}

            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'goal_progress_failed', 'message' => 'Не удалось получить прогресс: ' . $e->getMessage()]);
        }
    }

    private function raceDistanceToKey(?string $distance): ?string {
        if (!$distance) return null;
        $map = ['5k' => '5k', '10k' => '10k', 'half_marathon' => 'half', 'half' => 'half', 'marathon' => 'marathon', '5 км' => '5k', '10 км' => '10k', 'полумарафон' => 'half', 'марафон' => 'marathon'];
        return $map[strtolower($distance)] ?? null;
    }

    private function executeGetRaceStrategy(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        try {
            require_once __DIR__ . '/UserProfileService.php';
            $profile = (new UserProfileService($this->db))->getProfile($userId);

            $distance = $args['distance'] ?? null;
            if (!$distance && !empty($profile['race_distance'])) {
                $distance = $this->raceDistanceToKey($profile['race_distance']) ?? 'marathon';
            }
            if (!$distance) $distance = 'marathon';

            $distNames = ['5k' => '5 км', '10k' => '10 км', 'half' => 'Полумарафон (21.1 км)', 'marathon' => 'Марафон (42.2 км)'];
            $distKm = ['5k' => 5.0, '10k' => 10.0, 'half' => 21.0975, 'marathon' => 42.195];

            $result = ['race_info' => ['distance' => $distNames[$distance] ?? $distance]];
            if (!empty($profile['race_date'])) {
                $result['race_info']['date'] = $profile['race_date'];
                $tz = $this->getUserTz($userId);
                $today = new DateTime('now', $tz); $today->setTime(0, 0, 0);
                $raceDate = DateTime::createFromFormat('Y-m-d', $profile['race_date'], $tz);
                if ($raceDate) { $raceDate->setTime(0, 0, 0); $result['race_info']['days_until_race'] = max(0, (int) $today->diff($raceDate)->days); }
            }
            if (!empty($profile['race_target_time'])) $result['race_info']['target_time'] = $profile['race_target_time'];

            // Pace zones from VDOT
            require_once __DIR__ . '/StatsService.php';
            $vdotData = (new StatsService($this->db))->getBestResultForVdot($userId);
            if (!empty($vdotData['vdot'])) {
                $vdot = (float) $vdotData['vdot'];
                $result['vdot'] = round($vdot, 1);
                $predictions = $this->calculateVdotPredictions($vdot);
                if (isset($predictions[$distance])) $result['predicted_time'] = $predictions[$distance];
                $result['pace_zones'] = $this->calculateVdotPaceZones($vdot);

                // Pacing plan
                $paceZones = $result['pace_zones'];
                $marathonPace = null;
                foreach ($paceZones as $z) {
                    if ($z['zone'] === 'Лёгкий') $result['pacing_plan']['warmup_pace'] = $z['pace'];
                    if ($z['zone'] === 'Темповый') $marathonPace = $z['pace'];
                }
                if (in_array($distance, ['marathon', 'half'])) {
                    $result['pacing_plan']['strategy'] = 'negative_split';
                    $result['pacing_plan']['description'] = 'Начни на 5-10 сек/км медленнее целевого. Вторую половину — по плану или чуть быстрее.';
                } else {
                    $result['pacing_plan']['strategy'] = 'even_pace';
                    $result['pacing_plan']['description'] = 'Ровный темп с лёгким ускорением на последнем километре.';
                }
            }

            // Taper status from plan phase
            try {
                require_once __DIR__ . '/TrainingStateBuilder.php';
                $state = (new TrainingStateBuilder($this->db))->buildForUserId($userId);
                if (!empty($state['load_policy'])) {
                    $result['taper_info'] = ['weeks_to_goal' => $state['weeks_to_goal'] ?? null];
                }
                if (!empty($state['training_paces'])) {
                    $result['daniels_zones'] = $state['formatted_training_paces'] ?? $state['training_paces'];
                }
            } catch (Throwable $e) {}

            // Nutrition template
            $raceKm = $distKm[$distance] ?? 42.195;
            if ($raceKm >= 21) {
                $result['nutrition'] = [
                    'before_3days' => 'Углеводная загрузка: 8-10 г углеводов / кг массы тела в день',
                    'race_morning' => 'За 2-3 часа до старта: лёгкий завтрак (каша, банан, тост с мёдом). 300-500 мл воды.',
                    'during' => 'Гель каждые 30-45 мин (начиная с 45-60 мин). 150-250 мл воды каждые 15-20 мин.',
                    'after' => 'В течение 30 мин: белок + углеводы (шоколадное молоко, банан, протеиновый батончик).',
                ];
            }

            // Warmup
            $result['warmup'] = $raceKm <= 10
                ? 'Разминка 10-15 мин: лёгкий бег 1.5-2 км, динамическая растяжка, 3-4 ускорения по 80 м.'
                : 'Разминка 10 мин: лёгкий бег 1-1.5 км, динамическая растяжка. Не тратить энергию.';

            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'strategy_failed', 'message' => 'Не удалось подготовить стратегию: ' . $e->getMessage()]);
        }
    }

    private function executeExplainPlanLogic(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $date = $args['date'] ?? (new DateTime('now', $this->getUserTz($userId)))->format('Y-m-d');
        if (!$this->validateDate($date)) return json_encode(['error' => 'invalid_date']);
        $scope = $args['scope'] ?? 'day';

        try {
            // Get week info
            require_once __DIR__ . '/../repositories/WeekRepository.php';
            $repo = new WeekRepository($this->db);
            $week = $repo->getWeekByDate($userId, $date);

            $result = ['date' => $date, 'scope' => $scope];

            if ($week) {
                $result['week_number'] = (int) $week['week_number'];
                $result['total_volume'] = $week['total_volume'] ?? null;

                // Try to get phase info from plan skeleton
                $days = $repo->getDaysByWeekId($userId, (int) $week['id']);
                $daysByDate = [];
                foreach ($days as $d) $daysByDate[$d['date'] ?? ''] = $d;

                // Phase from generation metadata or skeleton
                $phase = $this->detectPlanPhase($userId, (int) $week['week_number']);
                if ($phase) {
                    $phaseLabels = ['pre_base' => 'Предбазовая', 'adaptation' => 'Адаптация', 'base' => 'Базовая', 'build' => 'Развитие', 'development' => 'Развитие', 'peak' => 'Пиковая', 'taper' => 'Снижение нагрузки', 'maintenance' => 'Поддержание'];
                    $result['current_phase'] = [
                        'name' => $phase, 'label' => $phaseLabels[$phase] ?? $phase,
                        'purpose' => $this->getPhaseExplanation($phase),
                    ];
                }
            }

            if ($scope === 'day' || $scope === 'week') {
                $dayPlan = $this->getDayPlanDataByDate($userId, $date);
                if ($dayPlan) {
                    $typeExplanations = [
                        'easy' => ['develops' => 'аэробная база, восстановление', 'reason' => 'Лёгкий бег поддерживает аэробную базу без перегрузки. 80% тренировок должны быть лёгкими.'],
                        'long' => ['develops' => 'выносливость, жиросжигание', 'reason' => 'Длительный бег — ключевая тренировка недели. Развивает капиллярную сеть, учит организм расходовать жиры.'],
                        'tempo' => ['develops' => 'лактатный порог', 'reason' => 'Темповый бег повышает скорость, которую ты можешь поддерживать длительно. Темп на уровне анаэробного порога.'],
                        'interval' => ['develops' => 'МПК (максимальное потребление кислорода)', 'reason' => 'Интервалы развивают максимальную аэробную мощность. Короткие быстрые отрезки с восстановлением.'],
                        'fartlek' => ['develops' => 'переключение темпа, аэробная мощность', 'reason' => 'Фартлек — разнообразная скоростная работа в свободной форме. Учит организм менять темп.'],
                        'rest' => ['develops' => 'восстановление, суперкомпенсация', 'reason' => 'День отдыха — когда происходит адаптация. Мышцы восстанавливаются и становятся сильнее.'],
                        'sbu' => ['develops' => 'техника бега, сила', 'reason' => 'Специальные беговые упражнения улучшают технику и укрепляют мышцы-стабилизаторы.'],
                        'other' => ['develops' => 'общая физическая подготовка', 'reason' => 'Кросс-тренинг укрепляет мышечный корсет и предотвращает травмы.'],
                        'control' => ['develops' => 'оценка текущей формы', 'reason' => 'Контрольный забег показывает прогресс и помогает скорректировать целевой темп.'],
                        'race' => ['develops' => 'соревновательный опыт', 'reason' => 'Забег — проверка подготовки в условиях соревнования.'],
                    ];
                    $type = $dayPlan['type'] ?? 'easy';
                    $result['day_explanation'] = [
                        'type' => self::TYPE_RU[$type] ?? $type,
                        'description' => $dayPlan['description'] ?? '',
                        'develops' => $typeExplanations[$type]['develops'] ?? 'общая подготовка',
                        'rationale' => $typeExplanations[$type]['reason'] ?? '',
                    ];
                }
            }

            if ($scope === 'week' && $week) {
                $days = $repo->getDaysByWeekId($userId, (int) $week['id']);
                $typeCounts = [];
                foreach ($days as $d) {
                    $t = $d['type'] ?? 'rest';
                    $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
                }
                $result['week_structure'] = [
                    'day_types' => array_map(fn($t, $c) => (self::TYPE_RU[$t] ?? $t) . ": {$c}", array_keys($typeCounts), $typeCounts),
                    'rationale' => 'Структура недели: ключевые тренировки (длительный, темповый, интервалы) чередуются с лёгкими днями и отдыхом для восстановления.',
                ];
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'explain_failed', 'message' => 'Не удалось объяснить логику: ' . $e->getMessage()]);
        }
    }

    private function detectPlanPhase(int $userId, int $weekNumber): ?string {
        try {
            $stmt = $this->db->prepare(
                "SELECT week_number FROM training_plan_weeks WHERE user_id = ? ORDER BY week_number ASC"
            );
            if (!$stmt) return null;
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $totalWeeks = 0;
            while ($res->fetch_assoc()) $totalWeeks++;
            $stmt->close();
            if ($totalWeeks === 0) return null;

            $pct = $weekNumber / $totalWeeks;
            if ($pct <= 0.15) return 'base';
            if ($pct <= 0.5) return 'build';
            if ($pct <= 0.8) return 'peak';
            if ($pct <= 0.95) return 'taper';
            return 'peak';
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getPhaseExplanation(string $phase): string {
        $explanations = [
            'pre_base' => 'Подготовка организма к нагрузкам. Минимальный объём, акцент на привыкание к регулярным тренировкам.',
            'adaptation' => 'Адаптация к беговым нагрузкам. Постепенное увеличение объёма, все тренировки в комфортном темпе.',
            'base' => 'Строительство аэробной базы. Объём растёт, основа — лёгкий бег. Появляются первые ключевые тренировки.',
            'build' => 'Развитие специфических качеств. Увеличение интенсивности: темповые, интервалы. Объём на плато или растёт медленно.',
            'development' => 'Максимальное развитие формы. Самые интенсивные тренировки, пиковый объём.',
            'peak' => 'Пиковая готовность. Интенсивность высокая, объём начинает снижаться. Организм выходит на максимум.',
            'taper' => 'Снижение нагрузки перед забегом. Объём сокращается на 40-60%, сохраняя интенсивность. Цель — суперкомпенсация.',
            'maintenance' => 'Поддержание набранной формы. Стабильный объём и интенсивность.',
        ];
        return $explanations[$phase] ?? '';
    }

    private function executeReportHealthIssue(array $args, ?int $userId): string {
        if ($err = $this->requireUser($userId)) return $err;
        $issueType = $args['issue_type'] ?? 'other';
        $description = $args['description'] ?? '';
        $severity = $args['severity'] ?? 'mild';
        $daysOff = isset($args['days_off']) ? (int) $args['days_off'] : null;
        $affectedArea = $args['affected_area'] ?? null;

        try {
            $typeRu = ['illness' => 'болезнь', 'injury' => 'травма', 'fatigue' => 'усталость/перетренированность', 'other' => 'другое'];
            $sevRu = ['mild' => 'лёгкая', 'moderate' => 'средняя', 'severe' => 'тяжёлая'];

            // Assessment based on current load
            $loadInfo = null;
            try {
                require_once __DIR__ . '/TrainingLoadService.php';
                $load = (new TrainingLoadService($this->db))->getTrainingLoad($userId, 28);
                if (!empty($load['current'])) $loadInfo = $load['current'];
            } catch (Throwable $e) {}

            $result = [
                'issue' => ['type' => $typeRu[$issueType] ?? $issueType, 'description' => $description, 'severity' => $sevRu[$severity] ?? $severity],
            ];
            if ($affectedArea) $result['issue']['affected_area'] = $affectedArea;

            // Persist health event for AI memory
            $insertStmt = $this->db->prepare(
                "INSERT INTO user_health_events (user_id, issue_type, description, severity, affected_area, days_off)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if ($insertStmt) {
                $insertStmt->bind_param('issssi', $userId, $issueType, $description, $severity, $affectedArea, $daysOff);
                $insertStmt->execute();
                $result['issue']['recorded'] = true;
                $insertStmt->close();
            }

            // Return-to-run protocol
            $restDays = $daysOff ?? match ($severity) { 'mild' => 2, 'moderate' => 5, 'severe' => 10, default => 3 };
            if ($issueType === 'illness') $restDays = max($restDays, 3);
            if ($issueType === 'injury' && $severity === 'severe') $restDays = max($restDays, 14);

            $result['return_protocol'] = [
                ['phase' => 1, 'description' => "Полный отдых: {$restDays} дней. Никакого бега.", 'days' => $restDays],
                ['phase' => 2, 'description' => 'Лёгкая активность: ходьба 20-30 мин, плавание, йога.', 'days' => max(2, (int) ($restDays * 0.5))],
                ['phase' => 3, 'description' => 'Лёгкий бег: 50% обычного объёма, очень комфортный темп.', 'days' => max(3, (int) ($restDays * 0.7))],
                ['phase' => 4, 'description' => 'Постепенный возврат: 70-80% объёма, добавление ключевых по самочувствию.', 'days' => max(3, (int) ($restDays * 0.5))],
            ];

            // Plan impact
            $result['plan_impact'] = ['suggest_recalculate' => $restDays >= 5 || $severity !== 'mild'];
            if ($result['plan_impact']['suggest_recalculate']) {
                $result['plan_impact']['note'] = 'Рекомендуется пересчитать план после возврата к тренировкам.';
            }

            // Load context
            if ($loadInfo) {
                $tsb = $loadInfo['tsb'] ?? null;
                if ($tsb !== null && $tsb < -20) {
                    $result['assessment'] = 'Высокая накопленная усталость (TSB: ' . round($tsb, 1) . '). Проблема может быть связана с перегрузкой. Отдых критически важен.';
                }
            }

            // Medical referral
            if ($severity === 'severe' || ($issueType === 'injury' && $severity === 'moderate')) {
                $result['medical_note'] = 'При данной степени тяжести настоятельно рекомендуется консультация спортивного врача.';
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            return json_encode(['error' => 'health_report_failed', 'message' => 'Не удалось обработать: ' . $e->getMessage()]);
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
