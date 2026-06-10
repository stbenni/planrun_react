<?php

class WorkoutClassifier {

    private const NON_RUN = ['walking', 'hiking', 'cycling', 'biking', 'bike', 'swimming', 'swim', 'other', 'ofp', 'sbu', 'strength', 'cross'];

    public static function classify(array $ctx): ?string {
        $activity = strtolower(trim((string)($ctx['activity_type'] ?? 'running')));
        if (in_array($activity, self::NON_RUN, true)) {
            return null;
        }

        $distanceKm = (float)($ctx['distance_km'] ?? 0);
        $durationSec = (int)($ctx['duration_seconds'] ?? 0);
        if ($durationSec <= 0 && !empty($ctx['duration_minutes'])) {
            $durationSec = (int)$ctx['duration_minutes'] * 60;
        }
        $avgHr = (int)($ctx['avg_heart_rate'] ?? 0);
        $maxHr = (int)($ctx['max_hr'] ?? 0);
        $paces = $ctx['paces'] ?? null;
        $laps = $ctx['laps'] ?? null;

        if (is_array($laps) && count($laps) >= 4) {
            $iv = self::detectIntervals($laps);
            if ($iv['isInterval']) {
                return self::intervalOrFartlek($iv['work']);
            }
        }

        $paceSec = ($distanceKm > 0 && $durationSec > 0) ? $durationSec / $distanceKm : 0;
        $isLong = ($distanceKm >= 18 || $durationSec >= 95 * 60);

        if (is_array($paces) && $paceSec > 0) {
            $marathon = (float)($paces['marathon'] ?? 0);
            $easyHi = isset($paces['easy'][1]) ? (float)$paces['easy'][1] : 0;
            if (!$isLong && $marathon > 0 && $paceSec <= $marathon * 1.05) {
                return 'tempo';
            }
            if ($isLong) {
                return 'long';
            }
            if ($easyHi > 0 && $paceSec <= $easyHi * 1.25) {
                return 'easy';
            }
            return 'recovery';
        }

        if ($isLong) {
            return 'long';
        }

        if ($maxHr > 0 && $avgHr > 0) {
            $r = $avgHr / $maxHr;
            if ($r >= 0.85) return 'tempo';
            if ($r >= 0.68) return 'easy';
            return 'recovery';
        }

        return 'easy';
    }

    private static function detectIntervals(array $laps): array {
        $cands = [];
        foreach ($laps as $lap) {
            if (!is_array($lap)) continue;
            $dist = isset($lap['distance_km']) ? (float)$lap['distance_km'] : null;
            $sec = isset($lap['moving_seconds']) ? (float)$lap['moving_seconds']
                : (isset($lap['elapsed_seconds']) ? (float)$lap['elapsed_seconds']
                : (isset($lap['duration_seconds']) ? (float)$lap['duration_seconds'] : null));
            if ($dist === null || $dist < 0.15 || $dist > 2.5) continue;
            if ($sec === null || $sec < 30 || $sec > 1200) continue;
            $pace = $sec / $dist;
            if ($pace <= 0) continue;
            $cands[] = ['dist' => $dist, 'pace' => $pace];
        }
        if (count($cands) < 4) {
            return ['isInterval' => false, 'work' => []];
        }
        $sortedPaces = array_map(static fn($c) => $c['pace'], $cands);
        sort($sortedPaces);
        $mid = intdiv(count($sortedPaces), 2);
        $median = count($sortedPaces) % 2
            ? $sortedPaces[$mid]
            : ($sortedPaces[$mid - 1] + $sortedPaces[$mid]) / 2;
        $work = [];
        $pairs = 0;
        for ($i = 0; $i < count($cands) - 1; $i++) {
            $cur = $cands[$i];
            $nxt = $cands[$i + 1];
            $relGap = $nxt['pace'] / $cur['pace'];
            $absGap = $nxt['pace'] - $cur['pace'];
            $recovOk = $nxt['dist'] >= 0.1 && $nxt['dist'] <= max($cur['dist'] * 1.8, 0.6);
            $alternates = $cur['pace'] <= $median && $nxt['pace'] >= $median;
            if ($recovOk && $alternates && $relGap >= 1.22 && $absGap >= 45) {
                $work[] = $cur;
                $pairs++;
                $i++;
            }
        }
        if ($pairs < 3) {
            return ['isInterval' => false, 'work' => []];
        }
        return ['isInterval' => true, 'work' => $work];
    }

    private static function intervalOrFartlek(array $work): string {
        $dists = array_map(static fn($w) => $w['dist'], $work);
        $n = count($dists);
        if ($n < 2) return 'interval';
        $mean = array_sum($dists) / $n;
        if ($mean <= 0) return 'interval';
        $var = 0.0;
        foreach ($dists as $d) {
            $var += ($d - $mean) ** 2;
        }
        $cv = sqrt($var / $n) / $mean;
        return $cv < 0.25 ? 'interval' : 'fartlek';
    }

    public static function maxHrFromBirthYear(?int $birthYear): int {
        if (!$birthYear || $birthYear < 1900) return 0;
        $age = (int)date('Y') - $birthYear;
        if ($age < 8 || $age > 100) return 0;
        return 220 - $age;
    }
}
