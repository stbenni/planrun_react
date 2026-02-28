<?php
/**
 * Сервис чата: контекст (профиль, история, поиск по чату, RAG), вызов LM Studio напрямую (localhost).
 * Без прослойки API: приложение → LM Studio. RAG — один запрос в ai за фрагментами, дальше всё в LM Studio.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';
require_once __DIR__ . '/ChatContextBuilder.php';
require_once __DIR__ . '/DateResolver.php';
require_once __DIR__ . '/PushNotificationService.php';
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
            if (connection_aborted()) {
                $this->sendChatPush($userId, 'Новое сообщение от AI-тренера', $fullContent, 'chat');
            }
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

        $toolsUsed = [];
        $messages = $this->resolveToolCalls($messages, $userId, $toolsUsed);

        if (in_array('update_training_day', $toolsUsed, true)) {
            echo json_encode(['plan_updated' => true]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
        if (in_array('recalculate_plan', $toolsUsed, true)) {
            echo json_encode(['plan_recalculating' => true]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
        if (in_array('generate_next_plan', $toolsUsed, true)) {
            echo json_encode(['plan_generating_next' => true]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        }

        $chunks = [];
        $thinkBuffer = '';
        $insideThink = false;
        $this->callLlmStream($messages, function ($chunk) use (&$chunks, &$thinkBuffer, &$insideThink) {
            $thinkBuffer .= $chunk;

            if ($insideThink) {
                if (stripos($thinkBuffer, '[/THINK]') !== false) {
                    $parts = preg_split('/\[\/THINK\]\s*/i', $thinkBuffer, 2);
                    $thinkBuffer = '';
                    $insideThink = false;
                    $after = $parts[1] ?? '';
                    if ($after !== '') {
                        $chunks[] = $after;
                        echo json_encode(['chunk' => $after]) . "\n";
                        if (ob_get_level()) ob_flush();
                        flush();
                    }
                } elseif (stripos($thinkBuffer, '</think>') !== false) {
                    $parts = preg_split('/<\/think>\s*/i', $thinkBuffer, 2);
                    $thinkBuffer = '';
                    $insideThink = false;
                    $after = $parts[1] ?? '';
                    if ($after !== '') {
                        $chunks[] = $after;
                        echo json_encode(['chunk' => $after]) . "\n";
                        if (ob_get_level()) ob_flush();
                        flush();
                    }
                }
                return;
            }

            if (preg_match('/\[THINK\]/i', $thinkBuffer) || preg_match('/<think>/i', $thinkBuffer)) {
                $insideThink = true;
                $thinkBuffer = preg_replace('/^.*(\[THINK\]|<think>)/is', '', $thinkBuffer);
                return;
            }

            $out = $thinkBuffer;
            $thinkBuffer = '';
            $chunks[] = $out;
            echo json_encode(['chunk' => $out]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        });

        $fullContent = $this->sanitizeResponse(implode('', $chunks));
        $planWasUpdated = false;
        $fullContent = $this->parseAndExecuteActions($fullContent, $userId, $history, $content, $planWasUpdated);
        if ($planWasUpdated) {
            echo json_encode(['plan_updated' => true]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
        if ($fullContent !== '') {
            $this->repository->addMessage($conversation['id'], 'ai', null, $fullContent, ['model' => $this->llmModel]);
            $this->repository->touchConversation($conversation['id']);
            if (connection_aborted()) {
                $this->sendChatPush($userId, 'Новое сообщение от AI-тренера', $fullContent, 'chat');
            }
        }
    }

    /**
     * Резолвит tool-вызовы (до maxToolRounds раундов) перед стримингом.
     * Если LLM хочет вызвать tools — выполняем их (non-streaming), добавляем результаты
     * в messages, и повторяем. Когда tool_calls пусты — messages готовы для стриминга.
     */
    private function resolveToolCalls(array $messages, int $userId, array &$toolsUsed = []): array {
        $toolsEnabled = (int) env('CHAT_TOOLS_ENABLED', 1) === 1;
        if (!$toolsEnabled) {
            return $messages;
        }

        $tools = $this->getChatTools();
        $maxToolRounds = 5;

        for ($round = 0; $round < $maxToolRounds; $round++) {
            try {
                $useTools = ($round === 0) ? $tools : null;
                $result = $this->callLlmDirect($messages, $useTools);
            } catch (Throwable $e) {
                Logger::warning('Tool resolution failed, streaming without tools', ['error' => $e->getMessage()]);
                return $messages;
            }

            $msg = $result['message'] ?? null;
            $toolCalls = $msg['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                return $messages;
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
                $toolsUsed[] = $name;
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'content' => $output
                ];
            }
        }

        return $messages;
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
        $systemContent = <<<PROMPT
Ты — PlanRun, персональный тренер по бегу. Сегодня: {$todayDow}, {$today}. Завтра: {$tomorrowDow}, {$tomorrow}.

ПЕРСОНА:
Ты — опытный тренер по бегу с 15-летним стажем. Ты работаешь с бегунами любого уровня — от начинающих до марафонцев. Твой стиль:
- Дружелюбный, но профессиональный. Как тренер, который действительно заботится о подопечном.
- Краткий: не перегружай информацией. 2-4 предложения на простой вопрос, развёрнуто — только когда просят.
- Конкретный: вместо «бегай побольше» — «добавь 1 км к длительной в воскресенье».
- Эмпатичный: если пользователь устал/травмирован — прояви понимание, предложи помощь, не дави.
- Мотивирующий: отмечай прогресс, хвали за выполнение плана, поддерживай при неудачах.
- Адаптивный: подстраивай тон. Перед забегом — собранность и поддержка. После тяжёлой тренировки — забота о восстановлении. При пропуске — без укоров, мягко помоги вернуться.

ЯЗЫК:
- Отвечай ТОЛЬКО на русском. Никаких английских слов (recuperation → восстановление, pace → темп, long run → длительный бег, cooldown → заминка).
- Даты — в русском формате: «18 февраля», «среда, 18 февраля».
- Не выводи внутренние рассуждения, мета-комментарии, англоязычный reasoning.

ПОВЕДЕНИЕ ТРЕНЕРА:
- Если пользователь здоровается → коротко поприветствуй, спроси как дела / как прошла тренировка (если есть данные о недавней).
- Если спрашивает про план → посмотри get_plan, дай конкретный ответ.
- Если жалуется на усталость/боль/травму:
  1. Прояви эмпатию («Понимаю, давай разберёмся»).
  2. Уточни детали если нужно.
  3. ПРЕДЛОЖИ конкретную корректировку (заменить тяжёлую на лёгкую, добавить отдых).
  4. Получи подтверждение → update_training_day.
- Если пропустил тренировки → не укоряй. Скажи «Ничего страшного, давай посмотрим как лучше продолжить».
- Если показал хороший результат → похвали конкретно: «Отличный темп 5:30 на интервалах — это прогресс по сравнению с прошлой неделей!»
- Не вываливай весь контекст — используй его точечно, когда релевантно.

ИНСТРУМЕНТЫ (tools):
Используй их ПРОАКТИВНО — не гадай и не выдумывай цифры:
1. get_plan(week_number или date) — план на неделю.
2. get_workouts(date_from, date_to) — история выполненных тренировок (дистанция, время, темп, пульс, ощущения).
3. get_day_details(date) — полные детали дня: план + упражнения + фактический результат.
4. get_date(phrase) — преобразование «завтра», «в среду» в дату Y-m-d.
5. update_training_day(date, type, description) — изменить запланированную тренировку. ОБЯЗАТЕЛЬНО спроси подтверждение перед изменением.
6. recalculate_plan() — пересчитать весь план с учётом истории, пропусков и текущей формы. Запускает фоновый процесс (3-5 мин). ОБЯЗАТЕЛЬНО спроси подтверждение.
7. generate_next_plan() — создать новый план после завершения предыдущего. Вызывай когда пользователь говорит, что план закончился или просит новый цикл. ОБЯЗАТЕЛЬНО спроси подтверждение.

СТРАТЕГИЯ:
- Вопрос о конкретной тренировке → get_day_details.
- Вопрос о периоде/прогрессе → get_workouts.
- Жалоба на самочувствие → get_day_details (ближайшая запланированная) → предложи замену.
- Длительная пауза (>7 дней) / выполнение <50% → предложи recalculate_plan.
- План закончился / «хочу новый план» → предложи generate_next_plan.
- Никогда не выдумывай цифры — если нужны данные, вызови tool.

Ниже — контекст пользователя (ID: {$userId}): профиль, план, статистика, сводка по тренировкам.
В ТРЕНИРОВКИ (кратко) — только сводка. Для деталей вызывай get_workouts или get_day_details.

PROMPT;
        $systemContent .= "\n\n";

        if ($this->hasAddTrainingIntent($currentQuestion, $history)) {
            $systemContent .= "ДОБАВЛЕНИЕ ТРЕНИРОВКИ: Пользователь просит добавить тренировку. Уточни тип и детали, если не указано. Для даты используй get_date. При подтверждении («да», «супер», «ок», «достаточно») — ОБЯЗАТЕЛЬНО выведи блок ACTION с деталями из своего предыдущего сообщения. Без блока тренировка НЕ попадёт в календарь.\n";
            $systemContent .= "Формат ответа при подтверждении: краткий текст + на новой строке блок. description в кавычках; несколько упражнений ОФП/СБУ — с новой строки в description. Примеры:\n";
            $systemContent .= "ОФП: Понял, тренировку установил на 11 февраля.\n<!-- ACTION add_training_day date=2026-02-11 type=other description=\"Приседания — 3×10, 20 кг\nВыпрыгивания — 2×15\nПланка — 1 мин\" -->\n";
            $systemContent .= "СБУ: Понял, тренировку установил на 13 февраля.\n<!-- ACTION add_training_day date=2026-02-13 type=sbu description=\"Бег с высоким подниманием бедра — 30 м\nЗахлёст голени — 50 м\" -->\n";
            $systemContent .= "date — Y-m-d. type: easy|long|tempo|interval|fartlek|rest|other|sbu|race|marathon|control|free.\n";
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
        // Reasoning-блоки моделей: [THINK]...[/THINK], <think>...</think>
        $text = preg_replace('/\[THINK\][\s\S]*?\[\/THINK\]\s*/i', '', $text);
        $text = preg_replace('/<think>[\s\S]*?<\/think>\s*/i', '', $text);
        // Незакрытый [THINK] — обрезать всё от начала до первой кириллицы после него
        $text = preg_replace('/^\[THINK\][\s\S]*?(?=[\p{Cyrillic}])/iu', '', $text);
        $text = trim($text);
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
     * @param bool $planWasUpdated Выходной: true при успешном add_training_day
     */
    private function parseAndExecuteActions(string $text, int $userId, array $history = [], ?string $currentUserMessage = null, bool &$planWasUpdated = false): string {
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
            $planWasUpdated = true;
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
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_workouts',
                    'description' => 'Получить историю выполненных тренировок за период. Вызывай при вопросах «как я бегал на прошлой неделе?», «покажи мои результаты за февраль», «какой у меня прогресс?», «сколько я пробежал?» и т.п.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date_from' => [
                                'type' => 'string',
                                'description' => 'Начало периода Y-m-d (например 2026-02-01)'
                            ],
                            'date_to' => [
                                'type' => 'string',
                                'description' => 'Конец периода Y-m-d (например 2026-02-28)'
                            ]
                        ],
                        'required' => ['date_from', 'date_to']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_day_details',
                    'description' => 'Получить полные детали конкретного дня: план (тип, описание, упражнения) + фактический результат (дистанция, время, темп, пульс, заметки). Вызывай при вопросах «что было запланировано на вторник?», «как прошла тренировка 15 февраля?», «какой результат вчера?».',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => [
                                'type' => 'string',
                                'description' => 'Дата Y-m-d (например 2026-02-18)'
                            ]
                        ],
                        'required' => ['date']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_training_day',
                    'description' => 'Изменить запланированную тренировку на конкретную дату. Используй когда пользователь просит заменить тренировку, снизить нагрузку, поставить отдых из-за травмы/усталости, или скорректировать план. ОБЯЗАТЕЛЬНО спроси подтверждение перед вызовом.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => [
                                'type' => 'string',
                                'description' => 'Дата тренировки Y-m-d'
                            ],
                            'type' => [
                                'type' => 'string',
                                'description' => 'Новый тип: easy, long, tempo, interval, fartlek, control, rest, other, sbu, race, free',
                                'enum' => ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'rest', 'other', 'sbu', 'race', 'free']
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Описание тренировки (формат как в add_training_day). Для rest — пустая строка.'
                            ]
                        ],
                        'required' => ['date', 'type']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'recalculate_plan',
                    'description' => 'Пересчитать весь план тренировок с учётом истории выполненных тренировок, пропусков и текущей формы. Вызывай когда видишь длительную паузу (>5 дней без тренировок), серьёзное отклонение от плана (выполнено <50%), или пользователь просит пересчитать. ОБЯЗАТЕЛЬНО спроси подтверждение перед вызовом.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Краткое описание причины пересчёта на основе разговора (травма, болезнь, пауза, изменение целей и т.п.)'
                            ]
                        ],
                        'required' => []
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_next_plan',
                    'description' => 'Создать новый план после завершения предыдущего. Вызывай когда пользователь говорит, что план закончился, хочет новый цикл, или просит «создай новый план». AI учтёт всю историю тренировок. ОБЯЗАТЕЛЬНО спроси подтверждение перед вызовом.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'goals' => [
                                'type' => 'string',
                                'description' => 'Пожелания к новому плану на основе разговора (подготовка к забегу, увеличение объёма и т.п.)'
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
        if ($name === 'get_workouts') {
            return $this->executeGetWorkouts($args, $userId);
        }
        if ($name === 'get_day_details') {
            return $this->executeGetDayDetails($args, $userId);
        }
        if ($name === 'update_training_day') {
            return $this->executeUpdateTrainingDay($args, $userId);
        }
        if ($name === 'recalculate_plan') {
            return $this->executeRecalculatePlan($args, $userId);
        }
        if ($name === 'generate_next_plan') {
            return $this->executeGenerateNextPlan($args, $userId);
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
        } else {
            $tzName = $userId ? getUserTimezone($userId) : 'Europe/Moscow';
            try {
                $tz = new DateTimeZone($tzName);
            } catch (Exception $e) {
                $tz = new DateTimeZone('Europe/Moscow');
            }
            $today = (new DateTime('now', $tz))->format('Y-m-d');
            $week = $repo->getWeekByDate($userId, $today);
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
            'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'control' => 'Контрольный забег',
            'rest' => 'Отдых', 'other' => 'ОФП', 'sbu' => 'СБУ', 'race' => 'Забег', 'free' => 'Пустой'
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

    private function executeGetWorkouts(array $args, ?int $userId): string {
        if (!$userId) {
            return json_encode(['error' => 'user_required']);
        }
        $dateFrom = $args['date_from'] ?? '';
        $dateTo = $args['date_to'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return json_encode(['error' => 'invalid_dates', 'message' => 'Формат дат: Y-m-d']);
        }

        $limit = 100;
        $workouts = $this->contextBuilder->getWorkoutsHistory($userId, $dateFrom, $dateTo, $limit);
        if (empty($workouts)) {
            return json_encode(['workouts' => [], 'message' => 'Нет выполненных тренировок за период']);
        }

        $typeRu = [
            'easy' => 'Легкий бег', 'long' => 'Длительный', 'tempo' => 'Темповый',
            'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'control' => 'Контрольный забег',
            'rest' => 'Отдых', 'other' => 'ОФП', 'sbu' => 'СБУ', 'race' => 'Забег'
        ];

        $formatted = [];
        $totalKm = 0;
        foreach ($workouts as $w) {
            $type = $w['plan_type'] ?? null;
            $entry = [
                'date' => $w['date'],
                'type' => $type ? ($typeRu[$type] ?? $type) : null,
                'is_key_workout' => !empty($w['is_key_workout']),
            ];
            if (!empty($w['distance_km'])) {
                $entry['distance_km'] = (float) $w['distance_km'];
                $totalKm += (float) $w['distance_km'];
            }
            if (!empty($w['result_time']) && $w['result_time'] !== '0:00:00') {
                $entry['time'] = $w['result_time'];
            }
            if (!empty($w['pace']) && $w['pace'] !== '0:00') {
                $entry['pace'] = $w['pace'];
            }
            if (!empty($w['avg_heart_rate'])) {
                $entry['avg_hr'] = (int) $w['avg_heart_rate'];
            }
            if (!empty($w['rating'])) {
                $ratingLabels = [1 => 'очень тяжело', 2 => 'тяжело', 3 => 'нормально', 4 => 'хорошо', 5 => 'отлично'];
                $entry['feeling'] = $ratingLabels[(int) $w['rating']] ?? (int) $w['rating'];
            }
            $notes = trim($w['notes'] ?? '');
            if ($notes !== '') {
                $entry['notes'] = mb_strlen($notes) > 300 ? mb_substr($notes, 0, 297) . '…' : $notes;
            }
            $formatted[] = $entry;
        }

        $result = [
            'period' => "{$dateFrom} — {$dateTo}",
            'total_workouts' => count($formatted),
            'total_km' => round($totalKm, 1),
            'workouts' => $formatted,
        ];
        if (count($formatted) >= $limit) {
            $result['note'] = 'Показаны последние ' . $limit . ' тренировок за период.';
        }
        return json_encode($result);
    }

    private function executeGetDayDetails(array $args, ?int $userId): string {
        if (!$userId) {
            return json_encode(['error' => 'user_required']);
        }
        $date = $args['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        }

        $details = $this->contextBuilder->getDayDetails($userId, $date);

        $result = ['date' => $date];

        if ($details['plan']) {
            $result['plan'] = $details['plan'];
        } else {
            $result['plan'] = null;
            $result['plan_message'] = 'На этот день нет запланированной тренировки';
        }

        if (!empty($details['exercises'])) {
            $exFormatted = [];
            foreach ($details['exercises'] as $ex) {
                $e = ['category' => $ex['category'], 'name' => $ex['name']];
                if (!empty($ex['sets'])) $e['sets'] = (int) $ex['sets'];
                if (!empty($ex['reps'])) $e['reps'] = (int) $ex['reps'];
                if (!empty($ex['distance_m'])) $e['distance_m'] = (int) $ex['distance_m'];
                if (!empty($ex['duration_sec'])) $e['duration_sec'] = (int) $ex['duration_sec'];
                if (!empty($ex['weight_kg'])) $e['weight_kg'] = (float) $ex['weight_kg'];
                if (!empty($ex['pace'])) $e['pace'] = $ex['pace'];
                $exFormatted[] = $e;
            }
            $result['exercises'] = $exFormatted;
        }

        if ($details['workout']) {
            $w = $details['workout'];
            $workout = ['completed' => true];
            if (!empty($w['distance_km'])) $workout['distance_km'] = (float) $w['distance_km'];
            if (!empty($w['result_time']) && $w['result_time'] !== '0:00:00') $workout['time'] = $w['result_time'];
            if (!empty($w['pace']) && $w['pace'] !== '0:00') $workout['pace'] = $w['pace'];
            if (!empty($w['avg_heart_rate'])) $workout['avg_hr'] = (int) $w['avg_heart_rate'];
            if (!empty($w['max_heart_rate'])) $workout['max_hr'] = (int) $w['max_heart_rate'];
            if (!empty($w['avg_cadence'])) $workout['cadence'] = (int) $w['avg_cadence'];
            if (!empty($w['elevation_gain'])) $workout['elevation_m'] = (int) $w['elevation_gain'];
            if (!empty($w['calories'])) $workout['calories'] = (int) $w['calories'];
            if (!empty($w['rating'])) {
                $ratingLabels = [1 => 'очень тяжело', 2 => 'тяжело', 3 => 'нормально', 4 => 'хорошо', 5 => 'отлично'];
                $workout['feeling'] = $ratingLabels[(int) $w['rating']] ?? (int) $w['rating'];
            }
            $notes = trim($w['notes'] ?? '');
            if ($notes !== '') {
                $workout['notes'] = mb_strlen($notes) > 500 ? mb_substr($notes, 0, 497) . '…' : $notes;
            }
            $result['workout'] = $workout;
        } else {
            $result['workout'] = null;
            $result['workout_message'] = 'Тренировка не выполнена';
        }

        return json_encode($result);
    }

    /**
     * Tool: update_training_day — изменить или заменить тренировку на конкретную дату.
     * Находит day_id по дате, обновляет тип и описание через WeekService.
     */
    private function executeUpdateTrainingDay(array $args, ?int $userId): string {
        if (!$userId) {
            return json_encode(['error' => 'user_required']);
        }
        $date = $args['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return json_encode(['error' => 'invalid_date', 'message' => 'Формат даты: Y-m-d']);
        }
        $type = $args['type'] ?? null;
        $allowed = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'rest', 'other', 'sbu', 'race', 'free'];
        if (!$type || !in_array($type, $allowed, true)) {
            return json_encode(['error' => 'invalid_type', 'message' => 'Допустимые типы: ' . implode(', ', $allowed)]);
        }

        $dayId = $this->findDayIdByDate($userId, $date);

        if (!$dayId) {
            return json_encode([
                'error' => 'no_plan_for_date',
                'message' => "На дату {$date} нет запланированной тренировки. Используй add_training_day."
            ]);
        }

        try {
            require_once __DIR__ . '/WeekService.php';
            $weekService = new WeekService($this->db);

            $data = ['type' => $type];
            if (isset($args['description'])) {
                $data['description'] = $args['description'];
            }
            if ($type === 'rest') {
                $data['description'] = $data['description'] ?? 'Отдых';
                $data['is_key_workout'] = 0;
            }

            $weekService->updateTrainingDayById($dayId, $userId, $data);

            $typeRu = [
                'easy' => 'Лёгкий бег', 'long' => 'Длительный бег', 'tempo' => 'Темповый бег',
                'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'control' => 'Контрольный забег',
                'rest' => 'Отдых', 'other' => 'Другое', 'sbu' => 'СБУ/ОФП', 'race' => 'Забег', 'free' => 'Свободная'
            ];
            $typeName = $typeRu[$type] ?? $type;

            $dt = DateTime::createFromFormat('Y-m-d', $date);
            $dateFormatted = $dt ? $dt->format('d.m.Y') : $date;

            return json_encode([
                'success' => true,
                'message' => "Тренировка на {$dateFormatted} изменена на «{$typeName}»"
            ]);
        } catch (Exception $e) {
            return json_encode([
                'error' => 'update_failed',
                'message' => 'Не удалось обновить: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Найти day_id плана по дате для заданного пользователя.
     */
    private function findDayIdByDate(int $userId, string $date): ?int {
        $stmt = $this->db->prepare(
            "SELECT d.id FROM training_plan_days d 
             JOIN training_plan_weeks w ON d.week_id = w.id 
             WHERE w.user_id = ? AND d.date = ? 
             ORDER BY d.id DESC LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('is', $userId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    private function executeRecalculatePlan(array $args, ?int $userId): string {
        if (!$userId) {
            return json_encode(['error' => 'user_required']);
        }
        try {
            $reason = !empty($args['reason']) ? trim($args['reason']) : null;
            require_once __DIR__ . '/TrainingPlanService.php';
            $planService = new TrainingPlanService($this->db);
            $result = $planService->recalculatePlan($userId, $reason);
            return json_encode([
                'success' => true,
                'message' => 'Пересчёт плана запущен. Новый план будет готов через 3-5 минут.',
                'pid' => $result['pid'] ?? null
            ]);
        } catch (Exception $e) {
            return json_encode([
                'error' => 'recalculate_failed',
                'message' => 'Не удалось запустить пересчёт: ' . $e->getMessage()
            ]);
        }
    }

    private function executeGenerateNextPlan(array $args, ?int $userId): string {
        if (!$userId) {
            return json_encode(['error' => 'user_required']);
        }
        try {
            $goals = !empty($args['goals']) ? trim($args['goals']) : null;
            require_once __DIR__ . '/TrainingPlanService.php';
            $planService = new TrainingPlanService($this->db);
            $result = $planService->generateNextPlan($userId, $goals);
            return json_encode([
                'success' => true,
                'message' => 'Генерация нового плана запущена. План будет готов через 3-5 минут.',
                'pid' => $result['pid'] ?? null
            ]);
        } catch (Exception $e) {
            return json_encode([
                'error' => 'generate_next_failed',
                'message' => 'Не удалось запустить генерацию: ' . $e->getMessage()
            ]);
        }
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
     * Пользователь: отправить сообщение другому пользователю (от имени отправителя)
     * Сообщение попадает в admin-чат получателя (он увидит в «От администрации»)
     */
    public function sendUserMessageToUser(int $senderUserId, int $targetUserId, string $content): array {
        if ($senderUserId === $targetUserId) {
            throw new InvalidArgumentException('Нельзя отправить сообщение самому себе');
        }
        $conversation = $this->repository->getOrCreateConversation($targetUserId, 'admin');
        $messageId = $this->repository->addMessage($conversation['id'], 'user', $senderUserId, $content);
        $this->repository->touchConversation($conversation['id']);
        $senderUsername = $this->getUsernameById($senderUserId);
        $this->sendChatPush($targetUserId, 'Новое сообщение от ' . ($senderUsername ?: 'пользователя'), $content, 'chat');
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
        $this->sendChatPush($targetUserId, 'Новое сообщение от администрации', $content, 'admin');
        return [
            'conversation_id' => $conversation['id'],
            'message_id' => $messageId
        ];
    }

    /**
     * Сообщения между текущим пользователем и другим (диалог «Написать»)
     * Сообщения хранятся в admin-чате получателя
     * При загрузке помечает сообщения от собеседника как прочитанные
     */
    public function getDirectMessagesWithUser(int $currentUserId, int $targetUserId, int $limit = 50, int $offset = 0): array {
        $this->repository->markDirectDialogRead($currentUserId, $targetUserId);
        $messages = $this->repository->getDirectMessagesBetweenUsers($currentUserId, $targetUserId, $limit, $offset);
        return [
            'messages' => $messages,
            'conversation_id' => null,
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
     * Список диалогов: пользователи, которые писали мне через «Написать»
     */
    public function getUsersWhoWroteToMe(int $userId): array {
        return $this->repository->getUsersWhoWroteToMe($userId);
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
        $this->sendChatPush($userId, 'Новое сообщение от AI-тренера', $content, 'chat');
        return ['message_id' => $messageId];
    }

    /**
     * Отправить push о новом сообщении в чате (проверяет push_chat_enabled).
     */
    private function sendChatPush(int $userId, string $title, string $body, string $type): void {
        try {
            $push = new PushNotificationService($this->db);
            $truncated = mb_strlen($body) > 100 ? mb_substr($body, 0, 97) . '...' : $body;
            $push->sendToUser($userId, $title, $truncated, [
                'type' => 'chat',
                'link' => '/chat'
            ]);
        } catch (\Throwable $e) {
            // Push send failed — тихо игнорируем
        }
    }

    private function getUsernameById(int $userId): ?string {
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['username'] ?? null;
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
            $this->sendChatPush($targetUserId, 'Новое сообщение от администрации', $content, 'admin');
            $sent++;
        }
        return ['sent' => $sent];
    }
}
