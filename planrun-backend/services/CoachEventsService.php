<?php
/**
 * CoachEventsService — лента событий тренера за последние дни.
 *
 * Источники (MVP Фаза 3):
 *  - upload: новые тренировки атлетов за последние 48 часов (workouts.start_time)
 *  - risk: атлеты с низким compliance или 7+ дней без активности
 *  - question: непрочитанные/неотвеченные сообщения атлета в прямом чате (chat_conversations.type='admin')
 *
 * Структура события унифицирована для отображения в EventStream:
 *  { id, athlete_id, athlete_username, athlete_avatar_path,
 *    kind: 'upload'|'risk'|'question', tone, title, detail, created_at,
 *    cta_label, cta_action, context: {...} }
 *
 * Сортируется по created_at DESC (новые сверху).
 */

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/StatsService.php';

class CoachEventsService extends BaseService {

    /**
     * Получить ленту событий тренера.
     *
     * @param int $coachId
     * @param int $hoursBack  по умолчанию 48 часов
     * @return array { events: [...] }
     */
    public function getEvents(int $coachId, int $hoursBack = 48): array {
        $events = [];

        foreach ($this->collectUploads($coachId, $hoursBack) as $ev) $events[] = $ev;
        foreach ($this->collectRisks($coachId) as $ev) $events[] = $ev;
        foreach ($this->collectQuestions($coachId, max($hoursBack, 72)) as $ev) $events[] = $ev;
        foreach ($this->collectPRs($coachId, max($hoursBack, 168)) as $ev) $events[] = $ev;

        // Сортировка по created_at DESC
        usort($events, static function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return ['events' => $events];
    }

    // ── upload ──────────────────────────────────────────────

    private function collectUploads(int $coachId, int $hoursBack): array {
        $sql = "SELECT w.id, w.user_id, w.activity_type, w.start_time, w.duration_minutes,
                       w.distance_km, w.avg_pace, w.avg_heart_rate, u.username, u.avatar_path
                FROM workouts w
                JOIN user_coaches uc ON uc.user_id = w.user_id
                JOIN users u ON u.id = w.user_id
                WHERE uc.coach_id = ?
                  AND w.start_time >= NOW() - INTERVAL ? HOUR
                ORDER BY w.start_time DESC
                LIMIT 30";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $coachId, $hoursBack);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $events = [];
        foreach ($rows as $r) {
            $dist = $r['distance_km'] !== null ? (float) $r['distance_km'] : null;
            $pace = $r['avg_pace'];
            $hr = $r['avg_heart_rate'];
            $detail = $this->formatUploadDetail($dist, $pace, (int) $r['duration_minutes'], $hr);
            $events[] = [
                'id' => 'upload-' . (int) $r['id'],
                'athlete_id' => (int) $r['user_id'],
                'athlete_username' => $r['username'],
                'athlete_avatar_path' => $r['avatar_path'],
                'kind' => 'upload',
                'tone' => 'success',
                'title' => $this->activityTypeLabel($r['activity_type']) . ($dist ? " · " . $this->formatKm($dist) . " км" : ''),
                'detail' => $detail,
                'created_at' => $r['start_time'],
                'cta_label' => 'Похвалить',
                'cta_action' => 'praise',
                'context' => [
                    'workout_id' => (int) $r['id'],
                    'distance_km' => $dist,
                    'avg_pace' => $pace,
                ],
            ];
        }
        return $events;
    }

    // ── risk ──────────────────────────────────────────────────

    private function collectRisks(int $coachId): array {
        // Получаем атлетов с week_completed/week_total/last_activity
        $sql = "SELECT u.id, u.username, u.avatar_path,
                       (SELECT COUNT(*) FROM training_plan_days d
                        WHERE d.user_id = u.id
                          AND d.date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                          AND d.date <= DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY)
                          AND d.type NOT IN ('rest', 'free')
                       ) AS week_total,
                       (SELECT COUNT(DISTINCT DATE(w2.start_time)) FROM workouts w2
                        WHERE w2.user_id = u.id
                          AND DATE(w2.start_time) >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                          AND DATE(w2.start_time) <= CURDATE()
                       ) AS week_completed,
                       (SELECT MAX(w3.start_time) FROM workouts w3 WHERE w3.user_id = u.id) AS last_activity
                FROM users u
                JOIN user_coaches uc ON uc.user_id = u.id
                WHERE uc.coach_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $coachId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $events = [];
        $nowTs = time();
        foreach ($rows as $r) {
            $total = (int) ($r['week_total'] ?? 0);
            $done = (int) ($r['week_completed'] ?? 0);
            $compliance = $total > 0 ? $done / $total : null;
            $lastActivity = $r['last_activity'];
            $daysSince = $lastActivity ? (int) floor(($nowTs - strtotime($lastActivity)) / 86400) : null;

            $isRisk = ($compliance !== null && $compliance < 0.5) || ($daysSince !== null && $daysSince > 7);
            if (!$isRisk) continue;

            $title = '';
            $detail = '';
            $tone = 'danger';
            if ($daysSince !== null && $daysSince > 7) {
                $title = 'Долго без активности';
                $detail = "Последняя тренировка — {$daysSince} дн. назад";
            } else {
                $title = 'Низкое выполнение плана';
                $detail = 'Compliance ' . (int) round(($compliance ?: 0) * 100) . "% · {$done}/{$total} за неделю";
                $tone = 'warn';
            }

            $events[] = [
                'id' => 'risk-' . (int) $r['id'],
                'athlete_id' => (int) $r['id'],
                'athlete_username' => $r['username'],
                'athlete_avatar_path' => $r['avatar_path'],
                'kind' => 'risk',
                'tone' => $tone,
                'title' => $title,
                'detail' => $detail,
                'created_at' => date('Y-m-d H:i:s'),
                'cta_label' => 'Связаться',
                'cta_action' => 'contact',
                'context' => [
                    'compliance' => $compliance,
                    'days_since' => $daysSince,
                ],
            ];
        }
        return $events;
    }

