/**
 * Мелкие блоки дашборда v3B: чип режима, колокольчик, метрика-плитка,
 * точки недели, интервал-бар. Вёрстка — по мокам r3-b.jsx (BModeChip,
 * BMetricTile, BWeekDots, BIntervalBar).
 */

import { useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { PrIcon, PrLabel, PrSpark } from '../../ui';
import { useNotificationFeed } from '../../common/useNotificationFeed';
import NotificationCenter from '../../common/NotificationCenter';
import { MODE_LABEL, MODE_GLYPH } from './dashData';
import '../../common/NotificationBell.css';
import '../../common/NotificationCenter.css';

export function ModeChip({ mode = 'ai', onClick }) {
  return (
    <button
      type="button"
      className="pr-press"
      onClick={onClick}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 7,
        border: '1px solid var(--pr-card-border)',
        background: 'var(--pr-card)',
        borderRadius: 999,
        padding: '5px 11px 5px 6px',
        cursor: 'pointer',
      }}
    >
      <span
        style={{
          width: 20,
          height: 20,
          borderRadius: 999,
          background: mode === 'self' ? 'var(--pr-card-2)' : 'var(--pr-grad)',
          border: mode === 'self' ? '1px solid var(--pr-card-border)' : 'none',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          fontSize: 8,
          fontWeight: 800,
          color: mode === 'self' ? 'var(--pr-ink)' : '#fff',
          fontFamily: 'var(--pr-font-display)',
        }}
      >
        {MODE_GLYPH[mode] || 'AI'}
      </span>
      <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--pr-ink)', fontFamily: 'var(--pr-font-body)' }}>
        {MODE_LABEL[mode] || MODE_LABEL.ai}
      </span>
      <svg width="9" height="9" viewBox="0 0 10 10" fill="none" stroke="var(--pr-sub)" strokeWidth="2" strokeLinecap="round">
        <path d="M2 3.5l3 3 3-3" />
      </svg>
    </button>
  );
}

/** Колокольчик v3B: логика NotificationBell (фид + панель), новый вид кнопки. */
export function DashBell({ api, size = 19 }) {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [anchor, setAnchor] = useState({ top: 64, right: 16 });
  const btnRef = useRef(null);
  const panelRef = useRef(null);
  const { items, counts, markRead, markAllRead, dismiss, dismissAll } = useNotificationFeed(api);

  const close = useCallback(() => setOpen(false), []);
  const toggle = useCallback(() => {
    setOpen((prev) => {
      const next = !prev;
      if (next && btnRef.current) {
        const r = btnRef.current.getBoundingClientRect();
        setAnchor({ top: r.bottom + 8, right: Math.max(8, window.innerWidth - r.right) });
      }
      return next;
    });
  }, []);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const onPointer = (e) => {
      if (
        panelRef.current && !panelRef.current.contains(e.target) &&
        btnRef.current && !btnRef.current.contains(e.target)
      ) close();
    };
    document.addEventListener('keydown', onKey);
    const t = setTimeout(() => document.addEventListener('pointerdown', onPointer), 0);
    return () => {
      document.removeEventListener('keydown', onKey);
      clearTimeout(t);
      document.removeEventListener('pointerdown', onPointer);
    };
  }, [open, close]);

  const handleNavigate = useCallback((link) => {
    close();
    if (link) navigate(link);
  }, [close, navigate]);

  const handleOpenSettings = useCallback(() => {
    close();
    navigate('/settings?tab=notifications');
  }, [close, navigate]);

  const portalTarget = typeof document !== 'undefined'
    ? (document.getElementById('modal-root') || document.body)
    : null;

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        onClick={toggle}
        aria-label="Уведомления"
        aria-haspopup="dialog"
        aria-expanded={open}
        style={{ position: 'relative', background: 'none', border: 'none', padding: 2, cursor: 'pointer', display: 'flex' }}
      >
        {PrIcon.bell('var(--pr-ink)', size)}
        {counts.unread > 0 && (
          <span style={{ position: 'absolute', top: -1, right: -2, width: 7, height: 7, borderRadius: 999, background: 'var(--pr-accent)' }} />
        )}
      </button>
      {open && portalTarget && createPortal(
        <div className="notif-panel-root is-open">
          <div className="notif-panel__backdrop" onClick={close} aria-hidden />
          <div
            ref={panelRef}
            className="notif-panel"
            role="dialog"
            aria-label="Уведомления"
            style={{ '--notif-anchor-top': `${anchor.top}px`, '--notif-anchor-right': `${anchor.right}px` }}
          >
            <NotificationCenter
              items={items}
              counts={counts}
              markRead={markRead}
              markAllRead={markAllRead}
              dismiss={dismiss}
              dismissAll={dismissAll}
              onNavigate={handleNavigate}
              onOpenSettings={handleOpenSettings}
            />
          </div>
        </div>,
        portalTarget
      )}
    </>
  );
}

