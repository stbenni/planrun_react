<?php
/**
 * Self-critique pass для сгенерированных тренировочных планов.
 *
 * Flow:
 *   1. План сгенерирован первым LLM-вызовом (recalculate/generate/next).
 *   2. runPlanSelfCritique() — независимый LLM-вызов как «opposing coach», ищет проблемы
 *      с точки зрения тренировочной науки.
 *   3. Если severity = critical / moderate с should_revise = true →
 *      revisePlanWithCritique() — LLM-revision с конкретным feedback.
 *   4. Результат критики сохраняется (для UI и observability).
 *
 * Бюджет: 1-2 дополнительных LLM-вызова. Управляется флагами в env:
 *   PLAN_CRITIQUE_ENABLED=1
 *   PLAN_CRITIQUE_MAX_REVISIONS=1
 */

require_once __DIR__ . '/../services/LlmGateway.php';

/**
 * Запускает critique-pass на сгенерированном плане.
 * Возвращает структуру:
 *   {
 *     severity: 'critical|moderate|minor|none',
 *     should_revise: bool,
 *     issues: [{severity, title, week, description, suggested_fix}, ...],
 *     strengths: [string, ...],
 *     summary: string
 *   }
 * При ошибке вернёт null.
 */
function runPlanSelfCritique(array $planData, array $user, array $context, int $userId): ?array {
    if ((int) env('PLAN_CRITIQUE_ENABLED', 1) !== 1) {
        return null;
    }

    $baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
    $model = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');
    if ($baseUrl === '' || $model === '') return null;

    require_once __DIR__ . '/plan_review_generator.php';
    $startDate = (string) ($context['new_start_date'] ?? $context['start_date'] ?? date('Y-m-d'));
    $planSummary = buildPlanSummaryForReview($planData, $startDate);
    if (mb_strlen($planSummary) > 8000) {
        $planSummary = mb_substr($planSummary, 0, 8000) . "\n... (обрезка)";
    }

    $userBlock = buildAthleteBlockForCritique($user, $context);
    $historyBlock = buildHistoryBlockForCritique($context);

    $systemPrompt = <<<TXT
Ты — независимый опытный тренер по бегу (15+ лет coaching, бегаешь сам).
Твоя задача: критически проанализировать сгенерированный план тренировок и найти
КОНКРЕТНЫЕ проблемы, опираясь на тренировочную науку (Daniels, Pfitzinger, Hudson)
и контекст атлета.

ПРИНЦИПЫ ДЛЯ ПРОВЕРКИ (используй как ориентир, не как жёсткие константы):
- Easy дни ≈ 60-75% maxHR, обычно 8-15 км; >18 км в "easy" формате — это уже medium-long
- Прогрессия объёма ≤ +15%/нед (до +25% после recovery-недели или return from pause)

ПОДГОТОВКА К МАРАФОНУ (если главный старт = marathon) — КАНОН:
- Build phase: 3-4 длинных подряд по 26-32 км. Минимум 2 из них с MP-блоком 8-16 км.
- Peak длинная 30-34 км (75-80% от race distance) за 2-3 недели до главного старта.
- Между длинными — точечно, не уменьшать. Если W13 long=24 → W14 long=28 → W15 long=32, не делай W14=14.
- Таперинг длинных: 32 → 26 → 18-22, монотонно по убыванию. Не вставляй длинную короче предыдущей в середине build.
- После promejutochnoj race (HM или ниже): recovery 3-5 дней, затем СРАЗУ длинная 20-24 км.
- Recovery после long: следующий день ≤8 км easy или rest (но не серия rest).

ОБЩИЕ:
- Quality density: 1-2 quality-дня в неделю, между ними ≥48ч
- Race-week: 3-4 короткие пробежки 3-8 km + race. ЗАПРЕЩЕНО 4+ rest подряд перед марафоном.
- Taper: сохраняй short quality для neuromuscular sharpness (strides, короткий MP).
- Цель должна соответствовать VDOT/истории (3:15 от 3:43 за 9 нед нереалистично — корректируй)
- Учитывай compliance в истории — если атлет регулярно превращает easy в tempo, режь easy
- Если в истории есть БОЛЬ / fatigue flag → план должен явно учитывать это

ВАЖНО:
- Привязывайся к КОНКРЕТНЫМ неделям и дням плана (W14 Wed, W15 Sun, …)
- Цифры — из факта плана, не выдумывай
- Severity: critical = критический риск (травма/перетренировка/неготовность к старту);
  moderate = снижает качество подготовки на 10-25%; minor = мелочи стиля
- should_revise = true только при наличии critical ИЛИ нескольких moderate
- Если план хорош — пиши severity = "none" и список strengths

ОТВЕТ — СТРОГО JSON без markdown и без комментариев:
{
  "severity": "critical|moderate|minor|none",
  "should_revise": true|false,
  "summary": "1-2 предложения общей оценки",
  "issues": [
    {
      "severity": "critical|moderate|minor",
      "title": "Краткое название (5-10 слов)",
      "week": "W14" or "W14-W15" or null,
      "description": "Конкретные цифры и почему это проблема",
      "suggested_fix": "Что предлагаешь — конкретно"
    }
  ],
  "strengths": ["короткий список сильных сторон плана"]
}
TXT;

    $userContent = "{$userBlock}\n\n{$historyBlock}\n\nПЛАН ПОД РЕВЬЮ (нач. {$startDate}):\n{$planSummary}";

    $critiqueMaxTokens = max(1500, min(8000, (int) env('PLAN_CRITIQUE_MAX_TOKENS', 4000)));

    $payload = LlmGateway::withThinkingMode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ],
        'stream' => false,
        'temperature' => 0.2,
        'max_tokens' => $critiqueMaxTokens,
        'response_format' => ['type' => 'json_object'],
    ], $baseUrl, false);

    try {
        $db = function_exists('getDBConnection') ? getDBConnection() : null;
        $response = LlmGateway::requestChatCompletion($baseUrl, $payload, [
            'feature' => 'Plan self-critique',
            'purpose' => 'chat',
            'db' => $db,
            'surface' => 'plan_critique',
            'event_type' => 'llm_request',
            'user_id' => $userId,
            'timeout' => max(15, min(180, (int) env('PLAN_CRITIQUE_TIMEOUT_SECONDS', 90))),
            'connect_timeout' => 5,
            'max_attempts' => 1,
        ]);
    } catch (Throwable $e) {
        error_log("plan_critique: LLM error: " . $e->getMessage());
        return null;
    }

    $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    $finishReason = (string) ($response['choices'][0]['finish_reason'] ?? '');
    if ($content === '') return null;

    error_log("plan_critique: response length=" . strlen($content) . ", finish_reason={$finishReason}, max_tokens={$critiqueMaxTokens}");

    $parsed = repairAndParseCritiqueJson($content);
    if (!is_array($parsed)) {
        error_log("plan_critique: failed to parse JSON, length=" . strlen($content) . ", preview=" . mb_substr($content, 0, 500));
        return null;
    }

    // Нормализация структуры
    $parsed['severity'] = (string) ($parsed['severity'] ?? 'none');
    $parsed['should_revise'] = (bool) ($parsed['should_revise'] ?? false);
    $parsed['summary'] = (string) ($parsed['summary'] ?? '');
    $parsed['issues'] = is_array($parsed['issues'] ?? null) ? $parsed['issues'] : [];
    $parsed['strengths'] = is_array($parsed['strengths'] ?? null) ? $parsed['strengths'] : [];

    return $parsed;
}

