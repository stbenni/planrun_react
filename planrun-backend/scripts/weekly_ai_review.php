#!/usr/bin/env php
<?php
/**
 * Еженедельное AI-ревью тренировок.
 * Запуск по cron: воскресенье вечером (20:00 по часовому поясу пользователя).
 * Cron (каждую минуту, скрипт сам фильтрует по таймзоне):
 *   * * * * php /var/www/vladimirov/planrun-backend/scripts/weekly_ai_review.php
 *
 * Логика:
 * 1. Находим пользователей, у которых сейчас воскресенье 20:00 в их таймзоне
 * 2. Собираем данные прошедшей недели (prepareWeeklyAnalysis)
 * 3. Генерируем короткое ревью через LM Studio
 * 4. Отправляем в чат через ChatService->addAIMessageToUser()
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/prepare_weekly_analysis.php';
require_once $baseDir . '/services/ChatService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$reviewHour = 20;
$reviewMinute = 0;
$reviewDayOfWeek = 7; // воскресенье (ISO-8601: 1=Пн, 7=Вс)

// Находим пользователей с активным планом и push-уведомлениями
// Пользователи с активным планом (есть неделя, покрывающая последние 7 дней)
$stmt = $db->query("
    SELECT u.id, COALESCE(u.timezone, 'Europe/Moscow') AS timezone
    FROM users u
    INNER JOIN training_plan_weeks tpw ON tpw.user_id = u.id
    GROUP BY u.id, u.timezone
    HAVING MAX(DATE_ADD(tpw.start_date, INTERVAL 6 DAY)) >= CURDATE() - INTERVAL 7 DAY
");

if (!$stmt) {
    fwrite(STDERR, "Query failed: " . $db->error . "\n");
    exit(1);
}

$result = $stmt->get_result();
$sent = 0;
$errors = 0;

while ($row = $result->fetch_assoc()) {
    $userId = (int)$row['id'];
    $tz = $row['timezone'] ?: 'Europe/Moscow';

    try {
        $userNow = new DateTime('now', new DateTimeZone($tz));
    } catch (Exception $e) {
        $userNow = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    }

    // Проверяем: воскресенье, 20:00
    if ((int)$userNow->format('N') !== $reviewDayOfWeek) {
        continue;
    }
    if ((int)$userNow->format('G') !== $reviewHour || (int)$userNow->format('i') !== $reviewMinute) {
        continue;
    }

    try {
        // Собираем данные текущей недели
        $weekNumber = getCurrentWeekNumber($userId, $db);
        $analysis = prepareWeeklyAnalysis($userId, $weekNumber);

        // Формируем текст для LLM
        $reviewText = buildWeeklyReviewPromptData($analysis);

        // Генерируем ревью через LM Studio
        $review = generateWeeklyReview($reviewText, $analysis['user']['username'] ?? 'спортсмен');
        if (!$review) {
            error_log("weekly_ai_review: LLM returned empty for user $userId");
            $errors++;
            continue;
        }

        // Отправляем в чат
        $chatService = new ChatService($db);
        $chatService->addAIMessageToUser($userId, $review);
        $sent++;

    } catch (Throwable $e) {
        error_log("weekly_ai_review: error for user $userId: " . $e->getMessage());
        $errors++;
    }
}

if (php_sapi_name() === 'cli') {
    echo "Weekly AI review: sent=$sent, errors=$errors\n";
}

// ── Вспомогательные функции ──

/**
 * Формирует структурированный текст для промпта LLM из данных недели.
 */
function buildWeeklyReviewPromptData(array $analysis): string {
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
        'mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср',
        'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'
    ];

    foreach ($days as $day) {
        $dn = $dayLabels[$day['day_name']] ?? $day['day_name'];
        $planned = $day['planned'] ?? null;
        $actual = $day['actual'] ?? [];
        $completed = !empty($actual);

        $planType = $planned['type'] ?? 'rest';
        $planDesc = $planned['description'] ?? '';
        $isKey = !empty($planned['is_key_workout']);
        $keyMark = $isKey ? ' [ключевая]' : '';

        if ($planType === 'rest') {
            $lines[] = "{$dn}: Отдых" . ($completed ? " (но была тренировка!)" : "");
            continue;
        }

        $status = $completed ? 'ВЫПОЛНЕНО' : 'ПРОПУЩЕНО';
        $lines[] = "{$dn}: {$planDesc}{$keyMark} — {$status}";

        if ($completed) {
            foreach ($actual as $w) {
                $parts = [];
                if (!empty($w['distance_km'])) $parts[] = round($w['distance_km'], 1) . " км";
                if (!empty($w['pace'])) $parts[] = "темп " . $w['pace'];
                if (!empty($w['duration_minutes'])) $parts[] = $w['duration_minutes'] . " мин";
                if (!empty($w['avg_heart_rate'])) $parts[] = "пульс " . $w['avg_heart_rate'];
                if (!empty($parts)) {
                    $lines[] = "  → " . implode(', ', $parts);
                }
            }
        }
    }

    // Цель пользователя
    $lines[] = "";
    $goalType = $user['goal_type'] ?? '';
    $raceDate = $user['race_date'] ?? $user['target_marathon_date'] ?? '';
    $raceTime = $user['race_target_time'] ?? $user['target_marathon_time'] ?? '';
    $raceDist = $user['race_distance'] ?? '';
    if ($goalType === 'race' && $raceDate) {
        $lines[] = "Цель: забег {$raceDist}, дата {$raceDate}, целевое время {$raceTime}";
    } elseif ($goalType) {
        $lines[] = "Цель: {$goalType}";
    }

    return implode("\n", $lines);
}

/**
 * Генерирует еженедельное ревью через LM Studio.
 */
function generateWeeklyReview(string $weekData, string $username): ?string {
    $baseUrl = rtrim(env('LMSTUDIO_BASE_URL', 'http://127.0.0.1:1234/v1'), '/');
    $model = env('LMSTUDIO_CHAT_MODEL', 'openai/gpt-oss-20b');

    if ($baseUrl === '' || $model === '') {
        error_log('weekly_ai_review: LMSTUDIO_BASE_URL or LMSTUDIO_CHAT_MODEL not set');
        return null;
    }

    $systemPrompt = <<<PROMPT
Ты — AI-тренер PlanRun. Напиши краткое еженедельное ревью тренировок для спортсмена.

Правила:
- 3-5 предложений, дружелюбный тон
- Отметь что было хорошо (конкретно: темп, объём, ключевые тренировки)
- Если были пропуски — мягко упомяни, без давления
- Дай 1 конкретный совет на следующую неделю
- Если % выполнения > 80% — похвали
- Если < 50% — поддержи, предложи облегчить нагрузку
- Только русский язык
- Не начинай с "Привет" или обращения по имени — сразу по делу
- Используй emoji умеренно (1-2 штуки максимум)
PROMPT;

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Данные недели:\n\n" . $weekData]
        ],
        'stream' => false,
        'max_tokens' => 800,
        'temperature' => 0.5
    ];

    $url = $baseUrl . '/chat/completions';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        error_log("weekly_ai_review: LM Studio HTTP {$httpCode}");
        return null;
    }

    $data = json_decode($response, true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return null;
    }

    return mb_substr($content, 0, 4000);
}
