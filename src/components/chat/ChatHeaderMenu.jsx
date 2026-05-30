/**
 * ChatHeaderMenu — ⋯-меню в шапке чата с действиями (пока одно: «Очистить чат»).
 * Закрывается по клику снаружи и по Escape.
 */

import { useState, useEffect, useRef, useCallback } from 'react';
import './ChatHeaderMenu.css';

export default function ChatHeaderMenu({ items }) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);

  const close = useCallback(() => setOpen(false), []);

  useEffect(() => {
    if (!open) return undefined;
    const onDocClick = (e) => {
      if (!wrapRef.current?.contains(e.target)) close();
    };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, close]);

  const activeItems = (items || []).filter(Boolean);
  if (activeItems.length === 0) return null;

  return (
    <div className="chat-hmenu" ref={wrapRef}>
      <button
        type="button"
        className={`chat-hmenu__btn${open ? ' is-active' : ''}`}
        onClick={() => setOpen((v) => !v)}
        aria-label="Меню чата"
        aria-expanded={open}
        aria-haspopup="menu"
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <circle cx="12" cy="5" r="1.7" />
          <circle cx="12" cy="12" r="1.7" />
          <circle cx="12" cy="19" r="1.7" />
        </svg>
      </button>
      {open && (
        <div className="chat-hmenu__panel" role="menu">
          {activeItems.map((item, i) => (
            <button
              key={item.key || i}
              type="button"
              role="menuitem"
              className={`chat-hmenu__item${item.tone === 'danger' ? ' chat-hmenu__item--danger' : ''}`}
              disabled={!!item.disabled}
              onClick={() => { close(); item.onClick?.(); }}
            >
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
