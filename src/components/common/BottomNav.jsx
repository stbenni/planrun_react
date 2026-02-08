/**
 * Bottom Navigation - Мобильная навигация в стиле Nike Run Club
 */

import React from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import './BottomNav.css';

const BottomNav = () => {
  const location = useLocation();
  const navigate = useNavigate();

  const tabs = [
    { id: 'home', path: '/', icon: '🏠', label: 'Главная' },
    { id: 'chat', path: '/chat', icon: '💬', label: 'Чат' },
    { id: 'calendar', path: '/calendar', icon: '📅', label: 'Календарь' },
    { id: 'stats', path: '/stats', icon: '📊', label: 'Статистика' },
    { id: 'settings', path: '/settings', icon: '⚙️', label: 'Настройки' }
  ];

  const isActive = (path) => {
    if (path === '/') {
      return location.pathname === '/' || location.pathname === '/dashboard';
    }
    return location.pathname.startsWith(path);
  };

  return (
    <nav className="bottom-nav">
      {tabs.map(tab => {
        const active = isActive(tab.path);
        return (
          <button
            key={tab.id}
            className={`nav-item ${active ? 'active' : ''}`}
            onClick={() => navigate(tab.path)}
            aria-label={tab.label}
          >
            <span className="nav-icon">{tab.icon}</span>
            <span className="nav-label">{tab.label}</span>
          </button>
        );
      })}
    </nav>
  );
};

export default BottomNav;