    // ── question (unanswered athlete message) ──────────────────

    private function collectQuestions(int $coachId, int $hoursBack): array {
        $sql = "SELECT m.id, m.conversation_id, m.content, m.created_at,
                       c.user_id, u.username, u.avatar_path
                FROM chat_messages m
                JOIN chat_conversations c ON c.id = m.conversation_id
                JOIN user_coaches uc ON uc.user_id = c.user_id
                JOIN users u ON u.id = c.user_id
                WHERE c.type = 'admin'
                  AND m.sender_type = 'user'
                  AND uc.coach_id = ?
                  AND m.created_at >= NOW() - INTERVAL ? HOUR
                  AND NOT EXISTS (
                    SELECT 1 FROM chat_messages m2
                    WHERE m2.conversation_id = m.conversation_id
                      AND m2.created_at > m.created_at
                      AND m2.sender_type = 'admin'
                  )
                ORDER BY m.created_at DESC
                LIMIT 20";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $coachId, $hoursBack);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Один event per athlete (последнее unanswered сообщение)
        $seen = [];
        $events = [];
        foreach ($rows as $r) {
            $athleteId = (int) $r['user_id'];
            if (isset($seen[$athleteId])) continue;
            $seen[$athleteId] = true;
            $excerpt = mb_substr((string) $r['content'], 0, 120);
            if (mb_strlen($r['content']) > 120) $excerpt .= '…';
            $events[] = [
                'id' => 'question-' . (int) $r['id'],
                'athlete_id' => $athleteId,
                'athlete_username' => $r['username'],
                'athlete_avatar_path' => $r['avatar_path'],
                'kind' => 'question',
                'tone' => 'info',
                'title' => 'Сообщение от атлета',
                'detail' => '«' . $excerpt . '»',
                'created_at' => $r['created_at'],
                'cta_label' => 'Ответить',
                'cta_action' => 'reply',
                'context' => [
                    'conversation_id' => (int) $r['conversation_id'],
                    'message_id' => (int) $r['id'],
                ],
            ];
        }
        return $events;
    }

