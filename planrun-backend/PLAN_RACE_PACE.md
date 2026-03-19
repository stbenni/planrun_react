# План: Race-Pace тренировки для всех дистанций

## Проблема

Сейчас есть только два типа ключевых тренировок: tempo (T-pace, порог) и interval (I-pace, VO2max).
Нет тренировок в **соревновательном темпе** — ключевого элемента специфической подготовки.

Разница огромная. Пример для марафонца с VDOT 46.6:
- T-pace (порог): 4:25/км
- Marathon pace (MP): 4:59/км
- I-pace (VO2max): 3:59/км

MP-run 12 км в темпе 4:59 — это совсем другая тренировка, чем tempo 12 км в 4:25.

## Что нужно для каждой дистанции

### Марафон (42.2k)
- **MP-run**: 8→16 км в марафонском темпе
- Суть: приучить организм к экономичному бегу на целевой скорости
- Частота: каждую 2-ю неделю в build/peak (чередуется с T-pace tempo)
- Структура: разминка 2 км + MP-работа + заминка 1.5 км

### Полумарафон (21.1k)
- **HMP-run**: 6→12 км в темпе полумарафона
- Темп HMP ≈ между T-pace и MP (примерно T-pace + 8-12 сек)
- Частота: каждую 2-ю неделю в build/peak

### 10к
- **10k-pace run**: 4→8 км в темпе 10к
- Темп 10k ≈ между T-pace и I-pace (T-pace - 5-8 сек)
- Частота: каждую 2-3 неделю в build/peak

### 5к
- **R-pace repeats**: 200-400м повторы в repetition-темпе (быстрее I-pace)
- Темп R ≈ I-pace - 10-15 сек/км
- Суть: нейромышечная скорость, экономичность на высокой скорости
- Частота: каждую 2-3 неделю в build/peak

## Архитектура решения

### Подход: чередование внутри "tempo" типа

НЕ создаём новый тип дня в БД — используем существующий `tempo`.
Чередуем: нечётные tempo-тренировки = threshold (T-pace), чётные = race-pace.
Различие видно по pace и description.

**Почему так:**
- Нет миграции БД (enum не меняется)
- Фронтенд не нужно менять
- Пользователь видит разницу в описании: "Темповый бег 8 км в 4:25" vs "MP-run 12 км в 4:59"
- Обратно совместимо

### Новые файлы

| Файл | Назначение |
|------|-----------|
| `planrun_ai/skeleton/RacePaceProgressionBuilder.php` | Прогрессия race-pace тренировок по дистанциям |

### Модификация существующих файлов

| Файл | Изменение |
|------|-----------|
| `planrun_ai/skeleton/PlanSkeletonGenerator.php` | В `buildWorkoutDetails()`: чередовать tempo между threshold и race-pace |
| `planrun_ai/skeleton/VolumeDistributor.php` | В `applyTempoDetails()`: использовать race_pace_sec если subtype='race_pace' |
| `services/TrainingStateBuilder.php` | Добавить в pace_rules: half_pace_sec, ten_k_pace_sec, repetition_sec |
| `planrun_ai/skeleton/enrichment_prompt_builder.php` | Добавить race-pace пояснение в промпт |

## Детали реализации

### 1. RacePaceProgressionBuilder.php

```php
class RacePaceProgressionBuilder
{
    // Марафон: MP-runs 8→16 км
    private const MARATHON_PROGRESSION = [
        1 => 8, 2 => 10, 3 => 10, 4 => 12,
        5 => 12, 6 => 14, 7 => 14, 8 => 16,
    ];

    // Полумарафон: HMP-runs 6→12 км
    private const HALF_PROGRESSION = [
        1 => 6, 2 => 7, 3 => 8, 4 => 8,
        5 => 10, 6 => 10, 7 => 12, 8 => 12,
    ];

    // 10к: 10k-pace runs 4→8 км
    private const TEN_K_PROGRESSION = [
        1 => 4, 2 => 5, 3 => 5, 4 => 6,
        5 => 6, 6 => 7, 7 => 8, 8 => 8,
    ];

    // 5к: R-pace repeats (reps x distance_m)
    private const FIVE_K_PROGRESSION = [
        1 => ['reps' => 6, 'distance_m' => 200, 'rest_m' => 200],
        2 => ['reps' => 8, 'distance_m' => 200, 'rest_m' => 200],
        3 => ['reps' => 6, 'distance_m' => 300, 'rest_m' => 300],
        4 => ['reps' => 8, 'distance_m' => 300, 'rest_m' => 300],
        5 => ['reps' => 6, 'distance_m' => 400, 'rest_m' => 400],
        6 => ['reps' => 8, 'distance_m' => 400, 'rest_m' => 400],
        7 => ['reps' => 6, 'distance_m' => 400, 'rest_m' => 400],
        8 => ['reps' => 8, 'distance_m' => 400, 'rest_m' => 400],
    ];

    public static function build(
        string $phase,
        int    $racePaceNumber,
        string $raceDistance,
        array  $paceRules
    ): ?array
    // Возвращает:
    // Для marathon/half/10k — структуру аналогичную tempo:
    //   warmup_km, cooldown_km, tempo_km (race-pace работа), total_km,
    //   tempo_pace_sec (= race pace, НЕ threshold), subtype='race_pace'
    //
    // Для 5k — структуру аналогичную interval:
    //   reps, interval_m, rest_m, warmup_km, cooldown_km, total_km,
    //   interval_pace_sec (= repetition pace), subtype='race_pace'
}
```

