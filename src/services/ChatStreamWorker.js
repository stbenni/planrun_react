/**
 * Запуск стрима ответа ИИ через Web Worker — главный поток не занят чтением стрима.
 * Fetch и парсинг NDJSON выполняются в отдельном потоке.
 */

/**
 * @param {object} api - ApiClient (baseUrl, getToken())
 * @param {string} content - текст сообщения
 * @param {function(string): void} onChunk - вызывается при каждом чанке (уже на главном потоке)
 * @param {{ timeoutMs?: number }} opts
 * @returns {Promise<string>} полный ответ ИИ
 */
export function runChatStreamInWorker(api, content, onChunk, opts = {}) {
  const { timeoutMs = 180000, onPlanUpdated } = opts;

  return new Promise(async (resolve, reject) => {
    let token;
    try {
      token = await api.getToken();
    } catch (e) {
      reject(e);
      return;
    }

    const url = `${api.baseUrl}/api_wrapper.php?action=chat_send_message_stream`;
    const body = JSON.stringify({ content: (content || '').trim() });

    const worker = new Worker(
      new URL('../workers/chatStream.worker.js', import.meta.url)
    );

    const timeoutId = setTimeout(() => {
      worker.terminate();
      reject(new Error('Таймаут ответа ИИ'));
    }, timeoutMs);

    worker.onmessage = (e) => {
      const { type, chunk, fullContent, message } = e.data || {};
      if (type === 'chunk' && typeof onChunk === 'function') {
        onChunk(chunk);
      } else if (type === 'plan_updated' && typeof onPlanUpdated === 'function') {
        onPlanUpdated();
      } else if (type === 'done') {
        clearTimeout(timeoutId);
        worker.terminate();
        resolve(fullContent || '');
      } else if (type === 'error') {
        clearTimeout(timeoutId);
        worker.terminate();
        reject(new Error(message || 'Ошибка чата'));
      }
    };

    worker.onerror = (e) => {
      clearTimeout(timeoutId);
      worker.terminate();
      reject(new Error(e.message || 'Ошибка воркера'));
    };

    worker.postMessage({ type: 'start', url, body, token });
  });
}
