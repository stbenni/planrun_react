/**
 * Server-Sent Events (SSE) — универсальный real-time для чатов и уведомлений
 * Поддерживает admin, ai, coach, direct (для будущих типов)
 * Singleton — одно соединение на приложение
 */

const listeners = new Set();
let eventSource = null;
let unreadData = { total: 0, by_type: {} };
let reconnectTimer = null;
let reconnectDelay = 1000;
const MAX_RECONNECT_DELAY = 30000;

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

function scheduleReconnect() {
  if (reconnectTimer) return;
  if (listeners.size === 0) return;
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    if (listeners.size > 0) connect();
  }, reconnectDelay);
  reconnectDelay = Math.min(reconnectDelay * 2, MAX_RECONNECT_DELAY);
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

  es.onopen = () => {
    reconnectDelay = 1000;
  };

  es.onerror = () => {
    es.close();
    eventSource = null;
    scheduleReconnect();
  };

  eventSource = es;
}

function disconnect() {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }
  reconnectDelay = 1000;
  if (eventSource) {
    eventSource.close();
    eventSource = null;
  }
}

function subscribe(callback) {
  const wasEmpty = listeners.size === 0;
  listeners.add(callback);
  callback(unreadData);
  if (wasEmpty) connect();
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
