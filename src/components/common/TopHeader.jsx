/**
 * TopHeader - Верхняя навигация для десктопов
 * В стиле спортивного приложения (Strava/Nike Run Club)
 */

import React from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import './TopHeader.css';

const TopHeader = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { user } = useAuthStore();

  const navItems = [
    { id: 'home', path: '/', icon: '🏠', label: 'Главная' },
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

  const toggleTheme = () => {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', newTheme);
    document.body.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
  };

  const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';

  return (
    <header className="top-header">
      <div className="top-header-container">
        {/* Логотип/Название */}
        <div className="top-header-logo" onClick={() => navigate('/')}>
          <span className="logo-icon">🏃</span>
          <span className="logo-text">PlanRun</span>
        </div>

        {/* Навигация */}
        <nav className="top-header-nav">
          {navItems.map(item => {
            const active = isActive(item.path);
            return (
              <button
                key={item.id}
                className={`top-nav-item ${active ? 'active' : ''}`}
                onClick={() => navigate(item.path)}
                aria-label={item.label}
              >
                <span className="top-nav-icon">{item.icon}</span>
                <span className="top-nav-label">{item.label}</span>
              </button>
            );
          })}
        </nav>

        {/* Правая часть: Профиль и переключатель темы */}
        <div className="top-header-actions">
          {user?.name && (
            <div className="user-info">
              <span className="user-name">{user.name}</span>
            </div>
          )}
          <button
            className="theme-toggle-header"
            onClick={toggleTheme}
            aria-label={currentTheme === 'light' ? 'Переключить на темную тему' : 'Переключить на светлую тему'}
            title={currentTheme === 'light' ? 'Темная тема' : 'Светлая тема'}
          >
            {currentTheme === 'light' ? '🌙' : '☀️'}
          </button>
        </div>
      </div>
    </header>
  );
};

export default TopHeader;
