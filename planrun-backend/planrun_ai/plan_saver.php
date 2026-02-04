<?php
/**
 * Сохранение плана тренировок в БД
 * Конвертирует план из формата RAG API в структуру БД
 */

require_once __DIR__ . '/../db_config.php';

/**
 * Сохранение плана тренировок в БД
 * 
 * @param mysqli $db Соединение с БД
 * @param int $userId ID пользователя
 * @param array $planData Данные плана из RAG API
 * @param string $startDate Дата начала тренировок (YYYY-MM-DD)
 * @return void
 * @throws Exception
 */
function saveTrainingPlan($db, $userId, $planData, $startDate) {
    if (!isset($planData['weeks']) || !is_array($planData['weeks'])) {
        throw new Exception("План не содержит данных о неделях");
    }
    
    $startDateTime = new DateTime($startDate);
    
    // Удаляем старый план пользователя
    $deleteDaysStmt = $db->prepare("DELETE FROM training_plan_days WHERE user_id = ?");
    $deleteDaysStmt->bind_param('i', $userId);
    $deleteDaysStmt->execute();
    $deleteDaysStmt->close();
    
    $deleteWeeksStmt = $db->prepare("DELETE FROM training_plan_weeks WHERE user_id = ?");
    $deleteWeeksStmt->bind_param('i', $userId);
    $deleteWeeksStmt->execute();
    $deleteWeeksStmt->close();
    
    // Маппинг типов тренировок
    // ВАЖНО: Используем только значения из ENUM колонки type в БД:
    // 'rest','tempo','interval','long','race','other','free','easy','sbu','fartlek'
    $typeMap = [
        'easy_run' => 'easy',
        'easy' => 'easy',
        'interval' => 'interval',
        'tempo' => 'tempo',
        'long_run' => 'long',
        'long' => 'long',
        'rest' => 'rest',
        'ofp' => 'other',      // ОФП -> other (нет 'ofp' в ENUM)
        'marathon' => 'long',   // Марафон -> long (нет 'marathon' в ENUM)
        'control' => 'tempo',   // Контрольная -> tempo (нет 'control' в ENUM)
        'race' => 'race',
        'fartlek' => 'fartlek'
    ];
    
    // Разрешенные типы из ENUM колонки
    $allowedTypes = ['rest', 'tempo', 'interval', 'long', 'race', 'other', 'free', 'easy', 'sbu', 'fartlek'];
    
    // Маппинг дней недели
    $dayNameToNumber = [
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
        'sun' => 7
    ];
    
    // Сохраняем недели
    foreach ($planData['weeks'] as $weekIndex => $week) {
        $weekNumber = $week['week_number'] ?? ($weekIndex + 1);
        
        // Вычисляем дату начала недели
        $weekStartDate = clone $startDateTime;
        $weekStartDate->modify('+' . (($weekNumber - 1) * 7) . ' days');
        
        // Находим понедельник этой недели
        $dayOfWeek = (int)$weekStartDate->format('N'); // 1 = Пн, 7 = Вс
        if ($dayOfWeek > 1) {
            $weekStartDate->modify('-' . ($dayOfWeek - 1) . ' days');
        }
        
        // Вставляем неделю
        $insertWeekStmt = $db->prepare("
            INSERT INTO training_plan_weeks (user_id, week_number, start_date, total_volume)
            VALUES (?, ?, ?, ?)
        ");
        $totalVolume = 0;
        $weekStartDateStr = $weekStartDate->format('Y-m-d');
        $insertWeekStmt->bind_param('iisd', $userId, $weekNumber, $weekStartDateStr, $totalVolume);
        $insertWeekStmt->execute();
        $weekId = $db->insert_id;
        $insertWeekStmt->close();
        
        if (!$weekId) {
            throw new Exception("Ошибка создания недели {$weekNumber}");
        }
        
        // Сохраняем дни недели
        if (isset($week['days']) && is_array($week['days'])) {
            foreach ($week['days'] as $dayIndex => $day) {
                // Определяем день недели
                $dayOfWeek = ($dayIndex % 7) + 1; // 1 = Пн, 7 = Вс
                
                // Вычисляем дату дня
                $dayDate = clone $weekStartDate;
                $dayDate->modify('+' . ($dayOfWeek - 1) . ' days');
                
                // Тип тренировки
                $type = $day['type'] ?? 'rest';
                $originalType = $type;
                
                // Применяем маппинг, если значение есть в мапе
                if (isset($typeMap[$type])) {
                    $type = $typeMap[$type];
                } else {
                    // Если значения нет в мапе, проверяем, может это уже разрешенное значение
                    $type = trim(strtolower($type));
                    if (!in_array($type, $allowedTypes, true)) {
                        // Неизвестный тип - заменяем на 'rest'
                        error_log("saveTrainingPlan: Неизвестный тип тренировки '{$originalType}', заменяем на 'rest'");
                        $type = 'rest';
                    }
                }
                
                // Финальная валидация: гарантируем, что тип из разрешенного списка
                if (!in_array($type, $allowedTypes, true)) {
                    error_log("saveTrainingPlan: Тип '{$type}' не в списке разрешенных, заменяем на 'rest'");
                    $type = 'rest';
                }
                
                // Описание
                $description = $day['description'] ?? '';
                if (empty($description) && isset($day['distance_km'])) {
                    $description = "Бег {$day['distance_km']} км";
                    if (isset($day['pace'])) {
                        $description .= " в темпе {$day['pace']}";
                    }
                }
                
                // Ключевая тренировка (используем только разрешенные типы)
                $isKeyWorkout = in_array($type, ['interval', 'tempo', 'long', 'race']);
                
                // Вставляем день
                $insertDayStmt = $db->prepare("
                    INSERT INTO training_plan_days 
                    (user_id, week_id, day_of_week, type, description, is_key_workout, date)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $dayDateStr = $dayDate->format('Y-m-d');
                $isKey = $isKeyWorkout ? 1 : 0;
                $insertDayStmt->bind_param('iiissis', $userId, $weekId, $dayOfWeek, $type, $description, $isKey, $dayDateStr);
                $insertDayStmt->execute();
                $dayId = $db->insert_id;
                $insertDayStmt->close();
                
                if (!$dayId) {
                    error_log("Ошибка создания дня {$dayOfWeek} для недели {$weekNumber}");
                    continue;
                }
                
                // Сохраняем упражнения (если есть дистанция)
                // Используем только разрешенные типы из ENUM
                if (isset($day['distance_km']) && $day['distance_km'] > 0 && in_array($type, ['easy', 'long', 'tempo', 'interval', 'fartlek', 'race', 'other', 'free'])) {
                    $distanceM = (float)$day['distance_km'] * 1000;
                    
                    $insertExerciseStmt = $db->prepare("
                        INSERT INTO training_day_exercises 
                        (user_id, plan_day_id, category, name, distance_m, duration_sec, notes)
                        VALUES (?, ?, 'run', ?, ?, ?, ?)
                    ");
                    // Конвертируем минуты в секунды, если указано
                    $durationSec = null;
                    if (isset($day['duration_minutes']) && $day['duration_minutes'] > 0) {
                        $durationSec = (int)($day['duration_minutes'] * 60);
                    }
                    $exerciseName = "Бег " . number_format($day['distance_km'], 1) . " км";
                    $exerciseDesc = $description;
                    $insertExerciseStmt->bind_param('iisdis', $userId, $dayId, $exerciseName, $distanceM, $durationSec, $exerciseDesc);
                    $insertExerciseStmt->execute();
                    if ($insertExerciseStmt->error) {
                        error_log("Ошибка сохранения упражнения: " . $insertExerciseStmt->error);
                        throw new Exception("Ошибка сохранения упражнения: " . $insertExerciseStmt->error);
                    }
                    $insertExerciseStmt->close();
                    
                    // Обновляем общий объем недели
                    $totalVolume += (float)$day['distance_km'];
                }
            }
            
            // Обновляем общий объем недели
            $updateVolumeStmt = $db->prepare("
                UPDATE training_plan_weeks 
                SET total_volume = ? 
                WHERE id = ?
            ");
            $updateVolumeStmt->bind_param('di', $totalVolume, $weekId);
            $updateVolumeStmt->execute();
            $updateVolumeStmt->close();
        }
    }
    
    error_log("saveTrainingPlan: План успешно сохранен для пользователя {$userId}, недель: " . count($planData['weeks']));
}
