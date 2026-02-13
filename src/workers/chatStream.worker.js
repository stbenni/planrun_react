/**
 * Web Worker: fetch и чтение стрима ответа ИИ в отдельном потоке.
 * Главный поток не блокируется — парсинг NDJSON и декодирование идут здесь.
 * @see https://developer.mozilla.org/en-US/docs/Web/API/WorkerGlobalScope/fetch
 */

self.onmessage = async (e) => {
  const { type, url, body, token } = e.data || {};
  if (type !== 'start' || !url || body === undefined) {
    return;
  }

  try {
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const response = await fetch(url, {
      method: 'POST',
      headers,
      credentials: 'include',
      body: typeof body === 'string' ? body : JSON.stringify(body),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      self.postMessage({ type: 'error', message: err.error || `HTTP ${response.status}` });
      return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let fullContent = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        try {
          const obj = JSON.parse(trimmed);
          if (obj.error) {
            self.postMessage({ type: 'error', message: obj.error });
            return;
          }
          if (obj.chunk) {
            fullContent += obj.chunk;
            self.postMessage({ type: 'chunk', chunk: obj.chunk });
          }
        } catch (_) {}
      }
    }
    if (buffer.trim()) {
      try {
        const obj = JSON.parse(buffer.trim());
        if (obj.error) {
          self.postMessage({ type: 'error', message: obj.error });
          return;
        }
        if (obj.chunk) {
          fullContent += obj.chunk;
          self.postMessage({ type: 'chunk', chunk: obj.chunk });
        }
      } catch (_) {}
    }

    self.postMessage({ type: 'done', fullContent });
  } catch (err) {
    self.postMessage({
      type: 'error',
      message: err?.message || (err?.name === 'AbortError' ? 'Отменено' : 'Ошибка сети'),
    });
  }
};
