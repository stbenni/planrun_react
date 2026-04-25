<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../planrun_ai/prompt_builder.php';
require_once __DIR__ . '/../../services/TrainingStateBuilder.php';

class VdotCalculationTest extends TestCase {

    // ── 1. Формула estimateVDOT: известные значения из таблиц Daniels ──

    public function test_estimateVDOT_known_values(): void {
        // 5K за 20:00 → ~49.8 VDOT
        $v = estimateVDOT(5.0, 20 * 60);
        $this->assertEqualsWithDelta(49.8, $v, 1.0);

        // 10K за 40:00 → ~51.9 VDOT
        $v = estimateVDOT(10.0, 40 * 60);
        $this->assertEqualsWithDelta(51.9, $v, 1.0);

        // Марафон за 3:00:00 → ~53.5 VDOT
        $v = estimateVDOT(42.195, 3 * 3600);
        $this->assertEqualsWithDelta(53.5, $v, 1.0);

        // Монотонность: одинаковый бегун быстрее на короткой дистанции
        $v5k = estimateVDOT(5.0, 25 * 60);  // 5K за 25:00
        $v10k = estimateVDOT(10.0, 50 * 60); // 10K за 50:00 (тот же темп)
        $this->assertLessThan($v10k, $v5k, 'Same pace, longer race should give higher VDOT');
    }

    public function test_estimateVDOT_clamped_to_bounds(): void {
        // Экстремально быстрый → макс 85
        $v = estimateVDOT(5.0, 10 * 60); // 5K за 10 мин = нереально
        $this->assertLessThanOrEqual(85.0, $v);

        // Экстремально медленный → мин 20
        $v = estimateVDOT(5.0, 60 * 60); // 5K за 60 мин = пешком
        $this->assertGreaterThanOrEqual(20.0, $v);
    }

    // ── 2. predictRaceTime: обратимость с estimateVDOT ──

    public function test_predictRaceTime_roundtrips_with_estimateVDOT(): void {
        $vdot = 50.0;
        $dists = [5.0, 10.0, 21.0975, 42.195];

        foreach ($dists as $km) {
            $predictedSec = predictRaceTime($vdot, $km);
            $recoveredVdot = estimateVDOT($km, $predictedSec);
            $this->assertEqualsWithDelta($vdot, $recoveredVdot, 0.5,
                "Roundtrip failed for {$km} km: predicted {$predictedSec}s → VDOT {$recoveredVdot}");
        }
    }

    // ── 3. getTrainingPaces: зоны монотонно убывают по темпу ──

    public function test_getTrainingPaces_zones_order(): void {
        $paces = getTrainingPaces(50.0);

        // easy slow > easy fast > marathon > threshold > interval > repetition (в сек/км)
        $this->assertGreaterThan($paces['easy'][1], $paces['easy'][0], 'easy_slow should be slower than easy_fast');
        $this->assertGreaterThan($paces['marathon'], $paces['easy'][1], 'easy_fast should be slower than marathon');
        $this->assertGreaterThan($paces['threshold'], $paces['marathon'], 'marathon should be slower than threshold');
        $this->assertGreaterThan($paces['interval'], $paces['threshold'], 'threshold should be slower than interval');
        $this->assertGreaterThan($paces['repetition'], $paces['interval'], 'interval should be slower than repetition');
    }

    // ── 4. Приоритеты источников VDOT ──

    public function test_source_priority_benchmark_override_wins(): void {
        $builder = new \TrainingStateBuilder(getDBConnection());
        $state = $builder->buildForUser([
            'planning_benchmark_distance' => '10k',
            'planning_benchmark_time' => '00:42:00',
            'last_race_distance' => '5k',
            'last_race_time' => '00:20:00',
            'last_race_date' => date('Y-m-d'),
            'easy_pace_sec' => 360,
            'race_distance' => '10k',
            'race_target_time' => '00:40:00',
        ]);

        $this->assertSame('benchmark_override', $state['vdot_source']);
        $this->assertSame('high', $state['vdot_confidence']);
    }

    public function test_source_priority_fresh_last_race_over_easy_pace(): void {
        $builder = new \TrainingStateBuilder(getDBConnection());
        $state = $builder->buildForUser([
            'last_race_distance' => '10k',
            'last_race_time' => '00:45:00',
            'last_race_date' => date('Y-m-d', strtotime('-2 weeks')),
            'easy_pace_sec' => 420,
        ]);

        $this->assertSame('last_race', $state['vdot_source']);
        $this->assertSame('high', $state['vdot_confidence']);
    }

    public function test_source_stale_last_race_not_used_as_fresh(): void {
        $builder = new \TrainingStateBuilder(getDBConnection());
        $state = $builder->buildForUser([
            'last_race_distance' => '10k',
            'last_race_time' => '00:45:00',
            'last_race_date' => date('Y-m-d', strtotime('-10 weeks')),
            'easy_pace_sec' => 420,
        ]);

        // Не должно быть last_race (>8 недель) — должен упасть на easy_pace
        $this->assertNotSame('last_race', $state['vdot_source']);
    }

    // ── 5. target_time применяет коэффициент 0.92 ──

    public function test_target_time_applies_092_coefficient(): void {
        $builder = new \TrainingStateBuilder(getDBConnection());
        $state = $builder->buildForUser([
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_target_time' => '00:45:00',
        ]);

        // Без коэффициента: estimateVDOT(10, 2700) ≈ 42.7
        // С ×0.92: ≈ 39.3
        $rawVdot = estimateVDOT(10.0, 2700);
        $expectedVdot = round($rawVdot * 0.92, 1);

        $this->assertSame('target_time', $state['vdot_source']);
        $this->assertSame('low', $state['vdot_confidence']);
        $this->assertEqualsWithDelta($expectedVdot, $state['vdot'], 0.2,
            "target_time VDOT should be raw ({$rawVdot}) × 0.92 ≈ {$expectedVdot}, got {$state['vdot']}");
        $this->assertLessThan($rawVdot, $state['vdot'], 'target_time VDOT must be less than raw');
    }

