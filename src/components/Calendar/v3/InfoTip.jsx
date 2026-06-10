/* InfoTip — «i» в кружочке с поясняющим тултипом. Клик-тоггл (работает на тач и десктопе)
   + закрытие по клику вне/ESC. Наведение мышью тоже показывает (десктоп). */
import React, { useEffect, useRef, useState } from 'react';

export default function InfoTip({ text, children, label = 'Пояснение' }) {
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
    <span className="calv3-infotip" ref={ref}>
      <button
        type="button"
        className="calv3-infotip__btn"
        aria-label={label}
        aria-expanded={open}
        onClick={(e) => { e.stopPropagation(); setOpen((o) => !o); }}
      >
        i
      </button>
      <span className={`calv3-infotip__pop${open ? ' is-open' : ''}`} role="tooltip">{children || text}</span>
    </span>
  );
}
