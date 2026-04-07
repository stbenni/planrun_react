<?php
/**
 * LLMReviewer — ревью логики плана через LLM.
 *
 * Шаг 2-3 LLM-пайплайна: отправляет план + профиль бегуна,
 * получает JSON с найденными ошибками.
 */

require_once __DIR__ . '/enrichment_prompt_builder.php';

class LLMReviewer
{
    private string $baseUrl;
    private string $model;
    private int $maxTokens;

    public function __construct(
        ?string $baseUrl = null,
        ?string $model = null,
        int $maxTokens = 4096
    ) {
        $this->baseUrl = rtrim($baseUrl ?? $this->getEnv('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $this->model = $model ?? $this->getEnv('LLM_CHAT_MODEL', 'qwen3-14b');
        $this->maxTokens = $maxTokens;
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
            error_log('LLMReviewer: Failed to parse review response');
            return ['status' => 'ok', 'issues' => []];
        }

        return $result;
    }

    private function callLLM(string $prompt): ?string
    {
        $url = $this->baseUrl . '/chat/completions';

        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'Ты — рецензент тренировочных планов. Отвечай строго JSON без markdown-обёрток. Пиши только на русском языке.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => $this->maxTokens,
            'stream' => false,
            'chat_template_kwargs' => ['enable_thinking' => false],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
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
        return $json['choices'][0]['message']['content'] ?? null;
    }

    private function parseReviewResponse(string $response): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $cleaned = preg_replace('/\s*```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        if (is_array($parsed) && isset($parsed['status'])) {
            return [
                'status' => $parsed['status'] === 'ok' ? 'ok' : 'has_issues',
                'issues' => $parsed['issues'] ?? [],
            ];
        }

        // Попытка найти JSON в тексте
        if (preg_match('/\{[\s\S]*"status"\s*:[\s\S]*\}/', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['status'])) {
                return [
                    'status' => $parsed['status'] === 'ok' ? 'ok' : 'has_issues',
                    'issues' => $parsed['issues'] ?? [],
                ];
            }
        }

        return null;
    }

    private function getEnv(string $key, string $default): string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}
