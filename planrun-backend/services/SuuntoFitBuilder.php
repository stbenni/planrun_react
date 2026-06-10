<?php
/**
 * SuuntoFitBuilder — собирает FIT-файл из тренировки PlanRun (summary + workout_timeline)
 * для заливки в Suunto. Делегирует кодирование Node-хелперу generate_fit.mjs (Garmin FIT SDK).
 */
class SuuntoFitBuilder {
    private $db;
    public function __construct($db) { $this->db = $db; }

    private function env(string $k, string $d): string {
        $v = function_exists('env') ? env($k, $d) : $d;
        return $v === null ? $d : (string)$v;
    }

    /**
     * @return string|null путь к временному .fit (caller удаляет), либо null при ошибке
     */
    public function buildFitFile(int $userId, int $workoutId): ?string {
        $stmt = $this->db->prepare(
            "SELECT activity_type, start_time, duration_seconds, duration_minutes, distance_km,
                    avg_heart_rate, max_heart_rate, elevation_gain
             FROM workouts WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $workoutId, $userId);
        $stmt->execute();
        $w = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$w || empty($w['start_time'])) {
            return null;
        }

        $startMs = strtotime($w['start_time'] . ' UTC') * 1000;
        $dur = (int)($w['duration_seconds'] ?: ((int)$w['duration_minutes'] * 60));
        if ($dur <= 0) {
            return null;
        }
        $sportMap = ['running' => 'running', 'walking' => 'walking', 'hiking' => 'hiking', 'cycling' => 'cycling', 'swimming' => 'swimming'];
        $payload = [
            'sport' => $sportMap[$w['activity_type']] ?? 'running',
            'subSport' => 'generic',
            'startTimeMs' => $startMs,
            'totalElapsedSec' => $dur,
            'totalDistanceM' => round((float)$w['distance_km'] * 1000, 1),
            'avgHr' => $w['avg_heart_rate'] ? (int)$w['avg_heart_rate'] : null,
            'maxHr' => $w['max_heart_rate'] ? (int)$w['max_heart_rate'] : null,
            'totalCalories' => null,
            'totalAscent' => $w['elevation_gain'] ? (int)$w['elevation_gain'] : null,
            'points' => [],
        ];

        $stmt = $this->db->prepare(
            "SELECT timestamp, latitude, longitude, distance, heart_rate, altitude, cadence, pace
             FROM workout_timeline WHERE workout_id = ? ORDER BY id ASC"
        );
        $stmt->bind_param('i', $workoutId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($p = $res->fetch_assoc()) {
            $tMs = strtotime($p['timestamp'] . ' UTC') * 1000;
            $speedMs = null;
            if (!empty($p['pace']) && strpos($p['pace'], ':') !== false) {
                [$m, $s] = explode(':', $p['pace']);
                $sec = (int)$m * 60 + (int)$s;
                if ($sec > 0) $speedMs = round(1000 / $sec, 3);
            }
            $payload['points'][] = [
                'tMs' => $tMs,
                'lat' => $p['latitude'] !== null ? (float)$p['latitude'] : null,
                'lng' => $p['longitude'] !== null ? (float)$p['longitude'] : null,
                'distM' => $p['distance'] !== null ? round((float)$p['distance'] * 1000, 1) : null,
                'hr' => $p['heart_rate'] !== null ? (int)$p['heart_rate'] : null,
                'altM' => $p['altitude'] !== null ? (float)$p['altitude'] : null,
                'speedMs' => $speedMs,
                'cad' => $p['cadence'] !== null ? (int)$p['cadence'] : null,
            ];
        }
        $stmt->close();

        if (count($payload['points']) < 2) {
            return null; // без трека FIT смысла не имеет
        }

        $projectRoot = dirname(dirname(__DIR__));
        $tmpJson = tempnam(sys_get_temp_dir(), 'suunto_fit_') . '.json';
        $tmpFit = tempnam(sys_get_temp_dir(), 'suunto_fit_') . '.fit';
        if (@file_put_contents($tmpJson, json_encode($payload)) === false) {
            return null;
        }
        $node = $this->env('SUUNTO_NODE_BIN', 'node');
        $script = $projectRoot . '/planrun-backend/scripts/generate_fit.mjs';
        $cmd = sprintf(
            'cd %s && %s %s --input %s --output %s 2>&1',
            escapeshellarg($projectRoot),
            escapeshellcmd($node),
            escapeshellarg($script),
            escapeshellarg($tmpJson),
            escapeshellarg($tmpFit)
        );
        $out = shell_exec($cmd);
        @unlink($tmpJson);

        if (!is_file($tmpFit) || filesize($tmpFit) < 12) {
            require_once __DIR__ . '/../config/Logger.php';
            Logger::warning('Suunto FIT build failed', ['workout_id' => $workoutId, 'node_out' => substr((string)$out, 0, 300)]);
            @unlink($tmpFit);
            return null;
        }
        return $tmpFit;
    }
}
