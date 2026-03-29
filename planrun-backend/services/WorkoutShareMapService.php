<?php
/**
 * Сервис генерации статической карты для карточек шаринга тренировки.
 *
 * Сначала пытается использовать Mapbox Static Images API, затем MapTiler Static Maps API.
 * Если провайдер не настроен, вызывающий код должен использовать локальный SVG fallback.
 */

require_once __DIR__ . '/../config/env_loader.php';

class WorkoutShareMapService {
    private const MAX_ROUTE_POINTS = 140;
    private const DEFAULT_ROUTE_COLOR = 'ea580c';
    private const DEFAULT_MAPBOX_STYLE = 'mapbox/light-v11';
    private const DEFAULT_MAPTILER_STYLE = 'streets-v2';

    /**
     * @param array<int, array<string, mixed>> $timeline
     * @return array{body: string, contentType: string, provider: string}
     */
    public function render(array $timeline, int $width, int $height, int $scale = 2): array {
        $points = $this->extractRoutePoints($timeline);
        if (count($points) < 2) {
            throw new RuntimeException('GPS-маршрут недоступен для этой тренировки.');
        }

        $width = max(240, min(1280, $width));
        $height = max(160, min(1280, $height));
        $scale = max(1, min(2, $scale));

        if ($this->hasMapboxConfig()) {
            return $this->fetchMapboxMap($points, $width, $height, $scale);
        }

        if ($this->hasMapTilerConfig()) {
            return $this->fetchMapTilerMap($points, $width, $height, $scale);
        }

        throw new RuntimeException('Не настроен провайдер статических карт для шаринга.');
    }

    private function hasMapboxConfig(): bool {
        return trim((string) env('MAPBOX_ACCESS_TOKEN', '')) !== '';
    }

    private function hasMapTilerConfig(): bool {
        return trim((string) env('MAPTILER_API_KEY', '')) !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $timeline
     * @return array<int, array{latitude: float, longitude: float}>
     */
    private function extractRoutePoints(array $timeline): array {
        $points = [];

        foreach ($timeline as $point) {
            $latitude = isset($point['latitude']) ? (float) $point['latitude'] : null;
            $longitude = isset($point['longitude']) ? (float) $point['longitude'] : null;

            if (!is_finite($latitude) || !is_finite($longitude)) {
                continue;
            }

            $points[] = [
                'latitude' => round($latitude, 6),
                'longitude' => round($longitude, 6),
            ];
        }

        if (count($points) <= self::MAX_ROUTE_POINTS) {
            return $points;
        }

        $lastIndex = count($points) - 1;
        $sampled = [];
        $seen = [];

        for ($i = 0; $i < self::MAX_ROUTE_POINTS; $i++) {
            $index = (int) round(($i / max(1, self::MAX_ROUTE_POINTS - 1)) * $lastIndex);
            if (isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;
            $sampled[] = $points[$index];
        }

        if (end($sampled) !== $points[$lastIndex]) {
            $sampled[] = $points[$lastIndex];
        }

        return $sampled;
    }

    /**
     * @param array<int, array{latitude: float, longitude: float}> $points
     * @return array{body: string, contentType: string, provider: string}
     */
    private function fetchMapboxMap(array $points, int $width, int $height, int $scale): array {
        $token = trim((string) env('MAPBOX_ACCESS_TOKEN', ''));
        if ($token === '') {
            throw new RuntimeException('MAPBOX_ACCESS_TOKEN не настроен.');
        }

        $style = trim((string) env('MAPBOX_STATIC_STYLE', self::DEFAULT_MAPBOX_STYLE));
        [$username, $styleId] = array_pad(explode('/', $style, 2), 2, null);
        if (!$styleId) {
            $username = 'mapbox';
            $styleId = $style;
        }

        $polyline = rawurlencode($this->encodePolyline($points));
        $overlay = sprintf('path-5+%s-0.92(%s)', self::DEFAULT_ROUTE_COLOR, $polyline);
        $retinaSuffix = $scale > 1 ? '@2x' : '';

        $query = http_build_query([
            'access_token' => $token,
            'logo' => 'false',
            'attribution' => 'false',
            'padding' => '18',
        ], '', '&', PHP_QUERY_RFC3986);

        $url = sprintf(
            'https://api.mapbox.com/styles/v1/%s/%s/static/%s/auto/%dx%d%s?%s',
            rawurlencode((string) $username),
            rawurlencode((string) $styleId),
            $overlay,
            $width,
            $height,
            $retinaSuffix,
            $query
        );

        return $this->fetchRemoteImage($url, 'mapbox');
    }

    /**
     * @param array<int, array{latitude: float, longitude: float}> $points
     * @return array{body: string, contentType: string, provider: string}
     */
    private function fetchMapTilerMap(array $points, int $width, int $height, int $scale): array {
        $apiKey = trim((string) env('MAPTILER_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('MAPTILER_API_KEY не настроен.');
        }

        $style = trim((string) env('MAPTILER_STATIC_STYLE', self::DEFAULT_MAPTILER_STYLE));
        $coords = implode('|', array_map(
            fn($point) => $this->formatCoordinate($point['latitude']) . ',' . $this->formatCoordinate($point['longitude']),
            $points
        ));

        $retinaSuffix = $scale > 1 ? '@2x' : '';
        $query = http_build_query([
            'path' => sprintf('stroke:%s|width:5|fill:none|%s', self::DEFAULT_ROUTE_COLOR, $coords),
            'padding' => '0.16',
            'latlng' => 'true',
            'attribution' => 'false',
            'key' => $apiKey,
        ], '', '&', PHP_QUERY_RFC3986);

        $url = sprintf(
            'https://api.maptiler.com/maps/%s/static/auto/%dx%d%s.png?%s',
            rawurlencode($style),
            $width,
            $height,
            $retinaSuffix,
            $query
        );

        return $this->fetchRemoteImage($url, 'maptiler');
    }

    /**
     * @return array{body: string, contentType: string, provider: string}
     */
    private function fetchRemoteImage(string $url, string $provider): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => [
                'Accept: image/png,image/*;q=0.9,*/*;q=0.8',
            ],
            CURLOPT_USERAGENT => 'PlanRunShareMap/1.0 (+https://planrun.ru)',
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError) {
            throw new RuntimeException('Не удалось загрузить карту у внешнего провайдера.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $details = trim(substr(strip_tags((string) $body), 0, 160));
            if ($details === '') {
                $details = 'Нет подробностей';
            }
            throw new RuntimeException(sprintf('Провайдер карты вернул %d: %s', $httpCode, $details));
        }

        if ($contentType === '') {
            $contentType = 'image/png';
        }

        return [
            'body' => (string) $body,
            'contentType' => $contentType,
            'provider' => $provider,
        ];
    }

    /**
     * @param array<int, array{latitude: float, longitude: float}> $points
     */
    private function encodePolyline(array $points): string {
        $result = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($points as $point) {
            $lat = (int) round($point['latitude'] * 100000);
            $lng = (int) round($point['longitude'] * 100000);

            $result .= $this->encodeSignedNumber($lat - $prevLat);
            $result .= $this->encodeSignedNumber($lng - $prevLng);

            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $result;
    }

    private function encodeSignedNumber(int $value): string {
        $shifted = $value < 0 ? ~($value << 1) : ($value << 1);
        $output = '';

        while ($shifted >= 0x20) {
            $output .= chr((0x20 | ($shifted & 0x1f)) + 63);
            $shifted >>= 5;
        }

        $output .= chr($shifted + 63);
        return $output;
    }

    private function formatCoordinate(float $value): string {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
