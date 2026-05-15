<?php
/**
 * Сборка system prompt и массивов сообщений для LLM-чата.
 * Оптимизирован для DeepSeek API (128K контекст): нативные tools,
 * динамические секции, бюджетирование токенов.
 */

require_once __DIR__ . '/DateResolver.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class ChatPromptBuilder {

    private const MAX_CONTEXT_TOKENS = 32000;
    private const SYSTEM_PROMPT_BUDGET = 6000;
    private const CONTEXT_BUDGET = 10000;
    private const HISTORY_BUDGET = 14000;
    private const CHARS_PER_TOKEN = 3.2;

    /** @var mixed */
    private $db;
    private ChatContextBuilder $contextBuilder;
    private ChatRepository $repository;

    public function __construct($db, ChatContextBuilder $contextBuilder, ChatRepository $repository) {
        $this->db = $db;
        $this->contextBuilder = $contextBuilder;
        $this->repository = $repository;
    }

    /**
     * Оценка количества токенов для русского текста.
     * Для кириллицы ~3.2 символа на токен (эмпирика, примерно совпадает для DeepSeek).
     */
    public function estimateTokens(string $text): int {
        $len = mb_strlen($text);
        if ($len === 0) return 0;
        return (int) ceil($len / self::CHARS_PER_TOKEN);
    }

    /**
     * Оценка токенов для массива сообщений (system + user + assistant).
     */
    public function estimateMessagesTokens(array $messages): int {
        $total = 0;
        foreach ($messages as $msg) {
            $total += $this->estimateTokens($msg['content'] ?? '') + 4;
        }
        return $total;
    }

    /**
     * Собирает массив сообщений для API: сжатый system prompt + контекст + история.
     * Автоматически управляет бюджетом токенов, обрезая при превышении.
     */
    public function buildChatMessages(int $userId, string $context, array $history, string $currentQuestion): array {
        $tzName = getUserTimezone($userId);
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Exception $e) {
            $tz = new DateTimeZone('Europe/Moscow');
        }
        $now = new DateTime('now', $tz);
        $tomorrowDt = (clone $now)->modify('+1 day');
        $today = $now->format('Y-m-d');
        $tomorrow = $tomorrowDt->format('Y-m-d');
        $daysRu = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];
        $todayDow = $daysRu[(int) $now->format('N')] ?? '';
        $tomorrowDow = $daysRu[(int) $tomorrowDt->format('N')] ?? '';

        $systemContent = $this->buildCompressedSystemPrompt($userId, $today, $tomorrow, $todayDow, $tomorrowDow);

        if ($this->hasReplaceWithRaceIntent($currentQuestion, $history)) {
            $systemContent .= $this->getRaceReplacementAddon($today, $tomorrow);
        }
        if ($this->hasAddTrainingIntent($currentQuestion, $history)) {
            $resolvedDate = $this->resolveDateFromUserMessage($currentQuestion, $now);
            $systemContent .= $this->getAddTrainingAddon($resolvedDate);
        }

        $systemContent .= "\n\n" . $this->trimToTokenBudget($context, self::CONTEXT_BUDGET);

        // DATES go INTO the latest user message (not system), so system+tools+history can stay
        // byte-identical between requests. DeepSeek's prefix cache requires bytes-from-token-0
        // to match — anything dynamic before the user message invalidates the whole prefix.
        // Source: api-docs.deepseek.com/guides/kv_cache.

        $messages = [['role' => 'system', 'content' => $systemContent]];

        $historyMessages = [];
        foreach ($history as $m) {
            $role = $m['sender_type'] === 'user' ? 'user' : 'assistant';
            $historyMessages[] = ['role' => $role, 'content' => trim($m['content'])];
        }

        $enrichedQuestion = $this->enrichQuestionWithDates($currentQuestion, $now);
        $datesSuffix = $this->buildDatesSuffix($today, $tomorrow, $todayDow, $tomorrowDow);
        $historyMessages[] = ['role' => 'user', 'content' => $enrichedQuestion . $datesSuffix];
        $historyMessages = $this->trimHistoryToTokenBudget($historyMessages, self::HISTORY_BUDGET);

        $messages = array_merge($messages, $historyMessages);

        $totalTokens = $this->estimateMessagesTokens($messages);
        if ($totalTokens > self::MAX_CONTEXT_TOKENS) {
            Logger::warning('Token budget exceeded after assembly, trimming history', [
                'tokens' => $totalTokens, 'max' => self::MAX_CONTEXT_TOKENS, 'history_count' => count($historyMessages),
            ]);
            $excess = $totalTokens - self::MAX_CONTEXT_TOKENS;
            $messages = $this->trimOldestMessages($messages, $excess);
        }

        return $this->normalizeMessagesForStrictAlternation($messages);
    }

    /**
     * Сжатый system prompt — ~40% короче оригинала при сохранении смысла.
     */
    private function buildCompressedSystemPrompt(int $userId, string $today, string $tomorrow, string $todayDow, string $tomorrowDow): string {
        $yesterdayDt = (new DateTime($today))->modify('-1 day');
        $daysRu = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];
        $yesterday = $yesterdayDt->format('Y-m-d');
        $yesterdayDow = $daysRu[(int) $yesterdayDt->format('N')] ?? '';

        // NOTE: Стабильную часть промпта держим в начале, даты — в самом конце,
        // чтобы DeepSeek context cache работал. Иначе ежедневные даты ломают prefix кэш
        // и весь промпт становится cache-miss каждый новый день (потеря 90% скидки).
        return <<<PROMPT
