<?php
/**
 * Управление долговременной памятью AI-тренера.
 * Автоматически извлекает ключевые факты из диалога и сохраняет
 * в chat_user_memory для подстановки в будущие промпты.
 */

require_once __DIR__ . '/../config/Logger.php';
require_once __DIR__ . '/../user_functions.php';

class ChatMemoryManager {

    private $db;
    private string $llmBaseUrl;
    private string $llmModel;

    private const MAX_MEMORY_LENGTH = 2000;
    private const MAX_FACTS_PER_EXTRACTION = 10;
    private const MIN_MESSAGES_FOR_EXTRACTION = 4;

    public function __construct($db) {
        $this->db = $db;
        $this->llmBaseUrl = rtrim(env('LLM_CHAT_BASE_URL', 'http://127.0.0.1:8081/v1'), '/');
        $this->llmModel = env('LLM_CHAT_MODEL', 'mistralai/ministral-3-14b-reasoning');
    }

    /**
     * Основной метод: извлекает факты из последних сообщений и мержит с памятью.
     * Вызывается после завершения AI-ответа (non-blocking) или по крону.
     */
    public function extractAndSaveMemory(int $userId, array $recentMessages): bool {
        if (count($recentMessages) < self::MIN_MESSAGES_FOR_EXTRACTION) return false;

        $existingMemory = $this->getMemory($userId);
        $newFacts = $this->extractFacts($recentMessages, $existingMemory);
        if (empty($newFacts)) return false;

        $merged = $this->mergeFacts($existingMemory, $newFacts);
        return $this->saveMemory($userId, $merged);
    }

