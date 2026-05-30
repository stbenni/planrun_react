/**
 * EventQuickReplySheet — bottom-sheet для быстрого ответа на событие на мобильном.
 *
 * Открывается тапом по карточке EventStream на мобиле (вместо drill-in overlay).
 * Содержит: avatar+name+time, заголовок события + детали, готовые quick-reply chips,
 * textarea + кнопка «Отправить», и 2×2 кнопок действий (план / перенести / графики / AI-черновик).
 *
 * Полный send-сообщение flow — Фаза 5 / расширение. Сейчас: chip заполняет textarea,
 * «Отправить» → console.log + close.
 */

import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  CloseIcon, ClipboardListIcon, ArrowLeftRightIcon, BarChartIcon, BotIcon,
} from '../common/Icons';
import { CoachAvatar, TONE } from './CoachPrimitives';
import './EventQuickReplySheet.css';

const QUICK_REPLIES_BY_KIND = {
  upload: ['👍 Молодец!', '🔥 Отличная работа!', 'Так держать!'],
  risk: ['Что случилось?', 'Связь?', 'Расскажи как ты'],
  question: ['Сейчас расскажу', 'Хороший вопрос!', 'Давай завтра обсудим'],
  pr: ['🎉 Поздравляю!', 'Большой шаг!', 'Это твой потолок? 😉'],
};

function formatTime(iso) {
  if (!iso) return '';
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return '';
  const secAgo = Math.floor((Date.now() - t) / 1000);
  if (secAgo < 60) return 'только что';
  if (secAgo < 3600) return `${Math.floor(secAgo / 60)} мин назад`;
  if (secAgo < 86400) return `${Math.floor(secAgo / 3600)} ч назад`;
  return `${Math.floor(secAgo / 86400)} дн назад`;
}

export default function EventQuickReplySheet({ isOpen, event, onClose, onOpenAthlete, onSendMessage }) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [sentOk, setSentOk] = useState(false);
  const [errorMsg, setErrorMsg] = useState(null);

  useEffect(() => {
    if (!isOpen) return undefined;
    setText('');
    setSentOk(false);
    setErrorMsg(null);
    setBusy(false);
    const onKey = (e) => { if (e.key === 'Escape' && !busy) onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, onClose]);

  if (!isOpen || !event) return null;

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return null;

  const t = TONE[event.tone] || TONE.primary;
  const fakeAthlete = {
    id: event.athlete_id,
    username: event.athlete_username,
    avatar_path: event.athlete_avatar_path,
  };
  const quickReplies = QUICK_REPLIES_BY_KIND[event.kind] || ['👍', 'Понял', 'Спасибо!'];

  const handleSend = async () => {
    if (!text.trim() || busy) return;
    setBusy(true);
    setErrorMsg(null);
    try {
      const result = await onSendMessage?.({ athlete_id: event.athlete_id, text: text.trim(), event });
      // onSendMessage может вернуть promise (false / throw → ошибка), либо undefined (sync ok)
      if (result === false) {
        setErrorMsg('Не удалось отправить сообщение');
        return;
      }
      setSentOk(true);
      setText('');
      // Авто-закрытие через 800 мс, чтобы видна была галочка
      setTimeout(() => onClose?.(), 800);
    } catch (e) {
      setErrorMsg(e?.message || 'Ошибка отправки');
    } finally {
      setBusy(false);
    }
  };

  const content = (
    <>
      <div className="qrs__scrim" onClick={onClose} aria-hidden />
      <div className="qrs" role="dialog" aria-modal="true" aria-label="Быстрый ответ">
        <div className="qrs__handle" aria-hidden />
        <header className="qrs__head">
          <CoachAvatar athlete={fakeAthlete} size={40} ring={t.solid} />
          <div className="qrs__head-info">
            <div className="qrs__name">{event.athlete_username}</div>
            <div className="qrs__time">{formatTime(event.created_at)}</div>
          </div>
          <button type="button" className="qrs__close" onClick={onClose} aria-label="Закрыть">
            <CloseIcon size={18} />
          </button>
        </header>

        <div className="qrs__event" style={{ background: t.bg, color: t.color }}>
          <div className="qrs__event-title">{event.title}</div>
          {event.detail && <div className="qrs__event-detail">{event.detail}</div>}
        </div>

        <div className="qrs__section">
          <div className="qrs__section-label">БЫСТРЫЙ ОТВЕТ</div>
          <div className="qrs__chips">
            {quickReplies.map((reply) => (
              <button
                key={reply}
                type="button"
                className="qrs__chip"
                onClick={() => setText((prev) => prev ? `${prev} ${reply}` : reply)}
              >
                {reply}
              </button>
            ))}
          </div>
          <textarea
            className="qrs__textarea"
            rows={3}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder="Напишите ответ…"
          />
          <button
            type="button"
            className="qrs__send"
            onClick={handleSend}
            disabled={!text.trim() || busy || sentOk}
          >
            {sentOk ? '✓ Отправлено' : busy ? 'Отправляю…' : 'Отправить →'}
          </button>
          {errorMsg && <div className="qrs__error">{errorMsg}</div>}
        </div>

        <div className="qrs__section">
          <div className="qrs__section-label">ИЛИ ДЕЙСТВИЕ</div>
          <div className="qrs__actions">
            <button type="button" className="qrs__action" onClick={() => { onOpenAthlete?.(event.athlete_id); onClose?.(); }}>
              <ClipboardListIcon size={18} /> Открыть план
            </button>
            <button type="button" className="qrs__action" disabled title="Скоро">
              <ArrowLeftRightIcon size={18} /> Перенести
            </button>
            <button type="button" className="qrs__action" onClick={() => { onOpenAthlete?.(event.athlete_id); onClose?.(); }}>
              <BarChartIcon size={18} /> Графики
            </button>
            <button type="button" className="qrs__action" disabled title="Скоро">
              <BotIcon size={18} /> Черновик AI
            </button>
          </div>
        </div>
      </div>
    </>
  );

  return createPortal(content, target);
}
