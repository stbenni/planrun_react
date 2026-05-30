/**
 * Coach primitives — небольшие переиспользуемые компоненты для тренерского workspace.
 * Sparkline, ComplianceBar, GroupTag, CoachAvatar.
 *
 * Все цвета из --primary-* / --success-* / --warning-* / --danger-* токенов.
 */

import { useState } from 'react';
import { getAvatarSrc } from '../../utils/avatarUrl';

/**
 * SVG-полоска линия по точкам. data: number[] длины ≥2.
 * labels: опциональный массив подписей для tooltip (например, даты).
 * unit: единица для tooltip (по умолчанию 'км').
 */
export function Sparkline({
  data,
  w = 70,
  h = 22,
  color = 'var(--primary-500)',
  bg = true,
  thick = false,
  labels,
  unit = 'км',
}) {
  const [hoverIdx, setHoverIdx] = useState(null);

  if (!Array.isArray(data) || data.length < 2) {
    return <span style={{ fontSize: 11, color: 'var(--text-tertiary)' }}>—</span>;
  }
  const max = Math.max(...data, 1);
  const step = w / (data.length - 1);
  const points = data.map((v, i) => {
    const x = i * step;
    const y = h - (v / max) * (h - 2) - 1;
    return [x, y];
  });
  const d = 'M ' + points.map((p) => p.join(' ')).join(' L ');
  const area = d + ` L ${w} ${h} L 0 ${h} Z`;
  const last = points[points.length - 1];
  const interactive = Array.isArray(labels) && labels.length === data.length;

  if (!interactive) {
    return (
      <svg width={w} height={h} style={{ display: 'block', overflow: 'visible' }} aria-hidden>
        {bg && <path d={area} fill={color} opacity="0.12" />}
        <path d={d} stroke={color} strokeWidth={thick ? 2 : 1.5} fill="none" strokeLinejoin="round" strokeLinecap="round" />
        <circle cx={last[0]} cy={last[1]} r={thick ? 3 : 2} fill={color} />
      </svg>
    );
  }

  const hover = hoverIdx != null ? { x: points[hoverIdx][0], y: points[hoverIdx][1], v: data[hoverIdx], l: labels[hoverIdx] } : null;
  const tooltipX = hover ? Math.max(0, Math.min(w - 60, hover.x - 30)) : 0;

  return (
    <span style={{ position: 'relative', display: 'inline-block', lineHeight: 0 }}>
      <svg
        width={w}
        height={h}
        style={{ display: 'block', overflow: 'visible' }}
        onMouseLeave={() => setHoverIdx(null)}
      >
        {bg && <path d={area} fill={color} opacity="0.12" />}
        <path d={d} stroke={color} strokeWidth={thick ? 2 : 1.5} fill="none" strokeLinejoin="round" strokeLinecap="round" />
        {hover && (
          <line x1={hover.x} y1={0} x2={hover.x} y2={h} stroke={color} strokeWidth="1" strokeDasharray="2 2" opacity="0.5" />
        )}
        <circle cx={last[0]} cy={last[1]} r={thick ? 3 : 2} fill={color} />
        {hover && (
          <circle cx={hover.x} cy={hover.y} r={thick ? 4 : 3} fill={color} stroke="#fff" strokeWidth="1.5" />
        )}
        {points.map(([x], i) => (
          <rect
            key={i}
            x={x - step / 2}
            y={0}
            width={step}
            height={h}
            fill="transparent"
            style={{ cursor: 'pointer' }}
            onMouseEnter={() => setHoverIdx(i)}
          />
        ))}
      </svg>
      {hover && (
        <span
          style={{
            position: 'absolute',
            left: tooltipX,
            top: -38,
            background: 'rgba(15, 23, 42, 0.95)',
            color: '#fff',
            padding: '4px 8px',
            borderRadius: 6,
            fontSize: 11,
            fontWeight: 600,
            whiteSpace: 'nowrap',
            lineHeight: 1.3,
            pointerEvents: 'none',
            zIndex: 10,
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
          }}
        >
          <span style={{ display: 'block', fontSize: 10, opacity: 0.7, fontWeight: 500 }}>{hover.l}</span>
          <span>{hover.v} {unit}</span>
        </span>
      )}
    </span>
  );
}

