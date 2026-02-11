/**
 * Server-Sent Events (SSE) — универсальный real-time для чатов и уведомлений
 * Поддерживает admin, ai, coach, direct (для будущих типов)
 * Singleton — одно соединение на приложение
 */

const listeners = new Set();
let eventSource = null;
let unreadData = { total: 0, by_type: {} };

function getSSEUrl() {
  const base = typeof window !== 'undefined' && window.location.origin ? `${window.location.origin}/api` : '/api';
  return `${base}/chat_sse.php`;
}

function parsePayload(data) {
  try {
    const p = JSON.parse(data || '{}');
    const total = typeof p.total === 'number' ? p.total : 0;
    const by_type = p.by_type && typeof p.by_type === 'object' ? p.by_type : {};
    return { total, by_type };
  } catch {
    return { total: 0, by_type: {} };
  }
}

function notifyListeners(data) {
  unreadData = data;
  listeners.forEach((fn) => fn(data));
}

function connect() {
  if (typeof window === 'undefined') return;
  if (eventSource && (eventSource.readyState === EventSource.OPEN || eventSource.readyState === EventSource.CONNECTING)) {
    return;
  }

  const url = getSSEUrl();
  const es = new EventSource(url, { withCredentials: true });

  es.addEventListener('chat_unread', (e) => {
    const data = parsePayload(e.data);
    notifyListeners(data);
  });

  es.onerror = () => {
    es.close();
    eventSource = null;
  };

  eventSource = es;
}

function disconnect() {
  if (eventSource) {
    eventSource.close();
    eventSource = null;
  }
}

function subscribe(callback) {
  listeners.add(callback);
  callback(unreadData);
}

function unsubscribe(callback) {
  listeners.delete(callback);
  if (listeners.size === 0) {
    disconnect();
  }
}

function getUnreadData() {
  return unreadData;
}

function setUnreadData(data) {
  const payload = typeof data === 'object' && data !== null
    ? { total: Number(data.total) || 0, by_type: data.by_type && typeof data.by_type === 'object' ? data.by_type : {} }
    : parsePayload(typeof data === 'string' ? data : JSON.stringify(data || {}));
  notifyListeners(payload);
}

function getUnreadTotal() {
  return unreadData.total;
}

function getUnreadByType(type) {
  return unreadData.by_type?.[type] ?? 0;
}

export const ChatSSE = {
  connect,
  disconnect,
  subscribe,
  unsubscribe,
  getUnreadData,
  getUnreadTotal,
  getUnreadByType,
  setUnreadData,
};
