<?php
/**
 * Исполнение задач генерации плана из очереди.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../planrun_ai/plan_generator.php';
require_once __DIR__ . '/../planrun_ai/plan_saver.php';
require_once __DIR__ . '/../training_utils.php';

class PlanGenerationProcessorService extends BaseService {
    public function process(int $userId, string $jobType = 'generate', array $payload = []): array {
        if ($userId < 1) {
            throw new InvalidArgumentException('Не указан user_id', 400);
        }

        $useSkeletonGenerator = (bool) (env('USE_SKELETON_GENERATOR', '0'));

        $userReason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
        $userGoals = isset($payload['goals']) ? trim((string) $payload['goals']) : null;
        $isRecalculate = $jobType === 'recalculate';
        $isNextPlan = $jobType === 'next_plan';

        $mode = $isNextPlan ? 'НОВЫЙ ПЛАН' : ($isRecalculate ? 'ПЕРЕСЧЁТ' : 'ГЕНЕРАЦИЯ');
        $this->logInfo("Начало {$mode} плана", [
            'user_id' => $userId,
            'job_type' => $jobType,
            'skeleton_generator' => $useSkeletonGenerator,
        ]);

        if ($useSkeletonGenerator) {
            $result = $this->processViaSkeleton($userId, $jobType, $payload);
            $planData = $result['plan'];
            $cutoffDate = $result['cutoff_date'] ?? null;
            $keptWeeks = $result['kept_weeks'] ?? null;
        } elseif ($isNextPlan) {
            $planData = generateNextPlanViaPlanRunAI($userId, $userGoals);
        } elseif ($isRecalculate) {
            $result = recalculatePlanViaPlanRunAI($userId, $userReason);
            $planData = $result['plan'];
            $cutoffDate = $result['cutoff_date'];
            $keptWeeks = $result['kept_weeks'];
        } else {
            $planData = generatePlanViaPlanRunAI($userId);
        }

        if (!$planData || !isset($planData['weeks']) || empty($planData['weeks'])) {
            throw new RuntimeException('План не содержит данных о неделях', 500);
        }

        // Загружаем preferences пользователя для enforcement расписания в нормализаторе
        $userPreferences = $this->loadUserPreferences($userId);

        $startDate = null;
        if ($isNextPlan) {
            $startDate = (new DateTime())->modify('monday this week')->format('Y-m-d');
            saveTrainingPlan($this->db, $userId, $planData, $startDate, $userPreferences);
            $updateStmt = $this->db->prepare("UPDATE users SET training_start_date = ? WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param('si', $startDate, $userId);
                $updateStmt->execute();
                $updateStmt->close();
            }
        } elseif ($isRecalculate) {
            saveRecalculatedPlan($this->db, $userId, $planData, $cutoffDate, $userPreferences);
        } else {
            $stmt = $this->db->prepare("SELECT training_start_date FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            $startDate = $user['training_start_date'] ?? date('Y-m-d');
            saveTrainingPlan($this->db, $userId, $planData, $startDate, $userPreferences);
        }

        $this->activateLatestPlan($userId);
        $reviewStartDate = $startDate ?? ($cutoffDate ?? date('Y-m-d'));
        $this->appendPlanReview($userId, $planData, $reviewStartDate, $mode);

        $resultPayload = [
            'user_id' => $userId,
            'job_type' => $jobType,
            'weeks_count' => count($planData['weeks']),
        ];
        if (!empty($planData['_generation_metadata']) && is_array($planData['_generation_metadata'])) {
            $resultPayload['generation_metadata'] = $planData['_generation_metadata'];
        }
        if (isset($keptWeeks)) {
            $resultPayload['kept_weeks'] = $keptWeeks;
        }
        if (isset($cutoffDate)) {
            $resultPayload['cutoff_date'] = $cutoffDate;
        }
        if ($startDate) {
            $resultPayload['start_date'] = $startDate;
        }

        $this->logInfo("Завершено {$mode} плана", [
            'user_id' => $userId,
            'job_type' => $jobType,
            'weeks_count' => count($planData['weeks']),
            'repair_count' => $planData['_generation_metadata']['repair_count'] ?? 0,
            'prompt_version' => $planData['_generation_metadata']['prompt_version'] ?? null,
            'policy_version' => $planData['_generation_metadata']['policy_version'] ?? null,
            'vdot_source' => $planData['_generation_metadata']['vdot_source'] ?? null,
            'validation_errors_count' => count($planData['_generation_metadata']['final_validation_errors'] ?? []),
        ]);

        return $resultPayload;
    }

    /**
     * Новый путь генерации: PlanSkeletonGenerator + LLM-обогащение + LLM-ревью.
     */
    private function processViaSkeleton(int $userId, string $jobType, array $payload): array {
        require_once __DIR__ . '/../planrun_ai/skeleton/PlanSkeletonGenerator.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/LLMEnricher.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/SkeletonValidator.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/LLMReviewer.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/PlanAutoFixer.php';
        require_once __DIR__ . '/../planrun_ai/skeleton/StartRunningProgramBuilder.php';

        // Шаг 0: Для recalculate — собрать реальные данные тренировок из БД
        if ($jobType === 'recalculate') {
            $payload = $this->enrichRecalculatePayload($userId, $payload);
        }

        // Шаг 1: Генерация числового скелета (без LLM)
        $generator = new PlanSkeletonGenerator($this->db);
        $skeleton = $generator->generate($userId, $jobType, $payload);

        if (empty($skeleton['weeks'])) {
            throw new RuntimeException('Скелет плана пуст', 500);
        }

        $user = $generator->getLastUser();
        $state = $generator->getLastState();
        $paceRules = $state['pace_rules'] ?? [];
        $loadPolicy = $state['load_policy'] ?? [];

        $this->logInfo('Скелет сгенерирован', [
            'user_id' => $userId,
            'weeks' => count($skeleton['weeks']),
            'vdot' => $state['vdot'] ?? null,
        ]);

        // Шаг 2: LLM-обогащение (notes, structure)
        $enricher = new LLMEnricher();
        $enrichContext = [
            'reason' => $payload['reason'] ?? null,
            'goals' => $payload['goals'] ?? null,
            'job_type' => $jobType,
        ];
        $enriched = $enricher->enrich($skeleton, $user, $state, $enrichContext);

        // Шаг 3: Алгоритмическая валидация — LLM не сломала числа
        $validationErrors = SkeletonValidator::validateAgainstOriginal($skeleton, $enriched);
        if (!empty($validationErrors)) {
            $this->logInfo('LLM-обогащение сломало числа, используем fallback', [
                'errors_count' => count($validationErrors),
            ]);
            $enriched = SkeletonValidator::addAlgorithmicNotes($skeleton);
        }

        // Шаг 4: LLM-ревью (проверка логики)
        $reviewer = new LLMReviewer();
        $review = $reviewer->review($enriched, $user, $state);

        $maxIterations = 2;
        $iteration = 0;

        while ($review['status'] === 'has_issues' && !empty($review['issues']) && $iteration < $maxIterations) {
            $iteration++;
            $this->logInfo("LLM-ревью нашло ошибки, автофикс (итерация {$iteration})", [
                'issues_count' => count($review['issues']),
            ]);

            // Шаг 5: Автофикс
            $fixResult = PlanAutoFixer::fix($enriched, $review['issues'], $paceRules, $loadPolicy);
            $enriched = $fixResult['plan'];

            if ($fixResult['fixes_applied'] === 0) {
                break;
            }

            // Повторное ревью
            if ($iteration < $maxIterations) {
                $review = $reviewer->review($enriched, $user, $state);
            }
        }

        // Шаг 6: Финальная алгоритмическая валидация + автоисправление
        $consistencyErrors = SkeletonValidator::validateConsistency($enriched, $paceRules);
        if (!empty($consistencyErrors)) {
            $this->logInfo('Финальная валидация нашла ошибки, исправляем', [
                'errors' => array_map(fn($e) => $e['description'] ?? $e['type'], $consistencyErrors),
            ]);
            $fixResult = PlanAutoFixer::fix($enriched, $consistencyErrors, $paceRules, $loadPolicy);
            $enriched = $fixResult['plan'];

            // Повторная проверка после исправлений
            $remainingErrors = SkeletonValidator::validateConsistency($enriched, $paceRules);
            if (!empty($remainingErrors)) {
                $this->logInfo('Остались неисправленные ошибки после финальной валидации', [
                    'errors' => array_map(fn($e) => $e['description'] ?? $e['type'], $remainingErrors),
                ]);
            }
            $consistencyErrors = $remainingErrors;
        }

        $enriched['_generation_metadata'] = array_merge(
            $skeleton['_metadata'] ?? [],
            [
                'llm_review_iterations' => $iteration,
                'llm_review_final_status' => $review['status'] ?? 'ok',
                'consistency_errors' => count($consistencyErrors),
                'generator' => 'PlanSkeletonGenerator',
            ]
        );

        $result = ['plan' => $enriched];

        // Для recalculate — передаём cutoff данные
        if ($jobType === 'recalculate') {
            $result['cutoff_date'] = $payload['cutoff_date'] ?? (new DateTime())->modify('monday this week')->format('Y-m-d');
            $result['kept_weeks'] = $payload['kept_weeks'] ?? 0;
        }

        return $result;
    }

    /**
     * Собрать реальные данные тренировок для recalculate.
     * Аналогично тому, что делает recalculatePlanViaPlanRunAI:
     * cutoff_date, kept_weeks, avg_weekly_km_4w, fresh_vdot.
     */
    private function enrichRecalculatePayload(int $userId, array $payload): array
    {
        // cutoff_date — понедельник текущей недели
        if (empty($payload['cutoff_date'])) {
            $payload['cutoff_date'] = (new DateTime())->modify('monday this week')->format('Y-m-d');
        }
        $cutoffDate = $payload['cutoff_date'];

        // kept_weeks — сколько недель плана до cutoff
        if (!isset($payload['kept_weeks'])) {
            $stmt = $this->db->prepare(
                "SELECT MAX(week_number) AS max_wn FROM training_plan_weeks WHERE user_id = ? AND start_date < ?"
            );
            if ($stmt) {
                $stmt->bind_param('is', $userId, $cutoffDate);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $payload['kept_weeks'] = (int) ($row['max_wn'] ?? 0);
            }
        }

        // actual_weekly_km_4w — средний фактический объём за последние 4 недели
        if (empty($payload['actual_weekly_km_4w'])) {
            $fourWeeksAgo = (new DateTime())->modify('-28 days')->format('Y-m-d');
            $today = (new DateTime())->format('Y-m-d');

            // Из workout_log (ручные)
            $weekKms = [];
            $stmt = $this->db->prepare("
                SELECT training_date, distance_km
                FROM workout_log
                WHERE user_id = ? AND is_completed = 1
                  AND training_date >= ? AND training_date <= ?
            ");
            if ($stmt) {
                $stmt->bind_param('iss', $userId, $fourWeeksAgo, $today);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $dist = (float) ($r['distance_km'] ?? 0);
                    if ($dist > 0) {
                        $weekKey = date('Y-W', strtotime($r['training_date']));
                        $weekKms[$weekKey] = ($weekKms[$weekKey] ?? 0) + $dist;
                    }
                }
                $stmt->close();
            }

            // Из workouts (автоматические с часов)
            $stmt = $this->db->prepare("
                SELECT DATE(start_time) AS workout_date, distance_km
                FROM workouts
                WHERE user_id = ? AND DATE(start_time) >= ? AND DATE(start_time) <= ?
            ");
            if ($stmt) {
                $stmt->bind_param('iss', $userId, $fourWeeksAgo, $today);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $dist = (float) ($r['distance_km'] ?? 0);
                    if ($dist > 0) {
                        $weekKey = date('Y-W', strtotime($r['workout_date']));
                        $weekKms[$weekKey] = ($weekKms[$weekKey] ?? 0) + $dist;
                    }
                }
                $stmt->close();
            }

            $weekCount = count($weekKms);
            if ($weekCount > 0) {
                $payload['actual_weekly_km_4w'] = round(array_sum($weekKms) / $weekCount, 1);
            }
        }

        // fresh_vdot — свежий VDOT из лучших результатов (уже считается в TrainingStateBuilder)
        // Не дублируем: TrainingStateBuilder.buildForUser() сам найдёт best_result/last_race

        return $payload;
    }

    public function persistFailure(int $userId, string $message): void {
        $stmt = $this->db->prepare("
            UPDATE user_training_plans 
            SET is_active = FALSE,
                error_message = ?
            WHERE user_id = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $message, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Загружает предпочтения пользователя (preferred_days, preferred_ofp_days)
     * для передачи в нормализатор плана.
     */
    private function loadUserPreferences(int $userId): ?array {
        $stmt = $this->db->prepare(
            "SELECT preferred_days, preferred_ofp_days FROM users WHERE id = ?"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $prefDays = !empty($row['preferred_days'])
            ? (json_decode($row['preferred_days'], true) ?: [])
            : [];
        $ofpDays = !empty($row['preferred_ofp_days'])
            ? (json_decode($row['preferred_ofp_days'], true) ?: [])
            : [];

        // Если preferred_days пустой — пользователь не указал конкретные дни, нет ограничений
        if (empty($prefDays)) {
            return null;
        }

        return [
            'preferred_days' => $prefDays,
            'preferred_ofp_days' => $ofpDays,
        ];
    }

    private function activateLatestPlan(int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE user_training_plans 
            SET is_active = TRUE 
            WHERE user_id = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function appendPlanReview(int $userId, array $planData, string $reviewStartDate, string $mode): void {
        try {
            require_once __DIR__ . '/../planrun_ai/plan_review_generator.php';
            require_once __DIR__ . '/ChatService.php';
            $review = generatePlanReview($planData, $reviewStartDate, $mode);
            if ($review === null || $review === '') {
                $review = $this->buildFallbackPlanReview($planData, $reviewStartDate, $mode);
            }

            if ($review === null || $review === '') {
                return;
            }

            $chatService = new ChatService($this->db);
            $eventKey = match ($mode) {
                'ПЕРЕСЧЁТ' => 'plan.recalculated',
                'НОВЫЙ ПЛАН' => 'plan.next_generated',
                default => 'plan.generated',
            };
            $title = match ($mode) {
                'ПЕРЕСЧЁТ' => 'План пересчитан',
                'НОВЫЙ ПЛАН' => 'Следующий план готов',
                default => 'План сгенерирован',
            };
            $chatService->addAIMessageToUser($userId, $review, [
                'event_key' => $eventKey,
                'title' => $title,
                'link' => '/chat',
            ]);
        } catch (Throwable $reviewEx) {
            $this->logError('Рецензия плана не добавлена', [
                'user_id' => $userId,
                'error' => $reviewEx->getMessage(),
            ]);
        }
    }

    private function buildFallbackPlanReview(array $planData, string $reviewStartDate, string $mode): string {
        $summaryPrefix = match ($mode) {
            'ПЕРЕСЧЁТ' => 'План успешно пересчитан.',
            'НОВЫЙ ПЛАН' => 'Новый план успешно сформирован.',
            default => 'План успешно сгенерирован.',
        };

        $weeksCount = count($planData['weeks'] ?? []);
        $longDayLabel = null;
        $restLabels = [];
        $dayLabels = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];

        try {
            require_once __DIR__ . '/../planrun_ai/plan_normalizer.php';
            $normalized = normalizeTrainingPlan($planData, $reviewStartDate);
            $firstWeekDays = $normalized['weeks'][0]['days'] ?? [];
            foreach ($firstWeekDays as $index => $day) {
                $type = normalizeTrainingType($day['type'] ?? null);
                if ($type === 'long' && $longDayLabel === null) {
                    $longDayLabel = $dayLabels[$index] ?? null;
                }
                if ($type === 'rest') {
                    $restLabels[] = $dayLabels[$index] ?? ('День ' . ($index + 1));
                }
            }
        } catch (Throwable $normalizationError) {
            // Fallback message should still be delivered even if normalization failed.
        }

        $parts = [
            $summaryPrefix,
            "Я обновил календарь начиная с недели от {$reviewStartDate}.",
        ];

        if ($weeksCount > 0) {
            $parts[] = "В обновлённой части плана {$weeksCount} " . $this->formatWeeksLabel($weeksCount) . '.';
        }
        if ($longDayLabel !== null) {
            $parts[] = "Длительная сейчас стоит на {$longDayLabel}.";
        }
        if (!empty($restLabels)) {
            $parts[] = 'Дни отдыха на первой обновлённой неделе: ' . implode(', ', $restLabels) . '.';
        }

        $parts[] = 'Проверь календарь. Если нужно ещё скорректировать структуру, напиши это в пересчёте или в чате.';

        return implode(' ', $parts);
    }

    private function formatWeeksLabel(int $weeksCount): string {
        $mod100 = $weeksCount % 100;
        $mod10 = $weeksCount % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'недель';
        }
        return match ($mod10) {
            1 => 'неделя',
            2, 3, 4 => 'недели',
            default => 'недель',
        };
    }
}