Ты — PlanRun, персональный тренер по бегу.

СТИЛЬ: Дружелюбный тренер, 15 лет стажа. 2-4 предложения, конкретно, эмпатично. Хвали прогресс. Без укоров при пропуске.

ЯЗЫК: ⚠⚠⚠ 100% РУССКИЙ ЯЗЫК. Ни одного английского слова в ответе. Все термины по-русски: recovery=восстановление, pace=темп, long run=длительный бег, cooldown=заминка, warm up=разминка, easy run=лёгкий бег, interval=интервал, threshold=пороговый, split=отрезок, workout=тренировка, planned=запланированный, today=сегодня, tomorrow=завтра, yesterday=вчера. Даты: «18 февраля».
Без emoji. Без смайликов. Без 🏃🔹💪⚡🎯 и подобных символов.

ДАТЫ: «вчера», «сегодня», «завтра», «позавчера» резолви через блок DATES в конце промпта. Используй абсолютные Y-m-d в вызовах инструментов. НИКОГДА не спрашивай пользователя «какая дата вчера?». Передавай в tools дату Y-m-d.

НЕ ПОВТОРЯЙСЯ: Каждый ответ — НОВАЯ информация. Контекст точечно. Не вываливай все данные.

ИНСТРУМЕНТЫ: Тебе доступны tools (function calling). Вызывай их ПРОАКТИВНО — не выдумывай цифры, даты и данные. Все даты в аргументах tools: Y-m-d. Write-операции (update, delete, swap, move, add, recalculate, generate, log) — ОБЯЗАТЕЛЬНО спроси подтверждение перед вызовом.

ТОЧНОСТЬ ЦИФР: ⚠ Используй ТОЛЬКО конкретные числа из контекста или tool results. НЕ генерируй "обычно длинные до 30-40 км", "темп ~4:40 для марафона" из общих знаний — у пользователя свой план с конкретными цифрами. Если хочешь сказать «длинные до X км» — назови ТОЧНОЕ X из плана. Если данных в контексте нет — вызови соответствующий tool (get_plan/get_day_details/race_prediction). Если и там нет — честно скажи «эти данные пока не вычислены» вместо выдуманного диапазона.

ПОДТВЕРЖДЕНИЯ: Перед записью — опиши с КОНКРЕТНЫМИ ДАТАМИ, получи «да»/«ок». Не вызывай tool повторно после подтверждения.

СТРАТЕГИЯ:
- Вопрос о тренировке → get_day_details(дата). О периоде → get_workouts.
- «Вчера» → get_day_details(yesterday из DATES) или get_workouts(date_from=yesterday, date_to=yesterday).
- Перенос/замена → get_day_details → уточни → подтверждение → tool.
- «Пробежал X км» → уточни → log_workout. Статистика → get_stats. Прогноз → race_prediction.

