<?php
/**
 * Построитель промптов для генерации планов тренировок.
 * Промпт строится исключительно из данных формы (регистрация/специализация);
 * ИИ должен вернуть план, строго соответствующий этим данным.
 */

/**
 * Вычисляет позицию дня забега (неделя + индекс 0-6) относительно start_date.
 * Возвращает ['week' => int, 'dayIndex' => int, 'dayName' => string] или null.
 */
function computeRaceDayPosition(?string $startDateStr, ?string $raceDateStr): ?array {
    if (!$startDateStr || !$raceDateStr) return null;
    try {
        $start = new DateTime($startDateStr);
        $race = new DateTime($raceDateStr);
    } catch (Exception $e) {
        return null;
    }
    if ($race <= $start) return null;

    $dow = (int) $start->format('N');
    $weekStart = clone $start;
    if ($dow > 1) {
        $weekStart->modify('-' . ($dow - 1) . ' days');
    }

    $diff = (int) $weekStart->diff($race)->days;
    $weekNum = (int) floor($diff / 7) + 1;
    $dayIndex = $diff % 7;

    $dayNames = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
    return [
        'week' => $weekNum,
        'dayIndex' => $dayIndex,
        'dayName' => $dayNames[$dayIndex] ?? '',
    ];
}

/**
 * Вычисляет рекомендуемое количество недель плана по цели и датам из формы.
 * @return int|null Количество недель или null, если нельзя вычислить
 */
function getSuggestedPlanWeeks($userData, $goalType) {
    $start = !empty($userData['training_start_date']) ? strtotime($userData['training_start_date']) : null;
    if (!$start) {
        return null;
    }
    if ($goalType === 'health') {
        if (!empty($userData['health_plan_weeks'])) {
            return (int) $userData['health_plan_weeks'];
        }
        if (!empty($userData['health_program'])) {
            $map = ['start_running' => 8, 'couch_to_5k' => 10, 'regular_running' => 12, 'custom' => 12];
            return $map[$userData['health_program']] ?? 12;
        }
        return 12;
    }
    if ($goalType === 'weight_loss' && !empty($userData['weight_goal_date'])) {
        $end = strtotime($userData['weight_goal_date']);
        if ($end > $start) {
            return (int) max(1, ceil(($end - $start) / (7 * 86400)));
        }
    }
    if (($goalType === 'race' || $goalType === 'time_improvement')) {
        $endStr = $userData['race_date'] ?? $userData['target_marathon_date'] ?? null;
        if ($endStr) {
            $end = strtotime($endStr);
            if ($end > $start) {
                return (int) max(1, ceil(($end - $start) / (7 * 86400)));
            }
        }
    }
    return null;
}

/**
 * Рассчитывает тренировочные темпы (зоны) на основе данных пользователя.
 * Приоритет: easy_pace_sec → race_target_time + race_distance → null.
 * Возвращает ассоц. массив с ключами easy, long, tempo, interval, recovery (в сек/км)
 * или null, если данных недостаточно.
 */
function calculatePaceZones($userData) {
    $easySec = null;

    if (!empty($userData['easy_pace_sec'])) {
        $easySec = (int) $userData['easy_pace_sec'];
    } elseif (!empty($userData['race_target_time']) && !empty($userData['race_distance'])) {
        $distKm = ['5k' => 5, '10k' => 10, 'half' => 21.1, '21.1k' => 21.1, 'marathon' => 42.195, '42.2k' => 42.195];
        $km = $distKm[$userData['race_distance']] ?? null;
        if ($km) {
            $parts = explode(':', $userData['race_target_time']);
            $totalSec = 0;
            if (count($parts) === 3) {
                $totalSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
            } elseif (count($parts) === 2) {
                $totalSec = (int)$parts[0] * 60 + (int)$parts[1];
            }
            if ($totalSec > 0) {
                $racePace = $totalSec / $km;
                $adders = ['5k' => 90, '10k' => 75, 'half' => 55, '21.1k' => 55, 'marathon' => 35, '42.2k' => 35];
                $easySec = (int) round($racePace + ($adders[$userData['race_distance']] ?? 60));
            }
        }
    }

    if (!$easySec || $easySec < 180 || $easySec > 600) {
        return null;
    }

    return [
        'easy'     => $easySec,
        'long'     => $easySec + 15,
        'tempo'    => $easySec - 45,
        'interval' => $easySec - 65,
        'recovery' => $easySec + 25,
    ];
}

/**
 * Форматирует секунды/км в строку «мин:сек».
 */
function formatPace($sec) {
    $m = (int) floor($sec / 60);
    $s = (int) ($sec % 60);
    return $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}

/**
 * Построение промпта для генерации плана тренировок.
 * Все данные берутся из формы пользователя; вывод плана должен им строго соответствовать.
 *
 * @param array $userData Данные пользователя (из БД, заполнены из формы)
 * @param string $goalType Тип цели (health, race, weight_loss, time_improvement)
 * @return string Промпт для LLM
 */
