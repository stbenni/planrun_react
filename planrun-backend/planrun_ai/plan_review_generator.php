<?php
/**
 * Генерация рецензии плана через LLM (llama.cpp).
 * После сохранения плана — добавляет в чат пользователя краткое описание:
 * что и почему расставлено в плане.
 */

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../services/LlmGateway.php';
require_once __DIR__ . '/plan_normalizer.php';

/**
 * Строит текстовое описание плана для промпта LLM.
 *
 * @param array $planData Сырой план (weeks[].days[])
 * @param string $startDate Дата начала (YYYY-MM-DD)
 * @return string
 */
function buildPlanSummaryForReview(array $planData, string $startDate): string {
    $normalized = normalizeTrainingPlan($planData, $startDate);
    $dayNames = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    $lines = [];
    $typeLabels = [
        'easy' => 'Лёгкий бег',
        'long' => 'Длительный бег',
        'tempo' => 'Темповая работа',
        'interval' => 'Интервалы',
        'fartlek' => 'Фартлек',
        'control' => 'Контрольный старт',
        'race' => 'Главный старт',
        'walking' => 'Ходьба',
        'other' => 'ОФП',
        'sbu' => 'СБУ',
        'rest' => 'Отдых',
        'free' => 'Свободный день'
    ];

    foreach ($normalized['weeks'] ?? [] as $week) {
        $wn = $week['week_number'] ?? 0;
        $lines[] = "Неделя {$wn}:";
        foreach ($week['days'] ?? [] as $i => $day) {
            $dow = $dayNames[$i] ?? ('День ' . ($i + 1));
            $type = $day['type'] ?? 'rest';
            $desc = trim($day['description'] ?? '');
            $isKey = !empty($day['is_key_workout']);
            $keyMark = $isKey ? ' [ключевая]' : '';
            $label = $typeLabels[$type] ?? $type;
            if ($desc !== '') {
                $lines[] = "  {$dow}: {$label} — {$desc}{$keyMark}";
            } else {
                $lines[] = "  {$dow}: {$label}{$keyMark}";
            }
        }
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * Генерирует рецензию плана через LLM.
 *
 * @param array $planData Сырой план
 * @param string $startDate Дата начала
 * @param string $mode 'ГЕНЕРАЦИЯ'|'ПЕРЕСЧЁТ'|'НОВЫЙ ПЛАН'
 * @param array|null $realismContext Контекст оценки цели для честного объяснения:
 *   severity (none|moderate|major), goal_target_time, predicted_target_time,
 *   effective_target_time, race_distance_label, gap_pct.
 * @return string|null Текст рецензии или null при ошибке
 */
function generatePlanReview(
    array $planData,
    string $startDate,
    string $mode = 'ГЕНЕРАЦИЯ',
    ?array $realismContext = null
): ?string {
    $baseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'https://api.deepseek.com'), '/');
    $model = env('LLM_CHAT_MODEL', 'deepseek-v4-flash');

    if ($baseUrl === '' || $model === '') {
        error_log('plan_review_generator: LLM_CHAT_BASE_URL или LLM_CHAT_MODEL не заданы');
        return null;
    }

    $planSummary = buildPlanSummaryForReview($planData, $startDate);
    $reviewFacts = buildPlanReviewFacts($planData, $startDate);
    if (mb_strlen($planSummary) > 8000) {
        $planSummary = mb_substr($planSummary, 0, 8000) . "\n... (обрезка)";
    }

    // PR9: блок про реалистичность цели — честно сообщает пользователю, под какой
    // таргет план готовит, если goal в профиле не достижим за один цикл.
    $realismFacts = buildRealismFactsForReview($realismContext);
    $realismDirective = buildRealismDirectiveForReview($realismContext);

    $systemPrompt = "Ты — AI-тренер PlanRun. Сгенерируй короткое человеческое объяснение плана для пользователя. " .
        "Объясни, что и почему расставлено в плане, особенно логику подводки, восстановления и ключевых дней. " .
        "Пиши дружелюбно, спокойно и только по фактам из плана. Максимум 2 коротких абзаца и 4–6 предложений суммарно. Только русский язык. " .
        "Не начинай с канцелярских фраз вроде «План построен с учётом» или «План разработан с учётом». Лучше говори как живой тренер: коротко, по делу и без повтора одной мысли разными словами. " .
        "Не используй английские слова и внутренний спортивный жаргон без необходимости: не пиши tune-up, quality, readiness, control, race, recovery, fatigue. " .
        "Вместо этого используй понятные русские формулировки: контрольный старт, главный старт, восстановление, усталость, готовность, подводка к старту. " .
        "Строгие правила: " .
        "1) день типа race — это главный старт, а не тренировочная длительная и не способ нарастить объём; " .
        "2) день типа control — это контрольный старт, его нельзя описывать как обычную длительную; " .
        "3) если план заканчивается гонкой, описывай это как подводку к старту или снижение нагрузки перед стартом, а не как прогрессивное увеличение объёма; " .
        "4) не пиши, что марафон в конце плана «готовит к марафону»; " .
        "5) не используй слово «тейпер» и похожие англицизмы; " .
        "6) не придумывай мотивы, которых нет в фактах плана." .
        $realismDirective;

    $factsBlocks = [$reviewFacts];
    if ($realismFacts !== '') {
        $factsBlocks[] = $realismFacts;
    }

    $userContent = "Режим: {$mode}.\n\nФакты для рецензии:\n" . implode("\n\n", $factsBlocks) . "\n\nПлан:\n\n" . $planSummary;

    $timeoutSeconds = max(10, min(300, (int) env('PLAN_REVIEW_LLM_TIMEOUT_SECONDS', 45)));
    $connectTimeoutSeconds = max(1, min(60, (int) env('PLAN_REVIEW_LLM_CONNECT_TIMEOUT_SECONDS', 5)));
    $maxTokens = max(200, min(1500, (int) env('PLAN_REVIEW_LLM_MAX_TOKENS', 700)));

    $payload = LlmGateway::withThinkingMode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent]
        ],
        'stream' => false,
        'max_tokens' => $maxTokens,
        'temperature' => 0.3,
    ], $baseUrl, false);

    try {
        $db = function_exists('getDBConnection') ? getDBConnection() : null;
        $data = LlmGateway::requestChatCompletion($baseUrl, $payload, [
            'feature' => 'Plan review',
            'purpose' => 'chat',
            'db' => $db,
            'surface' => 'plan_review',
            'event_type' => 'llm_request',
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $connectTimeoutSeconds,
            'max_attempts' => max(1, min(5, (int) env('LLM_MAX_RETRIES', 1))),
        ]);
    } catch (Throwable $e) {
        error_log('plan_review_generator: LLM error: ' . $e->getMessage());
        return null;
    }

    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return null;
    }

    $content = sanitizePlanReviewContent($content, $planData, $startDate);
    if ($content === '') {
        return null;
    }

    $content = mb_substr($content, 0, 4000);
    return $content;
}

