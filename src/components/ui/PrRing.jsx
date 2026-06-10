import { useEffect, useState } from 'react';

export default function PrRing({
  pct,
  size = 120,
  stroke = 10,
  color = 'url(#pr-grad)',
  track = 'var(--pr-track)',
  round = true,
  children,
}) {
  const clamped = Math.max(0, Math.min(1, pct || 0));
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const [on, setOn] = useState(false);
  useEffect(() => {
    const t = setTimeout(() => setOn(true), 60);
    return () => clearTimeout(t);
  }, []);
  return (
    <div style={{ position: 'relative', width: size, height: size, flexShrink: 0 }}>
      <svg width={size} height={size} style={{ transform: 'rotate(-90deg)', display: 'block' }}>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={track} strokeWidth={stroke} />
        <circle
          className="pr-ring-arc"
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={color}
          strokeWidth={stroke}
          strokeLinecap={round ? 'round' : 'butt'}
          strokeDasharray={c}
          strokeDashoffset={on ? c * (1 - clamped) : c}
        />
      </svg>
      <div
        style={{
          position: 'absolute',
          inset: 0,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        {children}
      </div>
    </div>
  );
}
