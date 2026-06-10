/* CopyWeekV3 — копирование недели на другую дату (порт из WeekCalendar, для тренера).
   variant: 'button' (отдельная кнопка) | 'menuitem' (пункт внутри ⋯-меню плана). */
import { useState } from 'react';
import { Copy } from 'lucide-react';

export default function CopyWeekV3({ api, weekId, viewContext, onCopied, variant = 'button' }) {
  const [open, setOpen] = useState(false);
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);

  if (!weekId || !api?.copyWeek) return null;

  const doCopy = async () => {
    if (!target) return;
    // нормализуем к понедельнику
    const d = new Date(target + 'T12:00:00');
    const dow = d.getDay();
    const diff = dow === 0 ? -6 : 1 - dow;
    d.setDate(d.getDate() + diff);
    const monday = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    setBusy(true);
    try {
      await api.copyWeek(weekId, monday, viewContext || undefined);
      setOpen(false);
      setTarget('');
      onCopied?.();
    } catch (e) {
      alert(e.message || 'Ошибка копирования недели');
    }
    setBusy(false);
  };

  // ── Пункт меню (внутри ⋯) ──
  if (variant === 'menuitem') {
    if (!open) {
      return (
        <button type="button" className="calv3-planmenu-item" onClick={() => setOpen(true)}>
          <Copy size={18} strokeWidth={2} />
          <span>Копировать неделю</span>
        </button>
      );
    }
    return (
      <div className="calv3-planmenu-copy">
        <input
          type="date"
          className="calv3-copyweek__date"
          value={target}
          onChange={(e) => setTarget(e.target.value)}
        />
        <div className="calv3-planmenu-copy__row">
          <button type="button" className="calv3-planmenu-copy__ok" onClick={doCopy} disabled={!target || busy}>
            {busy ? '…' : 'Копировать'}
          </button>
          <button type="button" className="calv3-planmenu-copy__cancel" onClick={() => { setOpen(false); setTarget(''); }}>
            Отмена
          </button>
        </div>
      </div>
    );
  }

  // ── Отдельная кнопка (десктоп) ──
  if (!open) {
    return (
      <button type="button" className="calv3-ghost-btn" onClick={() => setOpen(true)}>Копировать неделю</button>
    );
  }
  return (
    <div className="calv3-copyweek">
      <input type="date" className="calv3-copyweek__date" value={target} onChange={(e) => setTarget(e.target.value)} />
      <button type="button" className="calv3-primary-btn" onClick={doCopy} disabled={!target || busy}>{busy ? '…' : 'OK'}</button>
      <button type="button" className="calv3-ghost-btn" onClick={() => { setOpen(false); setTarget(''); }}>✕</button>
    </div>
  );
}
