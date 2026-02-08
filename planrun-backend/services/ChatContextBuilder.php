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
     * Собрать полный контекст пользователя для AI
     */
    public function buildContextForUser(int $userId): string {
        $user = getUserData($userId, null, false);
        $plan = loadTrainingPlanForUser($userId, false);
        $stats = $this->getStats($userId);

        $parts = [];
        $parts[] = $this->formatProfile($user);
        $parts[] = $this->formatPlanSummary($plan, $userId);
        $parts[] = $this->formatStats($stats);

        return implode("\n\n", array_filter($parts));
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
                if (!empty($user['race_target_time'])) $lines[] = "Целевое время: {$user['race_target_time']}";
                break;
            case 'time_improvement':
                $lines[] = "Цель: Улучшение времени";
                if (!empty($user['target_marathon_date'])) $lines[] = "Дата марафона: {$user['target_marathon_date']}";
                if (!empty($user['target_marathon_time'])) $lines[] = "Целевое время: {$user['target_marathon_time']}";
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

    private function formatPlanSummary(array $plan, int $userId): string {
        if (empty($plan['phases']) || !is_array($plan['phases'])) {
            return "═══ ПЛАН ═══\nПлан тренировок пока не создан.";
        }

        $lines = ["═══ ТЕКУЩИЙ ПЛАН ═══"];

        $today = new DateTime();
        $today->setTime(0, 0, 0);

        foreach ($plan['phases'] as $phase) {
            $weeks = $phase['weeks_data'] ?? [];
            foreach ($weeks as $week) {
                $startDate = $week['start_date'] ?? null;
                if (!$startDate) continue;

                $start = new DateTime($startDate);
                $start->setTime(0, 0, 0);
                $end = clone $start;
                $end->modify('+6 days');

                if ($today >= $start && $today <= $end) {
                    $lines[] = "Текущая неделя: №{$week['number']}";
                    if (!empty($week['total_volume'])) {
                        $lines[] = "Объём недели: {$week['total_volume']}";
                    }
                    $days = $week['days'] ?? [];
                    $dayLabels = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
                    foreach ($days as $dayName => $dayData) {
                        if ($dayData && is_array($dayData)) {
                            $type = $dayData['type'] ?? 'rest';
                            $text = $dayData['text'] ?? '';
                            $typeRu = $this->getDayTypeRu($type);
                            $lines[] = "  {$dayLabels[$dayName]}: {$typeRu}" . ($text ? " — {$text}" : '');
                        }
                    }
                    return implode("\n", $lines);
                }
            }
        }

        $lines[] = "Текущая неделя не найдена в плане. План включает " . count($plan['phases'][0]['weeks_data'] ?? []) . " недель.";
        return implode("\n", $lines);
    }

    private function getDayTypeRu(string $type): string {
        $map = [
            'easy' => 'Легкий бег',
            'long' => 'Длительный',
            'tempo' => 'Темповый',
            'interval' => 'Интервалы',
            'fartlek' => 'Фартлек',
            'rest' => 'Отдых',
            'ofp' => 'ОФП',
            'race' => 'Забег'
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
}
