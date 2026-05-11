<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/plan_review_generator.php';

class PlanReviewGeneratorTest extends TestCase {
    public function test_buildPlanSummaryForReview_keeps_explicit_day_types_when_description_exists(): void {
        $planData = [
            'weeks' => [[
                'week_number' => 1,
                'days' => [
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 4.4, 'description' => '4.4 км · 0:22:14'],
                    ['type' => 'easy', 'distance_km' => 4.4, 'description' => '4.4 км · 0:22:14'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'control', 'distance_km' => 21.1, 'description' => '21.1 км · 1:39:10 · 4:42/км'],
                    ['type' => 'rest'],
                ],
            ], [
                'week_number' => 2,
                'days' => [
                    ['type' => 'easy', 'distance_km' => 3.0, 'description' => '3.0 км · 0:15:09'],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 3.0, 'description' => '3.0 км · 0:15:09'],
                    ['type' => 'easy', 'distance_km' => 3.0, 'description' => '3.0 км · 0:15:09'],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'race', 'distance_km' => 42.2, 'description' => '42.2 км · 3:30:18 · 4:59/км'],
                ],
            ]],
        ];

        $summary = buildPlanSummaryForReview($planData, '2026-04-20');

        $this->assertStringContainsString('Контрольный старт — 21.1 км', $summary);
        $this->assertStringContainsString('Главный старт — 42.2 км', $summary);
    }

    public function test_sanitizePlanReviewContent_removes_sentences_that_describe_race_day_as_long_run_build_up(): void {
        $planData = [
            'weeks' => [[
                'days' => [
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 4.4],
                    ['type' => 'easy', 'distance_km' => 4.4],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'control', 'distance_km' => 21.1, 'description' => '21.1 км · 1:39:10 · 4:42/км'],
                    ['type' => 'rest'],
                ],
            ], [
                'days' => [
                    ['type' => 'easy', 'distance_km' => 3.0],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 3.0],
                    ['type' => 'easy', 'distance_km' => 3.0],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'race', 'distance_km' => 42.2, 'description' => '42.2 км · 3:30:18 · 4:59/км'],
                ],
            ]],
        ];

        $content = 'Вторая неделя постепенно увеличивает нагрузку. Удвоение дистанции в длинной пробежке (42,2 км) — готовит к марафону. Темп остаётся стабильным.';

        $sanitized = sanitizePlanReviewContent($content, $planData, '2026-04-20');

        $this->assertStringNotContainsString('Удвоение дистанции', $sanitized);
        $this->assertStringNotContainsString('готовит к марафону', $sanitized);
        $this->assertStringContainsString('сам главный старт', $sanitized);
    }

    public function test_sanitizePlanReviewContent_removes_race_day_activation_framing(): void {
        $planData = [
            'weeks' => [[
                'days' => [
                    ['type' => 'easy', 'distance_km' => 3.0],
                    ['type' => 'rest'],
                    ['type' => 'easy', 'distance_km' => 3.0],
                    ['type' => 'easy', 'distance_km' => 3.0],
                    ['type' => 'rest'],
                    ['type' => 'rest'],
                    ['type' => 'race', 'distance_km' => 42.2, 'description' => '42.2 км · 3:30:18 · 4:59/км'],
                ],
            ]],
        ];

        $content = 'Главный старт (42.2 км, темп 4:59) становится финальной активацией, где темп соответствует целевому, что помогает адаптировать организм к марафонскому темпу без риска перегрузки.';

        $sanitized = sanitizePlanReviewContent($content, $planData, '2026-04-27');

        $this->assertStringNotContainsString('финальной активацией', $sanitized);
        $this->assertStringNotContainsString('адаптировать организм к марафонскому темпу', $sanitized);
        $this->assertStringContainsString('сам главный старт', $sanitized);
    }

    public function test_applyPlanReviewLanguageReplacements_translates_remaining_anglicisms(): void {
        $content = 'Это tune-up перед race. После него нужен recovery, а не quality block. Ключевая активация должна быть спокойной.';

        $sanitized = applyPlanReviewLanguageReplacements($content);

        $this->assertStringNotContainsString('tune-up', $sanitized);
        $this->assertStringNotContainsString('race', $sanitized);
        $this->assertStringNotContainsString('recovery', $sanitized);
        $this->assertStringNotContainsString('quality', $sanitized);
        $this->assertStringContainsString('контрольный старт', $sanitized);
        $this->assertStringContainsString('главный старт', $sanitized);
        $this->assertStringContainsString('восстановление', $sanitized);
    }

    public function test_applyPlanReviewLanguageReplacements_replaces_taper_wording_with_human_russian(): void {
        $content = 'Во второй неделе идёт тейпер перед главным стартом, а в английском шаблоне был taper.';

        $sanitized = applyPlanReviewLanguageReplacements($content);

        $this->assertStringNotContainsString('тейпер', mb_strtolower($sanitized, 'UTF-8'));
        $this->assertStringNotContainsString('taper', mb_strtolower($sanitized, 'UTF-8'));
        $this->assertStringContainsString('подводка к старту', $sanitized);
    }

    /**
     * PR9: facts-блок — только сухие данные (цель, прогноз, таргет, gap, severity).
     * Никаких готовых формулировок «не достижима» / «амбициозная» — модель сама
     * пишет тренерскую фразу из этих фактов.
     */
    public function test_buildRealismFacts_lists_target_facts_without_interpretation(): void {
        $facts = buildRealismFactsForReview([
            'severity' => 'major',
            'goal_target_time' => '3:15:00',
            'predicted_target_time' => '3:25:00',
            'effective_target_time' => '3:25:00',
            'race_distance_label' => 'марафон',
            'gap_pct' => 4.9,
        ]);

        $this->assertStringContainsString('Контекст по цели:', $facts);
        $this->assertStringContainsString('цель в профиле: 3:15:00', $facts);
        $this->assertStringContainsString('реалистичный прогноз по текущей форме: 3:25:00', $facts);
        $this->assertStringContainsString('таргет, под который реально рассчитан план: 3:25:00', $facts);
        $this->assertStringContainsString('марафон', $facts);
        $this->assertStringContainsString('severity: major', $facts);
        // Запрещённый хардкод: ни «не достижима», ни «амбициозная» — это интерпретация
        $this->assertStringNotContainsString('не достижима', $facts);
        $this->assertStringNotContainsString('амбициозная', $facts);
        $this->assertStringNotContainsString('подтягиваются к цели', $facts);
    }

    public function test_buildRealismDirective_is_neutral_and_not_a_template_phrase(): void {
        $directive = buildRealismDirectiveForReview(['severity' => 'major']);
        $this->assertStringContainsString('Контекст по цели', $directive);
        $this->assertStringContainsString('первой фразе', $directive);
        // Не диктуем готовые шаблоны и не навязываем интерпретацию темпов
        $this->assertStringNotContainsString('не достижима', $directive);
        $this->assertStringNotContainsString('амбициозная', $directive);
        $this->assertStringNotContainsString('темпы темповых и интервалов', $directive);
        $this->assertStringNotContainsString('подтягиваются к цели', $directive);
    }

    public function test_buildRealismFacts_returns_empty_for_realistic_severity(): void {
        $this->assertSame('', buildRealismFactsForReview(['severity' => 'none']));
        $this->assertSame('', buildRealismFactsForReview(null));
    }

    public function test_buildRealismDirective_returns_empty_for_realistic_severity(): void {
        $this->assertSame('', buildRealismDirectiveForReview(['severity' => 'none']));
        $this->assertSame('', buildRealismDirectiveForReview(null));
    }

    public function test_buildRealismFacts_works_for_moderate_severity_too(): void {
        $facts = buildRealismFactsForReview([
            'severity' => 'moderate',
            'goal_target_time' => '3:30:00',
            'predicted_target_time' => '3:38:00',
            'effective_target_time' => '3:30:00',
            'race_distance_label' => 'марафон',
            'gap_pct' => 3.7,
        ]);

        $this->assertStringContainsString('severity: moderate', $facts);
        $this->assertStringContainsString('цель в профиле: 3:30:00', $facts);
    }

    public function test_polishPlanReviewTone_makes_text_shorter_and_less_bureaucratic(): void {
        $content = 'План построен с учётом ближайших ключевых событий: контрольный старт 26 апреля и главный старт 3 мая. Такой подход помогает сохранить баланс. Контрольный старт выступает как проверка формы. Лёгкие пробежки служат именно для поддержания тонуса. После них идёт восстановление, чтобы не допустить переутомления. Такой подход снижает риск усталости.';

        $polished = polishPlanReviewTone($content);

        $this->assertStringNotContainsString('План построен с учётом', $polished);
        $this->assertStringNotContainsString('Такой подход', $polished);
        $this->assertStringNotContainsString('выступает как', $polished);
        $this->assertStringContainsString('контрольный старт', $polished);
        $this->assertStringContainsString('главный старт', $polished);
        $sentences = preg_split('/(?<=[.!?])\s+/u', str_replace("\n", ' ', $polished), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertIsArray($sentences);
        $this->assertLessThanOrEqual(5, count($sentences));
    }
}
