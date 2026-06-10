import PrIcon from './PrIcon';

const RUNNER_ITEMS = [
  { id: 'home', icon: 'home', label: 'Главная' },
  { id: 'cal', icon: 'cal', label: 'План' },
  { id: 'chat', icon: 'chat', label: 'Чат' },
  { id: 'stats', icon: 'stats', label: 'Прогресс' },
  { id: 'profile', icon: 'user', label: 'Профиль' },
];

export default function BNav({ items = RUNNER_ITEMS, active, onSelect, style }) {
  return (
    <nav className="pr-bnav" style={style}>
      {items.map((it) => {
        const act = it.id === active;
        return (
          <button
            key={it.id}
            type="button"
            className={`pr-bnav-item${act ? ' is-active' : ''}`}
            onClick={() => onSelect?.(it.id)}
          >
            {PrIcon[it.icon](act ? '#fff' : 'var(--pr-sub)', 19)}
            <span className="pr-bnav-label">{it.label}</span>
            {it.badge && !act && <span className="pr-bnav-badge" />}
          </button>
        );
      })}
    </nav>
  );
}
