<?php
/**
 * Генератор планов тренировок через PlanRun AI
 * Использует локальную LLM (Ministral 3 14B Reasoning) с RAG для создания персональных планов
 */

require_once __DIR__ . '/planrun_ai_integration.php';
require_once __DIR__ . '/prompt_builder.php';
require_once __DIR__ . '/plan_normalizer.php';
require_once __DIR__ . '/plan_validator.php';
require_once __DIR__ . '/../services/TrainingStateBuilder.php';
require_once __DIR__ . '/../services/PlanSkeletonBuilder.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../repositories/WeekRepository.php';

/**
 * Проверка доступности PlanRun AI системы
 */
function isPlanRunAIConfigured() {
    require_once __DIR__ . '/planrun_ai_config.php';
    return USE_PLANRUN_AI && isPlanRunAIAvailable();
}

/**
 * Генерация плана тренировок через PlanRun AI
 * 
 * @param int $userId ID пользователя
 * @return array План тренировок в формате PlanRun
 * @throws Exception
 */
function generatePlanViaPlanRunAI($userId) {
    if (!isPlanRunAIConfigured()) {
        throw new Exception("PlanRun AI система недоступна. Проверьте, что сервис запущен на порту 8000.");
    }
    
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Ошибка подключения к БД");
    }
    
    // Получаем данные пользователя
    $stmt = $db->prepare("
        SELECT 
            id, username, goal_type, race_distance, race_date, race_target_time,
            target_marathon_date, target_marathon_time, training_start_date,
            gender, birth_year, height_cm, weight_kg, experience_level,
            weekly_base_km, sessions_per_week, preferred_days, preferred_ofp_days,
            has_treadmill, ofp_preference, training_time_pref, health_notes,
            weight_goal_kg, weight_goal_date, health_program, health_plan_weeks,
            current_running_level, running_experience, easy_pace_sec,
            is_first_race_at_distance, last_race_distance, last_race_distance_km,
            last_race_time, last_race_date, device_type
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        throw new Exception("Пользователь не найден");
    }
    
    // Декодируем JSON поля
    if (!empty($user['preferred_days'])) {
        $user['preferred_days'] = json_decode($user['preferred_days'], true) ?: [];
    } else {
        $user['preferred_days'] = [];
    }
    
    if (!empty($user['preferred_ofp_days'])) {
        $user['preferred_ofp_days'] = json_decode($user['preferred_ofp_days'], true) ?: [];
    } else {
        $user['preferred_ofp_days'] = [];
    }
    
    // Определяем goal_type
    $goalType = $user['goal_type'] ?? 'health';

    // Проверяем, нужно ли разбивать план на чанки (>16 недель)
    $chunks = computePlanChunks($user, $goalType);

    if ($chunks !== null && count($chunks) > 1) {
        return generateSplitPlan($user, $goalType, $chunks, $userId);
    }

    // Стандартная генерация (≤16 недель)
    $prompt = buildTrainingPlanPrompt($user, $goalType);

    error_log("PlanRun AI Generator: Промпт построен для пользователя {$userId}, длина: " . strlen($prompt) . " символов");

    // Вызываем PlanRun AI API
    try {
        $response = callAIAPI($prompt, $user, 3, $userId);
        error_log("PlanRun AI Generator: Получен ответ от PlanRun AI API, длина: " . strlen($response) . " символов");

        // Парсим JSON ответ с repair pipeline
        $planData = parseAndRepairPlanJSON($response, $userId);

        return $planData;

    } catch (Exception $e) {
        error_log("PlanRun AI Generator: Ошибка при генерации плана: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Генерация длинного плана по частям (сплит).
 * Каждый чанк генерируется отдельным вызовом LLM, затем недели объединяются.
 *
 * @param array $user       Данные пользователя
 * @param string $goalType  Тип цели
 * @param array $chunks     Массив чанков из computePlanChunks()
 * @param int $userId       ID пользователя
 * @return array            Объединённый план {weeks: [...]}
 * @throws Exception
 */
function generateSplitPlan(array $user, string $goalType, array $chunks, int $userId): array {
    $totalWeeks = 0;
    foreach ($chunks as $chunk) {
        $totalWeeks += $chunk['weeks_count'];
    }

    $totalChunks = count($chunks);
    error_log("PlanRun AI Generator: Сплит-генерация для пользователя {$userId}: {$totalWeeks} недель → {$totalChunks} чанков");

    $allWeeks = [];
    $prevLastWeek = null;

    foreach ($chunks as $chunkIndex => $chunk) {
        $prompt = buildPartialPlanPrompt(
            $user,
            $goalType,
            $chunk,
            $totalWeeks,
            $chunkIndex,
            $totalChunks,
            $prevLastWeek
        );

        error_log("PlanRun AI Generator: Чанк " . ($chunkIndex + 1) . "/{$totalChunks} — недели {$chunk['week_from']}–{$chunk['week_to']}, промпт: " . strlen($prompt) . " симв.");

        try {
            $response = callAIAPI($prompt, $user, 3, $userId);
            error_log("PlanRun AI Generator: Чанк " . ($chunkIndex + 1) . " — ответ: " . strlen($response) . " симв.");

            $chunkPlan = parseAndRepairPlanJSON($response, $userId);

            if (empty($chunkPlan['weeks'])) {
                throw new Exception("Чанк " . ($chunkIndex + 1) . " вернул пустой план");
            }

            // Перенумеровываем week_number в абсолютную нумерацию
            foreach ($chunkPlan['weeks'] as &$week) {
                $relWeekNum = $week['week_number'] ?? null;
                if ($relWeekNum !== null) {
                    $week['week_number'] = $chunk['week_from'] + ($relWeekNum - 1);
                }
            }
            unset($week);

            // Сохраняем последнюю неделю для контекста следующего чанка
            $prevLastWeek = end($chunkPlan['weeks']);

            // Добавляем недели в общий массив
            $allWeeks = array_merge($allWeeks, $chunkPlan['weeks']);

        } catch (Exception $e) {
            error_log("PlanRun AI Generator: Ошибка чанка " . ($chunkIndex + 1) . ": " . $e->getMessage());
            throw new Exception("Ошибка генерации части " . ($chunkIndex + 1) . " плана: " . $e->getMessage());
        }
    }

    // Финальная перенумерация (на случай, если LLM вернула неправильную нумерацию)
    foreach ($allWeeks as $i => &$week) {
        $week['week_number'] = $i + 1;
    }
    unset($week);

    error_log("PlanRun AI Generator: Сплит-генерация завершена — итого " . count($allWeeks) . " недель");

    return validatePlanStructure(['weeks' => $allWeeks], $userId);
}

/**
 * Pipeline: Parse → Validate → Repair JSON ответа от LLM.
 *
 * 1. Попытка прямого json_decode
 * 2. Очистка от текста перед/после JSON (```json ... ```)
 * 3. Попытка извлечь JSON из текста
 * 4. Валидация структуры (weeks → days)
 *
 * @param string $response Сырой ответ от LLM
 * @param int $userId ID пользователя (для логов)
 * @return array Валидный план
 * @throws Exception
 */
function parseAndRepairPlanJSON(string $response, int $userId): array {
    // 1. Прямой decode
    $planData = json_decode($response, true);
    if ($planData && isset($planData['weeks']) && is_array($planData['weeks'])) {
        return validatePlanStructure($planData, $userId);
    }

    // 2. LLM иногда оборачивает в ```json...``` или добавляет текст перед/после
    $cleaned = $response;

    // Убрать markdown code blocks
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $cleaned, $m)) {
        $cleaned = $m[1];
    }

    // Убрать текст до первой { и после последней }
    $firstBrace = strpos($cleaned, '{');
    $lastBrace = strrpos($cleaned, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $cleaned = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
    }

    $planData = json_decode($cleaned, true);
    if ($planData && isset($planData['weeks']) && is_array($planData['weeks'])) {
        error_log("parseAndRepairPlanJSON (user {$userId}): Repaired — extracted JSON from text wrapper");
        return validatePlanStructure($planData, $userId);
    }

    // 3. Иногда LLM выдаёт массив напрямую [{...}] вместо {"weeks":[...]}
    $planData = json_decode($cleaned, true);
    if (is_array($planData) && !isset($planData['weeks'])) {
        // Проверяем, может это массив недель напрямую
        $first = $planData[0] ?? null;
        if ($first && isset($first['days'])) {
            error_log("parseAndRepairPlanJSON (user {$userId}): Repaired — wrapped bare weeks array");
            return validatePlanStructure(['weeks' => $planData], $userId);
        }
    }

    // 4. Попытка починить невалидный JSON (trailing commas, single quotes)
    $repaired = $cleaned;
    $repaired = preg_replace('/,\s*([}\]])/', '$1', $repaired); // trailing commas
    $repaired = str_replace("'", '"', $repaired); // single → double quotes

    $planData = json_decode($repaired, true);
    if ($planData && isset($planData['weeks']) && is_array($planData['weeks'])) {
        error_log("parseAndRepairPlanJSON (user {$userId}): Repaired — fixed trailing commas / quotes");
        return validatePlanStructure($planData, $userId);
    }

    // 5. Не удалось — логируем и бросаем ошибку
    $jsonError = json_last_error_msg();
    $snippet = substr($response, 0, 500);
    error_log("parseAndRepairPlanJSON (user {$userId}): FAILED. json_error={$jsonError}. Snippet: {$snippet}");
    throw new Exception("Не удалось распарсить ответ LLM. JSON error: {$jsonError}");
}

