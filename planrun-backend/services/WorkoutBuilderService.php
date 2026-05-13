<?php
/**
 * Конструктор ОФП/СБУ-тренировок из exercise_library.
 *
 * AI-планировщик не справляется с генерацией структурированных силовых сессий,
 * поэтому когда мы детерминированно вставляем ОФП-день — используем готовые
 * runner-specific templates из библиотеки упражнений.
 */

class WorkoutBuilderService {

    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Собрать ОФП-сессию для бегуна (5-6 упражнений, ~30-40 минут).
     *
     * @param string $preference  'gym' | 'home' | 'both' | 'group_classes' | 'online'
     * @return array  exercises[] в формате training_day_exercises
     */
    /**
     * Маппинг название упражнения → коэффициент к bodyweight.
     * Calibrated against typical intermediate lifter ratios.
     */
    private const BODYWEIGHT_FACTORS = [
        'жим ногами' => 1.5,
        'приседания со штангой' => 0.9,
        'жим лежа' => 0.75,
        'тяга верхнего блока' => 0.6,
        'жим гантелей сидя' => 0.18,
        'разгибание ног' => 0.45,
        'сгибание ног' => 0.4,
    ];

    /**
     * Подбирает вес упражнения как функцию bodyweight + experience_level.
     * Не персонализирует bodyweight-exercises (планка/отжимания/подтягивания/пресс).
     */
    private function computePersonalizedWeight(string $exerciseName, ?float $bodyweight, ?string $experienceLevel): ?float {
        if ($bodyweight === null || $bodyweight < 30 || $bodyweight > 200) return null;
        $key = mb_strtolower($exerciseName);
        $factor = null;
        foreach (self::BODYWEIGHT_FACTORS as $needle => $f) {
            if (str_contains($key, $needle)) {
                $factor = $f;
                break;
            }
        }
        if ($factor === null) return null;

        // Experience scaling
        $expScale = 1.0;
        $exp = strtolower((string) $experienceLevel);
        if (str_contains($exp, 'novice') || str_contains($exp, 'beginner')) $expScale = 0.7;
        elseif (str_contains($exp, 'advanced') || str_contains($exp, 'expert')) $expScale = 1.2;

        $weight = $bodyweight * $factor * $expScale;
        // Округляем до 2.5 кг (стандартные блины)
        return round($weight / 2.5) * 2.5;
    }