    /**
     * Извлекает факты из диалога с помощью LLM.
     * @return string[] Массив фактов-строк
     */
    private function extractFacts(array $messages, string $existingMemory): array {
        $dialogText = '';
        $count = 0;
        foreach (array_slice($messages, -20) as $m) {
            $role = ($m['sender_type'] ?? '') === 'user' ? 'Пользователь' : 'Тренер';
            $c = trim($m['content'] ?? '');
            if ($c !== '') { $dialogText .= "{$role}: {$c}\n\n"; $count++; }
        }
        if ($count < self::MIN_MESSAGES_FOR_EXTRACTION || mb_strlen($dialogText) < 100) return [];

        $existingSection = $existingMemory !== ''
            ? "ТЕКУЩАЯ ПАМЯТЬ (не дублировать):\n{$existingMemory}\n\n"
            : '';

        $systemPrompt = <<<PROMPT
Ты — модуль извлечения фактов для AI-тренера по бегу. Из диалога ниже извлеки НОВЫЕ конкретные факты о пользователе, которых ещё нет в текущей памяти.

{$existingSection}КАТЕГОРИИ ФАКТОВ:
1. ТРАВМЫ/БОЛИ: конкретные травмы, хронические боли, ограничения
2. ЦЕЛИ: забеги, целевые времена, дистанции, спортивные амбиции
3. ПРЕДПОЧТЕНИЯ: время бега, любимые маршруты, погодные предпочтения, нелюбимые тренировки
4. ПРИВЫЧКИ: регулярность, дни бега, объёмы, темпы
5. ЛИЧНОЕ: работа/график (если влияет на тренировки), другие виды спорта
6. РЕАКЦИИ: что мотивирует, что демотивирует, стиль общения

ПРАВИЛА:
- Только КОНКРЕТНЫЕ факты. Не общие фразы.
- Не дублировать то, что уже в памяти.
- Максимум 10 фактов.
- Каждый факт — одна строка, начинается с категории в квадратных скобках.
- Если новых фактов нет — ответь ПУСТО.

ФОРМАТ:
[КАТЕГОРИЯ] Факт
[КАТЕГОРИЯ] Факт

PROMPT;

        $url = $this->llmBaseUrl . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->llmModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Извлеки факты из диалога:\n\n" . mb_substr($dialogText, 0, 8000)],
                ],
                'stream' => false, 'max_tokens' => 600, 'temperature' => 0.1,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            Logger::warning('Memory extraction LLM call failed', ['http' => $httpCode, 'error' => curl_error($ch)]);
            return [];
        }

        $data = json_decode($response, true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');

        if ($content === '' || mb_stripos($content, 'ПУСТО') !== false || mb_stripos($content, 'пусто') !== false) {
            return [];
        }

        $facts = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) < 10) continue;
            if (!preg_match('/^\[/u', $line)) continue;
            $facts[] = $line;
            if (count($facts) >= self::MAX_FACTS_PER_EXTRACTION) break;
        }

        Logger::info('Memory facts extracted', ['userId' => $userId ?? null, 'facts_count' => count($facts)]);
        return $facts;
    }

    /**
     * Мержит новые факты с существующей памятью.
     * Дедупликация: проверяет по ключевым словам.
     */
    private function mergeFacts(string $existingMemory, array $newFacts): string {
        $existingLines = array_filter(array_map('trim', explode("\n", $existingMemory)));
        $existingLower = array_map('mb_strtolower', $existingLines);

        foreach ($newFacts as $fact) {
            $factLower = mb_strtolower($fact);
            $isDuplicate = false;
            foreach ($existingLower as $existing) {
                if ($this->isSimilarFact($factLower, $existing)) {
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate) {
                $existingLines[] = $fact;
                $existingLower[] = $factLower;
            }
        }

        $result = implode("\n", $existingLines);

        if (mb_strlen($result) > self::MAX_MEMORY_LENGTH) {
            $result = $this->compressMemory($result);
        }

        return $result;
    }

    /**
     * Проверяет похожесть двух фактов (>60% общих значимых слов).
     */
    private function isSimilarFact(string $a, string $b): bool {
        $stopWords = ['и', 'в', 'на', 'не', 'с', 'по', 'из', 'за', 'к', 'о', 'что', 'это', 'как', 'но', 'а', 'или', 'у', 'для', 'бег', 'км', 'мин'];
        $extract = function (string $text) use ($stopWords): array {
            $words = preg_split('/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_filter($words, fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stopWords)));
        };
        $wordsA = $extract($a);
        $wordsB = $extract($b);
        if (empty($wordsA) || empty($wordsB)) return false;
        $common = count(array_intersect($wordsA, $wordsB));
        $minLen = min(count($wordsA), count($wordsB));
        return $minLen > 0 && ($common / $minLen) > 0.6;
    }

    /**
     * Сжимает память если она превысила лимит.
     * Удаляет самые старые факты (верхние строки), сохраняя новые.
     */
    private function compressMemory(string $memory): string {
        $lines = array_filter(array_map('trim', explode("\n", $memory)));
        while (mb_strlen(implode("\n", $lines)) > self::MAX_MEMORY_LENGTH && count($lines) > 5) {
            array_shift($lines);
        }
        return implode("\n", $lines);
    }

    // ── DB operations ──

    public function getMemory(int $userId): string {
        $stmt = $this->db->prepare("SELECT content FROM chat_user_memory WHERE user_id = ?");
        if (!$stmt) return '';
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return trim($row['content'] ?? '');
    }

    public function saveMemory(int $userId, string $content): bool {
        $content = trim($content);
        $stmt = $this->db->prepare(
            "INSERT INTO chat_user_memory (user_id, content) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE content = VALUES(content)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('is', $userId, $content);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) Logger::info('Memory saved', ['userId' => $userId, 'length' => mb_strlen($content)]);
        return $result;
    }

    /**
     * Добавляет один факт в память без LLM-вызова.
     * Для программного использования (из tools, событий и т.д.)
     */
    public function addFact(int $userId, string $category, string $fact): bool {
        $formatted = "[{$category}] {$fact}";
        $existing = $this->getMemory($userId);
        $merged = $this->mergeFacts($existing, [$formatted]);
        return $this->saveMemory($userId, $merged);
    }

    /**
     * Очищает память пользователя.
     */
    public function clearMemory(int $userId): bool {
        return $this->saveMemory($userId, '');
    }
}