/**
 * Валидация структуры плана: проверяет что weeks содержат days с правильными типами.
 */
function validatePlanStructure(array $planData, int $userId): array {
    $weeks = $planData['weeks'];
    $warnings = [];

    foreach ($weeks as $wi => $week) {
        if (!isset($week['days']) || !is_array($week['days'])) {
            $warnings[] = "Неделя " . ($wi + 1) . ": нет массива days";
            continue;
        }
        foreach ($week['days'] as $di => $day) {
            if (!is_array($day)) {
                $warnings[] = "Неделя " . ($wi + 1) . " день " . ($di + 1) . ": не является объектом";
                $weeks[$wi]['days'][$di] = ['type' => 'rest'];
                continue;
            }
            // Проверка типа
            $type = strtolower(trim($day['type'] ?? ''));
            $allowed = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'rest', 'other', 'sbu', 'race', 'free', 'walking',
                        'easy_run', 'long_run', 'long-run', 'ofp', 'marathon'];
            if (!in_array($type, $allowed, true)) {
                $warnings[] = "Неделя " . ($wi + 1) . " день " . ($di + 1) . ": неизвестный тип '{$type}' → rest";
                $weeks[$wi]['days'][$di]['type'] = 'rest';
            }
        }
    }

    if (!empty($warnings)) {
        error_log("validatePlanStructure (user {$userId}): " . count($warnings) . " warnings: " . implode('; ', array_slice($warnings, 0, 5)));
    }

    $planData['weeks'] = $weeks;
    return $planData;
}

/**
 * ПЕРЕСЧЁТ плана с учётом истории тренировок, пропусков и текущей формы.
 *
 * Сохраняет прошлые недели нетронутыми. Удаляет текущую и будущие недели,
 * генерирует только оставшуюся часть плана и вставляет с правильной нумерацией.
 */
