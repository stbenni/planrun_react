<?php
/**
 * WeeklyAdaptationEngine — еженедельная проверка план vs факт и адаптация.
 *
 * Запускается по cron (weekly_ai_review.php) каждое воскресенье.
 * Сравнивает плановые и фактические тренировки, определяет триггеры адаптации,
 * при необходимости пересчитывает оставшиеся недели через PlanSkeletonGenerator.
 */

require_once __DIR__ . '/../../prepare_weekly_analysis.php';
require_once __DIR__ . '/../../services/TrainingStateBuilder.php';
require_once __DIR__ . '/../../services/PostWorkoutFollowupService.php';
require_once __DIR__ . '/../../services/AthleteSignalsService.php';
require_once __DIR__ . '/../prompt_builder.php';

class WeeklyAdaptationEngine
{
    private mysqli $db;

    // Пороги для триггеров адаптации
    private const COMPLIANCE_LOW = 0.70;       // < 70% выполнения → снизить нагрузку
    private const COMPLIANCE_HIGH = 1.15;      // > 115% перевыполнение → можно поднять
    private const KEY_COMPLETION_LOW = 0.50;    // < 50% ключевых → упростить
    private const PACE_SLOW_THRESHOLD = 1.10;  // бежит на 10%+ медленнее → VDOT вниз
    private const PACE_FAST_THRESHOLD = 0.95;  // бежит на 5%+ быстрее → VDOT вверх
    private const CONSECUTIVE_LOW_WEEKS = 2;   // 2+ недели подряд < 70% → значительное снижение
    private const SKIP_DAYS_DETRAINING = 5;    // 5+ пропущенных дней → detraining

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Запустить еженедельный анализ и адаптацию.
     *
     * @return array {
     *   adapted: bool,
     *   adaptation_type: string|null,
     *   triggers: string[],
     *   metrics: array,
     *   review_message: string,
     *   goal_status: array|null
     * }
     */
    public function analyze(int $userId): array
    {
        // Шаг 1: Определяем текущую неделю
        $weekNumber = getCurrentWeekNumber($userId, $this->db);
        if ($weekNumber < 1) {
            return $this->noDataResult('Не найдена текущая неделя в плане');
        }

        // Шаг 2: Собираем данные прошедшей недели
        $analysis = prepareWeeklyAnalysis($userId, $weekNumber);

        // Шаг 3: Считаем метрики
        $metrics = $this->computeMetrics($userId, $analysis);

        // Шаг 4: Проверяем историю предыдущих недель (для consecutive low compliance)
        $recentHistory = $this->getRecentWeeksHistory($userId, $weekNumber, 3);

        // Шаг 5: Определяем триггеры
        $triggers = $this->detectTriggers($metrics, $recentHistory);

        // Шаг 6: Определяем тип адаптации
        $adaptationType = $this->decideAdaptation($triggers, $metrics);

        // Шаг 7: Оценка достижимости цели (для race/time_improvement)
        $goalStatus = $this->assessGoalProgress($userId, $analysis, $metrics);

        // Шаг 8: Генерация ревью-сообщения (через LLM или fallback)
        $reviewMessage = $this->generateReviewMessage($analysis, $metrics, $triggers, $adaptationType, $goalStatus);

        // Шаг 9: Если нужна адаптация — пересчитать план
        $adapted = false;
        if ($adaptationType !== null) {
            $adapted = $this->applyAdaptation($userId, $adaptationType, $metrics, $weekNumber);
        }

        return [
            'adapted' => $adapted,
            'adaptation_type' => $adaptationType,
            'triggers' => $triggers,
            'metrics' => $metrics,
            'review_message' => $reviewMessage,
            'goal_status' => $goalStatus,
            'week_number' => $weekNumber,
        ];
    }

