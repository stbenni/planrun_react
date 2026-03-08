<?php
/**
 * Контроллер для заметок к дням и неделям плана (коммуникация тренер ↔ атлет)
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../repositories/NoteRepository.php';
require_once __DIR__ . '/../services/PlanNotificationService.php';

class NoteController extends BaseController {

    protected $noteRepo;
    protected $notifService;

    public function __construct($db) {
        parent::__construct($db);
        $this->noteRepo = new NoteRepository($db);
        $this->notifService = new PlanNotificationService($db);
    }

    /**
     * GET /api_v2.php?action=get_day_notes&date=Y-m-d
     */
    public function getDayNotes() {
        if (!$this->requireAuth()) return;

        $date = $this->getParam('date');
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->returnError('Параметр date обязателен (Y-m-d)', 400);
            return;
        }

        try {
            $notes = $this->noteRepo->getDayNotes($this->calendarUserId, $date);
            $this->returnSuccess(['notes' => $notes]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST /api_v2.php?action=save_day_note
     * Body: { "date": "Y-m-d", "content": "...", "note_id": null|int }
     */
    public function saveDayNote() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();

        try {
            $data = $this->getJsonBody();
            $date = $data['date'] ?? null;
            $content = trim($data['content'] ?? '');
            $noteId = $data['note_id'] ?? null;

            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->returnError('Параметр date обязателен (Y-m-d)', 400);
                return;
            }
            if ($content === '') {
                $this->returnError('Текст заметки не может быть пустым', 400);
                return;
            }
            if (mb_strlen($content) > 2000) {
                $this->returnError('Текст заметки слишком длинный (макс. 2000 символов)', 400);
                return;
            }

            // Право писать: владелец календаря или тренер с can_edit
            if ($this->currentUserId !== $this->calendarUserId && !$this->canEdit) {
                $this->returnError('Нет прав на добавление заметки', 403);
                return;
            }

            if ($noteId) {
                // Обновление — только автор может редактировать свою заметку
                $result = $this->noteRepo->updateDayNote((int)$noteId, $this->currentUserId, $content);
                if (($result['affected_rows'] ?? 0) === 0) {
                    $this->returnError('Заметка не найдена или нет прав на редактирование', 404);
                    return;
                }
                $this->returnSuccess(['updated' => true]);
            } else {
                $result = $this->noteRepo->addDayNote($this->calendarUserId, $this->currentUserId, $date, $content);
                $this->notifyAthleteIfCoach('note', $date);
                $this->returnSuccess(['note_id' => $result['insert_id']]);
            }
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST /api_v2.php?action=delete_day_note
     * Body: { "note_id": 123 }
     */
    public function deleteDayNote() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();

        try {
            $data = $this->getJsonBody();
            $noteId = $data['note_id'] ?? null;
            if (!$noteId) {
                $this->returnError('note_id обязателен', 400);
                return;
            }

            $result = $this->noteRepo->deleteDayNote((int)$noteId, $this->currentUserId);
            if (($result['affected_rows'] ?? 0) === 0) {
                $this->returnError('Заметка не найдена или нет прав на удаление', 404);
                return;
            }
            $this->returnSuccess(['deleted' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET /api_v2.php?action=get_week_notes&week_start=Y-m-d
     */
    public function getWeekNotes() {
        if (!$this->requireAuth()) return;

        $weekStart = $this->getParam('week_start');
        if (!$weekStart || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            $this->returnError('Параметр week_start обязателен (Y-m-d)', 400);
            return;
        }

        try {
            $notes = $this->noteRepo->getWeekNotes($this->calendarUserId, $weekStart);
            $this->returnSuccess(['notes' => $notes]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST /api_v2.php?action=save_week_note
     * Body: { "week_start": "Y-m-d", "content": "...", "note_id": null|int }
     */
    public function saveWeekNote() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();

        try {
            $data = $this->getJsonBody();
            $weekStart = $data['week_start'] ?? null;
            $content = trim($data['content'] ?? '');
            $noteId = $data['note_id'] ?? null;

            if (!$weekStart || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
                $this->returnError('Параметр week_start обязателен (Y-m-d)', 400);
                return;
            }
            if ($content === '') {
                $this->returnError('Текст заметки не может быть пустым', 400);
                return;
            }
            if (mb_strlen($content) > 2000) {
                $this->returnError('Текст заметки слишком длинный (макс. 2000 символов)', 400);
                return;
            }

            if ($this->currentUserId !== $this->calendarUserId && !$this->canEdit) {
                $this->returnError('Нет прав на добавление заметки', 403);
                return;
            }

            if ($noteId) {
                $result = $this->noteRepo->updateWeekNote((int)$noteId, $this->currentUserId, $content);
                if (($result['affected_rows'] ?? 0) === 0) {
                    $this->returnError('Заметка не найдена или нет прав на редактирование', 404);
                    return;
                }
                $this->returnSuccess(['updated' => true]);
            } else {
                $result = $this->noteRepo->addWeekNote($this->calendarUserId, $this->currentUserId, $weekStart, $content);
                $this->returnSuccess(['note_id' => $result['insert_id']]);
            }
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST /api_v2.php?action=delete_week_note
     * Body: { "note_id": 123 }
     */
    public function deleteWeekNote() {
        if (!$this->requireAuth()) return;
        $this->checkCsrfToken();

        try {
            $data = $this->getJsonBody();
            $noteId = $data['note_id'] ?? null;
            if (!$noteId) {
                $this->returnError('note_id обязателен', 400);
                return;
            }

            $result = $this->noteRepo->deleteWeekNote((int)$noteId, $this->currentUserId);
            if (($result['affected_rows'] ?? 0) === 0) {
                $this->returnError('Заметка не найдена или нет прав на удаление', 404);
                return;
            }
            $this->returnSuccess(['deleted' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET /api_v2.php?action=get_note_counts&start_date=Y-m-d&end_date=Y-m-d
     * Количество заметок по датам/неделям — для индикаторов в календаре
     */
    public function getNoteCounts() {
        if (!$this->requireAuth()) return;

        $startDate = $this->getParam('start_date');
        $endDate = $this->getParam('end_date');
        if (!$startDate || !$endDate) {
            $this->returnError('start_date и end_date обязательны', 400);
            return;
        }

        try {
            $dayCounts = $this->noteRepo->getDayNoteCounts($this->calendarUserId, $startDate, $endDate);
            $weekCounts = $this->noteRepo->getWeekNoteCounts($this->calendarUserId, $startDate, $endDate);
            $this->returnSuccess([
                'day_counts' => $dayCounts,
                'week_counts' => $weekCounts,
            ]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET /api_v2.php?action=get_plan_notifications
     * Непрочитанные уведомления текущего пользователя (тренер обновил план / атлет внёс результат)
     */
    public function getPlanNotifications() {
        if (!$this->requireAuth()) return;
        try {
            $notifications = $this->notifService->getUnread($this->currentUserId);
            $this->returnSuccess(['notifications' => $notifications]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST /api_v2.php?action=mark_plan_notification_read
     * Body: { "notification_id": 123 }  или  { "all": true }
     */
    public function markPlanNotificationRead() {
        if (!$this->requireAuth()) return;
        try {
            $data = $this->getJsonBody();
            if (!empty($data['all'])) {
                $this->notifService->markAllRead($this->currentUserId);
            } else {
                $noteId = $data['notification_id'] ?? null;
                if (!$noteId) {
                    $this->returnError('notification_id обязателен', 400);
                    return;
                }
                $this->notifService->markRead((int)$noteId, $this->currentUserId);
            }
            $this->returnSuccess(['ok' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
