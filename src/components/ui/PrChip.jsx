export default function PrChip({ active = false, className = '', children, ...props }) {
  const cls = ['pr-chip', active ? 'is-active' : '', className].filter(Boolean).join(' ');
  return (
    <button type="button" className={cls} {...props}>
      {children}
    </button>
  );
}