    /**
     * Вычислить метрики за неделю из данных анализа.
     */
    private function computeMetrics(int $userId, array $analysis): array
    {
        $stats = $analysis['statistics'] ?? [];
        $week = $analysis['week'] ?? [];
        $days = $analysis['days'] ?? [];

        $plannedVolume = (float) ($week['planned_volume_km'] ?? 0);
        $actualVolume = (float) ($stats['actual_volume_km'] ?? 0);

        // Compliance = фактический объём / плановый
        $compliance = $plannedVolume > 0
            ? round($actualVolume / $plannedVolume, 2)
            : 1.0;

        // Ключевые тренировки
        $plannedKeyWorkouts = 0;
        $completedKeyWorkouts = 0;
        $skippedDays = 0;

        foreach ($days as $day) {
            $planned = $day['planned'] ?? null;
            if (!$planned) continue;

            $planType = $planned['type'] ?? 'rest';
            if ($planType === 'rest') continue;

            if (!$day['completed']) {
                $skippedDays++;
            }

            if (!empty($planned['is_key_workout'])) {
                $plannedKeyWorkouts++;
                if ($day['completed']) {
                    $completedKeyWorkouts++;
                }
            }
        }

        $keyCompletion = $plannedKeyWorkouts > 0
            ? round($completedKeyWorkouts / $plannedKeyWorkouts, 2)
            : 1.0;

        // Средний easy pace из фактических тренировок
        $actualEasyPaces = [];
        foreach ($days as $day) {
            $planned = $day['planned'] ?? null;
            $actuals = $day['actual'] ?? [];
            if (!$planned || ($planned['type'] ?? '') !== 'easy') continue;

            foreach ($actuals as $actual) {
                $paceSec = $this->parsePaceToSeconds($actual['pace'] ?? null);
                if ($paceSec > 0) {
                    $actualEasyPaces[] = $paceSec;
                }
            }
        }

        $avgActualEasyPaceSec = !empty($actualEasyPaces)
            ? (int) round(array_sum($actualEasyPaces) / count($actualEasyPaces))
            : null;

        $feedbackMetrics = $this->getSubjectiveFeedbackMetrics($userId, (string) ($week['start_date'] ?? ''));
        $athleteSignals = $this->getAthleteSignalsMetrics($userId, (string) ($week['start_date'] ?? ''));

        return [
            'planned_volume_km' => $plannedVolume,
            'actual_volume_km' => $actualVolume,
            'compliance' => $compliance,
            'planned_key_workouts' => $plannedKeyWorkouts,
            'completed_key_workouts' => $completedKeyWorkouts,
            'key_completion' => $keyCompletion,
            'skipped_days' => $skippedDays,
            'completed_days' => (int) ($stats['completed_days'] ?? 0),
            'planned_days' => (int) ($stats['planned_days'] ?? 0),
            'completion_rate' => (float) ($stats['completion_rate'] ?? 0),
            'avg_actual_easy_pace_sec' => $avgActualEasyPaceSec,
            'planned_easy_pace_sec' => isset($analysis['user']['easy_pace_sec']) ? (int) $analysis['user']['easy_pace_sec'] : null,
            'avg_heart_rate' => $stats['avg_heart_rate'] ?? null,
            'subjective_feedback_total' => (int) ($feedbackMetrics['total_responses'] ?? 0),
            'subjective_good_count' => (int) ($feedbackMetrics['good_count'] ?? 0),
            'subjective_fatigue_count' => (int) ($feedbackMetrics['fatigue_count'] ?? 0),
            'subjective_pain_count' => (int) ($feedbackMetrics['pain_count'] ?? 0),
            'subjective_recovery_risk' => (float) ($feedbackMetrics['recent_average_recovery_risk'] ?? 0.0),
            'subjective_risk_level' => (string) ($feedbackMetrics['risk_level'] ?? 'low'),
            'subjective_recent_session_rpe' => (float) ($feedbackMetrics['recent_session_rpe_avg'] ?? 0.0),
            'subjective_session_rpe_delta' => (float) ($feedbackMetrics['session_rpe_delta'] ?? 0.0),
            'subjective_recent_legs_score' => (float) ($feedbackMetrics['recent_legs_score_avg'] ?? 0.0),
            'subjective_recent_breath_score' => (float) ($feedbackMetrics['recent_breath_score_avg'] ?? 0.0),
            'subjective_recent_hr_strain_score' => (float) ($feedbackMetrics['recent_hr_strain_score_avg'] ?? 0.0),
            'subjective_recent_pain_score' => (float) ($feedbackMetrics['recent_pain_score_avg'] ?? 0.0),
            'subjective_pain_score_delta' => (float) ($feedbackMetrics['pain_score_delta'] ?? 0.0),
            'subjective_load_delta' => (float) ($feedbackMetrics['subjective_load_delta'] ?? 0.0),
            'athlete_signal_overall_risk_level' => (string) ($athleteSignals['overall_risk_level'] ?? 'low'),
            'athlete_signal_note_risk_level' => (string) ($athleteSignals['note_risk_level'] ?? 'low'),
            'athlete_signal_note_risk_score' => (float) ($athleteSignals['note_risk_score'] ?? 0.0),
            'athlete_signal_note_pain_count' => (int) ($athleteSignals['note_pain_count'] ?? 0),
            'athlete_signal_note_fatigue_count' => (int) ($athleteSignals['note_fatigue_count'] ?? 0),
            'athlete_signal_note_sleep_count' => (int) ($athleteSignals['note_sleep_count'] ?? 0),
            'athlete_signal_note_illness_count' => (int) ($athleteSignals['note_illness_count'] ?? 0),
            'athlete_signal_note_stress_count' => (int) ($athleteSignals['note_stress_count'] ?? 0),
            'athlete_signal_note_travel_count' => (int) ($athleteSignals['note_travel_count'] ?? 0),
            'athlete_signal_highlights' => (array) ($athleteSignals['highlights'] ?? []),
        ];
    }

