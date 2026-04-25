<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/PlanGenerationProcessorService.php';

class PlanGenerationProcessorServiceTest extends TestCase {
    private \mysqli $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->db->begin_transaction();
    }

    protected function tearDown(): void {
        $this->db->rollback();
        parent::tearDown();
    }

    public function test_enrichRecalculatePayload_excludesWalkingAndManualCrossTrainingFromActualWeeklyKm(): void {
        $userId = $this->createTestUser();
        $runningTypeId = $this->ensureActivityType('running');
        $cyclingTypeId = $this->ensureActivityType('cycling_test');

        $this->insertWorkout($userId, 'running', '2026-04-08 07:00:00', '2026-04-08 08:00:00', 10.0, 60);
        $this->insertWorkout($userId, 'walking', '2026-04-09 07:00:00', '2026-04-09 08:00:00', 5.0, 60);
        $this->insertWorkout($userId, 'running', '2026-03-31 07:00:00', '2026-03-31 07:45:00', 8.0, 45);

        $this->insertWorkoutLog($userId, '2026-04-03', $runningTypeId, 7.0, 42);
        $this->insertWorkoutLog($userId, '2026-04-04', $cyclingTypeId, 20.0, 55);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, ['reason' => 'тестовый пересчёт']);

        $this->assertSame(12.5, $payload['actual_weekly_km_4w']);
    }

    public function test_enrichRecalculatePayload_sets_mutable_from_date_to_tomorrow_when_running_workout_today_exists(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $this->insertWorkout($userId, 'running', $today . ' 07:00:00', $today . ' 08:00:00', 8.0, 48);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, ['reason' => 'тестовый пересчёт']);

        $this->assertSame(date('Y-m-d', strtotime($today . ' +1 day')), $payload['mutable_from_date']);
    }

    public function test_enrichRecalculatePayload_aligns_cutoff_to_future_plan_start_and_includes_current_phase(): void {
        $userId = $this->createPlanningUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:48:46',
            'training_start_date' => '2026-04-27',
            'weekly_base_km' => 3.0,
            'sessions_per_week' => 4,
            'experience_level' => 'novice',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'fri']),
            'easy_pace_sec' => 380,
        ]);

        $this->insertPlanWeek($userId, 1, '2026-04-27', 6.8, ['easy', 'easy', 'rest', 'easy', 'long', 'rest', 'rest']);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, ['reason' => 'пересчитать план']);

        $this->assertSame('2026-04-27', $payload['cutoff_date'] ?? null);
        $this->assertSame(0, (int) ($payload['kept_weeks'] ?? -1));
        $this->assertIsArray($payload['current_phase'] ?? null);
        $this->assertSame('base', $payload['current_phase']['phase'] ?? null);
        $this->assertSame('recalculate', $payload['continuation_context']['mode'] ?? null);
        $this->assertSame('2026-04-27', $payload['continuation_context']['anchor_date'] ?? null);
    }

    public function test_enrichRecalculatePayload_builds_progression_counters_from_completed_key_days(): void {
        $userId = $this->createPlanningUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:45:00',
            'training_start_date' => '2026-03-23',
            'weekly_base_km' => 24.0,
            'sessions_per_week' => 5,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'tue', 'thu', 'sat', 'sun']),
            'easy_pace_sec' => 350,
        ]);

        $this->insertPlanWeek($userId, 1, '2026-03-23', 24.0, ['easy', 'tempo', 'rest', 'interval', 'rest', 'fartlek', 'long']);
        $this->insertPlanWeek($userId, 2, '2026-03-30', 26.0, ['easy', 'tempo', 'rest', 'interval', 'rest', 'control', 'long']);

        $runningTypeId = $this->ensureActivityType('running');
        $this->insertWorkoutLog($userId, '2026-03-24', $runningTypeId, 8.0, 42);
        $this->insertWorkoutLog($userId, '2026-03-26', $runningTypeId, 9.0, 45);
        $this->insertWorkoutLog($userId, '2026-03-31', $runningTypeId, 8.5, 43);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichRecalculatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, [
            'reason' => 'нужно продолжить план',
            'cutoff_date' => '2026-04-06',
        ]);

        $counters = $payload['progression_counters'] ?? [];

        $this->assertSame(2, (int) ($counters['tempo_count'] ?? 0));
        $this->assertSame(1, (int) ($counters['interval_count'] ?? 0));
        $this->assertSame(0, (int) ($counters['fartlek_count'] ?? 0));
        $this->assertSame(0, (int) ($counters['control_count'] ?? 0));
        $this->assertSame(3, (int) ($counters['completed_key_days'] ?? 0));
        $this->assertSame(1, (int) ($counters['race_pace_count'] ?? 0));
        $this->assertSame(2, (int) ($payload['continuation_context']['progression_counters']['tempo_count'] ?? 0));
    }

    public function test_enrichNextPlanPayload_uses_recent_non_race_weeks_before_new_start(): void {
        $userId = $this->createPlanningUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_date' => '2026-05-24',
            'race_target_time' => '00:48:46',
            'training_start_date' => '2026-03-23',
            'weekly_base_km' => 8.0,
            'sessions_per_week' => 4,
            'experience_level' => 'intermediate',
            'preferred_days' => json_encode(['mon', 'wed', 'fri', 'sun']),
            'easy_pace_sec' => 360,
        ]);

        $this->insertPlanWeek($userId, 1, '2026-03-23', 7.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']);
        $this->insertPlanWeek($userId, 2, '2026-03-30', 8.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']);
        $this->insertPlanWeek($userId, 3, '2026-04-06', 20.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'race']);
        $this->insertPlanWeek($userId, 4, '2026-04-13', 9.0, ['easy', 'rest', 'easy', 'rest', 'easy', 'rest', 'long']);

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enrichNextPlanPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $userId, []);

        $this->assertSame('2026-04-20', $payload['cutoff_date'] ?? null);
        $this->assertSame(8.0, (float) ($payload['last_plan_avg_km'] ?? 0.0));
        $this->assertSame('next_plan', $payload['continuation_context']['mode'] ?? null);
        $this->assertCount(3, $payload['continuation_context']['recent_plan_weeks'] ?? []);
    }

    public function test_attachGenerationExplanation_adds_explanation_metadata(): void {
        $userId = $this->createTestUser();
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'attachGenerationExplanation');
        $method->setAccessible(true);

        $planData = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'planned_km' => 8.0],
                    ['type' => 'tempo', 'planned_km' => 10.0],
                    ['type' => 'rest', 'planned_km' => 0.0],
                    ['type' => 'easy', 'planned_km' => 7.0],
                    ['type' => 'interval', 'planned_km' => 9.0],
                    ['type' => 'rest', 'planned_km' => 0.0],
                    ['type' => 'long', 'planned_km' => 16.0],
                ],
            ]],
        ];
        $state = [
            'readiness' => 'normal',
            'vdot' => 42.1,
            'vdot_source_label' => 'лучшие свежие тренировки',
            'athlete_signals' => [
                'prompt_summary' => 'post-workout feedback: 2 ответа; overall=moderate',
            ],
        ];

        $result = $method->invoke($service, $userId, 'recalculate', ['reason' => 'после тяжёлой недели'], $planData, $state);
        $summary = (string) ($result['_generation_metadata']['explanation']['summary'] ?? '');

        $this->assertIsArray($result['_generation_metadata']['explanation'] ?? null);
        $this->assertStringNotContainsString('readiness=', $summary);
        $this->assertStringNotContainsString('quality-дней', $summary);
        $this->assertStringNotContainsString('post-workout feedback', $summary);
        $this->assertStringNotContainsString('VDOT', $summary);
        $this->assertStringNotContainsString('readiness', $summary);
        $this->assertMatchesRegularExpression('/форм|ориентир/u', $summary);
        $this->assertStringContainsString('объём ближайшей недели', $summary);
        $this->assertSame(2, (int) ($result['_generation_metadata']['explanation']['plan_outline']['week_1_quality_count'] ?? 0));
    }

    public function test_buildQualityGateFailureMessage_prefers_blocking_errors_over_warnings(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'buildQualityGateFailureMessage');
        $method->setAccessible(true);

        $message = $method->invoke($service, [
            ['severity' => 'warning', 'message' => 'warning: taper too big'],
            ['severity' => 'error', 'message' => 'error: weekly spike'],
            ['severity' => 'warning', 'message' => 'warning: adjacent workouts'],
            ['severity' => 'error', 'message' => 'error: race mismatch'],
        ]);

        $this->assertSame('error: weekly spike | error: race mismatch', $message);
    }

    public function test_syncLatestTrainingPlanSnapshot_updates_latest_row_and_clears_error(): void {
        $userId = $this->createTestUserWithGoal([
            'goal_type' => 'time_improvement',
            'race_date' => '2026-06-21',
            'race_target_time' => '00:48:46',
            'training_start_date' => '2026-04-22',
        ]);

        $activeStmt = $this->db->prepare(
            'INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active, error_message, plan_description)
             VALUES (?, ?, ?, ?, 1, NULL, ?)'
        );
        $activeStartDate = '2026-04-01';
        $activePlanDate = '2026-06-01';
        $activeTargetTime = '52:00';
        $activeDescription = 'older active description';
        $activeStmt->bind_param('issss', $userId, $activeStartDate, $activePlanDate, $activeTargetTime, $activeDescription);
        $activeStmt->execute();
        $activeStmt->close();

        $stmt = $this->db->prepare(
            'INSERT INTO user_training_plans (user_id, start_date, marathon_date, target_time, is_active, error_message, plan_description)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        );
        $oldStartDate = '2026-04-22';
        $oldPlanDate = '2026-06-21';
        $oldTargetTime = '48:46';
        $oldError = 'old generation error';
        $oldDescription = 'old description';
        $stmt->bind_param('isssss', $userId, $oldStartDate, $oldPlanDate, $oldTargetTime, $oldError, $oldDescription);
        $stmt->execute();
        $stmt->close();

        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'syncLatestTrainingPlanSnapshot');
        $method->setAccessible(true);

        $method->invoke($service, $userId, '2026-04-27', [
            '_generation_metadata' => [
                'explanation' => [
                    'summary' => 'Новый актуальный план',
                ],
            ],
        ]);

        $row = $this->db->query(
            'SELECT start_date, marathon_date, target_time, is_active, error_message, plan_description
             FROM user_training_plans WHERE user_id = ' . (int) $userId . ' ORDER BY id DESC LIMIT 1'
        )->fetch_assoc();

        $this->assertSame('2026-04-27', $row['start_date'] ?? null);
        $this->assertSame('2026-06-21', $row['marathon_date'] ?? null);
        $this->assertSame('48:46', $row['target_time'] ?? null);
        $this->assertSame('1', (string) ($row['is_active'] ?? '0'));
        $this->assertNull($row['error_message']);
        $this->assertSame('Новый актуальный план', $row['plan_description'] ?? null);

        $activeCount = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM user_training_plans WHERE user_id = ' . (int) $userId . ' AND is_active = 1'
        )->fetch_assoc();
        $this->assertSame(1, (int) ($activeCount['cnt'] ?? 0));
    }

    public function test_enforceRaceDayConsistency_restores_target_marathon_distance_and_pace(): void {
        $service = new \PlanGenerationProcessorService($this->db);
        $method = new \ReflectionMethod($service, 'enforceRaceDayConsistency');
        $method->setAccessible(true);

        $plan = [
            'weeks' => [[
                'week_number' => 3,
                'target_volume_km' => 25.9,
                'actual_volume_km' => 25.9,
                'days' => [
                    ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '5:03'],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'easy', 'distance_km' => 3.0, 'pace' => '5:03'],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'rest', 'distance_km' => 0.0, 'pace' => null],
                    ['type' => 'race', 'distance_km' => 19.9, 'pace' => '4:59', 'description' => '19.9 км · 1:39:10'],
                ],
            ]],
        ];
        $trainingState = [
            'race_distance' => 'marathon',
            'goal_pace_sec' => 299,
            'training_paces' => ['marathon' => 299],
        ];
        $user = ['race_distance' => 'marathon'];

        $result = $method->invoke($service, $plan, $trainingState, $user);
        $raceDay = $result['weeks'][0]['days'][6];

        $this->assertSame(42.2, (float) ($raceDay['distance_km'] ?? 0.0));
        $this->assertSame('4:59', $raceDay['pace'] ?? null);
        $this->assertSame(48.2, (float) ($result['weeks'][0]['actual_volume_km'] ?? 0.0));
        $this->assertGreaterThanOrEqual(42.2, (float) ($result['weeks'][0]['target_volume_km'] ?? 0.0));
    }

    public function test_saveRecalculatedPlan_preserves_past_days_of_current_week_before_mutable_date(): void {
        $userId = $this->createTestUser();

        $weekStmt = $this->db->prepare(
            'INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)'
        );
        $weekNumber = 1;
        $startDate = '2026-04-20';
        $totalVolume = 20.0;
        $weekStmt->bind_param('iisd', $userId, $weekNumber, $startDate, $totalVolume);
        $weekStmt->execute();
        $weekId = (int) $this->db->insert_id;
        $weekStmt->close();

        $this->insertPlanDay($userId, $weekId, 1, 'easy', 'old monday', 0, '2026-04-20');
        $this->insertPlanDay($userId, $weekId, 2, 'walking', 'old tuesday walk', 0, '2026-04-21');
        $this->insertPlanDay($userId, $weekId, 3, 'rest', 'old wednesday', 0, '2026-04-22');
        $this->insertPlanDay($userId, $weekId, 4, 'rest', 'old thursday', 0, '2026-04-23');
        $this->insertPlanDay($userId, $weekId, 5, 'rest', 'old friday', 0, '2026-04-24');
        $this->insertPlanDay($userId, $weekId, 6, 'rest', 'old saturday', 0, '2026-04-25');
        $this->insertPlanDay($userId, $weekId, 7, 'rest', 'old sunday', 0, '2026-04-26');

        $newPlan = [
            'weeks' => [[
                'days' => [
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 5.0, 'pace' => '5:10'],
                    ['type' => 'easy', 'distance_km' => 6.0, 'pace' => '5:10'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'control', 'distance_km' => 21.1, 'pace' => '4:42', 'notes' => 'control'],
                ],
            ]],
        ];

        saveRecalculatedPlan(
            $this->db,
            $userId,
            $newPlan,
            '2026-04-20',
            null,
            '2026-04-22'
        );

        $monday = $this->fetchPlanDay($userId, '2026-04-20');
        $tuesday = $this->fetchPlanDay($userId, '2026-04-21');
        $wednesday = $this->fetchPlanDay($userId, '2026-04-22');
        $thursday = $this->fetchPlanDay($userId, '2026-04-23');
        $sunday = $this->fetchPlanDay($userId, '2026-04-26');

        $this->assertSame('easy', $monday['type'] ?? null);
        $this->assertSame('walking', $tuesday['type'] ?? null);
        $this->assertSame('easy', $wednesday['type'] ?? null);
        $this->assertSame('easy', $thursday['type'] ?? null);
        $this->assertSame('control', $sunday['type'] ?? null);
    }

    private function createTestUser(): int {
        return $this->createTestUserWithGoal();
    }

    private function createPlanningUser(array $overrides = []): int {
        $suffix = bin2hex(random_bytes(4));
        $record = array_merge([
            'username' => 'planning_user_' . $suffix,
            'username_slug' => 'planning_user_' . $suffix,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'email' => 'planning_user_' . $suffix . '@example.com',
            'onboarding_completed' => 1,
            'training_mode' => 'self',
            'goal_type' => 'race',
            'gender' => 'male',
        ], $overrides);

        $columns = array_keys($record);
        $values = array_map(function ($value): string {
            if ($value === null) {
                return 'NULL';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            return "'" . $this->db->real_escape_string((string) $value) . "'";
        }, array_values($record));

        $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $this->db->query($sql);

        return (int) $this->db->insert_id;
    }

    private function createTestUserWithGoal(array $overrides = []): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'recalc_proc_' . $suffix;
        $slug = $username;
        $email = $username . '@example.com';
        $password = password_hash('secret123', PASSWORD_DEFAULT);
        $trainingMode = 'self';
        $goalType = (string) ($overrides['goal_type'] ?? 'race');
        $gender = 'male';
        $onboardingCompleted = 1;
        $raceDate = $overrides['race_date'] ?? null;
        $raceTargetTime = $overrides['race_target_time'] ?? null;
        $trainingStartDate = $overrides['training_start_date'] ?? null;

        $stmt = $this->db->prepare(
            'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender, race_date, race_target_time, training_start_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssissssss', $username, $slug, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender, $raceDate, $raceTargetTime, $trainingStartDate);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        $stmt->close();

        return $userId;
    }

    private function ensureActivityType(string $name): int {
        $stmt = $this->db->prepare('SELECT id FROM activity_types WHERE name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return (int) $row['id'];
        }

        $icon = '?';
        $color = 'primary';
        $isActive = 1;
        $sortOrder = 0;
        $insert = $this->db->prepare(
            'INSERT INTO activity_types (name, icon, color, is_active, sort_order) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->bind_param('sssii', $name, $icon, $color, $isActive, $sortOrder);
        $insert->execute();
        $id = (int) $this->db->insert_id;
        $insert->close();

        return $id;
    }

    private function insertWorkout(int $userId, string $activityType, string $startTime, string $endTime, float $distanceKm, int $durationMinutes): void {
        $stmt = $this->db->prepare(
            'INSERT INTO workouts (user_id, activity_type, start_time, end_time, duration_minutes, distance_km)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssid', $userId, $activityType, $startTime, $endTime, $durationMinutes, $distanceKm);
        $stmt->execute();
        $stmt->close();
    }

    private function insertWorkoutLog(int $userId, string $trainingDate, int $activityTypeId, float $distanceKm, int $durationMinutes): void {
        $dayName = strtolower((new \DateTimeImmutable($trainingDate))->format('D'));
        $weekNumber = 1;
        $isCompleted = 1;
        $isSuccessful = 1;

        $stmt = $this->db->prepare(
            'INSERT INTO workout_log
                (user_id, training_date, week_number, day_name, activity_type_id, is_completed, is_successful, distance_km, duration_minutes, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->bind_param(
            'isisiiidi',
            $userId,
            $trainingDate,
            $weekNumber,
            $dayName,
            $activityTypeId,
            $isCompleted,
            $isSuccessful,
            $distanceKm,
            $durationMinutes
        );
        $stmt->execute();
        $stmt->close();
    }

    private function insertPlanDay(
        int $userId,
        int $weekId,
        int $dayOfWeek,
        string $type,
        string $description,
        int $isKeyWorkout,
        string $date
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_days (user_id, week_id, day_of_week, type, description, is_key_workout, date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiissis', $userId, $weekId, $dayOfWeek, $type, $description, $isKeyWorkout, $date);
        $stmt->execute();
        $stmt->close();
    }

    private function insertPlanWeek(int $userId, int $weekNumber, string $startDate, float $totalVolume, array $types): void {
        $stmt = $this->db->prepare(
            'INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iisd', $userId, $weekNumber, $startDate, $totalVolume);
        $stmt->execute();
        $weekId = (int) $this->db->insert_id;
        $stmt->close();

        foreach (array_values($types) as $index => $type) {
            $date = date('Y-m-d', strtotime($startDate . ' +' . $index . ' days'));
            $description = 'day ' . ($index + 1) . ' ' . $type;
            $isKeyWorkout = in_array($type, ['tempo', 'interval', 'fartlek', 'control', 'race'], true) ? 1 : 0;
            $this->insertPlanDay($userId, $weekId, $index + 1, $type, $description, $isKeyWorkout, $date);
        }
    }

    private function fetchPlanDay(int $userId, string $date): ?array {
        $stmt = $this->db->prepare(
            'SELECT type, description FROM training_plan_days WHERE user_id = ? AND date = ? LIMIT 1'
        );
        $stmt->bind_param('is', $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}
