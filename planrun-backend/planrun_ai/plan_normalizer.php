<?php
/**
 * Нормализатор плана тренировок.
 *
 * Принимает сырой JSON от ИИ и возвращает чистый, валидный массив,
 * готовый для сохранения в БД или для отправки на фронтенд.
 * Не зависит от БД — чистая трансформация данных.
 */

require_once __DIR__ . '/description_parser.php';

const PLAN_TYPE_LABELS = [
    'easy'     => 'Лёгкий бег',
    'long'     => 'Длительный бег',
    'tempo'    => 'Темповый бег',
    'race'     => 'Соревнование',
    'interval' => 'Интервалы',
    'fartlek'  => 'Фартлек',
    'control'  => 'Контрольная тренировка',
    'walking'  => 'Ходьба',
];

const PLAN_REST_TYPE_MAP = [
    'jog'  => 'трусцой',
    'walk' => 'ходьбой',
    'rest' => 'отдых',
];

/**
 * Парсит строку темпа "M:SS" в секунды на км.
 */
function parsePaceToSeconds(?string $pace): ?int {
    if ($pace === null || $pace === '') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($pace), $m)) {
        return (int)$m[1] * 60 + (int)$m[2];
    }
    return null;
}

/**
 * Форматирует секунды в "M:SS".
 */
function formatPaceFromSec(int $sec): string {
    return floor($sec / 60) . ':' . str_pad($sec % 60, 2, '0', STR_PAD_LEFT);
}

/**
 * Форматирует длительность в секундах в "Ч:ММ:СС".
 */
function formatDurationHMS(int $totalSec): string {
    $h = (int) floor($totalSec / 3600);
    $m = (int) floor(($totalSec % 3600) / 60);
    $s = $totalSec % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}

/**
 * Рассчитывает duration_minutes из distance_km и pace "M:SS".
 */
function calculateDurationMinutes(?float $distKm, ?string $pace): ?int {
    if ($distKm === null || $distKm <= 0) return null;
    $paceSec = parsePaceToSeconds($pace);
    if ($paceSec === null || $paceSec <= 0) return null;
    return (int) round($distKm * $paceSec / 60);
}

/**
 * Рассчитывает суммарную дистанцию интервальной тренировки.
 */
function calculateIntervalTotalKm(array $day): float {
    $warmup = (float)($day['warmup_km'] ?? 0);
    $cooldown = (float)($day['cooldown_km'] ?? 0);
    $reps = (int)($day['reps'] ?? 0);
    $intervalM = (int)($day['interval_m'] ?? 0);
    $restM = (int)($day['rest_m'] ?? 0);
    return $warmup + ($reps * ($intervalM + $restM)) / 1000.0 + $cooldown;
}

/**
 * Рассчитывает суммарную дистанцию фартлека.
 */
function calculateFartlekTotalKm(array $day): float {
    $warmup = (float)($day['warmup_km'] ?? 0);
    $cooldown = (float)($day['cooldown_km'] ?? 0);
    $total = $warmup + $cooldown;
    $segments = $day['segments'] ?? [];
    if (is_array($segments)) {
        foreach ($segments as $seg) {
            $reps = (int)($seg['reps'] ?? 0);
            $distM = (int)($seg['distance_m'] ?? 0);
            $recM = (int)($seg['recovery_m'] ?? 0);
            $total += ($reps * ($distM + $recM)) / 1000.0;
        }
    }
    return $total;
}

/**
 * Строит description из структурированных полей (новый формат LLM).
 *
 * Формат description совпадает с regex-шаблонами AddTrainingModal.jsx.
 */
function buildDescriptionFromFields(array $day): string {
    $type = $day['type'] ?? 'rest';

    // Простой бег (включая контрольную)
    if (in_array($type, ['easy', 'long', 'tempo', 'race', 'control', 'walking'], true)) {
        $label = PLAN_TYPE_LABELS[$type] ?? 'Бег';
        $dist = $day['distance_km'] ?? null;
        $pace = $day['pace'] ?? null;

        if ($dist !== null && $dist > 0) {
            $lines = [];
            $paceSec = parsePaceToSeconds($pace);
            if ($paceSec !== null && $paceSec > 0) {
                $totalSec = (int) round($dist * $paceSec);
                $lines[] = "{$dist} км · " . formatDurationHMS($totalSec);
                $lines[] = "Темп: {$pace} мин/км";
            } else {
                $lines[] = "{$dist} км";
            }
            $notes = $day['notes'] ?? '';
            if ($notes !== '') {
                $lines[] = $notes;
            }
            return implode("\n", $lines);
        }
        $notes = $day['notes'] ?? '';
        if ($notes !== '') return $notes;
        $durMin = $day['duration_minutes'] ?? null;
        if ($durMin !== null && $durMin > 0) {
            return "{$durMin} мин";
        }
        return $label;
    }

    // Интервалы
    if ($type === 'interval') {
        $warmup = $day['warmup_km'] ?? 2;
        $reps = (int)($day['reps'] ?? 0);
        $intervalM = (int)($day['interval_m'] ?? 0);
        $intPace = $day['interval_pace'] ?? '';
        $restM = (int)($day['rest_m'] ?? 0);
        $restType = PLAN_REST_TYPE_MAP[$day['rest_type'] ?? 'jog'] ?? 'трусцой';
        $cooldown = $day['cooldown_km'] ?? 1.5;

        $desc = "Разминка: {$warmup} км. ";
        $desc .= "{$reps}" . "\xC3\x97" . "{$intervalM}м";
        if ($intPace !== '') {
            $desc .= " в темпе {$intPace}";
        }
        if ($restM > 0) {
            $desc .= ", пауза {$restM}м {$restType}";
        }
        $desc .= ". Заминка: {$cooldown} км";
        return $desc;
    }

    // Фартлек
    if ($type === 'fartlek') {
        $warmup = $day['warmup_km'] ?? 2;
        $cooldown = $day['cooldown_km'] ?? 1.5;
        $segments = $day['segments'] ?? [];

        $desc = "Разминка: {$warmup} км. ";
        $segParts = [];
        foreach ($segments as $seg) {
            $reps = (int)($seg['reps'] ?? 0);
            $distM = (int)($seg['distance_m'] ?? 0);
            $segPace = $seg['pace'] ?? null;
            $recM = (int)($seg['recovery_m'] ?? 0);
            $recType = PLAN_REST_TYPE_MAP[$seg['recovery_type'] ?? 'jog'] ?? 'трусцой';

            $part = "{$reps}" . "\xC3\x97" . "{$distM}м";
            if ($segPace !== null && $segPace !== '') {
                $part .= " в темпе {$segPace}";
            }
            if ($recM > 0) {
                $part .= ", восстановление {$recM}м {$recType}";
            }
            $segParts[] = $part;
        }
        $desc .= implode('. ', $segParts);
        $desc .= ". Заминка: {$cooldown} км";
        return $desc;
    }

    // ОФП
    if ($type === 'other') {
        $exercises = $day['exercises'] ?? [];
        $lines = [];
        foreach ($exercises as $ex) {
            $name = $ex['name'] ?? 'Упражнение';
            if (!empty($ex['sets']) && !empty($ex['reps'])) {
                $line = "{$name} \xE2\x80\x94 {$ex['sets']}\xC3\x97{$ex['reps']}";
                if (!empty($ex['weight_kg'])) {
                    $line .= ", {$ex['weight_kg']} кг";
                }
                $lines[] = $line;
            } elseif (!empty($ex['duration_min'])) {
                $lines[] = "{$name} \xE2\x80\x94 {$ex['duration_min']} мин";
            } else {
                $lines[] = $name;
            }
        }
        return implode("\n", $lines);
    }

    // СБУ
    if ($type === 'sbu') {
        $exercises = $day['exercises'] ?? [];
        $lines = [];
        foreach ($exercises as $ex) {
            $name = $ex['name'] ?? 'Упражнение';
            $distM = $ex['distance_m'] ?? null;
            if ($distM !== null && $distM > 0) {
                $lines[] = "{$name} \xE2\x80\x94 {$distM} м";
            } else {
                $lines[] = $name;
            }
        }
        return implode("\n", $lines);
    }

    // rest / free
    return '';
}

/**
 * Определяет, содержит ли день новый структурированный формат (без description).
 */