    /**
     * Получить историю метрик за последние N недель.
     */
    private function getRecentWeeksHistory(int $userId, int $currentWeek, int $lookback): array
    {
        $history = [];

        for ($w = max(1, $currentWeek - $lookback); $w < $currentWeek; $w++) {
            try {
                $weekAnalysis = prepareWeeklyAnalysis($userId, $w);
                $weekStats = $weekAnalysis['statistics'] ?? [];
                $weekInfo = $weekAnalysis['week'] ?? [];

                $plannedVol = (float) ($weekInfo['planned_volume_km'] ?? 0);
                $actualVol = (float) ($weekStats['actual_volume_km'] ?? 0);

                $history[] = [
                    'week_number' => $w,
                    'compliance' => $plannedVol > 0 ? round($actualVol / $plannedVol, 2) : 1.0,
                    'actual_volume_km' => $actualVol,
                    'completed_days' => (int) ($weekStats['completed_days'] ?? 0),
                ];
            } catch (Throwable $e) {
                // Неделя не найдена — пропускаем
            }
        }

        return $history;
    }

    /**
     * Обнаружить триггеры адаптации.
     */
    private function detectTriggers(array $metrics, array $recentHistory): array
    {
        $triggers = [];

        // 1. Низкое выполнение объёма
        if ($metrics['compliance'] < self::COMPLIANCE_LOW) {
            $triggers[] = 'low_compliance';
        }

        // 2. Перевыполнение
        if ($metrics['compliance'] > self::COMPLIANCE_HIGH) {
            $triggers[] = 'high_compliance';
        }

        // 3. Низкое выполнение ключевых
        if ($metrics['key_completion'] < self::KEY_COMPLETION_LOW && $metrics['planned_key_workouts'] > 0) {
            $triggers[] = 'low_key_completion';
        }

        // 4. Много пропущенных дней
        if ($metrics['skipped_days'] >= self::SKIP_DAYS_DETRAINING) {
            $triggers[] = 'many_skipped_days';
        }

        // 5. Темп easy слишком медленный (нужна актуализация VDOT)
        if ($metrics['avg_actual_easy_pace_sec'] !== null) {
            $plannedEasyPaceSec = $this->getPlannedEasyPaceSec($metrics);
            if ($plannedEasyPaceSec > 0) {
                $paceDeviation = $metrics['avg_actual_easy_pace_sec'] / $plannedEasyPaceSec;
                if ($paceDeviation > self::PACE_SLOW_THRESHOLD) {
                    $triggers[] = 'pace_too_slow';
                }
                if ($paceDeviation < self::PACE_FAST_THRESHOLD) {
                    $triggers[] = 'pace_too_fast';
                }
            }
        }

        if (
            ($metrics['subjective_pain_count'] ?? 0) > 0
            || (float) ($metrics['subjective_recent_pain_score'] ?? 0.0) >= 4.0
            || (float) ($metrics['subjective_pain_score_delta'] ?? 0.0) >= 2.0
        ) {
            $triggers[] = 'subjective_pain_signal';
        }

        if (
            ($metrics['subjective_fatigue_count'] ?? 0) >= 2
            || (float) ($metrics['subjective_recovery_risk'] ?? 0.0) >= 0.60
            || (float) ($metrics['subjective_load_delta'] ?? 0.0) >= 0.75
            || (
                (float) ($metrics['subjective_recent_session_rpe'] ?? 0.0) >= 7.5
                && (float) ($metrics['subjective_session_rpe_delta'] ?? 0.0) >= 0.75
            )
            || (float) ($metrics['subjective_recent_legs_score'] ?? 0.0) >= 8.0
            || (float) ($metrics['subjective_recent_breath_score'] ?? 0.0) >= 8.0
            || (float) ($metrics['subjective_recent_hr_strain_score'] ?? 0.0) >= 8.0
        ) {
            $triggers[] = 'subjective_fatigue_signal';
        }

        if (($metrics['athlete_signal_note_illness_count'] ?? 0) > 0) {
            $triggers[] = 'athlete_illness_signal';
        }

        if (
            (float) ($metrics['athlete_signal_note_risk_score'] ?? 0.0) >= 0.55
            ||
            ($metrics['athlete_signal_note_sleep_count'] ?? 0) > 0
            || ($metrics['athlete_signal_note_stress_count'] ?? 0) > 0
            || ($metrics['athlete_signal_note_travel_count'] ?? 0) > 0
        ) {
            $triggers[] = 'athlete_recovery_context_signal';
        }

        // 6. Consecutive low compliance (2+ недели подряд < 70%)
        $consecutiveLow = 0;
        if ($metrics['compliance'] < self::COMPLIANCE_LOW) {
            $consecutiveLow = 1;
            foreach (array_reverse($recentHistory) as $week) {
                if ($week['compliance'] < self::COMPLIANCE_LOW) {
                    $consecutiveLow++;
                } else {
                    break;
                }
            }
        }
        if ($consecutiveLow >= self::CONSECUTIVE_LOW_WEEKS) {
            $triggers[] = 'consecutive_low_compliance';
        }

        return $triggers;
    }

