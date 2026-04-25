<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/TrainingStateBuilder.php';
require_once __DIR__ . '/../planrun_ai/plan_normalizer.php';

class PlanExplanationService extends BaseService {
    public function buildExplanation(int $userId, string $jobType, array $payload, array $planData, ?array $trainingState = null): array {
        $state = is_array($trainingState) && $trainingState !== []
            ? $trainingState
            : (new TrainingStateBuilder($this->db))->buildForUserId($userId);

        $outline = $this->extractPlanOutline($planData);
        $inputs = $this->buildInputSignals($jobType, $payload, $state);
        $summary = $this->buildSummary($jobType, $payload, $state, $outline, $inputs);

        return [
            'summary' => $summary,
            'inputs' => $inputs,
            'plan_outline' => $outline,
            'readiness' => $state['readiness'] ?? null,
            'vdot' => $state['vdot'] ?? null,
            'overall_signal_risk' => $state['athlete_signals']['overall_risk_level'] ?? null,
        ];
    }

    private function extractPlanOutline(array $planData): array {
        $weeks = $planData['weeks'] ?? [];
        if (!is_array($weeks) || $weeks === []) {
            return [
                'weeks_count' => 0,
            ];
        }

        $firstWeek = $weeks[0] ?? [];
        $days = is_array($firstWeek['days'] ?? null) ? $firstWeek['days'] : [];
        $qualityCount = 0;
        $restCount = 0;
        $longRunKm = null;

        foreach ($days as $day) {
            $type = strtolower(trim((string) ($day['type'] ?? '')));
            if (in_array($type, ['tempo', 'interval', 'fartlek', 'control', 'race'], true)) {
                $qualityCount++;
            }
            if (in_array($type, ['rest', 'free'], true)) {
                $restCount++;
            }
            if ($type === 'long' && $longRunKm === null && isset($day['planned_km'])) {
                $longRunKm = round((float) $day['planned_km'], 1);
            }
            if ($type === 'long' && $longRunKm === null && isset($day['distance'])) {
                $longRunKm = round((float) $day['distance'], 1);
            }
        }

        $weekVolume = null;
        if (isset($firstWeek['weekly_target_km'])) {
            $weekVolume = round((float) $firstWeek['weekly_target_km'], 1);
        } elseif (isset($firstWeek['total_km'])) {
            $weekVolume = round((float) $firstWeek['total_km'], 1);
        } else {
            $sum = 0.0;
            foreach ($days as $day) {
                $sum += (float) ($day['planned_km'] ?? $day['distance'] ?? 0.0);
            }
            if ($sum > 0) {
                $weekVolume = round($sum, 1);
            }
        }

        return [
            'weeks_count' => count($weeks),
            'week_1_volume_km' => $weekVolume,
            'week_1_quality_count' => $qualityCount,
            'week_1_rest_days' => $restCount,
            'week_1_long_run_km' => $longRunKm,
        ];
    }

    private function buildInputSignals(string $jobType, array $payload, array $state): array {
        $inputs = [];
        $readiness = trim((string) ($state['readiness'] ?? ''));
        if ($readiness !== '') {
            $inputs[] = 'готовность сейчас ' . $this->formatReadinessLabel($readiness);
        }

        $vdot = $state['vdot'] ?? null;
        if ($vdot !== null) {
            $source = (string) ($state['vdot_source_label'] ?? $state['vdot_source'] ?? 'training_state');
            $inputs[] = $this->buildHumanFormSignal((float) $vdot, $source);
        }

        $signalSummary = $this->buildHumanSignalSummary($state);
        if ($signalSummary !== '') {
            $inputs[] = $signalSummary;
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason !== '') {
            $inputs[] = 'причина пересчёта: ' . $reason;
        }

        $goals = trim((string) ($payload['goals'] ?? ''));
        if ($goals !== '') {
            $inputs[] = 'новый фокус: ' . $goals;
        }

        if ($jobType === 'recalculate' && isset($payload['actual_weekly_km_4w'])) {
            $inputs[] = 'фактический объём 4 недель: ' . round((float) $payload['actual_weekly_km_4w'], 1) . ' км/нед';
        }

        return $inputs;
    }

