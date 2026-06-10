export default function PrCard({ hover = false, className = '', children, ...props }) {
  const cls = ['pr-card', hover ? 'pr-hover' : '', className].filter(Boolean).join(' ');
  return (
    <div className={cls} {...props}>
      {children}
    </div>
  );
}
