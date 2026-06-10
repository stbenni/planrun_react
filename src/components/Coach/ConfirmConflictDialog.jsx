/**
 * ConfirmConflictDialog — диалог подтверждения перезаписи плана у атлетов.
 *
 * Показывается после preflight bulkAssign (overwrite=false), если сервер вернул conflicts[].
 * Юзер видит список «у кого что уже стоит» и решает: перезаписать или отменить.
 */

import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon, AlertTriangleIcon } from '../common/Icons';
import { WORKOUT_TYPE_LABEL as TYPE_LABELS } from '../../utils/workoutTypes';
import './ConfirmConflictDialog.css';

export default function ConfirmConflictDialog({ isOpen, conflicts, onClose, onConfirm, busy = false }) {
  useEffect(() => {
    if (!isOpen) return undefined;
    const onKey = (e) => { if (e.key === 'Escape' && !busy) onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [isOpen, onClose, busy]);

  if (!isOpen || !Array.isArray(conflicts) || conflicts.length === 0) return null;

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return null;

  const content = (
    <>
      <div className="coach-conflict__scrim" onClick={busy ? undefined : onClose} aria-hidden />
      <div className="coach-conflict" role="alertdialog" aria-modal="true" aria-label="Перезаписать существующий план?">
        <header className="coach-conflict__head">
          <div className="coach-conflict__head-icon" aria-hidden><AlertTriangleIcon size={20} /></div>
          <div className="coach-conflict__head-text">
            <div className="coach-conflict__title">У {conflicts.length} {plural(conflicts.length)} уже есть план на эту дату</div>
            <div className="coach-conflict__subtitle">При подтверждении существующая тренировка будет перезаписана.</div>
          </div>
          {!busy && (
            <button type="button" className="coach-conflict__close" onClick={onClose} aria-label="Закрыть">
              <CloseIcon size={16} />
            </button>
          )}
        </header>

        <div className="coach-conflict__list">
          {conflicts.map((c) => (
            <div key={c.athlete_id} className="coach-conflict__item">
              <div className="coach-conflict__item-name">{c.athlete_name}</div>
              <div className="coach-conflict__item-existing">
                <span className="coach-conflict__existing-label">сейчас:</span>{' '}
                <span className="coach-conflict__existing-type">{TYPE_LABELS[c.existing?.type] || c.existing?.type || '—'}</span>
                {c.existing?.description ? (
                  <span className="coach-conflict__existing-desc"> · {truncate(c.existing.description, 60)}</span>
                ) : null}
              </div>
            </div>
          ))}
        </div>

        <footer className="coach-conflict__foot">
          <button
            type="button"
            className="coach-conflict__btn coach-conflict__btn--ghost"
            onClick={onClose}
            disabled={busy}
          >
            Отмена
          </button>
          <span style={{ flex: 1 }} />
          <button
            type="button"
            className="coach-conflict__btn coach-conflict__btn--danger"
            onClick={onConfirm}
            disabled={busy}
          >
            {busy ? 'Перезаписываю…' : 'Перезаписать'}
          </button>
        </footer>
      </div>
    </>
  );

  return createPortal(content, target);
}

function plural(n) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return 'атлета';
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'атлетов';
  return 'атлетов';
}

function truncate(s, n) {
  if (!s) return '';
  return s.length > n ? s.slice(0, n - 1) + '…' : s;
}
