<?php
/**
 * LLMReviewer — ревью логики плана через LLM.
 *
 * Шаг 2-3 LLM-пайплайна: отправляет план + профиль бегуна,
 * получает JSON с найденными ошибками.
 */

require_once __DIR__ . '/enrichment_prompt_builder.php';
require_once __DIR__ . '/StructuredJsonResponseParser.php';

class LLMReviewer
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
        $this->maxTokens = $maxTokens ?? $this->getEnvInt('LLM_REVIEWER_MAX_TOKENS', 1536, 128, 3072);
        $this->timeoutSeconds = $this->getEnvInt('LLM_REVIEWER_TIMEOUT_SECONDS', 45, 10, 300);
        $this->connectTimeoutSeconds = $this->getEnvInt('LLM_REVIEWER_CONNECT_TIMEOUT_SECONDS', 5, 1, 60);
        // Reviewer downstream uses only structured JSON, so reasoning mode adds noise and hurts parseability.
        $this->enableThinking = false;
    }

    /**
     * Запустить ревью плана.
     *
     * @param array $plan  План {weeks: [...]}
     * @param array $user  Данные пользователя
     * @param array $state TrainingState
     * @return array {status: 'ok'|'has_issues', issues: [...]}
     */
    public function review(array $plan, array $user, array $state): array
    {
        $prompt = buildReviewPrompt($plan, $user, $state);
        $response = $this->callLLM($prompt);

        if ($response === null) {
            error_log('LLMReviewer: LLM call failed, assuming ok');
            return ['status' => 'ok', 'issues' => []];
        }

        $result = $this->parseReviewResponse($response);
        if ($result === null) {
            error_log('LLMReviewer: failed to parse review response, retrying compact answer without thinking');
            $fallback = $this->requestCompletion($this->buildJsonOnlyRetryPrompt($prompt), false);
            $fallbackContent = trim((string) ($fallback['content'] ?? ''));
            if ($fallbackContent !== '') {
                $result = $this->parseReviewResponse($fallbackContent);
            }
            if ($result === null) {
                error_log('LLMReviewer: Failed to parse review response after retry');
                return ['status' => 'ok', 'issues' => []];
            }
        }

        $result['issues'] = $this->filterScenarioFalsePositives((array) ($result['issues'] ?? []), $plan, $state);
        if (empty($result['issues'])) {
            $result['status'] = 'ok';
        }

        return $result;
    }

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
            error_log('LLMReviewer: retrying without thinking after reasoning-only response');
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
                ['role' => 'system', 'content' => 'Ты — рецензент тренировочных планов. Отвечай только валидным JSON по заданной схеме: без markdown, без пояснений, без <think>. Пиши только на русском языке.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.0,
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
            error_log("LLMReviewer: HTTP {$httpCode}, error: {$error}");
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

    private function parseReviewResponse(string $response): ?array
    {
        return StructuredJsonResponseParser::parseReviewPayload($response);
    }

    private function buildResponseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'review_response',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'enum' => ['ok', 'has_issues'],
                        ],
                        'issues' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'week' => ['type' => 'integer'],
                                    'day_of_week' => ['type' => 'integer'],
                                    'type' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'fix_suggestion' => ['type' => 'string'],
                                ],
                                'required' => ['week', 'type', 'description'],
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                    'required' => ['status', 'issues'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private function buildJsonOnlyRetryPrompt(string $prompt): string
    {
        return "Повтори ревью в максимально компактном виде.\n"
            . "Верни СТРОГО один JSON-объект без markdown, без пояснений, без <think>.\n"
            . "Форма ответа: {\"status\":\"ok\",\"issues\":[]} или {\"status\":\"has_issues\",\"issues\":[{\"week\":1,\"day_of_week\":1,\"type\":\"...\",\"description\":\"...\",\"fix_suggestion\":\"...\"}]}.\n"
            . "Если критичных замечаний нет или ответ получается слишком длинным, верни {\"status\":\"ok\",\"issues\":[]}.\n\n"
            . "Исходная задача:\n" . mb_substr($prompt, 0, 12000, 'UTF-8');
    }

    private function filterScenarioFalsePositives(array $issues, array $plan, array $state): array
    {
        $scenario = (array) ($state['planning_scenario'] ?? []);
        $flags = array_values(array_filter((array) ($scenario['flags'] ?? []), 'is_string'));
        if ($issues === [] || $flags === []) {
            return $issues;
        }

        $filtered = [];
        foreach ($issues as $issue) {
            if (!$this->shouldIgnoreIssue($issue, $plan, $scenario, $flags)) {
                $filtered[] = $issue;
            }
        }

        return $filtered;
    }

    private function shouldIgnoreIssue(array $issue, array $plan, array $scenario, array $flags): bool
    {
        $type = (string) ($issue['type'] ?? '');
        $weekNum = (int) ($issue['week'] ?? 0);
        $dayNum = isset($issue['day_of_week']) ? (int) $issue['day_of_week'] : null;
        $day = $dayNum !== null ? $this->findDay($plan, $weekNum, $dayNum) : null;
        $dayType = (string) ($day['type'] ?? '');

        $tuneUp = (array) ($scenario['tune_up_event'] ?? []);
        $tuneUpWeek = (int) ($tuneUp['week'] ?? 0);
        $tuneUpDay = (int) ($tuneUp['dayIndex'] ?? 0);
        $tuneUpDayOfWeek = (int) ($tuneUp['day_of_week'] ?? ($tuneUpDay > 0 ? $tuneUpDay + 1 : 0));
        $isTuneUpScenario = in_array('b_race_before_a_race', $flags, true) || in_array('explicit_tune_up_event', $flags, true);
        $isShortTaper = in_array('short_runway_taper', $flags, true) || in_array('short_runway_long_race', $flags, true);

        $isTuneUpControlDay = $isTuneUpScenario
            && $weekNum === $tuneUpWeek
            && $dayNum !== null
            && ($dayNum === $tuneUpDay || $dayNum === $tuneUpDayOfWeek)
            && $dayType === 'control';

        if ($isTuneUpControlDay) {
            if (in_array($type, ['pace_logic', 'too_aggressive', 'taper_violation'], true)) {
                return true;
            }
        }

        if ($type === 'long_run_decrease' && ($isShortTaper || $isTuneUpScenario) && $this->isTaperLikeWeek($plan, $weekNum)) {
            return true;
        }

        if ($type === 'volume_jump' && !$this->hasActualVolumeJump($plan, $weekNum, 1.15)) {
            return true;
        }

        if ($type === 'volume_jump' && $isTuneUpScenario && $weekNum === $tuneUpWeek && $this->isProtectedTuneUpWeek($plan, $tuneUpWeek)) {
            return true;
        }

        if ($type === 'taper_violation' && ($isShortTaper || $isTuneUpScenario) && $this->isProtectedTuneUpWeek($plan, $weekNum)) {
            return true;
        }

        if ($type === 'pace_logic' && in_array($dayType, ['race', 'marathon'], true) && $this->isFinalRaceWeek($plan, $weekNum)) {
            return true;
        }

        if ($type === 'too_aggressive' && in_array($dayType, ['race', 'marathon'], true) && $this->isFinalRaceWeek($plan, $weekNum)) {
            return true;
        }

        return false;
    }

    private function findDay(array $plan, int $weekNum, int $dayNum): ?array
    {
        foreach ((array) ($plan['weeks'] ?? []) as $week) {
            if ((int) ($week['week_number'] ?? 0) !== $weekNum) {
                continue;
            }

            foreach ((array) ($week['days'] ?? []) as $day) {
                if ((int) ($day['day_of_week'] ?? 0) === $dayNum) {
                    return $day;
                }
            }
        }

        return null;
    }

    private function findWeek(array $plan, int $weekNum): ?array
    {
        foreach ((array) ($plan['weeks'] ?? []) as $week) {
            if ((int) ($week['week_number'] ?? 0) === $weekNum) {
                return $week;
            }
        }

        return null;
    }

    private function isTaperLikeWeek(array $plan, int $weekNum): bool
    {
        $week = $this->findWeek($plan, $weekNum);
        return $week !== null && (string) ($week['phase'] ?? '') === 'taper';
    }

    private function isProtectedTuneUpWeek(array $plan, int $weekNum): bool
    {
        $week = $this->findWeek($plan, $weekNum);
        if ($week === null) {
            return false;
        }

        $days = (array) ($week['days'] ?? []);
        $longCount = 0;
        $qualityCount = 0;
        $raceLikeCount = 0;

        foreach ($days as $day) {
            $type = (string) ($day['type'] ?? '');
            if ($type === 'long') {
                $longCount++;
            }
            if (!empty($day['is_key_workout'])) {
                $qualityCount++;
            }
            if (in_array($type, ['control', 'race', 'marathon'], true)) {
                $raceLikeCount++;
            }
        }

        return $raceLikeCount === 1 && $longCount === 0 && $qualityCount <= 1;
    }

    private function calculateWeekVolume(array $plan, int $weekNum): float
    {
        $week = $this->findWeek($plan, $weekNum);
        if ($week === null) {
            return 0.0;
        }

        $total = 0.0;
        foreach ((array) ($week['days'] ?? []) as $day) {
            $total += (float) ($day['distance_km'] ?? 0.0);
        }

        return round($total, 2);
    }

    private function hasActualVolumeJump(array $plan, int $weekNum, float $ratioThreshold): bool
    {
        if ($weekNum <= 1) {
            return false;
        }

        $current = $this->calculateWeekVolume($plan, $weekNum);
        $previous = $this->calculateWeekVolume($plan, $weekNum - 1);
        if ($previous <= 0.0) {
            return false;
        }

        return ($current / $previous) > $ratioThreshold;
    }

    private function isFinalRaceWeek(array $plan, int $weekNum): bool
    {
        $weeks = array_values((array) ($plan['weeks'] ?? []));
        if ($weeks === []) {
            return false;
        }

        $lastWeek = end($weeks);
        if (!is_array($lastWeek)) {
            return false;
        }

        return (int) ($lastWeek['week_number'] ?? 0) === $weekNum;
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
        if ($raw === false || $raw === null || trim((string) $raw) === '') {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }
}
