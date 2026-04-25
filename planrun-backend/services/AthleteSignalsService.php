<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/PostWorkoutFollowupService.php';

class AthleteSignalsService extends BaseService {
    private PostWorkoutFollowupService $followupService;

    public function __construct(mysqli $db) {
        parent::__construct($db);
        $this->followupService = new PostWorkoutFollowupService($db);
    }

    public function getRecentSignalsSummary(int $userId, int $days = 14, ?string $endDate = null): array {
        $windowDays = max(1, $days);
        $effectiveEndDate = $this->isValidDate((string) $endDate) ? (string) $endDate : gmdate('Y-m-d');
        $end = DateTime::createFromFormat('Y-m-d', $effectiveEndDate) ?: new DateTime('now');
        $start = (clone $end)->modify('-' . max(0, $windowDays - 1) . ' days');

        return $this->getSignalsBetween($userId, $start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public function getSignalsBetween(int $userId, string $startDate, string $endDate): array {
        if ($userId <= 0 || !$this->isValidDate($startDate) || !$this->isValidDate($endDate) || $startDate > $endDate) {
            return $this->buildEmptySignalsSummary($startDate, $endDate);
        }

        $feedback = $this->followupService->getFeedbackAnalyticsBetween($userId, $startDate, $endDate);
        $notes = $this->getAthleteNotesBetween($userId, $startDate, $endDate);
        $noteMetrics = $this->buildNoteMetrics($notes);

        return $this->mergeSignalSummaries($feedback, $noteMetrics, $startDate, $endDate);
    }

    private function getAthleteNotesBetween(int $userId, string $startDate, string $endDate): array {
        $weekStartWindow = date('Y-m-d', strtotime($startDate . ' -6 days'));
        $notes = [];

        $dayStmt = $this->db->prepare(
            "SELECT id, 'day' AS scope, date AS target_date, content, created_at
             FROM plan_day_notes
             WHERE user_id = ?
               AND author_id = ?
               AND date BETWEEN ? AND ?
               AND content NOT LIKE 'Самочувствие после тренировки:%'
             ORDER BY date DESC, id DESC
             LIMIT 20"
        );
        if ($dayStmt) {
            $dayStmt->bind_param('iiss', $userId, $userId, $startDate, $endDate);
            if ($dayStmt->execute()) {
                $result = $dayStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $notes[] = $row;
                }
            }
            $dayStmt->close();
        }

        $weekStmt = $this->db->prepare(
            "SELECT id, 'week' AS scope, week_start AS target_date, content, created_at
             FROM plan_week_notes
             WHERE user_id = ?
               AND author_id = ?
               AND week_start BETWEEN ? AND ?
             ORDER BY week_start DESC, id DESC
             LIMIT 12"
        );
        if ($weekStmt) {
            $weekStmt->bind_param('iiss', $userId, $userId, $weekStartWindow, $endDate);
            if ($weekStmt->execute()) {
                $result = $weekStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $notes[] = $row;
                }
            }
            $weekStmt->close();
        }

        usort($notes, static function (array $left, array $right): int {
            $leftKey = ($left['target_date'] ?? '') . ' ' . ($left['created_at'] ?? '');
            $rightKey = ($right['target_date'] ?? '') . ' ' . ($right['created_at'] ?? '');
            return strcmp($rightKey, $leftKey);
        });

        return $notes;
    }

