<?php
/**
 * LLMEnricher — вызов LLM для обогащения скелета плана.
 *
 * Шаг 1 LLM-пайплайна: отправляет скелет + профиль бегуна,
 * получает JSON с добавленными notes.
 */

require_once __DIR__ . '/enrichment_prompt_builder.php';
require_once __DIR__ . '/SkeletonValidator.php';
require_once __DIR__ . '/StructuredJsonResponseParser.php';

class LLMEnricher
{
    private string $baseUrl;
    private string $model;
    private int $maxTokens;
    private bool $enableThinking;
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;

    public function __construct(
        ?string $baseUrl = null,
        ?string $model = null,
        ?int $maxTokens = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? $this->getEnv('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $this->model = $model ?? $this->getEnv('LLM_CHAT_MODEL', 'mistralai/ministral-3-14b-reasoning');
        $this->maxTokens = $maxTokens ?? $this->getEnvInt('LLM_ENRICHER_MAX_TOKENS', 2048, 256, 4096);
        $this->timeoutSeconds = $this->getEnvInt('LLM_ENRICHER_TIMEOUT_SECONDS', 75, 10, 300);
        $this->connectTimeoutSeconds = $this->getEnvInt('LLM_ENRICHER_CONNECT_TIMEOUT_SECONDS', 5, 1, 60);
        $this->enableThinking = $this->getEnvBool('LLM_STRUCTURED_ENABLE_THINKING', false);
    }

    /**
     * Обогатить скелет плана через LLM.
     *
     * @param array $skeleton Числовой скелет {weeks: [...]}
     * @param array $user     Данные пользователя
     * @param array $state    TrainingState
     * @param array $context  Контекст: reason, goals, job_type
     * @return array Обогащённый план {weeks: [...]} или исходный скелет при ошибке
     */
    public function enrich(array $skeleton, array $user, array $state, array $context = []): array
    {
        $prompt = buildEnrichmentPrompt($skeleton, $user, $state, $context);
        $basePlan = SkeletonValidator::addAlgorithmicNotes($skeleton);

        $response = $this->callLLM($prompt);
        if ($response === null) {
            error_log('LLMEnricher: LLM call failed, returning algorithmic notes fallback');
            return $basePlan;
        }

        $enriched = $this->parseResponse($response);
        if ($enriched === null) {
            error_log('LLMEnricher: failed to parse response, retrying compact answer without thinking');
            $fallback = $this->requestCompletion($this->buildJsonOnlyRetryPrompt($prompt), false);
            $fallbackContent = trim((string) ($fallback['content'] ?? ''));
            if ($fallbackContent !== '') {
                $enriched = $this->parseResponse($fallbackContent);
            }
            if ($enriched === null) {
                error_log('LLMEnricher: Failed to parse LLM response after retry, returning algorithmic notes fallback');
                return $basePlan;
            }
        }

        // Мержим notes из LLM в исходный скелет
        return $this->mergeNotes($basePlan, $enriched);
    }

    /**
     * Вызов LLM API (OpenAI-совместимый /v1/chat/completions).
     */
    private function callLLM(string $prompt): ?string
    {
        $response = $this->requestCompletion($prompt, $this->enableThinking);
        if ($response === null) {
            return null;
        }

        $content = trim((string) ($response['content'] ?? ''));
        $hasReasoning = trim((string) ($response['reasoning_content'] ?? '')) !== '';
        $finishReason = (string) ($response['finish_reason'] ?? '');

        if ($this->enableThinking && $content === '' && ($hasReasoning || $finishReason === 'length')) {
            error_log('LLMEnricher: retrying without thinking after reasoning-only response');
            $fallback = $this->requestCompletion($prompt, false);
            if ($fallback === null) {
                return null;
            }
            $content = trim((string) ($fallback['content'] ?? ''));
        }

        return $content !== '' ? $content : null;
    }

    private function requestCompletion(string $prompt, bool $enableThinking): ?array
    {
        $url = $this->baseUrl . '/chat/completions';

        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'Ты — тренер по бегу. Отвечай только валидным JSON по заданной схеме: без markdown, без пояснений, без <think>. Все notes пиши ТОЛЬКО НА РУССКОМ ЯЗЫКЕ — никакого английского текста.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => $this->maxTokens,
            'stream' => false,
            'response_format' => $this->buildResponseFormat(),
            'chat_template_kwargs' => ['enable_thinking' => $enableThinking],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            error_log("LLMEnricher: HTTP {$httpCode}, error: {$error}");
            return null;
        }

        $json = json_decode($result, true);
        $choice = $json['choices'][0] ?? [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        return [
            'content' => $message['content'] ?? '',
            'reasoning_content' => $message['reasoning_content'] ?? '',
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];
    }

    private function buildResponseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'enrichment_notes_response',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'notes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'week_number' => ['type' => 'integer'],
                                    'day_of_week' => ['type' => 'integer'],
                                    'notes' => ['type' => 'string'],
                                ],
                                'required' => ['week_number', 'day_of_week', 'notes'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['notes'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private function buildJsonOnlyRetryPrompt(string $prompt): string
    {
        return "Повтори ответ в максимально компактном виде.\n"
            . "Верни СТРОГО один JSON-объект без markdown, без пояснений, без <think>.\n"
            . "Форма ответа: {\"notes\":[{\"week_number\":1,\"day_of_week\":1,\"notes\":\"...\"}]}.\n"
            . "Если полный ответ получается длинным или ты не уверен, верни {\"notes\":[]}.\n\n"
            . "Исходная задача:\n" . mb_substr($prompt, 0, 12000, 'UTF-8');
    }

    /**
     * Парсинг ответа LLM — извлечение JSON из текста.
     */
    private function parseResponse(string $response): ?array
    {
        $parsedNotes = StructuredJsonResponseParser::parseNotesPayload($response);
        if ($parsedNotes !== null) {
            return $parsedNotes;
        }

        $parsed = StructuredJsonResponseParser::parseWeeksPayload($response);
        if ($parsed !== null) {
            return $parsed;
        }

        error_log('LLMEnricher: Could not parse JSON from response, len=' . strlen($response));
        return null;
    }

    /**
     * Мержить notes из LLM-ответа в исходный скелет.
     * LLM может менять только notes — всё остальное берём из скелета.
     * Сопоставление по week_number и day_of_week (не по индексу массива).
     */
    private function mergeNotes(array $skeleton, array $enriched): array
    {
        $result = $skeleton;

        $notesByWeekDay = [];

        foreach ((array) ($enriched['notes'] ?? []) as $noteItem) {
            $weekNumber = isset($noteItem['week_number']) ? (int) $noteItem['week_number'] : 0;
            $dayOfWeek = isset($noteItem['day_of_week']) ? (int) $noteItem['day_of_week'] : 0;
            $noteText = trim((string) ($noteItem['notes'] ?? ''));
            if ($weekNumber < 1 || $dayOfWeek < 1 || $noteText === '') {
                continue;
            }
            $notesByWeekDay[$weekNumber . ':' . $dayOfWeek] = $noteText;
        }

        if ($notesByWeekDay === [] && !empty($enriched['weeks'])) {
            foreach ((array) ($enriched['weeks'] ?? []) as $week) {
                $weekNumber = isset($week['week_number']) ? (int) $week['week_number'] : 0;
                foreach ((array) ($week['days'] ?? []) as $day) {
                    $dayOfWeek = isset($day['day_of_week']) ? (int) $day['day_of_week'] : 0;
                    $noteText = trim((string) ($day['notes'] ?? ''));
                    if ($weekNumber < 1 || $dayOfWeek < 1 || $noteText === '') {
                        continue;
                    }
                    $notesByWeekDay[$weekNumber . ':' . $dayOfWeek] = $noteText;
                }
            }
        }

        foreach ($result['weeks'] as &$week) {
            $weekNumber = isset($week['week_number']) ? (int) $week['week_number'] : 0;

            foreach ($week['days'] as &$day) {
                $dayOfWeek = isset($day['day_of_week']) ? (int) $day['day_of_week'] : 0;
                $noteKey = $weekNumber . ':' . $dayOfWeek;
                if (isset($notesByWeekDay[$noteKey])) {
                    $day['notes'] = $notesByWeekDay[$noteKey];
                }
            }
            unset($day);
        }
        unset($week);

        return $result;
    }

    private function getEnv(string $key, string $default): string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }

    private function getEnvBool(string $key, bool $default): bool
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($raw === false || $raw === null) {
            return $default;
        }

        $value = trim(mb_strtolower((string) $raw, 'UTF-8'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function getEnvInt(string $key, int $default, int $min, int $max): int
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
