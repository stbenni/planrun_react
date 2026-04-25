<?php
/**
 * Построение компактных промптов для LLM-обогащения и ревью плана.
 */

function stringifyPromptValue($raw): string
{
    if (is_array($raw)) {
        $parts = [];
        array_walk_recursive(
            $raw,
            static function ($value) use (&$parts): void {
                if (is_scalar($value) || $value === null) {
                    $text = trim((string) $value);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
        );

        return implode('; ', array_values(array_unique($parts)));
    }

    return trim((string) $raw);
}

/**
 * Промпт для обогащения скелета (notes, structure).
 *
 * @param array $skeleton Числовой скелет плана
 * @param array $user     Данные пользователя
 * @param array $state    TrainingState
 * @return string Промпт (~10-15KB)
 */
function buildEnrichmentPrompt(array $skeleton, array $user, array $state, array $context = []): string
{
    $profile = buildCompactProfile($user, $state);
    $skeletonJson = json_encode(
        buildCompactWeeksForEnrichment((array) ($skeleton['weeks'] ?? [])),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    // Контекст пересчёта / нового плана от пользователя
    $contextBlock = '';
    $reason = stringifyPromptValue($context['reason'] ?? '');
    $goals = stringifyPromptValue($context['goals'] ?? '');
    $jobType = $context['job_type'] ?? 'generate';

    if ($reason !== '') {
        $contextBlock .= "\nПРИЧИНА ПЕРЕСЧЁТА (от пользователя):\n{$reason}\n";
        $contextBlock .= "Учти эту причину в notes: если перерыв/травма — добавь рекомендации по плавному возврату; если тяжело — поддержи; если хочет больше — мотивируй.\n";
    }
    if ($goals !== '') {
        $contextBlock .= "\nПОЖЕЛАНИЯ ПОЛЬЗОВАТЕЛЯ:\n{$goals}\n";
        $contextBlock .= "Учти пожелания в notes к тренировкам.\n";
    }

    return <<<PROMPT
Ты — опытный тренер по бегу. Перед тобой компактный план тренировок и профиль бегуна.
Твоя задача — добавить короткие текстовые заметки только для нужных дней.

{$profile}
{$contextBlock}
КОМПАКТНЫЙ ПЛАН (JSON):
{$skeletonJson}

ЗАДАЧА:
1. Для каждого дня с type=interval, fartlek, control или tempo — добавь заметку с понятным описанием тренировки.
   Пример для interval: "Разминка 2 км. 5×1000м в темпе 4:40, пауза 400м трусцой. Заминка 1.5 км"
   Пример для tempo (threshold): "Разминка 2 км. Темповый бег 5 км в темпе 5:10. Заминка 1.5 км"
   Пример для tempo (MP-run): "Разминка 2 км. MP-run: бег 12 км в марафонском темпе 4:59. Заминка 1.5 км"
   Пример для tempo (HMP-run): "Разминка 2 км. HMP-run: бег 8 км в темпе полумарафона 4:35. Заминка 1.5 км"
   Пример для tempo (R-pace): "Разминка 2 км. R-pace: 6×300м в темпе 3:47, пауза 300м шагом. Заминка 1.5 км"
   Пример для long: "Длительный бег 18 км. Последние 3 км можно в марафонском темпе"
2. Для 2-3 дней в неделю (не каждый) можно добавить короткий полезный совет.
3. Если есть health_notes — учти их в notes.
4. Для части rest-дней можно добавить короткую рекомендацию вроде "Активное восстановление: прогулка 30 мин".

СТРОГИЕ ПРАВИЛА:
- ⚠ ЯЗЫК: ВСЕ notes ТОЛЬКО НА РУССКОМ ЯЗЫКЕ. Никакого английского.
  Правильно: "Разминка 2 км. 5×1000м в темпе 4:40, пауза 400м трусцой. Заминка 1.5 км"
  Неправильно: "Warm up 2 km. 5x1000m at 4:40 pace, 400m jog recovery. Cool down 1.5 km"
  Правильно: "Лёгкий восстановительный бег. Следи за пульсом, не торопись."
  Неправильно: "Easy recovery run. Keep your heart rate low."
- НЕ меняй числа или типы тренировок.
- НЕ возвращай весь план целиком.
- Возвращай ТОЛЬКО объект JSON такого вида:
  {"notes":[{"week_number":1,"day_of_week":2,"notes":"..."}]}
- В массив notes включай только дни, для которых реально нужна заметка.
- notes должна быть короткой: 1 строка, максимум 220 символов.

НАПОМИНАНИЕ: Весь текст в notes — строго на русском языке. Без исключений.

Верни только JSON-объект с массивом notes.
PROMPT;
}

/**
 * Промпт для ревью плана (поиск ошибок).
 *
 * @param array $plan    Обогащённый план
 * @param array $user    Данные пользователя
 * @param array $state   TrainingState
 * @return string Промпт (~8-12KB)
 */
function buildReviewPrompt(array $plan, array $user, array $state): string
{
    $profile = buildCompactProfile($user, $state);
    $planJson = json_encode(
        buildCompactWeeksForReview((array) ($plan['weeks'] ?? [])),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $healthNotes = stringifyPromptValue($user['health_notes'] ?? '');
    $healthLine = $healthNotes !== '' ? "\nЗдоровье бегуна: {$healthNotes}" : '';

    // Конкретные допустимые темпы для данного бегуна
    $paceRules = $state['pace_rules'] ?? [];
    $paceBlock = '';
    if (!empty($paceRules)) {
        $easyRange = (isset($paceRules['easy_min_sec']) ? formatPaceSec($paceRules['easy_min_sec']) : '?')
            . ' – ' . (isset($paceRules['easy_max_sec']) ? formatPaceSec($paceRules['easy_max_sec']) : '?');
        $longRange = (isset($paceRules['long_min_sec']) ? formatPaceSec($paceRules['long_min_sec']) : '?')
            . ' – ' . (isset($paceRules['long_max_sec']) ? formatPaceSec($paceRules['long_max_sec']) : '?');
        $tempoSec = isset($paceRules['tempo_sec']) ? formatPaceSec($paceRules['tempo_sec']) : '?';
        $tempoTol = $paceRules['tempo_tolerance_sec'] ?? 8;
        $intervalSec = isset($paceRules['interval_sec']) ? formatPaceSec($paceRules['interval_sec']) : '?';
        $intervalTol = $paceRules['interval_tolerance_sec'] ?? 8;
        // Race-pace темпы (для дней с subtype=race_pace)
        $marathonPace = isset($paceRules['race_pace_sec']) ? formatPaceSec($paceRules['race_pace_sec']) : (isset($paceRules['marathon_sec']) ? formatPaceSec($paceRules['marathon_sec']) : null);
        $halfPace = isset($paceRules['half_pace_sec']) ? formatPaceSec($paceRules['half_pace_sec']) : null;
        $tenKPace = isset($paceRules['ten_k_pace_sec']) ? formatPaceSec($paceRules['ten_k_pace_sec']) : null;
        $repPace = isset($paceRules['repetition_sec']) ? formatPaceSec($paceRules['repetition_sec']) : null;

        $paceBlock = "\n\nДОПУСТИМЫЕ ТЕМПЫ (мин:сек/км):"
            . "\n- Easy: {$easyRange}"
            . "\n- Long: {$longRange}"
            . "\n- Tempo (threshold): {$tempoSec} (±{$tempoTol}с)"
            . "\n- Interval: {$intervalSec} (±{$intervalTol}с)";

        // Добавляем race-pace если есть
        $racePaceLines = [];
        if ($marathonPace) $racePaceLines[] = "MP (марафонский): {$marathonPace}";
        if ($halfPace) $racePaceLines[] = "HMP (полумарафон): {$halfPace}";
        if ($tenKPace) $racePaceLines[] = "10k-pace: {$tenKPace}";
        if ($repPace) $racePaceLines[] = "R-pace (повторения): {$repPace}";
        if (!empty($racePaceLines)) {
            $paceBlock .= "\n- Race-pace (для дней с subtype=race_pace): " . implode(', ', $racePaceLines);
        }
    }

    $scenario = (array) ($state['planning_scenario'] ?? []);
    $scenarioPrimary = (string) ($scenario['primary'] ?? '');
    $scenarioFlags = array_values(array_filter((array) ($scenario['flags'] ?? []), 'is_string'));
    $scenarioBlock = '';
    if ($scenarioPrimary !== '' || $scenarioFlags !== []) {
        $scenarioBlock = "\n\nСЦЕНАРИЙ ПЛАНИРОВАНИЯ:";
        if ($scenarioPrimary !== '') {
            $scenarioBlock .= "\n- primary: {$scenarioPrimary}";
        }
        if ($scenarioFlags !== []) {
            $scenarioBlock .= "\n- flags: " . implode(', ', $scenarioFlags);
        }

        $tuneUp = (array) ($scenario['tune_up_event'] ?? []);
        if (!empty($tuneUp['date'])) {
            $distance = $tuneUp['distance_label'] ?? ($tuneUp['distance_km'] ?? '?');
            $forcedType = $tuneUp['forced_type'] ?? ($tuneUp['type'] ?? 'control');
            $scenarioBlock .= "\n- explicit tune-up: {$tuneUp['date']}, {$distance}, type={$forcedType}";
            $scenarioBlock .= "\n- ВАЖНО: explicit tune-up перед главным стартом может быть НАМЕРЕННО downgraded до control. Не считай это ошибкой и не отмечай как too_aggressive/taper_violation только потому, что такой день быстрее марафонского темпа.";
        }
    }

    return <<<PROMPT
Ты — эксперт-рецензент тренировочных планов по бегу.

{$profile}{$healthLine}{$paceBlock}{$scenarioBlock}

КОМПАКТНЫЙ ПЛАН (JSON):
{$planJson}

Проверь план на логические ошибки. Ищи:
1. ТЕМПЫ: easy pace должен быть МЕДЛЕННЕЕ tempo pace, tempo МЕДЛЕННЕЕ interval. Сверяй с ДОПУСТИМЫМИ ТЕМПАМИ выше. Если отклонение > ±15 сек — ошибка.
   ВАЖНО: дни типа tempo с subtype=race_pace имеют ДРУГОЙ целевой темп (MP, HMP, 10k-pace, R-pace) — это НЕ ошибка, НЕ отмечай их как pace_logic.
2. ПРОГРЕССИЯ ОБЪЁМОВ: рост недельного объёма не более 15% от предыдущей (кроме recovery weeks). Скачки больше 15% — ошибка.
   ВАЖНО: type=volume_jump используй только когда объём именно ВЫРОС слишком сильно. Простое снижение объёма не является volume_jump.
3. ПРОГРЕССИЯ ДЛИТЕЛЬНОЙ: long run должен расти от недели к неделе (кроме recovery/taper). Если длительная уменьшилась без причины — ошибка.
4. ДВЕ КЛЮЧЕВЫЕ ПОДРЯД: два is_key_workout=true дня подряд (следующие по day_of_week) — ошибка.
5. RECOVERY WEEKS: если is_recovery=true, объём должен быть снижен на 15-25% от предыдущей обычной недели.
6. ПОДВОДКА (TAPER): если phase=taper, объём должен снижаться от недели к неделе.
7. ЗДОРОВЬЕ: если есть health_notes — проверь, нет ли тренировок, противопоказанных при данном состоянии.
8. АГРЕССИВНОСТЬ: слишком быстрая прогрессия для возраста/уровня.

Ответь строго JSON:
{
  "status": "ok" или "has_issues",
  "issues": [
    {
      "week": 5,
      "day_of_week": 2,
      "type": "pace_logic|volume_jump|consecutive_key|missing_recovery|taper_violation|health_concern|too_aggressive",
      "description": "описание ошибки",
      "fix_suggestion": "как исправить"
    }
  ]
}

ПРАВИЛА ФОРМАТА ОТВЕТА:
- У каждой issue обязательно должен быть week.
- day_of_week указывай только если ошибка относится к конкретному дню.
- Не возвращай текст вне JSON.

Если ошибок нет — верни {"status": "ok", "issues": []}.
Не придумывай ошибки. Будь строгим, но справедливым.
PROMPT;
}

/**
 * Компактный профиль бегуна для промпта.
 */
function buildCompactProfile(array $user, array $state): string
{
    $age = !empty($user['birth_year']) ? ((int) date('Y') - (int) $user['birth_year']) : '?';
    $gender = ($user['gender'] ?? '') === 'female' ? 'женщина' : 'мужчина';
    $exp = $user['experience_level'] ?? 'novice';
    $runExp = $user['running_experience'] ?? '';
    $goalType = $user['goal_type'] ?? 'health';
    $raceDist = $user['race_distance'] ?? '';
    $raceTarget = $user['race_target_time'] ?? '';
    $raceDate = $user['race_date'] ?? $user['target_marathon_date'] ?? '';
    $vdot = $state['vdot'] ?? '?';
    $vdotConf = $state['vdot_confidence'] ?? 'low';
    $healthNotes = stringifyPromptValue($user['health_notes'] ?? '');
    $sessions = $user['sessions_per_week'] ?? 3;

    $paceRules = $state['pace_rules'] ?? [];
    $easyPace = isset($paceRules['easy_min_sec']) ? formatPaceSec($paceRules['easy_min_sec']) : '?';
    $tempoPace = isset($paceRules['tempo_sec']) ? formatPaceSec($paceRules['tempo_sec']) : '?';
    $intervalPace = isset($paceRules['interval_sec']) ? formatPaceSec($paceRules['interval_sec']) : '?';

    $specialFlags = $state['special_population_flags'] ?? [];
    $flagsStr = !empty($specialFlags) ? implode(', ', $specialFlags) : 'нет';

    $lines = [
        "БЕГУН:",
        "- {$age} лет, {$gender}, уровень: {$exp}" . ($runExp ? ", опыт: {$runExp}" : ''),
        "- Цель: {$goalType}" . ($raceDist ? ", дистанция: {$raceDist}" : '') . ($raceTarget ? " за {$raceTarget}" : '') . ($raceDate ? ", дата: {$raceDate}" : ''),
        "- VDOT: {$vdot} (уверенность: {$vdotConf}), тренировок/нед: {$sessions}",
        "- Темпы: Easy {$easyPace}, Tempo {$tempoPace}, Interval {$intervalPace}",
    ];

    if ($healthNotes !== '') {
        $lines[] = "- Здоровье: {$healthNotes}";
    }
    if ($flagsStr !== 'нет') {
        $lines[] = "- Особые флаги: {$flagsStr}";
    }

    return implode("\n", $lines);
}

/**
 * @param array<int, array<string, mixed>> $weeks
 * @return array<int, array<string, mixed>>
 */
function buildCompactWeeksForEnrichment(array $weeks): array
{
    $result = [];
    foreach ($weeks as $week) {
        $result[] = [
            'week_number' => (int) ($week['week_number'] ?? 0),
            'phase' => (string) ($week['phase'] ?? ''),
            'is_recovery' => !empty($week['is_recovery']),
            'days' => buildCompactPromptDays((array) ($week['days'] ?? []), true),
        ];
    }

    return $result;
}

/**
 * @param array<int, array<string, mixed>> $weeks
 * @return array<int, array<string, mixed>>
 */
function buildCompactWeeksForReview(array $weeks): array
{
    $result = [];
    foreach ($weeks as $week) {
        $days = buildCompactPromptDays((array) ($week['days'] ?? []), false);
        $volume = 0.0;
        foreach ($days as $day) {
            $volume += (float) ($day['distance_km'] ?? 0.0);
        }

        $result[] = [
            'week_number' => (int) ($week['week_number'] ?? 0),
            'phase' => (string) ($week['phase'] ?? ''),
            'is_recovery' => !empty($week['is_recovery']),
            'planned_volume_km' => round($volume, 1),
            'days' => $days,
        ];
    }

    return $result;
}

/**
 * @param array<int, array<string, mixed>> $days
 * @return array<int, array<string, mixed>>
 */
function buildCompactPromptDays(array $days, bool $includeNotesHints): array
{
    $result = [];
    foreach ($days as $day) {
        $compact = [
            'day_of_week' => (int) ($day['day_of_week'] ?? 0),
            'type' => (string) ($day['type'] ?? 'rest'),
        ];

        foreach (['subtype', 'pace', 'description'] as $field) {
            $value = trim((string) ($day[$field] ?? ''));
            if ($value !== '') {
                $compact[$field] = $value;
            }
        }

        foreach (['distance_km', 'warmup_km', 'cooldown_km', 'tempo_km', 'interval_m', 'rest_m', 'duration_minutes'] as $field) {
            if (isset($day[$field]) && is_numeric($day[$field])) {
                $compact[$field] = (float) $day[$field];
            }
        }

        if (isset($day['reps']) && is_numeric($day['reps'])) {
            $compact['reps'] = (int) $day['reps'];
        }

        if (!empty($day['is_key_workout'])) {
            $compact['is_key_workout'] = true;
        }

        if ($includeNotesHints) {
            $compact['needs_note'] = in_array($compact['type'], ['interval', 'fartlek', 'tempo', 'control'], true)
                || !empty($compact['is_key_workout']);
        }

        $result[] = $compact;
    }

    return $result;
}

if (!function_exists('formatPaceSec')) {
    function formatPaceSec(int $sec): string
    {
        return (int) floor($sec / 60) . ':' . str_pad((string) ($sec % 60), 2, '0', STR_PAD_LEFT);
    }
}