function buildTrainingPlanPrompt($userData, $goalType = 'health') {
    $prompt = "";

    // Роль и принципы (evidence-based)
    $prompt .= "Ты опытный тренер по бегу. Строй план по данным пользователя и научно обоснованным принципам: прогрессия нагрузки, восстановление, периодизация (где уместно), распределение интенсивности.\n";
    $prompt .= "Отвечай ТОЛЬКО валидным JSON без комментариев и лишнего текста. Все решения опирай на указанные ниже данные пользователя и на принципы для выбранной цели.\n\n";
    
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
            'novice' => 'Новичок (не бегает или менее 3 месяцев)',
            'beginner' => 'Начинающий (3-6 месяцев регулярного бега)',
            'intermediate' => 'Средний (6-12 месяцев регулярного бега)',
            'advanced' => 'Продвинутый (1-2 года регулярного бега)',
            'expert' => 'Опытный (более 2 лет регулярного бега)'
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
                    'half' => '21.1 км (полумарафон)',
                    'marathon' => '42.2 км (марафон)',
                    '21.1k' => '21.1 км (полумарафон)',
                    '42.2k' => '42.2 км (марафон)'
                ];
                $distance = $distanceMap[$userData['race_distance']] ?? $userData['race_distance'];
                $prompt .= "Дистанция забега: {$distance}\n";
            }
            if (!empty($userData['race_date'])) {
                $prompt .= "Дата забега: {$userData['race_date']}\n";
                $racePos = computeRaceDayPosition($userData['training_start_date'] ?? null, $userData['race_date']);
                if ($racePos) {
                    $prompt .= "ДЕНЬ ЗАБЕГА: неделя {$racePos['week']}, день индекс {$racePos['dayIndex']} ({$racePos['dayName']}). Поставь type: \"race\" именно на этот индекс.\n";
                }
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
                $paceMin = (int) floor($userData['easy_pace_sec'] / 60);
                $paceSec = (int) ($userData['easy_pace_sec'] % 60);
                $prompt .= "Комфортный темп: {$paceMin}:" . str_pad((string)$paceSec, 2, '0', STR_PAD_LEFT) . " /км\n";
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
                    'half' => '21.1 км (полумарафон)',
                    'marathon' => '42.2 км (марафон)',
                    '21.1k' => '21.1 км (полумарафон)',
                    '42.2k' => '42.2 км (марафон)'
                ];
                $distance = $distanceMap[$userData['race_distance']] ?? $userData['race_distance'];
                $prompt .= "Дистанция: {$distance}\n";
            }
            $targetTime = $userData['race_target_time'] ?? $userData['target_marathon_time'] ?? null;
            if (!empty($targetTime)) {
                $prompt .= "Целевое время: {$targetTime}\n";
            }
            $targetDate = $userData['race_date'] ?? $userData['target_marathon_date'] ?? null;
            if (!empty($targetDate)) {
                $prompt .= "Дата целевого забега: {$targetDate}\n";
                $racePos = computeRaceDayPosition($userData['training_start_date'] ?? null, $targetDate);
                if ($racePos) {
                    $prompt .= "ДЕНЬ ЗАБЕГА: неделя {$racePos['week']}, день индекс {$racePos['dayIndex']} ({$racePos['dayName']}). Поставь type: \"race\" именно на этот индекс.\n";
                }
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
                $paceMin = (int) floor($userData['easy_pace_sec'] / 60);
                $paceSec = (int) ($userData['easy_pace_sec'] % 60);
                $prompt .= "Комфортный темп: {$paceMin}:" . str_pad((string)$paceSec, 2, '0', STR_PAD_LEFT) . " /км\n";
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
    
    // Дата начала (критично для расчёта недель и дат в плане)
    $startDate = $userData['training_start_date'] ?? null;
    if ($startDate) {
        $prompt .= "\nДата начала тренировок: {$startDate}\n";
        $prompt .= "Первая неделя плана — та, в которую попадает эта дата (понедельник этой недели = начало недели 1).\n";
    }

    // Количество недель плана (из формы)
    $suggestedWeeks = getSuggestedPlanWeeks($userData, $goalType);
    if ($suggestedWeeks !== null) {
        $prompt .= "Количество недель плана: {$suggestedWeeks}. Сформируй ровно столько недель.\n";
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
    } else {
        $prompt .= "Пользователь не планирует делать ОФП (выбрал «нет»). В плане не должно быть тренировок типа ОФП (type: other).\n";
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

    // Тренировочные зоны (рассчитанные)
    $zones = calculatePaceZones($userData);
    if ($zones) {
        $prompt .= "\n═══ ТРЕНИРОВОЧНЫЕ ЗОНЫ (используй в полях pace / interval_pace) ═══\n\n";
        $prompt .= "Лёгкий бег (easy): " . formatPace($zones['easy']) . " /км — основной объём, разговорный темп, RPE 3-4\n";
        $prompt .= "Длительная (long): " . formatPace($zones['long']) . " /км — немного медленнее лёгкого, RPE 3-4\n";
        $prompt .= "Темповый (tempo): " . formatPace($zones['tempo']) . " /км — комфортно-тяжело, RPE 6-7\n";
        $prompt .= "Интервальный (interval): " . formatPace($zones['interval']) . " /км — тяжело, RPE 8-9, для отрезков 400м-2км\n";
        $prompt .= "Восстановительная трусца (между интервалами): " . formatPace($zones['recovery']) . " /км\n";
        $prompt .= "\nВАЖНО: Подставляй эти темпы в поля pace (для easy/long/tempo) и interval_pace (для interval). Не придумывай другие темпы.\n";
    }

    // Принципы для выбранной цели
    $prompt .= "\n═══ ПРИНЦИПЫ И СТРУКТУРА ПЛАНА ═══\n\n";

    // Определяем уровень для выбора стартового объёма
    $expLevel = $userData['experience_level'] ?? 'novice';
    $weeklyKm = !empty($userData['weekly_base_km']) ? (float) $userData['weekly_base_km'] : 0;
    $isNovice = in_array($expLevel, ['novice', 'beginner']);

    switch ($goalType) {
        case 'health':
            $program = $userData['health_program'] ?? '';
            $prompt .= "Цель: здоровье и регулярная активность.\n\n";
            if ($program === 'start_running') {
                $prompt .= "Программа «Начни бегать» (8 недель):\n";
                $prompt .= "- 3 беговых дня, между ними — отдых. Темп не указывать — только по ощущениям (RPE 3-4, можно разговаривать).\n";
                $prompt .= "- Недели 1-2: бег 1 мин / ходьба 2 мин, повторить 8 раз (24 мин).\n";
                $prompt .= "- Недели 3-4: бег 3 мин / ходьба 2 мин × 5 (25 мин).\n";
                $prompt .= "- Недели 5-6: бег 5 мин / ходьба 1 мин × 4 (24 мин).\n";
                $prompt .= "- Недели 7-8: непрерывный бег 15-20 мин.\n";
                $prompt .= "- Каждая тренировка начинается с 5 мин ходьбы (разминка). Тип: easy. distance_km: null. duration_minutes: суммарное время. notes: краткое описание интервалов бег/ходьба.\n";
            } elseif ($program === 'couch_to_5k') {
                $prompt .= "Программа «С дивана до 5 км» (10 недель):\n";
                $prompt .= "- 3 тренировки в неделю. Темп не указывать — только по ощущениям.\n";
                $prompt .= "- Недели 1-2: бег 1-1.5 мин / ходьба 2 мин, 8-10 повторов.\n";
                $prompt .= "- Недели 3-4: бег 3-5 мин / ходьба 1.5-3 мин.\n";
                $prompt .= "- Недели 5-6: непрерывный бег 20 мин.\n";
                $prompt .= "- Недели 7-8: бег 25 мин.\n";
                $prompt .= "- Недели 9-10: бег 30 мин (≈5 км).\n";
                $prompt .= "- Тип: easy. distance_km: null. duration_minutes: суммарное время (обязательно!). notes: краткое описание интервалов бег/ходьба.\n";
            } elseif ($program === 'regular_running') {
                $prompt .= "Программа «Регулярный бег» (12 недель):\n";
                $prompt .= "- 3-4 лёгких пробежки в неделю. Одна чуть длиннее (длительная).\n";
                $prompt .= "- Старт: " . ($weeklyKm > 0 ? "{$weeklyKm} км/нед" : "10-15 км/нед") . ", прирост до 10% в неделю.\n";
                $prompt .= "- Все пробежки в лёгком темпе (разговорный, RPE 3-4).\n";
                $prompt .= "- Неделя 4, 8 — разгрузочные (объём -20%).\n";
            } else {
                $prompt .= "Свой план:\n";
                $prompt .= "- 3-4 беговых дня, прогрессия плавная (до 10%/нед).\n";
                $runLevel = $userData['current_running_level'] ?? '';
                if ($runLevel === 'zero') {
                    $prompt .= "- Начинающий с нуля: старт с чередования бег/ходьба, как «Начни бегать».\n";
                } elseif ($runLevel === 'basic') {
                    $prompt .= "- Бегает с трудом: короткие отрезки 2-3 км, акцент на регулярность, не на объём.\n";
                } else {
                    $prompt .= "- Бегает комфортно: непрерывный бег, можно добавить одну длительную.\n";
                }
            }
            if ($isNovice) {
                $prompt .= "\nДля начинающих: НЕ указывай темп в мин/км — только по ощущениям. Писать «в комфортном темпе» или «темп разговорный».\n";
            }
            break;

        case 'race':
        case 'time_improvement':
            $goalLabel = $goalType === 'race' ? 'подготовка к забегу' : 'улучшение результата';
            $prompt .= "Цель: {$goalLabel}.\n\n";

            // Макроцикл
            $totalWeeks = getSuggestedPlanWeeks($userData, $goalType);
            if ($totalWeeks && $totalWeeks >= 8) {
                $taper = min(3, max(2, (int) round($totalWeeks * 0.12)));
                $intense = min(6, max(3, (int) round($totalWeeks * 0.35)));
                $base = $totalWeeks - $taper - $intense;
                $prompt .= "МАКРОЦИКЛ ({$totalWeeks} недель):\n";
                $prompt .= "- Базовый период (недели 1-{$base}): аэробный объём. Лёгкий бег + 1 длительная. Без темповых/интервалов. Прирост объёма до 10%/нед. Каждые 3-4 недели — разгрузка.\n";
                $intStart = $base + 1;
                $intEnd = $base + $intense;
                $prompt .= "- Интенсивный период (недели {$intStart}-{$intEnd}): добавляются 1-2 ключевые тренировки (темп/интервалы). Длительная остаётся. Объём стабилен или слегка растёт.\n";
                $tapStart = $intEnd + 1;
                $prompt .= "- Подводка (недели {$tapStart}-{$totalWeeks}): объём снижается на 40-60% от пикового. Интенсивность сохраняется (короткие быстрые отрезки), но объём низкий. Последняя неделя — совсем лёгкая.\n\n";
            } elseif ($totalWeeks && $totalWeeks >= 4) {
                $prompt .= "МАКРОЦИКЛ ({$totalWeeks} недель, короткий):\n";
                $prompt .= "- Недели 1-" . ($totalWeeks - 2) . ": постепенный ввод ключевых тренировок, прирост объёма.\n";
                $prompt .= "- Последние 1-2 недели: подводка (снижение объёма, сохранение коротких быстрых отрезков).\n\n";
            }

            // Дистанционно-специфичные шаблоны
            $dist = $userData['race_distance'] ?? '';
            $prompt .= "СТРУКТУРА ТРЕНИРОВОЧНОЙ НЕДЕЛИ:\n";
            $prompt .= "- 80% объёма в лёгком темпе, до 20% — ключевые тренировки.\n";
            $prompt .= "- Между двумя ключевыми тренировками — минимум 1 день лёгкого бега или отдыха.\n\n";

            if ($dist === '5k') {
                $prompt .= "ТРЕНИРОВКИ ДЛЯ 5 КМ:\n";
                $prompt .= "- Интервалы: 6-10 × 400 м (отдых трусцой 200 м) или 4-6 × 800 м (отдых 400 м трусцой) или 3-5 × 1000 м.\n";
                $prompt .= "- Темповый: 2-4 км непрерывного бега в темповом темпе.\n";
                $prompt .= "- Длительная: 8-12 км в лёгком темпе.\n";
                $prompt .= "- Фартлек: 30-40 мин с ускорениями 1-2 мин через 2-3 мин трусцой.\n";
            } elseif ($dist === '10k') {
                $prompt .= "ТРЕНИРОВКИ ДЛЯ 10 КМ:\n";
                $prompt .= "- Интервалы: 5-8 × 1000 м (отдых 400 м трусцой) или 3-5 × 2000 м (отдых 600-800 м).\n";
                $prompt .= "- Темповый: 4-6 км в темповом темпе.\n";
                $prompt .= "- Длительная: 12-16 км в лёгком темпе.\n";
                $prompt .= "- Фартлек: 40-50 мин с ускорениями 2-3 мин через 2-3 мин трусцой.\n";
            } elseif (in_array($dist, ['half', '21.1k'])) {
                $prompt .= "ТРЕНИРОВКИ ДЛЯ ПОЛУМАРАФОНА:\n";
                $prompt .= "- Интервалы: 4-6 × 1600-2000 м (отдых 600 м трусцой).\n";
                $prompt .= "- Темповый: 6-10 км в темповом темпе.\n";
                $prompt .= "- Длительная: 16-22 км в лёгком темпе. Последние 3-5 км можно в целевом темпе (прогрессивная длительная).\n";
                $prompt .= "- Длительная с вкраплениями: 18-20 км, из них 3 × 2 км в целевом темпе с 1 км лёгкого между.\n";
            } elseif (in_array($dist, ['marathon', '42.2k'])) {
                $prompt .= "ТРЕНИРОВКИ ДЛЯ МАРАФОНА:\n";
                $prompt .= "- Интервалы: 4-6 × 1600-2000 м (отдых 800 м трусцой).\n";
                $prompt .= "- Темповый: 8-12 км в темповом темпе.\n";
                $prompt .= "- Длительная: 22-32 км в лёгком/марафонском темпе. Пиковая длительная — за 3-4 недели до старта, не более 35 км.\n";
                $prompt .= "- Марафонский темп: 10-16 км в целевом марафонском темпе (вставки внутри длительной или отдельная тренировка).\n";
            } else {
                $prompt .= "ОБЩИЕ ТРЕНИРОВКИ:\n";
                $prompt .= "- Интервалы: 4-8 × 800-1600 м с трусцой между отрезками.\n";
                $prompt .= "- Темповый: 3-8 км непрерывного бега.\n";
                $prompt .= "- Длительная: на 50-70% больше средней дневной дистанции.\n";
            }

            $prompt .= "\nКАЖДАЯ КЛЮЧЕВАЯ ТРЕНИРОВКА (темп, интервалы, фартлек) включает разминку (1.5-2 км) и заминку (1-1.5 км).\n";
            if ($zones) {
                $prompt .= "Используй рассчитанные зоны: interval_pace=\"" . formatPace($zones['interval']) . "\", темповый pace=\"" . formatPace($zones['tempo']) . "\".\n";
            }
            $prompt .= "distance_km для интервалов/фартлека считает код — не заполняй.\n\n";

            // Контрольные забеги
            $prompt .= "КОНТРОЛЬНЫЕ ЗАБЕГИ (type: \"control\"):\n";
            $prompt .= "Контрольный забег — это тест-забег на дистанцию короче целевой, выполняемый на максимальное усилие для замера прогресса.\n";
            $prompt .= "ЗАЧЕМ: объективно отследить улучшение формы, скорректировать тренировочные темпы, потренировать волевой бег.\n";
            $prompt .= "КОГДА СТАВИТЬ:\n";
            $prompt .= "- Каждые 3-4 недели, в конце мезоцикла (перед разгрузочной неделей).\n";
            $prompt .= "- НЕ ставить в первые 2-3 недели плана (нет базы для сравнения).\n";
            $prompt .= "- НЕ ставить в последние 2 недели перед забегом (подводка).\n";
            $prompt .= "- День перед контрольной — отдых или очень лёгкий бег. День после — тоже лёгкий.\n";

            if (in_array($dist, ['5k'])) {
                $prompt .= "ДИСТАНЦИЯ КОНТРОЛЬНОЙ ДЛЯ 5 КМ: 1-2 км (бег на результат) или 3 км.\n";
            } elseif (in_array($dist, ['10k'])) {
                $prompt .= "ДИСТАНЦИЯ КОНТРОЛЬНОЙ ДЛЯ 10 КМ: 3 км или 5 км (бег на результат).\n";
            } elseif (in_array($dist, ['half', '21.1k'])) {
                $prompt .= "ДИСТАНЦИЯ КОНТРОЛЬНОЙ ДЛЯ ПОЛУМАРАФОНА: 5-10 км (бег на результат).\n";
            } elseif (in_array($dist, ['marathon', '42.2k'])) {
                $prompt .= "ДИСТАНЦИЯ КОНТРОЛЬНОЙ ДЛЯ МАРАФОНА: 10-15 км в целевом марафонском темпе, или 10 км на результат.\n";
            } else {
                $prompt .= "ДИСТАНЦИЯ КОНТРОЛЬНОЙ: 30-50% от целевой дистанции, бег на результат.\n";
            }
            $prompt .= "is_key_workout для контрольных забегов: ВСЕГДА true.\n";
            $prompt .= "pace для контрольного забега: null (бегун бежит на результат, темп не назначается).\n\n";

            if ($isNovice) {
                $prompt .= "Уровень НОВИЧОК: интервалы вводить не ранее недели 4-5 (базовый период). Начинать с 3-4 × 400 м, постепенно увеличивая.\n";
                $prompt .= "Контрольные забеги для новичка: не ранее недели 4, дистанция 1-2 км.\n";
            }
            break;

        case 'weight_loss':
            $prompt .= "Цель: снижение веса к указанной дате.\n\n";
            $prompt .= "ПРИНЦИПЫ:\n";
            $prompt .= "- 3-4 беговых дня в неделю. Весь бег — в лёгком темпе (жиросжигание в аэробной зоне).\n";
            $prompt .= "- 1 длительная пробежка в неделю (30-60 мин) — основной инструмент.\n";
            $prompt .= "- 1 тренировка с ускорениями (фартлек или короткие интервалы) для метаболизма, но не обязательно в первые 2-3 недели.\n";
            $prompt .= "- Прирост объёма: до 10%/нед. Старт с текущего объёма" . ($weeklyKm > 0 ? " ({$weeklyKm} км/нед)" : "") . ".\n";
            if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
                $prompt .= "- ОФП 2 раза в неделю (в указанные дни): круговые тренировки, акцент на крупные мышечные группы для сохранения мышечной массы.\n";
            }
            $prompt .= "- Безопасная скорость: не более 0.5-1 кг/нед. Питание — вне плана, но тренировки оптимизированы для дефицита калорий.\n";
            if ($isNovice) {
                $prompt .= "- Начинающий: старт с бег/ходьба, акцент на регулярность и длительность (время), а не скорость.\n";
            }
            break;

        default:
            $prompt .= "Общие принципы: прогрессия нагрузки, чередование нагрузки и отдыха, разнообразие (лёгкий бег, длительная, при необходимости темп/интервалы).\n";
    }

    $prompt .= "\nРАЗГРУЗОЧНАЯ НЕДЕЛЯ (каждые 3-4 недели):\n";
    $prompt .= "- Объём снижен на 20-30% от предыдущей недели.\n";
    $prompt .= "- Все тренировки — лёгкий бег. Убрать интервалы и темповые. Длительную сократить на 30%.\n";
    $prompt .= "- Если есть ОФП — оставить, но облегчить (меньше подходов).\n\n";

    $prompt .= "ОБЩИЕ ПРАВИЛА:\n";
    $prompt .= "- Прирост недельного км: не более 10%.\n";
    $prompt .= "- 80% объёма — лёгкий бег, до 20% — ключевые тренировки (принцип 80/20).\n";
    $prompt .= "- Длительная — в конце недели (суббота/воскресенье), если пользователь выбрал эти дни.\n";

    $prompt .= "\n═══ КЛЮЧЕВЫЕ ТРЕНИРОВКИ (is_key_workout) ═══\n\n";
    $prompt .= "Ключевая тренировка — та, которая даёт основной тренировочный стимул в неделе. Именно эти тренировки продвигают бегуна к цели, всё остальное (лёгкий бег, отдых) — поддержка восстановления между ними.\n\n";

    $prompt .= "ТИПЫ КЛЮЧЕВЫХ ТРЕНИРОВОК:\n";
    $prompt .= "- Темповый бег (tempo) — развивает лактатный порог, учит тело работать на высокой интенсивности дольше.\n";
    $prompt .= "- Интервалы (interval) — развивают МПК (VO2max), скорость и экономичность бега.\n";
    $prompt .= "- Фартлек (fartlek) — развивает умение переключать темп, скоростную выносливость. Структурированный фартлек — ключевая, лёгкий игровой — нет.\n";
    $prompt .= "- Длительная (long) — развивает аэробную базу, жировой обмен, ментальную выносливость. ЭТО ТОЖЕ КЛЮЧЕВАЯ ТРЕНИРОВКА.\n";
    $prompt .= "- Забег (race) — пиковая нагрузка, всегда ключевая.\n\n";

    $prompt .= "НЕ ЯВЛЯЮТСЯ КЛЮЧЕВЫМИ:\n";
    $prompt .= "- Лёгкий бег (easy) — восстановительный бег.\n";
    $prompt .= "- ОФП (other), СБУ (sbu) — вспомогательные.\n";
    $prompt .= "- Отдых (rest).\n\n";

    $prompt .= "ПРАВИЛА РАССТАНОВКИ:\n";
    $sessions = (int)($userData['sessions_per_week'] ?? 3);
    if ($sessions <= 3) {
        $prompt .= "- При {$sessions} тренировках в неделю: 1-2 ключевые (длительная + 1 интенсивная в интенсивном периоде).\n";
    } elseif ($sessions <= 5) {
        $prompt .= "- При {$sessions} тренировках в неделю: 2-3 ключевые (длительная + 1-2 интенсивные).\n";
    } else {
        $prompt .= "- При {$sessions} тренировках в неделю: 2-3 ключевые (длительная + 1-2 интенсивные), остальное — лёгкий бег.\n";
    }
    $prompt .= "- Между двумя ключевыми — минимум 1 день лёгкого бега или отдыха. НИКОГДА две ключевые подряд.\n";
    $prompt .= "- В разгрузочную неделю — 0-1 ключевая (только сокращённая длительная), убрать интенсивность.\n";
    $prompt .= "- В базовый период — только длительная как ключевая, без темпа/интервалов.\n";
    $prompt .= "- В подводку — сохранять 1-2 короткие интенсивные, но со сниженным объёмом.\n\n";

    $prompt .= "Для каждого дня ставь поле \"is_key_workout\": true/false. Это важно для визуального выделения в приложении.\n";

    // Обязательные правила: план строго по форме
    $prompt .= "\n═══ ОБЯЗАТЕЛЬНЫЕ ПРАВИЛА (соблюдай строго) ═══\n\n";
    $prompt .= "1. Расписание по дням:\n";
    if (!empty($userData['preferred_days']) && is_array($userData['preferred_days'])) {
        $daysList = implode(', ', $userData['preferred_days']);
        $prompt .= "   — Беговые тренировки ставить ТОЛЬКО в эти дни недели: {$daysList}. В остальные дни — отдых (rest)" . (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days']) ? " или ОФП в указанные дни" : "") . ".\n";
    } else {
        $prompt .= "   — Количество беговых дней в неделю: " . ($userData['sessions_per_week'] ?? 3) . ". Остальные дни — rest" . (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days']) ? " или ОФП по предпочтениям" : "") . ".\n";
    }
    if (!empty($userData['preferred_ofp_days']) && is_array($userData['preferred_ofp_days'])) {
        $ofpList = implode(', ', $userData['preferred_ofp_days']);
        $prompt .= "   — ОФП (type: other) ставить ТОЛЬКО в эти дни: {$ofpList}.\n";
    } else {
        $prompt .= "   — ОФП в плане не включать (пользователь выбрал «не делать ОФП»). Дни без бега — только type: rest.\n";
    }
    $prompt .= "2. Объём и сложность — по уровню подготовки и weekly_base_km выше; не завышай нагрузку.\n";
    $prompt .= "3. Даты НЕ нужны — код вычислит их автоматически из start_date и номера недели.\n";
    $prompt .= "4. В каждой неделе ровно 7 дней: порядок понедельник (индекс 0), вторник (1), …, воскресенье (6). День без тренировки — type: \"rest\", все поля null.\n\n";

    // Задача
    $prompt .= "═══ ЗАДАЧА ═══\n\n";
    $prompt .= "Сформируй персональный план по данным пользователя и по блоку «ПРИНЦИПЫ И СТРУКТУРА ПЛАНА» выше. Учитывай предпочтения по дням, объём (weekly_base_km, sessions_per_week), уровень подготовки и ограничения по здоровью.\n";
    $prompt .= "Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.) — все поля в каждом дне, неиспользуемые = null.\n";
    $prompt .= "НЕ генерируй поле \"description\" — код построит его автоматически. НЕ считай distance_km и duration_minutes для интервалов/фартлеков — код посчитает.\n\n";

    $prompt .= "═══ ФОРМАТ ОТВЕТА (только этот JSON) ═══\n\n";
    $prompt .= "Верни один JSON-объект с ключом \"weeks\". В каждой неделе массив \"days\" из ровно 7 элементов (пн … вс).\n";
    $prompt .= "Тип дня (type): easy, long, tempo, interval, fartlek, control, rest, other (ОФП), sbu, race, free.\n\n";

    $prompt .= "КРИТИЧНО: Каждый день — объект со ВСЕМИ полями ниже. Неиспользуемые = null. Пропуск полей запрещён!\n\n";

    $prompt .= "ПОЛНЫЙ ШАБЛОН ДНЯ (все поля):\n";
    $prompt .= "{\n";
    $prompt .= "  \"type\": \"...\",\n";
    $prompt .= "  \"distance_km\": null,\n";
    $prompt .= "  \"pace\": null,\n";
    $prompt .= "  \"warmup_km\": null,\n";
    $prompt .= "  \"cooldown_km\": null,\n";
    $prompt .= "  \"reps\": null,\n";
    $prompt .= "  \"interval_m\": null,\n";
    $prompt .= "  \"interval_pace\": null,\n";
    $prompt .= "  \"rest_m\": null,\n";
    $prompt .= "  \"rest_type\": null,\n";
    $prompt .= "  \"segments\": null,\n";
    $prompt .= "  \"exercises\": null,\n";
    $prompt .= "  \"duration_minutes\": null,\n";
    $prompt .= "  \"notes\": null,\n";
    $prompt .= "  \"is_key_workout\": false\n";
    $prompt .= "}\n\n";

    $prompt .= "ПРИМЕРЫ ПО ТИПАМ (все поля всегда присутствуют):\n\n";

    $prompt .= "1) Лёгкий бег (easy):\n";
    $prompt .= "{\"type\":\"easy\",\"distance_km\":8,\"pace\":\"6:00\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $prompt .= "2) Длительная (long):\n";
    $prompt .= "{\"type\":\"long\",\"distance_km\":15,\"pace\":\"6:30\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $prompt .= "3) Темповый (tempo):\n";
    $prompt .= "{\"type\":\"tempo\",\"distance_km\":6,\"pace\":\"5:00\",\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $prompt .= "4) Контрольный забег (control) — pace всегда null:\n";
    $prompt .= "{\"type\":\"control\",\"distance_km\":3,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $prompt .= "5) Забег (race):\n";
    $prompt .= "{\"type\":\"race\",\"distance_km\":21.1,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $prompt .= "6) Интервалы (interval):\n";
    $prompt .= "{\"type\":\"interval\",\"distance_km\":null,\"pace\":null,\"warmup_km\":2,\"cooldown_km\":1.5,\"reps\":5,\"interval_m\":1000,\"interval_pace\":\"4:20\",\"rest_m\":400,\"rest_type\":\"jog\",\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $prompt .= "7) Фартлек (fartlek):\n";
    $prompt .= "{\"type\":\"fartlek\",\"distance_km\":null,\"pace\":null,\"warmup_km\":2,\"cooldown_km\":1.5,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":[{\"reps\":6,\"distance_m\":200,\"pace\":\"4:30\",\"recovery_m\":200,\"recovery_type\":\"jog\"}],\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":true}\n\n";

    $prompt .= "8) ОФП (other):\n";
    $prompt .= "{\"type\":\"other\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":[{\"name\":\"Приседания\",\"sets\":3,\"reps\":10,\"weight_kg\":20,\"distance_m\":null,\"duration_min\":null},{\"name\":\"Планка\",\"sets\":null,\"reps\":null,\"weight_kg\":null,\"distance_m\":null,\"duration_min\":1}],\"duration_minutes\":30,\"notes\":null,\"is_key_workout\":false}\n\n";

    $prompt .= "9) СБУ (sbu):\n";
    $prompt .= "{\"type\":\"sbu\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":[{\"name\":\"Бег с высоким подниманием бедра\",\"sets\":null,\"reps\":null,\"weight_kg\":null,\"distance_m\":30,\"duration_min\":null}],\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $prompt .= "10) Отдых (rest):\n";
    $prompt .= "{\"type\":\"rest\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $prompt .= "11) Свободный день (free):\n";
    $prompt .= "{\"type\":\"free\",\"distance_km\":null,\"pace\":null,\"warmup_km\":null,\"cooldown_km\":null,\"reps\":null,\"interval_m\":null,\"interval_pace\":null,\"rest_m\":null,\"rest_type\":null,\"segments\":null,\"exercises\":null,\"duration_minutes\":null,\"notes\":null,\"is_key_workout\":false}\n\n";

    $prompt .= "ПРАВИЛА:\n";
    $prompt .= "- НЕ генерируй поле \"description\" — код построит его автоматически из структурированных полей.\n";
    $prompt .= "- distance_km для interval и fartlek: null (код посчитает из warmup + reps × distance + cooldown).\n";
    $prompt .= "- duration_minutes для interval и fartlek: null (код посчитает).\n";
    $prompt .= "- Дата НЕ нужна — рассчитается автоматически.\n";
    $prompt .= "- Не добавляй комментарии и текст вне JSON. Ответ должен начинаться с { и заканчиваться }.\n";

    return $prompt;
}

/**
 * Промпт для ПЕРЕСЧЁТА плана — учитывает историю тренировок, detraining, текущую форму.
 * Внутри вызывает buildTrainingPlanPrompt() и дополняет контекстом пересчёта.
 *
 * @param array $userData Данные пользователя
 * @param string $goalType Тип цели
 * @param array $recalcContext Контекст пересчёта (history, compliance, detraining и т.д.)
 * @return string Промпт для LLM
 */
function buildRecalculationPrompt($userData, $goalType, array $recalcContext) {
    $origStartDate = $userData['training_start_date'] ?? null;
    $newStartDate = $recalcContext['new_start_date'] ?? date('Y-m-d', strtotime('monday this week'));
    $keptWeeks = $recalcContext['kept_weeks'] ?? 0;
    $weeksToGenerate = $recalcContext['weeks_to_generate'] ?? null;

    $modifiedUser = $userData;
    $modifiedUser['training_start_date'] = $newStartDate;
    if ($weeksToGenerate !== null) {
        $modifiedUser['health_plan_weeks'] = $weeksToGenerate;
    }

    $basePrompt = buildTrainingPlanPrompt($modifiedUser, $goalType);

    $recalcBlock = buildRecalcContextBlock($recalcContext, $origStartDate);

    $taskMarker = "═══ ЗАДАЧА ═══";
    $pos = strpos($basePrompt, $taskMarker);
    if ($pos === false) {
        return $basePrompt . "\n\n" . $recalcBlock;
    }

    $beforeTask = substr($basePrompt, 0, $pos);
    $fromTask = substr($basePrompt, $pos);

    $newTask = "═══ ЗАДАЧА: ПЕРЕСЧЁТ ПЛАНА ═══\n\n";
    $newTask .= "Это КОРРЕКЦИЯ существующего плана, а не генерация с нуля.\n";
    $newTask .= "Первые {$keptWeeks} недель плана СОХРАНЕНЫ — ты генерируешь только ПРОДОЛЖЕНИЕ.\n";
    if ($weeksToGenerate !== null) {
        $newTask .= "Сгенерируй ровно {$weeksToGenerate} недель (нумерация week_number от 1 до {$weeksToGenerate}).\n";
    }
    $newTask .= "Дата начала первой генерируемой недели: {$newStartDate}.\n\n";
    $newTask .= "Учитывай ТЕКУЩЕЕ СОСТОЯНИЕ из блока выше: реальные объёмы, темпы, самочувствие.\n\n";
    $newTask .= "ПРИНЦИПЫ ПЕРЕСЧЁТА:\n";
    $newTask .= "1. Первые 1-2 недели — плавный возврат к нагрузке:\n";
    $newTask .= "   - Начальный объём = средний реальный объём за последние 4 недели × коэффициент формы.\n";
    $newTask .= "   - Первая неделя — только лёгкий бег (easy) и длительная (сокращённая).\n";
    $newTask .= "   - Вторая неделя — можно вернуть 1 ключевую тренировку (облегчённую).\n";
    $newTask .= "2. Далее — стандартная прогрессия (до +10%/нед), возвращение к обычной структуре.\n";
    $newTask .= "3. Если до забега мало времени — сжать фазы, но НЕ форсировать нагрузку.\n";
    $newTask .= "4. Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.).\n";
    $newTask .= "5. НЕ генерируй \"description\" — код построит автоматически.\n\n";

    $formatPos = strpos($fromTask, "═══ ФОРМАТ ОТВЕТА");
    if ($formatPos !== false) {
        $formatSection = substr($fromTask, $formatPos);
    } else {
        $formatSection = '';
    }

    return $beforeTask . $recalcBlock . "\n\n" . $newTask . $formatSection;
}

/**
 * Формирует текстовый блок с контекстом пересчёта для вставки в промпт.
 */
function buildRecalcContextBlock(array $ctx, ?string $origStartDate): string {
    $lines = ["═══ ТЕКУЩЕЕ СОСТОЯНИЕ (для пересчёта) ═══\n"];
    $lines[] = "Это пересчёт существующего плана. Пользователь уже тренировался, ниже — реальные данные.\n";

    $daysSince = $ctx['days_since_last_workout'] ?? null;
    if ($daysSince !== null) {
        $lines[] = "Дней с последней тренировки: {$daysSince}";
    }

    $factor = $ctx['detraining_factor'] ?? null;
    if ($factor !== null) {
        $pct = (int) round($factor * 100);
        $lines[] = "Оценка текущей формы: ~{$pct}% от пиковой";
        if ($pct >= 95) {
            $lines[] = "→ Пауза минимальная, можно продолжать почти с того же объёма.";
        } elseif ($pct >= 85) {
            $lines[] = "→ Лёгкое снижение формы. Первая неделя — на 10-15% ниже последнего реального объёма, потом возврат.";
        } elseif ($pct >= 75) {
            $lines[] = "→ Заметная потеря формы. Первые 2 недели — плавный возврат, начать с 70-80% от последнего реального объёма.";
        } else {
            $lines[] = "→ Серьёзная потеря формы. Нужен мягкий старт: первые 2-3 недели как базовый период, лёгкий бег, постепенное наращивание.";
        }
    }

    $compliance = $ctx['compliance_2w'] ?? null;
    if ($compliance) {
        $lines[] = "\nВыполнение плана за 2 недели: {$compliance['completed']}/{$compliance['planned']} (" . ($compliance['pct'] ?? 0) . "%)";
    }

    $avgKm = $ctx['avg_weekly_km_4w'] ?? null;
    if ($avgKm !== null && $avgKm > 0) {
        $lines[] = "Средний реальный объём за 4 недели: {$avgKm} км/нед (НАЧИНАЙ ПЕРВУЮ НЕДЕЛЮ ОТ ЭТОГО ЗНАЧЕНИЯ × коэффициент формы, не от weekly_base_km из профиля)";
    } elseif ($avgKm !== null) {
        $lines[] = "За последние 4 недели тренировок НЕ БЫЛО. Используй weekly_base_km из профиля как ориентир, но начни с 50-60% от этого объёма.";
    }

    $avgPace = $ctx['avg_pace_4w'] ?? null;
    if ($avgPace !== null) {
        $lines[] = "Средний реальный темп за 4 недели: {$avgPace} /км";
    }

    $avgHr = $ctx['avg_hr_4w'] ?? null;
    if ($avgHr !== null && $avgHr > 0) {
        $lines[] = "Средний пульс за 4 недели: {$avgHr} уд/мин";
    }

    $avgRating = $ctx['avg_rating_4w'] ?? null;
    if ($avgRating !== null) {
        $ratingLabel = $avgRating >= 4 ? 'хорошо' : ($avgRating >= 3 ? 'нормально' : 'тяжело');
        $lines[] = "Среднее самочувствие: {$avgRating}/5 ({$ratingLabel})";
    }

    $keptWeeks = $ctx['kept_weeks'] ?? 0;
    $weeksToGen = $ctx['weeks_to_generate'] ?? null;
    if ($keptWeeks > 0 || $weeksToGen !== null) {
        $lines[] = "\nПлан: первые {$keptWeeks} нед. сохранены, генерировать {$weeksToGen} новых.";
    }

    $weeksRemaining = $ctx['weeks_remaining_to_goal'] ?? null;
    if ($weeksRemaining !== null) {
        $lines[] = "Недель до цели (забега/дедлайна): {$weeksRemaining}";
        if ($weeksRemaining <= 4) {
            $lines[] = "⚠ МАЛО ВРЕМЕНИ — сжатый план, без форсирования. Приоритет: поддержание формы, подводка.";
        } elseif ($weeksRemaining <= 8) {
            $lines[] = "Умеренный запас времени — можно наверстать, но аккуратно.";
        }
    }

    $recent = $ctx['recent_workouts'] ?? [];
    if (!empty($recent)) {
        $lines[] = "\nПоследние тренировки (факт):";
        $shown = 0;
        foreach ($recent as $w) {
            if ($shown >= 8) break;
            $date = $w['date'] ?? '';
            $type = $w['plan_type'] ?? 'тренировка';
            $dist = !empty($w['distance_km']) ? "{$w['distance_km']} км" : '';
            $pace = !empty($w['pace']) && $w['pace'] !== '0:00' ? "темп {$w['pace']}" : '';
            $rating = !empty($w['rating']) ? "ощущение {$w['rating']}/5" : '';
            $parts = array_filter([$date, $type, $dist, $pace, $rating]);
            $lines[] = "  - " . implode(', ', $parts);
            $shown++;
        }
    }

    $userReason = $ctx['user_reason'] ?? null;
    if ($userReason !== null && $userReason !== '') {
        $lines[] = "\n═══ ПРИЧИНА ПЕРЕСЧЁТА (от пользователя) ═══\n";
        $lines[] = $userReason;
        $lines[] = "\nУЧТИ ЭТУ ПРИЧИНУ при составлении плана. Если пользователь упоминает травму — исключи нагрузку на проблемную зону, снизь интенсивность, добавь восстановительные тренировки. Если устал — начни мягче. Если хочет больше — можно прибавить, но не более +10%/нед.\n";
    }

    if ($origStartDate) {
        $lines[] = "\nОригинальная дата старта плана: {$origStartDate}";
    }
    $lines[] = "Новая дата старта плана: {$ctx['new_start_date']}";
    $lines[] = "Первая неделя нового плана — от этого понедельника. Нумерация недель начинается с 1.\n";

    return implode("\n", $lines);
}

/**
 * Промпт для генерации НОВОГО плана после завершения предыдущего.
 * Берёт базовый промпт из buildTrainingPlanPrompt() и дополняет его
 * полной историей достижений из предыдущего плана.
 *
 * @param array  $userData       Данные пользователя (training_start_date = новый старт)
 * @param string $goalType       Тип цели
 * @param array  $nextPlanContext Контекст с историей предыдущего плана
 * @return string Промпт для LLM
 */
function buildNextPlanPrompt($userData, $goalType, array $nextPlanContext) {
    $newStartDate = $nextPlanContext['new_start_date'] ?? date('Y-m-d', strtotime('monday this week'));
    $newPlanWeeks = $nextPlanContext['new_plan_weeks'] ?? 12;

    $modifiedUser = $userData;
    $modifiedUser['training_start_date'] = $newStartDate;
    $modifiedUser['health_plan_weeks'] = $newPlanWeeks;

    $basePrompt = buildTrainingPlanPrompt($modifiedUser, $goalType);

    $historyBlock = buildPreviousPlanHistoryBlock($nextPlanContext);

    $taskMarker = "═══ ЗАДАЧА ═══";
    $pos = strpos($basePrompt, $taskMarker);
    if ($pos === false) {
        return $basePrompt . "\n\n" . $historyBlock;
    }

    $beforeTask = substr($basePrompt, 0, $pos);
    $fromTask = substr($basePrompt, $pos);

    $newTask = "═══ ЗАДАЧА: НОВЫЙ ПЛАН (ПРОДОЛЖЕНИЕ ТРЕНИРОВОЧНОГО ЦИКЛА) ═══\n\n";
    $newTask .= "Пользователь ЗАВЕРШИЛ предыдущий план и хочет новый. Это НЕ план для новичка — у пользователя есть серьёзная тренировочная база.\n\n";
    $newTask .= "Сгенерируй ровно {$newPlanWeeks} недель (нумерация week_number от 1 до {$newPlanWeeks}).\n";
    $newTask .= "Дата начала первой недели: {$newStartDate}.\n\n";
    $newTask .= "ПРИНЦИПЫ НОВОГО ПЛАНА:\n";
    $newTask .= "1. СТАРТОВЫЙ ОБЪЁМ = средний объём за последние 4 недели предыдущего плана (см. блок ИСТОРИЯ).\n";
    $newTask .= "   НЕ начинай с нуля и НЕ начинай с weekly_base_km из профиля — начинай с РЕАЛЬНОГО текущего уровня.\n";
    $newTask .= "2. Первая неделя — разгрузочная (recovery): 80-85% от последнего реального объёма.\n";
    $newTask .= "   Это переходный микроцикл между планами.\n";
    $newTask .= "3. Со 2-й недели — стандартная прогрессия (+5-10% в неделю), с разгрузочными неделями каждую 4-ю.\n";
    $newTask .= "4. Если пиковый объём предыдущего плана > weekly_base_km, обнови ориентир на пиковый.\n";
    $newTask .= "5. Учти лучшие результаты ключевых тренировок для выбора темпов.\n";
    $newTask .= "6. Выдавай ТОЛЬКО структурированные поля (type, distance_km, pace и т.д.).\n";
    $newTask .= "7. НЕ генерируй \"description\" — код построит автоматически.\n\n";

    $formatPos = strpos($fromTask, "═══ ФОРМАТ ОТВЕТА");
    if ($formatPos !== false) {
        $formatSection = substr($fromTask, $formatPos);
    } else {
        $formatSection = '';
    }

    return $beforeTask . $historyBlock . "\n\n" . $newTask . $formatSection;
}

/**
 * Формирует текстовый блок с полной историей предыдущего плана.
 */
function buildPreviousPlanHistoryBlock(array $ctx): string {
    $lines = ["═══ ИСТОРИЯ ПРЕДЫДУЩЕГО ПЛАНА ═══\n"];
    $lines[] = "Пользователь завершил предыдущий тренировочный план. Ниже — его реальные достижения.\n";

    $oldStart = $ctx['old_plan_start'] ?? null;
    $oldWeeks = $ctx['old_plan_weeks'] ?? 0;
    if ($oldStart) {
        $lines[] = "Предыдущий план: старт {$oldStart}, длительность {$oldWeeks} нед.";
    }

    $totalWorkouts = $ctx['total_workouts'] ?? 0;
    $totalKm = $ctx['total_distance_km'] ?? 0;
    $lines[] = "Выполнено тренировок: {$totalWorkouts}";
    if ($totalKm > 0) {
        $lines[] = "Суммарный набег: {$totalKm} км";
    }

    $avgKm = $ctx['avg_weekly_km'] ?? 0;
    $peakKm = $ctx['peak_weekly_km'] ?? 0;
    if ($avgKm > 0) {
        $lines[] = "\nСредний недельный объём: {$avgKm} км";
    }
    if ($peakKm > 0) {
        $lines[] = "Пиковый недельный объём: {$peakKm} км";
    }

    $firstQ = $ctx['first_quarter_avg_km'] ?? 0;
    $lastQ = $ctx['last_quarter_avg_km'] ?? 0;
    if ($firstQ > 0 && $lastQ > 0) {
        $growth = $lastQ > $firstQ ? round(($lastQ - $firstQ) / $firstQ * 100) : 0;
        $lines[] = "Прогрессия объёмов: начало плана ~{$firstQ} км/нед → конец ~{$lastQ} км/нед";
        if ($growth > 0) {
            $lines[] = "  Рост за план: +{$growth}%";
        }
    }

    $bestLong = $ctx['best_long_run_km'] ?? 0;
    $bestTempo = $ctx['best_tempo_pace'] ?? null;
    $bestInterval = $ctx['best_interval_pace'] ?? null;
    $avgPace = $ctx['avg_pace'] ?? null;

    if ($bestLong > 0 || $bestTempo || $bestInterval || $avgPace) {
        $lines[] = "\nЛучшие показатели за план:";
        if ($bestLong > 0) {
            $lines[] = "  Длительный бег (макс): {$bestLong} км";
        }
        if ($bestTempo) {
            $lines[] = "  Темповый бег (лучший темп): {$bestTempo} /км";
        }
        if ($bestInterval) {
            $lines[] = "  Интервалы (лучший темп): {$bestInterval} /км";
        }
        if ($avgPace) {
            $lines[] = "  Средний темп за план: {$avgPace} /км";
        }
    }

    $avgHr = $ctx['avg_hr'] ?? null;
    if ($avgHr) {
        $lines[] = "  Средний пульс: {$avgHr} уд/мин";
    }

    $avgRating = $ctx['avg_rating'] ?? null;
    if ($avgRating !== null) {
        $ratingLabel = $avgRating >= 4 ? 'хорошо' : ($avgRating >= 3 ? 'нормально' : 'тяжело');
        $lines[] = "  Среднее самочувствие: {$avgRating}/5 ({$ratingLabel})";
    }

    $compliance = $ctx['compliance'] ?? null;
    if ($compliance && $compliance['planned'] > 0) {
        $lines[] = "\nВыполнение плана (последние 2 нед.): {$compliance['completed']}/{$compliance['planned']} ({$compliance['pct']}%)";
    }

    $keyResults = $ctx['key_workout_results'] ?? [];
    if (!empty($keyResults)) {
        $lines[] = "\nКлючевые тренировки (факт):";
        foreach ($keyResults as $kw) {
            $parts = array_filter([
                $kw['date'],
                $kw['type'],
                !empty($kw['distance_km']) ? "{$kw['distance_km']} км" : '',
                !empty($kw['pace']) ? "темп {$kw['pace']}" : '',
                !empty($kw['rating']) ? "ощущение {$kw['rating']}/5" : '',
            ]);
            $lines[] = "  - " . implode(', ', $parts);
        }
    }

    $recent4wAvgKm = $ctx['recent_4w_avg_km'] ?? 0;
    $recent4wAvgPace = $ctx['recent_4w_avg_pace'] ?? null;
    if ($recent4wAvgKm > 0) {
        $lines[] = "\n═══ ТЕКУЩАЯ ФОРМА (последние 4 недели) ═══";
        $lines[] = "Средний объём: {$recent4wAvgKm} км/нед";
        if ($recent4wAvgPace) {
            $lines[] = "Средний темп: {$recent4wAvgPace} /км";
        }
        $lines[] = "ПЕРВАЯ НЕДЕЛЯ НОВОГО ПЛАНА ДОЛЖНА СТАРТОВАТЬ ОТ ЭТОГО ОБЪЁМА (с лёгким снижением ~15% для recovery).";
    }

    $recent = $ctx['recent_workouts'] ?? [];
    if (!empty($recent)) {
        $lines[] = "\nПоследние тренировки:";
        $shown = 0;
        foreach ($recent as $w) {
            if ($shown >= 8) break;
            $date = $w['date'] ?? '';
            $type = $w['plan_type'] ?? 'тренировка';
            $dist = !empty($w['distance_km']) ? "{$w['distance_km']} км" : '';
            $pace = !empty($w['pace']) && $w['pace'] !== '0:00' ? "темп {$w['pace']}" : '';
            $rating = !empty($w['rating']) ? "ощущение {$w['rating']}/5" : '';
            $parts = array_filter([$date, $type, $dist, $pace, $rating]);
            $lines[] = "  - " . implode(', ', $parts);
            $shown++;
        }
    }

    $userGoals = $ctx['user_goals'] ?? null;
    if ($userGoals !== null && $userGoals !== '') {
        $lines[] = "\n═══ ПОЖЕЛАНИЯ ПОЛЬЗОВАТЕЛЯ К НОВОМУ ПЛАНУ ═══\n";
        $lines[] = $userGoals;
        $lines[] = "\nУЧТИ ЭТИ ПОЖЕЛАНИЯ при построении нового плана.\n";
    }

    $lines[] = "\nДата начала нового плана: {$ctx['new_start_date']}";
    $lines[] = "Количество недель: {$ctx['new_plan_weeks']}";
    $lines[] = "Нумерация недель начинается с 1.\n";

    return implode("\n", $lines);
}

/**
 * Рассчитывает коэффициент detraining на основе дней с последней тренировки.
 */
function calculateDetrainingFactor(int $daysSince): float {
    if ($daysSince <= 3) return 1.0;
    if ($daysSince <= 7) return 0.95;
    if ($daysSince <= 14) return 0.85;
    if ($daysSince <= 21) return 0.75;
    return max(0.5, 1.0 - $daysSince * 0.015);
}
