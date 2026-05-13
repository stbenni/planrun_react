<?php
/**
 * Step 3 of stepped plan generation: enrich беговой план ОФП/СБУ сессиями.
 *
 * Получает revised беговой план + профиль атлета + библиотеку упражнений → LLM-вызов
 * возвращает JSON {ofp_sessions: {YYYY-MM-DD: [exercises]}}. Каждая сессия персонализирована
 * под нагрузку этой недели (recovery / build / peak).
 *
 * Если LLM упадёт — caller использует force-inject fallback (template из WorkoutBuilderService).
 */

require_once __DIR__ . '/../services/LlmGateway.php';

function enrichPlanWithOfp(array $planData, array $user, array $exerciseLibrary, int $userId): ?array {
    if ((int) env('OFP_ENRICHER_ENABLED', 1) !== 1) return null;
    if (empty($planData['weeks'])) return null;

    $ofpPref = (string) ($user['ofp_preference'] ?? '');
    if ($ofpPref === '' || $ofpPref === 'none') return null;

    $rawDays = $user['preferred_ofp_days'] ?? null;
    $ofpDays = is_string($rawDays) ? json_decode($rawDays, true) : (is_array($rawDays) ? $rawDays : null);
    if (!is_array($ofpDays) || empty($ofpDays)) return null;

    $dayMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    $ofpDowIndex = [];
    foreach ($ofpDays as $d) {
        $key = strtolower((string) $d);
        if (isset($dayMap[$key])) $ofpDowIndex[$dayMap[$key]] = true;
    }
    if (empty($ofpDowIndex)) return null;

    // Собираем target-даты для ОФП и контекст недели для каждой
    $targetDates = [];
    foreach ($planData['weeks'] as $week) {
        if (!is_array($week['days'] ?? null)) continue;
        $weekLoad = ofpEnricherSummariseWeekLoad((array) $week['days']);
        foreach ($week['days'] as $day) {
            $date = (string) ($day['date'] ?? '');
            if ($date === '') continue;
            $dow = (int) date('N', strtotime($date));
            if (!isset($ofpDowIndex[$dow])) continue;
            $currentType = (string) ($day['type'] ?? '');
            // Обрабатываем rest/free И other/sbu без exercises (когда planner поставил type
            // но не заполнил упражнения).
            $isEmptyOfp = in_array($currentType, ['other', 'sbu'], true) && empty($day['exercises']);
            if (!in_array($currentType, ['rest', 'free'], true) && !$isEmptyOfp) continue;
            $targetDates[$date] = [
                'date' => $date,
                'dow' => $dow,
                'week_summary' => $weekLoad,
            ];
        }
    }
    if (empty($targetDates)) return null;

    $baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
    $model = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');
    if ($baseUrl === '' || $model === '') return null;

    $exerciseList = [];
    foreach ($exerciseLibrary as $ex) {
        $exerciseList[] = [
            'id' => (int) $ex['id'],
            'name' => $ex['name'],
            'category' => $ex['category'],
            'default_sets' => $ex['default_sets'] !== null ? (int) $ex['default_sets'] : null,
            'default_reps' => $ex['default_reps'] !== null ? (int) $ex['default_reps'] : null,
            'default_distance_m' => $ex['default_distance_m'] !== null ? (int) $ex['default_distance_m'] : null,
            'default_duration_sec' => $ex['default_duration_sec'] !== null ? (int) $ex['default_duration_sec'] : null,
        ];
    }

    $userBlock = ofpEnricherUserBlock($user);
    $targetsBlock = json_encode(array_values($targetDates), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $libraryBlock = json_encode($exerciseList, JSON_UNESCAPED_UNICODE);

    // История выполнения для AI — реальные рабочие веса атлета.
    $historyBlock = "";
    try {
        require_once __DIR__ . '/../services/ExecutedExerciseService.php';
        $execSvc = new ExecutedExerciseService($GLOBALS['db'] ?? getDBConnection());
        $history = $execSvc->getRecentHistoryForUser($userId, 8);
        if (!empty($history)) {
            $historyBlock = "\n\nФАКТИЧЕСКИЕ ВЕСА АТЛЕТА (executed_exercises за 8 нед.):\n";
            foreach ($history as $h) {
                $line = "  - " . $h['exercise_name'] . " ({$h['category']}): max " . round((float) $h['max_weight'], 1) . " кг";
                $line .= ", выполнено {$h['times']} раз, последняя {$h['last_date']}";
                $historyBlock .= $line . "\n";
            }
            $historyBlock .= "При подборе весов учитывай эти числа — атлет уже работает с ними. Лёгкий progressive overload (+2.5 кг) допустим если >= 2 недели прошло.";
        }
    } catch (Throwable $e) {
        // ignore
    }

    $systemPrompt = <<<TXT
Ты — опытный тренер по силовой подготовке бегунов, готовишь марафонцев.
Тебе дан беговой план атлета и target-даты для ОФП. Подбери сессии исходя из контекста.

═══ КОНТЕКСТ КАЖДОГО ДНЯ ═══
В targets для каждой даты есть week_summary с описанием нагрузки недели:
- "RACE-WEEK" — главный старт в эту неделю
- "high load (peak)" — пиковая беговая нагрузка
- "medium load (build)" — стандартная build-неделя
- "low load (recovery/cutback)" — разгрузочная неделя

Также видно структуру нескольких target-дат — если они идут подряд, это серия ОФП-дней
одной недели, реши сам как распределить нагрузку между ними (тяжёлая+лёгкая,
или две средних, или одна тяжёлая + восстановительная).

═══ COACHING ПРИНЦИПЫ (ориентиры, не жёсткие правила) ═══
- ОФП — поддерживающая работа в марафонской подготовке, не отдельная цель.
- Когда беговая нагрузка велика — силовая обычно отступает (поддержание, не прогресс).
- В неделю с главным стартом heavy lower-body обычно убирают (10+ дней до race —
  последняя серьёзная strength), но neuromuscular sharpness можно сохранить лёгким.
- В recovery / cutback недели есть пространство для прогрессии (heavier compounds).
- После race атлету нужно несколько дней без strength для восстановления.
- При БОЛЬ / fatigue в feedback — упрости (core + bodyweight, без compounds).
- Сам решай состав и интенсивность сессии под нагрузку. Варьируй упражнения между
  днями недели (не повторяй одну сессию подряд).

═══ ТЕХНИЧЕСКИЕ ТРЕБОВАНИЯ ═══
- Упражнения ТОЛЬКО из exercise_library (по name, exact match).
- Для бегуна обычно полезны: calf-упражнение (подъёмы на носки), core (планка/пресс).
- 4-7 упражнений в сессии, 25-45 минут.
- weight_kg для упражнений со штангой/гантелями/блоками. Источники в порядке приоритета:
  1) "ФАКТИЧЕСКИЕ ВЕСА АТЛЕТА" — реальные рабочие веса (можешь +2.5 кг если ≥2 нед).
  2) bodyweight × коэффициент (приседания 0.9, жим лежа 0.75, тяга 0.6, жим гантелей 0.18).
  Округляй до 2.5 кг. В race/taper неделях — снижай.

