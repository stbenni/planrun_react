<?php
/**
 * Парсер FIT-файлов (Garmin, Suunto, Coros, Wahoo и др.)
 * Использует библиотеку adriangibbons/php-fit-file-analysis
 * Возвращает данные в том же формате, что и GpxTcxParser::parse()
 */

require_once __DIR__ . '/../vendor/autoload.php';

use adriangibbons\phpFITFileAnalysis;

class FitParser {
    /**
     * Парсит FIT-файл и возвращает тренировку в нормализованном формате
     * @param string $filePath Путь к FIT-файлу
     * @param string|null $date Дата тренировки (Y-m-d) для fallback
     * @return array|null
     */
    public static function parse(string $filePath, ?string $date = null): ?array {
        try {
            $fit = new phpFITFileAnalysis($filePath, [
                'overwrite_with_dev_data' => false,
            ]);
        } catch (\Exception $e) {
            error_log("FitParser: Failed to parse FIT file: " . $e->getMessage());
            return null;
        }

        $record = $fit->data_mesgs['record'] ?? [];
        if (empty($record) || empty($record['timestamp'] ?? [])) {
            return null;
        }

        // === Извлекаем time-series данные ===
        $timestamps  = $record['timestamp'] ?? [];
        $heartRates  = $record['heart_rate'] ?? [];
        $latitudes   = $record['position_lat'] ?? [];
        $longitudes  = $record['position_long'] ?? [];
        $altitudes   = $record['altitude'] ?? [];
        $cadences    = $record['cadence'] ?? [];
        $speeds      = $record['speed'] ?? [];      // м/с
        $distances   = $record['distance'] ?? [];    // метры (кумулятивная)

        // === Session data (summary) ===
        $session = $fit->data_mesgs['session'] ?? [];
        $avgHr = null;
        $maxHr = null;
        $totalDistance = null;
        $totalElapsed = null;
        $avgSpeed = null;
        $activityType = 'running';
        $totalAscent = null;
        $totalCalories = null;
        $avgCadence = null;

        // FIT sport enum: 0=generic, 1=running, 2=cycling, 5=swimming, 11=walking, 17=hiking, 4=fitness
        $fitSportMap = [
            0 => 'running', 1 => 'running', 2 => 'cycling', 4 => 'fitness',
            5 => 'swimming', 11 => 'walking', 17 => 'walking',
        ];

        if (!empty($session)) {
            $avgHr = self::firstVal($session['avg_heart_rate'] ?? null);
            $maxHr = self::firstVal($session['max_heart_rate'] ?? null);
            $totalDistance = self::firstVal($session['total_distance'] ?? null);     // км (библиотека конвертирует)
            $totalElapsed = self::firstVal($session['total_elapsed_time'] ?? null);  // секунды
            $avgSpeed = self::firstVal($session['avg_speed'] ?? null);               // м/с
            $totalAscent = self::firstVal($session['total_ascent'] ?? null);         // метры
            $totalCalories = self::firstVal($session['total_calories'] ?? null);
            $avgCadence = self::firstVal($session['avg_cadence'] ?? null);

            // Определяем тип активности
            $sport = self::firstVal($session['sport'] ?? null);
            if ($sport !== null) {
                if (is_numeric($sport)) {
                    $activityType = $fitSportMap[(int)$sport] ?? 'running';
                } elseif (is_string($sport)) {
                    $sportLower = strtolower($sport);
                    if (strpos($sportLower, 'cycling') !== false || strpos($sportLower, 'biking') !== false) {
                        $activityType = 'cycling';
                    } elseif (strpos($sportLower, 'walk') !== false || strpos($sportLower, 'hiking') !== false) {
                        $activityType = 'walking';
                    } elseif (strpos($sportLower, 'swim') !== false) {
                        $activityType = 'swimming';
                    }
                }
            }
        }

        // === Вычисляем start/end time ===
        $tsValues = is_array($timestamps) ? array_values($timestamps) : [];
        if (empty($tsValues)) return null;

        $startTimestamp = min($tsValues);
        $endTimestamp = max($tsValues);
        $startTime = date('Y-m-d H:i:s', (int)$startTimestamp);
        $endTime = date('Y-m-d H:i:s', (int)$endTimestamp);

        // === Дистанция (библиотека уже конвертирует в км) ===
        $distanceKm = null;
        if ($totalDistance !== null && $totalDistance > 0) {
            $distanceKm = round($totalDistance, 3);
        } elseif (!empty($distances)) {
            $lastDist = is_array($distances) ? max($distances) : (float)$distances;
            if ($lastDist > 0) {
                $distanceKm = round($lastDist, 3);
            }
        }

        // === Длительность ===
        $durationSec = null;
        if ($totalElapsed !== null && $totalElapsed > 0) {
            $durationSec = (int)round($totalElapsed);
        } else {
            $durationSec = (int)($endTimestamp - $startTimestamp);
        }
        $durationMinutes = $durationSec > 0 ? round($durationSec / 60, 1) : null;

        // === Средний темп (из точных секунд, не из округлённых минут) ===
        $avgPace = null;
        if ($distanceKm > 0 && $durationSec > 0) {
            $paceSecPerKm = $durationSec / $distanceKm;
            $m = (int)floor($paceSecPerKm / 60);
            $s = (int)round($paceSecPerKm % 60);
            if ($s >= 60) { $s -= 60; $m++; }
            $avgPace = sprintf('%d:%02d', $m, $s);
        }

        // === Набор высоты (если нет в session, считаем из record) ===
        $elevationGain = null;
        if ($totalAscent !== null && $totalAscent > 0) {
            $elevationGain = (int)$totalAscent;
        } elseif (!empty($altitudes) && is_array($altitudes)) {
            $gain = 0;
            $prevAlt = null;
            foreach ($altitudes as $alt) {
                if ($alt !== null && $prevAlt !== null && $alt > $prevAlt) {
                    $gain += ($alt - $prevAlt);
                }
                $prevAlt = $alt;
            }
            $elevationGain = $gain > 0 ? (int)round($gain) : null;
        }

        // === Avg/Max HR (fallback из record если нет в session) ===
        if ($avgHr === null && !empty($heartRates) && is_array($heartRates)) {
            $validHr = array_filter($heartRates, fn($v) => $v !== null && $v > 30 && $v < 250);
            if (!empty($validHr)) {
                $avgHr = (int)round(array_sum($validHr) / count($validHr));
                $maxHr = (int)max($validHr);
            }
        }

        // === Timeline ===
        $timeline = self::buildTimeline($timestamps, $heartRates, $latitudes, $longitudes, $altitudes, $cadences, $speeds, $distances);

        // === Laps ===
        $laps = self::buildLaps($fit->data_mesgs['lap'] ?? []);

        return [
            'activity_type' => $activityType,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_minutes' => $durationMinutes,
            'duration_seconds' => $durationSec,
            'distance_km' => $distanceKm,
            'avg_pace' => $avgPace,
            'avg_heart_rate' => $avgHr ? (int)$avgHr : null,
            'max_heart_rate' => $maxHr ? (int)$maxHr : null,
            'elevation_gain' => $elevationGain,
            'calories' => $totalCalories ? (int)$totalCalories : null,
            'cadence' => $avgCadence ? (int)($avgCadence * 2) : null, // FIT хранит шаги на ногу
            'external_id' => null,
            'timeline' => $timeline,
            'laps' => $laps,
        ];
    }

