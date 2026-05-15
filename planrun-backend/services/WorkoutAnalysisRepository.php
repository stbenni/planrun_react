<?php
/**
 * Persistence + queries для разборов тренировок.
 *
 * Каждая запись — это структурированный итог одной фактической тренировки:
 * план vs факт, классификация (interval/tempo/race/...), структура лапов,
 * LLM-разбор и feedback. Используется для передачи контекста в:
 *  - WeeklyPlanAdaptationService (промпт адаптации)
 *  - ChatPromptBuilder (system-контекст)
 *  - ProactiveCoachService (генерация проактивных сообщений)
 *  - PlanGenerationProcessorService (пересчёт плана)
 */

class WorkoutAnalysisRepository {

    private $db;
    private static bool $schemaEnsured = false;

    public function __construct($db) {
        $this->db = $db;
        $this->ensureSchema();
    }

    public function ensureSchema(): void {
        if (self::$schemaEnsured) return;
        @$this->db->query(
            "CREATE TABLE IF NOT EXISTS workout_analyses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                source_kind VARCHAR(24) NOT NULL,
                source_id INT UNSIGNED NOT NULL,
                workout_date DATE NOT NULL,

                planned_type VARCHAR(32) NULL DEFAULT NULL,
                planned_description TEXT NULL,

                actual_distance_km DECIMAL(7,2) NULL DEFAULT NULL,
                actual_duration_min SMALLINT UNSIGNED NULL DEFAULT NULL,
                actual_avg_pace VARCHAR(10) NULL DEFAULT NULL,
                actual_avg_hr SMALLINT UNSIGNED NULL DEFAULT NULL,
                actual_max_hr SMALLINT UNSIGNED NULL DEFAULT NULL,

                detected_type VARCHAR(32) NULL DEFAULT NULL,
                detected_confidence VARCHAR(16) NULL DEFAULT NULL,
                intensity DECIMAL(4,3) NULL DEFAULT NULL,
                pace_variance DECIMAL(5,3) NULL DEFAULT NULL,

                planned_is_key TINYINT(1) NULL DEFAULT NULL,
                is_significant TINYINT(1) NOT NULL DEFAULT 0,

                structure_json MEDIUMTEXT NULL,
                llm_review_text MEDIUMTEXT NULL,
                summary_line VARCHAR(255) NOT NULL,

                feedback_rpe TINYINT UNSIGNED NULL DEFAULT NULL,
                feedback_legs TINYINT UNSIGNED NULL DEFAULT NULL,
                feedback_pain_flag TINYINT(1) NULL DEFAULT NULL,
                feedback_fatigue_flag TINYINT(1) NULL DEFAULT NULL,

                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uk_user_source (user_id, source_kind, source_id),
                INDEX idx_user_date (user_id, workout_date),
                INDEX idx_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Тихие миграции для уже существующей таблицы.
        foreach ([
            "ALTER TABLE workout_analyses ADD COLUMN planned_is_key TINYINT(1) NULL DEFAULT NULL AFTER pace_variance",
            "ALTER TABLE workout_analyses ADD COLUMN is_significant TINYINT(1) NOT NULL DEFAULT 0 AFTER planned_is_key",
            "ALTER TABLE workout_analyses ADD INDEX idx_user_significant (user_id, is_significant, workout_date)",
        ] as $sql) {
            try { $this->db->query($sql); } catch (Throwable $e) { /* column/index уже есть */ }
        }
        self::$schemaEnsured = true;
    }

    /**
     * Сохранить или обновить разбор (upsert по user_id+source).
     */
    public function save(array $data): int {
        $userId = (int) ($data['user_id'] ?? 0);
        $sourceKind = (string) ($data['source_kind'] ?? '');
        $sourceId = (int) ($data['source_id'] ?? 0);
        if ($userId <= 0 || $sourceKind === '' || $sourceId <= 0) return 0;

        // Automatic flags
        $data['planned_is_key'] = $data['planned_is_key'] ?? null;
        $data['is_significant'] = self::detectSignificance($data) ? 1 : 0;

        $structureJson = isset($data['structure']) ? json_encode($data['structure'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->db->prepare(
            "INSERT INTO workout_analyses
                (user_id, source_kind, source_id, workout_date,
                 planned_type, planned_description,
                 actual_distance_km, actual_duration_min, actual_avg_pace, actual_avg_hr, actual_max_hr,
                 detected_type, detected_confidence, intensity, pace_variance,
                 planned_is_key, is_significant,
                 structure_json, llm_review_text, summary_line,
                 feedback_rpe, feedback_legs, feedback_pain_flag, feedback_fatigue_flag)
             VALUES (?, ?, ?, ?,
                     ?, ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                workout_date = VALUES(workout_date),
                planned_type = VALUES(planned_type),
                planned_description = VALUES(planned_description),
                actual_distance_km = VALUES(actual_distance_km),
                actual_duration_min = VALUES(actual_duration_min),
                actual_avg_pace = VALUES(actual_avg_pace),
                actual_avg_hr = VALUES(actual_avg_hr),
                actual_max_hr = VALUES(actual_max_hr),
                detected_type = VALUES(detected_type),
                detected_confidence = VALUES(detected_confidence),
                intensity = VALUES(intensity),
                pace_variance = VALUES(pace_variance),
                planned_is_key = COALESCE(VALUES(planned_is_key), planned_is_key),
                is_significant = VALUES(is_significant),
                structure_json = VALUES(structure_json),
                llm_review_text = COALESCE(VALUES(llm_review_text), llm_review_text),
                summary_line = VALUES(summary_line),
                feedback_rpe = COALESCE(VALUES(feedback_rpe), feedback_rpe),
                feedback_legs = COALESCE(VALUES(feedback_legs), feedback_legs),
                feedback_pain_flag = COALESCE(VALUES(feedback_pain_flag), feedback_pain_flag),
                feedback_fatigue_flag = COALESCE(VALUES(feedback_fatigue_flag), feedback_fatigue_flag),
                updated_at = NOW()"
        );
        if (!$stmt) return 0;

        $workoutDate = (string) ($data['workout_date'] ?? '');
        $plannedType = $data['planned_type'] ?? null;
        $plannedDescription = $data['planned_description'] ?? null;
        $distance = isset($data['actual_distance_km']) ? (float) $data['actual_distance_km'] : null;
        $duration = isset($data['actual_duration_min']) ? (int) $data['actual_duration_min'] : null;
        $pace = $data['actual_avg_pace'] ?? null;
        $avgHr = isset($data['actual_avg_hr']) ? (int) $data['actual_avg_hr'] : null;
        $maxHr = isset($data['actual_max_hr']) ? (int) $data['actual_max_hr'] : null;
        $detectedType = $data['detected_type'] ?? null;
        $detectedConf = $data['detected_confidence'] ?? null;
        $intensity = isset($data['intensity']) ? (float) $data['intensity'] : null;
        $paceVariance = isset($data['pace_variance']) ? (float) $data['pace_variance'] : null;
        $llmText = $data['llm_review_text'] ?? null;
        $summaryLine = (string) ($data['summary_line'] ?? '');
        $feedbackRpe = isset($data['feedback_rpe']) ? (int) $data['feedback_rpe'] : null;
        $feedbackLegs = isset($data['feedback_legs']) ? (int) $data['feedback_legs'] : null;
        $feedbackPain = isset($data['feedback_pain_flag']) ? (int) $data['feedback_pain_flag'] : null;
        $feedbackFatigue = isset($data['feedback_fatigue_flag']) ? (int) $data['feedback_fatigue_flag'] : null;
        $plannedIsKey = isset($data['planned_is_key']) ? (int) $data['planned_is_key'] : null;
        $isSignificant = (int) ($data['is_significant'] ?? 0);

        $stmt->bind_param(
            'isisssdisiissddiisssiiii',
            $userId,
            $sourceKind,
            $sourceId,
            $workoutDate,
            $plannedType,
            $plannedDescription,
            $distance,
            $duration,
            $pace,
            $avgHr,
            $maxHr,
            $detectedType,
            $detectedConf,
            $intensity,
            $paceVariance,
            $plannedIsKey,
            $isSignificant,
            $structureJson,
            $llmText,
            $summaryLine,
            $feedbackRpe,
            $feedbackLegs,
            $feedbackPain,
            $feedbackFatigue
        );
        $stmt->execute();
        $id = (int) ($stmt->insert_id ?: 0);
        $stmt->close();
        return $id;
    }

    /**
     * Получить summary_line всех тренировок текущего активного плана пользователя
     * + хвост предыдущей подготовки (N недель до старта плана), чтобы при пересчёте
     * и адаптации AI видел continuity через смену мезоциклов.
     *
     * @param int $contextWeeksBeforePlan  Сколько недель до старта плана добавить в окно
     * @return string[]  массив отформатированных summary-строк, по возрастанию даты
     */
    public function getSummaryLinesForActivePlan(int $userId, int $contextWeeksBeforePlan = 6): array {
        $planStart = $this->getActivePlanStartDate($userId);

        if ($planStart !== null) {
            $extendedStart = date('Y-m-d', strtotime("-{$contextWeeksBeforePlan} weeks", strtotime($planStart)));
        } else {
            // Нет активного плана — берём последние 12 недель тренировок.
            $extendedStart = date('Y-m-d', strtotime('-12 weeks'));
        }

        return $this->getSummaryLinesSince($userId, $extendedStart);
    }

    public function getSummaryLinesSince(int $userId, string $sinceDate, int $limit = 200): array {
        $stmt = $this->db->prepare(
            "SELECT summary_line FROM workout_analyses
             WHERE user_id = ? AND workout_date >= ?
             ORDER BY workout_date ASC, id ASC
             LIMIT ?"
        );
        if (!$stmt) return [];
        $stmt->bind_param('isi', $userId, $sinceDate, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['summary_line'])) $lines[] = (string) $row['summary_line'];
        }
        $stmt->close();
        return $lines;
    }

    /**
     * Сводка по неделям (Пн-Вс) — объём, количество тренировок, ключевые типы.
     * Передаётся в LLM как «факты», чтобы LLM не считал сам и не галлюцинировал.
     *
     * @return string[]  массив строк вида "27.04-03.05: 74.0 км / 5 trn (race, mixed) [МАРАФОН]"
     */
    public function getWeeklyRollupSince(int $userId, string $sinceDate): array {
        $stmt = $this->db->prepare(
            "SELECT workout_date, actual_distance_km, detected_type
             FROM workout_analyses
             WHERE user_id = ? AND workout_date >= ?
             ORDER BY workout_date ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('is', $userId, $sinceDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $weeks = [];
        while ($row = $result->fetch_assoc()) {
            $date = (string) $row['workout_date'];
            $ts = strtotime($date);
            // Понедельник этой недели (ISO N=1)
            $dayOfWeek = (int) date('N', $ts);
            $monday = date('Y-m-d', strtotime("-" . ($dayOfWeek - 1) . " days", $ts));
            $sunday = date('Y-m-d', strtotime("+6 days", strtotime($monday)));
            $key = $monday . '|' . $sunday;
            if (!isset($weeks[$key])) {
                $weeks[$key] = ['monday' => $monday, 'sunday' => $sunday, 'km' => 0.0, 'count' => 0, 'types' => []];
            }
            $weeks[$key]['km'] += (float) ($row['actual_distance_km'] ?? 0);
            $weeks[$key]['count']++;
            $type = $row['detected_type'] ?? null;
            if ($type && $type !== 'unknown' && $type !== 'mixed') {
                $weeks[$key]['types'][$type] = ($weeks[$key]['types'][$type] ?? 0) + 1;
            }
        }
        $stmt->close();

        $lines = [];
        foreach ($weeks as $w) {
            $monDay = (int) date('d', strtotime($w['monday']));
            $monMon = (int) date('m', strtotime($w['monday']));
            $sunDay = (int) date('d', strtotime($w['sunday']));
            $sunMon = (int) date('m', strtotime($w['sunday']));
            $period = sprintf('%02d.%02d-%02d.%02d', $monDay, $monMon, $sunDay, $sunMon);
            $km = round($w['km'], 1);
            $count = $w['count'];
            $types = '';
            if (!empty($w['types'])) {
                arsort($w['types']);
                $typeLabels = array_keys($w['types']);
                $types = ' (' . implode(', ', array_slice($typeLabels, 0, 3)) . ')';
            }
            $marker = '';
            if (isset($w['types']['race']) && $w['km'] >= 35) $marker = ' [МАРАФОН]';
            elseif (isset($w['types']['race'])) $marker = ' [гонка]';
            $lines[] = "  {$period}: {$km} км / {$count} trn{$types}{$marker}";
        }

        return $lines;
    }

    /**
     * Сводка ключевых/значимых тренировок: каждая строка — ключевая (по плану) или
     * объективно значимая (race / большой long-run / интервалы / темпо).
     * Каждая строка с пометкой выполнено/пропущено.
     *
     * @return string[]
     */
    public function getKeyWorkoutSummary(int $userId, string $sinceDate): array {
        $stmt = $this->db->prepare(
            "SELECT workout_date, planned_type, planned_description,
                    actual_distance_km, actual_avg_pace, intensity,
                    detected_type, detected_confidence, planned_is_key, is_significant,
                    feedback_rpe, feedback_pain_flag, feedback_fatigue_flag
             FROM workout_analyses
             WHERE user_id = ? AND workout_date >= ?
               AND (planned_is_key = 1 OR is_significant = 1)
             ORDER BY workout_date ASC, id ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('is', $userId, $sinceDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $lines[] = self::formatKeyLine($row);
        }
        $stmt->close();
        return $lines;
    }

    public function getKeyWorkoutSummaryForActivePlan(int $userId, int $contextWeeksBeforePlan = 6): array {
        $planStart = $this->getActivePlanStartDate($userId);
        $since = $planStart
            ? date('Y-m-d', strtotime("-{$contextWeeksBeforePlan} weeks", strtotime($planStart)))
            : date('Y-m-d', strtotime('-12 weeks'));
        return $this->getKeyWorkoutSummary($userId, $since);
    }

    private static function formatKeyLine(array $row): string {
        $date = date('d.m', strtotime((string) $row['workout_date']));
        $detected = (string) ($row['detected_type'] ?? '');
        $plannedType = trim((string) ($row['planned_type'] ?? ''));
        $isPlannedKey = !empty($row['planned_is_key']);

        $marker = $isPlannedKey ? '★' : '◆';
        $planLabel = $plannedType !== '' ? "ПЛАН {$plannedType}" : 'без плана';

        $factPart = '';
        $km = isset($row['actual_distance_km']) ? round((float) $row['actual_distance_km'], 1) : null;
        if ($detected !== '' && $detected !== 'unknown') $factPart .= "{$detected}";
        if ($km !== null) $factPart .= " {$km}км";
        if (!empty($row['actual_avg_pace'])) $factPart .= " @{$row['actual_avg_pace']}";
        if (!empty($row['intensity'])) $factPart .= " hr" . (int) round($row['intensity'] * 100) . '%';

        $factPart = trim($factPart);
        if ($factPart === '') $factPart = 'не выполнена';

        // Determine «выполнено / пропущено» — если planned_is_key, но нет факта, помечаем как пропуск.
        $statusMarker = '';
        if ($isPlannedKey) {
            if ($km !== null && $km > 0) {
                // Простая эвристика: если факт <60% от планируемой дистанции — недовыполнено.
                $plannedKm = self::extractPlannedKmFromDesc((string) ($row['planned_description'] ?? ''));
                if ($plannedKm > 0 && $km / $plannedKm < 0.6) {
                    $statusMarker = ' [НЕДОВЫПОЛНЕНА]';
                } else {
                    $statusMarker = ' [выполнено]';
                }
            } else {
                $statusMarker = ' [ПРОПУЩЕНА]';
            }
        }

        $extras = [];
        if (!empty($row['feedback_pain_flag'])) $extras[] = 'БОЛЬ';
        if (!empty($row['feedback_fatigue_flag'])) $extras[] = 'усталость';
        if (!empty($row['feedback_rpe'])) $extras[] = "RPE {$row['feedback_rpe']}";
        $extra = !empty($extras) ? ' [' . implode(', ', $extras) . ']' : '';

        return "{$marker} {$date} {$planLabel} → ФАКТ {$factPart}{$statusMarker}{$extra}";
    }

    private static function extractPlannedKmFromDesc(string $desc): float {
        if ($desc === '') return 0.0;
        if (preg_match('/(\d+(?:[\.,]\d+)?)\s*км/u', $desc, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return 0.0;
    }

    public function getWeeklyRollupForActivePlan(int $userId, int $contextWeeksBeforePlan = 6): array {
        $planStart = $this->getActivePlanStartDate($userId);
        $since = $planStart
            ? date('Y-m-d', strtotime("-{$contextWeeksBeforePlan} weeks", strtotime($planStart)))
            : date('Y-m-d', strtotime('-12 weeks'));
        return $this->getWeeklyRollupSince($userId, $since);
    }

    public function getRecent(int $userId, int $limit = 20): array {
        $stmt = $this->db->prepare(
            "SELECT id, source_kind, source_id, workout_date, planned_type, detected_type,
                    detected_confidence, intensity, actual_distance_km, actual_duration_min,
                    actual_avg_pace, actual_avg_hr,
                    summary_line, llm_review_text
             FROM workout_analyses
             WHERE user_id = ?
             ORDER BY workout_date DESC, id DESC
             LIMIT ?"
        );
        if (!$stmt) return [];
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function getBySource(int $userId, string $sourceKind, int $sourceId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM workout_analyses
             WHERE user_id = ? AND source_kind = ? AND source_id = ?
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('isi', $userId, $sourceKind, $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function getActivePlanStartDate(int $userId): ?string {
        // Источник истины: user_training_plans.is_active=1.
        $stmt = $this->db->prepare(
            "SELECT start_date FROM user_training_plans
             WHERE user_id = ? AND is_active = 1
             ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['start_date'])) {
                return (string) $row['start_date'];
            }
        }

        // Fallback: training_plan_weeks с week_number=1 (новые планы) за последние 6 мес.
        $stmt = $this->db->prepare(
            "SELECT MIN(start_date) AS first_date
             FROM training_plan_weeks
             WHERE user_id = ? AND week_number = 1
               AND start_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $d = $row['first_date'] ?? null;
        return $d ? (string) $d : null;
    }

    /**
     * Формирует компактную summary_line из данных разбора.
     * Пример: "11.05 ПЛАН easy 8км → ФАКТ interval 10.4км @4:54 hr87% [3×работа @3:41–3:47]"
     */
    /**
     * Эвристика: тренировка значима, если запланирована как ключевая ИЛИ по факту
     * это race / большая длительная / интервалы / темпо высокой интенсивности.
     */
    public static function detectSignificance(array $data): bool {
        if (!empty($data['planned_is_key'])) return true;
        $type = (string) ($data['detected_type'] ?? '');
        $distance = (float) ($data['actual_distance_km'] ?? 0);
        $intensity = (float) ($data['intensity'] ?? 0);
        if ($type === 'race') return true;
        if ($type === 'long' && $distance >= 20.0) return true;
        if ($type === 'interval' && $distance >= 8.0) return true;
        if ($type === 'tempo' && $distance >= 10.0 && $intensity >= 0.82) return true;
        return false;
    }

    public static function formatSummaryLine(array $data): string {
        $date = isset($data['workout_date']) ? date('d.m', strtotime((string) $data['workout_date'])) : '?';
        $plan = (string) ($data['planned_type'] ?? '');
        $isKey = !empty($data['planned_is_key']);
        $planPart = $plan !== '' ? "ПЛАН {$plan}" : 'ПЛАН ?';
        if ($isKey) $planPart = "★ {$planPart}";

        $planDesc = self::extractDistanceFromDesc((string) ($data['planned_description'] ?? ''));
        if ($planDesc !== '') $planPart .= " {$planDesc}";

        $detected = (string) ($data['detected_type'] ?? '');
        if ($detected === '') $detected = 'unknown';

        $distance = isset($data['actual_distance_km']) ? round((float) $data['actual_distance_km'], 1) : null;
        $pace = (string) ($data['actual_avg_pace'] ?? '');
        $intensity = isset($data['intensity']) ? (int) round(((float) $data['intensity']) * 100) : null;

        $factPart = "ФАКТ {$detected}";
        if ($distance !== null) $factPart .= " {$distance}км";
        if ($pace !== '') $factPart .= " @{$pace}";
        if ($intensity !== null) $factPart .= " hr{$intensity}%";

        // Дополнения по структуре
        $extras = [];
        $structure = $data['structure'] ?? null;
        if (is_string($structure)) {
            $structure = json_decode($structure, true);
        }
        if (is_array($structure)) {
            if (!empty($structure['work_laps']) && $detected === 'interval') {
                $workCount = count($structure['work_laps']);
                $paces = array_unique(array_map(fn($l) => $l['pace'] ?? '', $structure['work_laps']));
                $paces = array_filter($paces);
                if (!empty($paces)) {
                    $extras[] = "{$workCount}×работа @" . implode('/', $paces);
                }
            }
        }

        // Feedback flags
        if (!empty($data['feedback_pain_flag'])) $extras[] = 'БОЛЬ';
        if (!empty($data['feedback_fatigue_flag'])) $extras[] = 'УСТАЛОСТЬ';
        if (!empty($data['feedback_rpe'])) $extras[] = "RPE {$data['feedback_rpe']}";

        $line = "{$date} {$planPart} → {$factPart}";
        if (!empty($extras)) $line .= " [" . implode(', ', $extras) . "]";

        return mb_substr($line, 0, 250, 'UTF-8');
    }

    private static function extractDistanceFromDesc(string $desc): string {
        if ($desc === '') return '';
        if (preg_match('/(\d+(?:[\.,]\d+)?)\s*км/u', $desc, $m)) {
            return str_replace(',', '.', $m[1]) . 'км';
        }
        return '';
    }
}
