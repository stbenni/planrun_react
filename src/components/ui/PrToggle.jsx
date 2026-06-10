export default function PrToggle({ on = false, onChange, disabled = false, ...props }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      disabled={disabled}
      className={`pr-toggle${on ? ' is-on' : ''}`}
      onClick={() => onChange?.(!on)}
      {...props}
    >
      <span className="pr-toggle-knob" />
    </button>
  );
}
