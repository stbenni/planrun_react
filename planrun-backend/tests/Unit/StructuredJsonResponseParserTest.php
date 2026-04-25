<?php

require_once __DIR__ . '/../../planrun_ai/skeleton/StructuredJsonResponseParser.php';

use PHPUnit\Framework\TestCase;

final class StructuredJsonResponseParserTest extends TestCase
{
    public function testParsesNotesPayloadFromNoisyResponse(): void
    {
        $response = <<<TXT
Конечно.
{
  "notes": [
    {
      "week_number": 3,
      "day_of_week": 5,
      "notes": "Держи лёгкий темп и оставь запас."
    }
  ]
}
TXT;

        $parsed = StructuredJsonResponseParser::parseNotesPayload($response);

        $this->assertIsArray($parsed);
        $this->assertCount(1, $parsed['notes']);
        $this->assertSame(3, $parsed['notes'][0]['week_number']);
        $this->assertSame('Держи лёгкий темп и оставь запас.', $parsed['notes'][0]['notes']);
    }

    public function testParsesWeeksPayloadFromMarkdownWrappedResponse(): void
    {
        $response = <<<TXT
Вот результат.

```json
[
  {
    "week_number": 1,
    "days": [
      {"day_of_week": 1, "type": "easy", "notes": "Лёгкий бег 6 км"}
    ]
  }
]
```
TXT;

        $parsed = StructuredJsonResponseParser::parseWeeksPayload($response);

        $this->assertIsArray($parsed);
        $this->assertSame(1, $parsed['weeks'][0]['week_number']);
        $this->assertSame('Лёгкий бег 6 км', $parsed['weeks'][0]['days'][0]['notes']);
    }

    public function testParsesReviewPayloadFromNoisyResponse(): void
    {
        $response = <<<TXT
Смотри, нашёл одно замечание.
{
  "status": "has_issues",
  "issues": [
    {
      "week": 2,
      "day_of_week": 7,
      "type": "too_aggressive",
      "description": "слишком агрессивно",
      "fix_suggestion": "сделать мягче"
    }
  ]
}
Спасибо.
TXT;

        $parsed = StructuredJsonResponseParser::parseReviewPayload($response);

        $this->assertIsArray($parsed);
        $this->assertSame('has_issues', $parsed['status']);
        $this->assertCount(1, $parsed['issues']);
        $this->assertSame('too_aggressive', $parsed['issues'][0]['type']);
    }

    public function testParsesReviewPayloadWithoutExplicitStatus(): void
    {
        $response = <<<TXT
{
  "issues": [
    {
      "week": 4,
      "type": "volume_jump",
      "description": "скачок объёма"
    }
  ]
}
TXT;

        $parsed = StructuredJsonResponseParser::parseReviewPayload($response);

        $this->assertIsArray($parsed);
        $this->assertSame('has_issues', $parsed['status']);
        $this->assertCount(1, $parsed['issues']);
        $this->assertSame('volume_jump', $parsed['issues'][0]['type']);
    }

    public function testParsesReviewPayloadFromPlainIssueList(): void
    {
        $response = <<<TXT
[
  {
    "week": 6,
    "type": "taper_violation",
    "description": "неделя перед стартом слишком тяжёлая"
  }
]
TXT;

        $parsed = StructuredJsonResponseParser::parseReviewPayload($response);

        $this->assertIsArray($parsed);
        $this->assertSame('has_issues', $parsed['status']);
        $this->assertCount(1, $parsed['issues']);
        $this->assertSame('taper_violation', $parsed['issues'][0]['type']);
    }

    public function testParsesReviewPayloadTreatsSuccessStatusAsOk(): void
    {
        $response = <<<TXT
{
  "status": "success",
  "issues": []
}
TXT;

        $parsed = StructuredJsonResponseParser::parseReviewPayload($response);

        $this->assertIsArray($parsed);
        $this->assertSame('ok', $parsed['status']);
        $this->assertSame([], $parsed['issues']);
    }

    public function testParsesReviewPayloadWithThinkBlockErrorsAndTrailingCommas(): void
    {
        $response = <<<TXT
<think>Я сначала порассуждаю, но это не должно попасть в JSON.</think>
{
  // Qwen иногда добавляет комментарии и trailing comma
  "valid": false,
  "errors": [
    "Пиковая длительная слишком короткая для марафона",
  ],
}
TXT;

        $parsed = StructuredJsonResponseParser::parseReviewPayload($response);

        $this->assertIsArray($parsed);
        $this->assertSame('has_issues', $parsed['status']);
        $this->assertSame('Пиковая длительная слишком короткая для марафона', $parsed['issues'][0]['description']);
    }

    public function testParsesReviewPayloadTreatsEmptyIssueArrayAsOk(): void
    {
        $parsed = StructuredJsonResponseParser::parseReviewPayload('[]');

        $this->assertIsArray($parsed);
        $this->assertSame('ok', $parsed['status']);
        $this->assertSame([], $parsed['issues']);
    }

    public function testParsesNotesPayloadFromWeekDayMap(): void
    {
        $response = <<<TXT
{
  "notes": {
    "1:5": "Держи разговорный темп.",
    "2-7": {"text": "Длительную не ускорять в конце."}
  }
}
TXT;

        $parsed = StructuredJsonResponseParser::parseNotesPayload($response);

        $this->assertIsArray($parsed);
        $this->assertCount(2, $parsed['notes']);
        $this->assertSame(1, $parsed['notes'][0]['week_number']);
        $this->assertSame(5, $parsed['notes'][0]['day_of_week']);
        $this->assertSame('Держи разговорный темп.', $parsed['notes'][0]['notes']);
        $this->assertSame('Длительную не ускорять в конце.', $parsed['notes'][1]['notes']);
    }
}
