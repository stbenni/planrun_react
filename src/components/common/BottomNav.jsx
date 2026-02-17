/**
 * Bottom Navigation - Мобильная навигация в стиле Nike Run Club
 * Последняя вкладка — профиль (аватар, открывает боковое меню). Без подписей под иконками.
 */

import React, { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import { getAvatarSrc } from '../../utils/avatarUrl';
import './BottomNav.css';

const initials = (user) => {
  if (user?.name && typeof user.name === 'string') {
    const parts = user.name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    if (parts[0].length) return parts[0].slice(0, 2).toUpperCase();
  }
  if (user?.username) return user.username.slice(0, 2).toUpperCase();
  return '?';
};

const BottomNav = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, api, drawerOpen, setDrawerOpen } = useAuthStore();
  const [avatarError, setAvatarError] = useState(false);

  const tabs = [
    { id: 'home', path: '/', icon: '🏠', label: 'Главная' },
    { id: 'chat', path: '/chat', icon: '💬', label: 'Чат' },
    { id: 'calendar', path: '/calendar', icon: '📅', label: 'Календарь' },
    { id: 'stats', path: '/stats', icon: '📊', label: 'Статистика' },
    { id: 'profile', path: null, icon: null, label: 'Профиль' }
  ];

  const isActive = (path) => {
    if (path === '/') {
      return location.pathname === '/' || location.pathname === '/dashboard';
    }
    return path && location.pathname.startsWith(path);
  };

  return (
    <nav className="bottom-nav">
      {tabs.map(tab => {
        const isProfile = tab.id === 'profile';
        const active = isProfile ? drawerOpen : isActive(tab.path);
        return (
          <button
            key={tab.id}
            className={`nav-item ${active ? 'active' : ''} ${isProfile ? 'nav-item-profile' : ''}`}
            onClick={() => (isProfile ? setDrawerOpen(true) : navigate(tab.path))}
            aria-label={tab.label}
          >
            {isProfile ? (
              <span className="nav-icon nav-icon-avatar">
                {user?.avatar_path && !avatarError ? (
                  <img
                    src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api')}
                    alt=""
                    className="nav-avatar-img"
                    onError={() => setAvatarError(true)}
                  />
                ) : (
                  <span className="nav-avatar-initials">{initials(user)}</span>
                )}
              </span>
            ) : (
              <span className="nav-icon">{tab.icon}</span>
            )}
            <span className="nav-label">{tab.label}</span>
          </button>
        );
      })}
    </nav>
  );
};

export default BottomNav;
