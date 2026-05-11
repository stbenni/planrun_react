const ACTIVE_PLAN_QUEUE_STATUSES = new Set(['pending', 'running']);

export function isActivePlanGenerationStatus(status) {
  if (!status) return false;
  const queueStatus = String(status.queue_status ?? status.status ?? '').toLowerCase();
  return status.generating === true || status.queued === true || ACTIVE_PLAN_QUEUE_STATUSES.has(queueStatus);
}
