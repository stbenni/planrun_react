#!/usr/bin/env php
<?php
/**
 * Заливает в Suunto тренировки из очереди suunto_upload_queue (PlanRun → Suunto зеркало).
 *
 * Cron (например каждые 2-5 минут):
 *   php /path/to/planrun-backend/scripts/suunto_upload_worker.php
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/providers/SuuntoProvider.php';

if ((int) env('SUUNTO_UPLOAD_WORKER_ENABLED', 1) !== 1) {
    echo "SuuntoUploadWorker disabled\n";
    exit(0);
}

$lockPath = sys_get_temp_dir() . '/planrun-suunto-upload-worker.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "SuuntoUploadWorker already running\n";
    exit(0);
}

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$limit = (int) env('SUUNTO_UPLOAD_BATCH', 10);
$maxIter = (int) env('SUUNTO_UPLOAD_MAX_ITER', 50);
$delayMs = (int) env('SUUNTO_UPLOAD_DELAY_MS', 3000); // пауза между заливками (анти-троттлинг Suunto)
$maxAttempts = 3;
$provider = new SuuntoProvider($db);

$total = 0;
for ($iter = 0; $iter < $maxIter; $iter++) {
$res = $db->query("SELECT id, user_id, workout_id, attempts FROM suunto_upload_queue
                   WHERE status IN ('pending','error') AND attempts < $maxAttempts ORDER BY id ASC LIMIT $limit");
if (!$res) { fwrite(STDERR, "query failed: " . $db->error . "\n"); exit(1); }

$rows = [];
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
if (empty($rows)) { break; }
$total += count($rows);

foreach ($rows as $job) {
    $jid = (int)$job['id'];
    $uid = (int)$job['user_id'];
    $wid = (int)$job['workout_id'];
    $attempts = (int)$job['attempts'] + 1;

    $db->query("UPDATE suunto_upload_queue SET status='processing', attempts=$attempts WHERE id=$jid");

    try {
        $r = $provider->uploadWorkout($uid, $wid);
    } catch (Throwable $e) {
        $r = ['status' => 'ERROR', 'workoutKey' => null, 'message' => $e->getMessage()];
    }

    $status = $r['status'] ?? 'ERROR';
    $key = $r['workoutKey'] ?? null;
    $msg = substr((string)($r['message'] ?? ''), 0, 250);

    if ($status === 'PROCESSED' || $status === 'SKIPPED') {
        $final = ($status === 'PROCESSED') ? 'done' : 'skipped';
        $stmt = $db->prepare("UPDATE suunto_upload_queue SET status=?, suunto_workout_key=?, last_error=NULL WHERE id=?");
        $stmt->bind_param('ssi', $final, $key, $jid);
        $stmt->execute(); $stmt->close();
        echo "  job#$jid w$wid u$uid → $final" . ($key ? " key=$key" : "") . "\n";
    } else {
        // повтор позже, либо окончательная ошибка после maxAttempts
        $final = ($attempts >= $maxAttempts) ? 'error' : 'pending';
        $stmt = $db->prepare("UPDATE suunto_upload_queue SET status=?, last_error=? WHERE id=?");
        $stmt->bind_param('ssi', $final, $msg, $jid);
        $stmt->execute(); $stmt->close();
        echo "  job#$jid w$wid u$uid → $final ($msg)\n";
    }
    if ($delayMs > 0) { usleep($delayMs * 1000); }
}
}
echo "done. processed=$total\n";
