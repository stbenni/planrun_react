<?php
/**
 * Сервис для адаптации плана тренировок.
 *
 * Использует WeeklyAdaptationEngine для анализа план vs факт
 * и автоматической корректировки оставшихся недель.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../planrun_ai/skeleton/WeeklyAdaptationEngine.php';

class AdaptationService extends BaseService {

    /**
     * Запустить недельную адаптацию для пользователя.
     *
     * @param int $userId ID пользователя
     * @return array Результат адаптации
     * @throws Exception
     */
    public function runWeeklyAdaptation(int $userId): array {
        $engine = new WeeklyAdaptationEngine($this->db);
        $result = $engine->analyze($userId);

        // Отправляем ревью в чат
        if (!empty($result['review_message'])) {
            try {
                require_once __DIR__ . '/ChatService.php';
                $chatService = new ChatService($this->db);
                $chatService->addAIMessageToUser($userId, $result['review_message'], [
                    'event_key' => 'plan.weekly_adaptation',
                    'title' => 'Недельная адаптация готова',
                    'link' => '/chat',
                ]);
            } catch (Throwable $e) {
                $this->logError('Не удалось отправить ревью в чат', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logInfo('Еженедельная адаптация завершена', [
            'user_id' => $userId,
            'adapted' => $result['adapted'],
            'adaptation_type' => $result['adaptation_type'],
            'triggers' => $result['triggers'],
            'week_number' => $result['week_number'] ?? null,
        ]);

        return $result;
    }
}
