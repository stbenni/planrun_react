<?php
/**
 * Тесты для AiPlanGenerationEventLogger (PR6 / Phase D.1).
 */

namespace Tests\Unit;

use AiPlanGenerationEventLogger;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/AiPlanGenerationEventLogger.php';

class AiPlanGenerationEventLoggerTest extends TestCase
{
    private \mysqli $db;
    private AiPlanGenerationEventLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = getDBConnection();
        $this->logger = new AiPlanGenerationEventLogger($this->db);

        // Чистим таблицу до теста — изолированность.
        $this->db->query('DELETE FROM ai_plan_generation_events WHERE user_id BETWEEN 9000000 AND 9999999');
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM ai_plan_generation_events WHERE user_id BETWEEN 9000000 AND 9999999');
        parent::tearDown();
    }

    public function test_derive_cohort_returns_healthy_for_clean_state(): void
    {
        $cohort = $this->logger->deriveCohort([
            'planning_scenario' => ['flags' => []],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ]);

        $this->assertSame('healthy', $cohort);
    }

    public function test_derive_cohort_prioritizes_pregnancy_over_others(): void
    {
        $cohort = $this->logger->deriveCohort([
            'planning_scenario' => ['flags' => ['return_after_injury', 'pain_protective']],
            'special_population_flags' => ['pregnant_or_postpartum', 'return_after_injury'],
            'goal_realism' => ['severity' => 'major'],
        ]);

        $this->assertSame('pregnant_or_postpartum', $cohort);
    }

    public function test_derive_cohort_detects_return_after_injury_via_population_flags(): void
    {
        $cohort = $this->logger->deriveCohort([
            'planning_scenario' => ['flags' => []],
            'special_population_flags' => ['return_after_injury'],
            'goal_realism' => ['severity' => 'none'],
        ]);

        $this->assertSame('return_after_injury', $cohort);
    }

    public function test_derive_cohort_detects_return_after_injury_via_scenario_flags(): void
    {
        $cohort = $this->logger->deriveCohort([
            'planning_scenario' => ['flags' => ['return_after_injury']],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ]);

        $this->assertSame('return_after_injury', $cohort);
    }

    public function test_derive_cohort_detects_pain_signal(): void
    {
        $cohort = $this->logger->deriveCohort([
            'planning_scenario' => ['flags' => ['pain_protective']],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ]);

        $this->assertSame('pain_signal', $cohort);
    }

    public function test_derive_cohort_detects_illness_signal(): void
    {
        $cohort = $this->logger->deriveCohort([
            'planning_scenario' => ['flags' => ['illness_protective']],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ]);

        $this->assertSame('illness_signal', $cohort);
    }

    public function test_derive_cohort_detects_unrealistic_goal(): void
    {
        $cohort = $this->logger->deriveCohort([
            'planning_scenario' => ['flags' => []],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'major'],
        ]);

        $this->assertSame('unrealistic_goal', $cohort);
    }

    public function test_record_success_writes_row_with_metadata_fields(): void
    {
        $userId = 9001001;
        $metadata = [
            'generator' => 'DeepSeekPlanPlanner',
            'generation_mode' => 'llm_planner',
            'model' => 'deepseek-chat',
            'model_selection_reason' => 'default',
            'model_complexity_score' => 0,
            'enable_thinking' => false,
            'planner_strategy' => 'single_pass',
            'prompt_version' => 'deepseek_llm_planner_v3_simplified',
            'hard_safety_repairs' => [],
            'quality_gate' => [
                'status' => 'ok',
                'mode' => 'permissive',
                'mode_config' => 'auto',
                'mode_reason' => 'healthy_marathon',
                'issue_codes' => [],
                'normalizer_warnings' => [],
            ],
        ];
        $trainingState = [
            'planning_scenario' => ['flags' => []],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ];

        $id = $this->logger->recordSuccess($userId, 'generate', $metadata, $trainingState, 12345, 'plan-generation-abc123');

        $this->assertNotNull($id);
        $this->assertGreaterThan(0, $id);

        $row = $this->fetchById($id);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame('generate', $row['job_type']);
        $this->assertSame('healthy', $row['cohort']);
        $this->assertSame('deepseek-chat', $row['model']);
        $this->assertSame('default', $row['model_selection_reason']);
        $this->assertSame(0, (int) $row['complexity_score']);
        $this->assertSame(0, (int) $row['enable_thinking']);
        $this->assertSame('single_pass', $row['planner_strategy']);
        $this->assertSame(12345, (int) $row['duration_ms']);
        $this->assertSame('auto', $row['gate_mode']);
        $this->assertSame('permissive', $row['gate_resolved_mode']);
        $this->assertSame('ok', $row['gate_status']);
        $this->assertSame('success', $row['status']);
        $this->assertSame('deepseek_llm_planner_v3_simplified', $row['prompt_version']);
        $this->assertSame('plan-generation-abc123', $row['trace_id']);
    }

    public function test_record_success_serializes_issue_and_repair_codes(): void
    {
        $userId = 9001002;
        $metadata = [
            'model' => 'deepseek-reasoner',
            'model_selection_reason' => 'complex_scenario',
            'model_complexity_score' => 3,
            'enable_thinking' => true,
            'hard_safety_repairs' => [
                ['code' => 'long_run_capped', 'week' => 6],
                ['code' => 'taper_volume_reduced'],
            ],
            'quality_gate' => [
                'status' => 'warnings',
                'issue_codes' => ['volume_spike', 'tempo_too_long'],
                'normalizer_warnings' => [['code' => 'filler_added', 'detail' => 'week 3']],
                'mode' => 'strict',
                'mode_config' => 'auto',
            ],
        ];
        $trainingState = [
            'planning_scenario' => ['flags' => ['return_after_injury']],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ];

        $id = $this->logger->recordSuccess($userId, 'recalculate', $metadata, $trainingState, 67890, 'trace-xyz');

        $this->assertNotNull($id);
        $row = $this->fetchById($id);

        $this->assertSame('return_after_injury', $row['cohort']);
        $this->assertSame('deepseek-reasoner', $row['model']);
        $this->assertSame('complex_scenario', $row['model_selection_reason']);
        $this->assertSame(3, (int) $row['complexity_score']);
        $this->assertSame(1, (int) $row['enable_thinking']);

        $issues = json_decode($row['issue_codes'], true);
        $this->assertSame(['volume_spike', 'tempo_too_long'], $issues);

        $repairs = json_decode($row['applied_repair_codes'], true);
        $this->assertSame(['long_run_capped', 'taper_volume_reduced'], $repairs);

        $warnings = json_decode($row['normalizer_warning_codes'], true);
        $this->assertSame(['filler_added'], $warnings);

        $this->assertSame('strict', $row['gate_resolved_mode']);
    }

    public function test_record_failure_captures_error_code_and_message(): void
    {
        $userId = 9001003;
        $exception = new \RuntimeException('DeepSeek timeout', 504);

        $id = $this->logger->recordFailure(
            $userId,
            'generate',
            $exception,
            [],
            [
                'planning_scenario' => ['flags' => ['return_after_injury']],
                'special_population_flags' => [],
            ],
            42000,
            'trace-fail'
        );

        $this->assertNotNull($id);
        $row = $this->fetchById($id);

        $this->assertSame('failure', $row['status']);
        $this->assertSame('504', $row['error_code']);
        $this->assertSame('DeepSeek timeout', $row['error_message']);
        $this->assertSame('return_after_injury', $row['cohort']);
        $this->assertSame(42000, (int) $row['duration_ms']);
    }

    public function test_get_recent_events_filters_by_user_id_and_status(): void
    {
        $userId1 = 9002001;
        $userId2 = 9002002;
        $baseMetadata = [
            'model' => 'deepseek-chat',
            'model_selection_reason' => 'default',
            'model_complexity_score' => 0,
            'enable_thinking' => false,
            'quality_gate' => ['status' => 'ok', 'mode_config' => 'auto', 'mode' => 'permissive'],
            'hard_safety_repairs' => [],
        ];
        $cleanState = [
            'planning_scenario' => ['flags' => []],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ];

        $this->logger->recordSuccess($userId1, 'generate', $baseMetadata, $cleanState, 1000);
        $this->logger->recordSuccess($userId1, 'recalculate', $baseMetadata, $cleanState, 2000);
        $this->logger->recordSuccess($userId2, 'generate', $baseMetadata, $cleanState, 3000);
        $this->logger->recordFailure($userId2, 'generate', 'oops', [], $cleanState, 500);

        $u1 = $this->logger->getRecentEvents(50, ['user_id' => $userId1]);
        $this->assertCount(2, $u1);
        foreach ($u1 as $e) {
            $this->assertSame($userId1, (int) $e['user_id']);
        }

        $failures = $this->logger->getRecentEvents(50, ['status' => 'failure']);
        $failureUserIds = array_unique(array_map(fn($e) => (int) $e['user_id'], $failures));
        $this->assertContains($userId2, $failureUserIds);
        foreach ($failures as $e) {
            $this->assertSame('failure', $e['status']);
        }
    }

    public function test_get_metrics_summary_aggregates_by_cohort_and_model(): void
    {
        $cleanState = [
            'planning_scenario' => ['flags' => []],
            'special_population_flags' => [],
            'goal_realism' => ['severity' => 'none'],
        ];
        $injuryState = [
            'planning_scenario' => ['flags' => ['return_after_injury']],
            'special_population_flags' => ['return_after_injury'],
            'goal_realism' => ['severity' => 'none'],
        ];

        $chat = [
            'model' => 'deepseek-chat',
            'model_selection_reason' => 'default',
            'model_complexity_score' => 0,
            'enable_thinking' => false,
            'quality_gate' => ['status' => 'ok', 'mode_config' => 'auto', 'mode' => 'permissive'],
            'hard_safety_repairs' => [],
        ];
        $reasoner = [
            'model' => 'deepseek-reasoner',
            'model_selection_reason' => 'complex_scenario',
            'model_complexity_score' => 2,
            'enable_thinking' => true,
            'quality_gate' => ['status' => 'ok', 'mode_config' => 'auto', 'mode' => 'strict'],
            'hard_safety_repairs' => [['code' => 'long_run_capped']],
        ];

        $this->logger->recordSuccess(9003001, 'generate', $chat, $cleanState, 1500);
        $this->logger->recordSuccess(9003002, 'generate', $chat, $cleanState, 2500);
        $this->logger->recordSuccess(9003003, 'recalculate', $reasoner, $injuryState, 4000);
        $this->logger->recordFailure(9003004, 'generate', 'fail', [], $cleanState, 500);

        $summary = $this->logger->getMetricsSummary(24);

        $this->assertGreaterThanOrEqual(4, $summary['total']);
        $this->assertGreaterThanOrEqual(3, $summary['success']);
        $this->assertGreaterThanOrEqual(1, $summary['failure']);
        $this->assertGreaterThanOrEqual(1, $summary['repaired']);

        $cohortByName = [];
        foreach ($summary['by_cohort'] as $row) {
            $cohortByName[$row['cohort']] = $row;
        }
        $this->assertArrayHasKey('healthy', $cohortByName);
        $this->assertArrayHasKey('return_after_injury', $cohortByName);

        $modelByName = [];
        foreach ($summary['by_model'] as $row) {
            $modelByName[$row['model']] = $row;
        }
        $this->assertArrayHasKey('deepseek-chat', $modelByName);
        $this->assertArrayHasKey('deepseek-reasoner', $modelByName);
        $this->assertSame(1, $modelByName['deepseek-reasoner']['total']);

        $this->assertArrayHasKey('repair_rate', $summary);
        $this->assertArrayHasKey('failure_rate', $summary);
        $this->assertArrayHasKey('bad_plan_rate', $summary);
    }

    private function fetchById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_plan_generation_events WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row;
    }
}