    private function buildNoteMetrics(array $notes): array {
        $metrics = [
            'total_notes_count' => 0,
            'day_notes_count' => 0,
            'week_notes_count' => 0,
            'note_pain_count' => 0,
            'note_fatigue_count' => 0,
            'note_sleep_count' => 0,
            'note_illness_count' => 0,
            'note_stress_count' => 0,
            'note_travel_count' => 0,
            'note_positive_count' => 0,
            'note_recovery_count' => 0,
            'note_risk_score' => 0.0,
            'note_risk_level' => 'low',
            'recent_note_excerpts' => [],
            'highlights' => [],
            'planning_biases' => [],
            'has_note_pain_signal' => false,
            'has_note_fatigue_signal' => false,
            'has_note_sleep_signal' => false,
            'has_note_illness_signal' => false,
            'has_note_stress_signal' => false,
            'has_note_travel_signal' => false,
        ];

        if ($notes === []) {
            return $metrics;
        }

        $highlights = [];
        $planningBiases = [];
        $riskScore = 0.0;

        foreach ($notes as $note) {
            $content = trim((string) ($note['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $scope = (string) ($note['scope'] ?? 'day');
            $metrics['total_notes_count']++;
            if ($scope === 'week') {
                $metrics['week_notes_count']++;
            } else {
                $metrics['day_notes_count']++;
            }

            $analysis = $this->analyzeNote($content);
            $riskScore += (float) ($analysis['risk_weight'] ?? 0.0);

            foreach ([
                'pain' => ['note_pain_count', 'has_note_pain_signal', 'protect_injury'],
                'fatigue' => ['note_fatigue_count', 'has_note_fatigue_signal', 'prefer_recovery'],
                'sleep' => ['note_sleep_count', 'has_note_sleep_signal', 'sleep_guard'],
                'illness' => ['note_illness_count', 'has_note_illness_signal', 'illness_guard'],
                'stress' => ['note_stress_count', 'has_note_stress_signal', 'stress_guard'],
                'travel' => ['note_travel_count', 'has_note_travel_signal', 'travel_guard'],
                'positive' => ['note_positive_count', null, null],
                'recovery' => ['note_recovery_count', null, 'prefer_recovery'],
            ] as $signalKey => [$countKey, $flagKey, $biasKey]) {
                if (empty($analysis[$signalKey])) {
                    continue;
                }
                $metrics[$countKey]++;
                if ($flagKey !== null) {
                    $metrics[$flagKey] = true;
                }
                if ($biasKey !== null) {
                    $planningBiases[] = $biasKey;
                }
            }

            $targetDate = (string) ($note['target_date'] ?? '');
            $prefix = $scope === 'week' ? 'Неделя' : 'День';
            $excerpt = $prefix . ' ' . $targetDate . ': ' . $this->trimExcerpt($content);
            $metrics['recent_note_excerpts'][] = $excerpt;

            foreach ((array) ($analysis['highlights'] ?? []) as $highlight) {
                $highlights[] = $highlight;
            }
        }

        $metrics['recent_note_excerpts'] = array_slice($metrics['recent_note_excerpts'], 0, 4);
        $metrics['highlights'] = array_values(array_unique(array_slice($highlights, 0, 6)));
        $metrics['planning_biases'] = array_values(array_unique($planningBiases));
        $metrics['note_risk_score'] = round(min(1.0, $riskScore), 2);
        $metrics['note_risk_level'] = $this->resolveRiskLevel(
            $metrics['note_risk_score'],
            $metrics['has_note_illness_signal'],
            $metrics['has_note_pain_signal']
        );

        return $metrics;
    }

    private function analyzeNote(string $content): array {
        $normalized = mb_strtolower($content, 'UTF-8');

        $pain = (bool) preg_match('/бол(ь|ит|ело)|тянет|прострел|ноет|ахилл|голен|икр|колен|стоп|спин|поясниц|дискомфорт|неприятн/u', $normalized);
        $fatigue = (bool) preg_match('/устал|разбит|тяжел|тяжко|забил|перегруз|не восстанов|не восстановился|самочувствие так себе|ноги тяж/u', $normalized);
        $sleep = (bool) preg_match('/сон|не высп|невысп|плохо спал|мало спал|бессон|не спал/u', $normalized);
        $illness = (bool) preg_match('/простуд|болею|заболел|температур|кашел|насморк|орви|грипп|covid|болезн/u', $normalized);
        $stress = (bool) preg_match('/стресс|нерв|нервн|завал|замотан|напряж|тяжелая неделя|тяжёлая неделя|переработ/u', $normalized);
        $travel = (bool) preg_match('/поездк|командиров|дорог|перелет|перелёт|джетлаг|jet lag|смена часового пояса/u', $normalized);
        $positive = (bool) preg_match('/легко|хорош|отлич|нормально|свеж|все ок|всё ок|комфорт/u', $normalized);
        $recovery = (bool) preg_match('/восстанов|отдых|разгруз/u', $normalized);

        $riskWeight = 0.0;
        if ($illness) {
            $riskWeight += 0.70;
        }
        if ($pain) {
            $riskWeight += 0.55;
        }
        if ($fatigue) {
            $riskWeight += 0.30;
        }
        if ($sleep) {
            $riskWeight += 0.22;
        }
        if ($stress) {
            $riskWeight += 0.20;
        }
        if ($travel) {
            $riskWeight += 0.18;
        }
        if ($positive && !$pain && !$illness) {
            $riskWeight -= 0.08;
        }

        $highlights = [];
        if ($illness) {
            $highlights[] = 'Есть упоминание болезни или простуды';
        }
        if ($pain) {
            $highlights[] = 'Есть заметка о боли или локальном дискомфорте';
        }
        if ($sleep) {
            $highlights[] = 'Есть сигнал про плохой сон';
        }
        if ($stress) {
            $highlights[] = 'Есть сигнал про стресс или сильную занятость';
        }
        if ($travel) {
            $highlights[] = 'Есть контекст поездки или смены режима';
        }

        return [
            'pain' => $pain,
            'fatigue' => $fatigue,
            'sleep' => $sleep,
            'illness' => $illness,
            'stress' => $stress,
            'travel' => $travel,
            'positive' => $positive,
            'recovery' => $recovery,
            'risk_weight' => max(0.0, round($riskWeight, 2)),
            'highlights' => $highlights,
        ];
    }

    private function mergeSignalSummaries(array $feedback, array $noteMetrics, string $startDate, string $endDate): array {
        $overallRiskScore = max(
            (float) ($feedback['recent_average_recovery_risk'] ?? 0.0),
            (float) ($feedback['max_recovery_risk'] ?? 0.0),
            (float) ($noteMetrics['note_risk_score'] ?? 0.0)
        );

        $overallRiskLevel = $this->resolveRiskLevel(
            $overallRiskScore,
            !empty($feedback['has_recent_pain']) || !empty($noteMetrics['has_note_pain_signal']),
            !empty($noteMetrics['has_note_illness_signal'])
        );

        $highlights = array_values(array_unique(array_filter(array_merge(
            (array) ($noteMetrics['highlights'] ?? []),
            $this->buildFeedbackHighlights($feedback)
        ))));

        $planningBiases = array_values(array_unique(array_merge(
            (array) ($noteMetrics['planning_biases'] ?? []),
            $this->buildFeedbackBiases($feedback)
        )));

        return [
            'window_start' => $startDate,
            'window_end' => $endDate,
            'feedback' => $feedback,
            'day_notes_count' => (int) ($noteMetrics['day_notes_count'] ?? 0),
            'week_notes_count' => (int) ($noteMetrics['week_notes_count'] ?? 0),
            'total_notes_count' => (int) ($noteMetrics['total_notes_count'] ?? 0),
            'note_pain_count' => (int) ($noteMetrics['note_pain_count'] ?? 0),
            'note_fatigue_count' => (int) ($noteMetrics['note_fatigue_count'] ?? 0),
            'note_sleep_count' => (int) ($noteMetrics['note_sleep_count'] ?? 0),
            'note_illness_count' => (int) ($noteMetrics['note_illness_count'] ?? 0),
            'note_stress_count' => (int) ($noteMetrics['note_stress_count'] ?? 0),
            'note_travel_count' => (int) ($noteMetrics['note_travel_count'] ?? 0),
            'note_positive_count' => (int) ($noteMetrics['note_positive_count'] ?? 0),
            'note_recovery_count' => (int) ($noteMetrics['note_recovery_count'] ?? 0),
            'note_risk_score' => (float) ($noteMetrics['note_risk_score'] ?? 0.0),
            'note_risk_level' => (string) ($noteMetrics['note_risk_level'] ?? 'low'),
            'overall_risk_score' => round($overallRiskScore, 2),
            'overall_risk_level' => $overallRiskLevel,
            'has_note_pain_signal' => !empty($noteMetrics['has_note_pain_signal']),
            'has_note_fatigue_signal' => !empty($noteMetrics['has_note_fatigue_signal']),
            'has_note_sleep_signal' => !empty($noteMetrics['has_note_sleep_signal']),
            'has_note_illness_signal' => !empty($noteMetrics['has_note_illness_signal']),
            'has_note_stress_signal' => !empty($noteMetrics['has_note_stress_signal']),
            'has_note_travel_signal' => !empty($noteMetrics['has_note_travel_signal']),
            'highlights' => array_slice($highlights, 0, 6),
            'planning_biases' => $planningBiases,
            'recent_note_excerpts' => array_slice((array) ($noteMetrics['recent_note_excerpts'] ?? []), 0, 4),
            'prompt_summary' => $this->buildPromptSummary($feedback, $noteMetrics, $overallRiskLevel),
        ];
    }

    private function buildFeedbackHighlights(array $feedback): array {
        $highlights = [];
        if (!empty($feedback['has_recent_pain'])) {
            $highlights[] = 'В recent post-workout feedback был болевой сигнал';
        }
        if (!empty($feedback['has_recent_fatigue'])) {
            $highlights[] = 'В recent post-workout feedback есть накопленная усталость';
        }
        if ((float) ($feedback['subjective_load_delta'] ?? 0.0) >= 0.75) {
            $highlights[] = 'Есть всплеск subjective load относительно личного baseline';
        }
        return $highlights;
    }

    private function buildFeedbackBiases(array $feedback): array {
        $biases = [];
        if (!empty($feedback['has_recent_pain'])) {
            $biases[] = 'protect_injury';
        }
        if (!empty($feedback['has_recent_fatigue']) || (float) ($feedback['subjective_load_delta'] ?? 0.0) >= 0.45) {
            $biases[] = 'prefer_recovery';
        }
        return $biases;
    }

    private function buildPromptSummary(array $feedback, array $noteMetrics, string $overallRiskLevel): string {
        $parts = [];

        $feedbackResponses = (int) ($feedback['total_responses'] ?? 0);
        if ($feedbackResponses > 0) {
            $parts[] = sprintf(
                'post-workout feedback: %d ответов, pain=%d, fatigue=%d, risk=%s',
                $feedbackResponses,
                (int) ($feedback['pain_count'] ?? 0),
                (int) ($feedback['fatigue_count'] ?? 0),
                (string) ($feedback['risk_level'] ?? 'low')
            );
        }

        $noteParts = [];
        foreach ([
            'pain' => (int) ($noteMetrics['note_pain_count'] ?? 0),
            'fatigue' => (int) ($noteMetrics['note_fatigue_count'] ?? 0),
            'sleep' => (int) ($noteMetrics['note_sleep_count'] ?? 0),
            'illness' => (int) ($noteMetrics['note_illness_count'] ?? 0),
            'stress' => (int) ($noteMetrics['note_stress_count'] ?? 0),
            'travel' => (int) ($noteMetrics['note_travel_count'] ?? 0),
        ] as $label => $count) {
            if ($count > 0) {
                $noteParts[] = $label . '=' . $count;
            }
        }

        if ($noteParts !== []) {
            $parts[] = 'notes: ' . implode(', ', $noteParts);
        }

        if ($parts === []) {
            return 'Сильных негативных athlete signals за окно не найдено.';
        }

        return implode('; ', $parts) . '; overall=' . $overallRiskLevel;
    }

    private function buildEmptySignalsSummary(string $startDate, string $endDate): array {
        return [
            'window_start' => $startDate,
            'window_end' => $endDate,
            'feedback' => [],
            'day_notes_count' => 0,
            'week_notes_count' => 0,
            'total_notes_count' => 0,
            'note_pain_count' => 0,
            'note_fatigue_count' => 0,
            'note_sleep_count' => 0,
            'note_illness_count' => 0,
            'note_stress_count' => 0,
            'note_travel_count' => 0,
            'note_positive_count' => 0,
            'note_recovery_count' => 0,
            'note_risk_score' => 0.0,
            'note_risk_level' => 'low',
            'overall_risk_score' => 0.0,
            'overall_risk_level' => 'low',
            'has_note_pain_signal' => false,
            'has_note_fatigue_signal' => false,
            'has_note_sleep_signal' => false,
            'has_note_illness_signal' => false,
            'has_note_stress_signal' => false,
            'has_note_travel_signal' => false,
            'highlights' => [],
            'planning_biases' => [],
            'recent_note_excerpts' => [],
            'prompt_summary' => 'Athlete signals пока не собраны.',
        ];
    }

    private function trimExcerpt(string $content, int $limit = 140): string {
        $clean = trim(preg_replace('/\s+/u', ' ', $content));
        if (mb_strlen($clean, 'UTF-8') <= $limit) {
            return $clean;
        }
        return rtrim(mb_substr($clean, 0, $limit - 1, 'UTF-8')) . '…';
    }

    private function resolveRiskLevel(float $riskScore, bool $hasPrimarySignal, bool $hasSecondarySignal): string {
        if ($hasPrimarySignal || $riskScore >= 0.75 || $hasSecondarySignal) {
            return 'high';
        }
        if ($riskScore >= 0.35) {
            return 'moderate';
        }
        return 'low';
    }

    private function isValidDate(string $date): bool {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }
}
