<?php
/**
 * Сервис чата: контекст (профиль, история, поиск по чату, RAG), вызов LM Studio напрямую (localhost).
 * Без прослойки API: приложение → LM Studio. RAG — один запрос в ai за фрагментами, дальше всё в LM Studio.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';
require_once __DIR__ . '/ChatContextBuilder.php';
require_once __DIR__ . '/DateResolver.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class ChatService extends BaseService {

    private const DEFAULT_HISTORY_LIMIT = 100;
    private const DEFAULT_SUMMARIZE_THRESHOLD = 35;
    private const DEFAULT_RECENT_MESSAGES = 15;

    private $repository;
    private $contextBuilder;
    /** @var string LM Studio base URL, например http://localhost:1234/v1 */
    private $llmBaseUrl;
    /** @var string Идентификатор модели в LM Studio */
    private $llmModel;
    private $historyLimit;

    public function __construct($db) {
        parent::__construct($db);
        $this->repository = new ChatRepository($db);
        $this->contextBuilder = new ChatContextBuilder($db);
        $this->llmBaseUrl = rtrim(env('LMSTUDIO_BASE_URL', 'http://127.0.0.1:1234/v1'), '/');
        $this->llmModel = env('LMSTUDIO_CHAT_MODEL', 'openai/gpt-oss-20b');
        $this->historyLimit = (int) env('CHAT_HISTORY_MESSAGES_LIMIT', self::DEFAULT_HISTORY_LIMIT);
        if ($this->historyLimit < 1) {
            $this->historyLimit = self::DEFAULT_HISTORY_LIMIT;
        }
    }

    /**
     * При превышении порога — суммаризировать старые сообщения, сохранить в history_summary,
     * вернуть только последние N сообщений для контекста.
     */
    private function applyHistorySummarization(int $userId, int $conversationId, array &$history): void {
        if ((int) env('CHAT_SUMMARIZE_ENABLED', 1) !== 1) {
            return;
        }
        $threshold = (int) env('CHAT_SUMMARIZE_THRESHOLD', self::DEFAULT_SUMMARIZE_THRESHOLD);
        $recentCount = (int) env('CHAT_RECENT_MESSAGES', self::DEFAULT_RECENT_MESSAGES);
        if ($recentCount < 1) {
            $recentCount = self::DEFAULT_RECENT_MESSAGES;
        }
        $total = count($history);
        if ($total < $threshold) {
            return;
        }

        $olderCount = $total - $recentCount;
        if ($olderCount < 5) {
            return;
        }

        $older = array_slice($history, 0, $olderCount);
        $recent = array_slice($history, $olderCount);
        $summary = $this->summarizeOlderMessages($older, $userId);
        if ($summary !== '') {
            $this->contextBuilder->setHistorySummary($userId, $summary);
            $history = $recent;
        }
    }

    /**
     * Вызвать LM Studio для суммаризации старой части диалога.
     */
    private function summarizeOlderMessages(array $messages, int $userId): string {
        $text = '';
        foreach ($messages as $m) {
            $role = ($m['sender_type'] ?? '') === 'user' ? 'Пользователь' : 'Ассистент';
            $content = trim($m['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $text .= "{$role}: {$content}\n\n";
        }
        if (mb_strlen($text) < 200) {
            return '';
        }

        $systemPrompt = "Ты — помощник для суммаризации диалога бегуна с AI-тренером. Сжато извлеки ключевую информацию из диалога ниже. Пиши ТОЛЬКО на русском. Формат (краткие пункты):\n\n" .
            "ЦЕЛИ/ЗАБЕГИ: цели по бегу, планы на забеги, целевые времена.\n" .
            "ТРАВМЫ/ОГРАНИЧЕНИЯ: травмы, боли, ограничения по здоровью.\n" .
            "ПРИВЫЧКИ: дни бега, предпочтения по темпу, погоде, времени.\n" .
            "РЕШЕНИЯ: что добавлено в план, какие тренировки запланированы, изменения.\n" .
            "ПРОЧЕЕ: другая важная информация о пользователе.\n\n" .
            "Не повторяй общие фразы. Только конкретика. До 500 символов.";

        $payload = [
            'model' => $this->llmModel,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Суммаризируй диалог:\n\n" . mb_substr($text, 0, 12000)]
            ],
            'stream' => false,
            'max_tokens' => 800
        ];

        $url = $this->llmBaseUrl . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            Logger::warning('Chat summarization failed', ['http' => $httpCode, 'userId' => $userId]);
            return '';
        }
        $data = json_decode($response, true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');
        return $content;
    }

    /**
     * Отправить сообщение пользователя и получить ответ от AI
     * Возвращает полный ответ (без streaming) для сохранения в БД
     */
    public function sendMessageAndGetResponse(int $userId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $history = $this->repository->getMessagesAscending($conversation['id'], $this->historyLimit);

        $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);

        $this->applyHistorySummarization($userId, $conversation['id'], $history);

        $context = $this->contextBuilder->buildContextForUser($userId);
        $context = $this->appendChatSearchSnippet($context, $conversation['id'], $content);
        $context = $this->appendRagSnippet($context, $content);
        $messages = $this->buildChatMessages($userId, $context, $history, $content);

        $response = $this->callLlm($messages, $userId);

        $fullContent = $this->sanitizeResponse($response['content'] ?? '');
        $fullContent = $this->parseAndExecuteActions($fullContent, $userId, $history, $content);
        if ($fullContent !== '') {
            $this->repository->addMessage($conversation['id'], 'ai', null, $fullContent, [
                'model' => $this->llmModel,
                'eval_count' => $response['usage']['total_tokens'] ?? null
            ]);
            $this->repository->touchConversation($conversation['id']);
        }

        return [
            'content' => $fullContent,
            'message_id' => $this->db->insert_id ?? null
        ];
    }

    /**
     * Вызов LM Studio с streaming — выводит NDJSON в stdout (OpenAI SSE → чанки)
     */
    public function streamResponse(int $userId, string $content): void {
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $history = $this->repository->getMessagesAscending($conversation['id'], $this->historyLimit);

        $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);

        $this->applyHistorySummarization($userId, $conversation['id'], $history);

        $context = '';
        try {
            $context = $this->contextBuilder->buildContextForUser($userId);
        } catch (Throwable $e) {
            Logger::warning('ChatContextBuilder failed, using minimal context', ['error' => $e->getMessage()]);
            $context = "═══ ПРОФИЛЬ ═══\nДанные контекста временно недоступны.";
        }
        $context = $this->appendChatSearchSnippet($context, $conversation['id'], $content);
        $context = $this->appendRagSnippet($context, $content);

        $messages = $this->buildChatMessages($userId, $context, $history, $content);

        $chunks = [];
        $this->callLlmStream($messages, function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
            echo json_encode(['chunk' => $chunk]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        });

        $fullContent = $this->sanitizeResponse(implode('', $chunks));
        $fullContent = $this->parseAndExecuteActions($fullContent, $userId, $history, $content);
        if ($fullContent !== '') {
            $this->repository->addMessage($conversation['id'], 'ai', null, $fullContent, ['model' => $this->llmModel]);
            $this->repository->touchConversation($conversation['id']);
        }
    }

    /**
     * Поиск по истории чата пользователя в БД и подстановка релевантных фрагментов в контекст.
     * Включается через CHAT_SEARCH_HISTORY=1 (по умолчанию 1). Ключевые слова — из текущего сообщения.
     */
    private function appendChatSearchSnippet(string $context, int $conversationId, string $currentMessage): string {
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
    private function appendRagSnippet(string $context, string $currentMessage): string {
        if ((int) env('CHAT_RAG_ENABLED', 0) !== 1) {
            return $context;
        }
        $url = env('RAG_RETRIEVE_URL', '');
        if ($url === '') {
            $base = env('PLANRUN_AI_API_URL', 'http://127.0.0.1:8000/api/v1/generate-plan');
            $url = preg_replace('#/generate-plan$#', '/retrieve-knowledge', $base);
        }
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
     * Собирает массив сообщений для API: универсальный system prompt + контекст пользователя.
     * LLM подстраивается под контекст и сообщение пользователя.
     */
    private function buildChatMessages(int $userId, string $context, array $history, string $currentQuestion): array {
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
        $systemContent = "Ты — PlanRun, AI-тренер по бегу. Отвечай ТОЛЬКО на русском языке. Сегодня: {$todayDow}, {$today}. Завтра: {$tomorrowDow}, {$tomorrow}.\n\n";
        $systemContent .= "ПРАВИЛА: Не выводи внутренние рассуждения, инструкции, мета-комментарии (типа «We need to», «The conversation», «The user»). Отвечай сразу по существу, как тренер пользователю. Даты в ответах пиши в русском формате: «18 февраля», «среда, 18 февраля».\n\n";
        $systemContent .= "ЯЗЫК: ЗАПРЕЩЕНО использовать английские слова в ответах. Все термины — только по-русски. Например: recuperation → пауза/восстановление, tempowork → темповая работа, practice → практиковать, especially → особенно, focus → фокус, weekly plan → недельный план. Фразы вроде «slightly faster than race pace» заменяй на «чуть быстрее гоночного темпа».\n\n";
        $systemContent .= "ТОН: Весь контекст ниже (профиль, план, статистика, база знаний, история) — держи в голове, но не вываливай без явного вопроса. Отвечай на то, что спрашивают. Если вопрос общий или приветствие — отвечай кратко (привет, чем могу помочь?). Используй контекст только когда он релевантен запросу.\n\n";
        $systemContent .= "При вопросе «какой план?», «что на неделю?», «что запланировано на следующую неделю?» — вызывай инструмент get_plan (week_number или date), чтобы получить актуальные данные из БД. Не опирайся только на контекст.\n\n";
        $systemContent .= "Ниже — контекст пользователя (ID: {$userId}): профиль, план, статистика. Используй эти данные точно — числа, даты и время переписывай как есть, без искажений.\n\n";

        if ($this->hasAddTrainingIntent($currentQuestion, $history)) {
            $systemContent .= "ДОБАВЛЕНИЕ ТРЕНИРОВКИ: Пользователь просит добавить тренировку. Уточни тип и детали, если не указано. Для даты используй get_date. При подтверждении («да», «супер», «ок», «достаточно») — ОБЯЗАТЕЛЬНО выведи блок ACTION с деталями из своего предыдущего сообщения. Без блока тренировка НЕ попадёт в календарь.\n";
            $systemContent .= "Формат ответа при подтверждении: краткий текст + на новой строке блок. description в кавычках; несколько упражнений ОФП/СБУ — с новой строки в description. Примеры:\n";
            $systemContent .= "ОФП: Понял, тренировку установил на 11 февраля.\n<!-- ACTION add_training_day date=2026-02-11 type=other description=\"Приседания — 3×10, 20 кг\nВыпрыгивания — 2×15\nПланка — 1 мин\" -->\n";
            $systemContent .= "СБУ: Понял, тренировку установил на 13 февраля.\n<!-- ACTION add_training_day date=2026-02-13 type=sbu description=\"Бег с высоким подниманием бедра — 30 м\nЗахлёст голени — 50 м\" -->\n";
            $systemContent .= "date — Y-m-d. type: easy|long|tempo|interval|fartlek|rest|other|sbu|race.\n";
            $systemContent .= "Маппинг: лёгкий→easy, темповый→tempo, длительный→long, интервалы→interval, фартлек→fartlek, соревнование→race, ОФП→other, СБУ→sbu, отдых→rest.\n";
            $systemContent .= "description — СТРОГО по формату ниже (иначе не распарсится при редактировании). Включай ВСЕ поля:\n";
            $systemContent .= "--- ПРОСТОЙ БЕГ (easy/tempo/long/race) ---\n";
            $systemContent .= "Формат: «Легкий бег: X км» или «Легкий бег: X км или ЧЧ:ММ:СС, темп M:SS» (+ опционально «, пульс 140»). Темп — M:SS (минуты:секунды), без ~ и /км. Если есть дистанция+темп — рассчитай время (6 км × 7:30 = 45 мин → или 0:45:00).\n";
            $systemContent .= "Примеры: Легкий бег: 6 км или 0:45:00, темп 7:30 | Темповый бег: 10 км | Длительный бег: 21 км\n";
            $systemContent .= "--- ИНТЕРВАЛЫ ---\n";
            $systemContent .= "Все поля: разминка (км + темп), серия (N×Mм + темп), пауза (В МЕТРАХ: 200м, 400м — НЕ в секундах!), тип паузы (трусцой|ходьбой|отдых), заминка (км + темп).\n";
            $systemContent .= "Пример: Разминка: 2 км в темпе 6:00. 8×400м в темпе 5:30, пауза 400м трусцой. Заминка: 1.5 км в темпе 6:00.\n";
            $systemContent .= "--- ФАРТЛЕК ---\n";
            $systemContent .= "Разминка, сегменты (N×Mм в темпе M:SS, восстановление Pм трусцой|ходьбой|легким бегом), заминка. Несколько сегментов — несколько блоков через точку.\n";
            $systemContent .= "Пример: Разминка: 1 км. 4×200м в темпе 4:30, восстановление 200м трусцой. 3×400м в темпе 4:00, восстановление 300м ходьбой. Заминка: 1 км.\n";
            $systemContent .= "--- ОФП ---\n";
            $systemContent .= "Каждая строка: «Название — 3×10, 20 кг». Названия могут быть из контекста/библиотеки или свои (пользователь мог назвать по-своему). Разделитель — тире «—». Несколько упражнений — каждая с новой строки.\n";
            $systemContent .= "Пример:\nПриседания — 3×10, 20 кг\nВыпрыгивания — 2×15\nПланка — 1 мин\n";
            $systemContent .= "--- СБУ ---\n";
            $systemContent .= "Каждая строка: «Название — 30 м» или «X км». Названия могут быть из контекста или свои. Несколько — с новой строки.\n";
            $systemContent .= "Пример:\nБег с высоким подниманием бедра — 30 м\nЗахлёст голени — 50 м\n";
            $systemContent .= "--- ОТДЫХ ---\n";
            $systemContent .= "type=rest, description пустой или «Отдых»\n";
            $resolvedDate = $this->resolveDateFromUserMessage($currentQuestion, $now);
            if ($resolvedDate !== null) {
                $systemContent .= "Вычисленная дата: {$resolvedDate}. Используй в date=.\n";
            }
            $systemContent .= "\n";
        }

        $systemContent .= $context;

        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemContent];

        // История диалога — отдельные сообщения user/assistant
        foreach ($history as $m) {
            $role = $m['sender_type'] === 'user' ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => trim($m['content'])];
        }
        // Текущий вопрос — добавлен в БД после getMessagesAscending, в history его нет
        $messages[] = ['role' => 'user', 'content' => $currentQuestion];

        return $messages;
    }

    /**
     * Есть ли в сообщениях намерение добавить тренировку (добавь, поставь, запланируй и т.п.).
     * Проверяет текущий вопрос и последние сообщения пользователя.
     */
    private function hasAddTrainingIntent(string $currentQuestion, array $history): bool {
        $texts = [$currentQuestion];
        $n = 0;
        for ($i = count($history) - 1; $i >= 0 && $n < 3; $i--) {
            if (($history[$i]['sender_type'] ?? '') === 'user') {
                $texts[] = $history[$i]['content'] ?? '';
                $n++;
            }
        }
        $s = mb_strtolower(implode(' ', $texts));
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
     * Разрешает дату из сообщения пользователя («завтра», «в среду» и т.п.).
     * @return string|null Y-m-d или null
     */
    private function resolveDateFromUserMessage(string $text, DateTime $now): ?string {
        $resolver = new DateResolver();
        if (!$resolver->hasDateReference($text)) {
            return null;
        }
        $today = clone $now;
        $today->setTime(0, 0, 0);
        return $resolver->resolveFromText($text, $today);
    }

    /**
     * Убирает утечку reasoning и английские префиксы из ответа LLM.
     */
    private function sanitizeResponse(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        // Убрать мусор в начале: точки, тире, многоточие, >, пробелы
        $text = preg_replace('/^[-.\s>…]+/u', '', $text);
        // Служебные токены модели gpt-oss: <|channel|>, <|constrain|>, <|message|>
        $text = preg_replace('/<\|[a-z_]+\|>/', '', $text);
        $text = preg_replace('/\bcommentary\s+to=commentary\b/iu', '', $text);
        $text = trim($text);
        // Паттерны reasoning-утечки (англ.)
        $leakPrefixes = [
            '/^We\s+need\s+to\s+(output|provide|give|write)\s+[^.]*\.?##\s*/iu',
            '/^We\s+need\s+to\s+/iu',
            '/^The\s+conversation\s+/iu',
            '/^The\s+user\s+(asks|wants|is)\s+/iu',
            '/^Let\s+me\s+(think|analyze|check)\s+/iu',
            '/^First,?\s+/iu',
            '/^I\'ll\s+(start|begin|provide)\s+/iu',
            '/^I\s+should\s+/iu',
            '/^Here\'s?\s+(my|the)\s+/iu',
            '/^\[.*?\]\s*/u',
            '/^Output\s+(the\s+)?(plan|response)\s+[^.#]*\.?##\s*/iu',
        ];
        foreach ($leakPrefixes as $re) {
            $prev = $text;
            $text = preg_replace($re, '', $text);
            if ($text !== $prev) {
                $text = trim($text);
            }
        }
        // Если текст начинается с английского мусора и дальше есть кириллица — обрезаем префикс
        if (preg_match('/[\p{Cyrillic}]/u', $text)) {
            $firstCyr = null;
            $len = mb_strlen($text);
            for ($i = 0; $i < $len; $i++) {
                if (preg_match('/[\p{Cyrillic}]/u', mb_substr($text, $i, 1))) {
                    $firstCyr = $i;
                    break;
                }
            }
            if ($firstCyr !== null && $firstCyr > 0) {
                $before = mb_substr($text, 0, $firstCyr);
                if (preg_match('/^[\s\p{P}A-Za-z0-9]+$/u', $before) && mb_strlen($before) < 150) {
                    $text = mb_substr($text, $firstCyr);
                }
            }
        }
        // Артефакты: латинская t вместо кириллической т в сокращениях дней
        $text = preg_replace('/\bBt\b/u', 'Вт', $text);
        $text = preg_replace('/\bПt\b/u', 'Пт', $text);
        // Частые английские термины → русские
        $terms = [
            'recovery' => 'восстановление',
            'recuperation' => 'пауза',
            'Weekly volume' => 'Недельный объём',
            'weekly plan' => 'недельный план',
            'tempo run' => 'темповая тренировка',
            'tempowork' => 'темповая работа',
            'long run' => 'длинная пробежка',
            'especially' => 'особенно',
            'practice' => 'практиковать',
            'Focus' => 'Фокус',
            'focus' => 'фокус',
            'slightly faster than race pace' => 'чуть быстрее гоночного темпа',
            'tipo' => 'типа',
        ];
        foreach ($terms as $en => $ru) {
            $text = preg_replace('/\b' . preg_quote($en, '/') . '\b/iu', $ru, $text);
        }
        return trim($text);
    }

    /**
     * Парсит action-блок из ответа, выполняет действия, возвращает текст без блока.
     * Если блок не найден — fallback: при подтверждении пользователя парсим последнее сообщение AI с предложением.
     */
    private function parseAndExecuteActions(string $text, int $userId, array $history = [], ?string $currentUserMessage = null): string {
        $params = null;
        $replaceRegex = null;

        // Формат 1: <!-- ACTION add_training_day date=... type=... description="..." --> (многострочный и без кавычек тоже)
        $actionRegex = '/\s*<!--\s*ACTION\s+add_training_day\s+([\s\S]+?)\s*-->\s*/';
        if (preg_match($actionRegex, $text, $m)) {
            $attrs = trim($m[1]);
            $params = [];
            if (preg_match('/date=([^\s"\']+)/', $attrs, $dm)) {
                $params['date'] = trim($dm[1]);
            }
            if (preg_match('/type=([^\s"\']+)/', $attrs, $tm)) {
                $params['type'] = trim($tm[1]);
            }
            // description: в кавычках или без (часто последний параметр — всё до конца attrs)
            if (preg_match('/description=("([^"]*)"|\'([^\']*)\')/', $attrs, $desc)) {
                $params['description'] = trim($desc[2] ?? $desc[3] ?? '', '"\'');
            } elseif (preg_match('/description=([\s\S]+)/', $attrs, $desc)) {
                $params['description'] = trim($desc[1], '"\' ');
            }
            $replaceRegex = $actionRegex;
        }

        // Формат 2: JSON {"action":"add_training_day","date":"...","type":"...","description":"..."} (gpt-oss и др.)
        $jsonPattern = '/\{\s*"action"\s*:\s*"add_training_day"\s*,\s*"date"\s*:\s*"([^"]+)"\s*,\s*"type"\s*:\s*"([^"]+)"\s*(?:,\s*"description"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*)?\}/';
        if ($params === null && preg_match($jsonPattern, $text, $jm)) {
            $params = [
                'date' => $jm[1],
                'type' => $jm[2],
                'description' => isset($jm[3]) ? $jm[3] : '',
            ];
            $replaceRegex = $jsonPattern;
        }

        if ($params === null) {
            if ($currentUserMessage !== null && $this->isConfirmationMessage($currentUserMessage)) {
                $fallbackParams = $this->tryExtractFromLastProposal($history, $userId);
                if ($fallbackParams !== null) {
                    $params = $fallbackParams;
                }
            }
        }
        if ($params === null || empty($params['date']) || empty($params['type'])) {
            return $text;
        }
        $validTypes = ['rest', 'easy', 'long', 'tempo', 'interval', 'fartlek', 'marathon', 'control', 'race', 'other', 'free', 'sbu'];
        if (!in_array($params['type'], $validTypes)) {
            return trim(preg_replace($replaceRegex, '', $text));
        }
        $dateObj = DateTime::createFromFormat('Y-m-d', $params['date']);
        if (!$dateObj) {
            return trim(preg_replace($replaceRegex, '', $text));
        }
        $maxDate = (new DateTime())->modify('+1 year')->format('Y-m-d');
        if ($params['date'] > $maxDate) {
            return trim(preg_replace($replaceRegex, '', $text));
        }
        try {
            require_once __DIR__ . '/WeekService.php';
            $weekService = new WeekService($this->db);
            $weekService->addTrainingDayByDate($params, $userId);
            if ($replaceRegex === null) {
                Logger::info('Chat add_training_day fallback: workout added', ['date' => $params['date'] ?? null, 'type' => $params['type'] ?? null]);
            }
        } catch (Throwable $e) {
            Logger::warning('Chat action add_training_day failed', ['error' => $e->getMessage(), 'params' => $params]);
        }
        return $replaceRegex !== null ? trim(preg_replace($replaceRegex, '', $text)) : $text;
    }

    private function isConfirmationMessage(string $text): bool {
        $s = mb_strtolower(trim($text));
        $short = preg_replace('/[\s\p{P}]+/u', '', $s);
        return in_array($short, ['да', 'давай', 'ок', 'окей', 'супер', 'хорошо', 'отлично', 'го', 'погнали', 'достаточно', 'этогодостаточно'])
            || preg_match('/^(да|давай|ок|супер|отлично|хорошо)[\s\p{P}]*$/ui', $s)
            || preg_match('/^(этого\s+)?достаточно\??$/ui', $s);
    }

    /**
     * Fallback: извлечь date/type/description из последнего сообщения AI с предложением тренировки.
     * Формат: «уточню детали для **ОФП на пятницу, 13 февраля**» и «Детали: Приседания — 3×10 (20 кг)...»
     */
    private function tryExtractFromLastProposal(array $history, int $userId): ?array {
        $lastAi = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['sender_type'] ?? '') === 'assistant') {
                $lastAi = trim($history[$i]['content'] ?? '');
                break;
            }
        }
        if ($lastAi === '' || mb_strlen($lastAi) < 20) {
            return null;
        }
        $params = [];
        if (preg_match('/для\s+\*\*[^*]*на\s+([^*]+)\*\*/ui', $lastAi, $dm)
            || preg_match('/(\d{1,2}\s+(?:января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря))/ui', $lastAi, $dm)) {
            $resolver = new DateResolver();
            $today = new DateTime('now', new DateTimeZone(getUserTimezone($userId)));
            $dateStr = $resolver->resolveFromText(trim($dm[1]), $today);
            if ($dateStr) {
                $params['date'] = $dateStr;
            }
        }
        if (preg_match('/\*\*Тип\*\*:\s*(\w+)/ui', $lastAi, $tm)) {
            $t = mb_strtolower(trim($tm[1]));
            $map = ['офп' => 'other', 'сбу' => 'sbu', 'интервалы' => 'interval', 'интервал' => 'interval', 'фартлек' => 'fartlek', 'лёгкий' => 'easy', 'темповый' => 'tempo', 'длительный' => 'long', 'соревнование' => 'race', 'отдых' => 'rest'];
            $params['type'] = $map[$t] ?? $t;
        } elseif (preg_match('/для\s+\*\*(ОФП|СБУ|интервалы?|фартлек|лёгкий|темповый|длительный)/ui', $lastAi, $tm)) {
            $t = mb_strtolower(trim($tm[1]));
            $map = ['офп' => 'other', 'сбу' => 'sbu', 'интервалы' => 'interval', 'интервал' => 'interval', 'фартлек' => 'fartlek', 'лёгкий' => 'easy', 'темповый' => 'tempo', 'длительный' => 'long'];
            $params['type'] = $map[$t] ?? 'other';
        }
        if (preg_match('/\*\*Детали\*\*:\s*([^\n]+(?:\n(?!\s*[-*]|\s*Если)[^\n]*)*)/ui', $lastAi, $dd)) {
            $details = trim($dd[1]);
            $details = preg_replace('/\s*\((\d+)\s*кг\)/u', ', $1 кг', $details);
            $lines = preg_split('/,\s+(?=[А-ЯЁ])/u', $details, -1, PREG_SPLIT_NO_EMPTY);
            $params['description'] = implode("\n", array_map('trim', $lines));
        }
        $validTypes = ['rest', 'easy', 'long', 'tempo', 'interval', 'fartlek', 'marathon', 'control', 'race', 'other', 'free', 'sbu'];
        if (empty($params['date']) || empty($params['type']) || !in_array($params['type'], $validTypes)) {
            return null;
        }
        Logger::info('Chat add_training_day fallback: extracted from proposal', ['params' => $params]);
        return $params;
    }

    /**
     * Вызов LLM: при CHAT_USE_PLANRUN_AI=1 — через PlanRun AI, иначе — напрямую LM Studio с fallback.
     * Для LM Studio: tools (get_date) + цикл обработки tool_calls.
     */
    private function callLlm(array $messages, ?int $userId = null): array {
        if ((int) env('CHAT_USE_PLANRUN_AI', 0) === 1) {
            return $this->callPlanRunAIChat($messages);
        }
        $tools = ((int) env('CHAT_TOOLS_ENABLED', 1) === 1) ? $this->getChatTools() : null;
        $maxToolRounds = 5;
        $totalUsage = [];

        try {
            for ($round = 0; $round <= $maxToolRounds; $round++) {
                $useTools = ($round === 0) ? $tools : null;
                $result = $this->callLlmDirect($messages, $useTools);
                $msg = $result['message'] ?? null;
                if (isset($result['usage']) && !empty($result['usage'])) {
                    $totalUsage = $result['usage'];
                }
                $toolCalls = $msg['tool_calls'] ?? [];
                if (empty($toolCalls)) {
                    return [
                        'content' => $msg['content'] ?? '',
                        'usage' => $totalUsage
                    ];
                }
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $msg['content'] ?? '',
                    'tool_calls' => $toolCalls
                ];
                foreach ($toolCalls as $tc) {
                    $id = $tc['id'] ?? '';
                    $fn = $tc['function'] ?? [];
                    $name = $fn['name'] ?? '';
                    $argsJson = $fn['arguments'] ?? '{}';
                    $output = $this->executeTool($name, $argsJson, $userId);
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $id,
                        'content' => $output
                    ];
                }
            }
            return [
                'content' => $msg['content'] ?? '',
                'usage' => $totalUsage
            ];
        } catch (Throwable $e) {
            if ((int) env('CHAT_FALLBACK_TO_PLANRUN_AI', 0) === 1) {
                Logger::warning('LM Studio failed, using PlanRun AI fallback', ['error' => $e->getMessage()]);
                return $this->callPlanRunAIChat($messages);
            }
            throw $e;
        }
    }

    private function getChatTools(): array {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_date',
                    'description' => 'Преобразовать фразу о дате на русском (завтра, в среду, следующая пятница, через неделю, 15 февраля) в дату Y-m-d. Используй для date= в add_training_day.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'Фраза о дате: завтра, послезавтра, в понедельник, следующая среда, через 3 дня, 15 февраля'
                            ]
                        ],
                        'required' => ['phrase']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_plan',
                    'description' => 'Получить актуальный план тренировок на неделю из БД. Вызывай при вопросе «какой план?», «что на следующую неделю?», «что запланировано?» и т.п. Возвращает фактические данные из календаря.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'week_number' => [
                                'type' => 'integer',
                                'description' => 'Номер недели (например 13 для 13-й недели)'
                            ],
                            'date' => [
                                'type' => 'string',
                                'description' => 'Дата Y-m-d — план на неделю, содержащую эту дату (например 2026-02-18)'
                            ]
                        ],
                        'required' => []
                    ]
                ]
            ]
        ];
    }

    private function executeTool(string $name, string $argsJson, ?int $userId): string {
        $args = json_decode($argsJson, true) ?? [];
        if ($name === 'get_date') {
            $phrase = $args['phrase'] ?? '';
            if ($phrase === '') {
                return json_encode(['date' => null, 'error' => 'empty_phrase']);
            }
            $tzName = $userId ? getUserTimezone($userId) : 'Europe/Moscow';
            try {
                $tz = new DateTimeZone($tzName);
            } catch (Exception $e) {
                $tz = new DateTimeZone('Europe/Moscow');
            }
            $now = new DateTime('now', $tz);
            $today = clone $now;
            $today->setTime(0, 0, 0);
            $resolver = new DateResolver();
            $date = $resolver->resolveFromText($phrase, $today);
            return json_encode(['date' => $date]);
        }
        if ($name === 'get_plan') {
            return $this->executeGetPlan($args, $userId);
        }
        return json_encode(['error' => 'unknown_tool']);
    }

    private function executeGetPlan(array $args, ?int $userId): string {
        if (!$userId) {
            return json_encode(['error' => 'user_required']);
        }
        require_once __DIR__ . '/../repositories/WeekRepository.php';
        $repo = new WeekRepository($this->db);
        $week = null;
        if (!empty($args['week_number'])) {
            $week = $repo->getWeekByWeekNumber($userId, (int) $args['week_number']);
        } elseif (!empty($args['date'])) {
            $week = $repo->getWeekByDate($userId, $args['date']);
        }
        if (!$week) {
            return json_encode(['error' => 'week_not_found', 'message' => 'Неделя не найдена в плане']);
        }
        $weekId = (int) $week['id'];
        $weekNumber = (int) $week['week_number'];
        $startDate = $week['start_date'] ?? null;
        $days = $repo->getDaysByWeekId($userId, $weekId);
        $dayLabels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $typeRu = [
            'easy' => 'Легкий бег', 'long' => 'Длительный', 'tempo' => 'Темповый',
            'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'rest' => 'Отдых',
            'other' => 'ОФП', 'sbu' => 'СБУ', 'race' => 'Забег', 'free' => 'Пустой'
        ];
        $byDay = [];
        foreach ($days as $d) {
            $dow = (int) $d['day_of_week'];
            $label = $dayLabels[$dow] ?? (string) $dow;
            $type = $d['type'] ?? 'rest';
            $desc = trim($d['description'] ?? '');
            $byDay[] = [
                'day' => $label,
                'date' => $d['date'] ?? null,
                'type' => $typeRu[$type] ?? $type,
                'description' => $desc
            ];
        }
        return json_encode([
            'week_number' => $weekNumber,
            'start_date' => $startDate,
            'days' => $byDay,
            'total_volume' => $week['total_volume'] ?? null
        ]);
    }

    /**
     * Вызов LM Studio напрямую. tools — опционально (get_date и др.)
     */
    private function callLlmDirect(array $messages, ?array $tools = null): array {
        $url = $this->llmBaseUrl . '/chat/completions';
        $maxTokens = (int) env('CHAT_MAX_TOKENS', 87000);
        if ($maxTokens < 1) {
            $maxTokens = 87000;
        }
        $payload = [
            'model' => $this->llmModel,
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => $maxTokens
        ];
        if ($tools !== null && $tools !== []) {
            $payload['tools'] = $tools;
        }
        $body = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            Logger::error('LM Studio connection error', ['error' => $curlErr, 'url' => $url]);
            throw new Exception('LM Studio недоступен. Запустите: lms server start');
        }
        if ($httpCode !== 200 || $response === false) {
            Logger::error('LM Studio API error', ['http_code' => $httpCode, 'response' => substr($response ?? '', 0, 500)]);
            throw new Exception('LM Studio вернул ошибку ' . $httpCode . '. Проверьте, что модель загружена.');
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception('Ошибка ответа LM Studio');
        }

        $choices = $data['choices'] ?? [];
        $msg = $choices[0]['message'] ?? [];
        $content = $msg['content'] ?? '';

        return [
            'content' => $content,
            'message' => $msg,
            'usage' => $data['usage'] ?? []
        ];
    }

    /**
     * Fallback: вызов PlanRun AI /api/v1/chat (тоже идёт в LM Studio, но через ai-сервис)
     */
    private function callPlanRunAIChat(array $messages): array {
        $base = env('PLANRUN_AI_API_URL', 'http://127.0.0.1:8000/api/v1/generate-plan');
        $url = preg_replace('#/generate-plan$#', '/chat', $base);
        $maxTokens = (int) env('CHAT_MAX_TOKENS', 87000);
        if ($maxTokens < 1) {
            $maxTokens = 87000;
        }
        $payload = [
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => $maxTokens
        ];
        $body = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200 || $response === false) {
            throw new Exception('PlanRun AI недоступен. Запустите: systemctl start planrun-ai');
        }
        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception('Ошибка ответа PlanRun AI');
        }
        return [
            'content' => $data['content'] ?? '',
            'usage' => $data['usage'] ?? []
        ];
    }

    /**
     * Стриминг: при CHAT_USE_PLANRUN_AI=1 — через PlanRun AI, иначе LM Studio с fallback
     */
    private function callLlmStream(array $messages, callable $onChunk): void {
        if ((int) env('CHAT_USE_PLANRUN_AI', 0) === 1) {
            $this->callPlanRunAIChatStream($messages, $onChunk);
            return;
        }
        try {
            $this->callLlmStreamDirect($messages, $onChunk);
        } catch (Throwable $e) {
            if ((int) env('CHAT_FALLBACK_TO_PLANRUN_AI', 0) === 1) {
                Logger::warning('LM Studio stream failed, using PlanRun AI fallback', ['error' => $e->getMessage()]);
                $this->callPlanRunAIChatStream($messages, $onChunk);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Стриминг: LM Studio POST /v1/chat/completions stream=true
     */
    private function callLlmStreamDirect(array $messages, callable $onChunk): void {
        $url = $this->llmBaseUrl . '/chat/completions';
        $maxTokens = (int) env('CHAT_MAX_TOKENS', 87000);
        if ($maxTokens < 1) {
            $maxTokens = 87000;
        }
        $payload = [
            'model' => $this->llmModel,
            'messages' => $messages,
            'stream' => true,
            'max_tokens' => $maxTokens
        ];
        $body = json_encode($payload);

        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 180,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk, &$buffer) {
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, 'data: ') !== 0) {
                        continue;
                    }
                    $json = substr($line, 5);
                    if ($json === '[DONE]') {
                        continue;
                    }
                    $decoded = json_decode($json, true);
                    if (!$decoded || empty($decoded['choices'][0])) {
                        continue;
                    }
                    $delta = $decoded['choices'][0]['delta'] ?? [];
                    $content = $delta['content'] ?? '';
                    if ($content !== '') {
                        $onChunk($content);
                    }
                }
                return strlen($data);
            }
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            Logger::error('LM Studio stream error', ['error' => $curlErr, 'url' => $url]);
            throw new Exception('Не удалось подключиться к LM Studio. Запустите: lms server start');
        }
        if ($httpCode !== 200) {
            Logger::error('LM Studio stream API error', ['http_code' => $httpCode, 'url' => $url]);
            throw new Exception('LM Studio вернул ошибку ' . $httpCode . '.');
        }
    }

    /**
     * Fallback stream: PlanRun AI /api/v1/chat (NDJSON {chunk})
     */
    private function callPlanRunAIChatStream(array $messages, callable $onChunk): void {
        $base = env('PLANRUN_AI_API_URL', 'http://127.0.0.1:8000/api/v1/generate-plan');
        $url = preg_replace('#/generate-plan$#', '/chat', $base);
        $maxTokens = (int) env('CHAT_MAX_TOKENS', 87000);
        if ($maxTokens < 1) {
            $maxTokens = 87000;
        }
        $payload = [
            'messages' => $messages,
            'stream' => true,
            'max_tokens' => $maxTokens
        ];
        $body = json_encode($payload);

        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 180,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk, &$buffer) {
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $decoded = json_decode($line, true);
                    if ($decoded && isset($decoded['chunk']) && $decoded['chunk'] !== '') {
                        $onChunk($decoded['chunk']);
                    }
                }
                return strlen($data);
            }
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            throw new Exception('PlanRun AI недоступен. Запустите: systemctl start planrun-ai');
        }
    }

    /**
     * Получить сообщения разговора
     */
    public function getMessages(int $userId, string $type = 'ai', int $limit = 50, int $offset = 0): array {
        $conversation = $this->repository->getOrCreateConversation($userId, $type);
        $messages = $this->repository->getMessages($conversation['id'], $limit, $offset);
        return [
            'conversation_id' => $conversation['id'],
            'messages' => $messages
        ];
    }

    /**
     * Очистить чат с AI — удалить все сообщения из диалога и суммаризацию истории
     */
    public function clearAiChat(int $userId): void {
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $this->repository->deleteMessagesByConversation($conversation['id']);
        $this->contextBuilder->setHistorySummary($userId, '');
    }

    /**
     * Отметить сообщения как прочитанные (admin)
     */
    public function markAsRead(int $userId, int $conversationId): void {
        $conv = $this->repository->getConversationById($conversationId, $userId);
        if ($conv) {
            $this->repository->markMessagesRead($conversationId);
        }
    }

    /**
     * Пользователь: отправить сообщение администрации
     * Сообщение добавляется в admin-чат пользователя (админы увидят в админ-панели)
     */
    public function sendUserMessageToAdmin(int $userId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($userId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'user', $userId, $content);
        $this->repository->touchConversation($conversation['id']);
        return [
            'conversation_id' => $conversation['id'],
            'message_id' => $messageId
        ];
    }

    /**
     * Админ: отправить сообщение пользователю
     */
    public function sendAdminMessage(int $targetUserId, int $adminUserId, string $content): array {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'admin', $adminUserId, $content);
        $this->repository->touchConversation($conversation['id']);
        return [
            'conversation_id' => $conversation['id'],
            'message_id' => $messageId
        ];
    }

    /**
     * Админ: получить сообщения пользователя (admin-чат)
     * При запросе сообщений помечает входящие от пользователя как прочитанные
     */
    public function getAdminMessages(int $targetUserId, int $limit = 50, int $offset = 0): array {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $this->repository->markUserMessagesReadByAdmin($conversation['id']);
        return $this->getMessages($targetUserId, 'admin', $limit, $offset);
    }

    /**
     * Админ: список пользователей, которые писали в admin-чат
     */
    public function getUsersWithAdminChat(): array {
        return $this->repository->getUsersWithAdminChat();
    }

    /**
     * Админ: непрочитанные сообщения от пользователей (для уведомлений)
     */
    public function getUnreadUserMessagesForAdmin(int $limit = 10): array {
        return $this->repository->getUnreadUserMessagesForAdmin($limit);
    }

    /**
     * Админ: счётчик непрочитанных сообщений от пользователей
     */
    public function getAdminUnreadCount(): int {
        return $this->repository->getAdminUnreadCount();
    }

    /**
     * Отметить все сообщения как прочитанные (для пользователя — во всех его чатах)
     */
    public function markAllAsRead(int $userId): void {
        $this->repository->markAllConversationsReadForUser($userId);
    }

    /**
     * Админ: отметить все сообщения от пользователей как прочитанные
     */
    public function markAllAdminAsRead(): void {
        $this->repository->markAllAdminUserMessagesRead();
    }

    /**
     * Админ: отметить сообщения конкретного пользователя как прочитанные (при открытии чата с ним)
     */
    public function markAdminConversationRead(int $targetUserId): void {
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $this->repository->markUserMessagesReadByAdmin($conversation['id']);
    }

    /**
     * Добавить сообщение от AI в чат пользователя (без ответа пользователя).
     * Используется для «досыла» сообщений, напоминаний, рассылок от AI.
     * Вызывается админом или AI-сервисом.
     *
     * @return array ['message_id' => int]
     */
    public function addAIMessageToUser(int $userId, string $content): array {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('Сообщение не может быть пустым');
        }
        if (mb_strlen($content) > 4000) {
            throw new InvalidArgumentException('Сообщение слишком длинное');
        }
        $conversation = $this->repository->getOrCreateConversation($userId, 'ai');
        $messageId = $this->repository->addMessage($conversation['id'], 'ai', null, $content);
        $this->repository->touchConversation($conversation['id']);
        return ['message_id' => $messageId];
    }

    /**
     * Админ: массовая рассылка сообщения пользователям
     * @param int $adminUserId ID админа (отправителя)
     * @param string $content Текст сообщения
     * @param array|null $userIds Список ID получателей или null = всем пользователям (кроме админа)
     * @return array ['sent' => N]
     */
    public function broadcastAdminMessage(int $adminUserId, string $content, ?array $userIds = null): array {
        if ($userIds === null) {
            $userIds = $this->repository->getAllUserIdsForBroadcast($adminUserId);
        }
        $userIds = array_map('intval', array_filter($userIds, fn($id) => $id > 0 && $id !== $adminUserId));
        $sent = 0;
        foreach ($userIds as $targetUserId) {
            $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
            $this->repository->addMessage($conversation['id'], 'admin', $adminUserId, $content);
            $this->repository->touchConversation($conversation['id']);
            $sent++;
        }
        return ['sent' => $sent];
    }
}
