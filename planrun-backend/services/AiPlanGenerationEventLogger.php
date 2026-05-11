<?php
/**
 * PR6 / Phase D.1: запись событий генерации планов AI в ai_plan_generation_events.
 *
 * Цель — структурированный observability layer для plan generation:
 *  - bad-plan rate (severity=error after gate / status='failure')
 *  - repair rate (применился safety repair)
 *  - average duration / token usage
 *  - cohort breakdown (healthy vs return_after_injury vs ...)
 *  - model selection rate (deepseek-chat vs deepseek-reasoner)
 *
 * Не путать с AiObservabilityService — тот пишет в ai_runtime_events generic события (chat,
 * tools, recalculate triggers и т.д.). Этот логгер — узко на plan-generation.
 *
 * См. docs/PLANS-AI-V2.md раздел Phase D.1.
 */

require_once __DIR__ . '/BaseService.php';

class AiPlanGenerationEventLogger extends BaseService {
    /**
     * Записать успешное событие генерации плана.
     *
     * @param int $userId ID пользователя.
     * @param string $jobType generate | recalculate | next_plan.
     * @param array $generationMetadata Содержимое `_generation_metadata` итогового плана.
     * @param array $trainingState `training_state` из planner result (для cohort).
     * @param int $durationMs Длительность в миллисекундах.
     * @param string|null $traceId Trace ID для связи с runtime events.
     * @param array $extra Дополнительные поля (token usage, retries и т.п.).
     * @return int|null ID созданной записи (null при отключённом логировании / ошибке).
     */
    public function recordSuccess(
        int $userId,
        string $jobType,
        array $generationMetadata,
        array $trainingState,
        int $durationMs,
        ?string $traceId = null,
        array $extra = []
    ): ?int {
        if (!$this->isEnabled()) {
            return null;
        }

        $row = $this->buildRow($userId, $jobType, $generationMetadata, $trainingState, $durationMs, $traceId, $extra);
        $row['status'] = 'success';

        return $this->insert($row);
    }

    /**
     * Записать неудачное событие генерации плана (исключение / ошибка валидации).
     *
     * @param int $userId
     * @param string $jobType
     * @param Throwable|string $errorOrMessage Исключение или строка-описание.
     * @param array $generationMetadata Метаданные (если есть; иначе пустой массив).
     * @param array $trainingState
     * @param int $durationMs
     * @param string|null $traceId
     * @param array $extra
     * @return int|null
     */
    public function recordFailure(
        int $userId,
        string $jobType,
        $errorOrMessage,
        array $generationMetadata,
        array $trainingState,
        int $durationMs,
        ?string $traceId = null,
        array $extra = []
    ): ?int {
        if (!$this->isEnabled()) {
            return null;
        }

        $row = $this->buildRow($userId, $jobType, $generationMetadata, $trainingState, $durationMs, $traceId, $extra);
        $row['status'] = 'failure';

        if ($errorOrMessage instanceof Throwable) {
            $row['error_code'] = (string) $errorOrMessage->getCode() ?: get_class($errorOrMessage);
            $row['error_message'] = mb_substr((string) $errorOrMessage->getMessage(), 0, 1000);
        } elseif (is_string($errorOrMessage)) {
            $row['error_code'] = 'error';
            $row['error_message'] = mb_substr($errorOrMessage, 0, 1000);
        }

        return $this->insert($row);
    }

    /**
     * Получить последние события — для admin-дашборда и диагностики.
     */
    public function getRecentEvents(int $limit = 50, array $filters = []): array {
        if (!$this->isEnabled()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $where = ['1=1'];
        $params = [];
        $types = '';

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int) $filters['user_id'];
            $types .= 'i';
        }
        if (!empty($filters['cohort'])) {
            $where[] = 'cohort = ?';
            $params[] = (string) $filters['cohort'];
            $types .= 's';
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = (string) $filters['status'];
            $types .= 's';
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at >= ?';
            $params[] = (string) $filters['since'];
            $types .= 's';
        }

