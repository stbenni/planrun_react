/**
 * Хук для real-time счётчика непрочитанных сообщений
 * Универсальный формат: { total, by_type: { admin, ai, coach, direct, ... } }
 * Использует SSE (Server-Sent Events)
 */

import { useState, useEffect, useRef } from 'react';
import { ChatSSE } from '../services/ChatSSE';

function sameUnread(a, b) {
  if (a === b) return true;
  if (!a || !b) return false;
  if (a.total !== b.total) return false;
  const aKeys = Object.keys(a.by_type || {});
  const bKeys = Object.keys(b.by_type || {});
  if (aKeys.length !== bKeys.length) return false;
  for (const k of aKeys) {
    if ((a.by_type[k] ?? 0) !== (b.by_type[k] ?? 0)) return false;
  }
  return true;
}

export function useChatUnread() {
  const [data, setData] = useState(() => ChatSSE.getUnreadData());
  const prevRef = useRef(data);

  useEffect(() => {
    const cb = (payload) => {
      if (sameUnread(prevRef.current, payload)) return;
      prevRef.current = payload;
      setData(payload);
    };
    ChatSSE.subscribe(cb);
    return () => ChatSSE.unsubscribe(cb);
  }, []);

  return data;
}
