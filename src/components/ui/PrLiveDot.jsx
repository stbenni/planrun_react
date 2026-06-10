export default function PrLiveDot({ label, color = 'var(--pr-accent)', size = 9, style }) {
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, ...style }}>
      <span className="pr-live-dot" style={color !== 'var(--pr-accent)' ? { background: color } : undefined} />
      {label && (
        <span className="pr-label" style={{ fontSize: size, color }}>
          {label}
        </span>
      )}
    </span>
  );
}