function hasStructuredFields(array $day): bool {
    if (!empty($day['warmup_km']) || !empty($day['reps']) || !empty($day['interval_m'])) return true;
    if (!empty($day['segments'])) return true;
    if (!empty($day['exercises'])) return true;
    if (!empty($day['notes'])) return true;
    $type = strtolower(trim($day['type'] ?? ''));
    if (in_array($type, ['easy', 'long', 'tempo', 'race', 'control'], true)) {
        if (!empty($day['distance_km'])) return true; // distance_km = structured, даже если LLM добавил description
    }
    if ($type === 'rest' && empty($day['description'])) return true;
    return false;
}

const PLAN_TYPE_MAP = [
    'easy_run'  => 'easy',
    'easy'      => 'easy',
    'interval'  => 'interval',
    'tempo'     => 'tempo',
    'long_run'  => 'long',
    'long'      => 'long',
    'long-run'  => 'long',
    'rest'      => 'rest',
    'ofp'       => 'other',
    'marathon'  => 'long',
    'control'   => 'control',
    'race'      => 'race',
    'fartlek'   => 'fartlek',
    'sbu'       => 'sbu',
    'free'      => 'free',
    'other'     => 'other',
    'walking'   => 'walking',
    'walk'      => 'walking',
    'recovery_walk' => 'walking',
    'recovery-walk' => 'walking',
];

const PLAN_ALLOWED_TYPES = ['rest', 'tempo', 'interval', 'long', 'race', 'other', 'free', 'easy', 'sbu', 'fartlek', 'control', 'walking'];

const PLAN_KEY_WORKOUT_TYPES = ['interval', 'tempo', 'long', 'fartlek', 'race', 'control'];

const PLAN_RUN_TYPES = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'race', 'control'];

const PLAN_DAY_KEYS = [
    0 => 'mon',
    1 => 'tue',
    2 => 'wed',
    3 => 'thu',
    4 => 'fri',
    5 => 'sat',
    6 => 'sun',
];

const PLAN_DAY_KEY_TO_INDEX = [
    'mon' => 0,
    'tue' => 1,
    'wed' => 2,
    'thu' => 3,
    'fri' => 4,
    'sat' => 5,
    'sun' => 6,
];

const PLAN_REST_RUN_KEYWORDS = '/бег|км|темп|мин\/км|трусцой|лёгкий|легкий|дистанция|интервал/iu';

/**
 * Нормализует тип тренировки.
 *
 * @param string|null $rawType Сырой тип из ИИ
 * @return string Нормализованный тип из ENUM
 */
function normalizeTrainingType(?string $rawType): string {
    if ($rawType === null || $rawType === '') {
        return 'rest';
    }
    $lower = trim(strtolower($rawType));
    if (isset(PLAN_TYPE_MAP[$lower])) {
        return PLAN_TYPE_MAP[$lower];
    }
    if (in_array($lower, PLAN_ALLOWED_TYPES, true)) {
        return $lower;
    }
    return 'rest';
}

function normalizePreferredDayKeys(array $days): array {
    $normalized = [];
    $aliases = [
        'monday' => 'mon',
        'mon' => 'mon',
        'понедельник' => 'mon',
        'пн' => 'mon',
        'tuesday' => 'tue',
        'tue' => 'tue',
        'вторник' => 'tue',
        'вт' => 'tue',
        'wednesday' => 'wed',
        'wed' => 'wed',
        'среда' => 'wed',
        'ср' => 'wed',
        'thursday' => 'thu',
        'thu' => 'thu',
        'четверг' => 'thu',
        'чт' => 'thu',
        'friday' => 'fri',
        'fri' => 'fri',
        'пятница' => 'fri',
        'пт' => 'fri',
        'saturday' => 'sat',
        'sat' => 'sat',
        'суббота' => 'sat',
        'сб' => 'sat',
        'sunday' => 'sun',
        'sun' => 'sun',
        'воскресенье' => 'sun',
        'вс' => 'sun',
    ];

    foreach ($days as $day) {
        $key = mb_strtolower(trim((string) $day), 'UTF-8');
        if (isset($aliases[$key])) {
            $normalized[$aliases[$key]] = true;
        }
    }

    $ordered = array_keys($normalized);
    usort(
        $ordered,
        static fn(string $left, string $right): int => PLAN_DAY_KEY_TO_INDEX[$left] <=> PLAN_DAY_KEY_TO_INDEX[$right]
    );

    return $ordered;
}

function normalizeSkeletonDayType(?string $rawType): string {
    return normalizeTrainingType($rawType);
}

function isRunTypeForSchedule(string $type): bool {
    return in_array($type, PLAN_RUN_TYPES, true);
}

/**
 * Определяет is_key_workout: если LLM указал явно — берём его значение,
 * иначе — фолбэк на тип тренировки (PLAN_KEY_WORKOUT_TYPES).
 */
function resolveIsKeyWorkout(array $day, string $type): bool {
    if (isset($day['is_key_workout']) && is_bool($day['is_key_workout'])) {
        return $day['is_key_workout'];
    }
    if (isset($day['is_key_workout'])) {
        $val = $day['is_key_workout'];
        if ($val === 1 || $val === '1' || $val === 'true') return true;
        if ($val === 0 || $val === '0' || $val === 'false' || $val === null) return false;
    }
    return in_array($type, PLAN_KEY_WORKOUT_TYPES, true);
}

function resolveRunDistanceSafetyNet(string $type): float {
    return match ($type) {
        'easy' => 1.5,
        'tempo' => 2.5,
        default => 3.0,
    };
}

/**
 * Нормализует один день плана.
 *
 * @param array $day Сырой день из ИИ-ответа
 * @param string $computedDate Вычисленная дата YYYY-MM-DD (fallback, если нет date в дне)
 * @param int $dayOfWeek Номер дня недели (1=пн, 7=вс)
 * @return array Нормализованный день + массив parsed_exercises
 */
