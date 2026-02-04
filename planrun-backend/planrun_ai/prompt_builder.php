<?php
/**
 * Построитель промптов для генерации планов тренировок
 * Создает промпты специально для нашего проекта с учетом всех данных пользователя
 */

/**
 * Построение промпта для генерации плана тренировок
 * 
 * @param array $userData Данные пользователя
 * @param string $goalType Тип цели (health, race, weight_loss, time_improvement)
 * @return string Промпт для LLM
 */
function buildTrainingPlanPrompt($userData, $goalType = 'health') {
    $prompt = "";
    
    // Системная часть
    $prompt .= "Ты профессиональный тренер по бегу с доступом к научной базе знаний о беге и тренировках.\n";
    $prompt .= "Твоя задача - создать персональный план тренировок на основе данных пользователя и научных принципов.\n";
    $prompt .= "Отвечай ТОЛЬКО на русском языке и ТОЛЬКО в формате JSON.\n\n";
    
    // Информация о пользователе
    $prompt .= "═══ ИНФОРМАЦИЯ О ПОЛЬЗОВАТЕЛЕ ═══\n\n";
    
    // Основные данные
    if (!empty($userData['gender'])) {
        $gender = $userData['gender'] === 'male' ? 'мужской' : 'женский';
        $prompt .= "Пол: {$gender}\n";
    }
    
    if (!empty($userData['birth_year'])) {
        $age = date('Y') - (int)$userData['birth_year'];
        $prompt .= "Возраст: ~{$age} лет\n";
    }
    
    if (!empty($userData['height_cm'])) {
        $prompt .= "Рост: {$userData['height_cm']} см\n";
    }
    
    if (!empty($userData['weight_kg'])) {
        $prompt .= "Вес: {$userData['weight_kg']} кг\n";
    }
    
    // Опыт и уровень
    if (!empty($userData['experience_level'])) {
        $levelMap = [
            'beginner' => 'Новичок (менее 6 месяцев бега)',
            'intermediate' => 'Любитель (6 месяцев - 2 года)',
            'advanced' => 'Опытный (более 2 лет)'
        ];
        $level = $levelMap[$userData['experience_level']] ?? $userData['experience_level'];
        $prompt .= "Уровень подготовки: {$level}\n";
    }
    
    if (!empty($userData['weekly_base_km'])) {
        $prompt .= "Текущий объем бега: {$userData['weekly_base_km']} км в неделю\n";
    }
    
    if (!empty($userData['sessions_per_week'])) {
        $prompt .= "Желаемое количество тренировок в неделю: {$userData['sessions_per_week']}\n";
    }
    
    // Цель и параметры
    $prompt .= "\n═══ ЦЕЛЬ ТРЕНИРОВОК ═══\n\n";
    
    switch ($goalType) {
        case 'race':
            $prompt .= "Цель: Подготовка к забегу\n";
            if (!empty($userData['race_distance'])) {
                $distanceMap = [
                    '5k' => '5 км',
                    '10k' => '10 км',
                    '21.1k' => '21.1 км (полумарафон)',
                    '42.2k' => '42.2 км (марафон)'
                ];
                $distance = $distanceMap[$userData['race_distance']] ?? $userData['race_distance'];
                $prompt .= "Дистанция забега: {$distance}\n";
            }
            if (!empty($userData['race_date'])) {
                $prompt .= "Дата забега: {$userData['race_date']}\n";
            }
            if (!empty($userData['race_target_time'])) {
                $prompt .= "Целевое время: {$userData['race_target_time']}\n";
            }
            if (!empty($userData['is_first_race_at_distance'])) {
                $prompt .= "Это первый забег на эту дистанцию: " . ($userData['is_first_race_at_distance'] ? 'Да' : 'Нет') . "\n";
            }
            if (!empty($userData['last_race_time']) && !empty($userData['last_race_distance'])) {
                $prompt .= "Последний результат: {$userData['last_race_distance']} за {$userData['last_race_time']}\n";
            }
            
            // Расширенный профиль для race
            if (!empty($userData['running_experience'])) {
                $expMap = [
                    'less_3m' => 'Менее 3 месяцев',
                    '3_6m' => '3-6 месяцев',
                    '6_12m' => '6-12 месяцев',
                    '1_2y' => '1-2 года',
                    'more_2y' => 'Более 2 лет'
                ];
                $exp = $expMap[$userData['running_experience']] ?? $userData['running_experience'];
                $prompt .= "Стаж регулярного бега: {$exp}\n";
            }
            
            if (!empty($userData['easy_pace_sec'])) {
                $paceMin = floor($userData['easy_pace_sec'] / 60);
                $paceSec = $userData['easy_pace_sec'] % 60;
                $prompt .= "Комфортный темп: {$paceMin}:{$paceSec} /км\n";
            }
            
            if (!empty($userData['last_race_date'])) {
                $prompt .= "Дата последнего забега: {$userData['last_race_date']}\n";
            }
            
            if (!empty($userData['last_race_distance_km']) && $userData['last_race_distance'] === 'other') {
                $prompt .= "Последний забег: {$userData['last_race_distance_km']} км\n";
            }
            break;
            
        case 'weight_loss':
            $prompt .= "Цель: Снижение веса\n";
            if (!empty($userData['weight_goal_kg'])) {
                $currentWeight = $userData['weight_kg'] ?? 0;
                if ($currentWeight > 0) {
                    $diff = $currentWeight - $userData['weight_goal_kg'];
                    $prompt .= "Текущий вес: {$currentWeight} кг\n";
                    $prompt .= "Целевой вес: {$userData['weight_goal_kg']} кг (нужно сбросить {$diff} кг)\n";
                } else {
                    $prompt .= "Целевой вес: {$userData['weight_goal_kg']} кг\n";
                }
            }
            if (!empty($userData['weight_goal_date'])) {
                $prompt .= "К какой дате достичь цели: {$userData['weight_goal_date']}\n";
            }
            break;
            
        case 'time_improvement':
            $prompt .= "Цель: Улучшение времени на дистанции\n";
            if (!empty($userData['race_distance'])) {
                $distanceMap = [
                    '5k' => '5 км',
                    '10k' => '10 км',
                    '21.1k' => '21.1 км (полумарафон)',
                    '42.2k' => '42.2 км (марафон)'
                ];
                $distance = $distanceMap[$userData['race_distance']] ?? $userData['race_distance'];
                $prompt .= "Дистанция: {$distance}\n";
            }
            if (!empty($userData['race_target_time'])) {
                $prompt .= "Целевое время: {$userData['race_target_time']}\n";
            }
            if (!empty($userData['last_race_time'])) {
                $prompt .= "Текущее время: {$userData['last_race_time']}\n";
            }
            
            // Расширенный профиль для time_improvement
            if (!empty($userData['running_experience'])) {
                $expMap = [
                    'less_3m' => 'Менее 3 месяцев',
                    '3_6m' => '3-6 месяцев',
                    '6_12m' => '6-12 месяцев',
                    '1_2y' => '1-2 года',
                    'more_2y' => 'Более 2 лет'
                ];
                $exp = $expMap[$userData['running_experience']] ?? $userData['running_experience'];
                $prompt .= "Стаж регулярного бега: {$exp}\n";
            }
            
            if (!empty($userData['easy_pace_sec'])) {
                $paceMin = floor($userData['easy_pace_sec'] / 60);
                $paceSec = $userData['easy_pace_sec'] % 60;
                $prompt .= "Комфортный темп: {$paceMin}:{$paceSec} /км\n";
            }
            
            if (!empty($userData['last_race_date'])) {
                $prompt .= "Дата последнего забега: {$userData['last_race_date']}\n";
            }
            
            if (!empty($userData['last_race_distance_km']) && $userData['last_race_distance'] === 'other') {
                $prompt .= "Последний забег: {$userData['last_race_distance_km']} км\n";
            }
            break;
            
        case 'health':
        default:
            $prompt .= "Цель: Бег для здоровья и общего физического развития\n";
            if (!empty($userData['health_program'])) {
                $programMap = [
                    'start_running' => 'Начни бегать (8 недель)',
                    'couch_to_5k' => '5 км без остановки (10 недель)',
                    'regular_running' => 'Регулярный бег (12 недель)',
                    'custom' => 'Свой план'
                ];
                $program = $programMap[$userData['health_program']] ?? $userData['health_program'];
                $prompt .= "Программа: {$program}\n";
            }
            if (!empty($userData['current_running_level'])) {
                $levelMap = [
                    'zero' => 'Нет, начинаю с нуля',
                    'basic' => 'Да, но тяжело',
                    'comfortable' => 'Легко, могу больше'
                ];
                $level = $levelMap[$userData['current_running_level']] ?? $userData['current_running_level'];
                $prompt .= "Может пробежать 1 км без остановки: {$level}\n";
            }
            if (!empty($userData['health_plan_weeks'])) {
                $prompt .= "Срок плана: {$userData['health_plan_weeks']} недель\n";
            }
            break;
    }
    
    // Дата начала
    if (!empty($userData['training_start_date'])) {
        $prompt .= "\nДата начала тренировок: {$userData['training_start_date']}\n";
    }
    
    // Предпочтения
    $prompt .= "\n═══ ПРЕДПОЧТЕНИЯ ═══\n\n";
    
    if (!empty($userData['preferred_days']) && is_array($userData['preferred_days'])) {
        $dayLabels = [
            'mon' => 'Понедельник',
            'tue' => 'Вторник',
            'wed' => 'Среда',
            'thu' => 'Четверг',
            'fri' => 'Пятница',
            'sat' => 'Суббота',
            'sun' => 'Воскресенье'
        ];
        $days = array_map(function($day) use ($dayLabels) {
            return $dayLabels[$day] ?? $day;
        }, $userData['preferred_days']);
        $prompt .= "Предпочитаемые дни для бега: " . implode(', ', $days) . "\n";
    }
    
    if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
        $dayLabels = [
            'mon' => 'Понедельник',
            'tue' => 'Вторник',
            'wed' => 'Среда',
            'thu' => 'Четверг',
            'fri' => 'Пятница',
            'sat' => 'Суббота',
            'sun' => 'Воскресенье'
        ];
        $days = array_map(function($day) use ($dayLabels) {
            return $dayLabels[$day] ?? $day;
        }, $userData['preferred_ofp_days']);
        $prompt .= "Предпочитаемые дни для ОФП: " . implode(', ', $days) . "\n";
    }
    
    if (!empty($userData['training_time_pref'])) {
        $timeMap = [
            'morning' => 'Утро',
            'day' => 'День',
            'evening' => 'Вечер'
        ];
        $time = $timeMap[$userData['training_time_pref']] ?? $userData['training_time_pref'];
        $prompt .= "Предпочитаемое время тренировок: {$time}\n";
    }
    
    if (!empty($userData['has_treadmill'])) {
        $prompt .= "Есть доступ к беговой дорожке: Да\n";
    }
    
    if (!empty($userData['ofp_preference'])) {
        $ofpMap = [
            'gym' => 'В тренажерном зале',
            'home' => 'Дома самостоятельно',
            'both' => 'И в зале, и дома',
            'group_classes' => 'Групповые занятия',
            'online' => 'Онлайн-платформы'
        ];
        $ofp = $ofpMap[$userData['ofp_preference']] ?? $userData['ofp_preference'];
        $prompt .= "Где удобно делать ОФП: {$ofp}\n";
    }
    
    // Ограничения по здоровью
    if (!empty($userData['health_notes'])) {
        $prompt .= "\nОграничения по здоровью: {$userData['health_notes']}\n";
    }
    
    // Задача
    $prompt .= "\n═══ ЗАДАЧА ═══\n\n";
    $prompt .= "Создай детальный план тренировок на основе:\n";
    $prompt .= "1. Научных принципов из базы знаний (PlanRun AI найдет релевантные документы)\n";
    $prompt .= "2. Индивидуальных данных пользователя выше\n";
    $prompt .= "3. Лучших практик подготовки бегунов\n\n";
    
    $prompt .= "ТРЕБОВАНИЯ К ПЛАНУ:\n";
    $prompt .= "- План должен быть научно обоснован и учитывать принципы прогрессии\n";
    $prompt .= "- Учитывай текущий уровень подготовки пользователя\n";
    $prompt .= "- План должен быть реалистичным и достижимым\n";
    $prompt .= "- Включай разнообразие тренировок (легкий бег, интервалы, темповые, длинные)\n";
    $prompt .= "- Учитывай предпочтения по дням и времени\n";
    $prompt .= "- Включай дни отдыха и восстановления\n";
    
    if ($goalType === 'race' || $goalType === 'time_improvement') {
        $prompt .= "- План должен привести к пику формы к дате забега\n";
        $prompt .= "- Включай периодизацию (базовый период, интенсивный период, подводка)\n";
    }
    
    if ($goalType === 'weight_loss') {
        $prompt .= "- План должен способствовать сжиганию калорий\n";
        $prompt .= "- Учитывай безопасную скорость снижения веса (не более 1 кг в неделю)\n";
    }
    
    $prompt .= "\nФОРМАТ ОТВЕТА:\n";
    $prompt .= "Верни результат ТОЛЬКО в формате JSON, который соответствует структуре PlanRun:\n";
    $prompt .= "{\n";
    $prompt .= "  \"weeks\": [\n";
    $prompt .= "    {\n";
    $prompt .= "      \"week_number\": 1,\n";
    $prompt .= "      \"days\": [\n";
    $prompt .= "        {\n";
    $prompt .= "          \"date\": \"YYYY-MM-DD\",\n";
    $prompt .= "          \"type\": \"easy_run|interval|tempo|long_run|rest|ofp\",\n";
    $prompt .= "          \"distance_km\": число,\n";
    $prompt .= "          \"duration_minutes\": число,\n";
    $prompt .= "          \"description\": \"описание тренировки\",\n";
    $prompt .= "          \"pace\": \"темп (если применимо)\"\n";
    $prompt .= "        }\n";
    $prompt .= "      ]\n";
    $prompt .= "    }\n";
    $prompt .= "  ]\n";
    $prompt .= "}\n\n";
    
    $prompt .= "ВАЖНО: Используй принципы из базы знаний для создания научно обоснованного плана!\n";
    
    return $prompt;
}