        $sql = "SELECT id, created_at, user_id, job_type, surface, cohort,
                       model, model_selection_reason, complexity_score, enable_thinking,
                       planner_strategy, duration_ms, prompt_tokens, completion_tokens, total_tokens,
                       gate_mode, gate_resolved_mode, gate_status, retries,
                       issue_codes, applied_repair_codes, normalizer_warning_codes,
                       status, error_code, error_message,
                       prompt_version, trace_id
                FROM ai_plan_generation_events
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC, id DESC
                LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['issue_codes'] = $this->decodeJsonArray($row['issue_codes'] ?? null);
            $row['applied_repair_codes'] = $this->decodeJsonArray($row['applied_repair_codes'] ?? null);
            $row['normalizer_warning_codes'] = $this->decodeJsonArray($row['normalizer_warning_codes'] ?? null);
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Агрегаты для admin-дашборда (за последние N часов): по cohort'у и по модели.
     *
     * @param int $hours Окно в часах (default 24).
     * @return array {
     *   total: int, success: int, failure: int,
     *   by_cohort: [cohort => {total, success, failure, avg_duration_ms, repair_rate, blocked_rate}],
     *   by_model: [model => {total, success, failure, avg_duration_ms}],
     *   bad_plan_rate: float, repair_rate: float, blocked_rate: float
     * }
     */
    public function getMetricsSummary(int $hours = 24): array {
        if (!$this->isEnabled()) {
            return ['total' => 0, 'by_cohort' => [], 'by_model' => []];
        }

        $hours = max(1, min(24 * 30, $hours));
        $since = (new DateTime("-{$hours} hours"))->format('Y-m-d H:i:s');

        $totalRow = $this->fetchOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_cnt,
                SUM(CASE WHEN status = 'failure' THEN 1 ELSE 0 END) AS failure_cnt,
                SUM(CASE WHEN gate_status = 'blocked' THEN 1 ELSE 0 END) AS blocked_cnt,
                SUM(CASE WHEN JSON_LENGTH(applied_repair_codes) > 0 THEN 1 ELSE 0 END) AS repaired_cnt,
                AVG(duration_ms) AS avg_duration_ms
             FROM ai_plan_generation_events
             WHERE created_at >= ?",
            's',
            [$since]
        );

        $total = (int) ($totalRow['total'] ?? 0);
        $success = (int) ($totalRow['success_cnt'] ?? 0);
        $failure = (int) ($totalRow['failure_cnt'] ?? 0);
        $blocked = (int) ($totalRow['blocked_cnt'] ?? 0);
        $repaired = (int) ($totalRow['repaired_cnt'] ?? 0);

        $byCohort = $this->fetchAll(
            "SELECT cohort,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_cnt,
                    SUM(CASE WHEN status = 'failure' THEN 1 ELSE 0 END) AS failure_cnt,
                    SUM(CASE WHEN gate_status = 'blocked' THEN 1 ELSE 0 END) AS blocked_cnt,
                    SUM(CASE WHEN JSON_LENGTH(applied_repair_codes) > 0 THEN 1 ELSE 0 END) AS repaired_cnt,
                    AVG(duration_ms) AS avg_duration_ms,
                    AVG(complexity_score) AS avg_complexity_score
             FROM ai_plan_generation_events
             WHERE created_at >= ?
             GROUP BY cohort",
            's',
            [$since]
        );

        $byModel = $this->fetchAll(
            "SELECT model,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_cnt,
                    SUM(CASE WHEN status = 'failure' THEN 1 ELSE 0 END) AS failure_cnt,
                    AVG(duration_ms) AS avg_duration_ms,
                    AVG(prompt_tokens) AS avg_prompt_tokens,
                    AVG(completion_tokens) AS avg_completion_tokens
             FROM ai_plan_generation_events
             WHERE created_at >= ?
             GROUP BY model",
            's',
            [$since]
        );

