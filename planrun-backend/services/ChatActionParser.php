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
        'swap_training_days', 'add_training_day', 'copy_day',
        'log_workout', 'recalculate_plan', 'generate_next_plan',
        'update_profile', 'report_health_issue',
    ];

    private const WRITE_TOOLS = [
        'move_training_day', 'update_training_day', 'delete_training_day',
        'swap_training_days', 'add_training_day', 'copy_day',
        'log_workout', 'recalculate_plan', 'generate_next_plan',
        'update_profile', 'report_health_issue',
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
     * Единый метод: парсит и выполняет все ACTION-блоки + fallback для подтверждений.
     */
    public function parseAndExecuteActions(string $text, int $userId, array $history = [], ?string $currentUserMessage = null, bool &$planWasUpdated = false, array $alreadyUsedTools = []): string {
        $text = $this->stripAlreadyHandledBlocks($text, $alreadyUsedTools);

        $isConfirmation = $currentUserMessage !== null && $this->confirmationHandler->isConfirmationMessage($currentUserMessage);
        $text = $this->executeAllActionBlocks($text, $userId, $planWasUpdated, $isConfirmation);

        if (!$planWasUpdated && $isConfirmation) {
            $fallbackParams = $this->confirmationHandler->tryExtractFromLastProposal($history, $userId);
            if ($fallbackParams !== null && !empty($fallbackParams['date']) && !empty($fallbackParams['type'])) {
                $text = $this->executeAddTrainingFallback($text, $userId, $fallbackParams, $planWasUpdated);
            }
        }

        return $text;
    }

    /**
     * Strips ACTION blocks for tools that were already executed by confirmation handlers.
     */
    private function stripAlreadyHandledBlocks(string $text, array $alreadyUsedTools): string {
        if (empty($alreadyUsedTools)) return $text;
        $handled = array_intersect($alreadyUsedTools, self::WRITE_TOOLS);
        foreach ($handled as $tool) {
            $text = preg_replace('/\s*<!--\s*ACTION\s+' . preg_quote($tool, '/') . '\s+[\s\S]+?\s*-->\s*/', '', $text);
        }
        return $text;
    }

    /**
     * Unified ACTION block executor for all tool types.
     */
    private function executeAllActionBlocks(string $text, int $userId, bool &$planWasUpdated, bool $isConfirmation): string {
        $toolPattern = implode('|', array_map(fn($t) => preg_quote($t, '/'), self::ACTION_TOOLS));
        $pattern = '/\s*<!--\s*ACTION\s+(' . $toolPattern . ')\s+([\s\S]+?)\s*-->\s*/';

        if (!preg_match($pattern, $text)) return $text;

        $hasWriteBlocks = false;
        if (preg_match_all($pattern, $text, $allMatches)) {
            foreach ($allMatches[1] as $toolName) {
                if (in_array($toolName, self::WRITE_TOOLS, true)) { $hasWriteBlocks = true; break; }
            }
        }

        if ($hasWriteBlocks && !$isConfirmation) {
            $askingConfirmation = preg_match('/(подтверди|подтверж|правильно\s*\?|подходит\s*\?|согласен|нужно.*\?|хоч|переставлю|перенесу|обновлю|заменю)/ui', $text);
            if ($askingConfirmation) {
                Logger::debug('ACTION blocks in proposal — stripping without executing');
                return trim(preg_replace($pattern, '', $text));
            }
        }

        while (preg_match($pattern, $text, $m)) {
            $toolName = $m[1];
            $attrs = trim($m[2]);
            $args = $this->parseActionAttributes($attrs);

            if ($toolName === 'add_training_day') {
                if (empty($args['date']) || empty($args['type'])) {
                    $text = preg_replace($pattern, '', $text, 1);
                    continue;
                }
                if (!$this->validateAddTrainingParams($args)) {
                    $text = preg_replace($pattern, '', $text, 1);
                    continue;
                }
            }

            try {
                $output = $this->toolRegistry->executeTool($toolName, json_encode($args), $userId);
                $result = json_decode($output, true);
                if (!isset($result['error'])) {
                    $planWasUpdated = true;
                    Logger::info('ACTION block executed', ['tool' => $toolName, 'args' => $args]);
                } else {
                    Logger::warning('ACTION block failed', ['tool' => $toolName, 'error' => $result['error']]);
                }
            } catch (Throwable $e) {
                Logger::warning('ACTION block exception', ['tool' => $toolName, 'error' => $e->getMessage()]);
            }

            $text = preg_replace($pattern, '', $text, 1);
        }

        // Also handle JSON-format action blocks
        $text = $this->executeJsonActionBlocks($text, $userId, $planWasUpdated);

        return trim($text);
    }

    /**
     * Handles JSON-format action: {"action":"add_training_day",...}
     */
    private function executeJsonActionBlocks(string $text, int $userId, bool &$planWasUpdated): string {
        if (!preg_match('/(\{[^{}]*"action"\s*:\s*"add_training_day"[^{}]*\})/', $text, $jm)) return $text;

        $jsonData = json_decode($jm[1], true);
        if (!$jsonData || empty($jsonData['date']) || empty($jsonData['type'])) return $text;

        $params = ['date' => $jsonData['date'], 'type' => $jsonData['type'], 'description' => $jsonData['description'] ?? ''];
        if (!$this->validateAddTrainingParams($params)) {
            return str_replace($jm[0], '', $text);
        }

        try {
            $output = $this->toolRegistry->executeTool('add_training_day', json_encode($params), $userId);
            $result = json_decode($output, true);
            if (!isset($result['error'])) {
                $planWasUpdated = true;
                Logger::info('JSON action block executed', ['args' => $params]);
            }
        } catch (Throwable $e) {
            Logger::warning('JSON action block exception', ['error' => $e->getMessage()]);
        }

        return trim(str_replace($jm[0], '', $text));
    }

    /**
     * Fallback: add training from last AI proposal after user confirmation.
     */
    private function executeAddTrainingFallback(string $text, int $userId, array $params, bool &$planWasUpdated): string {
        if (!$this->validateAddTrainingParams($params)) return $text;

        try {
            $output = $this->toolRegistry->executeTool('add_training_day', json_encode($params), $userId);
            $result = json_decode($output, true);
            if (!isset($result['error'])) {
                $planWasUpdated = true;
                Logger::info('Add training fallback executed', ['params' => $params]);
            }
        } catch (Throwable $e) {
            Logger::warning('Add training fallback failed', ['error' => $e->getMessage()]);
        }

        return $text;
    }

    private function validateAddTrainingParams(array $params): bool {
        $validTypes = ['rest', 'easy', 'long', 'tempo', 'interval', 'fartlek', 'marathon', 'control', 'race', 'other', 'free', 'sbu'];
        if (!in_array($params['type'] ?? '', $validTypes, true)) return false;
        $dateObj = DateTime::createFromFormat('Y-m-d', $params['date'] ?? '');
        if (!$dateObj) return false;
        $maxDate = (new DateTime())->modify('+1 year')->format('Y-m-d');
        if (($params['date'] ?? '') > $maxDate) return false;
        return true;
    }

    private function parseActionAttributes(string $attrs): array {
        $args = [];
        foreach (['source_date', 'target_date', 'date1', 'date2', 'date', 'type', 'reason'] as $key) {
            if (preg_match('/' . $key . '=([^\s"\']+|"[^"]*"|\'[^\']*\')/', $attrs, $km)) {
                $args[$key] = trim($km[1], '"\'');
            }
        }
        if (preg_match('/description=("((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')/', $attrs, $dm)) {
            $args['description'] = stripslashes(trim($dm[2] ?? $dm[3] ?? '', '"\''));
        } elseif (preg_match('/description=([\s\S]+)/', $attrs, $dm)) {
            $args['description'] = trim($dm[1], '"\' ');
        }
        return $args;
    }

    // ── English term replacement ──

    private function replaceEnglishTerms(string $text): string {
        $text = preg_replace('/\(\s*taper\s*\)/iu', '', $text);
        $text = preg_replace('/\(\s*carb\s*loading\s*\)/iu', '', $text);
        $text = preg_replace('/\(\s*negative\s*split\s*\)/iu', '', $text);
        $text = preg_replace('/\(\s*fartlek\s*\)/iu', '', $text);

        $terms = [
            'slightly faster than race pace' => 'чуть быстрее гоночного темпа',
            'today\'s workout' => 'сегодняшняя тренировка', 'today\'s training' => 'сегодняшняя тренировка',
            'today\'s plan' => 'план на сегодня', 'Weekly volume' => 'Недельный объём',
            'weekly plan' => 'недельный план', 'tempo run' => 'темповый бег',
            'long run' => 'длительный бег', 'easy run' => 'лёгкий бег',
            'taper period' => 'период снижения нагрузки', 'tapering' => 'снижение нагрузки', 'taper' => 'снижение нагрузки',
            'carb loading' => 'углеводная загрузка', 'carbo loading' => 'углеводная загрузка',
            'negative split' => 'отрицательный сплит', 'positive split' => 'положительный сплит',
            'hill repeats' => 'повторы в гору', 'hill repeat' => 'повтор в гору',
            'fartlek' => 'фартлек', 'strides' => 'ускорения',
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

        $text = preg_replace('/\bthy\b/iu', '', $text);
        $text = preg_replace('/\b(your|my|his|her|its|our|their)\b/iu', '', $text);

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