ФОРМАТ ОТВЕТА — СТРОГО JSON, без markdown / комментариев:
{
  "ofp_sessions": {
    "YYYY-MM-DD": [
      {"exercise_id": 8, "name": "Планка", "sets": 3, "reps": null, "duration_sec": 60, "weight_kg": null, "notes": "коротко — для контекста и атлета"}
    ]
  }
}
TXT;

    $userContent = "АТЛЕТ:\n{$userBlock}{$historyBlock}\n\nTARGET ДАТЫ И КОНТЕКСТ:\n{$targetsBlock}\n\nEXERCISE LIBRARY:\n{$libraryBlock}";

    $payload = LlmGateway::withThinkingMode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ],
        'stream' => false,
        'temperature' => 0.4,
        'max_tokens' => max(2000, min(12000, (int) env('OFP_ENRICHER_MAX_TOKENS', 6000))),
        'response_format' => ['type' => 'json_object'],
    ], $baseUrl, false);

    try {
        $db = function_exists('getDBConnection') ? getDBConnection() : null;
        $response = LlmGateway::requestChatCompletion($baseUrl, $payload, [
            'feature' => 'OFP enricher',
            'purpose' => 'chat',
            'db' => $db,
            'surface' => 'ofp_enricher',
            'event_type' => 'llm_request',
            'user_id' => $userId,
            'timeout' => 90,
            'connect_timeout' => 5,
            'max_attempts' => 1,
        ]);
    } catch (Throwable $e) {
        error_log("ofp_enricher: LLM error: " . $e->getMessage());
        return null;
    }

    $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    if ($content === '') return null;

    require_once __DIR__ . '/plan_critique_generator.php';
    $parsed = function_exists('repairAndParseCritiqueJson') ? repairAndParseCritiqueJson($content) : json_decode($content, true);
    if (!is_array($parsed)) {
        error_log("ofp_enricher: not JSON, preview: " . mb_substr($content, 0, 300));
        return null;
    }

    $sessions = is_array($parsed['ofp_sessions'] ?? null) ? $parsed['ofp_sessions'] : null;
    if (empty($sessions)) return null;

    // Validate: каждое exercise.name должно совпадать с library
    $libByName = [];
    foreach ($exerciseLibrary as $ex) {
        $libByName[mb_strtolower($ex['name'])] = $ex;
    }

    $validated = [];
    foreach ($sessions as $date => $exercises) {
        if (!is_array($exercises)) continue;
        $cleanExercises = [];
        $order = 0;
        foreach ($exercises as $ex) {
            if (!is_array($ex)) continue;
            $name = (string) ($ex['name'] ?? '');
            $libEx = $libByName[mb_strtolower($name)] ?? null;
            if ($libEx === null) continue; // skip hallucinated exercises
            // Если LLM не указал weight_kg — берём библиотечный default.
            // (LLM часто пропускает поле; чтобы UI не показывал «—», заполняем сами.)
            $weight = isset($ex['weight_kg']) && $ex['weight_kg'] !== null
                ? (float) $ex['weight_kg']
                : ($libEx['default_weight_kg'] !== null ? (float) $libEx['default_weight_kg'] : null);

            $cleanExercises[] = [
                'category' => (string) $libEx['category'],
                'exercise_id' => (int) $libEx['id'],
                'name' => $libEx['name'],
                'sets' => isset($ex['sets']) ? (int) $ex['sets'] : ($libEx['default_sets'] ? (int) $libEx['default_sets'] : null),
                'reps' => isset($ex['reps']) && $ex['reps'] !== null ? (int) $ex['reps'] : ($libEx['default_reps'] ? (int) $libEx['default_reps'] : null),
                'distance_m' => isset($ex['distance_m']) && $ex['distance_m'] !== null ? (int) $ex['distance_m'] : ($libEx['default_distance_m'] ? (int) $libEx['default_distance_m'] : null),
                'duration_sec' => isset($ex['duration_sec']) && $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : ($libEx['default_duration_sec'] ? (int) $libEx['default_duration_sec'] : null),
                'weight_kg' => $weight,
                'pace' => null,
                'notes' => isset($ex['notes']) ? (string) $ex['notes'] : null,
                'order_index' => $order++,
            ];
        }
        if (!empty($cleanExercises)) {
            $validated[(string) $date] = $cleanExercises;
        }
    }

    if (empty($validated)) return null;
    return $validated;
}

