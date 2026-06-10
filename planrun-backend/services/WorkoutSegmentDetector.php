<?php
/**
 * WorkoutSegmentDetector — авто-детект структуры тренировки (интервалы / фартлек / темпо)
 * по СЫРОМУ стриму (таймлайн посекундно), независимо от записанных кругов.
 *
 * Подход (à la TrainingPeaks Interval Detection / Athletica Interval IQ):
 *  1. строим сглаженный темп из дистанции/времени (убираем GPS-шум);
 *  2. 2-means по темпу -> кластеры "работа"/"восстановление" (порог сам подстраивается);
 *  3. если кластеры близко (ровный бег) -> структуры нет, возвращаем null;
 *  4. помечаем точки work/rest, чистим короткие сегменты, группируем в репиты;
 *  5. классифицируем (interval/fartlek/tempo) + строим нарратив и pattern.
 *
 * Вход — таймлайн в формате FitParser/saveWorkoutTimeline (timestamp, distance[км кумул.], heart_rate).
 * Выход — массив структуры либо null, если чёткой структуры не выделено (ровный бег).
 */
class WorkoutSegmentDetector {
    private int $smoothWindowSec = 12;    // полуокно сглаживания скорости, сек
    private int $paceSmoothPts = 5;       // доп. сглаживание темпа скользящим средним (точек)
    private float $minSeparation = 0.20;  // мин. относит. контраст темпа work/recovery (дрейф на длительной ~10-15%, интервалы 30%+)
    private float $minWorkKm = 0.2;       // мин. дистанция рабочего отрезка (≈200 м)
    private int $minRecoverySec = 15;     // мин. длительность восстановления

    /**
     * @param array<int,array<string,mixed>> $timeline
     * @return array<string,mixed>|null
     */
    public function detect(array $timeline, ?int $maxHr = null): ?array {
        $pts = $this->cleanPoints($timeline);
        if (count($pts) < 30) return null;

        $paces = $this->smoothedPaceSeries($pts);
        $paces = $this->smoothSeries($paces, $this->paceSmoothPts); // гасим квантизационный шум (дистанция округлена до 10 м)
        $valid = array_values(array_filter($paces, fn($p) => $p !== null));
        if (count($valid) < 30) return null;

        [$thresh, $fastMean, $slowMean] = $this->twoMeansThreshold($valid);
        if ($thresh === null || $slowMean <= 0) return null;
        if (($slowMean - $fastMean) / $slowMean < $this->minSeparation) {
            return null; // кластеры близко — ровный бег, интервалов нет
        }

        // Помечаем точки: 1 = работа (быстрее порога), 0 = восстановление
        $marks = [];
        foreach ($pts as $i => $p) {
            $pace = $paces[$i];
            $marks[] = ($pace === null) ? null : (($pace < $thresh) ? 1 : 0);
        }
        $marks = $this->fillGaps($marks);
        $marks = $this->smoothSegments($marks, $pts);

        // Почти нет "работы" → шум, не структура (верхнюю границу не ставим: темпо = много работы)
        $workFrac = array_sum($marks) / max(1, count($marks));
        if ($workFrac < 0.05) return null;

        $segs = $this->buildSegments($pts, $marks);
        $reps = [];
        $recov = [];
        foreach ($segs as $s) {
            $sum = $this->summariseSegment($pts, $s['i0'], $s['i1'], $maxHr);
            if ($sum === null) continue;
            if ($s['work']) $reps[] = $sum; else $recov[] = $sum;
        }
        // Артефакт-фильтр: рабочий отрезок реально быстрее восстановления и не гигантский блок (>3.5 км = не интервал)
        $reps = array_values(array_filter($reps, fn($r) => $r['pace_sec'] < $slowMean * 0.97 && $r['distance_m'] <= 3500));
        // Чёткая повторяющаяся структура: ≥3 рабочих отрезка ограниченной длины + ≥2 трусцы.
        // Иначе (1–2 длинных быстрых блока / дрейф темпа на длительной) — структуры нет.
        if (count($reps) < 3 || count($recov) < 2) return null;
        $repDists = array_column($reps, 'distance_m');
        sort($repDists);
        $medRep = $repDists[intdiv(count($repDists), 2)];
        if ($medRep > 3000 || $medRep < 120) return null;

        return $this->classify($reps, $recov, $maxHr);
    }

