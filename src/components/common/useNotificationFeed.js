/**
 * useNotificationFeed — фид notification center из единого store (plan_notifications).
 * Все события, включая чат (свёрнуто по диалогу), приходят одной выдачей —
 * без отдельного поллинга чата. Нормализует, считает счётчики по категориям,
 * отдаёт mark read / mark all / dismiss.
 */

import { useState, useCallback, useEffect, useRef } from 'react';
import { ChatSSE } from '../../services/ChatSSE';
import {
  categoryForType,
  defaultTitleForCategory,
  defaultActionForCategory,
} from './notificationCategories';

const REFRESH_MS = 60000;

function toDate(v) {
  if (!v) return new Date(0);
  const d = new Date(typeof v === 'number' ? v : String(v).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? new Date(0) : d;
}

function normalizeRow(n) {
  const meta = (n.metadata && typeof n.metadata === 'object') ? n.metadata : {};
  const category = meta.category || categoryForType(n.type, 'plan');
  const link = meta.link
    || (meta.date ? `/calendar?date=${encodeURIComponent(meta.date)}` : '/calendar');
  return {
    id: `plan_${n.id}`,
    rawId: n.id,
    source: 'plan',
    conversationId: meta.conversation_id || null,
    category,
    title: meta.title || defaultTitleForCategory(category),
    body: meta.body || n.message || '',
    time: toDate(n.created_at),
    read: !!n.read_at,
    link,
    actionLabel: meta.action_label || defaultActionForCategory(category),
  };
}

export function useNotificationFeed(api) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const cooldownRef = useRef(0);
  const dismissedRef = useRef(new Set());

  const load = useCallback(async () => {
    if (!api) return;
    if (Date.now() < cooldownRef.current) return;
    setLoading(true);
    try {
      const [planRes, dismissedRes] = await Promise.all([
        api.getPlanNotifications({ includeRead: true, limit: 50 }).catch(() => null),
        api.getNotificationsDismissed().catch(() => null),
      ]);

      const dismissed = new Set(Array.isArray(dismissedRes) ? dismissedRes : []);
      dismissedRef.current = dismissed;

      const planList = planRes?.data?.notifications ?? planRes?.notifications ?? [];
      const out = planList.map(normalizeRow);

      const visible = out.filter((it) => !dismissed.has(it.id));
      visible.sort((a, b) => b.time - a.time);
      setItems(visible);
    } catch (e) {
      if (e?.status === 429) {
        cooldownRef.current = Date.now() + (Number(e?.retry_after) || 60) * 1000;
      }
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    load();
    const t = setInterval(load, REFRESH_MS);
    // Real-time: новое чат-сообщение меняет chat_unread по SSE → обновляем фид сразу.
    const onSse = () => load();
    ChatSSE.subscribe(onSse);
    return () => { clearInterval(t); ChatSSE.unsubscribe(onSse); };
  }, [load]);

  const markRead = useCallback((item) => {
    if (!item || item.read) return;
    setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, read: true } : it)));
    api?.markPlanNotificationRead(item.rawId).catch(() => {});
    // Чат-запись: гасим и непрочитанное самого диалога (синхронизация двух read-моделей).
    if (item.conversationId) {
      api?.chatMarkRead(item.conversationId).catch(() => {});
    }
  }, [api]);

  const markAllRead = useCallback(() => {
    setItems((prev) => prev.map((it) => ({ ...it, read: true })));
    api?.markAllPlanNotificationsRead().catch(() => {});
    // Чат-источники помечаются прочитанными при открытии чата; здесь не трогаем
    // их badge'и, чтобы не сбрасывать непрочитанное в самих диалогах неожиданно.
  }, [api]);

  const dismiss = useCallback((item) => {
    if (!item) return;
    dismissedRef.current.add(item.id);
    setItems((prev) => prev.filter((it) => it.id !== item.id));
    api?.dismissNotification(item.id).catch(() => {});
  }, [api]);

  const dismissAll = useCallback(() => {
    setItems((prev) => {
      for (const it of prev) {
        dismissedRef.current.add(it.id);
        api?.dismissNotification(it.id).catch(() => {});
      }
      return [];
    });
  }, [api]);

  const total = items.length;
  const unread = items.reduce((s, it) => s + (it.read ? 0 : 1), 0);
  const byCategory = items.reduce((acc, it) => {
    acc[it.category] = (acc[it.category] || 0) + 1;
    return acc;
  }, {});

  return { items, loading, counts: { total, unread, byCategory }, markRead, markAllRead, dismiss, dismissAll, refresh: load };
}