    /**
     * Решить какой тип адаптации применить.
     */
    private function decideAdaptation(array $triggers, array $metrics): ?string
    {
        if (empty($triggers)) {
            return null;
        }

        // Приоритет триггеров (от более серьёзных к менее)
        if (in_array('athlete_illness_signal', $triggers, true)) {
            return 'insert_recovery';
        }

        if (in_array('subjective_pain_signal', $triggers, true)) {
            return 'insert_recovery';
        }

        if (in_array('consecutive_low_compliance', $triggers, true)) {
            return 'volume_down_significant'; // снизить на 15-20%
        }

        if (in_array('subjective_fatigue_signal', $triggers, true)) {
            return 'volume_down';
        }

        if (in_array('athlete_recovery_context_signal', $triggers, true)) {
            return 'volume_down';
        }

        if (in_array('many_skipped_days', $triggers, true)) {
            return 'insert_recovery'; // вставить разгрузочную неделю
        }

        if (in_array('pace_too_slow', $triggers, true)) {
            return 'vdot_adjust_down';
        }

        if (in_array('pace_too_fast', $triggers, true)) {
            return 'vdot_adjust_up';
        }

        if (in_array('low_key_completion', $triggers, true)) {
            return 'simplify_key';
        }

        if (in_array('low_compliance', $triggers, true)) {
            return 'volume_down'; // снизить на 10%
        }

        if (in_array('high_compliance', $triggers, true)) {
            return 'volume_up'; // поднять на 5%
        }

        return null;
    }