function normalizeTrainingDay(array $day, string $computedDate, int $dayOfWeek): array {
    $type = normalizeTrainingType($day['type'] ?? null);
    // ВСЕГДА используем вычисленную дату — LLM не должна указывать даты,
    // но если она это делает (из контекста last_plan_weeks), даты могут быть неправильными.
    $date = $computedDate;
    $day['type'] = $type;

    // ── Извлекаем поля ──
    $distanceKm = isset($day['distance_km']) ? (float)$day['distance_km'] : null;
    $pace = isset($day['pace']) ? trim((string)$day['pace']) : null;
    $durationMin = isset($day['duration_minutes']) ? (int)$day['duration_minutes'] : null;

    // ── Вычисляем дистанцию/длительность по типу ──
    if (in_array($type, ['easy', 'long', 'tempo', 'race', 'control', 'walking'], true)) {
        if ($durationMin === null) {
            $durationMin = calculateDurationMinutes($distanceKm, $pace);
        }
    } elseif ($type === 'interval') {
        $distanceKm = round(calculateIntervalTotalKm($day), 1);
        $pace = null;
    } elseif ($type === 'fartlek') {
        $distanceKm = round(calculateFartlekTotalKm($day), 1);
        $pace = null;
    }

    if ($type === 'rest') {
        // rest с дистанцией/беговым описанием → easy
        $hasDistance = $distanceKm !== null && $distanceKm > 0;
        $desc = trim($day['description'] ?? '');
        $descRunLike = $desc !== '' && preg_match(PLAN_REST_RUN_KEYWORDS, $desc);
        if ($hasDistance || $descRunLike) {
            $type = 'easy';
            $day['type'] = $type;
        } else {
            return [
                'date' => $date, 'day_of_week' => $dayOfWeek, 'type' => 'rest',
                'description' => '', 'distance_km' => null, 'duration_minutes' => null,
                'pace' => null, 'is_key_workout' => false, 'exercises' => [],
                'warmup_km' => null, 'cooldown_km' => null, 'reps' => null,
                'interval_m' => null, 'interval_pace' => null, 'rest_m' => null,
                'rest_type' => null, 'tempo_km' => null, 'segments' => null, 'notes' => null,
            ];
        }
    }

    // Нулевая длительная не должна попадать в сохранённый план как "long 0 км".
    if ($type === 'long' && ($distanceKm === null || $distanceKm <= 0)) {
        return [
            'date' => $date, 'day_of_week' => $dayOfWeek, 'type' => 'rest',
            'description' => '', 'distance_km' => null, 'duration_minutes' => null,
            'pace' => null, 'is_key_workout' => false, 'exercises' => [],
            'warmup_km' => null, 'cooldown_km' => null, 'reps' => null,
            'interval_m' => null, 'interval_pace' => null, 'rest_m' => null,
            'rest_type' => null, 'tempo_km' => null, 'segments' => null, 'notes' => null,
        ];
    }

    // ── Санити-чеки ──
    if ($distanceKm !== null) {
        if ($distanceKm < 0.5) $distanceKm = null;
        if ($distanceKm > 60) $distanceKm = 60;
    }

    // Safety net для коротких easy/tempo должен совпадать с conservative floor policy,
    // иначе normalizer сам раздувает low-volume недели после генерации.
    $minRunSafetyNet = resolveRunDistanceSafetyNet($type);
    if (in_array($type, ['easy', 'tempo'], true) && $distanceKm !== null && $distanceKm > 0 && $distanceKm < $minRunSafetyNet) {
        $oldDist = $distanceKm;
        $distanceKm = $minRunSafetyNet;
        if ($pace !== null) {
            $durationMin = calculateDurationMinutes($distanceKm, $pace);
        }
        $day['distance_km'] = $distanceKm;
        $day['duration_min'] = $durationMin;
        error_log("plan_normalizer: {$type} distance {$oldDist} km → {$distanceKm} km (min safety net)");
    }

    // easy/tempo без distance_km — попробуем вытащить из description
    if (in_array($type, ['easy', 'tempo'], true) && ($distanceKm === null || $distanceKm <= 0)) {
        $desc = trim($day['description'] ?? '');
        if ($desc !== '' && preg_match('/(\d+(?:[.,]\d+)?)\s*(?:км|km)/iu', $desc, $m)) {
            $parsedDist = (float)str_replace(',', '.', $m[1]);
            if ($parsedDist > 0 && $parsedDist < $minRunSafetyNet) {
                $distanceKm = $minRunSafetyNet;
                error_log("plan_normalizer: {$type} parsed {$parsedDist} km from description → {$distanceKm} km");
            } elseif ($parsedDist >= $minRunSafetyNet) {
                $distanceKm = $parsedDist;
            }
            $day['distance_km'] = $distanceKm;
            if ($pace !== null) {
                $durationMin = calculateDurationMinutes($distanceKm, $pace);
            }
        }
    }

    $paceSec = parsePaceToSeconds($pace);
    if ($paceSec !== null && ($paceSec < 150 || $paceSec > 600)) {
        $pace = null; // 2:30-10:00 допустимый диапазон
    }

    // ── Description: всегда пересобираем из полей ──
    $day['distance_km'] = $distanceKm;
    $day['duration_min'] = $durationMin;
    $day['pace'] = $pace;
    $description = buildDescriptionFromFields($day);

    // ── Exercises ──
    $isKeyWorkout = resolveIsKeyWorkout($day, $type);
    $exercises = [];

    if ($distanceKm !== null && $distanceKm > 0 && in_array($type, PLAN_RUN_TYPES, true)) {
        $exercises[] = [
            'category'     => 'run',
            'name'         => 'Бег ' . number_format($distanceKm, 1) . ' км',
            'distance_m'   => (int)round($distanceKm * 1000),
            'duration_sec' => ($durationMin !== null && $durationMin > 0) ? $durationMin * 60 : null,
            'sets'         => null,
            'reps'         => null,
            'weight_kg'    => null,
            'pace'         => $pace,
            'notes'        => $description,
            'order_index'  => 0,
        ];
    }

    if (in_array($type, ['other', 'sbu'], true)) {
        $category = ($type === 'sbu') ? 'sbu' : 'ofp';
        if (!empty($day['exercises'])) {
            foreach ($day['exercises'] as $idx => $ex) {
                $exercises[] = [
                    'category'     => $category,
                    'name'         => $ex['name'] ?? 'Упражнение',
                    'sets'         => isset($ex['sets']) ? (int)$ex['sets'] : null,
                    'reps'         => isset($ex['reps']) ? (int)$ex['reps'] : null,
                    'weight_kg'    => isset($ex['weight_kg']) ? (float)$ex['weight_kg'] : null,
                    'distance_m'   => isset($ex['distance_m']) ? (int)$ex['distance_m'] : null,
                    'duration_sec' => isset($ex['duration_min']) ? (int)$ex['duration_min'] * 60 : null,
                    'pace'         => null,
                    'notes'        => null,
                    'order_index'  => $idx,
                ];
            }
        } elseif ($description !== '') {
            $parsed = parseOfpSbuDescription($description, $type);
            foreach ($parsed as $idx => $ex) {
                $exercises[] = [
                    'category'     => $category,
                    'name'         => $ex['name'],
                    'sets'         => $ex['sets'],
                    'reps'         => $ex['reps'],
                    'weight_kg'    => $ex['weight_kg'],
                    'distance_m'   => $ex['distance_m'],
                    'duration_sec' => $ex['duration_sec'],
                    'pace'         => null,
                    'notes'        => $ex['notes'],
                    'order_index'  => $idx,
                ];
            }
        }
    }

    $structuredFields = [
        'warmup_km' => isset($day['warmup_km']) ? (float) $day['warmup_km'] : null,
        'cooldown_km' => isset($day['cooldown_km']) ? (float) $day['cooldown_km'] : null,
        'reps' => isset($day['reps']) ? (int) $day['reps'] : null,
        'interval_m' => isset($day['interval_m']) ? (int) $day['interval_m'] : null,
        'interval_pace' => isset($day['interval_pace']) && $day['interval_pace'] !== '' ? trim((string) $day['interval_pace']) : null,
        'rest_m' => isset($day['rest_m']) ? (int) $day['rest_m'] : null,
        'rest_type' => isset($day['rest_type']) && $day['rest_type'] !== '' ? trim((string) $day['rest_type']) : null,
        'tempo_km' => isset($day['tempo_km']) ? (float) $day['tempo_km'] : null,
        'segments' => is_array($day['segments'] ?? null) ? array_values($day['segments']) : null,
        'notes' => isset($day['notes']) && trim((string) $day['notes']) !== '' ? trim((string) $day['notes']) : null,
        'subtype' => isset($day['subtype']) && trim((string) $day['subtype']) !== '' ? trim((string) $day['subtype']) : null,
    ];

    return [
        'date'            => $date,
        'day_of_week'     => $dayOfWeek,
        'type'            => $type,
        'description'     => $description,
        'distance_km'     => $distanceKm,
        'duration_minutes' => $durationMin,
        'pace'            => $pace,
        'is_key_workout'  => $isKeyWorkout,
        'exercises'       => $exercises,
    ] + $structuredFields;
}

function rebuildNormalizedDayArtifacts(array $day): array {
    $type = normalizeTrainingType($day['type'] ?? null);
    $day['type'] = $type;

    $distanceKm = isset($day['distance_km']) && $day['distance_km'] !== null ? round((float) $day['distance_km'], 1) : null;
    $pace = isset($day['pace']) && $day['pace'] !== '' ? trim((string) $day['pace']) : null;
    $durationMin = isset($day['duration_minutes']) && $day['duration_minutes'] !== null ? (int) $day['duration_minutes'] : null;

    if ($type === 'interval' && ($distanceKm === null || $distanceKm <= 0)) {
        $distanceKm = round(calculateIntervalTotalKm($day), 1);
    } elseif ($type === 'fartlek' && ($distanceKm === null || $distanceKm <= 0)) {
        $distanceKm = round(calculateFartlekTotalKm($day), 1);
    } elseif ($durationMin === null && $distanceKm !== null && $pace !== null && in_array($type, ['easy', 'long', 'tempo', 'race', 'control', 'walking'], true)) {
        $durationMin = calculateDurationMinutes($distanceKm, $pace);
    }

    $structuredDay = array_merge(
        [
            'warmup_km' => null,
            'cooldown_km' => null,
            'reps' => null,
            'interval_m' => null,
            'interval_pace' => null,
            'rest_m' => null,
            'rest_type' => null,
            'segments' => null,
            'notes' => null,
        ],
        $day,
        [
            'type' => $type,
            'distance_km' => $distanceKm,
            'pace' => $pace,
            'duration_minutes' => $durationMin,
        ]
    );

    $description = buildDescriptionFromFields($structuredDay);
    $isKeyWorkout = resolveIsKeyWorkout($structuredDay, $type);

    $day['description'] = $description;
    $day['distance_km'] = $distanceKm;
    $day['duration_minutes'] = $durationMin;
    $day['pace'] = $pace;
    $day['is_key_workout'] = $isKeyWorkout;

    if (in_array($type, PLAN_RUN_TYPES, true) && $distanceKm !== null && $distanceKm > 0) {
        $day['exercises'] = [[
            'category' => 'run',
            'name' => 'Бег ' . number_format($distanceKm, 1) . ' км',
            'distance_m' => (int) round($distanceKm * 1000),
            'duration_sec' => $durationMin !== null && $durationMin > 0 ? $durationMin * 60 : null,
            'sets' => null,
            'reps' => null,
            'weight_kg' => null,
            'pace' => $pace,
            'notes' => $description,
            'order_index' => 0,
        ]];
    } elseif (!isset($day['exercises']) || !is_array($day['exercises'])) {
        $day['exercises'] = [];
    }

    return $day;
}

