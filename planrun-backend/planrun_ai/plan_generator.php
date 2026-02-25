<?php
/**
 * Генератор планов тренировок через PlanRun AI
 * Использует локальную LLM (Qwen3 14B) с RAG для создания персональных планов
 */

require_once __DIR__ . '/planrun_ai_integration.php';
require_once __DIR__ . '/prompt_builder.php';
require_once __DIR__ . '/../db_config.php';

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
    
    // Строим промпт
    $prompt = buildTrainingPlanPrompt($user, $goalType);
    
    error_log("PlanRun AI Generator: Промпт построен для пользователя {$userId}, длина: " . strlen($prompt) . " символов");
    
    // Вызываем PlanRun AI API
    try {
        $response = callAIAPI($prompt, $user, 3, $userId);
        error_log("PlanRun AI Generator: Получен ответ от PlanRun AI API, длина: " . strlen($response) . " символов");
        
        // Парсим JSON ответ
        $planData = json_decode($response, true);
        
        if (!$planData || !isset($planData['weeks'])) {
            error_log("PlanRun AI Generator: Неверный формат ответа. Ответ: " . substr($response, 0, 500));
            throw new Exception("Неверный формат ответа от PlanRun AI API");
        }
        
        return $planData;
        
    } catch (Exception $e) {
        error_log("PlanRun AI Generator: Ошибка при генерации плана: " . $e->getMessage());
        throw $e;
    }
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
    $stmt = $db->prepare(
        "SELECT MAX(week_number) AS max_wn FROM training_plan_weeks WHERE user_id = ? AND start_date < ?"
    );
    $stmt->bind_param('is', $userId, $cutoffDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $keptWeeks = (int) ($row['max_wn'] ?? 0);

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
            $weekKey = date('W', strtotime($w['date']));
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

    $detrainingFactor = $daysSinceLastWorkout !== null
        ? calculateDetrainingFactor($daysSinceLastWorkout)
        : null;

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
    ];

    $prompt = buildRecalculationPrompt($user, $goalType, $recalcContext);

    error_log("PlanRun AI Recalculate: user={$userId}, kept_weeks={$keptWeeks}, generate={$weeksToGenerate}, detraining=" . ($detrainingFactor ?? 'null'));

    try {
        $response = callAIAPI($prompt, $user, 3, $userId);
        $planData = json_decode($response, true);

        if (!$planData || !isset($planData['weeks'])) {
            error_log("PlanRun AI Recalculate: Неверный формат ответа: " . substr($response, 0, 500));
            throw new Exception("Неверный формат ответа от PlanRun AI API при пересчёте");
        }

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
            $wk = date('W', strtotime($w['date']));
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
        $planData = json_decode($response, true);

        if (!$planData || !isset($planData['weeks'])) {
            error_log("PlanRun AI NextPlan: Неверный формат ответа: " . substr($response, 0, 500));
            throw new Exception("Неверный формат ответа от PlanRun AI API при генерации нового плана");
        }

        return $planData;
    } catch (Exception $e) {
        error_log("PlanRun AI NextPlan: Ошибка: " . $e->getMessage());
        throw $e;
    }
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
