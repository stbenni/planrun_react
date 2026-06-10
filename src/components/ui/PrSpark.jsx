export default function PrSpark({
  data,
  w = 72,
  h = 24,
  color = 'var(--pr-accent)',
  fill = 'none',
  sw = 2,
}) {
  if (!data || data.length < 2) return <svg width={w} height={h} style={{ display: 'block' }} />;
  const min = Math.min(...data);
  const max = Math.max(...data);
  const pts = data.map((v, i) => {
    const x = (i / (data.length - 1)) * (w - 2) + 1;
    const y = h - 2 - ((v - min) / (max - min || 1)) * (h - 4);
    return `${x},${y}`;
  });
  return (
    <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} style={{ display: 'block' }}>
      {fill !== 'none' && (
        <polygon points={`1,${h - 1} ${pts.join(' ')} ${w - 1},${h - 1}`} fill={fill} stroke="none" />
      )}
      <polyline
        points={pts.join(' ')}
        fill="none"
        stroke={color}
        strokeWidth={sw}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
