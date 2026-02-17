/**
 * TopHeader - Верхняя навигация для десктопов
 * В стиле спортивного приложения (Strava/Nike Run Club)
 * Справа: аватар с выпадающим меню (Профиль, Настройки тренировок, Конфиденциальность, Интеграции, Выйти)
 */

import React, { useState, useRef, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import { getAvatarSrc } from '../../utils/avatarUrl';
import ChatNotificationButton from './ChatNotificationButton';
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
  const { user, logout, api, setShowOnboardingModal, drawerOpen, setDrawerOpen } = useAuthStore();
  const needsOnboarding = !!(user && !user.onboarding_completed);
  const [menuOpen, setMenuOpen] = useState(false);
  const [avatarError, setAvatarError] = useState(false);
  const [isMobile, setIsMobile] = useState(() => isNarrowViewport());
  const menuRef = useRef(null);
  const triggerRef = useRef(null);

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

  const navItems = [
    { id: 'home', path: '/', icon: '🏠', label: 'Главная' },
    { id: 'calendar', path: '/calendar', icon: '📅', label: 'Календарь' },
    { id: 'stats', path: '/stats', icon: '📊', label: 'Статистика' }
  ];

  const isActive = (path) => {
    if (path === '/') return location.pathname === '/' || location.pathname === '/dashboard';
    return location.pathname.startsWith(path);
  };

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

  useEffect(() => {
    if (!drawerOpen) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const handleEscape = (e) => { if (e.key === 'Escape') setDrawerOpen(false); };
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.body.style.overflow = prev;
      document.removeEventListener('keydown', handleEscape);
    };
  }, [drawerOpen]);

  const handleMenuAction = async (action) => {
    setMenuOpen(false);
    setDrawerOpen(false);
    if (action === 'profile') navigate('/settings?tab=profile');
    if (action === 'training') navigate('/settings?tab=training');
    if (action === 'privacy') navigate('/settings?tab=social');
    if (action === 'integrations') navigate('/settings?tab=integrations');
    if (action === 'logout') {
      await logout();
      navigate('/landing');
    }
  };

  const closeDrawer = () => setDrawerOpen(false);
  const onAvatarClick = () => {
    if (isMobile) setDrawerOpen((o) => !o);
    else setMenuOpen((o) => !o);
  };

  return (
    <>
      <header className={`top-header ${isMobile ? 'top-header-mobile' : ''}`}>
      <div className="top-header-container">
        <div className="top-header-logo" onClick={() => navigate('/')}>
          <span className="logo-icon">🏃</span>
          <span className="logo-text">PlanRun</span>
        </div>

        <nav className="top-header-nav">
          {navItems.map(item => (
            <button
              key={item.id}
              className={`top-nav-item ${isActive(item.path) ? 'active' : ''}`}
              onClick={() => navigate(item.path)}
              aria-label={item.label}
            >
              <span className="top-nav-icon">{item.icon}</span>
              <span className="top-nav-label">{item.label}</span>
            </button>
          ))}
        </nav>

        {!isMobile && (
        <div className="top-header-actions">
          {user && (
            <>
            {needsOnboarding && (
              <button type="button" className="top-header-onboarding-btn" onClick={() => setShowOnboardingModal(true)}>
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
                aria-expanded={isMobile ? drawerOpen : menuOpen}
                aria-haspopup="true"
                aria-label="Меню профиля"
              >
                {user.avatar_path && !avatarError ? (
                  <img
                    key={user.avatar_path}
                    src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api')}
                    alt=""
                    className="header-avatar-img"
                    onError={() => setAvatarError(true)}
                  />
                ) : (
                  <span className="header-avatar-initials">{initials(user)}</span>
                )}
              </button>
              {!isMobile && menuOpen && (
                <div className="header-avatar-dropdown" ref={menuRef} role="menu">
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('profile')}>
                    <span className="header-dropdown-icon">👤</span>
                    Профиль
                  </button>
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('training')}>
                    <span className="header-dropdown-icon">🏃</span>
                    Настройки тренировок
                  </button>
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('privacy')}>
                    <span className="header-dropdown-icon">🔒</span>
                    Конфиденциальность
                  </button>
                  <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => handleMenuAction('integrations')}>
                    <span className="header-dropdown-icon">🔗</span>
                    Интеграции
                  </button>
                  {user?.role === 'admin' && (
                    <button type="button" role="menuitem" className="header-dropdown-item" onClick={() => { setMenuOpen(false); navigate('/admin'); }}>
                      <span className="header-dropdown-icon">⚙️</span>
                      Админка
                    </button>
                  )}
                  <div className="header-dropdown-divider" />
                  <button type="button" role="menuitem" className="header-dropdown-item header-dropdown-item-danger" onClick={() => handleMenuAction('logout')}>
                    <span className="header-dropdown-icon">🚪</span>
                    Выйти
                  </button>
                </div>
              )}
            </div>
            </>
          )}
        </div>
        )}
      </div>
    </header>

      {isMobile && (
        <>
          <div
            className={`app-drawer-backdrop ${drawerOpen ? 'app-drawer-backdrop-open' : ''}`}
            onClick={closeDrawer}
            aria-hidden="true"
          />
          <aside className={`app-drawer ${drawerOpen ? 'app-drawer-open' : ''}`} role="dialog" aria-label="Меню">
            <div className="app-drawer-inner">
              <div className="app-drawer-header">
                <span className="logo-icon">🏃</span>
                <span className="logo-text">PlanRun</span>
                <button type="button" className="app-drawer-close" onClick={closeDrawer} aria-label="Закрыть меню">
                  ✕
                </button>
              </div>
              {user && (
                <div className="app-drawer-nav">
                    <button type="button" className="app-drawer-item" onClick={() => handleMenuAction('profile')}>
                      <span className="app-drawer-icon">👤</span>
                      <span className="app-drawer-label">Профиль</span>
                    </button>
                    <button type="button" className="app-drawer-item" onClick={() => handleMenuAction('training')}>
                      <span className="app-drawer-icon">🏃</span>
                      <span className="app-drawer-label">Настройки тренировок</span>
                    </button>
                    <button type="button" className="app-drawer-item" onClick={() => handleMenuAction('privacy')}>
                      <span className="app-drawer-icon">🔒</span>
                      <span className="app-drawer-label">Конфиденциальность</span>
                    </button>
                    <button type="button" className="app-drawer-item" onClick={() => handleMenuAction('integrations')}>
                      <span className="app-drawer-icon">🔗</span>
                      <span className="app-drawer-label">Интеграции</span>
                    </button>
                    {user?.role === 'admin' && (
                      <button type="button" className="app-drawer-item" onClick={() => { closeDrawer(); navigate('/admin'); }}>
                        <span className="app-drawer-icon">⚙️</span>
                        <span className="app-drawer-label">Админка</span>
                      </button>
                    )}
                    <div className="app-drawer-divider" />
                    <button type="button" className="app-drawer-item app-drawer-item-danger" onClick={() => handleMenuAction('logout')}>
                      <span className="app-drawer-icon">🚪</span>
                      <span className="app-drawer-label">Выйти</span>
                    </button>
                </div>
              )}
            </div>
          </aside>
        </>
      )}
    </>
  );
};

export default TopHeader;
