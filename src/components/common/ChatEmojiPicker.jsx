/**
 * ChatEmojiPicker — кнопка-смайл + панель emoji-mart (Apple-набор, как в Telegram).
 * Мобайл: панель снизу на всю ширину. Десктоп: поповер над кнопкой.
 * Picker и данные грузятся лениво (отдельным чанком) при первом открытии.
 */

import { useState, useRef, useEffect, useCallback, Suspense, lazy } from 'react';
import { SmileIcon } from './Icons';
import './ChatEmojiPicker.css';

const EmojiMartLazy = lazy(() => import('./EmojiMartLazy'));

function isMobileWidth() {
  return typeof window !== 'undefined' && window.innerWidth <= 640;
}

export default function ChatEmojiPicker({ onPick }) {
  const [open, setOpen] = useState(false);
  const [theme, setTheme] = useState('light');
  const [mobile, setMobile] = useState(isMobileWidth());
  const wrapRef = useRef(null);

  const close = useCallback(() => setOpen(false), []);

  const toggle = useCallback(() => {
    setOpen((o) => {
      const next = !o;
      if (next) {
        setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
        setMobile(isMobileWidth());
      }
      return next;
    });
  }, []);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const onPointer = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) close();
    };
    document.addEventListener('keydown', onKey);
    const t = setTimeout(() => document.addEventListener('pointerdown', onPointer), 0);
    return () => {
      document.removeEventListener('keydown', onKey);
      clearTimeout(t);
      document.removeEventListener('pointerdown', onPointer);
    };
  }, [open, close]);

  return (
    <div className={`chat-emoji ${mobile ? 'chat-emoji--mobile' : ''}`} ref={wrapRef}>
      <button
        type="button"
        className={`chat-emoji__btn ${open ? 'is-active' : ''}`}
        onClick={toggle}
        aria-label="Эмодзи"
        aria-expanded={open}
        tabIndex={-1}
      >
        <SmileIcon size={20} />
      </button>
      {open && (
        <div className="chat-emoji__panel" role="dialog" aria-label="Выбор эмодзи">
          <Suspense fallback={<div className="chat-emoji__loading">Загрузка…</div>}>
            <EmojiMartLazy theme={theme} onPick={onPick} dynamicWidth={mobile} />
          </Suspense>
        </div>
      )}
    </div>
  );
}