function retargetNormalizedDay(array $day, string $date, int $dayOfWeek): array {
    $day['date'] = $date;
    $day['day_of_week'] = $dayOfWeek;
    return rebuildNormalizedDayArtifacts($day);
}

function retargetWeekDays(array $days, DateTime $weekStartDate): array {
    $retargeted = [];

    foreach (array_values($days) as $index => $day) {
        $date = clone $weekStartDate;
        $date->modify('+' . $index . ' days');
        $retargeted[] = retargetNormalizedDay($day, $date->format('Y-m-d'), $index + 1);
    }

    return $retargeted;
}

function createCoercedSkeletonDay(array $day, string $expectedType): array {
    $expectedType = normalizeTrainingType($expectedType);
    $coerced = $day;
    $coerced['type'] = $expectedType;

    if ($expectedType === 'rest') {
        $coerced['distance_km'] = null;
        $coerced['duration_minutes'] = null;
        $coerced['pace'] = null;
        $coerced['is_key_workout'] = false;
        $coerced['exercises'] = [];
        return rebuildNormalizedDayArtifacts($coerced);
    }

    if ($expectedType === 'easy' && (empty($coerced['distance_km']) || (float) $coerced['distance_km'] <= 0)) {
        $coerced['distance_km'] = 6.0;
    }

    if ($expectedType === 'long' && (empty($coerced['distance_km']) || (float) $coerced['distance_km'] <= 0)) {
        $coerced['distance_km'] = 16.0;
    }

    if ($expectedType === 'tempo') {
        $coerced['is_key_workout'] = true;
        if (empty($coerced['distance_km']) || (float) $coerced['distance_km'] < 6.0) {
            $coerced['distance_km'] = 8.0;
        }
    }

    if (in_array($expectedType, ['interval', 'fartlek', 'control', 'race'], true)) {
        $coerced['is_key_workout'] = true;
    }

    return rebuildNormalizedDayArtifacts($coerced);
}

function alignWeekDaysToSkeleton(array $days, array $skeletonDays): array {
    $aligned = array_values($days);
    $expected = [];

    foreach ($skeletonDays as $index => $type) {
        $expected[$index] = normalizeSkeletonDayType($type);
    }

    for ($index = 0; $index < count($aligned); $index++) {
        $actualType = normalizeTrainingType($aligned[$index]['type'] ?? null);
        $expectedType = $expected[$index] ?? 'rest';
        if ($actualType === $expectedType) {
            continue;
        }

        $swapIndex = null;
        for ($candidate = 0; $candidate < count($aligned); $candidate++) {
            if ($candidate === $index) {
                continue;
            }
            $candidateType = normalizeTrainingType($aligned[$candidate]['type'] ?? null);
            if ($candidateType !== $expectedType) {
                continue;
            }
            if (($expected[$candidate] ?? 'rest') === $candidateType) {
                continue;
            }
            $swapIndex = $candidate;
            break;
        }

        if ($swapIndex !== null) {
            $tmp = $aligned[$index];
            $aligned[$index] = $aligned[$swapIndex];
            $aligned[$swapIndex] = $tmp;
            continue;
        }

        $aligned[$index] = createCoercedSkeletonDay($aligned[$index], $expectedType);
    }

    return $aligned;
}

function resolvePreferredLongRunIndex(array $preferredDays, ?int $raceIndex = null): ?int {
    if (empty($preferredDays)) {
        return null;
    }

    $preferredIndexes = array_map(
        static fn(string $dayKey): int => PLAN_DAY_KEY_TO_INDEX[$dayKey],
        normalizePreferredDayKeys($preferredDays)
    );

    foreach ([6, 5] as $weekendIndex) {
        if (in_array($weekendIndex, $preferredIndexes, true) && $weekendIndex !== $raceIndex) {
            return $weekendIndex;
        }
    }

    $preferredIndexes = array_values(array_filter(
        $preferredIndexes,
        static fn(int $index): bool => $index !== $raceIndex
    ));

    if (empty($preferredIndexes)) {
        return null;
    }

    return max($preferredIndexes);
}

function moveLongRunToPreferredIndex(array $days, ?int $targetIndex): array {
    if ($targetIndex === null || !isset($days[$targetIndex])) {
        return $days;
    }

    $longIndex = null;
    foreach ($days as $index => $day) {
        if (normalizeTrainingType($day['type'] ?? null) === 'long') {
            $longIndex = $index;
            break;
        }
    }

    if ($longIndex === null || $longIndex === $targetIndex) {
        return $days;
    }

    $tmp = $days[$targetIndex];
    $days[$targetIndex] = $days[$longIndex];
    $days[$longIndex] = $tmp;

    return $days;
}

function repairAdjacentKeyWorkouts(array $days): array {
    $repaired = array_values($days);
    $hardKeyTypes = ['tempo', 'interval', 'fartlek', 'control', 'long'];

    for ($index = 0; $index < count($repaired) - 1; $index++) {
        $leftType = normalizeTrainingType($repaired[$index]['type'] ?? null);
        $rightType = normalizeTrainingType($repaired[$index + 1]['type'] ?? null);

        if (!in_array($leftType, $hardKeyTypes, true) || !in_array($rightType, $hardKeyTypes, true)) {
            continue;
        }

        for ($candidate = $index + 2; $candidate < count($repaired); $candidate++) {
            $candidateType = normalizeTrainingType($repaired[$candidate]['type'] ?? null);
            if (in_array($candidateType, $hardKeyTypes, true) || $candidateType === 'race') {
                continue;
            }

            $tmp = $repaired[$index + 1];
            $repaired[$index + 1] = $repaired[$candidate];
            $repaired[$candidate] = $tmp;
            break;
        }
    }

    return $repaired;
}

function calculateNormalizedWeekVolume(array $days): float {
    $volume = 0.0;
    foreach ($days as $day) {
        if (isset($day['distance_km']) && $day['distance_km'] !== null && (float) $day['distance_km'] > 0) {
            $volume += (float) $day['distance_km'];
        }
    }
    return round($volume, 1);
}

/**
 * Нормализует весь план тренировок.
 *
 * Принимает сырой массив от ИИ, возвращает чистый массив, готовый к сохранению.
 * Дни дополняются вычисленными датами, типы нормализуются, description генерируются
 * при необходимости, ОФП/СБУ парсятся в структурированные упражнения.
 *
 * @param array $rawPlan Сырой план (['weeks' => [...]])
 * @param string $startDate Дата начала тренировок (YYYY-MM-DD)
 * @return array Нормализованный план с метаданными
 * @throws InvalidArgumentException
 */
