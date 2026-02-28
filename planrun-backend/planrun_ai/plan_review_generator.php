<?php
/**
 * Генерация рецензии плана через LM Studio.
 * После сохранения плана — добавляет в чат пользователя краткое описание:
 * что и почему расставлено в плане.
 */

require_once __DIR__ . '/../config/env_loader.php';
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

    foreach ($normalized['weeks'] ?? [] as $week) {
        $wn = $week['week_number'] ?? 0;
        $lines[] = "Неделя {$wn}:";
        foreach ($week['days'] ?? [] as $i => $day) {
            $dow = $dayNames[$i] ?? ('День ' . ($i + 1));
            $type = $day['type'] ?? 'rest';
            $desc = trim($day['description'] ?? '');
            $isKey = !empty($day['is_key_workout']);
            $keyMark = $isKey ? ' [ключевая]' : '';
            if ($desc !== '') {
                $lines[] = "  {$dow}: {$desc}{$keyMark}";
            } else {
                $typeLabels = [
                    'easy' => 'Лёгкий бег', 'long' => 'Длительный бег', 'tempo' => 'Темповый',
                    'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'control' => 'Контрольная',
                    'other' => 'ОФП', 'sbu' => 'СБУ', 'rest' => 'Отдых', 'free' => 'Свободный'
                ];
                $label = $typeLabels[$type] ?? $type;
                $lines[] = "  {$dow}: {$label}{$keyMark}";
            }
        }
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * Генерирует рецензию плана через LM Studio.
 *
 * @param array $planData Сырой план
 * @param string $startDate Дата начала
 * @param string $mode 'ГЕНЕРАЦИЯ'|'ПЕРЕСЧЁТ'|'НОВЫЙ ПЛАН'
 * @return string|null Текст рецензии или null при ошибке
 */
function generatePlanReview(array $planData, string $startDate, string $mode = 'ГЕНЕРАЦИЯ'): ?string {
    $baseUrl = rtrim(env('LMSTUDIO_BASE_URL', 'http://127.0.0.1:1234/v1'), '/');
    $model = env('LMSTUDIO_CHAT_MODEL', 'openai/gpt-oss-20b');

    if ($baseUrl === '' || $model === '') {
        error_log('plan_review_generator: LMSTUDIO_BASE_URL или LMSTUDIO_CHAT_MODEL не заданы');
        return null;
    }

    $planSummary = buildPlanSummaryForReview($planData, $startDate);
    if (mb_strlen($planSummary) > 8000) {
        $planSummary = mb_substr($planSummary, 0, 8000) . "\n... (обрезка)";
    }

    $systemPrompt = "Ты — AI-тренер PlanRun. Сгенерируй краткую рецензию плана для пользователя. " .
        "Объясни, что и почему расставлено в плане, логику и структуру. " .
        "Пиши дружелюбно, 3–5 абзацев. Только русский язык. Без префиксов вроде «Рецензия»: сразу по делу.";

    $userContent = "Режим: {$mode}. План:\n\n" . $planSummary;

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent]
        ],
        'stream' => false,
        'max_tokens' => 1500,
        'temperature' => 0.3
    ];

    $url = $baseUrl . '/chat/completions';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        error_log("plan_review_generator: LM Studio HTTP {$httpCode} или пустой ответ");
        return null;
    }

    $data = json_decode($response, true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return null;
    }

    $content = mb_substr($content, 0, 4000);
    return $content;
}
