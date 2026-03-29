<?php
/**
 * Парсер GPX и TCX для извлечения данных тренировки
 */
class GpxTcxParser {
    /**
     * Парсит файл и возвращает тренировку в нормализованном формате
     * @param string $filePath Путь к временному файлу
     * @param string $date Дата тренировки (Y-m-d) для fallback
     * @return array|null [activity_type, start_time, end_time, duration_minutes, distance_km, avg_pace, ...]
     */
    public static function parse(string $filePath, string $date = null): ?array {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'gpx') {
            return self::parseGpx($filePath, $date);
        }
        if ($ext === 'tcx') {
            return self::parseTcx($filePath, $date);
        }
        if ($ext === 'fit') {
            require_once __DIR__ . '/FitParser.php';
            return FitParser::parse($filePath, $date);
        }
        return null;
    }

    private static function parseGpx(string $filePath, ?string $date): ?array {
        $xml = @simplexml_load_file($filePath);
        if (!$xml) return null;

        // Регистрируем namespaces для поиска extensions
        $namespaces = $xml->getNamespaces(true);
        $gpxNs = $namespaces[''] ?? '';
        if ($gpxNs) {
            $xml->registerXPathNamespace('gpx', $gpxNs);
        }

        $points = [];
        $maxCumulativeDistance = 0; // Кумулятивная дистанция из extensions (метры)

        $trkpts = $gpxNs ? $xml->xpath('//gpx:trkpt') : $xml->xpath('//trkpt');
        if (empty($trkpts) && isset($xml->trk)) {
            foreach ($xml->trk as $trk) {
                foreach ($trk->trkseg ?? [] as $seg) {
                    foreach ($seg->trkpt ?? [] as $trkpt) {
                        $trkpts[] = $trkpt;
                    }
                }
            }
        }
        foreach ($trkpts ?: [] as $trkpt) {
            $lat = isset($trkpt['lat']) ? (float)$trkpt['lat'] : null;
            $lon = isset($trkpt['lon']) ? (float)$trkpt['lon'] : null;
            if ($lat === null || $lon === null) continue;
            // Пропускаем точки с нулевыми координатами (невалидные)
            if (abs($lat) < 0.0001 && abs($lon) < 0.0001) continue;

            $time = null;
            if (isset($trkpt->time)) {
                $time = strtotime((string)$trkpt->time);
            }
            $ele = isset($trkpt->ele) ? (float)$trkpt->ele : null;

            // Ищем данные в extensions (HR, cadence, distance, speed)
            $dist = null;
            $hr = null;
            $cad = null;
            $speed = null;
            if (isset($trkpt->extensions)) {
                // Проверяем все namespace-расширения (Garmin gpxtpx, Suunto, Coros и т.д.)
                foreach ($namespaces as $prefix => $uri) {
                    if ($prefix === '' || $prefix === 'gpx' || $prefix === 'xsi') continue;
                    $extChildren = $trkpt->extensions->children($uri);
                    foreach ($extChildren as $ext) {
                        $extName = strtolower($ext->getName());
                        // Прямые значения
                        if ($extName === 'hr' && $hr === null) $hr = (int)$ext;
                        if (($extName === 'cad' || $extName === 'cadence') && $cad === null) $cad = (int)$ext;
                        if (($extName === 'distance' || $extName === 'dist') && $dist === null) $dist = (float)$ext;
                        if ($extName === 'speed' && $speed === null) $speed = (float)$ext;

                        // Вложенные (напр. <gpxtpx:TrackPointExtension><gpxtpx:hr>)
                        foreach ($ext->children($uri) as $child) {
                            $childName = strtolower($child->getName());
                            if ($childName === 'hr' && $hr === null) $hr = (int)$child;
                            if (($childName === 'cad' || $childName === 'cadence' || $childName === 'runcadence') && $cad === null) $cad = (int)$child;
                            if (($childName === 'distance' || $childName === 'dist') && $dist === null) $dist = (float)$child;
                            if ($childName === 'speed' && $speed === null) $speed = (float)$child;
                        }
                    }
                }
                // Fallback: проверяем элементы без namespace
                foreach ($trkpt->extensions->children() as $ext) {
                    $extName = strtolower($ext->getName());
                    if ($extName === 'hr' && $hr === null) $hr = (int)$ext;
                    if (($extName === 'distance' || $extName === 'dist') && $dist === null) $dist = (float)$ext;
                }
            }
            if ($hr !== null && ($hr < 30 || $hr > 250)) $hr = null; // фильтр невалидных
            if ($cad !== null && $cad > 0) $cad = $cad * 2; // Garmin отдаёт половину (шагов на ногу)
            if ($dist !== null && $dist > $maxCumulativeDistance) {
                $maxCumulativeDistance = $dist;
            }

            $points[] = ['lat' => $lat, 'lon' => $lon, 'time' => $time, 'ele' => $ele, 'hr' => $hr, 'cad' => $cad, 'speed' => $speed];
        }
        if (empty($points)) return null;

        // === Дистанция: выбираем лучший источник ===
        // Приоритет: 1) extension distance  2) speed × time  3) Haversine + коррекция
        $haversineDistance = self::calculateDistance($points);
        $extensionDistance = $maxCumulativeDistance / 1000; // метры → км
        $speedDistance = self::calculateSpeedDistance($points); // из speed × dt (м/с × сек → км)

        if ($extensionDistance > 0.1 && $haversineDistance > 0) {
            // Кумулятивная дистанция из extensions (самый надёжный источник)
            $ratio = $extensionDistance / $haversineDistance;
            $distance = ($ratio > 0.5 && $ratio < 2.0) ? $extensionDistance : $haversineDistance;
        } elseif ($speedDistance > 0.1) {
            // Speed × time: данные с акселерометра+GPS устройства, точнее чем GPS-only Haversine.
            // Фильтрует GPS-шум (джиттер ±3-5м) который добавляет ~3-5% к Haversine при 1Hz.
            $distance = $speedDistance;
        } else {
            // Fallback: Haversine с коррекцией на частоту записи
            $pointCount = count($points);
            $firstTime = null;
            $lastTime = null;
            foreach ($points as $pt) {
                if (!empty($pt['time'])) {
                    $firstTime = $firstTime ?? $pt['time'];
                    $lastTime = $pt['time'];
                }
            }
            if ($firstTime && $lastTime && $pointCount > 1) {
                $totalTimeSec = abs($lastTime - $firstTime);
                $avgInterval = $totalTimeSec / ($pointCount - 1);
                if ($avgInterval <= 2) {
                    $correctionFactor = 1.007;
                } elseif ($avgInterval <= 5) {
                    $correctionFactor = 1.010;
                } elseif ($avgInterval <= 15) {
                    $correctionFactor = 1.015;
                } else {
                    $correctionFactor = 1.025;
                }
            } else {
                $correctionFactor = 1.010;
            }
            $distance = $haversineDistance * $correctionFactor;
        }
        $startTime = null;
        $endTime = null;
        foreach ($points as $p) {
            if ($p['time']) {
                $startTime = $startTime ?? $p['time'];
                $endTime = $p['time'];
            }
        }
        $durationSec = ($startTime && $endTime) ? (int)($endTime - $startTime) : null;
        $durationMinutes = $durationSec !== null ? round($durationSec / 60, 1) : null;
        $startTimeStr = $startTime ? date('Y-m-d H:i:s', $startTime) : ($date ? $date . ' 12:00:00' : null);
        $endTimeStr = $endTime ? date('Y-m-d H:i:s', $endTime) : $startTimeStr;
        // Темп считаем из точных секунд, а не из округлённых минут
        $avgPace = null;
        if ($distance > 0 && $durationSec > 0) {
            $paceSecPerKm = $durationSec / $distance;
            $m = (int)floor($paceSecPerKm / 60);
            $s = (int)round($paceSecPerKm % 60);
            if ($s >= 60) { $s -= 60; $m++; }
            $avgPace = sprintf('%d:%02d', $m, $s);
        }

        // Средний и максимальный пульс из extensions
        $hrValues = array_filter(array_column($points, 'hr'), fn($v) => $v !== null && $v > 0);
        $avgHr = !empty($hrValues) ? (int)round(array_sum($hrValues) / count($hrValues)) : null;
        $maxHr = !empty($hrValues) ? (int)max($hrValues) : null;

        return [
            'activity_type' => 'running',
            'start_time' => $startTimeStr,
            'end_time' => $endTimeStr,
            'duration_minutes' => $durationMinutes,
            'duration_seconds' => $durationSec,
            'distance_km' => $distance > 0 ? round($distance, 3) : null,
            'avg_pace' => $avgPace,
            'avg_heart_rate' => $avgHr,
            'max_heart_rate' => $maxHr,
            'elevation_gain' => self::calculateElevationGain($points),
            'external_id' => null,
            'timeline' => self::buildTimeline($points),
        ];
    }

    private static function parseTcx(string $filePath, ?string $date): ?array {
        $xml = @simplexml_load_file($filePath);
        if (!$xml) return null;
        $ns = $xml->getNamespaces(true);
        $tcxNs = $ns[''] ?? 'http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2';
        $xml->registerXPathNamespace('t', $tcxNs);
        $points = [];
        $startTime = null;
        $endTime = null;
        $totalDistance = 0;
        $activityType = 'running';
        $trackpoints = $xml->xpath('//t:Trackpoint') ?: $xml->xpath('//Trackpoint') ?: [];
        if (empty($trackpoints) && isset($xml->Activities->Activity)) {
            foreach ($xml->Activities->Activity->Lap ?? [] as $lap) {
                foreach ($lap->Track->Trackpoint ?? [] as $tp) {
                    $trackpoints[] = $tp;
                }
            }
        }
        foreach ($trackpoints as $tp) {
            $ns = $tp->getNamespaces(true);
            $lat = (float)($tp->Position->LatitudeDegrees ?? 0);
            $lon = (float)($tp->Position->LongitudeDegrees ?? 0);
            $time = null;
            if (isset($tp->Time)) {
                $time = strtotime((string)$tp->Time);
                $startTime = $startTime ?? $time;
                $endTime = $time;
            }
            $dist = isset($tp->DistanceMeters) ? (float)$tp->DistanceMeters : null;
            if ($dist !== null) $totalDistance = $dist / 1000;
            $points[] = ['lat' => $lat, 'lon' => $lon, 'time' => $time, 'ele' => isset($tp->AltitudeMeters) ? (float)$tp->AltitudeMeters : null];
        }
        $distance = $totalDistance > 0 ? $totalDistance : (count($points) > 1 ? self::calculateDistance($points) : 0);
        $durationSec = ($startTime && $endTime) ? (int)($endTime - $startTime) : null;
        $duration = $durationSec !== null ? (int)round($durationSec / 60) : null;
        $startTimeStr = $startTime ? date('Y-m-d H:i:s', $startTime) : ($date ? $date . ' 12:00:00' : null);
        $endTimeStr = $endTime ? date('Y-m-d H:i:s', $endTime) : $startTimeStr;
        $avgPace = ($distance > 0 && $duration > 0) ? self::paceFromKmAndMinutes($distance, $duration) : null;
        $activity = $xml->Activities->Activity ?? null;
        if ($activity && isset($activity['Sport'])) {
            $sport = strtolower((string)$activity['Sport']);
            if (strpos($sport, 'bik') !== false) $activityType = 'cycling';
            elseif (strpos($sport, 'walk') !== false) $activityType = 'walking';
        }
        return [
            'activity_type' => $activityType,
            'start_time' => $startTimeStr,
            'end_time' => $endTimeStr,
            'duration_minutes' => $duration,
            'duration_seconds' => $durationSec,
            'distance_km' => $distance > 0 ? round($distance, 3) : null,
            'avg_pace' => $avgPace,
            'elevation_gain' => self::calculateElevationGain($points),
            'external_id' => null,
            'timeline' => self::buildTimeline($points),
        ];
    }

    private static function buildTimeline(array $points): ?array {
        $count = count($points);
        if ($count < 2) return null;
        $step = max(1, (int)floor($count / 500));
        $timeline = [];
        $cumulativeDistance = 0;
        $prevPoint = null;

        $indices = [];
        for ($i = 0; $i < $count; $i += $step) $indices[] = $i;
        if ($count > 1 && ($count - 1) % $step !== 0) $indices[] = $count - 1;

        foreach ($indices as $i) {
            $p = $points[$i];
            if (abs($p['lat']) < 0.0001 && abs($p['lon']) < 0.0001) continue;

            // Рассчитываем кумулятивную дистанцию и темп
            $pace = null;
            if ($prevPoint !== null) {
                $segDist = self::haversineKm($prevPoint['lat'], $prevPoint['lon'], $p['lat'], $p['lon']);
                $cumulativeDistance += $segDist;

                // Темп: предпочитаем speed из устройства (точнее GPS-derived)
                $deviceSpeed = $p['speed'] ?? null;
                if ($deviceSpeed !== null && $deviceSpeed > 0.3) {
                    // speed в м/с → темп мин/км
                    $paceSecPerKm = 1000 / $deviceSpeed;
                    if ($paceSecPerKm >= 120 && $paceSecPerKm <= 900) {
                        $m = (int)floor($paceSecPerKm / 60);
                        $s = (int)round($paceSecPerKm % 60);
                        if ($s >= 60) { $s -= 60; $m++; }
                        $pace = sprintf('%d:%02d', $m, $s);
                    }
                } elseif ($p['time'] && $prevPoint['time'] && $segDist > 0.005) {
                    // Fallback: темп из GPS координат
                    $dt = $p['time'] - $prevPoint['time'];
                    if ($dt > 0 && $dt < 300) {
                        $paceSecPerKm = $dt / $segDist;
                        if ($paceSecPerKm >= 120 && $paceSecPerKm <= 900) {
                            $m = (int)floor($paceSecPerKm / 60);
                            $s = (int)round($paceSecPerKm % 60);
                            if ($s >= 60) { $s -= 60; $m++; }
                            $pace = sprintf('%d:%02d', $m, $s);
                        }
                    }
                }
            }

            $timeline[] = [
                'timestamp' => $p['time'] ? date('Y-m-d H:i:s', $p['time']) : null,
                'heart_rate' => $p['hr'] ?? null,
                'pace' => $pace,
                'altitude' => $p['ele'],
                'distance' => round($cumulativeDistance, 3),
                'cadence' => $p['cad'] ?? null,
                'latitude' => (float)$p['lat'],
                'longitude' => (float)$p['lon'],
            ];
            $prevPoint = $p;
        }
        return !empty($timeline) ? $timeline : null;
    }

    private static function calculateDistance(array $points): float {
        $total = 0;
        $prev = null;
        $prevBearing = null;

        foreach ($points as $p) {
            // Пропускаем точки без валидных координат
            if (abs($p['lat']) < 0.0001 && abs($p['lon']) < 0.0001) continue;

            if ($prev !== null) {
                $seg = self::haversineKm($prev['lat'], $prev['lon'], $p['lat'], $p['lon']);

                // Фильтруем GPS-выбросы: сегмент > 1 км при разнице времени < 5 сек
                if (isset($p['time']) && isset($prev['time_raw']) && $p['time'] && $prev['time_raw']) {
                    $dt = abs($p['time'] - $prev['time_raw']);
                    if ($dt < 5 && $seg > 1.0) {
                        continue;
                    }
                }

                // Коррекция на кривизну: прямая линия «срезает» повороты.
                // Рассчитываем изменение направления (bearing) между сегментами.
                // Для дуги с хордой c и углом θ: arc = c × θ / (2 × sin(θ/2))
                // Приближение для малых углов: arc ≈ c × (1 + θ²/24)
                if ($seg > 0.001) { // > 1 метр
                    $bearing = self::bearing($prev['lat'], $prev['lon'], $p['lat'], $p['lon']);
                    if ($prevBearing !== null) {
                        $turnAngle = abs($bearing - $prevBearing);
                        if ($turnAngle > M_PI) $turnAngle = 2 * M_PI - $turnAngle;
                        // Кривизну делим на 2: каждый сегмент «принимает» половину поворота
                        $halfTurn = $turnAngle / 2;
                        if ($halfTurn > 0.01) { // > ~0.6° — значимый поворот
                            $correction = 1 + ($halfTurn * $halfTurn) / 24;
                            $seg *= $correction;
                        }
                    }
                    $prevBearing = $bearing;
                }

                $total += $seg;
            }
            $prev = ['lat' => $p['lat'], 'lon' => $p['lon'], 'time_raw' => $p['time'] ?? null];
        }
        return $total;
    }

    /**
     * Рассчитывает начальный азимут (bearing) между двумя точками в радианах [0, 2π)
     */
    private static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLon = deg2rad($lon2 - $lon1);
        $y = sin($dLon) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
        $bearing = atan2($y, $x);
        return fmod($bearing + 2 * M_PI, 2 * M_PI);
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }

    /**
     * Рассчитывает дистанцию из поля speed (м/с) в extensions: sum(speed × dt)
     * Это данные с акселерометра+GPS устройства — точнее чем GPS-only Haversine,
     * т.к. фильтрует GPS-шум (джиттер ±3-5м добавляет ~3-5% к Haversine при 1Hz).
     * Доступно в GPX от Zepp/Amazfit, некоторых Garmin, Polar.
     */
    private static function calculateSpeedDistance(array $points): float {
        $totalMeters = 0;
        $prevTime = null;
        $hasSpeed = false;

        foreach ($points as $p) {
            $speed = $p['speed'] ?? null;
            $time = $p['time'] ?? null;

            if ($speed !== null) $hasSpeed = true;

            if ($prevTime !== null && $time !== null && $speed !== null && $speed >= 0) {
                $dt = $time - $prevTime;
                if ($dt > 0 && $dt < 30) { // макс 30 сек между точками
                    $totalMeters += $speed * $dt;
                }
            }

            if ($time !== null) $prevTime = $time;
        }

        return $hasSpeed ? $totalMeters / 1000 : 0; // возвращаем км, 0 если нет speed данных
    }

    private static function calculateElevationGain(array $points): ?int {
        $gain = 0;
        $prev = null;
        foreach ($points as $p) {
            if ($p['ele'] !== null && $prev !== null && $prev['ele'] !== null && $p['ele'] > $prev['ele']) {
                $gain += (int)round($p['ele'] - $prev['ele']);
            }
            $prev = $p;
        }
        return $gain > 0 ? $gain : null;
    }

    private static function paceFromKmAndMinutes(float $km, int $minutes): string {
        if ($km <= 0) return null;
        $paceMinPerKm = $minutes / $km;
        $m = (int)floor($paceMinPerKm);
        $s = (int)round(($paceMinPerKm - $m) * 60);
        if ($s >= 60) { $s += 60; $m++; }
        return sprintf('%d:%02d', $m, $s);
    }
}
