<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanSkeletonBuilder.php';
require_once __DIR__ . '/../../planrun_ai/prompt_builder.php';

class GoldenPlanPolicyTest extends TestCase {
    /**
     * @dataProvider goldenCases
     */
    public function test_golden_plan_policy_cases(array $case): void {
        $builder = new \PlanSkeletonBuilder();
        $skeleton = $builder->build($case['user'], $case['goal_type'], $case['options']);

        $weeks = $skeleton['weeks'] ?? [];
        $this->assertNotEmpty($weeks, $case['name'] . ': skeleton must not be empty');

        $expected = $case['expected'];

        if (!empty($expected['forbidden_run_days'])) {
            $forbiddenIndexes = array_map(
                static fn(string $day): int => getPromptWeekdayOrder()[$day] - 1,
                $expected['forbidden_run_days']
            );

            foreach ($weeks as $week) {
                foreach ($forbiddenIndexes as $index) {
                    $this->assertSame('rest', $week['days'][$index], $case['name'] . ': forbidden day must stay rest');
                }
            }
        }

        if (!empty($expected['long_day'])) {
            $longDayIndex = getPromptWeekdayOrder()[$expected['long_day']] - 1;
            foreach ($weeks as $week) {
                if (in_array('race', $week['days'], true)) {
                    continue;
                }
                $this->assertSame('long', $week['days'][$longDayIndex], $case['name'] . ': long run must sit on expected day');
            }
        }

        if (!empty($expected['race_week']) && !empty($expected['race_day'])) {
            $raceWeek = $weeks[$expected['race_week'] - 1] ?? null;
            $this->assertNotNull($raceWeek, $case['name'] . ': race week must exist');
            $raceDayIndex = getPromptWeekdayOrder()[$expected['race_day']] - 1;
            $this->assertSame('race', $raceWeek['days'][$raceDayIndex], $case['name'] . ': race must be placed on expected day');
        }

        if (!empty($expected['allowed_types'])) {
            foreach ($weeks as $week) {
                foreach ($week['days'] as $type) {
                    $this->assertContains($type, $expected['allowed_types'], $case['name'] . ': unexpected type in skeleton');
                }
            }
        }

        if (!empty($expected['allowed_types_by_week']) && is_array($expected['allowed_types_by_week'])) {
            foreach ($expected['allowed_types_by_week'] as $weekNumber => $allowedTypes) {
                $week = $weeks[((int) $weekNumber) - 1] ?? null;
                $this->assertNotNull($week, $case['name'] . ": week {$weekNumber} must exist");
                foreach (($week['days'] ?? []) as $type) {
                    $this->assertContains($type, $allowedTypes, $case['name'] . ": unexpected type in week {$weekNumber}");
                }
            }
        }

        if (!empty($expected['must_include_types'])) {
            $allTypes = [];
            foreach ($weeks as $week) {
                foreach (($week['days'] ?? []) as $type) {
                    $allTypes[$type] = true;
                }
            }

            foreach ($expected['must_include_types'] as $requiredType) {
                $this->assertArrayHasKey($requiredType, $allTypes, $case['name'] . ": expected type {$requiredType} not found in skeleton");
            }
        }

        if (!empty($expected['must_not_include_types'])) {
            foreach ($weeks as $week) {
                foreach (($week['days'] ?? []) as $type) {
                    $this->assertNotContains($type, $expected['must_not_include_types'], $case['name'] . ': forbidden type found in skeleton');
                }
            }
        }
    }

    public static function goldenCases(): array {
        $cases = require __DIR__ . '/../Fixtures/golden_plan_policy_cases.php';

        $result = [];
        foreach ($cases as $case) {
            $result[$case['name']] = [$case];
        }

        return $result;
    }
}