    /**
     * Строит timeline из record-данных FIT
     */
    private static function buildTimeline(
        array $timestamps,
        $heartRates,
        $latitudes,
        $longitudes,
        $altitudes,
        $cadences,
        $speeds,
        $distances
    ): ?array {
        $count = count($timestamps);
        if ($count < 2) return null;

        // Даунсэмплинг до ~500 точек
        $step = max(1, (int)floor($count / 500));
        $timeline = [];
        // timestamps — [0 => ts1, 1 => ts2, ...], остальные — [ts1 => val, ts2 => val, ...]
        $tsValues = array_values($timestamps);

        $indices = [];
        for ($i = 0; $i < $count; $i += $step) $indices[] = $i;
        if ($count > 1 && ($count - 1) % $step !== 0) $indices[] = $count - 1;

        foreach ($indices as $idx) {
            $ts = $tsValues[$idx] ?? null;
            if ($ts === null) continue;

            // Используем timestamp как ключ для поиска в остальных массивах
            $key = $ts;

            // GPS координаты: библиотека конвертирует semicircles в градусы
            $lat = self::getVal($latitudes, $key);
            $lon = self::getVal($longitudes, $key);

            // Пропускаем точки без валидных координат
            if ($lat !== null && $lon !== null && abs($lat) < 0.0001 && abs($lon) < 0.0001) {
                $lat = null;
                $lon = null;
            }

            $hr = self::getVal($heartRates, $key);
            if ($hr !== null && ($hr < 30 || $hr > 250)) $hr = null;

            $alt = self::getVal($altitudes, $key);
            $cad = self::getVal($cadences, $key);
            if ($cad !== null && $cad > 0) $cad = (int)($cad * 2); // шаги на ногу → шагов/мин

            // Дистанция (кумулятивная, библиотека уже в км)
            $dist = self::getVal($distances, $key);
            $distKm = ($dist !== null && $dist > 0) ? round($dist, 3) : null;

            // Темп из скорости (м/с → мин/км)
            $pace = null;
            $speed = self::getVal($speeds, $key);
            if ($speed !== null && $speed > 0.3) { // > ~1 км/ч (чтобы не было бесконечного темпа)
                $paceSecPerKm = 1000 / $speed; // секунд на километр
                if ($paceSecPerKm >= 120 && $paceSecPerKm <= 900) { // 2:00 — 15:00 мин/км
                    $m = (int)floor($paceSecPerKm / 60);
                    $s = (int)round($paceSecPerKm % 60);
                    if ($s >= 60) { $s -= 60; $m++; }
                    $pace = sprintf('%d:%02d', $m, $s);
                }
            }

            $timeline[] = [
                'timestamp' => date('Y-m-d H:i:s', (int)$ts),
                'heart_rate' => $hr !== null ? (int)$hr : null,
                'pace' => $pace,
                'altitude' => $alt !== null ? round((float)$alt, 1) : null,
                'distance' => $distKm,
                'cadence' => $cad !== null ? (int)$cad : null,
                'latitude' => $lat !== null ? (float)$lat : null,
                'longitude' => $lon !== null ? (float)$lon : null,
            ];
        }

        return !empty($timeline) ? $timeline : null;
    }

