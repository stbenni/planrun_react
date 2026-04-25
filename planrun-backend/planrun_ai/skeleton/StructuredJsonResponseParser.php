<?php

/**
 * StructuredJsonResponseParser
 *
 * Безопасный разбор "почти JSON" ответов от LLM для structured-задач.
 * Ищет и извлекает валидные JSON-фрагменты даже если модель добавила
 * пояснения, markdown-обёртки или лишний текст вокруг ответа.
 */
class StructuredJsonResponseParser
{
    public static function parseNotesPayload(string $response): ?array
    {
        foreach (self::extractJsonCandidates($response) as $candidate) {
            $parsed = self::decodeJson($candidate);
            if (!is_array($parsed)) {
                continue;
            }

            if (isset($parsed['notes']) && is_array($parsed['notes'])) {
                $notes = self::normalizeNotesList($parsed['notes']);
                return ['notes' => $notes ?? $parsed['notes']];
            }
        }

        return null;
    }

    public static function parseWeeksPayload(string $response): ?array
    {
        foreach (self::extractJsonCandidates($response) as $candidate) {
            $parsed = self::decodeJson($candidate);
            if (!is_array($parsed)) {
                continue;
            }

            if (isset($parsed['weeks']) && is_array($parsed['weeks'])) {
                return $parsed;
            }

            if (self::looksLikeWeeksArray($parsed)) {
                return ['weeks' => $parsed];
            }
        }

        return null;
    }