        return [
            'window_hours' => $hours,
            'total' => $total,
            'success' => $success,
            'failure' => $failure,
            'blocked' => $blocked,
            'repaired' => $repaired,
            'avg_duration_ms' => $total > 0 ? (int) round((float) $totalRow['avg_duration_ms']) : null,
            'bad_plan_rate' => $total > 0 ? round(($failure + $blocked) / $total, 4) : 0.0,
            'repair_rate' => $total > 0 ? round($repaired / $total, 4) : 0.0,
            'blocked_rate' => $total > 0 ? round($blocked / $total, 4) : 0.0,
            'failure_rate' => $total > 0 ? round($failure / $total, 4) : 0.0,
            'by_cohort' => array_map([$this, 'enrichAggregateRow'], $byCohort),
            'by_model' => array_map([$this, 'enrichAggregateRow'], $byModel),
        ];
    }

    /**
     * Cohort: производный признак для агрегации.
     * Приоритет: pregnant > injury_return > pain_signal > illness_signal > unrealistic_goal > healthy.
     */
    public function deriveCohort(array $trainingState): string {
        $populationFlags = (array) ($trainingState['special_population_flags'] ?? []);
        if (in_array('pregnant_or_postpartum', $populationFlags, true)) {
            return 'pregnant_or_postpartum';
        }
        if (in_array('return_after_injury', $populationFlags, true)) {
            return 'return_after_injury';
        }

        $scenarioFlags = (array) ($trainingState['planning_scenario']['flags'] ?? []);
        if (in_array('return_after_injury', $scenarioFlags, true)) {
            return 'return_after_injury';
        }
        if (in_array('pain_protective', $scenarioFlags, true) || in_array('recent_pain_signal', $populationFlags, true)) {
            return 'pain_signal';
        }
        if (in_array('illness_protective', $scenarioFlags, true) || in_array('recent_illness_signal', $populationFlags, true)) {
            return 'illness_signal';
        }

        $goalSeverity = (string) ($trainingState['goal_realism']['severity'] ?? '');
        if ($goalSeverity === 'major') {
            return 'unrealistic_goal';
        }

        return 'healthy';
    }

    private function isEnabled(): bool {
        return (int) env('PLAN_AI_EVENT_LOG_ENABLED', '1') === 1;
    }

    /**
     * DeepSeek off-peak window: 16:30–00:30 UTC daily.
     * Reasoner: −75%, V3-chat: −50% during this window.
     * Tag this in metadata so we can compute spend distribution and shift batch jobs to off-peak.
     */
    private static function isDeepSeekOffPeakNow(): bool
    {
        $minutesUtc = (int) gmdate('H') * 60 + (int) gmdate('i');
        // Window is 16:30 (990) to 00:30 next day (30 + 1440 = 1470, but wraps), i.e.
        // [16:30..23:59] OR [00:00..00:30].
        return $minutesUtc >= 990 || $minutesUtc <= 30;
    }

    private function buildRow(
        int $userId,
        string $jobType,
        array $metadata,
        array $trainingState,
        int $durationMs,
        ?string $traceId,
        array $extra
    ): array {
        $cohort = $this->deriveCohort($trainingState);
        $metadata['pricing_tier'] = self::isDeepSeekOffPeakNow() ? 'off_peak' : 'standard';

        $qualityGate = (array) ($metadata['quality_gate'] ?? []);
        $issueCodes = array_values((array) ($qualityGate['issue_codes'] ?? []));
        $repairCodes = array_values(array_map(
            static function ($r) {
                if (is_array($r)) {
                    return (string) ($r['code'] ?? ($r['type'] ?? 'unknown_repair'));
                }
                return (string) $r;
            },
            (array) ($metadata['hard_safety_repairs'] ?? [])
        ));
        $normalizerWarnings = array_values(array_map(
            static function ($w) {
                if (is_array($w)) {
                    return (string) ($w['code'] ?? ($w['type'] ?? 'unknown_warning'));
                }
                return (string) $w;
            },
            (array) ($qualityGate['normalizer_warnings'] ?? [])
        ));

        return [
            'user_id' => $userId,
            'job_type' => mb_substr($jobType, 0, 32),
            'surface' => 'plan_generation',
            'cohort' => $cohort,
            'model' => mb_substr((string) ($metadata['model'] ?? ''), 0, 64),
            'model_selection_reason' => mb_substr((string) ($metadata['model_selection_reason'] ?? 'default'), 0, 64),
            'complexity_score' => (int) ($metadata['model_complexity_score'] ?? 0),
            'enable_thinking' => !empty($metadata['enable_thinking']) ? 1 : 0,
            'planner_strategy' => mb_substr((string) ($metadata['planner_strategy'] ?? 'single_pass'), 0, 32),
            'duration_ms' => $durationMs,
            'prompt_tokens' => isset($extra['prompt_tokens']) ? (int) $extra['prompt_tokens'] : null,
            'completion_tokens' => isset($extra['completion_tokens']) ? (int) $extra['completion_tokens'] : null,
            'total_tokens' => isset($extra['total_tokens']) ? (int) $extra['total_tokens'] : null,
            'prompt_cache_hit_tokens' => isset($extra['prompt_cache_hit_tokens']) ? (int) $extra['prompt_cache_hit_tokens'] : null,
            'prompt_cache_miss_tokens' => isset($extra['prompt_cache_miss_tokens']) ? (int) $extra['prompt_cache_miss_tokens'] : null,
            'gate_mode' => isset($qualityGate['mode_config']) ? mb_substr((string) $qualityGate['mode_config'], 0, 16) : null,
            'gate_resolved_mode' => isset($qualityGate['mode']) ? mb_substr((string) $qualityGate['mode'], 0, 16) : null,
            'gate_status' => isset($qualityGate['status']) ? mb_substr((string) $qualityGate['status'], 0, 16) : null,
            'retries' => isset($metadata['llm_repair_attempts']) ? (int) $metadata['llm_repair_attempts'] : 0,
            'issue_codes' => $issueCodes,
            'applied_repair_codes' => $repairCodes,
            'normalizer_warning_codes' => $normalizerWarnings,
            'prompt_version' => isset($metadata['prompt_version']) ? mb_substr((string) $metadata['prompt_version'], 0, 64) : null,
            'trace_id' => $traceId !== null ? mb_substr($traceId, 0, 64) : null,
            'metadata' => $metadata,
        ];
    }

    private function insert(array $row): ?int {
        $sql = "INSERT INTO ai_plan_generation_events
                (user_id, job_type, surface, cohort, model, model_selection_reason, complexity_score,
                 enable_thinking, planner_strategy, duration_ms, prompt_tokens, completion_tokens,
                 total_tokens, prompt_cache_hit_tokens, prompt_cache_miss_tokens,
                 gate_mode, gate_resolved_mode, gate_status, retries,
                 issue_codes, applied_repair_codes, normalizer_warning_codes,
                 status, error_code, error_message, prompt_version, trace_id, metadata)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            $this->logError('Не удалось подготовить запрос ai_plan_generation_events', ['error' => $this->db->error]);
            return null;
        }

        $issueCodesJson = json_encode($row['issue_codes'], JSON_UNESCAPED_UNICODE);
        $repairCodesJson = json_encode($row['applied_repair_codes'], JSON_UNESCAPED_UNICODE);
        $normalizerJson = json_encode($row['normalizer_warning_codes'], JSON_UNESCAPED_UNICODE);
        $metadataJson = json_encode($row['metadata'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metadataJson === false) {
            $metadataJson = null;
        }

        $userId = $row['user_id'];
        $jobType = $row['job_type'];
        $surface = $row['surface'];
        $cohort = $row['cohort'];
        $model = $row['model'];
        $modelReason = $row['model_selection_reason'];
        $complexity = $row['complexity_score'];
        $thinking = $row['enable_thinking'];
        $strategy = $row['planner_strategy'];
        $duration = $row['duration_ms'];
        $promptTokens = $row['prompt_tokens'];
        $completionTokens = $row['completion_tokens'];
        $totalTokens = $row['total_tokens'];
        $cacheHitTokens = $row['prompt_cache_hit_tokens'] ?? null;
        $cacheMissTokens = $row['prompt_cache_miss_tokens'] ?? null;
        $gateMode = $row['gate_mode'];
        $gateResolved = $row['gate_resolved_mode'];
        $gateStatus = $row['gate_status'];
        $retries = $row['retries'];
        $status = $row['status'] ?? 'success';
        $errorCode = $row['error_code'] ?? null;
        $errorMessage = $row['error_message'] ?? null;
        $promptVersion = $row['prompt_version'];
        $traceId = $row['trace_id'];

        $stmt->bind_param(
            'isssssiisiiiiiisssisssssssss',
            $userId,
            $jobType,
            $surface,
            $cohort,
            $model,
            $modelReason,
            $complexity,
            $thinking,
            $strategy,
            $duration,
            $promptTokens,
            $completionTokens,
            $totalTokens,
            $cacheHitTokens,
            $cacheMissTokens,
            $gateMode,
            $gateResolved,
            $gateStatus,
            $retries,
            $issueCodesJson,
            $repairCodesJson,
            $normalizerJson,
            $status,
            $errorCode,
            $errorMessage,
            $promptVersion,
            $traceId,
            $metadataJson
        );

        if (!$stmt->execute()) {
            $this->logError('Не удалось записать событие ai_plan_generation_events', ['error' => $stmt->error]);
            $stmt->close();
            return null;
        }
        $insertId = (int) $stmt->insert_id;
        $stmt->close();

        return $insertId > 0 ? $insertId : null;
    }

    private function fetchOne(string $sql, string $types, array $params): array {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row;
    }

    private function fetchAll(string $sql, string $types, array $params): array {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function enrichAggregateRow(array $row): array {
        $total = (int) ($row['total'] ?? 0);
        $success = (int) ($row['success_cnt'] ?? 0);
        $failure = (int) ($row['failure_cnt'] ?? 0);
        $blocked = (int) ($row['blocked_cnt'] ?? 0);
        $repaired = (int) ($row['repaired_cnt'] ?? 0);

        $row['total'] = $total;
        $row['success'] = $success;
        $row['failure'] = $failure;
        $row['blocked'] = $blocked;
        $row['repaired'] = $repaired;
        unset($row['success_cnt'], $row['failure_cnt'], $row['blocked_cnt'], $row['repaired_cnt']);

        $row['avg_duration_ms'] = isset($row['avg_duration_ms']) && $row['avg_duration_ms'] !== null
            ? (int) round((float) $row['avg_duration_ms'])
            : null;
        if (array_key_exists('avg_complexity_score', $row)) {
            $row['avg_complexity_score'] = $row['avg_complexity_score'] !== null
                ? round((float) $row['avg_complexity_score'], 2)
                : null;
        }
        if (array_key_exists('avg_prompt_tokens', $row)) {
            $row['avg_prompt_tokens'] = $row['avg_prompt_tokens'] !== null ? (int) round((float) $row['avg_prompt_tokens']) : null;
        }
        if (array_key_exists('avg_completion_tokens', $row)) {
            $row['avg_completion_tokens'] = $row['avg_completion_tokens'] !== null ? (int) round((float) $row['avg_completion_tokens']) : null;
        }

        $row['bad_plan_rate'] = $total > 0 ? round(($failure + $blocked) / $total, 4) : 0.0;
        $row['repair_rate'] = $total > 0 ? round($repaired / $total, 4) : 0.0;
        $row['blocked_rate'] = $total > 0 ? round($blocked / $total, 4) : 0.0;
        $row['failure_rate'] = $total > 0 ? round($failure / $total, 4) : 0.0;

        return $row;
    }

    private function decodeJsonArray($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