ПРОАКТИВНЫЙ МОНИТОРИНГ: пауза >3 дн, тяжёлая тренировка, выполнение <50%, скорый забег. Сначала ответь на вопрос.

КРОСС-ТРЕНИНГ: При невозможности бега → плавание/вело/эллипс, йога/растяжка, силовые.

Контекст пользователя (ID: {$userId}):
PROMPT;
    }

    /**
     * Mutable date block. Always appended LAST in the system message so DeepSeek's
     * prefix cache stays warm — everything before this string is cache-eligible.
     */
    private function buildDatesSuffix(string $today, string $tomorrow, string $todayDow, string $tomorrowDow): string {
        $yesterdayDt = (new DateTime($today))->modify('-1 day');
        $daysRu = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];
        $yesterday = $yesterdayDt->format('Y-m-d');
        $yesterdayDow = $daysRu[(int) $yesterdayDt->format('N')] ?? '';

        return "\n\nDATES: вчера={$yesterdayDow} {$yesterday}, сегодня={$todayDow} {$today}, завтра={$tomorrowDow} {$tomorrow}";
    }

    private function getRaceReplacementAddon(string $today, string $tomorrow): string {
        return "\n\nЗАМЕНА НА ЗАБЕГ: get_day_details({$today}) и get_day_details({$tomorrow}). Предложи: {$today}→race (дистанция+время), {$tomorrow}→easy 6-8 км. При подтверждении система обновит автоматически. Дай тактику: разминка 1.5-2 км, темп по отрезкам, питание, заминка. Формат description: «Полумарафон: 21.1 км за 2:00:00».\n";
    }

    private function getAddTrainingAddon(?string $resolvedDate): string {
        $addon = "\n\nДОБАВЛЕНИЕ ТРЕНИРОВКИ: Уточни тип/детали. При подтверждении вызови tool add_training_day.\n";
        $addon .= "Типы: easy|long|tempo|interval|fartlek|rest|other|sbu|race|marathon|control|free.\n";
        $addon .= "Форматы description:\n";
        $addon .= "Бег: «Легкий бег: X км» или «X км или ЧЧ:ММ:СС, темп M:SS». Темп — M:SS без ~/км.\n";
        $addon .= "Интервалы: разминка км+темп, N×Мм+темп, пауза В МЕТРАХ (200м), тип паузы, заминка.\n";
        $addon .= "Фартлек: разминка, N×Мм темп M:SS, восстановление Pм трусцой/ходьбой, заминка.\n";
        $addon .= "ОФП: «Название — 3×10, 20 кг» с новой строки. СБУ: «Название — 30 м» с новой строки.\n";
        if ($resolvedDate !== null) {
            $addon .= "Вычисленная дата: {$resolvedDate}.\n";
        }
        return $addon;
    }

    /**
     * Обрезает текст до заданного бюджета токенов (по параграфам с конца).
     */
    private function trimToTokenBudget(string $text, int $maxTokens): string {
        if ($this->estimateTokens($text) <= $maxTokens) return $text;

        $sections = preg_split('/\n{2,}/', $text);
        $result = '';
        $usedTokens = 0;
        foreach ($sections as $section) {
            $sectionTokens = $this->estimateTokens($section);
            if ($usedTokens + $sectionTokens > $maxTokens) {
                if ($usedTokens === 0) {
                    $maxChars = (int)($maxTokens * self::CHARS_PER_TOKEN);
                    $result = mb_substr($section, 0, $maxChars) . '…';
                }
                break;
            }
            $result .= ($result !== '' ? "\n\n" : '') . $section;
            $usedTokens += $sectionTokens;
        }
        return $result;
    }

    /**
     * Обрезает историю сообщений, удаляя старые пары, сохраняя последний user.
     */
    private function trimHistoryToTokenBudget(array $messages, int $maxTokens): array {
        $totalTokens = 0;
        foreach ($messages as $msg) {
            $totalTokens += $this->estimateTokens($msg['content'] ?? '') + 4;
        }
        if ($totalTokens <= $maxTokens) return $messages;

        while (count($messages) > 2 && $totalTokens > $maxTokens) {
            $removed = array_shift($messages);
            $totalTokens -= ($this->estimateTokens($removed['content'] ?? '') + 4);
        }
        return $messages;
    }

    /**
     * Обрезает самые старые non-system сообщения для снижения бюджета на excess токенов.
     */
    private function trimOldestMessages(array $messages, int $excessTokens): array {
        $trimmed = 0;
        $result = [];
        foreach ($messages as $i => $msg) {
            if ($i === 0 && ($msg['role'] ?? '') === 'system') {
                $result[] = $msg;
                continue;
            }
            if ($trimmed < $excessTokens && ($msg['role'] ?? '') !== 'system') {
                $trimmed += $this->estimateTokens($msg['content'] ?? '') + 4;
                continue;
            }
            $result[] = $msg;
        }
        return $result;
    }

    /**
     * DeepSeek и большинство LLM требуют строгого чередования user/assistant.
     * История сайта может содержать:
     * - подряд несколько user-сообщений после неуспешных ответов,
     * - подряд несколько assistant-сообщений из системных AI-уведомлений,
     * - ведущие assistant-сообщения без предшествующего user.
     * Нормализуем такую историю в допустимый вид перед отправкой в LLM.
     */
    public function normalizeMessagesForStrictAlternation(array $messages): array {
        if (empty($messages)) {
            return $messages;
        }

        $normalized = [];
        $index = 0;
        if (($messages[0]['role'] ?? null) === 'system') {
            $normalized[] = $messages[0];
            $index = 1;
        }

        $appendToSystem = function (string $text) use (&$normalized): void {
            $text = trim($text);
            if ($text === '') {
                return;
            }

            $note = "═══ ПРЕДЫДУЩИЕ СООБЩЕНИЯ АССИСТЕНТА ═══\n{$text}";
            if (!empty($normalized) && ($normalized[0]['role'] ?? null) === 'system') {
                $existing = rtrim((string) ($normalized[0]['content'] ?? ''));
                if (!str_contains($existing, $note)) {
                    $normalized[0]['content'] = $existing . "\n\n" . $note;
                }
                return;
            }

            array_unshift($normalized, ['role' => 'system', 'content' => $note]);
        };

        for (; $index < count($messages); $index++) {
            $message = $messages[$index];
            $role = $message['role'] ?? null;
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            if (!in_array($role, ['user', 'assistant'], true)) {
                $normalized[] = $message;
                continue;
            }

            $lastIndex = count($normalized) - 1;
            $lastRole = $lastIndex >= 0 ? ($normalized[$lastIndex]['role'] ?? null) : null;
            $hasConversationTurns = !empty($normalized) && (($normalized[0]['role'] ?? null) !== 'system' || count($normalized) > 1);

            if (!$hasConversationTurns && $role === 'assistant') {
                $appendToSystem($content);
                continue;
            }

            if ($lastRole === $role && in_array($role, ['user', 'assistant'], true)) {
                $separator = $role === 'user'
                    ? "\n\n[Дополнение пользователя]\n"
                    : "\n\n[Предыдущее сообщение ассистента]\n";
                $normalized[$lastIndex]['content'] = rtrim((string) $normalized[$lastIndex]['content']) . $separator . $content;
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    /**
     * Поиск по истории чата пользователя в БД и подстановка релевантных фрагментов в контекст.
     * Включается через CHAT_SEARCH_HISTORY=1 (по умолчанию 1). Ключевые слова — из текущего сообщения.
     */
    public function appendChatSearchSnippet(string $context, int $conversationId, string $currentMessage): string {
        if ((int) env('CHAT_SEARCH_HISTORY', 1) !== 1) {
            return $context;
        }
        $words = preg_split('/[\s\p{P}\p{S}]+/u', mb_strtolower($currentMessage), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words, function ($w) {
            return mb_strlen($w) >= 3;
        }));
        $words = array_slice($words, 0, 8);
        if (empty($words)) {
            return $context;
        }
        try {
            $rows = $this->repository->searchInChat($conversationId, $words, 8);
        } catch (Throwable $e) {
            Logger::warning('Chat search failed', ['error' => $e->getMessage()]);
            return $context;
        }
        if (empty($rows)) {
            return $context;
        }
        $currentTrim = trim($currentMessage);
        $lines = ["═══ ИЗ ПРОШЛЫХ СООБЩЕНИЙ (по теме запроса) ═══"];
        foreach ($rows as $r) {
            $text = trim($r['content'] ?? '');
            if ($text === '' || $text === $currentTrim) {
                continue;
            }
            $role = ($r['sender_type'] ?? '') === 'user' ? 'Пользователь' : 'Ассистент';
            $date = isset($r['created_at']) ? date('d.m.Y H:i', strtotime($r['created_at'])) : '';
            $snippet = mb_strlen($text) > 600 ? mb_substr($text, 0, 597) . '…' : $text;
            $lines[] = "{$role}" . ($date ? " ({$date})" : '') . ": {$snippet}";
        }
        if (count($lines) <= 1) {
            return $context;
        }
        return $context . "\n\n" . implode("\n\n", $lines);
    }

    /**
     * RAG: запрос к базе знаний (PlanRun AI /api/v1/retrieve-knowledge) и подстановка фрагментов в контекст.
     * Включается через CHAT_RAG_ENABLED=1. URL — RAG_RETRIEVE_URL (по умолчанию из PLANRUN_AI_API_URL).
     */
    public function appendRagSnippet(string $context, string $currentMessage): string {
        if ((int) env('CHAT_RAG_ENABLED', 0) !== 1) {
            return $context;
        }
        $url = env('RAG_RETRIEVE_URL', '');
        if ($url === '') {
            $base = env('PLANRUN_AI_API_URL', 'http://127.0.0.1:8000/api/v1/generate-plan');
            $url = preg_replace('#/generate-plan$#', '/retrieve-knowledge', $base);
        }

        // #32: RAG-выдача по запросу стабильна (документация), но HTTP-вызов
        // добавляет до 15с к каждому стриму. Кэшируем sources по хэшу запроса.
        // Форматирование ниже не трогаем — кэш только убирает сетевой вызов.
        $cacheKey = 'rag_sources_' . md5(mb_strtolower(trim($currentMessage)) . '|8');
        $ragTtl = max(60, min(86400, (int) env('CHAT_RAG_CACHE_TTL_SECONDS', 3600)));
        $sources = null;
        try {
            require_once __DIR__ . '/../cache_config.php';
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $sources = $cached;
            }
        } catch (Throwable $e) {
            // cache недоступен — деградируем к прямому вызову
        }

        if ($sources === null) {
            $payload = json_encode(['query' => $currentMessage, 'limit' => 8]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode !== 200 || $response === false) {
                return $context;
            }
            $data = json_decode($response, true);
            $sources = $data['sources'] ?? [];
            if (!empty($sources)) {
                try {
                    Cache::set($cacheKey, $sources, $ragTtl);
                } catch (Throwable $e) {
                    // ignore — кэш необязателен
                }
            }
        }

        if (empty($sources)) {
            return $context;
        }
        $lines = ['═══ БАЗА ЗНАНИЙ (документация по бегу) ═══'];
        $lines[] = 'Используй эти выдержки при ответе. Не придумывай факты — опирайся на контекст.';
        foreach ($sources as $i => $src) {
            $chunk = trim($src['chunk'] ?? '');
            if ($chunk === '') {
                continue;
            }
            $title = isset($src['title']) && $src['title'] !== '' ? $src['title'] . ': ' : '';
            $snippet = mb_strlen($chunk) > 500 ? mb_substr($chunk, 0, 497) . '…' : $chunk;
            $lines[] = ($i + 1) . '. ' . $title . $snippet;
        }
        if (count($lines) <= 2) {
            return $context;
        }
        return $context . "\n\n" . implode("\n\n", $lines);
    }

    /**
     * Есть ли в сообщениях намерение добавить тренировку (добавь, поставь, запланируй и т.п.).
     * Проверяет текущий вопрос и последние сообщения пользователя.
     */
    public function hasAddTrainingIntent(string $currentQuestion, array $history): bool {
        $texts = [$currentQuestion];
        $n = 0;
        for ($i = count($history) - 1; $i >= 0 && $n < 3; $i--) {
            if (($history[$i]['sender_type'] ?? '') === 'user') {
                $texts[] = $history[$i]['content'] ?? '';
                $n++;
            }
        }
        $s = mb_strtolower(implode(' ', $texts));

        // Fix #5: исключаем запросы на отдых — «день отдыха», «выходной», «отменить тренировку»
        $restPhrases = ['день отдыха', 'выходной', 'отдохнуть', 'отменить', 'убрать тренировку', 'пропустить', 'не бегать', 'отмени'];
        foreach ($restPhrases as $rp) {
            if (mb_strpos($s, $rp) !== false) {
                return false;
            }
        }

        $verbs = ['добавь', 'добавить', 'поставь', 'поставить', 'запланируй', 'запланировать', 'запиши', 'записать', 'внеси', 'внести', 'установи', 'установить'];
        foreach ($verbs as $v) {
            if (mb_strpos($s, $v) !== false) {
                return true;
            }
        }
        if (preg_match('/тренировку\s+(на|в|завтра)/u', $s)) {
            return true;
        }
        return false;
    }

    /**
     * Есть ли намерение заменить тренировку на забег (полумарафон/марафон) с целью и дать тактику.
     */
    public function hasReplaceWithRaceIntent(string $currentQuestion, array $history): bool {
        $texts = [$currentQuestion];
        $n = 0;
        for ($i = count($history) - 1; $i >= 0 && $n < 3; $i--) {
            if (($history[$i]['sender_type'] ?? '') === 'user') {
                $texts[] = $history[$i]['content'] ?? '';
                $n++;
            }
        }
        $s = mb_strtolower(implode(' ', $texts));
        $racePhrases = ['полумарафон', 'марафон', 'половинку', '21.1', '42.2', '21 км', '42 км'];
        $replacePhrases = ['поменяй', 'замени', 'поставь', 'сделай', 'пробежать', 'предоставь'];
        $tacticPhrases = ['тактику', 'тактика', 'план'];
        $hasRace = false;
        foreach ($racePhrases as $p) {
            if (mb_strpos($s, $p) !== false) {
                $hasRace = true;
                break;
            }
        }
        $hasReplace = false;
        foreach ($replacePhrases as $p) {
            if (mb_strpos($s, $p) !== false) {
                $hasReplace = true;
                break;
            }
        }
        $hasTactic = false;
        foreach ($tacticPhrases as $p) {
            if (mb_strpos($s, $p) !== false) {
                $hasTactic = true;
                break;
            }
        }
        return $hasRace && ($hasReplace || $hasTactic);
    }

    /**
     * Разрешает дату из сообщения пользователя («завтра», «в среду» и т.п.).
     * @return string|null Y-m-d или null
     */
    public function resolveDateFromUserMessage(string $text, DateTime $now): ?string {
        $resolver = new DateResolver();
        if (!$resolver->hasDateReference($text)) {
            return null;
        }
        $today = clone $now;
        $today->setTime(0, 0, 0);
        return $resolver->resolveFromText($text, $today);
    }

    /**
     * If the user message contains date references (вчера, завтра, в среду...),
     * append a system hint with the resolved Y-m-d so the model doesn't have to compute it.
     */
    private function enrichQuestionWithDates(string $question, DateTime $now): string {
        $resolver = new DateResolver();
        if (!$resolver->hasDateReference($question)) {
            return $question;
        }

        $today = clone $now;
        $today->setTime(0, 0, 0);
        $resolved = $resolver->resolveFromText($question, $today);
        if ($resolved === null) {
            return $question;
        }

        $daysRu = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
        $dt = new DateTime($resolved);
        $dow = $daysRu[(int) $dt->format('N')] ?? '';

        return $question . "\n[система: дата={$resolved}, {$dow}]";
    }
}