/** Compliance-бар: фон gray-200, fill цвета по % (≥80 success / 50-80 warning / <50 danger). */
export function ComplianceBar({ done = 0, total = 0, w = 48 }) {
  const pct = total > 0 ? Math.min(1, done / total) : 0;
  const color =
    pct >= 0.8 ? 'var(--success-500)' :
    pct >= 0.5 ? 'var(--warning-500)' :
    pct > 0 ? 'var(--danger-500)' : 'var(--gray-300)';
  return (
    <div
      style={{ width: w, height: 4, background: 'var(--gray-200)', borderRadius: 999, overflow: 'hidden' }}
      role="progressbar"
      aria-valuenow={Math.round(pct * 100)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div style={{ width: `${pct * 100}%`, height: '100%', background: color, borderRadius: 999 }} />
    </div>
  );
}

/** Тэг-чип группы атлета. group: { id, name, color } или null. */
export function GroupTag({ group, size = 'sm' }) {
  if (!group?.name) return null;
  const color = group.color || 'var(--primary-500)';
  const isSmall = size === 'sm';
  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        padding: isSmall ? '2px 7px' : '4px 10px',
        borderRadius: 999,
        fontSize: isSmall ? 11 : 12,
        fontWeight: 600,
        background: color + '15',
        color,
        lineHeight: 1.4,
        whiteSpace: 'nowrap',
      }}
    >
      <span style={{ width: 5, height: 5, borderRadius: 999, background: color }} />
      {group.name}
    </span>
  );
}

/** Инициалы из name или username. */
export function getInitials(athlete) {
  if (athlete?.name && typeof athlete.name === 'string') {
    const parts = athlete.name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    if (parts[0].length) return parts[0].slice(0, 2).toUpperCase();
  }
  if (athlete?.username) return athlete.username.slice(0, 2).toUpperCase();
  return '?';
}

/** Аватар атлета: img если есть avatar_path, иначе инициалы. ring — цвет рамки (HEX/var) или null. */
export function CoachAvatar({ athlete, size = 36, ring = null, apiBaseUrl = '/api', radius = '50%' }) {
  const src = athlete?.avatar_path ? getAvatarSrc(athlete.avatar_path, apiBaseUrl, size <= 32 ? 'sm' : 'full') : null;
  const initials = getInitials(athlete);
  const tone = athlete?._tone || 'var(--gray-100)';
  const ringShadow = ring ? `0 0 0 2px ${ring}, 0 0 0 4px white` : 'none';
  const common = {
    width: size,
    height: size,
    borderRadius: typeof radius === 'number' ? `${radius}px` : radius,
    flexShrink: 0,
    boxShadow: ringShadow,
  };
  if (src) {
    return <img src={src} alt="" style={{ ...common, objectFit: 'cover', background: tone }} />;
  }
  return (
    <div
      style={{
        ...common,
        background: tone,
        color: 'var(--text-primary)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontWeight: 700,
        fontSize: size * 0.36,
        fontFamily: 'var(--font-stats), sans-serif',
        letterSpacing: '0.02em',
      }}
      aria-hidden
    >
      {initials}
    </div>
  );
}

/** Tone-стили для KPI-карточек/событий по семантическому полю. */
export const TONE = {
  primary: { bg: 'var(--primary-50)', color: 'var(--primary-500)', solid: 'var(--primary-500)' },
  success: { bg: '#DCFCE7', color: '#166534', solid: 'var(--success-500)' },
  warning: { bg: '#FEF9C3', color: '#92400E', solid: 'var(--warning-500)' },
  danger: { bg: 'var(--primary-50)', color: 'var(--danger-500)', solid: 'var(--danger-500)' },
  info: { bg: '#DBEAFE', color: '#1E40AF', solid: 'var(--info-500)' },
};

/** Workout type → цвет (для строки/точки в таблице). */
export const WORKOUT_TYPE_COLOR = {
  easy: 'var(--success-500)',
  long: 'var(--info-500)',
  'long-run': 'var(--info-500)',
  tempo: 'var(--warning-500)',
  interval: 'var(--danger-500)',
  fartlek: 'var(--danger-500)',
  control: '#8B5CF6',
  race: 'var(--primary-500)',
  sbu: '#8B5CF6',
  other: 'var(--danger-500)',
  rest: 'var(--gray-400)',
};