    /** @return array<int,array{t:int,d:float,hr:?int}> */
    private function cleanPoints(array $timeline): array {
        $pts = [];
        $lastD = null;
        foreach ($timeline as $p) {
            $d = $p['distance'] ?? null;
            $t = isset($p['timestamp']) ? strtotime((string)$p['timestamp']) : null;
            if ($d === null || $t === null || $t === false) continue;
            $d = (float)$d;
            // дистанция кумулятивная и неубывающая
            if ($lastD !== null && $d < $lastD) $d = $lastD;
            $lastD = $d;
            // Темп из таймлайна (FitParser считает его из точной скорости FIT) — чистый сигнал
            $paceSec = null;
            if (!empty($p['pace']) && is_string($p['pace']) && strpos($p['pace'], ':') !== false) {
                [$mm, $ss] = explode(':', $p['pace']);
                $cand = (int)$mm * 60 + (int)$ss;
                if ($cand >= 120 && $cand <= 1500) $paceSec = $cand;
            }
            $pts[] = [
                't' => (int)$t,
                'd' => $d,
                'pace' => $paceSec,
                'hr' => isset($p['heart_rate']) && $p['heart_rate'] !== null ? (int)$p['heart_rate'] : null,
            ];
        }
        return $pts;
    }

    /**
     * Темп (sec/km) на каждую точку. Приоритет — готовый темп из скорости FIT (чистый);
     * фолбэк — из кумулятивной дистанции центрированным окном (если темпа в точке нет).
     */
    private function smoothedPaceSeries(array $pts): array {
        $n = count($pts);
        $w = $this->smoothWindowSec;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($pts[$i]['pace'] !== null) { $out[] = (float)$pts[$i]['pace']; continue; }
            $t = $pts[$i]['t'];
            $lo = $i; while ($lo > 0 && $t - $pts[$lo - 1]['t'] <= $w) $lo--;
            $hi = $i; while ($hi < $n - 1 && $pts[$hi + 1]['t'] - $t <= $w) $hi++;
            $dt = $pts[$hi]['t'] - $pts[$lo]['t'];
            $dd = ($pts[$hi]['d'] - $pts[$lo]['d']) * 1000.0;
            if ($dt <= 0 || $dd <= 0) { $out[] = null; continue; }
            $pace = 1000.0 / ($dd / $dt);
            $out[] = ($pace >= 120 && $pace <= 1500) ? $pace : null;
        }
        return $out;
    }

    /** 1D 2-means по темпу. @return array{0:?float,1:float,2:float} [threshold, fastMean, slowMean] */
    private function twoMeansThreshold(array $vals): array {
        sort($vals);
        $n = count($vals);
        if ($n < 4) return [null, 0.0, 0.0];
        $c1 = $vals[(int)floor($n * 0.25)];
        $c2 = $vals[(int)floor($n * 0.75)];
        for ($iter = 0; $iter < 25; $iter++) {
            $s1 = 0; $n1 = 0; $s2 = 0; $n2 = 0;
            foreach ($vals as $v) {
                if (abs($v - $c1) <= abs($v - $c2)) { $s1 += $v; $n1++; }
                else { $s2 += $v; $n2++; }
            }
            $nc1 = $n1 ? $s1 / $n1 : $c1;
            $nc2 = $n2 ? $s2 / $n2 : $c2;
            if (abs($nc1 - $c1) < 0.5 && abs($nc2 - $c2) < 0.5) { $c1 = $nc1; $c2 = $nc2; break; }
            $c1 = $nc1; $c2 = $nc2;
        }
        $fast = min($c1, $c2);
        $slow = max($c1, $c2);
        return [($fast + $slow) / 2.0, $fast, $slow];
    }

    /** Заполняет null'ы соседями и медианно сглаживает одиночные выбросы. */
    private function fillGaps(array $marks): array {
        $n = count($marks);
        $last = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($marks[$i] === null) $marks[$i] = $last; else $last = $marks[$i];
        }
        $sm = $marks;
        for ($i = 1; $i < $n - 1; $i++) {
            $sm[$i] = (($marks[$i - 1] + $marks[$i] + $marks[$i + 1]) >= 2) ? 1 : 0;
        }
        return $sm;
    }

    /** Итеративно убирает сегменты короче минимума, переворачивая их в соседний тип. */
    private function smoothSegments(array $marks, array $pts): array {
        for ($pass = 0; $pass < 12; $pass++) {
            $segs = $this->buildSegments($pts, $marks);
            if (count($segs) <= 1) break;
            $worst = null; $worstDur = PHP_INT_MAX;
            foreach ($segs as $s) {
                $dur = $pts[$s['i1']]['t'] - $pts[$s['i0']]['t'];
                $dist = ($pts[$s['i1']]['d'] - $pts[$s['i0']]['d']) * 1000;
                $tooShort = $s['work']
                    ? ($dist < $this->minWorkKm * 1000)
                    : ($dur < $this->minRecoverySec);
                if ($tooShort && $dur < $worstDur) { $worst = $s; $worstDur = $dur; }
            }
            if ($worst === null) break;
            $flip = $worst['work'] ? 0 : 1;
            for ($i = $worst['i0']; $i <= $worst['i1']; $i++) $marks[$i] = $flip;
        }
        return $marks;
    }

    /** @return array<int,array{work:bool,i0:int,i1:int}> */
    private function buildSegments(array $pts, array $marks): array {
        $segs = [];
        $n = count($marks);
        if ($n === 0) return $segs;
        $start = 0; $cur = (int)$marks[0];
        for ($i = 1; $i < $n; $i++) {
            $m = (int)$marks[$i];
            if ($m !== $cur) { $segs[] = ['work' => (bool)$cur, 'i0' => $start, 'i1' => $i - 1]; $start = $i; $cur = $m; }
        }
        $segs[] = ['work' => (bool)$cur, 'i0' => $start, 'i1' => $n - 1];
        return $segs;
    }

    /** @return array<string,mixed>|null */
    private function summariseSegment(array $pts, int $i0, int $i1, ?int $maxHr): ?array {
        if ($i1 <= $i0) return null;
        $distM = round(($pts[$i1]['d'] - $pts[$i0]['d']) * 1000);
        $dur = $pts[$i1]['t'] - $pts[$i0]['t'];
        if ($distM <= 0 || $dur <= 0) return null;
        $paceSec = (int)round($dur / ($distM / 1000.0));
        $hrs = [];
        for ($i = $i0; $i <= $i1; $i++) {
            if ($pts[$i]['hr'] !== null) $hrs[] = $pts[$i]['hr'];
        }
        $avgHr = $hrs ? (int)round(array_sum($hrs) / count($hrs)) : null;
        $maxSegHr = $hrs ? max($hrs) : null;
        return [
            'distance_m' => (int)$distM,
            'duration_sec' => (int)$dur,
            'pace' => $this->fmtPace($paceSec),
            'pace_sec' => $paceSec,
            'avg_hr' => $avgHr,
            'max_hr' => $maxSegHr,
            'hr_pct' => ($maxHr && $avgHr) ? round($avgHr / $maxHr, 2) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function classify(array $reps, array $recov, ?int $maxHr): array {
        $n = count($reps);
        $dists = array_column($reps, 'distance_m');
        $paceSecs = array_column($reps, 'pace_sec');
        $meanD = array_sum($dists) / $n;
        $cvD = $this->cv($dists);
        $regular = $cvD < 0.20; // дистанции репитов в пределах ~20%

        $repHrs = array_filter(array_column($reps, 'avg_hr'));
        $minPace = $paceSecs ? min($paceSecs) : 0;
        $maxPace = $paceSecs ? max($paceSecs) : 0;

        if ($regular) {
            $type = 'interval';
            $confidence = ($cvD < 0.12) ? 'high' : 'medium';
            $pattern = $n . ' × ~' . $this->roundDist($meanD) . ' м @ ' . $this->fmtPace((int)round($this->mean($paceSecs)));
        } else {
            $type = 'fartlek';
            $confidence = 'medium';
            $pattern = $n . ' быстрых отрезков разной длины (' . $this->roundDist(min($dists)) . '–' . $this->roundDist(max($dists)) . ' м)';
        }

        return [
            'source' => 'stream',
            'type' => $type,
            'confidence' => $confidence,
            'rep_count' => $n,
            'pattern' => $pattern,
            'reps' => $reps,
            'recoveries' => $recov,
            'work_pace_range' => $minPace && $maxPace ? [$this->fmtPace($minPace), $this->fmtPace($maxPace)] : null,
            'work_hr_range' => $repHrs ? [min($repHrs), max($repHrs)] : null,
            'narrative' => $this->narrative($type, $reps, $recov, $pattern, $repHrs),
        ];
    }

    private function narrative(string $type, array $reps, array $recov, string $pattern, array $repHrs): string {
        $hrPart = '';
        if ($repHrs) {
            $lo = min($repHrs); $hi = max($repHrs);
            $hrPart = ' при пульсе ' . ($lo === $hi ? $lo : "{$lo}–{$hi}");
        }
        $recPart = '';
        if (!empty($recov)) {
            $rd = array_column($recov, 'distance_m');
            $recPart = '; трусца ~' . $this->roundDist(array_sum($rd) / count($rd)) . ' м между отрезками';
        }
        if ($type === 'interval') {
            return "Интервалы: {$pattern}{$hrPart}{$recPart}.";
        }
        return "Фартлек: {$pattern}{$hrPart}{$recPart}.";
    }

    // ── утилиты ──────────────────────────────────────────────────────────
    /** Скользящее среднее по ряду (null'ы пропускаются). */
    private function smoothSeries(array $vals, int $win): array {
        $n = count($vals);
        if ($n === 0 || $win < 2) return $vals;
        $half = intdiv($win, 2);
        $out = $vals;
        for ($i = 0; $i < $n; $i++) {
            $s = 0.0; $c = 0;
            for ($j = max(0, $i - $half); $j <= min($n - 1, $i + $half); $j++) {
                if ($vals[$j] !== null) { $s += $vals[$j]; $c++; }
            }
            $out[$i] = $c ? $s / $c : null;
        }
        return $out;
    }

    private function mean(array $v): float { return $v ? array_sum($v) / count($v) : 0.0; }

    private function cv(array $v): float {
        $n = count($v);
        if ($n < 2) return 0.0;
        $m = $this->mean($v);
        if ($m == 0.0) return 0.0;
        $var = 0.0;
        foreach ($v as $x) $var += ($x - $m) ** 2;
        return sqrt($var / $n) / $m;
    }

    private function roundDist(float $m): int {
        if ($m >= 1000) return (int)(round($m / 100) * 100);
        if ($m >= 300) return (int)(round($m / 50) * 50);
        return (int)(round($m / 25) * 25);
    }

    private function fmtPace(int $secPerKm): string {
        if ($secPerKm <= 0) return '—';
        $m = (int)($secPerKm / 60);
        $s = $secPerKm % 60;
        return sprintf('%d:%02d', $m, $s);
    }
}
