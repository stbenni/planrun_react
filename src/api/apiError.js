class ApiError extends Error {
  constructor({ code, message, attempts_left, status, retry_after }) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.message = message;
    this.attempts_left = attempts_left;
    this.status = status;
    this.retry_after = retry_after;
  }
}

function extractRetryAfter(response, data, fallbackMessage = '') {
  const headerValue = response?.headers?.get?.('retry-after');
  const headerSeconds = Number(headerValue);
  if (Number.isFinite(headerSeconds) && headerSeconds > 0) {
    return headerSeconds;
  }

  const bodySeconds = Number(data?.retry_after);
  if (Number.isFinite(bodySeconds) && bodySeconds > 0) {
    return bodySeconds;
  }

  const match = String(fallbackMessage || '').match(/через\s+(\d+)\s+сек/i);
  if (match) {
    return Number(match[1]) || undefined;
  }

  return undefined;
}

function buildApiError({ response, data, code, message, attempts_left }) {
  const finalMessage = data?.error || data?.message || message;
  const status = response?.status;
  const retry_after = extractRetryAfter(response, data, finalMessage);

  return new ApiError({
    code,
    message: finalMessage,
    attempts_left: attempts_left ?? data?.attempts_left,
    status,
    retry_after,
  });
}

export { ApiError, extractRetryAfter, buildApiError };
