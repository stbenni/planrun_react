/**
 * Боковой drawer с пунктами профиля и настроек.
 * Открывается из TopHeader (десктоп) и из BottomNav-кнопки «Профиль» (мобильный).
 * Использует drawerOpen/setDrawerOpen из useAuthStore.
 */

import { useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import { isNativeCapacitor } from '../../services/TokenStorageService';
import {
  UserIcon,
  RunningIcon,
  BellIcon,
  LockIcon,
  LinkIcon,
  LogOutIcon,
  SettingsIcon,
  CloseIcon,
} from './Icons';
import { NavIconTrainers } from './BottomNavIcons';
import './TopHeader.css';

const UserDrawer = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { user, logout, drawerOpen, setDrawerOpen } = useAuthStore();
  const isCoach = user?.role === 'coach';

  useEffect(() => {
    if (!drawerOpen) return undefined;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const handleEscape = (e) => {
      if (e.key === 'Escape') setDrawerOpen(false);
    };
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.body.style.overflow = prev;
      document.removeEventListener('keydown', handleEscape);
    };
  }, [drawerOpen, setDrawerOpen]);

  // Закрываем drawer при смене маршрута (например, тап на другой таб в BottomNav).
  useEffect(() => {
    if (drawerOpen) setDrawerOpen(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, location.search]);

  const closeDrawer = () => setDrawerOpen(false);

  const goToSettings = (tab) => {
    closeDrawer();
    navigate({ pathname: '/settings', search: `?tab=${tab}` });
  };

  const goToTrainers = () => {
    closeDrawer();
    navigate('/trainers');
  };

  const handleLogout = async () => {
    closeDrawer();
    await logout();
    if (isNativeCapacitor()) {
      window.location.href = '/landing';
    } else {
      navigate('/landing');
    }
  };

  return (
    <>
      <div
        className={`app-drawer-backdrop ${drawerOpen ? 'app-drawer-backdrop-open' : ''}`}
        onClick={closeDrawer}
        aria-hidden="true"
      />
      <aside
        className={`app-drawer ${drawerOpen ? 'app-drawer-open' : ''}`}
        role="dialog"
        aria-label="Меню"
      >
        <div className="app-drawer-inner">
          <div className="app-drawer-header">
            <div
              className="top-header-logo"
              onClick={() => { closeDrawer(); navigate('/'); }}
            >
              <span className="logo-text">
                <span className="logo-plan">plan</span>
                <span className="logo-run">RUN</span>
              </span>
            </div>
            <button
              type="button"
              className="app-drawer-close"
              onClick={closeDrawer}
              aria-label="Закрыть меню"
            >
              <CloseIcon className="modal-close-icon" />
            </button>
          </div>
          {user && (
            <div className="app-drawer-nav">
              <button
                type="button"
                className="app-drawer-item"
                onClick={() => goToSettings('profile')}
              >
                <span className="app-drawer-icon" aria-hidden><UserIcon size={20} /></span>
                <span className="app-drawer-label">Профиль</span>
              </button>
              <button
                type="button"
                className="app-drawer-item"
                onClick={() => goToSettings('training')}
              >
                <span className="app-drawer-icon" aria-hidden><RunningIcon size={20} /></span>
                <span className="app-drawer-label">Настройки тренировок</span>
              </button>
              <button
                type="button"
                className="app-drawer-item"
                onClick={() => goToSettings('notifications')}
              >
                <span className="app-drawer-icon" aria-hidden><BellIcon size={20} /></span>
                <span className="app-drawer-label">Уведомления</span>
              </button>
              <button
                type="button"
                className="app-drawer-item"
                onClick={() => goToSettings('social')}
              >
                <span className="app-drawer-icon" aria-hidden><LockIcon size={20} /></span>
                <span className="app-drawer-label">Конфиденциальность</span>
              </button>
              <button
                type="button"
                className="app-drawer-item"
                onClick={() => goToSettings('integrations')}
              >
                <span className="app-drawer-icon" aria-hidden><LinkIcon size={20} /></span>
                <span className="app-drawer-label">Интеграции</span>
              </button>
              {!isCoach && (
                <button
                  type="button"
                  className="app-drawer-item"
                  onClick={goToTrainers}
                >
                  <span className="app-drawer-icon" aria-hidden><NavIconTrainers /></span>
                  <span className="app-drawer-label">Найти тренера</span>
                </button>
              )}
              {user?.role === 'admin' && (
                <button
                  type="button"
                  className="app-drawer-item"
                  onClick={() => { closeDrawer(); navigate('/admin'); }}
                >
                  <span className="app-drawer-icon" aria-hidden><SettingsIcon size={20} /></span>
                  <span className="app-drawer-label">Админка</span>
                </button>
              )}
              <div className="app-drawer-divider" />
              <button
                type="button"
                className="app-drawer-item app-drawer-item-danger"
                onClick={handleLogout}
              >
                <span className="app-drawer-icon" aria-hidden><LogOutIcon size={20} /></span>
                <span className="app-drawer-label">Выйти</span>
              </button>
            </div>
          )}
        </div>
      </aside>
    </>
  );
};

export default UserDrawer;
