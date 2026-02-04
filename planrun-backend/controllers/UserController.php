<?php
/**
 * Контроллер для работы с пользователями
 */

require_once __DIR__ . '/BaseController.php';

class UserController extends BaseController {
    
    /**
     * Получить профиль текущего пользователя
     * GET /api_v2.php?action=get_profile
     */
    public function getProfile() {
        if (!$this->requireAuth()) {
            return;
        }
        
        try {
            require_once __DIR__ . '/../user_functions.php';
            $userData = getUserData($this->currentUserId, null, true);
            
            if (!$userData) {
                $this->returnError('Профиль не найден', 404);
                return;
            }
            
            // Парсим JSON поля
            if (isset($userData['preferred_days']) && is_string($userData['preferred_days'])) {
                $userData['preferred_days'] = json_decode($userData['preferred_days'], true) ?? [];
            }
            if (isset($userData['preferred_ofp_days']) && is_string($userData['preferred_ofp_days'])) {
                $userData['preferred_ofp_days'] = json_decode($userData['preferred_ofp_days'], true) ?? [];
            }
            
            // Убираем чувствительные данные
            unset($userData['password']);
            
            // Возвращаем данные в формате, ожидаемом фронтендом
            $this->returnSuccess($userData);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Обновить профиль пользователя
     * POST /api_v2.php?action=update_profile
     */
    public function updateProfile() {
        if (!$this->requireAuth()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            
            // Вспомогательная функция для нормализации null значений
            $normalizeNull = function($value) {
                if ($value === null || $value === 'null' || $value === '') {
                    return null;
                }
                return $value;
            };
            
            // Подготовка данных для обновления
            $updateFields = [];
            $updateValues = [];
            $types = '';
            
            // Базовые поля
            if (isset($data['username'])) {
                $username = trim($data['username']);
                if (strlen($username) < 3 || strlen($username) > 50) {
                    $this->returnError('Имя пользователя должно быть от 3 до 50 символов', 400);
                    return;
                }
                $updateFields[] = 'username = ?';
                $updateValues[] = $username;
                $types .= 's';
            }
            
            if (isset($data['email'])) {
                $email = $normalizeNull($data['email']);
                if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->returnError('Некорректный формат email', 400);
                    return;
                }
                $updateFields[] = 'email = ?';
                $updateValues[] = $email;
                $types .= 's';
            }
            
            if (isset($data['timezone'])) {
                $updateFields[] = 'timezone = ?';
                $updateValues[] = $normalizeNull($data['timezone']);
                $types .= 's';
            }
            
            // Профиль
            if (isset($data['gender'])) {
                $gender = $normalizeNull($data['gender']);
                if ($gender !== null && !in_array($gender, ['male', 'female'], true)) {
                    $gender = null;
                }
                $updateFields[] = 'gender = ?';
                $updateValues[] = $gender;
                $types .= 's';
            }
            
            if (isset($data['birth_year'])) {
                $birthYear = $normalizeNull($data['birth_year']);
                if ($birthYear !== null) {
                    $birthYear = (int)$birthYear;
                    if ($birthYear < 1900 || $birthYear > date('Y')) {
                        $this->returnError('Некорректный год рождения', 400);
                        return;
                    }
                }
                $updateFields[] = 'birth_year = ?';
                $updateValues[] = $birthYear;
                $types .= 'i';
            }
            
            if (isset($data['height_cm'])) {
                $heightCm = $normalizeNull($data['height_cm']);
                if ($heightCm !== null) {
                    $heightCm = (int)$heightCm;
                    if ($heightCm < 50 || $heightCm > 250) {
                        $this->returnError('Некорректный рост (50-250 см)', 400);
                        return;
                    }
                }
                $updateFields[] = 'height_cm = ?';
                $updateValues[] = $heightCm;
                $types .= 'i';
            }
            
            if (isset($data['weight_kg'])) {
                $weightKg = $normalizeNull($data['weight_kg']);
                if ($weightKg !== null) {
                    $weightKg = (float)$weightKg;
                    if ($weightKg < 20 || $weightKg > 300) {
                        $this->returnError('Некорректный вес (20-300 кг)', 400);
                        return;
                    }
                }
                $updateFields[] = 'weight_kg = ?';
                $updateValues[] = $weightKg;
                $types .= 'd';
            }
            
            // Цель и забег
            if (isset($data['goal_type'])) {
                $goalType = $data['goal_type'];
                if (!in_array($goalType, ['health', 'race', 'weight_loss', 'time_improvement'], true)) {
                    $goalType = 'health';
                }
                $updateFields[] = 'goal_type = ?';
                $updateValues[] = $goalType;
                $types .= 's';
            }
            
            if (isset($data['race_distance'])) {
                $updateFields[] = 'race_distance = ?';
                $updateValues[] = $normalizeNull($data['race_distance']);
                $types .= 's';
            }
            
            if (isset($data['race_date'])) {
                $updateFields[] = 'race_date = ?';
                $updateValues[] = $normalizeNull($data['race_date']);
                $types .= 's';
            }
            
            if (isset($data['race_target_time'])) {
                $updateFields[] = 'race_target_time = ?';
                $updateValues[] = $normalizeNull($data['race_target_time']);
                $types .= 's';
            }
            
            if (isset($data['target_marathon_date'])) {
                $updateFields[] = 'target_marathon_date = ?';
                $updateValues[] = $normalizeNull($data['target_marathon_date']);
                $types .= 's';
            }
            
            if (isset($data['target_marathon_time'])) {
                $updateFields[] = 'target_marathon_time = ?';
                $updateValues[] = $normalizeNull($data['target_marathon_time']);
                $types .= 's';
            }
            
            // Опыт и тренировки
            if (isset($data['experience_level'])) {
                $expLevel = $data['experience_level'];
                // Поддержка новых значений: novice, beginner, intermediate, advanced, expert
                // И обратная совместимость со старыми значениями
                if (!in_array($expLevel, ['novice', 'beginner', 'intermediate', 'advanced', 'expert'], true)) {
                    // Маппинг старых значений для обратной совместимости
                    if ($expLevel === 'zero' || $expLevel === '') {
                        $expLevel = 'novice';
                    } else {
                        $expLevel = 'beginner'; // по умолчанию
                    }
                }
                $updateFields[] = 'experience_level = ?';
                $updateValues[] = $expLevel;
                $types .= 's';
            }
            
            if (isset($data['weekly_base_km'])) {
                $weeklyBaseKm = $normalizeNull($data['weekly_base_km']);
                if ($weeklyBaseKm !== null) {
                    $weeklyBaseKm = (float)$weeklyBaseKm;
                }
                $updateFields[] = 'weekly_base_km = ?';
                $updateValues[] = $weeklyBaseKm;
                $types .= 'd';
            }
            
            if (isset($data['sessions_per_week'])) {
                $sessionsPerWeek = $normalizeNull($data['sessions_per_week']);
                if ($sessionsPerWeek !== null) {
                    $sessionsPerWeek = (int)$sessionsPerWeek;
                }
                $updateFields[] = 'sessions_per_week = ?';
                $updateValues[] = $sessionsPerWeek;
                $types .= 'i';
            }
            
            if (isset($data['preferred_days'])) {
                $preferredDays = is_array($data['preferred_days']) ? $data['preferred_days'] : [];
                $preferredDaysJson = !empty($preferredDays) ? json_encode(array_values($preferredDays), JSON_UNESCAPED_UNICODE) : null;
                $updateFields[] = 'preferred_days = ?';
                $updateValues[] = $preferredDaysJson;
                $types .= 's';
            }
            
            if (isset($data['preferred_ofp_days'])) {
                $preferredOfpDays = is_array($data['preferred_ofp_days']) ? $data['preferred_ofp_days'] : [];
                $preferredOfpDaysJson = !empty($preferredOfpDays) ? json_encode(array_values($preferredOfpDays), JSON_UNESCAPED_UNICODE) : null;
                $updateFields[] = 'preferred_ofp_days = ?';
                $updateValues[] = $preferredOfpDaysJson;
                $types .= 's';
            }
            
            if (isset($data['has_treadmill'])) {
                $hasTreadmill = isset($data['has_treadmill']) && ($data['has_treadmill'] === true || $data['has_treadmill'] === 1 || $data['has_treadmill'] === '1') ? 1 : 0;
                $updateFields[] = 'has_treadmill = ?';
                $updateValues[] = $hasTreadmill;
                $types .= 'i';
            }
            
            if (isset($data['training_time_pref'])) {
                $trainingTimePref = $normalizeNull($data['training_time_pref']);
                if ($trainingTimePref !== null && !in_array($trainingTimePref, ['morning', 'day', 'evening'], true)) {
                    $trainingTimePref = null;
                }
                $updateFields[] = 'training_time_pref = ?';
                $updateValues[] = $trainingTimePref;
                $types .= 's';
            }
            
            if (isset($data['ofp_preference'])) {
                $ofpPreference = $normalizeNull($data['ofp_preference']);
                if ($ofpPreference !== null && !in_array($ofpPreference, ['gym', 'home', 'both', 'group_classes', 'online'], true)) {
                    $ofpPreference = null;
                }
                $updateFields[] = 'ofp_preference = ?';
                $updateValues[] = $ofpPreference;
                $types .= 's';
            }
            
            if (isset($data['training_mode'])) {
                $trainingMode = $data['training_mode'];
                if (!in_array($trainingMode, ['ai', 'coach', 'both', 'self'], true)) {
                    $trainingMode = 'ai';
                }
                $updateFields[] = 'training_mode = ?';
                $updateValues[] = $trainingMode;
                $types .= 's';
            }
            
            if (isset($data['training_start_date'])) {
                $updateFields[] = 'training_start_date = ?';
                $updateValues[] = $normalizeNull($data['training_start_date']);
                $types .= 's';
            }
            
            // Здоровье
            if (isset($data['health_notes'])) {
                $updateFields[] = 'health_notes = ?';
                $updateValues[] = $normalizeNull($data['health_notes']);
                $types .= 's';
            }
            
            if (isset($data['device_type'])) {
                $updateFields[] = 'device_type = ?';
                $updateValues[] = $normalizeNull($data['device_type']);
                $types .= 's';
            }
            
            if (isset($data['weight_goal_kg'])) {
                $weightGoalKg = $normalizeNull($data['weight_goal_kg']);
                if ($weightGoalKg !== null) {
                    $weightGoalKg = (float)$weightGoalKg;
                }
                $updateFields[] = 'weight_goal_kg = ?';
                $updateValues[] = $weightGoalKg;
                $types .= 'd';
            }
            
            if (isset($data['weight_goal_date'])) {
                $updateFields[] = 'weight_goal_date = ?';
                $updateValues[] = $normalizeNull($data['weight_goal_date']);
                $types .= 's';
            }
            
            if (isset($data['health_program'])) {
                $healthProgram = $normalizeNull($data['health_program']);
                if ($healthProgram !== null && !in_array($healthProgram, ['start_running', 'couch_to_5k', 'regular_running', 'custom'], true)) {
                    $healthProgram = null;
                }
                $updateFields[] = 'health_program = ?';
                $updateValues[] = $healthProgram;
                $types .= 's';
            }
            
            if (isset($data['health_plan_weeks'])) {
                $healthPlanWeeks = $normalizeNull($data['health_plan_weeks']);
                if ($healthPlanWeeks !== null) {
                    $healthPlanWeeks = (int)$healthPlanWeeks;
                }
                $updateFields[] = 'health_plan_weeks = ?';
                $updateValues[] = $healthPlanWeeks;
                $types .= 'i';
            }
            
            if (isset($data['current_running_level'])) {
                $currentRunningLevel = $normalizeNull($data['current_running_level']);
                if ($currentRunningLevel !== null && !in_array($currentRunningLevel, ['zero', 'basic', 'comfortable'], true)) {
                    $currentRunningLevel = null;
                }
                $updateFields[] = 'current_running_level = ?';
                $updateValues[] = $currentRunningLevel;
                $types .= 's';
            }
            
            // Расширенный профиль бегуна
            if (isset($data['running_experience'])) {
                $runningExperience = $normalizeNull($data['running_experience']);
                if ($runningExperience !== null && !in_array($runningExperience, ['less_3m', '3_6m', '6_12m', '1_2y', 'more_2y'], true)) {
                    $runningExperience = null;
                }
                $updateFields[] = 'running_experience = ?';
                $updateValues[] = $runningExperience;
                $types .= 's';
            }
            
            if (isset($data['easy_pace_sec'])) {
                $easyPaceSec = $normalizeNull($data['easy_pace_sec']);
                if ($easyPaceSec !== null) {
                    $easyPaceSec = (int)$easyPaceSec;
                }
                $updateFields[] = 'easy_pace_sec = ?';
                $updateValues[] = $easyPaceSec;
                $types .= 'i';
            }
            
            if (isset($data['is_first_race_at_distance'])) {
                $isFirstRace = $normalizeNull($data['is_first_race_at_distance']);
                if ($isFirstRace !== null) {
                    $isFirstRace = ($isFirstRace === true || $isFirstRace === 1 || $isFirstRace === '1') ? 1 : 0;
                }
                $updateFields[] = 'is_first_race_at_distance = ?';
                $updateValues[] = $isFirstRace;
                $types .= 'i';
            }
            
            if (isset($data['last_race_distance'])) {
                $lastRaceDistance = $normalizeNull($data['last_race_distance']);
                if ($lastRaceDistance !== null && !in_array($lastRaceDistance, ['5k', '10k', 'half', 'marathon', 'other'], true)) {
                    $lastRaceDistance = null;
                }
                $updateFields[] = 'last_race_distance = ?';
                $updateValues[] = $lastRaceDistance;
                $types .= 's';
            }
            
            if (isset($data['last_race_distance_km'])) {
                $lastRaceDistanceKm = $normalizeNull($data['last_race_distance_km']);
                if ($lastRaceDistanceKm !== null) {
                    $lastRaceDistanceKm = (float)$lastRaceDistanceKm;
                }
                $updateFields[] = 'last_race_distance_km = ?';
                $updateValues[] = $lastRaceDistanceKm;
                $types .= 'd';
            }
            
            if (isset($data['last_race_time'])) {
                $updateFields[] = 'last_race_time = ?';
                $updateValues[] = $normalizeNull($data['last_race_time']);
                $types .= 's';
            }
            
            if (isset($data['last_race_date'])) {
                $updateFields[] = 'last_race_date = ?';
                $updateValues[] = $normalizeNull($data['last_race_date']);
                $types .= 's';
            }
            
            // Аватар и приватность
            if (isset($data['avatar_path'])) {
                $updateFields[] = 'avatar_path = ?';
                $updateValues[] = $normalizeNull($data['avatar_path']);
                $types .= 's';
            }
            
            if (isset($data['privacy_level'])) {
                $privacyLevel = $data['privacy_level'];
                if (!in_array($privacyLevel, ['public', 'private', 'link'], true)) {
                    $privacyLevel = 'public';
                }
                $updateFields[] = 'privacy_level = ?';
                $updateValues[] = $privacyLevel;
                $types .= 's';
            }
            
            // Обновляем username_slug если изменился username
            if (isset($data['username'])) {
                require_once __DIR__ . '/../user_functions.php';
                $usernameSlug = generateUsernameSlug($data['username'], $this->currentUserId);
                $updateFields[] = 'username_slug = ?';
                $updateValues[] = $usernameSlug;
                $types .= 's';
            }
            
            if (empty($updateFields)) {
                $this->returnError('Нет данных для обновления', 400);
                return;
            }
            
            // Добавляем updated_at
            $updateFields[] = 'updated_at = NOW()';
            
            // Выполняем обновление
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateValues[] = $this->currentUserId;
            $types .= 'i';
            
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $this->returnError('Ошибка подготовки запроса: ' . $this->db->error, 500);
                return;
            }
            
            $stmt->bind_param($types, ...$updateValues);
            $stmt->execute();
            
            if ($stmt->error) {
                $stmt->close();
                $this->returnError('Ошибка обновления профиля: ' . $stmt->error, 500);
                return;
            }
            
            $stmt->close();
            
            // Очищаем кеш пользователя
            require_once __DIR__ . '/../user_functions.php';
            clearUserCache($this->currentUserId);
            
            // Возвращаем обновленные данные
            $updatedUser = getUserData($this->currentUserId, null, false);
            if (isset($updatedUser['preferred_days']) && is_string($updatedUser['preferred_days'])) {
                $updatedUser['preferred_days'] = json_decode($updatedUser['preferred_days'], true) ?? [];
            }
            if (isset($updatedUser['preferred_ofp_days']) && is_string($updatedUser['preferred_ofp_days'])) {
                $updatedUser['preferred_ofp_days'] = json_decode($updatedUser['preferred_ofp_days'], true) ?? [];
            }
            unset($updatedUser['password']);
            
            require_once __DIR__ . '/../config/Logger.php';
            Logger::info("Профиль пользователя обновлен", ['user_id' => $this->currentUserId]);
            
            $this->returnSuccess([
                'message' => 'Профиль успешно обновлен',
                'user' => $updatedUser
            ]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Удалить пользователя
     * POST /api_v2.php?action=delete_user
     */
    public function deleteUser() {
        if (!$this->requireAuth() || !$this->requireEdit()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            // Проверяем, что текущий пользователь - администратор
            require_once __DIR__ . '/../user_functions.php';
            $currentUser = getCurrentUser();
            if (!$currentUser || $currentUser['role'] !== 'admin') {
                $this->returnError('Доступ запрещен. Требуется роль администратора.', 403);
                return;
            }
            
            $data = $this->getJsonBody();
            $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : null;
            
            if (!$targetUserId || $targetUserId <= 0) {
                $this->returnError('Не указан ID пользователя', 400);
                return;
            }
            
            // Нельзя удалить самого себя
            if ($targetUserId === $this->currentUserId) {
                $this->returnError('Нельзя удалить самого себя', 400);
                return;
            }
            
            // Проверяем, что пользователь существует
            $checkStmt = $this->db->prepare("SELECT id, username FROM users WHERE id = ?");
            $checkStmt->bind_param("i", $targetUserId);
            $checkStmt->execute();
            $userResult = $checkStmt->get_result();
            $user = $userResult->fetch_assoc();
            $checkStmt->close();
            
            if (!$user) {
                $this->returnError('Пользователь не найден', 404);
                return;
            }
            
            // Начинаем транзакцию для каскадного удаления
            $this->db->begin_transaction();
            
            try {
                // 1. Удаляем упражнения дней тренировок
                $deleteExercisesStmt = $this->db->prepare("DELETE tde FROM training_day_exercises tde INNER JOIN training_plan_days tpd ON tde.plan_day_id = tpd.id WHERE tpd.user_id = ?");
                $deleteExercisesStmt->bind_param("i", $targetUserId);
                $deleteExercisesStmt->execute();
                $deleteExercisesStmt->close();
                
                // 2. Удаляем дни тренировок
                $deleteDaysStmt = $this->db->prepare("DELETE FROM training_plan_days WHERE user_id = ?");
                $deleteDaysStmt->bind_param("i", $targetUserId);
                $deleteDaysStmt->execute();
                $deleteDaysStmt->close();
                
                // 3. Удаляем недели тренировок
                $deleteWeeksStmt = $this->db->prepare("DELETE FROM training_plan_weeks WHERE user_id = ?");
                $deleteWeeksStmt->bind_param("i", $targetUserId);
                $deleteWeeksStmt->execute();
                $deleteWeeksStmt->close();
                
                // 4. Удаляем фазы тренировок (если таблица существует)
                $deletePhasesStmt = $this->db->prepare("DELETE FROM training_plan_phases WHERE user_id = ?");
                $deletePhasesStmt->bind_param("i", $targetUserId);
                $deletePhasesStmt->execute();
                $deletePhasesStmt->close();
                
                // 5. Удаляем планы тренировок
                $deletePlansStmt = $this->db->prepare("DELETE FROM user_training_plans WHERE user_id = ?");
                $deletePlansStmt->bind_param("i", $targetUserId);
                $deletePlansStmt->execute();
                $deletePlansStmt->close();
                
                // 6. Удаляем прогресс тренировок
                $deleteProgressStmt = $this->db->prepare("DELETE FROM training_progress WHERE user_id = ?");
                $deleteProgressStmt->bind_param("i", $targetUserId);
                $deleteProgressStmt->execute();
                $deleteProgressStmt->close();
                
                // 7. Удаляем ручные тренировки (workout_log)
                $deleteWorkoutLogStmt = $this->db->prepare("DELETE FROM workout_log WHERE user_id = ?");
                $deleteWorkoutLogStmt->bind_param("i", $targetUserId);
                $deleteWorkoutLogStmt->execute();
                $deleteWorkoutLogStmt->close();
                
                // 8. Удаляем автоматические тренировки (workouts)
                $deleteWorkoutsStmt = $this->db->prepare("DELETE FROM workouts WHERE user_id = ?");
                $deleteWorkoutsStmt->bind_param("i", $targetUserId);
                $deleteWorkoutsStmt->execute();
                $deleteWorkoutsStmt->close();
                
                // 9. Удаляем самого пользователя
                $deleteUserStmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
                $deleteUserStmt->bind_param("i", $targetUserId);
                $deleteUserStmt->execute();
                
                if ($deleteUserStmt->error) {
                    throw new Exception('Ошибка удаления пользователя: ' . $deleteUserStmt->error);
                }
                
                $deleteUserStmt->close();
                
                // Подтверждаем транзакцию
                $this->db->commit();
                
                require_once __DIR__ . '/../config/Logger.php';
                Logger::info("Пользователь удален", [
                    'username' => $user['username'],
                    'target_user_id' => $targetUserId,
                    'admin_user_id' => $this->currentUserId
                ]);
                
                $this->returnSuccess([
                    'message' => 'Пользователь и все его данные успешно удалены',
                    'username' => $user['username']
                ]);
            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Загрузить аватар
     * POST /api_v2.php?action=upload_avatar
     */
    public function uploadAvatar() {
        if (!$this->requireAuth()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $this->returnError('Файл не загружен или произошла ошибка', 400);
                return;
            }
            
            $file = $_FILES['avatar'];
            
            // Проверка типа файла
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                $this->returnError('Недопустимый тип файла. Разрешены только изображения (JPEG, PNG, GIF, WebP)', 400);
                return;
            }
            
            // Проверка размера файла (максимум 5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                $this->returnError('Размер файла превышает 5MB', 400);
                return;
            }
            
            // Создаем директорию для аватаров (в корне проекта)
            $projectRoot = dirname(dirname(__DIR__)); // /var/www/vladimirov
            $avatarDir = $projectRoot . '/uploads/avatars/';
            
            if (!is_dir($avatarDir)) {
                mkdir($avatarDir, 0755, true);
            }
            
            // Генерируем уникальное имя файла
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = 'avatar_' . $this->currentUserId . '_' . time() . '.' . $extension;
            $filePath = $avatarDir . $fileName;
            
            // Загружаем файл
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Получаем старый путь аватара для удаления
                $oldAvatarStmt = $this->db->prepare("SELECT avatar_path FROM users WHERE id = ?");
                $oldAvatarStmt->bind_param("i", $this->currentUserId);
                $oldAvatarStmt->execute();
                $oldAvatar = $oldAvatarStmt->get_result()->fetch_assoc();
                $oldAvatarStmt->close();
                
                // Удаляем старый аватар если есть
                if ($oldAvatar && $oldAvatar['avatar_path']) {
                    $oldPath = $projectRoot . $oldAvatar['avatar_path'];
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }
                
                // Сохраняем путь в БД (относительный путь)
                $relativePath = '/uploads/avatars/' . $fileName;
                $updateStmt = $this->db->prepare("UPDATE users SET avatar_path = ? WHERE id = ?");
                $updateStmt->bind_param("si", $relativePath, $this->currentUserId);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Очищаем кеш пользователя
                require_once __DIR__ . '/../user_functions.php';
                clearUserCache($this->currentUserId);
                
                require_once __DIR__ . '/../config/Logger.php';
                Logger::info("Аватар загружен", ['user_id' => $this->currentUserId]);
                
                // Возвращаем обновленные данные пользователя
                $updatedUser = getUserData($this->currentUserId, null, false);
                unset($updatedUser['password']);
                
                $this->returnSuccess([
                    'message' => 'Аватар успешно загружен',
                    'avatar_path' => $relativePath,
                    'user' => $updatedUser
                ]);
            } else {
                $this->returnError('Ошибка загрузки файла', 500);
            }
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Удалить аватар
     * POST /api_v2.php?action=remove_avatar
     */
    public function removeAvatar() {
        if (!$this->requireAuth()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            // Получаем путь к аватару
            $stmt = $this->db->prepare("SELECT avatar_path FROM users WHERE id = ?");
            $stmt->bind_param("i", $this->currentUserId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result && $result['avatar_path']) {
                // Удаляем файл
                $projectRoot = dirname(dirname(__DIR__));
                $oldPath = $projectRoot . $result['avatar_path'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            
            // Обновляем БД
            $updateStmt = $this->db->prepare("UPDATE users SET avatar_path = NULL WHERE id = ?");
            $updateStmt->bind_param("i", $this->currentUserId);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Очищаем кеш
            require_once __DIR__ . '/../user_functions.php';
            require_once __DIR__ . '/../config/Cache.php';
            Cache::delete("user_data_{$this->currentUserId}");
            
            require_once __DIR__ . '/../config/Logger.php';
            Logger::info("Аватар удален", ['user_id' => $this->currentUserId]);
            
            $this->returnSuccess(['message' => 'Аватар успешно удален']);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Обновить настройки приватности
     * POST /api_v2.php?action=update_privacy
     */
    public function updatePrivacy() {
        if (!$this->requireAuth()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            $data = $this->getJsonBody();
            $privacyLevel = $data['privacy_level'] ?? 'public';
            
            if (!in_array($privacyLevel, ['public', 'private', 'link'], true)) {
                $privacyLevel = 'public';
            }
            
            // Если выбран уровень "по ссылке" и токена нет - генерируем его
            if ($privacyLevel === 'link') {
                $tokenStmt = $this->db->prepare("SELECT public_token FROM users WHERE id = ?");
                $tokenStmt->bind_param("i", $this->currentUserId);
                $tokenStmt->execute();
                $tokenResult = $tokenStmt->get_result()->fetch_assoc();
                $tokenStmt->close();
                
                if (empty($tokenResult['public_token'])) {
                    // Генерируем новый токен
                    $token = bin2hex(random_bytes(16)); // 32 символа
                    $updateTokenStmt = $this->db->prepare("UPDATE users SET public_token = ? WHERE id = ?");
                    $updateTokenStmt->bind_param("si", $token, $this->currentUserId);
                    $updateTokenStmt->execute();
                    $updateTokenStmt->close();
                }
            }
            
            // Обновляем уровень приватности
            $stmt = $this->db->prepare("UPDATE users SET privacy_level = ? WHERE id = ?");
            $stmt->bind_param("si", $privacyLevel, $this->currentUserId);
            $stmt->execute();
            $stmt->close();
            
            // Очищаем кеш
            require_once __DIR__ . '/../user_functions.php';
            require_once __DIR__ . '/../config/Cache.php';
            Cache::delete("user_data_{$this->currentUserId}");
            
            require_once __DIR__ . '/../config/Logger.php';
            Logger::info("Настройки приватности обновлены", [
                'user_id' => $this->currentUserId,
                'privacy_level' => $privacyLevel
            ]);
            
            $this->returnSuccess([
                'message' => 'Настройки приватности обновлены',
                'privacy_level' => $privacyLevel
            ]);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Отвязать Telegram ID
     * POST /api_v2.php?action=unlink_telegram
     */
    public function unlinkTelegram() {
        if (!$this->requireAuth()) {
            return;
        }
        
        $this->checkCsrfToken();
        
        try {
            // Обновляем БД
            $stmt = $this->db->prepare("UPDATE users SET telegram_id = NULL WHERE id = ?");
            $stmt->bind_param("i", $this->currentUserId);
            $stmt->execute();
            $stmt->close();
            
            // Очищаем кеш
            require_once __DIR__ . '/../user_functions.php';
            require_once __DIR__ . '/../config/Cache.php';
            Cache::delete("user_data_{$this->currentUserId}");
            
            require_once __DIR__ . '/../config/Logger.php';
            Logger::info("Telegram отвязан", ['user_id' => $this->currentUserId]);
            
            $this->returnSuccess(['message' => 'Telegram успешно отвязан']);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
}
