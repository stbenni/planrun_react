export default function PrLogo({ size = 15, style }) {
  return (
    <div
      style={{
        fontFamily: 'var(--pr-font-display)',
        fontSize: size,
        fontWeight: 700,
        color: 'var(--pr-ink)',
        ...style,
      }}
    >
      plan<span className="pr-grad-text">run</span>
    </div>
  );
}
