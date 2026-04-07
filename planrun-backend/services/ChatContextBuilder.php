<?php
/**
 * Построитель контекста пользователя для AI-чата
 * Собирает профиль, план и статистику в текстовый контекст
 */

require_once __DIR__ . '/../user_functions.php';
require_once __DIR__ . '/../load_training_plan.php';

class ChatContextBuilder {

    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Собрать полный контекст пользователя для AI.
     * Включает профиль, план, статистику и постоянную «память» по пользователю
     * (хранится в БД, подставляется в каждый запрос — для модели выглядит как «она помнит»).
     */
    public function buildContextForUser(int $userId): string {
        $user = getUserData($userId, null, false);
        $plan = loadTrainingPlanForUser($userId, false);
        $stats = $this->getStats($userId);
        $memory = $this->getUserMemory($userId);

        $parts = [];
        $parts[] = $this->formatProfile($user);
        $parts[] = $this->formatPlanSummary($plan, $userId);
        $parts[] = $this->formatNextWeekSummary($plan, $userId);
        $parts[] = $this->formatPaceZonesAndPhase($userId);
        $parts[] = $this->formatHrZones($userId, $user);
        $parts[] = $this->formatStats($stats);
        $parts[] = $this->formatCoachingInsights($userId);
        $parts[] = $this->formatRecentWorkouts($userId);
        $parts[] = $this->formatActiveHealthIssues($userId);
        if ($memory !== '') {
            $parts[] = "═══ ПАМЯТЬ О ПОЛЬЗОВАТЕЛЕ (из прошлых диалогов) ═══\n" . $memory;
        }

        $historySummary = $this->getHistorySummary($userId);
        if ($historySummary !== '') {
            $parts[] = "═══ СУММАРИЗАЦИЯ СТАРОЙ ИСТОРИИ ЧАТА ═══\n" . $historySummary;
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Прочитать постоянный контекст пользователя из chat_user_memory.
     * Модель сама ничего не хранит; мы каждый раз подставляем это в промпт.
     */
    private function getUserMemory(int $userId): string {
        $stmt = $this->db->prepare("SELECT content FROM chat_user_memory WHERE user_id = ?");
        if (!$stmt || !$stmt->bind_param('i', $userId) || !$stmt->execute()) {
            return '';
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        return $row && !empty(trim($row['content'] ?? '')) ? trim($row['content']) : '';
    }

    /**
     * Прочитать суммаризацию старой истории чата (для гибридного контекста).
     */
    public function getHistorySummary(int $userId): string {
        $stmt = $this->db->prepare("SELECT history_summary FROM chat_user_memory WHERE user_id = ?");
        if (!$stmt || !$stmt->bind_param('i', $userId) || !$stmt->execute()) {
            return '';
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $summary = $row['history_summary'] ?? null;
        return $summary !== null && $summary !== '' ? trim($summary) : '';
    }

    /**
     * Записать суммаризацию старой истории чата.
     */
    public function setHistorySummary(int $userId, string $content): bool {
        $content = trim($content);
        $stmt = $this->db->prepare(
            "INSERT INTO chat_user_memory (user_id, content, history_summary) VALUES (?, '', ?) 
             ON DUPLICATE KEY UPDATE history_summary = VALUES(history_summary)"
        );
        if (!$stmt || !$stmt->bind_param('is', $userId, $content)) {
            return false;
        }
        return $stmt->execute();
    }

    /**
     * Записать или обновить «память» пользователя (постоянный контекст для AI).
     * Вызывать из админки, API или после суммаризации диалога.
     */
    public function setUserMemory(int $userId, string $content): bool {
        $content = trim($content);
        $stmt = $this->db->prepare("INSERT INTO chat_user_memory (user_id, content) VALUES (?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)");
        if (!$stmt || !$stmt->bind_param('is', $userId, $content)) {
            return false;
        }
        return $stmt->execute();
    }

    private function formatProfile(?array $user): string {
        if (!$user) {
            return '';
        }
        $lines = ["═══ ПРОФИЛЬ ПОЛЬЗОВАТЕЛЯ ═══\n"];

        if (!empty($user['gender'])) {
            $gender = $user['gender'] === 'male' ? 'мужской' : 'женский';
            $lines[] = "Пол: {$gender}";
        }
        if (!empty($user['birth_year'])) {
            $age = date('Y') - (int)$user['birth_year'];
            $lines[] = "Возраст: ~{$age} лет";
        }
        if (!empty($user['height_cm'])) {
            $lines[] = "Рост: {$user['height_cm']} см";
        }
        if (!empty($user['weight_kg'])) {
            $lines[] = "Вес: {$user['weight_kg']} кг";
        }

        if (!empty($user['experience_level'])) {
            $levelMap = [
                'novice' => 'Новичок',
                'beginner' => 'Начинающий',
                'intermediate' => 'Любитель',
                'advanced' => 'Опытный',
                'expert' => 'Эксперт'
            ];
            $level = $levelMap[$user['experience_level']] ?? $user['experience_level'];
            $lines[] = "Уровень: {$level}";
        }
        if (!empty($user['weekly_base_km'])) {
            $lines[] = "Текущий объём бега: {$user['weekly_base_km']} км/неделю";
        }
        if (!empty($user['sessions_per_week'])) {
            $lines[] = "Тренировок в неделю: {$user['sessions_per_week']}";
        }

        $goalType = $user['goal_type'] ?? 'health';
        $lines[] = "\n═══ ЦЕЛЬ ═══";
        switch ($goalType) {
            case 'race':
                $lines[] = "Цель: Подготовка к забегу";
                if (!empty($user['race_date'])) $lines[] = "Дата забега: {$user['race_date']}";
                if (!empty($user['race_distance'])) $lines[] = "Дистанция: {$user['race_distance']}";
                if (!empty($user['race_target_time'])) {
                    $lines[] = "Целевое время: " . $this->formatTimeForPrompt($user['race_target_time']);
                }
                break;
            case 'time_improvement':
                $lines[] = "Цель: Улучшение времени";
                if (!empty($user['target_marathon_date'])) $lines[] = "Дата марафона: {$user['target_marathon_date']}";
                if (!empty($user['target_marathon_time'])) {
                    $lines[] = "Целевое время: " . $this->formatTimeForPrompt($user['target_marathon_time']);
                }
                break;
            case 'weight_loss':
                $lines[] = "Цель: Снижение веса";
                if (!empty($user['weight_goal_kg'])) $lines[] = "Целевой вес: {$user['weight_goal_kg']} кг";
                if (!empty($user['weight_goal_date'])) $lines[] = "К дате: {$user['weight_goal_date']}";
                break;
            case 'health':
            default:
                $lines[] = "Цель: Бег для здоровья";
                if (!empty($user['health_program'])) $lines[] = "Программа: {$user['health_program']}";
        }

        if (!empty($user['training_start_date'])) {
            $lines[] = "Дата начала тренировок: {$user['training_start_date']}";
        }

        if (!empty($user['easy_pace_sec'])) {
            $paceMin = floor($user['easy_pace_sec'] / 60);
            $paceSec = $user['easy_pace_sec'] % 60;
            $lines[] = "Комфортный темп: {$paceMin}:" . sprintf('%02d', $paceSec) . " /км";
        }

        if (!empty($user['preferred_days'])) {
            $days = is_string($user['preferred_days']) ? json_decode($user['preferred_days'], true) : $user['preferred_days'];
            if (is_array($days) && !empty($days)) {
                $dayLabels = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
                $dayStr = implode(', ', array_map(fn($d) => $dayLabels[$d] ?? $d, $days));
                $lines[] = "Дни бега: {$dayStr}";
            }
        }

        if (!empty($user['training_time_pref'])) {
            $timeMap = ['morning' => 'Утро', 'day' => 'День', 'evening' => 'Вечер'];
            $lines[] = "Время тренировок: " . ($timeMap[$user['training_time_pref']] ?? $user['training_time_pref']);
        }

        if (!empty($user['has_treadmill'])) {
            $lines[] = "Есть беговая дорожка: Да";
        }

        if (!empty($user['health_notes'])) {
            $lines[] = "Ограничения по здоровью: {$user['health_notes']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Форматирует время из БД (HH:MM:SS или HH:MM) в однозначный вид для промпта: "X ч Y мин"
     */
    private function formatTimeForPrompt(?string $time): string {
        if (empty($time) || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', trim($time), $m)) {
            return (string) $time;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        return "{$h} ч " . sprintf('%02d', $min) . " мин";
    }

    private function formatPlanSummary(array $plan, int $userId): string {
        $weeks = isset($plan['weeks_data']) && is_array($plan['weeks_data']) ? $plan['weeks_data'] : [];
        if (empty($weeks)) {
            return "═══ ПЛАН ═══\nПлан тренировок пока не создан.";
        }

        $lines = ["═══ ТЕКУЩИЙ ПЛАН ═══"];
        $tzName = getUserTimezone($userId);
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Exception $e) {
            $tz = new DateTimeZone('Europe/Moscow');
        }
        $today = new DateTime('now', $tz);
        $today->setTime(0, 0, 0);
        $dayLabels = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];

        $found = false;
        foreach ($weeks as $week) {
            $startDate = $week['start_date'] ?? null;
            if (!$startDate) {
                continue;
            }
            $start = new DateTime($startDate);
            $start->setTime(0, 0, 0);
            $end = clone $start;
            $end->modify('+6 days');
            if ($today < $start || $today > $end) {
                continue;
            }
            $lines[] = "Текущая неделя: №{$week['number']}";
            if (!empty($week['total_volume'])) {
                $lines[] = "Объём недели: {$week['total_volume']}";
            }
            $days = $week['days'] ?? [];
            foreach ($days as $dayName => $dayData) {
                if (!$dayData || !is_array($dayData)) continue;
                $items = isset($dayData[0]) ? $dayData : [$dayData];
                $first = $items[0];
                $type = $first['type'] ?? 'rest';
                $text = $first['text'] ?? '';
                $typeRu = $this->getDayTypeRu($type);
                $lines[] = "  {$dayLabels[$dayName]}: {$typeRu}" . ($text ? " — {$text}" : '');
            }
            $found = true;
            break;
        }

        if (!$found) {
            $lines[] = "Текущая неделя не найдена в плане. План включает " . count($weeks) . " недель.";
        }
        return implode("\n", $lines);
    }

    private function getUserTz(int $userId): DateTimeZone {
        try {
            return new DateTimeZone(getUserTimezone($userId));
        } catch (Exception $e) {
            return new DateTimeZone('Europe/Moscow');
        }
    }

    private function getDayTypeRu(string $type): string {
        $map = [
            'easy' => 'Легкий бег',
            'long' => 'Длительный',
            'tempo' => 'Темповый',
            'interval' => 'Интервалы',
            'fartlek' => 'Фартлек',
            'control' => 'Контрольный забег',
            'rest' => 'Отдых',
            'other' => 'ОФП',
            'ofp' => 'ОФП',
            'sbu' => 'СБУ',
            'race' => 'Забег',
            'free' => 'Свободный',
        ];
        return $map[$type] ?? $type;
    }

    private function getStats(int $userId): array {
        try {
            require_once __DIR__ . '/../repositories/BaseRepository.php';
            require_once __DIR__ . '/../repositories/StatsRepository.php';
            require_once __DIR__ . '/../query_helpers.php';
            require_once __DIR__ . '/../training_utils.php';
            $total = (new StatsRepository($this->db))->getTotalDays($userId);
            $completedDaysSet = getCompletedDaysKeys($this->db, $userId);
            $workoutDates = (new StatsRepository($this->db))->getWorkoutDates($userId);
            foreach ($workoutDates as $workoutDate) {
                $trainingDay = findTrainingDay($workoutDate, $userId);
                if ($trainingDay) {
                    $dayKey = $trainingDay['training_date'] . '-' . $trainingDay['week_number'] . '-' . $trainingDay['day_name'];
                    $completedDaysSet[$dayKey] = true;
                }
            }
            $completed = count($completedDaysSet);
            $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
            return ['total' => $total, 'completed' => $completed, 'percentage' => $percentage];
        } catch (Throwable $e) {
            return ['total' => 0, 'completed' => 0, 'percentage' => 0];
        }
    }

    private function formatStats(array $stats): string {
        $lines = ["═══ СТАТИСТИКА ═══"];
        $lines[] = "Выполнено: {$stats['completed']} из {$stats['total']} дней плана ({$stats['percentage']}%)";
        return implode("\n", $lines);
    }

    /**
     * Загружает последние N выполненных тренировок с результатами.
     * Источники: workout_log (результаты заполненные через «Выполнено»)
     * и training_plan_days (запланированный тип/описание для контекста).
     */
    public function getRecentWorkouts(int $userId, int $limit = 10): array {
        $sql = "(SELECT 
                    wl.training_date AS date,
                    wl.distance_km,
                    wl.result_time,
                    wl.pace,
                    wl.duration_minutes,
                    wl.avg_heart_rate,
                    wl.max_heart_rate,
                    wl.notes,
                    wl.rating,
                    wl.is_completed,
                    tpd.type AS plan_type,
                    tpd.description AS plan_description,
                    tpd.is_key_workout,
                    LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) COLLATE utf8mb4_unicode_ci AS activity_type,
                    'manual' AS source
                FROM workout_log wl
                LEFT JOIN activity_types at ON at.id = wl.activity_type_id
                LEFT JOIN training_plan_days tpd 
                    ON tpd.id = (
                        SELECT id FROM training_plan_days
                        WHERE user_id = wl.user_id AND date = wl.training_date
                        ORDER BY id DESC LIMIT 1
                    )
                WHERE wl.user_id = ? 
                    AND wl.is_completed = 1)
                UNION ALL
                (SELECT 
                    DATE(w.start_time) AS date,
                    w.distance_km,
                    NULL AS result_time,
                    w.avg_pace COLLATE utf8mb4_unicode_ci AS pace,
                    w.duration_minutes,
                    w.avg_heart_rate,
                    w.max_heart_rate,
                    NULL AS notes,
                    NULL AS rating,
                    1 AS is_completed,
                    COALESCE(tpd.type COLLATE utf8mb4_unicode_ci, w.activity_type COLLATE utf8mb4_unicode_ci) AS plan_type,
                    COALESCE(
                        tpd.description COLLATE utf8mb4_unicode_ci,
                        CONCAT(w.activity_type, ' ', COALESCE(w.distance_km, 0), ' км') COLLATE utf8mb4_unicode_ci
                    ) AS plan_description,
                    COALESCE(tpd.is_key_workout, 0) AS is_key_workout,
                    LOWER(COALESCE(NULLIF(TRIM(w.activity_type), ''), 'running')) COLLATE utf8mb4_unicode_ci AS activity_type,
                    COALESCE(w.source, 'import') COLLATE utf8mb4_unicode_ci AS source
                FROM workouts w
                LEFT JOIN training_plan_days tpd
                    ON tpd.id = (
                        SELECT id FROM training_plan_days
                        WHERE user_id = w.user_id AND date = DATE(w.start_time)
                        ORDER BY id DESC LIMIT 1
                    )
                WHERE w.user_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM workout_log wl2
                        WHERE wl2.user_id = w.user_id
                          AND wl2.training_date = DATE(w.start_time)
                          AND wl2.is_completed = 1
                    ))
                ORDER BY date DESC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iii', $userId, $userId, $limit);
        if (!$stmt->execute()) {
            return [];
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function formatNextWeekSummary(?array $plan, int $userId): string {
        if (empty($plan['weeks_data'])) return '';
        $tz = $this->getUserTz($userId);
        $today = new \DateTime('now', $tz);
        $nextMonday = (clone $today)->modify('next monday');
        $nextSunday = (clone $nextMonday)->modify('+6 days');

        $nextWeekDays = [];
        foreach ($plan['weeks_data'] as $week) {
            foreach ($week['days'] ?? [] as $day) {
                $date = $day['date'] ?? '';
                if ($date >= $nextMonday->format('Y-m-d') && $date <= $nextSunday->format('Y-m-d')) {
                    $nextWeekDays[] = $day;
                }
            }
        }
        if (empty($nextWeekDays)) return '';

        $lines = ["═══ СЛЕДУЮЩАЯ НЕДЕЛЯ ({$nextMonday->format('d.m')} – {$nextSunday->format('d.m')}) ═══"];
        $totalKm = 0;
        foreach ($nextWeekDays as $d) {
            $date = $d['date'] ?? '';
            $type = $d['type'] ?? 'rest';
            $dist = (float) ($d['distance_km'] ?? 0);
            $desc = mb_substr($d['short_description'] ?? $d['description'] ?? '', 0, 60);
            $totalKm += $dist;
            $dow = $this->shortDow($date);
            $lines[] = "{$dow} {$date}: {$type}" . ($dist > 0 ? " {$dist}км" : '') . ($desc ? " — {$desc}" : '');
        }
        $lines[] = "Итого: " . round($totalKm, 1) . " км";
        return implode("\n", $lines);
    }

    private function formatPaceZonesAndPhase(int $userId): string {
        $parts = [];
        try {
            require_once __DIR__ . '/TrainingStateBuilder.php';
            $state = (new TrainingStateBuilder($this->db))->buildState($userId);
            if (!empty($state['pace_zones'])) {
                $zones = $state['pace_zones'];
                $zoneLines = ["═══ ЗОНЫ ТЕМПА ═══"];
                $zoneMap = [
                    'easy' => 'Лёгкий', 'moderate' => 'Умеренный', 'tempo' => 'Темповый',
                    'threshold' => 'Пороговый', 'interval' => 'Интервальный', 'repetition' => 'Повторный',
                ];
                foreach ($zones as $key => $val) {
                    $label = $zoneMap[$key] ?? $key;
                    if (is_array($val)) {
                        $from = $val['from'] ?? $val['min'] ?? '';
                        $to = $val['to'] ?? $val['max'] ?? '';
                        $zoneLines[] = "{$label}: {$from} — {$to} мин/км";
                    } else {
                        $zoneLines[] = "{$label}: {$val} мин/км";
                    }
                }
                $parts[] = implode("\n", $zoneLines);
            }
            if (!empty($state['macrocycle_phase'])) {
                $phaseLabels = [
                    'base' => 'Базовый период', 'build' => 'Развивающий период',
                    'peak' => 'Пиковый период', 'taper' => 'Тейпер (сужение)',
                    'recovery' => 'Восстановление', 'race' => 'Соревновательный',
                ];
                $ph = $state['macrocycle_phase'];
                $label = $phaseLabels[$ph] ?? $ph;
                $parts[] = "═══ ФАЗА МАКРОЦИКЛА ═══\nТекущая фаза: {$label}";
            }
        } catch (Throwable $e) {
            // TrainingStateBuilder may not be available
        }
        return implode("\n\n", $parts);
    }

    /**
     * Зоны ЧСС пользователя для контекста ИИ.
     */
    private function formatHrZones(int $userId, ?array $user): string {
        try {
            require_once __DIR__ . '/UserProfileService.php';
            $profileService = new UserProfileService($this->db);
            $hrData = $profileService->getHrZonesData($userId, $user);

            $maxHr = $hrData['effective_max_hr'] ?? 0;
            if ($maxHr < 120) return '';

            $source = $hrData['source'] ?? 'unknown';
            $sourceLabel = match($source) {
                'detected' => 'из тренировок',
                'override' => 'ручной (авто: ' . ($hrData['detected_max_hr'] ?? '?') . ')',
                'profile' => 'ручной ввод',
                'manual' => 'ручной ввод',
                'formula' => 'по формуле 220−возраст',
                default => '',
            };

            $zones = $hrData['zones'] ?? [];
            if (empty($zones)) return '';

            $zoneNames = [
                1 => 'Восст.',
                2 => 'Аэробная',
                3 => 'Темповая',
                4 => 'Пороговая',
                5 => 'Макс.',
            ];

            $methodLabel = ($hrData['zone_method'] ?? '') === 'karvonen'
                ? ', Карвонен, покой ' . ($hrData['effective_rest_hr'] ?? '?')
                : '';
            $lines = ["═══ ЗОНЫ ЧСС (макс {$maxHr} уд/м, {$sourceLabel}{$methodLabel}) ═══"];
            foreach ($zones as $z) {
                $num = $z['zone'] ?? 0;
                $name = $zoneNames[$num] ?? "Z{$num}";
                $lines[] = "Z{$num} {$name}: {$z['min_hr']}–{$z['max_hr']} уд/м";
            }
            // Реальные пульсовые данные из тренировок (6 недель) — приоритет над формулами
            $realRanges = $hrData['real_hr_ranges'] ?? [];
            if (!empty($realRanges)) {
                $lines[] = "";
                $lines[] = "РЕАЛЬНЫЙ ПУЛЬС ИЗ ТРЕНИРОВОК за 6 недель (используй ЭТИ данные для рекомендаций):";
                $typeLabels = [
                    'easy' => 'Лёгкий бег (≥5:30/км)',
                    'moderate' => 'Умеренный (5:00–5:29/км)',
                    'intense' => 'Интенсивный (<5:00/км)',
                ];
                foreach ($typeLabels as $key => $label) {
                    if (empty($realRanges[$key])) continue;
                    $r = $realRanges[$key];
                    $line = "- {$label}: обычно {$r['p25']}–{$r['p75']} уд/м ({$r['count']} тренировок)";
                    // Тренд
                    $trend = $r['trend'] ?? null;
                    if ($trend === 'improving') {
                        $line .= " ↓ пульс снижается ({$r['trend_diff']} уд, было {$r['prev_avg']} → стало {$r['recent_avg']})";
                    } elseif ($trend === 'worsening') {
                        $line .= " ↑ пульс вырос (+{$r['trend_diff']} уд, было {$r['prev_avg']} → стало {$r['recent_avg']})";
                    }
                    $lines[] = $line;
                }
                $lines[] = "Указывай целевой пульс на основе РЕАЛЬНЫХ данных. Если тренд ↓ — отметь прогресс.";
            } else {
                $lines[] = "При описании тренировок указывай целевую зону ЧСС и диапазон пульса.";
            }

            return implode("\n", $lines);
        } catch (Throwable $e) {
            return '';
        }
    }

    private function shortDow(string $date): string {
        $map = ['Mon' => 'Пн', 'Tue' => 'Вт', 'Wed' => 'Ср', 'Thu' => 'Чт', 'Fri' => 'Пт', 'Sat' => 'Сб', 'Sun' => 'Вс'];
        try {
            $d = new \DateTime($date);
            return $map[$d->format('D')] ?? '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Coaching insights: краткая сводка + сигналы для AI (пропуски, нагрузка, тренд).
     * Занимает 5-10 строк — экономим токены, но даём AI достаточно для умных ответов.
     */
    private function formatCoachingInsights(int $userId): string {
        $lastWorkout = $this->getRecentWorkouts($userId, 1);
        $weekData = $this->getThisWeekWorkoutCount($userId);
        $complianceData = $this->getWeeklyCompliance($userId);
        $loadTrend = $this->getLoadTrend($userId);
        $planVsActual = $this->getThisWeekPlanVsActual($userId);

        $lines = ["═══ ТРЕНЕРСКАЯ СВОДКА ═══"];

        if (!empty($lastWorkout)) {
            $w = $lastWorkout[0];
            $date = $w['date'] ?? '';
            $daysAgo = '';
            $tz = $this->getUserTz($userId);
            if ($date) {
                $diff = (int) (new DateTime('now', $tz))->diff(new DateTime($date, $tz))->days;
                $daysAgo = $diff === 0 ? 'сегодня' : ($diff === 1 ? 'вчера' : "{$diff} дн. назад");
            }
            $type = $w['plan_type'] ? $this->getDayTypeRu($w['plan_type']) : 'тренировка';
            $brief = "Последняя тренировка: {$daysAgo}, {$type}";
            $dist = $w['distance_km'] ?? null;
            if ($dist !== null && $dist > 0) {
                $brief .= ", {$dist} км";
            }
            $rating = $w['rating'] ?? null;
            if ($rating !== null && $rating > 0) {
                $ratingLabels = [1 => 'очень тяжело', 2 => 'тяжело', 3 => 'нормально', 4 => 'хорошо', 5 => 'отлично'];
                $brief .= ' (' . ($ratingLabels[$rating] ?? '') . ')';
            }
            $lines[] = $brief;

            if ($diff >= 4) {
                $lines[] = "⚠ Пауза {$diff} дней — мягко уточни причину, предложи помощь с возвращением.";
            }
            if ($rating !== null && $rating <= 2) {
                $lines[] = "⚠ Последняя тренировка была тяжёлой — спроси про самочувствие, при необходимости предложи снизить нагрузку.";
            }
        } else {
            $lines[] = "Нет выполненных тренировок — пользователь только начинает.";
        }

        // Разбивка по типу активности за неделю
        $weekLine = "Эта неделя: {$weekData['completed']} тренировок";
        if ($weekData['total_km'] > 0) {
            $weekLine .= ", {$weekData['total_km']} км";
        }
        $byType = $weekData['by_type'] ?? [];
        if (count($byType) > 1 || (count($byType) === 1 && !isset($byType['running']))) {
            $typeParts = [];
            $typeLabels = ['running' => 'бег', 'walking' => 'ходьба', 'cycling' => 'вело', 'swimming' => 'плавание', 'hiking' => 'поход'];
            foreach ($byType as $t => $d) {
                $label = $typeLabels[$t] ?? $t;
                $typeParts[] = "{$label}: {$d['count']} ({$d['km']} км)";
            }
            $weekLine .= ' [' . implode(', ', $typeParts) . ']';
        }
        $lines[] = $weekLine;

        // План vs факт: несовпадение типов
        if ($planVsActual['mismatch'] > 0) {
            $lines[] = "⚠ НЕСОВПАДЕНИЕ ТИПОВ: по плану бег, но {$planVsActual['mismatch']} дн. заменены другой активностью:";
            foreach ($planVsActual['mismatches'] as $m) {
                $plannedRu = $this->getDayTypeRu($m['planned']);
                $lines[] = "  • {$m['date']}: план — {$plannedRu}, факт — {$m['actual']}";
            }
            $lines[] = "→ Уточни у пользователя причину замены. Ходьба не заменяет беговую тренировку для подготовки к забегу.";
        }
        if ($planVsActual['missing'] > 0) {
            $lines[] = "Пропущено {$planVsActual['missing']} запланированных тренировок на этой неделе.";
        }

        if ($complianceData['planned'] > 0) {
            $pct = round(($complianceData['completed'] / $complianceData['planned']) * 100);
            $lines[] = "Выполнение плана за 2 недели: {$complianceData['completed']}/{$complianceData['planned']} ({$pct}%)";
            if ($complianceData['missed'] >= 3) {
                $lines[] = "⚠ Пропущено {$complianceData['missed']} тренировок за 2 недели — узнай причину, предложи адаптацию.";
            }
        }

        if ($loadTrend !== null) {
            if ($loadTrend > 30) {
                $lines[] = "⚠ Нагрузка выросла на {$loadTrend}% к прошлой неделе — следи за восстановлением.";
            } elseif ($loadTrend < -30) {
                $lines[] = "Нагрузка снизилась на " . abs($loadTrend) . "% — возможно разгрузочная неделя или пропуски.";
            }
        }

        // ACWR (Acute:Chronic Workload Ratio)
        $acwr = $this->calculateACWR($userId);
        if ($acwr['acwr'] !== null) {
            $acwrVal = $acwr['acwr'];
            $zoneLabels = [
                'low' => 'недогрузка',
                'optimal' => 'оптимально',
                'caution' => 'повышенный риск',
                'danger' => 'ВЫСОКИЙ РИСК ТРАВМЫ',
            ];
            $zoneLabel = $zoneLabels[$acwr['zone']] ?? '';
            $lines[] = "ACWR: {$acwrVal} ({$zoneLabel})";
            if ($acwr['zone'] === 'danger') {
                $lines[] = "⚠ ACWR > 1.5 — рекомендуй снижение нагрузки, проверь самочувствие, предложи разгрузочный день.";
            } elseif ($acwr['zone'] === 'caution') {
                $lines[] = "⚠ ACWR в зоне предупреждения (1.3–1.5) — не увеличивай нагрузку, мониторь восстановление.";
            }
        }

        // Детализация: план vs факт по каждому дню текущей недели
        $dayDetails = $this->getWeekDayByDayComparison($userId);
        if (!empty($dayDetails)) {
            $lines[] = "";
            $lines[] = "ПЛАН vs ФАКТ (эта неделя):";
            foreach ($dayDetails as $dd) {
                $lines[] = "  {$dd}";
            }
        }

        $lines[] = "Для детальных данных о конкретном дне — используй get_day_details(date).";

        return implode("\n", $lines);
    }

    /**
     * Recent workout history (last 4 workouts) for AI context — avoids extra tool calls.
     */
    private function formatRecentWorkouts(int $userId): string {
        $tz = $this->getUserTz($userId);
        $fourWeeksAgo = (new DateTime('now', $tz))->modify('-28 days')->format('Y-m-d');
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $workouts = $this->getWorkoutsHistory($userId, $fourWeeksAgo, $today, 4);
        if (empty($workouts)) return '';

        $lines = ["═══ ПОСЛЕДНИЕ ТРЕНИРОВКИ ═══"];
        foreach (array_reverse($workouts) as $w) {
            $date = $w['date'] ?? '';
            $type = !empty($w['plan_type']) ? $this->getDayTypeRu($w['plan_type']) : ($w['activity_type'] ?? 'тренировка');
            $km = round((float) ($w['distance_km'] ?? 0), 1);
            $pace = $w['pace'] ?? '';
            $hr = (int) ($w['avg_heart_rate'] ?? 0);
            $source = $w['source'] ?? '';

            $line = "{$date}: {$type}";
            if ($km > 0) $line .= ", {$km} км";
            if ($pace && $pace !== '0:00') $line .= ", темп {$pace}";
            if ($hr > 0) $line .= ", ЧСС {$hr}";
            if ($source && $source !== 'manual') $line .= " [{$source}]";
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Active health issues from user_health_events table.
     */
    private function formatActiveHealthIssues(int $userId): string {
        if (!$this->tableExists('user_health_events')) return '';
        $stmt = $this->db->prepare(
            "SELECT issue_type, description, severity, affected_area, created_at
             FROM user_health_events
             WHERE user_id = ? AND resolved_at IS NULL
             ORDER BY created_at DESC LIMIT 5"
        );
        if (!$stmt) return '';
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $issues = [];
        while ($row = $result->fetch_assoc()) $issues[] = $row;
        $stmt->close();

        if (empty($issues)) return '';

        $typeRu = ['illness' => 'Болезнь', 'injury' => 'Травма', 'fatigue' => 'Усталость', 'other' => 'Другое'];
        $sevRu = ['mild' => 'лёгкая', 'moderate' => 'средняя', 'severe' => 'тяжёлая'];
        $lines = ["═══ АКТИВНЫЕ ПРОБЛЕМЫ СО ЗДОРОВЬЕМ ═══"];
        $lines[] = "⚠ ВАЖНО: Учитывай эти проблемы при рекомендациях!";
        foreach ($issues as $issue) {
            $type = $typeRu[$issue['issue_type']] ?? $issue['issue_type'];
            $sev = $sevRu[$issue['severity']] ?? $issue['severity'];
            $line = "{$type} ({$sev})";
            if (!empty($issue['affected_area'])) $line .= " — {$issue['affected_area']}";
            $line .= ": {$issue['description']}";
            $line .= " (с " . date('d.m', strtotime($issue['created_at'])) . ")";
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private function tableExists(string $table): bool {
        $result = $this->db->query("SHOW TABLES LIKE '{$this->db->real_escape_string($table)}'");
        return $result && $result->num_rows > 0;
    }

    /**
     * ACWR (Acute:Chronic Workload Ratio) — соотношение острой (7 дней) к хронической (28 дней) нагрузке.
     * Используем sRPE (session RPE): duration_minutes × (6 - rating) / 5, где rating 1=тяжело, 5=легко.
     * Если rating нет — используем дистанцию как proxy.
     * Безопасная зона: 0.8–1.3. Опасно: >1.5.
     *
     * @return array{acwr: float|null, acute: float, chronic: float, zone: string}
     */
    public function calculateACWR(int $userId): array {
        $tz = $this->getUserTz($userId);
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $sevenDaysAgo = (new DateTime('now', $tz))->modify('-7 days')->format('Y-m-d');
        $twentyEightDaysAgo = (new DateTime('now', $tz))->modify('-28 days')->format('Y-m-d');

        // Получаем все тренировки за 28 дней из обоих источников
        $sql = "(SELECT training_date AS date, distance_km, duration_minutes, rating
                 FROM workout_log
                 WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?)
                UNION ALL
                (SELECT DATE(start_time) AS date, distance_km, duration_minutes, NULL AS rating
                 FROM workouts
                 WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
                   AND NOT EXISTS (
                       SELECT 1 FROM workout_log wl
                       WHERE wl.user_id = workouts.user_id AND wl.training_date = DATE(workouts.start_time) AND wl.is_completed = 1
                   ))
                ORDER BY date DESC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['acwr' => null, 'acute' => 0, 'chronic' => 0, 'zone' => 'unknown'];
        }

        $stmt->bind_param('ississ', $userId, $twentyEightDaysAgo, $today, $userId, $twentyEightDaysAgo, $today);
        $stmt->execute();
        $result = $stmt->get_result();

        $acuteLoad = 0;
        $chronicLoad = 0;

        while ($row = $result->fetch_assoc()) {
            $date = $row['date'];
            $dist = (float)($row['distance_km'] ?? 0);
            $dur = (int)($row['duration_minutes'] ?? 0);
            $rating = $row['rating'] !== null ? (int)$row['rating'] : null;

            // sRPE: duration × intensity_factor
            // rating 1 (очень тяжело) → factor 1.0, rating 5 (отлично) → factor 0.2
            if ($dur > 0 && $rating !== null && $rating >= 1 && $rating <= 5) {
                $intensityFactor = (6 - $rating) / 5; // 1→1.0, 2→0.8, 3→0.6, 4→0.4, 5→0.2
                $load = $dur * $intensityFactor;
            } elseif ($dist > 0) {
                // Proxy: дистанция × 6 (среднее ~6 мин/км)
                $load = $dist * 6;
            } else {
                continue;
            }

            $chronicLoad += $load;
            if ($date >= $sevenDaysAgo) {
                $acuteLoad += $load;
            }
        }
        $stmt->close();

        // Chronic = среднее за 4 недели (нормируем на 1 неделю)
        $chronicWeekly = $chronicLoad / 4;
        $acwr = $chronicWeekly > 0 ? round($acuteLoad / $chronicWeekly, 2) : null;

        // Определяем зону
        $zone = 'unknown';
        if ($acwr !== null) {
            if ($acwr < 0.8) $zone = 'low'; // Недотренированность
            elseif ($acwr <= 1.3) $zone = 'optimal'; // Безопасная зона
            elseif ($acwr <= 1.5) $zone = 'caution'; // Предупреждение
            else $zone = 'danger'; // Высокий риск травмы
        }

        return [
            'acwr' => $acwr,
            'acute' => round($acuteLoad, 1),
            'chronic' => round($chronicWeekly, 1),
            'zone' => $zone,
        ];
    }

    /**
     * План vs факт по дням текущей недели (пн-вс): краткие строки для контекста.
     */
    private function getWeekDayByDayComparison(int $userId): array {
        $tz = $this->getUserTz($userId);
        $today = new DateTime('now', $tz);
        $monday = (clone $today)->modify('monday this week');
        $sunday = (clone $today)->modify('sunday this week');

        $from = $monday->format('Y-m-d');
        $to = $sunday->format('Y-m-d');

        $plan = [];
        $stmt = $this->db->prepare("SELECT date, type, description FROM training_plan_days WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date");
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $plan[$row['date']] = $row;
            $stmt->close();
        }

        $actual = [];
        $sql = "(SELECT training_date AS d, distance_km FROM workout_log WHERE user_id = ? AND training_date BETWEEN ? AND ? AND is_completed = 1)
                UNION ALL
                (SELECT DATE(start_time) AS d, distance_km FROM workouts WHERE user_id = ? AND DATE(start_time) BETWEEN ? AND ?)
                ORDER BY d";
        $stmt2 = $this->db->prepare($sql);
        if ($stmt2) {
            $stmt2->bind_param('ississ', $userId, $from, $to, $userId, $from, $to);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) {
                $d = $row['d'];
                if (!isset($actual[$d])) $actual[$d] = [];
                $actual[$d][] = $row;
            }
            $stmt2->close();
        }

        $dows = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $lines = [];
        $cursor = clone $monday;
        for ($i = 0; $i < 7; $i++) {
            $d = $cursor->format('Y-m-d');
            $dow = $dows[$i];
            $p = $plan[$d] ?? null;
            $a = $actual[$d] ?? [];

            $planStr = $p ? ($p['type'] ?? '?') : '-';
            if ($d > $today->format('Y-m-d')) {
                $lines[] = "{$dow}: план={$planStr}";
            } elseif (empty($a)) {
                $lines[] = "{$dow}: план={$planStr}, факт=пропуск";
            } else {
                $totalKm = array_sum(array_column($a, 'distance_km'));
                $lines[] = "{$dow}: план={$planStr}, факт=" . round($totalKm, 1) . "км";
            }
            $cursor->modify('+1 day');
        }
        return $lines;
    }

    /**
     * Выполнение плана за последние 2 недели: сколько тренировок запланировано vs выполнено.
     */
    public function getWeeklyCompliance(int $userId): array {
        $tz = $this->getUserTz($userId);
        $twoWeeksAgo = (new DateTime('now', $tz))->modify('-14 days')->format('Y-m-d');
        $today = (new DateTime('now', $tz))->format('Y-m-d');

        $planned = 0;
        $stmtPlan = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM training_plan_days d 
             JOIN training_plan_weeks w ON d.week_id = w.id 
             WHERE w.user_id = ? AND d.date >= ? AND d.date <= ? AND d.type != 'rest'"
        );
        if ($stmtPlan) {
            $stmtPlan->bind_param('iss', $userId, $twoWeeksAgo, $today);
            $stmtPlan->execute();
            $row = $stmtPlan->get_result()->fetch_assoc();
            $planned = (int) ($row['cnt'] ?? 0);
            $stmtPlan->close();
        }

        $completed = 0;
        $stmtDone = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM workout_log 
             WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?"
        );
        if ($stmtDone) {
            $stmtDone->bind_param('iss', $userId, $twoWeeksAgo, $today);
            $stmtDone->execute();
            $row = $stmtDone->get_result()->fetch_assoc();
            $completed = (int) ($row['cnt'] ?? 0);
            $stmtDone->close();
        }

        $stmtW = $this->db->prepare(
            "SELECT COUNT(DISTINCT DATE(start_time)) as cnt FROM workouts
             WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM workout_log wl
                   WHERE wl.user_id = workouts.user_id AND wl.training_date = DATE(workouts.start_time) AND wl.is_completed = 1
               )"
        );
        if ($stmtW) {
            $stmtW->bind_param('iss', $userId, $twoWeeksAgo, $today);
            $stmtW->execute();
            $rowW = $stmtW->get_result()->fetch_assoc();
            $completed += (int)($rowW['cnt'] ?? 0);
            $stmtW->close();
        }

        return [
            'planned' => $planned,
            'completed' => $completed,
            'missed' => max(0, $planned - $completed),
        ];
    }

    /**
     * Тренд нагрузки: процент изменения км этой недели vs прошлой.
     */
    private function getLoadTrend(int $userId): ?int {
        $tz = $this->getUserTz($userId);
        $thisMonday = (new DateTime('now', $tz))->modify('monday this week')->format('Y-m-d');
        $thisSunday = (new DateTime('now', $tz))->modify('sunday this week')->format('Y-m-d');
        $lastMonday = (new DateTime('now', $tz))->modify('monday last week')->format('Y-m-d');
        $lastSunday = (new DateTime('now', $tz))->modify('sunday last week')->format('Y-m-d');

        $getKm = function (string $from, string $to) use ($userId): float {
            $km = 0.0;
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(distance_km), 0) as km FROM workout_log 
                 WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?"
            );
            if ($stmt) {
                $stmt->bind_param('iss', $userId, $from, $to);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $km += (float)($row['km'] ?? 0);
                $stmt->close();
            }
            $stmt2 = $this->db->prepare(
                "SELECT COALESCE(SUM(distance_km), 0) as km FROM workouts
                 WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
                   AND NOT EXISTS (
                       SELECT 1 FROM workout_log wl
                       WHERE wl.user_id = workouts.user_id AND wl.training_date = DATE(workouts.start_time) AND wl.is_completed = 1
                   )"
            );
            if ($stmt2) {
                $stmt2->bind_param('iss', $userId, $from, $to);
                $stmt2->execute();
                $row2 = $stmt2->get_result()->fetch_assoc();
                $km += (float)($row2['km'] ?? 0);
                $stmt2->close();
            }
            return $km;
        };

        $lastKm = $getKm($lastMonday, $lastSunday);
        $thisKm = $getKm($thisMonday, $thisSunday);

        if ($lastKm < 1) return null;
        return (int) round((($thisKm - $lastKm) / $lastKm) * 100);
    }

    private static array $RUNNING_PLAN_TYPES = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race'];

    /**
     * Количество тренировок и км за текущую неделю с разбивкой по типу активности.
     */
    private function getThisWeekWorkoutCount(int $userId): array {
        $tz = $this->getUserTz($userId);
        $monday = (new DateTime('now', $tz))->modify('monday this week')->format('Y-m-d');
        $sunday = (new DateTime('now', $tz))->modify('sunday this week')->format('Y-m-d');

        $byType = [];

        $stmt = $this->db->prepare(
            "SELECT COALESCE(NULLIF(TRIM(activity_type COLLATE utf8mb4_unicode_ci), ''), 'running') AS atype,
                    COUNT(*) as cnt, COALESCE(SUM(distance_km), 0) as km
             FROM workouts
             WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
             GROUP BY atype"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $monday, $sunday);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $t = $row['atype'];
                $byType[$t] = [
                    'count' => (int)$row['cnt'],
                    'km' => round((float)$row['km'], 1),
                ];
            }
            $stmt->close();
        }

        $cntLog = 0; $kmLog = 0.0;
        $stmt2 = $this->db->prepare(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(distance_km), 0) as km
             FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM workouts w
                   WHERE w.user_id = workout_log.user_id AND DATE(w.start_time) = workout_log.training_date
               )"
        );
        if ($stmt2) {
            $stmt2->bind_param('iss', $userId, $monday, $sunday);
            $stmt2->execute();
            $row2 = $stmt2->get_result()->fetch_assoc();
            $cntLog = (int)($row2['cnt'] ?? 0);
            $kmLog = (float)($row2['km'] ?? 0);
            $stmt2->close();
        }

        $totalCnt = $cntLog;
        $totalKm = $kmLog;
        foreach ($byType as $d) {
            $totalCnt += $d['count'];
            $totalKm += $d['km'];
        }

        return [
            'completed' => $totalCnt,
            'total_km' => round($totalKm, 1),
            'by_type' => $byType,
        ];
    }

    /**
     * Сравнение плана и факта за текущую неделю: какие тренировки совпали по типу, какие нет.
     */
    private function getThisWeekPlanVsActual(int $userId): array {
        $tz = $this->getUserTz($userId);
        $monday = (new DateTime('now', $tz))->modify('monday this week')->format('Y-m-d');
        $sunday = (new DateTime('now', $tz))->modify('sunday this week')->format('Y-m-d');
        $today = (new DateTime('now', $tz))->format('Y-m-d');

        $planned = [];
        $stmt = $this->db->prepare(
            "SELECT d.date, d.type FROM training_plan_days d
             JOIN training_plan_weeks w ON d.week_id = w.id
             WHERE w.user_id = ? AND d.date >= ? AND d.date <= ? AND d.date <= ?
             ORDER BY d.date"
        );
        if ($stmt) {
            $stmt->bind_param('isss', $userId, $monday, $sunday, $today);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $planned[$row['date']] = $row['type'];
            }
            $stmt->close();
        }

        $actual = [];
        $stmt = $this->db->prepare(
            "SELECT DATE(start_time) as d, activity_type COLLATE utf8mb4_unicode_ci as activity_type, distance_km
             FROM workouts WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
             ORDER BY start_time"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $monday, $sunday);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $actual[$row['d']][] = $row;
            }
            $stmt->close();
        }

        $logDates = [];
        $stmt = $this->db->prepare(
            "SELECT training_date FROM workout_log
             WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $monday, $sunday);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $logDates[$row['training_date']] = true;
            }
            $stmt->close();
        }

        $match = 0;
        $mismatch = 0;
        $missing = 0;
        $mismatches = [];

        foreach ($planned as $date => $planType) {
            if ($planType === 'rest' || $planType === 'free') continue;

            $hasLog = isset($logDates[$date]);
            $hasWorkout = isset($actual[$date]);

            if (!$hasLog && !$hasWorkout) {
                $missing++;
                continue;
            }

            if (in_array($planType, self::$RUNNING_PLAN_TYPES) && $hasWorkout) {
                $hasRunning = false;
                $actualTypes = [];
                foreach ($actual[$date] as $w) {
                    $actualTypes[] = $w['activity_type'];
                    if ($w['activity_type'] === 'running') $hasRunning = true;
                }
                if ($hasRunning) {
                    $match++;
                } else {
                    $mismatch++;
                    $mismatches[] = [
                        'date' => $date,
                        'planned' => $planType,
                        'actual' => implode(', ', array_unique($actualTypes)),
                    ];
                }
            } else {
                $match++;
            }
        }

        return [
            'match' => $match,
            'mismatch' => $mismatch,
            'missing' => $missing,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * Загружает детали конкретного дня: план + упражнения + результат.
     * Используется tool get_day_details.
     */
    public function getDayDetails(int $userId, string $date): array {
        $result = ['date' => $date, 'plan' => null, 'exercises' => [], 'workout' => null];

        // Запланированные данные
        $stmt = $this->db->prepare(
            "SELECT id, type, description, is_key_workout, day_of_week 
             FROM training_plan_days 
             WHERE user_id = ? AND date = ? 
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('is', $userId, $date);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $result['plan'] = [
                    'type' => $row['type'],
                    'type_ru' => $this->getDayTypeRu($row['type']),
                    'description' => $row['description'],
                    'is_key_workout' => (bool) $row['is_key_workout'],
                ];

                $dayId = (int) $row['id'];
                $exStmt = $this->db->prepare(
                    "SELECT category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes
                     FROM training_day_exercises 
                     WHERE user_id = ? AND plan_day_id = ?
                     ORDER BY order_index"
                );
                if ($exStmt) {
                    $exStmt->bind_param('ii', $userId, $dayId);
                    $exStmt->execute();
                    $exResult = $exStmt->get_result();
                    while ($ex = $exResult->fetch_assoc()) {
                        $result['exercises'][] = $ex;
                    }
                    $exStmt->close();
                }
            }
        }

        // Фактические результаты из workout_log (ручные записи)
        $allWorkouts = [];
        $stmt = $this->db->prepare(
            "SELECT distance_km, result_time, pace, duration_minutes, avg_heart_rate, max_heart_rate,
                    notes, rating, is_completed, avg_cadence, elevation_gain, calories, 'manual' AS source
             FROM workout_log
             WHERE user_id = ? AND training_date = ? AND is_completed = 1
             ORDER BY id DESC"
        );
        if ($stmt) {
            $stmt->bind_param('is', $userId, $date);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $allWorkouts[] = $row;
            }
            $stmt->close();
        }

        // Результаты из таблицы workouts (импорт/Strava) — avg_cadence вычисляется из timeline
        $stmtW = $this->db->prepare(
            "SELECT w.id AS workout_id, w.distance_km, NULL AS result_time,
                    w.avg_pace COLLATE utf8mb4_unicode_ci AS pace, w.duration_minutes,
                    w.avg_heart_rate, w.max_heart_rate, NULL AS notes, NULL AS rating,
                    1 AS is_completed, w.elevation_gain, w.calories,
                    COALESCE(w.source, 'import') COLLATE utf8mb4_unicode_ci AS source,
                    w.activity_type COLLATE utf8mb4_unicode_ci AS activity_type,
                    (SELECT ROUND(AVG(wt.cadence)) FROM workout_timeline wt
                     WHERE wt.workout_id = w.id AND wt.cadence IS NOT NULL AND wt.cadence > 0
                    ) AS avg_cadence
             FROM workouts w
             WHERE w.user_id = ? AND DATE(w.start_time) = ?
             ORDER BY (w.activity_type = 'running') DESC, w.start_time ASC"
        );
        if ($stmtW) {
            $stmtW->bind_param('is', $userId, $date);
            $stmtW->execute();
            $resW = $stmtW->get_result();
            while ($rowW = $resW->fetch_assoc()) {
                // Сохраняем id для прямого выбора тренировки (workout_id → id)
                if (isset($rowW['workout_id'])) {
                    $rowW['id'] = (int) $rowW['workout_id'];
                    unset($rowW['workout_id']);
                }
                $allWorkouts[] = $rowW;
            }
            $stmtW->close();
        }

        if (count($allWorkouts) === 1) {
            $result['workout'] = $allWorkouts[0];
        } elseif (count($allWorkouts) > 1) {
            // Несколько тренировок за день — возвращаем массив
            $result['workouts'] = $allWorkouts;
            // Для обратной совместимости: workout = основная (бег > остальные, затем по дистанции)
            usort($allWorkouts, function($a, $b) {
                $aRun = ($a['activity_type'] ?? '') === 'running' ? 1 : 0;
                $bRun = ($b['activity_type'] ?? '') === 'running' ? 1 : 0;
                if ($aRun !== $bRun) return $bRun <=> $aRun;
                return ((float)($b['distance_km'] ?? 0)) <=> ((float)($a['distance_km'] ?? 0));
            });
            $result['workout'] = $allWorkouts[0];
        }

        return $result;
    }

    /**
     * Загружает историю тренировок за период.
     * Используется tool get_workouts.
     */
    public function getWorkoutsHistory(int $userId, string $dateFrom, string $dateTo, int $limit = 30): array {
        $sql = "(SELECT 
                    wl.training_date AS date,
                    wl.distance_km,
                    wl.result_time,
                    wl.pace,
                    wl.duration_minutes,
                    wl.avg_heart_rate,
                    wl.notes,
                    wl.rating,
                    tpd.type AS plan_type,
                    tpd.description AS plan_description,
                    tpd.is_key_workout,
                    LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) COLLATE utf8mb4_unicode_ci AS activity_type,
                    'manual' AS source
                FROM workout_log wl
                LEFT JOIN activity_types at ON at.id = wl.activity_type_id
                LEFT JOIN training_plan_days tpd 
                    ON tpd.id = (
                        SELECT id FROM training_plan_days
                        WHERE user_id = wl.user_id AND date = wl.training_date
                        ORDER BY id DESC LIMIT 1
                    )
                WHERE wl.user_id = ? 
                    AND wl.is_completed = 1
                    AND wl.training_date >= ?
                    AND wl.training_date <= ?)
                UNION ALL
                (SELECT 
                    DATE(w.start_time) AS date,
                    w.distance_km,
                    NULL AS result_time,
                    w.avg_pace COLLATE utf8mb4_unicode_ci AS pace,
                    w.duration_minutes,
                    w.avg_heart_rate,
                    NULL AS notes,
                    NULL AS rating,
                    COALESCE(tpd.type COLLATE utf8mb4_unicode_ci, w.activity_type COLLATE utf8mb4_unicode_ci) AS plan_type,
                    COALESCE(
                        tpd.description COLLATE utf8mb4_unicode_ci,
                        CONCAT(w.activity_type, ' ', COALESCE(w.distance_km, 0), ' км') COLLATE utf8mb4_unicode_ci
                    ) AS plan_description,
                    COALESCE(tpd.is_key_workout, 0) AS is_key_workout,
                    LOWER(COALESCE(NULLIF(TRIM(w.activity_type), ''), 'running')) COLLATE utf8mb4_unicode_ci AS activity_type,
                    COALESCE(w.source, 'import') COLLATE utf8mb4_unicode_ci AS source
                FROM workouts w
                LEFT JOIN training_plan_days tpd
                    ON tpd.id = (
                        SELECT id FROM training_plan_days
                        WHERE user_id = w.user_id AND date = DATE(w.start_time)
                        ORDER BY id DESC LIMIT 1
                    )
                WHERE w.user_id = ?
                    AND DATE(w.start_time) >= ?
                    AND DATE(w.start_time) <= ?)
                ORDER BY date ASC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ississi', $userId, $dateFrom, $dateTo, $userId, $dateFrom, $dateTo, $limit);
        if (!$stmt->execute()) {
            return [];
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
