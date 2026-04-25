<?php
/**
 * Bootstrap для PHPUnit тестов
 */

// Загружаем переменные окружения из тестового .env
$_ENV['APP_ENV'] = 'testing';

// Используем локальное хранилище сессий, чтобы CLI-тесты не зависели
// от системного session.save_path с ограниченными правами.
$sessionDir = sys_get_temp_dir() . '/planrun-phpunit-sessions';
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
    throw new RuntimeException('Не удалось создать директорию для тестовых сессий: ' . $sessionDir);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

// Загружаем env_loader
require_once __DIR__ . '/../config/env_loader.php';

// Позволяет запускать PHPUnit на отдельной базе, не трогая основной .env.
$phpunitDbEnv = $_ENV['PLANRUN_PHPUNIT_DB_NAME'] ?? getenv('PLANRUN_PHPUNIT_DB_NAME');
$phpunitDbName = trim((string) ($phpunitDbEnv ?: ''));
if ($phpunitDbName !== '') {
    $_ENV['DB_NAME'] = $phpunitDbName;
    putenv('DB_NAME=' . $phpunitDbName);
}

// Загружаем основные файлы
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../auth.php';

if (!function_exists('planrunPhpunitDeleteRows')) {
    function planrunPhpunitDeleteRows(mysqli $db, array &$deleted, string $table, string $where): void {
        if ($db->query("DELETE FROM `$table` WHERE $where")) {
            $deleted[$table] = ($deleted[$table] ?? 0) + max(0, $db->affected_rows);
        }
    }
}

register_shutdown_function(static function (): void {
    if (PHP_SAPI !== 'cli' || (string) ($_ENV['APP_ENV'] ?? '') !== 'testing') {
        return;
    }

    $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) {
        return;
    }
    $db->set_charset(DB_CHARSET);

    $deleted = [];
    try {
        $db->begin_transaction();

        $fixtureRes = $db->query(
            "SELECT id FROM workout_log
             WHERE user_id = 1
               AND training_date = CURDATE()
               AND distance_km = 9.10
               AND duration_minutes = 51
               AND notes = '[самочувствие после тренировки] Нормально, но по пульсу было тяжеловато и ноги подзабились'"
        );
        $fixtureIds = [];
        while ($fixtureRes && ($row = $fixtureRes->fetch_assoc())) {
            $fixtureIds[] = (int) $row['id'];
        }

        if ($fixtureIds) {
            $ids = implode(',', $fixtureIds);
            $followups = [];
            $res = $db->query("SELECT id, followup_message_id, response_message_id, note_id FROM post_workout_followups WHERE user_id = 1 AND source_kind = 'workout_log' AND source_id IN ($ids)");
            while ($res && ($row = $res->fetch_assoc())) {
                $followups[] = $row;
            }
            $followupIds = array_map(static fn($row) => (int) $row['id'], $followups);
            $messageIds = [];
            $noteIds = [];
            foreach ($followups as $row) {
                foreach (['followup_message_id', 'response_message_id'] as $column) {
                    if (!empty($row[$column])) {
                        $messageIds[] = (int) $row[$column];
                    }
                }
                if (!empty($row['note_id'])) {
                    $noteIds[] = (int) $row['note_id'];
                }
            }
            if ($followupIds) {
                $likes = array_map(static fn($id) => "metadata LIKE '%\\\"id\\\": $id%'", $followupIds);
                $res = $db->query(
                    "SELECT id FROM chat_messages
                     WHERE conversation_id IN (SELECT id FROM chat_conversations WHERE user_id = 1)
                       AND metadata LIKE '%post_workout_checkin_reply%'
                       AND (" . implode(' OR ', $likes) . ")"
                );
                while ($res && ($row = $res->fetch_assoc())) {
                    $messageIds[] = (int) $row['id'];
                }
            }
            $messageIds = array_values(array_unique(array_filter($messageIds)));
            $noteIds = array_values(array_unique(array_filter($noteIds)));

            if ($messageIds) {
                planrunPhpunitDeleteRows($db, $deleted, 'chat_messages', 'id IN (' . implode(',', $messageIds) . ')');
            }
            if ($followupIds) {
                planrunPhpunitDeleteRows($db, $deleted, 'post_workout_followups', 'id IN (' . implode(',', $followupIds) . ')');
            }
            if ($noteIds) {
                planrunPhpunitDeleteRows($db, $deleted, 'plan_day_notes', 'id IN (' . implode(',', $noteIds) . ')');
            }
            planrunPhpunitDeleteRows($db, $deleted, 'workout_log', 'id IN (' . $ids . ')');
        }

        $prefixes = [
            'recalc_proc_%', 'skeleton_break_%', 'workout_rating_%', 'feedback_state_%', 'ai_smoke_%',
            'metrics_%', 'training_state_%', 'pending_confirm_%', 'chat_repo_%', 'chat_context_%',
            'tool_registry_%', 'repo_%', 'plan_service_%', 'athlete_signals_%', 'planning_user_%', 'post_followup_%',
        ];
        $conditions = array_map(static fn($prefix) => "username LIKE '" . $db->real_escape_string($prefix) . "'", $prefixes);
        $res = $db->query('SELECT id, email FROM users WHERE ' . implode(' OR ', $conditions));
        $users = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $users[] = $row;
        }

        if ($users) {
            $userIds = implode(',', array_map(static fn($row) => (int) $row['id'], $users));
            $emails = array_values(array_filter(array_map(static fn($row) => $row['email'] ?? null, $users)));

            planrunPhpunitDeleteRows($db, $deleted, 'chat_messages', "conversation_id IN (SELECT id FROM chat_conversations WHERE user_id IN ($userIds))");
            planrunPhpunitDeleteRows($db, $deleted, 'chat_messages', "sender_id IN ($userIds)");
            planrunPhpunitDeleteRows($db, $deleted, 'workout_laps', "workout_id IN (SELECT id FROM workouts WHERE user_id IN ($userIds))");
            planrunPhpunitDeleteRows($db, $deleted, 'workout_timeline', "workout_id IN (SELECT id FROM workouts WHERE user_id IN ($userIds))");
            if ($emails) {
                $emailSql = implode(',', array_map(static fn($email) => "'" . $db->real_escape_string($email) . "'", $emails));
                planrunPhpunitDeleteRows($db, $deleted, 'email_verification_codes', "email IN ($emailSql)");
            }

            $tables = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id' AND TABLE_NAME <> 'users' ORDER BY TABLE_NAME");
            while ($tables && ($row = $tables->fetch_assoc())) {
                planrunPhpunitDeleteRows($db, $deleted, $row['TABLE_NAME'], "user_id IN ($userIds)");
            }

            foreach (['user_coaches' => 'coach_id', 'coach_requests' => 'coach_id'] as $table => $column) {
                $check = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                if ($check && $check->num_rows > 0) {
                    planrunPhpunitDeleteRows($db, $deleted, $table, "$column IN ($userIds)");
                }
            }
            planrunPhpunitDeleteRows($db, $deleted, 'users', "id IN ($userIds)");
        }

        $db->commit();
    } catch (Throwable) {
        $db->rollback();
    } finally {
        $db->close();
    }
});
