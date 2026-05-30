<?php
/**
 * CoachTemplateService — шаблоны тренировок тренера + bulk-assign.
 *
 * Используется CoachController.listWorkoutTemplates / bulkAssignTraining.
 * Шаблоны per-coach (свои у каждого тренера). Bulk-assign применяет шаблон
 * к выбранным атлетам на указанную дату: создаёт training_plan_days +
 * training_day_exercises.
 *
 * Conflict policy: preflight (overwrite=false) возвращает diff с конфликтами;
 * UI показывает confirm-dialog; повторный вызов overwrite=true применяет.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/WeekService.php';

class CoachTemplateService extends BaseService {

    /**
     * Все шаблоны тренера (с упражнениями).
     */
    public function getTemplates(int $coachId): array {
        $stmt = $this->db->prepare(
            "SELECT id, coach_id, name, type, distance, emoji, description,
                    is_key_workout, uses_count, created_at, updated_at
             FROM coach_workout_templates
             WHERE coach_id = ?
             ORDER BY uses_count DESC, id ASC"
        );
        $stmt->bind_param('i', $coachId);
        $stmt->execute();
        $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($templates) === 0) return ['templates' => []];

        $ids = array_map(static fn($t) => (int) $t['id'], $templates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $exStmt = $this->db->prepare(
            "SELECT id, template_id, exercise_id, category, name,
                    sets, reps, distance_m, duration_sec, weight_kg, pace,
                    notes, order_index
             FROM coach_workout_template_exercises
             WHERE template_id IN ($placeholders)
             ORDER BY template_id ASC, order_index ASC, id ASC"
        );
        $exStmt->bind_param($types, ...$ids);
        $exStmt->execute();
        $exercisesAll = $exStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $exStmt->close();

        $byTemplate = [];
        foreach ($exercisesAll as $ex) {
            $tid = (int) $ex['template_id'];
            $byTemplate[$tid] = $byTemplate[$tid] ?? [];
            $byTemplate[$tid][] = $ex;
        }

        foreach ($templates as &$t) {
            $tid = (int) $t['id'];
            $t['distance'] = $t['distance'] !== null ? (float) $t['distance'] : null;
            $t['is_key_workout'] = (int) $t['is_key_workout'];
            $t['uses_count'] = (int) $t['uses_count'];
            $t['exercises'] = $byTemplate[$tid] ?? [];
        }
        unset($t);

        return ['templates' => $templates];
    }

    /**
     * Создать или обновить шаблон.
     * Если в data есть template_id и он принадлежит coach — UPDATE; иначе INSERT.
     * Упражнения шаблона перезаписываются полностью.
     */
    public function createTemplate(int $coachId, array $data): array {
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? '');
        if ($name === '') $this->throwException('name обязателен', 400);
        if (!$this->isValidType($type)) $this->throwException('type невалиден', 400);

        $distance = isset($data['distance']) && $data['distance'] !== '' && $data['distance'] !== null
            ? (float) $data['distance'] : null;
        $emoji = isset($data['emoji']) && $data['emoji'] !== null ? trim((string) $data['emoji']) : null;
        if ($emoji === '') $emoji = null;
        $description = isset($data['description']) && $data['description'] !== null
            ? (string) $data['description'] : null;
        if ($description === '') $description = null;
        $isKey = !empty($data['is_key_workout']) ? 1 : 0;

        $tid = isset($data['template_id']) ? (int) $data['template_id'] : 0;
        $existing = $tid > 0 ? $this->getTemplateOwned($coachId, $tid) : null;

        if ($existing) {
            // UPDATE
            $stmt = $this->db->prepare(
                "UPDATE coach_workout_templates
                 SET name = ?, type = ?, distance = ?, emoji = ?, description = ?, is_key_workout = ?
                 WHERE id = ? AND coach_id = ?"
            );
            $stmt->bind_param('ssdssiii', $name, $type, $distance, $emoji, $description, $isKey, $tid, $coachId);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) $this->throwException('Ошибка обновления шаблона', 500);
            // Полная перезапись exercises
            $del = $this->db->prepare("DELETE FROM coach_workout_template_exercises WHERE template_id = ?");
            $del->bind_param('i', $tid);
            $del->execute();
            $del->close();
        } else {
            // INSERT
            $stmt = $this->db->prepare(
                "INSERT INTO coach_workout_templates
                   (coach_id, name, type, distance, emoji, description, is_key_workout)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issdssi', $coachId, $name, $type, $distance, $emoji, $description, $isKey);
            $ok = $stmt->execute();
            $tid = (int) $this->db->insert_id;
            $stmt->close();
            if (!$ok) $this->throwException('Ошибка создания шаблона', 500);
        }

        $exercises = is_array($data['exercises'] ?? null) ? $data['exercises'] : [];
        $this->upsertExercises((int) $tid, $exercises);

        return ['template_id' => (int) $tid];
    }

    /**
     * Удалить шаблон тренера.
     */
    public function deleteTemplate(int $coachId, int $templateId): void {
        $stmt = $this->db->prepare(
            "DELETE FROM coach_workout_templates WHERE id = ? AND coach_id = ?"
        );
        $stmt->bind_param('ii', $templateId, $coachId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Массовое назначение тренировки.
     *
     * @return array {
     *   ok: bool,
     *   conflicts?: [{athlete_id, athlete_name, existing: {type, description}}],
     *   assigned?: int,
     *   overwritten?: int,
     *   errors?: string[],
     * }
     */
    public function bulkAssign(int $coachId, int $templateId, array $athleteIds, string $date, bool $overwrite = false): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->throwException('date должен быть в формате Y-m-d', 400);
        }
        $athleteIds = array_values(array_unique(array_map('intval', $athleteIds)));
        if (count($athleteIds) === 0) {
            return ['ok' => false, 'errors' => ['Не выбраны атлеты']];
        }

        $template = $this->getTemplateOwned($coachId, $templateId);
        if (!$template) {
            return ['ok' => false, 'errors' => ['Шаблон не найден']];
        }

        $allowedAthletes = $this->filterAthletesCoachCanEdit($coachId, $athleteIds);
        $forbidden = array_diff($athleteIds, $allowedAthletes);

        if (count($allowedAthletes) === 0) {
            return ['ok' => false, 'errors' => ['Нет прав на редактирование выбранных атлетов']];
        }

        $existing = $this->findExistingPlanDays($allowedAthletes, $date);

        // Preflight: если есть конфликты и overwrite=false — вернуть diff без записи
        if (count($existing) > 0 && !$overwrite) {
            $names = $this->getUserNames(array_keys($existing));
            $conflicts = [];
            foreach ($existing as $athleteId => $existingDay) {
                $conflicts[] = [
                    'athlete_id' => $athleteId,
                    'athlete_name' => $names[$athleteId] ?? ('id ' . $athleteId),
                    'existing' => [
                        'type' => $existingDay['type'],
                        'description' => $existingDay['description'],
                    ],
                ];
            }
            return [
                'ok' => false,
                'conflicts' => $conflicts,
                'forbidden_count' => count($forbidden),
            ];
        }

        // Apply
        $exercises = $this->getTemplateExercises($templateId);
        $weekService = new WeekService($this->db);

        $assigned = 0;
        $overwritten = 0;
        $errors = [];

        $this->db->begin_transaction();
        try {
            foreach ($allowedAthletes as $athleteId) {
                $existingDay = $existing[$athleteId] ?? null;
                if ($existingDay) {
                    // Удаляем существующий plan_day + связанные exercises (FK ON DELETE? нет — делаем явно)
                    $this->deletePlanDayWithExercises((int) $existingDay['id'], (int) $athleteId);
                    $overwritten++;
                }
                $result = $weekService->addTrainingDayByDate([
                    'date' => $date,
                    'type' => $template['type'],
                    'description' => $template['description'] ?? '',
                    'is_key_workout' => (int) $template['is_key_workout'],
                ], (int) $athleteId);
                $newDayId = (int) ($result['day_id'] ?? 0);
                if ($newDayId > 0) {
                    $this->copyExercisesToDay($exercises, $newDayId, (int) $athleteId);
                    $assigned++;
                } else {
                    $errors[] = "Не удалось назначить атлету id={$athleteId}";
                }
            }

            // Increment uses_count
            $upd = $this->db->prepare(
                "UPDATE coach_workout_templates SET uses_count = uses_count + 1 WHERE id = ? AND coach_id = ?"
            );
            $upd->bind_param('ii', $templateId, $coachId);
            $upd->execute();
            $upd->close();

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            $this->logError('bulkAssign failed', ['err' => $e->getMessage()]);
            return ['ok' => false, 'errors' => ['Ошибка применения: ' . $e->getMessage()]];
        }

        return [
            'ok' => true,
            'assigned' => $assigned,
            'overwritten' => $overwritten,
            'forbidden_count' => count($forbidden),
            'errors' => $errors,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function isValidType(string $type): bool {
        return in_array($type, [
            'rest','tempo','interval','long','race','other','free','easy','sbu','fartlek','control','walking'
        ], true);
    }

    private function getTemplateOwned(int $coachId, int $templateId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, name, type, distance, emoji, description, is_key_workout
             FROM coach_workout_templates WHERE id = ? AND coach_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $templateId, $coachId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function getTemplateExercises(int $templateId): array {
        $stmt = $this->db->prepare(
            "SELECT exercise_id, category, name, sets, reps, distance_m, duration_sec,
                    weight_kg, pace, notes, order_index
             FROM coach_workout_template_exercises
             WHERE template_id = ?
             ORDER BY order_index ASC, id ASC"
        );
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function filterAthletesCoachCanEdit(int $coachId, array $athleteIds): array {
        if (count($athleteIds) === 0) return [];
        $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));
        $types = 'i' . str_repeat('i', count($athleteIds));
        $sql = "SELECT user_id FROM user_coaches
                WHERE coach_id = ? AND can_edit = 1 AND user_id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, $coachId, ...$athleteIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(static fn($r) => (int) $r['user_id'], $rows);
    }

    /**
     * Map [athleteId => {id, type, description}] для атлетов, у которых уже есть plan_day на date.
     */
    private function findExistingPlanDays(array $athleteIds, string $date): array {
        if (count($athleteIds) === 0) return [];
        $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));
        $types = str_repeat('i', count($athleteIds)) . 's';
        $sql = "SELECT id, user_id, type, description FROM training_plan_days
                WHERE user_id IN ($placeholders) AND date = ?";
        $stmt = $this->db->prepare($sql);
        $params = array_merge($athleteIds, [$date]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = $r;
        }
        return $out;
    }

    private function deletePlanDayWithExercises(int $dayId, int $userId): void {
        $stmt = $this->db->prepare(
            "DELETE FROM training_day_exercises WHERE plan_day_id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $dayId, $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare(
            "DELETE FROM training_plan_days WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $dayId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function copyExercisesToDay(array $templateExercises, int $planDayId, int $userId): void {
        if (count($templateExercises) === 0) return;
        $stmt = $this->db->prepare(
            "INSERT INTO training_day_exercises
               (user_id, plan_day_id, exercise_id, category, name, sets, reps,
                distance_m, duration_sec, weight_kg, pace, notes, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($templateExercises as $ex) {
            $exerciseId = $ex['exercise_id'] !== null ? (int) $ex['exercise_id'] : null;
            $category = $ex['category'];
            $name = $ex['name'];
            $sets = $ex['sets'] !== null ? (int) $ex['sets'] : null;
            $reps = $ex['reps'] !== null ? (int) $ex['reps'] : null;
            $distanceM = $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
            $durationSec = $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
            $weightKg = $ex['weight_kg'] !== null ? (float) $ex['weight_kg'] : null;
            $pace = $ex['pace'];
            $notes = $ex['notes'];
            $orderIndex = (int) ($ex['order_index'] ?? 0);

            $stmt->bind_param(
                'iiississiidssi',
                $userId, $planDayId, $exerciseId, $category, $name,
                $sets, $reps, $distanceM, $durationSec, $weightKg,
                $pace, $notes, $orderIndex
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    private function upsertExercises(int $templateId, array $exercises): void {
        if (count($exercises) === 0) return;
        $stmt = $this->db->prepare(
            "INSERT INTO coach_workout_template_exercises
               (template_id, exercise_id, category, name, sets, reps,
                distance_m, duration_sec, weight_kg, pace, notes, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($exercises as $idx => $ex) {
            $exerciseId = isset($ex['exercise_id']) && $ex['exercise_id'] !== null ? (int) $ex['exercise_id'] : null;
            $category = $ex['category'] ?? 'run';
            $name = $ex['name'] ?? '';
            $sets = isset($ex['sets']) && $ex['sets'] !== null ? (int) $ex['sets'] : null;
            $reps = isset($ex['reps']) && $ex['reps'] !== null ? (int) $ex['reps'] : null;
            $distanceM = isset($ex['distance_m']) && $ex['distance_m'] !== null ? (int) $ex['distance_m'] : null;
            $durationSec = isset($ex['duration_sec']) && $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null;
            $weightKg = isset($ex['weight_kg']) && $ex['weight_kg'] !== null ? (float) $ex['weight_kg'] : null;
            $pace = $ex['pace'] ?? null;
            $notes = $ex['notes'] ?? null;
            $orderIndex = (int) ($ex['order_index'] ?? $idx);

            $stmt->bind_param(
                'iissiiiidssi',
                $templateId, $exerciseId, $category, $name,
                $sets, $reps, $distanceM, $durationSec, $weightKg,
                $pace, $notes, $orderIndex
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    private function getUserNames(array $ids): array {
        if (count($ids) === 0) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $this->db->prepare(
            "SELECT id, username FROM users WHERE id IN ($placeholders)"
        );
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['username'];
        }
        return $out;
    }
}
