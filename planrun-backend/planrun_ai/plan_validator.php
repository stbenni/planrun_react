<?php

require_once __DIR__ . '/plan_normalizer.php';
require_once __DIR__ . '/validators/schedule_validator.php';
require_once __DIR__ . '/validators/pace_validator.php';
require_once __DIR__ . '/validators/load_validator.php';
require_once __DIR__ . '/validators/taper_validator.php';
require_once __DIR__ . '/validators/goal_consistency_validator.php';
require_once __DIR__ . '/validators/workout_completeness_validator.php';

function collectNormalizedPlanValidationIssues(array $normalizedPlan, array $trainingState, array $context = []): array {
    $issues = array_merge(
        collectScheduleValidationIssues($normalizedPlan, $trainingState, $context),
        collectPaceValidationIssues($normalizedPlan, $trainingState, $context),
        collectLoadValidationIssues($normalizedPlan, $trainingState, $context),
        collectTaperValidationIssues($normalizedPlan, $trainingState, $context),
        collectGoalConsistencyValidationIssues($normalizedPlan, $trainingState, $context),
        collectWorkoutCompletenessValidationIssues($normalizedPlan, $trainingState, $context),
    );

    usort(
        $issues,
        static function (array $left, array $right): int {
            $severityRank = ['error' => 0, 'warning' => 1];
            $leftRank = $severityRank[$left['severity'] ?? 'warning'] ?? 1;
            $rightRank = $severityRank[$right['severity'] ?? 'warning'] ?? 1;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            $leftWeek = (int) ($left['week_number'] ?? 0);
            $rightWeek = (int) ($right['week_number'] ?? 0);
            if ($leftWeek !== $rightWeek) {
                return $leftWeek <=> $rightWeek;
            }

            return strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
        }
    );

    return $issues;
}

function validateNormalizedPlanAgainstTrainingState(array $normalizedPlan, array $trainingState, array $context = []): array {
    return array_map(
        static fn(array $issue): string => $issue['message'],
        collectNormalizedPlanValidationIssues($normalizedPlan, $trainingState, $context)
    );
}

function shouldRunCorrectiveRegeneration(array $validationIssues): bool {
    foreach ($validationIssues as $issue) {
        if (($issue['severity'] ?? 'warning') === 'error') {
            return true;
        }
    }

    return false;
}

function scoreValidationIssues(array $validationIssues): int {
    $score = 0;
    foreach ($validationIssues as $issue) {
        $score += (($issue['severity'] ?? 'warning') === 'error') ? 3 : 1;
    }
    return $score;
}

function validatorFormatPaceSec(int $sec): string {
    $m = (int) floor($sec / 60);
    $s = (int) ($sec % 60);
    return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
}