    // ── 6. Confidence degradation ──

    public function test_confidence_degrades_with_inactivity(): void {
        $builder = new \TrainingStateBuilder(getDBConnection());

        // Для чистоты: used easy_pace (low confidence), без тренировок
        $state = $builder->buildForUser([
            'easy_pace_sec' => 360,
        ]);

        $this->assertSame('low', $state['vdot_confidence']);
    }

    // ── 7. assessGoalRealism prefers training_state VDOT ──

    public function test_assessGoalRealism_uses_training_state_vdot(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_target_time' => '0:45:00',
            'race_date' => date('Y-m-d', strtotime('+12 weeks')),
            'training_start_date' => date('Y-m-d'),
            'weekly_base_km' => 30,
            'sessions_per_week' => 4,
            'experience_level' => 'intermediate',
            'training_state' => [
                'vdot' => 48.0,
                'vdot_source_label' => 'лучшие свежие тренировки',
                'training_paces' => getTrainingPaces(48.0),
            ],
        ];

        $result = assessGoalRealism($user);

        $this->assertSame(48.0, $result['vdot'], 'Should use VDOT from training_state, not calculate own');
    }

    public function test_assessGoalRealism_falls_back_to_last_race(): void {
        $user = [
            'goal_type' => 'race',
            'race_distance' => '10k',
            'race_target_time' => '0:45:00',
            'race_date' => date('Y-m-d', strtotime('+12 weeks')),
            'training_start_date' => date('Y-m-d'),
            'weekly_base_km' => 30,
            'sessions_per_week' => 4,
            'experience_level' => 'intermediate',
            'last_race_distance' => '5k',
            'last_race_time' => '0:22:00',
        ];

        $result = assessGoalRealism($user);

        // Should calculate from last_race (5K in 22:00)
        $expectedVdot = estimateVDOT(5.0, 22 * 60);
        $this->assertEqualsWithDelta($expectedVdot, $result['vdot'], 0.1);
    }

    public function test_assessGoalRealism_registration_context_is_advisory_not_blocking(): void {
        $user = [
            '_assessment_context' => 'registration',
            'goal_type' => 'race',
            'race_distance' => 'marathon',
            'race_target_time' => '03:30:00',
            'race_date' => date('Y-m-d', strtotime('+6 weeks')),
            'training_start_date' => date('Y-m-d'),
            'weekly_base_km' => 5,
            'sessions_per_week' => 2,
            'experience_level' => 'novice',
        ];

        $result = assessGoalRealism($user);

        $this->assertSame('caution', $result['verdict']);
        $this->assertSame('unrealistic', $result['verdict_original']);
        $this->assertFalse($result['blocks_registration']);
        $this->assertSame('advisory', $result['assessment_mode']);

        foreach ($result['messages'] as $message) {
            $this->assertNotSame('error', $message['type']);
        }
    }

    // ── 8. predictAllRaceTimes возвращает все дистанции ──

    public function test_predictAllRaceTimes_returns_all_distances(): void {
        $predictions = predictAllRaceTimes(50.0);

        $this->assertArrayHasKey('5k', $predictions);
        $this->assertArrayHasKey('10k', $predictions);
        $this->assertArrayHasKey('half', $predictions);
        $this->assertArrayHasKey('marathon', $predictions);

        foreach ($predictions as $label => $pred) {
            $this->assertArrayHasKey('seconds', $pred);
            $this->assertArrayHasKey('formatted', $pred);
            $this->assertArrayHasKey('pace_sec', $pred);
            $this->assertArrayHasKey('pace_formatted', $pred);
            $this->assertGreaterThan(0, $pred['seconds'], "Prediction for {$label} should be positive");
        }

        // Marathon должен быть медленнее 5K
        $this->assertGreaterThan($predictions['5k']['seconds'], $predictions['marathon']['seconds']);
    }

    // ── 9. formatPaceSec и formatTimeSec ──

    public function test_formatPaceSec(): void {
        $this->assertSame('5:00', formatPaceSec(300));
        $this->assertSame('4:30', formatPaceSec(270));
        $this->assertSame('3:05', formatPaceSec(185));
    }

    public function test_formatTimeSec(): void {
        $this->assertSame('20:00', formatTimeSec(1200));
        $this->assertSame('1:30:00', formatTimeSec(5400));
        $this->assertSame('3:00:00', formatTimeSec(10800));
    }

    // ── 10. parseTimeSec edge cases in TrainingStateBuilder ──

    public function test_buildForUser_parses_time_formats_correctly(): void {
        $builder = new \TrainingStateBuilder(getDBConnection());

        // HH:MM:SS формат
        $state = $builder->buildForUser([
            'planning_benchmark_distance' => '10k',
            'planning_benchmark_time' => '00:45:00',
        ]);
        $this->assertSame('benchmark_override', $state['vdot_source']);

        // MM:SS формат
        $state2 = $builder->buildForUser([
            'planning_benchmark_distance' => '5k',
            'planning_benchmark_time' => '22:00',
        ]);
        $this->assertSame('benchmark_override', $state2['vdot_source']);
        $expectedVdot = estimateVDOT(5.0, 22 * 60);
        $this->assertEqualsWithDelta($expectedVdot, $state2['vdot'], 0.1);
    }
}