    /**
     * Применить адаптацию — пересчитать оставшиеся недели.
     */
    private function applyAdaptation(int $userId, string $adaptationType, array $metrics, int $currentWeek): bool
    {
        try {
            require_once __DIR__ . '/PlanSkeletonGenerator.php';
            require_once __DIR__ . '/LLMEnricher.php';
            require_once __DIR__ . '/SkeletonValidator.php';
            require_once __DIR__ . '/../../services/PlanGenerationProcessorService.php';

            // Определяем cutoff_date — понедельник следующей недели
            $cutoffDate = (new DateTime())->modify('monday next week')->format('Y-m-d');

            // Формируем payload для recalculate с адаптационным контекстом
            $payload = [
                'cutoff_date' => $cutoffDate,
                'kept_weeks' => $currentWeek,
                'adaptation_type' => $adaptationType,
                'adaptation_metrics' => $metrics,
            ];

            // Применяем корректировки к пользовательским данным в зависимости от типа
            $this->applyAdaptationAdjustments($userId, $adaptationType, $metrics);

            // Запускаем пересчёт через существующий pipeline
            $processorService = new PlanGenerationProcessorService($this->db);
            $processorService->process($userId, 'recalculate', $payload);

            return true;
        } catch (Throwable $e) {
            error_log("WeeklyAdaptationEngine: adaptation failed for user {$userId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Применить корректировки к данным пользователя перед пересчётом.
     */
    private function applyAdaptationAdjustments(int $userId, string $adaptationType, array $metrics): void
    {
        switch ($adaptationType) {
            case 'volume_down':
                // Снижаем weekly_base_km на 10% (берём фактический объём как новую базу)
                $newBase = round($metrics['actual_volume_km'] * 0.95, 1);
                $this->updateWeeklyBaseKm($userId, $newBase);
                break;

            case 'volume_down_significant':
                // Снижаем weekly_base_km на 20%
                $newBase = round($metrics['actual_volume_km'] * 0.85, 1);
                $this->updateWeeklyBaseKm($userId, $newBase);
                break;

            case 'volume_up':
                // Поднимаем weekly_base_km на 5% от фактического
                $newBase = round($metrics['actual_volume_km'] * 1.05, 1);
                $this->updateWeeklyBaseKm($userId, $newBase);
                break;

            case 'vdot_adjust_down':
            case 'vdot_adjust_up':
                // VDOT пересчитается автоматически в TrainingStateBuilder
                // через getBestResultForVdot() на основе свежих тренировок.
                // Здесь ничего не делаем — пересчёт возьмёт актуальные данные.
                break;

            case 'simplify_key':
                // Ничего не меняем в user data — PlanSkeletonGenerator
                // обработает adaptation_type из payload.
                break;

            case 'insert_recovery':
                // Ничего не меняем — recovery вставится через пересчёт.
                break;
        }
    }

    /**
     * Оценить прогресс к цели (для race/time_improvement).
     */
    private function assessGoalProgress(int $userId, array $analysis, array $metrics): ?array
    {
        $user = $analysis['user'] ?? [];
        $goalType = $user['goal_type'] ?? 'health';

        if (!in_array($goalType, ['race', 'time_improvement'])) {
            return null;
        }

        $raceDate = $user['race_date'] ?? $user['target_marathon_date'] ?? null;
        $raceTargetTime = $user['race_target_time'] ?? $user['target_marathon_time'] ?? null;
        $raceDistance = $user['race_distance'] ?? null;

        if (!$raceDate || !$raceTargetTime || !$raceDistance) {
            return null;
        }

        // Получаем текущий VDOT
        $stateBuilder = new TrainingStateBuilder($this->db);
        $state = $stateBuilder->buildForUserId($userId);
        $currentVdot = $state['vdot'] ?? null;

        if (!$currentVdot) {
            return null;
        }

        // Целевой VDOT
        $targetDistKm = $this->parseRaceDistanceKm($raceDistance);
        $targetTimeSec = $this->parseTimeToSeconds($raceTargetTime);
        if ($targetDistKm <= 0 || $targetTimeSec <= 0) {
            return null;
        }
        $targetVdot = estimateVDOT($targetDistKm, $targetTimeSec);

        // Недель до забега
        $weeksToRace = max(0, (int) ceil((strtotime($raceDate) - time()) / (7 * 86400)));

        // Прогнозируемый прирост VDOT: ~0.5-1.0 за 4 недели (для intermediate)
        $expectedGainPerWeek = 0.15; // conservative
        $expectedFinalVdot = $currentVdot + ($weeksToRace * $expectedGainPerWeek);

        // Прогнозное финишное время
        $predictedTimeSec = $this->predictRaceTime($expectedFinalVdot, $targetDistKm);
        $gap = $targetVdot > 0 ? ($targetVdot - $currentVdot) / $targetVdot : 0;

        $status = 'on_track';
        $message = null;

        if ($gap > 0.15) {
            $status = 'unrealistic';
            $predictedTime = $this->formatTime($predictedTimeSec);
            $message = "Цель {$raceDistance} за {$raceTargetTime} недостижима при текущей форме (VDOT {$currentVdot}). "
                . "Прогнозное время: {$predictedTime}. Рекомендуем скорректировать цель.";
        } elseif ($gap > 0.05) {
            $status = 'challenging';
            $predictedTime = $this->formatTime($predictedTimeSec);
            $message = "Целевое время {$raceTargetTime} сложно, но возможно. Прогноз: {$predictedTime}. "
                . "Важно выполнять ключевые тренировки.";
        } else {
            $status = 'on_track';
            $message = "Вы на верном пути к цели {$raceDistance} за {$raceTargetTime}.";
        }

        return [
            'status' => $status,
            'current_vdot' => $currentVdot,
            'target_vdot' => round($targetVdot, 1),
            'vdot_gap' => round($gap * 100, 1),
            'weeks_to_race' => $weeksToRace,
            'predicted_finish_time' => $this->formatTime($predictedTimeSec),
            'predicted_finish_sec' => $predictedTimeSec,
            'message' => $message,
        ];
    }

    /**
     * Сгенерировать ревью-сообщение (через LLM или fallback).
     */
    private function generateReviewMessage(
        array $analysis,
        array $metrics,
        array $triggers,
        ?string $adaptationType,
        ?array $goalStatus
    ): string {
        // Пробуем через LLM
        $llmMessage = $this->generateReviewViaLLM($analysis, $metrics, $triggers, $adaptationType, $goalStatus);
        if ($llmMessage !== null && $llmMessage !== '') {
            return $llmMessage;
        }

        // Fallback — алгоритмическое сообщение
        return $this->buildFallbackReview($metrics, $triggers, $adaptationType, $goalStatus);
    }

    /**
     * Сгенерировать ревью через LLM.
     */
    private function generateReviewViaLLM(
        array $analysis,
        array $metrics,
        array $triggers,
        ?string $adaptationType,
        ?array $goalStatus
    ): ?string {
        $weekData = $this->buildWeeklyReviewPromptData($analysis);

        // Дополняем контекстом адаптации
        $adaptationContext = '';
        if ($adaptationType) {
            $adaptationLabels = [
                'volume_down' => 'Объём снижен на 10% из-за недовыполнения плана.',
                'volume_down_significant' => 'Объём значительно снижен (15-20%) из-за хронического недовыполнения плана.',
                'volume_up' => 'Объём немного увеличен (+5%) благодаря стабильному перевыполнению.',
                'vdot_adjust_down' => 'Темпы пересчитаны вниз — фактические тренировки медленнее запланированных.',
                'vdot_adjust_up' => 'Темпы пересчитаны вверх — бегаешь быстрее запланированного!',
                'simplify_key' => 'Ключевые тренировки упрощены (часть интервалов заменена на темповые).',
                'insert_recovery' => 'Добавлена дополнительная разгрузочная неделя для восстановления.',
            ];
            $adaptationContext = "\n\nАДАПТАЦИЯ ПЛАНА: " . ($adaptationLabels[$adaptationType] ?? $adaptationType);
        }

        if ($goalStatus && $goalStatus['message']) {
            $adaptationContext .= "\n\nЦЕЛЬ: " . $goalStatus['message'];
        }

        $signalHighlights = array_slice((array) ($metrics['athlete_signal_highlights'] ?? []), 0, 3);
        if (!empty($signalHighlights)) {
            $adaptationContext .= "\n\nATHLETE SIGNALS: " . implode(' | ', $signalHighlights);
        }

        $username = $analysis['user']['username'] ?? 'спортсмен';

        return $this->generateWeeklyReview($weekData . $adaptationContext, $username);
    }

    /**
     * Алгоритмический fallback для ревью.
     */
    private function buildFallbackReview(
        array $metrics,
        array $triggers,
        ?string $adaptationType,
        ?array $goalStatus
    ): string {
        $parts = [];
        $compliance = $metrics['compliance'];
        $completionPct = round($compliance * 100);

        if ($compliance >= 0.9) {
            $parts[] = "Отличная неделя! Выполнение плана {$completionPct}%.";
        } elseif ($compliance >= 0.7) {
            $parts[] = "Неделя прошла нормально, выполнение {$completionPct}%.";
        } elseif ($compliance >= 0.5) {
            $parts[] = "Выполнение плана {$completionPct}% — чуть меньше, чем хотелось бы.";
        } else {
            $parts[] = "На этой неделе выполнение {$completionPct}%. Ничего страшного, бывает.";
        }

        $actualVol = round($metrics['actual_volume_km'], 1);
        $plannedVol = round($metrics['planned_volume_km'], 1);
        $parts[] = "Объём: {$actualVol} км из запланированных {$plannedVol} км.";

        if ($metrics['completed_key_workouts'] > 0 && $metrics['key_completion'] >= 1.0) {
            $parts[] = "Все ключевые тренировки выполнены — это самое важное.";
        } elseif ($metrics['completed_key_workouts'] > 0) {
            $parts[] = "Выполнено {$metrics['completed_key_workouts']} из {$metrics['planned_key_workouts']} ключевых тренировок.";
        }

        if (($metrics['subjective_pain_count'] ?? 0) > 0) {
            $parts[] = 'Ты отметил боль или тревожный сигнал по самочувствию после тренировки, поэтому приоритет сейчас — восстановление.';
        } elseif (
            ($metrics['subjective_fatigue_count'] ?? 0) > 0
            || (float) ($metrics['subjective_load_delta'] ?? 0.0) >= 0.45
            || (float) ($metrics['subjective_recent_session_rpe'] ?? 0.0) >= 7.0
        ) {
            $parts[] = 'По самочувствию видно накопление усталости, поэтому нагрузку лучше держать под контролем.';
        }

        if (($metrics['athlete_signal_note_illness_count'] ?? 0) > 0) {
            $parts[] = 'В заметках недели или дня есть сигнал про болезнь или простуду, поэтому ближайшая нагрузка должна быть осторожнее.';
        } elseif (
            ($metrics['athlete_signal_note_sleep_count'] ?? 0) > 0
            || ($metrics['athlete_signal_note_stress_count'] ?? 0) > 0
            || ($metrics['athlete_signal_note_travel_count'] ?? 0) > 0
        ) {
            $parts[] = 'В заметках есть контекст сна, стресса или поездок, и это тоже учтено в адаптации недели.';
        }

        if ($adaptationType) {
            $adaptationMessages = [
                'volume_down' => 'Я немного снизил нагрузку на следующие недели, чтобы план был реалистичнее.',
                'volume_down_significant' => 'Я значительно скорректировал план вниз. Лучше бегать меньше, но стабильно.',
                'volume_up' => 'Ты стабильно перевыполняешь план — я немного увеличил нагрузку.',
                'vdot_adjust_down' => 'Скорректировал темпы — они теперь больше соответствуют текущей форме.',
                'vdot_adjust_up' => 'Твоя форма растёт! Немного ускорил целевые темпы.',
                'simplify_key' => 'Упростил ключевые тренировки — они были слишком сложными.',
                'insert_recovery' => 'Добавил дополнительную разгрузочную неделю для восстановления.',
            ];
            $parts[] = $adaptationMessages[$adaptationType] ?? 'План скорректирован.';
        }

        if ($goalStatus && $goalStatus['status'] !== 'on_track' && $goalStatus['message']) {
            $parts[] = $goalStatus['message'];
        }

        $parts[] = 'Проверь календарь на следующую неделю.';

        return implode(' ', $parts);
    }

    private function buildWeeklyReviewPromptData(array $analysis): string
    {
        $stats = $analysis['statistics'] ?? [];
        $days = $analysis['days'] ?? [];
        $user = $analysis['user'] ?? [];
        $week = $analysis['week'] ?? [];

        $lines = [];
        $lines[] = "НЕДЕЛЯ #{$week['number']} (с {$week['start_date']})";
        $lines[] = "Плановый объём: " . ($week['planned_volume_km'] ?? '?') . " км";
        $lines[] = "Фактический объём: " . ($stats['actual_volume_km'] ?? 0) . " км";
        $lines[] = "Выполнено тренировок: " . ($stats['completed_days'] ?? 0) . " из " . ($stats['planned_days'] ?? 0);
        $lines[] = "% выполнения: " . ($stats['completion_rate'] ?? 0) . "%";

        if (!empty($stats['avg_heart_rate'])) {
            $lines[] = "Средний пульс: " . $stats['avg_heart_rate'] . " уд/мин";
        }

        $lines[] = "";
        $dayLabels = [
            'mon' => 'Пн',
            'tue' => 'Вт',
            'wed' => 'Ср',
            'thu' => 'Чт',
            'fri' => 'Пт',
            'sat' => 'Сб',
            'sun' => 'Вс',
        ];

        foreach ($days as $day) {
            $dn = $dayLabels[$day['day_name'] ?? ''] ?? ($day['day_name'] ?? '');
            $planned = $day['planned'] ?? null;
            $actual = $day['actual'] ?? [];
            $completed = !empty($actual);

            $planType = $planned['type'] ?? 'rest';
            $planDesc = $planned['description'] ?? '';
            $isKey = !empty($planned['is_key_workout']);
            $keyMark = $isKey ? ' [ключевая]' : '';

            if ($planType === 'rest') {
                $lines[] = "{$dn}: Отдых" . ($completed ? " (но была тренировка)" : "");
                continue;
            }

            $status = $completed ? 'ВЫПОЛНЕНО' : 'ПРОПУЩЕНО';
            $lines[] = "{$dn}: {$planDesc}{$keyMark} — {$status}";

            if ($completed) {
                foreach ($actual as $workout) {
                    $parts = [];
                    if (!empty($workout['distance_km'])) {
                        $parts[] = round((float) $workout['distance_km'], 1) . ' км';
                    }
                    if (!empty($workout['pace'])) {
                        $parts[] = 'темп ' . $workout['pace'];
                    }
                    if (!empty($workout['duration_minutes'])) {
                        $parts[] = $workout['duration_minutes'] . ' мин';
                    }
                    if (!empty($workout['avg_heart_rate'])) {
                        $parts[] = 'пульс ' . $workout['avg_heart_rate'];
                    }
                    if ($parts !== []) {
                        $lines[] = '  → ' . implode(', ', $parts);
                    }
                }
            }
        }

        $goalType = $user['goal_type'] ?? '';
        $raceDate = $user['race_date'] ?? $user['target_marathon_date'] ?? '';
        $raceTime = $user['race_target_time'] ?? $user['target_marathon_time'] ?? '';
        $raceDist = $user['race_distance'] ?? '';

        $lines[] = "";
        if ($goalType === 'race' && $raceDate) {
            $lines[] = "Цель: забег {$raceDist}, дата {$raceDate}, целевое время {$raceTime}";
        } elseif ($goalType !== '') {
            $lines[] = "Цель: {$goalType}";
        }

        return implode("\n", $lines);
    }

    private function generateWeeklyReview(string $weekData, string $username): ?string
    {
        $baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $model = env('LLM_CHAT_MODEL', 'mistralai/ministral-3-14b-reasoning');

        if ($baseUrl === '' || $model === '') {
            error_log('weekly_adaptation: LLM_CHAT_BASE_URL or LLM_CHAT_MODEL not set');
            return null;
        }

        $systemPrompt = <<<PROMPT
Ты — AI-тренер PlanRun. Напиши еженедельное ревью тренировок.

Правила:
- 4-6 предложений, дружелюбный профессиональный тон.
- Конкретика: упомяни объём, ключевые тренировки, темп/пульс если есть.
- Если забег близко (<14 дней) — напомни о снижении нагрузки перед стартом и восстановлении.
- При пропусках — мягко, без укоров. При >80% — конкретная похвала.
- 1-2 совета на следующую неделю.
- СТРОГО русский язык. Без emoji.
PROMPT;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Спортсмен: {$username}\n\nДанные недели:\n\n" . $weekData],
            ],
            'stream' => false,
            'max_tokens' => 800,
            'temperature' => 0.5,
        ];

        $url = $baseUrl . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            error_log("weekly_adaptation: LLM HTTP {$httpCode}");
            return null;
        }

        $data = json_decode($response, true);
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            return null;
        }

