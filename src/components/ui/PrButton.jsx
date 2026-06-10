export default function PrButton({ variant = 'primary', className = '', children, ...props }) {
  const base = variant === 'primary' ? 'pr-btn-primary' : 'pr-btn-secondary';
  return (
    <button type="button" className={[base, className].filter(Boolean).join(' ')} {...props}>
      {children}
    </button>
  );
}