    public static function parseReviewPayload(string $response): ?array
    {
        foreach (self::extractJsonCandidates($response) as $candidate) {
            $parsed = self::decodeJson($candidate);
            if (is_array($parsed) && $parsed === []) {
                return [
                    'status' => 'ok',
                    'issues' => [],
                ];
            }

            if (self::looksLikeIssueList($parsed)) {
                $issues = is_array($parsed) ? $parsed : [];
                return [
                    'status' => $issues === [] ? 'ok' : 'has_issues',
                    'issues' => $issues,
                ];
            }

            if (!is_array($parsed)) {
                continue;
            }

            if (
                !isset($parsed['status'])
                && !isset($parsed['issues'])
                && !isset($parsed['errors'])
                && !array_key_exists('ok', $parsed)
                && !array_key_exists('valid', $parsed)
            ) {
                continue;
            }

            $issueSource = $parsed['issues'] ?? ($parsed['errors'] ?? []);
            $issues = is_array($issueSource) ? self::normalizeReviewIssues($issueSource) : [];
            if (($parsed['ok'] ?? null) === true || ($parsed['valid'] ?? null) === true) {
                $status = $issues === [] ? 'ok' : 'has_issues';
            } elseif (($parsed['ok'] ?? null) === false || ($parsed['valid'] ?? null) === false) {
                $status = 'has_issues';
            } else {
                $status = isset($parsed['status'])
                ? self::normalizeReviewStatus((string) ($parsed['status'] ?? 'ok'), $issues)
                : ($issues === [] ? 'ok' : 'has_issues');
            }

            return [
                'status' => $status,
                'issues' => $issues,
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function extractJsonCandidates(string $response): array
    {
        $cleaned = self::stripMarkdownFences($response);
        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return [];
        }

        $candidates = [$cleaned];

        $firstBrace = strpos($cleaned, '{');
        $lastBrace = strrpos($cleaned, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidates[] = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $firstBracket = strpos($cleaned, '[');
        $lastBracket = strrpos($cleaned, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $candidates[] = substr($cleaned, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        foreach (self::extractBalancedFragments($cleaned) as $fragment) {
            $candidates[] = $fragment;
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $unique[$candidate] = true;
        }

        return array_keys($unique);
    }

    private static function stripMarkdownFences(string $response): string
    {
        $cleaned = preg_replace('/^\xEF\xBB\xBF/u', '', $response);
        $cleaned = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', (string) $cleaned);
        $cleaned = preg_replace('/^```(?:json)?\s*/mi', '', (string) $cleaned);
        $cleaned = preg_replace('/\s*```$/mi', '', (string) $cleaned);
        return (string) $cleaned;
    }

    /**
     * @return mixed
     */
    private static function decodeJson(string $candidate)
    {
        $variants = [trim($candidate)];
        $normalized = self::normalizeJsonCandidate($candidate);
        if ($normalized !== $variants[0]) {
            $variants[] = $normalized;
        }

        foreach ($variants as $variant) {
            try {
                return json_decode($variant, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                continue;
            }
        }

        return null;
    }

    private static function normalizeJsonCandidate(string $candidate): string
    {
        $normalized = trim($candidate);
        $normalized = strtr($normalized, [
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '«' => '"',
            '»' => '"',
            '‘' => "'",
            '’' => "'",
        ]);
        $normalized = self::stripJsonComments($normalized);
        $normalized = preg_replace('/,\s*([}\]])/', '$1', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private static function stripJsonComments(string $json): string
    {
        $result = '';
        $length = strlen($json);
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            $next = $i + 1 < $length ? $json[$i + 1] : '';

            if ($escaped) {
                $result .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $result .= $char;
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $result .= $char;
                $inString = !$inString;
                continue;
            }

            if (!$inString && $char === '/' && $next === '/') {
                while ($i < $length && !in_array($json[$i], ["\n", "\r"], true)) {
                    $i++;
                }
                if ($i < $length) {
                    $result .= $json[$i];
                }
                continue;
            }

            if (!$inString && $char === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $length && !($json[$i] === '*' && $json[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    /**
     * @param array<mixed> $notes
     * @return list<array<string, mixed>>|null
     */
    private static function normalizeNotesList(array $notes): ?array
    {
        if ($notes === []) {
            return [];
        }

        if (array_is_list($notes)) {
            return $notes;
        }

        $normalized = [];
        foreach ($notes as $key => $value) {
            if (!preg_match('/^(\d+)\D+(\d+)$/', (string) $key, $matches)) {
                continue;
            }

            $noteText = is_array($value)
                ? trim((string) ($value['notes'] ?? $value['note'] ?? $value['text'] ?? ''))
                : trim((string) $value);
            if ($noteText === '') {
                continue;
            }

            $normalized[] = [
                'week_number' => (int) $matches[1],
                'day_of_week' => (int) $matches[2],
                'notes' => $noteText,
            ];
        }

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * @param array<mixed> $issues
     * @return list<mixed>
     */
    private static function normalizeReviewIssues(array $issues): array
    {
        if ($issues === []) {
            return [];
        }

        $normalized = [];
        foreach ($issues as $issue) {
            if (is_string($issue)) {
                $text = trim($issue);
                if ($text !== '') {
                    $normalized[] = [
                        'type' => 'error',
                        'description' => $text,
                    ];
                }
                continue;
            }

            $normalized[] = $issue;
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private static function looksLikeWeeksArray($value): bool
    {
        return is_array($value)
            && $value !== []
            && isset($value[0])
            && is_array($value[0])
            && (isset($value[0]['week_number']) || isset($value[0]['days']));
    }

    /**
     * @param mixed $value
     */
    private static function looksLikeIssueList($value): bool
    {
        return is_array($value)
            && $value !== []
            && isset($value[0])
            && is_array($value[0])
            && (isset($value[0]['type']) || isset($value[0]['description']) || isset($value[0]['fix_suggestion']));
    }

    /**
     * @param array<int, mixed> $issues
     */
    private static function normalizeReviewStatus(string $status, array $issues): string
    {
        $normalized = mb_strtolower(trim($status), 'UTF-8');
        if ($normalized === '' || $normalized === 'ok') {
            return $issues === [] ? 'ok' : 'has_issues';
        }

        if (in_array($normalized, ['success', 'done', 'no_issues', 'clean'], true)) {
            return 'ok';
        }

        return 'has_issues';
    }

    /**
     * @return list<string>
     */
    private static function extractBalancedFragments(string $text): array
    {
        $results = [];
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            $open = $text[$start];
            if ($open !== '{' && $open !== '[') {
                continue;
            }

            $close = $open === '{' ? '}' : ']';
            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start; $i < $length; $i++) {
                $char = $text[$i];

                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = !$inString;
                    continue;
                }

                if ($inString) {
                    continue;
                }

                if ($char === $open) {
                    $depth++;
                    continue;
                }

                if ($char === $close) {
                    $depth--;
                    if ($depth === 0) {
                        $results[] = substr($text, $start, $i - $start + 1);
                        break;
                    }
                }
            }
        }

        usort($results, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $results;
    }
}