        return mb_substr($content, 0, 4000);
    }

    /**
     * Обновить weekly_base_km пользователя.
     */
    private function updateWeeklyBaseKm(int $userId, float $newBase): void
    {
        if ($newBase < 3.0) {
            $newBase = 3.0; // минимум
        }

        require_once __DIR__ . '/../../repositories/UserRepository.php';
        $userRepo = new UserRepository($this->db);
        $userRepo->update($userId, ['weekly_base_km' => $newBase]);
    }

    /**
     * Получить плановый easy pace из TrainingStateBuilder.
     */
    private function getPlannedEasyPaceSec(array $metrics): int
    {
        $pace = isset($metrics['planned_easy_pace_sec']) ? (int) $metrics['planned_easy_pace_sec'] : 0;
        return $pace > 0 ? $pace : 0;
    }

    private function getSubjectiveFeedbackMetrics(int $userId, string $weekStartDate): array
    {
        if (!$this->isValidDate($weekStartDate)) {
            return [];
        }

        $weekEndDate = date('Y-m-d', strtotime($weekStartDate . ' +6 days'));
        $service = new PostWorkoutFollowupService($this->db);

        try {
            return $service->getFeedbackAnalyticsBetween($userId, $weekStartDate, $weekEndDate);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function getAthleteSignalsMetrics(int $userId, string $weekStartDate): array
    {
        if (!$this->isValidDate($weekStartDate)) {
            return [];
        }

        $weekEndDate = date('Y-m-d', strtotime($weekStartDate . ' +6 days'));
        $service = new AthleteSignalsService($this->db);

        try {
            return $service->getSignalsBetween($userId, $weekStartDate, $weekEndDate);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Прогнозировать время забега по VDOT и дистанции.
     * Упрощённая формула Дэниелса (обратная estimateVDOT).
     */
    private function predictRaceTime(float $vdot, float $distanceKm): int
    {
        if ($vdot <= 0 || $distanceKm <= 0) {
            return 0;
        }

        // Используем таблицу Дэниелса — приближённая формула
        // VO2max ≈ VDOT, pace = f(VO2max, distance)
        $distM = $distanceKm * 1000;

        // Итеративный поиск: находим время при котором estimateVDOT() даёт наш vdot
        $lowSec = (int) ($distanceKm * 120);  // ~2:00/km — мировой рекорд
        $highSec = (int) ($distanceKm * 600); // ~10:00/km — очень медленно

        for ($i = 0; $i < 50; $i++) {
            $midSec = (int) (($lowSec + $highSec) / 2);
            $midVdot = estimateVDOT($distanceKm, $midSec);

            if (abs($midVdot - $vdot) < 0.1) {
                return $midSec;
            }

            if ($midVdot > $vdot) {
                $lowSec = $midSec; // бежим слишком быстро для этого vdot
            } else {
                $highSec = $midSec;
            }
        }

        return (int) (($lowSec + $highSec) / 2);
    }

    /**
     * Парсинг pace строки "M:SS" или "M:SS/km" в секунды.
     */
    private function parsePaceToSeconds(?string $pace): int
    {
        if ($pace === null || $pace === '') {
            return 0;
        }
        // Убираем "/km" если есть
        $pace = preg_replace('#/km$#i', '', trim($pace));

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $pace, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        return 0;
    }

    /**
     * Парсинг race_distance в км.
     */
    private function parseRaceDistanceKm(?string $distance): float
    {
        if (!$distance) return 0;

        $map = [
            '5k' => 5.0, '5km' => 5.0, '5 km' => 5.0,
            '10k' => 10.0, '10km' => 10.0, '10 km' => 10.0,
            'half_marathon' => 21.1, 'half marathon' => 21.1, 'полумарафон' => 21.1,
            'marathon' => 42.195, 'марафон' => 42.195,
        ];

        $lower = mb_strtolower(trim($distance));
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        // Попытка парсинга числа
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:km|км)?$/i', $lower, $m)) {
            return (float) $m[1];
        }

        return 0;
    }

    /**
     * Парсинг времени "H:MM:SS" или "MM:SS" в секунды.
     */
    private function parseTimeToSeconds(?string $time): int
    {
        if (!$time) return 0;

        $time = trim($time);

        // H:MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $time, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }
        // MM:SS
        if (preg_match('/^(\d{1,3}):(\d{2})$/', $time, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return 0;
    }

    /**
     * Форматирование секунд в "H:MM:SS" или "MM:SS".
     */
    private function formatTime(int $seconds): string
    {
        if ($seconds <= 0) return '?';

        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = $seconds % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }

    private function isValidDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    private function noDataResult(string $message): array
    {
        return [
            'adapted' => false,
            'adaptation_type' => null,
            'triggers' => [],
            'metrics' => [],
            'review_message' => $message,
            'goal_status' => null,
            'week_number' => 0,
        ];
    }
}