    public function buildOfpSession(string $preference = 'gym', ?float $bodyweight = null, ?string $experienceLevel = null, ?int $userId = null): array {
        $library = $this->loadLibrary('ofp');
        if (empty($library)) {
            return $this->fallbackBodyweightOfp();
        }

        // Подгружаем executed history для personalized weights / progressive overload.
        $executedSvc = null;
        if ($userId !== null && $userId > 0) {
            try {
                require_once __DIR__ . '/ExecutedExerciseService.php';
                $executedSvc = new ExecutedExerciseService($this->db);
            } catch (Throwable $e) {
                $executedSvc = null;
            }
        }

        $byName = [];
        foreach ($library as $ex) {
            $byName[mb_strtolower($ex['name'])] = $ex;
        }

        // Template runner-specific ОФП: legs (compound) + calves + core + upper + push
        $templates = [
            'gym' => [
                ['name_match' => 'приседания со штангой', 'fallback' => 'приседания'],
                ['name_match' => 'тяга верхнего блока', 'fallback' => 'подтягивания'],
                ['name_match' => 'подъемы на носки', 'fallback' => null],
                ['name_match' => 'жим лежа', 'fallback' => 'отжимания'],
                ['name_match' => 'планка', 'fallback' => null],
                ['name_match' => 'пресс', 'fallback' => null],
            ],
            'home' => [
                ['name_match' => 'приседания со штангой', 'fallback' => 'приседания'],
                ['name_match' => 'отжимания', 'fallback' => null],
                ['name_match' => 'подтягивания', 'fallback' => null],
                ['name_match' => 'подъемы на носки', 'fallback' => null],
                ['name_match' => 'планка', 'fallback' => null],
                ['name_match' => 'пресс', 'fallback' => null],
            ],
        ];

        $tmplKey = in_array($preference, ['home', 'online'], true) ? 'home' : 'gym';
        $template = $templates[$tmplKey];

        $exercises = [];
        $order = 0;
        $usedIds = [];
        foreach ($template as $slot) {
            $ex = $this->findExerciseByName($byName, $slot['name_match'], $slot['fallback']);
            if ($ex === null || isset($usedIds[$ex['id']])) continue;
            $usedIds[$ex['id']] = true;

            // Приоритет весов: executed history → personalized (bodyweight × factor × exp_scale) → library default.
            $weight = null;
            $weightSource = 'default';
            if ($executedSvc !== null) {
                $hist = $executedSvc->getLastExecuted($userId, $ex['name'], (int) $ex['id']);
                if ($hist !== null && $hist['last_weight_kg'] !== null) {
                    // Progressive overload: если последняя сессия была >= 14 дней назад
                    // и атлет выполнил все sets — добавляем +2.5 кг.
                    $weight = (float) $hist['last_weight_kg'];
                    $daysAgo = (int) ((time() - strtotime((string) $hist['last_executed_date'])) / 86400);
                    if ($daysAgo >= 14 && $hist['last_sets'] !== null && $ex['default_sets'] !== null
                        && $hist['last_sets'] >= (int) $ex['default_sets']) {
                        $weight = round(($weight + 2.5) / 2.5) * 2.5;
                        $weightSource = 'executed+overload';
                    } else {
                        $weightSource = 'executed';
                    }
                }
            }
            if ($weight === null) {
                $weight = $this->computePersonalizedWeight($ex['name'], $bodyweight, $experienceLevel);
                if ($weight !== null) $weightSource = 'bodyweight_formula';
                else {
                    $weight = $ex['default_weight_kg'] !== null ? (float) $ex['default_weight_kg'] : null;
                }
            }

            $exercises[] = [
                'category' => 'ofp',
                'exercise_id' => (int) $ex['id'],
                'name' => $ex['name'],
                'sets' => $ex['default_sets'] !== null ? (int) $ex['default_sets'] : null,
                'reps' => $ex['default_reps'] !== null ? (int) $ex['default_reps'] : null,
                'distance_m' => $ex['default_distance_m'] !== null ? (int) $ex['default_distance_m'] : null,
                'duration_sec' => $ex['default_duration_sec'] !== null ? (int) $ex['default_duration_sec'] : null,
                'weight_kg' => $weight,
                'pace' => null,
                'notes' => $ex['default_notes'] ?? null,
                'weight_source' => $weightSource,
                'order_index' => $order++,
            ];
        }

        if (empty($exercises)) {
            return $this->fallbackBodyweightOfp();
        }
        return $exercises;
    }

    /**
     * Собрать СБУ-сессию (5-6 упражнений ~20 минут).
     */
    public function buildSbuSession(): array {
        $library = $this->loadLibrary('sbu');
        if (empty($library)) {
            return [];
        }

        // Стандартный набор для бегуна: основные дриллы + плиометрика
        $preferredOrder = [
            'высокий подъем бедра',
            'захлест голени',
            'бег с высоким подниманием',
            'олений бег',
            'многоскоки',
            'бег с подскоками',
        ];

        $byName = [];
        foreach ($library as $ex) {
            $byName[mb_strtolower($ex['name'])] = $ex;
        }

        $exercises = [];
        $order = 0;
        $usedIds = [];
        foreach ($preferredOrder as $match) {
            $ex = $this->findExerciseByName($byName, $match, null);
            if ($ex === null || isset($usedIds[$ex['id']])) continue;
            $usedIds[$ex['id']] = true;

            $exercises[] = [
                'category' => 'sbu',
                'exercise_id' => (int) $ex['id'],
                'name' => $ex['name'],
                'sets' => $ex['default_sets'] !== null ? (int) $ex['default_sets'] : 3,
                'reps' => $ex['default_reps'] !== null ? (int) $ex['default_reps'] : null,
                'distance_m' => $ex['default_distance_m'] !== null ? (int) $ex['default_distance_m'] : 30,
                'duration_sec' => $ex['default_duration_sec'] !== null ? (int) $ex['default_duration_sec'] : null,
                'weight_kg' => null,
                'pace' => null,
                'notes' => $ex['default_notes'] ?? null,
                'order_index' => $order++,
            ];
        }
        return $exercises;
    }

