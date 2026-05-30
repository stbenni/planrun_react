/**
 * useNotificationFeed — агрегатор уведомлений для notification center.
 * Источники: plan_notifications (read+unread, единое событийное хранилище),
 * AI-сообщения и тренерские сообщения из чата. Нормализует всё в общий вид,
 * считает счётчики по категориям и отдаёт действия mark read / mark all.
 */

import { useState, useCallback, useEffect, useRef } from 'react';
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

function normalizePlan(n) {
  const meta = (n.metadata && typeof n.metadata === 'object') ? n.metadata : {};
  const category = categoryForType(n.type, 'plan');
  const link = meta.link
    || (meta.date ? `/calendar?date=${encodeURIComponent(meta.date)}` : '/calendar');
  return {
    id: `plan_${n.id}`,
    rawId: n.id,
    source: 'plan',
    category,
    title: meta.title || defaultTitleForCategory(category),
    body: meta.body || n.message || '',
    time: toDate(n.created_at),
    read: !!n.read_at,
    link,
    actionLabel: meta.action_label || defaultActionForCategory(category),
  };
}

function normalizeAi(m) {
  return {
    id: `ai_${m.id}`,
    rawId: m.id,
    source: 'ai',
    conversationId: m.conversation_id || null,
    category: 'ai',
    title: 'AI-тренер',
    body: m.content || '',
    time: toDate(m.created_at),
    read: !!m.read_at,
    link: '/chat',
    actionLabel: defaultActionForCategory('ai'),
  };
}

function normalizeCoach(m) {
  const who = m.sender_username || m.username;
  return {
    id: `coach_${m.id}`,
    rawId: m.id,
    source: 'coach',
    conversationId: m.conversation_id || null,
    category: 'coach',
    title: who ? `${who} написал` : 'Сообщение от тренера',
    body: m.content || '',
    time: toDate(m.created_at),
    read: !!m.read_at,
    link: '/chat',
    actionLabel: defaultActionForCategory('coach'),
  };
}

export function useNotificationFeed(api, user, isAdmin) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const cooldownRef = useRef(0);
  const dismissedRef = useRef(new Set());

  const load = useCallback(async () => {
    if (!api) return;
    if (Date.now() < cooldownRef.current) return;
    setLoading(true);
    try {
      const tasks = [
        api.getPlanNotifications({ includeRead: true, limit: 50 }).catch(() => null),
        api.getNotificationsDismissed().catch(() => null),
      ];
      if (!isAdmin) {
        tasks.push(api.chatGetMessages('ai', 10, 0).catch(() => null));
        tasks.push(api.chatGetMessages('admin', 10, 0).catch(() => null));
      }
      const [planRes, dismissedRes, aiRes, coachRes] = await Promise.all(tasks);

      const dismissed = new Set(Array.isArray(dismissedRes) ? dismissedRes : []);
      dismissedRef.current = dismissed;

      const out = [];

      const planList = planRes?.data?.notifications ?? planRes?.notifications ?? [];
      for (const n of planList) out.push(normalizePlan(n));

      if (!isAdmin) {
        const aiList = Array.isArray(aiRes?.messages) ? aiRes.messages : [];
        for (const m of aiList) {
          if (m.sender_type === 'ai') out.push(normalizeAi(m));
        }
        const coachList = Array.isArray(coachRes?.messages) ? coachRes.messages : [];
        for (const m of coachList) {
          if (m.sender_type === 'admin' || (m.sender_type === 'user' && m.sender_id !== user?.id)) {
            out.push(normalizeCoach(m));
          }
        }
      }

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
  }, [api, isAdmin, user?.id]);

  useEffect(() => {
    load();
    const t = setInterval(load, REFRESH_MS);
    return () => clearInterval(t);
  }, [load]);

  const markRead = useCallback((item) => {
    if (!item || item.read) return;
    setItems((prev) => prev.map((it) => (it.id === item.id ? { ...it, read: true } : it)));
    if (item.source === 'plan') {
      api?.markPlanNotificationRead(item.rawId).catch(() => {});
    } else if (item.conversationId) {
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
