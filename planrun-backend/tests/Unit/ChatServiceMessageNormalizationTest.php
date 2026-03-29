<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/ChatPromptBuilder.php';

class ChatServiceMessageNormalizationTest extends TestCase {
    public function test_normalizeMessagesForStrictAlternation_merges_consecutive_roles_and_folds_leading_assistant(): void {
        $db = getDBConnection();
        require_once __DIR__ . '/../../services/ChatContextBuilder.php';
        require_once __DIR__ . '/../../repositories/ChatRepository.php';
        $builder = new \ChatPromptBuilder($db, new \ChatContextBuilder($db), new \ChatRepository($db));
        $ref = new \ReflectionClass($builder);
        $method = $ref->getMethod('normalizeMessagesForStrictAlternation');
        $method->setAccessible(true);

        $normalized = $method->invoke($builder, [
            ['role' => 'system', 'content' => 'base system'],
            ['role' => 'assistant', 'content' => 'Служебное уведомление 1'],
            ['role' => 'assistant', 'content' => 'Служебное уведомление 2'],
            ['role' => 'user', 'content' => 'Привет'],
            ['role' => 'user', 'content' => 'Ещё один вопрос'],
            ['role' => 'assistant', 'content' => 'Ответ 1'],
            ['role' => 'assistant', 'content' => 'Ответ 2'],
        ]);

        $this->assertCount(3, $normalized);
        $this->assertSame('system', $normalized[0]['role']);
        $this->assertStringContainsString('Служебное уведомление 1', $normalized[0]['content']);
        $this->assertStringContainsString('Служебное уведомление 2', $normalized[0]['content']);

        $this->assertSame('user', $normalized[1]['role']);
        $this->assertStringContainsString('Привет', $normalized[1]['content']);
        $this->assertStringContainsString('Ещё один вопрос', $normalized[1]['content']);

        $this->assertSame('assistant', $normalized[2]['role']);
        $this->assertStringContainsString('Ответ 1', $normalized[2]['content']);
        $this->assertStringContainsString('Ответ 2', $normalized[2]['content']);
    }
}
