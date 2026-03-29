<?php
/**
 * Обработка подтверждений пользователя в чате.
 * Когда AI предложил действие (swap, update, delete, move...) и пользователь сказал «да»,
 * этот модуль парсит предложение AI и выполняет действие через ChatToolRegistry.
 */

require_once __DIR__ . '/DateResolver.php';
require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class ChatConfirmationHandler {

    private $db;
    private ChatToolRegistry $toolRegistry;

    public function __construct($db, ChatToolRegistry $toolRegistry) {
        $this->db = $db;
        $this->toolRegistry = $toolRegistry;
    }

    public function isConfirmationMessage(string $text): bool {
        $s = mb_strtolower(trim($text));
        if (mb_strlen($s) > 50) return false;
        $short = preg_replace('/[\s\p{P}]+/u', '', $s);
        return in_array($short, ['да', 'давай', 'ок', 'окей', 'супер', 'хорошо', 'отлично', 'погнали', 'достаточно', 'этогодостаточно', 'правильно'])
            || preg_match('/^(да|давай|ок|супер|отлично|хорошо|правильно)[\s\p{P}]*$/ui', $s)
            || preg_match('/^(этого\s+)?достаточно\??$/ui', $s)
            || preg_match('/^(да,?\s+)?правильно\??$/ui', $s);
    }

    public function tryHandleSwapConfirmation(string $content, array $history, int $userId, array &$messages, array &$toolsUsed): bool {
        $trimmed = trim($content);
        if (mb_strlen($trimmed) > 30) return false;
        if (!preg_match('/^(да|ок|давай|подходит|сделай|согласен|хорошо|yes|ok|ага|угу|окей|оке|правильно)(,?\s*.*)?$/ui', $trimmed)) return false;

        $lastAssistant = $this->getLastAssistantMessage($history);
        if ($lastAssistant === '') return false;
        if (preg_match('/\b(1\.\s.*\n.*2\.)/us', $lastAssistant)) return false;
        if (!preg_match('/(поменять\s+местами|поменял\s+местами|swap|меняем\s+местами)/ui', $lastAssistant)) return false;

        $swapDates = $this->extractSwapDatesFromText($lastAssistant, $userId);
        if ($swapDates === null) return false;

        $output = $this->toolRegistry->executeTool('swap_training_days', json_encode(['date1' => $swapDates[0], 'date2' => $swapDates[1]]), $userId);
        $result = json_decode($output, true);
        if (!isset($result['success']) || !$result['success']) return false;

        $toolsUsed[] = 'swap_training_days';
        $tcId = 'swap-' . uniqid();
        $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => $tcId, 'type' => 'function', 'function' => ['name' => 'swap_training_days', 'arguments' => json_encode(['date1' => $swapDates[0], 'date2' => $swapDates[1]])]]]];
        $messages[] = ['role' => 'tool', 'tool_call_id' => $tcId, 'content' => $output];
        return true;
    }

    public function tryHandleReplaceWithRaceConfirmation(string $content, array $history, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!$this->isConfirmationMessage($content)) return false;
        $lastAssistant = $this->getLastAssistantMessage($history);
        if ($lastAssistant === '') return false;

        $proposal = $this->parseReplaceWithRaceProposal($lastAssistant, $userId);
        if ($proposal === null) return false;

        $dates = $this->extractReplaceDatesFromText($lastAssistant, $userId);
        if ($dates === null) return false;

        $workout1 = $proposal['today'] ?? null;
        $workout2 = $proposal['tomorrow'] ?? null;
        if (!$workout1 || !$workout2) return false;

        $out1 = $this->toolRegistry->executeTool('update_training_day', json_encode(['date' => $dates[0], 'type' => $workout1['type'], 'description' => $workout1['description']]), $userId);
        $res1 = json_decode($out1, true);
        if (isset($res1['error'])) return false;

        $out2 = $this->toolRegistry->executeTool('update_training_day', json_encode(['date' => $dates[1], 'type' => $workout2['type'], 'description' => $workout2['description']]), $userId);
        $res2 = json_decode($out2, true);
        if (isset($res2['error'])) {
            if (isset($res1['original_type']) && isset($res1['original_description'])) {
                $this->toolRegistry->executeTool('update_training_day', json_encode(['date' => $dates[0], 'type' => $res1['original_type'], 'description' => $res1['original_description']]), $userId);
            }
            return false;
        }

        $toolsUsed[] = 'update_training_day';
        $toolsUsed[] = 'update_training_day';
        $tcId1 = 'upd-' . uniqid();
        $tcId2 = 'upd-' . uniqid();
        $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['id' => $tcId1, 'type' => 'function', 'function' => ['name' => 'update_training_day', 'arguments' => json_encode(['date' => $dates[0], 'type' => $workout1['type'], 'description' => $workout1['description']])]],
            ['id' => $tcId2, 'type' => 'function', 'function' => ['name' => 'update_training_day', 'arguments' => json_encode(['date' => $dates[1], 'type' => $workout2['type'], 'description' => $workout2['description']])]],
        ]];
        $messages[] = ['role' => 'tool', 'tool_call_id' => $tcId1, 'content' => $out1];
        $messages[] = ['role' => 'tool', 'tool_call_id' => $tcId2, 'content' => $out2];
        return true;
    }

    public function tryHandleGenericUpdateConfirmation(string $content, array $history, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!$this->isConfirmationMessage($content)) return false;

        $lastAssistant = $this->getLastAssistantMessage($history);
        if ($lastAssistant === '' || mb_strlen($lastAssistant) < 20) return false;
        if (!preg_match('/(обновлю|скорректирую|изменю|заменю|сократим|сокращу|поменяю|заменим|скорректируем|записываю|зафиксирую|обновлённый|удалю|уберу|отменю|перенесу|переставлю|добавлю|скопирую|повтор[юяю]|пересчита[юю]|запущу|сгенериру[юю]|создам|подтверди|правильно\s*\?|подходит\s*\?|верно\s*\?)/ui', $lastAssistant)) return false;

        if ($this->tryExecuteRecalculateFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        if ($this->tryExecuteGenerateNextPlanFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        if ($this->tryExecuteDeleteFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        if ($this->tryExecuteMoveFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        if ($this->tryExecuteCopyFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        if ($this->tryExecuteAddFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        if ($this->tryExecuteLogWorkoutFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        if ($this->tryExecuteUpdateProfileFromProposal($lastAssistant, $userId, $messages, $toolsUsed)) return true;
        return $this->tryExecuteUpdateFromProposal($lastAssistant, $userId, $messages, $toolsUsed);
    }

    public function tryExtractFromLastProposal(array $history, int $userId): ?array {
        $lastAi = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['sender_type'] ?? '') === 'assistant') {
                $lastAi = trim($history[$i]['content'] ?? '');
                break;
            }
        }
        if ($lastAi === '' || mb_strlen($lastAi) < 20) return null;

        $params = [];
        if (preg_match('/для\s+\*\*[^*]*на\s+([^*]+)\*\*/ui', $lastAi, $dm)
            || preg_match('/(\d{1,2}\s+(?:января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря))/ui', $lastAi, $dm)) {
            $resolver = new DateResolver();
            $today = new DateTime('now', new DateTimeZone(getUserTimezone($userId)));
            $dateStr = $resolver->resolveFromText(trim($dm[1]), $today);
            if ($dateStr) $params['date'] = $dateStr;
        }

        $typeMap = ['ОФП' => 'other', 'СБУ' => 'sbu', 'Лёгкий бег' => 'easy', 'Легкий бег' => 'easy', 'Длительный' => 'long', 'Темповый' => 'tempo', 'Интервалы' => 'interval', 'Фартлек' => 'fartlek', 'Отдых' => 'rest', 'Забег' => 'race'];
        foreach ($typeMap as $ru => $en) {
            if (mb_stripos($lastAi, $ru) !== false) { $params['type'] = $en; break; }
        }

        if (preg_match('/Детали:\s*(.+?)(?:\n|$)/u', $lastAi, $dd)) {
            $params['description'] = trim($dd[1]);
        } elseif (preg_match('/описание:\s*(.+?)(?:\n|$)/ui', $lastAi, $dd)) {
            $params['description'] = trim($dd[1]);
        }

        if (!empty($params['date']) && !empty($params['type'])) {
            $params['description'] = $params['description'] ?? '';
            return $params;
        }
        return null;
    }

    // ── Private helpers ──

    private function getLastAssistantMessage(array $history): string {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['sender_type'] ?? '') === 'ai') {
                return trim($history[$i]['content'] ?? '');
            }
        }
        return '';
    }

    public function extractSwapDatesFromText(string $text, int $userId): ?array {
        $tz = $this->getUserTz($userId);
        $now = new DateTime('now', $tz);
        $now->setTime(0, 0, 0);
        $dates = [];

        if (preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
            foreach ($m[1] as $d) { if (DateTime::createFromFormat('Y-m-d', $d)) $dates[] = $d; }
        }
        if (preg_match('/\bсегодня\b/ui', $text)) $dates[] = $now->format('Y-m-d');
        if (preg_match('/\bзавтра\b/ui', $text)) $dates[] = (clone $now)->modify('+1 day')->format('Y-m-d');
        if (preg_match('/\bпослезавтра\b/ui', $text)) $dates[] = (clone $now)->modify('+2 days')->format('Y-m-d');

        $dayNames = ['понедельник' => 1, 'вторник' => 2, 'среда' => 3, 'среду' => 3, 'четверг' => 4, 'пятница' => 5, 'пятницу' => 5, 'суббота' => 6, 'субботу' => 6, 'воскресенье' => 7];
        foreach ($dayNames as $name => $dow) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/ui', $text)) {
                $diff = $dow - (int) $now->format('N');
                if ($diff < 0) $diff += 7;
                $dates[] = (clone $now)->modify("+{$diff} days")->format('Y-m-d');
            }
        }

        $dates = array_values(array_unique($dates));
        if (count($dates) >= 2) { sort($dates); return [$dates[0], $dates[1]]; }
        return null;
    }

    private function extractSingleDateFromText(string $text, int $userId): ?string {
        $tz = $this->getUserTz($userId);
        $now = new DateTime('now', $tz);
        $now->setTime(0, 0, 0);

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) return $m[1];
        if (preg_match('/\bсегодня\b/ui', $text)) return $now->format('Y-m-d');
        if (preg_match('/\bзавтра\b/ui', $text)) return (clone $now)->modify('+1 day')->format('Y-m-d');
        if (preg_match('/\bпослезавтра\b/ui', $text)) return (clone $now)->modify('+2 days')->format('Y-m-d');

        return (new DateResolver())->resolveFromText($text, clone $now);
    }

    private function extractReplaceDatesFromText(string $text, int $userId): ?array {
        return $this->extractSwapDatesFromText($text, $userId);
    }

    private function getUserTz(int $userId): DateTimeZone {
        $tzName = getUserTimezone($userId);
        try { return new DateTimeZone($tzName); } catch (Exception $e) { return new DateTimeZone('Europe/Moscow'); }
    }

    private function addToolCallToMessages(string $toolName, array $args, string $output, array &$messages, array &$toolsUsed): void {
        $toolsUsed[] = $toolName;
        $tcId = substr($toolName, 0, 6) . '-' . uniqid();
        $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => $tcId, 'type' => 'function', 'function' => ['name' => $toolName, 'arguments' => json_encode($args)]]]];
        $messages[] = ['role' => 'tool', 'tool_call_id' => $tcId, 'content' => $output];
    }

    // ── Proposal parsers (private) ──

    private function tryExecuteDeleteFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(удал[яюю]|убер[у|ём]|отмен[яюю]|удалить|убрать|отменить)\s*(тренировку|день|запись)/ui', $text)) return false;
        $date = $this->extractSingleDateFromText($text, $userId);
        if (!$date) return false;
        $output = $this->toolRegistry->executeTool('delete_training_day', json_encode(['date' => $date]), $userId);
        if (isset(json_decode($output, true)['error'])) return false;
        $this->addToolCallToMessages('delete_training_day', ['date' => $date], $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteMoveFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(перенес[уём]|перестав[люю]|перемещ[уаю]|перенести|переставить)/ui', $text)) return false;
        $dates = $this->extractSwapDatesFromText($text, $userId);
        if ($dates === null) return false;
        $args = ['source_date' => $dates[0], 'target_date' => $dates[1]];
        $output = $this->toolRegistry->executeTool('move_training_day', json_encode($args), $userId);
        if (isset(json_decode($output, true)['error'])) return false;
        $this->addToolCallToMessages('move_training_day', $args, $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteAddFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(добавл[яюю]|поставл[яюю]|добавить|поставить)\s*(тренировку|день|на)/ui', $text)) return false;
        $data = $this->parseGenericUpdateProposal($text, $userId);
        if ($data === null) return false;
        $args = ['date' => $data['date'], 'type' => $data['type'], 'description' => $data['description']];
        $output = $this->toolRegistry->executeTool('add_training_day', json_encode($args), $userId);
        if (isset(json_decode($output, true)['error'])) return false;
        $this->addToolCallToMessages('add_training_day', $args, $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteLogWorkoutFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(записываю|запишу|фиксирую|зафиксирую)[:\s]/ui', $text)) return false;
        $date = $this->extractSingleDateFromText($text, $userId);
        if (!$date) {
            $tz = $this->getUserTz($userId);
            $date = (new DateTime('now', $tz))->format('Y-m-d');
        }
        if (!preg_match('/(\d+(?:[.,]\d+)?)\s*км/u', $text, $km)) return false;
        $distanceKm = (float) str_replace(',', '.', $km[1]);
        if ($distanceKm <= 0) return false;

        $args = ['date' => $date, 'distance_km' => $distanceKm];
        if (preg_match('/(\d+)\s*(?:минут|мин)/u', $text, $tm)) $args['duration_minutes'] = (int)$tm[1];
        elseif (preg_match('/(\d+):(\d{2}):(\d{2})/u', $text, $hm)) $args['duration_minutes'] = (int)$hm[1] * 60 + (int)$hm[2];
        elseif (preg_match('/(\d+):(\d{2})/u', $text, $hm) && (int)$hm[1] > 15) $args['duration_minutes'] = (int)$hm[1];
        if (preg_match('/пульс[:\s]*(\d{2,3})/ui', $text, $hr)) $args['avg_heart_rate'] = (int)$hr[1];

        $output = $this->toolRegistry->executeTool('log_workout', json_encode($args), $userId);
        if (isset(json_decode($output, true)['error'])) return false;
        $this->addToolCallToMessages('log_workout', $args, $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteUpdateFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        $data = $this->parseGenericUpdateProposal($text, $userId);
        if ($data === null) return false;
        $args = ['date' => $data['date'], 'type' => $data['type'], 'description' => $data['description']];
        $output = $this->toolRegistry->executeTool('update_training_day', json_encode($args), $userId);
        $result = json_decode($output, true);
        if (isset($result['error'])) {
            if (str_contains($result['error'] ?? '', 'not_found') || str_contains($result['error'] ?? '', 'не найден') || ($result['error'] ?? '') === 'no_plan_for_date') {
                $addOutput = $this->toolRegistry->executeTool('add_training_day', json_encode($args), $userId);
                if (!isset(json_decode($addOutput, true)['error'])) {
                    $this->addToolCallToMessages('add_training_day', $args, $addOutput, $messages, $toolsUsed);
                    return true;
                }
            }
            return false;
        }
        $this->addToolCallToMessages('update_training_day', $args, $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteRecalculateFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(пересчита[юем]|запущу\s+пересч[её]т|пересчитать\s+план|адаптирую\s+план)/ui', $text)) return false;
        $reason = '';
        if (preg_match('/(?:потому что|из-за|причина|так как|учитывая)\s+([^.!?]+)/ui', $text, $rm)) $reason = trim($rm[1]);
        $args = $reason ? ['reason' => $reason] : [];
        $output = $this->toolRegistry->executeTool('recalculate_plan', json_encode($args), $userId);
        if (isset(json_decode($output, true)['error'])) return false;
        $this->addToolCallToMessages('recalculate_plan', $args, $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteGenerateNextPlanFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(создам\s+(?:новый\s+)?план|сгенериру[юем]\s+(?:новый\s+)?план|запущу\s+генерацию|новый\s+(?:тренировочный\s+)?план)/ui', $text)) return false;
        $output = $this->toolRegistry->executeTool('generate_next_plan', json_encode([]), $userId);
        if (isset(json_decode($output, true)['error'])) return false;
        $this->addToolCallToMessages('generate_next_plan', [], $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteCopyFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(скопиру[юем]|повтор[яюю]|копирую|дублирую)\s*(тренировку|день|на)/ui', $text)) return false;
        $dates = $this->extractSwapDatesFromText($text, $userId);
        if ($dates === null) return false;
        $args = ['source_date' => $dates[0], 'target_date' => $dates[1]];
        $output = $this->toolRegistry->executeTool('copy_day', json_encode($args), $userId);
        if (isset(json_decode($output, true)['error'])) return false;
        $this->addToolCallToMessages('copy_day', $args, $output, $messages, $toolsUsed);
        return true;
    }

    private function tryExecuteUpdateProfileFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed): bool {
        if (!preg_match('/(обновлю|изменю|установл[юяю]|запишу)\s*(вес|рост|цель|забег|дистанц|темп|тренировок\s*в\s*неделю|профиль)/ui', $text)) return false;

        $fieldPatterns = [
            'weight_kg' => '/(?:вес|масс[ау])[:\s]*(\d+(?:[.,]\d+)?)\s*(?:кг)?/ui',
            'height_cm' => '/(?:рост)[:\s]*(\d{2,3})\s*(?:см)?/ui',
            'sessions_per_week' => '/(\d+)\s*(?:раз|тренирово[кч])\s*(?:в\s+)?недел[юи]/ui',
            'easy_pace_sec' => '/(?:лёгк|легк)\w*\s*темп[:\s]*(\d+):(\d{2})/ui',
            'race_distance' => '/(?:забег|дистанц|гонк)[^:]*[:\s]*(marathon|half|полумарафон|марафон|10k|5k|10\s*км|5\s*км|21[.,]1|42[.,]2)/ui',
            'race_target_time' => '/(?:цел|целев|за\s+время|финиш)[^:]*[:\s]*(\d{1,2}):(\d{2})(?::(\d{2}))?/ui',
            'race_date' => '/(?:забег|старт|гонка)\s+(?:на\s+)?(\d{4}-\d{2}-\d{2})/ui',
            'goal_type' => '/(?:цель|задач)[^:]*[:\s]*(подготовка\s+к\s+забегу|улучшение\s+формы|похудение|здоровье)/ui',
        ];

        foreach ($fieldPatterns as $field => $pattern) {
            if (!preg_match($pattern, $text, $m)) continue;
            $value = '';
            switch ($field) {
                case 'weight_kg': $value = str_replace(',', '.', $m[1]); break;
                case 'height_cm': case 'sessions_per_week': $value = $m[1]; break;
                case 'easy_pace_sec': $value = (string)((int)$m[1] * 60 + (int)$m[2]); break;
                case 'race_distance':
                    $distMap = ['marathon' => 'marathon', 'марафон' => 'marathon', 'half' => 'half_marathon', 'полумарафон' => 'half_marathon', '10k' => '10k', '10 км' => '10k', '5k' => '5k', '5 км' => '5k', '21.1' => 'half_marathon', '21,1' => 'half_marathon', '42.2' => 'marathon', '42,2' => 'marathon'];
                    $value = $distMap[mb_strtolower($m[1])] ?? $m[1]; break;
                case 'race_target_time':
                    $value = sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0); break;
                case 'race_date': $value = $m[1]; break;
                case 'goal_type':
                    $goalMap = ['подготовка к забегу' => 'race', 'улучшение формы' => 'fitness', 'похудение' => 'weight_loss', 'здоровье' => 'health'];
                    $value = $goalMap[mb_strtolower($m[1])] ?? $m[1]; break;
            }
            if ($value === '') continue;
            $args = ['field' => $field, 'value' => $value];
            $output = $this->toolRegistry->executeTool('update_profile', json_encode($args), $userId);
            if (isset(json_decode($output, true)['error'])) return false;
            $this->addToolCallToMessages('update_profile', $args, $output, $messages, $toolsUsed);
            return true;
        }
        return false;
    }

    private function parseGenericUpdateProposal(string $text, int $userId): ?array {
        $tz = $this->getUserTz($userId);
        $now = new DateTime('now', $tz);
        $now->setTime(0, 0, 0);

        $date = null;
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) $date = $m[1];
        if (!$date && preg_match('/\bсегодня\b/ui', $text)) $date = $now->format('Y-m-d');
        if (!$date && preg_match('/\bзавтра\b/ui', $text)) $date = (clone $now)->modify('+1 day')->format('Y-m-d');
        if (!$date) { $resolved = (new DateResolver())->resolveFromText($text, clone $now); if ($resolved) $date = $resolved; }
        if (!$date) $date = $now->format('Y-m-d');

        $type = null;
        $typeMap = [
            'длительный бег' => 'long', 'длительный' => 'long', 'длительная' => 'long', 'длинный бег' => 'long',
            'лёгкий бег' => 'easy', 'легкий бег' => 'easy', 'лёгкая' => 'easy', 'легкая' => 'easy',
            'темповый' => 'tempo', 'темповая' => 'tempo', 'темповый бег' => 'tempo',
            'интервалы' => 'interval', 'интервальная' => 'interval', 'фартлек' => 'fartlek',
            'отдых' => 'rest', 'офп' => 'other', 'сбу' => 'sbu', 'забег' => 'race', 'соревнование' => 'race',
        ];
        $lower = mb_strtolower($text);
        foreach ($typeMap as $kw => $tv) { if (mb_strpos($lower, $kw) !== false) { $type = $tv; break; } }
        if (!$type) {
            if (preg_match('/\b(\d+)\s*км\b/u', $text) && preg_match('/(5:3|5:4|5:5|6:|7:|комфортн)/u', $text)) $type = 'easy';
            elseif (preg_match('/\b(\d{2,})\s*км\b/u', $text, $km) && (int)$km[1] >= 18) $type = 'long';
        }
        if (!$type) return null;

        $description = $this->extractDescriptionFromProposal($text, $type);
        if ($description === null || $description === '') return null;
        return ['date' => $date, 'type' => $type, 'description' => $description];
    }

    private function extractDescriptionFromProposal(string $text, string $type): ?string {
        if (!preg_match('/(\d+(?:\.\d+)?)\s*км/u', $text, $m)) return null;
        $km = $m[1];
        $typeNames = ['long' => 'Длительный бег', 'easy' => 'Легкий бег', 'tempo' => 'Темповый бег', 'interval' => 'Интервалы', 'fartlek' => 'Фартлек', 'rest' => 'Отдых', 'race' => 'Забег', 'other' => 'ОФП', 'sbu' => 'СБУ'];

        $paceStr = '';
        if (preg_match('/темп[:\s]*(\d+:\d+(?:\s*[–—-]\s*\d+:\d+)?)/ui', $text, $pm)) $paceStr = ", темп {$pm[1]}";
        $walkStr = '';
        if (preg_match('/(отрезк[иа]\s+ходьбы[^.]*)/ui', $text, $wm)) $walkStr = '. ' . ucfirst(trim($wm[1]));
        elseif (preg_match('/(\d+\s*(км|м)\s*бег\s*\+\s*\d+[–—-]?\d*\s*мин\s*ходьб[аыи])/ui', $text, $wm)) $walkStr = '. ' . ucfirst(trim($wm[1]));
        $cooldownStr = '';
        if (preg_match('/заминка[:\s]*(\d+(?:\.\d+)?)\s*км(?:\s+[ва]?\s*(?:лёгком|легком)\s+темпе)?/ui', $text, $cm)) $cooldownStr = ". Заминка: {$cm[1]} км";

        return ($typeNames[$type] ?? 'Бег') . ": {$km} км{$paceStr}{$walkStr}{$cooldownStr}";
    }

    private function parseReplaceWithRaceProposal(string $text, int $userId): ?array {
        if (!preg_match('/(полумарафон|марафон|21\.1|42\.2|21\s*км|42\s*км)/ui', $text)) return null;
        if (!preg_match('/(сегодня|завтра|подтверди|обновлю|замен)/ui', $text)) return null;

        $result = ['today' => null, 'tomorrow' => null];

        if (preg_match('/(?:полумарафон|марафон)[^0-9]*(?:—|\-)?\s*(\d+(?:\.\d+)?)\s*км\s*(?:за\s+)?(\d+)(?::(\d+))?(?::(\d+))?\s*(?:час|ч|мин|м)?/ui', $text, $m)
            || preg_match('/(\d+(?:\.\d+)?)\s*км\s*(?:за\s+)?(\d+)(?::(\d+))?(?::(\d+))?\s*(?:час|ч|мин|м)/ui', $text, $m)) {
            if (!isset($m[1]) || !isset($m[2])) return null;
            $km = (float)$m[1];
            $totalSec = (int)$m[2] * 3600 + (isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0) * 60 + (isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0);
            if ($totalSec <= 0 || $km <= 0) return null;
            $timeStr = sprintf('%d:%02d:%02d', (int)floor($totalSec / 3600), (int)floor(($totalSec % 3600) / 60), $totalSec % 60);
            $result['today'] = ['type' => $km < 30 ? 'race' : 'marathon', 'description' => ($km < 30 ? 'Полумарафон' : 'Марафон') . ": {$km} км за {$timeStr}"];
        }

        if (preg_match('/(?:л[её]гкий|легкий)\s*бег[^0-9]*(?:—|\-)?\s*(\d+(?:\.\d+)?)\s*км(?:\s+в\s+темпе\s+(\d+):(\d+))?/ui', $text, $m)
            || preg_match('/(?:л[её]гкий|легкий)\s*бег[^—\-]*[—\-]\s*(\d+(?:\.\d+)?)\s*км/ui', $text, $m)) {
            if (!isset($m[1])) return null;
            $km = (float)$m[1];
            if ($km <= 0) return null;
            if (isset($m[2]) && $m[2] !== '' && isset($m[3]) && $m[3] !== '') {
                $pace = sprintf('%d:%02d', (int)$m[2], (int)$m[3]);
                $dur = sprintf('0:%02d:00', (int)round($km * ((int)$m[2] + (int)$m[3] / 60)));
                $result['tomorrow'] = ['type' => 'easy', 'description' => "Легкий бег: {$km} км или {$dur}, темп {$pace}"];
            } else {
                $result['tomorrow'] = ['type' => 'easy', 'description' => "Легкий бег: {$km} км"];
            }
        }

        return ($result['today'] && $result['tomorrow']) ? $result : null;
    }
}