function normalizeTrainingPlan(array $rawPlan, string $startDate, int $weekNumberOffset = 0, ?array $preferences = null, ?array $expectedSkeleton = null): array {
    if (!isset($rawPlan['weeks']) || !is_array($rawPlan['weeks'])) {
        throw new InvalidArgumentException('План не содержит данных о неделях');
    }

    $startDateTime = new DateTime($startDate);
    $warnings = [];
    $normalizedWeeks = [];

    // ── Подготовка расписания из preferences ──
    // preferred_days = ['mon','wed','fri','sun'] → dayOfWeek числа {1,3,5,7}
    // preferred_ofp_days = ['tue','thu'] → dayOfWeek числа {2,4}
    $allowedRunDays = null; // null = нет ограничений (preferred_days не указаны)
    $allowedOfpDays = []; // пустой = ОФП запрещена
    $dayCodeToNum = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>7];

    if ($preferences !== null) {
        $prefDays = $preferences['preferred_days'] ?? null;
        if (is_array($prefDays) && !empty($prefDays)) {
            $allowedRunDays = [];
            foreach ($prefDays as $code) {
                if (isset($dayCodeToNum[$code])) {
                    $allowedRunDays[] = $dayCodeToNum[$code];
                }
            }
        }
        $ofpDays = $preferences['preferred_ofp_days'] ?? null;
        if (is_array($ofpDays) && !empty($ofpDays)) {
            foreach ($ofpDays as $code) {
                if (isset($dayCodeToNum[$code])) {
                    $allowedOfpDays[] = $dayCodeToNum[$code];
                }
            }
        }
        error_log("normalizeTrainingPlan: preferences loaded, allowedRunDays=" . json_encode($allowedRunDays) . ", allowedOfpDays=" . json_encode($allowedOfpDays));
    } else {
        error_log("normalizeTrainingPlan: preferences=NULL — no schedule enforcement");
    }

    foreach ($rawPlan['weeks'] as $weekIndex => $week) {
        $weekNumber = ($weekIndex + 1) + $weekNumberOffset;

        $weekStartDate = clone $startDateTime;
        $weekStartDate->modify('+' . ($weekIndex * 7) . ' days');
        $dow = (int) $weekStartDate->format('N');
        if ($dow > 1) {
            $weekStartDate->modify('-' . ($dow - 1) . ' days');
        }

        $days = $week['days'] ?? [];
        if (!is_array($days)) {
            $warnings[] = "Неделя {$weekNumber}: поле days не является массивом";
            continue;
        }

        if (count($days) !== 7) {
            $warnings[] = "Неделя {$weekNumber}: ожидается 7 дней, получено " . count($days);
        }

        $normalizedDays = [];
        $weekVolume = 0.0;

        foreach ($days as $dayIndex => $day) {
            if (!is_array($day)) {
                $warnings[] = "Неделя {$weekNumber}, день {$dayIndex}: не является объектом";
                continue;
            }

            $dayOfWeek = ($dayIndex % 7) + 1;
            $dayDate = clone $weekStartDate;
            $dayDate->modify('+' . ($dayOfWeek - 1) . ' days');
            $computedDate = $dayDate->format('Y-m-d');

            $originalType = $day['type'] ?? 'rest';
            $normalizedDay = normalizeTrainingDay($day, $computedDate, $dayOfWeek);

            if ($normalizedDay['type'] !== normalizeTrainingType($originalType)) {
                $warnings[] = "Неделя {$weekNumber}, {$normalizedDay['date']}: тип '{$originalType}' переопределён в '{$normalizedDay['type']}'";
            }

            // ── Enforcement расписания: беговые тренировки только в preferred_days ──
            $nType = $normalizedDay['type'];
            $nDow = $normalizedDay['day_of_week'];
            $isRunType = in_array($nType, PLAN_RUN_TYPES, true);
            $isOfpType = in_array($nType, ['other', 'sbu'], true);

            if ($allowedRunDays !== null && $isRunType && !in_array($nDow, $allowedRunDays, true)) {
                // Беговая тренировка на нетренировочный день → rest
                // Исключение: race (забег может быть в любой день)
                if ($nType !== 'race') {
                    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
                    $dayName = $dayNames[$nDow] ?? $nDow;
                    $warnings[] = "Неделя {$weekNumber}, {$dayName}: {$nType} на нетренировочный день → rest (preferred_days enforcement)";
                    $normalizedDay = [
                        'date' => $normalizedDay['date'],
                        'day_of_week' => $nDow,
                        'type' => 'rest',
                        'description' => '',
                        'distance_km' => null,
                        'duration_minutes' => null,
                        'pace' => null,
                        'is_key_workout' => false,
                        'exercises' => [],
                    ];
                }
            }

            if ($allowedRunDays !== null && $isOfpType && !empty($allowedOfpDays) && !in_array($nDow, $allowedOfpDays, true)) {
                // ОФП на день, не предназначенный для ОФП → rest
                $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
                $dayName = $dayNames[$nDow] ?? $nDow;
                $warnings[] = "Неделя {$weekNumber}, {$dayName}: {$nType} на не-ОФП день → rest (preferred_ofp_days enforcement)";
                $normalizedDay = [
                    'date' => $normalizedDay['date'],
                    'day_of_week' => $nDow,
                    'type' => 'rest',
                    'description' => '',
                    'distance_km' => null,
                    'duration_minutes' => null,
                    'pace' => null,
                    'is_key_workout' => false,
                    'exercises' => [],
                ];
            }

            if ($normalizedDay['distance_km'] !== null && $normalizedDay['distance_km'] > 0) {
                $weekVolume += $normalizedDay['distance_km'];
            }

            $normalizedDays[] = $normalizedDay;
        }

        $weekSkeletonDays = $expectedSkeleton['weeks'][$weekIndex]['days'] ?? null;
        if (is_array($weekSkeletonDays) && !empty($weekSkeletonDays)) {
            $normalizedDays = alignWeekDaysToSkeleton($normalizedDays, $weekSkeletonDays);
        }

        if (is_array($preferences['preferred_days'] ?? null) && !empty($preferences['preferred_days'])) {
            $raceIndex = null;
            foreach ($normalizedDays as $index => $day) {
                if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                    $raceIndex = $index;
                    break;
                }
            }
            $preferredLongIndex = resolvePreferredLongRunIndex($preferences['preferred_days'], $raceIndex);
            $normalizedDays = moveLongRunToPreferredIndex($normalizedDays, $preferredLongIndex);
        }

        if (!is_array($weekSkeletonDays) || empty($weekSkeletonDays)) {
            $normalizedDays = repairAdjacentKeyWorkouts($normalizedDays);
        }

        $normalizedDays = retargetWeekDays($normalizedDays, $weekStartDate);
        $weekVolume = calculateNormalizedWeekVolume($normalizedDays);

        $normalizedWeeks[] = [
            'week_number' => $weekNumber,
            'start_date'  => $weekStartDate->format('Y-m-d'),
            'phase' => isset($week['phase']) ? (string) $week['phase'] : null,
            'phase_label' => isset($week['phase_label']) ? (string) $week['phase_label'] : null,
            'is_recovery' => !empty($week['is_recovery']),
            'target_volume_km' => isset($week['target_volume_km']) ? round((float) $week['target_volume_km'], 1) : $weekVolume,
            'actual_volume_km' => isset($week['actual_volume_km']) ? round((float) $week['actual_volume_km'], 1) : $weekVolume,
            'total_volume' => $weekVolume,
            'days'        => $normalizedDays,
        ];
    }

    return [
        'weeks'    => $normalizedWeeks,
        'warnings' => $warnings,
    ];
}

function updateRunExercisePace(array &$day): void {
    if (empty($day['exercises']) || !is_array($day['exercises'])) {
        return;
    }

    foreach ($day['exercises'] as &$exercise) {
        if (($exercise['category'] ?? null) !== 'run') {
            continue;
        }
        $exercise['pace'] = $day['pace'] ?? null;
        if (!empty($day['description'])) {
            $exercise['notes'] = $day['description'];
        }
    }
    unset($exercise);
}

function updateSimpleRunDayAfterDistanceChange(array $day): array {
    $day = rebuildNormalizedDayArtifacts($day);
    updateRunExercisePace($day);
    return $day;
}

