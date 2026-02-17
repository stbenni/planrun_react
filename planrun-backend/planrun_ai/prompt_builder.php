<?php
/**
 * Построитель промптов для генерации планов тренировок.
 * Промпт строится исключительно из данных формы (регистрация/специализация);
 * ИИ должен вернуть план, строго соответствующий этим данным.
 */

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
            'novice' => 'Начинающий (нет опыта)',
            'beginner' => 'Новичок (менее 6 месяцев бега)',
            'intermediate' => 'Любитель (6 месяцев - 2 года)',
            'advanced' => 'Опытный (более 2 лет)',
            'expert' => 'Эксперт (соревнующийся)'
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

    if (!empty($userData['device_type'])) {
        $prompt .= "Устройство/платформа: " . trim($userData['device_type']) . "\n";
    }

    // Принципы для выбранной цели (на основе лучших практик подготовки)
    $prompt .= "\n═══ ПРИНЦИПЫ И СТРУКТУРА ПЛАНА ДЛЯ ЭТОЙ ЦЕЛИ ═══\n\n";
    switch ($goalType) {
        case 'health':
            $program = $userData['health_program'] ?? '';
            $prompt .= "Цель: здоровье и регулярная активность.\n";
            if ($program === 'start_running') {
                $prompt .= "Программа «Начни бегать» (8 недель): 3 беговых дня в неделю с отдыхом между ними. Начинай с коротких интервалов бег/ходьба (1–2 мин бег, 1–2 мин ходьба), к концу плана — непрерывный бег 15–20 мин. Разминка 5 мин ходьбы перед каждой тренировкой. Не повышай объём резко.\n";
            } elseif ($program === 'couch_to_5k') {
                $prompt .= "Программа «5 км без остановки» (9–10 недель): классическая схема Couch to 5K. 3 тренировки в неделю, между ними — день отдыха. Недели 1–2: короткие интервалы бег/ходьба (1–1,5 мин бег, 1,5–2 мин ходьба), общее время 20–25 мин. Недели 3–4: бег 3–5 мин, ходьба 1,5–3 мин. К 5–6 неделе — длительный непрерывный бег (20 мин), к 9–10 — до 30 мин. Разминка 5 мин ходьбы. Описание в description: «Бег X мин, ходьба Y мин» или «Непрерывный бег Z мин».\n";
            } elseif ($program === 'regular_running') {
                $prompt .= "Программа «Регулярный бег» (12 недель): 3–4 лёгких пробежки в неделю по выбранным дням. Плавное увеличение длительности или дистанции (не более 10% в неделю). Одна из тренировок может быть чуть длиннее остальных. Темп разговорный (лёгкий). При наличии ОФП — в указанные пользователем дни.\n";
            } else {
                $prompt .= "Свой план (срок по полю «недель»): 3–4 беговых дня в неделю, прогрессия объёма плавная (до 10% прироста в неделю). Сочетай лёгкий бег и при желании одну длительную. Учитывай current_running_level: при «с нуля» — старт с бег/ходьба; при «тяжело» — короткие отрезки; при «легко» — можно сразу непрерывный бег с умеренным объёмом.\n";
            }
            break;
        case 'race':
            $prompt .= "Цель: подготовка к забегу на указанную дистанцию к дате старта.\n";
            $prompt .= "Периодизация: базовый период (аэробный объём, лёгкий бег + длительная) → интенсивный период (темповые, интервалы, работа на целевом темпе) → подводка (снижение объёма за 2–3 недели до забега, сохранение лёгкой интенсивности). Распределение интенсивности: около 80% объёма в лёгком темпе, до 20% — темповые и интервалы. В неделю: 1 длительная, 1–2 ключевые тренировки (темп/интервалы), остальное — лёгкий бег. Каждые 3–4 недели — разгрузочная неделя (снижение объёма на 15–25%). Не увеличивай недельный километраж более чем на 10% от предыдущей недели. Учитывай целевое время и комфортный темп пользователя при расчёте темпов в description.\n";
            break;
        case 'time_improvement':
            $prompt .= "Цель: улучшение результата на дистанции (текущее время → целевое).\n";
            $prompt .= "Та же структура, что и для подготовки к забегу: периодизация (база → интенсив → подводка к целевой дате). 80% лёгкий бег, до 20% темповые и интервалы. Длительная + 1–2 ключевые тренировки в неделю. Разгрузка каждые 3–4 недели. Учитывай текущее и целевое время: задавай темпы в интервалах и темповых так, чтобы они соответствовали целевой скорости на дистанции (например, интервалы чуть быстрее целевого темпа, длительная — лёгче). В description указывай темп в формате мин/км где уместно.\n";
            break;
        case 'weight_loss':
            $prompt .= "Цель: снижение веса к указанной дате.\n";
            $prompt .= "Безопасная скорость снижения веса — не более 1 кг в неделю. План должен сочетать: (1) лёгкий и длительный бег (сжигание жира, пульс/темп умеренные); (2) 1 интервальную или темповую тренировку в неделю для метаболизма. Если пользователь указал дни для ОФП — включи ОФП 2 раза в неделю в эти дни для сохранения мышечной массы; если дней для ОФП нет — только бег. 3–4 беговых дня в неделю, прогрессия объёма плавная (до 10% в неделю). Не завышай километраж резко. В description указывай «Лёгкий бег X км» или «Интервалы …» с темпом при необходимости.\n";
            break;
        default:
            $prompt .= "Общие принципы: прогрессия нагрузки, чередование нагрузки и отдыха, разнообразие (лёгкий бег, длительная, при необходимости темп/интервалы).\n";
    }

    $prompt .= "\nОбщие правила для любого плана: (1) В разгрузочную неделю (каждые 3–4 недели) снижай объём на 15–25%, убирай или упрощай ключевые тренировки. (2) Не более 10% прироста недельного километража от недели к неделе. (3) Между двумя тяжёлыми днями — минимум один день отдыха или лёгкого бега. (4) Большую часть километража — в лёгком, разговорном темпе (принцип 80/20).\n";

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
    $prompt .= "3. Даты в плане: первая неделя начинается с понедельника недели, на которую попадает дата начала тренировок. Каждый день в массиве days — с датой YYYY-MM-DD.\n";
    $prompt .= "4. В каждой неделе ровно 7 дней: порядок понедельник (индекс 0), вторник (1), …, воскресенье (6). День без тренировки — ТОЛЬКО type: \"rest\" И description пустой или строго \"Отдых\"/\"День отдыха\". НЕ указывай в description бег, км, темп, дистанцию для дней с type: rest; иначе день считается беговым.\n\n";

    // Задача
    $prompt .= "═══ ЗАДАЧА ═══\n\n";
    $prompt .= "Сформируй персональный план по данным пользователя и по блоку «ПРИНЦИПЫ И СТРУКТУРА ПЛАНА ДЛЯ ЭТОЙ ЦЕЛИ» выше. Учитывай предпочтения по дням, объём (weekly_base_km, sessions_per_week), уровень подготовки и ограничения по здоровью. В описаниях тренировок (description) указывай конкретику: дистанция, темп (мин/км), длительность, для интервалов — разминка, серия, отдых, заминка. Важно: день с type: rest — только отдых; у такого дня description пустой или \"Отдых\", distance_km и duration_minutes — null. Если в день запланирован бег — ставь type: easy/long/tempo/interval и заполняй description и distance_km.\n";

    $prompt .= "\n═══ ФОРМАТ ОТВЕТА (только этот JSON) ═══\n\n";
    $prompt .= "Верни один JSON-объект с ключом \"weeks\". В каждой неделе массив \"days\" из ровно 7 элементов (пн … вс).\n";
    $prompt .= "Тип дня (type): easy, long, tempo, interval, fartlek, rest, other (ОФП), sbu, race, free. Допустимы также easy_run, long_run, ofp (будут приведены к easy, long, other).\n";
    $prompt .= "Поля дня: date (YYYY-MM-DD), type, description (текст), distance_km (число или null), duration_minutes (число или null), pace (строка, опционально).\n";
    $prompt .= "Пример: день отдыха — {\"date\":\"...\",\"type\":\"rest\",\"description\":\"\",\"distance_km\":null,\"duration_minutes\":null}. День с бегом — {\"date\":\"...\",\"type\":\"easy\",\"description\":\"Лёгкий бег 8 км, 5:30/км\",\"distance_km\":8,\"duration_minutes\":44}. Не смешивай: type rest без бегового текста в description.\n\n";
    $prompt .= "ОФП (type: other) и СБУ (type: sbu) — description СТРОГО по формату «как в чате», по одному упражнению на строку:\n";
    $prompt .= "ОФП: каждая строка «Название — 3×10, 20 кг» или «Название — 1 мин». Разделитель — тире «—». Пример:\nПриседания — 3×10, 20 кг\nВыпады — 2×15\nПланка — 1 мин\n";
    $prompt .= "СБУ: каждая строка «Название — 30 м» или «Название — 50 м». Пример:\nБег с высоким подниманием бедра — 30 м\nЗахлёст голени — 50 м\n";
    $prompt .= "Не пиши ОФП/СБУ одним абзацем (типа «Силовые упражнения: приседания, выпады...»); только построчный формат выше.\n\n";
    $prompt .= "Не добавляй комментарии и текст вне JSON. Ответ должен начинаться с { и заканчиваться }.\n";

    return $prompt;
}
