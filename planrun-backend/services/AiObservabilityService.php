<?php

require_once __DIR__ . '/BaseService.php';

class AiObservabilityService extends BaseService {
    private static bool $schemaEnsured = false;

    public function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS ai_runtime_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL DEFAULT NULL,
            surface VARCHAR(32) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'ok',
            trace_id VARCHAR(64) NOT NULL,
            duration_ms INT UNSIGNED NULL DEFAULT NULL,
            payload_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_runtime_events_trace (trace_id),
            INDEX idx_ai_runtime_events_surface (surface, created_at),
            INDEX idx_ai_runtime_events_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->db->query($sql)) {
            $this->logError('Не удалось создать ai_runtime_events', ['error' => $this->db->error]);
            return;
        }

        self::$schemaEnsured = true;
    }

    public function createTraceId(string $surface): string {
        $prefix = preg_replace('/[^a-z0-9_]+/i', '-', strtolower(trim($surface))) ?: 'ai';
        return $prefix . '-' . bin2hex(random_bytes(6));
    }

    public function logEvent(string $surface, string $eventType, string $status = 'ok', array $payload = [], ?int $userId = null, ?string $traceId = null, ?int $durationMs = null): void {
        if ((int) env('AI_OBSERVABILITY_ENABLED', 1) !== 1) {
            return;
        }

        $this->ensureSchema();

        $safeTraceId = $traceId ?: $this->createTraceId($surface);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = null;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO ai_runtime_events
                (user_id, surface, event_type, status, trace_id, duration_ms, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param(
            'issssis',
            $userId,
            $surface,
            $eventType,
            $status,
            $safeTraceId,
            $durationMs,
            $json
        );
        $stmt->execute();
        $stmt->close();
    }
}
