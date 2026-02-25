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

const SAVER_ALLOWED_TYPES = ['rest', 'tempo', 'interval', 'long', 'race', 'other', 'free', 'easy', 'sbu', 'fartlek', 'control'];

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
function saveTrainingPlan($db, $userId, $planData, $startDate) {
    $normalized = normalizeTrainingPlan($planData, $startDate);

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
                            "INSERT INTO training_day_exercises (user_id, plan_day_id, category, name, distance_m, duration_sec, notes)
                             VALUES (?, ?, 'run', ?, ?, ?, ?)"
                        );
                        $distM = $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
                        $durSec = $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
                        $stmt->bind_param('iisiis',
                            $userId, $dayId, $ex['name'], $distM, $durSec, $ex['notes']
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
function saveRecalculatedPlan($db, $userId, array $newPlanData, string $cutoffDate) {
    $lastKeptWeek = 0;
    $stmt = $db->prepare(
        "SELECT MAX(week_number) AS max_wn FROM training_plan_weeks WHERE user_id = ? AND start_date < ?"
    );
    $stmt->bind_param('is', $userId, $cutoffDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $lastKeptWeek = (int) ($row['max_wn'] ?? 0);

    $normalized = normalizeTrainingPlan($newPlanData, $cutoffDate, $lastKeptWeek);

    foreach ($normalized['warnings'] as $w) {
        error_log("saveRecalculatedPlan (user {$userId}): {$w}");
    }

    $db->begin_transaction();

    try {
        $futureWeekIds = [];
        $stmt = $db->prepare(
            "SELECT id FROM training_plan_weeks WHERE user_id = ? AND start_date >= ?"
        );
        $stmt->bind_param('is', $userId, $cutoffDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $futureWeekIds[] = (int) $r['id'];
        }
        $stmt->close();

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
                            "INSERT INTO training_day_exercises (user_id, plan_day_id, category, name, distance_m, duration_sec, notes)
                             VALUES (?, ?, 'run', ?, ?, ?, ?)"
                        );
                        $distM = $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
                        $durSec = $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
                        $stmt->bind_param('iisiis',
                            $userId, $dayId, $ex['name'], $distM, $durSec, $ex['notes']
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
            . "Сохранено старых недель: {$lastKeptWeek}, добавлено новых: " . count($normalized['weeks']));

    } catch (Exception $e) {
        $db->rollback();
        error_log("saveRecalculatedPlan ROLLBACK (user {$userId}): " . $e->getMessage());
        throw $e;
    }
}
