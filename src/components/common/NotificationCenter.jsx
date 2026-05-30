/**
 * NotificationCenter — презентационная панель уведомлений (контент колокольчика).
 * Хедер со счётчиками + «прочитать все», табы-фильтры, группировка по времени,
 * карточки с категорийными аватарами, свайп-влево/крестик для дисмисса, футер.
 * Данные приходят пропсами из useNotificationFeed (агрегатор живёт в NotificationBell).
 */

import { useMemo, useState, useRef } from 'react';
import {
  BotIcon, MessageCircleIcon, MedalIcon, TrendingUpIcon,
  TargetIcon, CalendarIcon, BellIcon, SettingsIcon, CloseIcon, TrashIcon,
} from './Icons';
import { NOTIF_CATEGORY, NOTIF_CATEGORY_ORDER } from './notificationCategories';

const CATEGORY_VISUAL = {
  ai: { tone: 'ai', Icon: BotIcon, text: 'AI' },
  coach: { tone: 'coach', Icon: MessageCircleIcon },
  workout: { tone: 'workout', Icon: TrendingUpIcon },
  achievement: { tone: 'achievement', Icon: MedalIcon },
  race: { tone: 'race', Icon: TargetIcon },
  plan: { tone: 'plan', Icon: CalendarIcon },
  system: { tone: 'system', Icon: BellIcon },
};

function pluralRu(n, one, few, many) {
  const m10 = n % 10;
  const m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
  return many;
}

