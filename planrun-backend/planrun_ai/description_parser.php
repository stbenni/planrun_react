<?php
/**
 * Парсинг description для ОФП и СБУ в формат «как в чате»:
 * ОФП: каждая строка «Название — 3×10, 20 кг» или «Название — 1 мин»
 * СБУ: каждая строка «Название — 30 м» или «Название — 50 м»
 *
 * @return array<int, array{name: string, sets: ?int, reps: ?int, weight_kg: ?float, duration_sec: ?int, distance_m: ?int, notes: ?string}>
 */
function parseOfpSbuDescription(string $description, string $type): array {
    $description = trim($description);
    if ($description === '') {
        return [];
    }
    $lines = preg_split('/\r\n|\n|\r/', $description, -1, PREG_SPLIT_NO_EMPTY);
    $exercises = [];
    $category = ($type === 'sbu') ? 'sbu' : 'ofp';
    // Fallback: один абзац вида «Силовые упражнения: приседания, выпады, становая тяга (3 подхода по 12 повторений)»
    if ($category === 'ofp' && count($lines) === 1 && strpos($lines[0], '—') === false) {
        $one = trim($lines[0]);
        $sets = null;
        $reps = null;
        if (preg_match('/\s*\((\d+)\s*подхода?\s*по\s*(\d+)\s*повторений?\)\s*$/ui', $one, $pm)) {
            $sets = (int) $pm[1];
            $reps = (int) $pm[2];
            $one = trim(preg_replace('/\s*\(\d+\s*подхода?\s*по\s*\d+\s*повторений?\)\s*$/ui', '', $one));
        } elseif (preg_match('/\s*\((\d+)\s*×\s*(\d+)\)\s*$/u', $one, $pm)) {
            $sets = (int) $pm[1];
            $reps = (int) $pm[2];
            $one = trim(preg_replace('/\s*\(\d+\s*×\s*\d+\)\s*$/u', '', $one));
        }
        if (preg_match('/^(.+?):\s*(.+)$/u', $one, $colon)) {
            $one = trim($colon[2]); // «приседания, выпады, становая тяга»
        }
        $names = array_map('trim', preg_split('/\s*,\s*|\s+и\s+/u', $one, -1, PREG_SPLIT_NO_EMPTY));
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }
            $exercises[] = [
                'name' => $name,
                'sets' => $sets,
                'reps' => $reps,
                'weight_kg' => null,
                'duration_sec' => null,
                'distance_m' => null,
                'notes' => null,
            ];
        }
        if (count($exercises) > 0) {
            return $exercises;
        }
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        // Разделитель — тире (длинное или короткое)
        if (!preg_match('/^(.+?)\s*[—\-]\s*(.+)$/u', $line, $m)) {
            $exercises[] = [
                'name' => $line,
                'sets' => null,
                'reps' => null,
                'weight_kg' => null,
                'duration_sec' => null,
                'distance_m' => null,
                'notes' => null,
            ];
            continue;
        }
        $name = trim($m[1]);
        $details = trim($m[2]);
        $sets = null;
        $reps = null;
        $weight_kg = null;
        $duration_sec = null;
        $distance_m = null;
        $notes = null;

        if ($category === 'sbu') {
            // "30 м" или "50 м" или "0.1 км"
            if (preg_match('/^(\d+)\s*м$/u', $details, $d)) {
                $distance_m = (int) $d[1];
            } elseif (preg_match('/^([\d.,]+)\s*км$/u', $details, $d)) {
                $distance_m = (int) round((float) str_replace(',', '.', $d[1]) * 1000);
            } else {
                $notes = $details;
            }
        } else {
            // ОФП: "3×10, 20 кг" или "2×15" или "1 мин"
            if (preg_match('/^(\d+)\s*×\s*(\d+)\s*(?:,\s*(\d+(?:[.,]\d+)?)\s*кг)?/u', $details, $d)) {
                $sets = (int) $d[1];
                $reps = (int) $d[2];
                if (isset($d[3]) && $d[3] !== '') {
                    $weight_kg = (float) str_replace(',', '.', $d[3]);
                }
            } elseif (preg_match('/^(\d+)\s*мин/u', $details, $d)) {
                $duration_sec = (int) $d[1] * 60;
            } else {
                $notes = $details;
            }
        }

        $exercises[] = [
            'name' => $name,
            'sets' => $sets,
            'reps' => $reps,
            'weight_kg' => $weight_kg,
            'duration_sec' => $duration_sec,
            'distance_m' => $distance_m,
            'notes' => $notes,
        ];
    }
    return $exercises;
}
