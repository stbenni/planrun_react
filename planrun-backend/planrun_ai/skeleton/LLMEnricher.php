<?php
/**
 * LLMEnricher — вызов LLM для обогащения скелета плана.
 *
 * Шаг 1 LLM-пайплайна: отправляет скелет + профиль бегуна,
 * получает JSON с добавленными notes.
 */

require_once __DIR__ . '/enrichment_prompt_builder.php';

class LLMEnricher
{
    private string $baseUrl;
    private string $model;
    private int $maxTokens;

    public function __construct(
        ?string $baseUrl = null,
        ?string $model = null,
        int $maxTokens = 16384
    ) {
        $this->baseUrl = rtrim($baseUrl ?? $this->getEnv('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $this->model = $model ?? $this->getEnv('LLM_CHAT_MODEL', 'mistralai/ministral-3-14b-reasoning');
        $this->maxTokens = $maxTokens;
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

        $response = $this->callLLM($prompt);
        if ($response === null) {
            error_log('LLMEnricher: LLM call failed, returning original skeleton');
            return $skeleton;
        }

        $enriched = $this->parseResponse($response);
        if ($enriched === null) {
            error_log('LLMEnricher: Failed to parse LLM response, returning original skeleton');
            return $skeleton;
        }

        // Мержим notes из LLM в исходный скелет
        return $this->mergeNotes($skeleton, $enriched);
    }

    /**
     * Вызов LLM API (OpenAI-совместимый /v1/chat/completions).
     */
    private function callLLM(string $prompt): ?string
    {
        $url = $this->baseUrl . '/chat/completions';

        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'Ты — тренер по бегу. Отвечай строго JSON без markdown-обёрток. Все notes пиши ТОЛЬКО НА РУССКОМ ЯЗЫКЕ — никакого английского текста.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => $this->maxTokens,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
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
        return $json['choices'][0]['message']['content'] ?? null;
    }

    /**
     * Парсинг ответа LLM — извлечение JSON из текста.
     */
    private function parseResponse(string $response): ?array
    {
        // Убрать markdown-обёртки
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $cleaned = preg_replace('/\s*```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        // Попытка 1: прямой парсинг
        $parsed = json_decode($cleaned, true);
        if (is_array($parsed)) {
            // Если вернули {weeks: [...]}, извлекаем
            if (isset($parsed['weeks'])) {
                return $parsed;
            }
            // Если вернули голый массив [...]
            if (isset($parsed[0]['week_number'])) {
                return ['weeks' => $parsed];
            }
        }

        // Попытка 2: найти JSON в тексте
        if (preg_match('/\{[\s\S]*"weeks"\s*:\s*\[[\s\S]*\]\s*\}/', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['weeks'])) {
                return $parsed;
            }
        }

        // Попытка 3: найти массив weeks
        if (preg_match('/\[\s*\{[\s\S]*"week_number"[\s\S]*\}\s*\]/', $cleaned, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                return ['weeks' => $parsed];
            }
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

        // Индексируем enriched по week_number
        $enrichedByWeek = [];
        foreach ($enriched['weeks'] ?? [] as $ew) {
            $wn = $ew['week_number'] ?? null;
            if ($wn !== null) {
                $enrichedByWeek[$wn] = $ew;
            }
        }

        foreach ($result['weeks'] as &$week) {
            $wn = $week['week_number'] ?? null;
            $enrichedWeek = $enrichedByWeek[$wn] ?? null;
            if (!$enrichedWeek || !isset($enrichedWeek['days'])) {
                continue;
            }

            // Индексируем enriched days по day_of_week
            $enrichedByDay = [];
            foreach ($enrichedWeek['days'] as $ed) {
                $dow = $ed['day_of_week'] ?? null;
                if ($dow !== null) {
                    $enrichedByDay[$dow] = $ed;
                }
            }

            foreach ($week['days'] as &$day) {
                $dow = $day['day_of_week'] ?? null;
                $enrichedDay = $enrichedByDay[$dow] ?? null;
                if (!$enrichedDay) {
                    continue;
                }

                // Берём только notes из LLM
                if (!empty($enrichedDay['notes']) && is_string($enrichedDay['notes'])) {
                    $day['notes'] = $enrichedDay['notes'];
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
}
