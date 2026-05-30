/**
 * BulkActionBar — нижняя плашка действий над выбранными атлетами.
 * Появляется когда selected.size > 0. Плавающая, через portal в #modal-root
 * (чтобы быть всегда поверх контента, как и BottomNav на мобиле).
 */

import { createPortal } from 'react-dom';
import {
  CloseIcon, MailIcon, PenLineIcon, ClipboardListIcon, ArrowLeftRightIcon,
} from '../common/Icons';
import './BulkActionBar.css';

export default function BulkActionBar({ athletes, selected, onClear, onAssign, onCompare, onApplyTemplate, onSendMessage }) {
  if (!selected || selected.size === 0) return null;

  const ids = Array.from(selected);
  const firstNames = ids
    .slice(0, 3)
    .map((id) => {
      const a = athletes.find((x) => String(x.id) === String(id));
      if (!a) return null;
      const fullName = a.name || a.username || '';
      return fullName.split(/\s+/)[0];
    })
    .filter(Boolean);
  const overflow = ids.length - firstNames.length;

  const bar = (
    <div className="coach-bulk-bar" role="region" aria-label="Действия над выбранными атлетами">
      <div className="coach-bulk-bar__left">
        <span className="coach-bulk-bar__count">Выбрано · {selected.size}</span>
        {firstNames.length > 0 && (
          <span className="coach-bulk-bar__names">
            {firstNames.join(', ')}
            {overflow > 0 ? ` · ещё ${overflow}` : ''}
          </span>
        )}
      </div>
      <div className="coach-bulk-bar__actions">
        <button type="button" className="coach-bulk-bar__btn" onClick={onAssign}>
          <PenLineIcon size={16} /> Назначить тренировку
        </button>
        {selected.size >= 2 && selected.size <= 4 && (
          <button type="button" className="coach-bulk-bar__btn" onClick={onCompare}>
            <ArrowLeftRightIcon size={16} /> Сравнить
          </button>
        )}
        <button type="button" className="coach-bulk-bar__btn" onClick={onApplyTemplate}>
          <ClipboardListIcon size={16} /> Применить шаблон
        </button>
        <button type="button" className="coach-bulk-bar__btn" onClick={onSendMessage}>
          <MailIcon size={16} /> Сообщение группе
        </button>
        <button
          type="button"
          className="coach-bulk-bar__btn coach-bulk-bar__btn--ghost"
          onClick={onClear}
          aria-label="Очистить выбор"
        >
          <CloseIcon size={16} />
        </button>
      </div>
    </div>
  );

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return bar;
  return createPortal(bar, target);
}
