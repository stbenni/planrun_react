/**
 * Bottom Navigation — мобильная навигация в стиле v3 (Variant C minimal).
 * Неактивные вкладки — только иконка. Активная вкладка превращается в оранжевую
 * pill-кнопку с белым лейблом, который плавно вытесняет соседей.
 */

import { useMemo } from 'react';
import { createPortal } from 'react-dom';
import { useLocation, useNavigate } from 'react-router-dom';
import {
  NavIconHome, NavIconCalendar, NavIconChat, NavIconStats,
  NavIconUsers, NavIconSettings, NavIconStream,
} from './BottomNavIcons';
import useAuthStore from '../../stores/useAuthStore';
import './BottomNav.css';

const userTabs = [
  { id: 'home', path: '/', Icon: NavIconHome, label: 'Главная' },
  { id: 'calendar', path: '/calendar', Icon: NavIconCalendar, label: 'План' },
  { id: 'chat', path: '/chat', Icon: NavIconChat, label: 'Чат' },
  { id: 'stats', path: '/stats', Icon: NavIconStats, label: 'Прогресс' },
  { id: 'settings', action: 'drawer', Icon: NavIconSettings, label: 'Меню' },
];

const coachTabs = [
  { id: 'team', path: '/', Icon: NavIconUsers, label: 'Команда' },
  { id: 'stream', path: '/?view=stream', Icon: NavIconStream, label: 'Поток' },
  { id: 'calendar', path: '/calendar', Icon: NavIconCalendar, label: 'Календарь' },
  { id: 'chat', path: '/chat', Icon: NavIconChat, label: 'Чат' },
  { id: 'settings', action: 'drawer', Icon: NavIconSettings, label: 'Меню' },
];

const BottomNav = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, drawerOpen, setDrawerOpen } = useAuthStore();
  const role = user?.role || 'user';
  const isCoach = role === 'coach';
  const tabs = useMemo(() => isCoach ? coachTabs : userTabs, [isCoach]);

  const isActive = (tab) => {
    if (tab.action === 'drawer') return drawerOpen;
    const [tabPath, tabQuery] = (tab.path || '/').split('?');
    const currentView = new URLSearchParams(location.search).get('view');
    if (tabPath === '/' || tabPath === '') {
      const onHome = location.pathname === '/' || location.pathname === '/dashboard';
      if (!onHome) return false;
      if (tabQuery) {
        const tabView = new URLSearchParams(`?${tabQuery}`).get('view');
        return currentView === tabView;
      }
      if (tab.id === 'team') return !currentView || currentView === 'table' || currentView === 'grid';
      return !currentView;
    }
    return location.pathname.startsWith(tabPath);
  };

  const handleTabClick = (tab) => {
    if (tab.action === 'drawer') {
      setDrawerOpen(!drawerOpen);
      return;
    }
    navigate(tab.path);
  };

  const nav = (
    <nav className="bottom-nav">
      {tabs.map((tab) => {
        const Icon = tab.Icon;
        const active = isActive(tab);
        return (
          <button
            key={tab.id}
            type="button"
            className={`nav-item ${active ? 'active' : ''}`}
            onClick={() => handleTabClick(tab)}
            aria-label={tab.label}
          >
            <span className="nav-icon">{Icon ? <Icon /> : null}</span>
            <span className="nav-label">{tab.label}</span>
          </button>
        );
      })}
    </nav>
  );

  if (typeof document === 'undefined') return nav;
  const portalTarget = document.getElementById('modal-root') || document.body;
  return createPortal(nav, portalTarget);
};

export default BottomNav;