/**
 * PR9: формирует facts-блок про реалистичность цели для prompt.
 * Только сухие данные — никакой интерпретации и никаких готовых формулировок;
 * вывод и тренерскую фразу пишет сама модель.
 * Возвращает '' если severity = none или нет данных.
 */
function buildRealismFactsForReview(?array $realism): string {
    if (!is_array($realism)) {
        return '';
    }
    $severity = (string) ($realism['severity'] ?? 'none');
    if ($severity !== 'major' && $severity !== 'moderate') {
        return '';
    }

    $goal = (string) ($realism['goal_target_time'] ?? '');
    $effective = (string) ($realism['effective_target_time'] ?? '');
    $predicted = (string) ($realism['predicted_target_time'] ?? '');
    $distLabel = (string) ($realism['race_distance_label'] ?? '');
    $gapPct = $realism['gap_pct'] ?? null;

    if ($goal === '' || $effective === '') {
        return '';
    }

    $lines = ['Контекст по цели:'];
    if ($distLabel !== '') {
        $lines[] = "- дистанция: {$distLabel}";
    }
    $lines[] = "- цель в профиле: {$goal}";
    if ($predicted !== '') {
        $lines[] = "- реалистичный прогноз по текущей форме: {$predicted}";
    }
    $lines[] = "- таргет, под который реально рассчитан план: {$effective}";
    if ($gapPct !== null) {
        $gap = is_numeric($gapPct) ? round((float) $gapPct, 1) : $gapPct;
        $lines[] = "- gap goal vs predicted: {$gap}%";
    }
    $lines[] = "- severity: {$severity}";

    return implode("\n", $lines);
}

/**
 * PR9: нейтральная директива для системного промпта при moderate/major цели.
 * Не диктуем модели готовые фразы и не навязываем интерпретацию — просто
 * указываем, что блок «Контекст по цели» в фактах нужно отразить, и без оправданий.
 * Тренерскую формулировку модель пишет сама из фактов.
 */
function buildRealismDirectiveForReview(?array $realism): string {
    if (!is_array($realism)) {
        return '';
    }
    $severity = (string) ($realism['severity'] ?? 'none');
    if ($severity !== 'major' && $severity !== 'moderate') {
        return '';
    }

    return ' Дополнительное правило: если в фактах есть блок «Контекст по цели», отрази его в первой фразе ответа — спокойно, по-тренерски, без оправданий и без обещаний. Назови цель из профиля и таргет, под который реально готовит план; не выдумывай причин, опирайся только на эти факты.';
}

