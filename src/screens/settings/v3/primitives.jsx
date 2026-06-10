// iOS-style grouped-list примитивы для настроек v3 — порт STY-примитивов прототипа.
import { Children } from 'react';
import { ChevronIcon } from './icons';

export function Group({ label, children, footer }) {
  const rows = Children.toArray(children).filter(Boolean);
  return (
    <div className="sv3-group-wrap">
      {label && <div className="sv3-group-label">{label}</div>}
      <div className="sv3-group">
        {rows.map((r, i) => (
          <div key={i}>
            {r}
            {i < rows.length - 1 && <div className="sv3-divider" />}
          </div>
        ))}
      </div>
      {footer && <div className="sv3-group-footer">{footer}</div>}
    </div>
  );
}

export function Row({ children, onClick, className = '', column = false }) {
  return (
    <div
      onClick={onClick}
      className={`sv3-row ${column ? 'sv3-row--col' : ''} ${onClick ? 'sv3-row--btn' : ''} ${className}`.trim()}
      {...(onClick ? { role: 'button', tabIndex: 0, onKeyDown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(e); } } } : {})}
    >
      {children}
    </div>
  );
}

export function NavRow({ title, sub, onClick }) {
  return (
    <Row onClick={onClick}>
      <div className="sv3-row-main">
        <div className="sv3-row-title">{title}</div>
        {sub && <div className="sv3-row-sub">{sub}</div>}
      </div>
      <span className="sv3-chev"><ChevronIcon /></span>
    </Row>
  );
}

export function FieldRow({ label, children }) {
  return (
    <div className="sv3-field">
      <span className="sv3-field-label">{label}</span>
      <div className="sv3-field-control">{children}</div>
    </div>
  );
}

export function Toggle({ on, onChange, disabled }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={!!on}
      onClick={() => { if (!disabled) onChange?.(!on); }}
      className={`sv3-toggle ${on ? 'sv3-toggle--on' : ''} ${disabled ? 'sv3-toggle--disabled' : ''}`.trim()}
    >
      <span className="sv3-toggle-knob" />
    </button>
  );
}

export function ToggleRow({ label, sub, on, onChange, disabled }) {
  return (
    <Row className={disabled ? 'sv3-row--dim' : ''}>
      <div className="sv3-row-main">
        <div className="sv3-row-title">{label}</div>
        {sub && <div className="sv3-row-sub">{sub}</div>}
      </div>
      <Toggle on={on} onChange={onChange} disabled={disabled} />
    </Row>
  );
}

export function Seg({ options, value, onChange }) {
  return (
    <div className="sv3-seg">
      {options.map(([id, l]) => (
        <button
          key={id}
          type="button"
          onClick={() => onChange?.(id)}
          className={`sv3-seg-btn ${value === id ? 'sv3-seg-btn--on' : ''}`.trim()}
        >
          {l}
        </button>
      ))}
    </div>
  );
}

export function DayPicker({ days, value, onToggle, variant = 'run' }) {
  return (
    <div className="sv3-days">
      {days.map((d) => {
        const active = value.includes(d.value);
        return (
          <button
            key={d.value}
            type="button"
            aria-pressed={active}
            onClick={() => onToggle(d.value)}
            className={`sv3-day ${active ? (variant === 'ofp' ? 'sv3-day--ofp' : 'sv3-day--on') : ''}`.trim()}
          >
            {d.label}
          </button>
        );
      })}
    </div>
  );
}
