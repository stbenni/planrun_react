import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon } from '../../common/Icons';
import './ModeSwitchPopup.css';

const MODE_INFO = {
  ai: { label: 'AI-тренер', desc: 'Бесплатно · отвечает мгновенно · 24/7', kind: 'ai', glyph: 'AI' },
  coach: { label: 'Живой тренер', desc: 'Персональный план · человеческий подход', kind: 'coach', glyph: '👤' },
  self: { label: 'Сам', desc: 'Полный контроль над планом', kind: 'self', glyph: '✎' },
};
const ORDER = ['ai', 'coach', 'self'];

function ModeBadge({ kind, glyph, size = 48 }) {
  return (
    <span className={`msp-badge msp-badge--${kind}`} style={{ width: size, height: size, fontSize: size * 0.42 }} aria-hidden>
      {glyph}
    </span>
  );
}

export default function ModeSwitchPopup({ open, currentMode = 'ai', busy = false, onClose, onSelect }) {
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onClose?.(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  const target = typeof document !== 'undefined' && (document.getElementById('modal-root') || document.body);
  if (!target) return null;

  const cur = MODE_INFO[currentMode] || MODE_INFO.ai;
  const others = ORDER.filter((m) => m !== currentMode);

  return createPortal(
    <>
      <div className="msp-scrim" onClick={busy ? undefined : onClose} />
      <div className="msp" role="dialog" aria-modal="true" aria-label="Режим тренировок">
        <div className="msp-grip" />
        <div className="msp-head">
          <span className="msp-title">Режим тренировок</span>
          <button type="button" className="msp-close" onClick={onClose} aria-label="Закрыть"><CloseIcon size={18} /></button>
        </div>

        <div className="msp-body">
          <div className="msp-current">
            <div className="msp-eyebrow">Сейчас активен</div>
            <div className="msp-current-row">
              <ModeBadge kind={cur.kind} glyph={cur.glyph} size={52} />
              <div className="msp-current-main">
                <div className="msp-current-name">{cur.label}</div>
                <div className="msp-current-desc">{cur.desc}</div>
              </div>
              <span className="msp-active-pill">Активен</span>
            </div>
          </div>

          <div className="msp-eyebrow msp-eyebrow--switch">Переключиться на</div>
          {others.map((m) => {
            const info = MODE_INFO[m];
            return (
              <button key={m} type="button" className="msp-switch" disabled={busy} onClick={() => onSelect?.(m)}>
                <ModeBadge kind={info.kind} glyph={info.glyph} size={44} />
                <div className="msp-switch-main">
                  <div className="msp-switch-name">{info.label}</div>
                  <div className="msp-switch-desc">{info.desc}</div>
                </div>
                <span className="msp-arrow">→</span>
              </button>
            );
          })}

          <div className="msp-warn">
            <span className="msp-warn-ic" aria-hidden>⚠</span>
            <div className="msp-warn-text">
              При смене план ведёт выбранный источник. История тренировок и прогресс сохраняются.
            </div>
          </div>
        </div>
      </div>
    </>,
    target,
  );
}
