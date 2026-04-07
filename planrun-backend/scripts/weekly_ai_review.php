#!/usr/bin/env php
<?php
/**
 * Еженедельное AI-ревью тренировок.
 * Запуск по cron: воскресенье вечером (20:00 по часовому поясу пользователя).
 * Cron (каждую минуту, скрипт сам фильтрует по таймзоне):
 *   * * * * php /var/www/planrun/planrun-backend/scripts/weekly_ai_review.php
 *
 * Логика:
 * 1. Находим пользователей, у которых сейчас воскресенье 20:00 в их таймзоне
 * 2. Собираем данные прошедшей недели (prepareWeeklyAnalysis)
 * 3. Генерируем короткое ревью через LLM (llama.cpp)
 * 4. Отправляем в чат через ChatService->addAIMessageToUser()
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/prepare_weekly_analysis.php';
require_once $baseDir . '/services/ChatService.php';
require_once $baseDir . '/services/AdaptationService.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$useAdaptationEngine = (bool) (env('USE_SKELETON_GENERATOR', '0'));

$reviewHour = 20;
$reviewMinute = 0;
$reviewDayOfWeek = 7; // воскресенье (ISO-8601: 1=Пн, 7=Вс)

// Находим пользователей с активным планом
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

$result = $stmt;
$sent = 0;
$adapted = 0;
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
        if ($useAdaptationEngine) {
            // Новый путь: WeeklyAdaptationEngine (анализ + адаптация + ревью)
            $adaptationService = new AdaptationService($db);
            $adaptResult = $adaptationService->runWeeklyAdaptation($userId);
            $sent++;
            if (!empty($adaptResult['adapted'])) {
                $adapted++;
            }
        } else {
            // Старый путь: только ревью без адаптации
            $weekNumber = getCurrentWeekNumber($userId, $db);
            $analysis = prepareWeeklyAnalysis($userId, $weekNumber);
            $enrichment = collectReviewEnrichment($userId, $db);
            $reviewText = buildWeeklyReviewPromptData($analysis, $enrichment);
            $review = generateWeeklyReview($reviewText, $analysis['user']['username'] ?? 'спортсмен');
            if (!$review) {
                error_log("weekly_ai_review: LLM returned empty for user $userId");
                $errors++;
                continue;
            }
            $chatService = new ChatService($db);
            $chatService->addAIMessageToUser($userId, $review, [
                'event_key' => 'plan.weekly_review',
                'title' => 'Еженедельный обзор готов',
                'link' => '/chat',
            ]);
            $sent++;
        }
    } catch (Throwable $e) {
        error_log("weekly_ai_review: error for user $userId: " . $e->getMessage());
        $errors++;
    }
}

if (php_sapi_name() === 'cli') {
    echo "Weekly AI review: sent=$sent, adapted=$adapted, errors=$errors\n";
}

// ── Вспомогательные функции ──

/**
 * Формирует структурированный текст для промпта LLM из данных недели.
 * Enhanced: 4-week trends, ACWR, goal progress.
 */
function buildWeeklyReviewPromptData(array $analysis, ?array $enrichment = null): string {
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

    // 4-week volume trend
    if (!empty($enrichment['weekly_volumes'])) {
        $lines[] = "";
        $lines[] = "ТРЕНД ОБЪЁМОВ (4 недели):";
        foreach ($enrichment['weekly_volumes'] as $wk => $km) {
            $lines[] = "  {$wk}: {$km} км";
        }
    }

    // ACWR
    if (!empty($enrichment['acwr'])) {
        $acwr = $enrichment['acwr'];
        $zoneRu = ['low' => 'недогрузка', 'optimal' => 'оптимально', 'caution' => 'осторожно', 'danger' => 'ОПАСНО'];
        $lines[] = "";
        $lines[] = "НАГРУЗКА:";
        $lines[] = "  ACWR: {$acwr['acwr']} (" . ($zoneRu[$acwr['zone']] ?? $acwr['zone']) . ")";
        $lines[] = "  Острая (7 дн): {$acwr['acute']}, Хроническая (28 дн): {$acwr['chronic']}";
    }

    // Goal progress
    $lines[] = "";
    $goalType = $user['goal_type'] ?? '';
    $raceDate = $user['race_date'] ?? $user['target_marathon_date'] ?? '';
    $raceTime = $user['race_target_time'] ?? $user['target_marathon_time'] ?? '';
    $raceDist = $user['race_distance'] ?? '';
    if ($goalType === 'race' && $raceDate) {
        $lines[] = "Цель: забег {$raceDist}, дата {$raceDate}, целевое время {$raceTime}";
        try {
            $daysUntil = (int) (new DateTime())->diff(new DateTime($raceDate))->days;
            if ($daysUntil > 0) $lines[] = "До забега: {$daysUntil} дней";
        } catch (Exception $e) {}
    } elseif ($goalType) {
        $lines[] = "Цель: {$goalType}";
    }

    // VDOT progress
    if (!empty($enrichment['vdot'])) {
        $vdotLine = "Текущий VDOT: {$enrichment['vdot']}";
        if (isset($enrichment['vdot_trend']) && $enrichment['vdot_trend'] != 0) {
            $sign = $enrichment['vdot_trend'] > 0 ? '+' : '';
            $vdotLine .= " ({$sign}{$enrichment['vdot_trend']} за неделю)";
        }
        $lines[] = $vdotLine;
    }

    // Goal progress (predicted vs target time)
    if (!empty($enrichment['goal_progress'])) {
        $gp = $enrichment['goal_progress'];
        $lines[] = "";
        $lines[] = "ПРОГРЕСС К ЦЕЛИ:";
        $lines[] = "  Прогноз: " . gmdate('H:i:s', $gp['predicted_sec']);
        $lines[] = "  Цель: " . gmdate('H:i:s', $gp['target_sec']);
        $lines[] = "  Статус: " . ($gp['on_track'] ? 'НА ПУТИ (прогноз быстрее цели)' : 'Нужно улучшение');
        if (($gp['consistency_streak'] ?? 0) >= 3) {
            $lines[] = "  Стабильность: {$gp['consistency_streak']} недель подряд";
        }
    }

    return implode("\n", $lines);
}