    public function buildOfpDescription(array $exercises): string {
        if (empty($exercises)) return 'ОФП';
        $lines = [];
        foreach ($exercises as $ex) {
            $line = $ex['name'];
            if (!empty($ex['sets']) && !empty($ex['reps'])) {
                $line .= " — {$ex['sets']}×{$ex['reps']}";
                if (!empty($ex['weight_kg'])) $line .= ", " . (int) $ex['weight_kg'] . " кг";
            } elseif (!empty($ex['sets']) && !empty($ex['duration_sec'])) {
                $line .= " — {$ex['sets']}× по " . (int) $ex['duration_sec'] . " сек";
            } elseif (!empty($ex['duration_sec'])) {
                $line .= " — " . (int) $ex['duration_sec'] . " сек";
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    // ── Internal ──

    private function loadLibrary(string $category): array {
        $stmt = $this->db->prepare(
            "SELECT id, name, default_description, default_sets, default_reps,
                    default_distance_m, default_duration_sec, default_weight_kg,
                    default_notes
             FROM exercise_library
             WHERE category = ? AND is_active = 1"
        );
        if (!$stmt) return [];
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        return $rows;
    }

    private function findExerciseByName(array $byName, string $matchSubstring, ?string $fallbackSubstring): ?array {
        $matchSubstring = mb_strtolower($matchSubstring);
        foreach ($byName as $lcName => $ex) {
            if (str_contains($lcName, $matchSubstring)) return $ex;
        }
        if ($fallbackSubstring !== null) {
            $fallbackSubstring = mb_strtolower($fallbackSubstring);
            foreach ($byName as $lcName => $ex) {
                if (str_contains($lcName, $fallbackSubstring)) return $ex;
            }
        }
        return null;
    }

    private function fallbackBodyweightOfp(): array {
        // Если exercise_library пустая или недоступна — minimal hard-coded set.
        return [
            ['category' => 'ofp', 'exercise_id' => null, 'name' => 'Приседания', 'sets' => 4, 'reps' => 15, 'distance_m' => null, 'duration_sec' => null, 'weight_kg' => null, 'pace' => null, 'notes' => null, 'order_index' => 0],
            ['category' => 'ofp', 'exercise_id' => null, 'name' => 'Отжимания', 'sets' => 3, 'reps' => 15, 'distance_m' => null, 'duration_sec' => null, 'weight_kg' => null, 'pace' => null, 'notes' => null, 'order_index' => 1],
            ['category' => 'ofp', 'exercise_id' => null, 'name' => 'Планка', 'sets' => 3, 'reps' => null, 'distance_m' => null, 'duration_sec' => 60, 'weight_kg' => null, 'pace' => null, 'notes' => null, 'order_index' => 2],
            ['category' => 'ofp', 'exercise_id' => null, 'name' => 'Выпады', 'sets' => 3, 'reps' => 12, 'distance_m' => null, 'duration_sec' => null, 'weight_kg' => null, 'pace' => null, 'notes' => null, 'order_index' => 3],
            ['category' => 'ofp', 'exercise_id' => null, 'name' => 'Подъёмы на носки', 'sets' => 3, 'reps' => 20, 'distance_m' => null, 'duration_sec' => null, 'weight_kg' => null, 'pace' => null, 'notes' => null, 'order_index' => 4],
            ['category' => 'ofp', 'exercise_id' => null, 'name' => 'Скручивания на пресс', 'sets' => 3, 'reps' => 20, 'distance_m' => null, 'duration_sec' => null, 'weight_kg' => null, 'pace' => null, 'notes' => null, 'order_index' => 5],
        ];
    }
}
