<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/PlanGenerationQueueService.php';

class WorkoutPlanRecalculationService extends BaseService {
    private const MIN_FUTURE_WORKOUTS = 2;
    private const MIN_VDOT_DELTA = 1.0;

    public function maybeQueueAfterPerformanceUpdate(
        int $userId,
        string $type,
        string $resultDate,
        ?float $oldVdot,
        float $newVdot
    ): array {
        try {
            $remainingDays = $this->countRemainingPlannedDaysAfterDate($userId, $resultDate);
            if ($remainingDays < self::MIN_FUTURE_WORKOUTS) {
                return ['queued' => false, 'skipped_reason' => 'в плане почти не осталось будущих тренировок'];
            }

            $vdotDelta = $oldVdot !== null ? abs($newVdot - $oldVdot) : null;
            if ($oldVdot !== null && $vdotDelta < self::MIN_VDOT_DELTA) {
                return ['queued' => false, 'skipped_reason' => 'изменение формы слишком маленькое для автоматического пересчёта'];
            }

            $queue = new PlanGenerationQueueService($this->db);
            if (!$queue->isQueueAvailable()) {
                return ['queued' => false, 'skipped_reason' => 'очередь пересчёта недоступна'];
            }

            $deltaText = $oldVdot !== null ? round($newVdot - $oldVdot, 1) : null;
            $reason = $type === 'control'
                ? 'Автопересчёт после контрольной: обновлён VDOT'
                : 'Автопересчёт после результата забега: обновлён VDOT';
            if ($deltaText !== null) {
                $reason .= " ({$deltaText}).";
            } else {
                $reason .= '.';
            }

            $result = $queue->enqueue($userId, 'recalculate', [
                'reason' => $reason,
                'source' => 'workout_result_vdot_update',
                'result_date' => $resultDate,
            ]);
            $this->deactivateActivePlans($userId);

            return [
                'queued' => true,
                'job_id' => $result['job_id'] ?? null,
                'deduplicated' => $result['deduplicated'] ?? false,
            ];
        } catch (Throwable $e) {
            $this->logError('Автопересчёт плана после обновления VDOT не удался', [
                'user_id' => $userId,
                'type' => $type,
                'result_date' => $resultDate,
                'error' => $e->getMessage(),
            ]);

            return ['queued' => false, 'skipped_reason' => 'не удалось поставить пересчёт в очередь'];
        }
    }

    private function deactivateActivePlans(int $userId): void {
        $stmt = $this->db->prepare(
            "UPDATE user_training_plans SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE"
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function countRemainingPlannedDaysAfterDate(int $userId, string $date): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS cnt
            FROM training_plan_days
            WHERE user_id = ?
              AND date > ?
              AND type IN ('easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race', 'other', 'sbu')
        ");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('is', $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }
}