function recalculatePlanViaPlanRunAI($userId, $userReason = null) {
    if (!isPlanRunAIConfigured()) {
        throw new Exception("PlanRun AI система недоступна.");
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Ошибка подключения к БД");
    }

    $stmt = $db->prepare("
        SELECT 
            id, username, goal_type, race_distance, race_date, race_target_time,
            target_marathon_date, target_marathon_time, training_start_date,
            gender, birth_year, height_cm, weight_kg, experience_level,
            weekly_base_km, sessions_per_week, preferred_days, preferred_ofp_days,
            has_treadmill, ofp_preference, training_time_pref, health_notes,
            weight_goal_kg, weight_goal_date, health_program, health_plan_weeks,
            current_running_level, running_experience, easy_pace_sec,
            is_first_race_at_distance, last_race_distance, last_race_distance_km,
            last_race_time, last_race_date, device_type
        FROM users WHERE id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("Пользователь не найден");
    }

    if (!empty($user['preferred_days'])) {
        $user['preferred_days'] = json_decode($user['preferred_days'], true) ?: [];
    } else {
        $user['preferred_days'] = [];
    }
    if (!empty($user['preferred_ofp_days'])) {
        $user['preferred_ofp_days'] = json_decode($user['preferred_ofp_days'], true) ?: [];
    } else {
        $user['preferred_ofp_days'] = [];
    }

    $goalType = $user['goal_type'] ?? 'health';

    // --- Определяем границу: понедельник текущей недели ---
    $cutoffDate = (new DateTime())->modify('monday this week')->format('Y-m-d');

    // Сколько недель в текущем плане сохраняется (до cutoff)
    $weekRepo = new WeekRepository($db);
    $keptWeeks = $weekRepo->getMaxWeekNumberBefore($userId, $cutoffDate);

    // Определяем общее число недель плана и сколько генерировать
    $totalPlanWeeks = getSuggestedPlanWeeks($user, $goalType) ?? 12;
    $weeksToGenerate = max(1, $totalPlanWeeks - $keptWeeks);

    // Если есть дата цели — уточняем по ней
    $goalDate = $user['race_date'] ?? $user['target_marathon_date'] ?? $user['weight_goal_date'] ?? null;
    if ($goalDate) {
        $goalTs = strtotime($goalDate);
        $cutoffTs = strtotime($cutoffDate);
        if ($goalTs > $cutoffTs) {
            $weeksToGoal = (int) max(1, ceil(($goalTs - $cutoffTs) / (7 * 86400)));
            $weeksToGenerate = $weeksToGoal;
        }
    }

    $weeksToGenerate = min($weeksToGenerate, 30);

    // --- Собираем структуру сохранённых недель ---
    $keptWeeksSummary = [];
    $maxPlannedLongKm = 0;
    $maxPlannedVolumeKm = 0;

    $stmt = $db->prepare("
        SELECT tpw.week_number, tpw.start_date, tpw.total_volume,
               GROUP_CONCAT(tpd.type ORDER BY tpd.day_of_week) AS day_types
        FROM training_plan_weeks tpw
        LEFT JOIN training_plan_days tpd ON tpd.week_id = tpw.id
        WHERE tpw.user_id = ? AND tpw.start_date < ?
        GROUP BY tpw.id
        ORDER BY tpw.week_number
    ");
    $stmt->bind_param('is', $userId, $cutoffDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $vol = (float) ($r['total_volume'] ?? 0);
        if ($vol > $maxPlannedVolumeKm) $maxPlannedVolumeKm = $vol;
        $keptWeeksSummary[] = [
            'week' => (int) $r['week_number'],
            'volume' => $vol,
            'types' => $r['day_types'] ?? '',
        ];
    }
    $stmt->close();

    // Макс длительная из плана (запланированная)
    $stmt = $db->prepare("
        SELECT MAX(tde.distance_m) / 1000 AS max_long_km
        FROM training_day_exercises tde
        JOIN training_plan_days tpd ON tde.plan_day_id = tpd.id
        JOIN training_plan_weeks tpw ON tpd.week_id = tpw.id
        WHERE tpw.user_id = ? AND tpw.start_date < ? AND tpd.type = 'long' AND tde.category = 'run'
    ");
    $stmt->bind_param('is', $userId, $cutoffDate);
    $stmt->execute();
    $longRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $maxPlannedLongKm = round((float) ($longRow['max_long_km'] ?? 0), 1);

    // Определяем текущую фазу по оригинальному макроциклу
    $currentPhaseInfo = detectCurrentPhase($user, $goalType, $keptWeeks);

    // --- Последние 3 недели ПЛАНА (детальная структура для продолжения) ---
    $lastPlanWeeks = [];
    $stmt = $db->prepare("
        SELECT tpw.week_number, tpw.start_date, tpw.total_volume,
               tpd.day_of_week, tpd.type, tpd.description
        FROM training_plan_weeks tpw
        JOIN training_plan_days tpd ON tpd.week_id = tpw.id
        WHERE tpw.user_id = ? AND tpw.start_date < ?
        ORDER BY tpw.week_number DESC, tpd.day_of_week ASC
    ");
    $stmt->bind_param('is', $userId, $cutoffDate);
    $stmt->execute();
    $res = $stmt->get_result();
    $weekData = [];
    while ($r = $res->fetch_assoc()) {
        $wn = (int) $r['week_number'];
        if (!isset($weekData[$wn])) {
            $weekData[$wn] = [
                'week_number' => $wn,
                'total_volume' => $r['total_volume'],
                'days' => [],
            ];
        }
        $weekData[$wn]['days'][] = [
            'day' => (int) $r['day_of_week'],
            'type' => $r['type'],
            'desc' => $r['description'],
        ];
    }
    $stmt->close();
    // Берём последние 3 недели (они уже в DESC порядке)
    $lastPlanWeeks = array_slice(array_values($weekData), 0, 3);
    // Разворачиваем обратно в хронологическом порядке
    $lastPlanWeeks = array_reverse($lastPlanWeeks);

    // --- Собираем контекст тренировок ---
    require_once __DIR__ . '/../services/ChatContextBuilder.php';
    $ctxBuilder = new ChatContextBuilder($db);

    $recentWorkouts = $ctxBuilder->getRecentWorkouts($userId, 20);
    $compliance = $ctxBuilder->getWeeklyCompliance($userId);

    $fourWeeksAgo = (new DateTime())->modify('-28 days')->format('Y-m-d');
    $today = (new DateTime())->format('Y-m-d');
    $history4w = $ctxBuilder->getWorkoutsHistory($userId, $fourWeeksAgo, $today, 50);

    $avgKm = 0;
    $avgPaceSec = 0;
    $avgHr = 0;
    $avgRating = 0;
    $weekKms = [];
    $paceCount = 0;
    $hrCount = 0;
    $ratingCount = 0;

    foreach ($history4w as $w) {
        $dist = (float) ($w['distance_km'] ?? 0);
        if ($dist > 0) {
            $weekKey = date('Y-W', strtotime($w['date']));
            $weekKms[$weekKey] = ($weekKms[$weekKey] ?? 0) + $dist;
        }
        if (!empty($w['pace']) && $w['pace'] !== '0:00') {
            $parts = explode(':', $w['pace']);
            if (count($parts) >= 2) {
                $avgPaceSec += (int) $parts[0] * 60 + (int) $parts[1];
                $paceCount++;
            }
        }
        $hr = (int) ($w['avg_heart_rate'] ?? 0);
        if ($hr > 0) {
            $avgHr += $hr;
            $hrCount++;
        }
        $r = (int) ($w['rating'] ?? 0);
        if ($r > 0) {
            $avgRating += $r;
            $ratingCount++;
        }
    }

    $weekCount = count($weekKms);
    $avgWeeklyKm = $weekCount > 0 ? round(array_sum($weekKms) / $weekCount, 1) : 0;
    $avgPaceStr = $paceCount > 0
        ? floor(($avgPaceSec / $paceCount) / 60) . ':' . str_pad((int)(($avgPaceSec / $paceCount) % 60), 2, '0', STR_PAD_LEFT)
        : null;
    $avgHrVal = $hrCount > 0 ? (int) round($avgHr / $hrCount) : null;
    $avgRatingVal = $ratingCount > 0 ? round($avgRating / $ratingCount, 1) : null;

    $daysSinceLastWorkout = null;
    if (!empty($recentWorkouts)) {
        $lastDate = $recentWorkouts[0]['date'] ?? null;
        if ($lastDate) {
            $daysSinceLastWorkout = (int) (new DateTime())->diff(new DateTime($lastDate))->days;
        }
    }

    $expLevelForDetraining = $user['experience_level'] ?? 'intermediate';
    $detrainingFactor = $daysSinceLastWorkout !== null
        ? calculateDetrainingFactor($daysSinceLastWorkout, $expLevelForDetraining)
        : null;

    // Лучшая фактически выполненная длительная (полная история плана)
    $bestActualLongKm = 0;
    $historyFrom = $user['training_start_date'] ?? $fourWeeksAgo;
    $historyAll = $ctxBuilder->getWorkoutsHistory($userId, $historyFrom, $today, 500);
    foreach ($historyAll as $w) {
        $type = $w['plan_type'] ?? '';
        $dist = (float) ($w['distance_km'] ?? 0);
        if ($type === 'long' && $dist > $bestActualLongKm) {
            $bestActualLongKm = $dist;
        }
    }
    $bestActualLongKm = round($bestActualLongKm, 1);

    $origStart = $user['training_start_date'] ?? null;
    $currentWeekNumber = null;
    if ($origStart) {
        $origTs = strtotime($origStart);
        $nowTs = time();
        if ($nowTs > $origTs) {
            $currentWeekNumber = (int) ceil(($nowTs - $origTs) / (7 * 86400));
        }
    }

    $recalcContext = [
        'days_since_last_workout' => $daysSinceLastWorkout,
        'detraining_factor' => $detrainingFactor,
        'current_week_number' => $currentWeekNumber,
        'total_plan_weeks' => $totalPlanWeeks,
        'kept_weeks' => $keptWeeks,
        'weeks_to_generate' => $weeksToGenerate,
        'weeks_remaining_to_goal' => $goalDate ? $weeksToGenerate : null,
        'compliance_2w' => [
            'planned' => $compliance['planned'],
            'completed' => $compliance['completed'],
            'pct' => $compliance['planned'] > 0 ? (int) round(($compliance['completed'] / $compliance['planned']) * 100) : 0,
        ],
        'avg_weekly_km_4w' => $avgWeeklyKm,
        'avg_pace_4w' => $avgPaceStr,
        'avg_hr_4w' => $avgHrVal,
        'avg_rating_4w' => $avgRatingVal,
        'recent_workouts' => $recentWorkouts,
        'new_start_date' => $cutoffDate,
        'user_reason' => $userReason,
        'kept_weeks_summary' => $keptWeeksSummary,
        'max_planned_long_km' => $maxPlannedLongKm,
        'max_planned_volume_km' => round($maxPlannedVolumeKm, 1),
        'best_actual_long_km' => $bestActualLongKm,
        'current_phase' => $currentPhaseInfo,
        'acwr' => $ctxBuilder->calculateACWR($userId),
        'last_plan_weeks' => $lastPlanWeeks,
    ];

    $prompt = buildRecalculationPrompt($user, $goalType, $recalcContext);

    error_log("PlanRun AI Recalculate: user={$userId}, kept_weeks={$keptWeeks}, generate={$weeksToGenerate}, detraining=" . ($detrainingFactor ?? 'null'));

    try {
        $response = callAIAPI($prompt, $user, 3, $userId);
        $planData = parseAndRepairPlanJSON($response, $userId);

        return [
            'plan' => $planData,
            'cutoff_date' => $cutoffDate,
            'kept_weeks' => $keptWeeks,
        ];
    } catch (Exception $e) {
        error_log("PlanRun AI Recalculate: Ошибка: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Генерация НОВОГО плана после завершения предыдущего.
 *
 * Отличия от generatePlanViaPlanRunAI():
 *  - Собирает ПОЛНУЮ историю тренировок за весь период старого плана.
 *  - Рассчитывает прогрессию объёмов, пиковые показатели, лучшие результаты.
 *  - Передаёт всё это AI через buildNextPlanPrompt(), чтобы новый план
 *    стартовал с правильного уровня и продолжал прогрессию.
 *  - Сохраняется через saveTrainingPlan() (полная замена, старый план удаляется).
 *
 * @param int         $userId     ID пользователя
 * @param string|null $userGoals  Новые пожелания/цели пользователя (textarea из попапа)
 * @return array planData (weeks[])
 * @throws Exception
 */
function generateNextPlanViaPlanRunAI($userId, $userGoals = null) {
    if (!isPlanRunAIConfigured()) {
        throw new Exception("PlanRun AI система недоступна.");
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Ошибка подключения к БД");
    }

    $stmt = $db->prepare("
        SELECT 
            id, username, goal_type, race_distance, race_date, race_target_time,
            target_marathon_date, target_marathon_time, training_start_date,
            gender, birth_year, height_cm, weight_kg, experience_level,
            weekly_base_km, sessions_per_week, preferred_days, preferred_ofp_days,
            has_treadmill, ofp_preference, training_time_pref, health_notes,
            weight_goal_kg, weight_goal_date, health_program, health_plan_weeks,
            current_running_level, running_experience, easy_pace_sec,
            is_first_race_at_distance, last_race_distance, last_race_distance_km,
            last_race_time, last_race_date, device_type
        FROM users WHERE id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("Пользователь не найден");
    }

    if (!empty($user['preferred_days'])) {
        $user['preferred_days'] = json_decode($user['preferred_days'], true) ?: [];
    } else {
        $user['preferred_days'] = [];
    }
    if (!empty($user['preferred_ofp_days'])) {
        $user['preferred_ofp_days'] = json_decode($user['preferred_ofp_days'], true) ?: [];
    } else {
        $user['preferred_ofp_days'] = [];
    }

    $goalType = $user['goal_type'] ?? 'health';

    // --- Определяем период старого плана ---
    $stmt = $db->prepare(
        "SELECT MIN(start_date) AS plan_start, MAX(start_date) AS last_week_start
         FROM training_plan_weeks WHERE user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $planRange = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $oldPlanStart = $planRange['plan_start'] ?? $user['training_start_date'] ?? null;
    $oldPlanLastWeek = $planRange['last_week_start'] ?? null;

    $oldPlanWeeksCount = 0;
    if ($oldPlanStart && $oldPlanLastWeek) {
        $oldPlanWeeksCount = (int) max(1, ceil(
            (strtotime($oldPlanLastWeek) - strtotime($oldPlanStart)) / (7 * 86400)
        )) + 1;
    }

    // --- Собираем ПОЛНУЮ историю тренировок за весь план ---
    require_once __DIR__ . '/../services/ChatContextBuilder.php';
    $ctxBuilder = new ChatContextBuilder($db);

    $historyFrom = $oldPlanStart ?: (new DateTime())->modify('-12 weeks')->format('Y-m-d');
    $historyTo = (new DateTime())->format('Y-m-d');
    $allWorkouts = $ctxBuilder->getWorkoutsHistory($userId, $historyFrom, $historyTo, 500);

    // Понедельная статистика объёмов
    $weeklyVolumes = [];
    $totalDistance = 0;
    $totalWorkouts = 0;
    $paceSum = 0;
    $paceCount = 0;
    $hrSum = 0;
    $hrCount = 0;
    $ratingSum = 0;
    $ratingCount = 0;
    $bestLongRun = 0;
    $bestTempoPace = null;
    $bestIntervalPace = null;
    $keyWorkoutResults = [];

    foreach ($allWorkouts as $w) {
        $totalWorkouts++;
        $dist = (float) ($w['distance_km'] ?? 0);
        if ($dist > 0) {
            $totalDistance += $dist;
            $weekKey = date('Y-W', strtotime($w['date']));
            $weeklyVolumes[$weekKey] = ($weeklyVolumes[$weekKey] ?? 0) + $dist;
        }

        if (!empty($w['pace']) && $w['pace'] !== '0:00') {
            $parts = explode(':', $w['pace']);
            if (count($parts) >= 2) {
                $paceSec = (int) $parts[0] * 60 + (int) $parts[1];
                $paceSum += $paceSec;
                $paceCount++;

                $type = $w['plan_type'] ?? '';
                if ($type === 'tempo' && ($bestTempoPace === null || $paceSec < $bestTempoPace)) {
                    $bestTempoPace = $paceSec;
                }
                if ($type === 'interval' && ($bestIntervalPace === null || $paceSec < $bestIntervalPace)) {
                    $bestIntervalPace = $paceSec;
                }
            }
        }

        $hr = (int) ($w['avg_heart_rate'] ?? 0);
        if ($hr > 0) { $hrSum += $hr; $hrCount++; }

        $r = (int) ($w['rating'] ?? 0);
        if ($r > 0) { $ratingSum += $r; $ratingCount++; }

        $type = $w['plan_type'] ?? '';
        if ($type === 'long' && $dist > $bestLongRun) {
            $bestLongRun = $dist;
        }

        if (!empty($w['is_key_workout']) && $dist > 0) {
            $keyWorkoutResults[] = [
                'date' => $w['date'],
                'type' => $type,
                'distance_km' => $dist,
                'pace' => $w['pace'] ?? null,
                'rating' => $w['rating'] ?? null,
            ];
        }
    }

    // Прогрессия объёмов: первая vs последняя четверть
    $volumeValues = array_values($weeklyVolumes);
    $peakWeeklyKm = !empty($volumeValues) ? max($volumeValues) : 0;
    $avgWeeklyKm = !empty($volumeValues) ? round(array_sum($volumeValues) / count($volumeValues), 1) : 0;

    $firstQuarterAvg = 0;
    $lastQuarterAvg = 0;
    if (count($volumeValues) >= 4) {
        $q = (int) ceil(count($volumeValues) / 4);
        $firstQuarterAvg = round(array_sum(array_slice($volumeValues, 0, $q)) / $q, 1);
        $lastQuarterAvg = round(array_sum(array_slice($volumeValues, -$q)) / $q, 1);
    }

    // Последние 4 недели (текущая форма)
    $fourWeeksAgo = (new DateTime())->modify('-28 days')->format('Y-m-d');
    $recent4w = $ctxBuilder->getWorkoutsHistory($userId, $fourWeeksAgo, $historyTo, 50);
    $recent4wKms = [];
    $recent4wPaceSum = 0;
    $recent4wPaceCount = 0;
    foreach ($recent4w as $w) {
        $dist = (float) ($w['distance_km'] ?? 0);
        if ($dist > 0) {
            $wk = date('Y-W', strtotime($w['date']));
            $recent4wKms[$wk] = ($recent4wKms[$wk] ?? 0) + $dist;
        }
        if (!empty($w['pace']) && $w['pace'] !== '0:00') {
            $parts = explode(':', $w['pace']);
            if (count($parts) >= 2) {
                $recent4wPaceSum += (int) $parts[0] * 60 + (int) $parts[1];
                $recent4wPaceCount++;
            }
        }
    }
    $recent4wAvgKm = !empty($recent4wKms) ? round(array_sum($recent4wKms) / count($recent4wKms), 1) : 0;
    $recent4wAvgPace = $recent4wPaceCount > 0
        ? floor(($recent4wPaceSum / $recent4wPaceCount) / 60) . ':' . str_pad((int)(($recent4wPaceSum / $recent4wPaceCount) % 60), 2, '0', STR_PAD_LEFT)
        : null;

    // Compliance
    $compliance = $ctxBuilder->getWeeklyCompliance($userId);

    // Последние 10 тренировок для detail
    $recentWorkouts = $ctxBuilder->getRecentWorkouts($userId, 10);

    // Новая дата старта — понедельник текущей недели
    $newStartDate = (new DateTime())->modify('monday this week')->format('Y-m-d');

    // Кол-во недель нового плана
    $newPlanWeeks = getSuggestedPlanWeeks($user, $goalType) ?? 12;
    $goalDate = $user['race_date'] ?? $user['target_marathon_date'] ?? $user['weight_goal_date'] ?? null;
    if ($goalDate) {
        $goalTs = strtotime($goalDate);
        $nowTs = strtotime($newStartDate);
        if ($goalTs > $nowTs) {
            $newPlanWeeks = (int) max(1, ceil(($goalTs - $nowTs) / (7 * 86400)));
        }
    }
    $newPlanWeeks = min($newPlanWeeks, 30);

    $nextPlanContext = [
        'old_plan_start' => $oldPlanStart,
        'old_plan_weeks' => $oldPlanWeeksCount,
        'total_workouts' => $totalWorkouts,
        'total_distance_km' => round($totalDistance, 1),
        'avg_weekly_km' => $avgWeeklyKm,
        'peak_weekly_km' => round($peakWeeklyKm, 1),
        'first_quarter_avg_km' => $firstQuarterAvg,
        'last_quarter_avg_km' => $lastQuarterAvg,
        'best_long_run_km' => round($bestLongRun, 1),
        'best_tempo_pace' => $bestTempoPace !== null
            ? floor($bestTempoPace / 60) . ':' . str_pad($bestTempoPace % 60, 2, '0', STR_PAD_LEFT)
            : null,
        'best_interval_pace' => $bestIntervalPace !== null
            ? floor($bestIntervalPace / 60) . ':' . str_pad($bestIntervalPace % 60, 2, '0', STR_PAD_LEFT)
            : null,
        'avg_pace' => $paceCount > 0
            ? floor(($paceSum / $paceCount) / 60) . ':' . str_pad((int)(($paceSum / $paceCount) % 60), 2, '0', STR_PAD_LEFT)
            : null,
        'avg_hr' => $hrCount > 0 ? (int) round($hrSum / $hrCount) : null,
        'avg_rating' => $ratingCount > 0 ? round($ratingSum / $ratingCount, 1) : null,
        'key_workout_results' => array_slice($keyWorkoutResults, -6),
        'recent_4w_avg_km' => $recent4wAvgKm,
        'recent_4w_avg_pace' => $recent4wAvgPace,
        'compliance' => [
            'planned' => $compliance['planned'],
            'completed' => $compliance['completed'],
            'pct' => $compliance['planned'] > 0 ? (int) round(($compliance['completed'] / $compliance['planned']) * 100) : 0,
        ],
        'recent_workouts' => $recentWorkouts,
        'new_start_date' => $newStartDate,
        'new_plan_weeks' => $newPlanWeeks,
        'user_goals' => $userGoals,
    ];

    $modifiedUser = $user;
    $modifiedUser['training_start_date'] = $newStartDate;
    $modifiedUser['health_plan_weeks'] = $newPlanWeeks;

    $prompt = buildNextPlanPrompt($modifiedUser, $goalType, $nextPlanContext);

    error_log("PlanRun AI NextPlan: user={$userId}, old_weeks={$oldPlanWeeksCount}, new_weeks={$newPlanWeeks}, total_workouts={$totalWorkouts}");

    try {
        $response = callAIAPI($prompt, $modifiedUser, 3, $userId);
        $planData = parseAndRepairPlanJSON($response, $userId);

        return $planData;
    } catch (Exception $e) {
        error_log("PlanRun AI NextPlan: Ошибка: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Определяет текущую фазу макроцикла по количеству прошедших недель.
 *
 * Вызывает computeMacrocycle() с оригинальными данными пользователя,
 * затем сопоставляет $keptWeeks с фазами.
 *
 * @param array  $userData   Данные пользователя (с оригинальным training_start_date)
 * @param string $goalType   Тип цели
 * @param int    $keptWeeks  Сколько недель сохранено (уже пройдено)
 * @return array|null  ['phase'=>..., 'phase_label'=>..., 'weeks_into_phase'=>..., 'weeks_left_in_phase'=>..., 'next_phase'=>..., 'remaining_phases'=>[...], 'long_run_progression'=>[...]]
 */
function detectCurrentPhase(array $userData, string $goalType, int $keptWeeks): ?array {
    $mc = computeMacrocycle($userData, $goalType);
    if (!$mc) {
        return null;
    }

    // Текущая неделя = keptWeeks + 1 (первая неделя, которую будем генерировать)
    $currentWeek = $keptWeeks + 1;

    $currentPhase = null;
    $nextPhase = null;
    $remainingPhases = [];
    $foundCurrent = false;

    foreach ($mc['phases'] as $i => $phase) {
        if (!$foundCurrent && $currentWeek >= $phase['weeks_from'] && $currentWeek <= $phase['weeks_to']) {
            $currentPhase = $phase;
            $foundCurrent = true;
            // Следующая фаза
            if (isset($mc['phases'][$i + 1])) {
                $nextPhase = $mc['phases'][$i + 1];
            }
            // Оставшиеся фазы (включая остаток текущей)
            for ($j = $i; $j < count($mc['phases']); $j++) {
                $remainingPhases[] = $mc['phases'][$j];
            }
        }
    }

    // Если keptWeeks >= totalWeeks (все фазы пройдены)
    if (!$currentPhase) {
        $lastPhase = end($mc['phases']);
        return [
            'phase' => $lastPhase['name'],
            'phase_label' => $lastPhase['label'],
            'weeks_into_phase' => $currentWeek - $lastPhase['weeks_from'],
            'weeks_left_in_phase' => 0,
            'next_phase' => null,
            'next_phase_label' => null,
            'remaining_phases' => [],
            'long_run_progression' => [],
            'recovery_weeks' => [],
        ];
    }

    // Прогрессия длительной для оставшихся недель
    $longRunProgression = [];
    for ($w = $currentWeek; $w <= $mc['total_weeks']; $w++) {
        if (isset($mc['long_run']['by_week'][$w])) {
            $longRunProgression[$w] = $mc['long_run']['by_week'][$w];
        }
    }

    // Разгрузочные недели среди оставшихся
    $remainingRecovery = array_filter($mc['recovery_weeks'], fn($w) => $w >= $currentWeek);

    // Контрольные недели среди оставшихся
    $remainingControl = array_filter($mc['control_weeks'] ?? [], fn($w) => $w >= $currentWeek);

    return [
        'phase' => $currentPhase['name'],
        'phase_label' => $currentPhase['label'],
        'weeks_into_phase' => $currentWeek - $currentPhase['weeks_from'],
        'weeks_left_in_phase' => $currentPhase['weeks_to'] - $currentWeek + 1,
        'next_phase' => $nextPhase ? $nextPhase['name'] : null,
        'next_phase_label' => $nextPhase ? $nextPhase['label'] : null,
        'remaining_phases' => $remainingPhases,
        'long_run_progression' => $longRunProgression,
        'recovery_weeks' => array_values($remainingRecovery),
        'control_weeks' => array_values($remainingControl),
        'peak_volume_km' => $mc['peak_volume_km'],
        'start_volume_km' => $mc['start_volume_km'],
    ];
}

/**
 * Парсинг ответа от PlanRun AI API
 *
 * @param string $response JSON ответ от PlanRun AI API
 * @return array Распарсенный план
 */
function parsePlanRunAIResponse($response) {
    // PlanRun AI API уже возвращает валидный JSON, просто парсим
    $plan = json_decode($response, true);
    
    if (!$plan) {
        throw new Exception("Не удалось распарсить ответ от PlanRun AI API");
    }
    
    return $plan;
}

function decodeGeneratedPlanResponse(string $response, array $userData, string $sourceLabel = 'PlanRun AI'): array {
    $plan = parseAndRepairPlanJSON($response, 0);
    $expectedWeeks = !empty($userData['plan_skeleton']['weeks']) && is_array($userData['plan_skeleton']['weeks'])
        ? count($userData['plan_skeleton']['weeks'])
        : 0;

    if ($expectedWeeks > 0) {
        $actualWeeks = count($plan['weeks'] ?? []);
        if ($actualWeeks < $expectedWeeks) {
            throw new Exception("Неполный план от {$sourceLabel}: ожидалось {$expectedWeeks} недель, получено {$actualWeeks}.");
        }
        if ($actualWeeks > $expectedWeeks) {
            $plan['weeks'] = array_values(array_slice($plan['weeks'], 0, $expectedWeeks));
        }
    }

    return $plan;
}

function buildPlanValidationContext(array $userData): array {
    return [
        'goal_type' => $userData['goal_type'] ?? ($userData['training_state']['goal_type'] ?? 'health'),
        'preferred_days' => $userData['preferred_days'] ?? ($userData['training_state']['preferred_days'] ?? []),
        'sessions_per_week' => (int) ($userData['sessions_per_week'] ?? ($userData['training_state']['sessions_per_week'] ?? 0)),
        'expected_skeleton' => $userData['plan_skeleton'] ?? null,
    ];
}

function buildTrainingStateForValidation(array $userData): array {
    $trainingState = is_array($userData['training_state'] ?? null) ? $userData['training_state'] : [];

    foreach ([
        'goal_type',
        'race_distance',
        'goal_pace',
        'goal_pace_sec',
        'preferred_days',
        'sessions_per_week',
        'plan_intent_contract',
        'load_policy',
    ] as $key) {
        if (!array_key_exists($key, $trainingState) && array_key_exists($key, $userData)) {
            $trainingState[$key] = $userData[$key];
        }
    }

    return $trainingState;
}

function normalizePlanGenerationUserFields(array $user): array {
    foreach (['preferred_days', 'preferred_ofp_days'] as $field) {
        if (!empty($user[$field]) && is_string($user[$field])) {
            $user[$field] = json_decode($user[$field], true) ?: [];
        } elseif (empty($user[$field]) || !is_array($user[$field])) {
            $user[$field] = [];
        }
    }

    return $user;
}

function hydratePlanGenerationUserState(mysqli $db, array $user): array {
    $user = normalizePlanGenerationUserFields($user);
    $builder = new TrainingStateBuilder($db);
    $user['training_state'] = $builder->buildForUser($user);
    return $user;
}

function attachPlanSkeleton(array $user, string $goalType, array $options = []): array {
    $user = normalizePlanGenerationUserFields($user);
    $builder = new PlanSkeletonBuilder();
    $user['plan_skeleton'] = $builder->build($user, $goalType, $options);
    return $user;
}

function normalizeGeneratedPlanForValidation(array $plan, array $user, string $startDate, int $weekNumberOffset = 0): array {
    $user = normalizePlanGenerationUserFields($user);
    $expectedSkeleton = $user['plan_skeleton'] ?? null;
    $trainingState = buildTrainingStateForValidation($user);

    $normalized = normalizeTrainingPlan($plan, $startDate, $weekNumberOffset, $user, $expectedSkeleton);
    $normalized = applyTrainingStatePaceRepairs($normalized, $trainingState);
    $normalized = applyTrainingStateWorkoutDetailFallbacks($normalized, $trainingState);
    $normalized = applyTrainingStateLoadRepairs($normalized, $trainingState);
    $normalized = applyTrainingStateMinimumDistanceRepairs($normalized, $trainingState);

    return $normalized;
}

function buildCorrectiveRegenerationPrompt(string $basePrompt, array $validationIssues, array $planData): string {
    $lines = [
        $basePrompt,
        '',
        '═══ VALIDATION FAILURE ═══',
        'Исправь план так, чтобы устранить критические нарушения ниже. Верни только исправленный JSON-план.',
    ];

    foreach ($validationIssues as $issue) {
        $severity = strtoupper((string) ($issue['severity'] ?? 'warning'));
        $code = (string) ($issue['code'] ?? 'unknown_issue');
        $message = (string) ($issue['message'] ?? '');
        $lines[] = "- {$severity} {$code}: {$message}";
    }

    $lines[] = '';
    $lines[] = 'Текущий проблемный план:';
    $lines[] = json_encode($planData, JSON_UNESCAPED_UNICODE);

    return implode("\n", $lines);
}

function maybeApplyCorrectiveRegenerationToPlan(
    array $planData,
    array $userData,
    string $basePrompt,
    string $startDate,
    int $weekNumberOffset,
    $userId = 0,
    $generator = null,
    string $sourceLabel = 'PlanRun AI',
    string $generationMode = 'generate'
): array {
    $validationContext = buildPlanValidationContext($userData);
    $trainingState = buildTrainingStateForValidation($userData);

    $normalized = normalizeTrainingPlan($planData, $startDate, $weekNumberOffset, $userData, $validationContext['expected_skeleton']);
    $issues = collectNormalizedPlanValidationIssues($normalized, $trainingState, $validationContext);
    $needsRepair = shouldRunCorrectiveRegeneration($issues);

    $metadata = [
        'generation_mode' => $generationMode,
        'repair_count' => 0,
        'corrective_regeneration_used' => false,
        'vdot_source' => $trainingState['vdot_source'] ?? null,
        'validation_issue_count_before' => count($issues),
    ];

    if (!$needsRepair) {
        $planData['_generation_metadata'] = $metadata;
        return $planData;
    }

    if (!is_callable($generator)) {
        $generator = static function (string $prompt) use ($userData, $userId): string {
            $resolvedUserId = is_numeric($userId) ? (int) $userId : null;
            return callAIAPI($prompt, $userData, 1, $resolvedUserId);
        };
    }

    $correctivePrompt = buildCorrectiveRegenerationPrompt($basePrompt, $issues, $planData);
    $rawCorrected = $generator($correctivePrompt);
    if (!is_string($rawCorrected) || trim($rawCorrected) === '') {
        $planData['_generation_metadata'] = $metadata;
        return $planData;
    }

    try {
        $correctedPlan = decodeGeneratedPlanResponse($rawCorrected, $userData, $sourceLabel);
    } catch (Exception $e) {
        $planData['_generation_metadata'] = $metadata;
        return $planData;
    }

    $normalizedCorrected = normalizeTrainingPlan($correctedPlan, $startDate, $weekNumberOffset, $userData, $validationContext['expected_skeleton']);
    $correctedIssues = collectNormalizedPlanValidationIssues($normalizedCorrected, $trainingState, $validationContext);

    if (scoreValidationIssues($correctedIssues) <= scoreValidationIssues($issues)) {
        $metadata['repair_count'] = 1;
        $metadata['corrective_regeneration_used'] = true;
        $metadata['validation_issue_count_after'] = count($correctedIssues);
        $correctedPlan['_generation_metadata'] = $metadata;
        return $correctedPlan;
    }

    $planData['_generation_metadata'] = $metadata;
    return $planData;
}

function isRunningRelevantWorkoutEntry(array $workout): bool {
    $distanceKm = (float) ($workout['distance_km'] ?? 0.0);
    if ($distanceKm <= 0.0) {
        return false;
    }

    $source = trim(mb_strtolower((string) ($workout['source'] ?? ''), 'UTF-8'));
    if ($source === 'manual') {
        return true;
    }

    $activityType = trim(mb_strtolower((string) ($workout['activity_type'] ?? ''), 'UTF-8'));
    if (in_array($activityType, ['walking', 'walk', 'hiking'], true)) {
        return false;
    }
    if (in_array($activityType, ['running', 'run', 'trail running', 'treadmill'], true)) {
        return true;
    }

    $planType = trim(mb_strtolower((string) ($workout['plan_type'] ?? ''), 'UTF-8'));
    return in_array($planType, ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race', 'free'], true);
}

function resolveRecalculationCutoffDateValue(string $today, bool $hasRunningWorkoutToday): string {
    if (!$hasRunningWorkoutToday) {
        return $today;
    }

    return date('Y-m-d', strtotime($today . ' +1 day'));
}