/**
 * Robust парсинг JSON-ответа с repair-fallback: markdown stripping, extraction
 * первого { ... }, удаление trailing commas. Возвращает null при полном фейле.
 */
function repairAndParseCritiqueJson(string $content): ?array {
    // 1. strip markdown code fences
    $clean = preg_replace('/^```(?:json)?\s*/m', '', $content);
    $clean = preg_replace('/```\s*$/m', '', $clean);
    $clean = trim($clean);

    $parsed = json_decode($clean, true);
    if (is_array($parsed)) return $parsed;

    // 2. extract first {...} block (LLM may have wrapped JSON in prose)
    if (preg_match('/\{.*\}/s', $clean, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) return $parsed;
    }

    // 3. strip trailing commas before } or ]
    $repaired = preg_replace('/,(\s*[}\]])/', '$1', $clean);
    $parsed = json_decode($repaired, true);
    if (is_array($parsed)) return $parsed;

    // 4. extract {...} + strip trailing commas
    if (preg_match('/\{.*\}/s', $repaired, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) return $parsed;
    }

    return null;
}

/**
 * Re-generate план с учётом критики.
 * Принимает первоначальный planData + critique → возвращает обновлённый planData
 * (тот же формат JSON-плана). При ошибке возвращает null (caller использует исходный план).
 */
