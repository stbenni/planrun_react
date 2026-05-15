<?php
/**
 * Санитизация ответа LLM и парсинг/исполнение ACTION-блоков в тексте чата.
 * Unified: все ACTION-блоки обрабатываются единообразно через ChatToolRegistry.
 */

require_once __DIR__ . '/ChatToolRegistry.php';
require_once __DIR__ . '/ChatConfirmationHandler.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/DateResolver.php';
require_once __DIR__ . '/../user_functions.php';

class ChatActionParser {

    private $db;
    private ChatToolRegistry $toolRegistry;
    private ChatConfirmationHandler $confirmationHandler;

    private const ACTION_TOOLS = [
        'move_training_day', 'update_training_day', 'delete_training_day',
        'swap_training_days', 'add_training_day',
    ];

    public function __construct($db, ChatToolRegistry $toolRegistry, ChatConfirmationHandler $confirmationHandler) {
        $this->db = $db;
        $this->toolRegistry = $toolRegistry;
        $this->confirmationHandler = $confirmationHandler;
    }

    /**
     * Proxy for backward compatibility — delegates to ChatConfirmationHandler.
     */
    public function isConfirmationMessage(string $text): bool {
        return $this->confirmationHandler->isConfirmationMessage($text);
    }

    /**
     * Убирает утечку reasoning и английские префиксы из ответа LLM.
     */
    public function sanitizeResponse(string $text): string {
        $text = trim($text);
        if ($text === '') return '';

        $text = preg_replace('/\[THINK\][\s\S]*?\[\/THINK\]\s*/i', '', $text) ?? $text;
        $text = preg_replace('/<think>[\s\S]*?<\/think>\s*/i', '', $text) ?? $text;
        $text = preg_replace('/^\[THINK\][\s\S]*?(?=[\p{Cyrillic}])/iu', '', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/^[-.\s>…]+/u', '', $text);
        $text = preg_replace('/<\|[a-z_]+\|>/', '', $text);
        $text = preg_replace('/\bcommentary\s+to=commentary\b/iu', '', $text);
        $text = trim($text);

        $leakPrefixes = [
            '/^We\s+need\s+to\s+(output|provide|give|write)\s+[^.]*\.?##\s*/iu',
            '/^We\s+need\s+to\s+/iu', '/^The\s+conversation\s+/iu',
            '/^The\s+user\s+(asks|wants|is)\s+/iu', '/^Let\s+me\s+(think|analyze|check)\s+/iu',
            '/^First,?\s+/iu', '/^I\'ll\s+(start|begin|provide)\s+/iu',
            '/^I\s+should\s+/iu', '/^Here\'s?\s+(my|the)\s+/iu', '/^\[.*?\]\s*/u',
            '/^Output\s+(the\s+)?(plan|response)\s+[^.#]*\.?##\s*/iu',
        ];
        foreach ($leakPrefixes as $re) {
            $prev = $text;
            $text = preg_replace($re, '', $text);
            if ($text !== $prev) $text = trim($text);
        }

        if (preg_match('/[\p{Cyrillic}]/u', $text)) {
            $len = mb_strlen($text);
            for ($i = 0; $i < $len; $i++) {
                if (preg_match('/[\p{Cyrillic}]/u', mb_substr($text, $i, 1))) {
                    if ($i > 0) {
                        $before = mb_substr($text, 0, $i);
                        if (preg_match('/^[\s\p{P}A-Za-z0-9]+$/u', $before) && mb_strlen($before) >= 20 && mb_strlen($before) < 150) {
                            $text = mb_substr($text, $i);
                        }
                    }
                    break;
                }
            }
        }

        $text = preg_replace('/\w+\[ARGS\]\s*\{[^}]*\}/i', '', $text);
        $text = trim($text);
        $text = preg_replace('/\bBt\b/u', 'Вт', $text);
        $text = preg_replace('/\bПt\b/u', 'Пт', $text);

        $text = $this->replaceEnglishTerms($text);
        $text = $this->stripEmoji($text);
        $this->logLeakedEnglish($text);

        return trim($text);
    }

    /**
     * Strips any residual ACTION blocks from LLM output and handles confirmation fallbacks.
     * ACTION blocks are legacy — native tool calling handles all tool invocations now.
     */
    public function parseAndExecuteActions(string $text, int $userId, array $history = [], ?string $currentUserMessage = null, bool &$planWasUpdated = false, array $alreadyUsedTools = []): string {
        // Strip any residual ACTION blocks the LLM might still emit
        $text = $this->stripAllActionBlocks($text);

        // Strip any JSON-format action blocks
        $text = preg_replace('/\{[^{}]*"action"\s*:\s*"add_training_day"[^{}]*\}/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Strip all ACTION blocks from text (safety net for any legacy output).
     */
    private function stripAllActionBlocks(string $text): string {
        $toolPattern = implode('|', array_map(fn($t) => preg_quote($t, '/'), self::ACTION_TOOLS));
        return preg_replace('/\s*<!--\s*ACTION\s+(?:' . $toolPattern . ')\s+[\s\S]+?\s*-->\s*/', '', $text) ?? $text;
    }

    // ── English term replacement ──

    private function replaceEnglishTerms(string $text): string {
        // #17: ~225 preg_replace на каждый ответ — пропускаем целиком, если в
        // тексте нет ни одного латинского слова (типичный случай: чистый русский).
        if (!preg_match('/[A-Za-z]{2,}/', $text)) {
            return $text;
        }
        $terms = [
            'slightly faster than race pace' => 'чуть быстрее гоночного темпа',
            'today\'s workout' => 'сегодняшняя тренировка', 'today\'s training' => 'сегодняшняя тренировка',
            'today\'s plan' => 'план на сегодня', 'Weekly volume' => 'Недельный объём',
            'weekly plan' => 'недельный план', 'tempo run' => 'темповый бег',
            'long run' => 'длительный бег', 'easy run' => 'лёгкий бег',
            'finish strong' => 'финишировать мощно', 'warm up' => 'разминка',
            'cool down' => 'заминка', 'cooldown' => 'заминка', 'warm-up' => 'разминка',
            'recovery' => 'восстановление', 'especially' => 'особенно',
            'prefers' => 'предпочитает', 'ambitious' => 'амбициозный',
            'today' => 'сегодня', 'tomorrow' => 'завтра', 'yesterday' => 'вчера',
            'training' => 'тренировка', 'workout' => 'тренировка',
            'threshold' => 'пороговый', 'interval' => 'интервал',
            'focus' => 'фокус', 'split' => 'отрезок', 'pace' => 'темп',
            'week' => 'неделя', 'after' => 'после', 'before' => 'перед',
            'danger zone' => 'зона риска', 'overtraining' => 'перетренированность',
            'rest day' => 'день отдыха', 'rest' => 'отдых',
            'good job' => 'молодец', 'great' => 'отлично', 'perfect' => 'отлично',
            'important' => 'важно', 'recommended' => 'рекомендуется',
            'caution' => 'осторожность', 'optimal' => 'оптимальный',
            'progress' => 'прогресс', 'distance' => 'дистанция', 'heart rate' => 'пульс',
            'shorter' => 'короче', 'longer' => 'длиннее', 'faster' => 'быстрее', 'slower' => 'медленнее',
            'based on' => 'на основе', 'including' => 'включая', 'however' => 'однако', 'also' => 'также',
            'planned' => 'запланировано', 'completed' => 'выполнено', 'skipped' => 'пропущено',
            'actually' => 'на самом деле', 'basically' => 'по сути', 'definitely' => 'определённо',
            'seriously' => 'серьёзно', 'significant' => 'значительный', 'excellent' => 'отлично',
            'well done' => 'молодец', 'good work' => 'хорошая работа', 'nice work' => 'хорошая работа',
            'keep going' => 'продолжай', 'keep it up' => 'так держать',
            'suggestion' => 'рекомендация', 'recommend' => 'рекомендую',
            'approximately' => 'примерно', 'elevation' => 'набор высоты',
            'average' => 'средний', 'total' => 'итого', 'volume' => 'объём',
            'current' => 'текущий', 'previous' => 'предыдущий', 'next' => 'следующий',
            'session' => 'тренировка', 'sessions' => 'тренировки',
            'schedule' => 'расписание', 'plan' => 'план',
            'fatigue' => 'усталость', 'tired' => 'устал',
            'injury' => 'травма', 'pain' => 'боль',
            'strength' => 'сила', 'flexibility' => 'гибкость',
            'meanwhile' => 'тем временем', 'therefore' => 'поэтому',
        ];
        foreach ($terms as $en => $ru) {
            $text = preg_replace('/(?<![a-zA-Z])' . preg_quote($en, '/') . '\'s(?![a-zA-Z])/iu', $ru, $text);
            if (!preg_match('/[а-яёА-ЯЁ]/u', $en)) {
                $text = preg_replace('/(?<![a-zA-Z])' . preg_quote($en, '/') . '(?=[а-яёА-ЯЁ])/iu', $ru, $text);
            }
            $text = preg_replace('/(?<![a-zA-Z])' . preg_quote($en, '/') . '(?![a-zA-Z])/iu', $ru, $text);
        }

        $engDays = ['Monday' => 'понедельник', 'Tuesday' => 'вторник', 'Wednesday' => 'среда', 'Thursday' => 'четверг', 'Friday' => 'пятница', 'Saturday' => 'суббота', 'Sunday' => 'воскресенье'];
        foreach ($engDays as $en => $ru) $text = preg_replace('/\b' . $en . '\b/iu', $ru, $text);

        $engMonths = ['January' => 'января', 'February' => 'февраля', 'March' => 'марта', 'April' => 'апреля', 'May' => 'мая', 'June' => 'июня', 'July' => 'июля', 'August' => 'августа', 'September' => 'сентября', 'October' => 'октября', 'November' => 'ноября', 'December' => 'декабря'];
        foreach ($engMonths as $en => $ru) $text = preg_replace('/\b' . $en . '\b/iu', $ru, $text);

        // #16: удаление местоимений (your/my/his/...) убрано — оно рушило смысл
        // легитимных фраз («your VDOT 52» → «VDOT 52»). English-leak фиксируется
        // через logLeakedEnglish (warning), а не тихим вырезанием.

        return $text;
    }

    /**
     * Strip emoji characters that models sometimes inject despite instructions.
     * Keeps standard punctuation, Cyrillic, Latin (for technical terms like VDOT), digits.
     */
    private function stripEmoji(string $text): string {
        $text = preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '', $text);
        $text = preg_replace('/[\x{2600}-\x{27BF}]/u', '', $text);
        $text = preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $text);
        $text = preg_replace('/[\x{200D}]/u', '', $text);
        $text = preg_replace('/[\x{20E3}]/u', '', $text);
        $text = preg_replace('/[\x{E0020}-\x{E007F}]/u', '', $text);
        $text = preg_replace('/[\x{2702}-\x{27B0}]/u', '', $text);
        $text = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $text);
        $text = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $text);
        $text = preg_replace('/[\x{1FA00}-\x{1FA6F}]/u', '', $text);
        $text = preg_replace('/[\x{1FA70}-\x{1FAFF}]/u', '', $text);
        $text = preg_replace('/[\x{2300}-\x{23FF}]/u', '', $text);
        $text = preg_replace('/[\x{2B05}-\x{2B55}]/u', '', $text);
        $text = preg_replace('/  +/', ' ', $text);
        return trim($text);
    }

    private function logLeakedEnglish(string $text): void {
        if (!preg_match_all('/(?<=[\p{Cyrillic}\s])[a-zA-Z]{4,}(?=[\p{Cyrillic}\s\p{P}])/u', $text, $engWords)) return;
        $allowList = ['ACWR', 'VDOT', 'ATL', 'CTL', 'TSB', 'TRIMP', 'Strava', 'Polar', 'Garmin', 'Coros', 'GPS', 'HR'];
        $leaked = array_filter(array_unique($engWords[0]), fn($w) => !in_array(strtoupper($w), array_map('strtoupper', $allowList)));
        if (!empty($leaked)) Logger::warning('English words leaked in AI response', ['words' => array_values($leaked)]);
    }
}