    /**
     * Строит массив кругов из lap-данных FIT
     */
    private static function buildLaps(array $lapData): ?array {
        if (empty($lapData)) return null;

        $timestamps = $lapData['timestamp'] ?? [];
        $totalTimers = $lapData['total_timer_time'] ?? [];
        $totalDists = $lapData['total_distance'] ?? [];
        $avgHrs = $lapData['avg_heart_rate'] ?? [];
        $maxHrs = $lapData['max_heart_rate'] ?? [];
        $avgSpeeds = $lapData['avg_speed'] ?? [];
        $avgCadences = $lapData['avg_cadence'] ?? [];
        $totalAscents = $lapData['total_ascent'] ?? [];

        if (!is_array($timestamps) || empty($timestamps)) return null;

        $laps = [];
        $count = count($timestamps);
        for ($i = 0; $i < $count; $i++) {
            $duration = self::getIdx($totalTimers, $i);
            $dist = self::getIdx($totalDists, $i);
            $distKm = ($dist !== null && $dist > 0) ? round($dist, 3) : null;

            // Темп из avg_speed
            $pace = null;
            $avgSpd = self::getIdx($avgSpeeds, $i);
            if ($avgSpd !== null && $avgSpd > 0.3) {
                $paceSecPerKm = 1000 / $avgSpd;
                if ($paceSecPerKm >= 120 && $paceSecPerKm <= 900) {
                    $m = (int)floor($paceSecPerKm / 60);
                    $s = (int)round($paceSecPerKm % 60);
                    if ($s >= 60) { $s -= 60; $m++; }
                    $pace = sprintf('%d:%02d', $m, $s);
                }
            }

            $hr = self::getIdx($avgHrs, $i);
            $cad = self::getIdx($avgCadences, $i);

            $laps[] = [
                'name' => 'Круг ' . ($i + 1),
                'distance_km' => $distKm,
                'duration_seconds' => $duration !== null ? (int)round($duration) : null,
                'avg_pace' => $pace,
                'avg_heart_rate' => $hr !== null ? (int)$hr : null,
                'max_heart_rate' => self::getIdx($maxHrs, $i) !== null ? (int)self::getIdx($maxHrs, $i) : null,
                'cadence' => $cad !== null ? (int)($cad * 2) : null,
                'elevation_gain' => self::getIdx($totalAscents, $i) !== null ? (int)self::getIdx($totalAscents, $i) : null,
            ];
        }

        return !empty($laps) ? $laps : null;
    }

    /**
     * Получить значение из массива или скаляра по ключу
     */
    private static function getVal($data, $key) {
        if ($data === null) return null;
        if (is_array($data)) {
            return $data[$key] ?? null;
        }
        return $data;
    }

    /**
     * Получить значение из массива по числовому индексу
     */
    private static function getIdx($data, int $index) {
        if ($data === null || !is_array($data)) return null;
        $values = array_values($data);
        return $values[$index] ?? null;
    }

    /**
     * Получить первое значение из массива или скаляр
     */
    private static function firstVal($data) {
        if ($data === null) return null;
        if (is_array($data)) {
            return !empty($data) ? reset($data) : null;
        }
        return $data;
    }
}