function revisePlanWithCritique(
    array $planData,
    array $critique,
    array $user,
    array $context,
    int $userId,
    string $mode = 'ПЕРЕСЧЁТ'
): ?array {
    $maxRevisions = max(0, (int) env('PLAN_CRITIQUE_MAX_REVISIONS', 1));
    if ($maxRevisions <= 0) return null;
    if (empty($critique['should_revise']) || empty($critique['issues'])) return null;

    $baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
    $model = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');
    if ($baseUrl === '' || $model === '') return null;

    $startDate = (string) ($context['new_start_date'] ?? $context['start_date'] ?? date('Y-m-d'));
    $planJson = json_encode($planData, JSON_UNESCAPED_UNICODE);

    $issuesBlock = "ЗАМЕЧАНИЯ от независимого тренера:\n";
    foreach ($critique['issues'] as $i => $issue) {
        $n = $i + 1;
        $title = (string) ($issue['title'] ?? '');
        $sev = (string) ($issue['severity'] ?? 'minor');
        $week = (string) ($issue['week'] ?? '');
        $desc = (string) ($issue['description'] ?? '');
        $fix = (string) ($issue['suggested_fix'] ?? '');
        $issuesBlock .= "{$n}. [{$sev}] {$title}";
        if ($week !== '') $issuesBlock .= " ({$week})";
        $issuesBlock .= "\n   Проблема: {$desc}\n   Предложение: {$fix}\n";
    }

    // Список недель, упомянутых критиком — для scoped revision.
    $touchedWeeks = [];
    foreach ($critique['issues'] ?? [] as $issue) {
        $w = trim((string) ($issue['week'] ?? ''));
        if ($w !== '' && $w !== 'all') $touchedWeeks[] = $w;
    }
    $scopeHint = !empty($touchedWeeks)
        ? "Затронутые критикой недели: " . implode(', ', array_unique($touchedWeeks)) . ". Остальные недели НЕ ТРОГАЙ."
        : "Меняй точечно — только проблемы из issues.";

    $systemPrompt = <<<TXT
Ты — тренер, который только что сгенерировал план. Независимый коллега-тренер
нашёл проблемы. Пересмотри план ТОЧЕЧНО, не переписывай его заново.

ОБЯЗАТЕЛЬНЫЕ ИНВАРИАНТЫ (нарушение = брак revision):
1. Каждая неделя при подготовке к марафону содержит ровно 1 длительную (type=long),
   КРОМЕ recovery-недели сразу после race (max 1 raz). Длительная — это ≥ 14 км.
2. Длительные растут постепенно от ~14 до пиковой 30-34 км (≈75-80% race distance),
   пик за 2-3 недели до главного старта.
3. Race-week (последняя неделя перед главным стартом): 3-4 короткие пробежки 3-8 км
   + race. ЗАПРЕЩЕНО ставить 5+ rest подряд перед марафоном.
4. ВСЕ дни type='race' оставь на тех же датах и с тем же описанием — это ручные старты пользователя, неприкосновенны. Дни type='control' можно модифицировать.
5. Сохрани target time из исходного плана. Не меняй цель.
6. Не уменьшай число тренировочных дней в неделю (атлет сам выбрал sessions_per_week).
7. Не удаляй quality-тренировки целиком (tempo, interval, long) — только модифицируй.

ПРАВИЛА РЕВИЗИИ:
- {$scopeHint}
- Исправь critical замечания обязательно.
- Moderate замечания учти, если они согласуются с историей и уровнем атлета.
- Minor — на своё усмотрение.
- НЕ overcorrect. Лучше оставить недели нетронутыми, чем срезать важные элементы.
- Сохрани общую структуру (build → peak → taper).

ОТВЕТ — полный обновлённый план в ТОМ ЖЕ JSON-формате, что и оригинал.
Только JSON, без markdown, комментариев, объяснений.
TXT;

    $userContent = "ИСХОДНЫЙ ПЛАН (нач. {$startDate}):\n{$planJson}\n\n{$issuesBlock}";

    $maxTokens = max(4000, min(32000, (int) env('PLAN_REVISION_MAX_TOKENS', 24000)));

    $payload = LlmGateway::withThinkingMode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ],
        'stream' => false,
        'temperature' => 0.3,
        'max_tokens' => $maxTokens,
        'response_format' => ['type' => 'json_object'],
    ], $baseUrl, false);

    try {
        $db = function_exists('getDBConnection') ? getDBConnection() : null;
        $response = LlmGateway::requestChatCompletion($baseUrl, $payload, [
            'feature' => 'Plan revision after critique',
            'purpose' => 'chat',
            'db' => $db,
            'surface' => 'plan_revision',
            'event_type' => 'llm_request',
            'user_id' => $userId,
            'timeout' => max(30, min(600, (int) env('PLAN_REVISION_TIMEOUT_SECONDS', 240))),
            'connect_timeout' => 5,
            'max_attempts' => 1,
        ]);
    } catch (Throwable $e) {
        error_log("plan_revision: LLM error: " . $e->getMessage());
        return null;
    }

    $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    $finishReason = (string) ($response['choices'][0]['finish_reason'] ?? '');
    if ($content === '') return null;

    error_log("plan_revision: response length=" . strlen($content) . ", finish_reason={$finishReason}, max_tokens={$maxTokens}");

    // 1. Robust JSON repair (markdown strip, {...} extract, trailing-comma fix)
    $parsed = repairAndParseCritiqueJson($content);

    // 2. Если repair достал JSON — пропускаем через parseAndRepairPlanJSON для plan-specific repairs
    //    (например, нормализация ключей weeks[].days[]).
    if (!is_array($parsed)) {
        require_once __DIR__ . '/plan_normalizer.php';
        try {
            if (function_exists('parseAndRepairPlanJSON')) {
                $parsed = parseAndRepairPlanJSON($content, $userId);
            }
        } catch (Throwable $e) {
            error_log("plan_revision: parseAndRepairPlanJSON failed: " . $e->getMessage());
        }
    }

    if (!is_array($parsed)) {
        error_log("plan_revision: failed all parsers, preview=" . mb_substr($content, 0, 300));
        return null;
    }

    if (empty($parsed['weeks'])) {
        error_log("plan_revision: parsed JSON has no weeks[]; keys=" . implode(',', array_keys($parsed)));
        return null;
    }

    // Sanity-check revised plan: ловим overcorrection (срезанные длительные, all-rest race-week).
    $sanityIssue = validateRevisedPlan($planData, $parsed);
    if ($sanityIssue !== null) {
        error_log("plan_revision: rejected (sanity check failed): {$sanityIssue}");
        return null;
    }

    return $parsed;
}

