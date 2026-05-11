<?php

require_once __DIR__ . '/BaseService.php';

/**
 * Прогноз погоды для тренировок. OpenWeatherMap 5-day/3-hour forecast.
 * Кэш на 3 часа (TTL) в weather_forecast_cache, ключ — округлённые координаты.
 *
 * Резолв локации:
 *  1. users.latitude/longitude (если задано)
 *  2. fallback: timezone → координаты крупного города (Europe/Moscow → Москва)
 *  3. если ни то ни другое — null (нет погоды)
 *
 * Конфиг (env):
 *  WEATHER_API_KEY — ключ OpenWeatherMap. Без ключа сервис тихо отключён.
 *  WEATHER_CACHE_TTL_SECONDS — по умолчанию 10800 (3 часа)
 */
class WeatherService extends BaseService {
    private const API_BASE = 'https://api.openweathermap.org/data/2.5/forecast';
    private const CACHE_TTL = 10800; // 3 hours

    private string $apiKey;
    private int $cacheTtl;

    public function __construct($db) {
        parent::__construct($db);
        $this->apiKey = (string) env('WEATHER_API_KEY', '');
        $this->cacheTtl = max(300, min(86400, (int) env('WEATHER_CACHE_TTL_SECONDS', self::CACHE_TTL)));
    }

    public function isEnabled(): bool {
        return $this->apiKey !== '';
    }

    /**
     * Возвращает прогноз на dates (Y-m-d list) для пользователя или null если нет данных/ключа.
     * Формат: ['location' => ['lat','lon','city'], 'forecasts' => [date => {min/max temp, precip, wind, condition}]]
     */
    public function getForecastForUser(int $userId, array $dates): ?array {
        if (!$this->isEnabled()) return null;
        $loc = $this->resolveUserLocation($userId);
        if ($loc === null) return null;

        $cached = $this->fetchFromCache($loc['lat'], $loc['lon']);
        $raw = $cached ?? $this->fetchFromApi($loc['lat'], $loc['lon']);
        if ($raw === null) return null;
        if ($cached === null) {
            $this->saveToCache($loc['lat'], $loc['lon'], $raw);
        }

        $byDay = $this->aggregateByDay($raw);
        $out = [];
        foreach ($dates as $date) {
            if (isset($byDay[$date])) $out[$date] = $byDay[$date];
        }
        return ['location' => $loc, 'forecasts' => $out];
    }

    /**
     * Резолвит локацию пользователя: сначала из users.latitude/longitude, потом fallback по timezone.
     */
    private function resolveUserLocation(int $userId): ?array {
        $stmt = $this->db->prepare("SELECT latitude, longitude, location_city, timezone FROM users WHERE id = ?");
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;

        $lat = isset($row['latitude']) && $row['latitude'] !== null ? (float) $row['latitude'] : null;
        $lon = isset($row['longitude']) && $row['longitude'] !== null ? (float) $row['longitude'] : null;
        $city = $row['location_city'] ?? null;

        if ($lat !== null && $lon !== null) {
            return ['lat' => $lat, 'lon' => $lon, 'city' => $city ?? 'указанная локация'];
        }

        // Fallback: timezone → city center
        $tzMap = $this->timezoneToCityFallback();
        $tz = (string) ($row['timezone'] ?? '');
        if (isset($tzMap[$tz])) {
            return $tzMap[$tz];
        }
        return null;
    }

    /**
     * Грубое сопоставление timezone → крупный город для пользователей без точной геолокации.
     * Покрывает основные регионы РФ/СНГ + крупные мировые города.
     */
    private function timezoneToCityFallback(): array {
        return [
            'Europe/Moscow' => ['lat' => 55.7558, 'lon' => 37.6173, 'city' => 'Москва (по умолчанию)'],
            'Europe/Kaliningrad' => ['lat' => 54.7104, 'lon' => 20.4522, 'city' => 'Калининград'],
            'Europe/Samara' => ['lat' => 53.1959, 'lon' => 50.1002, 'city' => 'Самара'],
            'Asia/Yekaterinburg' => ['lat' => 56.8389, 'lon' => 60.6057, 'city' => 'Екатеринбург'],
            'Asia/Omsk' => ['lat' => 54.9924, 'lon' => 73.3686, 'city' => 'Омск'],
            'Asia/Krasnoyarsk' => ['lat' => 56.0184, 'lon' => 92.8672, 'city' => 'Красноярск'],
            'Asia/Novosibirsk' => ['lat' => 55.0084, 'lon' => 82.9357, 'city' => 'Новосибирск'],
            'Asia/Irkutsk' => ['lat' => 52.2870, 'lon' => 104.3050, 'city' => 'Иркутск'],
            'Asia/Yakutsk' => ['lat' => 62.0339, 'lon' => 129.7330, 'city' => 'Якутск'],
            'Asia/Vladivostok' => ['lat' => 43.1056, 'lon' => 131.8735, 'city' => 'Владивосток'],
            'Europe/Kiev' => ['lat' => 50.4501, 'lon' => 30.5234, 'city' => 'Киев'],
            'Europe/Minsk' => ['lat' => 53.9006, 'lon' => 27.5590, 'city' => 'Минск'],
            'Asia/Almaty' => ['lat' => 43.2220, 'lon' => 76.8512, 'city' => 'Алматы'],
            'Asia/Tashkent' => ['lat' => 41.2995, 'lon' => 69.2401, 'city' => 'Ташкент'],
            'Asia/Tbilisi' => ['lat' => 41.7151, 'lon' => 44.8271, 'city' => 'Тбилиси'],
            'Asia/Yerevan' => ['lat' => 40.1792, 'lon' => 44.4991, 'city' => 'Ереван'],
            'Europe/London' => ['lat' => 51.5074, 'lon' => -0.1278, 'city' => 'Лондон'],
            'Europe/Berlin' => ['lat' => 52.5200, 'lon' => 13.4050, 'city' => 'Берлин'],
            'America/New_York' => ['lat' => 40.7128, 'lon' => -74.0060, 'city' => 'Нью-Йорк'],
            'America/Los_Angeles' => ['lat' => 34.0522, 'lon' => -118.2437, 'city' => 'Лос-Анджелес'],
        ];
    }

