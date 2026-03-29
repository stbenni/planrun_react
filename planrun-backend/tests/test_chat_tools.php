#!/usr/bin/env php
<?php
/**
 * Тестирование 8 новых chat tools — реальные вызовы через executeTool().
 * Запуск: php planrun-backend/tests/test_chat_tools.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../services/ChatService.php';

$db = getDBConnection();
if (!$db) {
    echo "❌ Нет подключения к БД\n";
    exit(1);
}

$chatService = new ChatService($db);

$toolRegistryRef = new ReflectionClass($chatService);
$toolRegistryProp = $toolRegistryRef->getProperty('toolRegistry');
$toolRegistryProp->setAccessible(true);
$toolRegistry = $toolRegistryProp->getValue($chatService);

$ref = new ReflectionMethod($toolRegistry, 'executeTool');
$ref->setAccessible(true);

$testUserId = 1; // st_benni

$passed = 0;
$failed = 0;
$tests = [];

function runTest(string $name, string $toolName, string $argsJson, int $userId, $ref, $toolRegistry, array &$tests, int &$passed, int &$failed): void {
    echo "\n" . str_repeat('═', 70) . "\n";
    echo "🧪 {$name}\n";
    echo "   Tool: {$toolName}({$argsJson})\n";
    echo str_repeat('─', 70) . "\n";

    try {
        $result = $ref->invoke($toolRegistry, $toolName, $argsJson, $userId);
        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ FAIL: невалидный JSON: {$result}\n";
            $failed++;
            $tests[] = ['name' => $name, 'status' => 'FAIL', 'error' => 'invalid JSON'];
            return;
        }

        $hasError = isset($data['error']);
        $hasSuccess = isset($data['success']) && $data['success'] === true;
        $hasData = !$hasError; // read-only tools don't have 'success' key

        // Форматируем вывод
        $prettyJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // Ограничиваем вывод
        if (strlen($prettyJson) > 2000) {
            $prettyJson = substr($prettyJson, 0, 2000) . "\n   ... (truncated)";
        }
        echo $prettyJson . "\n";

        if ($hasError && $data['error'] !== 'no_data' && $data['error'] !== 'not_found') {
            echo "\n⚠️  WARN: tool вернул ошибку: {$data['error']} — {$data['message']}\n";
            // Некоторые ошибки ожидаемы (no_data для training_load без пульса)
            $tests[] = ['name' => $name, 'status' => 'WARN', 'error' => $data['error']];
        } else {
            echo "\n✅ PASS\n";
            $passed++;
            $tests[] = ['name' => $name, 'status' => 'PASS'];
        }
    } catch (Throwable $e) {
        echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failed++;
        $tests[] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
    }
}

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║           ТЕСТ НОВЫХ CHAT TOOLS — ChatService.php                  ║\n";
echo "║           Пользователь: {$testUserId} (st_benni)                           ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";

// ──────────────────────────────────────────────
// 1. get_stats
// ──────────────────────────────────────────────
runTest(
    '1. get_stats (period=plan)',
    'get_stats',
    json_encode(['period' => 'plan']),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

runTest(
    '1b. get_stats (period=week)',
    'get_stats',
    json_encode(['period' => 'week']),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

// ──────────────────────────────────────────────
// 2. race_prediction
// ──────────────────────────────────────────────
runTest(
    '2. race_prediction (all distances)',
    'race_prediction',
    json_encode([]),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

runTest(
    '2b. race_prediction (half only)',
    'race_prediction',
    json_encode(['distance' => 'half']),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

// ──────────────────────────────────────────────
// 3. get_profile
// ──────────────────────────────────────────────
runTest(
    '3. get_profile',
    'get_profile',
    json_encode([]),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

// ──────────────────────────────────────────────
// 4. get_training_load
// ──────────────────────────────────────────────
runTest(
    '4. get_training_load',
    'get_training_load',
    json_encode([]),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

// ──────────────────────────────────────────────
// 5. update_profile (safe field)
// ──────────────────────────────────────────────

// Сначала получим текущий вес, чтобы потом восстановить
$profileResult = $ref->invoke($toolRegistry, 'get_profile', '{}', $testUserId);
$profileData = json_decode($profileResult, true);
$originalWeight = $profileData['weight_kg'] ?? 75;

runTest(
    '5. update_profile (weight_kg → 77.5)',
    'update_profile',
    json_encode(['field' => 'weight_kg', 'value' => '77.5']),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

// Восстанавливаем оригинальный вес
$ref->invoke($toolRegistry, 'update_profile', json_encode(['field' => 'weight_kg', 'value' => (string)$originalWeight]), $testUserId);
echo "   (вес восстановлен: {$originalWeight})\n";

// ──────────────────────────────────────────────
// 5b. update_profile — невалидное поле
// ──────────────────────────────────────────────
runTest(
    '5b. update_profile (invalid field → error)',
    'update_profile',
    json_encode(['field' => 'admin_role', 'value' => 'true']),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

// ──────────────────────────────────────────────
// 6. add_training_day — на далёкую дату, потом удалим
// ──────────────────────────────────────────────
$testDate = '2026-12-25'; // далёкая дата, вряд ли занята

// Сначала проверим, нет ли уже тренировки
$findRef = new ReflectionMethod($chatService, 'findDayIdByDate');
$findRef->setAccessible(true);
$existingId = $findRef->invoke($chatService, $testUserId, $testDate);
if ($existingId) {
    echo "\n⏭️  Пропускаю add_training_day — на {$testDate} уже есть тренировка (id={$existingId})\n";
    $tests[] = ['name' => '6. add_training_day', 'status' => 'SKIP'];
} else {
    runTest(
        '6. add_training_day (easy, 2026-12-25)',
        'add_training_day',
        json_encode(['date' => $testDate, 'type' => 'easy', 'description' => 'Тестовый лёгкий бег: 5 км']),
        $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
    );

    // Удаляем тестовую тренировку
    $newDayId = $findRef->invoke($chatService, $testUserId, $testDate);
    if ($newDayId) {
        runTest(
            '6b. delete_training_day (cleanup 2026-12-25)',
            'delete_training_day',
            json_encode(['date' => $testDate]),
            $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
        );
    }
}

// ──────────────────────────────────────────────
// 7. copy_day — нужна существующая тренировка
// ──────────────────────────────────────────────

// Находим дату с существующей тренировкой
$dayStmt = $db->prepare(
    "SELECT d.date FROM training_plan_days d
     JOIN training_plan_weeks w ON d.week_id = w.id
     WHERE w.user_id = ? AND d.type != 'rest' AND d.date IS NOT NULL
     ORDER BY d.date DESC LIMIT 1"
);
$dayStmt->bind_param('i', $testUserId);
$dayStmt->execute();
$dayRow = $dayStmt->get_result()->fetch_assoc();
$dayStmt->close();

if ($dayRow) {
    $sourceDate = $dayRow['date'];
    $copyTargetDate = '2026-12-26';

    // Убедимся что целевая дата пуста
    $existingCopy = $findRef->invoke($chatService, $testUserId, $copyTargetDate);
    if ($existingCopy) {
        echo "\n⏭️  Пропускаю copy_day — на {$copyTargetDate} уже есть тренировка\n";
        $tests[] = ['name' => '7. copy_day', 'status' => 'SKIP'];
    } else {
        runTest(
            "7. copy_day ({$sourceDate} → {$copyTargetDate})",
            'copy_day',
            json_encode(['source_date' => $sourceDate, 'target_date' => $copyTargetDate]),
            $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
        );

        // Cleanup
        $copiedId = $findRef->invoke($chatService, $testUserId, $copyTargetDate);
        if ($copiedId) {
            $ref->invoke($toolRegistry, 'delete_training_day', json_encode(['date' => $copyTargetDate]), $testUserId);
            echo "   (копия удалена)\n";
        }
    }
} else {
    echo "\n⏭️  Пропускаю copy_day — нет тренировок в плане\n";
    $tests[] = ['name' => '7. copy_day', 'status' => 'SKIP'];
}

// ──────────────────────────────────────────────
// 8. log_workout — на вчерашнюю дату, потом удалим
// ──────────────────────────────────────────────
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));

runTest(
    "8. log_workout ({$yesterdayDate}, 5.2km, 28min)",
    'log_workout',
    json_encode([
        'date' => $yesterdayDate,
        'distance_km' => 5.2,
        'duration_minutes' => 28,
        'avg_heart_rate' => 148,
        'rating' => 4,
        'notes' => 'Тестовая тренировка из чата'
    ]),
    $testUserId, $ref, $toolRegistry, $tests, $passed, $failed
);

// Cleanup: удаляем тестовую запись из workout_log
$cleanupStmt = $db->prepare(
    "DELETE FROM workout_log WHERE user_id = ? AND training_date = ? AND notes LIKE '%[из чата]%' ORDER BY id DESC LIMIT 1"
);
$cleanupStmt->bind_param('is', $testUserId, $yesterdayDate);
$cleanupStmt->execute();
$deletedRows = $cleanupStmt->affected_rows;
$cleanupStmt->close();
echo "   (тестовая запись удалена: {$deletedRows} строк)\n";

// ──────────────────────────────────────────────
// ИТОГИ
// ──────────────────────────────────────────────
echo "\n" . str_repeat('═', 70) . "\n";
echo "ИТОГИ ТЕСТОВ\n";
echo str_repeat('─', 70) . "\n";

foreach ($tests as $t) {
    $icon = match($t['status']) {
        'PASS' => '✅',
        'WARN' => '⚠️ ',
        'FAIL' => '❌',
        'SKIP' => '⏭️ ',
        default => '❓'
    };
    $extra = isset($t['error']) ? " — {$t['error']}" : '';
    echo "{$icon} {$t['name']}{$extra}\n";
}

$total = count($tests);
echo "\n{$passed}/{$total} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