function applyTrainingStatePaceRepairs(array $normalized, array $trainingState): array {
    $paceRules = $trainingState['pace_rules'] ?? [];
    if (empty($normalized['weeks']) || empty($paceRules)) {
        return $normalized;
    }

    $weeks = $normalized['weeks'];
    foreach ($weeks as &$week) {
        foreach ($week['days'] as &$day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $pace = $day['pace'] ?? null;
            $paceSec = parsePaceToSeconds($pace);
            if ($paceSec === null) {
                continue;
            }

            $newPaceSec = $paceSec;
            if ($type === 'easy' && isset($paceRules['easy_min_sec'], $paceRules['easy_max_sec'])) {
                $newPaceSec = max((int) $paceRules['easy_min_sec'], min((int) $paceRules['easy_max_sec'], $paceSec));
            } elseif ($type === 'long' && isset($paceRules['long_min_sec'], $paceRules['long_max_sec'])) {
                $newPaceSec = max((int) $paceRules['long_min_sec'], min((int) $paceRules['long_max_sec'], $paceSec));
            } elseif ($type === 'tempo' && isset($paceRules['tempo_sec'])) {
                $goalPaceSec = isset($trainingState['goal_pace_sec']) ? (int) $trainingState['goal_pace_sec'] : null;
                $tempoTol = isset($paceRules['tempo_tolerance_sec']) ? (int) $paceRules['tempo_tolerance_sec'] : 8;
                $notesText = mb_strtolower(trim((string) (($day['notes'] ?? '') . ' ' . ($day['description'] ?? ''))), 'UTF-8');
                $isGoalSpecificMarathonTempo = $goalPaceSec !== null
                    && str_contains($notesText, 'целев')
                    && str_contains($notesText, 'марафон');
                if ($isGoalSpecificMarathonTempo && abs($paceSec - $goalPaceSec) <= 15) {
                    continue;
                }
                if (abs($paceSec - (int) $paceRules['tempo_sec']) > $tempoTol) {
                    $newPaceSec = (int) $paceRules['tempo_sec'];
                }
            }

            if ($newPaceSec !== $paceSec) {
                $day['pace'] = formatPaceFromSec($newPaceSec);
                if (!empty($day['distance_km'])) {
                    $day['duration_minutes'] = calculateDurationMinutes((float) $day['distance_km'], $day['pace']);
                }
                $day = updateSimpleRunDayAfterDistanceChange($day);
            }
        }
        unset($day);
    }
    unset($week);

    $normalized['weeks'] = $weeks;
    return $normalized;
}

function applyControlWorkoutFallback(array $day, array $trainingState): array {
    if (normalizeTrainingType($day['type'] ?? null) !== 'control') {
        return $day;
    }

    $raceDistance = $trainingState['race_distance'] ?? null;
    $distanceKm = in_array($raceDistance, ['marathon', '42.2k'], true) ? 8.0 : 5.0;

    $day['distance_km'] = $day['distance_km'] ?? $distanceKm;
    if ((float) $day['distance_km'] <= 0) {
        $day['distance_km'] = $distanceKm;
    }
    $day['pace'] = null;
    $day['notes'] = trim((string) ($day['notes'] ?? ''));
    if ($day['notes'] === '') {
        $day['notes'] = 'Разминка 2 км, затем контрольный непрерывный забег в ровном усилии, заминка 1.5 км.';
    }

    return $day;
}

function ceilToTenth(float $value): float {
    return ceil($value * 10.0) / 10.0;
}

function roundToHalf(float $value): float {
    return round($value * 2.0) / 2.0;
}

function trimDaysByType(array &$days, array $types, float &$excess, callable $floorResolver): void {
    $indexes = [];
    foreach ($days as $index => $day) {
        if (in_array(normalizeTrainingType($day['type'] ?? null), $types, true)) {
            $indexes[] = $index;
        }
    }

    usort(
        $indexes,
        static fn(int $left, int $right): int => ((float) ($days[$right]['distance_km'] ?? 0)) <=> ((float) ($days[$left]['distance_km'] ?? 0))
    );

    foreach ($indexes as $index) {
        if ($excess <= 0.0) {
            return;
        }
        $distance = (float) ($days[$index]['distance_km'] ?? 0.0);
        if ($distance <= 0.0) {
            continue;
        }
        $floor = (float) $floorResolver($days[$index]);
        $reducible = round(max(0.0, $distance - $floor), 1);
        if ($reducible <= 0.0) {
            continue;
        }
        $cut = min($reducible, $excess);
        $days[$index]['distance_km'] = round($distance - $cut, 1);
        $days[$index] = updateSimpleRunDayAfterDistanceChange($days[$index]);
        $excess = round(max(0.0, $excess - $cut), 1);
    }
}

function rebalanceLongShareWithinWeek(array &$days, array $loadPolicy, int $weekNumber): void {
    $currentTotal = calculateNormalizedWeekVolume($days);
    if ($currentTotal <= 0.0) {
        return;
    }

    $longIndex = null;
    $easyIndexes = [];
    $runDayCount = 0;
    foreach ($days as $index => $day) {
        $type = normalizeTrainingType($day['type'] ?? null);
        if (in_array($type, ['easy', 'long', 'tempo', 'interval', 'fartlek', 'control', 'race'], true)) {
            $runDayCount++;
        }
        if ($type === 'long') {
            $longIndex = $index;
        } elseif ($type === 'easy') {
            $easyIndexes[] = $index;
        }
    }

    if ($longIndex === null || $easyIndexes === []) {
        return;
    }

    $longShareCap = isset($loadPolicy['long_share_cap']) ? (float) $loadPolicy['long_share_cap'] : 0.45;
    if ($longShareCap <= 0.0) {
        return;
    }
    if ($runDayCount <= 2) {
        $longShareCap = min(max($longShareCap, 0.50), 0.52);
    } elseif ($currentTotal < 12.0) {
        $longShareCap = min($longShareCap, 0.40);
    } elseif ($currentTotal < 20.0 || $runDayCount <= 3) {
        $longShareCap = min($longShareCap, 0.42);
    }

    $currentLong = (float) ($days[$longIndex]['distance_km'] ?? 0.0);
    $triggerShare = $longShareCap + 0.04;
    $maxLong = round($currentTotal * $longShareCap, 1);
    $longFloor = resolveLongRepairFloorKm($loadPolicy);
    if (($currentLong / $currentTotal) <= $triggerShare || $maxLong < $longFloor) {
        return;
    }

    $delta = round($currentLong - $maxLong, 1);
    if ($delta <= 0.0) {
        return;
    }

    $days[$longIndex]['distance_km'] = $maxLong;
    $days[$longIndex] = updateSimpleRunDayAfterDistanceChange($days[$longIndex]);

    $remaining = $delta;
    $lastEasyIndex = end($easyIndexes);
    foreach ($easyIndexes as $index) {
        $add = $index === $lastEasyIndex
            ? $remaining
            : round($delta / count($easyIndexes), 1);
        $remaining = round($remaining - $add, 1);
        if ($add <= 0.0) {
            continue;
        }

        $days[$index]['distance_km'] = round((float) ($days[$index]['distance_km'] ?? 0.0) + $add, 1);
        $days[$index] = updateSimpleRunDayAfterDistanceChange($days[$index]);
    }
}

function simplifyRaceWeekDays(array &$days, array $trainingState): void {
    $easyFloorSec = isset($trainingState['pace_rules']['easy_min_sec'])
        ? (int) $trainingState['pace_rules']['easy_min_sec']
        : null;

    foreach ($days as &$day) {
        $type = normalizeTrainingType($day['type'] ?? null);
        if ($type === 'tempo' && (!isset($day['distance_km']) || (float) $day['distance_km'] > 6.0)) {
            $day['distance_km'] = 6.0;
            $day['warmup_km'] = $day['warmup_km'] ?? 2.0;
            $day['cooldown_km'] = $day['cooldown_km'] ?? 1.5;
            $day['notes'] = 'Разминка 2 км, затем короткий темповый отрезок, заминка 1.5 км';
            $day = updateSimpleRunDayAfterDistanceChange($day);
        } elseif ($type === 'long') {
            $day['type'] = 'easy';
            $day['distance_km'] = min(4.0, (float) ($day['distance_km'] ?? 4.0));
            $day['is_key_workout'] = false;
            if ($easyFloorSec !== null) {
                $day['pace'] = formatPaceFromSec($easyFloorSec);
            }
            $day['notes'] = 'Очень лёгкий бег перед стартом';
            $day = updateSimpleRunDayAfterDistanceChange($day);
        }
    }
    unset($day);
}

function resolveEasyRepairFloorKm(array $loadPolicy, int $weekNumber, bool $containsRace): float {
    $recoveryWeeks = array_map('intval', $loadPolicy['recovery_weeks'] ?? []);

    if ($containsRace && isset($loadPolicy['easy_taper_min_km']) && $loadPolicy['easy_taper_min_km'] !== null) {
        return (float) $loadPolicy['easy_taper_min_km'];
    }

    if (in_array($weekNumber, $recoveryWeeks, true) && isset($loadPolicy['easy_recovery_min_km']) && $loadPolicy['easy_recovery_min_km'] !== null) {
        return (float) $loadPolicy['easy_recovery_min_km'];
    }

    return isset($loadPolicy['easy_min_km']) ? (float) $loadPolicy['easy_min_km'] : 3.0;
}

function resolveTempoRepairFloorKm(array $loadPolicy): float {
    return isset($loadPolicy['tempo_min_km']) ? (float) $loadPolicy['tempo_min_km'] : 3.0;
}