function formatTimeAgo(date) {
  const diff = Date.now() - date.getTime();
  const min = Math.floor(diff / 60000);
  if (min < 1) return 'сейчас';
  if (min < 60) return `${min} мин`;
  const h = Math.floor(min / 60);
  if (h < 24) return `${h} ч`;
  const d = Math.floor(h / 24);
  if (d === 1) return 'вчера';
  if (d < 7) return `${d} ${pluralRu(d, 'день', 'дня', 'дней')}`;
  return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

function dayBucket(date) {
  const now = new Date();
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const t = date.getTime();
  if (t >= startToday) return 'today';
  if (t >= startToday - 86400000) return 'yesterday';
  return 'earlier';
}

const BUCKET_LABEL = { today: 'Сегодня', yesterday: 'Вчера', earlier: 'Ранее' };
const SWIPE_DISMISS_PX = 80;

function NotifCard({ item, onOpen, onDismiss }) {
  const [dx, setDx] = useState(0);
  const [dragging, setDragging] = useState(false);
  const [dismissing, setDismissing] = useState(false);
  const startX = useRef(null);
  const moved = useRef(false);

  const vis = CATEGORY_VISUAL[item.category] || CATEGORY_VISUAL.system;
  const Icon = vis.Icon;

  const triggerDismiss = () => {
    setDismissing(true);
    setTimeout(() => onDismiss(item), 200);
  };

  const onTouchStart = (e) => { startX.current = e.touches[0].clientX; moved.current = false; setDragging(true); };
  const onTouchMove = (e) => {
    if (startX.current == null) return;
    const d = e.touches[0].clientX - startX.current;
    if (Math.abs(d) > 6) moved.current = true;
    setDx(Math.max(-160, Math.min(0, d)));
  };
  const onTouchEnd = () => {
    setDragging(false);
    startX.current = null;
    if (dx <= -SWIPE_DISMISS_PX) triggerDismiss();
    else setDx(0);
  };

  const handleClick = () => { if (!moved.current && !dismissing) onOpen(item); };

  return (
    <div className={`notif-card-wrap ${dismissing ? 'is-dismissing' : ''}`}>
      <div className="notif-card-wrap__bg" aria-hidden><TrashIcon size={18} /></div>
      <div
        role="button"
        tabIndex={0}
        className={`notif-card ${item.read ? '' : 'is-unread'} ${dragging ? 'is-dragging' : ''}`}
        style={{ transform: dx ? `translateX(${dx}px)` : undefined }}
        onClick={handleClick}
        onKeyDown={(e) => { if (e.key === 'Enter') handleClick(); }}
        onTouchStart={onTouchStart}
        onTouchMove={onTouchMove}
        onTouchEnd={onTouchEnd}
      >
        <span className={`notif-card__avatar notif-card__avatar--${vis.tone}`} aria-hidden>
          {vis.text ? vis.text : <Icon size={18} />}
        </span>
        <span className="notif-card__content">
          <span className="notif-card__title">{item.title}</span>
          {item.body && <span className="notif-card__body">{item.body}</span>}
          <span className="notif-card__meta">
            <span className="notif-card__time">{formatTimeAgo(item.time)}</span>
            <span className="notif-card__action">{item.actionLabel}</span>
          </span>
        </span>
        {!item.read && <span className="notif-card__dot" aria-hidden />}
        <button
          type="button"
          className="notif-card__dismiss"
          aria-label="Убрать уведомление"
          onClick={(e) => { e.stopPropagation(); triggerDismiss(); }}
        >
          <CloseIcon size={15} />
        </button>
      </div>
    </div>
  );
}

export default function NotificationCenter({ items, counts, markRead, markAllRead, dismiss, dismissAll, onNavigate, onOpenSettings }) {
  const [filter, setFilter] = useState('all');

  const tabs = useMemo(() => {
    const base = [
      { key: 'all', label: 'Все', count: counts.total },
      { key: 'unread', label: 'Новые', count: counts.unread },
    ];
    for (const cat of NOTIF_CATEGORY_ORDER) {
      const c = counts.byCategory[cat];
      if (c) base.push({ key: cat, label: NOTIF_CATEGORY[cat].label, count: c });
    }
    return base;
  }, [counts]);

  const filtered = useMemo(() => {
    if (filter === 'all') return items;
    if (filter === 'unread') return items.filter((it) => !it.read);
    return items.filter((it) => it.category === filter);
  }, [items, filter]);

  const groups = useMemo(() => {
    const g = { today: [], yesterday: [], earlier: [] };
    for (const it of filtered) g[dayBucket(it.time)].push(it);
    return g;
  }, [filtered]);

  const handleOpen = (item) => {
    markRead(item);
    if (item.link) onNavigate(item.link);
  };

  const readCount = counts.total - counts.unread;

  return (
    <div className="notif-center">
      <div className="notif-center__head">
        <div className="notif-center__head-text">
          <div className="notif-center__eyebrow">УВЕДОМЛЕНИЯ</div>
          <div className="notif-center__summary">
            <b>{counts.unread}</b> {pluralRu(counts.unread, 'новое', 'новых', 'новых')}
            {readCount > 0 && <span className="notif-center__summary-muted"> · {readCount} прочитано</span>}
          </div>
        </div>
        {counts.unread > 0 && (
          <button type="button" className="notif-center__readall" onClick={markAllRead}>
            Прочитать все
          </button>
        )}
      </div>

      <div className="notif-center__tabs" role="tablist">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            role="tab"
            aria-selected={filter === t.key}
            className={`notif-center__tab ${filter === t.key ? 'is-active' : ''}`}
            onClick={() => setFilter(t.key)}
          >
            {t.label} <span className="notif-center__tab-count">· {t.count}</span>
          </button>
        ))}
      </div>

      <div className="notif-center__body">
        {filtered.length === 0 ? (
          <div className="notif-center__empty">
            <BellIcon size={28} />
            <span>{filter === 'unread' ? 'Нет новых уведомлений' : 'Уведомлений пока нет'}</span>
          </div>
        ) : (
          ['today', 'yesterday', 'earlier'].map((bucket) => (
            groups[bucket].length > 0 && (
              <div key={bucket} className="notif-center__group">
                <div className="notif-center__group-label">{BUCKET_LABEL[bucket]}</div>
                {groups[bucket].map((item) => (
                  <NotifCard key={item.id} item={item} onOpen={handleOpen} onDismiss={dismiss} />
                ))}
              </div>
            )
          ))
        )}
      </div>

      <div className="notif-center__footer">
        <button type="button" className="notif-center__settings" onClick={onOpenSettings}>
          <SettingsIcon size={16} />
          Настройки уведомлений
        </button>
        {items.length > 0 && (
          <button type="button" className="notif-center__clearall" onClick={dismissAll}>
            Очистить всё
          </button>
        )}
      </div>
    </div>
  );
}