function buildPlanReviewFacts(array $planData, string $startDate): string {
    $normalized = normalizeTrainingPlan($planData, $startDate);
    $facts = [];
    $raceFacts = [];
    $controlFacts = [];

    foreach ($normalized['weeks'] ?? [] as $week) {
        foreach ($week['days'] ?? [] as $day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $date = trim((string) ($day['date'] ?? ''));
            $distanceKm = isset($day['distance_km']) ? round((float) $day['distance_km'], 1) : 0.0;
            $pace = trim((string) ($day['pace'] ?? ''));

            if ($type === 'race') {
                $raceFacts[] = sprintf(
                    '- главный старт: %s%s%s',
                    $date !== '' ? $date : 'дата не указана',
                    $distanceKm > 0 ? sprintf(', %.1f км', $distanceKm) : '',
                    $pace !== '' ? ', целевой темп ' . $pace : ''
                );
            }

            if ($type === 'control') {
                $controlFacts[] = sprintf(
                    '- контрольный старт: %s%s%s',
                    $date !== '' ? $date : 'дата не указана',
                    $distanceKm > 0 ? sprintf(', %.1f км', $distanceKm) : '',
                    $pace !== '' ? ', ориентир ' . $pace : ''
                );
            }
        }
    }

    if ($controlFacts !== []) {
        $facts[] = implode("\n", $controlFacts);
    }
    if ($raceFacts !== []) {
        $facts[] = implode("\n", $raceFacts);
    }
    if ($raceFacts !== []) {
        $facts[] = '- дни типа race — это уже сам старт, а не подготовительная длительная тренировка';
    }
    if ($controlFacts !== [] && $raceFacts !== []) {
        $facts[] = '- если контрольный старт стоит незадолго до главного старта, трактуй это как проверку формы перед главным стартом';
    }
    if ($facts === []) {
        $facts[] = '- в плане нет отдельного дня race';
    }

    return implode("\n", $facts);
}

function sanitizePlanReviewContent(string $content, array $planData, string $startDate): string {
    $normalized = normalizeTrainingPlan($planData, $startDate);
    $hasRaceDay = false;
    $raceDistanceKm = null;

    foreach ($normalized['weeks'] ?? [] as $week) {
        foreach ($week['days'] ?? [] as $day) {
            if (normalizeTrainingType($day['type'] ?? null) !== 'race') {
                continue;
            }
            $hasRaceDay = true;
            if (isset($day['distance_km'])) {
                $raceDistanceKm = round((float) $day['distance_km'], 1);
            }
            break 2;
        }
    }

    if (!$hasRaceDay) {
        return trim($content);
    }

    $sentences = preg_split('/(?<=[.!?])\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($sentences) || $sentences === []) {
        return trim($content);
    }

    $filtered = [];
    $removed = false;
    foreach ($sentences as $sentence) {
        if (isForbiddenRaceReviewSentence($sentence, $raceDistanceKm)) {
            $removed = true;
            continue;
        }
        $filtered[] = $sentence;
    }

    if ($removed) {
        $filtered[] = 'Финальный день с дистанцией гонки — это сам главный старт, а неделя перед ним служит подводке и сохранению свежести, а не наращиванию объёма.';
    }

    return trim(polishPlanReviewTone(applyPlanReviewLanguageReplacements(implode(' ', $filtered))));
}

function isForbiddenRaceReviewSentence(string $sentence, ?float $raceDistanceKm = null): bool {
    $normalized = mb_strtolower(trim($sentence), 'UTF-8');
    if ($normalized === '') {
        return false;
    }

    $hasRaceDistanceMention = false;
    if ($raceDistanceKm !== null && $raceDistanceKm > 0) {
        $distanceVariants = [
            number_format($raceDistanceKm, 1, '.', ''),
            number_format($raceDistanceKm, 1, ',', ''),
        ];
        foreach ($distanceVariants as $variant) {
            if ($variant !== '' && mb_strpos($normalized, $variant) !== false) {
                $hasRaceDistanceMention = true;
                break;
            }
        }
    }

    if (preg_match('/удвоени\w+\s+дистанц/u', $normalized)) {
        return true;
    }
    if ($hasRaceDistanceMention && preg_match('/длинн|длительн/u', $normalized)) {
        return true;
    }
    if ($hasRaceDistanceMention && preg_match('/готов\w+.*к марафон|для подготов\w+.*к марафон/u', $normalized)) {
        return true;
    }
    if ($hasRaceDistanceMention && preg_match('/развива\w+.*вынослив|наращива\w+.*объем|увеличива\w+.*нагруз/u', $normalized)) {
        return true;
    }
    if (preg_match('/главн\w+\s+старт/u', $normalized) && preg_match('/активац|адаптир\w+.*к марафонск/u', $normalized)) {
        return true;
    }

    return false;
}