    // ── pr (personal record) ──────────────────────────────────
    /**
     * Детектор PR: для каждого атлета берём «лучшие гонки» за 52 нед и проверяем,
     * установлен ли свежий PR (последняя дата ≥ now - hoursBack часов).
     *
     * Per-athlete результаты кэшируются на 10 мин (Cache::set), т.к.
     * getBestRacesProgression делает 2 SQL-запроса с join'ами и PR редко меняются.
     */
    private function collectPRs(int $coachId, int $hoursBack): array {
        $athleteRows = $this->db->prepare(
            "SELECT u.id, u.username, u.avatar_path
             FROM user_coaches uc
             JOIN users u ON u.id = uc.user_id
             WHERE uc.coach_id = ?"
        );
        $athleteRows->bind_param('i', $coachId);
        $athleteRows->execute();
        $athletes = $athleteRows->get_result()->fetch_all(MYSQLI_ASSOC);
        $athleteRows->close();

        if (count($athletes) === 0) return [];

        // Опциональный кэш — если cache_config недоступен, fallback на прямой вызов
        $cacheAvailable = false;
        try {
            require_once __DIR__ . '/../cache_config.php';
            $cacheAvailable = class_exists('Cache');
        } catch (Throwable $e) {
            $cacheAvailable = false;
        }

        $stats = new StatsService($this->db);
        $events = [];
        $cutoffTs = time() - ($hoursBack * 3600);
        $cacheTtl = 600; // 10 минут

        foreach ($athletes as $a) {
            $athleteId = (int) $a['id'];
            $cacheKey = "coach_pr_records_{$athleteId}";
            $records = null;

            if ($cacheAvailable) {
                try {
                    $cached = Cache::get($cacheKey);
                    if (is_array($cached)) $records = $cached;
                } catch (Throwable $e) { /* miss */ }
            }

            if ($records === null) {
                try {
                    $records = $stats->getBestRacesProgression($athleteId, 52);
                } catch (Exception $e) {
                    continue;
                }
                if ($cacheAvailable && is_array($records)) {
                    try { Cache::set($cacheKey, $records, $cacheTtl); } catch (Throwable $e) { /* ignore */ }
                }
            }

            if (!is_array($records)) continue;

            foreach ($records as $r) {
                $date = $r['date'] ?? null;
                if (!$date) continue;
                $dateTs = strtotime($date . ' 12:00:00');
                if ($dateTs === false || $dateTs < $cutoffTs) continue;

                $distLabel = (string) ($r['distance_label'] ?? '');
                $timeFormatted = $this->formatPrTime((int) ($r['time_sec'] ?? 0));
                $title = 'Личный рекорд' . ($distLabel ? ' · ' . $this->prDistanceLabel($distLabel) : '');
                $detail = $timeFormatted;
                if (!empty($r['vdot'])) {
                    $detail .= ' · VDOT ' . round((float) $r['vdot'], 1);
                }

                $events[] = [
                    'id' => 'pr-' . (int) $a['id'] . '-' . $distLabel . '-' . substr($date, 0, 10),
                    'athlete_id' => (int) $a['id'],
                    'athlete_username' => $a['username'],
                    'athlete_avatar_path' => $a['avatar_path'],
                    'kind' => 'pr',
                    'tone' => 'primary',
                    'title' => $title,
                    'detail' => $detail,
                    'created_at' => $date . ' 12:00:00',
                    'cta_label' => 'Поздравить',
                    'cta_action' => 'praise',
                    'context' => [
                        'distance_label' => $distLabel,
                        'time_sec' => (int) ($r['time_sec'] ?? 0),
                    ],
                ];
            }
        }
        return $events;
    }

    private function formatPrTime(int $sec): string {
        if ($sec <= 0) return '—';
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        if ($h > 0) return sprintf('%d:%02d:%02d', $h, $m, $s);
        return sprintf('%d:%02d', $m, $s);
    }

    private function prDistanceLabel(string $key): string {
        $map = ['5k' => '5 км', '10k' => '10 км', 'half' => 'Полумарафон', 'marathon' => 'Марафон'];
        return $map[$key] ?? $key;
    }

    // ── Helpers ──────────────────────────────────────────────

    private function activityTypeLabel(string $t): string {
        $map = [
            'easy' => 'Лёгкий бег',
            'tempo' => 'Темповая',
            'interval' => 'Интервалы',
            'long' => 'Длительная',
            'fartlek' => 'Фартлек',
            'race' => 'Гонка',
            'control' => 'Контрольная',
            'sbu' => 'СБУ',
            'ofp' => 'ОФП',
            'other' => 'ОФП',
            'walking' => 'Ходьба',
            'cycling' => 'Велосипед',
            'swimming' => 'Плавание',
            'running' => 'Бег',
            'run' => 'Бег',
            'hike' => 'Поход',
            'hiking' => 'Поход',
        ];
        $key = strtolower($t);
        return $map[$key] ?? 'Тренировка';
    }

    private function formatKm(float $km): string {
        if ($km >= 100) return (string) (int) round($km);
        return rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.');
    }

    private function formatUploadDetail(?float $distKm, ?string $pace, int $durMin, ?int $hr): string {
        $parts = [];
        if ($durMin > 0) {
            $h = intdiv($durMin, 60);
            $m = $durMin % 60;
            $parts[] = $h > 0 ? "{$h}ч " . sprintf('%02d', $m) . "м" : "{$durMin} мин";
        }
        if ($pace) $parts[] = $pace . ' /км';
        if ($hr) $parts[] = "ЧСС {$hr}";
        return implode(' · ', $parts);
    }
}
