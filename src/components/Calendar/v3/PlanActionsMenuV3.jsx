/* PlanActionsMenuV3 — kebab «три точки» с действиями плана (Пересчитать/Новый план + Очистить).
   Самодостаточный: своё open-состояние + закрытие по клику вне/ESC. */
import React, { useEffect, useRef, useState } from 'react';
import { MoreHorizontal, RefreshCw, Sparkles, Trash2 } from 'lucide-react';

export default function PlanActionsMenuV3({
  isPlanCompleted = false,
  recalculating = false,
  generatingNext = false,
  clearingPlan = false,
  onPrimary,
  onClear,
  copyWeekSlot = null,
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="calv3-planmenu" ref={ref}>
      <button
        type="button"
        className={`calv3-planmenu-btn${open ? ' is-open' : ''}`}
        aria-label="Действия с планом"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
      >
        <MoreHorizontal size={20} strokeWidth={2.2} />
      </button>
      {open && (
        <div className="calv3-planmenu-panel" role="menu">
          {copyWeekSlot}
          {copyWeekSlot && <div className="calv3-planmenu-sep" />}
          <button
            type="button"
            role="menuitem"
            className="calv3-planmenu-item"
            onClick={() => { setOpen(false); onPrimary?.(); }}
            disabled={isPlanCompleted ? generatingNext : recalculating}
          >
            {isPlanCompleted ? <Sparkles size={18} strokeWidth={2} /> : <RefreshCw size={18} strokeWidth={2} />}
            <span>{isPlanCompleted ? (generatingNext ? 'Генерация…' : 'Новый план') : (recalculating ? 'Пересчёт…' : 'Пересчитать план')}</span>
          </button>
          <button
            type="button"
            role="menuitem"
            className="calv3-planmenu-item calv3-planmenu-item--danger"
            onClick={() => { setOpen(false); onClear?.(); }}
            disabled={recalculating || generatingNext || clearingPlan}
          >
            {clearingPlan ? <span className="btn-spinner" /> : <Trash2 size={18} strokeWidth={2} />}
            <span>{clearingPlan ? 'Очищаем…' : 'Очистить план'}</span>
          </button>
        </div>
      )}
    </div>
  );
}
