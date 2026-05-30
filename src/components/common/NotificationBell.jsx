/**
 * NotificationBell — колокольчик с живым счётчиком + панель notification center.
 * Десктоп: поповер под колокольчиком. Мобайл: bottom-sheet.
 * Агрегатор уведомлений (useNotificationFeed) живёт здесь, чтобы бейдж был актуален
 * даже при закрытой панели; сама панель (NotificationCenter) рендерится только при открытии.
 */

import { useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { BellIcon } from './Icons';
import { useNotificationFeed } from './useNotificationFeed';
import NotificationCenter from './NotificationCenter';
import './NotificationBell.css';
import './NotificationCenter.css';

export default function NotificationBell({ api, isAdmin, user }) {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [anchor, setAnchor] = useState({ top: 64, right: 16 });
  const btnRef = useRef(null);
  const panelRef = useRef(null);

  const { items, counts, markRead, markAllRead, dismiss, dismissAll } = useNotificationFeed(api, user, isAdmin);

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

  const panel = (
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
    </div>
  );

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        className={`notif-bell ${open ? 'is-active' : ''}`}
        onClick={toggle}
        aria-label="Уведомления"
        aria-haspopup="dialog"
        aria-expanded={open}
      >
        <BellIcon size={18} />
        {counts.unread > 0 && (
          <span className="notif-bell__badge" aria-hidden>{counts.unread > 9 ? '9+' : counts.unread}</span>
        )}
      </button>
      {open && portalTarget && createPortal(panel, portalTarget)}
    </>
  );
}
