<?php
/**
 * Контроллер для работы с тренировками и результатами
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../workout_types.php';

class WorkoutController extends BaseController {
    
    /**
     * Получить день тренировки
     * GET /api_v2.php?action=get_day&date=2026-01-25
     */
    public function getDay() {
        $date = $this->getParam('date');
        if (!$date) {
            $this->returnError('Параметр date обязателен');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $data = $service->getDay($date, $this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Сохранить результат тренировки
     * POST /api_v2.php?action=save_result
     */
    public function saveResult() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        // Получаем данные из JSON body
        $data = $this->getJsonBody();
        if (!$data) {
            $this->returnError('Данные не переданы');
            return;
        }
        
        if (!isset($data['date']) || !isset($data['week']) || !isset($data['day'])) {
            $this->returnError('Недостаточно данных: требуется date, week, day');
            return;
        }
        if (!isset($data['activity_type_id'])) {
            $data['activity_type_id'] = 1;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $result = $service->saveResult($data, $this->calendarUserId);
            $this->notifyCoachesResultLogged($data['date'] ?? null);
            $this->checkVdotUpdate($data, $this->calendarUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Получить результат тренировки
     * GET /api.php?action=get_result&date=2026-01-25
     */
    public function getResult() {
        $date = $this->getParam('date');
        if (!$date) {
            $this->returnError('Параметр date обязателен');
            return;
        }
        
        try {
            $result = $this->loadWorkoutResult($date, $this->calendarUserId);
            $this->returnSuccess(['result' => $result]);
        } catch (Exception $e) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::error('Ошибка загрузки результата', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            $this->returnError('Ошибка загрузки результата', 500);
        }
    }
    
    /**
     * Загрузить тренировку из GPX/TCX файла
     * POST /api_v2.php?action=upload_workout (multipart: file, date, csrf_token)
     */
    public function uploadWorkout() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        $this->checkCsrfToken();
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->returnError('Файл не загружен или произошла ошибка');
            return;
        }
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['gpx', 'tcx'])) {
            $this->returnError('Допустимы только файлы GPX и TCX');
            return;
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            $this->returnError('Размер файла превышает 10MB');
            return;
        }
        $date = $_POST['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->returnError('Неверный формат даты');
            return;
        }
        require_once __DIR__ . '/../utils/GpxTcxParser.php';
        $workout = GpxTcxParser::parse($file['tmp_name'], $date);
        if (!$workout || !$workout['start_time']) {
            $this->returnError('Не удалось распарсить файл. Проверьте формат GPX/TCX.');
            return;
        }
        require_once __DIR__ . '/../services/WorkoutService.php';
        $service = new WorkoutService($this->db);
        $result = $service->importWorkouts($this->currentUserId, [$workout], 'gpx');
        $this->returnSuccess([
            'message' => 'Тренировка загружена',
            'imported' => $result['imported'],
            'workout' => $workout,
        ]);
    }

    /**
     * Получить все результаты
     * GET /api_v2.php?action=get_all_results
     */
    public function getAllResults() {
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $data = $service->getAllResults($this->calendarUserId);
            $this->returnSuccess($data);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Удалить тренировку
     * POST /api_v2.php?action=delete_workout
     */
    public function deleteWorkout() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        // Получаем данные из JSON body
        $data = $this->getJsonBody();
        if (!$data || !isset($data['workout_id'])) {
            $this->returnError('Не указан ID тренировки');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $workoutId = (int)$data['workout_id'];
            $isManual = isset($data['is_manual']) ? (bool)$data['is_manual'] : false;
            $result = $service->deleteWorkout($workoutId, $isManual, $this->calendarUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Загрузить упражнения дня
     */
    private function loadDayExercises($planDayId, $userId) {
        $stmt = $this->db->prepare("
            SELECT id, category, name, sets, reps, distance_m, duration_sec, weight_kg, pace, notes, order_index 
            FROM training_day_exercises 
            WHERE user_id = ? AND plan_day_id = ? 
            ORDER BY order_index ASC, id ASC
        ");
        $stmt->bind_param("ii", $userId, $planDayId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $exercises = [];
        while ($row = $result->fetch_assoc()) {
            $exercises[] = $row;
        }
        $stmt->close();
        
        return $exercises;
    }
    
    /**
     * Сохранить прогресс тренировки (старая функция save)
     * POST /api_v2.php?action=save
     */
    public function save() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        // Получаем данные из JSON body
        $data = $this->getJsonBody();
        if (!$data) {
            $this->returnError('Данные не переданы');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $result = $service->saveProgress($data, $this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Сбросить прогресс
     * POST /api_v2.php?action=reset
     */
    public function reset() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $result = $service->resetProgress($this->currentUserId);
            $this->returnSuccess($result);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Получить timeline данные тренировки
     * GET /api_v2.php?action=get_workout_timeline&workout_id=123
     */
    public function getWorkoutTimeline() {
        $workoutId = $this->getParam('workout_id');
        if (!$workoutId) {
            $this->returnError('Параметр workout_id обязателен');
            return;
        }
        
        try {
            require_once __DIR__ . '/../services/WorkoutService.php';
            $service = new WorkoutService($this->db);
            $timeline = $service->getWorkoutTimeline((int)$workoutId, $this->currentUserId);
            $this->returnSuccess(['timeline' => $timeline]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Проверяет, является ли завершённая тренировка контрольной/забегом,
     * и если да — пересчитывает VDOT пользователя, уведомляет в чат.
     */
    private function checkVdotUpdate(array $data, int $userId): void {
        try {
            $distanceKm = isset($data['result_distance']) ? (float)$data['result_distance'] : 0;
            $resultTime = $data['result_time'] ?? '';
            if ($distanceKm <= 0 || $resultTime === '') {
                return;
            }

            // Определяем тип тренировки из плана
            $weekNum = (int)($data['week'] ?? 0);
            $dayName = $data['day'] ?? '';
            if (!$weekNum || !$dayName) return;

            $dayMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
            $dayOfWeek = $dayMap[$dayName] ?? 0;
            if (!$dayOfWeek) return;

            $stmt = $this->db->prepare("
                SELECT tpd.type FROM training_plan_days tpd
                INNER JOIN training_plan_weeks tpw ON tpd.week_id = tpw.id
                WHERE tpw.user_id = ? AND tpw.week_number = ? AND tpd.day_of_week = ?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $userId, $weekNum, $dayOfWeek);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $type = $row['type'] ?? '';
            if (!in_array($type, ['control', 'race'])) {
                return;
            }

            // Парсим время
            $timeSec = 0;
            $parts = explode(':', $resultTime);
            if (count($parts) === 3) {
                $timeSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
            } elseif (count($parts) === 2) {
                $timeSec = (int)$parts[0] * 60 + (int)$parts[1];
            }
            if ($timeSec <= 0) return;

            require_once __DIR__ . '/../planrun_ai/prompt_builder.php';

            $newVdot = estimateVDOT($distanceKm, $timeSec);
            if ($newVdot < 20 || $newVdot > 85) return;

            // Читаем текущий VDOT пользователя (если хранится)
            $stmt = $this->db->prepare("SELECT last_race_distance_km, last_race_time, easy_pace_sec FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $oldVdot = null;
            if (!empty($user['last_race_distance_km']) && !empty($user['last_race_time'])) {
                $oldTimeParts = explode(':', $user['last_race_time']);
                $oldTimeSec = 0;
                if (count($oldTimeParts) === 3) {
                    $oldTimeSec = (int)$oldTimeParts[0] * 3600 + (int)$oldTimeParts[1] * 60 + (int)$oldTimeParts[2];
                } elseif (count($oldTimeParts) === 2) {
                    $oldTimeSec = (int)$oldTimeParts[0] * 60 + (int)$oldTimeParts[1];
                }
                if ($oldTimeSec > 0) {
                    $oldVdot = estimateVDOT((float)$user['last_race_distance_km'], $oldTimeSec);
                }
            }

            // Обновляем last_race в профиле
            $updateStmt = $this->db->prepare("
                UPDATE users SET last_race_distance_km = ?, last_race_time = ?, last_race_date = ? WHERE id = ?
            ");
            $date = $data['date'] ?? date('Y-m-d');
            $updateStmt->bind_param('dssi', $distanceKm, $resultTime, $date, $userId);
            $updateStmt->execute();
            $updateStmt->close();

            // Формируем уведомление в чат
            $newVdotR = round($newVdot, 1);
            $paces = getTrainingPaces($newVdot);
            $predictions = predictAllRaceTimes($newVdot);

            $typeLabel = $type === 'control' ? 'контрольной тренировки' : 'забега';
            $msg = "Результат {$typeLabel}: {$distanceKm} км за {$resultTime}.\n";
            $msg .= "Ваш VDOT: **{$newVdotR}**";
            if ($oldVdot) {
                $diff = round($newVdot - $oldVdot, 1);
                $arrow = $diff > 0 ? '+' : '';
                $msg .= " ({$arrow}{$diff})";
            }
            $msg .= "\n\n";

            // Тренировочные зоны
            $msg .= "Обновлённые зоны:\n";
            $msg .= "- Лёгкий: " . formatPaceSec($paces['easy'][0]) . " – " . formatPaceSec($paces['easy'][1]) . "/км\n";
            $msg .= "- Пороговый: " . formatPaceSec($paces['threshold']) . "/км\n";
            $msg .= "- Интервальный: " . formatPaceSec($paces['interval']) . "/км\n\n";

            // Прогнозы
            $msg .= "Прогнозы: ";
            $parts = [];
            foreach ($predictions as $label => $pred) {
                $distLabels = ['5k' => '5К', '10k' => '10К', 'half' => 'ПМ', 'marathon' => 'М'];
                $parts[] = ($distLabels[$label] ?? $label) . " " . $pred['formatted'];
            }
            $msg .= implode(' | ', $parts);

            require_once __DIR__ . '/../services/ChatService.php';
            $chatService = new ChatService($this->db);
            $chatService->addAIMessageToUser($userId, $msg);

        } catch (Throwable $e) {
            // Не ломаем основной flow при ошибке VDOT-обновления
            error_log("checkVdotUpdate error for user $userId: " . $e->getMessage());
        }
    }

    /**
     * Загрузить результат тренировки
     */
    private function loadWorkoutResult($date, $userId) {
        $stmt = $this->db->prepare("
            SELECT * FROM workout_log 
            WHERE user_id = ? AND training_date = ? 
            LIMIT 1
        ");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ?: null;
    }
}
