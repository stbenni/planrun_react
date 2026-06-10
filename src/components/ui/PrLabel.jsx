export default function PrLabel({ size, color, style, className = '', children, ...props }) {
  return (
    <div
      className={['pr-label', className].filter(Boolean).join(' ')}
      style={{
        ...(size != null ? { fontSize: size } : null),
        ...(color != null ? { color } : null),
        ...style,
      }}
      {...props}
    >
      {children}
    </div>
  );
}