function applyPlanReviewLanguageReplacements(string $content): string {
    $replacements = [
        'tune-up' => 'контрольный старт',
        'tune up' => 'контрольный старт',
        'TUNE-UP' => 'контрольный старт',
        'taper' => 'подводка к старту',
        'quality' => 'качественная работа',
        'readiness' => 'готовность',
        'recovery' => 'восстановление',
        'fatigue' => 'усталость',
        'control' => 'контрольный старт',
        'race' => 'главный старт',
        'тейпер' => 'подводка к старту',
        'Тейпер' => 'Подводка к старту',
        'тейпера' => 'подводки',
        'тейпером' => 'подводкой',
        'тейперу' => 'подводке',
        'ключевая активация' => 'контрольная проверка формы',
        'финальная активация' => 'сам старт',
        'завершающая активация' => 'сам старт',
    ];

    return strtr($content, $replacements);
}

function polishPlanReviewTone(string $content): string {
    $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
    if ($content === '') {
        return '';
    }

    $content = preg_replace("/[ \t]+/u", ' ', $content) ?? $content;
    $content = preg_replace("/\n{3,}/u", "\n\n", $content) ?? $content;

    $phraseReplacements = [
        '/^План\s+(построен|разработан)\s+с\s+уч[её]том/iu' => 'Сейчас план собран с учётом',
        '/\bТакой подход\b/iu' => 'Это',
        '/\bЭто логично,\s+так как\b/iu' => '',
        '/\bвыступает как\b/iu' => 'помогает как',
        '/\bслужат именно для\b/iu' => 'нужны, чтобы',
        '/\bнаправлены на\b/iu' => 'нужны, чтобы',
        '/\bчто помогает\b/iu' => 'чтобы',
        '/\bчто позволяет\b/iu' => 'это помогает',
        '/\bВсё расписание учитывает\b/iu' => 'План держит',
        '/\bВажно, что\b/iu' => 'Важно:',
        '/На главный старт ид[её]т минимальная активность:\s*л[её]гкие беги и отдых\./iu' => 'Оставшиеся дни — это короткие лёгкие пробежки и отдых, чтобы подойти к главному старту свежее.',
        '/ид[её]т прямая подводка/iu' => 'остаётся спокойная подводка',
        '/лучше\s+[«"]?запустить[»"]?\s+организм\s+на\s+финальный\s+этап/iu' => 'подойти к главному старту свежее',
        '/\bподводка к старту к старту\b/iu' => 'подводка к старту',
        '/\bснижение нагрузки перед стартом к старту\b/iu' => 'снижение нагрузки перед стартом',
    ];

    foreach ($phraseReplacements as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content) ?? $content;
    }

    $content = preg_replace('/\s{2,}/u', ' ', $content) ?? $content;
    $content = trim($content);

    $sentences = preg_split('/(?<=[.!?])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($sentences) || $sentences === []) {
        return $content;
    }

    $kept = [];
    $seenCategories = [];
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }

        $category = detectPlanReviewSentenceCategory($sentence);
        if ($category !== 'other' && isset($seenCategories[$category])) {
            continue;
        }

        $kept[] = $sentence;
        if ($category !== 'other') {
            $seenCategories[$category] = true;
        }

        if (count($kept) >= 5) {
            break;
        }
    }

    if ($kept === []) {
        return '';
    }

    $firstParagraphCount = count($kept) > 3 ? 2 : min(2, count($kept));
    $paragraphOne = implode(' ', array_slice($kept, 0, $firstParagraphCount));
    $paragraphTwo = implode(' ', array_slice($kept, $firstParagraphCount));

    $result = trim($paragraphOne);
    if ($paragraphTwo !== '') {
        $result .= "\n\n" . trim($paragraphTwo);
    }

    return trim($result);
}

function detectPlanReviewSentenceCategory(string $sentence): string {
    $normalized = mb_strtolower($sentence, 'UTF-8');

    if (str_contains($normalized, 'контрольный старт') && str_contains($normalized, 'главный старт')) {
        return 'overview';
    }
    if (str_contains($normalized, 'контрольный старт')) {
        return 'control';
    }
    if (str_contains($normalized, 'главный старт')) {
        return 'race';
    }
    if (preg_match('/восстанов|отдых|устал|свежест/u', $normalized) === 1) {
        return 'recovery';
    }
    if (preg_match('/л[её]гк|коротк/u', $normalized) === 1) {
        return 'support';
    }

    return 'other';
}
