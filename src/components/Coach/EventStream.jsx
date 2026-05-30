/**
 * EventStream — лента событий тренера (view 'stream' в CoachWorkspace).
 *
 * События приходят из API /coach_events и хранятся в useCoachStore.events.
 * Каждая карточка: avatar с ring цвета tone, имя + время, заголовок + детали,
 * CTA-кнопка справа (для риск/вопрос/upload).
 */

import { useEffect, useRef, useState } from 'react';
import {
  UploadIcon, AlertTriangleIcon, HelpCircleIcon, TrophyIcon,
} from '../common/Icons';
import { CoachAvatar, TONE } from './CoachPrimitives';
import './EventStream.css';

const KIND_ICON = {
  upload: UploadIcon,
  risk: AlertTriangleIcon,
  warn: AlertTriangleIcon,
  question: HelpCircleIcon,
  pr: TrophyIcon,
};

function formatRelativeTime(iso) {
  if (!iso) return '';
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return '';
  const secAgo = Math.floor((Date.now() - t) / 1000);
  if (secAgo < 60) return 'только что';
  if (secAgo < 3600) return `${Math.floor(secAgo / 60)} мин назад`;
  if (secAgo < 86400) return `${Math.floor(secAgo / 3600)} ч назад`;
  if (secAgo < 86400 * 7) return `${Math.floor(secAgo / 86400)} дн назад`;
  const d = new Date(t);
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

export default function EventStream({ events, onOpenAthlete, onCta }) {
  // Запоминаем id событий из предыдущего рендера — новые получают slide-in класс.
  const seenIdsRef = useRef(new Set());
  const [newIds, setNewIds] = useState(() => new Set());

  useEffect(() => {
    if (!Array.isArray(events)) return;
    const prevSeen = seenIdsRef.current;
    const justAdded = events.filter((e) => !prevSeen.has(e.id)).map((e) => e.id);
    // На первый рендер не анимируем (только при следующих refresh)
    if (prevSeen.size === 0) {
      seenIdsRef.current = new Set(events.map((e) => e.id));
      return;
    }
    if (justAdded.length === 0) {
      seenIdsRef.current = new Set(events.map((e) => e.id));
      return;
    }
    setNewIds(new Set(justAdded));
    seenIdsRef.current = new Set(events.map((e) => e.id));
    // Сбросить флаг через 600мс после анимации
    const id = setTimeout(() => setNewIds(new Set()), 600);
    return () => clearTimeout(id);
  }, [events]);

  if (!events || events.length === 0) {
    return (
      <div className="coach-stream-empty">
        <div className="coach-stream-empty__icon" aria-hidden>🌤</div>
        <div className="coach-stream-empty__title">Пока тихо</div>
        <div className="coach-stream-empty__text">Новые тренировки атлетов, риски и вопросы появятся здесь.</div>
      </div>
    );
  }

  return (
    <div className="coach-stream">
      {events.map((ev) => {
        const t = TONE[ev.tone] || TONE.primary;
        const fakeAthlete = {
          id: ev.athlete_id,
          username: ev.athlete_username,
          avatar_path: ev.athlete_avatar_path,
        };
        const isNew = newIds.has(ev.id);
        return (
          <button
            key={ev.id}
            type="button"
            className={`coach-stream__card ${isNew ? 'coach-stream__card--new' : ''}`}
            onClick={() => onOpenAthlete?.(ev.athlete_id)}
          >
            <CoachAvatar athlete={fakeAthlete} size={44} ring={t.solid} />
            <div className="coach-stream__body">
              <div className="coach-stream__row1">
                <span className="coach-stream__name">{ev.athlete_username}</span>
                <span className="coach-stream__time">{formatRelativeTime(ev.created_at)}</span>
              </div>
              <div className="coach-stream__title">
                <span
                  className="coach-stream__icon"
                  style={{ background: t.bg, color: t.color }}
                  aria-hidden
                >
                  {(() => {
                    const IconCmp = KIND_ICON[ev.kind];
                    return IconCmp ? <IconCmp size={14} /> : null;
                  })()}
                </span>
                {ev.title}
              </div>
              {ev.detail && <div className="coach-stream__detail">{ev.detail}</div>}
            </div>
            {ev.cta_label && (
              <button
                type="button"
                className="coach-stream__cta"
                style={{ background: t.solid }}
                onClick={(e) => {
                  e.stopPropagation();
                  onCta?.(ev);
                }}
              >
                {ev.cta_label} →
              </button>
            )}
          </button>
        );
      })}
    </div>
  );
}