function resolveLongRepairFloorKm(array $loadPolicy): float {
    return isset($loadPolicy['long_min_km']) ? (float) $loadPolicy['long_min_km'] : 6.0;
}

function resolveRaceWeekSupplementaryCap(float $weekBeforeVolume, array $trainingState): float {
    $loadPolicy = is_array($trainingState['load_policy'] ?? null) ? $trainingState['load_policy'] : [];
    $raceDistance = (string) ($trainingState['race_distance'] ?? '');
    $ratio = isset($loadPolicy['race_week_supplementary_ratio'])
        ? (float) $loadPolicy['race_week_supplementary_ratio']
        : match ($raceDistance) {
            'marathon', '42.2k' => 0.35,
            'half', '21.1k' => 0.45,
            default => 0.60,
        };

    return round(max(6.0, ($weekBeforeVolume * $ratio) + 0.5), 1);
}

function applyTrainingStateLoadRepairs(array $normalized, array $trainingState): array {
    $weeks = $normalized['weeks'] ?? [];
    $warnings = $normalized['warnings'] ?? [];
    $loadPolicy = $trainingState['load_policy'] ?? [];

    foreach ($weeks as $weekIndex => &$week) {
        $week['total_volume'] = calculateNormalizedWeekVolume($week['days'] ?? []);
    }
    unset($week);

    foreach ($weeks as $weekIndex => &$week) {
        $days = $week['days'] ?? [];
        if (empty($days)) {
            continue;
        }

        $weekNumber = (int) ($week['week_number'] ?? ($weekIndex + 1));
        $currentTotal = calculateNormalizedWeekVolume($days);
        $containsRace = false;
        $raceDistance = 0.0;
        foreach ($days as $day) {
            if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                $containsRace = true;
                $raceDistance = (float) ($day['distance_km'] ?? 0.0);
                break;
            }
        }

        $nextContainsRace = false;
        if (isset($weeks[$weekIndex + 1]['days']) && is_array($weeks[$weekIndex + 1]['days'])) {
            foreach ($weeks[$weekIndex + 1]['days'] as $day) {
                if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                    $nextContainsRace = true;
                    break;
                }
            }
        }

        $limit = $currentTotal;
        if ($containsRace) {
            simplifyRaceWeekDays($days, $trainingState);
            $currentTotal = calculateNormalizedWeekVolume($days);
            $nonRaceDistance = max(0.0, $currentTotal - $raceDistance);
            $raceRatio = (float) ($loadPolicy['race_week_ratio'] ?? 0.85);
            $limit = roundToHalf($raceDistance + ($nonRaceDistance * $raceRatio));
            if ($weekIndex > 0) {
                $prevTotal = (float) ($weeks[$weekIndex - 1]['total_volume'] ?? calculateNormalizedWeekVolume($weeks[$weekIndex - 1]['days'] ?? []));
                $supplementaryCap = resolveRaceWeekSupplementaryCap($prevTotal, $trainingState);
                $limit = min($limit, round($raceDistance + $supplementaryCap, 1));
            }
        } elseif ($weekIndex > 0 && $nextContainsRace) {
            $prevTotal = (float) ($weeks[$weekIndex - 1]['total_volume'] ?? calculateNormalizedWeekVolume($weeks[$weekIndex - 1]['days'] ?? []));
            $ratio = (float) ($loadPolicy['pre_race_taper_ratio'] ?? 1.0);
            $limit = min($currentTotal, ceilToTenth(($prevTotal * $ratio) + 0.5));
        } elseif ($weekIndex > 0) {
            $prevTotal = (float) ($weeks[$weekIndex - 1]['total_volume'] ?? calculateNormalizedWeekVolume($weeks[$weekIndex - 1]['days'] ?? []));
            $ratio = (float) ($loadPolicy['allowed_growth_ratio'] ?? 1.10);
            $limit = min($currentTotal, ceilToTenth(($prevTotal * $ratio) + 0.5));
        }

        if (!empty($loadPolicy['weekly_volume_targets_km'][$weekNumber])) {
            $limit = min($limit, (float) $loadPolicy['weekly_volume_targets_km'][$weekNumber]);
        }

        $excess = round(max(0.0, $currentTotal - $limit), 1);
        if ($excess > 0.0) {
            $easyFloorKm = resolveEasyRepairFloorKm($loadPolicy, $weekNumber, $containsRace);
            $tempoFloorKm = resolveTempoRepairFloorKm($loadPolicy);
            $longFloorKm = resolveLongRepairFloorKm($loadPolicy);

            trimDaysByType(
                $days,
                ['easy'],
                $excess,
                static fn(array $day): float => $easyFloorKm
            );
            trimDaysByType(
                $days,
                ['tempo', 'control'],
                $excess,
                static fn(array $day): float => $tempoFloorKm
            );
        }
        if ($excess > 0.0) {
            $longTarget = isset($loadPolicy['long_run_targets_km'][$weekNumber])
                ? (float) $loadPolicy['long_run_targets_km'][$weekNumber]
                : null;
            $weeklyTarget = isset($loadPolicy['weekly_volume_targets_km'][$weekNumber])
                ? (float) $loadPolicy['weekly_volume_targets_km'][$weekNumber]
                : null;
            $longShareCap = isset($loadPolicy['long_share_cap']) ? (float) $loadPolicy['long_share_cap'] : 0.45;
            trimDaysByType(
                $days,
                ['long'],
                $excess,
                static function (array $day) use ($longTarget, $weeklyTarget, $longShareCap, $longFloorKm): float {
                    $targetFloor = $longTarget !== null
                        ? round($longTarget * 0.725, 1)
                        : round(((float) ($day['distance_km'] ?? $longFloorKm)) * 0.75, 1);
                    if ($weeklyTarget !== null && $weeklyTarget > 0.0 && $longShareCap > 0.0) {
                        $targetFloor = min($targetFloor, round($weeklyTarget * $longShareCap, 1));
                    }

                    return max($longFloorKm, $targetFloor);
                }
            );
        }
        if ($excess > 0.0) {
            trimDaysByType(
                $days,
                ['easy'],
                $excess,
                static fn(array $day): float => $easyFloorKm
            );
        }

        rebalanceLongShareWithinWeek($days, $loadPolicy, $weekNumber);

        $finalTotal = calculateNormalizedWeekVolume($days);
        if ($finalTotal < $currentTotal) {
            $warnings[] = "Неделя {$weekNumber}: объём скорректирован с {$currentTotal} до {$finalTotal} км";
        }

        $week['days'] = $days;
        $week['total_volume'] = $finalTotal;
    }
    unset($week);

    $normalized['weeks'] = $weeks;
    $normalized['warnings'] = $warnings;
    return $normalized;
}

function applyTrainingStateMinimumDistanceRepairs(array $normalized, array $trainingState): array {
    $warnings = $normalized['warnings'] ?? [];
    $loadPolicy = $trainingState['load_policy'] ?? [];
    $recoveryWeeks = array_map('intval', $loadPolicy['recovery_weeks'] ?? []);

    $weeks = $normalized['weeks'] ?? [];
    foreach ($weeks as &$week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $containsRace = false;
        foreach (($week['days'] ?? []) as $day) {
            if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                $containsRace = true;
                break;
            }
        }

        $easyFloor = (float) ($loadPolicy['easy_build_min_km'] ?? 0.0);
        if ($containsRace && isset($loadPolicy['easy_taper_min_km'])) {
            $easyFloor = (float) $loadPolicy['easy_taper_min_km'];
        } elseif (in_array($weekNumber, $recoveryWeeks, true) && isset($loadPolicy['easy_recovery_min_km'])) {
            $easyFloor = (float) $loadPolicy['easy_recovery_min_km'];
        }

        $days = $week['days'] ?? [];
        foreach ($days as &$day) {
            if (normalizeTrainingType($day['type'] ?? null) !== 'easy') {
                continue;
            }
            $distance = (float) ($day['distance_km'] ?? 0.0);
            if ($easyFloor > 0.0 && $distance > 0.0 && $distance < $easyFloor) {
                $day['distance_km'] = $easyFloor;
                $day = updateSimpleRunDayAfterDistanceChange($day);
                $warnings[] = "Неделя {$weekNumber}: easy повышен до минимальной дистанции {$easyFloor} км";
            }
        }
        unset($day);

        $week['days'] = $days;
        $week['total_volume'] = calculateNormalizedWeekVolume($days);
    }
    unset($week);

    $normalized['weeks'] = $weeks;
    $normalized['warnings'] = $warnings;
    return $normalized;
}