/**
 * Sanity-check для revised плана: ловит overcorrection.
 * Возвращает текст проблемы или null если revision приемлем.
 */
function validateRevisedPlan(array $originalPlan, array $revisedPlan): ?string {
    // Strict: revised не должен трогать дни type='race' — это manual user-input (старты),
    // их меняет только сам пользователь. Control AI ставит сам, его можно модифицировать.
    $raceDays = function (array $plan): array {
        $out = [];
        foreach ($plan['weeks'] ?? [] as $week) {
            foreach ($week['days'] ?? [] as $day) {
                if (($day['type'] ?? '') === 'race') {
                    $date = (string) ($day['date'] ?? '');
                    if ($date !== '') $out[$date] = true;
                }
            }
        }
        return $out;
    };

    $origRaces = $raceDays($originalPlan);
    $revRaces = $raceDays($revisedPlan);
    foreach ($origRaces as $date => $_) {
        if (!isset($revRaces[$date])) {
            return "revised удалил race-день на {$date} (manual user-input, защищён)";
        }
    }

    $countLongs = function (array $plan): int {
        $cnt = 0;
        foreach ($plan['weeks'] ?? [] as $week) {
            foreach ($week['days'] ?? [] as $day) {
                if (($day['type'] ?? '') === 'long') $cnt++;
            }
        }
        return $cnt;
    };

    $countWeeklyTrainingDays = function (array $plan): array {
        $out = [];
        foreach ($plan['weeks'] ?? [] as $w => $week) {
            $cnt = 0;
            foreach ($week['days'] ?? [] as $day) {
                if (($day['type'] ?? '') !== 'rest' && ($day['type'] ?? '') !== 'free') $cnt++;
            }
            $out[$w] = $cnt;
        }
        return $out;
    };

    $origLongs = $countLongs($originalPlan);
    $revLongs = $countLongs($revisedPlan);
    if ($origLongs >= 3 && $revLongs < (int) ceil($origLongs * 0.6)) {
        return "revised срезал длительные: было {$origLongs}, осталось {$revLongs} (минимум 60% должно сохраниться)";
    }

    // Race-week: ищем последнюю неделю с type=race; в ней должно быть >= 3 тренировок (кроме race-day).
    $lastRaceWeekIdx = null;
    foreach ($revisedPlan['weeks'] ?? [] as $i => $week) {
        foreach ($week['days'] ?? [] as $day) {
            if (($day['type'] ?? '') === 'race') {
                // Главный старт — обычно последняя в плане
                $lastRaceWeekIdx = $i;
            }
        }
    }
    if ($lastRaceWeekIdx !== null) {
        $raceWeek = $revisedPlan['weeks'][$lastRaceWeekIdx];
        $trainingDays = 0;
        $hasRaceDay = false;
        foreach ($raceWeek['days'] ?? [] as $day) {
            $t = $day['type'] ?? '';
            if ($t === 'race') $hasRaceDay = true;
            elseif ($t !== 'rest' && $t !== 'free') $trainingDays++;
        }
        if ($hasRaceDay && $trainingDays < 2) {
            return "revised race-week содержит слишком мало тренировочных дней ({$trainingDays}, минимум 2 коротких + race)";
        }
    }

    // Резкое падение объёма недели — отбрасываем
    $origDays = $countWeeklyTrainingDays($originalPlan);
    $revDays = $countWeeklyTrainingDays($revisedPlan);
    foreach ($origDays as $w => $origCnt) {
        if (!isset($revDays[$w])) continue;
        if ($origCnt >= 4 && $revDays[$w] < (int) ceil($origCnt * 0.5)) {
            $weekNum = $w + 1;
            return "revised W{$weekNum} срезал тренировки: было {$origCnt}, стало {$revDays[$w]}";
        }
    }

    return null;
}

