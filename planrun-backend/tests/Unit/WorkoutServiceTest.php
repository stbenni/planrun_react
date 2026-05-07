<?php
/**
 * Тесты для WorkoutService
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WorkoutService;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/WorkoutService.php';

class WorkoutServiceTest extends TestCase {
    
    private $db;
    private $service;
    
    protected function setUp(): void {
        parent::setUp();
        $this->db = getDBConnection();
        $this->service = new WorkoutService($this->db);
        $this->db->begin_transaction();
    }

    protected function tearDown(): void {
        if ($this->db instanceof \mysqli) {
            $this->db->rollback();
        }
        parent::tearDown();
    }
    
    /**
     * Тест получения всех результатов для несуществующего пользователя
     */
    public function test_getAllResults_returnsEmptyForNonExistentUser(): void {
        $result = $this->service->getAllResults(999999);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertIsArray($result['results']);
        $this->assertEmpty($result['results']);
    }
    
    /**
     * Тест получения результата для несуществующей тренировки
     */
    public function test_getResult_returnsNullForNonExistentWorkout(): void {
        $result = $this->service->getResult('2026-01-01', 1, 'mon', 999999);
        
        $this->assertNull($result);
    }

    public function test_saveResult_schedulesPostWorkoutFollowup(): void {
        $userId = $this->createTestUser();
        $today = date('Y-m-d');

        $result = $this->service->saveResult([
            'date' => $today,
            'week' => 1,
            'day' => 'mon',
            'activity_type_id' => 1,
            'is_successful' => true,
            'result_distance' => 9.1,
            'duration_minutes' => 51,
        ], $userId);

        $this->assertTrue((bool) ($result['success'] ?? false));
        $workoutLogId = (int) ($result['workout_log_id'] ?? 0);
        $this->assertGreaterThan(0, $workoutLogId);

        $stmt = $this->db->prepare(
            "SELECT status, workout_date
             FROM post_workout_followups
             WHERE user_id = ? AND source_kind = 'workout_log' AND source_id = ?"
        );
        $stmt->bind_param('ii', $userId, $workoutLogId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($today, $row['workout_date']);
    }

    public function test_saveResult_addsPostWorkoutAnalysisMessageWhenEnabled(): void {
        $this->setTestEnv('POST_WORKOUT_ANALYSIS_ENABLED', '1');
        $this->setTestEnv('POST_WORKOUT_ANALYSIS_FAKE_RESPONSE', 'AI-разбор: тренировка выполнена ровно, восстановление стоит проконтролировать.');

        try {
            $userId = $this->createTestUser();
            $today = date('Y-m-d');

            $result = $this->service->saveResult([
                'date' => $today,
                'week' => 1,
                'day' => 'wed',
                'activity_type_id' => 1,
                'is_successful' => true,
                'result_distance' => 10.2,
                'duration_minutes' => 56,
            ], $userId);

            $workoutLogId = (int) ($result['workout_log_id'] ?? 0);
            $this->assertGreaterThan(0, $workoutLogId);

            $stmt = $this->db->prepare(
                "SELECT analysis_message_id
                 FROM post_workout_followups
                 WHERE user_id = ? AND source_kind = 'workout_log' AND source_id = ?"
            );
            $stmt->bind_param('ii', $userId, $workoutLogId);
            $stmt->execute();
            $followup = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $messageId = (int) ($followup['analysis_message_id'] ?? 0);
            $this->assertGreaterThan(0, $messageId);

            $msgStmt = $this->db->prepare(
                "SELECT content, metadata
                 FROM chat_messages
                 WHERE id = ? AND sender_type = 'ai'
                 LIMIT 1"
            );
            $msgStmt->bind_param('i', $messageId);
            $msgStmt->execute();
            $message = $msgStmt->get_result()->fetch_assoc();
            $msgStmt->close();

            $this->assertNotNull($message);
            $this->assertStringContainsString('AI-разбор', (string) $message['content']);
            $this->assertStringContainsString('post_workout_analysis', (string) $message['metadata']);
        } finally {
            $this->unsetTestEnv('POST_WORKOUT_ANALYSIS_ENABLED');
            $this->unsetTestEnv('POST_WORKOUT_ANALYSIS_FAKE_RESPONSE');
        }
    }

    private function createTestUser(): int {
        $suffix = bin2hex(random_bytes(4));
        $username = 'post_followup_workout_' . $suffix;
        $slug = $username;
        $email = $username . '@example.com';
        $password = password_hash('secret123', PASSWORD_DEFAULT);
        $trainingMode = 'self';
        $goalType = 'race';
        $gender = 'male';
        $onboardingCompleted = 1;

        $timezone = 'UTC';
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, username_slug, password, email, onboarding_completed, training_mode, goal_type, gender, timezone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssissss', $username, $slug, $password, $email, $onboardingCompleted, $trainingMode, $goalType, $gender, $timezone);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        $stmt->close();

        return $userId;
    }

    private function setTestEnv(string $key, string $value): void {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function unsetTestEnv(string $key): void {
        unset($_ENV[$key]);
        putenv($key);
    }
}
