/**
 * Контент как вкладки: все экраны смонтированы, при переключении только меняется видимость.
 * Нет перезагрузки и «загрузки» при смене вкладки — как в браузере.
 *
 * Используем собственный LazyTab вместо React.lazy+Suspense.
 * Причина: React Router v6 может взаимодействовать с Suspense через startTransition,
 * что при одновременных Zustand store-обновлениях приводит к зависанию Suspense fallback.
 * LazyTab загружает модуль в useEffect и показывает скелет сам, без Suspense.
 */

import { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import SkeletonScreen from './common/SkeletonScreen';
import AppErrorBoundary from './common/AppErrorBoundary';
import useAuthStore from '../stores/useAuthStore';

/**
 * Загружает lazy-модуль через useEffect (не через React.lazy/Suspense).
 * Показывает SkeletonScreen пока модуль не загружен.
 * После загрузки модуль кешируется — повторных запросов нет.
 */
const moduleCache = new Map();

function useLazyModule(importFn, key) {
  // useState(fn) вызывает fn как initializer, поэтому оборачиваем в () =>
  const [Module, setModule] = useState(() => moduleCache.get(key) || null);

  useEffect(() => {
    if (moduleCache.has(key)) {
      // setState(fn) вызывает fn как updater — оборачиваем чтобы React сохранил компонент, а не вызвал его
      setModule(() => moduleCache.get(key));
      return;
    }
    let cancelled = false;
    importFn().then((mod) => {
      const Component = mod.default || mod;
      moduleCache.set(key, Component);
      if (!cancelled) setModule(() => Component);
    }).catch((err) => {
      console.error(`Failed to load module ${key}:`, err);
    });
    return () => { cancelled = true; };
  }, [importFn, key]);

  return Module;
}

function LazyTab({ importFn, moduleKey, props }) {
  const Component = useLazyModule(importFn, moduleKey);
  if (!Component) return <SkeletonScreen type="default" />;
  return <Component {...(props || {})} />;
}

// Фабрики импорта (стабильные ссылки)
const importDashboard = () => import('../screens/DashboardScreen');
const importCalendar = () => import('../screens/CalendarScreen');
const importStats = () => import('../screens/StatsScreen');
const importChat = () => import('../screens/ChatScreen');
const importTrainers = () => import('../screens/TrainersScreen');
const importSettings = () => import('../screens/SettingsScreen');
const importAthletesOverview = () => import('../screens/AthletesOverviewScreen');
const importAdmin = () => import('../screens/AdminScreen');
const importApplyCoach = () => import('./Trainers/ApplyCoachForm');

// Предзагрузка: основные экраны сразу, второстепенные — после idle
Promise.all([
  importDashboard(), importCalendar(), importStats(),
]).catch(() => {});

const preloadSecondary = () => {
  Promise.all([
    importChat(), importSettings(), importTrainers(),
    importAthletesOverview(), importAdmin(), importApplyCoach(),
  ]).catch(() => {});
};

if (typeof requestIdleCallback === 'function') {
  requestIdleCallback(preloadSecondary, { timeout: 3000 });
} else {
  setTimeout(preloadSecondary, 1500);
}

const TAB_KEYS = {
  dashboard: 'dashboard',
  calendar: 'calendar',
  stats: 'stats',
  chat: 'chat',
  trainers: 'trainers',
  settings: 'settings',
  admin: 'admin',
};

const AppTabsContent = ({ onLogout }) => {
  const location = useLocation();
  const { user } = useAuthStore();
  const pathname = location.pathname;
  const role = user?.role || 'user';
  const isAdmin = role === 'admin';
  const isCoach = role === 'coach';
  const isApplyCoach = pathname === '/trainers/apply';

  const isActive = (path) => {
    if (path === '/') return pathname === '/' || pathname === '/dashboard';
    return pathname.startsWith(path);
  };

  let activeKey = TAB_KEYS.dashboard;
  if (isActive('/admin')) activeKey = TAB_KEYS.admin;
  else if (isActive('/settings')) activeKey = TAB_KEYS.settings;
  else if (isActive('/trainers')) activeKey = TAB_KEYS.trainers;
  else if (isActive('/chat')) activeKey = TAB_KEYS.chat;
  else if (isActive('/stats')) activeKey = TAB_KEYS.stats;
  else if (isActive('/calendar')) activeKey = TAB_KEYS.calendar;

  const [mountedTabs, setMountedTabs] = useState(() => new Set([activeKey]));

  useEffect(() => {
    setMountedTabs((prev) => {
      if (prev.has(activeKey)) return prev;
      const next = new Set(prev);
      next.add(activeKey);
      return next;
    });
  }, [activeKey]);

  if (pathname.startsWith('/admin') && !isAdmin) {
    return <Navigate to="/" replace />;
  }

  const renderPane = (tabKey, isPaneActive, content, extraClass = '') => {
    const shouldRender = mountedTabs.has(tabKey) || isPaneActive;
    return (
    <div className={`app-tab-pane ${isPaneActive ? 'app-tab-pane--active' : ''} ${extraClass}`.trim()} aria-hidden={!isPaneActive}>
      {shouldRender ? (
        <AppErrorBoundary resetKey={pathname}>
          {content}
        </AppErrorBoundary>
      ) : null}
    </div>
    );
  };

  return (
    <div className="app-tabs-content">
      {renderPane(
        TAB_KEYS.dashboard,
        isActive('/'),
        isCoach
          ? <LazyTab importFn={importAthletesOverview} moduleKey="AthletesOverviewScreen" />
          : <LazyTab importFn={importDashboard} moduleKey="DashboardScreen" />
      )}
      {renderPane(TAB_KEYS.calendar, isActive('/calendar'),
        <LazyTab importFn={importCalendar} moduleKey="CalendarScreen" />
      )}
      {renderPane(TAB_KEYS.stats, isActive('/stats'),
        <LazyTab importFn={importStats} moduleKey="StatsScreen" />
      )}
      {renderPane(TAB_KEYS.chat, isActive('/chat'),
        <LazyTab importFn={importChat} moduleKey="ChatScreen" />,
        'app-tab-pane--chat'
      )}
      {renderPane(
        TAB_KEYS.trainers,
        isActive('/trainers'),
        isApplyCoach
          ? <LazyTab importFn={importApplyCoach} moduleKey="ApplyCoachForm" />
          : <LazyTab importFn={importTrainers} moduleKey="TrainersScreen" />
      )}
      {renderPane(TAB_KEYS.settings, isActive('/settings'),
        <LazyTab importFn={importSettings} moduleKey="SettingsScreen" props={{ onLogout }} />
      )}
      {isAdmin && (
        renderPane(TAB_KEYS.admin, isActive('/admin'),
          <LazyTab importFn={importAdmin} moduleKey="AdminScreen" />
        )
      )}
    </div>
  );
};

export default AppTabsContent;
