<?php
/**
 * Серверная генерация PNG-карточек для шаринга тренировки.
 *
 * Первый проход сделан на PHP GD, чтобы не зависеть от html2canvas и браузерного рендера.
 * Поддерживает шаблоны route и minimal, а для route умеет использовать реальную static map
 * либо мягко откатываться на локально нарисованный маршрут.
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/WorkoutService.php';
require_once __DIR__ . '/WorkoutShareMapService.php';
require_once __DIR__ . '/WorkoutShareCardBrowserRendererService.php';

class WorkoutShareCardService extends BaseService {
    private const TEMPLATE_ROUTE = 'route';
    private const TEMPLATE_MINIMAL = 'minimal';

    private const CARD_WIDTH = 840;
    private const ROUTE_HEIGHT = 1160;
    private const MINIMAL_HEIGHT = 980;

    private const CARD_PADDING = 56;
    private const INNER_WIDTH = 728;

    private const MAP_WIDTH = 728;
    private const MAP_HEIGHT = 472;
    private const MAP_REQUEST_WIDTH = 364;
    private const MAP_REQUEST_HEIGHT = 236;
    private const MAP_REQUEST_SCALE = 2;

    private const FONT_REGULAR = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    private const FONT_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const FONT_ITALIC = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Oblique.ttf';
    private const FONT_BOLD_ITALIC = '/usr/share/fonts/truetype/dejavu/DejaVuSans-BoldOblique.ttf';

    private const ACTIVITY_TYPE_LABELS = [
        'run' => 'Бег',
        'running' => 'Бег',
        'walking' => 'Ходьба',
        'hiking' => 'Поход',
        'cycling' => 'Велосипед',
        'swimming' => 'Плавание',
        'ofp' => 'ОФП',
        'sbu' => 'СБУ',
        'easy' => 'Легкий бег',
        'long' => 'Длительный бег',
        'long-run' => 'Длительный бег',
        'tempo' => 'Темповый бег',
        'interval' => 'Интервалы',
        'fartlek' => 'Фартлек',
        'race' => 'Соревнование',
        'control' => 'Контрольный забег',
        'other' => 'Тренировка',
        'rest' => 'Отдых',
        'free' => 'Пустой день',
    ];

    private const SOURCE_LABELS = [
        'strava' => 'Strava',
        'huawei' => 'Huawei Health',
        'polar' => 'Polar',
        'garmin' => 'Garmin',
        'coros' => 'COROS',
        'gpx' => 'GPX-файл',
        'fit' => 'FIT-файл',
    ];

    private const MAP_ATTRIBUTIONS = [
        'mapbox' => '© OpenStreetMap contributors · Mapbox',
        'maptiler' => '© OpenStreetMap contributors · MapTiler',
    ];

    private WorkoutService $workoutService;
    private WorkoutShareMapService $mapService;
    private WorkoutShareCardBrowserRendererService $browserRenderer;

    public function __construct($db) {
        parent::__construct($db);
        $this->workoutService = new WorkoutService($db);
        $this->mapService = new WorkoutShareMapService();
        $this->browserRenderer = new WorkoutShareCardBrowserRendererService();
    }

    /**
     * @return array{body: string, contentType: string, fileName: string, mapProvider: ?string}
     */
    public function render(int $workoutId, int $userId, string $template = self::TEMPLATE_ROUTE, string $workoutKind = 'workout'): array {
        $template = $this->normalizeTemplate($template);
        $workout = $this->loadWorkoutForShare($workoutId, $userId, $workoutKind);

        $timeline = [];
        $mapPayload = null;
        $mapAttribution = null;
        $mapProvider = null;

        if ($template === self::TEMPLATE_ROUTE) {
            if (!empty($workout['is_manual'])) {
                $this->throwValidationException('Для этой тренировки нет GPS-маршрута.');
            }

            $timelinePayload = $this->workoutService->getWorkoutTimeline($workoutId, $userId);
            $timeline = is_array($timelinePayload) && isset($timelinePayload['timeline']) && is_array($timelinePayload['timeline'])
                ? $timelinePayload['timeline']
                : [];

            if (!$this->hasRoutePoints($timeline)) {
                $this->throwValidationException('Для этой тренировки нет GPS-маршрута.');
            }

            $lastMapError = null;
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $map = $this->mapService->render(
                        $timeline,
                        self::MAP_REQUEST_WIDTH,
                        self::MAP_REQUEST_HEIGHT,
                        self::MAP_REQUEST_SCALE
                    );
                    if (!empty($map['body'])) {
                        $mapPayload = $map;
                        $mapProvider = $map['provider'] ?? null;
                        $mapAttribution = self::MAP_ATTRIBUTIONS[$mapProvider] ?? '© OpenStreetMap contributors';
                        break;
                    }
                    $lastMapError = new RuntimeException('Static map provider вернул пустое тело изображения.');
                } catch (Throwable $e) {
                    $lastMapError = $e;
                }

                if ($attempt < 2) {
                    usleep(120000);
                }
            }

            if ($mapPayload === null && $lastMapError) {
                $this->logInfo('Share card map fallback', [
                    'workout_id' => $workoutId,
                    'template' => $template,
                    'error' => $lastMapError->getMessage(),
                ]);
            }
        }

        $model = $this->buildModel($workout, $template, $mapAttribution);
        $fileName = $this->buildFileName($model, $template);

        if ($this->browserRenderer->isAvailable()) {
            try {
                $browserPayload = $this->buildBrowserPayload($model, $timeline, $mapPayload);
                $browserResult = $this->browserRenderer->render($browserPayload);

                return [
                    'body' => $browserResult['body'],
                    'contentType' => $browserResult['contentType'] ?? 'image/png',
                    'fileName' => $fileName,
                    'mapProvider' => $mapProvider,
                ];
            } catch (Throwable $e) {
                $this->logInfo('Browser share renderer fallback to GD', [
                    'workout_id' => $workoutId,
                    'template' => $template,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $mapImage = null;
        if ($mapPayload && !empty($mapPayload['body'])) {
            $mapResource = @imagecreatefromstring((string) $mapPayload['body']);
            if ($mapResource !== false) {
                $mapImage = $mapResource;
            }
        }

        $canvas = $template === self::TEMPLATE_ROUTE
            ? $this->renderRouteCard($model, $timeline, $mapImage)
            : $this->renderMinimalCard($model);

        ob_start();
        imagepng($canvas, null, 6);
        $body = (string) ob_get_clean();

        imagedestroy($canvas);
        if (is_resource($mapImage) || $mapImage instanceof GdImage) {
            imagedestroy($mapImage);
        }

        return [
            'body' => $body,
            'contentType' => 'image/png',
            'fileName' => $fileName,
            'mapProvider' => $mapProvider,
        ];
    }

    private function normalizeTemplate(string $template): string {
        $normalized = trim(mb_strtolower($template));
        if (in_array($normalized, [self::TEMPLATE_ROUTE, self::TEMPLATE_MINIMAL], true)) {
            return $normalized;
        }
        return self::TEMPLATE_ROUTE;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadWorkoutForShare(int $workoutId, int $userId, string $workoutKind = 'workout'): array {
        if ($workoutId <= 0 || $userId <= 0) {
            $this->throwValidationException('Неверный идентификатор тренировки.');
        }

        $normalizedKind = trim(mb_strtolower($workoutKind));
        $allowImported = $normalizedKind === '' || $normalizedKind === 'workout' || $normalizedKind === 'imported' || $normalizedKind === 'any';
        $allowManual = $normalizedKind === 'manual' || $normalizedKind === 'log' || $normalizedKind === 'workout_log' || $normalizedKind === 'any';

        if ($allowImported) {
            $imported = $this->loadImportedWorkout($workoutId, $userId);
            if ($imported) {
                return $imported;
            }
        }

        if ($allowManual) {
            $manual = $this->loadManualWorkout($workoutId, $userId);
            if ($manual) {
                return $manual;
            }
        }

        $this->throwNotFoundException('Тренировка не найдена.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadImportedWorkout(int $workoutId, int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, user_id, activity_type, source, start_time, duration_minutes, duration_seconds,
                   distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain
            FROM workouts
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $this->throwException('Не удалось подготовить запрос к workouts.');
        }

        $stmt->bind_param('ii', $workoutId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'activity_type' => strtolower(trim((string) ($row['activity_type'] ?? 'running'))),
            'source' => $row['source'] ?? null,
            'start_time' => (string) $row['start_time'],
            'duration_minutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'distance_km' => $row['distance_km'] !== null ? (float) $row['distance_km'] : null,
            'avg_pace' => $row['avg_pace'] !== null ? trim((string) $row['avg_pace']) : null,
            'avg_heart_rate' => $row['avg_heart_rate'] !== null ? (int) $row['avg_heart_rate'] : null,
            'max_heart_rate' => $row['max_heart_rate'] !== null ? (int) $row['max_heart_rate'] : null,
            'elevation_gain' => $row['elevation_gain'] !== null ? (float) $row['elevation_gain'] : null,
            'notes' => null,
            'calories' => null,
            'is_manual' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadManualWorkout(int $workoutId, int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT wl.id, wl.user_id, wl.training_date, wl.result_time, wl.duration_minutes, wl.distance_km,
                   wl.pace, wl.avg_heart_rate, wl.max_heart_rate, wl.elevation_gain, wl.notes, wl.calories,
                   LOWER(COALESCE(NULLIF(TRIM(at.name), ''), 'running')) AS activity_type_name
            FROM workout_log wl
            LEFT JOIN activity_types at ON wl.activity_type_id = at.id
            WHERE wl.id = ? AND wl.user_id = ? AND wl.is_completed = 1
            LIMIT 1
        ");
        if (!$stmt) {
            $this->throwException('Не удалось подготовить запрос к workout_log.');
        }

        $stmt->bind_param('ii', $workoutId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        $durationSeconds = $this->parseTimeToSeconds($row['result_time'] ?? null);
        if ($durationSeconds === null && $row['duration_minutes'] !== null) {
            $durationSeconds = (int) $row['duration_minutes'] * 60;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'activity_type' => strtolower(trim((string) ($row['activity_type_name'] ?? 'running'))),
            'source' => null,
            'start_time' => (string) $row['training_date'] . ' 12:00:00',
            'duration_minutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            'duration_seconds' => $durationSeconds,
            'distance_km' => $row['distance_km'] !== null ? (float) $row['distance_km'] : null,
            'avg_pace' => $row['pace'] !== null ? trim((string) $row['pace']) : null,
            'avg_heart_rate' => $row['avg_heart_rate'] !== null ? (int) $row['avg_heart_rate'] : null,
            'max_heart_rate' => $row['max_heart_rate'] !== null ? (int) $row['max_heart_rate'] : null,
            'elevation_gain' => $row['elevation_gain'] !== null ? (float) $row['elevation_gain'] : null,
            'notes' => $row['notes'] !== null ? trim((string) $row['notes']) : null,
            'calories' => $row['calories'] !== null ? (int) $row['calories'] : null,
            'is_manual' => true,
        ];
    }

    private function parseTimeToSeconds($value): ?int {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $parts = array_map('trim', explode(':', $normalized));
        if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
            return ((int) $parts[0] * 60) + (int) $parts[1];
        }

        if (count($parts) === 3 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && ctype_digit($parts[2])) {
            return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
        }

        if (ctype_digit($normalized)) {
            return (int) $normalized;
        }

        return null;
    }

    private function hasRoutePoints(array $timeline): bool {
        foreach ($timeline as $point) {
            if (isset($point['latitude'], $point['longitude']) && is_numeric($point['latitude']) && is_numeric($point['longitude'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildModel(array $workout, string $template, ?string $mapAttribution): array {
        $dateTime = new DateTime((string) $workout['start_time']);
        $activityType = strtolower(trim((string) ($workout['activity_type'] ?? 'running')));
        $typeLabel = self::ACTIVITY_TYPE_LABELS[$activityType] ?? ($workout['activity_type'] ?? 'Тренировка');
        $sourceKey = strtolower(trim((string) ($workout['source'] ?? '')));
        $sourceLabel = ($sourceKey !== '' && empty($workout['is_manual'])) ? (self::SOURCE_LABELS[$sourceKey] ?? $workout['source']) : null;
        $distance = $this->formatDistanceValue($workout['distance_km'] ?? null);
        $durationValue = $this->formatDurationValue($workout);

        return [
            'workout' => $workout,
            'template' => $template,
            'date_iso' => $dateTime->format('Y-m-d'),
            'date_label' => mb_strtoupper($this->formatRussianDate($dateTime)),
            'start_time_label' => $dateTime->format('H:i:s'),
            'type_label' => $typeLabel,
            'source_label' => $sourceLabel,
            'distance' => $distance,
            'duration_value' => $durationValue ?: '—',
            'pace_value' => !empty($workout['avg_pace']) ? trim((string) $workout['avg_pace']) : '—',
            'pulse_value' => ($workout['avg_heart_rate'] ?? null) !== null ? (string) ((int) $workout['avg_heart_rate']) : '—',
            'elevation_value' => ($workout['elevation_gain'] ?? null) !== null ? (string) round((float) $workout['elevation_gain']) : '—',
            'notes' => $this->truncateText($workout['notes'] ?? null, 140),
            'map_attribution' => $mapAttribution,
        ];
    }

    /**
     * @param array<string, mixed> $model
     * @param array<int, array<string, mixed>> $timeline
     * @param array<string, mixed>|null $mapPayload
     * @return array<string, mixed>
     */
    private function buildBrowserPayload(array $model, array $timeline, ?array $mapPayload): array {
        $routePoints = array_values(array_filter(array_map(static function ($point) {
            if (
                !isset($point['latitude'], $point['longitude']) ||
                !is_numeric($point['latitude']) ||
                !is_numeric($point['longitude'])
            ) {
                return null;
            }

            return [
                'latitude' => round((float) $point['latitude'], 6),
                'longitude' => round((float) $point['longitude'], 6),
            ];
        }, $timeline)));

        $staticMapDataUrl = null;
        if ($mapPayload && !empty($mapPayload['body'])) {
            $contentType = !empty($mapPayload['contentType']) ? (string) $mapPayload['contentType'] : 'image/png';
            $staticMapDataUrl = 'data:' . $contentType . ';base64,' . base64_encode((string) $mapPayload['body']);
        }

        return [
            'template' => $model['template'],
            'card' => [
                'dateLabel' => (string) $model['date_label'],
                'startTimeLabel' => (string) $model['start_time_label'],
                'typeLabel' => (string) $model['type_label'],
                'sourceLabel' => $model['source_label'] ? (string) $model['source_label'] : null,
                'distance' => $model['distance'],
                'durationValue' => (string) $model['duration_value'],
                'paceValue' => (string) $model['pace_value'],
                'pulseValue' => (string) $model['pulse_value'],
                'elevationValue' => (string) $model['elevation_value'],
                'notes' => $model['notes'] ? (string) $model['notes'] : null,
                'mapAttribution' => $model['map_attribution'] ? (string) $model['map_attribution'] : null,
                'staticMapDataUrl' => $staticMapDataUrl,
                'workoutId' => isset($model['workout']['id']) ? (int) $model['workout']['id'] : null,
            ],
            'timeline' => $routePoints,
        ];
    }

    private function formatDistanceValue($distanceKm): ?array {
        $value = $distanceKm !== null ? (float) $distanceKm : null;
        if ($value === null || !is_finite($value) || $value <= 0) {
            return null;
        }

        return [
            'value' => number_format($value, 2, ',', ''),
            'unit' => 'км',
        ];
    }

    /**
     * @param array<string, mixed> $workout
     */
    private function formatDurationValue(array $workout): ?string {
        if (($workout['duration_seconds'] ?? null) !== null && (int) $workout['duration_seconds'] > 0) {
            $totalSeconds = (int) $workout['duration_seconds'];
            $hours = intdiv($totalSeconds, 3600);
            $minutes = intdiv($totalSeconds % 3600, 60);
            $seconds = $totalSeconds % 60;
            return $hours > 0
                ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
                : sprintf('%d:%02d', $minutes, $seconds);
        }

        if (($workout['duration_minutes'] ?? null) !== null && (int) $workout['duration_minutes'] > 0) {
            $totalMinutes = (int) $workout['duration_minutes'];
            $hours = intdiv($totalMinutes, 60);
            $minutes = $totalMinutes % 60;
            return $hours > 0 ? sprintf('%d:%02d:00', $hours, $minutes) : sprintf('%d:00', $minutes);
        }

        return null;
    }

    private function formatRussianDate(DateTime $dateTime): string {
        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];

        $month = $months[(int) $dateTime->format('n')] ?? $dateTime->format('m');
        return $dateTime->format('j') . ' ' . $month;
    }

    private function truncateText($value, int $maxLength): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));
        if (!$normalized) {
            return null;
        }

        if (mb_strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $maxLength - 1)) . '…';
    }

    /**
     * @param array<string, mixed> $model
     */
    private function buildFileName(array $model, string $template): string {
        $activity = $model['workout']['activity_type'] ?? 'workout';
        $activity = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $activity));
        $activity = trim((string) $activity, '-');
        if ($activity === '') {
            $activity = 'workout';
        }

        $suffix = $template !== self::TEMPLATE_ROUTE ? '-' . $template : '';
        return sprintf('planrun-%s-%s%s.png', $model['date_iso'], $activity, $suffix);
    }

    /**
     * @param array<string, mixed> $model
     */
    private function renderRouteCard(array $model, array $timeline, $mapImage = null) {
        $image = $this->createCanvas(self::CARD_WIDTH, self::ROUTE_HEIGHT);

        $this->fillVerticalGradient($image, 0, 0, self::CARD_WIDTH, self::ROUTE_HEIGHT, '#FFF7F1', '#FFFFFF');
        $this->drawSoftGlow($image, 760, 72, 220, '#F97316', 0.14);
        $this->drawRoundedPanel($image, 0, 0, self::CARD_WIDTH, self::ROUTE_HEIGHT, 60, null, '#F4C7B0', 2);

        $orange = $this->color($image, '#F97316');
        $dark = $this->color($image, '#0F172A');
        $muted = $this->color($image, '#64748B');
        $labelColor = $this->color($image, '#94A3B8');

        $this->drawWordmark($image, 56, 74, 48);

        $badgesX = 56;
        $badgesY = 136;
        $badges = [
            ['text' => mb_strtoupper((string) $model['type_label']), 'bg' => '#FFE8DB', 'fg' => '#F97316', 'border' => null],
        ];
        if (!empty($model['source_label'])) {
            $badges[] = ['text' => (string) $model['source_label'], 'bg' => '#F2F0EF', 'fg' => '#475569', 'border' => null];
        }
        foreach ($badges as $badge) {
            $badgeWidth = $this->measureText((string) $badge['text'], 22, $this->font('bold'))['width'] + 34;
            $this->drawRoundedPanel($image, $badgesX, $badgesY, $badgeWidth, 40, 20, $badge['bg'], $badge['border']);
            $this->drawTextTop($image, (string) $badge['text'], 22, $badgesX + 17, $badgesY + 8, $badge['fg'], $this->font('bold'));
            $badgesX += $badgeWidth + 16;
        }

        $this->drawTextTopRight($image, (string) $model['date_label'], 24, 784, 72, $labelColor, $this->font('bold'));
        $this->drawTextTopRight($image, (string) $model['start_time_label'], 28, 784, 118, $muted, $this->font('regular'));

        $distance = $model['distance'];
        if (is_array($distance)) {
            $distanceFontSize = $this->fitTextSize((string) $distance['value'], 156, 110, 470, $this->font('bold-italic'));
            $distanceMetrics = $this->measureText((string) $distance['value'], $distanceFontSize, $this->font('bold-italic'));
            $unitSize = 48;
            $distanceTop = 226;
            $this->drawTextTop($image, (string) $distance['value'], $distanceFontSize, 56, $distanceTop, $orange, $this->font('bold-italic'));
            $unitX = 56 + $distanceMetrics['width'] + 10;
            $unitTop = $distanceTop + max(0, $distanceMetrics['height'] - 56);
            $this->drawTextTop($image, (string) $distance['unit'], $unitSize, $unitX, $unitTop, $dark, $this->font('bold'));
        } else {
            $this->drawTextTop($image, (string) $model['duration_value'], 92, 56, 244, $dark, $this->font('bold'));
        }

        $this->drawRoundedPanel($image, 544, 200, 240, 174, 48, 'rgba(255,255,255,0.94)', '#F7D6C7', 2);
        $this->drawTextTop($image, 'ВРЕМЯ', 22, 580, 236, $labelColor, $this->font('bold'));
        $this->drawTextTop($image, (string) $model['duration_value'], 58, 580, 286, $dark, $this->font('bold'));

        $mapY = 404;
        if ($mapImage !== null) {
            $roundedMap = $this->createRoundedResizedImage($mapImage, self::MAP_WIDTH, self::MAP_HEIGHT, 30);
            imagecopy($image, $roundedMap, 56, $mapY, 0, 0, self::MAP_WIDTH, self::MAP_HEIGHT);
            imagedestroy($roundedMap);
        } else {
            $fallbackMap = $this->renderFallbackRouteMap($timeline, self::MAP_WIDTH, self::MAP_HEIGHT);
            imagecopy($image, $fallbackMap, 56, $mapY, 0, 0, self::MAP_WIDTH, self::MAP_HEIGHT);
            imagedestroy($fallbackMap);
        }

        $this->drawRoundedPanel($image, 84, $mapY + 20, 168, 42, 21, 'rgba(255,255,255,0.84)', '#FFFFFF', 1);
        $this->drawTextTop($image, 'МАРШРУТ', 20, 112, $mapY + 30, $orange, $this->font('bold'));

        $this->drawRoundedPanel($image, 684, $mapY + 18, 100, 44, 22, 'rgba(71,85,105,0.82)', null);
        $this->drawTextTopRight($image, 'GPS', 22, 752, $mapY + 29, '#E2E8F0', $this->font('bold'));

        $tileY = 908;
        $tileWidth = 226;
        $tileHeight = 146;
        $tileGap = 24;
        $tiles = [
            ['label' => 'Темп', 'value' => (string) $model['pace_value'], 'unit' => 'мин/км', 'accent' => true],
            ['label' => 'Пульс', 'value' => (string) $model['pulse_value'], 'unit' => 'уд/мин', 'accent' => false],
            ['label' => 'Высота', 'value' => (string) $model['elevation_value'], 'unit' => 'м', 'accent' => false],
        ];

        foreach ($tiles as $index => $tile) {
            $x = 56 + ($index * ($tileWidth + $tileGap));
            $this->drawMetricTile(
                $image,
                $x,
                $tileY,
                $tileWidth,
                $tileHeight,
                $tile['label'],
                $tile['value'],
                $tile['unit'],
                !empty($tile['accent'])
            );
        }

        if (!empty($model['map_attribution'])) {
            imageline($image, 56, 1088, 784, 1088, $this->color($image, '#E2E8F0'));
            $this->drawTextTop($image, (string) $model['map_attribution'], 18, 56, 1112, '#A3B1C6', $this->font('regular'));
        }

        return $image;
    }

    /**
     * @param array<string, mixed> $model
     */
    private function renderMinimalCard(array $model) {
        $image = $this->createCanvas(self::CARD_WIDTH, self::MINIMAL_HEIGHT);
        $this->fillVerticalGradient($image, 0, 0, self::CARD_WIDTH, self::MINIMAL_HEIGHT, '#FFFFFF', '#FFFDFC');
        $this->drawRoundedPanel($image, 0, 0, self::CARD_WIDTH, self::MINIMAL_HEIGHT, 52, null, '#E6EDF5', 2);

        $dark = $this->color($image, '#0F172A');
        $labelColor = $this->color($image, '#94A3B8');
        $muted = $this->color($image, '#64748B');

        $this->drawWordmark($image, 56, 74, 44);
        $this->drawTextTopRight($image, (string) $model['date_label'], 28, 784, 70, $dark, $this->font('bold'));
        $this->drawTextTopRight($image, (string) $model['start_time_label'], 24, 784, 116, $muted, $this->font('regular'));

        $distance = $model['distance'];
        if (is_array($distance)) {
            $distanceFontSize = $this->fitTextSize((string) $distance['value'], 140, 100, 520, $this->font('bold'));
            $distanceMetrics = $this->measureText((string) $distance['value'], $distanceFontSize, $this->font('bold'));
            $distanceTop = 210;
            $this->drawTextTop($image, (string) $distance['value'], $distanceFontSize, 56, $distanceTop, $dark, $this->font('bold'));
            $this->drawTextTop($image, (string) $distance['unit'], 46, 56 + $distanceMetrics['width'] + 14, $distanceTop + max(0, $distanceMetrics['height'] - 50), '#F97316', $this->font('bold'));
        } else {
            $this->drawTextTop($image, (string) $model['duration_value'], 92, 56, 226, $dark, $this->font('bold'));
        }

        $subtitle = !empty($model['pace_value']) && $model['pace_value'] !== '—'
            ? 'Средний темп ' . $model['pace_value'] . ' /км'
            : (string) $model['type_label'];
        $this->drawTextTop($image, $subtitle, 30, 56, 348, '#475569', $this->font('regular'));

        $rows = [
            ['label' => 'Тип', 'value' => (string) $model['type_label']],
            ['label' => 'Источник', 'value' => (string) ($model['source_label'] ?: 'PlanRun')],
            ['label' => 'Время', 'value' => (string) $model['duration_value']],
            ['label' => 'Темп', 'value' => (string) ($model['pace_value'] !== '—' ? $model['pace_value'] . ' /км' : '—')],
            ['label' => 'Пульс', 'value' => (string) ($model['pulse_value'] !== '—' ? $model['pulse_value'] . ' уд/мин' : '—')],
            ['label' => 'Высота', 'value' => (string) ($model['elevation_value'] !== '—' ? $model['elevation_value'] . ' м' : '—')],
        ];

        $tableX = 56;
        $tableY = 404;
        $rowHeight = 82;
        imageline($image, 56, $tableY, 784, $tableY, $this->color($image, '#E2E8F0'));
        foreach ($rows as $index => $row) {
            $rowTop = $tableY + ($index * $rowHeight);
            $this->drawTextTop($image, mb_strtoupper((string) $row['label']), 20, $tableX, $rowTop + 24, $labelColor, $this->font('bold'));
            $this->drawTextTopRight($image, (string) $row['value'], 30, 784, $rowTop + 18, $dark, $this->font('bold'));
            imageline($image, 56, $rowTop + $rowHeight, 784, $rowTop + $rowHeight, $this->color($image, '#F1F5F9'));
        }

        return $image;
    }

    private function drawMetricTile($image, int $x, int $y, int $width, int $height, string $label, string $value, string $unit, bool $accent): void {
        $fill = $accent ? '#FFF9F5' : '#FFFFFF';
        $border = $accent ? '#FFD9C7' : '#E6EDF5';
        $labelColor = $accent ? '#F97316' : '#94A3B8';
        $this->drawRoundedPanel($image, $x, $y, $width, $height, 28, $fill, $border, 2);

        $this->drawTextTop($image, mb_strtoupper($label), 22, $x + 24, $y + 18, $labelColor, $this->font('bold'));

        if ($value === '—') {
            $this->drawTextTopRight($image, '—', 52, $x + $width - 24, $y + 62, '#0F172A', $this->font('bold'));
            return;
        }

        $primary = $value;
        $secondary = $unit;
        $this->drawTextTopRight($image, $primary, 52, $x + $width - 24, $y + 56, '#0F172A', $this->font('bold'));
        $this->drawTextTopRight($image, mb_strtoupper($secondary), 30, $x + $width - 24, $y + 102, '#475569', $this->font('bold'));
    }

    private function drawWordmark($image, int $x, int $y, int $size): void {
        $planFont = $this->font('italic');
        $runFont = $this->font('bold-italic');
        $plan = 'plan';
        $run = 'RUN';

        $planMetrics = $this->measureText($plan, $size, $planFont);
        $this->drawTextTop($image, $plan, $size, $x, $y, '#111827', $planFont);
        $this->drawTextTop($image, $run, $size, $x + $planMetrics['width'] - 2, $y, '#F97316', $runFont);
    }

    private function createCanvas(int $width, int $height) {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imageantialias($image, true);
        $background = imagecolorallocatealpha($image, 255, 255, 255, 0);
        imagefill($image, 0, 0, $background);
        return $image;
    }

    private function createTransparentCanvas(int $width, int $height) {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        return $image;
    }

    private function color($image, string $value, float $opacity = 1.0): int {
        if (str_starts_with($value, 'rgba')) {
            if (preg_match('/rgba\((\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+)\)/i', $value, $matches)) {
                $opacity = isset($matches[4]) ? (float) $matches[4] : $opacity;
                return imagecolorallocatealpha(
                    $image,
                    (int) $matches[1],
                    (int) $matches[2],
                    (int) $matches[3],
                    $this->gdAlpha($opacity)
                );
            }
        }

        $hex = ltrim($value, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return imagecolorallocatealpha($image, $r, $g, $b, $this->gdAlpha($opacity));
    }

    private function gdAlpha(float $opacity): int {
        $opacity = max(0.0, min(1.0, $opacity));
        return (int) round((1 - $opacity) * 127);
    }

    private function fillVerticalGradient($image, int $x, int $y, int $width, int $height, string $startHex, string $endHex): void {
        [$r1, $g1, $b1] = $this->hexToRgb($startHex);
        [$r2, $g2, $b2] = $this->hexToRgb($endHex);
        for ($i = 0; $i < $height; $i++) {
            $ratio = $height > 1 ? ($i / ($height - 1)) : 0;
            $r = (int) round($r1 + (($r2 - $r1) * $ratio));
            $g = (int) round($g1 + (($g2 - $g1) * $ratio));
            $b = (int) round($b1 + (($b2 - $b1) * $ratio));
            $color = imagecolorallocate($image, $r, $g, $b);
            imageline($image, $x, $y + $i, $x + $width, $y + $i, $color);
        }
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $value): array {
        $hex = ltrim($value, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function drawSoftGlow($image, int $centerX, int $centerY, int $radius, string $colorHex, float $maxOpacity): void {
        [$r, $g, $b] = $this->hexToRgb($colorHex);
        for ($i = 18; $i >= 1; $i--) {
            $ratio = $i / 18;
            $currentRadius = max(4, (int) round($radius * $ratio));
            $opacity = $maxOpacity * ($ratio * $ratio) * 0.72;
            $color = imagecolorallocatealpha($image, $r, $g, $b, $this->gdAlpha($opacity));
            imagefilledellipse($image, $centerX, $centerY, $currentRadius * 2, $currentRadius * 2, $color);
        }
    }

    private function drawRoundedPanel($image, int $x, int $y, int $width, int $height, int $radius, ?string $fillHex, ?string $borderHex, int $borderWidth = 1): void {
        if ($borderHex) {
            $borderColor = $this->color($image, $borderHex);
            $this->fillRoundedRect($image, $x, $y, $width, $height, $radius, $borderColor);
            if ($fillHex) {
                $fillColor = $this->color($image, $fillHex);
                $this->fillRoundedRect(
                    $image,
                    $x + $borderWidth,
                    $y + $borderWidth,
                    $width - ($borderWidth * 2),
                    $height - ($borderWidth * 2),
                    max(0, $radius - $borderWidth),
                    $fillColor
                );
            }
            return;
        }

        if ($fillHex) {
            $this->fillRoundedRect($image, $x, $y, $width, $height, $radius, $this->color($image, $fillHex));
        }
    }

    private function fillRoundedRect($image, int $x, int $y, int $width, int $height, int $radius, int $color): void {
        $radius = max(0, min($radius, (int) floor(min($width, $height) / 2)));
        if ($radius === 0) {
            imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, $color);
            return;
        }

        imagefilledrectangle($image, $x + $radius, $y, $x + $width - $radius, $y + $height, $color);
        imagefilledrectangle($image, $x, $y + $radius, $x + $width, $y + $height - $radius, $color);
        imagefilledellipse($image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $width - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $width - $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color);
    }

    /**
     * @return array{width:int,height:int,left:int,top:int}
     */
    private function measureText(string $text, int $size, string $font): array {
        $bbox = imagettfbbox($size, 0, $font, $text);
        if ($bbox === false) {
            return ['width' => 0, 'height' => 0, 'left' => 0, 'top' => 0];
        }

        $left = min($bbox[0], $bbox[6]);
        $right = max($bbox[2], $bbox[4]);
        $top = min($bbox[5], $bbox[7]);
        $bottom = max($bbox[1], $bbox[3]);

        return [
            'width' => (int) round($right - $left),
            'height' => (int) round($bottom - $top),
            'left' => (int) round($left),
            'top' => (int) round($top),
        ];
    }

    private function drawTextTop($image, string $text, int $size, int $x, int $yTop, string $colorHex, string $font): void {
        $metrics = $this->measureText($text, $size, $font);
        $baselineY = $yTop - $metrics['top'];
        $baselineX = $x - $metrics['left'];
        imagettftext($image, $size, 0, $baselineX, $baselineY, $this->color($image, $colorHex), $font, $text);
    }

    private function drawTextTopRight($image, string $text, int $size, int $right, int $yTop, string $colorHex, string $font): void {
        $metrics = $this->measureText($text, $size, $font);
        $x = $right - $metrics['width'];
        $this->drawTextTop($image, $text, $size, $x, $yTop, $colorHex, $font);
    }

    private function fitTextSize(string $text, int $startSize, int $minSize, int $maxWidth, string $font): int {
        for ($size = $startSize; $size >= $minSize; $size--) {
            if ($this->measureText($text, $size, $font)['width'] <= $maxWidth) {
                return $size;
            }
        }
        return $minSize;
    }

    private function font(string $variant): string {
        $fonts = [
            'regular' => self::FONT_REGULAR,
            'bold' => self::FONT_BOLD,
            'italic' => self::FONT_ITALIC,
            'bold-italic' => self::FONT_BOLD_ITALIC,
        ];

        $candidate = $fonts[$variant] ?? self::FONT_REGULAR;
        if (is_file($candidate)) {
            return $candidate;
        }

        if ($variant === 'bold-italic' && is_file(self::FONT_BOLD)) {
            return self::FONT_BOLD;
        }

        if ($variant === 'italic' && is_file(self::FONT_REGULAR)) {
            return self::FONT_REGULAR;
        }

        return self::FONT_REGULAR;
    }

    /**
     * @return string[]
     */
    private function wrapText(string $text, int $size, string $font, int $maxWidth): array {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->measureText($candidate, $size, $font)['width'] <= $maxWidth) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [$text];
    }

    private function createRoundedResizedImage($source, int $width, int $height, int $radius) {
        $resized = $this->createTransparentCanvas($width, $height);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        $radius = max(2, min($radius, (int) floor(min($width, $height) / 2)));

        for ($y = 0; $y < $radius; $y++) {
            for ($x = 0; $x < $radius; $x++) {
                $distance = sqrt((($radius - $x) ** 2) + (($radius - $y) ** 2));
                if ($distance > $radius) {
                    imagesetpixel($resized, $x, $y, $transparent);
                    imagesetpixel($resized, $width - $x - 1, $y, $transparent);
                    imagesetpixel($resized, $x, $height - $y - 1, $transparent);
                    imagesetpixel($resized, $width - $x - 1, $height - $y - 1, $transparent);
                }
            }
        }

        return $resized;
    }

    private function renderFallbackRouteMap(array $timeline, int $width, int $height) {
        $image = $this->createCanvas($width, $height);
        $this->fillVerticalGradient($image, 0, 0, $width, $height, '#171C25', '#1B2330');
        $this->drawSoftGlow($image, $width - 80, 28, 140, '#F97316', 0.16);

        $grid = $this->color($image, '#243041');
        imageline($image, 0, $height - 1, $width, $height - 1, $this->color($image, '#1F2937'));
        foreach ([0.25, 0.5, 0.75] as $ratio) {
            $y = (int) round($height * $ratio);
            imageline($image, 22, $y, $width - 22, $y, $grid);
        }
        foreach ([0.28, 0.56, 0.84] as $ratio) {
            $x = (int) round($width * $ratio);
            imageline($image, $x, 22, $x, $height - 22, $this->color($image, '#202B39'));
        }

        $points = [];
        foreach ($timeline as $point) {
            $latitude = isset($point['latitude']) ? (float) $point['latitude'] : null;
            $longitude = isset($point['longitude']) ? (float) $point['longitude'] : null;
            if (!is_finite($latitude) || !is_finite($longitude)) {
                continue;
            }
            $points[] = ['lat' => $latitude, 'lng' => $longitude];
        }

        if (count($points) < 2) {
            return $this->createRoundedResizedImage($image, $width, $height, 30);
        }

        $sampleStep = max(1, (int) floor(count($points) / 180));
        $sampled = [];
        foreach ($points as $index => $point) {
            if ($index % $sampleStep === 0 || $index === count($points) - 1) {
                $sampled[] = $point;
            }
        }

        $lats = array_column($sampled, 'lat');
        $lngs = array_column($sampled, 'lng');
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        $padding = 34;
        $routeWidth = $width - ($padding * 2);
        $routeHeight = $height - ($padding * 2);
        $latRange = max(0.0001, $maxLat - $minLat);
        $lngRange = max(0.0001, $maxLng - $minLng);

        $projected = [];
        foreach ($sampled as $point) {
            $projected[] = [
                'x' => $padding + (($point['lng'] - $minLng) / $lngRange) * $routeWidth,
                'y' => $padding + $routeHeight - (($point['lat'] - $minLat) / $latRange) * $routeHeight,
            ];
        }

        $shadowColor = $this->color($image, '#F97316', 0.34);
        $shineColor = $this->color($image, '#FFFFFF', 0.24);
        $lineColor = $this->color($image, '#F97316');

        imagesetthickness($image, 12);
        for ($i = 1, $total = count($projected); $i < $total; $i++) {
            imageline(
                $image,
                (int) round($projected[$i - 1]['x']),
                (int) round($projected[$i - 1]['y']),
                (int) round($projected[$i]['x']),
                (int) round($projected[$i]['y']),
                $shadowColor
            );
        }

        imagesetthickness($image, 7);
        for ($i = 1, $total = count($projected); $i < $total; $i++) {
            imageline(
                $image,
                (int) round($projected[$i - 1]['x']),
                (int) round($projected[$i - 1]['y']),
                (int) round($projected[$i]['x']),
                (int) round($projected[$i]['y']),
                $shineColor
            );
        }

        imagesetthickness($image, 5);
        for ($i = 1, $total = count($projected); $i < $total; $i++) {
            imageline(
                $image,
                (int) round($projected[$i - 1]['x']),
                (int) round($projected[$i - 1]['y']),
                (int) round($projected[$i]['x']),
                (int) round($projected[$i]['y']),
                $lineColor
            );
        }
        imagesetthickness($image, 1);

        $start = $projected[0];
        $end = $projected[count($projected) - 1];
        imagefilledellipse($image, (int) round($start['x']), (int) round($start['y']), 26, 26, $this->color($image, '#FFFFFF'));
        imagefilledellipse($image, (int) round($start['x']), (int) round($start['y']), 16, 16, $lineColor);
        imagefilledellipse($image, (int) round($end['x']), (int) round($end['y']), 24, 24, $lineColor);
        imageellipse($image, (int) round($end['x']), (int) round($end['y']), 24, 24, $this->color($image, '#111827'));

        $rounded = $this->createRoundedResizedImage($image, $width, $height, 30);
        imagedestroy($image);
        return $rounded;
    }
}