    private function buildSummary(string $jobType, array $payload, array $state, array $outline, array $inputs): string {
        $parts = [];

        $stateSentence = $this->buildHumanStateSummary($state);
        if ($stateSentence !== '') {
            $parts[] = $stateSentence;
        }

        $outlineSentence = $this->buildHumanOutlineSummary($outline, $jobType);
        if ($outlineSentence !== '') {
            $parts[] = $outlineSentence;
        }

        if ($parts === []) {
            return match ($jobType) {
                'recalculate' => 'План стал осторожнее и точнее под текущую форму.',
                'next_plan' => 'Следующий блок собран с плавным входом в работу.',
                default => 'План собран спокойно и без лишней резкости на старте.',
            };
        }

        return trim(implode(' ', $parts));
    }

    private function buildHumanSignalSummary(array $state): string {
        $feedback = is_array($state['feedback_analytics'] ?? null) ? $state['feedback_analytics'] : [];
        $signals = is_array($state['athlete_signals'] ?? null) ? $state['athlete_signals'] : [];
        $parts = [];

        $responses = (int) ($feedback['total_responses'] ?? 0);
        $painCount = (int) ($feedback['pain_count'] ?? 0);
        $fatigueCount = (int) ($feedback['fatigue_count'] ?? 0);
        $riskLevel = (string) ($feedback['risk_level'] ?? '');
        if ($responses > 0) {
            $hasNegativeFeedback = false;
            if ($painCount > 0) {
                $parts[] = 'в последних ответах о самочувствии был болевой сигнал';
                $hasNegativeFeedback = true;
            } elseif ($fatigueCount > 0) {
                $parts[] = $fatigueCount === 1
                    ? 'в последнем самочувствии отмечалась усталость'
                    : 'в последних ответах о самочувствии несколько раз отмечалась усталость';
                $hasNegativeFeedback = true;
            } else {
                $parts[] = 'по последним ответам о самочувствии всё выглядит спокойно';
            }

            if ($riskLevel === 'high') {
                $parts[] = 'сигнал на восстановление сейчас ' . $this->formatRiskLevelLabel($riskLevel);
            } elseif ($hasNegativeFeedback && $riskLevel === 'moderate') {
                $parts[] = 'восстановление пока требует обычной осторожности';
            }
        }

        $noteSignals = [];
        if ((int) ($signals['note_sleep_count'] ?? 0) > 0) {
            $noteSignals[] = 'сон';
        }
        if ((int) ($signals['note_stress_count'] ?? 0) > 0) {
            $noteSignals[] = 'стресс';
        }
        if ((int) ($signals['note_travel_count'] ?? 0) > 0) {
            $noteSignals[] = 'поездки';
        }
        if ((int) ($signals['note_illness_count'] ?? 0) > 0) {
            $noteSignals[] = 'самочувствие по болезни';
        }

        if ($noteSignals !== []) {
            $parts[] = 'в заметках есть контекст про ' . implode(', ', $noteSignals);
        }

        return implode('; ', $parts);
    }

    private function formatReadinessLabel(string $readiness): string {
        return match ($readiness) {
            'high' => 'высокая',
            'low' => 'сниженная',
            default => 'нормальная',
        };
    }

    private function formatRiskLevelLabel(string $riskLevel): string {
        return match ($riskLevel) {
            'high' => 'напряжённый',
            'moderate' => 'умеренно напряжённый',
            default => 'спокойный',
        };
    }