### 2. Новые темпы в pace_rules

```php
// TrainingStateBuilder::buildPaceRules()
// Добавить:
'half_pace_sec'   => рассчитать из VDOT (или интерполировать T-pace + 10 сек)
'ten_k_pace_sec'  => рассчитать из VDOT (или интерполировать T-pace - 6 сек)
'repetition_sec'  => рассчитать из VDOT (или I-pace - 12 сек)
```

Формулы для дистанций, где нет прямых данных из VDOT-таблиц:
- HMP ≈ T-pace + (MP - T-pace) * 0.4 (ближе к T-pace)
- 10k pace ≈ T-pace - (T-pace - I-pace) * 0.15 (чуть быстрее T)
- R-pace ≈ I-pace - 12 сек (или из VDOT таблицы если есть)

### 3. Чередование в PlanSkeletonGenerator

```php
// В buildWorkoutDetails(), case 'tempo':
$tempoCount++;
$isRacePace = ($tempoCount % 2 === 0) && $phase !== 'base' && $phase !== 'taper';

if ($isRacePace) {
    $racePaceCount++;
    $result = RacePaceProgressionBuilder::build($phase, $racePaceCount, $raceDistance, $paceRules);
    if ($result) {
        $details['tempo'] = $result; // тот же ключ 'tempo'
    }
} else {
    $result = TempoProgressionBuilder::build($phase, $tempoCount, $paceRules, $raceDistance);
    ...
}
```

### 4. Обработка в VolumeDistributor

```php
// В applyTempoDetails():
$subtype = $details['subtype'] ?? 'threshold';
if ($subtype === 'race_pace') {
    // Для 5к: это repeats — использовать interval-подобную структуру
    if (isset($details['reps'])) {
        return self::applyIntervalDetails($details, $paceRules, $totalKm);
    }
    // Для остальных: использовать race_pace_sec вместо tempo_sec
    $pace = self::formatPace($details['tempo_pace_sec'] ?? $paceRules['race_pace_sec'] ?? 300);
} else {
    $pace = self::formatPace($paceRules['tempo_sec'] ?? 300);
}
```

## Примеры результата

### Марафон, 16 недель

```
W5  [tempo]    Разминка 2 км. Темповый бег 6 км в 4:25. Заминка 1.5 км
W6  [tempo]    Разминка 2 км. MP-run 8 км в 4:59. Заминка 1.5 км
W7  [tempo]    Разминка 2 км. Темповый бег 7 км в 4:25. Заминка 1.5 км
W8  [recovery]
W9  [tempo]    Разминка 2 км. MP-run 10 км в 4:59. Заминка 1.5 км
W10 [tempo]    Разминка 2 км. Темповый бег 8 км в 4:25. Заминка 1.5 км
W11 [tempo]    Разминка 2 км. MP-run 12 км в 4:59. Заминка 1.5 км
W12 [recovery]
W13 [tempo]    Разминка 2 км. Темповый бег 10 км в 4:25. Заминка 1.5 км
W14 [tempo]    Разминка 2 км. MP-run 14 км в 4:59. Заминка 1.5 км
```

### 5к, 12 недель

```
W4  [tempo]    Разминка 2 км. Темповый бег 3 км в 4:25. Заминка 1.5 км
W5  [tempo]    Разминка 2 км. R-pace: 6x200м в 3:47, отдых 200м. Заминка 1.5 км
W6  [tempo]    Разминка 2 км. Темповый бег 4 км в 4:25. Заминка 1.5 км
W7  [tempo]    Разминка 2 км. R-pace: 6x300м в 3:47, отдых 300м. Заминка 1.5 км
```

## Проверка таблиц VDOT

Нужно проверить, какие дистанционные темпы доступны из функции `getTrainingPaces()`
в `prompt_builder.php`. Если VDOT-таблица содержит только easy/marathon/threshold/interval,
то для half/10k/R-pace нужно интерполировать.

## Порядок реализации

1. Проверить `getTrainingPaces()` — какие темпы доступны
2. Добавить недостающие темпы в `TrainingStateBuilder::buildPaceRules()`
3. Создать `RacePaceProgressionBuilder.php`
4. Модифицировать `PlanSkeletonGenerator::buildWorkoutDetails()` — чередование
5. Модифицировать `VolumeDistributor::applyTempoDetails()` — race-pace
6. Обновить `enrichment_prompt_builder.php` — пояснить LLM что такое MP-run
7. Тест: сгенерировать планы для marathon, half, 10k, 5k и проверить чередование
8. Тест: проверить что plan_normalizer не ломает race-pace тренировки

## Зависимости

- `race_pace_sec` уже есть в pace_rules (добавлен ранее)
- `marathon_sec` уже есть в pace_rules
- TempoProgressionBuilder и IntervalProgressionBuilder уже знают про race_distance
- PlanSkeletonBuilder уже различает дистанции при выборе quality types
