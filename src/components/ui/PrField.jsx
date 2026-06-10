export default function PrField({ label, multiline = false, style, inputRef, ...props }) {
  const control = multiline ? (
    <textarea className="pr-field" ref={inputRef} {...props} />
  ) : (
    <input className="pr-field" ref={inputRef} {...props} />
  );
  return (
    <label style={{ display: 'block', ...style }}>
      {label && <div className="pr-field-label">{label}</div>}
      {control}
    </label>
  );
}