export function MetricTile({ label, value, unit, delta, deltaColor = 'var(--pr-good)', spark, sparkColor, onClick }) {
  return (
    <div
      className="pr-card pr-hover"
      onClick={onClick}
      style={{ padding: '13px 15px', display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0, cursor: onClick ? 'pointer' : 'default' }}
    >
      <PrLabel size={9}>{label}</PrLabel>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 8 }}>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 21, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1, whiteSpace: 'nowrap' }}>
          {value}
          {unit && (
            <span style={{ fontSize: 10, fontFamily: 'var(--pr-font-body)', fontWeight: 600, color: 'var(--pr-sub)', marginLeft: 3 }}>
              {unit}
            </span>
          )}
        </div>
        {delta && <div style={{ fontFamily: 'var(--pr-font-body)', fontSize: 11, fontWeight: 700, color: deltaColor }}>{delta}</div>}
      </div>
      {spark && <PrSpark data={spark} w={110} h={20} color={sparkColor || 'var(--pr-accent)'} sw={2} />}
    </div>
  );
}

/** Точки недели: done = галка/good, today = градиент+glow, miss = bad, plan/rest = dashed. */
export function WeekDots({ days }) {
  if (!days) return null;
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6 }}>
      {days.map((d) => {
        const today = d.state === 'today';
        const done = d.state === 'done';
        const missed = d.state === 'missed';
        return (
          <div key={d.date} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 5 }}>
            <div
              style={{
                width: 30,
                height: 30,
                borderRadius: 999,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                background: today ? 'var(--pr-grad)' : done ? 'var(--pr-card-2)' : 'transparent',
                border: done
                  ? '1.5px solid var(--pr-good)'
                  : today
                    ? 'none'
                    : `1.5px dashed ${missed ? 'var(--pr-bad)' : d.state === 'rest' ? 'var(--pr-line)' : 'var(--pr-sub)'}`,
                boxShadow: today ? 'var(--pr-glow)' : 'none',
              }}
            >
              {done ? PrIcon.check('var(--pr-good)', 13) : (
                <span style={{ fontFamily: 'var(--pr-font-body)', fontSize: 11, fontWeight: 700, color: today ? '#fff' : missed ? 'var(--pr-bad)' : 'var(--pr-sub)' }}>
                  {d.km > 0 ? Math.round(d.km) : '·'}
                </span>
              )}
            </div>
            <PrLabel size={8.5} color={today ? 'var(--pr-accent)' : undefined}>{d.dow}</PrLabel>
          </div>
        );
      })}
    </div>
  );
}

/**
 * Интервал-бар: лёгкие сегменты приглушены, рабочие — градиент с glow.
 * seg = { segs: [{type,w}], caption } из buildRunSegments.
 */
export function IntervalBar({ seg, h = 10 }) {
  if (!seg || !seg.segs?.length) return null;
  const isMuted = (t) => t === 'easy' || t === 'recovery' || t === 'rest';
  return (
    <div>
      <div style={{ display: 'flex', gap: 3, height: h }}>
        {seg.segs.map((s, i) => (
          <div
            key={i}
            style={{
              flex: Math.max(0.4, s.w),
              borderRadius: 999,
              background: isMuted(s.type) ? 'var(--pr-sub)' : 'var(--pr-grad)',
              opacity: isMuted(s.type) ? 0.35 : 1,
              boxShadow: isMuted(s.type) ? 'none' : 'var(--pr-glow)',
            }}
          />
        ))}
      </div>
      {seg.caption && (
        <PrLabel size={8.5} style={{ marginTop: 6, textAlign: 'center', letterSpacing: '0.08em' }}>
          {seg.caption}
        </PrLabel>
      )}
    </div>
  );
}
