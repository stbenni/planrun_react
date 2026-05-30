/**
 * DashFabAi — floating action button для быстрого доступа к AI-чату с дашборда.
 * Mobile-only, размещается фиксированно в правом нижнем углу над bottom-nav.
 */

import { BotIcon } from '../../common/Icons';
import './DashFabAi.css';

export default function DashFabAi({ onOpen, mode = 'ai' }) {
  return (
    <button
      type="button"
      className="dash-fab-ai"
      onClick={onOpen}
      aria-label={mode === 'ai' ? 'Открыть AI-чат' : 'Открыть чат с тренером'}
    >
      <BotIcon size={22} />
    </button>
  );
}
