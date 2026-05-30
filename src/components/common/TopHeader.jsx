/**
 * TopHeader - Верхняя навигация для десктопов
 * В стиле спортивного приложения (Strava/Nike Run Club)
 * Справа: аватар с выпадающим меню (Профиль, Настройки тренировок, Уведомления, Конфиденциальность, Интеграции, Выйти)
 */

import React, { useState, useRef, useEffect, useLayoutEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import { isNativeCapacitor } from '../../services/TokenStorageService';
import { getAvatarSrc } from '../../utils/avatarUrl';
import ChatNotificationButton from './ChatNotificationButton';
import {
  NavIconHome, NavIconCalendar, NavIconStats, NavIconTrainers,
  NavIconUsers, NavIconChat, NavIconStream, NavIconAnalytics, NavIconLibrary,
} from './BottomNavIcons';
import useCoachStore from '../../stores/useCoachStore';
import { UserIcon, RunningIcon, BellIcon, LockIcon, LinkIcon, LogOutIcon, SettingsIcon } from './Icons';
import './TopHeader.css';

const initials = (user) => {
  if (user?.name && typeof user.name === 'string') {
    const parts = user.name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    if (parts[0].length) return parts[0].slice(0, 2).toUpperCase();
  }
  if (user?.username) return user.username.slice(0, 2).toUpperCase();
  return '?';
};

/** Узкий экран = мобильный хедер (только лого) и drawer. Широкий = полный хедер как на десктопе. */
const isNarrowViewport = () => typeof window !== 'undefined' && window.innerWidth < 1024;

const TopHeader = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, logout, api, setShowOnboardingModal } = useAuthStore();
  const needsOnboarding = !!(user && !user.onboarding_completed);
  const [menuOpen, setMenuOpen] = useState(false);
  const [avatarError, setAvatarError] = useState(false);
  const [isMobile, setIsMobile] = useState(() => isNarrowViewport());
  const menuRef = useRef(null);
  const triggerRef = useRef(null);
  const navRef = useRef(null);
  const [navPillStyle, setNavPillStyle] = useState({ left: 0, width: 0 });

  useEffect(() => {
    const check = () => setIsMobile(isNarrowViewport());
    check();
    window.addEventListener('resize', check);
    return () => window.removeEventListener('resize', check);
  }, []);

  useEffect(() => {
    if (!user?.user_id || user.avatar_path != null || !api) return;
    let cancelled = false;
    api.getCurrentUser().then((data) => {
      if (cancelled || !data?.authenticated || !data.avatar_path) return;
      const cur = useAuthStore.getState().user;
      if (cur && cur.user_id === data.user_id && !cur.avatar_path) {
        useAuthStore.getState().updateUser({ ...cur, avatar_path: data.avatar_path });
      }
    }).catch(() => {});
    return () => { cancelled = true; };
  }, [user?.user_id, user?.avatar_path, api]);

  useEffect(() => {
    setAvatarError(false);
  }, [user?.avatar_path]);

  const isCoach = user?.role === 'coach' || user?.role === 'admin';

  // Подписка на coach-store для badge'а «Поток» (риски + вопросы) — только если coach
  const coachAthletes = useCoachStore((s) => s.athletes);
  const coachEvents = useCoachStore((s) => s.events);
  const streamBadge = isCoach ? (() => {
    const events = Array.isArray(coachEvents) ? coachEvents : [];
    const risk = events.filter((e) => e.kind === 'risk').length;
    const question = events.filter((e) => e.kind === 'question').length;
    const total = risk + question;
    return total > 0 ? total : null;
  })() : null;
  const teamBadge = isCoach && Array.isArray(coachAthletes) ? coachAthletes.length || null : null;

  const navItemsUser = [
    { id: 'home', path: '/', Icon: NavIconHome, label: 'Дэшборд' },
    { id: 'calendar', path: '/calendar', Icon: NavIconCalendar, label: 'Календарь' },
    { id: 'stats', path: '/stats', Icon: NavIconStats, label: 'Статистика' },
    { id: 'trainers', path: '/trainers', Icon: NavIconTrainers, label: 'Тренеры' },
  ];

  const navItemsCoach = [
    { id: 'team', path: '/', search: '', Icon: NavIconUsers, label: 'Команда', badge: teamBadge },
    { id: 'stream', path: '/', search: '?view=stream', Icon: NavIconStream, label: 'Поток', badge: streamBadge },
    { id: 'calendar', path: '/calendar', Icon: NavIconCalendar, label: 'Календарь' },
    { id: 'chat', path: '/chat', Icon: NavIconChat, label: 'Чат' },
    { id: 'analytics', path: '/stats', Icon: NavIconAnalytics, label: 'Аналитика' },
    { id: 'library', path: '/library', Icon: NavIconLibrary, label: 'Шаблоны' },
  ];

  const navItems = isCoach ? navItemsCoach : navItemsUser;

  const isActive = (item) => {
    const path = item.path;
    const itemView = item.search ? new URLSearchParams(item.search).get('view') : null;
    const currentView = new URLSearchParams(location.search).get('view');
    if (path === '/') {
      if (!(location.pathname === '/' || location.pathname === '/dashboard')) return false;
      // Для coach «Команда» и «Поток» различаются по ?view=
      if (isCoach) {
        if (item.id === 'stream') return currentView === 'stream';
        if (item.id === 'team') return !currentView || currentView === 'table' || currentView === 'grid';
      }
      return true;
    }
    return location.pathname.startsWith(path);
  };

  const updateNavPill = () => {
    if (isMobile) {
      setNavPillStyle({ left: 0, width: 0 });
      return;
    }

    const nav = navRef.current;
    if (!nav) return;

    const activeItem = nav.querySelector('.top-nav-item.active');
    if (!activeItem) {
      setNavPillStyle({ left: 0, width: 0 });
      return;
    }

    setNavPillStyle({
      left: activeItem.offsetLeft,
      width: activeItem.offsetWidth,
    });
  };

  useLayoutEffect(() => {
    updateNavPill();
  }, [location.pathname, location.search, isMobile]);

  useLayoutEffect(() => {
    if (isMobile) return undefined;

    let frameId = 0;
    const scheduleUpdate = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(updateNavPill);
    };

    const nav = navRef.current;
    const resizeObserver = typeof ResizeObserver !== 'undefined' && nav
      ? new ResizeObserver(scheduleUpdate)
      : null;

    if (nav && resizeObserver) {
      resizeObserver.observe(nav);
      nav.querySelectorAll('.top-nav-item').forEach((item) => resizeObserver.observe(item));
    }

    window.addEventListener('resize', scheduleUpdate);
    if (document.fonts?.ready) {
      document.fonts.ready.then(scheduleUpdate).catch(() => {});
    }

    return () => {
      cancelAnimationFrame(frameId);
      window.removeEventListener('resize', scheduleUpdate);
      resizeObserver?.disconnect();
    };
  }, [isMobile, location.pathname, location.search]);

  useEffect(() => {
    if (!menuOpen) return;
    const handleClickOutside = (e) => {
      if (menuRef.current && !menuRef.current.contains(e.target) && triggerRef.current && !triggerRef.current.contains(e.target)) {
        setMenuOpen(false);
      }
    };
    const handleEscape = (e) => { if (e.key === 'Escape') setMenuOpen(false); };
    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [menuOpen]);

  const navigateToSettingsTab = (tab) => {
    navigate({
      pathname: '/settings',
      search: `?tab=${tab}`,
    });
  };

  const handleMenuAction = async (action) => {
    setMenuOpen(false);
    if (action === 'profile') return navigateToSettingsTab('profile');
    if (action === 'training') return navigateToSettingsTab('training');
    if (action === 'notifications') return navigateToSettingsTab('notifications');
    if (action === 'privacy') return navigateToSettingsTab('social');
    if (action === 'integrations') return navigateToSettingsTab('integrations');
    if (action === 'logout') {
      await logout();
      if (isNativeCapacitor()) {
        window.location.href = '/landing';
      } else {
        navigate('/landing');
      }
    }
  };

  const onAvatarClick = () => setMenuOpen((o) => !o);

  // На мобильных хедер не рендерится — навигация полностью через BottomNav,
  // а профильное меню (drawer) рендерится отдельно через UserDrawer.
  if (isMobile) return null;

  return (
    <>
      <header className="top-header">
      <div className="top-header-container">
        <div className="top-header-logo" onClick={() => navigate('/')}>
          <span className="logo-text"><span className="logo-plan">plan</span><span className="logo-run">RUN</span></span>
        </div>

        <nav
          ref={navRef}
          className="top-header-nav"
          style={{
            '--top-nav-pill-left': `${navPillStyle.left}px`,
            '--top-nav-pill-width': `${navPillStyle.width}px`,
          }}
        >
          <span className="top-nav-pill" aria-hidden="true" />
          {navItems.map(item => {
            const Icon = item.Icon;
            const target = item.path + (item.search || '');
            return (
              <button
                key={item.id}
                className={`top-nav-item ${isActive(item) ? 'active' : ''}`}
                onClick={() => navigate(target)}
                aria-label={item.label}
              >
                <span className="top-nav-icon">{Icon ? <Icon /> : null}</span>
                <span className="top-nav-label">{item.label}</span>
                {item.badge != null && (
                  <span className="top-nav-badge">{item.badge}</span>
                )}
              </button>
            );
          })}
        </nav>

        {user && (
        <div className="top-header-actions">
            {needsOnboarding && (
              <button type="button" className="btn btn-primary btn--sm" onClick={() => setShowOnboardingModal(true)}>
                Настроить план
              </button>
            )}
            <div className="header-chat-wrap">
              <ChatNotificationButton />
            </div>
            <div className="header-avatar-wrap" ref={triggerRef}>
              <button
                type="button"
                className="header-avatar-btn"
                onClick={onAvatarClick}
                aria-expanded={menuOpen}
                aria-haspopup="true"
                aria-label="Меню профиля"
              >
                {user.avatar_path && !avatarError ? (
                  <img
                    key={user.avatar_path}
                    src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api', 'sm')}
                    alt=""
                    className="header-avatar-img"
                    onError={() => setAvatarError(true)}
                  />
                ) : (
                  <span className="header-avatar-initials">{initials(user)}</span>
                )}
              </button>
              {menuOpen && (
                <div className="header-avatar-dropdown" ref={menuRef} role="menu">
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('profile')}>
                    <span className="header-dropdown-icon" aria-hidden><UserIcon size={18} /></span>
                    Профиль
                  </button>
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('training')}>
                    <span className="header-dropdown-icon" aria-hidden><RunningIcon size={18} /></span>
                    Настройки тренировок
                  </button>
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('notifications')}>
                    <span className="header-dropdown-icon" aria-hidden><BellIcon size={18} /></span>
                    Уведомления
                  </button>
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('privacy')}>
                    <span className="header-dropdown-icon" aria-hidden><LockIcon size={18} /></span>
                    Конфиденциальность
                  </button>
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('integrations')}>
                    <span className="header-dropdown-icon" aria-hidden><LinkIcon size={18} /></span>
                    Интеграции
                  </button>
                  {user?.role === 'admin' && (
                    <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => { setMenuOpen(false); navigate('/admin'); }}>
                      <span className="header-dropdown-icon" aria-hidden><SettingsIcon size={18} /></span>
                      Админка
                    </button>
                  )}
                  <div className="header-dropdown-divider" />
                  <button type="button" role="menuitem" className="header-dropdown-item header-dropdown-item-danger" onClick={() => handleMenuAction('logout')}>
                    <span className="header-dropdown-icon" aria-hidden><LogOutIcon size={18} /></span>
                    Выйти
                  </button>
                </div>
              )}
            </div>
        </div>
        )}
      </div>
    </header>

    </>
  );
};

export default TopHeader;
