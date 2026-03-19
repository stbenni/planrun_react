export function getAuthErrorMessage(error, fallback = 'Произошла ошибка. Попробуйте позже.') {
  if (!error) return fallback;

  const message = String(error.message || fallback).trim() || fallback;
  const retryAfter = getAuthRetryAfter(error);
  const isRateLimited = error.status === 429 || error.code === 'RATE_LIMITED';

  if (isRateLimited && Number.isFinite(retryAfter) && retryAfter > 0 && !/через\s+\d+\s+сек/i.test(message)) {
    return `${message} Подождите ${retryAfter} сек.`;
  }

  return message;
}

export function getAuthRetryAfter(error) {
  if (!error) return 0;

  const direct = Number(error.retry_after);
  if (Number.isFinite(direct) && direct > 0) {
    return Math.ceil(direct);
  }

  const match = String(error.message || '').match(/через\s+(\d+)\s+сек/i);
  if (match) {
    return Number(match[1]) || 0;
  }

  return 0;
}
