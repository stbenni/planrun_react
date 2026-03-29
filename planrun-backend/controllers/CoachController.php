<?php
/**
 * CoachController — API для раздела «Тренеры»
 *
 * Тонкий контроллер: auth/permissions + извлечение параметров + делегация в CoachService.
 */

require_once __DIR__ . '/../services/CoachService.php';

class CoachController extends BaseController {

    private ?CoachService $service = null;

    private function coachService(): CoachService {
        if (!$this->service) {
            $this->service = new CoachService($this->db);
        }
        return $this->service;
    }

    private function requireCoachOrAdmin(): bool {
        if (!$this->requireAuth()) return false;
        if (!$this->coachService()->isCoachOrAdmin($this->currentUserId)) {
            $this->returnError('Доступно только тренерам', 403);
            return false;
        }
        return true;
    }

    /**
     * GET list_coaches — каталог тренеров с пагинацией и фильтрами
     */
    public function listCoaches() {
        try {
            $offset = max(0, (int)($this->getParam('offset', 0)));
            $limit = min(50, max(1, (int)($this->getParam('limit', 20))));
            $filters = [
                'specialization' => $this->getParam('specialization'),
                'accepts_new' => $this->getParam('accepts_new'),
            ];

            $this->returnSuccess($this->coachService()->listCoaches($filters, $limit, $offset));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST request_coach — запрос на тренировку
     */
    public function requestCoach() {
        if (!$this->requireAuth()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $coachId = (int)($input['coach_id'] ?? 0);
            $message = trim($input['message'] ?? '');

            if (!$coachId) {
                $this->returnError('coach_id обязателен', 400);
            }

            $requestId = $this->coachService()->createRequest($this->currentUserId, $coachId, $message);
            $this->returnSuccess(['request_id' => $requestId]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET coach_requests — pending-запросы для текущего тренера
     */
    public function getCoachRequests() {
        if (!$this->requireCoachOrAdmin()) return;
        try {
            $offset = max(0, (int)($this->getParam('offset', 0)));
            $limit = min(50, max(1, (int)($this->getParam('limit', 20))));
            $status = $this->getParam('status', 'pending');

            $this->returnSuccess($this->coachService()->getRequests($this->currentUserId, $status, $limit, $offset));
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST accept_coach_request
     */
    public function acceptCoachRequest() {
        if (!$this->requireAuth()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $requestId = (int)($input['request_id'] ?? 0);

            $this->coachService()->acceptRequest($this->currentUserId, $requestId);
            $this->returnSuccess(['accepted' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST reject_coach_request
     */
    public function rejectCoachRequest() {
        if (!$this->requireAuth()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $requestId = (int)($input['request_id'] ?? 0);

            $this->coachService()->rejectRequest($this->currentUserId, $requestId);
            $this->returnSuccess(['rejected' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_my_coaches — тренеры текущего пользователя
     */
    public function getMyCoaches() {
        if (!$this->requireAuth()) return;
        try {
            $coaches = $this->coachService()->getUserCoaches($this->currentUserId);
            $this->returnSuccess(['coaches' => $coaches]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST remove_coach — отвязать тренера (может вызвать и атлет, и тренер)
     */
    public function removeCoach() {
        if (!$this->requireAuth()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $coachId = (int)($input['coach_id'] ?? 0);
            $athleteId = (int)($input['athlete_id'] ?? 0);

            $this->coachService()->removeCoachRelationship($this->currentUserId, $coachId ?: null, $athleteId ?: null);
            $this->returnSuccess(['removed' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST apply_coach — заявка «Стать тренером»
     */
    public function applyCoach() {
        if (!$this->requireAuth()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $applicationId = $this->coachService()->applyAsCoach($this->currentUserId, $input);
            $this->returnSuccess(['application_id' => $applicationId]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET coach_athletes — список атлетов тренера
     */
    public function getCoachAthletes() {
        if (!$this->requireCoachOrAdmin()) return;
        try {
            $athletes = $this->coachService()->getCoachAthletes($this->currentUserId);
            $this->returnSuccess(['athletes' => $athletes]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_coach_pricing — цены тренера
     */
    public function getCoachPricing() {
        if (!$this->requireAuth()) return;
        try {
            $coachId = (int)($this->getParam('coach_id', $this->currentUserId));
            $pricing = $this->coachService()->getPricing($coachId);
            $this->returnSuccess(['pricing' => $pricing]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST update_coach_pricing — обновить цены тренера
     */
    public function updateCoachPricing() {
        if (!$this->requireCoachOrAdmin()) return;
        try {
            $input = $this->getJsonBody() ?: $_POST;
            $items = $input['pricing'] ?? [];
            $pricesOnRequest = isset($input['prices_on_request']) ? (int)$input['prices_on_request'] : null;

            $this->coachService()->updatePricing($this->currentUserId, is_array($items) ? $items : [], $pricesOnRequest);
            $this->returnSuccess(['updated' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    // ==================== ГРУППЫ АТЛЕТОВ ====================

    /**
     * GET get_coach_groups — список групп тренера
     */
    public function getCoachGroups() {
        if (!$this->requireCoachOrAdmin()) return;
        try {
            $groups = $this->coachService()->getGroups($this->currentUserId);
            $this->returnSuccess(['groups' => $groups]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST save_coach_group — создать или обновить группу
     */
    public function saveCoachGroup() {
        if (!$this->requireCoachOrAdmin()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $name = trim($data['name'] ?? '');
            $color = $data['color'] ?? '#6366f1';
            $groupId = $data['group_id'] ?? null;

            $result = $this->coachService()->saveGroup($this->currentUserId, $name, $color, $groupId ? (int)$groupId : null);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST delete_coach_group — удалить группу
     */
    public function deleteCoachGroup() {
        if (!$this->requireCoachOrAdmin()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $groupId = (int)($data['group_id'] ?? 0);
            if (!$groupId) {
                $this->returnError('group_id обязателен', 400);
                return;
            }

            $this->coachService()->deleteGroup($this->currentUserId, $groupId);
            $this->returnSuccess(['deleted' => true]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_group_members — участники группы
     */
    public function getGroupMembers() {
        if (!$this->requireCoachOrAdmin()) return;
        try {
            $groupId = (int)$this->getParam('group_id', 0);
            if (!$groupId) {
                $this->returnError('group_id обязателен', 400);
                return;
            }

            $members = $this->coachService()->getGroupMembers($this->currentUserId, $groupId);
            $this->returnSuccess(['members' => $members]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * POST update_group_members — установить список участников группы
     */
    public function updateGroupMembers() {
        if (!$this->requireCoachOrAdmin()) return;
        $this->checkCsrfToken();
        try {
            $data = $this->getJsonBody();
            $groupId = (int)($data['group_id'] ?? 0);
            $userIds = $data['user_ids'] ?? [];

            if (!$groupId) {
                $this->returnError('group_id обязателен', 400);
                return;
            }
            if (!is_array($userIds)) {
                $this->returnError('user_ids должен быть массивом', 400);
                return;
            }

            $memberCount = $this->coachService()->updateGroupMembers($this->currentUserId, $groupId, $userIds);
            $this->returnSuccess(['member_count' => $memberCount]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET get_athlete_groups — группы конкретного атлета
     */
    public function getAthleteGroups() {
        if (!$this->requireCoachOrAdmin()) return;
        try {
            $userId = (int)$this->getParam('user_id', 0);
            if (!$userId) {
                $this->returnError('user_id обязателен', 400);
                return;
            }

            $groups = $this->coachService()->getAthleteGroups($this->currentUserId, $userId);
            $this->returnSuccess(['groups' => $groups]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
