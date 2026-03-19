/**
 * Контент как вкладки: все экраны смонтированы, при переключении только меняется видимость.
 * Нет перезагрузки и «загрузки» при смене вкладки — как в браузере.
 */

import React, { Suspense, useEffect, useMemo, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import SkeletonScreen from './common/SkeletonScreen';
import AppErrorBoundary from './common/AppErrorBoundary';
import useAuthStore from '../stores/useAuthStore';
import { lazyWithRetry } from '../utils/lazyWithRetry';

const DashboardScreen = lazyWithRetry(() => import('../screens/DashboardScreen'), 'DashboardScreen');
const CalendarScreen = lazyWithRetry(() => import('../screens/CalendarScreen'), 'CalendarScreen');
const StatsScreen = lazyWithRetry(() => import('../screens/StatsScreen'), 'StatsScreen');
const ChatScreen = lazyWithRetry(() => import('../screens/ChatScreen'), 'ChatScreen');
const TrainersScreen = lazyWithRetry(() => import('../screens/TrainersScreen'), 'TrainersScreen');
const SettingsScreen = lazyWithRetry(() => import('../screens/SettingsScreen'), 'SettingsScreen');
const AthletesOverviewScreen = lazyWithRetry(() => import('../screens/AthletesOverviewScreen'), 'AthletesOverviewScreen');
const AdminScreen = lazyWithRetry(() => import('../screens/AdminScreen'), 'AdminScreen');
const ApplyCoachForm = lazyWithRetry(() => import('./Trainers/ApplyCoachForm'), 'ApplyCoachForm');

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

  const activeKey = useMemo(() => {
    if (isActive('/admin')) return TAB_KEYS.admin;
    if (isActive('/settings')) return TAB_KEYS.settings;
    if (isActive('/trainers')) return TAB_KEYS.trainers;
    if (isActive('/chat')) return TAB_KEYS.chat;
    if (isActive('/stats')) return TAB_KEYS.stats;
    if (isActive('/calendar')) return TAB_KEYS.calendar;
    return TAB_KEYS.dashboard;
  }, [pathname]);

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
          <Suspense fallback={<SkeletonScreen type="default" />}>
            {content}
          </Suspense>
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
        isCoach ? <AthletesOverviewScreen /> : <DashboardScreen />
      )}
      {renderPane(TAB_KEYS.calendar, isActive('/calendar'), <CalendarScreen />)}
      {renderPane(TAB_KEYS.stats, isActive('/stats'), <StatsScreen />)}
      {renderPane(TAB_KEYS.chat, isActive('/chat'), <ChatScreen />, 'app-tab-pane--chat')}
      {renderPane(
        TAB_KEYS.trainers,
        isActive('/trainers'),
        isApplyCoach ? <ApplyCoachForm /> : <TrainersScreen />
      )}
      {renderPane(TAB_KEYS.settings, isActive('/settings'), <SettingsScreen onLogout={onLogout} />)}
      {isAdmin && (
        renderPane(TAB_KEYS.admin, isActive('/admin'), <AdminScreen />)
      )}
    </div>
  );
};

export default AppTabsContent;