/**
 * Собирает дополнительные данные для ревью: тренд объёмов, ACWR, VDOT.
 */
function collectReviewEnrichment(int $userId, $db): array {
    require_once dirname(__DIR__) . '/services/ChatContextBuilder.php';
    $ctx = new ChatContextBuilder($db);

    $enrichment = [];

    // 4-week volume trend
    $volumes = [];
    for ($i = 3; $i >= 0; $i--) {
        $monday = (new DateTime())->modify("-{$i} weeks monday")->format('Y-m-d');
        $sunday = (new DateTime($monday))->modify('+6 days')->format('Y-m-d');
        $km = 0.0;
        $stmt = $db->prepare("SELECT COALESCE(SUM(distance_km), 0) as km FROM workout_log WHERE user_id = ? AND is_completed = 1 AND training_date >= ? AND training_date <= ?");
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $monday, $sunday);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $km += (float) ($row['km'] ?? 0);
            $stmt->close();
        }
        $stmt2 = $db->prepare("SELECT COALESCE(SUM(distance_km), 0) as km FROM workouts WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ? AND NOT EXISTS (SELECT 1 FROM workout_log wl WHERE wl.user_id = workouts.user_id AND wl.training_date = DATE(workouts.start_time) AND wl.is_completed = 1)");
        if ($stmt2) {
            $stmt2->bind_param('iss', $userId, $monday, $sunday);
            $stmt2->execute();
            $row2 = $stmt2->get_result()->fetch_assoc();
            $km += (float) ($row2['km'] ?? 0);
            $stmt2->close();
        }
        $label = (new DateTime($monday))->format('d.m') . '-' . (new DateTime($sunday))->format('d.m');
        $volumes[$label] = round($km, 1);
    }
    $enrichment['weekly_volumes'] = $volumes;

    // ACWR
    $acwr = $ctx->calculateACWR($userId);
    if ($acwr['acwr'] !== null) $enrichment['acwr'] = $acwr;

    // VDOT + goal progress from GoalProgressService
    try {
        require_once dirname(__DIR__) . '/services/GoalProgressService.php';
        $gps = new GoalProgressService($db);
        $progress = $gps->getProgressSummary($userId);
        if ($progress) {
            if (!empty($progress['current_vdot'])) $enrichment['vdot'] = $progress['current_vdot'];
            if ($progress['vdot_trend'] !== null) $enrichment['vdot_trend'] = $progress['vdot_trend'];
            if ($progress['predicted_time_sec'] && $progress['race_target_time_sec']) {
                $enrichment['goal_progress'] = [
                    'predicted_sec' => $progress['predicted_time_sec'],
                    'target_sec' => $progress['race_target_time_sec'],
                    'on_track' => $progress['on_track'] ?? false,
                    'consistency_streak' => $progress['consistency_streak'] ?? 0,
                ];
            }
        }
    } catch (Throwable $e) {
        try {
            require_once dirname(__DIR__) . '/services/StatsService.php';
            $vdotData = (new StatsService($db))->getBestResultForVdot($userId);
            if (!empty($vdotData['vdot'])) $enrichment['vdot'] = round((float) $vdotData['vdot'], 1);
        } catch (Throwable $e2) {}
    }

    return $enrichment;
}

/**
 * Генерирует еженедельное ревью через llama-server.
 */
function generateWeeklyReview(string $weekData, string $username): ?string {
    $baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
    $model = env('LLM_CHAT_MODEL', 'qwen3-14b');

    if ($baseUrl === '' || $model === '') {
        error_log('weekly_ai_review: LLM_CHAT_BASE_URL or LLM_CHAT_MODEL not set');
        return null;
    }

    $systemPrompt = <<<PROMPT
Ты — AI-тренер PlanRun. Напиши еженедельное ревью тренировок.

Правила:
- 4-6 предложений, дружелюбный профессиональный тон.
- Конкретика: упомяни объём, ключевые тренировки, темп/пульс если есть.
- Если есть тренд объёмов (4 недели) — оцени динамику (рост/стабильность/снижение).
- Если есть ACWR — прокомментируй: оптимально → «нагрузка в норме»; осторожно/опасно → «рекомендую снизить/отдохнуть».
- Если забег близко (<14 дней) — напомни о тейпере и восстановлении.
- При пропусках — мягко, без укоров. При >80% — конкретная похвала.
- 1-2 совета на следующую неделю (конкретно, не общие фразы).
- СТРОГО русский язык. Без «Привет» — сразу по делу.
- Без emoji.
PROMPT;

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Данные недели:\n\n" . $weekData]
        ],
        'stream' => false,
        'max_tokens' => 800,
        'temperature' => 0.5,
        'chat_template_kwargs' => ['enable_thinking' => false],
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
        error_log("weekly_ai_review: LLM HTTP {$httpCode}");
        return null;
    }

    $data = json_decode($response, true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return null;
    }

    return mb_substr($content, 0, 4000);
}
