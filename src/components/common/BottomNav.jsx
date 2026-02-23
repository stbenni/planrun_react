/**
 * Bottom Navigation - Мобильная навигация (Главная, Чат, Календарь, Статистика).
 * Анимация как в Telegram: плавно перемещающаяся «таблетка» под активной вкладкой.
 */

import React, { useRef, useLayoutEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { NavIconHome, NavIconCalendar, NavIconStats, NavIconTrainers } from './BottomNavIcons';
import './BottomNav.css';

const tabs = [
  { id: 'home', path: '/', Icon: NavIconHome, label: 'Дэшборд' },
  { id: 'calendar', path: '/calendar', Icon: NavIconCalendar, label: 'Календарь' },
  { id: 'stats', path: '/stats', Icon: NavIconStats, label: 'Статистика' },
  { id: 'trainers', path: '/trainers', Icon: NavIconTrainers, label: 'Тренеры' }
];

const BottomNav = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const navRef = useRef(null);
  const [pillStyle, setPillStyle] = useState({ left: 0, width: 0 });

  const isActive = (path) => {
    if (path === '/') {
      return location.pathname === '/' || location.pathname === '/dashboard';
    }
    return path && location.pathname.startsWith(path);
  };

  const MIN_PILL_WIDTH = 72;

  const updatePill = () => {
    const nav = navRef.current;
    if (!nav) return;
    const active = nav.querySelector('.nav-item.active');
    if (!active) return;
    const navRect = nav.getBoundingClientRect();
    const itemRect = active.getBoundingClientRect();
    const w = Math.max(MIN_PILL_WIDTH, itemRect.width);
    setPillStyle({
      left: itemRect.left - navRect.left,
      width: w
    });
  };

  useLayoutEffect(() => {
    updatePill();
  }, [location.pathname]);

  useLayoutEffect(() => {
    window.addEventListener('resize', updatePill);
    return () => window.removeEventListener('resize', updatePill);
  }, []);

  return (
    <nav ref={navRef} className="bottom-nav" style={{ '--pill-left': `${pillStyle.left}px`, '--pill-width': `${pillStyle.width}px` }}>
      <span className="nav-pill" aria-hidden="true" />
      {tabs.map(tab => {
        const Icon = tab.Icon;
        return (
          <button
            key={tab.id}
            className={`nav-item ${isActive(tab.path) ? 'active' : ''}`}
            onClick={() => navigate(tab.path)}
            aria-label={tab.label}
          >
            <span className="nav-icon">{Icon ? <Icon /> : null}</span>
            <span className="nav-label">{tab.label}</span>
          </button>
        );
      })}
    </nav>
  );
};

export default BottomNav;
