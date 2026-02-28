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
        return null;
    }

    private static function parseGpx(string $filePath, ?string $date): ?array {
        $xml = @simplexml_load_file($filePath);
        if (!$xml) return null;
        $points = [];
        $trkpts = $xml->xpath('//trkpt');
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
            $lat = (float)($trkpt['lat'] ?? 0);
            $lon = (float)($trkpt['lon'] ?? 0);
            $time = null;
            if (isset($trkpt->time)) {
                $time = strtotime((string)$trkpt->time);
            }
            $ele = isset($trkpt->ele) ? (float)$trkpt->ele : null;
            $points[] = ['lat' => $lat, 'lon' => $lon, 'time' => $time, 'ele' => $ele];
        }
        if (empty($points)) return null;
        $distance = self::calculateDistance($points);
        $startTime = null;
        $endTime = null;
        foreach ($points as $p) {
            if ($p['time']) {
                $startTime = $startTime ?? $p['time'];
                $endTime = $p['time'];
            }
        }
        $durationSec = ($startTime && $endTime) ? (int)($endTime - $startTime) : null;
        $durationMinutes = $durationSec !== null ? (int)round($durationSec / 60) : null;
        $startTimeStr = $startTime ? date('Y-m-d H:i:s', $startTime) : ($date ? $date . ' 12:00:00' : null);
        $endTimeStr = $endTime ? date('Y-m-d H:i:s', $endTime) : $startTimeStr;
        $avgPace = ($distance > 0 && $durationMinutes > 0) ? self::paceFromKmAndMinutes($distance, $durationMinutes) : null;
        return [
            'activity_type' => 'running',
            'start_time' => $startTimeStr,
            'end_time' => $endTimeStr,
            'duration_minutes' => $durationMinutes,
            'duration_seconds' => $durationSec,
            'distance_km' => $distance > 0 ? round($distance, 3) : null,
            'avg_pace' => $avgPace,
            'elevation_gain' => self::calculateElevationGain($points),
            'external_id' => null,
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
        ];
    }

    private static function calculateDistance(array $points): float {
        $total = 0;
        $prev = null;
        foreach ($points as $p) {
            if ($prev && $p['lat'] && $p['lon']) {
                $total += self::haversineKm($prev['lat'], $prev['lon'], $p['lat'], $p['lon']);
            }
            $prev = $p;
        }
        return $total;
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
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