    private function formatQualityDaysLabel(int $count): string {
        return match ($count) {
            0 => 'без более интенсивных тренировок',
            1 => 'одна более интенсивная тренировка',
            2 => 'две более интенсивные тренировки',
            3 => 'три более интенсивные тренировки',
            4 => 'четыре более интенсивные тренировки',
            default => $count . ' более интенсивных тренировок',
        };
    }

    private function buildHumanOutlineSummary(array $outline, string $jobType): string {
        $parts = [];
        if (!empty($outline['week_1_volume_km'])) {
            $parts[] = 'объём ближайшей недели около ' . $outline['week_1_volume_km'] . ' км';
        }
        if (!empty($outline['week_1_long_run_km'])) {
            $parts[] = 'длительная около ' . $outline['week_1_long_run_km'] . ' км';
        }
        if (isset($outline['week_1_quality_count'])) {
            $parts[] = $this->formatQualityDaysLabel((int) $outline['week_1_quality_count']);
        }

        if ($parts === []) {
            return '';
        }

        $lead = match ($jobType) {
            'recalculate' => 'В ближайших днях',
            'next_plan' => 'В начале следующего блока',
            default => 'В начале плана',
        };

        return $lead . ' ' . implode(', ', $parts) . '.';
    }

    private function buildHumanStateSummary(array $state): string {
        $feedback = is_array($state['feedback_analytics'] ?? null) ? $state['feedback_analytics'] : [];
        $vdot = $state['vdot'] ?? null;
        $source = (string) ($state['vdot_source_label'] ?? $state['vdot_source'] ?? '');
        $readiness = (string) ($state['readiness'] ?? '');
        $painCount = (int) ($feedback['pain_count'] ?? 0);
        $fatigueCount = (int) ($feedback['fatigue_count'] ?? 0);
        $riskLevel = (string) ($feedback['risk_level'] ?? '');

        $formSentence = '';
        if ($vdot !== null) {
            $formSentence = ucfirst($this->buildHumanFormSignal((float) $vdot, $source)) . '.';
        } elseif ($readiness === 'high') {
            $formSentence = 'По текущим данным форма выглядит уверенно.';
        } elseif ($readiness === 'normal') {
            $formSentence = 'По текущим данным форма выглядит ровной.';
        }

        $cautionSentence = '';
        if ($painCount > 0) {
            $cautionSentence = 'Но по последнему самочувствию лучше держать план осторожным и не форсировать нагрузку.';
        } elseif ($fatigueCount > 0 || $riskLevel === 'moderate') {
            $cautionSentence = 'Но по недавнему самочувствию всё же лучше держать обычную осторожность.';
        } elseif ($riskLevel === 'high' || $readiness === 'low') {
            $cautionSentence = 'Сейчас лучше не форсировать и оставить приоритет за восстановлением.';
        } elseif ($readiness === 'high') {
            $cautionSentence = 'По самочувствию ничего тревожного не видно.';
        } elseif ($readiness === 'normal') {
            $cautionSentence = 'По самочувствию всё выглядит спокойно.';
        }

        return trim($formSentence . ' ' . $cautionSentence);
    }

    private function buildHumanFormSignal(float $vdot, string $source): string {
        $sourceLower = mb_strtolower(trim($source));

        if ($sourceLower !== '' && (str_contains($sourceLower, 'забег') || str_contains($sourceLower, 'race') || str_contains($sourceLower, 'control') || str_contains($sourceLower, 'контроль'))) {
            return $vdot >= 48.0
                ? 'последний результат показывает, что форма сейчас на хорошем уровне'
                : 'последний результат даёт понятный ориентир по текущей форме';
        }

        if ($sourceLower !== '' && (str_contains($sourceLower, 'easy') || str_contains($sourceLower, 'лёгк') || str_contains($sourceLower, 'спокойн'))) {
            return 'по недавним спокойным тренировкам видно, какой темп сейчас ощущается рабочим';
        }

        return 'по последним данным есть понятный ориентир по текущей форме';
    }
}
