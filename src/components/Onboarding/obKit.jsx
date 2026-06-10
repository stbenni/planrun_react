/**
 * Кит онбординга v3B: мелкие блоки из моков BObGoal/BObShell (design_handoff_v3b).
 * Вся палитра — только var(--pr-*).
 */

import { PrIcon, PrLabel } from '../ui';

export function ObHeading({ title, sub, style }) {
  return (
    <div style={{ marginTop: 26, ...style }}>
      <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 27, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1.15 }}>
        {title}
      </div>
      {sub && <div style={{ fontSize: 13, color: 'var(--pr-sub)', marginTop: 6 }}>{sub}</div>}
    </div>
  );
}

export function ObSection({ children, style }) {
  return (
    <PrLabel size={9} style={{ margin: '20px 0 8px', ...style }}>
      {children}
    </PrLabel>
  );
}

export function ObHint({ children, style }) {
  return (
    <div style={{ fontSize: 11, color: 'var(--pr-sub)', marginTop: 7, lineHeight: 1.45, ...style }}>
      {children}
    </div>
  );
}

export function ObError({ children }) {
  return (
    <div
      className="pr-card"
      style={{
        marginTop: 14,
        padding: '11px 14px',
        border: '1px solid var(--pr-bad)',
        fontSize: 12.5,
        fontWeight: 600,
        color: 'var(--pr-bad)',
      }}
    >
      {children}
    </div>
  );
}

/** Карточка-радио в список (программы, режимы) — паттерн progs из BObGoal. */
export function ObOptionCard({ active, onClick, leading, title, sub, trailing, disabled, children }) {
  return (
    <div
      className="pr-card pr-hover"
      role="radio"
      aria-checked={!!active}
      aria-disabled={disabled || undefined}
      onClick={disabled ? undefined : onClick}
      style={{
        padding: '13px 16px',
        display: 'flex',
        flexDirection: 'column',
        gap: 0,
        cursor: disabled ? 'default' : 'pointer',
        border: active ? '1.5px solid var(--pr-accent)' : '1px solid var(--pr-card-border)',
        background: active ? 'var(--pr-card-2)' : 'var(--pr-card)',
        opacity: disabled ? 0.55 : 1,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        {leading !== undefined ? (
          leading
        ) : (
          <span
            style={{
              width: 20,
              height: 20,
              borderRadius: 999,
              flexShrink: 0,
              border: active ? 'none' : '2px solid var(--pr-sub)',
              background: active ? 'var(--pr-grad)' : 'transparent',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            {active && PrIcon.check('#fff', 11)}
          </span>
        )}
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--pr-ink)' }}>{title}</div>
          {sub && <div style={{ fontSize: 11.5, color: 'var(--pr-sub)', marginTop: 2 }}>{sub}</div>}
        </div>
        {trailing}
      </div>
      {children}
    </div>
  );
}

/** Карточка цели N-в-ряд: иконка / название / подпись раздельно (фикс аудита №1). */
export function ObGoalCard({ active, onClick, icon, title, sub }) {
  return (
    <div
      className="pr-card pr-hover"
      role="radio"
      aria-checked={!!active}
      onClick={onClick}
      style={{
        padding: '14px 12px',
        textAlign: 'center',
        cursor: 'pointer',
        border: active ? '1.5px solid var(--pr-accent)' : '1px solid var(--pr-card-border)',
        boxShadow: active ? 'var(--pr-glow)' : 'none',
        background: active ? 'var(--pr-card-2)' : 'var(--pr-card)',
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 8 }}>
        {PrIcon[icon](active ? 'var(--pr-accent)' : 'var(--pr-sub)', 22)}
      </div>
      <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--pr-ink)' }}>{title}</div>
      <div style={{ fontSize: 10, color: 'var(--pr-sub)', marginTop: 3, lineHeight: 1.35 }}>{sub}</div>
    </div>
  );
}

/** Сегмент-чип (пол, самочувствие, дистанции, опыт) — паттерн из BResultModal. */
export function ObSeg({ active, onClick, children, style }) {
  return (
    <button
      type="button"
      className="pr-press"
      aria-pressed={!!active}
      onClick={onClick}
      style={{
        fontFamily: 'var(--pr-font-body)',
        fontSize: 12,
        fontWeight: 700,
        padding: '10px 8px',
        borderRadius: 12,
        textAlign: 'center',
        background: active ? 'var(--pr-grad)' : 'var(--pr-card)',
        color: active ? '#fff' : 'var(--pr-sub)',
        border: active ? 'none' : '1px solid var(--pr-card-border)',
        cursor: 'pointer',
        ...style,
      }}
    >
      {children}
    </button>
  );
}

/** Круглая ячейка дня недели (выбор дней) — паттерн BWeekDots. */
export function ObDayDot({ active, label, onClick }) {
  return (
    <button
      type="button"
      aria-pressed={!!active}
      onClick={onClick}
      style={{
        width: 38,
        height: 38,
        borderRadius: 999,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        background: active ? 'var(--pr-grad)' : 'transparent',
        border: active ? 'none' : '1.5px dashed var(--pr-sub)',
        boxShadow: active ? 'var(--pr-glow)' : 'none',
        fontFamily: 'var(--pr-font-body)',
        fontSize: 12,
        fontWeight: 700,
        color: active ? '#fff' : 'var(--pr-sub)',
        cursor: 'pointer',
        padding: 0,
      }}
    >
      {label}
    </button>
  );
}

/** Карточка с человекочитаемой датой и нативным date-пикером поверх (BObGoal «Старт плана»). */
export function ObDateCard({ label, value, min, onChange }) {
  return (
    <div
      className="pr-card"
      style={{ position: 'relative', padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 12 }}
    >
      <div style={{ flex: 1 }}>
        <PrLabel size={8.5}>{label}</PrLabel>
        <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--pr-ink)', marginTop: 2 }}>
          {formatHumanDate(value) || 'Выбрать дату'}
        </div>
      </div>
      {PrIcon.cal('var(--pr-accent)', 18)}
      <input
        type="date"
        value={value || ''}
        min={min}
        onChange={onChange}
        aria-label={label}
        style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', opacity: 0, cursor: 'pointer' }}
      />
    </div>
  );
}

/** «Понедельник, 15 июня» из YYYY-MM-DD. */
export function formatHumanDate(ymd) {
  if (!ymd) return '';
  const d = new Date(`${ymd}T00:00:00`);
  if (Number.isNaN(d.getTime())) return '';
  const s = d.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' });
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** Склонение: 1 тренировка / 2 тренировки / 5 тренировок. */
export function plural(n, one, few, many) {
  const m10 = n % 10;
  const m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
  return many;
}
