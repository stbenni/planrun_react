<?php
/**
 * Сохранение плана тренировок в БД.
 *
 * Использует plan_normalizer.php для нормализации сырого плана от ИИ,
 * затем сохраняет нормализованные данные в таблицы:
 *   training_plan_weeks → training_plan_days → training_day_exercises
 *
 * Вся операция обёрнута в транзакцию — при ошибке откатывается,
 * старый план остаётся нетронутым.
 */

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../cache_config.php';
require_once __DIR__ . '/plan_normalizer.php';
require_once __DIR__ . '/../repositories/WeekRepository.php';

const SAVER_ALLOWED_TYPES = ['rest', 'tempo', 'interval', 'long', 'race', 'other', 'free', 'easy', 'sbu', 'fartlek', 'control', 'walking'];

/**
 * Сохранение плана тренировок в БД.
 *
 * @param mysqli $db       Соединение с БД
 * @param int    $userId   ID пользователя
 * @param array  $planData Данные плана из RAG API (сырой или уже нормализованный)
 * @param string $startDate Дата начала тренировок (YYYY-MM-DD)
 * @return void
 * @throws Exception
 */
function saveTrainingPlan($db, $userId, $planData, $startDate, ?array $userPreferences = null) {
    $normalized = normalizeTrainingPlan($planData, $startDate, 0, $userPreferences);

    foreach ($normalized['warnings'] as $w) {
        error_log("saveTrainingPlan (user {$userId}): {$w}");
    }

    $db->begin_transaction();

    try {
        // Удаляем старый план пользователя
        $stmt = $db->prepare("DELETE FROM training_day_exercises WHERE user_id = ? AND plan_day_id IN (SELECT id FROM training_plan_days WHERE user_id = ?)");
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("DELETE FROM training_plan_days WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("DELETE FROM training_plan_weeks WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        foreach ($normalized['weeks'] as $week) {
            $stmt = $db->prepare(
                "INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)"
            );
            $wn = $week['week_number'];
            $sd = $week['start_date'];
            $tv = $week['total_volume'];
            $stmt->bind_param('iisd', $userId, $wn, $sd, $tv);
            $stmt->execute();
            $weekId = $db->insert_id;
            $stmt->close();

            if (!$weekId) {
                throw new Exception("Ошибка создания недели {$wn}");
            }

            foreach ($week['days'] as $day) {
                $type = $day['type'];
                if (!in_array($type, SAVER_ALLOWED_TYPES, true)) {
                    error_log("saveTrainingPlan: invalid type '{$type}' for user {$userId}, defaulting to 'rest'");
                    $type = 'rest';
                }

                $stmt = $db->prepare(
                    "INSERT INTO training_plan_days (user_id, week_id, day_of_week, type, description, is_key_workout, date)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $dow  = $day['day_of_week'];
                $desc = $day['description'];
                $isKey = $day['is_key_workout'] ? 1 : 0;
                $date = $day['date'];
                $stmt->bind_param('iiissis', $userId, $weekId, $dow, $type, $desc, $isKey, $date);
                $stmt->execute();

                if ($stmt->error) {
                    $stmt->close();
                    throw new Exception("Ошибка создания дня {$dow} для недели {$wn}: " . $stmt->error);
                }

                $dayId = $db->insert_id;
                $stmt->close();

                if (!$dayId) {
                    throw new Exception("Ошибка создания дня {$dow} для недели {$wn}: insert_id = 0");
                }

                foreach ($day['exercises'] as $ex) {
                    if ($ex['category'] === 'run') {
                        $stmt = $db->prepare(
                            "INSERT INTO training_day_exercises (user_id, plan_day_id, category, name, distance_m, duration_sec, pace, notes)
                             VALUES (?, ?, 'run', ?, ?, ?, ?, ?)"
                        );
                        $distM = $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
                        $durSec = $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
                        $paceVal = $ex['pace'] ?? null;
                        $stmt->bind_param('iisiiss',
                            $userId, $dayId, $ex['name'], $distM, $durSec, $paceVal, $ex['notes']
                        );
                        $stmt->execute();
                        if ($stmt->error) {
                            error_log("saveTrainingPlan: run exercise error: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare(
                            "INSERT INTO training_day_exercises
                             (user_id, plan_day_id, exercise_id, category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index)
                             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)"
                        );
                        $sets = $ex['sets'] !== null ? (int) $ex['sets'] : null;
                        $reps = $ex['reps'] !== null ? (int) $ex['reps'] : null;
                        $distM = $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
                        $durSec = $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
                        $weightKg = $ex['weight_kg'] !== null ? (float) $ex['weight_kg'] : null;
                        $orderIdx = (int) ($ex['order_index'] ?? 0);
                        $stmt->bind_param('iissiiidisi',
                            $userId, $dayId, $ex['category'], $ex['name'],
                            $sets, $reps, $distM, $durSec, $weightKg,
                            $ex['notes'], $orderIdx
                        );
                        $stmt->execute();
                        if ($stmt->error) {
                            error_log("saveTrainingPlan: exercise error: " . $stmt->error);
                        }
                        $stmt->close();
                    }
                }
            }
        }

        $db->commit();

        Cache::delete("training_plan_{$userId}");

        error_log("saveTrainingPlan: План сохранён для пользователя {$userId}, недель: " . count($normalized['weeks']));

    } catch (Exception $e) {
        $db->rollback();
        error_log("saveTrainingPlan ROLLBACK (user {$userId}): " . $e->getMessage());
        throw $e;
    }
}

/**
 * Пересчёт плана: сохраняет прошлые недели, заменяет текущую и будущие.
 *
 * @param mysqli $db
 * @param int    $userId
 * @param array  $newPlanData  Новые недели от AI (нумерация с 1 внутри, будет пересчитана)
 * @param string $cutoffDate   Дата-граница (понедельник текущей недели): всё с этой даты — удаляется и заменяется
 * @return void
 * @throws Exception
 */
function saveRecalculatedPlan($db, $userId, array $newPlanData, string $cutoffDate, ?array $userPreferences = null, ?string $mutableFromDate = null) {
    $weekRepo = new WeekRepository($db);
    $lastKeptWeek = $weekRepo->getMaxWeekNumberBefore($userId, $cutoffDate);

    $normalized = normalizeTrainingPlan($newPlanData, $cutoffDate, $lastKeptWeek, $userPreferences);
    $preservedCurrentWeekDays = [];
    if (is_string($mutableFromDate) && $mutableFromDate !== '' && $mutableFromDate > $cutoffDate) {
        $preservedCurrentWeekDays = loadPreservedRecalculationDays($db, $userId, $cutoffDate, $mutableFromDate);
        if (!empty($preservedCurrentWeekDays) && !empty($normalized['weeks'][0]['days'])) {
            $normalized['weeks'][0] = mergePreservedDaysIntoRecalculatedWeek(
                $normalized['weeks'][0],
                $preservedCurrentWeekDays,
                $mutableFromDate
            );
        }
    }

    foreach ($normalized['warnings'] as $w) {
        error_log("saveRecalculatedPlan (user {$userId}): {$w}");
    }

    $db->begin_transaction();

    try {
        $futureWeekIds = $weekRepo->getFutureWeekIds($userId, $cutoffDate);

        if (!empty($futureWeekIds)) {
            $placeholders = implode(',', array_fill(0, count($futureWeekIds), '?'));
            $types = str_repeat('i', count($futureWeekIds));

            $futureDayIds = [];
            $stmt = $db->prepare(
                "SELECT id FROM training_plan_days WHERE user_id = ? AND week_id IN ({$placeholders})"
            );
            $stmt->bind_param('i' . $types, $userId, ...$futureWeekIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $futureDayIds[] = (int) $r['id'];
            }
            $stmt->close();

            if (!empty($futureDayIds)) {
                $dayPlaceholders = implode(',', array_fill(0, count($futureDayIds), '?'));
                $dayTypes = str_repeat('i', count($futureDayIds));
                $stmt = $db->prepare(
                    "DELETE FROM training_day_exercises WHERE user_id = ? AND plan_day_id IN ({$dayPlaceholders})"
                );
                $stmt->bind_param('i' . $dayTypes, $userId, ...$futureDayIds);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $db->prepare(
                "DELETE FROM training_plan_days WHERE user_id = ? AND week_id IN ({$placeholders})"
            );
            $stmt->bind_param('i' . $types, $userId, ...$futureWeekIds);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare(
                "DELETE FROM training_plan_weeks WHERE user_id = ? AND start_date >= ?"
            );
            $stmt->bind_param('is', $userId, $cutoffDate);
            $stmt->execute();
            $stmt->close();
        }

        foreach ($normalized['weeks'] as $week) {
            $stmt = $db->prepare(
                "INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume) VALUES (?, ?, ?, ?)"
            );
            $wn = $week['week_number'];
            $sd = $week['start_date'];
            $tv = $week['total_volume'];
            $stmt->bind_param('iisd', $userId, $wn, $sd, $tv);
            $stmt->execute();
            $weekId = $db->insert_id;
            $stmt->close();

            if (!$weekId) {
                throw new Exception("Ошибка создания недели {$wn}");
            }

            foreach ($week['days'] as $day) {
                $type = $day['type'];
                if (!in_array($type, SAVER_ALLOWED_TYPES, true)) {
                    error_log("saveRecalculatedPlan: invalid type '{$type}', defaulting to 'rest'");
                    $type = 'rest';
                }

                $stmt = $db->prepare(
                    "INSERT INTO training_plan_days (user_id, week_id, day_of_week, type, description, is_key_workout, date)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $dow  = $day['day_of_week'];
                $desc = $day['description'];
                $isKey = $day['is_key_workout'] ? 1 : 0;
                $date = $day['date'];
                $stmt->bind_param('iiissis', $userId, $weekId, $dow, $type, $desc, $isKey, $date);
                $stmt->execute();

                if ($stmt->error) {
                    $stmt->close();
                    throw new Exception("Ошибка создания дня {$dow} для недели {$wn}: " . $stmt->error);
                }

                $dayId = $db->insert_id;
                $stmt->close();

                if (!$dayId) {
                    throw new Exception("Ошибка создания дня {$dow} для недели {$wn}: insert_id = 0");
                }

                foreach ($day['exercises'] as $ex) {
                    if ($ex['category'] === 'run') {
                        $stmt = $db->prepare(
                            "INSERT INTO training_day_exercises (user_id, plan_day_id, category, name, distance_m, duration_sec, pace, notes)
                             VALUES (?, ?, 'run', ?, ?, ?, ?, ?)"
                        );
                        $distM = $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
                        $durSec = $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
                        $paceVal = $ex['pace'] ?? null;
                        $stmt->bind_param('iisiiss',
                            $userId, $dayId, $ex['name'], $distM, $durSec, $paceVal, $ex['notes']
                        );
                        $stmt->execute();
                        if ($stmt->error) {
                            error_log("saveRecalculatedPlan: run exercise error: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare(
                            "INSERT INTO training_day_exercises
                             (user_id, plan_day_id, exercise_id, category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index)
                             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)"
                        );
                        $sets = $ex['sets'] !== null ? (int) $ex['sets'] : null;
                        $reps = $ex['reps'] !== null ? (int) $ex['reps'] : null;
                        $distM = $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
                        $durSec = $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
                        $weightKg = $ex['weight_kg'] !== null ? (float) $ex['weight_kg'] : null;
                        $orderIdx = (int) ($ex['order_index'] ?? 0);
                        $stmt->bind_param('iissiiidisi',
                            $userId, $dayId, $ex['category'], $ex['name'],
                            $sets, $reps, $distM, $durSec, $weightKg,
                            $ex['notes'], $orderIdx
                        );
                        $stmt->execute();
                        if ($stmt->error) {
                            error_log("saveRecalculatedPlan: exercise error: " . $stmt->error);
                        }
                        $stmt->close();
                    }
                }
            }
        }

        $db->commit();

        Cache::delete("training_plan_{$userId}");

        error_log("saveRecalculatedPlan: Пересчёт сохранён для пользователя {$userId}. "
            . "Сохранено старых недель: {$lastKeptWeek}, добавлено новых: " . count($normalized['weeks'])
            . ", сохранено прошлых дней текущей недели: " . count($preservedCurrentWeekDays));

    } catch (Exception $e) {
        $db->rollback();
        error_log("saveRecalculatedPlan ROLLBACK (user {$userId}): " . $e->getMessage());
        throw $e;
    }
}

function loadPreservedRecalculationDays(mysqli $db, int $userId, string $cutoffDate, string $mutableFromDate): array
{
    $stmt = $db->prepare(
        "SELECT id, date, day_of_week, type, description, is_key_workout
         FROM training_plan_days
         WHERE user_id = ? AND date >= ? AND date < ?
         ORDER BY date ASC, id ASC"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iss', $userId, $cutoffDate, $mutableFromDate);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    if (empty($rows)) {
        return [];
    }

    $planDayIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $exerciseMap = [];
    if (!empty($planDayIds)) {
        $placeholders = implode(',', array_fill(0, count($planDayIds), '?'));
        $types = str_repeat('i', count($planDayIds));
        $stmt = $db->prepare(
            "SELECT plan_day_id, category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index
             FROM training_day_exercises
             WHERE user_id = ? AND plan_day_id IN ({$placeholders})
             ORDER BY plan_day_id ASC, order_index ASC, id ASC"
        );
        if ($stmt) {
            $stmt->bind_param('i' . $types, $userId, ...$planDayIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $dayId = (int) ($row['plan_day_id'] ?? 0);
                $exerciseMap[$dayId][] = [
                    'category' => $row['category'] ?? null,
                    'name' => $row['name'] ?? null,
                    'sets' => isset($row['sets']) ? (int) $row['sets'] : null,
                    'reps' => isset($row['reps']) ? (int) $row['reps'] : null,
                    'distance_m' => isset($row['distance_m']) ? (int) $row['distance_m'] : null,
                    'duration_sec' => isset($row['duration_sec']) ? (int) $row['duration_sec'] : null,
                    'weight_kg' => isset($row['weight_kg']) ? (float) $row['weight_kg'] : null,
                    'pace' => $row['pace'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'order_index' => isset($row['order_index']) ? (int) $row['order_index'] : 0,
                ];
            }
            $stmt->close();
        }
    }

    $days = [];
    foreach ($rows as $row) {
        $dayId = (int) ($row['id'] ?? 0);
        $exercises = $exerciseMap[$dayId] ?? [];
        $runExercise = null;
        foreach ($exercises as $exercise) {
            if (($exercise['category'] ?? null) === 'run') {
                $runExercise = $exercise;
                break;
            }
        }

        $day = [
            'date' => (string) ($row['date'] ?? ''),
            'day_of_week' => (int) ($row['day_of_week'] ?? 0),
            'type' => (string) ($row['type'] ?? 'rest'),
            'description' => (string) ($row['description'] ?? ''),
            'distance_km' => $runExercise !== null && !empty($runExercise['distance_m'])
                ? round(((int) $runExercise['distance_m']) / 1000, 1)
                : null,
            'duration_minutes' => $runExercise !== null && !empty($runExercise['duration_sec'])
                ? (int) round(((int) $runExercise['duration_sec']) / 60)
                : null,
            'pace' => $runExercise['pace'] ?? null,
            'is_key_workout' => !empty($row['is_key_workout']),
            'exercises' => $exercises,
        ];

        $days[] = rebuildNormalizedDayArtifacts($day);
    }

    usort(
        $days,
        static function (array $left, array $right): int {
            $dateCompare = strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return ((int) ($left['day_of_week'] ?? 0)) <=> ((int) ($right['day_of_week'] ?? 0));
        }
    );

    return $days;
}

function mergePreservedDaysIntoRecalculatedWeek(array $week, array $preservedDays, string $mutableFromDate): array
{
    $futureDays = array_values(array_filter(
        (array) ($week['days'] ?? []),
        static fn(array $day): bool => ((string) ($day['date'] ?? '')) >= $mutableFromDate
    ));

    $merged = array_merge($preservedDays, $futureDays);
    usort(
        $merged,
        static function (array $left, array $right): int {
            $dateCompare = strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return ((int) ($left['day_of_week'] ?? 0)) <=> ((int) ($right['day_of_week'] ?? 0));
        }
    );

    $week['days'] = $merged;
    $week['total_volume'] = calculateNormalizedWeekVolume($merged);
    if (isset($week['actual_volume_km'])) {
        $week['actual_volume_km'] = calculateNormalizedWeekVolume($merged);
    }
    if (isset($week['target_volume_km'])) {
        $week['target_volume_km'] = max(
            (float) $week['target_volume_km'],
            calculateNormalizedWeekVolume($merged)
        );
    }

    return $week;
}