function findWeekIntentContract(array $trainingState, int $weekNumber, string $type): ?array {
    $contract = $trainingState['plan_intent_contract'] ?? null;
    if (!is_array($contract) || empty($contract['weeks'])) {
        return null;
    }

    $offset = (int) ($contract['week_number_offset'] ?? 0);
    $contractWeek = $weekNumber - $offset;
    foreach ($contract['weeks'] as $weekContract) {
        if ((int) ($weekContract['week'] ?? 0) !== $contractWeek) {
            continue;
        }
        foreach (($weekContract['contracts'] ?? []) as $entry) {
            if (($entry['type'] ?? null) === $type) {
                return array_merge($weekContract, $entry);
            }
        }
        return $weekContract;
    }

    return null;
}

function applyTrainingStateWorkoutDetailFallbacks(array $normalized, array $trainingState): array {
    $warnings = $normalized['warnings'] ?? [];
    $tempoSec = isset($trainingState['pace_rules']['tempo_sec']) ? (int) $trainingState['pace_rules']['tempo_sec'] : 0;
    $intervalSec = isset($trainingState['pace_rules']['interval_sec']) ? (int) $trainingState['pace_rules']['interval_sec'] : 0;
    $goalPaceSec = isset($trainingState['goal_pace_sec']) ? (int) $trainingState['goal_pace_sec'] : null;

    $weeks = $normalized['weeks'] ?? [];
    foreach ($weeks as &$week) {
        $weekNumber = (int) ($week['week_number'] ?? 0);
        $days = $week['days'] ?? [];
        foreach ($days as &$day) {
            $type = normalizeTrainingType($day['type'] ?? null);
            $contract = findWeekIntentContract($trainingState, $weekNumber, $type);

            if ($type === 'tempo') {
                $needsTempoFallback = empty($day['distance_km']) || (float) ($day['distance_km'] ?? 0.0) < 6.0 || empty($day['warmup_km']) || empty($day['cooldown_km']);
                if ($needsTempoFallback) {
                    $paceSec = ($contract['intent'] ?? null) === 'goal_pace_specific' && $goalPaceSec !== null
                        ? $goalPaceSec
                        : ($tempoSec > 0 ? $tempoSec : parsePaceToSeconds($day['pace'] ?? null) ?? 0);
                    $loadPolicy = is_array($trainingState['load_policy'] ?? null) ? $trainingState['load_policy'] : [];
                    $minimumDistanceKm = isset($loadPolicy['tempo_min_km']) ? (float) $loadPolicy['tempo_min_km'] : 3.0;
                    $currentDistanceKm = (float) ($day['distance_km'] ?? 0.0);
                    $defaultDistanceKm = ($contract['intent'] ?? null) === 'goal_pace_specific' ? 8.0 : 6.0;

                    $day['warmup_km'] = 2.0;
                    $day['cooldown_km'] = 1.5;
                    $day['distance_km'] = round(max($currentDistanceKm, max($minimumDistanceKm, $defaultDistanceKm)), 1);
                    if ($paceSec > 0) {
                        $day['pace'] = formatPaceFromSec($paceSec);
                    }
                    $stimulusKm = round(max(0.0, (float) $day['distance_km'] - (float) $day['warmup_km'] - (float) $day['cooldown_km']), 1);
                    $stimulusText = $stimulusKm > 0.0 ? "{$stimulusKm} км" : 'короткий темповый отрезок';
                    $day['notes'] = ($contract['intent'] ?? null) === 'goal_pace_specific'
                        ? "Разминка 2 км, затем {$stimulusText} в районе целевого темпа марафона, заминка 1.5 км"
                        : "Разминка 2 км, затем {$stimulusText} в пороговом темпе, заминка 1.5 км";
                    $day = updateSimpleRunDayAfterDistanceChange($day);
                    $warnings[] = "Неделя {$weekNumber}: tempo дополнен недостающей структурой";
                }
            } elseif ($type === 'control') {
                $day = applyControlWorkoutFallback($day, $trainingState);
                $day['warmup_km'] = $day['warmup_km'] ?? 2.0;
                $day['cooldown_km'] = $day['cooldown_km'] ?? 1.5;
                $day = updateSimpleRunDayAfterDistanceChange($day);
                $warnings[] = "Неделя {$weekNumber}: control дополнен benchmark-структурой";
            } elseif ($type === 'fartlek' && empty($day['segments'])) {
                $day['warmup_km'] = 2.0;
                $day['cooldown_km'] = 1.5;
                $day['segments'] = [[
                    'reps' => 8,
                    'distance_m' => 400,
                    'pace' => $intervalSec > 0 ? formatPaceFromSec($intervalSec) : '4:15',
                    'recovery_m' => 200,
                    'recovery_type' => 'jog',
                ]];
                $day = updateSimpleRunDayAfterDistanceChange($day);
                $warnings[] = "Неделя {$weekNumber}: fartlek дополнен сегментами";
            } elseif ($type === 'interval' && (empty($day['reps']) || empty($day['interval_m']) || empty($day['rest_m']) || empty($day['interval_pace']))) {
                $day['warmup_km'] = 2.0;
                $day['cooldown_km'] = 1.5;
                if (($contract['theme'] ?? null) === 'race_execution') {
                    $day['reps'] = 4;
                    $day['interval_m'] = 400;
                    $day['rest_m'] = 200;
                } else {
                    $day['reps'] = 4;
                    $day['interval_m'] = in_array($trainingState['race_distance'] ?? null, ['marathon', '42.2k'], true) ? 2000 : 1000;
                    $day['rest_m'] = in_array($trainingState['race_distance'] ?? null, ['marathon', '42.2k'], true) ? 600 : 400;
                }
                $day['rest_type'] = 'jog';
                $day['interval_pace'] = $intervalSec > 0 ? formatPaceFromSec($intervalSec) : '4:15';
                $day['distance_km'] = round(calculateIntervalTotalKm($day), 1);
                $day = updateSimpleRunDayAfterDistanceChange($day);
                $warnings[] = "Неделя {$weekNumber}: interval дополнен рабочей структурой";
            }
        }
        unset($day);

        $week['days'] = $days;
        $week['total_volume'] = calculateNormalizedWeekVolume($days);
    }
    unset($week);

    $normalized['weeks'] = $weeks;
    $normalized['warnings'] = $warnings;
    return $normalized;
}

function findNormalizedPlanRaceWeekNumber(array $normalizedPlan): ?int {
    foreach (($normalizedPlan['weeks'] ?? []) as $weekIndex => $week) {
        foreach (($week['days'] ?? []) as $day) {
            if (normalizeTrainingType($day['type'] ?? null) === 'race') {
                return (int) ($week['week_number'] ?? ($weekIndex + 1));
            }
        }
    }

    return null;
}

function resolveGoalPaceSecFromTrainingState(array $trainingState): ?int {
    if (!empty($trainingState['goal_pace_sec'])) {
        return (int) $trainingState['goal_pace_sec'];
    }

    $goalPace = parsePaceToSeconds($trainingState['goal_pace'] ?? null);
    if ($goalPace !== null) {
        return $goalPace;
    }

    if (!empty($trainingState['training_paces']['marathon'])) {
        return (int) $trainingState['training_paces']['marathon'];
    }

    return null;
}

function resolveGoalSpecificTempoPaceTargetSec(array $day, array $trainingState, int $weekNumber): ?int {
    $raceDistance = (string) ($trainingState['race_distance'] ?? '');
    if (!in_array($raceDistance, ['marathon', '42.2k', 'half', '21.1k'], true)) {
        return null;
    }

    $goalPaceSec = resolveGoalPaceSecFromTrainingState($trainingState);
    if ($goalPaceSec === null) {
        return null;
    }

    $contract = findWeekIntentContract($trainingState, $weekNumber, 'tempo');
    if (($contract['intent'] ?? null) === 'goal_pace_specific') {
        return $goalPaceSec;
    }

    $text = mb_strtolower(
        trim((string) (($day['notes'] ?? '') . ' ' . ($day['description'] ?? ''))),
        'UTF-8'
    );

    $goalPaceHints = ['целев', 'goal pace', 'марфонск', 'марафонск'];
    foreach ($goalPaceHints as $hint) {
        if (str_contains($text, $hint)) {
            return $goalPaceSec;
        }
    }

    return null;
}