function ofpEnricherSummariseWeekLoad(array $days): string {
    $longCount = 0;
    $longKm = 0;
    $qualityCount = 0;
    $totalKm = 0.0;
    $hasRaceThisWeek = false;
    $maxRaceKm = 0;
    foreach ($days as $d) {
        $t = (string) ($d['type'] ?? '');
        $km = (float) ($d['distance_km'] ?? 0);
        if ($t === 'long') { $longCount++; $longKm = max($longKm, $km); }
        elseif (in_array($t, ['tempo', 'interval', 'fartlek', 'control'], true)) $qualityCount++;
        elseif ($t === 'race') { $hasRaceThisWeek = true; $maxRaceKm = max($maxRaceKm, $km); $qualityCount++; }
        if ($km > 0) $totalKm += $km;
    }
    $totalKm = round($totalKm, 1);

    if ($hasRaceThisWeek) {
        if ($maxRaceKm >= 35) return "RACE-WEEK (marathon {$maxRaceKm}км) — НИКАКИХ heavy strength";
        return "RACE-WEEK (race {$maxRaceKm}км) — только лёгкое поддержание";
    }
    if ($qualityCount >= 2 && $longCount >= 1 && $longKm >= 28) {
        return "high load (peak): {$totalKm}км, {$qualityCount} quality + long {$longKm}км — урезай ОФП на ноги";
    }
    if ($qualityCount >= 1 || $longCount >= 1) {
        return "medium load (build): {$totalKm}км, {$qualityCount} quality, long={$longKm}км — стандартная нагрузка";
    }
    return "low load (recovery/cutback): {$totalKm}км — можно прогрессивную нагрузку";
}

function ofpEnricherUserBlock(array $user): string {
    $parts = [];
    if (!empty($user['username'])) $parts[] = "username: {$user['username']}";
    if (!empty($user['experience_level'])) $parts[] = "level: {$user['experience_level']}";
    if (!empty($user['weight_kg'])) $parts[] = "bodyweight: " . (float) $user['weight_kg'] . " кг";
    if (!empty($user['height_cm'])) $parts[] = "height: " . (int) $user['height_cm'] . " см";
    if (!empty($user['birth_year'])) $parts[] = "age: ~" . (date('Y') - (int) $user['birth_year']);
    if (!empty($user['ofp_preference'])) $parts[] = "ofp_location: {$user['ofp_preference']}";
    if (!empty($user['race_distance'])) $parts[] = "target: {$user['race_distance']}";
    if (!empty($user['health_notes'])) $parts[] = "health: " . mb_substr((string) $user['health_notes'], 0, 100);
    return implode("\n", $parts);
}
