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
 * Заменяет дистанции в notes, если они сильно расходятся с фактической distance_km.
 * LLM-обогащение может подставлять пиковые/целевые значения вместо фактических.
 */
function sanitizeNotesDistance(string $notes, float $actualKm): string {
    return preg_replace_callback(
        '/(?:бег|бега|пробежка|дистанция)\s+(\d+(?:[.,]\d+)?)\s*(?:км|km)/iu',
        function (array $m) use ($actualKm) {
            $noteKm = (float) str_replace(',', '.', $m[1]);
            if ($noteKm > 0 && abs($noteKm - $actualKm) > max(1.0, $actualKm * 0.15)) {
                return str_replace($m[1], number_format($actualKm, 1, '.', ''), $m[0]);
            }
            return $m[0];
        },
        $notes
    );
}

/**
 * Строит description из структурированных полей (новый формат LLM).
 *
 * Формат description совпадает с regex-шаблонами AddTrainingModal.jsx.
 */
function buildDescriptionFromFields(array $day): string {
    $type = $day['type'] ?? 'rest';

    // Простой бег (включая контрольную)
    if (in_array($type, ['easy', 'long', 'tempo', 'race', 'control'], true)) {
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
                $notes = sanitizeNotesDistance($notes, (float) $dist);
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
];

const PLAN_ALLOWED_TYPES = ['rest', 'tempo', 'interval', 'long', 'race', 'other', 'free', 'easy', 'sbu', 'fartlek', 'control'];

const PLAN_KEY_WORKOUT_TYPES = ['interval', 'tempo', 'long', 'fartlek', 'race', 'control'];

const PLAN_RUN_TYPES = ['easy', 'long', 'tempo', 'interval', 'fartlek', 'race', 'control'];

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
    if (in_array($type, ['easy', 'long', 'tempo', 'race', 'control'], true)) {
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
            $restNotes = trim($day['notes'] ?? $day['description'] ?? '');
            return [
                'date' => $date, 'day_of_week' => $dayOfWeek, 'type' => 'rest',
                'description' => $restNotes, 'distance_km' => null, 'duration_minutes' => null,
                'pace' => null, 'is_key_workout' => false, 'exercises' => [],
            ];
        }
    }

    // ── Санити-чеки ──
    if ($distanceKm !== null) {
        if ($distanceKm < 0.5) $distanceKm = null;
        if ($distanceKm > 60) $distanceKm = 60;
    }

    // Минимальная дистанция easy/tempo: safety net 3 км (уровневый контроль — в промпте)
    $minEasySafetyNet = 3.0;
    if (in_array($type, ['easy', 'tempo'], true) && $distanceKm !== null && $distanceKm > 0 && $distanceKm < $minEasySafetyNet) {
        $oldDist = $distanceKm;
        $distanceKm = $minEasySafetyNet;
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
            if ($parsedDist > 0 && $parsedDist < $minEasySafetyNet) {
                $distanceKm = $minEasySafetyNet;
                error_log("plan_normalizer: {$type} parsed {$parsedDist} km from description → {$distanceKm} km");
            } elseif ($parsedDist >= $minEasySafetyNet) {
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
    ];
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

        $normalizedWeeks[] = [
            'week_number' => $weekNumber,
            'start_date'  => $weekStartDate->format('Y-m-d'),
            'total_volume' => round($weekVolume, 1),
            'days'        => $normalizedDays,
        ];
    }

    return [
        'weeks'    => $normalizedWeeks,
        'warnings' => $warnings,
    ];
}
