/**
 * Хук для real-time счётчика непрочитанных сообщений
 * Универсальный формат: { total, by_type: { admin, ai, coach, direct, ... } }
 * Использует SSE (Server-Sent Events)
 */

import { useState, useEffect } from 'react';
import { ChatSSE } from '../services/ChatSSE';

export function useChatUnread() {
  const [data, setData] = useState(() => ChatSSE.getUnreadData());

  useEffect(() => {
    ChatSSE.connect();
    const cb = (payload) => setData(payload);
    ChatSSE.subscribe(cb);
    return () => ChatSSE.unsubscribe(cb);
  }, []);

  return data;
}