function buildAthleteBlockForCritique(array $user, array $context): string {
    $parts = ["АТЛЕТ:"];
    $parts[] = "- ID: " . ($user['id'] ?? '');
    if (!empty($user['username'])) $parts[] = "- Имя: " . $user['username'];
    if (!empty($user['birth_year'])) $parts[] = "- Возраст: ~" . (date('Y') - (int) $user['birth_year']);
    if (!empty($user['experience_level'])) $parts[] = "- Уровень: " . $user['experience_level'];
    if (!empty($user['weekly_base_km'])) $parts[] = "- Базовый объём: " . $user['weekly_base_km'] . " км/нед";
    if (!empty($user['sessions_per_week'])) $parts[] = "- Тренировок в неделю: " . $user['sessions_per_week'];
    if (!empty($user['race_date'])) $parts[] = "- Главный старт: " . $user['race_date'] . " (" . ($user['race_distance'] ?? '?') . ")";
    if (!empty($user['race_target_time'])) $parts[] = "- Целевое время: " . $user['race_target_time'];
    if (!empty($user['last_race_time']) && !empty($user['last_race_date'])) {
        $parts[] = "- Последний старт: " . $user['last_race_time'] . " (" . $user['last_race_date'] . ")";
    }
    if (!empty($context['acwr']['acwr'])) {
        $parts[] = "- ACWR: " . round((float) $context['acwr']['acwr'], 2) . " (" . ($context['acwr']['zone'] ?? '?') . ")";
    }
    if (!empty($context['compliance_2w'])) {
        $c = $context['compliance_2w'];
        $parts[] = "- Compliance 2 нед: " . ($c['completed'] ?? '?') . "/" . ($c['planned'] ?? '?') . " (" . ($c['pct'] ?? '?') . "%)";
    }
    if (!empty($context['avg_weekly_km_4w'])) $parts[] = "- Средний объём 4 нед: " . $context['avg_weekly_km_4w'] . " км";
    if (!empty($context['user_reason'])) $parts[] = "- Причина пересчёта: " . $context['user_reason'];
    return implode("\n", $parts);
}

function buildHistoryBlockForCritique(array $context): string {
    $blocks = [];

    if (!empty($context['plan_history_rollup'])) {
        $b = "ОБЪЁМЫ ПО НЕДЕЛЯМ (история):\n";
        foreach ($context['plan_history_rollup'] as $line) {
            $b .= "{$line}\n";
        }
        $blocks[] = trim($b);
    }

    if (!empty($context['plan_key_workouts'])) {
        $b = "КЛЮЧЕВЫЕ/ЗНАЧИМЫЕ ТРЕНИРОВКИ (★=план key, ◆=значимая по факту):\n";
        foreach ($context['plan_key_workouts'] as $line) {
            $b .= "  {$line}\n";
        }
        $blocks[] = trim($b);
    }

    return implode("\n\n", $blocks);
}
