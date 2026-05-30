/**
 * GroupMessageDialog — отправка одного сообщения всем выбранным атлетам.
 *
 * Открывается из BulkActionBar «Сообщение группе». Принимает массив athleteIds.
 * Сообщение отправляется параллельно каждому через chatSendMessageToUser.
 */

import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon } from '../common/Icons';
import { CoachAvatar } from './CoachPrimitives';
import './GroupMessageDialog.css';

export default function GroupMessageDialog({ isOpen, athletes, selectedIds, onClose, onSend }) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState({ done: 0, total: 0, errors: 0 });
  const [completed, setCompleted] = useState(false);

  useEffect(() => {
    if (!isOpen) return undefined;
    setText('');
    setBusy(false);
    setProgress({ done: 0, total: 0, errors: 0 });
    setCompleted(false);
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

  if (!isOpen) return null;

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return null;

  const selected = athletes.filter((a) => selectedIds?.has?.(a.id));

  const handleSend = async () => {
    const msg = text.trim();
    if (!msg || selected.length === 0) return;
    setBusy(true);
    setProgress({ done: 0, total: selected.length, errors: 0 });
    let done = 0, errors = 0;
    // Параллельно с ограничением — простая Promise.all
    await Promise.all(selected.map(async (a) => {
      try {
        await onSend?.(a.id, msg);
      } catch {
        errors++;
      } finally {
        done++;
        setProgress({ done, total: selected.length, errors });
      }
    }));
    setBusy(false);
    setCompleted(true);
    if (errors === 0) {
      setTimeout(() => onClose?.(), 900);
    }
  };

  const content = (
    <>
      <div className="gmd__scrim" onClick={busy ? undefined : onClose} aria-hidden />
      <div className="gmd" role="dialog" aria-modal="true" aria-label="Сообщение группе">
        <header className="gmd__head">
          <div>
            <div className="gmd__eyebrow">СООБЩЕНИЕ ГРУППЕ</div>
            <h2 className="gmd__title">{selected.length} атлетов получат сообщение</h2>
          </div>
          {!busy && (
            <button type="button" className="gmd__close" onClick={onClose} aria-label="Закрыть">
              <CloseIcon size={18} />
            </button>
          )}
        </header>

        <div className="gmd__avatars">
          {selected.slice(0, 8).map((a) => (
            <div key={a.id} className="gmd__avatar-wrap">
              <CoachAvatar athlete={a} size={32} />
            </div>
          ))}
          {selected.length > 8 && (
            <div className="gmd__avatar-more">+{selected.length - 8}</div>
          )}
        </div>

        <div className="gmd__body">
          <textarea
            className="gmd__textarea"
            rows={5}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder="Напишите сообщение атлетам…"
            disabled={busy || completed}
          />

          {busy && (
            <div className="gmd__progress">
              Отправлено: {progress.done} / {progress.total}
              {progress.errors > 0 && <span className="gmd__progress-err"> · ошибок: {progress.errors}</span>}
            </div>
          )}

          {completed && (
            <div className={progress.errors > 0 ? 'gmd__result gmd__result--warn' : 'gmd__result gmd__result--ok'}>
              {progress.errors > 0
                ? `Отправлено ${progress.done - progress.errors} из ${progress.total}, ошибок: ${progress.errors}`
                : `✓ Сообщение получили все ${progress.total} атлетов`}
            </div>
          )}
        </div>

        <footer className="gmd__foot">
          <button
            type="button"
            className="gmd__btn gmd__btn--ghost"
            onClick={onClose}
            disabled={busy}
          >
            {completed ? 'Закрыть' : 'Отмена'}
          </button>
          <span style={{ flex: 1 }} />
          {!completed && (
            <button
              type="button"
              className="gmd__btn gmd__btn--primary"
              onClick={handleSend}
              disabled={!text.trim() || busy || selected.length === 0}
            >
              {busy ? 'Отправляю…' : `→ Отправить ${selected.length} атлетам`}
            </button>
          )}
        </footer>
      </div>
    </>
  );

  return createPortal(content, target);
}