    private function locationKey(float $lat, float $lon): string {
        // Округление до 0.5° (~50 км) — соседние пользователи делят кэш
        return sprintf('%.1f_%.1f', round($lat * 2) / 2, round($lon * 2) / 2);
    }

    private function fetchFromCache(float $lat, float $lon): ?array {
        $key = $this->locationKey($lat, $lon);
        $stmt = $this->db->prepare("SELECT payload_json FROM weather_forecast_cache WHERE location_key = ? AND expires_at > NOW()");
        if (!$stmt) return null;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        $decoded = json_decode((string) $row['payload_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function saveToCache(float $lat, float $lon, array $payload): void {
        $key = $this->locationKey($lat, $lon);
        $expiresAt = (new DateTime('now'))->add(new DateInterval('PT' . $this->cacheTtl . 'S'))->format('Y-m-d H:i:s');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(
            "INSERT INTO weather_forecast_cache (location_key, fetched_at, expires_at, payload_json)
             VALUES (?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE fetched_at=NOW(), expires_at=VALUES(expires_at), payload_json=VALUES(payload_json)"
        );
        if (!$stmt) return;
        $stmt->bind_param('sss', $key, $expiresAt, $json);
        $stmt->execute();
        $stmt->close();
    }

    private function fetchFromApi(float $lat, float $lon): ?array {
        $url = self::API_BASE . '?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'appid' => $this->apiKey,
            'units' => 'metric',
            'lang' => 'ru',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            $this->logError('Weather API failed', ['code' => $code, 'error' => $err]);
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Aggregate 3h slots → per-day summary (min/max temp, precipitation, dominant condition, max wind).
     */
    private function aggregateByDay(array $raw): array {
        $list = is_array($raw['list'] ?? null) ? $raw['list'] : [];
        $byDay = [];
        foreach ($list as $slot) {
            $ts = (int) ($slot['dt'] ?? 0);
            if ($ts <= 0) continue;
            $date = gmdate('Y-m-d', $ts);
            $temp = (float) ($slot['main']['temp'] ?? 0);
            $rain = (float) ($slot['rain']['3h'] ?? 0);
            $snow = (float) ($slot['snow']['3h'] ?? 0);
            $wind = (float) ($slot['wind']['speed'] ?? 0);
            $cond = (string) ($slot['weather'][0]['description'] ?? '');

            if (!isset($byDay[$date])) {
                $byDay[$date] = [
                    'temp_min' => $temp, 'temp_max' => $temp,
                    'precipitation_mm' => 0.0, 'snow_mm' => 0.0,
                    'wind_max_ms' => $wind, 'conditions' => [],
                ];
            }
            $d = &$byDay[$date];
            $d['temp_min'] = min($d['temp_min'], $temp);
            $d['temp_max'] = max($d['temp_max'], $temp);
            $d['precipitation_mm'] += $rain;
            $d['snow_mm'] += $snow;
            $d['wind_max_ms'] = max($d['wind_max_ms'], $wind);
            if ($cond !== '' && !in_array($cond, $d['conditions'], true)) {
                $d['conditions'][] = $cond;
            }
            unset($d);
        }
        // Round nicely
        foreach ($byDay as &$d) {
            $d['temp_min'] = round($d['temp_min'], 1);
            $d['temp_max'] = round($d['temp_max'], 1);
            $d['precipitation_mm'] = round($d['precipitation_mm'], 1);
            $d['snow_mm'] = round($d['snow_mm'], 1);
            $d['wind_max_ms'] = round($d['wind_max_ms'], 1);
            $d['summary'] = $this->buildHumanSummary($d);
        }
        unset($d);
        return $byDay;
    }

    private function buildHumanSummary(array $d): string {
        $parts = [sprintf('%+.0f°…%+.0f°C', $d['temp_min'], $d['temp_max'])];
        if (!empty($d['conditions'])) $parts[] = $d['conditions'][0];
        if ($d['precipitation_mm'] > 0.5) $parts[] = sprintf('осадки %.1f мм', $d['precipitation_mm']);
        if ($d['wind_max_ms'] > 8.0) $parts[] = sprintf('ветер до %.0f м/с', $d['wind_max_ms']);
        return implode(', ', $parts);
    }

    /**
     * Heuristic flag: даёт ли погода повод изменить тренировку?
     * Возвращает массив тегов или пустой массив.
     */
    public function classifyConditions(array $dayForecast): array {
        $tags = [];
        $tMin = (float) ($dayForecast['temp_min'] ?? 0);
        $tMax = (float) ($dayForecast['temp_max'] ?? 0);
        if ($tMax >= 30) $tags[] = 'extreme_heat';
        elseif ($tMax >= 25) $tags[] = 'hot';
        if ($tMin <= -15) $tags[] = 'extreme_cold';
        elseif ($tMin <= -5) $tags[] = 'cold';
        if (($dayForecast['precipitation_mm'] ?? 0) > 5) $tags[] = 'heavy_rain';
        elseif (($dayForecast['precipitation_mm'] ?? 0) > 1) $tags[] = 'rain';
        if (($dayForecast['snow_mm'] ?? 0) > 1) $tags[] = 'snow';
        if (($dayForecast['wind_max_ms'] ?? 0) > 12) $tags[] = 'strong_wind';
        return $tags;
    }
}
